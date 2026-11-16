
<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$uid = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_profile'])) {
    $_SESSION['csrf_profile'] = bin2hex(random_bytes(16));
}

/* Announcements visible to adopters */
const ADOPTER_AUDIENCES = "('all', 'adopter')";

/* Store which announcements each adopter has read */
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS notification_user_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_notif_user (notification_id, user_id)
        )
    ");
} catch (Throwable $e) {
    // Continue even if table creation fails
}

function nt_safe_link($l, $conn, $uid): string
{
    $l = trim((string) $l);

    if (preg_match('/pet_details\.php\?id=(\d+)/i', $l, $m)) {
        $petId = (int) $m[1];

        try {
            $st = $conn->prepare("
                SELECT id
                FROM adoption_requests
                WHERE pet_id = ? AND user_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");

            $st->bind_param("ii", $petId, $uid);
            $st->execute();
            $res = $st->get_result()->fetch_assoc();
            $st->close();

            if ($res) {
                return 'my_application.php?request_id=' . (int) $res['id'];
            }
        } catch (Throwable $e) {
            // Ignore invalid or unavailable links
        }
    }

    return preg_match('/^[A-Za-z0-9_\-\/]+\.php(\?[A-Za-z0-9_=&\-]*)?$/', $l)
        ? $l
        : '';
}

function nt_kind(string $msg): string
{
    if (
        stripos($msg, 'not approved') !== false ||
        stripos($msg, 'rejected') !== false
    ) {
        return 'rejected';
    }

    if (stripos($msg, 'approved') !== false) {
        return 'approved';
    }

    return 'info';
}

/* OPEN A PERSONAL NOTIFICATION */
if (isset($_GET['open'])) {
    $id = (int) $_GET['open'];
    $go = 'notifications.php';

    try {
        $st = $conn->prepare("
            SELECT link
            FROM user_notifications
            WHERE id = ? AND user_id = ?
        ");

        $st->bind_param("ii", $id, $uid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if ($row) {
            $st = $conn->prepare("
                UPDATE user_notifications
                SET is_read = 1
                WHERE id = ? AND user_id = ?
            ");

            $st->bind_param("ii", $id, $uid);
            $st->execute();
            $st->close();

            $l = nt_safe_link($row['link'], $conn, $uid);

            if ($l !== '') {
                $go = $l;
            }
        }
    } catch (Throwable $e) {
        // Keep default destination
    }

    header('Location: ' . $go);
    exit();
}

/* OPEN AN ANNOUNCEMENT */
if (isset($_GET['open_b'])) {
    $id = (int) $_GET['open_b'];

    try {
        $st = $conn->prepare("
            INSERT INTO notification_user_reads (notification_id, user_id)
            SELECT id, ?
            FROM notifications
            WHERE id = ?
              AND audience IN " . ADOPTER_AUDIENCES . "
            ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP
        ");

        $st->bind_param("ii", $uid, $id);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        // Ignore read-status errors
    }

    header('Location: notifications.php');
    exit();
}

/* FILTER */
$filter = (($_GET['f'] ?? '') === 'unread') ? 'unread' : 'all';

/* MARK ALL AS READ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'mark_all' &&
    hash_equals($_SESSION['csrf_profile'], $_POST['csrf'] ?? '')
) {
    try {
        $st = $conn->prepare("
            UPDATE user_notifications
            SET is_read = 1
            WHERE user_id = ? AND is_read = 0
        ");

        $st->bind_param("i", $uid);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        // Continue
    }

    try {
        $st = $conn->prepare("
            INSERT INTO notification_user_reads (notification_id, user_id)
            SELECT id, ?
            FROM notifications
            WHERE audience IN " . ADOPTER_AUDIENCES . "
            ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP
        ");

        $st->bind_param("i", $uid);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        // Continue
    }

    header(
        'Location: notifications.php' .
        ($filter === 'unread' ? '?f=unread' : '')
    );
    exit();
}

/* PAGINATION */
$perPage = 15;
$page = max(1, (int) ($_GET['p'] ?? 1));

$all = [];

/* 1. PERSONAL NOTIFICATIONS */
try {
    $st = $conn->prepare("
        SELECT id, message, link, is_read, created_at
        FROM user_notifications
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 500
    ");

    $st->bind_param("i", $uid);
    $st->execute();

    $rs = $st->get_result();

    while ($r = $rs->fetch_assoc()) {
        $all[] = [
            'src'        => 'p',
            'id'         => (int) $r['id'],
            'title'      => '',
            'message'    => $r['message'],
            'is_read'    => (int) $r['is_read'],
            'created_at' => $r['created_at']
        ];
    }

    $st->close();
} catch (Throwable $e) {
    // Continue loading announcements
}

/* 2. ADMIN ANNOUNCEMENTS: EVERYONE + ADOPTERS */
try {
    $st = $conn->prepare("
        SELECT
            n.id,
            n.title,
            n.message,
            n.created_at,
            CASE
                WHEN r.id IS NULL THEN 0
                ELSE 1
            END AS is_read
        FROM notifications n
        LEFT JOIN notification_user_reads r
            ON r.notification_id = n.id
            AND r.user_id = ?
        WHERE n.audience IN " . ADOPTER_AUDIENCES . "
        ORDER BY n.created_at DESC
        LIMIT 500
    ");

    $st->bind_param("i", $uid);
    $st->execute();

    $rs = $st->get_result();

    while ($r = $rs->fetch_assoc()) {
        $all[] = [
            'src'        => 'b',
            'id'         => (int) $r['id'],
            'title'      => $r['title'],
            'message'    => $r['message'],
            'is_read'    => (int) $r['is_read'],
            'created_at' => $r['created_at']
        ];
    }

    $st->close();
} catch (Throwable $e) {
    // Continue
}

/* SORT NEWEST FIRST */
usort($all, function ($a, $b) {
    $t = strtotime($b['created_at']) <=> strtotime($a['created_at']);

    return $t !== 0 ? $t : ($b['id'] <=> $a['id']);
});

/* COUNT NOTIFICATIONS */
$total = count($all);
$unread = 0;

foreach ($all as $row) {
    if ($row['is_read'] === 0) {
        $unread++;
    }
}

/* APPLY FILTER */
$list = ($filter === 'unread')
    ? array_values(array_filter($all, function ($r) {
        return $r['is_read'] === 0;
    }))
    : $all;

/* PAGINATION DETAILS */
$shown = count($list);
$pages = max(1, (int) ceil($shown / $perPage));
$page = min($page, $pages);

$items = array_slice(
    $list,
    ($page - 1) * $perPage,
    $perPage
);

function nt_url(string $filter, int $p): string
{
    $q = [];

    if ($filter === 'unread') {
        $q[] = 'f=unread';
    }

    if ($p > 1) {
        $q[] = 'p=' . $p;
    }

    return 'notifications.php' . ($q ? '?' . implode('&', $q) : '');
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PAWFINDER - My Notifications</title>

    <link rel="stylesheet" href="css/homepage.css">
    <link rel="stylesheet" href="css/theme.css">

    <style>
        .nt-wrap {
            max-width: 820px;
            margin: 0 auto;
        }

        .nt-title {
            font-size: 26px;
            margin: 0 0 4px;
            color: #0f172a;
        }

        .nt-sub {
            margin: 0 0 20px;
            color: #64748b;
            font-size: 14px;
        }

        .nt-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .nt-stat {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .nt-stat b {
            display: block;
            font-size: 26px;
            color: #0b5a6b;
        }

        .nt-stat span {
            font-size: 13px;
            color: #64748b;
        }

        .nt-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .nt-tabs {
            display: flex;
            gap: 8px;
        }

        .nt-tab {
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 16px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #d5e3e7;
            color: #0b3f4b;
        }

        .nt-tab.active {
            background: #0b5a6b;
            border-color: #0b5a6b;
            color: #fff;
        }

        .nt-markall {
            background: #fff;
            border: 1px solid #d5e3e7;
            border-radius: 8px;
            padding: 7px 14px;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            color: #0b5a6b;
            cursor: pointer;
        }

        .nt-markall:hover {
            background: #f0fdfa;
        }

        .nt-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .nt-item {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            text-decoration: none;
            color: #0f172a;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .nt-item:hover {
            border-color: #0d9488;
            box-shadow: 0 2px 10px rgba(13, 148, 136, .12);
        }

        .nt-item.unread {
            background: #f0fdfa;
            border-color: #99e6da;
        }

        .nt-ico {
            flex: 0 0 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            background: #e2e8f0;
            color: #475569;
        }

        .nt-ico.approved {
            background: #dcfce7;
            color: #166534;
        }

        .nt-ico.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .nt-body {
            flex: 1;
            min-width: 0;
        }

        .nt-msg {
            font-size: 15px;
            line-height: 1.45;
        }

        .nt-item.unread .nt-msg {
            font-weight: 700;
        }

        .nt-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 2px;
        }

        .nt-head b {
            font-size: 15px;
        }

        .nt-tag {
            font-size: 11px;
            font-weight: 700;
            background: #dcfce7;
            color: #166534;
            border-radius: 999px;
            padding: 2px 9px;
        }

        .nt-time {
            font-size: 12px;
            color: #8a9aa0;
            margin-top: 4px;
        }

        .nt-new {
            flex: 0 0 auto;
            font-size: 11px;
            font-weight: 700;
            background: #0d9488;
            color: #fff;
            border-radius: 999px;
            padding: 2px 10px;
            align-self: center;
        }

        .nt-empty {
            background: #fff;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            color: #64748b;
        }

        .nt-pages {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            margin-top: 18px;
            font-size: 14px;
        }

        .nt-pages a {
            color: #0d9488;
            font-weight: 600;
            text-decoration: none;
        }

        @media(max-width: 600px) {
            .nt-item {
                padding: 12px;
                gap: 10px;
            }

            .nt-new {
                font-size: 10px;
            }
        }
    </style>
</head>

<body>

    <script src="js/darkmode.js"></script>

    <?php include 'header.php'; ?>

    <main class="content-area">

        <div class="nt-wrap">

            <h1 class="nt-title">My Notifications</h1>

            <p class="nt-sub">
                Updates on your adoption requests, weekly updates, and announcements from Pawfinder.
            </p>

            <div class="nt-stats">

                <div class="nt-stat">
                    <b><?= (int) $unread ?></b>
                    <span>Unread</span>
                </div>

                <div class="nt-stat">
                    <b><?= (int) $total ?></b>
                    <span>Total notifications</span>
                </div>

            </div>

            <div class="nt-bar">

                <div class="nt-tabs">

                    <a
                        class="nt-tab <?= $filter === 'all' ? 'active' : '' ?>"
                        href="notifications.php">
                        All
                    </a>

                    <a
                        class="nt-tab <?= $filter === 'unread' ? 'active' : '' ?>"
                        href="notifications.php?f=unread">
                        Unread
                    </a>

                </div>

                <?php if ($unread > 0): ?>

                    <form method="POST" action="<?= h(nt_url($filter, $page)) ?>">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= h($_SESSION['csrf_profile']) ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="mark_all">

                        <button type="submit" class="nt-markall">
                            Mark all as read
                        </button>

                    </form>

                <?php endif; ?>

            </div>

            <?php if ($items): ?>

                <div class="nt-list">

                    <?php foreach ($items as $n):

                        $isAnnouncement = ($n['src'] === 'b');

                        $item_kind = $isAnnouncement
                            ? 'info'
                            : nt_kind($n['message']);

                        $href = $isAnnouncement
                            ? 'notifications.php?open_b=' . (int) $n['id']
                            : 'notifications.php?open=' . (int) $n['id'];

                    ?>

                        <a
                            class="nt-item <?= $n['is_read'] ? '' : 'unread' ?>"
                            href="<?= h($href) ?>">

                            <span
                                class="nt-ico <?= h($item_kind) ?>"
                                aria-hidden="true">

                                <?= $item_kind === 'approved'
                                    ? '&#10003;'
                                    : ($item_kind === 'rejected'
                                        ? '&#10005;'
                                        : '&#9679;') ?>

                            </span>

                            <span class="nt-body">

                                <?php if ($isAnnouncement): ?>

                                    <span class="nt-head">

                                        <b><?= h($n['title']) ?></b>

                                        <span class="nt-tag">
                                            Announcement
                                        </span>

                                    </span>

                                    <span class="nt-msg">
                                        <?= nl2br(h($n['message'])) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="nt-msg">
                                        <?= h($n['message']) ?>
                                    </span>

                                <?php endif; ?>

                                <div class="nt-time">
                                    <?= h(date(
                                        'M j, Y g:i A',
                                        strtotime($n['created_at'])
                                    )) ?>
                                </div>

                            </span>

                            <?php if (!$n['is_read']): ?>

                                <span class="nt-new">New</span>

                            <?php endif; ?>

                        </a>

                    <?php endforeach; ?>

                </div>

                <?php if ($pages > 1): ?>

                    <div class="nt-pages">

                        <?php if ($page > 1): ?>

                            <a href="<?= h(nt_url($filter, $page - 1)) ?>">
                                &larr; Newer
                            </a>

                        <?php endif; ?>

                        <span>
                            Page <?= (int) $page ?> of <?= (int) $pages ?>
                        </span>

                        <?php if ($page < $pages): ?>

                            <a href="<?= h(nt_url($filter, $page + 1)) ?>">
                                Older &rarr;
                            </a>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            <?php else: ?>

                <div class="nt-empty">

                    <?= $filter === 'unread'
                        ? 'You have no unread notifications.'
                        : 'You have no notifications yet.' ?>

                </div>

            <?php endif; ?>

        </div>

    </main>

</body>
</html>