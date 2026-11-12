<?php
session_start();

// 1. Initialize variables safely right at the start to prevent "undefined variable" errors
$username = $_SESSION['username'] ?? '';
$user_data = null;
$db_error = "";

// 2. Redirect to login if not logged in
if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

try {
    $conn = new mysqli("localhost", "root", "", "pawfinder_db");
    $conn->set_charset("utf8mb4");

    $stmt = $conn->prepare("SELECT username, email, created_at FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
    } else {
        $db_error = "User account details could not be found.";
    }
    
    $stmt->close();
    $conn->close();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $db_error = "An error occurred while loading your profile.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - PAWFINDER</title>
    <link rel="stylesheet" href="../css/homepage.css">
    <link rel="stylesheet" href="../css/adopt.css">
    <link rel="stylesheet" href="../css/profile.css">
</head>
<body>

    <div class="content-area">
        <div class="profile-container">
            
            <!-- Top-Left Back Button -->
            <div class="profile-top-nav">
                <a href="homepage.php" class="back-btn">&larr; Back to Adopt Pets</a>
            </div>

            <div class="profile-header">
                <!-- Avatar with Upload Button Inside -->
                <form action="profile.php" method="POST" enctype="multipart/form-data" id="photoForm">
                    <div class="profile-avatar-wrapper" onclick="document.getElementById('photoInput').click();" title="Change profile photo">
                        <?php if (!empty($user_data['profile_photo'])): ?>
                            <img src="../<?= htmlspecialchars($user_data['profile_photo']) ?>" alt="Profile Photo" class="profile-avatar-img">
                        <?php else: ?>
                            <div class="profile-avatar">
                                <?= $username !== '' ? strtoupper(substr($username, 0, 1)) : 'U' ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="avatar-upload-overlay">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                                <circle cx="12" cy="13" r="4"></circle>
                            </svg>
                        </div>
                    </div>
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" style="display: none;" onchange="document.getElementById('photoForm').submit();">
                </form>

                <div>
                    <h2><?= htmlspecialchars($user_data['username']) ?></h2>
                    <p style="color: #64748b; font-size: 14px; margin: 0;">Manage your Pawfinder community account information</p>
                </div>
            </div>

            <?php if ($db_error !== ""): ?>
                <div class="adopt-empty"><?= htmlspecialchars($db_error) ?></div>
            <?php elseif ($user_data): ?>
                <div class="profile-info-group">
                    <div class="profile-label">Username</div>
                    <div class="profile-value"><?= htmlspecialchars($user_data['username']) ?></div>
                </div>

                <div class="profile-info-group">
                    <div class="profile-label">Email Address</div>
                    <div class="profile-value"><?= htmlspecialchars($user_data['email'] ?? 'Not provided') ?></div>
                </div>

                <div class="profile-info-group">
                    <div class="profile-label">Member Since</div>
                    <div class="profile-value"><?= htmlspecialchars($user_data['created_at'] ?? 'N/A') ?></div>
                </div>
            <?php endif; ?>

           
        </div>
    </div>

</body>
</html>