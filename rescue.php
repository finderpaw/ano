<?php
session_start();

// LOGIN PROTECTION
if (empty($_SESSION['logged_in'])) {
    header("Location: login.php?redirect=rescue.php");
    exit;
}

// DATABASE CONNECTION
$conn = new mysqli("localhost", "root", "", "pawfinder_db");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$success = "";
$error = "";

// PROCESS RESCUE REPORT
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $reporter_name = trim($_POST["reporter_name"] ?? "");
    $contact_number = trim($_POST["contact_number"] ?? "");
    $location = trim($_POST["location"] ?? "");
    $pet_type = trim($_POST["pet_type"] ?? "");
    $pet_condition = trim($_POST["pet_condition"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if (
        $reporter_name === "" ||
        $contact_number === "" ||
        $location === "" ||
        $pet_type === "" ||
        $pet_condition === "" ||
        $description === ""
    ) {
        $error = "Please complete all fields.";

    } elseif (!preg_match("/^09[0-9]{9}$/", $contact_number)) {
        $error = "Please enter a valid 11-digit mobile number.";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO rescue_reports
            (reporter_name, contact_number, location, pet_type,
             pet_condition, description)
            VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssssss",
            $reporter_name,
            $contact_number,
            $location,
            $pet_type,
            $pet_condition,
            $description
        );

        if ($stmt->execute()) {
            $success = "Your rescue report has been submitted successfully!";
        } else {
            $error = "Unable to submit your report. Please try again.";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PAWFINDER - Emergency Rescues</title>

    <link rel="stylesheet" href="css/homepage.css">
    <link rel="stylesheet" href="css/rescue.css">
    <link rel="stylesheet" href="css/theme.css">

    <!-- DIRECT OVERRIDE PARA SIGURADONG GUMANA ANG DARK MODE -->
    <style>
        body.dark-mode .post-pet-section,
        body.dark-mode .report-section {
            background-color: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        body.dark-mode .post-pet-section h2,
        body.dark-mode .report-section h2,
        body.dark-mode .section-header-title {
            color: #f8fafc !important;
        }
        body.dark-mode .post-pet-section p,
        body.dark-mode .report-section p,
        body.dark-mode .form-subtitle-desc,
        body.dark-mode .form-note {
            color: #94a3b8 !important;
        }
    </style>
</head>

<body>

    <!-- DARK MODE -->
    <script src="js/darkmode.js"></script>

    <!-- SHARED HEADER -->
    <?php include 'header.php'; ?>

    <main class="content-area">

        <!-- HERO BANNER -->
        <div class="hero-banner">

            <h1>Every Life Deserves a Chance.</h1>

            <p>
                Help us protect animals in need. Report stray, abandoned,
                injured, or endangered pets in your community.
            </p>

        </div>

        <!-- EMERGENCY RESCUES INTRODUCTION -->
        <section class="partners-section">

            <div class="partners-header">

                <h2>Emergency Pet Rescues</h2>

                <p>
                    PAWFINDER encourages the community to take part in helping
                    animals that need immediate attention. Submit a report so
                    our team can review the situation and coordinate assistance.
                </p>

            </div>

        </section>

        <!-- RESCUE REPORT FORM -->
        <section class="post-pet-section report-section" id="report-form">

            <h2 class="section-header-title" style="margin-bottom: 8px;">
                Report an Animal in Need
            </h2>

            <p class="form-subtitle-desc" style="font-size: 14px; margin-bottom: 20px;">
                Provide accurate information about the animal and its location.
            </p>

            <!-- SUCCESS MESSAGE -->
            <?php if ($success !== ""): ?>

                <div class="message success">
                    <?= htmlspecialchars($success) ?>
                </div>

            <?php endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if ($error !== ""): ?>

                <div class="message error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="rescue.php">

                <div class="form-grid">

                    <!-- REPORTER NAME -->
                    <div class="form-group">

                        <label for="reporter_name">
                            Your Full Name
                        </label>

                        <input
                            type="text"
                            id="reporter_name"
                            name="reporter_name"
                            placeholder="Enter your full name"
                            value="<?= htmlspecialchars($_POST['reporter_name'] ?? '') ?>"
                            required
                        >

                    </div>

                    <!-- CONTACT NUMBER -->
                    <div class="form-group">

                        <label for="contact_number">
                            Contact Number
                        </label>

                        <input
                            type="tel"
                            id="contact_number"
                            name="contact_number"
                            placeholder="09XXXXXXXXX"
                            maxlength="11"
                            value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>"
                            required
                        >

                    </div>

                    <!-- RESCUE LOCATION -->
                    <div class="form-group" style="grid-column: 1 / -1;">

                        <label for="location">
                            Rescue Location
                        </label>

                        <input
                            type="text"
                            id="location"
                            name="location"
                            placeholder="Enter the exact location or address"
                            value="<?= htmlspecialchars($_POST['location'] ?? '') ?>"
                            required
                        >

                    </div>

                    <!-- PET TYPE -->
                    <div class="form-group">

                        <label for="pet_type">
                            Type of Animal
                        </label>

                        <select id="pet_type" name="pet_type" required>

                            <option value="">Select animal</option>

                            <option value="Dog"
                                <?= (($_POST['pet_type'] ?? '') === 'Dog') ? 'selected' : '' ?>>
                                Dog
                            </option>

                            <option value="Cat"
                                <?= (($_POST['pet_type'] ?? '') === 'Cat') ? 'selected' : '' ?>>
                                Cat
                            </option>

                            <option value="Other"
                                <?= (($_POST['pet_type'] ?? '') === 'Other') ? 'selected' : '' ?>>
                                Other
                            </option>

                        </select>

                    </div>

                    <!-- PET CONDITION -->
                    <div class="form-group">

                        <label for="pet_condition">
                            Animal's Condition
                        </label>

                        <select id="pet_condition" name="pet_condition" required>

                            <option value="">Select condition</option>

                            <option value="Injured"
                                <?= (($_POST['pet_condition'] ?? '') === 'Injured') ? 'selected' : '' ?>>
                                Injured
                            </option>

                            <option value="Abandoned"
                                <?= (($_POST['pet_condition'] ?? '') === 'Abandoned') ? 'selected' : '' ?>>
                                Abandoned
                            </option>

                            <option value="Stray"
                                <?= (($_POST['pet_condition'] ?? '') === 'Stray') ? 'selected' : '' ?>>
                                Stray
                            </option>

                            <option value="Sick"
                                <?= (($_POST['pet_condition'] ?? '') === 'Sick') ? 'selected' : '' ?>>
                                Sick
                            </option>

                            <option value="In Danger"
                                <?= (($_POST['pet_condition'] ?? '') === 'In Danger') ? 'selected' : '' ?>>
                                In Danger
                            </option>

                            <option value="Other"
                                <?= (($_POST['pet_condition'] ?? '') === 'Other') ? 'selected' : '' ?>>
                                Other
                            </option>

                        </select>

                    </div>

                    <!-- DESCRIPTION -->
                    <div class="form-group" style="grid-column: 1 / -1;">

                        <label for="description">
                            Description of the Situation
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            rows="4"
                            placeholder="Describe the animal and explain why it needs help..."
                            required
                        ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

                    </div>

                </div>

                <!-- SUBMIT BUTTON -->
                <button type="submit" class="form-submit-btn">
                    Submit Rescue Report
                </button>

                <p class="form-note">
                    Please make sure the information you provide is accurate.
                </p>

            </form>

        </section>

        <!-- RESCUE REMINDER -->
        <section class="partners-section" style="margin-top: 50px;">

            <div class="shelter-cards-grid">

                <div class="shelter-card rescue-reminder">

                    <div>

                        <div class="shelter-name">
                            See an Animal in Danger?
                        </div>

                        <div class="shelter-info">
                            Report its location and condition as soon as possible.
                            If the animal is in immediate danger, contact your local
                            animal welfare or emergency service as well.
                        </div>

                    </div>

                    <a href="contact.php" class="shelter-link">
                        Contact Us →
                    </a>

                </div>

            </div>

        </section>

    </main>

</body>

</html>

<?php
$conn->close();
?>