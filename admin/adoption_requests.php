<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">
<style>
    .page-container { max-width: 1100px; margin: 0 auto; padding: 30px 24px 50px; }
    .back-link {
        display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600;
        color: var(--teal); background: #eef6f5; border: 1px solid #cfe5e2; padding: 6px 14px;
        border-radius: 999px; text-decoration: none; margin-bottom: 20px; transition: background 0.2s;
    }
    .back-link:hover { background: #e1f0ee; }
</style>
</head>
<body>
<div class="page-container">
    <div>
        <a class="back-link" href="admin.php">&larr; Back to Dashboard</a>
        <a class="back-link" href="notification.php" style="margin-left: 10px;">Notifications &rarr;</a>
    </div>

    <div class="top" style="margin-bottom: 24px;">
        <div>
            <h1>Adoption Requests</h1>
            <p>Review and manage community adoption applications.</p>
        </div>
        <div class="role">Active role: <b>Admin</b></div>
    </div>

    <?php if ($msg): ?>
        <div class="flash <?php echo $msg_type === 'err' ? 'err' : 'ok'; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Requests List</h2>
            <form method="get" style="margin:0;">
                <select class="input" name="s" onchange="this.form.submit()" style="padding:6px 12px;">
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
                style="border:1px solid <?php echo $isFocus ? 'var(--teal)' : 'var(--line)'; ?>; padding:16px; border-radius:8px; background:<?php echo $isFocus ? '#f0fdfa' : '#fafafa'; ?>;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <b style="font-size: 16px; color: var(--ink);">
                        <?php echo htmlspecialchars($r['pet_name']); ?>
                        <small class="muted" style="font-weight:normal;">
                            (<?php echo htmlspecialchars($r['pet_species'] . ', ' . $r['breed']); ?>)
                        </small>
                    </b>
                    <span class="badge"><?php echo htmlspecialchars($r['status']); ?></span>
                </div>

                <p style="color:var(--ink); margin-bottom:4px; font-size: 14px;">
                    <b>Requested by:</b> <?php echo htmlspecialchars($r['fullname']); ?>
                </p>
                <p class="muted" style="font-size:14px; margin-bottom: 0;">
                    <?php echo htmlspecialchars($r['email']); ?>
                    &middot; <?php echo htmlspecialchars($r['contact']); ?>
                    &middot; <?php echo htmlspecialchars($r['address']); ?>
                </p>

                <?php if ($r['status'] === 'Pending'): ?>
                <div style="display:flex; gap:10px; margin-top:12px; border-top:1px solid var(--line); padding-top:12px;">
                    <form method="post" style="margin:0;" onsubmit="return confirm('Approve this adoption?')">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn" style="padding:6px 14px; font-size:13px;">Approve</button>
                    </form>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Reject this request?')">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="mini danger" style="padding:6px 14px; background:var(--red-bg); color:var(--red); border-radius:6px; font-weight:600;">Reject</button>
                    </form>
                </div>
                <?php endif; ?>
            </article>
        <?php endwhile; else: ?>
            <p class="empty" style="text-align:center; padding:20px;">No <?php echo strtolower($filter); ?> requests.</p>
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