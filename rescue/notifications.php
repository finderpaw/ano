<?php

require_once __DIR__ . '/layout.php';

$username = $_SESSION['username'] ?? '';

/* Rescuers only see notifications meant for them:
   'all' (Everyone) and 'rescuer' (Rescuers). Admin alerts and adopter-only
   notifications are never shown here. */
const RESCUER_AUDIENCES = "('all', 'rescuer')";

/* -----------------------------------------    
   MARK AS READ
------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // MARK ALL AS READ (only the ones a rescuer can see)
    if (isset($_POST['read_all'])) {

        $stmt = $conn->prepare("
            INSERT INTO notification_reads
                (notification_id, rescuer_username)

            SELECT id, ?
            FROM notifications
            WHERE audience IN " . RESCUER_AUDIENCES . "

            ON DUPLICATE KEY UPDATE
                read_at = CURRENT_TIMESTAMP
        ");

        $stmt->bind_param("s", $username);

        $stmt->execute();

        $stmt->close();

    }

    // MARK ONE AS READ
    elseif (isset($_POST['id'])) {

        $id = (int)$_POST['id'];

        $stmt = $conn->prepare("
            INSERT INTO notification_reads
                (notification_id, rescuer_username)

            SELECT id, ?
            FROM notifications
            WHERE id = ?
              AND audience IN " . RESCUER_AUDIENCES . "

            ON DUPLICATE KEY UPDATE
                read_at = CURRENT_TIMESTAMP
        ");

        $stmt->bind_param("si", $username, $id);

        $stmt->execute();

        $stmt->close();

    }

    header("Location: notifications.php");

    exit();
}


/* -----------------------------------------
   FETCH NOTIFICATIONS
------------------------------------------ */

$sql = "

    SELECT

        n.id,
        n.title,
        n.message,
        n.audience,
        n.created_at,
        n.request_id,

        CASE
            WHEN nr.id IS NULL THEN 0
            ELSE 1
        END AS is_read

    FROM notifications n

    LEFT JOIN notification_reads nr

        ON nr.notification_id = n.id

        AND nr.rescuer_username = ?

    WHERE n.audience IN " . RESCUER_AUDIENCES . "

    ORDER BY is_read ASC, n.created_at DESC

";


$stmt = $conn->prepare($sql);

$stmt->bind_param("s", $username);

$stmt->execute();

$res = $stmt->get_result();

$audLabels = ['all' => 'Everyone', 'rescuer' => 'Rescuers'];


/* -----------------------------------------
   PAGE HEADER
------------------------------------------ */

rescuer_head(
    'notifications',
    'Notifications'
);

rescuer_top(
    'Notifications',
    'Updates and announcements from Pawfinder'
);

?>

<section class="panel">

    <div class="panel-head">

        <h2>Inbox</h2>

        <div class="panel-tools">

            <form method="POST">

                <button
                    class="btn btn-soft"
                    type="submit"
                    name="read_all"
                    value="1"
                >
                    Mark all as read
                </button>

            </form>

        </div>

    </div>


    <?php if ($res && $res->num_rows > 0): ?>

        <?php while ($n = $res->fetch_assoc()): ?>

            <div class="msg <?php echo $n['is_read'] ? '' : 'unread'; ?>">

                <div class="body">

                    <div class="meta">

                        <b>
                            <?php echo e($n['title']); ?>
                        </b>

                        <span class="pill <?php echo $n['audience'] === 'all' ? 'green' : 'amber'; ?>">

                            <?php echo e($audLabels[$n['audience']] ?? ucfirst($n['audience'])); ?>

                        </span>

                        <time>
                            <?php echo e($n['created_at']); ?>
                        </time>

                    </div>


                    <p>
                        <?php echo nl2br(e($n['message'])); ?>
                    </p>


                    <?php if (!empty($n['request_id'])): ?>

                        <small>
                            Related Request ID:
                            <?php echo (int)$n['request_id']; ?>
                        </small>

                    <?php endif; ?>


                    <?php if (!$n['is_read']): ?>

                        <form method="POST" style="margin-top: 8px;">

                            <input
                                type="hidden"
                                name="id"
                                value="<?php echo (int)$n['id']; ?>"
                            >

                            <button
                                type="submit"
                                class="mini"
                                style="border:0; cursor:pointer;"
                            >
                                Mark as read
                            </button>

                        </form>

                    <?php else: ?>

                        <small style="color: #1f7a4d;">
                            ✓ Read
                        </small>

                    <?php endif; ?>

                </div>

            </div>

        <?php endwhile; ?>

    <?php else: ?>

        <p class="empty">
            No notifications available yet.
            New Pawfinder announcements and updates will appear here.
        </p>

    <?php endif; ?>

</section>

<?php

$stmt->close();

rescuer_foot();

?>