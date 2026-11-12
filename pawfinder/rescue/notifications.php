<?php
require_once __DIR__ . '/layout.php';

/* ---------- mark as read (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['read_all'])) {
        @mysqli_query($conn, "UPDATE notifications SET is_read = 1");
    } elseif (isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        @mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = $id");
    }
    header("Location: notification.php" . (isset($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    exit();
}

$types = ['all' => 'All', 'adopter' => 'Adopter messages', 'missing' => 'Missing pets'];
$type  = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : 'all';
$where = $type === 'all' ? '' : "WHERE type = '" . mysqli_real_escape_string($conn, $type) . "'";
$res   = @mysqli_query($conn, "SELECT * FROM notifications $where ORDER BY is_read ASC, created_at DESC");

rescuer_head('notifications', 'Notifications');
rescuer_top('Notifications', 'Messages from adopters and missing pet alerts');
?>
        <section class="panel">
            <div class="panel-head">
                <h2>Inbox</h2>
                <div class="panel-tools">
                    <form method="post"><button class="btn btn-soft" name="read_all" value="1">Mark all as read</button></form>
                </div>
            </div>
            <div class="tabs">
                <?php foreach ($types as $k => $lbl): ?>
                    <a href="?type=<?php echo $k; ?>" class="<?php echo $type === $k ? 'on' : ''; ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>
            </div>

            <?php if ($res && mysqli_num_rows($res) > 0): while ($n = mysqli_fetch_assoc($res)):
                $missing = $n['type'] === 'missing'; ?>
                <div class="msg <?php echo $n['is_read'] ? '' : 'unread'; ?>">
                    <div class="body">
                        <div class="meta">
                            <b><?php echo e($n['sender_name']); ?></b>
                            <span class="pill <?php echo $missing ? 'amber' : 'green'; ?>"><?php echo $missing ? 'Missing pet' : 'Adopter'; ?></span>
                            <time><?php echo e($n['created_at']); ?></time>
                        </div>
                        <p><?php echo e($n['message']); ?></p>
                        <?php if (!$n['is_read']): ?>
                            <form method="post" style="margin-top:6px">
                                <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                                <button class="mini" style="border:0;cursor:pointer">Mark as read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; else: ?>
                <p class="empty">No messages here yet. New adopter messages and missing-pet alerts will show up in this list.</p>
            <?php endif; ?>
        </section>
<?php rescuer_foot(); ?>