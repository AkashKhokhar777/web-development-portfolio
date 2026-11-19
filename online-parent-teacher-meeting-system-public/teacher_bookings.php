<?php
date_default_timezone_set('Asia/Karachi');

session_start();

require_once __DIR__ . "/config/session_timeout.php";

// Only logged-in teachers can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "teacher"
) {
    header("Location: teacher.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$teacherUserId = $_SESSION["user_id"];
$message = "";
$messageType = "";

// Current Pakistan local date and time
$currentDateTime = new DateTimeImmutable(
    "now",
    new DateTimeZone("Asia/Karachi")
);

try {
    // Get the teacher's profile ID
    $stmt = $pdo->prepare(
        "SELECT id FROM teachers WHERE user_id = ? LIMIT 1"
    );
    $stmt->execute([$teacherUserId]);
    $teacher = $stmt->fetch();

    if (!$teacher) {
        exit("Teacher profile not found.");
    }

    $teacherId = $teacher["id"];

    // Handle booking actions
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $bookingId = filter_input(
            INPUT_POST,
            "booking_id",
            FILTER_VALIDATE_INT
        );

        $action = $_POST["action"] ?? "";

        if (
            !$bookingId ||
            !in_array($action, ["confirm", "cancel", "complete"], true)
        ) {
            throw new Exception("Invalid booking request.");
        }

        $pdo->beginTransaction();

        // Lock booking and verify that it belongs to this teacher
        $stmt = $pdo->prepare(
            "SELECT
                b.id,
                b.booking_status,
                b.meeting_id,
                m.meeting_date,
                m.end_time
             FROM bookings b
             INNER JOIN meetings m ON b.meeting_id = m.id
             WHERE b.id = ? AND m.teacher_id = ?
             FOR UPDATE"
        );

        $stmt->execute([$bookingId, $teacherId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            throw new Exception("Booking not found.");
        }

        if ($action === "confirm") {

            if ($booking["booking_status"] !== "pending") {
                throw new Exception(
                    "Only pending bookings can be confirmed."
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE bookings
                 SET booking_status = 'confirmed'
                 WHERE id = ?"
            );

            $stmt->execute([$bookingId]);

            $message = "Meeting booking confirmed successfully.";

        } elseif ($action === "cancel") {

            if (!in_array(
                $booking["booking_status"],
                ["pending", "confirmed"],
                true
            )) {
                throw new Exception(
                    "This booking cannot be cancelled."
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE bookings
                 SET booking_status = 'cancelled'
                 WHERE id = ?"
            );

            $stmt->execute([$bookingId]);

            // Cancel the meeting slot as well.
            $stmt = $pdo->prepare(
                "UPDATE meetings
                 SET status = 'cancelled'
                 WHERE id = ? AND teacher_id = ?"
            );

            $stmt->execute([
                $booking["meeting_id"],
                $teacherId
            ]);

            $message = "Meeting booking cancelled successfully.";

        } elseif ($action === "complete") {

            if ($booking["booking_status"] !== "confirmed") {
                throw new Exception(
                    "Only confirmed meetings can be marked as completed."
                );
            }

            // Compare meeting end time using Pakistan local time.
            $meetingEnd = new DateTimeImmutable(
                $booking["meeting_date"] . " " . $booking["end_time"],
                new DateTimeZone("Asia/Karachi")
            );

            if ($meetingEnd > $currentDateTime) {
                throw new Exception(
                    "You can mark this meeting as completed only after its scheduled end time."
                );
            }

            // Complete the booking.
            $stmt = $pdo->prepare(
                "UPDATE bookings
                 SET booking_status = 'completed'
                 WHERE id = ?"
            );

            $stmt->execute([$bookingId]);

            // Complete the meeting slot.
            $stmt = $pdo->prepare(
                "UPDATE meetings
                 SET status = 'completed'
                 WHERE id = ? AND teacher_id = ?"
            );

            $stmt->execute([
                $booking["meeting_id"],
                $teacherId
            ]);

            $message = "Meeting marked as completed successfully.";
        }

        $pdo->commit();

        $messageType = "success";
    }

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof PDOException) {
        error_log("Teacher booking error: " . $e->getMessage());
        $message = "A system error occurred. Please try again.";
    } else {
        $message = $e->getMessage();
    }

    $messageType = "error";
}

// Get all bookings belonging to this teacher
$stmt = $pdo->prepare(
    "SELECT
        b.id AS booking_id,
        b.booking_status,
        b.payment_status,
        b.fee_amount,
        b.booked_at,

        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,

        u.full_name AS parent_name,
        u.email AS parent_email,

        m.meeting_date,
        m.start_time,
        m.end_time,
        m.meeting_type,
        m.status AS meeting_status,
        m.meeting_link,
        m.location

     FROM bookings b

     INNER JOIN meetings m
        ON b.meeting_id = m.id

     INNER JOIN students s
        ON b.student_id = s.id

     INNER JOIN users u
        ON b.parent_id = u.id

     WHERE m.teacher_id = ?

     ORDER BY
        m.meeting_date DESC,
        m.start_time DESC"
);

$stmt->execute([$teacherId]);
$bookings = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Bookings | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/teacher.css">

    <style>
        .booking-container {
            max-width: 1150px;
            margin: 40px auto;
            padding: 20px;
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .booking-header h1 {
            margin-bottom: 8px;
        }

        .back-button {
            display: inline-block;
            padding: 10px 18px;
            background: #eef2ff;
            color: #3730a3;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
        }

        .booking-alert {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .booking-alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .booking-alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .booking-table-wrapper {
            overflow-x: auto;
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
        }

        .booking-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .booking-table th,
        .booking-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        .booking-table th {
            background: #f8fafc;
            color: #334155;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status.confirmed {
            background: #dcfce7;
            color: #166534;
        }

        .status.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status.completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .booking-action {
            display: inline-block;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            margin: 3px;
            font-weight: 600;
        }

        .confirm-button {
            background: #16a34a;
        }

        .cancel-button {
            background: #dc2626;
        }

        .complete-button {
            background: #2563eb;
        }

        .join-button {
            display: inline-block;
            background: #0f766e;
            color: white;
            text-decoration: none;
            padding: 9px 13px;
            border-radius: 6px;
            font-weight: 600;
            margin: 3px;
        }

        .join-button:hover {
            background: #115e59;
        }

        .empty-bookings {
            text-align: center;
            padding: 45px 15px;
            color: #64748b;
        }

        @media (max-width: 600px) {
            .booking-container {
                padding: 12px;
                margin: 20px auto;
            }

            .booking-header {
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>

<div class="booking-container">

    <div class="booking-header">

        <div>
            <h1>Manage Meeting Bookings</h1>
            <p>View and manage parent meeting requests.</p>
        </div>

        <a href="teacher_dashboard.php" class="back-button">
            ← Back to Dashboard
        </a>

    </div>

    <?php if ($message !== ""): ?>
        <div class="booking-alert <?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="booking-table-wrapper">

        <?php if (count($bookings) > 0): ?>

            <table class="booking-table">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Parent</th>
                        <th>Meeting Schedule</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($bookings as $booking): ?>

                    <?php
                    $meetingEndTimestamp = strtotime(
                        $booking["meeting_date"] . " " . $booking["end_time"]
                    );

                    $meetingHasEnded = (
                        $meetingEndTimestamp !== false &&
                        $meetingEndTimestamp <= $currentDateTime->getTimestamp()
                    );

                    $meetingLink = trim((string) ($booking["meeting_link"] ?? ""));

                    $validMeetingLink = (
                        filter_var($meetingLink, FILTER_VALIDATE_URL) &&
                        in_array(
                            strtolower((string) parse_url($meetingLink, PHP_URL_SCHEME)),
                            ["http", "https"],
                            true
                        )
                    );
                    ?>

                    <tr>

                        <td>
                            <strong>
                                <?php echo htmlspecialchars($booking["student_name"]); ?>
                            </strong>
                            <br>
                            <small>
                                <?php
                                echo htmlspecialchars(
                                    $booking["class_name"] . " - " .
                                    $booking["section"]
                                );
                                ?>
                            </small>
                            <br>
                            <small>
                                Admission:
                                <?php echo htmlspecialchars($booking["admission_number"]); ?>
                            </small>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($booking["parent_name"]); ?>
                            <br>
                            <small>
                                <?php echo htmlspecialchars($booking["parent_email"]); ?>
                            </small>
                        </td>

                        <td>
                            <?php
                            echo date(
                                "d M Y",
                                strtotime($booking["meeting_date"])
                            );
                            ?>
                            <br>
                            <small>
                                <?php
                                echo date(
                                    "h:i A",
                                    strtotime($booking["start_time"])
                                );
                                ?>
                                -
                                <?php
                                echo date(
                                    "h:i A",
                                    strtotime($booking["end_time"])
                                );
                                ?>
                            </small>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                ucfirst($booking["meeting_type"])
                            ); ?>

                            <?php if (
                                $booking["meeting_type"] === "online" &&
                                $booking["booking_status"] === "confirmed" &&
                                $validMeetingLink
                            ): ?>
                                <br>
                                <a
                                    href="<?php echo htmlspecialchars($meetingLink, ENT_QUOTES, 'UTF-8'); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="join-button"
                                >
                                    Join Meeting
                                </a>
                            <?php elseif (
                                $booking["meeting_type"] === "online" &&
                                $booking["booking_status"] === "confirmed"
                            ): ?>
                                <br>
                                <small>Meeting link not available.</small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="status <?php echo htmlspecialchars($booking["booking_status"]); ?>">
                                <?php echo htmlspecialchars($booking["booking_status"]); ?>
                            </span>
                        </td>

                        <td>

                            <?php if ($booking["booking_status"] === "pending"): ?>

                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="booking_id"
                                           value="<?php echo (int)$booking["booking_id"]; ?>">

                                    <input type="hidden" name="action"
                                           value="confirm">

                                    <button type="submit"
                                            class="booking-action confirm-button">
                                        Confirm
                                    </button>
                                </form>

                            <?php endif; ?>

                            <?php if (in_array(
                                $booking["booking_status"],
                                ["pending", "confirmed"],
                                true
                            )): ?>

                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Are you sure you want to cancel this booking and its meeting slot?');">

                                    <input type="hidden" name="booking_id"
                                           value="<?php echo (int)$booking["booking_id"]; ?>">

                                    <input type="hidden" name="action"
                                           value="cancel">

                                    <button type="submit"
                                            class="booking-action cancel-button">
                                        Cancel
                                    </button>
                                </form>

                            <?php endif; ?>

                            <?php if (
                                $booking["booking_status"] === "confirmed" &&
                                $meetingHasEnded
                            ): ?>

                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Mark this meeting as completed?');">

                                    <input type="hidden" name="booking_id"
                                           value="<?php echo (int)$booking["booking_id"]; ?>">

                                    <input type="hidden" name="action"
                                           value="complete">

                                    <button type="submit"
                                            class="booking-action complete-button">
                                        Mark Completed
                                    </button>
                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php else: ?>

            <div class="empty-bookings">
                <h2>No Meeting Bookings Yet</h2>
                <p>Parent meeting requests will appear here.</p>
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>