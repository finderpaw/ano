<?php
/**
 * Mail helper (PHPMailer + Gmail SMTP)
 * Ilagay sa admin/ folder, katabi ng notification.php at rescuer_action.php
 */

// load mail_config.php (SMTP_USER, SMTP_PASS, ADMIN_EMAIL, SITE_URL, ...)
foreach ([__DIR__ . '/../mail_config.php', __DIR__ . '/mail_config.php'] as $cfg) {
    if (file_exists($cfg)) { require_once $cfg; break; }
}

// load PHPMailer from your PHPMailer folder
foreach ([__DIR__ . '/../PHPMailer/src/', __DIR__ . '/PHPMailer/src/', __DIR__ . '/../PHPMailer/'] as $dir) {
    if (file_exists($dir . 'PHPMailer.php')) {
        require_once $dir . 'Exception.php';
        require_once $dir . 'PHPMailer.php';
        require_once $dir . 'SMTP.php';
        break;
    }
}

/**
 * Send an approve/reject email to the applicant (BCC copy to the admin).
 * Returns true if sent, false if not (it never throws, so approval won't break).
 */
function send_status_email(string $to, string $name, string $subject, string $heading, string $body, bool $approved): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('send_status_email: invalid recipient "' . $to . '"');
        return false;
    }
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        error_log('send_status_email: PHPMailer not found');
        return false;
    }

    $color   = $approved ? '#0b5a6b' : '#b42318';
    $safeNm  = htmlspecialchars($name !== '' ? $name : 'there');
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';

    $html = '
    <div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
        <div style="background:' . $color . ';color:#fff;padding:16px 20px;font-size:18px;font-weight:bold">PAWFINDER</div>
        <div style="padding:20px;color:#111827;font-size:15px;line-height:1.5">
            <h2 style="margin:0 0 12px;color:' . $color . '">' . htmlspecialchars($heading) . '</h2>
            <p>Hi ' . $safeNm . ',</p>
            <p>' . $body . '</p>'
            . ($siteUrl !== '' ? '<p><a href="' . htmlspecialchars($siteUrl) . '" style="background:' . $color . ';color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">Go to Pawfinder</a></p>' : '') . '
            <p style="color:#6b7280;font-size:13px">Thank you for being part of the Pawfinder community.</p>
        </div>
    </div>';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = str_replace(' ', '', SMTP_PASS);   // tanggal ang spaces ng App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->CharSet    = 'UTF-8';

        // para gumana sa XAMPP/localhost (SSL certificate issue). Alisin pag naka-hosting na.
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]];

        $mail->setFrom(SMTP_USER, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Pawfinder');
        $mail->addAddress($to, $name);
        if (defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '') {
            $mail->addBCC(ADMIN_EMAIL);   // copy sa admin
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(str_replace('</p>', "\n", $body));

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('send_status_email failed: ' . $e->getMessage());
        return false;
    }
}