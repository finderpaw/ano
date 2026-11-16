<?php
// weekly_helper.php - checks which approved adoptions need a weekly update
if (!defined('WU_INTERVAL_DAYS')) define('WU_INTERVAL_DAYS', 7);

/**
 * Returns one row per approved adoption of the user:
 * request_id, pet_id, pet_name, last_update, days_since, days_left, due
 */
function wu_updates_status(mysqli $conn, int $uid): array
{
    $out = [];
    try {
        $st = $conn->prepare(
            "SELECT r.id AS request_id, r.pet_id, p.pet_name,
                    (SELECT MAX(u.created_at) FROM adoption_updates u WHERE u.request_id = r.id) AS last_update
             FROM adoption_requests r
             LEFT JOIN pets p ON p.id = r.pet_id
             WHERE r.user_id = ? AND LOWER(r.status) = 'approved'
             ORDER BY r.id DESC"
        );
        $st->bind_param("i", $uid);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        foreach ($rows as $r) {
            $last      = $r['last_update'] ? strtotime($r['last_update']) : null;
            $daysSince = $last === null ? null : (int)floor((time() - $last) / 86400);
            $due       = ($last === null) || ($daysSince >= WU_INTERVAL_DAYS);

            $out[] = [
                'request_id'  => (int)$r['request_id'],
                'pet_id'      => (int)$r['pet_id'],
                'pet_name'    => $r['pet_name'] ?? 'your pet',
                'last_update' => $last,
                'days_since'  => $daysSince,
                'days_left'   => $due ? 0 : WU_INTERVAL_DAYS - $daysSince,
                'due'         => $due,
            ];
        }
    } catch (Throwable $e) {
        // adoption_updates table does not exist yet: do not crash
    }
    return $out;
}

function wu_alert_text(array $d): string
{
    if ($d['last_update'] === null) {
        return 'Please send your first photo update of ' . $d['pet_name'] . ' so the admin knows it is safe and well.';
    }
    return 'Your last update of ' . $d['pet_name'] . ' was ' . (int)$d['days_since'] . ' days ago. Please send this week\'s photo update.';
}