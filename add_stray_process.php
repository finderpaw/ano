<?php
// add_stray_process.php (located in pawfinder/)
// Saves a missing/found stray pet report and sends an email notification to the admin.
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_config.php';

// Load PHPMailer (Composer or manual install)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

if (empty($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

$back_form = 'report.php';   // <-- change this to the real file name of your report form
$done_url  = 'homepage.php?stray_reported=1';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $back_form);
    exit();
}

function stray_fail(string $msg): void
{
    global $back_form;
    echo '<p style="font-family:Arial;color:#b00">' . htmlspecialchars($msg) . '</p>';
    echo '<p><a href="' . htmlspecialchars($back_form) . '">← Back to the form</a></p>';
    exit();
}

$report_type      = trim($_POST['report_type'] ?? '');
$pet_name         = trim($_POST['pet_name'] ?? '');
$pet_species      = strtolower(trim($_POST['pet_species'] ?? ''));
$markings         = trim($_POST['markings'] ?? '');
$barangay         = trim($_POST['barangay'] ?? '');
$landmark         = trim($_POST['landmark'] ?? '');
$sighting_date    = trim($_POST['sighting_date'] ?? '');
$reporter_contact = trim($_POST['reporter_contact'] ?? '');
$stray_details    = trim($_POST['stray_details'] ?? '');

if ($report_type === '' || $pet_name === '' || $pet_species === '' || $markings === ''
    || $barangay === '' || $landmark === '' || $sighting_date === '' || $reporter_contact === '') {
    stray_fail('Please fill in all required fields.');
}

$d = DateTime::createFromFormat('Y-m-d', $sighting_date);
if (!$d || $d->format('Y-m-d') !== $sighting_date) {
    stray_fail('Invalid date.');
}

// ---------- Photo upload ----------
if (!isset($_FILES['stray_photo']) || $_FILES['stray_photo']['error'] !== UPLOAD_ERR_OK) {
    stray_fail('There was a problem uploading the photo. Please try again.');
}

$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$ext = strtolower(pathinfo($_FILES['stray_photo']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    stray_fail('Invalid file type. Only JPG, JPEG, PNG, and WEBP are allowed.');
}

if (@getimagesize($_FILES['stray_photo']['tmp_name']) === false) {
    stray_fail('The file is not a valid image.');
}

$safe_base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($_FILES['stray_photo']['name'], PATHINFO_FILENAME));
$file_name = time() . '_stray_' . $safe_base . '.' . $ext;

if (!move_uploaded_file($_FILES['stray_photo']['tmp_name'], $upload_dir . $file_name)) {
    stray_fail('Could not save the photo to the uploads folder.');
}

$photo_path = 'uploads/' . $file_name;

// ---------- Save to database ----------
$stmt = $conn->prepare(
    "INSERT INTO stray_reports (report_type, pet_name, pet_species, markings, barangay, landmark, sighting_date, stray_photo, reporter_contact, stray_details)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    "ssssssssss",
    $report_type, $pet_name, $pet_species, $markings, $barangay,
    $landmark, $sighting_date, $photo_path, $reporter_contact, $stray_details
);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    stray_fail('Error saving to database: ' . $err);
}
$report_id = $stmt->insert_id;
$stmt->close();

// ---------- Notify the admin by Gmail ----------
// If the email fails, the report is still saved and the error is written to the PHP error log.
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
    $mail->addAddress(ADMIN_EMAIL);
    $mail->addAttachment($upload_dir . $file_name);

    $e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $mail->isHTML(true);
    $mail->Subject = 'New Stray Report #' . $report_id . ': ' . $report_type;
    $mail->Body =
        '<h2>New stray pet report</h2>' .
        '<p>A new report was submitted on Pawfinder and is waiting for your review.</p>' .
        '<table cellpadding="6" style="border-collapse:collapse;font-family:Arial">' .
        '<tr><td><b>Report type</b></td><td>' . $e($report_type) . '</td></tr>' .
        '<tr><td><b>Pet name / title</b></td><td>' . $e($pet_name) . '</td></tr>' .
        '<tr><td><b>Animal type</b></td><td>' . $e($pet_species) . '</td></tr>' .
        '<tr><td><b>Markings</b></td><td>' . $e($markings) . '</td></tr>' .
        '<tr><td><b>Barangay</b></td><td>' . $e($barangay) . '</td></tr>' .
        '<tr><td><b>Landmark</b></td><td>' . $e($landmark) . '</td></tr>' .
        '<tr><td><b>Date lost or found</b></td><td>' . $e($sighting_date) . '</td></tr>' .
        '<tr><td><b>Reporter contact</b></td><td>' . $e($reporter_contact) . '</td></tr>' .
        '<tr><td><b>Notes</b></td><td>' . nl2br($e($stray_details)) . '</td></tr>' .
        '</table>' .
        '<p>The photo is attached to this email.</p>' .
        '<p><a href="' . $e(SITE_URL . '/admin/admin.php') . '">Open the admin panel</a></p>';
    $mail->AltBody = 'New stray report #' . $report_id . ' (' . $report_type . ') from ' . $barangay . '. Please check the admin panel.';

    $mail->send();
} catch (MailException $ex) {
    error_log('Stray report email failed: ' . $ex->getMessage());
}

header("Location: " . $done_url);
exit();