<?php
require_once __DIR__ . '/admin_guard.php';

$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

if (!isset($conn) || !$conn) {
    die("Database connection is not available. Check config.php path.");
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NOT NULL
)");

$defaults = [
    'site_name' => 'PAWFINDER', 
    'contact_email' => '', 
    'contact_phone' => '', 
    'address' => '',
    'adoptions_open' => '1', 
    'rescue_alerts' => '1'
];

function load_settings($conn, $defaults) {
    $out = $defaults;
    $r = @mysqli_query($conn, 'SELECT setting_key, setting_value FROM settings');
    if ($r instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($r)) {
            if (isset($out[$row['setting_key']])) {
                $out[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    return $out;
}

function find_account($conn, $username) {
    foreach (['registered', 'users'] as $t) {
        $st = $conn->prepare("SELECT id, password FROM `$t` WHERE username = ?");
        if ($st) {
            $st->bind_param("s", $username);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) return [$t, $row];
        }
    }
    return [null, null];
}

$me = $_SESSION['username'] ?? '';
$success_msg = '';
$error_msg = '';
$root = '../';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'site') {
        $email = trim($_POST['contact_email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { 
            $error_msg = 'Enter a valid contact email.';
        } else {
            $vals = [
                'site_name' => trim($_POST['site_name'] ?? '') ?: 'PAWFINDER', 
                'contact_email' => $email,
                'contact_phone' => trim($_POST['contact_phone'] ?? ''), 
                'address' => trim($_POST['address'] ?? ''),
                'adoptions_open' => isset($_POST['adoptions_open']) ? '1' : '0', 
                'rescue_alerts' => isset($_POST['rescue_alerts']) ? '1' : '0'
            ];
            foreach ($vals as $k => $v) {
                $st = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                if ($st) {
                    $st->bind_param("ss", $k, $v);
                    $st->execute();
                    $st->close();
                }
            }
            $success_msg = 'Settings saved successfully.';
        }
    } elseif ($act === 'password') {
        [$table, $acc] = find_account($conn, $me);
        $cur = $_POST['current'] ?? ''; 
        $new = $_POST['new'] ?? ''; 
        $conf = $_POST['confirm'] ?? '';
        
        if (!$acc) {
            $error_msg = 'Could not find your account.';
        } elseif (!password_verify($cur, $acc['password']) && !hash_equals((string)$acc['password'], $cur)) {
            $error_msg = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error_msg = 'New password must be at least 8 characters.';
        } elseif ($new !== $conf) {
            $error_msg = 'New passwords do not match.';
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $st = $conn->prepare("UPDATE `$table` SET password = ? WHERE id = ?");
            if ($st) {
                $acc_id = (int)$acc['id'];
                $st->bind_param("si", $hashed, $acc_id);
                $st->execute();
                $st->close();
            }
            $success_msg = 'Password changed successfully.';
        }
    }
}

$s = load_settings($conn, $defaults);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Admin Settings</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">
<style>
    aside.side { display: flex; flex-direction: column; justify-content: space-between; }
    aside.side .nav-container { flex: 1; }
    aside.side .logout { border-top: 1px solid var(--line); padding-top: 12px; margin-top: auto; }
    aside.side .logout a { background: var(--red-bg); color: var(--red) !important; border-radius: 8px; transition: background 0.2s; }
    aside.side .logout a:hover { background: #fecaca; }
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
                <a href="admin.php">
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
                <a href="settings.php" class="active">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.400 15a1.700 1.700 0 0 0 .3 1.800l.1.1a2 2 0 1 1-2.800 2.800l-.1-.1a1.700 1.700 0 0 0-1.800-.3 1.700 1.700 0 0 0-1 1.500V21a2 2 0 1 1-4 0v-.1a1.700 1.700 0 0 0-1.100-1.500 1.700 1.700 0 0 0-1.800.3l-.1.1a2 2 0 1 1-2.800-2.800l.1-.1a1.700 1.700 0 0 0 .3-1.800 1.700 1.700 0 0 0-1.500-1H3a2 2 0 1 1 0-4h.1a1.700 1.700 0 0 0 1.500-1.100 1.700 1.700 0 0 0-.3-1.800l-.1-.1a2 2 0 1 1 2.800-2.800l.1.1a1.700 1.700 0 0 0 1.800.3H9a1.700 1.700 0 0 0 1-1.500V3a2 2 0 1 1 4 0v.1a1.700 1.700 0 0 0 1 1.500 1.700 1.700 0 0 0 1.800-.3l-.1-.1a2 2 0 1 1 2.800 2.800l-.1.1a1.700 1.700 0 0 0-.3 1.800V9a1.700 1.700 0 0 0 1.500 1H21a2 2 0 1 1 0 4h-.1a1.700 1.700 0 0 0-1.500 1z"/></svg>
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

    <!-- MAIN CONTENT -->
    <main class="main">
        <div class="top">
            <div>
                <h1>Settings</h1>
                <p>Manage system preferences and your administrator account security.</p>
            </div>
            <div class="role">Active role: <b>Admin</b></div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="flash ok" style="margin-top: 15px;"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="flash err" style="margin-top: 15px;"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="grid g2" style="align-items:start; margin-top: 24px;">
            <section class="card">
                <h2 style="margin-bottom: 16px;">System Configuration</h2>
                <form method="post" class="form-grid one">
                    <input type="hidden" name="action" value="site">
                    <label class="field">System name
                        <input class="input" name="site_name" value="<?php echo htmlspecialchars($s['site_name']); ?>">
                    </label>
                    <label class="field">Contact email
                        <input class="input" type="email" name="contact_email" value="<?php echo htmlspecialchars($s['contact_email']); ?>">
                    </label>
                    <label class="field">Contact number
                        <input class="input" name="contact_phone" value="<?php echo htmlspecialchars($s['contact_phone']); ?>">
                    </label>
                    <label class="field">Office address
                        <input class="input" name="address" value="<?php echo htmlspecialchars($s['address']); ?>">
                    </label>
                    <label class="check">
                        <input type="checkbox" name="adoptions_open" <?php echo $s['adoptions_open'] === '1' ? 'checked' : ''; ?>> Accept new adoption requests
                    </label>
                    <label class="check">
                        <input type="checkbox" name="rescue_alerts" <?php echo $s['rescue_alerts'] === '1' ? 'checked' : ''; ?>> Alert staff to new rescue reports
                    </label>
                    <div><button class="btn" type="submit">Save settings</button></div>
                </form>
            </section>

            <section class="card">
                <h2 style="margin-bottom: 6px;">Change Password</h2>
                <p class="muted" style="margin-bottom: 16px;">Signed in as <b><?php echo htmlspecialchars($me ?: 'admin'); ?></b></p>
                <form method="post" class="form-grid one">
                    <input type="hidden" name="action" value="password">
                    <label class="field">Current password
                        <input class="input" type="password" name="current" autocomplete="current-password" required>
                    </label>
                    <label class="field">New password (8+ characters)
                        <input class="input" type="password" name="new" minlength="8" autocomplete="new-password" required>
                    </label>
                    <label class="field">Confirm new password
                        <input class="input" type="password" name="confirm" minlength="8" autocomplete="new-password" required>
                    </label>
                    <div style="margin-top: 8px;"><button class="btn" type="submit">Update password</button></div>
                </form>
            </section>
        </div>
    </main>
</div>
</body>
</html>