<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$root = '../';

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Table that stores the rescue team's contact details (editable) */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rescue_contact_info (
    field_key   VARCHAR(50) PRIMARY KEY,
    field_value TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

/* Editable fields: key => [label, type, default, hint] */
$fields = [
    'hotline'   => ['Emergency rescue hotline', 'tel',   '',                              'Used for injured or urgent animals.'],
    'phone'     => ['Contact number',           'tel',   '',                              ''],
    'email'     => ['Email address',            'email', '',                              ''],
    'address'   => ['Rescue base / address',    'text',  'Clark College, Angeles City',   ''],
    'hours'     => ['Office hours',             'text',  'Mon - Sat, 8:00 AM - 5:00 PM',  ''],
    'facebook'  => ['Facebook link',            'url',   '',                              'Must start with http:// or https://'],
    'instagram' => ['Instagram link',           'url',   '',                              'Must start with http:// or https://'],
    'tiktok'    => ['TikTok link',              'url',   '',                              'Must start with http:// or https://'],
    'note'      => ['Note for adopters',        'text',  'For injured animals, please call the hotline first.', 'Short message shown with the details.'],
];

/* Add default rows once */
$ins = mysqli_prepare($conn, "INSERT IGNORE INTO rescue_contact_info (field_key, field_value) VALUES (?, ?)");
foreach ($fields as $key => $f) {
    mysqli_stmt_bind_param($ins, "ss", $key, $f[2]);
    mysqli_stmt_execute($ins);
}
mysqli_stmt_close($ins);

if (empty($_SESSION['ct_csrf'])) {
    $_SESSION['ct_csrf'] = bin2hex(random_bytes(16));
}

$flash = $_SESSION['ct_flash'] ?? null;
unset($_SESSION['ct_flash']);

/* ---------- SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_contact') {
    $msg = ['type' => 'err', 'text' => 'Something went wrong. Please try again.'];

    if (!hash_equals($_SESSION['ct_csrf'], $_POST['csrf'] ?? '')) {
        $msg['text'] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $clean = [];
        $error = '';

        foreach ($fields as $key => $f) {
            $val = trim($_POST[$key] ?? '');
            if (mb_strlen($val) > 255) {
                $error = $f[0] . ' is too long.';
                break;
            }
            if ($val !== '') {
                if ($f[1] === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address.';
                    break;
                }
                if ($f[1] === 'url' && !preg_match('#^https?://#i', $val)) {
                    $error = $f[0] . ' must start with http:// or https://';
                    break;
                }
                if ($f[1] === 'tel' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $val)) {
                    $error = 'Please enter a valid number for ' . strtolower($f[0]) . '.';
                    break;
                }
            }
            $clean[$key] = $val;
        }

        if ($error !== '') {
            $msg['text'] = $error;
        } else {
            $up = mysqli_prepare($conn, "INSERT INTO rescue_contact_info (field_key, field_value) VALUES (?, ?)
                                         ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)");
            foreach ($clean as $key => $val) {
                mysqli_stmt_bind_param($up, "ss", $key, $val);
                mysqli_stmt_execute($up);
            }
            mysqli_stmt_close($up);
            $msg = ['type' => 'ok', 'text' => 'Contact details saved.'];
        }
    }

    $_SESSION['ct_flash'] = $msg;
    header('Location: contact.php');
    exit;
}

/* ---------- LOAD ---------- */
$info = [];
$res = mysqli_query($conn, "SELECT field_key, field_value FROM rescue_contact_info");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $info[$r['field_key']] = $r['field_value'];
    }
}
foreach ($fields as $key => $f) {
    if (!isset($info[$key])) $info[$key] = $f[2];
}

$admin_name = e($_SESSION['username'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Contact</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">
<style>
    aside.side { display: flex; flex-direction: column; justify-content: space-between; }
    aside.side .nav-container { flex: 1; }
    aside.side .logout { border-top: 1px solid var(--line); padding-top: 12px; margin-top: auto; }
    aside.side .logout a { background: var(--red-bg); color: var(--red) !important; border-radius: 8px; transition: background 0.2s; }
    aside.side .logout a:hover { background: #fecaca; }

    /* contact page */
    .ct-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 24px; margin-top: 15px; align-items: start; }
    @media (max-width: 900px) { .ct-grid { grid-template-columns: 1fr; } }

    .ct-alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-top: 15px; }
    .ct-alert.ok  { background: #dcfce7; color: #166534; }
    .ct-alert.err { background: var(--red-bg); color: var(--red); }

    .ct-list { list-style: none; margin: 0; padding: 0; }
    .ct-list li { padding: 11px 0; border-bottom: 1px solid var(--line); font-size: 14px; word-break: break-word; }
    .ct-list li:last-child { border-bottom: 0; }
    .ct-list small { display: block; font-size: 12px; opacity: .65; margin-bottom: 2px; }
    .ct-list .ct-empty { opacity: .5; font-style: italic; }
    .ct-list .ct-hotline { font-size: 18px; font-weight: 700; }

    .ct-field { display: block; margin-bottom: 14px; font-size: 13px; font-weight: 600; }
    .ct-field input {
        display: block; width: 100%; box-sizing: border-box; margin-top: 6px;
        padding: 10px 12px; font: inherit; font-weight: 400;
        border: 1px solid var(--line); border-radius: 8px; background: #fff;
    }
    .ct-field input:focus { outline: 2px solid var(--teal-2); outline-offset: 0; }
    .ct-hint { display: block; font-size: 11.5px; font-weight: 400; opacity: .7; margin-top: 4px; }

    .ct-actions { display: flex; gap: 10px; margin-top: 6px; }
    .ct-btn {
        display: inline-block; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--line);
        font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none;
        background: #fff; color: inherit;
    }
    .ct-btn.primary { background: var(--teal-2); border-color: var(--teal-2); color: #fff; }
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
                <a href="settings.php">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.400 15a1.700 1.700 0 0 0 .3 1.800l.1.1a2 2 0 1 1-2.800 2.800l-.1-.1a1.700 1.700 0 0 0-1.800-.3 1.700 1.700 0 0 0-1 1.500V21a2 2 0 1 1-4 0v-.1a1.700 1.700 0 0 0-1.100-1.500 1.700 1.700 0 0 0-1.800.3l-.1.1a2 2 0 1 1-2.800-2.800l-.1-.1a1.700 1.700 0 0 0 .3-1.800 1.700 1.700 0 0 0-1.500-1H3a2 2 0 1 1 0-4h.1a1.700 1.700 0 0 0 1.500-1.100 1.700 1.700 0 0 0-.3-1.800l-.1-.1a2 2 0 1 1 2.800-2.800l.1.1a1.700 1.700 0 0 0 1.800.3H9a1.700 1.700 0 0 0 1-1.500V3a2 2 0 1 1 4 0v.1a1.700 1.700 0 0 0 1 1.500 1.700 1.700 0 0 0 1.800-.3l-.1-.1a2 2 0 1 1 2.800 2.800l-.1.1a1.700 1.700 0 0 0-.3 1.800V9a1.700 1.700 0 0 0 1.500 1H21a2 2 0 1 1 0 4h-.1a1.700 1.700 0 0 0-1.500 1z"/></svg>
                    Settings
                </a>
                <a href="contact.php" class="active">
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
                <h1>Contact</h1>
                <p>Manage the rescue team's contact details</p>
            </div>
            <div style="display:flex;align-items:center;gap:14px">
                <div class="role">Active session role: <b>Admin / Staff</b></div>
                <?php include __DIR__ . '/admin_bell.php'; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="ct-alert <?php echo $flash['type'] === 'ok' ? 'ok' : 'err'; ?>">
                <?php echo e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <div class="ct-grid">

            <!-- CURRENT DETAILS -->
            <div class="card">
                <h2>Current contact details</h2>
                <ul class="ct-list">
                    <?php foreach ($fields as $key => $f):
                        $val = $info[$key] ?? '';
                    ?>
                        <li>
                            <small><?php echo e($f[0]); ?></small>
                            <?php if ($val === ''): ?>
                                <span class="ct-empty">Not set</span>
                            <?php elseif ($f[1] === 'url'): ?>
                                <a href="<?php echo e($val); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($val); ?></a>
                            <?php elseif ($key === 'hotline'): ?>
                                <span class="ct-hotline"><?php echo e($val); ?></span>
                            <?php else: ?>
                                <?php echo e($val); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- EDIT FORM -->
            <div class="card">
                <h2>Edit contact details</h2>
                <form method="post">
                    <input type="hidden" name="action" value="save_contact">
                    <input type="hidden" name="csrf" value="<?php echo e($_SESSION['ct_csrf']); ?>">

                    <?php foreach ($fields as $key => $f): ?>
                        <label class="ct-field">
                            <?php echo e($f[0]); ?>
                            <input
                                type="<?php echo e($f[1]); ?>"
                                name="<?php echo e($key); ?>"
                                maxlength="255"
                                value="<?php echo e($info[$key] ?? ''); ?>"
                            >
                            <?php if ($f[3] !== ''): ?>
                                <span class="ct-hint"><?php echo e($f[3]); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>

                    <div class="ct-actions">
                        <button type="submit" class="ct-btn primary">Save changes</button>
                        <a href="contact.php" class="ct-btn">Reset</a>
                    </div>
                </form>
            </div>

        </div>
    </main>
</div>
</body>
</html>