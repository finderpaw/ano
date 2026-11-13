<?php
// pawfinder/adoption_application.php
// Adoption application: screening questions + valid ID + agreement.
// Papalit ito sa direktang pag-adopt. Dito na gagawa ng adoption request.
session_start();
include 'config.php';
require_once 'notify.php';

$pet_id = isset($_GET['pet_id']) ? intval($_GET['pet_id']) : 0;

/* ---------- Access checks ---------- */
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$petId  = (int)($_GET['pet_id'] ?? $_POST['pet_id'] ?? 0);
$back   = 'pet_details.php?id=' . $petId;
$userId = (int)$_SESSION['user_id'];

if (($_SESSION['role'] ?? '') === 'admin') {
    $_SESSION['adopt_msg'] = 'Admins cannot send adoption requests.';
    header("Location: $back");
    exit();
}

$st = $conn->prepare("SELECT * FROM pets WHERE id = ?");
$st->bind_param("i", $petId);
$st->execute();
$pet = $st->get_result()->fetch_assoc();
$st->close();

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

$st = $conn->prepare("SELECT id FROM adoption_requests WHERE pet_id = ? AND user_id = ? AND status = 'Pending' LIMIT 1");
$st->bind_param("ii", $petId, $userId);
$st->execute();
$dup = $st->get_result()->num_rows > 0;
$st->close();
if ($dup) {
    $_SESSION['adopt_msg'] = 'You already sent an adoption request for this pet.';
    header("Location: $back");
    exit();
}

$st = $conn->prepare("SELECT fullname, email, contact, address FROM users WHERE id = ?");
$st->bind_param("i", $userId);
$st->execute();
$me = $st->get_result()->fetch_assoc() ?: ['fullname' => '', 'email' => '', 'contact' => '', 'address' => ''];
$st->close();

/* ---------- Table ---------- */
$conn->query("CREATE TABLE IF NOT EXISTS adoption_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    pet_id INT NOT NULL,
    id_type VARCHAR(50) NOT NULL,
    id_file VARCHAR(255) NOT NULL,
    home_photo VARCHAR(255) NOT NULL DEFAULT '',
    answers LONGTEXT NOT NULL,
    flags TEXT NOT NULL,
    signature_name VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    agreed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_request (request_id)
)");

/* ---------- Helpers ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pv($k, $default = '') { return h($_POST[$k] ?? $default); }
function isChecked($k, $val) { return (($_POST[$k] ?? '') === $val) ? 'checked' : ''; }
function isSelected($k, $val) { return (($_POST[$k] ?? '') === $val) ? 'selected' : ''; }

function radios($name, array $opts) {
    foreach ($opts as $o) {
        echo '<label class="opt"><input type="radio" name="' . h($name) . '" value="' . h($o) . '" '
           . isChecked($name, $o) . ' required> ' . h($o) . '</label>';
    }
}
function selectBox($name, array $opts) {
    echo '<select name="' . h($name) . '" required><option value="">-- Select --</option>';
    foreach ($opts as $o) {
        echo '<option value="' . h($o) . '" ' . isSelected($name, $o) . '>' . h($o) . '</option>';
    }
    echo '</select>';
}

/* Ligtas na pag-save ng upload. Hindi pinagkakatiwalaan ang pangalan/extension ng file. */
function saveUpload(string $key, array $allowed, int $maxBytes, string $dir, bool $required): array
{
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
        return $required ? [null, 'Please upload the required file.'] : ['', null];
    }
    $f = $_FILES[$key];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed. Please try again with a smaller file.'];
    }
    if ($f['size'] > $maxBytes) {
        return [null, 'File is too large (max ' . round($maxBytes / 1048576) . ' MB).'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    if (!isset($allowed[$mime])) {
        return [null, 'Invalid file type. Allowed: ' . implode(', ', array_values($allowed)) . '.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return [null, 'Could not save the uploaded file.'];
    }
    return [$name, null];
}

/* Folder ng ID: naka-block sa direktang access (admin lang ang makakakita via view_application.php) */
$uploadDir = __DIR__ . '/uploads/ids';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}
if (!file_exists($uploadDir . '/.htaccess')) {
    @file_put_contents($uploadDir . '/.htaccess', "Require all denied\nDeny from all\n");
}
if (!file_exists($uploadDir . '/index.html')) {
    @file_put_contents($uploadDir . '/index.html', '');
}

if (empty($_SESSION['app_csrf'])) {
    $_SESSION['app_csrf'] = bin2hex(random_bytes(16));
}

/* ---------- Choices ---------- */
$idTypes   = ['PhilSys National ID', "Driver's License", 'Passport', 'UMID', "Voter's ID / Certification",
              'PRC ID', 'Postal ID', 'Senior Citizen ID', 'PWD ID', 'Other government-issued ID'];
$housing   = ['I own my home', 'I am renting', 'I live with family'];
$stayOpts  = ['Inside the house', 'Fenced yard with proper shelter', 'Outdoors, tied or chained', 'Caged or kenneled most of the day'];
$aloneOpts = ['Less than 4 hours', '4 to 8 hours', '8 to 12 hours', 'More than 12 hours'];
$budgetOpts = ['Below PHP 1,000', 'PHP 1,000 to 3,000', 'PHP 3,000 to 5,000', 'Above PHP 5,000'];
$yesNo     = ['Yes', 'No'];

$errors = [];

/* ---------- Submit ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['app_csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    }

    $P = function ($k) { return trim((string)($_POST[$k] ?? '')); };

    // Basic fields
    $birthdate = $P('birthdate');
    $age = null;
    if ($birthdate !== '' && ($ts = strtotime($birthdate))) {
        $age = (int)floor((time() - $ts) / (365.25 * 86400));
    }
    if ($age === null || $age < 18) {
        $errors[] = 'You must be at least 18 years old to adopt.';
    }

    $required = [
        'occupation'  => 'Occupation',
        'housing'     => 'Type of housing',
        'household'   => 'Number of people in the household',
        'all_agree'   => 'Household agreement',
        'allergies'   => 'Allergy question',
        'other_pets'  => 'Other pets question',
        'prev_pets'   => 'Previous pets question',
        'rehomed'     => 'Rehoming history question',
        'cruelty'     => 'Animal cruelty question',
        'vet'         => 'Veterinarian',
        'why'         => 'Reason for adopting',
        'stay'        => 'Where the pet will stay',
        'alone'       => 'Time alone per day',
        'travel'      => 'Care while you are away',
        'budget'      => 'Monthly budget',
        'vaccines'    => 'Vaccination commitment',
        'lifetime'    => 'Lifetime commitment',
        'id_type'     => 'ID type',
        'signature'   => 'Typed signature',
    ];
    foreach ($required as $k => $label) {
        if ($P($k) === '') {
            $errors[] = "$label is required.";
        }
    }

    if (mb_strlen($P('why')) < 30) {
        $errors[] = 'Please explain in at least 30 characters why you want to adopt this pet.';
    }
    if ($P('housing') === 'I am renting' && $P('landlord') === '') {
        $errors[] = 'Please tell us if your landlord allows pets.';
    }
    if ($P('prev_pets') === 'Yes' && mb_strlen($P('prev_pets_detail')) < 10) {
        $errors[] = 'Please describe what happened to your previous pets.';
    }
    if ($P('rehomed') === 'Yes' && mb_strlen($P('rehomed_detail')) < 10) {
        $errors[] = 'Please explain why you gave away, sold, or lost a pet before.';
    }
    if (!in_array($P('id_type'), $idTypes, true)) {
        $errors[] = 'Please choose a valid ID type.';
    }

    // Hard stops
    if ($P('all_agree') === 'No') {
        $errors[] = 'Everyone in your household must agree to the adoption before you can apply.';
    }
    if ($P('cruelty') === 'Yes') {
        $errors[] = 'We cannot process your application because of a reported animal cruelty case.';
    }
    if ($P('vaccines') === 'No') {
        $errors[] = 'Adopters must be willing to complete vaccinations, deworming, and spay/neuter as advised by a veterinarian.';
    }
    if ($P('lifetime') === 'No') {
        $errors[] = 'Adoption is a lifetime commitment. We cannot continue without it.';
    }

    // Required declarations & consents
    $declarations = [
        'd_food'    => 'I will NOT kill, cook, eat, or serve this animal as food.',
        'd_feed'    => 'I will NOT feed this animal to other animals (including snakes, reptiles, or other pets).',
        'd_fight'   => 'I will NOT use this animal for fighting, baiting, experiments, or any form of cruelty.',
        'd_resell'  => 'I will NOT sell, trade, breed for profit, or give away this animal to anyone else.',
        'd_return'  => 'If I can no longer keep this animal, I will return it to Pawfinder and will not abandon or rehome it myself.',
        'd_visit'   => 'I allow Pawfinder to do follow-up checks, including photo/video updates and home visits, after adoption.',
        'c_agree'   => 'I have read and agree to the Adoption Agreement and Liability Waiver below.',
        'c_privacy' => 'I consent to Pawfinder collecting and keeping my ID and personal data for adoption verification (Data Privacy Act of 2012, RA 10173).',
    ];
    foreach ($declarations as $k => $text) {
        if (empty($_POST[$k])) {
            $errors[] = 'You must check: "' . $text . '"';
        }
    }

    // Files
    $idFile = $homeFile = '';
    if (!$errors) {
        [$idFile, $e1] = saveUpload('id_file',
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'],
            5 * 1048576, $uploadDir, true);
        if ($e1) { $errors[] = 'Valid ID: ' . $e1; }

        [$homeFile, $e2] = saveUpload('home_photo',
            ['image/jpeg' => 'jpg', 'image/png' => 'png'],
            5 * 1048576, $uploadDir, false);
        if ($e2) { $errors[] = 'Home photo: ' . $e2; }

        if ($errors) {
            if ($idFile)   { @unlink($uploadDir . '/' . $idFile); }
            if ($homeFile) { @unlink($uploadDir . '/' . $homeFile); }
        }
    }

    // Save
    if (!$errors) {

        // Red flags para sa admin (hindi auto-reject, pero makikita ng admin)
        $flags = [];
        if ($P('housing') === 'I am renting' && $P('landlord') === 'No') { $flags[] = 'Renting and landlord does not allow pets'; }
        if ($P('allergies') === 'Yes')                                   { $flags[] = 'Someone in the household has pet allergies'; }
        if ($P('rehomed') === 'Yes')                                     { $flags[] = 'Has given away, sold, or lost a pet before'; }
        if (in_array($P('stay'), ['Outdoors, tied or chained', 'Caged or kenneled most of the day'], true)) {
            $flags[] = 'Pet would be kept ' . strtolower($P('stay'));
        }
        if ($P('alone') === 'More than 12 hours')                        { $flags[] = 'Pet would be alone more than 12 hours a day'; }
        if ($P('budget') === 'Below PHP 1,000')                          { $flags[] = 'Monthly budget below PHP 1,000'; }
        if (strcasecmp($P('signature'), trim($me['fullname'])) !== 0)    { $flags[] = 'Typed signature does not match account name'; }
        if ($P('vet') !== '' && preg_match('/^(none|wala|n\/a|no)$/i', $P('vet'))) { $flags[] = 'No veterinarian yet'; }

        // Mga sagot (para sa admin view)
        $A = [];
        $add = function ($sec, $q, $a) use (&$A) { $A[] = ['s' => $sec, 'q' => $q, 'a' => $a === '' ? '-' : $a]; };

        $add('Applicant', 'Full name (account)', $me['fullname']);
        $add('Applicant', 'Email (account)', $me['email']);
        $add('Applicant', 'Contact (account)', $me['contact']);
        $add('Applicant', 'Address (account)', $me['address']);
        $add('Applicant', 'Birthdate', $birthdate . ' (age ' . $age . ')');
        $add('Applicant', 'Occupation', $P('occupation'));
        $add('Applicant', 'Facebook / social media link', $P('social'));

        $add('Household', 'Type of housing', $P('housing'));
        $add('Household', 'Landlord allows pets', $P('landlord'));
        $add('Household', 'People in the household', $P('household'));
        $add('Household', 'Ages of children', $P('children'));
        $add('Household', 'All household members agree to adopt', $P('all_agree'));
        $add('Household', 'Anyone with pet allergies', $P('allergies'));
        $add('Household', 'Other pets at home', $P('other_pets'));
        $add('Household', 'Other pets details (vaccinated/neutered?)', $P('other_pets_detail'));

        $add('Experience', 'Has had pets before', $P('prev_pets'));
        $add('Experience', 'What happened to previous pets', $P('prev_pets_detail'));
        $add('Experience', 'Ever gave away, sold, or lost a pet', $P('rehomed'));
        $add('Experience', 'Explanation', $P('rehomed_detail'));
        $add('Experience', 'Ever reported/charged for animal cruelty (self or household)', $P('cruelty'));
        $add('Experience', 'Veterinarian / clinic', $P('vet'));

        $add('Care plan', 'Why adopt this pet', $P('why'));
        $add('Care plan', 'Where the pet will stay', $P('stay'));
        $add('Care plan', 'Hours alone per day', $P('alone'));
        $add('Care plan', 'Who cares for the pet when away', $P('travel'));
        $add('Care plan', 'Monthly budget for the pet', $P('budget'));
        $add('Care plan', 'Will complete vaccines, deworming, spay/neuter', $P('vaccines'));
        $add('Care plan', 'Commits to keep the pet for life', $P('lifetime'));

        foreach ($declarations as $k => $text) {
            $add('Declarations & consent', $text, 'Agreed');
        }
        $add('Verification', 'ID type', $P('id_type'));

        $answersJson = json_encode($A, JSON_UNESCAPED_UNICODE);
        $flagsJson   = json_encode($flags, JSON_UNESCAPED_UNICODE);
        $signature   = $P('signature');
        $ip          = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $idType      = $P('id_type');

        $requestId = 0;
        try {
            $conn->begin_transaction();

            // I-lock ang pet para walang dalawang sabay na mag-apply
            $s = $conn->prepare("SELECT status FROM pets WHERE id = ? FOR UPDATE");
            $s->bind_param("i", $petId);
            $s->execute();
            $cur = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$cur || $cur['status'] !== 'Available') {
                throw new Exception('This pet is no longer available.');
            }

            $ins = $conn->prepare("INSERT INTO adoption_requests (pet_id, user_id) VALUES (?, ?)");
            $ins->bind_param("ii", $petId, $userId);
            $ins->execute();
            $requestId = (int)$ins->insert_id;
            $ins->close();

            $app = $conn->prepare("INSERT INTO adoption_applications
                (request_id, user_id, pet_id, id_type, id_file, home_photo, answers, flags, signature_name, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $app->bind_param("iiisssssss", $requestId, $userId, $petId, $idType, $idFile, $homeFile,
                             $answersJson, $flagsJson, $signature, $ip);
            $app->execute();
            $app->close();

            $upd = $conn->prepare("UPDATE pets SET status = 'Pending' WHERE id = ? AND status = 'Available'");
            $upd->bind_param("i", $petId);
            $upd->execute();
            $upd->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            if ($idFile)   { @unlink($uploadDir . '/' . $idFile); }
            if ($homeFile) { @unlink($uploadDir . '/' . $homeFile); }
            error_log('Application save error: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving your application. Please try again.';
        }

        // Notifications (hindi sisirain ang application kung pumalya)
        if (!$errors && $requestId > 0) {
            try {
                $nTitle = 'New Adoption Application: ' . $pet['pet_name'];
                $nMsg   = $me['fullname'] . ' (' . $me['email'] . ') applied to adopt '
                        . $pet['pet_name'] . ' (' . $pet['pet_species'] . ', ' . $pet['breed'] . ').'
                        . ($flags ? ' ' . count($flags) . ' red flag(s) to review.' : ' No red flags.');
                $nAud   = 'admin';
                $n = $conn->prepare("INSERT INTO notifications (title, message, audience, request_id) VALUES (?, ?, ?, ?)");
                $n->bind_param("sssi", $nTitle, $nMsg, $nAud, $requestId);
                $n->execute();
                $n->close();
            } catch (Throwable $e) {
                error_log('DASHBOARD NOTIF ERROR: ' . $e->getMessage());
            }
            try {
                sendAdoptionNotification($pet, $me['fullname'], $me['email']);
            } catch (Throwable $e) {
                error_log('EMAIL ERROR: ' . $e->getMessage());
            }

            unset($_SESSION['app_csrf']);
            $_SESSION['adopt_msg'] = 'Your adoption application was submitted! Please wait for the admin to review it.';
            header("Location: $back");
            exit();
        }
    }
}

$maxDob = date('Y-m-d', strtotime('-18 years'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pawfinder - Adoption Application for <?php echo h($pet['pet_name']); ?></title>
<style>
    * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; }
    body { background: #f8fafc; color: #0f172a; padding: 40px 20px; }
    .container { max-width: 800px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.08); overflow: hidden; }
    .header-bar { padding: 20px 30px; border-bottom: 1px solid #e2e8f0; }
    .back-btn { text-decoration: none; color: #0d9488; font-weight: 600; font-size: 14px; }
    .body { padding: 30px; }
    h1 { font-size: 26px; margin-bottom: 6px; }
    .sub { color: #64748b; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
    .pet-card { display: flex; gap: 14px; align-items: center; background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: 12px; padding: 12px; margin-bottom: 22px; }
    .pet-card img { width: 70px; height: 70px; object-fit: cover; border-radius: 10px; }
    .errors { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; }
    .errors ul { margin-left: 18px; }
    fieldset { border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin-bottom: 20px; }
    legend { padding: 0 8px; font-weight: 700; color: #0d9488; font-size: 15px; }
    .field { margin-bottom: 14px; }
    .field > label.lbl { display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px; }
    .hint { font-size: 12px; color: #64748b; margin-bottom: 4px; }
    input[type=text], input[type=date], input[type=number], input[type=url], select, textarea, input[type=file] {
        width: 100%; padding: 9px 11px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff;
    }
    textarea { min-height: 90px; resize: vertical; }
    .opt { display: inline-flex; align-items: center; gap: 6px; margin-right: 16px; font-size: 14px; }
    .check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; line-height: 1.45; margin-bottom: 10px; }
    .check input { margin-top: 3px; }
    .agreement { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; max-height: 280px; overflow-y: auto; font-size: 13px; line-height: 1.6; margin-bottom: 14px; }
    .agreement ol { margin-left: 18px; }
    .agreement li { margin-bottom: 6px; }
    .btn-submit { display: block; width: 100%; background: #0d9488; color: #fff; border: 0; border-radius: 10px; padding: 14px; font-size: 16px; font-weight: 700; cursor: pointer; }
    .btn-submit:hover { background: #0b7c72; }
    .note { font-size: 12px; color: #64748b; margin-top: 10px; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header-bar"><a class="back-btn" href="<?php echo h($back); ?>">&larr; Back to <?php echo h($pet['pet_name']); ?></a></div>

    <div class="body">
        <h1>Adoption Application</h1>
        <p class="sub">Para sa kaligtasan ng bawat alaga, kailangan naming masiguro na ligtas at responsable ang magiging tahanan nila. Sagutin nang tapat ang lahat ng tanong. Rerepasuhin ito ng admin bago aprubahan.</p>

        <div class="pet-card">
            <img src="<?php echo h($pet['pet_photo']); ?>" alt="">
            <div>
                <b><?php echo h($pet['pet_name']); ?></b><br>
                <span style="font-size:13px;color:#64748b;"><?php echo h($pet['pet_species'] . ' &middot; ' . $pet['breed']); ?></span>
            </div>
        </div>

        <?php if ($errors): ?>
        <div class="errors" role="alert">
            <b>Please fix the following:</b>
            <ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['app_csrf']); ?>">
            <input type="hidden" name="pet_id" value="<?php echo (int)$petId; ?>">

            <!-- 1. APPLICANT -->
            <fieldset>
                <legend>1. About you</legend>
                <div class="field">
                    <label class="lbl">Full name</label>
                    <input type="text" value="<?php echo h($me['fullname']); ?>" disabled>
                    <div class="hint">Galing sa account mo. Dapat tugma ito sa pangalan sa ID.</div>
                </div>
                <div class="field">
                    <label class="lbl">Birthdate (must be 18 or older)</label>
                    <input type="date" name="birthdate" max="<?php echo $maxDob; ?>" value="<?php echo pv('birthdate'); ?>" required>
                </div>
                <div class="field">
                    <label class="lbl">Occupation / source of income</label>
                    <input type="text" name="occupation" maxlength="100" value="<?php echo pv('occupation'); ?>" required>
                </div>
                <div class="field">
                    <label class="lbl">Facebook / social media profile link (optional)</label>
                    <div class="hint">Tumutulong ito sa pag-verify na totoong tao ka.</div>
                    <input type="url" name="social" maxlength="200" placeholder="https://facebook.com/..." value="<?php echo pv('social'); ?>">
                </div>
            </fieldset>

            <!-- 2. HOUSEHOLD -->
            <fieldset>
                <legend>2. Home and household</legend>
                <div class="field">
                    <label class="lbl">Type of housing</label>
                    <?php radios('housing', $housing); ?>
                </div>
                <div class="field">
                    <label class="lbl">If renting: does your landlord allow pets?</label>
                    <?php foreach (['Yes', 'No'] as $o) echo '<label class="opt"><input type="radio" name="landlord" value="' . $o . '" ' . isChecked('landlord', $o) . '> ' . $o . '</label>'; ?>
                </div>
                <div class="field">
                    <label class="lbl">How many people live in your home?</label>
                    <input type="number" name="household" min="1" max="30" value="<?php echo pv('household'); ?>" required>
                </div>
                <div class="field">
                    <label class="lbl">Ages of children at home (leave blank if none)</label>
                    <input type="text" name="children" maxlength="100" value="<?php echo pv('children'); ?>">
                </div>
                <div class="field">
                    <label class="lbl">Does everyone in your household agree to this adoption?</label>
                    <?php radios('all_agree', $yesNo); ?>
                </div>
                <div class="field">
                    <label class="lbl">Does anyone at home have pet allergies?</label>
                    <?php radios('allergies', $yesNo); ?>
                </div>
                <div class="field">
                    <label class="lbl">Do you have other pets at home?</label>
                    <?php radios('other_pets', $yesNo); ?>
                    <div class="hint" style="margin-top:6px;">If yes: what kind, how many, and are they vaccinated and neutered/spayed?</div>
                    <input type="text" name="other_pets_detail" maxlength="250" value="<?php echo pv('other_pets_detail'); ?>">
                </div>
                <div class="field">
                    <label class="lbl">Photo of the place where the pet will stay (optional, JPG/PNG, max 5 MB)</label>
                    <input type="file" name="home_photo" accept="image/jpeg,image/png">
                </div>
            </fieldset>

            <!-- 3. EXPERIENCE -->
            <fieldset>
                <legend>3. Pet history</legend>
                <div class="field">
                    <label class="lbl">Have you owned pets before?</label>
                    <?php radios('prev_pets', $yesNo); ?>
                    <div class="hint" style="margin-top:6px;">If yes: what happened to them? (still with you, passed away naturally, etc.)</div>
                    <textarea name="prev_pets_detail" maxlength="500"><?php echo pv('prev_pets_detail'); ?></textarea>
                </div>
                <div class="field">
                    <label class="lbl">Have you ever given away, sold, abandoned, or lost a pet?</label>
                    <?php radios('rehomed', $yesNo); ?>
                    <div class="hint" style="margin-top:6px;">If yes: please explain.</div>
                    <textarea name="rehomed_detail" maxlength="500"><?php echo pv('rehomed_detail'); ?></textarea>
                </div>
                <div class="field">
                    <label class="lbl">Have you, or anyone in your household, ever been reported or charged for animal cruelty or neglect?</label>
                    <?php radios('cruelty', $yesNo); ?>
                </div>
                <div class="field">
                    <label class="lbl">Veterinarian or clinic you will use</label>
                    <div class="hint">Isulat ang pangalan ng clinic. Kung wala pa, isulat ang "None yet" at saang clinic ka magpapa-check up.</div>
                    <input type="text" name="vet" maxlength="150" value="<?php echo pv('vet'); ?>" required>
                </div>
            </fieldset>

            <!-- 4. CARE PLAN -->
            <fieldset>
                <legend>4. Care plan</legend>
                <div class="field">
                    <label class="lbl">Why do you want to adopt <?php echo h($pet['pet_name']); ?>?</label>
                    <textarea name="why" maxlength="800" required><?php echo pv('why'); ?></textarea>
                </div>
                <div class="field">
                    <label class="lbl">Where will the pet stay most of the time?</label>
                    <?php selectBox('stay', $stayOpts); ?>
                </div>
                <div class="field">
                    <label class="lbl">How many hours a day will the pet be left alone?</label>
                    <?php selectBox('alone', $aloneOpts); ?>
                </div>
                <div class="field">
                    <label class="lbl">Who will take care of the pet when you travel or get sick?</label>
                    <input type="text" name="travel" maxlength="200" value="<?php echo pv('travel'); ?>" required>
                </div>
                <div class="field">
                    <label class="lbl">Monthly budget for food, vet care, and supplies</label>
                    <?php selectBox('budget', $budgetOpts); ?>
                </div>
                <div class="field">
                    <label class="lbl">Will you complete anti-rabies and other vaccinations, deworming, and spay/neuter as advised by a veterinarian?</label>
                    <?php radios('vaccines', $yesNo); ?>
                </div>
                <div class="field">
                    <label class="lbl">Do you commit to keep this pet for its whole life, even if you move or your situation changes?</label>
                    <?php radios('lifetime', $yesNo); ?>
                </div>
            </fieldset>

            <!-- 5. DECLARATIONS -->
            <fieldset>
                <legend>5. Declarations (all required)</legend>
                <?php
                $decl = [
                    'd_food'   => 'I will <b>NOT</b> kill, cook, eat, or serve this animal as food.',
                    'd_feed'   => 'I will <b>NOT</b> feed this animal to other animals (including snakes, reptiles, or other pets).',
                    'd_fight'  => 'I will <b>NOT</b> use this animal for fighting, baiting, experiments, or any form of cruelty.',
                    'd_resell' => 'I will <b>NOT</b> sell, trade, breed for profit, or give away this animal to anyone else.',
                    'd_return' => 'If I can no longer keep this animal, I will <b>return it to Pawfinder</b> and will not abandon or rehome it myself.',
                    'd_visit'  => 'I allow Pawfinder to do <b>follow-up checks</b>, including photo/video updates and home visits, after adoption.',
                ];
                foreach ($decl as $k => $t) {
                    echo '<label class="check"><input type="checkbox" name="' . $k . '" value="1" ' . (!empty($_POST[$k]) ? 'checked' : '') . ' required><span>' . $t . '</span></label>';
                }
                ?>
            </fieldset>

            <!-- 6. ID -->
            <fieldset>
                <legend>6. Valid ID (required)</legend>
                <div class="field">
                    <label class="lbl">Type of ID</label>
                    <?php selectBox('id_type', $idTypes); ?>
                </div>
                <div class="field">
                    <label class="lbl">Upload a clear photo or scan of your ID (JPG, PNG, or PDF, max 5 MB)</label>
                    <div class="hint">Dapat malinaw at nababasa ang pangalan. Admin lang ang makakakita nito at hindi ito ipapakita sa publiko.</div>
                    <input type="file" name="id_file" accept="image/jpeg,image/png,application/pdf" required>
                </div>
            </fieldset>

            <!-- 7. AGREEMENT -->
            <fieldset>
                <legend>7. Adoption Agreement and Liability Waiver</legend>
                <div class="agreement">
                    <p><b>By signing, I, the adopter, agree to the following:</b></p>
                    <ol>
                        <li>All my answers and my uploaded ID are true and are mine. Giving false information is grounds for cancelling the adoption.</li>
                        <li>I will provide proper food, clean water, shelter, vaccinations, veterinary care, and humane treatment for the animal for its entire life.</li>
                        <li>I will not harm, neglect, abandon, kill, sell, trade, fight, or feed the animal to other animals, and I will not allow anyone in my household or care to do so.</li>
                        <li>If the animal is <b>intentionally harmed, killed, starved, sold, or abandoned</b> by me, a member of my household, or anyone I allowed access to it, I accept full responsibility. I understand that Pawfinder may take back the animal and any other animal in my care that was adopted from Pawfinder, may refuse any future adoption from me, and may report the incident, together with my submitted ID and information, to the barangay, the police, and the proper government authorities.</li>
                        <li>I understand that Pawfinder and its volunteers may file a complaint under applicable laws, including the Animal Welfare Act (RA 8485, as amended by RA 10631). I will be liable for any penalties and damages the law may impose.</li>
                        <li>I will comply with the Anti-Rabies Act (RA 9482), including vaccination and registration of the animal.</li>
                        <li>I will allow follow-up checks, including photo/video updates and home visits, and I will answer Pawfinder's messages about the animal's welfare.</li>
                        <li>If I can no longer keep the animal, I will return it to Pawfinder and will not abandon, sell, or give it away.</li>
                        <li>I understand that the adoption is not final until the admin approves my application, and that Pawfinder may reject or cancel an application at its discretion to protect the animal.</li>
                    </ol>
                </div>
                <label class="check"><input type="checkbox" name="c_agree" value="1" <?php echo !empty($_POST['c_agree']) ? 'checked' : ''; ?> required>
                    <span>I have read and agree to the Adoption Agreement and Liability Waiver above.</span></label>
                <label class="check"><input type="checkbox" name="c_privacy" value="1" <?php echo !empty($_POST['c_privacy']) ? 'checked' : ''; ?> required>
                    <span>I consent to Pawfinder collecting and keeping my ID and personal data for adoption verification, as allowed by the Data Privacy Act of 2012 (RA 10173).</span></label>
                <div class="field" style="margin-top:12px;">
                    <label class="lbl">Type your full name as your signature</label>
                    <input type="text" name="signature" maxlength="150" value="<?php echo pv('signature'); ?>" required>
                    <div class="hint">Date and time of signing will be recorded automatically: <?php echo date('M d, Y'); ?></div>
                </div>
            </fieldset>

            <button type="submit" class="btn-submit">Submit Application</button>
            <p class="note">Hindi pa final ang adoption hanggang hindi ina-approve ng admin.</p>
        </form>
    </div>
</div>
</body>
</html>