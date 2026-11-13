<?php
// header.php - shared top bar + navbar (gamitin sa lahat ng pages)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$loggedIn = !empty($_SESSION['logged_in']);
$role     = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

// Para ma-highlight ang active link
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<style>
    .user-chip{font-size:13px;color:#4a6670;margin-right:6px;align-self:center}
</style>

<div class="top-bar">
    <div class="role-container">
        <span class="role-label">Current Role:</span>
        <select id="roleSwitcher" class="role-select">
            <option value="admin">Admin</option>
            <option value="guest" selected>Guest / Public Visitor</option>
            <option value="rescuer">Local Rescuers</option>
            <option value="adopter">Adopter</option>
        </select>
    </div>
</div>

<header class="navbar">
    <div class="brand-section" onclick="location.href='homepage.php'">
        <img src="image/logo2.jpg" alt="PAWFINDER Logo" class="brand-logo-img">
        <div>
            <span class="brand-title">PAWFINDER</span>
            <span class="brand-subtitle">COMMUNITY PET SYSTEM</span>
        </div>
    </div>

    <ul class="nav-links">
        <li class="nav-item"><a href="homepage.php" class="<?= $currentPage === 'homepage.php' ? 'active' : '' ?>">Home</a></li>
        <li class="nav-item"><a href="adopt.php" class="<?= $currentPage === 'adopt.php' ? 'active' : '' ?>">Adopt Pets</a></li>
        <li class="nav-item"><a href="rescue.php" class="<?= $currentPage === 'rescue.php' ? 'active' : '' ?>">Emergency Rescues</a></li>
        <li class="nav-item"><a href="health_drives.php" class="<?= $currentPage === 'health_drives.php' ? 'active' : '' ?>">Health Drives</a></li>
        <li class="nav-item"><a href="contact.php" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">Contact</a></li>
    </ul>

    <div class="auth-actions">
        <?php if ($loggedIn): ?>
            <span class="user-chip">Hi, <?= htmlspecialchars($username) ?></span>
            <?php if ($role === 'rescuer'): ?>
                <button class="btn-login" onclick="location.href='rescue/overview.php'">Rescuer Dashboard</button>
            <?php elseif ($role === 'admin'): ?>
                <button class="btn-login" onclick="location.href='admin/admin.php'">Admin Dashboard</button>
            <?php endif; ?>
            <button class="btn-register" onclick="if(confirm('Are you sure you want to log out?')) location.href='logout.php'">Log Out</button>
        <?php else: ?>
            <button class="btn-login" onclick="location.href='login.php'">Log In</button>
            <button class="btn-register" onclick="location.href='register.php'">Register</button>
        <?php endif; ?>
    </div>
</header>