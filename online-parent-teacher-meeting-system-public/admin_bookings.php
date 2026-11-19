<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";
require_once __DIR__ . "/config/database.php";

// Administrators only.
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: admin.php");
    exit;
}

$message = "";
$messageType = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function escapeBooking($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Process administrator actions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $message = "Invalid request. Please refresh the page.";
        $messageType = "error";
    } else {

        $action = $_POST["action"] ?? "";

        // Assign or reassign a teacher to a booked meeting.
        if ($action === "assign_teacher") {

            $meetingId = (int)($_POST["meeting_id"] ?? 0);
            $teacherId = (int)($_POST["teacher_id"] ?? 0);

            if ($meetingId <= 0 || $teacherId <= 0) {
                $message = "Please select a valid meeting and teacher.";
                $messageType = "error";
            } else {
                try {
                    $meetingCheck = $pdo->prepare(
                        "SELECT id, teacher_id, meeting_date, start_time,
                                end_time, status
                         FROM meetings
                         WHERE id = :id
                         LIMIT 1"
                    );

                    $meetingCheck->execute(["id" => $meetingId]);
                    $meeting = $meetingCheck->fetch();

                    $teacherCheck = $pdo->prepare(
                        "SELECT t.id
                         FROM teachers t
                         INNER JOIN users u ON u.id = t.user_id
                         WHERE t.id = :id
                           AND u.role = 'teacher'
                           AND u.status = 'active'
                         LIMIT 1"
                    );

                    $teacherCheck->execute(["id" => $teacherId]);
                    $teacher = $teacherCheck->fetch();

                    if (!$meeting) {
                        $message = "Meeting slot not found.";
                        $messageType = "error";

                    } elseif (!$teacher) {
                        $message = "Selected teacher does not exist or is inactive.";
                        $messageType = "error";

                    } elseif ($meeting["status"] === "cancelled") {
                        $message = "A cancelled meeting cannot be reassigned.";
                        $messageType = "error";

                    } elseif ($meeting["status"] === "completed") {
                        $message = "A completed meeting cannot be reassigned.";
                        $messageType = "error";

                    } elseif ((int)$meeting["teacher_id"] === $teacherId) {
                        $message = "This teacher is already assigned to the meeting.";
                        $messageType = "error";

                    } else {

                        // Prevent overlapping active meetings for the selected teacher.
                        $overlap = $pdo->prepare(
                            "SELECT COUNT(*)
                             FROM meetings
                             WHERE teacher_id = :teacher_id
                               AND meeting_date = :meeting_date
                               AND id != :meeting_id
                               AND status IN ('available', 'booked')
                               AND start_time < :end_time
                               AND end_time > :start_time"
                        );

                        $overlap->execute([
                            "teacher_id" => $teacherId,
                            "meeting_date" => $meeting["meeting_date"],
                            "meeting_id" => $meetingId,
                            "start_time" => $meeting["start_time"],
                            "end_time" => $meeting["end_time"]
                        ]);

                        if ((int)$overlap->fetchColumn() > 0) {
                            $message = "The selected teacher already has another meeting during this time.";
                            $messageType = "error";
                        } else {

                            $update = $pdo->prepare(
                                "UPDATE meetings
                                 SET teacher_id = :teacher_id
                                 WHERE id = :meeting_id
                                   AND status IN ('available', 'booked')"
                            );

                            $update->execute([
                                "teacher_id" => $teacherId,
                                "meeting_id" => $meetingId
                            ]);

                            header("Location: admin_bookings.php?assigned=1");
                            exit;
                        }
                    }

                } catch (PDOException $e) {
                    error_log("Admin teacher assignment error: " . $e->getMessage());
                    $message = "Unable to update the teacher assignment.";
                    $messageType = "error";
                }
            }
        }
    }
}

// Display successful operation message.
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["assigned"])) {
    $message = "Teacher assigned successfully.";
    $messageType = "success";
}

// Load active teachers for assignment.
$teacherQuery = $pdo->query(
    "SELECT t.id, u.full_name, t.employee_number, t.subject
     FROM teachers t
     INNER JOIN users u ON u.id = t.user_id
     WHERE u.role = 'teacher'
       AND u.status = 'active'
     ORDER BY u.full_name"
);

$teachers = $teacherQuery->fetchAll();

// Load bookings with parent, student, meeting and teacher information.
$bookingQuery = $pdo->query(
    "SELECT
        b.id AS booking_id,
        b.booking_status,
        b.payment_status,
        b.fee_amount,
        b.booked_at,

        m.id AS meeting_id,
        m.meeting_date,
        m.start_time,
        m.end_time,
        m.meeting_type,
        m.meeting_link,
        m.location,
        m.status AS meeting_status,

        p.full_name AS parent_name,
        p.email AS parent_email,

        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,

        t.id AS teacher_id,
        tu.full_name AS teacher_name,
        t.employee_number,
        t.subject

     FROM bookings b

     INNER JOIN meetings m
        ON m.id = b.meeting_id

     INNER JOIN users p
        ON p.id = b.parent_id

     INNER JOIN students s
        ON s.id = b.student_id

     INNER JOIN teachers t
        ON t.id = m.teacher_id

     INNER JOIN users tu
        ON tu.id = t.user_id

     ORDER BY
        m.meeting_date DESC,
        m.start_time DESC,
        b.booked_at DESC"
);

$bookings = $bookingQuery->fetchAll();

// Calculate booking statistics.
$totalBookings = count($bookings);
$activeBookings = 0;
$confirmedBookings = 0;
$completedBookings = 0;
$cancelledBookings = 0;

foreach ($bookings as $booking) {
    if (in_array($booking["booking_status"], ["pending", "confirmed"], true)) {
        $activeBookings++;
    }

    if ($booking["booking_status"] === "confirmed") {
        $confirmedBookings++;
    }

    if ($booking["booking_status"] === "completed") {
        $completedBookings++;
    }

    if ($booking["booking_status"] === "cancelled") {
        $cancelledBookings++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Booked Slot Monitoring | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .page-main {
            max-width: 1250px;
            margin: 40px auto;
            padding: 24px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 25px;
        }

        .section-label {
            color: #2563eb;
            font-weight: bold;
            font-size: 13px;
            letter-spacing: 1px;
        }

        .back-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .content-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .content-card h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .help-text {
            color: #64748b;
            line-height: 1.6;
        }

        .statistics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
        }

        .stat-card h3 {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .stat-number {
            color: #1e3a8a;
            font-size: 30px;
            font-weight: bold;
        }

        .notice {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .notice.success {
            background: #dcfce7;
            color: #166534;
        }

        .notice.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }

        th {
            background: #f8fafc;
            color: #334155;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .confirmed {
            background: #dcfce7;
            color: #166534;
        }

        .completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .assignment-form {
            min-width: 190px;
        }

        .assignment-form select {
            width: 100%;
            padding: 9px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            margin-bottom: 8px;
            font: inherit;
        }

        .assign-button {
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 7px;
            padding: 9px 12px;
            cursor: pointer;
            font-weight: 600;
        }

        .assign-button:hover {
            background: #1d4ed8;
        }

        .muted {
            color: #64748b;
            font-size: 13px;
        }

        .empty-message {
            padding: 25px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 800px) {
            .statistics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .page-main {
                padding: 15px;
            }

            .content-card {
                padding: 17px;
            }

            .statistics {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="site-header">
    <div class="container navbar">
        <a href="index.php" class="brand">
            <span class="brand-icon">S</span>
            <span>
                <strong>Online Parent-Teacher Meeting System</strong>
                <small>Administration</small>
            </span>
        </a>
    </div>
</header>

<main class="page-main">

    <div class="page-header">
        <div>
            <p class="section-label">ADMINISTRATION</p>
            <h1>Booked Slot Monitoring</h1>
            <p class="help-text">
                Monitor parent bookings, student details, meeting schedules
                and assigned teachers.
            </p>
        </div>

        <a href="admin_dashboard.php" class="back-link">
            &larr; Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ""): ?>
        <div class="notice <?php echo $messageType === "success" ? "success" : "error"; ?>">
            <?php echo escapeBooking($message); ?>
        </div>
    <?php endif; ?>

    <section class="statistics">

        <div class="stat-card">
            <h3>Total Bookings</h3>
            <div class="stat-number"><?php echo $totalBookings; ?></div>
        </div>

        <div class="stat-card">
            <h3>Active Bookings</h3>
            <div class="stat-number"><?php echo $activeBookings; ?></div>
        </div>

        <div class="stat-card">
            <h3>Confirmed Bookings</h3>
            <div class="stat-number"><?php echo $confirmedBookings; ?></div>
        </div>

        <div class="stat-card">
            <h3>Completed Bookings</h3>
            <div class="stat-number"><?php echo $completedBookings; ?></div>
        </div>

    </section>

    <section class="content-card">

        <h2>All Parent-Teacher Bookings</h2>

        <p class="help-text">
            Review booking information and assign or reassign an active
            meeting to an available teacher. Teacher changes are checked
            for scheduling conflicts.
        </p>

        <?php if (empty($bookings)): ?>

            <div class="empty-message">
                No meeting bookings are available yet.
            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Parent</th>
                            <th>Student</th>
                            <th>Meeting Date &amp; Time</th>
                            <th>Meeting Type</th>
                            <th>Assigned Teacher</th>
                            <th>Booking Status</th>
                            <th>Payment Status</th>
                            <th>Teacher Assignment</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($bookings as $index => $booking): ?>

                        <tr>

                            <td><?php echo $index + 1; ?></td>

                            <td>
                                <strong>
                                    <?php echo escapeBooking($booking["parent_name"]); ?>
                                </strong>
                                <br>
                                <span class="muted">
                                    <?php echo escapeBooking($booking["parent_email"]); ?>
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <?php echo escapeBooking($booking["student_name"]); ?>
                                </strong>
                                <br>
                                <span class="muted">
                                    Admission:
                                    <?php echo escapeBooking($booking["admission_number"]); ?>
                                </span>
                                <br>
                                <span class="muted">
                                    Class:
                                    <?php echo escapeBooking($booking["class_name"]); ?>
                                    <?php echo escapeBooking($booking["section"]); ?>
                                </span>
                            </td>

                            <td>
                                <?php echo escapeBooking($booking["meeting_date"]); ?>
                                <br>
                                <?php echo escapeBooking(substr($booking["start_time"], 0, 5)); ?>
                                –
                                <?php echo escapeBooking(substr($booking["end_time"], 0, 5)); ?>
                            </td>

                            <td>
                                <?php echo $booking["meeting_type"] === "online" ? "Online" : "In-person"; ?>
                            </td>

                            <td>
                                <strong>
                                    <?php echo escapeBooking($booking["teacher_name"]); ?>
                                </strong>
                                <br>
                                <span class="muted">
                                    <?php echo escapeBooking($booking["employee_number"]); ?>
                                    -
                                    <?php echo escapeBooking($booking["subject"]); ?>
                                </span>
                            </td>

                            <td>
                                <span class="status <?php echo escapeBooking($booking["booking_status"]); ?>">
                                    <?php echo escapeBooking(ucfirst($booking["booking_status"])); ?>
                                </span>
                            </td>

                            <td>
                                <?php echo escapeBooking(ucfirst($booking["payment_status"])); ?>
                                <br>
                                <span class="muted">
                                    PKR <?php echo number_format((float)$booking["fee_amount"], 2); ?>
                                </span>
                            </td>

                            <td>

                                <?php if (
                                    in_array(
                                        $booking["booking_status"],
                                        ["pending", "confirmed"],
                                        true
                                    ) &&
                                    $booking["meeting_status"] !== "cancelled"
                                ): ?>

                                    <form
                                        method="POST"
                                        action="admin_bookings.php"
                                        class="assignment-form"
                                        onsubmit="return confirm('Update the teacher assignment for this meeting?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo escapeBooking($_SESSION["csrf_token"]); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="assign_teacher"
                                        >

                                        <input
                                            type="hidden"
                                            name="meeting_id"
                                            value="<?php echo (int)$booking["meeting_id"]; ?>"
                                        >

                                        <select name="teacher_id" required>
                                            <?php foreach ($teachers as $teacher): ?>

                                                <option
                                                    value="<?php echo (int)$teacher["id"]; ?>"
                                                    <?php echo (int)$booking["teacher_id"] === (int)$teacher["id"] ? "selected" : ""; ?>
                                                >
                                                    <?php
                                                    echo escapeBooking(
                                                        $teacher["full_name"] .
                                                        " (" . $teacher["employee_number"] . ")"
                                                    );
                                                    ?>
                                                </option>

                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit" class="assign-button">
                                            Assign Teacher
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="muted">Protected</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php endif; ?>

    </section>

    <p class="help-text">
        Cancelled and completed bookings are displayed for record keeping.
        Their teacher assignments cannot be changed from this page.
    </p>

</main>

</body>
</html>