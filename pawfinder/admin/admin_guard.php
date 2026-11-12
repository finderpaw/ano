<?php
// admin_guard.php
// Gumagana kahit nasa paw/admin/ o paw/includes/ ang file na ito.
//
// Sa admin.php (nasa paw/admin/):
//   kung nasa paw/admin/ ang guard:     require_once __DIR__ . '/admin_guard.php';
//   kung nasa paw/includes/ ang guard:  require_once __DIR__ . '/../includes/admin_guard.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Hanapin kung nasaan talaga ang admin_login.php sa disk
$candidates = [
    __DIR__ . '/admin_login.php',           // guard ay nasa admin/
    __DIR__ . '/../admin/admin_login.php',  // guard ay nasa includes/
];

$loginFile = '';
foreach ($candidates as $c) {
    if (file_exists($c)) {
        $loginFile = str_replace('\\', '/', (string)realpath($c));
        break;
    }
}

// Gawing web path
$docRoot = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
if ($loginFile !== '' && $docRoot !== '' && strpos($loginFile, $docRoot) === 0) {
    $ADMIN_LOGIN_URL = substr($loginFile, strlen($docRoot)); // hal. /paw/admin/admin_login.php
} else {
    $ADMIN_LOGIN_URL = '/paw/admin/admin_login.php';          // fallback
}

$ADMIN_TIMEOUT = 30 * 60; // 30 minutes

$isAdmin = !empty($_SESSION['logged_in'])
        && !empty($_SESSION['is_admin'])
        && ($_SESSION['role'] ?? '') === 'admin';

// DEBUG: gawing true para makita kung bakit hindi pumapasa. Ibalik sa false kapag ayos na.
$ADMIN_GUARD_DEBUG = false;

if (!$isAdmin) {
    if ($ADMIN_GUARD_DEBUG) {
        echo '<h3>admin_guard DEBUG</h3><pre>';
        echo 'session_id: ' . session_id() . "\n";
        echo 'logged_in : ' . var_export($_SESSION['logged_in'] ?? null, true) . "\n";
        echo 'is_admin  : ' . var_export($_SESSION['is_admin'] ?? null, true) . "\n";
        echo 'role      : ' . var_export($_SESSION['role'] ?? null, true) . "\n";
        echo 'redirect would go to: ' . $ADMIN_LOGIN_URL . "\n\n";
        echo 'FULL SESSION:' . "\n";
        print_r($_SESSION);
        echo '</pre>';
        exit();
    }
    header('Location: ' . $ADMIN_LOGIN_URL);
    exit();
}

if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > $ADMIN_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: ' . $ADMIN_LOGIN_URL . '?timeout=1');
    exit();
}

$_SESSION['admin_last_activity'] = time();