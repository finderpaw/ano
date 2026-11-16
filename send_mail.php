<?php
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/* returns true if the email was sent, false if not (the error is written to the PHP error log) */
function send_mail($to, $subject, $html) {
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host       = SMTP_HOST;
        $m->SMTPAuth   = true;
        $m->Username   = SMTP_USER;
        $m->Password   = SMTP_PASS;
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $m->Port       = SMTP_PORT;
        $m->CharSet    = 'UTF-8';

        $m->setFrom(SMTP_USER, MAIL_FROM_NAME);
        $m->addAddress($to);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = $html;
        $m->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $html));
        $m->send();
        return true;
    } catch (Throwable $e) {
        error_log('Pawfinder mail error: ' . $e->getMessage());
        return false;
    }
}