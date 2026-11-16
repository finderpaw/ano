<?php
// header.php - shared top bar + navbar (gamitin sa lahat ng pages)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notif_count.php';

$loggedIn = !empty($_SESSION['logged_in']);
$role     = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

// Para ma-highlight ang active link
$currentPage = basename($_SERVER['PHP_SELF']);

// Role na ipapakita sa top bar (galing sa naka-login na user)
$roleKey = strtolower($role);
if (!$loggedIn) {
    $selectedRole = 'guest';
} elseif (in_array($roleKey, ['admin', 'rescuer', 'adopter', 'local', 'visitor'], true)) {
    $selectedRole = $roleKey;
} else {
    $selectedRole = 'guest';
}

// Text na ipapakita (wala nang dropdown, label na lang)
$roleLabels = [
    'guest'   => 'Guest / Public Visitor',
    'admin'   => 'Admin',
    'rescuer' => 'Rescuer',
    'adopter' => 'Adopter',
    'local'   => 'Local',
    'visitor' => 'Visitor',
];
$roleLabel = $roleLabels[$selectedRole] ?? 'Guest / Public Visitor';

// Para sa dropdown ng profile_menu.php (shared ang variables sa include)
$isRescuer      = $loggedIn && $roleKey === 'rescuer';
$rescuerDashUrl = 'rescuer/overview.php';

// Bilang ng unread notifications (gagamitin ng profile_menu.php)
$pfUnread = 0;
if ($loggedIn && !empty($_SESSION['user_id']) && isset($conn)) {
    $pfUnread = pf_unread_count($conn, (int)$_SESSION['user_id']);
}
?>
<style>
    .user-chip{font-size:13px;color:#4a6670;margin-right:6px;align-self:center}
    .auth-actions{display:flex;align-items:center;gap:10px}

    /* Role na nakalagay na agad (walang dropdown) */
    .role-badge{
        display:inline-block;
        padding:6px 14px;
        font-size:13px;
        font-weight:600;
        line-height:1.2;
        color:#0f766e;
        background:#e6f4f1;
        border-radius:999px;
        cursor:default;
        user-select:none;
    }

    /* Red dot sa gear button + bilang sa My Notifications */
    .pf-dot{
        position:absolute;
        top:1px;
        right:1px;
        width:12px;
        height:12px;
        background:#e11d48;
        border:2px solid #fff;
        border-radius:50%;
        pointer-events:none;
    }
    .pf-badge{
        display:inline-block;
        margin-left:8px;
        min-width:20px;
        padding:1px 7px;
        font-size:12px;
        font-weight:700;
        line-height:18px;
        text-align:center;
        color:#fff;
        background:#e11d48;
        border-radius:999px;
    }
</style>

<div class="top-bar">
    <div class="role-container">
        <span id="roleSwitcher" class="role-badge"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
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
            <?php if ($roleKey === 'admin'): ?>
                <button class="btn-login" onclick="location.href='admin/admin.php'">Admin Dashboard</button>
            <?php endif; ?>
            <?php include __DIR__ . '/profile_menu.php'; ?>
        <?php else: ?>
            <button class="btn-login" onclick="location.href='login.php'">Log In</button>
            <button class="btn-register" onclick="location.href='register.php'">Register</button>
        <?php endif; ?>
    </div>
</header>