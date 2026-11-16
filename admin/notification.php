<?php
require_once __DIR__ . '/admin_guard.php';

$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;
require_once __DIR__ . '/mail_helper.php';

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    audience VARCHAR(20) NOT NULL DEFAULT 'all',
    request_id INT NULL DEFAULT NULL,
    type VARCHAR(50) NULL DEFAULT NULL,
    reference_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

@mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL DEFAULT NULL");
@mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_id INT NULL DEFAULT NULL");

// TABLE FOR SYSTEM CONTACT INFO (PARA SA EDIT CONTACT MODAL)
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS site_contact (
    id INT PRIMARY KEY DEFAULT 1,
    email VARCHAR(150) NOT NULL DEFAULT 'pawfinder@example.com',
    phone VARCHAR(50) NOT NULL DEFAULT '0912-345-6789',
    address VARCHAR(255) NOT NULL DEFAULT 'Community Animal Shelter'
)");
@mysqli_query($conn, "INSERT IGNORE INTO site_contact (id) VALUES (1)");

$aud = [
    'all' => 'Everyone',
    'adopter' => 'Adopters',
    'rescuer' => 'Rescuers',
    'admin' => 'Admin alerts'
];

$msg = '';
$msg_type = '';
$root = '../';

// EMAIL ADOPTION RESULT
function notifyAdoptionResult(mysqli $conn, int $reqId, string $action): string
{
    try {
        $s = $conn->prepare("SELECT * FROM adoption_requests WHERE id = ?");
        $s->bind_param("i", $reqId);
        $s->execute();
        $ar = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$ar) return '';

        $email = $ar['email'] ?? '';
        $name = $ar['full_name'] ?? $ar['fullname'] ?? $ar['name'] ?? $ar['username'] ?? '';
        $uid = $ar['user_id'] ?? $ar['adopter_id'] ?? $ar['applicant_id'] ?? null;

        if (($email === '' || $name === '') && $uid) {
            $u = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $uid = (int)$uid;
            $u->bind_param("i", $uid);
            $u->execute();
            $user = $u->get_result()->fetch_assoc();
            $u->close();

            if ($user) {
                if ($email === '') $email = $user['email'] ?? '';
                if ($name === '') $name = $user['full_name'] ?? $user['fullname'] ?? $user['name'] ?? $user['username'] ?? '';
            }
        }

        $petName = 'the pet';

        if (!empty($ar['pet_id'])) {
            $p = $conn->prepare("SELECT * FROM pets WHERE id = ?");
            $pid = (int)$ar['pet_id'];
            $p->bind_param("i", $pid);
            $p->execute();
            $pet = $p->get_result()->fetch_assoc();
            $p->close();

            if ($pet) $petName = $pet['name'] ?? $pet['pet_name'] ?? 'the pet';
        }

        $pn = htmlspecialchars($petName);

        if ($action === 'approve') {
            $ok = send_status_email(
                $email,
                $name,
                'Your adoption request for ' . $petName . ' was approved',
                'Adoption Approved',
                'Congratulations! Your request to adopt <b>' . $pn . '</b> has been <b>approved</b>. Please wait for further instructions on the next steps.',
                true
            );
        } else {
            $ok = send_status_email(
                $email,
                $name,
                'Update on your adoption request for ' . $petName,
                'Adoption Not Approved',
                'Thank you for your interest in adopting <b>' . $pn . '</b>. Unfortunately, your request was <b>not approved</b> at this time. You are welcome to browse other pets on Pawfinder.',
                false
            );
        }

        return $ok ? ' Email sent to the applicant.' : ' (Email could not be sent.)';

    } catch (Throwable $e) {
        error_log('notifyAdoptionResult: ' . $e->getMessage());
        return ' (Email could not be sent.)';
    }
}

// PROCESS ADOPTION
function processAdoption(mysqli $conn, int $reqId, string $action): string
{
    $conn->begin_transaction();

    try {
        $s = $conn->prepare("SELECT pet_id, status FROM adoption_requests WHERE id = ? FOR UPDATE");
        $s->bind_param("i", $reqId);
        $s->execute();
        $req = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$req || $req['status'] !== 'Pending') {
            throw new Exception('Request is no longer pending.');
        }

        $petId = (int)$req['pet_id'];

        if ($action === 'approve') {
            $a = $conn->prepare("UPDATE adoption_requests SET status = 'Approved' WHERE id = ?");
            $a->bind_param("i", $reqId);
            $a->execute();
            $a->close();

            $r = $conn->prepare("UPDATE adoption_requests SET status = 'Rejected' WHERE pet_id = ? AND id <> ? AND status = 'Pending'");
            $r->bind_param("ii", $petId, $reqId);
            $r->execute();
            $r->close();

            $p = $conn->prepare("UPDATE pets SET status = 'Adopted' WHERE id = ?");
            $p->bind_param("i", $petId);
            $p->execute();
            $p->close();

            $out = 'Request approved. The pet is now marked as Adopted.';

        } else {
            $a = $conn->prepare("UPDATE adoption_requests SET status = 'Rejected' WHERE id = ?");
            $a->bind_param("i", $reqId);
            $a->execute();
            $a->close();

            $c = $conn->prepare("SELECT COUNT(*) AS c FROM adoption_requests WHERE pet_id = ? AND status = 'Pending'");
            $c->bind_param("i", $petId);
            $c->execute();
            $left = (int)$c->get_result()->fetch_assoc()['c'];
            $c->close();

            if ($left === 0) {
                $p = $conn->prepare("UPDATE pets SET status = 'Available' WHERE id = ? AND status = 'Pending'");
                $p->bind_param("i", $petId);
                $p->execute();
                $p->close();
            }

            $out = 'Request rejected.';
        }

        $conn->commit();

        return $out . notifyAdoptionResult($conn, $reqId, $action);

    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// HANDLE ADMIN ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $act = $_POST['action'] ?? '';

    if ($act === 'update_contact') {
        $c_email = trim($_POST['contact_email'] ?? '');
        $c_phone = trim($_POST['contact_phone'] ?? '');
        $c_addr  = trim($_POST['contact_address'] ?? '');

        if ($c_email === '' || $c_phone === '' || $c_addr === '') {
            $msg = 'Please fill in all contact fields.';
            $msg_type = 'err';
        } else {
            $up = $conn->prepare("UPDATE site_contact SET email = ?, phone = ?, address = ? WHERE id = 1");
            if ($up) {
                $up->bind_param("sss", $c_email, $c_phone, $c_addr);
                if ($up->execute()) {
                    $msg = 'Contact information updated successfully.';
                    $msg_type = 'succ';
                } else {
                    $msg = 'Failed to update contact information.';
                    $msg_type = 'err';
                }
                $up->close();
            }
        }

    } elseif ($act === 'send') {

        $t = trim($_POST['title'] ?? '');
        $m = trim($_POST['message'] ?? '');
        $a = $_POST['audience'] ?? '';

        if ($t === '' || $m === '' || $a === '' || !isset($aud[$a])) {
            $msg = 'Add a title, a message and choose an audience.';
            $msg_type = 'err';

        } else {
            $st = $conn->prepare("INSERT INTO notifications (title, message, audience) VALUES (?, ?, ?)");

            if ($st) {
                $st->bind_param("sss", $t, $m, $a);

                if ($st->execute()) {
                    $msg = 'Notification sent to ' . strtolower($aud[$a]) . '.';
                    $msg_type = 'succ';
                } else {
                    $msg = 'Could not save the notification.';
                    $msg_type = 'err';
                }

                $st->close();
            }
        }

    } elseif ($act === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        $st = $conn->prepare("DELETE FROM notifications WHERE id = ?");

        if ($st) {
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
        }

        $msg = 'Notification deleted.';
        $msg_type = 'succ';

    } elseif ($act === 'approve' || $act === 'reject') {

        $reqId = (int)($_POST['request_id'] ?? 0);

        try {
            $msg = processAdoption($conn, $reqId, $act);
            $msg_type = 'succ';

        } catch (Throwable $e) {
            $msg = 'Could not update: ' . $e->getMessage();
            $msg_type = 'err';
        }
    }
}

// FETCH CURRENT CONTACT INFO
$contact_data = ['email' => '', 'phone' => '', 'address' => ''];
$cq = @mysqli_query($conn, "SELECT * FROM site_contact WHERE id = 1");
if ($cq && $row_c = mysqli_fetch_assoc($cq)) {
    $contact_data = $row_c;
}

// FILTER OPTIONS
$filters = [
    'rescuer' => 'Rescuers',
    'adopter' => 'Adopters',
    'admin' => 'Admin alerts',
    'health_drive' => 'Health drives',
    'donation' => 'Donations'
];

$f = $_GET['a'] ?? '';

if (!isset($filters[$f])) {
    $f = '';
}

// GET NOTIFICATIONS
$query = "SELECT
            n.*,
            ar.status AS req_status,
            u.rescuer_status
          FROM notifications n
          LEFT JOIN adoption_requests ar ON ar.id = n.request_id
          LEFT JOIN users u ON n.type = 'rescuer_request' AND u.id = n.reference_id";

$params = [];
$types = "";

// APPLY FILTER
if ($f === 'rescuer') {

    $query .= " WHERE (n.audience = 'rescuer' OR n.type = 'rescuer_request')";

} elseif ($f === 'adopter') {

    $query .= " WHERE (n.audience = 'adopter' OR n.request_id IS NOT NULL)";

} elseif ($f === 'health_drive') {

    $query .= " WHERE n.type = 'health_drive'";

} elseif ($f === 'donation') {

    // SHOW DONATION NOTIFICATIONS ONLY
    $query .= " WHERE n.type = 'donation' AND n.audience = 'admin'";

} elseif ($f !== '') {

    $query .= " WHERE n.audience = ?";
    $params[] = $f;
    $types .= "s";
}

// SORT
$query .= " ORDER BY COALESCE((ar.status = 'Pending' OR u.rescuer_status = 'Pending'), 0) DESC, n.id DESC";

$stmt = $conn->prepare($query);

if ($stmt) {

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

} else {
    $res = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>PAWFINDER - Send Notifications</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">

<style>
    aside.side {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    aside.side .nav-container {
        flex: 1;
    }

    aside.side .logout {
        border-top: 1px solid var(--line);
        padding-top: 12px;
        margin-top: auto;
    }

    aside.side .logout a {
        background: var(--red-bg);
        color: var(--red) !important;
        border-radius: 8px;
        transition: background 0.2s;
    }

    aside.side .logout a:hover {
        background: #fecaca;
    }

    /* MODAL STYLES */
    .modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        z-index: 999;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .modal-backdrop.open {
        display: flex;
    }
    .modal-box {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        width: 100%;
        max-width: 440px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    .modal-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    .modal-head h3 {
        margin: 0;
        font-size: 18px;
    }
    .modal-close {
        background: none;
        border: none;
        font-size: 22px;
        cursor: pointer;
        color: var(--muted);
    }
</style>
</head>

<body>

<div class="app">

    <aside class="side">

        <div class="nav-container">

            <div class="brand">
                <img src="<?php echo $root; ?>image/logo2.jpg" alt="PAWFINDER logo" onerror="this.src='https://placehold.co/42x42/0b5a6b/ffffff?text=PF'">
                <div>
                    <b>PAWFINDER</b>
                    <small>Community Pet System</small>
                </div>
            </div>

            <div class="group">MAIN MENU</div>

            <nav>

                <a href="admin.php">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </a>

                <a href="adopt.php">
                    <svg viewBox="0 0 24 24"><path d="M12 21s-7-4.5-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.5-9 9-9 9z"/></svg>
                    Pets
                </a>

                <a href="users.php">
                    <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.4c2 .7 3.5 2.500 3.5 5.600"/></svg>
                    Users
                </a>

                <a href="notification.php" class="active">
                    <svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.300 21a1.900 1.900 0 0 0 3.400 0"/></svg>
                    Notifications
                </a>

                <a href="admin_updates.php">
                    <svg viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.700 4.400A9 9 0 0 0 20.500 15"/></svg>
                    Weekly Updates
                    <span class="an-nav-badge" id="anNavBadge"></span>
                </a>

            </nav>

            <hr>

            <div class="group">OTHER</div>

            <nav>
                <a href="settings.php">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.400 15a1.700 1.700 0 0 0 .3 1.800l.1.1a2 2 0 1 1-2.800 2.800l-.1-.1a1.700 1.700 0 0 0-1.800-.3 1.700 1.700 0 0 0-1 1.500V21a2 2 0 1 1-4 0v-.1a1.700 1.700 0 0 0-1.100-1.500 1.700 1.700 0 0 0-1.800.3l-.1.1a2 2 0 1 1-2.800-2.800l.1-.1a1.700 1.700 0 0 0 .3-1.800 1.700 1.700 0 0 0-1.500-1H3a2 2 0 1 1 0-4h.1a1.700 1.700 0 0 0 1.500-1.100 1.700 1.700 0 0 0-.3-1.800l-.1-.1a2 2 0 1 1 2.800-2.800l.1.1a1.700 1.700 0 0 0 1.800.3H9a1.700 1.700 0 0 0 1-1.500V3a2 2 0 1 1 4 0v.1a1.700 1.700 0 0 0 1 1.500 1.700 1.700 0 0 0 1.800-.3l-.1-.1a2 2 0 1 1 2.800 2.800l-.1.1a1.700 1.700 0 0 0-.3 1.800V9a1.700 1.700 0 0 0 1.500 1H21a2 2 0 1 1 0 4h-.1a1.700 1.700 0 0 0-1.500 1z"/></svg>
                    Settings
                </a>
                <a href="contact.php">
                    <svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.200 2 19.800 19.800 0 0 1-8.600-3.100 19.500 19.500 0 0 1-6-6A19.800 19.800 0 0 1 2.100 4.200 2 2 0 0 1 4.100 2h3a2 2 0 0 1 2 1.700c.1 1 .4 1.900.7 2.800a2 2 0 0 1-.5 2.100L8.100 9.900a16 16 0 0 0 6 6l1.300-1.300a2 2 0 0 1 2.100-.4c.9.300 1.800.6 2.800.7a2 2 0 0 1 1.700 2z"/></svg>
                    Contact
                </a>
            </nav>

        </div>

        <nav class="logout">
            <a href="admin_logout.php" onclick="return confirm('Are you sure you want to log out?');">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Log Out
            </a>
        </nav>

    </aside>

    <main class="main">

        <div class="top">
            <div>
                <h1>Notifications Center</h1>
                <p>Broadcast updates or review user adoption, rescuer, health drive, and donation notifications.</p>
            </div>

            <div style="display:flex; align-items:center; gap:12px;">
                <button type="button" class="btn-soft" onclick="document.getElementById('contactModal').classList.add('open')">
                    Edit Contact Info
                </button>
                <div class="role">
                    Active role: <b>Admin</b>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="flash <?php echo $msg_type === 'err' ? 'err' : 'ok'; ?>" style="margin-top:15px;">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid g2" style="align-items:start; margin-top:24px;">

            <!-- CREATE NOTIFICATION -->
            <section class="card">

                <h2>New notification</h2>

                <form method="post" class="form-grid one" style="margin-top:16px;">

                    <input type="hidden" name="action" value="send">

                    <label class="field">
                        Title
                        <input class="input" name="title" maxlength="150" required>
                    </label>

                    <label class="field">
                        Send to

                        <select class="input" name="audience" required>
                            <option value="" disabled selected>Choose audience…</option>

                            <?php foreach ($aud as $k => $l): ?>
                                <?php if ($k === 'admin') continue; ?>
                                <option value="<?php echo htmlspecialchars($k); ?>">
                                    <?php echo htmlspecialchars($l); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </label>

                    <label class="field">
                        Message
                        <textarea class="input" name="message" rows="5" required></textarea>
                    </label>

                    <div>
                        <button class="btn" type="submit">Send notification</button>
                    </div>

                </form>

            </section>

            <!-- SENT NOTIFICATIONS -->
            <section class="card">

                <div class="panel-head" style="margin-bottom:15px;">

                    <h2>Sent notifications</h2>

                    <form method="get" style="margin:0;">

                        <select class="input" name="a" autocomplete="off" onchange="this.form.submit()" style="padding:6px 10px;">

                            <option value="">All notifications</option>

                            <?php foreach ($filters as $k => $l): ?>
                                <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $k === $f ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($l); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                    </form>

                </div>

                <div style="display:flex; flex-direction:column; gap:15px;">

                    <?php if ($res && mysqli_num_rows($res) > 0): ?>

                        <?php while ($n = mysqli_fetch_assoc($res)): ?>

                            <?php
                                $hasReq = !empty($n['request_id']) && $n['req_status'] !== null;
                                $reqStat = $n['req_status'] ?? '';

                                $hasResc = ($n['type'] ?? '') === 'rescuer_request' && !empty($n['reference_id']);
                                $rescStat = $n['rescuer_status'] ?? '';

                                $link = '';

                                if ($hasReq) {
                                    $link = 'view_application.php?request_id=' . (int)$n['request_id'];
                                } elseif ($hasResc) {
                                    $link = 'view_rescuer.php?user_id=' . (int)$n['reference_id'];
                                }

                                // CATEGORY BADGES
                                if (($n['type'] ?? '') === 'donation') {
                                    $badgeText = 'Donation';
                                } elseif (($n['type'] ?? '') === 'health_drive') {
                                    $badgeText = 'Health drive';
                                } elseif (($n['type'] ?? '') === 'rescuer_request') {
                                    $badgeText = 'Rescuer application';
                                } elseif (!empty($n['request_id'])) {
                                    $badgeText = 'Adoption request';
                                } else {
                                    $badgeText = $aud[$n['audience']] ?? $n['audience'];
                                }
                            ?>

                            <article class="note"
                                <?php if ($link !== ''): ?>
                                    onclick="if(!event.target.closest('button,form,a')) location.href='<?php echo htmlspecialchars($link, ENT_QUOTES); ?>'"
                                    title="Click to view the application"
                                <?php endif; ?>
                                style="border:1px solid var(--line); padding:15px; border-radius:8px; background:#fafafa;<?php echo $link !== '' ? ' cursor:pointer;' : ''; ?>">

                                <div class="note-top">

                                    <b>

                                        <?php if ($link !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($link); ?>" style="color:var(--teal); text-decoration:none;">
                                                <?php echo htmlspecialchars($n['title']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($n['title']); ?>
                                        <?php endif; ?>

                                    </b>

                                    <span class="badge">
                                        <?php echo htmlspecialchars($badgeText); ?>
                                    </span>

                                </div>

                                <p style="margin:8px 0; color:var(--ink); font-size:14px;">
                                    <?php echo nl2br(htmlspecialchars($n['message'])); ?>
                                </p>

                                <?php if ($hasReq): ?>

                                    <div style="display:flex; align-items:center; gap:10px; margin:10px 0; flex-wrap:wrap;">

                                        <span class="badge"><?php echo htmlspecialchars($reqStat); ?></span>

                                        <a href="<?php echo htmlspecialchars($link); ?>" class="btn-soft">
                                            View Application
                                        </a>

                                        <?php if ($reqStat === 'Pending'): ?>

                                            <form method="post" style="margin:0;" onsubmit="return confirm('Approve this adoption?')">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$n['request_id']; ?>">
                                                <button class="btn" type="submit" style="padding:6px 12px; font-size:13px;">Approve</button>
                                            </form>

                                            <form method="post" style="margin:0;" onsubmit="return confirm('Reject this request?')">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$n['request_id']; ?>">
                                                <button class="mini danger" type="submit" style="padding:6px 12px; background:var(--red-bg); color:var(--red); border-radius:6px;">Reject</button>
                                            </form>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                                <?php if ($hasResc): ?>

                                    <div style="display:flex; align-items:center; gap:10px; margin:10px 0; flex-wrap:wrap;">

                                        <span class="badge">
                                            <?php echo htmlspecialchars($rescStat !== '' ? $rescStat : 'Unknown'); ?>
                                        </span>

                                        <a href="<?php echo htmlspecialchars($link); ?>" class="btn-soft">
                                            View Application
                                        </a>

                                        <?php if ($rescStat === 'Pending'): ?>

                                            <form method="post" action="rescuer_action.php" style="margin:0;" onsubmit="return confirm('Approve this user as a Local Rescuer?')">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$n['reference_id']; ?>">
                                                <button class="btn" type="submit" style="padding:6px 12px; font-size:13px;">Approve</button>
                                            </form>

                                            <form method="post" action="rescuer_action.php" style="margin:0;" onsubmit="return confirm('Reject this rescuer application?')">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$n['reference_id']; ?>">
                                                <button class="mini danger" type="submit" style="padding:6px 12px; background:var(--red-bg); color:var(--red); border-radius:6px;">Reject</button>
                                            </form>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                                <?php if (($n['type'] ?? '') === 'donation' && !empty($n['reference_id'])): ?>

                                    <div style="margin:10px 0;">
                                        <span class="badge">
                                            Donation ID: #<?php echo (int)$n['reference_id']; ?>
                                        </span>
                                    </div>

                                <?php endif; ?>

                                <div class="note-top" style="border-top:1px solid var(--line); padding-top:8px; margin-top:8px;">

                                    <small class="muted">
                                        <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))); ?>
                                    </small>

                                    <form method="post" style="margin:0;" onsubmit="return confirm('Delete this notification?')">

                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">

                                        <button class="mini danger" type="submit">Delete</button>

                                    </form>

                                </div>

                            </article>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <p class="empty" style="text-align:center; padding:20px;">
                            No notifications found in this category.
                        </p>

                    <?php endif; ?>

                </div>

            </section>

        </div>

    </main>

</div>

<!-- EDIT CONTACT MODAL -->
<div class="modal-backdrop" id="contactModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Update Contact Details</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('contactModal').classList.remove('open')">&times;</button>
        </div>
        <form method="post" class="form-grid one">
            <input type="hidden" name="action" value="update_contact">
            <label class="field">
                Contact Email
                <input class="input" type="email" name="contact_email" value="<?php echo htmlspecialchars($contact_data['email'] ?? ''); ?>" required>
            </label>
            <label class="field">
                Contact Number
                <input class="input" type="text" name="contact_phone" value="<?php echo htmlspecialchars($contact_data['phone'] ?? ''); ?>" required>
            </label>
            <label class="field">
                Shelter / Office Address
                <input class="input" type="text" name="contact_address" value="<?php echo htmlspecialchars($contact_data['address'] ?? ''); ?>" required>
            </label>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" class="btn-soft" onclick="document.getElementById('contactModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>