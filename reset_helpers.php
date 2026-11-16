<?php
// Gamit ang parehong Gmail/PHPMailer setup ng registration OTP (mail_config.php + otp_mail.php)
require_once __DIR__ . '/otp_mail.php';

/* ============ SETTINGS ============ */
const RESET_CODE_MINUTES   = 10;   // gaano katagal valid ang code
const RESET_MAX_ATTEMPTS   = 5;    // ilang maling subok bago ma-lock ang code
const RESET_COOLDOWN_SECONDS = 60; // minimum na hintay bago makapag-request ulit ng code
const RESET_USER_PASSWORD_COLUMN = 'password'; // palitan kung iba ang pangalan ng password column sa users table

/* ================================== */

function rp_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rp_ensure_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS password_reset_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id)
    )");
}

function rp_hash_code(string $code, int $userId): string
{
    return hash_hmac('sha256', $code, 'pawfinder-reset-' . $userId);
}

function rp_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) { return $email; }
    $name = $parts[0];
    return mb_substr($name, 0, 1) . str_repeat('*', max(2, mb_strlen($name) - 1)) . '@' . $parts[1];
}

function rp_is_local(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function rp_send_code_email(string $to, string $name, string $code): bool
{
    $mins     = RESET_CODE_MINUTES;
    $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = 'Your Pawfinder password reset code';
        $mail->Body =
            '<div style="font-family:Arial,sans-serif;max-width:420px">' .
            '<h2>Reset your password</h2>' .
            '<p>Hi ' . $safeName . ', use this code to reset your Pawfinder password:</p>' .
            '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f0fdfa;padding:14px;text-align:center;border-radius:8px">' .
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p>This code expires in ' . $mins . ' minutes. Never share it with anyone. If you did not request it, you can ignore this email and your password will not change.</p>' .
            '</div>';
        $mail->AltBody = 'Your Pawfinder password reset code is ' . $code . '. It expires in ' . $mins . ' minutes.';

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('RESET email failed: ' . $e->getMessage());
        return false;
    }
}

function rp_page_start(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pawfinder - <?= rp_h($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Plus Jakarta Sans', sans-serif; }
        .login-box { max-width: 450px; margin: 40px auto; padding: 0 15px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.06); }
        .form-control { padding: 10px 14px; border-radius: 8px; border: 1px solid #dee2e6; }
        .form-control:focus { box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
        .code-input { font-size: 26px; letter-spacing: 10px; text-align: center; font-weight: 700; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="card">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <h3 class="fw-bold text-dark mb-1">Pawfinder</h3>
                <p class="text-muted small mb-0">Pet Adoption, Emergency Rescue &amp; Medical Services</p>
            </div>
<?php
}

function rp_page_end(): void
{
    ?>
        </div>
    </div>
</div>
</body>
</html>
<?php
}