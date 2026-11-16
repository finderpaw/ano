<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

/* ---------- helpers ---------- */
$root = '../';

function count_rows($conn, $sql) {
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_row($r);
    return (int)($row[0] ?? 0);
}

/* ---------- dashboard numbers ---------- */
$dogs   = count_rows($conn, "SELECT COUNT(*) FROM pets WHERE LOWER(pet_species) LIKE '%dog%'");
$cats   = count_rows($conn, "SELECT COUNT(*) FROM pets WHERE LOWER(pet_species) LIKE '%cat%'");
$birds  = count_rows($conn, "SELECT COUNT(*) FROM pets WHERE LOWER(pet_species) LIKE '%bird%'");
$pets_total   = count_rows($conn, "SELECT COUNT(*) FROM pets");
/* Health drives come from the health_drives table (same one used by health_drives.php) */
$sched_total  = count_rows($conn, "SELECT COUNT(*) FROM health_drives");
$rescue_total = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports");
/* Registered accounts: the real accounts live in the users table */
$members      = count_rows($conn, "SELECT COUNT(*) FROM users");

/* health drives per month, last 6 months */
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i month", strtotime(date('Y-m-01'))));
    $months[$key] = ['label' => date('M', strtotime($key . '-01')), 'n' => 0];
}
$rm = @mysqli_query($conn, "SELECT DATE_FORMAT(event_date,'%Y-%m') ym, COUNT(*) n FROM health_drives GROUP BY ym");
if ($rm) {
    while ($m = mysqli_fetch_assoc($rm)) {
        if (isset($months[$m['ym']])) $months[$m['ym']]['n'] = (int)$m['n'];
    }
}
$max_month = max(1, max(array_column($months, 'n')));

$bubble_max = max(1, $dogs, $cats, $birds);
function bubble($n, $max) { return (int)round(78 + 62 * ($n / $max)); }

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Admin Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">
<style>
    aside.side {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    aside.side .nav-container {
        flex: 1;
    }
    aside.side .logout {
        border-top: 1px solid var(--line);
        padding-top: 12px;
        margin-top: auto;
    }
    aside.side .logout a {
        background: var(--red-bg);
        color: var(--red) !important;
        border-radius: 8px;
        transition: background 0.2s;
    }
    aside.side .logout a:hover {
        background: #fecaca;
    }
</style>
</head>
<body>
<div class="app">

    <!-- SIDEBAR -->
    <aside class="side">
        <div class="nav-container">
            <div class="brand">
                <img src="<?php echo $root; ?>image/logo2.jpg" alt="PAWFINDER logo" onerror="this.src='https://placehold.co/42x42/0b5a6b/ffffff?text=PF'">
                <div><b>PAWFINDER</b><small>Community Pet System</small></div>
            </div>

            <div class="group">MAIN MENU</div>
            <nav>
                <a href="admin.php" class="active">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </a>
                <a href="adopt.php">
                    <svg viewBox="0 0 24 24"><path d="M12 21s-7-4.5-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.5-9 9-9 9z"/></svg>
                    Pets
                </a>
                <a href="users.php">
                    <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.4c2 .7 3.5 2.500 3.5 5.600"/></svg>
                    Users
                </a>
                <a href="notification.php">
                    <svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.300 21a1.900 1.900 0 0 0 3.400 0"/></svg>
                    Notifications
                </a>
                <a href="admin_updates.php">
                    <svg viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.700 4.400A9 9 0 0 0 20.500 15"/></svg>
                    Weekly Updates
                    <span class="an-nav-badge" id="anNavBadge"></span>
                </a>
            </nav>

            <hr>
            <div class="group">OTHER</div>
            <nav>
                <a href="settings.php">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.400 15a1.700 1.700 0 0 0 .3 1.800l.1.1a2 2 0 1 1-2.800 2.800l-.1-.1a1.700 1.700 0 0 0-1.800-.3 1.700 1.700 0 0 0-1 1.500V21a2 2 0 1 1-4 0v-.1a1.700 1.700 0 0 0-1.100-1.500 1.700 1.700 0 0 0-1.800.3l-.1.1a2 2 0 1 1-2.800-2.800l-.1-.1a1.700 1.700 0 0 0 .3-1.800 1.700 1.700 0 0 0-1.500-1H3a2 2 0 1 1 0-4h.1a1.700 1.700 0 0 0 1.500-1.100 1.700 1.700 0 0 0-.3-1.800l-.1-.1a2 2 0 1 1 2.800-2.800l.1.1a1.700 1.700 0 0 0 1.800.3H9a1.700 1.700 0 0 0 1-1.500V3a2 2 0 1 1 4 0v.1a1.700 1.700 0 0 0 1 1.500 1.700 1.700 0 0 0 1.800-.3l-.1-.1a2 2 0 1 1 2.800 2.800l-.1.1a1.700 1.700 0 0 0-.3 1.800V9a1.700 1.700 0 0 0 1.500 1H21a2 2 0 1 1 0 4h-.1a1.700 1.700 0 0 0-1.500 1z"/></svg>
                    Settings
                </a>
                <a href="contact.php">
                    <svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.200 2 19.800 19.800 0 0 1-8.600-3.100 19.500 19.500 0 0 1-6-6A19.800 19.800 0 0 1 2.100 4.200 2 2 0 0 1 4.100 2h3a2 2 0 0 1 2 1.700c.1 1 .4 1.900.7 2.800a2 2 0 0 1-.5 2.100L8.100 9.900a16 16 0 0 0 6 6l1.300-1.300a2 2 0 0 1 2.100-.4c.9.300 1.800.6 2.800.7a2 2 0 0 1 1.700 2z"/></svg>
                    Contact
                </a>
            </nav>
        </div>

        <nav class="logout">
            <a href="admin_logout.php" onclick="return confirm('Are you sure you want to log out?');">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Log Out
            </a>
        </nav>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <div class="top">
            <div>
                <h1><?php echo $greeting; ?>, <?php echo $admin_name; ?></h1>
                <p>Welcome to the Pawfinder Admin Dashboard</p>
            </div>
            <div style="display:flex;align-items:center;gap:14px">
                <div class="role">Active session role: <b>Admin / Staff</b></div>
                <?php include __DIR__ . '/admin_bell.php'; ?>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid g4" style="margin-top: 15px;">
            <div class="card stat">
                <h2>Pets for adoption</h2>
                <div class="num"><strong><?php echo $pets_total; ?></strong></div>
                <div class="sub">Dogs, cats and birds listed</div>
            </div>
            <div class="card stat">
                <h2>Health drives</h2>
                <div class="num"><strong><?php echo $sched_total; ?></strong></div>
                <div class="sub">Scheduled vaccination events</div>
            </div>
            <div class="card stat">
                <h2>Rescue reports</h2>
                <div class="num"><strong><?php echo $rescue_total; ?></strong></div>
                <div class="sub">Emergency reports received</div>
            </div>
            <div class="card stat">
                <h2>Registered users</h2>
                <div class="num"><strong><?php echo $members; ?></strong></div>
                <div class="sub">Adopters and rescuers</div>
            </div>
        </div>

        <!-- CHARTS / GRAPHS -->
        <div class="grid g2" style="margin-top: 24px;">
            <div class="card">
                <h2>Pets by species</h2>
                <div class="legend">
                    <span style="--c:var(--teal-2)">Dogs</span>
                    <span style="--c:var(--lav)">Cats</span>
                    <span style="--c:var(--mint)">Birds</span>
                </div>
                <div class="bubbles">
                    <?php $d = bubble($dogs,$bubble_max); $c = bubble($cats,$bubble_max); $b = bubble($birds,$bubble_max); ?>
                    <div class="b1" style="width:<?php echo $d; ?>px;height:<?php echo $d; ?>px"><?php echo $dogs; ?></div>
                    <div class="b2" style="width:<?php echo $c; ?>px;height:<?php echo $c; ?>px"><?php echo $cats; ?></div>
                    <div class="b3" style="width:<?php echo $b; ?>px;height:<?php echo $b; ?>px"><?php echo $birds; ?></div>
                </div>
            </div>

            <div class="card">
                <h2>Health drives per month</h2>
                <div class="bars">
                    <?php foreach ($months as $m): ?>
                        <div title="<?php echo $m['n']; ?> drive(s)"><i style="height:<?php echo round(($m['n'] / $max_month) * 100); ?>%"></i></div>
                    <?php endforeach; ?>
                </div>
                <div class="bars-x">
                    <?php foreach ($months as $m): ?><span><?php echo $m['label']; ?></span><?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>