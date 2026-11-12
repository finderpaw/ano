<?php
/* Opened from the link in the admin's email. The secret token in the link is the permission. */
require_once 'config.php';
require_once 'send_mail.php';

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$hash  = hash('sha256', $token);

$user = null;
if ($token !== '') {
    $stmt = $conn->prepare(
        "SELECT id, fullname, username, email, contact, address FROM users
         WHERE rescuer_token = ? AND rescuer_token_expires > NOW()
           AND rescuer_status = 'Pending' AND LOWER(role) IN ('local','visitor') LIMIT 1"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$up = null;
$msg = '';
$done = '';
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'] ?? '';
    if ($decision === 'approve') {
        $up = $conn->prepare("UPDATE users SET role = 'Rescuer', rescuer_status = 'Approved', rescuer_token = NULL, rescuer_token_expires = NULL WHERE id = ?");
        $msg = 'Great news! Your request to become a Local Rescuer was approved. Please log out and log in again to open the Rescuer Dashboard.';
        $done = 'approved';
    } elseif ($decision === 'reject') {
        $up = $conn->prepare("UPDATE users SET rescuer_status = 'Rejected', rescuer_token = NULL, rescuer_token_expires = NULL WHERE id = ?");
        $msg = 'Thank you for your interest. Your request to become a Local Rescuer was not approved at this time.';
        $done = 'rejected';
    }
    if ($done) {
        $up->bind_param("i", $user['id']);
        $up->execute();
        $up->close();
        if (!empty($user['email'])) {
            send_mail($user['email'], 'Pawfinder: your Local Rescuer request', '<p>Hi ' . $e($user['fullname']) . ',</p><p>' . $e($msg) . '</p>');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Review rescuer request - Pawfinder</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f5;margin:0;display:grid;place-items:center;min-height:100vh;padding:16px}
.box{background:#fff;border-radius:14px;padding:28px;max-width:460px;width:100%;box-shadow:0 4px 18px rgba(0,0,0,.08)}
h1{margin:0 0 6px;font-size:20px;color:#0b5a6b}p{color:#555;font-size:14px}
dl{display:grid;grid-template-columns:90px 1fr;gap:8px 10px;font-size:14px;margin:18px 0}dt{color:#777}dd{margin:0;font-weight:bold;word-break:break-word}
form{display:flex;gap:10px;margin-top:8px}
button{flex:1;padding:11px;border:0;border-radius:8px;font-size:14px;font-weight:bold;cursor:pointer;color:#fff}
.ok{background:#1f7a4d}.no{background:#b4442f}button:focus-visible{outline:2px solid #0b5a6b;outline-offset:2px}
a{color:#0b5a6b}
</style>
</head>
<body>
<div class="box">
<?php if ($done === 'approved'): ?>
    <h1>Request approved</h1>
    <p><b><?= $e($user['username']) ?></b> is now a Local Rescuer. They were notified by email and must log in again to see the Rescuer Dashboard.</p>
    <p><a href="admin/admin.php">Go to admin dashboard</a></p>
<?php elseif ($done === 'rejected'): ?>
    <h1>Request rejected</h1>
    <p>The request from <b><?= $e($user['username']) ?></b> was rejected and they were notified by email.</p>
    <p><a href="admin/admin.php">Go to admin dashboard</a></p>
<?php elseif ($user): ?>
    <h1>Local Rescuer request</h1>
    <p>Review the details, then approve or reject.</p>
    <dl>
        <dt>Name</dt><dd><?= $e($user['fullname']) ?></dd>
        <dt>Username</dt><dd><?= $e($user['username']) ?></dd>
        <dt>Email</dt><dd><?= $e($user['email']) ?></dd>
        <dt>Contact</dt><dd><?= $e($user['contact']) ?></dd>
        <dt>Address</dt><dd><?= $e($user['address']) ?></dd>
    </dl>
    <form method="post">
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <button class="ok" name="decision" value="approve">Approve</button>
        <button class="no" name="decision" value="reject" onclick="return confirm('Reject this request?')">Reject</button>
    </form>
<?php else: ?>
    <h1>Link not valid</h1>
    <p>This link has expired, was already used, or is incorrect. If the person still wants to be a rescuer, ask them to send a new request.</p>
    <p><a href="homepage.php">Back to homepage</a></p>
<?php endif; ?>
</div>
</body>
</html>