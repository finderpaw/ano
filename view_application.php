 <?php

// admin/view_application.php

// Ipinapakita ng admin ang buong adoption application ng isang request.

// Dito rin ligtas na ipinapakita ang ID at home photo (naka-block ang uploads/ids sa direktang access).

require_once __DIR__ . '/admin_guard.php';


$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';

require_once $config_file;


function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }


$reqId = (int)($_GET['request_id'] ?? $_GET['id'] ?? 0);

if ($reqId <= 0) {

    header('Location: adoption_requests.php');

    exit();

}


/* ---------- Request + pet + user ---------- */

$st = $conn->prepare(

    "SELECT r.id, r.status, r.created_at, r.pet_id, r.user_id,

            p.pet_name, p.pet_species, p.breed, p.pet_photo, p.status AS pet_status,

            u.fullname, u.username, u.email, u.contact, u.address

     FROM adoption_requests r

     LEFT JOIN pets  p ON p.id = r.pet_id

     LEFT JOIN users u ON u.id = r.user_id

     WHERE r.id = ?"

);

$st->bind_param("i", $reqId);

$st->execute();

$req = $st->get_result()->fetch_assoc();

$st->close();


if (!$req) {

    http_response_code(404);

    echo 'Adoption request not found. <a href="adoption_requests.php">Back</a>';

    exit();

}


/* ---------- Application (kung may form) ---------- */

$app = null;

try {

    $st = $conn->prepare("SELECT * FROM adoption_applications WHERE request_id = ? ORDER BY id DESC LIMIT 1");

    $st->bind_param("i", $reqId);

    $st->execute();

    $app = $st->get_result()->fetch_assoc();

    $st->close();

} catch (Throwable $e) {

    $app = null;

}


/* ---------- Ligtas na pag-serve ng ID / home photo ---------- */

if ($app && isset($_GET['file'])) {

    $name = ($_GET['file'] === 'home') ? (string)$app['home_photo'] : (string)$app['id_file'];


    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $name)) {

        http_response_code(404);

        exit('File not found.');

    }

    $path = realpath(__DIR__ . '/../uploads/ids/' . $name);

    $base = realpath(__DIR__ . '/../uploads/ids');

    if (!$path || !$base || strpos($path, $base) !== 0 || !is_file($path)) {

        http_response_code(404);

        exit('File not found.');

    }

    $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

    $ext   = pathinfo($name, PATHINFO_EXTENSION);

    header('Content-Type: ' . $mimes[$ext]);

    header('Content-Length: ' . filesize($path));

    header('X-Content-Type-Options: nosniff');

    header('Cache-Control: private, no-store');

    header('Content-Disposition: inline; filename="' . $name . '"');

    readfile($path);

    exit();

}


/* ---------- Ihanda ang data ---------- */

$answers = [];

$flags   = [];

if ($app) {

    $answers = json_decode((string)$app['answers'], true) ?: [];

    $flags   = json_decode((string)$app['flags'], true) ?: [];

}

$sections = [];

foreach ($answers as $a) {

    $sections[$a['s'] ?? 'Other'][] = $a;

}


$petPhoto = (string)($req['pet_photo'] ?? '');

if ($petPhoto !== '' && !preg_match('#^(https?:)?//#', $petPhoto) && $petPhoto[0] !== '/') {

    $petPhoto = '../' . ltrim($petPhoto, './');

}


$status      = (string)$req['status'];

$statusClass = strtolower($status);

$idIsPdf     = $app && strtolower(pathinfo((string)$app['id_file'], PATHINFO_EXTENSION)) === 'pdf';

$self        = 'view_application.php?request_id=' . (int)$reqId;

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Adoption Application #<?= (int)$reqId ?>Pawfinder Admin 

</title>

<style>

    * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; }

    body { background: #f8fafc; color: #0f172a; padding: 30px 20px; }

    .wrap { max-width: 900px; margin: 0 auto; }

    .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; font-size: 14px; }

    .top a { color: #0d9488; font-weight: 600; text-decoration: none; }

    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; margin-bottom: 18px; }

    h1 { font-size: 22px; margin-bottom: 4px; }

    h2 { font-size: 15px; color: #0d9488; margin-bottom: 12px; text-transform: uppercase; letter-spacing: .04em; }

    .head { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }

    .head img { width: 84px; height: 84px; object-fit: cover; border-radius: 12px; border: 1px solid #e2e8f0; }

    .muted { color: #64748b; font-size: 13px; }

    .badge { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; background: #e2e8f0; color: #475569; }

    .badge.pending  { background: #fef3c7; color: #92400e; }

    .badge.approved { background: #dcfce7; color: #166534; }

    .badge.rejected { background: #fee2e2; color: #991b1b; }

    .flags { background: #fff7ed; border: 1px solid #fed7aa; }

    .flags h2 { color: #c2410c; }

    .flags ul { margin-left: 18px; font-size: 14px; line-height: 1.7; color: #7c2d12; }

    .noflags { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; font-size: 14px; }

    table { width: 100%; border-collapse: collapse; font-size: 14px; }

    td { padding: 8px 6px; border-top: 1px solid #f1f5f9; vertical-align: top; }

    tr:first-child td { border-top: 0; }

    td.q { width: 42%; color: #64748b; }

    td.a { color: #0f172a; word-break: break-word; white-space: pre-wrap; }

    .idbox img { max-width: 100%; max-height: 520px; border: 1px solid #e2e8f0; border-radius: 10px; display: block; }

    .btn { display: inline-block; text-decoration: none; background: #0d9488; color: #fff; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; }

    .empty { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 14px; line-height: 1.6; }

    .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; }

</style>

</head>

<body>

<div class="wrap">


    <div class="top">

        <a href="adoption_requests.php">&larr; Back to Adoption Requests</a>

        <a href="admin.php">Dashboard</a>

    </div>


    <!-- HEADER -->

    <div class="card">

        <div class="head">

            <?php if ($petPhoto !== ''): ?><img src="<?= h($petPhoto) ?>" alt=""><?php endif; ?>

            <div style="flex:1;min-width:200px">

                <h1>Adoption Application #<?= (int)$reqId ?></h1>

                <p class="muted">

                    For <b><?= h($req['pet_name'] ?? 'Unknown pet') ?></b>

                    (<?= h(($req['pet_species'] ?? '') . ', ' . ($req['breed'] ?? '')) ?>)

                    &middot; Submitted <?= h(date('M j, Y g:i A', strtotime($req['created_at']))) ?>

                </p>

            </div>

            <span class="badge <?= h($statusClass) ?>"><?= h($status) ?></span>

        </div>

    </div>


    <!-- APPLICANT (galing sa account) -->

    <div class="card">

        <h2>Applicant account</h2>

        <table>

            <tr><td class="q">Name</td><td class="a"><?= h($req['fullname'] ?? '') ?></td></tr>

            <tr><td class="q">Username</td><td class="a"><?= h($req['username'] ?? '') ?></td></tr>

            <tr><td class="q">Email</td><td class="a"><?= h($req['email'] ?? '') ?></td></tr>

            <tr><td class="q">Contact</td><td class="a"><?= h($req['contact'] ?? '') ?></td></tr>

            <tr><td class="q">Address</td><td class="a"><?= h($req['address'] ?? '') ?></td></tr>

        </table>

    </div>


<?php if (!$app): ?>


    <div class="card empty">

        <b>Walang application form para sa request na ito.</b><br>

        Malamang ipinadala ito bago pa nagkaroon ng adoption application form (direktang request lang),

        kaya wala pang sagot, ID, o signature na naka-save.

    </div>


<?php else: ?>


    <!-- RED FLAGS -->

    <?php if ($flags): ?>

        <div class="card flags">

            <h2>Red flags (<?= count($flags) ?>)</h2>

            <ul><?php foreach ($flags as $f) echo '<li>' . h($f) . '</li>'; ?></ul>

        </div>

    <?php else: ?>

        <div class="card noflags"><b>No red flags</b> sa mga sagot ng applicant.</div>

    <?php endif; ?>


    <!-- MGA SAGOT -->

    <?php foreach ($sections as $title => $rows): ?>

        <div class="card">

            <h2><?= h($title) ?></h2>

            <table>

                <?php foreach ($rows as $r): ?>

                    <tr><td class="q"><?= h($r['q'] ?? '') ?></td><td class="a"><?= h($r['a'] ?? '') ?></td></tr>

                <?php endforeach; ?>

            </table>

        </div>

    <?php endforeach; ?>


    <!-- ID AT PHOTO -->

    <div class="card">

        <h2>Valid ID (<?= h($app['id_type']) ?>)</h2>

        <div class="idbox">

            <?php if ($idIsPdf): ?>

                <a class="btn" href="<?= h($self) ?>&amp;file=id" target="_blank" rel="noopener">Open ID (PDF)</a>

            <?php else: ?>

                <a href="<?= h($self) ?>&amp;file=id" target="_blank" rel="noopener">

                    <img src="<?= h($self) ?>&amp;file=id" alt="Applicant ID">

                </a>

            <?php endif; ?>

        </div>

        <p class="muted" style="margin-top:10px">Kumpirmahin na tugma ang pangalan sa ID at sa account: <b><?= h($req['fullname'] ?? '') ?></b></p>

    </div>


    <?php if (!empty($app['home_photo'])): ?>

        <div class="card">

            <h2>Photo of where the pet will stay</h2>

            <div class="idbox">

                <a href="<?= h($self) ?>&amp;file=home" target="_blank" rel="noopener">

                    <img src="<?= h($self) ?>&amp;file=home" alt="Home photo">

                </a>

            </div>

        </div>

    <?php endif; ?>


    <!-- SIGNATURE -->

    <div class="card">

        <h2>Agreement signature</h2>

        <table>

            <tr><td class="q">Typed signature</td><td class="a"><?= h($app['signature_name']) ?></td></tr>

            <tr><td class="q">Signed at</td><td class="a"><?= h(date('M j, Y g:i A', strtotime($app['agreed_at']))) ?></td></tr>

            <tr><td class="q">IP address</td><td class="a"><?= h($app['ip_address']) ?></td></tr>

        </table>

    </div>


<?php endif; ?>


    <?php if ($status === 'Pending'): ?>

        <div class="card">

            <h2>Decision</h2>

            <p class="muted" style="margin-bottom:12px">Suriin muna ang mga sagot, red flags, at ID bago magdesisyon.</p>

            <div style="display:flex;gap:10px;flex-wrap:wrap">

                <form method="post" action="notification.php" onsubmit="return confirm('Approve this adoption? Other pending requests for this pet will be rejected.')">

                    <input type="hidden" name="action" value="approve">

                    <input type="hidden" name="request_id" value="<?= (int)$reqId ?>">

                    <button type="submit" class="btn" style="border:0;cursor:pointer">Approve</button>

                </form>

                <form method="post" action="notification.php" onsubmit="return confirm('Reject this request?')">

                    <input type="hidden" name="action" value="reject">

                    <input type="hidden" name="request_id" value="<?= (int)$reqId ?>">

                    <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;border:0;cursor:pointer">Reject</button>

                </form>

            </div>

        </div>

    <?php endif; ?>


    <div class="top" style="margin-top:6px">



        <a href="notification.php<?= $status === 'Pending' ? '?s=Pending&focus=' . (int)$reqId : '' ?>">&larr; Back to Notifications</a>

       



        <a href="adoption_requests.php<?= $status === 'Pending' ? '?s=Pending&focus=' . (int)$reqId : '' ?>">&larr; Back to Adoption Requests</a>

        <a href="notification.php">Notifications</a>



    </div>


</div>

</body>

</html> 