<?php
session_start();
require_once '../config.php';
require_once __DIR__ . '/mail_helper.php';

if (($_SESSION['role'] ?? '') !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
$approve = ($_POST['action'] ?? '') === 'approve';
$status  = $approve ? 'Approved' : 'Rejected';

$s = $conn->prepare("UPDATE users
                     SET rescuer_status = ?, rescuer_token = NULL, rescuer_token_expires = NULL
                     WHERE id = ? AND rescuer_status = 'Pending'");
$s->bind_param("si", $status, $user_id);
$s->execute();
$changed = $s->affected_rows > 0;
$s->close();

if ($changed && $approve) {
    $r = $conn->prepare("UPDATE users SET role = 'rescuer' WHERE id = ?");
    $r->bind_param("i", $user_id);
    $r->execute();
    $r->close();
}

// Send the result email to the applicant (copy to admin)
if ($changed) {
    $u = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $u->bind_param("i", $user_id);
    $u->execute();
    $user = $u->get_result()->fetch_assoc();
    $u->close();

    if ($user) {
        $email = $user['email'] ?? '';
        $name  = $user['full_name'] ?? $user['fullname'] ?? $user['name'] ?? $user['username'] ?? '';

        if ($approve) {
            send_status_email(
                $email, $name,
                'Your Local Rescuer application was approved',
                'Application Approved',
                'Great news! Your application to become a <b>Local Rescuer</b> on Pawfinder has been <b>approved</b>. You can now log in and start posting pets in need of help.',
                true
            );
        } else {
            send_status_email(
                $email, $name,
                'Update on your Local Rescuer application',
                'Application Not Approved',
                'Thank you for applying to become a <b>Local Rescuer</b>. Unfortunately, your application was <b>not approved</b> at this time. You are welcome to contact the admin if you have questions.',
                false
            );
        }
    }
}

header("Location: notification.php");
exit;