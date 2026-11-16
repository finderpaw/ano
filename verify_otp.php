<?php
session_start();
require_once 'config.php';
require_once 'otp_mail.php';

// Nothing to verify: go back to the form
if (empty($_SESSION['pending_reg'])) {
    header("Location: register.php");
    exit();
}

$pending = $_SESSION['pending_reg'];
$error   = '';
$info    = '';

$MAX_ATTEMPTS    = 5;
$RESEND_COOLDOWN = 60; // seconds

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? 'verify';

    // ----- Resend code -----
    if ($action === 'resend') {
        $wait = $RESEND_COOLDOWN - (time() - $pending['last_sent']);
        if ($wait > 0) {
            $error = "Please wait {$wait} seconds before requesting a new code.";
        } else {
            $code = (string)random_int(100000, 999999);
            $pending['otp_hash']  = password_hash($code, PASSWORD_DEFAULT);
            $pending['expires']   = time() + 600;
            $pending['attempts']  = 0;
            $pending['last_sent'] = time();

            if (send_otp_email($pending['email'], $pending['fullname'], $code)) {
                $_SESSION['pending_reg'] = $pending;
                $info = "A new code was sent to your email.";
            } else {
                $error = "We could not send the email. Please try again.";
            }
        }
    }

    // ----- Verify code -----
    else {
        $input = preg_replace('/\D/', '', $_POST['otp'] ?? '');

        if (time() > $pending['expires']) {
            $error = "This code has expired. Please request a new one.";
        } elseif ($pending['attempts'] >= $MAX_ATTEMPTS) {
            $error = "Too many wrong attempts. Please request a new code.";
        } elseif (!password_verify($input, $pending['otp_hash'])) {
            $pending['attempts']++;
            $_SESSION['pending_reg'] = $pending;
            $left = $MAX_ATTEMPTS - $pending['attempts'];
            $error = "Incorrect code. {$left} attempt(s) left.";
        } else {
            // Correct code: make sure the username/email were not taken in the meantime
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $check->bind_param("ss", $pending['username'], $pending['email']);
            $check->execute();
            $check->store_result();
            $taken = $check->num_rows > 0;
            $check->close();

            if ($taken) {
                unset($_SESSION['pending_reg']);
                $_SESSION['error'] = "Username or email already exists.";
                header("Location: register.php");
                exit();
            }

            $status = "Approved";
            $stmt = $conn->prepare(
                "INSERT INTO users
                 (fullname, email, address, contact, role, username, password, account_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssssssss",
                $pending['fullname'],
                $pending['email'],
                $pending['address'],
                $pending['contact'],
                $pending['role'],
                $pending['username'],
                $pending['password'],
                $status
            );

            if ($stmt->execute()) {
                $stmt->close();
                unset($_SESSION['pending_reg']);
                $_SESSION['success'] = "Email verified! Your account was created. You can now log in.";
                header("Location: login.php");
                exit();
            }

            error_log($stmt->error);
            $stmt->close();
            $error = "Registration failed. Please try again.";
        }
    }
}

// Hide part of the email, e.g. ju***@gmail.com
function mask_email(string $email): string
{
    [$name, $domain] = explode('@', $email, 2) + ['', ''];
    return substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pawfinder - Verify Email</title>
    <link rel="stylesheet" href="css/register.css">
    <style>
        .otp-input{letter-spacing:10px;font-size:26px;text-align:center;font-weight:700}
        .otp-resend{margin-top:16px;font-size:14px}
        .otp-resend button{background:none;border:0;color:#0d9488;font:inherit;font-weight:600;cursor:pointer;padding:0}
        .otp-resend button:hover{text-decoration:underline}
        .otp-info{background:#e2f3ea;color:#1f7a4d;border:1px solid #bfe3cf;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
    </style>
</head>

<body>

<div class="register-card">

    <div class="sidebar-left">
        <div>
            <div class="brand-logo">🐾 PAWFINDER</div>

            <ul class="step-list">
                <li class="step-item">✓ Account Setup</li>
                <li class="step-item active">○ Verify Email</li>
                <li class="step-item">○ Resident Details</li>
                <li class="step-item">○ Confirm</li>
            </ul>
        </div>

        <div>
            <div class="sidebar-img-container">
                <img src="image/animal.jpg" alt="Animal">
            </div>

            <div class="back-to-login">
                <a href="register.php">← Back to Registration</a>
            </div>
        </div>
    </div>

    <div class="form-right">

        <h2>Verify your email</h2>
        <p>We sent a 6-digit code to <b><?= htmlspecialchars(mask_email($pending['email'])) ?></b>. Enter it below. The code expires in 10 minutes.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($info !== ''): ?>
            <div class="otp-info"><?= htmlspecialchars($info) ?></div>
        <?php endif; ?>

        <form action="verify_otp.php" method="POST">
            <input type="hidden" name="action" value="verify">

            <div class="form-group">
                <label>Verification Code</label>
                <input type="text" name="otp" class="otp-input"
                       inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       placeholder="000000" autocomplete="one-time-code" required autofocus>
            </div>

            <button type="submit" class="btn-submit">Verify &amp; Create Account</button>
        </form>

        <form action="verify_otp.php" method="POST" class="otp-resend">
            <input type="hidden" name="action" value="resend">
            Did not receive the code? <button type="submit">Resend code</button>
        </form>

    </div>
</div>

</body>
</html>