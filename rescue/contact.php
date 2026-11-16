<?php
require_once __DIR__ . '/layout.php';

/* Table that stores the rescuer team's contact details (editable) */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rescue_contact_info (
    field_key   VARCHAR(50) PRIMARY KEY,
    field_value TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

/* The fields that can be edited: key => [label, type, default, hint] */
$fields = [
    'hotline'   => ['Emergency rescue hotline', 'tel',   '',                              'Used for injured or urgent animals.'],
    'phone'     => ['Contact number',           'tel',   '',                              ''],
    'email'     => ['Email address',            'email', '',                              ''],
    'address'   => ['Rescue base / address',    'text',  'Clark College, Angeles City',   ''],
    'hours'     => ['Office hours',             'text',  'Mon - Sat, 8:00 AM - 5:00 PM',  ''],
    'facebook'  => ['Facebook link',            'url',   '',                              'Must start with https://'],
    'instagram' => ['Instagram link',           'url',   '',                              'Must start with https://'],
    'tiktok'    => ['TikTok link',              'url',   '',                              'Must start with https://'],
    'note'      => ['Note for adopters',        'text',  'For injured animals, please call the hotline first.', 'Short message shown with the details.'],
];

/* Add the default rows once */
$ins = mysqli_prepare($conn, "INSERT IGNORE INTO rescue_contact_info (field_key, field_value) VALUES (?, ?)");
foreach ($fields as $key => $f) {
    mysqli_stmt_bind_param($ins, "ss", $key, $f[2]);
    mysqli_stmt_execute($ins);
}
mysqli_stmt_close($ins);

if (empty($_SESSION['rc_csrf'])) {
    $_SESSION['rc_csrf'] = bin2hex(random_bytes(16));
}

$flash = $_SESSION['rc_flash'] ?? null;
unset($_SESSION['rc_flash']);

/* ---------- SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_contact') {
    $msg = ['type' => 'err', 'text' => 'Something went wrong. Please try again.'];

    if (!hash_equals($_SESSION['rc_csrf'], $_POST['csrf'] ?? '')) {
        $msg['text'] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $clean = [];
        $error = '';

        foreach ($fields as $key => $f) {
            $val = trim($_POST[$key] ?? '');
            if (mb_strlen($val) > 255) {
                $error = $f[0] . ' is too long.';
                break;
            }
            if ($val !== '') {
                if ($f[1] === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address.';
                    break;
                }
                if ($f[1] === 'url' && !preg_match('#^https?://#i', $val)) {
                    $error = $f[0] . ' must start with http:// or https://';
                    break;
                }
                if ($f[1] === 'tel' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $val)) {
                    $error = 'Please enter a valid number for ' . strtolower($f[0]) . '.';
                    break;
                }
            }
            $clean[$key] = $val;
        }

        if ($error !== '') {
            $msg['text'] = $error;
        } else {
            $up = mysqli_prepare($conn, "INSERT INTO rescue_contact_info (field_key, field_value) VALUES (?, ?)
                                         ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)");
            foreach ($clean as $key => $val) {
                mysqli_stmt_bind_param($up, "ss", $key, $val);
                mysqli_stmt_execute($up);
            }
            mysqli_stmt_close($up);
            $msg = ['type' => 'ok', 'text' => 'Contact details saved.'];
        }
    }

    $_SESSION['rc_flash'] = $msg;
    header('Location: contact.php');
    exit;
}

/* ---------- LOAD ---------- */
$info = [];
$res = mysqli_query($conn, "SELECT field_key, field_value FROM rescue_contact_info");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $info[$r['field_key']] = $r['field_value'];
    }
}
foreach ($fields as $key => $f) {
    if (!isset($info[$key])) $info[$key] = $f[2];
}

rescuer_head('contact', 'Contact');
rescuer_top('Contact', "Manage the rescue team's contact details");
?>
<style>
    .rc-list { list-style: none; margin: 0; padding: 0; }
    .rc-list li { padding: 11px 0; border-bottom: 1px solid #dfe5ea; font-size: 14px; word-break: break-word; }
    .rc-list li:last-child { border-bottom: 0; }
    .rc-list small { display: block; font-size: 12px; color: #5d6b78; margin-bottom: 2px; }
    .rc-list .rc-empty { opacity: .55; font-style: italic; }
    .rc-list .rc-hotline { font-size: 18px; font-weight: 700; }
    .rc-hint { font-size: 11.5px; opacity: .8; }
    .rc-actions { display: flex; gap: 10px; margin-top: 4px; }

    body.dark-mode .rc-list li { border-color: #26343d; }
</style>

        <?php if ($flash): ?>
            <div class="alert <?php echo $flash['type'] === 'ok' ? 'ok' : 'err'; ?>">
                <?php echo e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <div class="settings-grid">

            <!-- CURRENT DETAILS -->
            <section class="settings-section">
                <h3>Current contact details</h3>

                <ul class="rc-list">
                    <?php foreach ($fields as $key => $f):
                        $val = $info[$key] ?? '';
                    ?>
                        <li>
                            <small><?php echo e($f[0]); ?></small>
                            <?php if ($val === ''): ?>
                                <span class="rc-empty">Not set</span>
                            <?php elseif ($f[1] === 'url'): ?>
                                <a href="<?php echo e($val); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($val); ?></a>
                            <?php elseif ($key === 'hotline'): ?>
                                <span class="rc-hotline"><?php echo e($val); ?></span>
                            <?php else: ?>
                                <?php echo e($val); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <!-- EDIT FORM -->
            <section class="settings-section">
                <h3>Edit contact details</h3>

                <form method="post">
                    <input type="hidden" name="action" value="save_contact">
                    <input type="hidden" name="csrf" value="<?php echo e($_SESSION['rc_csrf']); ?>">

                    <?php foreach ($fields as $key => $f): ?>
                        <label class="field">
                            <?php echo e($f[0]); ?>
                            <input
                                type="<?php echo e($f[1]); ?>"
                                name="<?php echo e($key); ?>"
                                maxlength="255"
                                value="<?php echo e($info[$key] ?? ''); ?>"
                            >
                            <?php if ($f[3] !== ''): ?>
                                <span class="rc-hint"><?php echo e($f[3]); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>

                    <div class="rc-actions">
                        <button type="submit" class="btn">Save changes</button>
                        <a href="contact.php" class="btn">Reset</a>
                    </div>
                </form>
            </section>

        </div>
<?php rescuer_foot(); ?>