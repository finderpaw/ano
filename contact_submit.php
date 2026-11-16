<?php
session_start();
require_once 'config.php';

/* Inquiry types (must match the dropdown in contact.php) */
$types = [
    'general'  => 'General Inquiry',
    'adoption' => 'Adoption Question',
    'rescue'   => 'Emergency Rescue Concern',
    'health'   => 'Health Drive / Vaccination',
    'partner'  => 'Shelter Partnership',
    'report'   => 'Report a Problem / Listing',
];

function contact_back(string $key = '', string $text = ''): void
{
    if ($key !== '') {
        $_SESSION[$key] = $text;
    }
    header("Location: contact.php#contact-form");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    contact_back();
}

/* Simple flood guard: one message per 30 seconds per visitor */
if (!empty($_SESSION['last_contact']) && time() - $_SESSION['last_contact'] < 30) {
    contact_back('contact_error', 'Please wait a few seconds before sending another message.');
}

$name    = trim($_POST['full_name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$contact = trim($_POST['contact_number'] ?? '');
$type    = $_POST['inquiry_type'] ?? 'general';
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!isset($types[$type])) {
    $type = 'general';
}

if ($name === '' || $email === '' || $message === '') {
    contact_back('contact_error', 'Please fill in your name, email and message.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    contact_back('contact_error', 'Please enter a valid email address.');
}

if (
    mb_strlen($name) > 100 || mb_strlen($email) > 100 ||
    mb_strlen($contact) > 30 || mb_strlen($subject) > 100 ||
    mb_strlen($message) > 2000
) {
    contact_back('contact_error', 'One or more fields are too long.');
}

$label = $types[$type];

/* Title shown in the notification list (max 150 characters) */
$title = $label . ($subject !== '' ? ': ' . $subject : '');
$title = mb_substr($title, 0, 150);

/* ---------- 1) Admin notification (admin/notification.php, "Admin alerts") ---------- */
$adminBody  = "From: $name\n";
$adminBody .= "Email: $email\n";
$adminBody .= "Contact number: " . ($contact !== '' ? $contact : 'Not provided') . "\n";
$adminBody .= "Inquiry type: $label\n";
if ($subject !== '') {
    $adminBody .= "Subject: $subject\n";
}
$adminBody .= "\n" . $message;

/* ---------- 2) Rescuer notification (rescue/notifications.php) ----------
   Only for "Emergency Rescue Concern". The email is left out on purpose;
   rescuers only get the name, contact number and the message. */
$rescuerBody  = "Reported by: $name\n";
$rescuerBody .= "Contact number: " . ($contact !== '' ? $contact : 'Not provided') . "\n";
if ($subject !== '') {
    $rescuerBody .= "Subject: $subject\n";
}
$rescuerBody .= "\n" . $message;

try {
    $stmt = $conn->prepare(
        "INSERT INTO notifications (title, message, audience, type)
         VALUES (?, ?, 'admin', 'contact_message')"
    );
    $stmt->bind_param("ss", $title, $adminBody);
    $stmt->execute();
    $stmt->close();

    if ($type === 'rescue') {
        $rTitle = mb_substr('Rescue concern: ' . ($subject !== '' ? $subject : $name), 0, 150);

        $stmt = $conn->prepare(
            "INSERT INTO notifications (title, message, audience, type)
             VALUES (?, ?, 'rescuer', 'contact_message')"
        );
        $stmt->bind_param("ss", $rTitle, $rescuerBody);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('contact_submit: ' . $e->getMessage());
    contact_back('contact_error', 'Sorry, we could not send your message. Please try again.');
}

$_SESSION['last_contact'] = time();
contact_back('contact_success', 'Thank you! Your message was sent. Our team will get back to you soon.');