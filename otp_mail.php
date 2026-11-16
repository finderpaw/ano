<?php
// otp_mail.php - sends the verification code by Gmail (PHPMailer)
require_once __DIR__ . '/mail_config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function send_otp_email(string $to, string $name, string $code): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'Your Pawfinder verification code';
        $mail->Body =
            '<div style="font-family:Arial,sans-serif;max-width:420px">' .
            '<h2>Verify your email</h2>' .
            '<p>Hi ' . $safeName . ', use this code to finish creating your Pawfinder account:</p>' .
            '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f0fdfa;padding:14px;text-align:center;border-radius:8px">' .
            $code . '</p>' .
            '<p>This code expires in 10 minutes. If you did not request it, you can ignore this email.</p>' .
            '</div>';
        $mail->AltBody = 'Your Pawfinder verification code is ' . $code . '. It expires in 10 minutes.';

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('OTP email failed: ' . $e->getMessage());
        return false;
    }
}