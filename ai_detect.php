<?php
/**
 * Photo checks for the weekly update:
 *   1) AI-generated image detection (Sightengine "genai" model)
 *   2) Near-duplicate detection (perceptual hash, needs the PHP GD extension)
 *
 * Put these in config.php (NOT in this file):
 *   define('SIGHTENGINE_USER',   '...');
 *   define('SIGHTENGINE_SECRET', '...');
 *
 * Optional settings (also in config.php):
 *   define('AI_REJECT_THRESHOLD', 0.85);  // AI score at/above this = rejected
 *   define('AI_REVIEW_THRESHOLD', 0.40);  // AI score at/above this = flagged "Needs review"
 *   define('DUP_REJECT_DIST', 4);         // hash distance at/below this = rejected as duplicate
 *   define('DUP_REVIEW_DIST', 10);        // hash distance at/below this = flagged "Needs review"
 */

if (!defined('AI_REJECT_THRESHOLD')) define('AI_REJECT_THRESHOLD', 0.85);
if (!defined('AI_REVIEW_THRESHOLD')) define('AI_REVIEW_THRESHOLD', 0.40);
if (!defined('DUP_REJECT_DIST'))     define('DUP_REJECT_DIST', 4);
if (!defined('DUP_REVIEW_DIST'))     define('DUP_REVIEW_DIST', 10);

/* ============================================================
   1) AI-GENERATED CHECK
============================================================ */

/**
 * Returns a score from 0.0 to 1.0 (higher = more likely AI-generated),
 * or null if the check could not be done (no keys, no internet, API error).
 * The reason is written to the PHP error log.
 */
function ai_image_score(string $path): ?float
{
    if (!defined('SIGHTENGINE_USER') || !defined('SIGHTENGINE_SECRET')
        || SIGHTENGINE_USER === '' || SIGHTENGINE_SECRET === '') {
        error_log('ai_image_score: Sightengine keys are not set in config.php');
        return null;
    }
    if (!function_exists('curl_init')) {
        error_log('ai_image_score: PHP cURL extension is not enabled');
        return null;
    }

    $ch = curl_init('https://api.sightengine.com/1.0/check.json');
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => [
            'media'      => new CURLFile($path),
            'models'     => 'genai',
            'api_user'   => SIGHTENGINE_USER,
            'api_secret' => SIGHTENGINE_SECRET,
        ],
    ];

    // Local XAMPP often has no CA bundle configured, which makes HTTPS calls fail.
    // This relaxes the check ONLY when running on localhost.
    $host = $_SERVER['SERVER_NAME'] ?? '';
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);

    if ($res === false) {
        error_log('ai_image_score: cURL error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $data = json_decode((string)$res, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        error_log('ai_image_score: unexpected API response: ' . substr((string)$res, 0, 500));
        return null;
    }

    $score = $data['type']['ai_generated'] ?? null;
    if ($score === null) {
        error_log('ai_image_score: ai_generated score not found in response: ' . substr((string)$res, 0, 500));
        return null;
    }

    return (float)$score;
}

/* ============================================================
   2) NEAR-DUPLICATE CHECK (perceptual "difference hash")
============================================================ */

function _dh_gray($im, int $x, int $y): int
{
    $c = imagecolorat($im, $x, $y);
    return (int)(0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255));
}

/**
 * 64-bit difference hash as 16 hex characters, or null if it cannot be made.
 * Similar-looking images get hashes with a small hamming distance.
 */
function image_dhash(string $path): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        error_log('image_dhash: PHP GD extension is not enabled');
        return null;
    }
    $data = @file_get_contents($path);
    if ($data === false) return null;

    $img = @imagecreatefromstring($data);
    if (!$img) return null;

    $small = imagecreatetruecolor(9, 8);
    imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
    imagedestroy($img);

    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $bits .= (_dh_gray($small, $x, $y) > _dh_gray($small, $x + 1, $y)) ? '1' : '0';
        }
    }
    imagedestroy($small);

    $hex = '';
    foreach (str_split($bits, 4) as $nibble) {
        $hex .= dechex(bindec($nibble));
    }
    return $hex;
}

/** Number of different bits between two 16-char hashes (0 = identical, 64 = opposite). */
function hash_distance(string $a, string $b): int
{
    static $pop = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
    if (strlen($a) !== 16 || strlen($b) !== 16) return 64;

    $d = 0;
    for ($i = 0; $i < 16; $i++) {
        $d += $pop[hexdec($a[$i]) ^ hexdec($b[$i])];
    }
    return $d;
}

/**
 * Compare a new photo's hash with earlier weekly updates (from any adopter)
 * and with the pet's own listing photo.
 * Returns ['dist' => int, 'what' => string] for the closest match, or null.
 */
function find_similar_photo(mysqli $conn, string $hash, string $petPhotoRel = ''): ?array
{
    $best = null;

    try {
        $res = $conn->query("SELECT id, img_hash FROM adoption_updates WHERE img_hash IS NOT NULL ORDER BY id DESC LIMIT 1000");
        while ($res && ($row = $res->fetch_assoc())) {
            $d = hash_distance($hash, (string)$row['img_hash']);
            if ($best === null || $d < $best['dist']) {
                $best = ['dist' => $d, 'what' => 'update #' . (int)$row['id']];
            }
        }
    } catch (Throwable $e) {
        error_log('find_similar_photo: ' . $e->getMessage());
    }

    if ($petPhotoRel !== '') {
        $file = __DIR__ . '/' . ltrim($petPhotoRel, '/\\');
        if (is_file($file)) {
            $ph = image_dhash($file);
            if ($ph !== null) {
                $d = hash_distance($hash, $ph);
                if ($best === null || $d < $best['dist']) {
                    $best = ['dist' => $d, 'what' => "the pet's listing photo"];
                }
            }
        }
    }

    return $best;
}