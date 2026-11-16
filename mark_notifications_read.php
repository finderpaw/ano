<?php
session_start();
require_once 'config.php';

if (!empty($_SESSION['user_id']) && hash_equals($_SESSION['csrf_profile'] ?? '', $_POST['csrf'] ?? '')) {
    $uid = (int)$_SESSION['user_id'];
    $st = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $st->bind_param("i", $uid);
    $st->execute();
    $st->close();
}
http_response_code(204);