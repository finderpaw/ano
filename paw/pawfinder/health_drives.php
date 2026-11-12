<?php
session_start();

$conn = new mysqli("localhost", "root", "", "pawfinder_db");

if ($conn->connect_error) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");

// GET HEALTH DRIVE EVENTS FROM DATABASE
$sql = "SELECT
            id,
            name,
            event_date AS date,
            event_time AS time,
            location,
            description,
            image,
            status
        FROM health_drives /**name ng mga client to - ela */
        ORDER BY event_date ASC";

$result = $conn->query($sql);

$healthDrives = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $healthDrives[] = $row;
    }
}

$message = "";
$messageType = "";

// HANDLE REGISTRATION
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $eventName = trim($_POST["event_name"] ?? "");
    $ownerName = trim($_POST["owner_name"] ?? "");
    $contact = trim($_POST["contact_number"] ?? "");
    $petName = trim($_POST["pet_name"] ?? "");
    $petType = trim($_POST["pet_type"] ?? "");

    if (
        $eventName === "" ||
        $ownerName === "" ||
        $contact === "" ||
        $petName === "" ||
        $petType === ""
    ) {
        $message = "Please complete all registration fields.";
        $messageType = "error";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO health_drive
            (event_name, owner_name, contact_number, pet_name, pet_type)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "sssss",
            $eventName,
            $ownerName,
            $contact,
            $petName,
            $petType
        );

        if ($stmt->execute()) {
            $message = "Registration submitted successfully!";
            $messageType = "success";
        } else {
            $message = "Registration failed. Please try again.";
            $messageType = "error";
        }

        $stmt->close();
    }
}

function e($value) {
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PAWFINDER - Health Drives</title>

    <link rel="stylesheet" href="css/homepage.css">

    <style>

        .navbar {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-links {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 25px;

            list-style: none;
            margin: 0;
            padding: 0;
            white-space: nowrap;
        }

        .health-container {
            max-width: 1200px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .health-header {
            background: #1e293b;
            color: white;
            padding: 35px;
            text-align: center;
            border-radius: 12px;
            margin-bottom: 35px;
        }

        .health-header h1 {
            margin: 0 0 12px;
            font-size: 30px;
        }

        .health-header p {
            color: #cbd5e1;
            margin: 0;
        }

        .health-section-title {
            color: #0f172a;
            margin-bottom: 20px;
        }

        .health-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .health-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 3px 8px rgba(0,0,0,0.06);
            transition: 0.2s;
        }

        .health-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.10);
        }

        .health-card img {
            width: 100%;
            height: 190px;
            object-fit: cover;
            background: #f1f5f9;
        }

        .health-card-content {
            padding: 20px;
        }

        .health-card h3 {
            color: #0f172a;
            font-size: 18px;
            margin: 0 0 12px;
        }

        .health-card p {
            color: #475569;
            font-size: 14px;
            line-height: 1.6;
            margin: 8px 0;
        }

        .health-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #ccfbf1;
            color: #0f766e;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .health-btn {
            display: inline-block;
            background: #0d9488;
            color: white;
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            margin-top: 12px;
        }

        .health-btn:hover {
            background: #0f766e;
        }

        .registration-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-top: 45px;
            border: 1px solid #e2e8f0;
        }

        .registration-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            color: #334155;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .form-message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 900px) {
            .health-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 600px) {
            .health-grid,   
            .registration-form {
                grid-template-columns: 1fr;
            }

            .health-header {
                padding: 25px 15px;
            }

            .health-header h1 {
                font-size: 24px;
            }

            
        }
    </style>
</head>

<body>

    <!-- TOP BAR -->
    <div class="top-bar">
        <div class="role-container">
            <span class="role-label">Current Role:</span>
            <select id="roleSwitcher" class="role-select">
                <option value="admin">Admin</option>
                <option value="guest" selected>Guest / Public Visitor</option>
                <option value="rescuer">Local Rescuers</option>
                <option value="adopter">Adopter</option>
            </select>
        </div>
    </div>

    <!-- NAVBAR -->
    
<!-- NAVBAR -->
<header class="navbar">

    <div class="brand-section">
        <img src="image/logo2.jpg"
             alt="PAWFINDER Logo"
             class="brand-logo-img">

        <div>
            <span class="brand-title">PAWFINDER</span>
            <span class="brand-subtitle">COMMUNITY PET SYSTEM</span>
        </div>
    </div>

    <ul class="nav-links">
        <li class="nav-item">
            <a href="homepage.php">Home</a>
        </li>

        <li class="nav-item">
            <a href="homepage.php#adopt">Adopt Pets</a>
        </li>

        <li class="nav-item">
            <a href="rescue.php">Emergency Rescues</a>
        </li>

        <li class="nav-item">
            <a href="health_drives.php">Health Drives</a>
        </li>

        <li class="nav-item">
            <a href="contact.php">Contact</a>
        </li>
    </ul>
</header>

    <!-- MAIN CONTENT -->
    <main class="health-container">

        <section class="health-header">
            <h1>Community Pet Health Drives</h1>

            <p>
                Providing accessible veterinary care and healthier lives
                for every pet in our community.
            </p>
        </section>

        <!-- HEALTH DRIVE LISTINGS -->
        <section>

            <h2 class="health-section-title">
                Upcoming Health Drives
            </h2>

            <div class="health-grid">

                <?php foreach ($healthDrives as $drive): ?>

                    <div class="health-card">

                        <img
                            src="<?php echo e($drive['image']); ?>"
                            alt="<?php echo e($drive['name']); ?>"
                            onerror="this.src='https://placehold.co/600x400/e2e8f0/0f766e?text=Pet+Health+Drive'"
                        >

                        <div class="health-card-content">

                            <span class="health-status">
                                <?php echo e($drive['status']); ?>
                            </span>

                            <h3>
                                <?php echo e($drive['name']); ?>
                            </h3>

                            <p>
                                <strong>Date:</strong>
                                <?php echo e($drive['date']); ?>
                            </p>

                            <p>
                                <strong>Time:</strong>
                                <?php echo e($drive['time']); ?>
                            </p>

                            <p>
                                <strong>Location:</strong>
                                <?php echo e($drive['location']); ?>
                            </p>

                            <p>
                                <?php echo e($drive['description']); ?>
                            </p>

                            <a
                                href="#registration"
                                class="health-btn"
                                onclick="selectEvent('<?php echo e($drive['name']); ?>')"
                            >
                                Register Now
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </section>

        <!-- REGISTRATION FORM -->
        <section class="registration-section" id="registration">

            <h2 class="health-section-title">
                Register Your Pet
            </h2>

            <p>
                Fill out the form below to register your pet for a
                community health drive.
            </p>

            <?php if ($message !== ""): ?>

                <div class="form-message <?php echo e($messageType); ?>">
                    <?php echo e($message); ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="health_drives.php">

                <div class="registration-form">

                    <div class="form-group full-width">
                        <label for="event_name">
                            Select Health Drive
                        </label>

                        <select name="event_name" id="event_name" required>

                            <option value="">
                                Choose a health drive
                            </option>

                            <?php foreach ($healthDrives as $drive): ?>

                                <option value="<?php echo e($drive['name']); ?>">
                                    <?php echo e($drive['name']); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group">
                        <label for="owner_name">
                            Pet Owner's Name
                        </label>

                        <input
                            type="text"
                            id="owner_name"
                            name="owner_name"
                            placeholder="Enter your full name"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="contact_number">
                            Contact Number
                        </label>

                        <input
                            type="tel"
                            id="contact_number"
                            name="contact_number"
                            placeholder="Enter contact number"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="pet_name">
                            Pet Name
                        </label>

                        <input
                            type="text"
                            id="pet_name"
                            name="pet_name"
                            placeholder="Enter your pet's name"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="pet_type">
                            Pet Type
                        </label>

                        <select name="pet_type" id="pet_type" required>

                            <option value="">
                                Select pet type
                            </option>

                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                            <option value="Other">Other</option>

                        </select>
                    </div>

                    <div class="form-group full-width">

                        <button type="submit" class="health-btn">
                            Submit Registration
                        </button>

                    </div>

                </div>

            </form>

        </section>

    </main>

    <script>
        function selectEvent(eventName) {
            document.getElementById("event_name").value = eventName;
        }
    </script>

</body>
</html>

<?php
$conn->close();
?>
