<?php
// weekly_update.php - the adopter sends a weekly photo / live camera update of the adopted pet
session_start();
require_once 'config.php';
require_once 'weekly_helper.php';
require_once __DIR__ . '/ai_detect.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Make sure adoption_updates has the columns used by the photo checks (runs once per session) */
if (empty($_SESSION['wu_cols_ok'])) {
    try {
        $conn->query("ALTER TABLE adoption_updates ADD COLUMN IF NOT EXISTS ai_score DECIMAL(4,3) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE adoption_updates ADD COLUMN IF NOT EXISTS img_hash CHAR(16) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE adoption_updates ADD COLUMN IF NOT EXISTS needs_review TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE adoption_updates ADD COLUMN IF NOT EXISTS review_reason VARCHAR(255) NULL DEFAULT NULL");
        $_SESSION['wu_cols_ok'] = 1;
    } catch (Throwable $e) {
        error_log('weekly_update: could not add columns: ' . $e->getMessage());
    }
}

$uid   = (int)$_SESSION['user_id'];
$reqId = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);

if (empty($_SESSION['csrf_profile'])) {
    $_SESSION['csrf_profile'] = bin2hex(random_bytes(16));
}

/* Only the owner of an APPROVED request can open this page */
$st = $conn->prepare(
    "SELECT r.id, r.pet_id, r.status, p.pet_name, p.pet_species, p.breed, p.pet_photo
     FROM adoption_requests r
     LEFT JOIN pets p ON p.id = r.pet_id
     WHERE r.id = ? AND r.user_id = ?"
);
$st->bind_param("ii", $reqId, $uid);
$st->execute();
$req = $st->get_result()->fetch_assoc();
$st->close();

if (!$req || strtolower((string)$req['status']) !== 'approved') {
    header('Location: notifications.php');
    exit();
}

$status = null;
foreach (wu_updates_status($conn, $uid) as $s) {
    if ($s['request_id'] === $reqId) { $status = $s; }
}
$canSubmit = $status && $status['due'];

$healthOptions   = ['Healthy', 'Needs attention', 'Sick or injured'];
$appetiteOptions = ['Eating well', 'Eating less than usual', 'Not eating'];

$error       = '';
$rejected    = false;
$rejectTitle = '';
$rejectMsg   = '';
$done        = isset($_GET['done']);

/* ---------- Save the update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_profile'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$canSubmit) {
        $error = 'You already sent this week\'s update.';
    } else {
        $health   = in_array($_POST['health_status'] ?? '', $healthOptions, true) ? $_POST['health_status'] : '';
        $appetite = in_array($_POST['appetite'] ?? '', $appetiteOptions, true) ? $_POST['appetite'] : '';
        $source   = (($_POST['source'] ?? '') === 'camera') ? 'camera' : 'upload';
        $notes    = mb_substr(trim($_POST['notes'] ?? ''), 0, 1000);

        if ($health === '' || $appetite === '') {
            $error = 'Please choose the health status and appetite.';
        } elseif ($source !== 'camera') {
            $error = 'Please take a live photo using the camera. Uploaded files are not accepted.';
        } elseif (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please take a live photo using the camera.';
        } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            $error = 'The photo is too large. Maximum size is 5 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $error = 'Invalid file type. Only JPG, JPEG, PNG, and WEBP are allowed.';
            } elseif (@getimagesize($_FILES['photo']['tmp_name']) === false) {
                $error = 'The file is not a valid image.';
            } else {
                /* ================= PHOTO CHECKS ================= */
                $tmp         = $_FILES['photo']['tmp_name'];
                $reasons     = [];
                $needsReview = 0;

                // 1) A file upload instead of the live camera always goes to admin review
                if ($source !== 'camera') {
                    $needsReview = 1;
                    $reasons[]   = 'Uploaded a file instead of using the live camera';
                }

                // 2) AI-generated check
                $aiScore = ai_image_score($tmp);
                if ($aiScore === null) {
                    // No AI service available: a live camera photo is not flagged just for that.
                    // Uploads are already flagged above, so they still go to admin review.
                    error_log('weekly_update: request ' . $reqId . ' AI check could not run');
                } else {
                    error_log('weekly_update: request ' . $reqId . ' ai score = ' . $aiScore);
                    if ($aiScore >= AI_REJECT_THRESHOLD) {
                        $rejected    = true;
                        $rejectTitle = '⚠️ AI-generated photo detected';
                        $rejectMsg   = 'The photo you sent looks like it was made by AI. Please take a new, real photo of ' . ($req['pet_name'] ?? 'your pet') . ' using the live camera.';
                    } elseif ($aiScore >= AI_REVIEW_THRESHOLD) {
                        $needsReview = 1;
                        $reasons[]   = 'AI score ' . number_format($aiScore, 2);
                    }
                }

                // 3) Near-duplicate check (earlier updates + the pet's listing photo)
                $imgHash = image_dhash($tmp);
                if (!$rejected && $imgHash !== null) {
                    $match = find_similar_photo($conn, $imgHash, (string)($req['pet_photo'] ?? ''));
                    if ($match !== null) {
                        error_log('weekly_update: request ' . $reqId . ' closest photo = ' . $match['what'] . ' (distance ' . $match['dist'] . ')');
                        if ($match['dist'] <= DUP_REJECT_DIST) {
                            $rejected    = true;
                            $rejectTitle = '⚠️ This photo was used before';
                            $rejectMsg   = 'This photo looks almost the same as an earlier photo. Please take a new photo of ' . ($req['pet_name'] ?? 'your pet') . ' using the live camera.';
                        } elseif ($match['dist'] <= DUP_REVIEW_DIST) {
                            $needsReview = 1;
                            $reasons[]   = 'Looks similar to ' . $match['what'] . ' (distance ' . $match['dist'] . ')';
                        }
                    }
                }

                if ($rejected) {
                    $error = trim(preg_replace('/^[^\p{L}]+/u', '', $rejectTitle)) . '. ' . $rejectMsg;
                } else {
                    $dir = __DIR__ . '/uploads/updates/';
                    if (!is_dir($dir)) { mkdir($dir, 0777, true); }

                    $fileName = time() . '_' . $reqId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($tmp, $dir . $fileName)) {
                        $error = 'Could not save the photo.';
                    } else {
                        $photoPath  = 'uploads/updates/' . $fileName;
                        $petId      = (int)$req['pet_id'];
                        $reasonText = $reasons ? mb_substr(implode('; ', $reasons), 0, 250) : null;

                        $ins = $conn->prepare(
                            "INSERT INTO adoption_updates
                                (request_id, user_id, pet_id, photo, source, health_status, appetite, notes,
                                 ai_score, img_hash, needs_review, review_reason)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $ins->bind_param(
                            "iiisssssdsis",
                            $reqId, $uid, $petId, $photoPath, $source, $health, $appetite, $notes,
                            $aiScore, $imgHash, $needsReview, $reasonText
                        );

                        if ($ins->execute()) {
                            $ins->close();
                            header('Location: weekly_update.php?request_id=' . $reqId . '&done=1');
                            exit();
                        }
                        $error = 'Error saving the update: ' . $ins->error;
                        $ins->close();
                    }
                }
            }
        }
    }
}

/* ---------- History ---------- */
$history = [];
try {
    $st = $conn->prepare("SELECT photo, source, health_status, appetite, notes, created_at FROM adoption_updates WHERE request_id = ? AND user_id = ? ORDER BY id DESC LIMIT 20");
    $st->bind_param("ii", $reqId, $uid);
    $st->execute();
    $history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $e) { $history = []; }

$petName = $req['pet_name'] ?? 'your pet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PAWFINDER - Weekly Update</title>
    <link rel="stylesheet" href="css/homepage.css">
    <style>
        .wu-wrap{max-width:820px;margin:0 auto}
        .wu-back{display:inline-block;margin-bottom:14px;color:#0d9488;font-weight:600;text-decoration:none;font-size:14px}
        .wu-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px}
        .wu-pet{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
        .wu-pet img{width:84px;height:84px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0}
        .wu-pet h1{font-size:22px;margin:0 0 4px;color:#0f172a}
        .wu-muted{color:#64748b;font-size:13px}
        .wu-banner{border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:14px;border:1px solid}
        .wu-banner.due{background:#fef2f2;border-color:#fecaca;color:#991b1b;border-left:5px solid #dc2626}
        .wu-banner.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
        .wu-banner.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .wu-card h2{margin:0 0 12px;font-size:18px;color:#0f172a}
        .wu-panel{display:none;margin-bottom:14px}
        .wu-panel.active{display:block}
        .wu-video,.wu-preview{width:100%;max-width:480px;border-radius:12px;background:#0f172a;display:none;margin-bottom:10px}
        .wu-actions{display:flex;gap:8px;flex-wrap:wrap}
        .wu-btn{background:#0d9488;color:#fff;border:0;border-radius:8px;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer}
        .wu-btn:hover{background:#0b7c72}
        .wu-btn:disabled{opacity:.5;cursor:not-allowed}
        .wu-btn.alt{background:#fff;color:#0d9488;border:1px solid #99e6da}
        .wu-link{color:#0d9488;font-weight:600;font-size:13px;text-decoration:underline;cursor:pointer}
        .wu-field{margin:0 0 14px}
        .wu-field label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#4a6670}
        .wu-field select,.wu-field textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
        .wu-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        @media(max-width:600px){.wu-row{grid-template-columns:1fr}}
        .wu-hist{display:flex;gap:14px;padding:14px 0;border-top:1px solid #f1f5f9}
        .wu-hist:first-of-type{border-top:0}
        .wu-hist img{width:96px;height:96px;object-fit:cover;border-radius:10px;flex:0 0 auto}
        .wu-hist b{display:block;font-size:14px;color:#0f172a}
        .wu-tag{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:2px 10px;margin-left:6px;background:#ccfbf1;color:#0f766e}
        .wu-tag.warn{background:#fee2e2;color:#991b1b}

        /* rejected-photo popup */
        .wu-modal{position:fixed;inset:0;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;padding:16px;z-index:9999}
        .wu-modal-box{background:#fff;border-radius:14px;max-width:420px;width:100%;padding:24px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.3)}
        .wu-modal-box h3{margin:0 0 10px;font-size:20px;color:#991b1b}
        .wu-modal-box p{margin:0 0 18px;font-size:14px;color:#334155;line-height:1.5}

        /* dark mode */
        body.dark-mode .wu-card{background:#1e293b !important;border-color:#334155 !important}
        body.dark-mode .wu-pet h1,body.dark-mode .wu-card h2,body.dark-mode .wu-hist b{color:#f1f5f9 !important}
        body.dark-mode .wu-muted{color:#94a3b8 !important}
        body.dark-mode .wu-pet img{border-color:#334155 !important}
        body.dark-mode .wu-banner.due,body.dark-mode .wu-banner.err{background:#3b1c1c !important;border-color:#7f1d1d !important;color:#fca5a5 !important}
        body.dark-mode .wu-banner.ok{background:#12372a !important;border-color:#1f5a43 !important;color:#86efac !important}
        body.dark-mode .wu-btn.alt{background:#0f172a !important;border-color:#334155 !important;color:#2dd4bf !important}
        body.dark-mode .wu-field label{color:#94a3b8 !important}
        body.dark-mode .wu-hist{border-color:#334155 !important}
        body.dark-mode .wu-tag{background:#0f3d3a !important;color:#5eead4 !important}
        body.dark-mode .wu-tag.warn{background:#451a1a !important;color:#fca5a5 !important}
        body.dark-mode .wu-modal-box{background:#1e293b !important}
        body.dark-mode .wu-modal-box h3{color:#fca5a5 !important}
        body.dark-mode .wu-modal-box p{color:#cbd5e1 !important}
    </style>
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <script src="js/darkmode.js"></script>

    <?php include 'header.php'; ?>

    <main class="content-area">
        <div class="wu-wrap">
            <a class="wu-back" href="notifications.php">&larr; Back to notifications</a>

            <div class="wu-card">
                <div class="wu-pet">
                    <?php if (!empty($req['pet_photo'])): ?><img src="<?= h($req['pet_photo']) ?>" alt=""><?php endif; ?>
                    <div>
                        <h1>Weekly update: <?= h($petName) ?></h1>
                        <div class="wu-muted"><?= h(($req['pet_species'] ?? '') . ' · ' . ($req['breed'] ?? '')) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($done): ?>
                <div class="wu-banner ok" role="status">Thank you! Your weekly update was sent. The next update is due in <?= (int)WU_INTERVAL_DAYS ?> days.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="wu-banner err" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($canSubmit): ?>
                <div class="wu-banner due" role="alert">
                    <b>Update due.</b> <?= h(wu_alert_text($status)) ?>
                </div>

                <div class="wu-card">
                    <h2>Send this week's update</h2>

                    <form method="POST" action="weekly_update.php" enctype="multipart/form-data" id="wuForm">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_profile']) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$reqId ?>">
                        <input type="hidden" name="source" id="wuSource" value="camera">

                        <!-- Live camera (default) -->
                        <div class="wu-panel active" id="panelCam">
                            <video id="wuVideo" class="wu-video" autoplay playsinline muted></video>
                            <div class="wu-actions">
                                <button type="button" class="wu-btn" id="wuStart">📷 Start camera</button>
                                <button type="button" class="wu-btn" id="wuSnap" disabled>Take photo</button>
                            </div>
                            <div class="wu-muted" id="wuCamMsg" style="margin-top:8px">Please take a live photo of your pet. Allow camera access when your browser asks.</div>
                            <div class="wu-muted" style="margin-top:8px">Camera not working? Allow camera access in your browser settings, then reload this page.</div>
                        </div>

                        <!-- The photo is filled in only by the live camera capture (no file picker) -->
                        <input type="file" id="wuFile" name="photo" accept="image/jpeg" style="display:none" tabindex="-1" aria-hidden="true">

                        <img id="wuPreview" class="wu-preview" alt="Photo preview">

                        <div class="wu-row" style="margin-top:14px">
                            <div class="wu-field">
                                <label>Health status</label>
                                <select name="health_status" required>
                                    <option value="" disabled selected>Select</option>
                                    <?php foreach ($healthOptions as $o): ?><option><?= h($o) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="wu-field">
                                <label>Appetite</label>
                                <select name="appetite" required>
                                    <option value="" disabled selected>Select</option>
                                    <?php foreach ($appetiteOptions as $o): ?><option><?= h($o) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="wu-field">
                            <label>Notes (behavior, vet visits, vaccinations, etc.)</label>
                            <textarea name="notes" rows="3" maxlength="1000" placeholder="Tell us how your pet is doing this week..."></textarea>
                        </div>

                        <button type="submit" class="wu-btn">Submit weekly update</button>
                    </form>
                </div>

            <?php elseif ($status): ?>
                <div class="wu-banner ok">
                    You are up to date. Your next update is due in <b><?= (int)$status['days_left'] ?></b> day(s).
                </div>
            <?php endif; ?>

            <div class="wu-card">
                <h2>Update history</h2>
                <?php if ($history): ?>
                    <?php foreach ($history as $u): ?>
                        <div class="wu-hist">
                            <img src="<?= h($u['photo']) ?>" alt="">
                            <div>
                                <b>
                                    <?= h(date('M j, Y g:i A', strtotime($u['created_at']))) ?>
                                    <span class="wu-tag"><?= $u['source'] === 'camera' ? 'Live camera' : 'Uploaded' ?></span>
                                    <?php if ($u['health_status'] !== 'Healthy'): ?><span class="wu-tag warn"><?= h($u['health_status']) ?></span><?php endif; ?>
                                </b>
                                <div class="wu-muted">Health: <?= h($u['health_status']) ?> &middot; Appetite: <?= h($u['appetite']) ?></div>
                                <?php if (!empty($u['notes'])): ?><div style="margin-top:6px;font-size:14px"><?= nl2br(h($u['notes'])) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="wu-muted">No updates sent yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($rejected): ?>
    <div class="wu-modal" id="rejectModal" role="alertdialog" aria-modal="true" aria-labelledby="rejectTitle">
        <div class="wu-modal-box">
            <h3 id="rejectTitle"><?= h($rejectTitle) ?></h3>
            <p><?= h($rejectMsg) ?></p>
            <button type="button" class="wu-btn" id="rejectClose">Take a new photo</button>
        </div>
    </div>
    <?php endif; ?>

<script>
(function () {
    var panelCam = document.getElementById('panelCam');
    var video = document.getElementById('wuVideo');
    var startBtn = document.getElementById('wuStart');
    var snapBtn = document.getElementById('wuSnap');
    var fileInput = document.getElementById('wuFile');
    var preview = document.getElementById('wuPreview');
    var sourceInput = document.getElementById('wuSource');
    var msg = document.getElementById('wuCamMsg');
    var form = document.getElementById('wuForm');
    var stream = null;

    var modal = document.getElementById('rejectModal');
    var modalClose = document.getElementById('rejectClose');
    if (modal && modalClose) {
        modalClose.addEventListener('click', function () { modal.style.display = 'none'; });
        modalClose.focus();
    }

    if (!form) return;

    function stopCamera() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.style.display = 'none';
        snapBtn.disabled = true;
    }

    function showPreview(blobOrFile) {
        preview.src = URL.createObjectURL(blobOrFile);
        preview.style.display = 'block';
    }

    startBtn.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            msg.textContent = 'Your browser does not support the camera. Please open this page in an updated browser such as Chrome.';
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                video.style.display = 'block';
                preview.style.display = 'none';
                snapBtn.disabled = false;
                msg.textContent = 'Camera is on. Click "Take photo" when your pet is in view.';
            })
            .catch(function () {
                msg.textContent = 'Could not open the camera. Please allow camera access in your browser settings and try again.';
            });
    });

    snapBtn.addEventListener('click', function () {
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob(function (blob) {
            var file = new File([blob], 'live_update.jpg', { type: 'image/jpeg' });
            var dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            sourceInput.value = 'camera';
            showPreview(blob);
            stopCamera();
            msg.textContent = 'Photo captured. Click "Start camera" to retake it.';
        }, 'image/jpeg', 0.85);
    });

    form.addEventListener('submit', function (e) {
        if (!fileInput.files.length) {
            e.preventDefault();
            alert('Please take a live photo with the camera first.');
        }
    });
})();
</script>

</body>
</html>