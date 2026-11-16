<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/config.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug   = 2;
    $mail->Debugoutput = 'html';

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_APP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;  // 465
    $mail->Port       = 465;

    $mail->setFrom(MAIL_USER, 'Pawfinder');
    $mail->addAddress(ADMIN_NOTIFY_EMAIL);
    $mail->Subject = 'Test Pawfinder';
    $mail->Body    = 'Kung nababasa mo ito, gumagana na.';

    $mail->send();
    echo '<h3 style="color:green">SENT!</h3>';
} catch (Exception $e) {
    echo '<h3 style="color:red">FAILED: ' . htmlspecialchars($mail->ErrorInfo) . '</h3>';
}