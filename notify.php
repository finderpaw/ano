<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

function sendAdoptionNotification(array $pet, string $requesterName, string $requesterEmail): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_APP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // port 465 (gumana sa test)
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_USER, 'Pawfinder');
        $mail->addAddress(ADMIN_NOTIFY_EMAIL);
        $mail->addReplyTo($requesterEmail, $requesterName);

        $petName    = htmlspecialchars($pet['pet_name'] ?? '');
        $petSpecies = htmlspecialchars($pet['pet_species'] ?? '');
        $petBreed   = htmlspecialchars($pet['breed'] ?? '');
        $name       = htmlspecialchars($requesterName);
        $email      = htmlspecialchars($requesterEmail);

        $mail->isHTML(true);
        $mail->Subject = 'New Adoption Request: ' . ($pet['pet_name'] ?? 'Pet');
        $mail->Body    = "
            <h2>New Adoption Request</h2>
            <p><strong>Pet:</strong> {$petName} ({$petSpecies}, {$petBreed})</p>
            <p><strong>Requested by:</strong> {$name}</p>
            <p><strong>Email:</strong> {$email}</p>
            <p><strong>Date:</strong> " . date('M d, Y h:i A') . "</p>
            <p>Log in to the admin dashboard to review this request.</p>
        ";
        $mail->AltBody = "New adoption request for " . ($pet['pet_name'] ?? 'a pet')
                       . " from {$requesterName} ({$requesterEmail}).";

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}