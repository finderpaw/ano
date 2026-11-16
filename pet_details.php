
<?php
// pet_details.php
session_start();
include 'config.php';

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// Kung walang ID, balik sa homepage
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: homepage.php");
    exit();
}

$pet_id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT * FROM pets WHERE id = ?");
$stmt->bind_param("i", $pet_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "Pet not found.";
    exit();
}

$pet = $result->fetch_assoc();
$stmt->close();

// Availability
$status = $pet['status'] ?? 'Available';
$statusClass = strtolower($status);

$statusNote = [
    'Available' => 'This pet is available for adoption.',
    'Pending'   => 'An adoption request is currently being processed for this pet.',
    'Adopted'   => 'This pet has already been adopted.',
][$status] ?? '';

// Back link
if ($isAdmin) {
    $backUrl = 'admin.php';
    $backLabel = '← Back to Dashboard';
} else {
    $species = strtolower($pet['pet_species']);
    $anchor = $species === 'dog' ? '#dogs-section' : ($species === 'cat' ? '#cats-section' : '');

    $backUrl = 'adopt.php' . $anchor;
    $backLabel = '← Back to Home';
}

// Login status
$loggedIn = !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);

// Check existing pending adoption request
$alreadyRequested = false;

if ($loggedIn && !$isAdmin) {
    $chk = $conn->prepare("
        SELECT id
        FROM adoption_requests
        WHERE pet_id = ?
        AND user_id = ?
        AND status = 'Pending'
        LIMIT 1
    ");

    $chk->bind_param("ii", $pet_id, $_SESSION['user_id']);
    $chk->execute();

    $alreadyRequested = $chk->get_result()->num_rows > 0;
    $chk->close();
}

// Adoption message
$adoptMsg = $_SESSION['adopt_msg'] ?? '';
unset($_SESSION['adopt_msg']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Pawfinder - <?php echo htmlspecialchars($pet['pet_name']); ?> Details
    </title>

    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #f8fafc;
            color: #0f172a;
            padding: 40px 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .header-bar {
            padding: 20px 30px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .back-btn {
            text-decoration: none;
            color: #0d9488;
            font-weight: 600;
            font-size: 14px;
        }

        .details-wrapper {
            display: flex;
            flex-wrap: wrap;
            padding: 30px;
            gap: 30px;
        }

        .image-box {
            flex: 1 1 300px;
        }

        .image-box img {
            width: 100%;
            height: 320px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .info-box {
            flex: 1 1 400px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .pet-title {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }

        .badge-list {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .badge {
            background: #ccfbf1;
            color: #0d9488;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge.available {
            background: #dcfce7;
            color: #166534;
        }

        .badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge.adopted {
            background: #e2e8f0;
            color: #475569;
        }

        .info-group {
            border-top: 1px solid #f1f5f9;
            padding-top: 10px;
        }

        .info-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 15px;
            color: #334155;
            line-height: 1.5;
        }

        .avail-note {
            margin-top: 6px;
            font-size: 13px;
            color: #64748b;
        }

        .adopt-flash {
            margin: 20px 30px 0;
            padding: 12px 16px;
            background: #e2f3ea;
            color: #1f7a4d;
            border: 1px solid #bfe3cf;
            border-radius: 10px;
            font-size: 14px;
        }

        .adopt-box {
            margin-top: 6px;
        }

        .btn-adopt {
            display: block;
            width: 100%;
            text-align: center;
            text-decoration: none;
            background: #0d9488;
            color: #ffffff;
            border: 0;
            border-radius: 10px;
            padding: 14px 20px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-adopt:hover {
            background: #0b7c72;
        }

        .btn-adopt:focus-visible {
            outline: 2px solid #0d9488;
            outline-offset: 2px;
        }

        .btn-adopt[disabled] {
            background: #e2e8f0;
            color: #64748b;
            cursor: not-allowed;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-edit {
            text-decoration: none;
            background: #e2e8f0;
            color: #0f172a;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-del {
            text-decoration: none;
            background: #fee2e2;
            color: #991b1b;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            body {
                padding: 20px 10px;
            }

            .details-wrapper {
                padding: 20px;
            }

            .header-bar {
                padding: 16px 20px;
            }

            .image-box img {
                height: 250px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header-bar">

        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="back-btn">
            <?php echo $backLabel; ?>
        </a>

        <span class="badge <?php echo htmlspecialchars($statusClass); ?>">
            Status: <?php echo htmlspecialchars($status); ?>
        </span>

    </div>

    <?php if ($adoptMsg !== ''): ?>
        <div class="adopt-flash" role="status">
            <?php echo htmlspecialchars($adoptMsg); ?>
        </div>
    <?php endif; ?>

    <div class="details-wrapper">

        <!-- PET IMAGE -->
        <div class="image-box">

            <img
                src="<?php echo htmlspecialchars($pet['pet_photo']); ?>"
                alt="<?php echo htmlspecialchars($pet['pet_name']); ?>"
            >

        </div>

        <!-- PET INFORMATION -->
        <div class="info-box">

            <h1 class="pet-title">
                <?php echo htmlspecialchars($pet['pet_name']); ?>
            </h1>

            <div class="badge-list">

                <span class="badge">
                    <?php echo htmlspecialchars($pet['pet_species']); ?>
                </span>

                <span class="badge">
                    <?php echo htmlspecialchars($pet['breed']); ?>
                </span>

                <span class="badge">
                    <?php echo htmlspecialchars($pet['age']); ?>
                </span>

            </div>

            <!-- AVAILABILITY -->
            <div class="info-group">

                <div class="info-label">Availability</div>

                <div class="info-value">

                    <span class="badge <?php echo htmlspecialchars($statusClass); ?>">
                        <?php echo htmlspecialchars($status); ?>
                    </span>

                    <?php if ($statusNote !== ''): ?>
                        <div class="avail-note">
                            <?php echo htmlspecialchars($statusNote); ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

            <!-- BARANGAY -->
            <div class="info-group">

                <div class="info-label">Barangay Location</div>

                <div class="info-value">
                    <?php echo htmlspecialchars($pet['barangay']); ?>
                </div>

            </div>

            <!-- ADOPTION REASON -->
            <div class="info-group">

                <div class="info-label">Reason for Adoption</div>

                <div class="info-value">
                    <?php echo htmlspecialchars($pet['adoption_reason']); ?>
                </div>

            </div>

            <!-- HEALTH ISSUES -->
            <div class="info-group">

                <div class="info-label">Health Issues / Condition</div>

                <div class="info-value">
                    <?php
                    echo !empty($pet['health_issues'])
                        ? htmlspecialchars($pet['health_issues'])
                        : 'None reported';
                    ?>
                </div>

            </div>

            <!-- ADDITIONAL DETAILS -->
            <div class="info-group">

                <div class="info-label">Additional Details & Contact</div>

                <div class="info-value">
                    <?php
                    echo !empty($pet['pet_details'])
                        ? nl2br(htmlspecialchars($pet['pet_details']))
                        : 'N/A';
                    ?>
                </div>

            </div>

            <!-- ADOPTION BUTTON -->
            <?php if (!$isAdmin): ?>

                <div class="adopt-box">

                    <?php if ($alreadyRequested): ?>

                        <button type="button" class="btn-adopt" disabled>
                            Adoption Request Sent
                        </button>

                    <?php elseif ($status !== 'Available'): ?>

                        <button type="button" class="btn-adopt" disabled>
                            Not Available for Adoption
                        </button>

                    <?php elseif (!$loggedIn): ?>

                        <a href="login.php" class="btn-adopt">
                            Log in to Adopt
                            <?php echo htmlspecialchars($pet['pet_name']); ?>
                        </a>

                    <?php else: ?>

                        <!-- REDIRECT TO ADOPTION APPLICATION -->
                        <a
                            href="adoption_application.php?pet_id=<?php echo (int)$pet['id']; ?>"
                            class="btn-adopt"
                        >
                            Adopt <?php echo htmlspecialchars($pet['pet_name']); ?>
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <!-- ADMIN ACTION BUTTONS -->
            <?php if ($isAdmin): ?>

                <div class="action-buttons">

                    <a
                        href="edit_pet.php?id=<?php echo (int)$pet['id']; ?>"
                        class="btn-edit"
                    >
                        Edit Listing
                    </a>

                    <a
                        href="delete_pet.php?id=<?php echo (int)$pet['id']; ?>"
                        class="btn-del"
                        onclick="return confirm('Are you sure?')"
                    >
                        Delete Listing
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>
</html>
