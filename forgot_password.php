<?php
session_start();
include 'config.php';
require_once 'reset_helpers.php';

rp_ensure_table($conn);

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(16));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['reset_csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $st = $conn->prepare("SELECT id, fullname, email FROM users WHERE email = ? LIMIT 1");
            $st->bind_param("s", $email);
            $st->execute();
            $user = $st->get_result()->fetch_assoc();
            $st->close();

            unset($_SESSION['dev_code']);

            if ($user) {
                $uid  = (int)$user['id'];
                $cool = (int)RESET_COOLDOWN_SECONDS;

                $st = $conn->prepare("SELECT id FROM password_reset_codes WHERE user_id = ? AND created_at > (NOW() - INTERVAL $cool SECOND) LIMIT 1");
                $st->bind_param("i", $uid);
                $st->execute();
                $recent = $st->get_result()->num_rows > 0;
                $st->close();

                if (!$recent) {
                    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $mins = (int)RESET_CODE_MINUTES;
                    $hash = rp_hash_code($code, $uid);

                    $st = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                    $st->bind_param("i", $uid);
                    $st->execute();
                    $st->close();

                    $st = $conn->prepare("INSERT INTO password_reset_codes (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $mins MINUTE))");
                    $st->bind_param("is", $uid, $hash);
                    $st->execute();
                    $st->close();

                    if (!rp_send_code_email($user['email'], (string)$user['fullname'], $code)) {
                        error_log('PASSWORD RESET: email not sent. Code for user ' . $uid . ': ' . $code);
                        if (rp_is_local()) { $_SESSION['dev_code'] = $code; } // para ma-test sa localhost
                    }
                }
            }

            // Pareho ang daan kahit walang account ang email, para hindi ma-check kung may account
            $_SESSION['reset_email'] = $email;
            header('Location: reset_password.php');
            exit();
        }
    }
}

rp_page_start('Forgot Password');
?>
            <h5 class="fw-bold text-dark mb-1">Forgot your password?</h5>
            <p class="text-muted small">Enter the email you used to register. We'll send a 6-digit code to that email.</p>

<?php if ($error !== ''): ?>
            <div class="alert alert-danger small py-2"><?= rp_h($error) ?></div>
<?php endif; ?>

            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="csrf" value="<?= rp_h($_SESSION['reset_csrf']) ?>">
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold small text-secondary">Registered email</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your registered email"
                           value="<?= rp_h($_POST['email'] ?? ($_SESSION['reset_email'] ?? '')) ?>" required autocomplete="email" autofocus>
                </div>
                <button class="btn btn-primary w-100 shadow-sm mb-3 py-2 fw-semibold" type="submit">Send code</button>
            </form>

            <div class="text-center mt-3">
                <span class="small text-muted">Forgot your email?</span>
                <a href="register.php" class="small fw-bold text-decoration-none text-primary ms-1">Create a new account</a>
            </div>
            <div class="text-center mt-3 pt-3 border-top">
                <a href="login.php" class="small text-decoration-none text-secondary">&larr; Back to Sign In</a>
            </div>
<?php rp_page_end(); ?>