<?php
// adopt_request.php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: homepage.php");
    exit();
}

$petId = (int)($_POST['pet_id'] ?? 0);
$back  = 'pet_details.php?id=' . $petId;

// Dapat naka-login
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Admin ay hindi pwedeng mag-adopt
if (($_SESSION['role'] ?? '') === 'admin') {
    $_SESSION['adopt_msg'] = 'Admins cannot send adoption requests.';
    header("Location: $back");
    exit();
}

$userId = (int)$_SESSION['user_id'];

// Check kung existing at Available pa ang pet
$stmt = $conn->prepare("SELECT status FROM pets WHERE id = ?");
$stmt->bind_param("i", $petId);
$stmt->execute();
$pet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pet) {
    $_SESSION['adopt_msg'] = 'Pet not found.';
    header("Location: homepage.php");
    exit();
}

if (($pet['status'] ?? 'Available') !== 'Available') {
    $_SESSION['adopt_msg'] = 'Sorry, this pet is no longer available for adoption.';
    header("Location: $back");
    exit();
}

// Iwas duplicate request
$chk = $conn->prepare("SELECT id FROM adoption_requests WHERE pet_id = ? AND user_id = ? AND status = 'Pending' LIMIT 1");
$chk->bind_param("ii", $petId, $userId);
$chk->execute();
$exists = $chk->get_result()->num_rows > 0;
$chk->close();

if ($exists) {
    $_SESSION['adopt_msg'] = 'You already sent an adoption request for this pet.';
    header("Location: $back");
    exit();
}

// Save request + gawing Pending ang pet
try {
    $conn->begin_transaction();

    $ins = $conn->prepare("INSERT INTO adoption_requests (pet_id, user_id) VALUES (?, ?)");
    $ins->bind_param("ii", $petId, $userId);
    $ins->execute();
    $ins->close();

    $upd = $conn->prepare("UPDATE pets SET status = 'Pending' WHERE id = ? AND status = 'Available'");
    $upd->bind_param("i", $petId);
    $upd->execute();
    $upd->close();

    $conn->commit();
    $_SESSION['adopt_msg'] = 'Your adoption request was sent! Please wait for the admin to contact you.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['adopt_msg'] = 'Something went wrong. Please try again.';
}

header("Location: $back");
exit();