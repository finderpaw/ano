<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function back(string $msg): void
{
    $_SESSION['success'] = $msg;
    header('Location: homepage.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals($_SESSION['csrf_profile'] ?? '', $_POST['csrf'] ?? '')) {
    back('Invalid request.');
}

$f = $_FILES['photo'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) back('Upload failed. Please try again.');
if ($f['size'] > 2 * 1024 * 1024) back('Image must be 2MB or smaller.');

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
$ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
if (!$ext || !@getimagesize($f['tmp_name'])) back('Only JPG, PNG, or WEBP images are allowed.');

$uid = (int)$_SESSION['user_id'];
$dir = __DIR__ . '/uploads/avatars';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$name = 'u' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) back('Could not save the image.');

// Burahin ang lumang photo
$st = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
$st->bind_param("i", $uid);
$st->execute();
$old = $st->get_result()->fetch_assoc()['profile_photo'] ?? '';
$st->close();
if ($old && strpos($old, 'uploads/avatars/') === 0 && is_file(__DIR__ . '/' . $old)) {
    @unlink(__DIR__ . '/' . $old);
}

$path = 'uploads/avatars/' . $name;
$st = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
$st->bind_param("si", $path, $uid);
$st->execute();
$st->close();

back('Profile picture updated.');