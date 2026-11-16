<?php
// my_application.php - the user sees the result of their own adoption application

// my_application.php - nakikita ng user ang resulta ng sarili niyang adoption application

session_start();
require_once 'config.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid   = (int)$_SESSION['user_id'];
$reqId = (int)($_GET['request_id'] ?? 0);

if ($reqId <= 0) {
    header('Location: notifications.php');
    exit();
}


$st = $conn->prepare(
    "SELECT r.id, r.status, r.created_at, r.pet_id,
            p.pet_name, p.pet_species, p.breed, p.pet_photo,
            u.fullname, u.email, u.contact
     FROM adoption_requests r
     LEFT JOIN pets  p ON p.id = r.pet_id
     LEFT JOIN users u ON u.id = r.user_id
     WHERE r.id = ? AND r.user_id = ?"
);
$st->bind_param("ii", $reqId, $uid);
$st->execute();
$req = $st->get_result()->fetch_assoc();
$st->close();

if (!$req) {
    header('Location: notifications.php');
    exit();
}


$app = null;
try {
    $st = $conn->prepare("SELECT answers, id_type, signature_name, agreed_at FROM adoption_applications WHERE request_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param("ii", $reqId, $uid);
    $st->execute();
    $app = $st->get_result()->fetch_assoc();
    $st->close();
} catch (Throwable $e) {
    $app = null;
}

$sections = [];
if ($app) {
    foreach (json_decode((string)$app['answers'], true) ?: [] as $a) {
        $sec = $a['s'] ?? 'Other';
        if ($sec === 'Declarations & consent') continue;
        $sections[$sec][] = $a;
    }
}

$status  = (string)$req['status'];
$kind    = strtolower($status);   // pending | approved | rejected
$petName = $req['pet_name'] ?? 'the pet';
$petPhoto = (string)($req['pet_photo'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PAWFINDER - My Adoption Application</title>
    <link rel="stylesheet" href="css/homepage.css">
    <style>
        .ma-wrap{max-width:820px;margin:0 auto}
        .ma-back{display:inline-block;margin-bottom:14px;color:#0d9488;font-weight:600;text-decoration:none;font-size:14px}
        .ma-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px}
        .ma-pet{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
        .ma-pet img{width:84px;height:84px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0}
        .ma-pet h1{font-size:22px;margin:0 0 4px;color:#0f172a}
        .ma-muted{color:#64748b;font-size:13px}
        .ma-result{border-radius:14px;padding:22px 24px;margin-bottom:18px;border:1px solid;display:flex;gap:16px;align-items:flex-start}
        .ma-result .ico{flex:0 0 46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700}
        .ma-result h2{margin:0 0 6px;font-size:20px}
        .ma-result p{margin:0;font-size:14.5px;line-height:1.55}
        .ma-result.approved{background:#f0fdf4;border-color:#bbf7d0;color:#14532d}
        .ma-result.approved .ico{background:#dcfce7;color:#166534}
        .ma-result.rejected{background:#fef2f2;border-color:#fecaca;color:#7f1d1d}
        .ma-result.rejected .ico{background:#fee2e2;color:#991b1b}
        .ma-result.pending{background:#fffbeb;border-color:#fde68a;color:#78350f}
        .ma-result.pending .ico{background:#fef3c7;color:#92400e}
        .ma-btn{display:inline-block;margin-top:14px;text-decoration:none;background:#0d9488;color:#fff;font-weight:700;font-size:14px;padding:10px 18px;border-radius:10px}
        .ma-btn:hover{background:#0b7c72}
        .ma-card h3{margin:0 0 10px;font-size:15px;color:#0d9488;text-transform:uppercase;letter-spacing:.04em}
        .ma-table{width:100%;border-collapse:collapse;font-size:14px}
        .ma-table td{padding:8px 6px;border-top:1px solid #f1f5f9;vertical-align:top}
        .ma-table tr:first-child td{border-top:0}
        .ma-table td.q{width:42%;color:#64748b}
        .ma-table td.a{white-space:pre-wrap;word-break:break-word}
        details.ma-more summary{cursor:pointer;font-weight:700;color:#0b5a6b;font-size:15px}
        details.ma-more[open] summary{margin-bottom:14px}
        .ma-sec{margin-bottom:18px}
    </style>

    <!-- Dark mode overrides (must come after the styles above) -->
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <!-- Applies the saved dark mode (must be right after the opening body tag) -->
    <script src="js/darkmode.js"></script>

</head>
<body>

    <?php include 'header.php'; ?>

    <main class="content-area">
        <div class="ma-wrap">
            <a class="ma-back" href="notifications.php">&larr; Back to notifications</a>

            <!-- PET -->
            <div class="ma-card">
                <div class="ma-pet">
                    <?php if ($petPhoto !== ''): ?><img src="<?= h($petPhoto) ?>" alt=""><?php endif; ?>
                    <div>
                        <h1>Application for <?= h($petName) ?></h1>
                        <div class="ma-muted">
                            <?= h(($req['pet_species'] ?? '') . ' · ' . ($req['breed'] ?? '')) ?>
                            &middot; Submitted <?= h(date('M j, Y g:i A', strtotime($req['created_at']))) ?>
                        </div>
                    </div>
                </div>
            </div>

       
   
            <?php if ($kind === 'approved'): ?>
                <div class="ma-result approved" role="status">
                    <div class="ico" aria-hidden="true">&#10003;</div>
                    <div>
                        <h2>Approved!</h2>
                        <p>Congratulations! Your adoption application for <b><?= h($petName) ?></b> was approved.
                           The admin will contact you using your registered details to arrange the next steps.</p>
                        <p class="ma-muted" style="margin-top:8px;color:inherit;opacity:.8">
                            Email: <?= h($req['email'] ?? '') ?>
                            <?php if (!empty($req['contact'])): ?> &middot; Contact: <?= h($req['contact']) ?><?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php elseif ($kind === 'rejected'): ?>
                <div class="ma-result rejected" role="status">
                    <div class="ico" aria-hidden="true">&#10005;</div>
                    <div>
                        <h2>Not approved</h2>
                        <p>Sorry, your adoption application for <b><?= h($petName) ?></b> was not approved this time.
                           This can happen when another adopter was chosen or when the admin needs more assurance for the pet's safety.
                           You are welcome to apply for other pets.</p>
                        <a class="ma-btn" href="adopt.php">Browse pets for adoption</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="ma-result pending" role="status">
                    <div class="ico" aria-hidden="true">&#8987;</div>
                    <div>
                        <h2>Under review</h2>
                        <p>Your application for <b><?= h($petName) ?></b> is still being reviewed by the admin. You will get a notification once there is a decision.</p>
                    </div>
                </div>
            <?php endif; ?>

         
 
            <?php if ($app && $sections): ?>
                <div class="ma-card">
                    <details class="ma-more">
                        <summary>View my submitted application</summary>
                        <?php foreach ($sections as $title => $rows): ?>
                            <div class="ma-sec">
                                <h3><?= h($title) ?></h3>
                                <table class="ma-table">
                                    <?php foreach ($rows as $r): ?>
                                        <tr><td class="q"><?= h($r['q'] ?? '') ?></td><td class="a"><?= h($r['a'] ?? '') ?></td></tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endforeach; ?>
                        <div class="ma-sec">
                            <h3>Verification</h3>
                            <table class="ma-table">
                                <tr><td class="q">Typed signature</td><td class="a"><?= h($app['signature_name']) ?></td></tr>
                                <tr><td class="q">Signed on</td><td class="a"><?= h(date('M j, Y g:i A', strtotime($app['agreed_at']))) ?></td></tr>
                            </table>
                        </div>
                    </details>
                </div>
            <?php endif; ?>
        </div>
    </main>

</body>
</html>
