<?php
session_start();

// "Today" is based on Philippine time
date_default_timezone_set('Asia/Manila');

// Works out the real status from the event date (cancelled stays cancelled)
function driveStatus($eventDate, $dbStatus) {
    if (strtolower(trim($dbStatus ?? '')) === 'cancelled') {
        return ['key' => 'cancelled', 'label' => 'Cancelled', 'rank' => 3];
    }
    $ts = strtotime($eventDate ?? '');
    if ($ts === false) {
        $key = strtolower(trim($dbStatus ?? 'upcoming'));
        return ['key' => $key, 'label' => ucfirst($key), 'rank' => 1];
    }
    $day   = date('Y-m-d', $ts);
    $today = date('Y-m-d');
    if ($day === $today) return ['key' => 'today',     'label' => 'Today',     'rank' => 0];
    if ($day >  $today) return ['key' => 'upcoming',   'label' => 'Upcoming',  'rank' => 1];
    return                      ['key' => 'completed',  'label' => 'Completed', 'rank' => 2];
}

$conn = new mysqli("localhost", "root", "", "pawfinder_db");

if ($conn->connect_error) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, "UTF-8");
}

// Table that stores who registered for which health drive
$conn->query("CREATE TABLE IF NOT EXISTS health_drive_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    drive_id INT NOT NULL,
    user_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL DEFAULT '',
    email VARCHAR(150) NOT NULL DEFAULT '',
    phone VARCHAR(30) NOT NULL,
    pet_name VARCHAR(100) NOT NULL,
    pet_type VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_drive_user (drive_id, user_id)
)");

if (empty($_SESSION['hd_csrf'])) {
    $_SESSION['hd_csrf'] = bin2hex(random_bytes(16));
}

// Logged-in adopter
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

// One-time message shown after registering or donating
$flash = $_SESSION['hd_flash'] ?? null;
unset($_SESSION['hd_flash']);

// HANDLE REGISTRATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_drive') {
    $flashMsg = ['type' => 'err', 'text' => 'Something went wrong. Please try again.'];

    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['hd_csrf'], $token)) {
        $flashMsg['text'] = 'Your session expired. Please refresh the page and try again.';
    } elseif ($userId <= 0) {
        $flashMsg['text'] = 'Please log in first to register.';
    } else {
        $driveId = (int)($_POST['drive_id'] ?? 0);
        $phone   = trim($_POST['phone'] ?? '');
        $petName = trim($_POST['pet_name'] ?? '');
        $petType = $_POST['pet_type'] ?? '';

        if ($petName === '' || mb_strlen($petName) > 100) {
            $flashMsg['text'] = 'Please enter your pet\'s name.';
        } elseif (!in_array($petType, ['Dog', 'Cat', 'Other'], true)) {
            $flashMsg['text'] = 'Please choose your pet type.';
        } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $flashMsg['text'] = 'Please enter a valid contact number.';
        } else {
            try {
                $s = $conn->prepare("SELECT name, event_date, status FROM health_drives WHERE id = ?");
                $s->bind_param("i", $driveId);
                $s->execute();
                $drive = $s->get_result()->fetch_assoc();
                $s->close();

                if (!$drive) {
                    $flashMsg['text'] = 'That health drive no longer exists.';
                } elseif (!in_array(driveStatus($drive['event_date'], $drive['status'])['key'], ['upcoming', 'today'], true)) {
                    $flashMsg['text'] = 'Registration for this health drive is closed.';
                } else {
                    $name = '';
                    $email = '';
                    $u = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $u->bind_param("i", $userId);
                    $u->execute();
                    $user = $u->get_result()->fetch_assoc();
                    $u->close();
                    if ($user) {
                        $name  = $user['full_name'] ?? $user['fullname'] ?? $user['name'] ?? $user['username'] ?? '';
                        $email = $user['email'] ?? '';
                    }

                    $i = $conn->prepare("INSERT INTO health_drive_registrations
                        (drive_id, user_id, full_name, email, phone, pet_name, pet_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $i->bind_param("iisssss", $driveId, $userId, $name, $email, $phone, $petName, $petType);
                    $i->execute();
                    $i->close();

                    try {
                        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            title VARCHAR(150) NOT NULL,
                            message TEXT NOT NULL,
                            audience VARCHAR(20) NOT NULL DEFAULT 'all',
                            request_id INT NULL DEFAULT NULL,
                            type VARCHAR(50) NULL DEFAULT NULL,
                            reference_id INT NULL DEFAULT NULL,
                            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        )");

                        $nTitle = 'New health drive registration';
                        $nMsg   = ($name !== '' ? $name : 'An adopter')
                                . ' registered for "' . $drive['name'] . '" ('
                                . date('M d, Y', strtotime($drive['event_date'])) . ').'
                                . "\nPet: " . $petName . ' (' . $petType . ')'
                                . "\nContact: " . $phone
                                . ($email !== '' ? "\nEmail: " . $email : '');

                        $n = $conn->prepare("INSERT INTO notifications (title, message, audience, type, reference_id)
                                             VALUES (?, ?, ?, 'health_drive', ?)");
                        foreach (['admin', 'rescuer'] as $aud) {
                            $n->bind_param("sssi", $nTitle, $nMsg, $aud, $driveId);
                            $n->execute();
                        }
                        $n->close();
                    } catch (Throwable $ne) {
                        error_log('health drive notif: ' . $ne->getMessage());
                    }

                    $flashMsg = ['type' => 'ok', 'text' => 'You are registered for "' . $drive['name'] . '". See you there!'];
                }
            } catch (Throwable $ex) {
                if ((int)$ex->getCode() === 1062) {
                    $flashMsg['text'] = 'You are already registered for this health drive.';
                } else {
                    error_log('health drive register: ' . $ex->getMessage());
                }
            }
        }
    }

    $_SESSION['hd_flash'] = $flashMsg;
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '#drives');
    exit;
}

// Table that stores donation records
$conn->query("CREATE TABLE IF NOT EXISTS donations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL DEFAULT 0,
    donor_name VARCHAR(150) NOT NULL DEFAULT '',
    phone VARCHAR(30) NOT NULL DEFAULT '',
    donation_type VARCHAR(30) NOT NULL,
    details VARCHAR(255) NOT NULL DEFAULT '',
    message VARCHAR(500) NOT NULL DEFAULT '',
    is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// HANDLE DONATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_donation') {
    $flashMsg = ['type' => 'err', 'text' => 'Something went wrong. Please try again.'];

    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['hd_csrf'], $token)) {
        $flashMsg['text'] = 'Your session expired. Please refresh the page and try again.';
    } elseif ($userId <= 0) {
        $flashMsg['text'] = 'Please log in first to donate.';
    } else {
        $allowedTypes = ['Money (GCash)', 'Food', 'Supplies', 'Clothes', 'Medicine'];
        $dType     = $_POST['donation_type'] ?? '';
        $details   = trim($_POST['details'] ?? '');
        $leaveInfo = !empty($_POST['leave_info']);

        $donorName = '';
        $dPhone    = '';
        $dMessage  = '';
        $donorId   = 0;
        if ($leaveInfo) {
            $donorName = trim($_POST['donor_name'] ?? '');
            $dPhone    = trim($_POST['donor_phone'] ?? '');
            $dMessage  = trim($_POST['donor_message'] ?? '');
            $donorId   = $userId;
        }

        if (!in_array($dType, $allowedTypes, true)) {
            $flashMsg['text'] = 'Please choose what you want to donate.';
        } elseif (mb_strlen($details) > 255) {
            $flashMsg['text'] = 'Donation details are too long.';
        } elseif (mb_strlen($donorName) > 150) {
            $flashMsg['text'] = 'Your name is too long.';
        } elseif ($dPhone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $dPhone)) {
            $flashMsg['text'] = 'Please enter a valid contact number.';
        } elseif (mb_strlen($dMessage) > 500) {
            $flashMsg['text'] = 'Your message is too long.';
        } else {
            try {
                $isAnon = ($donorName === '' && $dPhone === '') ? 1 : 0;

                $d = $conn->prepare("INSERT INTO donations
                    (user_id, donor_name, phone, donation_type, details, message, is_anonymous)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $d->bind_param("isssssi", $donorId, $donorName, $dPhone, $dType, $details, $dMessage, $isAnon);
                $d->execute();
                $donationId = (int)$conn->insert_id;
                $d->close();

                try {
                    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        title VARCHAR(150) NOT NULL,
                        message TEXT NOT NULL,
                        audience VARCHAR(20) NOT NULL DEFAULT 'all',
                        request_id INT NULL DEFAULT NULL,
                        type VARCHAR(50) NULL DEFAULT NULL,
                        reference_id INT NULL DEFAULT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )");

                    $nTitle = 'New donation';
                    $nMsg   = ($donorName !== '' ? $donorName : 'An anonymous donor')
                            . ' wants to donate: ' . $dType . '.'
                            . ($details !== ''  ? "\nDetails: " . $details : '')
                            . ($dPhone !== ''   ? "\nContact: " . $dPhone : '')
                            . ($dMessage !== '' ? "\nMessage: " . $dMessage : '');

                    $n = $conn->prepare("INSERT INTO notifications (title, message, audience, type, reference_id)
                                         VALUES (?, ?, ?, 'donation', ?)");
                    foreach (['admin', 'rescuer'] as $aud) {
                        $n->bind_param("sssi", $nTitle, $nMsg, $aud, $donationId);
                        $n->execute();
                    }
                    $n->close();
                } catch (Throwable $ne) {
                    error_log('donation notif: ' . $ne->getMessage());
                }

                $flashMsg = ['type' => 'ok', 'text' => 'Thank you for your generosity! Your donation was recorded.'];
            } catch (Throwable $ex) {
                error_log('donation save: ' . $ex->getMessage());
            }
        }
    }

    $_SESSION['hd_flash'] = $flashMsg;
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '#drives');
    exit;
}

// GET HEALTH DRIVE EVENTS FROM DATABASE
$sql = "SELECT
            id,
            name,
            event_date AS date,
            event_time AS time,
            location,
            description,
            image,
            status
        FROM health_drives
        ORDER BY event_date ASC";

$result = $conn->query($sql);

$healthDrives = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $st = driveStatus($row['date'], $row['status']);
        $row['status']     = $st['label'];
        $row['status_key'] = $st['key'];
        $row['rank']       = $st['rank'];
        $healthDrives[] = $row;
    }
}

usort($healthDrives, function ($a, $b) {
    if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
    return strcmp($a['date'], $b['date']);
});

$regCounts = [];
$rc = $conn->query("SELECT drive_id, COUNT(*) AS c FROM health_drive_registrations GROUP BY drive_id");
if ($rc) {
    while ($row = $rc->fetch_assoc()) {
        $regCounts[(int)$row['drive_id']] = (int)$row['c'];
    }
}

$myRegs = [];
if ($userId > 0) {
    $m = $conn->prepare("SELECT drive_id FROM health_drive_registrations WHERE user_id = ?");
    $m->bind_param("i", $userId);
    $m->execute();
    $mr = $m->get_result();
    while ($row = $mr->fetch_assoc()) {
        $myRegs[(int)$row['drive_id']] = true;
    }
    $m->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PAWFINDER - Health Drives</title>

    <link rel="stylesheet" href="css/homepage.css">

<style>
    .health-container {
        max-width: 1200px;
        margin: 35px auto;
        padding: 0 20px;
    }

    .health-header {
        background: #1e293b;
        color: white;
        padding: 35px;
        text-align: center;
        border-radius: 12px;
        margin-bottom: 35px;
    }

    .health-header h1 {
        margin: 0 0 12px;
        font-size: 30px;
    }

    .health-header p {
        color: #cbd5e1;
        margin: 0;
    }

    .health-section-title {
        color: #0f172a;
        margin-bottom: 20px;
    }

    .health-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }

    .health-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 3px 8px rgba(0,0,0,0.06);
        transition: 0.2s;
        display: flex;
        flex-direction: column;
    }

    .health-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.10);
    }

    .health-card img {
        width: 100%;
        height: 190px;
        object-fit: cover;
        background: #f1f5f9;
    }

    .health-card-content {
        padding: 20px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .health-card h3 {
        color: #0f172a;
        font-size: 18px;
        margin: 0 0 12px;
    }

    .health-card p {
        color: #475569;
        font-size: 14px;
        line-height: 1.6;
        margin: 8px 0;
    }

    .health-status {
        display: inline-block;
        align-self: flex-start;
        padding: 5px 10px;
        border-radius: 20px;
        background: #ccfbf1;
        color: #0f766e;
        font-size: 12px;
        font-weight: bold;
        margin-bottom: 12px;
    }

    .health-status.today {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .health-status.completed {
        background: #e2e8f0;
        color: #475569;
    }

    .health-status.cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .hd-actions {
        margin-top: auto;
        padding-top: 14px;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .hd-btn {
        display: inline-block;
        background: #0d9488;
        color: #ffffff;
        border: 0;
        border-radius: 8px;
        padding: 10px 18px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.2s;
    }

    .hd-btn:hover {
        background: #0f766e;
    }

    .hd-btn.done {
        background: #dcfce7;
        color: #166534;
        cursor: default;
    }

    .hd-btn.closed {
        background: #e2e8f0;
        color: #64748b;
        cursor: not-allowed;
    }

    .hd-count {
        font-size: 13px;
        color: #64748b;
    }

    .hd-flash {
        padding: 14px 18px;
        border-radius: 10px;
        margin-bottom: 22px;
        font-size: 14px;
        font-weight: 600;
    }

    .hd-flash.ok {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #86efac;
    }

    .hd-flash.err {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }

    .hd-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .hd-modal.open {
        display: flex;
    }

    .hd-box {
        background: #ffffff;
        color: #0f172a;
        width: 100%;
        max-width: 430px;
        border-radius: 14px;
        padding: 26px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.3);
    }

    .hd-box h3 {
        margin: 0 0 4px;
        font-size: 20px;
        color: #0f172a;
    }

    .hd-box .hd-sub {
        margin: 0 0 18px;
        font-size: 13px;
        color: #64748b;
    }

    .hd-box label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 14px;
    }

    .hd-box input,
    .hd-box select {
        display: block;
        width: 100%;
        box-sizing: border-box;
        margin-top: 6px;
        padding: 10px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
        background: #ffffff;
        color: #0f172a;
    }

    .hd-row {
        display: flex;
        gap: 10px;
        margin-top: 6px;
    }

    .hd-row .hd-btn {
        flex: 1;
        text-align: center;
    }

    .hd-btn.cancel {
        background: #e2e8f0;
        color: #334155;
    }

    .hd-btn.cancel:hover {
        background: #cbd5e1;
    }

    .hd-btn.donate {
        background: #f59e0b;
        color: #ffffff;
    }

    .hd-btn.donate:hover {
        background: #d97706;
    }

    .health-header .hd-btn.donate {
        margin-top: 18px;
        padding: 12px 26px;
        font-size: 15px;
    }

    .hd-donate-info {
        background: #f1f5f9;
        border-radius: 10px;
        padding: 14px 16px;
        margin-bottom: 16px;
        font-size: 14px;
        color: #334155;
        line-height: 1.7;
    }

    .hd-donate-info strong {
        color: #0f172a;
    }

    .hd-donate-list {
        margin: 8px 0 10px;
        padding-left: 20px;
    }

    #donateModal .hd-box {
        max-height: 90vh;
        overflow-y: auto;
    }

    .hd-form-title {
        display: block;
        font-size: 15px;
        color: #0f172a;
        margin-bottom: 2px;
    }

    .hd-form-note {
        margin: 0 0 14px;
        font-size: 12px;
        color: #64748b;
    }

    .hd-box textarea {
        display: block;
        width: 100%;
        box-sizing: border-box;
        margin-top: 6px;
        padding: 10px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        background: #ffffff;
        color: #0f172a;
        resize: vertical;
    }

    .hd-box label.hd-check {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        cursor: pointer;
    }

    .hd-box .hd-check input {
        display: inline-block;
        width: auto;
        margin: 0;
    }

    .hd-donor-fields {
        display: none;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 14px 14px 2px;
        margin-bottom: 14px;
    }

    .schedule-section {
        margin-top: 45px;
    }

    .schedule-wrap {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow-x: auto;
        box-shadow: 0 3px 8px rgba(0,0,0,0.06);
    }

    .schedule-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 640px;
    }

    .schedule-table th {
        background: #1e293b;
        color: #ffffff;
        text-align: left;
        font-size: 13px;
        padding: 14px 18px;
    }

    .schedule-table td {
        padding: 14px 18px;
        font-size: 14px;
        color: #334155;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
    }

    .schedule-table tr:last-child td {
        border-bottom: none;
    }

    .schedule-table .svc-name {
        font-weight: 700;
        color: #0f172a;
    }

    .schedule-table .svc-desc {
        color: #64748b;
        font-size: 13px;
        margin-top: 2px;
    }

    .sched-status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .sched-status.upcoming {
        background: #ccfbf1;
        color: #0f766e;
    }

    .sched-status.ongoing {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .sched-status.completed {
        background: #e2e8f0;
        color: #475569;
    }

    .sched-status.cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .health-empty {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        color: #64748b;
    }

    @media (max-width: 900px) {
        .health-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 600px) {
        .health-grid {
            grid-template-columns: 1fr;
        }
        .health-header {
            padding: 25px 15px;
        }
        .health-header h1 {
            font-size: 24px;
        }
    }
</style>

    <link rel="stylesheet" href="css/theme.css">
</head>

<body>
    <script src="js/darkmode.js"></script>

    <?php include 'header.php'; ?>

    <main class="health-container">

        <section class="health-header">
            <h1>Community Pet Health Drives</h1>
            <p>
                Providing accessible veterinary care and healthier lives
                for every pet in our community.
            </p>

            <?php if ($userId > 0): ?>
                <button type="button" class="hd-btn donate" onclick="openDonate()">
                    Donate
                </button>
            <?php else: ?>
                <a href="login.php?redirect=<?= urlencode('health_drives.php?open_donate=1') ?>" class="hd-btn donate">
                    Donate
                </a>
            <?php endif; ?>
        </section>

        <section id="drives">
            <h2 class="health-section-title">
                Upcoming Health Drives
            </h2>

            <?php if ($flash): ?>
                <div class="hd-flash <?php echo $flash['type'] === 'ok' ? 'ok' : 'err'; ?>">
                    <?php echo e($flash['text']); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($healthDrives)): ?>
                <div class="health-empty">
                    No health drives are scheduled right now.
                    Please check back soon.
                </div>
            <?php else: ?>
                <div class="health-grid">
                    <?php foreach ($healthDrives as $drive):
                        $driveId    = (int)$drive['id'];
                        $isUpcoming = in_array($drive['status_key'], ['upcoming', 'today'], true);
                        $registered = isset($myRegs[$driveId]);
                        $count      = $regCounts[$driveId] ?? 0;
                    ?>
                        <div class="health-card">
                            <img
                                src="<?php echo e($drive['image']); ?>"
                                alt="<?php echo e($drive['name']); ?>"
                                onerror="this.onerror=null;this.src='https://placehold.co/600x400/e2e8f0/0f766e?text=Pet+Health+Drive'"
                            >

                            <div class="health-card-content">
                                <span class="health-status <?php echo e($drive['status_key']); ?>">
                                    <?php echo e($drive['status']); ?>
                                </span>

                                <h3>
                                    <?php echo e($drive['name']); ?>
                                </h3>

                                <p>
                                    <strong>Date:</strong>
                                    <?php echo e($drive['date']); ?>
                                </p>

                                <p>
                                    <strong>Time:</strong>
                                    <?php echo e($drive['time']); ?>
                                </p>

                                <p>
                                    <strong>Location:</strong>
                                    <?php echo e($drive['location']); ?>
                                </p>

                                <p>
                                    <?php echo e($drive['description']); ?>
                                </p>

                                <div class="hd-actions">
                                    <?php if (!$isUpcoming): ?>
                                        <span class="hd-btn closed">Registration closed</span>
                                    <?php elseif ($userId <= 0): ?>
                                        <a class="hd-btn" href="login.php">Log in to register</a>
                                    <?php elseif ($registered): ?>
                                        <span class="hd-btn done">&#10003; Registered</span>
                                    <?php else: ?>
                                        <button
                                            type="button"
                                            class="hd-btn"
                                            data-id="<?php echo $driveId; ?>"
                                            data-name="<?php echo e($drive['name']); ?>"
                                            onclick="openRegister(this)"
                                        >Register</button>
                                    <?php endif; ?>

                                    <?php if ($userId > 0): ?>
                                        <button type="button" class="hd-btn donate" onclick="openDonate()">Donate</button>
                                    <?php else: ?>
                                        <a href="login.php?redirect=<?= urlencode('health_drives.php?open_donate=1') ?>" class="hd-btn donate">Donate</a>
                                    <?php endif; ?>

                                    <?php if ($count > 0): ?>
                                        <span class="hd-count"><?php echo $count; ?> registered</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="schedule-section" id="schedule">
            <h2 class="health-section-title">
                Free Medicine &amp; Vaccination Schedule
            </h2>

            <?php if (empty($healthDrives)): ?>
                <div class="health-empty">
                    No schedule available yet.
                </div>
            <?php else: ?>
                <div class="schedule-wrap">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Location / Barangay</th>
                                <th>Services Offered</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($healthDrives as $drive): ?>
                                <tr>
                                    <td>
                                        <?php echo e(date('M d, Y', strtotime($drive['date']))); ?>
                                        <br>
                                        <?php echo e($drive['time']); ?>
                                    </td>
                                    <td>
                                        <?php echo e($drive['location']); ?>
                                    </td>
                                    <td>
                                        <div class="svc-name">
                                            <?php echo e($drive['name']); ?>
                                        </div>
                                        <div class="svc-desc">
                                            <?php echo e($drive['description']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="sched-status <?php echo e($drive['status_key']); ?>">
                                            <?php echo e($drive['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- REGISTRATION POP-UP -->
    <div class="hd-modal" id="hdModal">
        <div class="hd-box">
            <h3>Register for health drive</h3>
            <p class="hd-sub" id="hdDriveName"></p>

            <form method="post">
                <input type="hidden" name="action" value="register_drive">
                <input type="hidden" name="csrf" value="<?php echo e($_SESSION['hd_csrf']); ?>">
                <input type="hidden" name="drive_id" id="hdDriveId" value="">

                <label>Pet's name
                    <input type="text" name="pet_name" maxlength="100" required>
                </label>

                <label>Pet type
                    <select name="pet_type" required>
                        <option value="" disabled selected>Choose pet type...</option>
                        <option value="Dog">Dog</option>
                        <option value="Cat">Cat</option>
                        <option value="Other">Other</option>
                    </select>
                </label>

                <label>Contact number
                    <input type="tel" name="phone" maxlength="20" placeholder="09XX XXX XXXX" required>
                </label>

                <div class="hd-row">
                    <button type="submit" class="hd-btn">Submit Registration</button>
                    <button type="button" class="hd-btn cancel" onclick="closeRegister()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DONATE POP-UP -->
    <div class="hd-modal" id="donateModal">
        <div class="hd-box">
            <h3>Support Our Rescued Pets</h3>
            <p class="hd-sub">Your contributions fund medical treatments, vaccines, and shelter operations.</p>

            <div class="hd-donate-info">
                <strong>How to send donations:</strong>
                <ul class="hd-donate-list">
                    <li><strong>GCash / Maya:</strong> 0917-123-4567 (Pawfinder Community)</li>
                    <li><strong>Bank Transfer:</strong> BPI Account - 1234-5678-90</li>
                </ul>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="submit_donation">
                <input type="hidden" name="csrf" value="<?php echo e($_SESSION['hd_csrf']); ?>">

                <label>
                    <span class="hd-form-title">What are you donating? *</span>
                    <select name="donation_type" required>
                        <option value="" disabled selected>Select donation type...</option>
                        <option value="Money (GCash)">Money (GCash / Maya / Bank)</option>
                        <option value="Food">Pet Food (Dog/Cat food, treats)</option>
                        <option value="Supplies">Shelter Supplies (Cages, leashes, bowls)</option>
                        <option value="Clothes">Pet Clothes or Blankets</option>
                        <option value="Medicine">Vitamins or Medicines</option>
                    </select>
                </label>

                <label>
                    <span class="hd-form-title">Donation Details / Reference Number *</span>
                    <span class="hd-form-note">If money, enter GCash/Bank Ref No. If items, describe quantity/brand.</span>
                    <input type="text" name="details" maxlength="255" placeholder="e.g., Ref: 123456789 or 2 bags of Pedigree" required>
                </label>

                <label class="hd-check">
                    <input type="checkbox" name="leave_info" id="leaveInfoCheck" value="1" onchange="toggleDonorFields(this)">
                    Leave my contact info &amp; message (Optional)
                </label>

                <div class="hd-donor-fields" id="donorFields">
                    <label>Your Name
                        <input type="text" name="donor_name" maxlength="150" placeholder="e.g., Juan Dela Cruz">
                    </label>

                    <label>Contact Number
                        <input type="tel" name="donor_phone" maxlength="20" placeholder="09XX XXX XXXX">
                    </label>

                    <label>Message for Rescuers
                        <textarea name="donor_message" rows="2" maxlength="500" placeholder="Any message or instructions..."></textarea>
                    </label>
                </div>

                <div class="hd-row" style="margin-top: 18px;">
                    <button type="submit" class="hd-btn donate">Submit Donation</button>
                    <button type="button" class="hd-btn cancel" onclick="closeDonate()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRegister(btn) {
            document.getElementById('hdDriveId').value = btn.getAttribute('data-id');
            document.getElementById('hdDriveName').textContent = btn.getAttribute('data-name');
            document.getElementById('hdModal').classList.add('open');
        }
        function closeRegister() {
            document.getElementById('hdModal').classList.remove('open');
        }
        function openDonate() {
            document.getElementById('donateModal').classList.add('open');
        }
        function closeDonate() {
            document.getElementById('donateModal').classList.remove('open');
        }
        function toggleDonorFields(chk) {
            document.getElementById('donorFields').style.display = chk.checked ? 'block' : 'none';
        }

        document.getElementById('hdModal').addEventListener('click', function (e) {
            if (e.target === this) closeRegister();
        });
        document.getElementById('donateModal').addEventListener('click', function (e) {
            if (e.target === this) closeDonate();
        });

        // Auto-open donate modal if redirected after login
        document.addEventListener('DOMContentLoaded', function () {
            const params = new URLSearchParams(window.location.search);
            if (params.get('open_donate') === '1') {
                openDonate();
            }
        });
    </script>
</body>
</html>