<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

$is_admin = ($_SESSION['role'] ?? '') === 'admin' && !empty($_SESSION['is_admin']);

$back_form = $is_admin ? 'admin/add.php' : 'add.php';
$done_url  = $is_admin ? 'admin/admin.php?success=1' : 'homepage.php?pet_added=1';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $back_form);
    exit();
}

$pet_name        = trim($_POST['pet_name'] ?? '');
$pet_species     = strtolower(trim($_POST['pet_species'] ?? ''));
$gender          = in_array($_POST['gender'] ?? '', ['Male', 'Female'], true) ? $_POST['gender'] : 'Unknown';
$breed           = trim($_POST['breed'] ?? '');
$age             = trim($_POST['age'] ?? '');
$barangay        = trim($_POST['barangay'] ?? '');
$adoption_reason = trim($_POST['adoption_reason'] ?? '');
$health_issues   = trim($_POST['health_issues'] ?? '');
$pet_details     = trim($_POST['pet_details'] ?? '');

function add_pet_fail(string $msg): void
{
    global $back_form;
    echo '<p style="font-family:Arial;color:#b00">' . htmlspecialchars($msg) . '</p>';
    echo '<p><a href="' . htmlspecialchars($back_form) . '">← Back to the form</a></p>';
    exit();
}

if ($pet_name === '' || $pet_species === '') {
    add_pet_fail('Pet name or animal type is missing.');
}

if (!isset($_FILES['pet_photo']) || $_FILES['pet_photo']['error'] !== UPLOAD_ERR_OK) {
    add_pet_fail('There was a problem uploading the photo. Please try again.');
}

$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$ext = strtolower(pathinfo($_FILES['pet_photo']['name'], PATHINFO_EXTENSION));
$allowed_types = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array($ext, $allowed_types, true)) {
    add_pet_fail('Invalid file type. Only JPG, JPEG, PNG, and WEBP are allowed.');
}

if (@getimagesize($_FILES['pet_photo']['tmp_name']) === false) {
    add_pet_fail('The file is not a valid image.');
}

$safe_base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($_FILES['pet_photo']['name'], PATHINFO_FILENAME));
$file_name = time() . '_' . $safe_base . '.' . $ext;

if (!move_uploaded_file($_FILES['pet_photo']['tmp_name'], $upload_dir . $file_name)) {
    add_pet_fail('Could not save the photo to the uploads folder.');
}

$photo_path = 'uploads/' . $file_name;

$stmt = $conn->prepare(
    "INSERT INTO pets (pet_name, pet_species, gender, breed, age, barangay, pet_photo, adoption_reason, health_issues, pet_details)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    "ssssssssss",
    $pet_name, $pet_species, $gender, $breed, $age, $barangay,
    $photo_path, $adoption_reason, $health_issues, $pet_details
);

if ($stmt->execute()) {
    $stmt->close();
    header("Location: " . $done_url);
    exit();
}

$err = $stmt->error;
$stmt->close();
add_pet_fail('Error saving to database: ' . $err);