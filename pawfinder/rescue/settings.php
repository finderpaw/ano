<?php
require_once __DIR__ . '/layout.php';   /* session check, $conn, helpers, sidebar */

$user_id = (int)($_SESSION['user_id'] ?? 0);
$success_msg = '';
$error_msg   = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        /* ---------- update username / email ---------- */
        if ($action === 'update_info') {
            $new_username = trim($_POST['username'] ?? '');
            $new_email    = trim($_POST['email'] ?? '');

            if ($new_username === '') {
                $error_msg = "Username cannot be empty.";
            } elseif ($new_email !== '' && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error_msg = "Please enter a valid email address.";
            } else {
                /* make sure nobody else already uses them */
                $chk = $conn->prepare("SELECT id FROM users WHERE id <> ? AND (username = ? OR (email = ? AND email <> '')) LIMIT 1");
                $chk->bind_param("iss", $user_id, $new_username, $new_email);
                $chk->execute();
                $taken = $chk->get_result()->num_rows > 0;
                $chk->close();

                if ($taken) {
                    $error_msg = "That username or email is already taken.";
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $new_username, $new_email, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    $_SESSION['username'] = $new_username;
                    $success_msg = "Account information updated successfully!";
                }
            }
        }

        /* ---------- change password ---------- */
        elseif ($action === 'update_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($current, $row['password'])) {
                $error_msg = "Current password is incorrect.";
            } elseif (strlen($new) < 6) {
                $error_msg = "New password must be at least 6 characters.";
            } elseif ($new !== $confirm) {
                $error_msg = "New password and confirmation do not match.";
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $up->bind_param("si", $hash, $user_id);
                $up->execute();
                $up->close();
                $success_msg = "Password updated successfully!";
            }
        }

        /* ---------- delete account ---------- */
        elseif ($action === 'delete_account') {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            session_destroy();
            header("Location: ../register.php?deleted=1");
            exit();
        }
    }
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $error_msg = "Something went wrong while saving. Please try again.";
}

/* current details */
$stmt = $conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

rescuer_head('settings', 'Settings');
rescuer_top('Account Settings', 'Manage your preferences, security, and account data');
?>
        <?php if ($success_msg !== ''): ?><div class="alert ok"><?php echo e($success_msg); ?></div><?php endif; ?>
        <?php if ($error_msg !== ''): ?><div class="alert err"><?php echo e($error_msg); ?></div><?php endif; ?>

        <div class="settings-grid">
            <section class="settings-section">
                <h3>Appearance</h3>
                <div class="setting-row">
                    <div>
                        <div class="setting-title">Dark mode</div>
                        <div class="setting-desc">Use a dark theme on every rescuer page</div>
                    </div>
                    <label class="switch" aria-label="Dark mode">
                        <input type="checkbox" id="darkModeToggle">
                        <span class="slider"></span>
                    </label>
                </div>
            </section>

            <section class="settings-section">
                <h3>Edit profile information</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_info">
                    <label class="field">Username
                        <input type="text" name="username" value="<?php echo e($me['username'] ?? ''); ?>" required>
                    </label>
                    <label class="field">Email address
                        <input type="email" name="email" value="<?php echo e($me['email'] ?? ''); ?>">
                    </label>
                    <label class="field">Role
                        <input type="text" value="<?php echo e($me['role'] ?? 'Rescuer'); ?>" disabled>
                    </label>
                    <button type="submit" class="btn">Save changes</button>
                </form>
            </section>

            <section class="settings-section">
                <h3>Security</h3>
                <form method="post">
                    <input type="hidden" name="action" value="update_password">
                    <label class="field">Current password
                        <input type="password" name="current_password" required autocomplete="current-password">
                    </label>
                    <label class="field">New password
                        <input type="password" name="new_password" minlength="6" required autocomplete="new-password">
                    </label>
                    <label class="field">Confirm new password
                        <input type="password" name="confirm_password" minlength="6" required autocomplete="new-password">
                    </label>
                    <button type="submit" class="btn">Update password</button>
                </form>
            </section>

            <section class="settings-section danger">
                <h3>Delete account</h3>
                <p class="setting-desc">Once you delete your account, there is no going back. Please be certain.</p>
                <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete your Pawfinder account?');">
                    <input type="hidden" name="action" value="delete_account">
                    <button type="submit" class="btn btn-danger">Delete account</button>
                </form>
            </section>
        </div>

<script>
(function () {
    var t = document.getElementById('darkModeToggle');
    t.checked = localStorage.getItem('pawfinder_dark_mode') === 'enabled';
    t.addEventListener('change', function () {
        document.body.classList.toggle('dark-mode', t.checked);
        localStorage.setItem('pawfinder_dark_mode', t.checked ? 'enabled' : 'disabled');
    });
})();
</script>
<?php rescuer_foot(); ?>