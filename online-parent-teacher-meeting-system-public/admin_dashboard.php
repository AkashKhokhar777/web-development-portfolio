<?php
session_start();

// Apply the 20-minute inactivity timeout
require_once __DIR__ . "/config/session_timeout.php";

// Check admin login
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: admin.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$counts = [
    "users" => 0,
    "students" => 0,
    "teachers" => 0,
    "meetings" => 0,
    "bookings" => 0,
    "feedback" => 0,
    "pending_payments" => 0,
    "approved_payments" => 0
];

$dashboardError = "";

try {
    $tables = [
        "users",
        "students",
        "teachers",
        "meetings",
        "bookings",
        "feedback"
    ];

    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $counts[$table] = (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM payments
        WHERE student_fee_id IS NOT NULL
          AND payment_status = 'pending'
    ");

    $counts["pending_payments"] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM payments
        WHERE student_fee_id IS NOT NULL
          AND payment_status = 'completed'
    ");

    $counts["approved_payments"] = (int) $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Dashboard database error: " . $e->getMessage());
    $dashboardError = "Unable to load dashboard statistics.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard - Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        .header {
            background: #1e3a8a;
            color: white;
            padding: 18px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 24px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .welcome {
            font-size: 15px;
        }

        .logout-btn {
            background: white;
            color: #1e3a8a;
            text-decoration: none;
            padding: 9px 16px;
            border-radius: 6px;
            font-weight: bold;
        }

        .logout-btn:hover {
            background: #e5e7eb;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 35px auto;
        }

        .page-title {
            margin-bottom: 25px;
        }

        .page-title h2 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-title p {
            color: #6b7280;
            line-height: 1.5;
        }

        .navigation {
            background: white;
            padding: 22px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .navigation h3 {
            margin-bottom: 15px;
            font-size: 19px;
        }

        .nav-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .nav-btn {
            display: inline-block;
            text-decoration: none;
            background: #1e3a8a;
            color: white;
            padding: 11px 18px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
            transition: background 0.2s ease;
        }

        .nav-btn:hover {
            background: #172554;
        }

        .backup-btn {
            background: #15803d;
        }

        .backup-btn:hover {
            background: #166534;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .card h3 {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 12px;
        }

        .card .number {
            font-size: 32px;
            font-weight: bold;
            color: #1e3a8a;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        @media (max-width: 800px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .header {
                padding: 18px 20px;
            }

            .header-right {
                gap: 10px;
            }
        }

        @media (max-width: 550px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }

            .nav-buttons {
                flex-direction: column;
            }

            .nav-btn {
                text-align: center;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Online Parent-Teacher Meeting System</h1>

    <div class="header-right">
        <span class="welcome">
            Welcome,
            <?= htmlspecialchars(
                $_SESSION["full_name"] ?? "Administrator",
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </span>

        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</header>

<main class="container">

    <div class="page-title">
        <h2>Admin Dashboard</h2>
        <p>Manage the Online Parent-Teacher Meeting System online parent-teacher meeting system.</p>
    </div>

    <?php if ($dashboardError !== ""): ?>
        <div class="error">
            <?= htmlspecialchars($dashboardError, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <section class="navigation">
        <h3>Quick Management</h3>

        <div class="nav-buttons">

            <a href="manage_teachers.php" class="nav-btn">
                Manage Teachers
            </a>

            <a href="manage_parents.php" class="nav-btn">
                Manage Parents
            </a>

            <a href="manage_students.php" class="nav-btn">
                Manage Students
            </a>

            <a href="admin_ptm_events.php" class="nav-btn">
                Manage PTM Events
            </a>

            <a href="admin_meetings.php" class="nav-btn">
                Manage PTM Meetings
            </a>

            <a href="admin_bookings.php" class="nav-btn">
                Monitor Booked Slots
            </a>

            <a href="manage_fees.php" class="nav-btn">
                Manage Student Fees
            </a>

            <a href="admin_payments.php" class="nav-btn">
                Verify Student Fee Payments
            </a>

            <a href="manage_expenses.php" class="nav-btn">
                Manage Expenses &amp; Profit/Loss
            </a>

            <a href="manage_feedback.php" class="nav-btn">
                Manage Parent Feedback
            </a>

            <a href="backup.php" class="nav-btn backup-btn">
                Download Database Backup
            </a>

        </div>
    </section>

    <section class="cards">

        <div class="card">
            <h3>Total Users</h3>
            <div class="number"><?= $counts["users"] ?></div>
        </div>

        <div class="card">
            <h3>Total Students</h3>
            <div class="number"><?= $counts["students"] ?></div>
        </div>

        <div class="card">
            <h3>Total Teachers</h3>
            <div class="number"><?= $counts["teachers"] ?></div>
        </div>

        <div class="card">
            <h3>Total Meetings</h3>
            <div class="number"><?= $counts["meetings"] ?></div>
        </div>

        <div class="card">
            <h3>Total Bookings</h3>
            <div class="number"><?= $counts["bookings"] ?></div>
        </div>

        <div class="card">
            <h3>Total Feedback</h3>
            <div class="number"><?= $counts["feedback"] ?></div>
        </div>

        <div class="card">
            <h3>Pending Payments</h3>
            <div class="number"><?= $counts["pending_payments"] ?></div>
        </div>

        <div class="card">
            <h3>Approved Payments</h3>
            <div class="number"><?= $counts["approved_payments"] ?></div>
        </div>

    </section>

</main>

</body>
</html>