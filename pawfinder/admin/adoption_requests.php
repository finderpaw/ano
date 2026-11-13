<?php
require_once __DIR__ . '/admin_guard.php';

$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqId  = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($reqId > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $conn->begin_transaction();

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

                $msg = 'Request approved. The pet is now marked as Adopted.';
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

                $msg = 'Request rejected.';
            }

            $conn->commit();
            $msg_type = 'succ';
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Could not update: ' . $e->getMessage();
            $msg_type = 'err';
        }
    }
}

// Filter
$allowed = ['Pending', 'Approved', 'Rejected'];
$filter  = in_array($_GET['s'] ?? '', $allowed, true) ? $_GET['s'] : 'Pending';
$focusId = (int)($_GET['focus'] ?? 0);

$q = $conn->prepare("
    SELECT ar.id, ar.status, ar.pet_id,
           p.pet_name, p.pet_species, p.breed,
           u.fullname, u.email, u.contact, u.address
    FROM adoption_requests ar
    JOIN pets  p ON p.id = ar.pet_id
    JOIN users u ON u.id = ar.user_id
    WHERE ar.status = ?
    ORDER BY ar.id DESC
");
$q->bind_param("s", $filter);
$q->execute();
$res = $q->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Adoption Requests</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="app" style="display:block; padding:20px;">
<p><a href="admin.php">← Back to Dashboard</a> &nbsp;|&nbsp; <a href="notification.php">Notifications</a></p>

<?php if ($msg): ?>
    <div style="padding:10px 15px; margin-bottom:15px; border-radius:4px; background:<?php echo $msg_type === 'err' ? '#f8d7da' : '#d4edda'; ?>; color:<?php echo $msg_type === 'err' ? '#721c24' : '#155724'; ?>;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<section class="card" style="background:#fff; padding:20px; border-radius:8px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h2>Adoption requests</h2>
        <form method="get" style="margin:0;">
            <select class="input" name="s" onchange="this.form.submit()" style="padding:6px;">
                <?php foreach ($allowed as $s): ?>
                    <option value="<?php echo $s; ?>"<?php echo $s === $filter ? ' selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div style="display:flex; flex-direction:column; gap:15px;">
    <?php if ($res && $res->num_rows > 0): while ($r = $res->fetch_assoc()):
        $isFocus = ($focusId === (int)$r['id']);
    ?>
        <article id="req-<?php echo (int)$r['id']; ?>"
                 style="border:1px solid <?php echo $isFocus ? '#0d9488' : '#eee'; ?>; padding:15px; border-radius:6px; background:<?php echo $isFocus ? '#f0fdfa' : '#fafafa'; ?>;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <b><?php echo htmlspecialchars($r['pet_name']); ?>
                    <small style="font-weight:normal; color:#777;">
                        (<?php echo htmlspecialchars($r['pet_species'] . ', ' . $r['breed']); ?>)
                    </small>
                </b>
                <span style="background:#e2e8f0; padding:2px 8px; border-radius:4px; font-size:11px;">
                    <?php echo htmlspecialchars($r['status']); ?>
                </span>
            </div>

            <p style="color:#333; margin-bottom:4px;">
                <b>Requested by:</b> <?php echo htmlspecialchars($r['fullname']); ?>
            </p>
            <p style="color:#555; font-size:14px;">
                <?php echo htmlspecialchars($r['email']); ?>
                &middot; <?php echo htmlspecialchars($r['contact']); ?>
                &middot; <?php echo htmlspecialchars($r['address']); ?>
            </p>

            <?php if ($r['status'] === 'Pending'): ?>
            <div style="display:flex; gap:10px; margin-top:12px; border-top:1px solid #eee; padding-top:10px;">
                <form method="post" style="margin:0;" onsubmit="return confirm('Approve this adoption?')">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" style="padding:7px 16px; background:#0d9488; color:#fff; border:none; border-radius:4px; cursor:pointer;">Approve</button>
                </form>
                <form method="post" style="margin:0;" onsubmit="return confirm('Reject this request?')">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" style="padding:7px 16px; background:#fee2e2; color:#991b1b; border:none; border-radius:4px; cursor:pointer;">Reject</button>
                </form>
            </div>
            <?php endif; ?>
        </article>
    <?php endwhile; else: ?>
        <p style="color:#777; text-align:center; padding:20px;">No <?php echo strtolower($filter); ?> requests.</p>
    <?php endif; ?>
    </div>
</section>
</div>

<script>
var el = document.getElementById('req-<?php echo $focusId; ?>');
if (el) el.scrollIntoView({behavior: 'smooth', block: 'center'});
</script>
</body>
</html>