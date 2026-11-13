<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

/* Ensure notifications table exists */
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    audience VARCHAR(20) NOT NULL DEFAULT 'all',
    request_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$aud = ['all' => 'Everyone', 'adopter' => 'Adopters', 'rescuer' => 'Rescuers', 'admin' => 'Admin alerts'];
$msg = '';
$msg_type = '';

/* Approve / Reject ng adoption request */
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
        return $out;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'send') {
        $t = trim($_POST['title'] ?? '');
        $m = trim($_POST['message'] ?? '');
        $a = $_POST['audience'] ?? 'all';

        if ($t === '' || $m === '' || !isset($aud[$a])) {
            $msg = 'Add a title, a message and an audience.'; $msg_type = 'err';
        } else {
            $t_esc = mysqli_real_escape_string($conn, $t);
            $m_esc = mysqli_real_escape_string($conn, $m);
            $a_esc = mysqli_real_escape_string($conn, $a);

            $ins = mysqli_query($conn, "INSERT INTO notifications (title, message, audience) VALUES ('$t_esc', '$m_esc', '$a_esc')");
            if ($ins) {
                $msg = 'Notification sent to ' . strtolower($aud[$a]) . '.'; $msg_type = 'succ';
            } else {
                $msg = 'Could not save the notification.'; $msg_type = 'err';
            }
        }
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM notifications WHERE id = $id");
        $msg = 'Notification deleted.'; $msg_type = 'succ';
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

$f = isset($aud[$_GET['a'] ?? '']) ? $_GET['a'] : '';
$f_esc = mysqli_real_escape_string($conn, $f);

$query = "SELECT n.*, ar.status AS req_status
          FROM notifications n
          LEFT JOIN adoption_requests ar ON ar.id = n.request_id";
if ($f_esc !== '') {
    $query .= " WHERE n.audience = '$f_esc'";
}
$query .= " ORDER BY n.id DESC";

$res = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Send Notifications</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="app" style="display:block; padding:20px;">
<p><a href="admin.php">← Back to Dashboard</a> &nbsp;|&nbsp; <a href="adoption_requests.php">Adoption Requests</a></p>

<?php if ($msg): ?>
    <div style="padding: 10px 15px; margin-bottom: 15px; border-radius: 4px; background: <?php echo $msg_type === 'err' ? '#f8d7da' : '#d4edda'; ?>; color: <?php echo $msg_type === 'err' ? '#721c24' : '#155724'; ?>;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<div class="grid g2" style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items:start">
    <!-- NEW NOTIFICATION FORM -->
    <section class="card" style="background:#fff; padding:20px; border-radius:8px;">
        <h2>New notification</h2>
        <form method="post" class="form-grid one" style="margin-top:16px; display:flex; flex-direction:column; gap:15px;">
            <input type="hidden" name="action" value="send">
            <label class="field">Title<br><input class="input" name="title" maxlength="150" required style="width:100%; padding:8px; margin-top:5px;"></label>
            <label class="field">Send to<br>
                <select class="input" name="audience" style="width:100%; padding:8px; margin-top:5px;">
                    <?php foreach ($aud as $k => $l) { if ($k === 'admin') continue; echo "<option value=\"$k\">$l</option>"; } ?>
                </select>
            </label>
            <label class="field">Message<br><textarea class="input" name="message" rows="5" required style="width:100%; padding:8px; margin-top:5px;"></textarea></label>
            <div><button class="btn" type="submit" style="padding:8px 15px; background:#0b5a6b; color:#fff; border:none; border-radius:4px; cursor:pointer;">Send notification</button></div>
        </form>
    </section>

    <!-- SENT NOTIFICATIONS LIST -->
    <section class="card" style="background:#fff; padding:20px; border-radius:8px;">
        <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2>Sent notifications</h2>
            <form method="get" style="margin:0;">
                <select class="input" name="a" onchange="this.form.submit()" style="padding:6px;">
                    <option value="">All audiences</option>
                    <?php foreach ($aud as $k => $l) echo '<option value="' . $k . '"' . ($k === $f ? ' selected' : '') . '>' . $l . '</option>'; ?>
                </select>
            </form>
        </div>

        <div style="display:flex; flex-direction:column; gap:15px;">
        <?php if ($res && mysqli_num_rows($res) > 0): while ($n = mysqli_fetch_assoc($res)):
            $hasReq   = !empty($n['request_id']) && $n['req_status'] !== null;
            $reqStat  = $n['req_status'] ?? '';
            $link     = $hasReq ? 'adoption_requests.php?s=' . urlencode($reqStat) . '&focus=' . (int)$n['request_id'] : '';
            $badgeBg  = ['Pending' => '#fef3c7', 'Approved' => '#dcfce7', 'Rejected' => '#fee2e2'][$reqStat] ?? '#e2e8f0';
            $badgeCol = ['Pending' => '#92400e', 'Approved' => '#166534', 'Rejected' => '#991b1b'][$reqStat] ?? '#334155';
        ?>
            <article class="note"
                <?php if ($hasReq): ?>
                    onclick="if(!event.target.closest('button,form,a')) location.href='<?php echo htmlspecialchars($link, ENT_QUOTES); ?>'"
                    title="Click to open the request"
                <?php endif; ?>
                style="border:1px solid #eee; padding:15px; border-radius:6px; background:#fafafa;<?php echo $hasReq ? ' cursor:pointer;' : ''; ?>">
                <div class="note-top" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <b>
                        <?php if ($hasReq): ?>
                            <a href="<?php echo htmlspecialchars($link); ?>" style="color:#0b5a6b; text-decoration:none;"><?php echo htmlspecialchars($n['title']); ?></a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($n['title']); ?>
                        <?php endif; ?>
                    </b>
                    <span class="badge" style="background:#e2e8f0; padding:2px 6px; border-radius:4px; font-size:11px;"><?php echo htmlspecialchars($aud[$n['audience']] ?? $n['audience']); ?></span>
                </div>
                <p style="margin-bottom:10px; color:#333;"><?php echo nl2br(htmlspecialchars($n['message'])); ?></p>

                <?php if ($hasReq): ?>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <span style="background:<?php echo $badgeBg; ?>; color:<?php echo $badgeCol; ?>; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">
                        <?php echo htmlspecialchars($reqStat); ?>
                    </span>

                    <?php if ($reqStat === 'Pending'): ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Approve this adoption?')">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="request_id" value="<?php echo (int)$n['request_id']; ?>">
                        <button type="submit" style="padding:6px 14px; background:#0d9488; color:#fff; border:none; border-radius:4px; cursor:pointer;">Approve</button>
                    </form>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Reject this request?')">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="request_id" value="<?php echo (int)$n['request_id']; ?>">
                        <button type="submit" style="padding:6px 14px; background:#fee2e2; color:#991b1b; border:none; border-radius:4px; cursor:pointer;">Reject</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="note-top" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #eee; padding-top:8px;">
                    <small class="muted" style="color:#777;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))); ?></small>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Delete this notification?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                        <button class="mini danger" type="submit" style="background:none; border:none; color:red; cursor:pointer;">Delete</button>
                    </form>
                </div>
            </article>
        <?php endwhile; else: ?>
            <p class="empty" style="color:#777; text-align:center; padding:20px;">Nothing sent yet. Write your first notification on the left.</p>
        <?php endif; ?>
        </div>
    </section>
</div>
</div>
</body>
</html>