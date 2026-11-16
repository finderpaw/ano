<?php
// notif_count.php - binibilang ang unread notifications ng naka-login na user
// Parehong logic ng notifications.php (personal + announcements) para magtugma ang bilang.

function pf_unread_count($conn, int $uid): int
{
    $count = 0;

    // 1) Personal notifications
    try {
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM user_notifications WHERE user_id = ? AND is_read = 0");
        $st->bind_param("i", $uid);
        $st->execute();
        $count += (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
    } catch (Throwable $e) { }

    // 2) Announcements (hindi pa nababasa ng user)
    try {
        $st = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM notifications n
            LEFT JOIN notification_user_reads r
                   ON r.notification_id = n.id AND r.user_id = ?
            WHERE n.audience IN ('all', 'adopter', 'user')
              AND r.id IS NULL
        ");
        $st->bind_param("i", $uid);
        $st->execute();
        $count += (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
    } catch (Throwable $e) { }

    return $count;
}