<?php
// pawfinder/admin/view_application.php
require_once __DIR__ . '/admin_guard.php';

$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

$requestId = (int)($_GET['request_id'] ?? 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$q = $conn->prepare("
    SELECT a.*, p.pet_name, p.pet_species, p.breed, ar.status AS req_status,
           u.fullname, u.email, u.contact, u.address
    FROM adoption_applications a
    JOIN adoption_requests ar ON ar.id = a.request_id
    JOIN pets  p ON p.id = a.pet_id
    JOIN users u ON u.id = a.user_id
    WHERE a.request_id = ?
    LIMIT 1
");
$q->bind_param("i", $requestId);
$q->execute();
$app = $q->get_result()->fetch_assoc();
$q->close();

/* Ligtas na pagpapakita ng ID / larawan: admin lang (dahil sa admin_guard), galing sa private folder */
if ($app && isset($_GET['file'])) {
    $col  = ($_GET['file'] === 'home') ? 'home_photo' : 'id_file';
    $name = basename((string)$app[$col]);
    $path = dirname(__DIR__) . '/uploads/ids/' . $name;

    if ($name === '' || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        http_response_code(403);
        exit('Not allowed.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit();
}

$answers = $app ? (json_decode($app['answers'], true) ?: []) : [];
$flags   = $app ? (json_decode($app['flags'], true) ?: []) : [];

$grouped = [];
foreach ($answers as $row) {
    $grouped[$row['s']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Application Details</title>
<link rel="stylesheet" href="admin.css">
<style>
    .sec { background:#fff; padding:20px; border-radius:8px; margin-bottom:16px; }
    .sec h3 { margin-bottom:10px; color:#0b5a6b; }
    .qa { display:grid; grid-template-columns: 38% 1fr; gap:10px; padding:8px 0; border-top:1px solid #f1f5f9; font-size:14px; }
    .qa .q { color:#64748b; }
    .qa .a { color:#0f172a; white-space:pre-wrap; word-break:break-word; }
    .flag { background:#fef3c7; color:#92400e; padding:6px 10px; border-radius:6px; margin-bottom:6px; font-size:13px; }
    .ok   { background:#dcfce7; color:#166534; padding:8px 12px; border-radius:6px; font-size:13px; }
    .idimg { max-width:100%; max-height:520px; border:1px solid #e2e8f0; border-radius:8px; }
</style>
</head>
<body>
<div class="app" style="display:block; padding:20px; max-width:900px; margin:0 auto;">
<p><a href="adoption_requests.php">&larr; Adoption Requests</a> &nbsp;|&nbsp; <a href="notification.php">Notifications</a></p>

<?php if (!$app): ?>
    <div class="sec"><p>No application found for this request. Older requests were made before the application form existed.</p></div>
<?php else: ?>

    <div class="sec">
        <h2><?php echo h($app['pet_name']); ?> <small style="font-weight:normal;color:#777;">(<?php echo h($app['pet_species'] . ', ' . $app['breed']); ?>)</small></h2>
        <p style="margin-top:6px;">Applicant: <b><?php echo h($app['fullname']); ?></b> &middot; <?php echo h($app['email']); ?> &middot; <?php echo h($app['contact']); ?></p>
        <p style="color:#777; font-size:13px; margin-top:4px;">
            Status: <b><?php echo h($app['req_status']); ?></b> &middot;
            Signed as "<?php echo h($app['signature_name']); ?>" on <?php echo h(date('M j, Y g:i A', strtotime($app['agreed_at']))); ?>
            &middot; IP <?php echo h($app['ip_address']); ?>
        </p>
    </div>

    <div class="sec">
        <h3>Red flags</h3>
        <?php if ($flags): foreach ($flags as $f) echo '<div class="flag">&#9888; ' . h($f) . '</div>'; ?>
        <?php else: ?><div class="ok">No red flags from the answers. Still verify the ID.</div><?php endif; ?>
    </div>

    <div class="sec">
        <h3>Valid ID (<?php echo h($app['id_type']); ?>)</h3>
        <?php
        $idUrl = 'view_application.php?request_id=' . $requestId . '&file=id';
        if (strtolower(pathinfo($app['id_file'], PATHINFO_EXTENSION)) === 'pdf'): ?>
            <a href="<?php echo h($idUrl); ?>" target="_blank" rel="noopener">Open ID (PDF)</a>
        <?php else: ?>
            <img class="idimg" src="<?php echo h($idUrl); ?>" alt="Uploaded ID">
        <?php endif; ?>
        <p style="font-size:12px;color:#777;margin-top:8px;">Check that the name matches the account name and the ID is not expired.</p>
    </div>

    <?php if (!empty($app['home_photo'])): ?>
    <div class="sec">
        <h3>Home photo</h3>
        <img class="idimg" src="view_application.php?request_id=<?php echo $requestId; ?>&file=home" alt="Home photo">
    </div>
    <?php endif; ?>

    <?php foreach ($grouped as $section => $rows): ?>
    <div class="sec">
        <h3><?php echo h($section); ?></h3>
        <?php foreach ($rows as $r): ?>
            <div class="qa"><div class="q"><?php echo h($r['q']); ?></div><div class="a"><?php echo h($r['a']); ?></div></div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

<?php endif; ?>
</div>
</body>
</html>