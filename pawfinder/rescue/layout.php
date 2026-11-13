
<?php
/* Shared by overview.php, reports.php, notifications.php */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['logged_in']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'rescuer'
) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../config.php';

$root = '../';

$rescuerUsername = $_SESSION['username'] ?? '';

/* -----------------------------------------
   RESCUE REPORT URGENCY
------------------------------------------ */

$URG_SQL = "CASE
    WHEN LOWER(pet_condition) REGEXP 'injur|sick|critical|bleed|wound|hit|accident|dying'
        THEN 'High'
    WHEN LOWER(pet_condition) REGEXP 'abandon|stray|lost|hungry|malnourish|trapped'
        THEN 'Medium'
    ELSE 'Low'
END";


/* -----------------------------------------
   HELPER FUNCTIONS
------------------------------------------ */

function count_rows($conn, $sql) {

    $r = mysqli_query($conn, $sql);

    if (!$r) {
        return 0;
    }

    $row = mysqli_fetch_row($r);

    return (int)($row[0] ?? 0);
}


function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


function pill_class($value) {

    $v = strtolower($value);

    if ($v === 'high' || $v === 'pending') {
        return 'pill red';
    }

    if ($v === 'medium' || $v === 'on the way') {
        return 'pill amber';
    }

    return 'pill green';
}


/* -----------------------------------------
   ACCOUNT-SPECIFIC UNREAD NOTIFICATIONS
------------------------------------------ */

$safeUsername = mysqli_real_escape_string(
    $conn,
    $rescuerUsername
);

$unread_count = count_rows(
    $conn,
    "SELECT COUNT(*)
     FROM notifications n
     LEFT JOIN notification_reads nr
        ON nr.notification_id = n.id
        AND nr.rescuer_username = '$safeUsername'
     WHERE nr.id IS NULL"
);


/* -----------------------------------------
   RESCUER SIDEBAR
------------------------------------------ */

function rescuer_head($active, $title) {

    global $root, $unread_count;

    $items = [

        'overview' => [
            'overview.php',
            'Overview',
            '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'
        ],

        'reports' => [
            'reports.php',
            'Reports',
            '<path d="M12 21s-7-4.5-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.5-9 9-9 9z"/>'
        ],

        'notifications' => [
            'notifications.php',
            'Notifications',
            '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>'
        ],

    ];

    ?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>PAWFINDER - <?php echo e($title); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="stylesheet"
href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">

<link rel="stylesheet" href="../admin/admin.css">

<style>

.badge {
    margin-left: auto;
    background: #c0392b;
    color: #fff;
    border-radius: 10px;
    font-size: 11px;
    padding: 1px 7px;
    font-weight: 600;
}

.pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.pill.red {
    background: #fbe9e5;
    color: #b4442f;
}

.pill.amber {
    background: #fbf0d6;
    color: #9a6a10;
}

.pill.green {
    background: #e2f3ea;
    color: #1f7a4d;
}

.filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: 16px;
}

.filter-bar label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: #5d6b78;
}

.filter-bar select,
.filter-bar input {
    font: inherit;
    padding: 8px 12px;
    border: 1px solid #dfe5ea;
    border-radius: 8px;
    background: #fff;
}

.pct {
    font-size: 15px;
    color: #b4442f;
    font-weight: 600;
    margin-left: 8px;
}

.meter {
    height: 6px;
    border-radius: 4px;
    background: #dfe5ea;
    margin-top: 10px;
    overflow: hidden;
}

.meter i {
    display: block;
    height: 100%;
    background: #b4442f;
}

.tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.tabs a {
    padding: 6px 14px;
    border: 1px solid #dfe5ea;
    border-radius: 20px;
    text-decoration: none;
    color: inherit;
    font-size: 13px;
}

.tabs a.on {
    background: #0b5a6b;
    border-color: #0b5a6b;
    color: #fff;
}

.msg {
    display: flex;
    gap: 12px;
    padding: 14px 6px;
    border-bottom: 1px solid #dfe5ea;
}

.msg.unread {
    background: #f1f7f9;
}

.msg .body {
    flex: 1;
}

.msg .meta {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.msg time {
    margin-left: auto;
    font-size: 12px;
    color: #5d6b78;
}

.msg p {
    margin: 4px 0 0;
}

.alert {
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 14px;
    font-size: 13px;
}

.alert.ok {
    background: #e2f3ea;
    color: #1f7a4d;
}

.alert.err {
    background: #fbe9e5;
    color: #b4442f;
}

.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 16px;
    align-items: start;
}

.settings-section {
    background: #fff;
    border: 1px solid #dfe5ea;
    border-radius: 10px;
    padding: 18px 20px;
}

.settings-section h3 {
    margin: 0 0 14px;
    font-size: 15px;
}

.settings-section.danger h3 {
    color: #c0392b;
}

.settings-section.danger {
    border-color: #f0c9c2;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 12px;
    color: #5d6b78;
    margin-bottom: 12px;
}

.field input {
    font: inherit;
    font-size: 14px;
    padding: 9px 12px;
    border: 1px solid #dfe5ea;
    border-radius: 8px;
    background: #fff;
    color: inherit;
}

.field input:disabled {
    background: #f3f6f8;
}

.setting-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
}

.setting-title {
    font-weight: 600;
}

.setting-desc {
    font-size: 12.5px;
    color: #5d6b78;
    margin: 2px 0 12px;
}

.setting-row .setting-desc {
    margin-bottom: 0;
}

.btn-danger {
    background: #c0392b;
    border-color: #c0392b;
    color: #fff;
}

.switch {
    position: relative;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
}

.switch input {
    opacity: 0;
    width: 100%;
    height: 100%;
    position: absolute;
    margin: 0;
    cursor: pointer;
    z-index: 1;
}

.switch .slider {
    position: absolute;
    inset: 0;
    background: #c5ced6;
    border-radius: 24px;
    transition: background .2s;
}

.switch .slider:before {
    content: "";
    position: absolute;
    width: 18px;
    height: 18px;
    left: 3px;
    top: 3px;
    background: #fff;
    border-radius: 50%;
    transition: transform .2s;
}

.switch input:checked + .slider {
    background: #0b5a6b;
}

.switch input:checked + .slider:before {
    transform: translateX(20px);
}

.switch input:focus-visible + .slider {
    outline: 2px solid #0b5a6b;
    outline-offset: 2px;
}

/* DARK MODE */

body.dark-mode,
body.dark-mode .app,
body.dark-mode .main {
    background: #0f1519 !important;
    color: #e8eef2;
}

body.dark-mode .side {
    background: #162027 !important;
}

body.dark-mode .card,
body.dark-mode .panel,
body.dark-mode .settings-section {
    background: #131c22 !important;
    border-color: #26343d !important;
    color: #e8eef2;
}

body.dark-mode .filter-bar select,
body.dark-mode .filter-bar input,
body.dark-mode .field input {
    background: #0f1519;
    border-color: #26343d;
    color: #e8eef2;
}

body.dark-mode .field input:disabled {
    background: #1a252c;
}

body.dark-mode td,
body.dark-mode th,
body.dark-mode .msg {
    border-color: #26343d !important;
}

body.dark-mode .msg.unread {
    background: #17262d;
}

body.dark-mode small,
body.dark-mode .muted,
body.dark-mode .sub,
body.dark-mode .setting-desc,
body.dark-mode .field,
body.dark-mode .filter-bar label,
body.dark-mode .top p {
    color: #93a3b0 !important;
}

body.dark-mode h1,
body.dark-mode h2,
body.dark-mode h3,
body.dark-mode a {
    color: inherit;
}

body.dark-mode .tabs a {
    border-color: #26343d;
}

</style>

</head>

<body>

<div class="app">

    <aside class="side">

        <div class="brand">

            <img
                src="<?php echo $root; ?>image/logo2.jpg"
                alt="PAWFINDER logo"
                onerror="this.src='https://placehold.co/42x42/0b5a6b/ffffff?text=PF'"
            >

            <div>
                <b>PAWFINDER</b>
                <small>Community Pet System</small>
            </div>

        </div>

        <div class="group">MAIN MENU</div>

        <nav>

            <?php foreach ($items as $key => $it): ?>

                <a
                    href="<?php echo $it[0]; ?>"
                    <?php echo $active === $key ? 'class="active"' : ''; ?>
                >

                    <svg viewBox="0 0 24 24">
                        <?php echo $it[2]; ?>
                    </svg>

                    <?php echo $it[1]; ?>

                    <?php if ($key === 'notifications' && $unread_count > 0): ?>

                        <span class="badge">
                            <?php echo $unread_count; ?>
                        </span>

                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </nav>

        <hr>

        <div class="group">OTHER</div>

        <nav>

            <a href="settings.php"
                <?php echo $active === 'settings' ? 'class="active"' : ''; ?>
            >

                <svg viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
                </svg>

                Settings

            </a>

            <a href="../contact.php">

                <svg viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m3 7 9 6 9-6"/>
                </svg>

                Contact

            </a>

        </nav>

        <nav class="logout">

            <a
                href="../logout.php"
                onclick="return confirm('Are you sure you want to log out?');"
            >

                <svg viewBox="0 0 24 24">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                </svg>

                Log Out

            </a>

        </nav>

    </aside>

    <main class="main">

    <?php
}


/* -----------------------------------------
   TOP HEADER
------------------------------------------ */

function rescuer_top($heading, $sub) {

?>

    <div class="top">

        <div>

            <h1><?php echo e($heading); ?></h1>

            <p><?php echo e($sub); ?></p>

        </div>

        <div class="role">
            Active session role: <b>Rescuer</b>
        </div>

    </div>

<?php
}


/* -----------------------------------------
   FOOTER
------------------------------------------ */

function rescuer_foot() {

    echo "</main>\n</div>\n";

    echo "<script>
        if(localStorage.getItem('pawfinder_dark_mode') === 'enabled') {
            document.body.classList.add('dark-mode');
        }
    </script>\n";

    echo "</body>\n</html>";
}
