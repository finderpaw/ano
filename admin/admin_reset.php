<?php
// admin_reset.php — PANSAMANTALA LANG. BURAHIN AGAD pagkatapos gamitin.
// Nire-reset nito ang password ng account na username = 'admin'.
// Gumagana lang kapag galing sa sariling computer (localhost).

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Localhost lang ito.');
}

$config = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config;

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = $_POST['new_password'] ?? '';
    if (strlen($new) < 5) {
        $msg = 'Masyadong maikli ang password (minimum 5).';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin' AND LOWER(role) = 'admin'");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $rows = $stmt->affected_rows;
        $stmt->close();

        // Linisin din ang mga bilang ng maling try
        try { $conn->query("DELETE FROM admin_login_attempts"); } catch (Throwable $e) {}

        $msg = $rows > 0
            ? 'Tapos na. Na-reset ang password ng "admin". BURAHIN na ang file na ito.'
            : 'Walang na-update. Baka pareho na ang password, o walang account na username "admin" na may role admin.';
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Reset admin password</title>
<style>body{font-family:Arial;margin:24px}input,button{padding:6px}</style></head>
<body>
<h2>Reset admin password</h2>
<?php if ($msg !== ''): ?><p><b><?= htmlspecialchars($msg) ?></b></p><?php endif; ?>
<form method="post">
    <input type="text" name="new_password" placeholder="bagong password" required>
    <button>I-reset</button>
</form>
<p style="color:#b00"><b>Burahin ang file na ito pagkatapos gamitin.</b></p>
</body></html>