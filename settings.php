<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$uid         = (int)$_SESSION['user_id'];
$username    = $_SESSION['username'] ?? '';
$success_msg = "";
$error_msg   = "";

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // UPDATE: account details (username and email)
        if ($action === 'update_info') {
            $new_username = trim($_POST['username'] ?? '');
            $new_email    = trim($_POST['email'] ?? '');

            if ($new_username === '') {
                $error_msg = "Username cannot be empty.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $new_username, $new_email, $uid);
                if ($stmt->execute()) {
                    $_SESSION['username'] = $new_username;
                    $username    = $new_username;
                    $success_msg = "Account information updated successfully!";
                } else {
                    $error_msg = "Error updating profile. Username or email may already be taken.";
                }
                $stmt->close();
            }
        }

        // UPDATE: password
        elseif ($action === 'update_password') {
            $current_pass = $_POST['current_password'] ?? '';
            $new_pass     = $_POST['new_password'] ?? '';

            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                // Supports both hashed passwords and old plain-text passwords
                $stored = $row['password'];
                $ok = password_verify($current_pass, $stored) || hash_equals($stored, $current_pass);

                if (!$ok) {
                    $error_msg = "Current password is incorrect.";
                } elseif (strlen($new_pass) < 6) {
                    $error_msg = "New password must be at least 6 characters.";
                } else {
                    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $up->bind_param("si", $hashed, $uid);
                    $up->execute();
                    $up->close();
                    $success_msg = "Password updated successfully!";
                }
            }
        }

        // DELETE: account
        elseif ($action === 'delete_account') {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();

            session_destroy();
            header("Location: register.php?deleted=1");
            exit;
        }
    }

    // Fetch current user details
    $stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

} catch (Throwable $e) {
    error_log($e->getMessage());
    $error_msg = "Database error. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - PAWFINDER</title>
    <link rel="stylesheet" href="css/homepage.css">
    <link rel="stylesheet" href="css/settings.css">
    <link rel="stylesheet" href="css/theme.css">
    <style>
        /* Fallback styles in case css/settings.css does not exist yet */
        .settings-wrap{max-width:720px;margin:0 auto;padding:24px 16px}
        .settings-section{background:#fff;border:1px solid #d5e3e7;border-radius:14px;padding:20px 24px;margin:0 0 20px}
        .settings-section h3{margin:0 0 14px;font-size:18px}
        .setting-row{display:flex;align-items:center;justify-content:space-between;gap:16px}
        .setting-title{font-weight:600}
        .setting-desc{font-size:13px;color:#64748b}
        .profile-info-group{margin:0 0 14px}
        .profile-label{font-size:13px;font-weight:600;margin:0 0 6px;color:#4a6670}
        .settings-input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
        .btn-save{background:#0d9488;color:#fff;border:0;border-radius:8px;padding:10px 20px;font:inherit;font-weight:600;cursor:pointer}
        .btn-save:hover{background:#0b7c72}
        .btn-delete{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:10px 20px;font:inherit;font-weight:600;cursor:pointer}
        .btn-delete:hover{background:#b91c1c}
        .danger-zone{border-color:#fecaca}
        .alert-success{background:#e2f3ea;color:#1f7a4d;border:1px solid #bfe3cf;padding:12px 18px;border-radius:10px;margin:0 0 16px}
        .alert-error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:12px 18px;border-radius:10px;margin:0 0 16px}
        .back-btn{display:inline-block;margin:0 0 16px;color:#0d9488;text-decoration:none;font-weight:600}
        /* switch */
        .switch{position:relative;display:inline-block;width:50px;height:28px;flex:0 0 auto}
        .switch input{opacity:0;width:0;height:0}
        .slider{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:28px;transition:.2s}
        .slider:before{content:"";position:absolute;height:22px;width:22px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
        .switch input:checked + .slider{background:#0d9488}
        .switch input:checked + .slider:before{transform:translateX(22px)}
        /* dark mode for this page */
        body.dark-mode .settings-section{background:#1e293b;border-color:#334155}
        body.dark-mode .settings-section.danger-zone{border-color:#7f1d1d}
        body.dark-mode .setting-desc,
        body.dark-mode .profile-label{color:#94a3b8}
        body.dark-mode .settings-input{background:#0f172a;color:#e2e8f0;border-color:#334155}
        body.dark-mode .alert-success{background:#12372a;color:#86efac;border-color:#1f5a43}
        body.dark-mode .alert-error{background:#3b1c1c;color:#fca5a5;border-color:#7f1d1d}
    </style>
</head>
<body>
    <!-- Applies the saved dark mode (must be right after the opening body tag) -->
    <script src="js/darkmode.js"></script>

    <?php include 'header.php'; ?>

    <main class="content-area settings-wrap">

        

        <h2>Account Settings</h2>
        <p style="font-size:14px;margin:0 0 20px;">Manage your preferences, security, and account data</p>

        <?php if ($success_msg !== ""): ?>
            <div class="alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg !== ""): ?>
            <div class="alert-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <!-- APPEARANCE -->
        <div class="settings-section">
            <h3>Appearance</h3>
            <div class="setting-row">
                <div>
                    <div class="setting-title">Dark Mode</div>
                    <div class="setting-desc">Toggle dark theme across the platform</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="darkModeToggle">
                    <span class="slider round"></span>
                </label>
            </div>
        </div>

        <!-- EDIT PROFILE -->
        <div class="settings-section">
            <h3>Edit Profile Information</h3>
            <form action="settings.php" method="POST">
                <input type="hidden" name="action" value="update_info">

                <div class="profile-info-group">
                    <div class="profile-label">Username</div>
                    <input type="text" name="username" value="<?= htmlspecialchars($user_result['username'] ?? $username) ?>" required class="settings-input">
                </div>

                <div class="profile-info-group">
                    <div class="profile-label">Email Address</div>
                    <input type="email" name="email" value="<?= htmlspecialchars($user_result['email'] ?? '') ?>" class="settings-input">
                </div>

                <button type="submit" class="btn-save">Save Changes</button>
            </form>
        </div>

        <!-- SECURITY -->
        <div class="settings-section">
            <h3>Security</h3>
            <form action="settings.php" method="POST">
                <input type="hidden" name="action" value="update_password">

                <div class="profile-info-group">
                    <div class="profile-label">Current Password</div>
                    <input type="password" name="current_password" required class="settings-input">
                </div>

                <div class="profile-info-group">
                    <div class="profile-label">New Password</div>
                    <input type="password" name="new_password" required class="settings-input">
                </div>

                <button type="submit" class="btn-save">Update Password</button>
            </form>
        </div>

        <!-- DELETE ACCOUNT -->
        <div class="settings-section danger-zone">
            <h3 style="color:#ef4444;">Delete Account</h3>
            <p style="font-size:13px;margin-bottom:15px;">Once you delete your account, there is no going back. Please be certain.</p>

            <form action="settings.php" method="POST" onsubmit="return confirm('Are you sure you want to permanently delete your Pawfinder account?');">
                <input type="hidden" name="action" value="delete_account">
                <button type="submit" class="btn-delete">Delete Account</button>
            </form>
        </div>

    </main>

</body>
</html>