<?php
session_start();

// Check session inactivity timeout
require_once __DIR__ . "/config/session_timeout.php";

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "teacher"
) {
    header("Location: teacher.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$teacher = null;
$meetingCount = 0;
$bookingCount = 0;
$dashboardError = "";

try {
    $stmt = $pdo->prepare(
        "SELECT id, employee_number, subject, qualification
         FROM teachers
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        "user_id" => $_SESSION["user_id"]
    ]);

    $teacher = $stmt->fetch();

    if ($teacher) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM meetings
             WHERE teacher_id = :teacher_id"
        );

        $stmt->execute([
            "teacher_id" => $teacher["id"]
        ]);

        $meetingCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bookings b
             INNER JOIN meetings m ON b.meeting_id = m.id
             WHERE m.teacher_id = :teacher_id"
        );

        $stmt->execute([
            "teacher_id" => $teacher["id"]
        ]);

        $bookingCount = (int) $stmt->fetchColumn();
    }

} catch (PDOException $e) {
    error_log("Teacher dashboard error: " . $e->getMessage());
    $dashboardError = "Unable to load your dashboard information.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Teacher Dashboard - Online Parent-Teacher Meeting System</title>

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
            gap: 18px;
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
            max-width: 1100px;
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

        .profile {
            background: white;
            padding: 24px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .profile h3 {
            margin-bottom: 15px;
            color: #1e3a8a;
        }

        .profile p {
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .card h3 {
            color: #6b7280;
            font-size: 16px;
            margin-bottom: 12px;
        }

        .number {
            color: #1e3a8a;
            font-size: 32px;
            font-weight: bold;
        }

        .actions {
            background: white;
            padding: 24px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .actions h3 {
            color: #1e3a8a;
            margin-bottom: 15px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-block;
            background: #1e3a8a;
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 7px;
            font-weight: bold;
            transition: background 0.2s ease;
        }

        .action-btn:hover {
            background: #162e6b;
        }

        .notice {
            background: #dbeafe;
            color: #1e40af;
            padding: 16px;
            border-radius: 8px;
            line-height: 1.6;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .header {
                flex-direction: column;
                gap: 12px;
                padding: 18px 20px;
                text-align: center;
            }

            .header-right {
                flex-wrap: wrap;
                justify-content: center;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn {
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
                $_SESSION["full_name"] ?? "Teacher",
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </span>

        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</header>

<main class="container">

    <div class="page-title">
        <h2>Teacher Dashboard</h2>
        <p>Manage your meeting schedule and parent-teacher communication.</p>
    </div>

    <?php if ($dashboardError !== ""): ?>
        <div class="error">
            <?= htmlspecialchars($dashboardError, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <?php if ($teacher): ?>

        <section class="profile">
            <h3>Teacher Profile</h3>

            <p>
                <strong>Name:</strong>
                <?= htmlspecialchars(
                    $_SESSION["full_name"] ?? "Teacher",
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </p>

            <p>
                <strong>Employee Number:</strong>
                <?= htmlspecialchars($teacher["employee_number"], ENT_QUOTES, "UTF-8") ?>
            </p>

            <p>
                <strong>Subject:</strong>
                <?= htmlspecialchars($teacher["subject"], ENT_QUOTES, "UTF-8") ?>
            </p>

            <p>
                <strong>Qualification:</strong>
                <?= htmlspecialchars($teacher["qualification"], ENT_QUOTES, "UTF-8") ?>
            </p>
        </section>

        <section class="cards">

            <div class="card">
                <h3>Total Meeting Slots</h3>
                <div class="number"><?= $meetingCount ?></div>
            </div>

            <div class="card">
                <h3>Total Bookings</h3>
                <div class="number"><?= $bookingCount ?></div>
            </div>

        </section>

        <section class="actions">
            <h3>Profile Management</h3>

            <div class="action-buttons">
                <a href="teacher_profile.php" class="action-btn">
                    Manage My Profile
                </a>
            </div>
        </section>

        <section class="actions">
            <h3>Meeting Management</h3>

            <div class="action-buttons">
                <a href="manage_meetings.php" class="action-btn">
                    Manage Meetings
                </a>

                <a href="teacher_bookings.php" class="action-btn">
                    Manage Bookings
                </a>
            </div>
        </section>

        <section class="actions">
            <h3>Academic Management</h3>

            <div class="action-buttons">
                <a href="teacher_academics.php" class="action-btn">
                    Manage Academic Records
                </a>

                <a href="teacher_marksheets.php" class="action-btn">
                    Manage Marksheets
                </a>

                <a href="teacher_attendance.php" class="action-btn">
                    Manage Attendance
                </a>
            </div>
        </section>

        <section class="actions">
            <h3>Student Feedback Management</h3>

            <div class="action-buttons">
                <a href="teacher_feedback.php" class="action-btn">
                    Share Student Feedback
                </a>

                <a href="teacher_parent_feedback.php" class="action-btn">
                    Review Parent Feedback
                </a>
            </div>
        </section>

        <section class="actions">
            <h3>Parent Communication</h3>

            <div class="action-buttons">
                <a href="teacher_messages.php" class="action-btn">
                    Messages from Parents
                </a>
            </div>
        </section>

        <div class="notice">
            <strong>Teacher Management:</strong>
            Create and manage your available parent-teacher meeting slots.
            Parents will be able to view available slots and book meetings
            for their children.
        </div>

    <?php elseif ($dashboardError === ""): ?>

        <div class="notice">
            Your teacher profile could not be found.
            Please contact the administrator to check your account.
        </div>

    <?php endif; ?>

</main>

</body>
</html>