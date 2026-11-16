<?php
// admin_login_process.php — para lang sa ADMIN accounts (nasa paw/admin/)
session_start();

// config.php: hanapin sa admin/ muna, tapos sa parent (paw/)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    require_once __DIR__ . '/../config.php';
}
// $conn (mysqli)

// DEBUG: true = ipapakita kung bakit humihinto. Gawing false kapag ayos na.
$ADMIN_DEBUG = false;

function admin_fail(string $msg): void
{
    global $ADMIN_DEBUG;
    if ($ADMIN_DEBUG) {
        echo '<h3>admin_login_process DEBUG: NABIGO</h3><pre>';
        echo 'Dahilan: ' . htmlspecialchars($msg) . "\n\n";
        echo 'POST keys: ' . implode(', ', array_keys($_POST)) . "\n";
        echo 'csrf galing sa form : ' . var_export(isset($_POST['csrf']) ? substr($_POST['csrf'], 0, 8) . '…' : null, true) . "\n";
        echo 'csrf sa session     : ' . var_export(isset($_SESSION['csrf']) ? substr($_SESSION['csrf'], 0, 8) . '…' : null, true) . "\n";
        echo 'session_id          : ' . session_id() . "\n";
        echo '</pre>';
        exit();
    }
    $_SESSION['admin_error'] = $msg;
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_login.php');
    exit();
}

// 1) CSRF check
if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    admin_fail('Invalid request. Please try again.');
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '';

if ($username === '' || $password === '') {
    admin_fail('Please enter your username and password.');
}

// 2) Throttle: max 5 maling try kada 15 minutes kada IP
$q = $conn->prepare("SELECT COUNT(*) FROM admin_login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
$q->bind_param("s", $ip);
$q->execute();
$q->bind_result($attempts);
$q->fetch();
$q->close();

if ($attempts >= 5) {
    admin_fail('Too many failed attempts. Please try again in 15 minutes.');
}

// 3) Admin accounts LANG
$stmt = $conn->prepare(
    "SELECT id, username, password, role
     FROM users
     WHERE (username = ? OR email = ?) AND LOWER(role) = 'admin'
     LIMIT 1"
);
$stmt->bind_param("ss", $username, $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 4) Verify password
if (!$user || !password_verify($password, $user['password'])) {
    $log = $conn->prepare("INSERT INTO admin_login_attempts (ip) VALUES (?)");
    $log->bind_param("s", $ip);
    $log->execute();
    $log->close();

    admin_fail('Invalid admin credentials.');
}

// 5) Success
$clr = $conn->prepare("DELETE FROM admin_login_attempts WHERE ip = ?");
$clr->bind_param("s", $ip);
$clr->execute();
$clr->close();

session_regenerate_id(true);

$_SESSION['logged_in']           = true;
$_SESSION['user_id']             = (int)$user['id'];
$_SESSION['username']            = $user['username'];
$_SESSION['role']                = 'admin';
$_SESSION['is_admin']            = true;
$_SESSION['admin_last_activity'] = time();
unset($_SESSION['csrf']);

if ($ADMIN_DEBUG) {
    echo '<h3>admin_login_process DEBUG: PUMASA ANG LOGIN</h3><pre>';
    print_r($_SESSION);
    echo '</pre><p><a href="admin.php">Pumunta sa admin.php</a></p>';
    exit();
}

// Parehong folder ang admin.php (paw/admin/), kaya walang "admin/" sa unahan
header('Location: admin.php');
exit();