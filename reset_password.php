<?php
session_start();
include 'config.php';
require_once 'reset_helpers.php';

rp_ensure_table($conn);

$email = (string)($_SESSION['reset_email'] ?? '');
if ($email === '') {
    header('Location: forgot_password.php');
    exit();
}

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(16));
}

$st = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$st->bind_param("s", $email);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();
$uid = $user ? (int)$user['id'] : 0;

$row = null;
if ($uid) {
    $st = $conn->prepare("SELECT id, code_hash, attempts FROM password_reset_codes
                          WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
                          ORDER BY id DESC LIMIT 1");
    $st->bind_param("i", $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    $pw   = (string)($_POST['password'] ?? '');
    $pw2  = (string)($_POST['password2'] ?? '');

    if (!hash_equals($_SESSION['reset_csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    }
    if (strlen($code) !== 6) {
        $errors[] = 'Enter the 6-digit code we sent to your email.';
    }
    if (strlen($pw) < 8)  { $errors[] = 'Password must be at least 8 characters.'; }
    if (strlen($pw) > 72) { $errors[] = 'Password must be 72 characters or fewer.'; }
    if ($pw !== $pw2)     { $errors[] = 'The two passwords do not match.'; }

    // Code lang ang bini-verify kapag maayos na ang iba, para hindi masayang ang attempts
    if (!$errors) {
        if (!$row) {
            $errors[] = 'This code is invalid or expired. Please request a new one.';
        } elseif ((int)$row['attempts'] >= RESET_MAX_ATTEMPTS) {
            $conn->query("UPDATE password_reset_codes SET used_at = NOW() WHERE id = " . (int)$row['id']);
            $errors[] = 'Too many wrong attempts. Please request a new code.';
        } elseif (!hash_equals($row['code_hash'], rp_hash_code($code, $uid))) {
            $conn->query("UPDATE password_reset_codes SET attempts = attempts + 1 WHERE id = " . (int)$row['id']);
            $left = RESET_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
            if ($left <= 0) {
                $conn->query("UPDATE password_reset_codes SET used_at = NOW() WHERE id = " . (int)$row['id']);
                $errors[] = 'Incorrect code. Too many wrong attempts, please request a new code.';
            } else {
                $errors[] = 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
            }
        } else {
            try {
                $newHash = password_hash($pw, PASSWORD_DEFAULT);
                $col     = RESET_USER_PASSWORD_COLUMN;
                $rid     = (int)$row['id'];

                $conn->begin_transaction();

                $m = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
                $m->bind_param("i", $rid);
                $m->execute();
                if ($m->affected_rows !== 1) { throw new Exception('Code already used.'); }
                $m->close();

                $u = $conn->prepare("UPDATE users SET `$col` = ? WHERE id = ?");
                $u->bind_param("si", $newHash, $uid);
                $u->execute();
                $u->close();

                $conn->query("UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = " . $uid . " AND used_at IS NULL");

                $conn->commit();

                unset($_SESSION['reset_csrf'], $_SESSION['reset_email'], $_SESSION['dev_code']);
                $_SESSION['success'] = 'Your password was updated. You can now sign in.';
                header('Location: login.php');
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('PASSWORD RESET SAVE ERROR: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your new password. Please try again.';
            }
        }
    }
}

rp_page_start('Reset Password');
?>
            <h5 class="fw-bold text-dark mb-1">Enter your code</h5>
            <p class="text-muted small">
                If <b><?= rp_h(rp_mask_email($email)) ?></b> is registered, we sent a 6-digit code to it. It expires in <?= (int)RESET_CODE_MINUTES ?> minutes.
            </p>

<?php if (rp_is_local() && !empty($_SESSION['dev_code'])): ?>
            <div class="alert alert-warning small py-2">
                <b>Local testing only:</b> the email could not be sent from this server. Your code is <b><?= rp_h($_SESSION['dev_code']) ?></b>.
            </div>
<?php endif; ?>

<?php if ($errors): ?>
            <div class="alert alert-danger small py-2">
                <ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo '<li>' . rp_h($e) . '</li>'; ?></ul>
            </div>
<?php endif; ?>

            <form method="POST" action="reset_password.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= rp_h($_SESSION['reset_csrf']) ?>">

                <div class="mb-3">
                    <label for="code" class="form-label fw-semibold small text-secondary">6-digit code</label>
                    <input type="text" id="code" name="code" class="form-control code-input" inputmode="numeric"
                           pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code" autofocus>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold small text-secondary">New password</label>
                    <input type="password" id="password" name="password" class="form-control" minlength="8" maxlength="72"
                           required autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label for="password2" class="form-label fw-semibold small text-secondary">Confirm new password</label>
                    <input type="password" id="password2" name="password2" class="form-control" minlength="8" maxlength="72"
                           required autocomplete="new-password">
                </div>
                <button class="btn btn-primary w-100 shadow-sm mb-3 py-2 fw-semibold" type="submit">Save new password</button>
            </form>

            <div class="text-center small mt-2">
                <span class="text-muted">Didn't get a code?</span>
                <a href="forgot_password.php" class="text-decoration-none fw-bold ms-1">Send again</a>
            </div>
            <div class="text-center mt-3">
                <span class="small text-muted">Forgot your email?</span>
                <a href="register.php" class="small fw-bold text-decoration-none text-primary ms-1">Create a new account</a>
            </div>
            <div class="text-center mt-3 pt-3 border-top">
                <a href="login.php" class="small text-decoration-none text-secondary">&larr; Back to Sign In</a>
            </div>
<?php rp_page_end(); ?>