<?php
// login_process.php — para sa REGULAR users lang (hindi admin)
session_start();
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$pet_id   = $_POST['pet_id'] ?? '';
$redirect = trim($_POST['redirect'] ?? '');

function login_fail(string $msg, string $redirect = ''): void
{
    $_SESSION['error'] = $msg;
    $target = $redirect !== '' ? 'login.php?redirect=' . urlencode($redirect) : 'login.php';
    header("Location: " . $target);
    exit();
}

if ($username === '' || $password === '') {
    login_fail("Please enter your username and password.", $redirect);
}

$sql = "SELECT id, username, email, password, role
        FROM users
        WHERE username = ? OR email = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $username, $username);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

// Mali ang username o password
if (!$user || !password_verify($password, $user['password'])) {
    login_fail("Invalid username or password.", $redirect);
}

$role = strtolower($user['role'] ?? 'user');

// ADMIN: bawal dito. Tama ang password pero admin account, kaya popup ang ipapakita.
if ($role === 'admin' || strtolower($user['username']) === 'admin') {
    $_SESSION['admin_blocked'] = true;
    header("Location: login.php");
    exit();
}

session_regenerate_id(true);

$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['logged_in'] = true;
$_SESSION['role']      = $role;

if ($role === 'rescuer') {
    header("Location: rescue/overview.php");
    exit();
}

// Safe local redirect check (para hindi ma-redirect sa external site)
if ($redirect !== '' && !preg_match('#^(https?:)?//#i', $redirect)) {
    header("Location: " . $redirect);
    exit();
}

// Local / regular users
if (!empty($pet_id)) {
    header("Location: pet_details.php?id=" . urlencode($pet_id));
} else {
    header("Location: homepage.php");
}
exit();