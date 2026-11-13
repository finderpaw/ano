
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
        FROM health_drives
        ORDER BY event_date ASC";

$result = $conn->query($sql);

$healthDrives = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $healthDrives[] = $row;
    }
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, "UTF-8");
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

    .schedule-section {
        margin-top: 45px;
    }

    .schedule-wrap {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow-x: auto;
        box-shadow: 0 3px 8px rgba(0,0,0,0.06);
    }

    .schedule-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 640px;
    }

    .schedule-table th {
        background: #1e293b;
        color: #ffffff;
        text-align: left;
        font-size: 13px;
        padding: 14px 18px;
    }

    .schedule-table td {
        padding: 14px 18px;
        font-size: 14px;
        color: #334155;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
    }

    .schedule-table tr:last-child td {
        border-bottom: none;
    }

    .schedule-table .svc-name {
        font-weight: 700;
        color: #0f172a;
    }

    .schedule-table .svc-desc {
        color: #64748b;
        font-size: 13px;
        margin-top: 2px;
    }

    .sched-status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .sched-status.upcoming {
        background: #ccfbf1;
        color: #0f766e;
    }

    .sched-status.ongoing {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .sched-status.completed {
        background: #e2e8f0;
        color: #475569;
    }

    .sched-status.cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .health-empty {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        color: #64748b;
    }

    @media (max-width: 900px) {
        .health-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 600px) {
        .health-grid {
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

    <!-- SHARED HEADER -->
    <?php include 'header.php'; ?>


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

            <?php if (empty($healthDrives)): ?>

                <div class="health-empty">
                    No health drives are scheduled right now.
                    Please check back soon.
                </div>

            <?php else: ?>

                <div class="health-grid">

                    <?php foreach ($healthDrives as $drive): ?>

                        <div class="health-card">

                            <img
                                src="<?php echo e($drive['image']); ?>"
                                alt="<?php echo e($drive['name']); ?>"
                                onerror="this.onerror=null;this.src='https://placehold.co/600x400/e2e8f0/0f766e?text=Pet+Health+Drive'"
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

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>


        <!-- FREE MEDICINE & VACCINATION SCHEDULE -->
        <section class="schedule-section" id="schedule">

            <h2 class="health-section-title">
                Free Medicine &amp; Vaccination Schedule
            </h2>

            <?php if (empty($healthDrives)): ?>

                <div class="health-empty">
                    No schedule available yet.
                </div>

            <?php else: ?>

                <div class="schedule-wrap">

                    <table class="schedule-table">

                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Location / Barangay</th>
                                <th>Services Offered</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($healthDrives as $drive): ?>

                                <tr>

                                    <td>
                                        <?php echo e(date('M d, Y', strtotime($drive['date']))); ?>
                                        <br>
                                        <?php echo e($drive['time']); ?>
                                    </td>

                                    <td>
                                        <?php echo e($drive['location']); ?>
                                    </td>

                                    <td>
                                        <div class="svc-name">
                                            <?php echo e($drive['name']); ?>
                                        </div>

                                        <div class="svc-desc">
                                            <?php echo e($drive['description']); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="sched-status <?php echo e(strtolower($drive['status'])); ?>">
                                            <?php echo e($drive['status']); ?>
                                        </span>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    </main>

</body>

</html>

<?php
$conn->close();
?>
