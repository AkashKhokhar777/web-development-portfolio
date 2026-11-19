<?php
session_start();

// Check session inactivity timeout
require_once __DIR__ . "/config/session_timeout.php";

require_once "config/database.php";

// Only logged-in parents can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "parent"
) {
    header("Location: parent.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$parentId = (int) $_SESSION["user_id"];

$error = "";
$success = "";

// --------------------------------------------------
// HANDLE FORM SUBMISSIONS
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    // ----------------------------------------------
    // SUBMIT STUDENT FEE PAYMENT
    // ----------------------------------------------

    if ($action === "submit_fee_payment") {

        $feeId = filter_input(INPUT_POST, "fee_id", FILTER_VALIDATE_INT);
        $paymentMethod = $_POST["payment_method"] ?? "";
        $transactionReference = trim($_POST["transaction_reference"] ?? "");

        $allowedMethods = [
            "bank_transfer",
            "easypaisa",
            "jazzcash"
        ];

        if (!$feeId || !in_array($paymentMethod, $allowedMethods, true)) {
            $error = "Please select a valid fee and payment method.";

        } elseif ($transactionReference === "") {
            $error = "Please enter your transaction reference.";

        } elseif (strlen($transactionReference) > 100) {
            $error = "Transaction reference cannot exceed 100 characters.";

        } else {

            try {
                $pdo->beginTransaction();

                $feeCheck = $pdo->prepare(
                    "SELECT sf.id, sf.amount, sf.fee_status
                     FROM student_fees sf
                     INNER JOIN students s ON s.id = sf.student_id
                     WHERE sf.id = ? AND s.parent_id = ?
                     FOR UPDATE"
                );

                $feeCheck->execute([$feeId, $parentId]);
                $fee = $feeCheck->fetch();

                if (!$fee) {
                    throw new Exception("Fee record not found.");
                }

                if ($fee["fee_status"] !== "pending") {
                    throw new Exception("This fee is not currently pending.");
                }

                $existingPayment = $pdo->prepare(
                    "SELECT id
                     FROM payments
                     WHERE student_fee_id = ?
                     AND payment_status IN ('pending', 'completed')
                     LIMIT 1
                     FOR UPDATE"
                );

                $existingPayment->execute([$feeId]);

                if ($existingPayment->fetch()) {
                    throw new Exception(
                        "A payment has already been submitted for this fee."
                    );
                }

                $insertPayment = $pdo->prepare(
                    "INSERT INTO payments
                    (
                        student_fee_id,
                        amount,
                        payment_method,
                        transaction_reference,
                        payment_status
                    )
                    VALUES (?, ?, ?, ?, 'pending')"
                );

                $insertPayment->execute([
                    $feeId,
                    $fee["amount"],
                    $paymentMethod,
                    $transactionReference
                ]);

                $pdo->commit();

                header("Location: parent_dashboard.php?fee_payment=1");
                exit;

            } catch (Exception $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($ex instanceof PDOException) {
                    error_log("Parent fee payment error: " . $ex->getMessage());
                    $error = "Payment submission failed. Please try again.";
                } else {
                    $error = $ex->getMessage();
                }
            }
        }

    // ----------------------------------------------
    // SUBMIT FEEDBACK
    // ----------------------------------------------

    } elseif ($action === "submit_feedback") {

        $bookingId = filter_input(INPUT_POST, "booking_id", FILTER_VALIDATE_INT);
        $rating = filter_input(INPUT_POST, "rating", FILTER_VALIDATE_INT);
        $comments = trim($_POST["comments"] ?? "");

        if (
            !$bookingId ||
            $rating === false ||
            $rating === null ||
            $rating < 1 ||
            $rating > 5 ||
            strlen($comments) > 2000
        ) {
            $error = "Please provide valid feedback.";

        } else {

            try {
                $pdo->beginTransaction();

                $checkBooking = $pdo->prepare(
                    "SELECT id
                     FROM bookings
                     WHERE id = ?
                     AND parent_id = ?
                     AND booking_status = 'completed'
                     FOR UPDATE"
                );

                $checkBooking->execute([$bookingId, $parentId]);

                if (!$checkBooking->fetch()) {
                    throw new Exception(
                        "You can only submit feedback for completed meetings."
                    );
                }

                $checkFeedback = $pdo->prepare(
                    "SELECT id
                     FROM feedback
                     WHERE booking_id = ?
                     LIMIT 1"
                );

                $checkFeedback->execute([$bookingId]);

                if ($checkFeedback->fetch()) {
                    throw new Exception(
                        "Feedback has already been submitted."
                    );
                }

                $insertFeedback = $pdo->prepare(
                    "INSERT INTO feedback (booking_id, rating, comments)
                     VALUES (?, ?, ?)"
                );

                $insertFeedback->execute([
                    $bookingId,
                    $rating,
                    $comments
                ]);

                $pdo->commit();

                header("Location: parent_dashboard.php?feedback=1");
                exit;

            } catch (Exception $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($ex instanceof PDOException) {
                    error_log("Parent feedback error: " . $ex->getMessage());
                    $error = "Feedback submission failed. Please try again.";
                } else {
                    $error = $ex->getMessage();
                }
            }
        }

    // ----------------------------------------------
    // CANCEL MEETING BOOKING
    // ----------------------------------------------

    } elseif ($action === "cancel_booking") {

        $bookingId = filter_input(INPUT_POST, "booking_id", FILTER_VALIDATE_INT);

        if (!$bookingId) {
            $error = "Invalid booking.";

        } else {

            try {
                $pdo->beginTransaction();

                $bookingCheck = $pdo->prepare(
                    "SELECT
                        b.id,
                        b.meeting_id,
                        b.booking_status,
                        b.payment_status,
                        m.meeting_date
                     FROM bookings b
                     INNER JOIN meetings m ON m.id = b.meeting_id
                     WHERE b.id = ? AND b.parent_id = ?
                     FOR UPDATE"
                );

                $bookingCheck->execute([$bookingId, $parentId]);
                $booking = $bookingCheck->fetch();

                if (!$booking) {
                    throw new Exception("Booking not found.");
                }

                if (!in_array(
                    $booking["booking_status"],
                    ["pending", "confirmed"],
                    true
                )) {
                    throw new Exception("This booking cannot be cancelled.");
                }

                if ($booking["payment_status"] === "paid") {
                    throw new Exception(
                        "This booking cannot be cancelled because payment is recorded."
                    );
                }

                $cancelBooking = $pdo->prepare(
                    "UPDATE bookings
                     SET booking_status = 'cancelled'
                     WHERE id = ?"
                );

                $cancelBooking->execute([$bookingId]);

                if ($booking["meeting_date"] >= date("Y-m-d")) {

                    $updateMeeting = $pdo->prepare(
                        "UPDATE meetings SET status = 'available' WHERE id = ?"
                    );

                } else {

                    $updateMeeting = $pdo->prepare(
                        "UPDATE meetings SET status = 'cancelled' WHERE id = ?"
                    );
                }

                $updateMeeting->execute([$booking["meeting_id"]]);

                $pdo->commit();

                header("Location: parent_dashboard.php?cancelled=1");
                exit;

            } catch (Exception $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $ex->getMessage();
            }
        }

    // ----------------------------------------------
    // BOOK MEETING
    // ----------------------------------------------

    } elseif ($action === "" || $action === "book_meeting") {

        $meetingId = filter_input(INPUT_POST, "meeting_id", FILTER_VALIDATE_INT);
        $studentId = filter_input(INPUT_POST, "student_id", FILTER_VALIDATE_INT);

        if (!$meetingId || !$studentId) {
            $error = "Please select a student and meeting.";

        } else {

            try {
                $pdo->beginTransaction();

                $studentCheck = $pdo->prepare(
                    "SELECT id
                     FROM students
                     WHERE id = ? AND parent_id = ?"
                );

                $studentCheck->execute([$studentId, $parentId]);

                if (!$studentCheck->fetch()) {
                    throw new Exception("Invalid student selection.");
                }

                $feeCheck = $pdo->prepare(
                    "SELECT id
                     FROM student_fees
                     WHERE student_id = ?
                     AND fee_status = 'pending'
                     LIMIT 1"
                );

                $feeCheck->execute([$studentId]);

                if ($feeCheck->fetch()) {
                    throw new Exception(
                        "Meeting booking is restricted because this student has pending school fees. Please submit the fee payment first."
                    );
                }

                $meetingCheck = $pdo->prepare(
                    "SELECT
                        m.id,
                        m.status,
                        m.meeting_date,
                        m.start_time,
                        m.end_time,
                        m.ptm_event_id,
                        pe.status AS event_status,
                        pe.event_date,
                        pe.start_time AS event_start_time,
                        pe.end_time AS event_end_time
                     FROM meetings m
                     LEFT JOIN ptm_events pe ON pe.id = m.ptm_event_id
                     WHERE m.id = ?
                     FOR UPDATE"
                );

                $meetingCheck->execute([$meetingId]);
                $meeting = $meetingCheck->fetch();

                if (!$meeting || $meeting["status"] !== "available") {
                    throw new Exception(
                        "This meeting slot is no longer available."
                    );
                }

                if ($meeting["meeting_date"] < date("Y-m-d")) {
                    throw new Exception("Past meeting slots cannot be booked.");
                }

                if ($meeting["ptm_event_id"] !== null) {

                    if ($meeting["event_status"] !== "scheduled") {
                        throw new Exception(
                            "This meeting cannot be booked because its PTM event is not scheduled."
                        );
                    }

                    if (
                        $meeting["meeting_date"] !== $meeting["event_date"] ||
                        $meeting["start_time"] < $meeting["event_start_time"] ||
                        $meeting["end_time"] > $meeting["event_end_time"]
                    ) {
                        throw new Exception(
                            "This meeting slot no longer matches its PTM event schedule."
                        );
                    }
                }

                $existingBooking = $pdo->prepare(
                    "SELECT id
                     FROM bookings
                     WHERE meeting_id = ?
                     AND booking_status IN ('pending', 'confirmed')
                     LIMIT 1"
                );

                $existingBooking->execute([$meetingId]);

                if ($existingBooking->fetch()) {
                    throw new Exception(
                        "This meeting slot has already been booked."
                    );
                }

                $insertBooking = $pdo->prepare(
                    "INSERT INTO bookings
                    (
                        meeting_id,
                        parent_id,
                        student_id,
                        booking_status,
                        fee_amount,
                        payment_status
                    )
                    VALUES (?, ?, ?, 'pending', 0.00, 'unpaid')"
                );

                $insertBooking->execute([
                    $meetingId,
                    $parentId,
                    $studentId
                ]);

                $updateMeeting = $pdo->prepare(
                    "UPDATE meetings SET status = 'booked' WHERE id = ?"
                );

                $updateMeeting->execute([$meetingId]);

                $pdo->commit();

                header("Location: parent_dashboard.php?booked=1");
                exit;

            } catch (Exception $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $ex->getMessage();
            }
        }

    } else {
        $error = "Invalid request.";
    }
}

// --------------------------------------------------
// SUCCESS MESSAGES
// --------------------------------------------------

if (isset($_GET["booked"])) {
    $success = "Meeting booked successfully.";
}

if (isset($_GET["cancelled"])) {
    $success = "Meeting booking cancelled successfully.";
}

if (isset($_GET["feedback"])) {
    $success = "Feedback submitted successfully.";
}

if (isset($_GET["fee_payment"])) {
    $success = "Payment details submitted successfully. Please wait for admin verification.";
}

// --------------------------------------------------
// LOAD PARENT'S STUDENTS
// --------------------------------------------------

$childStmt = $pdo->prepare(
    "SELECT *
     FROM students
     WHERE parent_id = ?
     ORDER BY student_name"
);

$childStmt->execute([$parentId]);
$children = $childStmt->fetchAll();

// --------------------------------------------------
// LOAD CHILDREN'S ATTENDANCE
// --------------------------------------------------

$attendanceStmt = $pdo->prepare(
    "SELECT
        a.id,
        a.attendance_date,
        a.attendance_status,
        a.remarks,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,
        u.full_name AS teacher_name
     FROM attendance a
     INNER JOIN students s ON s.id = a.student_id
     INNER JOIN teachers t ON t.id = a.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     WHERE s.parent_id = ?
     ORDER BY a.attendance_date DESC, a.id DESC"
);

$attendanceStmt->execute([$parentId]);
$parentAttendance = $attendanceStmt->fetchAll();

// --------------------------------------------------
// LOAD STUDENT ACADEMIC RECORDS
// --------------------------------------------------

$recordStmt = $pdo->prepare(
    "SELECT
        sr.id,
        sr.record_title,
        sr.record_type,
        sr.description,
        sr.record_date,
        sr.file_path,
        sr.original_file_name,
        s.id AS student_id,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,
        u.full_name AS teacher_name
     FROM student_records sr
     INNER JOIN students s ON s.id = sr.student_id
     INNER JOIN teachers t ON t.id = sr.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     WHERE s.parent_id = ?
     ORDER BY sr.record_date DESC, sr.id DESC"
);

$recordStmt->execute([$parentId]);
$academicRecords = $recordStmt->fetchAll();

// --------------------------------------------------
// LOAD PARENT'S CHILDREN'S MARKSHEETS
// --------------------------------------------------

$marksheetStmt = $pdo->prepare(
    "SELECT
        m.id,
        m.exam_name,
        m.exam_date,
        m.remarks,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,
        u.full_name AS teacher_name,
        COALESCE(SUM(ms.total_marks), 0) AS total_marks,
        COALESCE(SUM(ms.obtained_marks), 0) AS obtained_marks
     FROM marksheets m
     INNER JOIN students s ON s.id = m.student_id
     INNER JOIN teachers t ON t.id = m.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     LEFT JOIN marksheet_subjects ms ON ms.marksheet_id = m.id
     WHERE s.parent_id = ?
     GROUP BY
        m.id,
        m.exam_name,
        m.exam_date,
        m.remarks,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,
        u.full_name
     ORDER BY m.exam_date DESC, m.id DESC"
);

$marksheetStmt->execute([$parentId]);
$parentMarksheets = $marksheetStmt->fetchAll();

$subjectStmt = $pdo->prepare(
    "SELECT
        ms.marksheet_id,
        ms.subject_name,
        ms.total_marks,
        ms.obtained_marks
     FROM marksheet_subjects ms
     INNER JOIN marksheets m ON m.id = ms.marksheet_id
     INNER JOIN students s ON s.id = m.student_id
     WHERE s.parent_id = ?
     ORDER BY ms.id ASC"
);

$subjectStmt->execute([$parentId]);
$allSubjectMarks = $subjectStmt->fetchAll();

$subjectsByMarksheet = [];

foreach ($allSubjectMarks as $subject) {
    $subjectsByMarksheet[$subject["marksheet_id"]][] = $subject;
}

// --------------------------------------------------
// LOAD TEACHER FEEDBACK
// --------------------------------------------------

$teacherFeedbackStmt = $pdo->prepare(
    "SELECT
        tf.id,
        tf.feedback_type,
        tf.title,
        tf.comments,
        tf.feedback_date,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,
        u.full_name AS teacher_name
     FROM teacher_feedback tf
     INNER JOIN students s ON s.id = tf.student_id
     INNER JOIN teachers t ON t.id = tf.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     WHERE s.parent_id = ?
     ORDER BY tf.feedback_date DESC, tf.id DESC"
);

$teacherFeedbackStmt->execute([$parentId]);
$teacherFeedback = $teacherFeedbackStmt->fetchAll();

// --------------------------------------------------
// LOAD STUDENT FEES
// --------------------------------------------------

$feeStmt = $pdo->prepare(
    "SELECT
        sf.id AS fee_id,
        sf.fee_title,
        sf.amount,
        sf.due_date,
        sf.fee_status,
        s.student_name,
        p.payment_status,
        p.payment_method,
        p.transaction_reference
     FROM student_fees sf
     INNER JOIN students s ON s.id = sf.student_id
     LEFT JOIN payments p ON p.id = (
         SELECT MAX(p2.id)
         FROM payments p2
         WHERE p2.student_fee_id = sf.id
     )
     WHERE s.parent_id = ?
     ORDER BY sf.due_date ASC, sf.id DESC"
);

$feeStmt->execute([$parentId]);
$parentFees = $feeStmt->fetchAll();

// --------------------------------------------------
// LOAD AVAILABLE MEETINGS
// --------------------------------------------------

$meetingStmt = $pdo->prepare(
    "SELECT
        m.*,
        t.subject,
        u.full_name AS teacher_name,
        pe.title AS event_title
     FROM meetings m
     INNER JOIN teachers t ON t.id = m.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     LEFT JOIN ptm_events pe ON pe.id = m.ptm_event_id
     WHERE m.status = 'available'
     AND m.meeting_date >= CURDATE()
     AND (
        m.ptm_event_id IS NULL
        OR (
            pe.status = 'scheduled'
            AND m.meeting_date = pe.event_date
            AND m.start_time >= pe.start_time
            AND m.end_time <= pe.end_time
        )
     )
     ORDER BY m.meeting_date, m.start_time"
);

$meetingStmt->execute();
$availableMeetings = $meetingStmt->fetchAll();

// --------------------------------------------------
// LOAD PARENT'S BOOKINGS AND FEEDBACK
// --------------------------------------------------

$bookingStmt = $pdo->prepare(
    "SELECT
        b.*,
        s.student_name,
        m.meeting_date,
        m.start_time,
        m.end_time,
        m.meeting_type,
        m.meeting_link,
        m.location,
        m.status AS meeting_status,
        t.subject,
        u.full_name AS teacher_name,
        f.id AS feedback_id,
        f.rating,
        f.comments,
        f.submitted_at
     FROM bookings b
     INNER JOIN students s ON s.id = b.student_id
     INNER JOIN meetings m ON m.id = b.meeting_id
     INNER JOIN teachers t ON t.id = m.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     LEFT JOIN feedback f ON f.booking_id = b.id
     WHERE b.parent_id = ?
     ORDER BY b.booked_at DESC"
);

$bookingStmt->execute([$parentId]);
$bookings = $bookingStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Parent Dashboard | Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #333;
        }

        header {
            background: #173b63;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        header h1 {
            margin: 0;
            font-size: 25px;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 5px;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 30px auto;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .welcome h2 {
            color: #173b63;
            margin-bottom: 8px;
        }

        .panel {
            background: white;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 9px;
            box-shadow: 0 2px 9px rgba(0,0,0,0.07);
        }

        .panel h2 {
            color: #173b63;
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 21px;
        }

        .panel h3 {
            color: #173b63;
        }

        .message {
            padding: 13px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }

        th {
            background: #eaf1f8;
            color: #173b63;
        }

        .btn {
            display: inline-block;
            padding: 9px 14px;
            border: none;
            border-radius: 5px;
            color: white;
            background: #1769aa;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .btn-danger {
            background: #dc3545;
        }

        .btn-success {
            background: #198754;
        }

        .btn:disabled {
            background: #999;
            cursor: not-allowed;
        }

        .discussion-btn {
            display: inline-block;
            background: #198754;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            margin-top: 10px;
        }

        .discussion-btn:hover {
            background: #146c43;
        }

        select, input, textarea {
            width: 100%;
            padding: 10px;
            margin: 7px 0 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        textarea {
            resize: vertical;
        }

        .fee-card,
        .record-card,
        .marksheet-card {
            border: 1px solid #dce4ed;
            border-radius: 7px;
            padding: 17px;
            margin-bottom: 15px;
            background: #fbfdff;
        }

        .fee-card h3,
        .record-card h3,
        .marksheet-card h3 {
            margin-top: 0;
            color: #173b63;
        }

        .fee-card p,
        .record-card p,
        .marksheet-card p {
            margin: 8px 0;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-paid,
        .status-present {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled,
        .status-absent {
            background: #f8d7da;
            color: #721c24;
        }

        .status-completed,
        .status-excused {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-late {
            background: #fff3cd;
            color: #856404;
        }

        .payment-form {
            margin-top: 15px;
            padding: 15px;
            background: #f0f5fa;
            border-radius: 6px;
        }

        .payment-note {
            padding: 12px;
            background: #fff3cd;
            color: #856404;
            border-radius: 5px;
            margin-top: 12px;
            font-size: 14px;
        }

        .meeting-card {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
        }

        .meeting-card h3 {
            color: #173b63;
            margin-top: 0;
        }

        .event-label {
            display: inline-block;
            background: #eaf1f8;
            color: #173b63;
            padding: 6px 10px;
            border-radius: 5px;
            margin: 0 0 10px;
            font-weight: bold;
        }

        .record-type {
            display: inline-block;
            background: #eaf1f8;
            color: #173b63;
            padding: 5px 9px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .marksheet-summary {
            background: #eaf1f8;
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
        }

        .marksheet-summary p {
            margin: 6px 0;
        }

        .feedback-form {
            min-width: 180px;
        }

        .feedback-form label {
            display: block;
            font-weight: 600;
            margin-top: 5px;
        }

        .feedback-display {
            min-width: 180px;
        }

        .feedback-display p {
            margin: 7px 0;
        }

        .empty {
            color: #777;
            padding: 10px 0;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        footer {
            text-align: center;
            padding: 18px;
            background: #173b63;
            color: white;
            margin-top: 40px;
        }

        @media (max-width: 768px) {
            .container {
                width: 94%;
            }

            .panel {
                padding: 15px;
                overflow-x: auto;
            }

            table {
                min-width: 650px;
            }

            header {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Online Parent-Teacher Meeting System</h1>
    <a href="logout.php" class="logout-btn">Logout</a>
</header>

<div class="container">

    <div class="welcome">
        <h2>Welcome to Parent Dashboard</h2>
        <p>Manage your children's school information and parent-teacher meetings.</p>

        <a href="parent_messages.php" class="discussion-btn">
            Discussion with Teacher
        </a>
    </div>

    <?php if ($success): ?>
        <div class="message success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>


    <!-- MY CHILDREN -->

    <section class="panel">

        <h2>My Children</h2>

        <?php if (count($children) > 0): ?>

            <div class="table-wrapper">
                <table>
                    <tr>
                        <th>Student Name</th>
                        <th>Admission Number</th>
                        <th>Class</th>
                        <th>Section</th>
                    </tr>

                    <?php foreach ($children as $child): ?>
                        <tr>
                            <td><?= e($child["student_name"]) ?></td>
                            <td><?= e($child["admission_number"]) ?></td>
                            <td><?= e($child["class_name"]) ?></td>
                            <td><?= e($child["section"]) ?></td>
                        </tr>
                    <?php endforeach; ?>

                </table>
            </div>

        <?php else: ?>

            <p class="empty">No students are linked to your account yet.</p>

        <?php endif; ?>

    </section>


    <!-- CHILDREN'S ATTENDANCE -->

    <section class="panel">

        <h2>My Children's Attendance</h2>

        <p>
            View attendance records shared by your children's teachers.
        </p>

        <?php if (count($parentAttendance) > 0): ?>

            <div class="table-wrapper">
                <table>

                    <tr>
                        <th>Student Name</th>
                        <th>Admission Number</th>
                        <th>Attendance Date</th>
                        <th>Status</th>
                        <th>Teacher</th>
                        <th>Remarks</th>
                    </tr>

                    <?php foreach ($parentAttendance as $attendance): ?>

                        <?php
                        $attendanceStatus = strtolower(
                            $attendance["attendance_status"]
                        );
                        ?>

                        <tr>

                            <td><?= e($attendance["student_name"]) ?></td>

                            <td><?= e($attendance["admission_number"]) ?></td>

                            <td><?= e($attendance["attendance_date"]) ?></td>

                            <td>
                                <span class="status status-<?= e($attendanceStatus) ?>">
                                    <?= e(ucfirst($attendanceStatus)) ?>
                                </span>
                            </td>

                            <td><?= e($attendance["teacher_name"]) ?></td>

                            <td>
                                <?= !empty($attendance["remarks"])
                                    ? nl2br(e($attendance["remarks"]))
                                    : "No remarks" ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </table>
            </div>

        <?php else: ?>

            <p class="empty">
                No attendance records have been shared for your children yet.
            </p>

        <?php endif; ?>

    </section>


    <!-- CHILDREN'S ACADEMIC RECORDS -->

    <section class="panel">

        <h2>My Children's Academic Records</h2>

        <p>
            View test results, assignments, reports, and document information
            shared by your children's teachers.
        </p>

        <?php if (count($academicRecords) > 0): ?>

            <?php foreach ($academicRecords as $record): ?>

                <div class="record-card">

                    <span class="record-type">
                        <?= e(ucwords(str_replace("_", " ", $record["record_type"]))) ?>
                    </span>

                    <h3><?= e($record["record_title"]) ?></h3>

                    <p>
                        <strong>Student:</strong>
                        <?= e($record["student_name"]) ?>
                    </p>

                    <p>
                        <strong>Admission Number:</strong>
                        <?= e($record["admission_number"]) ?>
                    </p>

                    <p>
                        <strong>Class:</strong>
                        <?= e($record["class_name"] . " " . $record["section"]) ?>
                    </p>

                    <p>
                        <strong>Record Date:</strong>
                        <?= e($record["record_date"]) ?>
                    </p>

                    <p>
                        <strong>Teacher:</strong>
                        <?= e($record["teacher_name"]) ?>
                    </p>

                    <?php if (!empty($record["description"])): ?>
                        <p>
                            <strong>Description / Result:</strong><br>
                            <?= nl2br(e($record["description"])) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($record["file_path"])): ?>

                        <p>
                            <strong>Attached PDF:</strong>
                            <?= e($record["original_file_name"] ?: "Academic document.pdf") ?>
                        </p>

                        <a
                            href="download_student_document.php?id=<?= (int)$record["id"] ?>&view=1"
                            class="btn"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            View PDF
                        </a>

                        <a
                            href="download_student_document.php?id=<?= (int)$record["id"] ?>"
                            class="btn btn-success"
                        >
                            Download PDF
                        </a>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty">
                No academic records have been shared by your children's teachers yet.
            </p>

        <?php endif; ?>

    </section>


    <!-- CHILDREN'S MARKSHEETS -->

    <section class="panel">

        <h2>My Children's Marksheets</h2>

        <p>
            View exam results and subject-wise marks entered by teachers.
        </p>

        <?php if (count($parentMarksheets) > 0): ?>

            <?php foreach ($parentMarksheets as $marksheet): ?>

                <?php
                $total = (float) $marksheet["total_marks"];
                $obtained = (float) $marksheet["obtained_marks"];

                $percentage = $total > 0
                    ? ($obtained / $total) * 100
                    : 0;
                ?>

                <div class="marksheet-card">

                    <h3><?= e($marksheet["exam_name"]) ?></h3>

                    <p>
                        <strong>Student:</strong>
                        <?= e($marksheet["student_name"]) ?>
                    </p>

                    <p>
                        <strong>Admission Number:</strong>
                        <?= e($marksheet["admission_number"]) ?>
                    </p>

                    <p>
                        <strong>Class:</strong>
                        <?= e($marksheet["class_name"] . " " . $marksheet["section"]) ?>
                    </p>

                    <p>
                        <strong>Exam Date:</strong>
                        <?= e($marksheet["exam_date"]) ?>
                    </p>

                    <p>
                        <strong>Teacher:</strong>
                        <?= e($marksheet["teacher_name"]) ?>
                    </p>

                    <h4>Subject-wise Results</h4>

                    <div class="table-wrapper">
                        <table>
                            <tr>
                                <th>Subject</th>
                                <th>Total Marks</th>
                                <th>Obtained Marks</th>
                            </tr>

                            <?php
                            $subjects = $subjectsByMarksheet[$marksheet["id"]] ?? [];
                            ?>

                            <?php if (count($subjects) > 0): ?>

                                <?php foreach ($subjects as $subject): ?>
                                    <tr>
                                        <td><?= e($subject["subject_name"]) ?></td>
                                        <td><?= e(number_format((float)$subject["total_marks"], 2)) ?></td>
                                        <td><?= e(number_format((float)$subject["obtained_marks"], 2)) ?></td>
                                    </tr>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="3">No subject marks are available.</td>
                                </tr>

                            <?php endif; ?>

                        </table>
                    </div>

                    <div class="marksheet-summary">

                        <p>
                            <strong>Total Marks:</strong>
                            <?= e(number_format($total, 2)) ?>
                        </p>

                        <p>
                            <strong>Obtained Marks:</strong>
                            <?= e(number_format($obtained, 2)) ?>
                        </p>

                        <p>
                            <strong>Percentage:</strong>
                            <?= e(number_format($percentage, 2)) ?>%
                        </p>

                    </div>

                    <?php if (!empty($marksheet["remarks"])): ?>
                        <p>
                            <strong>Teacher's Remarks:</strong><br>
                            <?= nl2br(e($marksheet["remarks"])) ?>
                        </p>
                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty">
                No marksheets have been shared for your children yet.
            </p>

        <?php endif; ?>

    </section>


    <!-- TEACHER FEEDBACK -->

    <section class="panel">

        <h2>Teacher Feedback About My Children</h2>

        <p>
            View academic and behavioral feedback shared by your children's teachers.
        </p>

        <?php if (count($teacherFeedback) > 0): ?>

            <?php foreach ($teacherFeedback as $item): ?>

                <div class="record-card">

                    <span class="record-type">
                        <?= e(ucfirst($item["feedback_type"])) ?> Feedback
                    </span>

                    <h3><?= e($item["title"]) ?></h3>

                    <p>
                        <strong>Student:</strong>
                        <?= e($item["student_name"]) ?>
                    </p>

                    <p>
                        <strong>Admission Number:</strong>
                        <?= e($item["admission_number"]) ?>
                    </p>

                    <p>
                        <strong>Class:</strong>
                        <?= e($item["class_name"] . " " . $item["section"]) ?>
                    </p>

                    <p>
                        <strong>Teacher:</strong>
                        <?= e($item["teacher_name"]) ?>
                    </p>

                    <p>
                        <strong>Feedback Date:</strong>
                        <?= e($item["feedback_date"]) ?>
                    </p>

                    <p>
                        <strong>Feedback Details:</strong><br>
                        <?= nl2br(e($item["comments"])) ?>
                    </p>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty">
                No academic or behavioral feedback has been shared for your children yet.
            </p>

        <?php endif; ?>

    </section>


    <!-- STUDENT FEES -->

    <section class="panel">

        <h2>My Student Fees</h2>

        <p>View your children's pending school fees and submit payment details.</p>

        <?php if (count($parentFees) > 0): ?>

            <?php foreach ($parentFees as $fee): ?>

                <div class="fee-card">

                    <h3><?= e($fee["fee_title"]) ?></h3>

                    <p>
                        <strong>Student:</strong>
                        <?= e($fee["student_name"]) ?>
                    </p>

                    <p>
                        <strong>Amount:</strong>
                        PKR <?= number_format((float)$fee["amount"], 2) ?>
                    </p>

                    <p>
                        <strong>Due Date:</strong>
                        <?= e($fee["due_date"]) ?>
                    </p>

                    <p>
                        <strong>Fee Status:</strong>

                        <?php if ($fee["fee_status"] === "paid"): ?>

                            <span class="status status-paid">Paid</span>

                        <?php elseif ($fee["fee_status"] === "waived"): ?>

                            <span class="status status-completed">Waived</span>

                        <?php else: ?>

                            <span class="status status-pending">Pending</span>

                        <?php endif; ?>

                    </p>

                    <?php if ($fee["fee_status"] === "pending"): ?>

                        <?php if ($fee["payment_status"] === "pending"): ?>

                            <div class="payment-note">
                                Your payment details have been submitted.
                                Please wait for admin verification.
                            </div>

                        <?php elseif ($fee["payment_status"] === "completed"): ?>

                            <div class="payment-note">
                                Payment has been recorded as completed.
                                Please contact the school if your fee status
                                has not been updated.
                            </div>

                        <?php else: ?>

                            <div class="payment-form">

                                <h4>Submit Fee Payment</h4>

                                <form method="POST" action="parent_dashboard.php">

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="submit_fee_payment"
                                    >

                                    <input
                                        type="hidden"
                                        name="fee_id"
                                        value="<?= (int)$fee["fee_id"] ?>"
                                    >

                                    <label>Payment Method</label>

                                    <select name="payment_method" required>
                                        <option value="">Select payment method</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="easypaisa">Easypaisa</option>
                                        <option value="jazzcash">JazzCash</option>
                                    </select>

                                    <label>Transaction Reference</label>

                                    <input
                                        type="text"
                                        name="transaction_reference"
                                        maxlength="100"
                                        required
                                        placeholder="Enter transaction ID or reference number"
                                    >

                                    <button type="submit" class="btn btn-success">
                                        Submit Payment
                                    </button>

                                </form>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty">
                No student fees have been assigned to your children yet.
            </p>

        <?php endif; ?>

        <div class="payment-note">
            Payment submissions are recorded for school administration
            verification. A real payment gateway is not connected to this
            demonstration system.
        </div>

    </section>


    <!-- AVAILABLE MEETINGS -->

    <section class="panel">

        <h2>Available Meetings</h2>

        <?php if (count($availableMeetings) > 0): ?>

            <?php foreach ($availableMeetings as $meeting): ?>

                <div class="meeting-card">

                    <h3>
                        Meeting with <?= e($meeting["teacher_name"]) ?>
                    </h3>

                    <?php if (!empty($meeting["event_title"])): ?>

                        <p class="event-label">
                            PTM Event:
                            <?= e($meeting["event_title"]) ?>
                        </p>

                    <?php else: ?>

                        <p>
                            <strong>PTM Event:</strong>
                            Not linked
                        </p>

                    <?php endif; ?>

                    <p>
                        <strong>Subject:</strong>
                        <?= e($meeting["subject"]) ?>
                    </p>

                    <p>
                        <strong>Date:</strong>
                        <?= e($meeting["meeting_date"]) ?>
                    </p>

                    <p>
                        <strong>Time:</strong>
                        <?= e($meeting["start_time"]) ?>
                        -
                        <?= e($meeting["end_time"]) ?>
                    </p>

                    <p>
                        <strong>Meeting Type:</strong>
                        <?= e($meeting["meeting_type"]) ?>
                    </p>

                    <?php if ($meeting["meeting_type"] === "in_person"): ?>
                        <p>
                            <strong>Location:</strong>
                            <?= e($meeting["location"] ?? "") ?>
                        </p>
                    <?php endif; ?>

                    <form method="POST" action="parent_dashboard.php">

                        <input type="hidden" name="action" value="book_meeting">

                        <input
                            type="hidden"
                            name="meeting_id"
                            value="<?= (int)$meeting["id"] ?>"
                        >

                        <label>Select Student</label>

                        <select name="student_id" required>
                            <option value="">Select your child</option>

                            <?php foreach ($children as $child): ?>
                                <option value="<?= (int)$child["id"] ?>">
                                    <?= e($child["student_name"]) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                        <button
                            type="submit"
                            class="btn"
                            <?= count($children) === 0 ? "disabled" : "" ?>
                        >
                            Book Meeting
                        </button>

                    </form>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p class="empty">
                No available meeting slots at the moment.
            </p>

        <?php endif; ?>

    </section>


    <!-- MY BOOKINGS -->

    <section class="panel">

        <h2>My Bookings</h2>

        <?php if (count($bookings) > 0): ?>

            <div class="table-wrapper">
                <table>

                    <tr>
                        <th>Student</th>
                        <th>Teacher</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Meeting</th>
                        <th>Action</th>
                    </tr>

                    <?php foreach ($bookings as $booking): ?>

                        <tr>

                            <td><?= e($booking["student_name"]) ?></td>

                            <td><?= e($booking["teacher_name"]) ?></td>

                            <td><?= e($booking["meeting_date"]) ?></td>

                            <td>
                                <?= e($booking["start_time"]) ?>
                                -
                                <?= e($booking["end_time"]) ?>
                            </td>

                            <td><?= e($booking["meeting_type"]) ?></td>

                            <td><?= e(ucfirst($booking["booking_status"])) ?></td>

                            <td>

                                <?php if (
                                    $booking["meeting_type"] === "online" &&
                                    !empty($booking["meeting_link"]) &&
                                    in_array(
                                        $booking["booking_status"],
                                        ["confirmed", "completed"],
                                        true
                                    )
                                ): ?>

                                    <a
                                        href="<?= e($booking["meeting_link"]) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Meeting Link
                                    </a>

                                <?php elseif ($booking["meeting_type"] === "in_person"): ?>

                                    <?= e($booking["location"] ?? "") ?>

                                <?php else: ?>

                                    Not available

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if (
                                    in_array(
                                        $booking["booking_status"],
                                        ["pending", "confirmed"],
                                        true
                                    ) &&
                                    $booking["payment_status"] !== "paid"
                                ): ?>

                                    <form
                                        method="POST"
                                        action="parent_dashboard.php"
                                        onsubmit="return confirm('Are you sure you want to cancel this booking?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="cancel_booking"
                                        >

                                        <input
                                            type="hidden"
                                            name="booking_id"
                                            value="<?= (int)$booking["id"] ?>"
                                        >

                                        <button type="submit" class="btn btn-danger">
                                            Cancel
                                        </button>

                                    </form>

                                <?php elseif (
                                    $booking["booking_status"] === "completed"
                                ): ?>

                                    <?php if ($booking["feedback_id"] !== null): ?>

                                        <div class="feedback-display">

                                            <span class="status status-completed">
                                                Feedback Submitted
                                            </span>

                                            <p>
                                                <strong>Rating:</strong>
                                                <?= (int)$booking["rating"] ?>/5
                                            </p>

                                            <p>
                                                <strong>Comments:</strong><br>
                                                <?= nl2br(e($booking["comments"] ?? "")) ?>
                                            </p>

                                        </div>

                                    <?php else: ?>

                                        <form
                                            method="POST"
                                            action="parent_dashboard.php"
                                            class="feedback-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="submit_feedback"
                                            >

                                            <input
                                                type="hidden"
                                                name="booking_id"
                                                value="<?= (int)$booking["id"] ?>"
                                            >

                                            <label>Rating</label>

                                            <select name="rating" required>
                                                <option value="">Select rating</option>
                                                <option value="5">5 - Excellent</option>
                                                <option value="4">4 - Very Good</option>
                                                <option value="3">3 - Good</option>
                                                <option value="2">2 - Fair</option>
                                                <option value="1">1 - Poor</option>
                                            </select>

                                            <label>Comments</label>

                                            <textarea
                                                name="comments"
                                                rows="3"
                                                maxlength="2000"
                                                placeholder="Write your feedback..."
                                            ></textarea>

                                            <button
                                                type="submit"
                                                class="btn btn-success"
                                            >
                                                Submit Feedback
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                <?php else: ?>

                                    -

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </table>
            </div>

        <?php else: ?>

            <p class="empty">
                You have not booked any meetings yet.
            </p>

        <?php endif; ?>

    </section>

</div>

<footer>
    <p>
        &copy; <?= date("Y") ?> Online Parent-Teacher Meeting System |
        Online Parent-Teacher Meeting System
    </p>
</footer>

</body>
</html>