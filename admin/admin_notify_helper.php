<?php
// admin_notify_helper.php - shared helpers for the admin "new weekly update" notifications

/* Adds admin_seen (and the review columns) to adoption_updates if missing.
   Old rows are marked as seen, so only NEW updates raise a notification. */
function an_ensure_columns(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $r = $conn->query("SHOW COLUMNS FROM adoption_updates LIKE 'admin_seen'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE adoption_updates ADD COLUMN admin_seen TINYINT(1) NOT NULL DEFAULT 1");
            $conn->query("ALTER TABLE adoption_updates ALTER COLUMN admin_seen SET DEFAULT 0");
        }
        $r = $conn->query("SHOW COLUMNS FROM adoption_updates LIKE 'needs_review'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE adoption_updates ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0");
        }
        $r = $conn->query("SHOW COLUMNS FROM adoption_updates LIKE 'review_reason'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE adoption_updates ADD COLUMN review_reason VARCHAR(255) NULL DEFAULT NULL");
        }
        $r = $conn->query("SHOW COLUMNS FROM adoption_updates LIKE 'ai_score'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE adoption_updates ADD COLUMN ai_score DECIMAL(4,3) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('admin_notify_helper: ' . $e->getMessage());
    }
}

/* Find the users table (the admin pages use "users"; the dashboard count uses "registered")
   and its id and name columns without guessing. */
function an_user_parts(mysqli $conn): array {
    static $parts = null;
    if ($parts !== null) return $parts;

    foreach (['users', 'registered'] as $table) {
        $cols = [];
        try {
            $r = $conn->query("SHOW COLUMNS FROM `$table`");
            while ($r && ($row = $r->fetch_assoc())) { $cols[] = $row['Field']; }
        } catch (Throwable $e) { $cols = []; }
        if (!$cols) continue;

        $id = in_array('id', $cols, true) ? 'id' : (in_array('user_id', $cols, true) ? 'user_id' : null);
        $name = null;
        foreach (['full_name', 'fullname', 'name', 'username', 'first_name', 'email'] as $n) {
            if (in_array($n, $cols, true)) { $name = $n; break; }
        }
        if ($id && $name) {
            $parts = [
                'join' => "LEFT JOIN `$table` r ON r.`$id` = u.user_id",
                'expr' => "r.`$name`",
            ];
            return $parts;
        }
    }

    $parts = ['join' => '', 'expr' => "CONCAT('User #', u.user_id)"];
    return $parts;
}

function an_time_ago($secs): string {
    $secs = (int)$secs;
    if ($secs < 60)    return 'just now';
    if ($secs < 3600)  return floor($secs / 60) . ' min ago';
    if ($secs < 86400) return floor($secs / 3600) . ' hr ago';
    return floor($secs / 86400) . ' day(s) ago';
}

/* Photos are saved relative to the site root (pawfinder/), the admin pages live in a subfolder */
function an_photo_url($p): string {
    $p = trim((string)$p);
    if ($p === '') return '';
    if (preg_match('#^(https?:)?//#i', $p)) return $p;
    return '../' . ltrim($p, '/');
}