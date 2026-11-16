<?php
session_start();
require_once 'config.php';
require_once 'send_mail.php';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header("Location: $base/login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !in_array($_SESSION['role'] ?? '', ['local', 'visitor'], true)) {
    header("Location: $base/homepage.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT fullname, username, email, contact, address, rescuer_status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    header("Location: $base/homepage.php");
    exit();
}

if (in_array($u['rescuer_status'] ?? '', ['Pending', 'Approved'], true)) {
    $_SESSION['success'] = "You already have a rescuer request on file.";
    header("Location: $base/homepage.php");
    exit();
}

$token   = bin2hex(random_bytes(32));
$hash    = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', strtotime('+3 days'));

$up = $conn->prepare("UPDATE users SET rescuer_status = 'Pending', rescuer_token = ?, rescuer_token_expires = ? WHERE id = ?");
$up->bind_param("ssi", $hash, $expires, $user_id);
$up->execute();
$up->close();

$ntitle = "New Rescuer Application: " . $u['username'];
$nmsg   = $u['fullname'] . " (" . $u['email'] . ") applied to become a Local Rescuer.";
$ntype  = 'rescuer_request';

$n = $conn->prepare("INSERT INTO notifications (title, message, audience, type, reference_id)
                     VALUES (?, ?, 'admin', ?, ?)");
$n->bind_param("sssi", $ntitle, $nmsg, $ntype, $user_id);
$n->execute();
$n->close();

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$link = SITE_URL . '/approve_rescuer.php?token=' . $token;

$html = '
<div style="font-family:Arial,sans-serif;max-width:520px">
  <h2 style="color:#0b5a6b;margin-bottom:4px">New Local Rescuer request</h2>
  <p>Someone wants to become a Local Rescuer on Pawfinder.</p>
  <table style="border-collapse:collapse;font-size:14px">
    <tr><td style="padding:4px 12px 4px 0;color:#666">Name</td><td><b>' . $e($u['fullname']) . '</b></td></tr>
    <tr><td style="padding:4px 12px 4px 0;color:#666">Username</td><td>' . $e($u['username']) . '</td></tr>
    <tr><td style="padding:4px 12px 4px 0;color:#666">Email</td><td>' . $e($u['email']) . '</td></tr>
    <tr><td style="padding:4px 12px 4px 0;color:#666">Contact</td><td>' . $e($u['contact']) . '</td></tr>
    <tr><td style="padding:4px 12px 4px 0;color:#666">Address</td><td>' . $e($u['address']) . '</td></tr>
  </table>
  <p style="margin-top:20px">
    <a href="' . $e($link) . '" style="background:#0b5a6b;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Review request</a>
  </p>
  <p style="color:#888;font-size:12px">This link expires in 3 days and can only be used once. If you did not expect this, ignore this email.</p>
</div>';

$sent = send_mail(ADMIN_EMAIL, 'Pawfinder: new Local Rescuer request from ' . $u['username'], $html);

$_SESSION['success'] = $sent
    ? "Request sent! The admin was notified by email and will review your application."
    : "Request saved! The admin will review it, but the email notification could not be sent.";

header("Location: $base/homepage.php");
exit();