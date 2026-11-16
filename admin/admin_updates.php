<?php
// admin_updates.php - admin list of weekly updates, with "Needs review" flags
require_once __DIR__ . '/admin_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;
require_once __DIR__ . '/admin_notify_helper.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['an_csrf'])) { $_SESSION['an_csrf'] = bin2hex(random_bytes(16)); }
an_ensure_columns($conn);

/* Mark all as read */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all'
    && hash_equals($_SESSION['an_csrf'], $_POST['csrf'] ?? '')) {
    $conn->query("UPDATE adoption_updates SET admin_seen = 1 WHERE admin_seen = 0");
    header('Location: admin_updates.php');
    exit;
}

/* Opening an update from the bell marks that one as seen */
$focus = (int)($_GET['id'] ?? 0);
if ($focus > 0) {
    $st = $conn->prepare("UPDATE adoption_updates SET admin_seen = 1 WHERE id = ?");
    $st->bind_param("i", $focus);
    $st->execute();
    $st->close();
}

$onlyReview = (($_GET['filter'] ?? '') === 'review');
$nm = an_user_parts($conn);

$rows = [];
$total = 0;
$unseen = 0;
try {
    $r = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(admin_seen = 0),0) n FROM adoption_updates");
    if ($r && ($x = $r->fetch_assoc())) { $total = (int)$x['c']; $unseen = (int)$x['n']; }

    $where = $onlyReview ? "WHERE u.needs_review = 1" : "";
    $sql = "SELECT u.id, u.photo, u.source, u.health_status, u.appetite, u.notes, u.ai_score,
                   u.needs_review, u.review_reason, u.admin_seen, u.created_at,
                   p.pet_name, {$nm['expr']} AS adopter
            FROM adoption_updates u
            LEFT JOIN pets p ON p.id = u.pet_id
            {$nm['join']}
            $where
            ORDER BY u.id DESC
            LIMIT 50";
    $r = $conn->query($sql);
    while ($r && ($x = $r->fetch_assoc())) { $rows[] = $x; }
} catch (Throwable $e) {
    error_log('admin_updates: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Weekly Updates</title>
<style>
    *{box-sizing:border-box}
    body{margin:0;background:#f1f5f9;font-family:"Hanken Grotesk",system-ui,sans-serif;color:#0f172a}
    .wrap{max-width:980px;margin:0 auto;padding:24px 16px}
    .bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px}
    .bar h1{margin:0;font-size:24px}
    .back{color:#0d9488;font-weight:600;text-decoration:none;font-size:14px}
    .tools{display:flex;gap:8px;flex-wrap:wrap}
    .btn{display:inline-block;background:#0d9488;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:600 13px inherit;text-decoration:none;cursor:pointer}
    .btn.alt{background:#fff;color:#0d9488;border:1px solid #99e6da}
    .btn.on{background:#991b1b;border-color:#991b1b;color:#fff}
    .card{display:flex;gap:16px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-bottom:12px}
    .card.new{border-left:5px solid #0d9488}
    .card.focus{outline:3px solid #99e6da}
    .card.flag{border-left:5px solid #dc2626}
    .card img{width:120px;height:120px;object-fit:cover;border-radius:10px;flex:0 0 120px;background:#e2e8f0}
    .card h2{margin:0 0 4px;font-size:16px}
    .muted{color:#64748b;font-size:13px}
    .tag{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:2px 10px;margin:0 6px 0 0;background:#ccfbf1;color:#0f766e}
    .tag.warn{background:#fee2e2;color:#991b1b}
    .tag.new{background:#dbeafe;color:#1e40af}
    .reason{margin-top:8px;font-size:13px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:8px 10px}
    .empty{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:30px;text-align:center;color:#64748b}
    @media(max-width:600px){.card{flex-direction:column}.card img{width:100%;height:200px;flex:none}}
</style>
</head>
<body>
<div class="wrap">
    <a class="back" href="admin.php">&larr; Back to dashboard</a>

    <div class="bar" style="margin-top:10px">
        <div>
            <h1>Weekly updates</h1>
            <div class="muted"><?= (int)$total ?> total &middot; <?= (int)$unseen ?> new</div>
        </div>
        <div class="tools">
            <a class="btn alt <?= $onlyReview ? '' : 'on' ?>" href="admin_updates.php" style="<?= $onlyReview ? '' : 'background:#0d9488;color:#fff' ?>">All</a>
            <a class="btn alt" href="admin_updates.php?filter=review" style="<?= $onlyReview ? 'background:#991b1b;border-color:#991b1b;color:#fff' : '' ?>">Needs review</a>
            <form method="POST" style="margin:0">
                <input type="hidden" name="action" value="read_all">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['an_csrf']) ?>">
                <button class="btn" type="submit">Mark all as read</button>
            </form>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty"><?= $onlyReview ? 'No updates need review.' : 'No weekly updates yet.' ?></div>
    <?php endif; ?>

    <?php foreach ($rows as $u):
        $isNew  = ((int)$u['admin_seen'] === 0);
        $isFlag = ((int)$u['needs_review'] === 1);
        $photo  = an_photo_url($u['photo']);
        $cls    = 'card' . ($isFlag ? ' flag' : ($isNew ? ' new' : '')) . ((int)$u['id'] === $focus ? ' focus' : '');
    ?>
        <div class="<?= $cls ?>" id="u<?= (int)$u['id'] ?>">
            <?php if ($photo): ?>
                <a href="<?= h($photo) ?>" target="_blank" rel="noopener"><img src="<?= h($photo) ?>" alt=""></a>
            <?php endif; ?>
            <div style="flex:1;min-width:0">
                <h2><?= h($u['adopter'] ?? 'Adopter') ?> &rarr; <?= h($u['pet_name'] ?? 'Pet') ?></h2>
                <div class="muted"><?= h(date('M j, Y g:i A', strtotime($u['created_at']))) ?></div>
                <div style="margin-top:8px">
                    <?php if ($isNew): ?><span class="tag new">New</span><?php endif; ?>
                    <span class="tag"><?= $u['source'] === 'camera' ? 'Live camera' : 'Uploaded' ?></span>
                    <?php if ($isFlag): ?><span class="tag warn">Needs review</span><?php endif; ?>
                    <?php if (($u['health_status'] ?? 'Healthy') !== 'Healthy'): ?><span class="tag warn"><?= h($u['health_status']) ?></span><?php endif; ?>
                </div>
                <div class="muted" style="margin-top:8px">
                    Health: <?= h($u['health_status']) ?> &middot; Appetite: <?= h($u['appetite']) ?>
                    <?php if ($u['ai_score'] !== null): ?> &middot; AI score: <?= h(number_format((float)$u['ai_score'], 2)) ?><?php endif; ?>
                </div>
                <?php if (!empty($u['notes'])): ?>
                    <div style="margin-top:8px;font-size:14px"><?= nl2br(h($u['notes'])) ?></div>
                <?php endif; ?>
                <?php if ($isFlag && !empty($u['review_reason'])): ?>
                    <div class="reason"><b>Reason:</b> <?= h($u['review_reason']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($focus > 0): ?>
<script>var f = document.getElementById('u<?= (int)$focus ?>'); if (f) f.scrollIntoView({block: 'center'});</script>
<?php endif; ?>
</body>
</html>