
<?php
session_start();
require_once 'config.php';

$loggedIn = !empty($_SESSION['logged_in']);
$role     = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

$roleLabels = [
    'visitor' => 'Visitor',
    'local' => 'Local',
    'adopter' => 'Adopter',
    'rescuer' => 'Local Rescuer',
    'admin' => 'Admin'
];

$roleLabel = $roleLabels[strtolower($role)] ?? ucfirst($role);

// Protect internal pages
function protectedLink($page, $loggedIn) {
    return $loggedIn
        ? $page
        : 'login.php?redirect=' . urlencode($page);
}

// Protected homepage links
$postLink   = protectedLink('add.php', $loggedIn);
$adoptLink  = protectedLink('adopt.php', $loggedIn);
$rescueLink = protectedLink('rescue.php', $loggedIn);
$healthLink = protectedLink('health_drives.php', $loggedIn);

// Become a Local Rescuer banner
$canRequest = false;
$reqStatus  = '';

if (
    $loggedIn &&
    !empty($_SESSION['user_id']) &&
    in_array(strtolower($role), ['local', 'visitor', 'adopter'], true)
) {
    try {
        $s = $conn->prepare("SELECT rescuer_status FROM users WHERE id = ?");
        $s->bind_param("i", $_SESSION['user_id']);
        $s->execute();
        $reqStatus = $s->get_result()->fetch_assoc()['rescuer_status'] ?? '';
        $s->close();

        $canRequest = true;
    } catch (Throwable $e) {
        $canRequest = false;
    }
}

$flash = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PAWFINDER - Community Pet System</title>

    <link rel="stylesheet" href="css/homepage.css">

    <style>
        .flash-msg {
            background: #e2f3ea;
            color: #1f7a4d;
            border: 1px solid #bfe3cf;
            padding: 12px 18px;
            border-radius: 10px;
            margin: 0 0 16px;
            font-size: 14px;
        }

        .role-line {
            margin: 0 0 20px;
            font-size: 14px;
            color: #4a6670;
        }

        .rescuer-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            background: #eef6f8;
            border: 1px solid #cfe3e8;
            border-left: 5px solid #0b5a6b;
            border-radius: 12px;
            padding: 16px 20px;
            margin: 0 0 24px;
        }

        .rescuer-banner h3 {
            margin: 0 0 4px;
            font-size: 16px;
            color: #0b3f4b;
        }

        .rescuer-banner p {
            margin: 0;
            font-size: 13.5px;
            color: #4a6670;
            max-width: 620px;
        }

        .rescuer-banner .rb-btn {
            background: #0b5a6b;
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 10px 18px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .rescuer-banner .rb-btn:hover {
            background: #094a58;
        }

        .rescuer-banner .rb-btn:focus-visible {
            outline: 2px solid #0b5a6b;
            outline-offset: 2px;
        }

        .rescuer-banner.pending {
            border-left-color: #d9a21b;
            background: #fdf6e3;
            border-color: #f1e1b0;
        }

        .rescuer-banner.pending h3 {
            color: #7a5a0b;
        }

        .rescuer-banner.pending p {
            color: #7d6a3c;
        }

        /* Rehome banner */
        .adopt-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            background: linear-gradient(135deg, #ccfbf1, #e0f2fe);
            border: 1px solid #99e6da;
            border-radius: 16px;
            padding: 28px 32px;
            margin: 0 0 32px;
        }

        .adopt-banner h2 {
            margin: 0 0 6px;
            font-size: 22px;
            color: #0f3d3a;
        }

        .adopt-banner p {
            margin: 0;
            font-size: 14.5px;
            color: #3f6b68;
            max-width: 560px;
            line-height: 1.5;
        }

        .adopt-banner-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ab-btn {
            display: inline-block;
            text-decoration: none;
            text-align: center;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 22px;
            border-radius: 10px;
            background: #0d9488;
            color: #fff;
            border: 1px solid #0d9488;
        }

        .ab-btn:hover {
            background: #0b7c72;
        }

        .ab-btn.secondary {
            background: #fff;
            color: #0d9488;
            border-color: #99e6da;
        }

        .ab-btn.secondary:hover {
            background: #f0fdfa;
        }

        .ab-btn:focus-visible {
            outline: 2px solid #0d9488;
            outline-offset: 2px;
        }

        .info-banners {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 0 0 32px;
        }

        .info-banner {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 18px;
            border-radius: 16px;
            padding: 26px 28px;
            border: 1px solid;
        }

        .info-banner h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .info-banner p {
            margin: 0;
            font-size: 14.5px;
            line-height: 1.5;
        }

        .info-banner.rescue {
            background: #fff4ed;
            border-color: #fdd2b5;
        }

        .info-banner.rescue h2 {
            color: #7c2d12;
        }

        .info-banner.rescue p {
            color: #8a5a44;
        }

        .info-banner.rescue .ab-btn {
            background: #ea580c;
            border-color: #ea580c;
        }

        .info-banner.rescue .ab-btn:hover {
            background: #c2410c;
        }

        .info-banner.health {
            background: #eef6ff;
            border-color: #bcd9f7;
        }

        .info-banner.health h2 {
            color: #0c3b6b;
        }

        .info-banner.health p {
            color: #46688a;
        }

        .info-banner.health .ab-btn {
            background: #2563eb;
            border-color: #2563eb;
        }

        .info-banner.health .ab-btn:hover {
            background: #1d4ed8;
        }

        .info-banner .ab-btn {
            align-self: flex-start;
        }
    </style>

    <link rel="stylesheet" href="css/theme.css">
</head>

<body>

    <script src="js/darkmode.js"></script>

    <?php include 'header.php'; ?>

    <main class="content-area">

        <?php if ($flash !== ''): ?>
            <div class="flash-msg" role="status">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <div class="hero-banner">
            <h1>Welcome to Pawfinder!</h1>
            <p>
                Creating a safer community by connecting rescued pets
                with responsible and loving owners
            </p>
        </div>

        <?php if ($canRequest): ?>

            <?php if ($reqStatus === 'Pending'): ?>

                <div class="rescuer-banner pending" role="status">
                    <div>
                        <h3>Your rescuer request is under review</h3>
                        <p>
                            An admin is checking your application to become
                            a Local Rescuer. Once approved, log out and log
                            in again to open the Rescuer Dashboard.
                        </p>
                    </div>
                </div>

            <?php else: ?>

                <div class="rescuer-banner">
                    <div>
                        <h3>
                            <?= $reqStatus === 'Rejected'
                                ? 'Want to apply again as a Local Rescuer?'
                                : 'Want to help rescue pets in your barangay?' ?>
                        </h3>

                        <p>
                            <?= $reqStatus === 'Rejected'
                                ? 'Your last request was not approved. You can send a new request anytime.'
                                : 'Become a Local Rescuer to receive emergency reports, see where pets need help, and mark rescues as done.' ?>
                        </p>
                    </div>

                    <form action="request_rescuer.php" method="POST"
                        onsubmit="return confirm('Send a request to become a Local Rescuer?');">

                        <button type="submit" class="rb-btn">
                            Become a Local Rescuer
                        </button>

                    </form>
                </div>

            <?php endif; ?>

        <?php endif; ?>


        <!-- PARTNER SHELTERS -->

        <section class="partners-section">

            <div class="partners-header">
                <h2>Our Partner Shelters</h2>

                <p>
                    When rescued animals or pets available for adoption
                    remain unadopted for an extended period, they are safely
                    transferred to larger partner facilities for long-term
                    shelter, rehabilitation, and specialized care.
                </p>
            </div>

            <div class="shelter-cards-grid">

                <div class="shelter-card">
                    <div>
                        <img src="image/afk.jpg"
                            alt="AKF Animal Rescue"
                            class="shelter-img">

                        <div class="shelter-name">
                            Animal Kingdom Foundation (AKF)
                        </div>

                        <div class="shelter-meta">
                            ★ 4.5 (2 reviews) • Verified
                        </div>

                        <div class="shelter-category">
                            Animal Shelters • Community Service / Non-Profit
                        </div>

                        <div class="shelter-info">
                            <strong>Address:</strong>
                            No. 8, Purante Street, Cub-Cub, Capas, Tarlac
                            <br>

                            <strong>Contact:</strong>
                            +63 939 914 2403
                        </div>
                    </div>

                    <a href="https://animalkingdomfoundation.org"
                        target="_blank"
                        class="shelter-link">
                        Visit Website / Page →
                    </a>
                </div>


                <div class="shelter-card">
                    <div>
                        <img src="image/part.jpg"
                            alt="Philippine Animal Rescue Team"
                            class="shelter-img">

                        <div class="shelter-name">
                            Philippine Animal Rescue Team (PART)
                        </div>

                        <div class="shelter-meta">
                            ★ 4.8 • Verified Sanctuary
                        </div>

                        <div class="shelter-category">
                            No-Kill Animal Shelter • Rehabilitation Center
                        </div>

                        <div class="shelter-info">
                            <strong>Address:</strong>
                            San Jose del Monte, Bulacan
                            <br>

                            <strong>Contact:</strong>
                            +63 917 859 2928
                        </div>
                    </div>

                    <a href="https://www.philippineanimalrescueteam.org"
                        target="_blank"
                        class="shelter-link">
                        Visit Website / Page →
                    </a>
                </div>


                <div class="shelter-card">
                    <div>
                        <img src="image/paw.jpg"
                            alt="PAWS Philippines"
                            class="shelter-img">

                        <div class="shelter-name">
                            Philippine Animal Welfare Society (PAWS)
                        </div>

                        <div class="shelter-meta">
                            ★ 4.9 • Verified Organization
                        </div>

                        <div class="shelter-category">
                            Animal Welfare • Spay & Neuter Clinic
                        </div>

                        <div class="shelter-info">
                            <strong>Address:</strong>
                            Aurora Blvd., Katipunan Valley,
                            Loyola Heights, Quezon City
                            <br>

                            <strong>Contact:</strong>
                            +63 2 8475 1686
                        </div>
                    </div>

                    <a href="https://paws.org.ph"
                        target="_blank"
                        class="shelter-link">
                        Visit Website / Page →
                    </a>
                </div>

            </div>

        </section>


        <!-- PET ADOPTION BANNER -->

        <section class="adopt-banner" id="rehome-banner">

            <div>
                <h2>Want to put your pet up for adoption?</h2>

                <p>
                    If you can no longer take care of your pet,
                    you don't have to give up on finding it a home.
                    Post it here and let a loving, responsible family
                    give it a second chance.
                </p>
            </div>

            <div class="adopt-banner-actions">

                <a href="<?= htmlspecialchars($postLink) ?>"
                    class="ab-btn">

                    <?= $loggedIn
                        ? 'Post My Pet for Adoption'
                        : 'Log In to Post a Pet' ?>

                </a>

                <a href="<?= htmlspecialchars($adoptLink) ?>"
                    class="ab-btn secondary">

                    <?= $loggedIn
                        ? 'Browse Pets for Adoption'
                        : 'Log In to Browse Pets' ?>

                </a>

            </div>

        </section>


        <!-- EMERGENCY RESCUE AND HEALTH BANNERS -->

        <div class="info-banners">

            <section class="info-banner rescue" id="rescue-banner">

                <div>
                    <h2>Found a stray or missing pet?</h2>

                    <p>
                        Report it right away so rescuers can help.
                        See missing and found stray pets reported
                        in your barangay on the Emergency Rescues page.
                    </p>
                </div>

                <a href="<?= htmlspecialchars($rescueLink) ?>"
                    class="ab-btn">

                    <?= $loggedIn
                        ? 'Go to Emergency Rescues'
                        : 'Log In to View Emergency Rescues' ?>

                </a>

            </section>


            <section class="info-banner health" id="health-banner">

                <div>
                    <h2>Free Medicine &amp; Vaccination</h2>

                    <p>
                        Check the schedule of free anti-rabies vaccinations,
                        deworming, health checkups, and spay &amp; neuter
                        missions near you.
                    </p>
                </div>

                <a href="<?= htmlspecialchars($healthLink) ?>"
                    class="ab-btn">

                    <?= $loggedIn
                        ? 'View Health Drives'
                        : 'Log In to View Health Drives' ?>

                </a>

            </section>

        </div>

    </main>

</body>
</html>
