<?php
session_start();

$username = $_SESSION['username'] ?? '';
$success_msg = "";
$error_msg = "";

if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

try {
    $conn = new mysqli("localhost", "root", "", "pawfinder_db");
    $conn->set_charset("utf8mb4");

    // Handle Form Submissions (CRUD Actions)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // UPDATE: Account Details (Username & Email)
        if ($action === 'update_info') {
            $new_username = trim($_POST['username']);
            $new_email = trim($_POST['email']);

            if (empty($new_username)) {
                $error_msg = "Username cannot be empty.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE username = ?");
                $stmt->bind_param("sss", $new_username, $new_email, $username);
                if ($stmt->execute()) {
                    $_SESSION['username'] = $new_username;
                    $username = $new_username;
                    $success_msg = "Account information updated successfully!";
                } else {
                    $error_msg = "Error updating profile. Username or email may already be taken.";
                }
                $stmt->close();
            }
        }

        // UPDATE: Password Change
        elseif ($action === 'update_password') {
            $current_pass = $_POST['current_password'];
            $new_pass = $_POST['new_password'];

            $stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // If your database stores plain text passwords, use ($current_pass === $row['password'])
                // For hashed passwords, use password_verify($current_pass, $row['password'])
                if ($current_pass === $row['password'] || password_verify($current_pass, $row['password'])) {
                    $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update_pass = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $update_pass->bind_param("ss", $hashed_pass, $username);
                    $update_pass->execute();
                    $update_pass->close();
                    $success_msg = "Password updated successfully!";
                } else {
                    $error_msg = "Current password is incorrect.";
                }
            }
            $stmt->close();
        }

        // DELETE: Account Deletion
        elseif ($action === 'delete_account') {
            $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->close();
            $conn->close();
            
            session_destroy();
            header("Location: register.php?deleted=1");
            exit;
        }
    }

    // Fetch current user details
    $stmt = $conn->prepare("SELECT username, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

} catch (Throwable $e) {
    error_log($e->getMessage());
    $error_msg = "Database connection error.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - PAWFINDER</title>
    <link rel="stylesheet" href="../css/homepage.css">
    <link rel="stylesheet" href="../css/adopt.css">
    <link rel="stylesheet" href="../css/profile.css">
    <link rel="stylesheet" href="../css/settings.css">
</head>
<body>

    <div class="content-area">
        <div class="profile-container">
            
            <div class="profile-top-nav">
                <a href="homepage.php" class="back-btn">&larr; Back to Adopt Pets</a>
            </div>

            <div class="profile-header">
                <div>
                    <h2>Account Settings</h2>
                    <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Manage your preferences, security, and account data</p>
                </div>
            </div>

            <?php if ($success_msg !== ""): ?>
                <div class="alert-success"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>

            <?php if ($error_msg !== ""): ?>
                <div class="alert-error"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <!-- DARK MODE SETTING SECTION -->
            <div class="settings-section">
                <h3>Appearance</h3>
                <div class="setting-row">
                    <div>
                        <div class="setting-title">Dark Mode</div>
                        <div class="setting-desc">Toggle dark theme across the platform</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="darkModeToggle" onchange="toggleDarkMode()">
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>

            <!-- UPDATE ACCOUNT INFO FORM -->
            <div class="settings-section">
                <h3>Edit Profile Information </h3>
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

            <!-- CHANGE PASSWORD SECTION -->
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

            <!-- DELETE ACCOUNT SECTION (CRUD - Delete) -->
            <div class="settings-section danger-zone">
                <h3 style="color: #ef4444;">Delete Account</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">Once you delete your account, there is no going back. Please be certain.</p>
                
                <form action="settings.php" method="POST" onsubmit="return confirm('Are you sure you want to permanently delete your Pawfinder account?');">
                    <input type="hidden" name="action" value="delete_account">
                    <button type="submit" class="btn-delete">Delete Account</button>
                </form>
            </div>

        </div>
    </div>

    <!-- Dark Mode Persistence Script -->
    <script>
        const bodyEl = document.body;
        const toggleEl = document.getElementById('darkModeToggle');

        if (localStorage.getItem('pawfinder_dark_mode') === 'enabled') {
            bodyEl.classList.add('dark-mode');
            toggleEl.checked = true;
        }

        function toggleDarkMode() {
            if (toggleEl.checked) {
                bodyEl.classList.add('dark-mode');
                localStorage.setItem('pawfinder_dark_mode', 'enabled');
            } else {
                bodyEl.classList.remove('dark-mode');
                localStorage.setItem('pawfinder_dark_mode', 'disabled');
            }
        }
    </script>
</body>
</html>