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

function escapeMeeting($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/**
 * Validate an optional PTM event linked to a meeting slot.
 * A linked slot must use the event date and fit within its time range.
 */
function validatePtmEvent(
    PDO $pdo,
    int $eventId,
    string $meetingDate,
    string $startTime,
    string $endTime,
    string &$error
): bool {

    // An event is optional for existing or independent meeting slots.
    if ($eventId === 0) {
        return true;
    }

    $eventQuery = $pdo->prepare(
        "SELECT id, title, event_date, start_time, end_time, status
         FROM ptm_events
         WHERE id = :id
         LIMIT 1"
    );

    $eventQuery->execute(["id" => $eventId]);
    $event = $eventQuery->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        $error = "The selected PTM event does not exist.";
        return false;
    }

    if ($event["status"] !== "scheduled") {
        $error = "You can only link meeting slots to scheduled PTM events.";
        return false;
    }

    if ($meetingDate !== $event["event_date"]) {
        $error = "The meeting date must match the selected PTM event date.";
        return false;
    }

    $eventStart = substr($event["start_time"], 0, 5);
    $eventEnd = substr($event["end_time"], 0, 5);

    if (
        $startTime < $eventStart ||
        $endTime > $eventEnd
    ) {
        $error = "The meeting slot must fall within the PTM event's start and end times.";
        return false;
    }

    return true;
}

// Process administrator actions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $message = "Invalid request. Please refresh the page.";
        $messageType = "error";
    } else {

        $action = $_POST["action"] ?? "";

        // CREATE MEETING SLOT.
        if ($action === "create") {

            $teacherId = (int)($_POST["teacher_id"] ?? 0);
            $eventId = (int)($_POST["ptm_event_id"] ?? 0);
            $meetingDate = $_POST["meeting_date"] ?? "";
            $startTime = $_POST["start_time"] ?? "";
            $endTime = $_POST["end_time"] ?? "";
            $meetingType = $_POST["meeting_type"] ?? "";
            $meetingLink = trim($_POST["meeting_link"] ?? "");
            $location = trim($_POST["location"] ?? "");

            $date = DateTime::createFromFormat("!Y-m-d", $meetingDate);

            $validDate = $date &&
                $date->format("Y-m-d") === $meetingDate;

            $validTime = function ($time) {
                return is_string($time) &&
                    preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
            };

            if (
                $teacherId <= 0 ||
                $eventId < 0 ||
                !$validDate ||
                $meetingDate < date("Y-m-d") ||
                !$validTime($startTime) ||
                !$validTime($endTime) ||
                $startTime >= $endTime
            ) {
                $message = "Please enter a valid teacher, event, date and time. The end time must be after the start time.";
                $messageType = "error";
            } elseif (!in_array($meetingType, ["online", "in_person"], true)) {
                $message = "Please select a valid meeting type.";
                $messageType = "error";
            } elseif (
                $meetingType === "online" &&
                (
                    $meetingLink === "" ||
                    !filter_var($meetingLink, FILTER_VALIDATE_URL) ||
                    !in_array(
                        strtolower((string)parse_url($meetingLink, PHP_URL_SCHEME)),
                        ["http", "https"],
                        true
                    )
                )
            ) {
                $message = "Please enter a valid online meeting link.";
                $messageType = "error";
            } elseif ($meetingType === "in_person" && $location === "") {
                $message = "Please enter the physical meeting location.";
                $messageType = "error";
            } else {
                try {

                    $eventError = "";

                    if (!validatePtmEvent(
                        $pdo,
                        $eventId,
                        $meetingDate,
                        $startTime,
                        $endTime,
                        $eventError
                    )) {
                        $message = $eventError;
                        $messageType = "error";
                    } else {

                        $teacherCheck = $pdo->prepare(
                            "SELECT id FROM teachers WHERE id = :id LIMIT 1"
                        );
                        $teacherCheck->execute(["id" => $teacherId]);

                        if (!$teacherCheck->fetch()) {
                            $message = "Selected teacher does not exist.";
                            $messageType = "error";
                        } else {

                            $overlap = $pdo->prepare(
                                "SELECT COUNT(*) FROM meetings
                                 WHERE teacher_id = :teacher_id
                                   AND meeting_date = :meeting_date
                                   AND status IN ('available', 'booked')
                                   AND start_time < :end_time
                                   AND end_time > :start_time"
                            );

                            $overlap->execute([
                                "teacher_id" => $teacherId,
                                "meeting_date" => $meetingDate,
                                "start_time" => $startTime . ":00",
                                "end_time" => $endTime . ":00"
                            ]);

                            if ((int)$overlap->fetchColumn() > 0) {
                                $message = "This teacher already has an overlapping active meeting slot.";
                                $messageType = "error";
                            } else {

                                $insert = $pdo->prepare(
                                    "INSERT INTO meetings
                                     (
                                        ptm_event_id,
                                        teacher_id,
                                        meeting_date,
                                        start_time,
                                        end_time,
                                        meeting_type,
                                        meeting_link,
                                        location,
                                        status
                                     )
                                     VALUES
                                     (
                                        :ptm_event_id,
                                        :teacher_id,
                                        :meeting_date,
                                        :start_time,
                                        :end_time,
                                        :meeting_type,
                                        :meeting_link,
                                        :location,
                                        'available'
                                     )"
                                );

                                $insert->execute([
                                    "ptm_event_id" => $eventId > 0 ? $eventId : null,
                                    "teacher_id" => $teacherId,
                                    "meeting_date" => $meetingDate,
                                    "start_time" => $startTime . ":00",
                                    "end_time" => $endTime . ":00",
                                    "meeting_type" => $meetingType,
                                    "meeting_link" => $meetingType === "online" ? $meetingLink : null,
                                    "location" => $meetingType === "in_person" ? $location : null
                                ]);

                                header("Location: admin_meetings.php?created=1");
                                exit;
                            }
                        }
                    }

                } catch (PDOException $e) {
                    error_log("Admin meeting creation error: " . $e->getMessage());
                    $message = "Unable to create the meeting slot.";
                    $messageType = "error";
                }
            }
        }

        // UPDATE MEETING SLOT.
        elseif ($action === "update") {

            $meetingId = (int)($_POST["meeting_id"] ?? 0);
            $teacherId = (int)($_POST["teacher_id"] ?? 0);
            $eventId = (int)($_POST["ptm_event_id"] ?? 0);
            $meetingDate = $_POST["meeting_date"] ?? "";
            $startTime = $_POST["start_time"] ?? "";
            $endTime = $_POST["end_time"] ?? "";
            $meetingType = $_POST["meeting_type"] ?? "";
            $meetingLink = trim($_POST["meeting_link"] ?? "");
            $location = trim($_POST["location"] ?? "");

            $date = DateTime::createFromFormat("!Y-m-d", $meetingDate);

            $validDate = $date &&
                $date->format("Y-m-d") === $meetingDate;

            $validTime = function ($time) {
                return is_string($time) &&
                    preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
            };

            if (
                $meetingId <= 0 ||
                $teacherId <= 0 ||
                $eventId < 0 ||
                !$validDate ||
                $meetingDate < date("Y-m-d") ||
                !$validTime($startTime) ||
                !$validTime($endTime) ||
                $startTime >= $endTime
            ) {
                $message = "Please enter valid meeting information.";
                $messageType = "error";
            } elseif (!in_array($meetingType, ["online", "in_person"], true)) {
                $message = "Invalid meeting type.";
                $messageType = "error";
            } elseif (
                $meetingType === "online" &&
                (
                    $meetingLink === "" ||
                    !filter_var($meetingLink, FILTER_VALIDATE_URL) ||
                    !in_array(
                        strtolower((string)parse_url($meetingLink, PHP_URL_SCHEME)),
                        ["http", "https"],
                        true
                    )
                )
            ) {
                $message = "Please enter a valid online meeting link.";
                $messageType = "error";
            } elseif ($meetingType === "in_person" && $location === "") {
                $message = "Please enter the physical meeting location.";
                $messageType = "error";
            } else {
                try {

                    $current = $pdo->prepare(
                        "SELECT id, status FROM meetings WHERE id = :id LIMIT 1"
                    );
                    $current->execute(["id" => $meetingId]);
                    $existingMeeting = $current->fetch(PDO::FETCH_ASSOC);

                    $eventError = "";

                    if (!$existingMeeting) {
                        $message = "Meeting slot not found.";
                        $messageType = "error";
                    } elseif ($existingMeeting["status"] !== "available") {
                        $message = "Only available meeting slots can be edited. Booked or completed meetings are protected.";
                        $messageType = "error";
                    } elseif (!validatePtmEvent(
                        $pdo,
                        $eventId,
                        $meetingDate,
                        $startTime,
                        $endTime,
                        $eventError
                    )) {
                        $message = $eventError;
                        $messageType = "error";
                    } else {

                        $teacherCheck = $pdo->prepare(
                            "SELECT id FROM teachers WHERE id = :id LIMIT 1"
                        );
                        $teacherCheck->execute(["id" => $teacherId]);

                        if (!$teacherCheck->fetch()) {
                            $message = "Selected teacher does not exist.";
                            $messageType = "error";
                        } else {

                            $overlap = $pdo->prepare(
                                "SELECT COUNT(*) FROM meetings
                                 WHERE teacher_id = :teacher_id
                                   AND meeting_date = :meeting_date
                                   AND id != :meeting_id
                                   AND status IN ('available', 'booked')
                                   AND start_time < :end_time
                                   AND end_time > :start_time"
                            );

                            $overlap->execute([
                                "teacher_id" => $teacherId,
                                "meeting_date" => $meetingDate,
                                "meeting_id" => $meetingId,
                                "start_time" => $startTime . ":00",
                                "end_time" => $endTime . ":00"
                            ]);

                            if ((int)$overlap->fetchColumn() > 0) {
                                $message = "This teacher already has an overlapping active meeting slot.";
                                $messageType = "error";
                            } else {

                                $update = $pdo->prepare(
                                    "UPDATE meetings
                                     SET ptm_event_id = :ptm_event_id,
                                         teacher_id = :teacher_id,
                                         meeting_date = :meeting_date,
                                         start_time = :start_time,
                                         end_time = :end_time,
                                         meeting_type = :meeting_type,
                                         meeting_link = :meeting_link,
                                         location = :location
                                     WHERE id = :id AND status = 'available'"
                                );

                                $update->execute([
                                    "ptm_event_id" => $eventId > 0 ? $eventId : null,
                                    "teacher_id" => $teacherId,
                                    "meeting_date" => $meetingDate,
                                    "start_time" => $startTime . ":00",
                                    "end_time" => $endTime . ":00",
                                    "meeting_type" => $meetingType,
                                    "meeting_link" => $meetingType === "online" ? $meetingLink : null,
                                    "location" => $meetingType === "in_person" ? $location : null,
                                    "id" => $meetingId
                                ]);

                                header("Location: admin_meetings.php?updated=1");
                                exit;
                            }
                        }
                    }

                } catch (PDOException $e) {
                    error_log("Admin meeting update error: " . $e->getMessage());
                    $message = "Unable to update the meeting slot.";
                    $messageType = "error";
                }
            }
        }

        // DELETE MEETING SLOT.
        elseif ($action === "delete") {

            $meetingId = (int)($_POST["meeting_id"] ?? 0);

            if ($meetingId <= 0) {
                $message = "Invalid meeting slot.";
                $messageType = "error";
            } else {
                try {
                    $check = $pdo->prepare(
                        "SELECT m.id, m.status,
                                (SELECT COUNT(*) FROM bookings b
                                 WHERE b.meeting_id = m.id) AS booking_count
                         FROM meetings m
                         WHERE m.id = :id LIMIT 1"
                    );
                    $check->execute(["id" => $meetingId]);
                    $meeting = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$meeting) {
                        $message = "Meeting slot not found.";
                        $messageType = "error";
                    } elseif (
                        $meeting["status"] !== "available" ||
                        (int)$meeting["booking_count"] > 0
                    ) {
                        $message = "This meeting cannot be deleted because it is booked or has booking history. Cancel it through the appropriate booking process instead.";
                        $messageType = "error";
                    } else {
                        $delete = $pdo->prepare(
                            "DELETE FROM meetings
                             WHERE id = :id AND status = 'available'"
                        );
                        $delete->execute(["id" => $meetingId]);

                        header("Location: admin_meetings.php?deleted=1");
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log("Admin meeting deletion error: " . $e->getMessage());
                    $message = "Unable to delete this meeting slot.";
                    $messageType = "error";
                }
            }
        }
    }
}

// Display operation results.
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if (isset($_GET["created"])) {
        $message = "Meeting slot created successfully.";
        $messageType = "success";
    } elseif (isset($_GET["updated"])) {
        $message = "Meeting slot updated successfully.";
        $messageType = "success";
    } elseif (isset($_GET["deleted"])) {
        $message = "Meeting slot deleted successfully.";
        $messageType = "success";
    }
}

// Load teachers for the form.
$teacherQuery = $pdo->query(
    "SELECT t.id, u.full_name, t.employee_number, t.subject
     FROM teachers t
     INNER JOIN users u ON u.id = t.user_id
     WHERE u.role = 'teacher'
     ORDER BY u.full_name"
);
$teacherOptions = $teacherQuery->fetchAll(PDO::FETCH_ASSOC);

// Load PTM events for the form.
$eventQuery = $pdo->query(
    "SELECT id, title, event_date, start_time, end_time, status
     FROM ptm_events
     ORDER BY event_date DESC, start_time DESC"
);
$eventOptions = $eventQuery->fetchAll(PDO::FETCH_ASSOC);

// Load selected meeting for editing.
$editMeeting = null;

if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["edit"]) &&
    ctype_digit($_GET["edit"])
) {
    $editQuery = $pdo->prepare(
        "SELECT * FROM meetings WHERE id = :id LIMIT 1"
    );
    $editQuery->execute(["id" => (int)$_GET["edit"]]);
    $editMeeting = $editQuery->fetch(PDO::FETCH_ASSOC);

    if (!$editMeeting) {
        $message = "Meeting slot not found.";
        $messageType = "error";
    } elseif ($editMeeting["status"] !== "available") {
        $editMeeting = null;
        $message = "Only available meeting slots can be edited.";
        $messageType = "error";
    }
}

// Load all meeting slots, including their linked PTM event title.
$list = $pdo->query(
    "SELECT m.*,
            u.full_name AS teacher_name,
            t.employee_number,
            pe.title AS event_title,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.meeting_id = m.id
               AND b.booking_status IN ('pending', 'confirmed')) AS booking_count,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.meeting_id = m.id) AS total_booking_history
     FROM meetings m
     INNER JOIN teachers t ON t.id = m.teacher_id
     INNER JOIN users u ON u.id = t.user_id
     LEFT JOIN ptm_events pe ON pe.id = m.ptm_event_id
     ORDER BY m.meeting_date DESC, m.start_time DESC"
);
$meetings = $list->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage PTM Meetings | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .page-main {
            max-width: 1150px;
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

        .back-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .content-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .content-card h2 {
            margin-top: 0;
        }

        .help-text {
            color: #64748b;
            line-height: 1.7;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin: 24px 0;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 600;
            color: #334155;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
        }

        .submit-button,
        .cancel-button,
        .edit-button,
        .delete-button {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .submit-button {
            background: #2563eb;
            color: white;
        }

        .cancel-button {
            background: #e2e8f0;
            color: #334155;
            margin-left: 8px;
        }

        .edit-button {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .delete-button {
            background: #fee2e2;
            color: #b91c1c;
        }

        .notice {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.5;
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
            min-width: 1050px;
        }

        th, td {
            padding: 12px;
            text-align: left;
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
            background: #e0f2fe;
            color: #075985;
            font-size: 12px;
            font-weight: 600;
        }

        .status.booked {
            background: #fef3c7;
            color: #92400e;
        }

        .status.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status.completed {
            background: #dcfce7;
            color: #166534;
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-buttons form {
            margin: 0;
        }

        @media (max-width: 650px) {
            .page-main {
                padding: 16px;
            }

            .content-card {
                padding: 18px;
            }

            .form-grid {
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
            <h1>PTM Event and Time Slot Management</h1>
            <p>Create and manage parent-teacher meeting schedules.</p>
        </div>

        <a href="admin_dashboard.php" class="back-link">
            &larr; Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ""): ?>
        <div class="notice <?php echo $messageType === "success" ? "success" : "error"; ?>" role="status">
            <?php echo escapeMeeting($message); ?>
        </div>
    <?php endif; ?>

    <section class="content-card">
        <h2><?php echo $editMeeting ? "Edit Meeting Slot" : "Create PTM Meeting Slot"; ?></h2>

        <p class="help-text">
            Assign a teacher and schedule an online or in-person parent-teacher meeting.
            You can optionally link the slot to a scheduled PTM event.
            When linked, the slot must use the event date and fit within its time range.
        </p>

        <form method="POST" action="admin_meetings.php">
            <input type="hidden" name="csrf_token" value="<?php echo escapeMeeting($_SESSION["csrf_token"]); ?>">
            <input type="hidden" name="action" value="<?php echo $editMeeting ? "update" : "create"; ?>">

            <?php if ($editMeeting): ?>
                <input type="hidden" name="meeting_id" value="<?php echo (int)$editMeeting["id"]; ?>">
            <?php endif; ?>

            <div class="form-grid">

                <div class="form-group">
                    <label for="ptm_event_id">PTM Event (Optional)</label>

                    <select id="ptm_event_id" name="ptm_event_id">
                        <option value="0">No linked event</option>

                        <?php foreach ($eventOptions as $event): ?>
                            <option
                                value="<?php echo (int)$event["id"]; ?>"
                                <?php
                                echo $editMeeting &&
                                    (int)($editMeeting["ptm_event_id"] ?? 0) === (int)$event["id"]
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php
                                echo escapeMeeting(
                                    $event["title"] .
                                    " | " . $event["event_date"] .
                                    " | " . substr($event["start_time"], 0, 5) .
                                    "-" . substr($event["end_time"], 0, 5) .
                                    " | " . ucfirst($event["status"])
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <small class="help-text">
                        Only scheduled events can be selected when saving a linked slot.
                    </small>
                </div>

                <div class="form-group">
                    <label for="teacher_id">Assign Teacher</label>

                    <select id="teacher_id" name="teacher_id" required>
                        <option value="">Select a teacher</option>

                        <?php foreach ($teacherOptions as $teacher): ?>
                            <option
                                value="<?php echo (int)$teacher["id"]; ?>"
                                <?php echo $editMeeting && (int)$editMeeting["teacher_id"] === (int)$teacher["id"] ? "selected" : ""; ?>
                            >
                                <?php
                                echo escapeMeeting(
                                    $teacher["full_name"] . " (" .
                                    $teacher["employee_number"] . ") - " .
                                    $teacher["subject"]
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="meeting_date">Meeting Date</label>

                    <input
                        type="date"
                        id="meeting_date"
                        name="meeting_date"
                        min="<?php echo date("Y-m-d"); ?>"
                        value="<?php echo $editMeeting ? escapeMeeting($editMeeting["meeting_date"]) : ""; ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="start_time">Start Time</label>

                    <input
                        type="time"
                        id="start_time"
                        name="start_time"
                        value="<?php echo $editMeeting ? escapeMeeting(substr($editMeeting["start_time"], 0, 5)) : ""; ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="end_time">End Time</label>

                    <input
                        type="time"
                        id="end_time"
                        name="end_time"
                        value="<?php echo $editMeeting ? escapeMeeting(substr($editMeeting["end_time"], 0, 5)) : ""; ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="meeting_type">Meeting Type</label>

                    <select id="meeting_type" name="meeting_type" required>
                        <option value="">Select meeting type</option>

                        <option value="online" <?php echo $editMeeting && $editMeeting["meeting_type"] === "online" ? "selected" : ""; ?>>
                            Online
                        </option>

                        <option value="in_person" <?php echo $editMeeting && $editMeeting["meeting_type"] === "in_person" ? "selected" : ""; ?>>
                            In-person
                        </option>
                    </select>
                </div>

                <div class="form-group" id="link_field">
                    <label for="meeting_link">Online Meeting Link</label>

                    <input
                        type="url"
                        id="meeting_link"
                        name="meeting_link"
                        placeholder="https://example.com/meeting"
                        value="<?php echo $editMeeting ? escapeMeeting($editMeeting["meeting_link"]) : ""; ?>"
                    >
                </div>

                <div class="form-group" id="location_field">
                    <label for="location">Physical Location</label>

                    <input
                        type="text"
                        id="location"
                        name="location"
                        maxlength="255"
                        placeholder="e.g. School Room 12"
                        value="<?php echo $editMeeting ? escapeMeeting($editMeeting["location"]) : ""; ?>"
                    >
                </div>

            </div>

            <button type="submit" class="submit-button">
                <?php echo $editMeeting ? "Save Changes" : "Create Meeting Slot"; ?>
            </button>

            <?php if ($editMeeting): ?>
                <a href="admin_meetings.php" class="cancel-button">Cancel</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="content-card">
        <h2>All PTM Meeting Slots</h2>

        <p class="help-text">
            View meeting schedules, linked PTM events, assigned teachers, and booking status.
            Booked and completed meeting records are protected from accidental changes.
        </p>

        <?php if (count($meetings) === 0): ?>

            <p>No meeting slots have been created yet.</p>

        <?php else: ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>PTM Event</th>
                            <th>Teacher</th>
                            <th>Employee No.</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Active Bookings</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($meetings as $index => $meeting): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>

                                <td>
                                    <?php
                                    echo $meeting["event_title"]
                                        ? escapeMeeting($meeting["event_title"])
                                        : "Not linked";
                                    ?>
                                </td>

                                <td><?php echo escapeMeeting($meeting["teacher_name"]); ?></td>

                                <td><?php echo escapeMeeting($meeting["employee_number"]); ?></td>

                                <td><?php echo escapeMeeting($meeting["meeting_date"]); ?></td>

                                <td>
                                    <?php echo escapeMeeting(substr($meeting["start_time"], 0, 5)); ?>
                                    –
                                    <?php echo escapeMeeting(substr($meeting["end_time"], 0, 5)); ?>
                                </td>

                                <td>
                                    <?php echo $meeting["meeting_type"] === "online" ? "Online" : "In-person"; ?>
                                </td>

                                <td><?php echo (int)$meeting["booking_count"]; ?></td>

                                <td>
                                    <span class="status <?php echo escapeMeeting($meeting["status"]); ?>">
                                        <?php echo escapeMeeting(ucfirst($meeting["status"])); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-buttons">

                                        <?php if ($meeting["status"] === "available"): ?>

                                            <a
                                                class="edit-button"
                                                href="admin_meetings.php?edit=<?php echo (int)$meeting["id"]; ?>"
                                            >
                                                Edit
                                            </a>

                                            <form
                                                method="POST"
                                                action="admin_meetings.php"
                                                onsubmit="return confirm('Delete this available meeting slot?');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?php echo escapeMeeting($_SESSION["csrf_token"]); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="meeting_id" value="<?php echo (int)$meeting["id"]; ?>">

                                                <button type="submit" class="delete-button">
                                                    Delete
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <span class="help-text">Protected</span>

                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

</main>

<script>
const meetingType = document.getElementById("meeting_type");
const linkField = document.getElementById("link_field");
const locationField = document.getElementById("location_field");
const meetingLink = document.getElementById("meeting_link");
const locationInput = document.getElementById("location");

function updateMeetingFields() {
    const isOnline = meetingType.value === "online";
    const isInPerson = meetingType.value === "in_person";

    linkField.hidden = !isOnline;
    locationField.hidden = !isInPerson;

    meetingLink.required = isOnline;
    locationInput.required = isInPerson;
}

meetingType.addEventListener("change", updateMeetingFields);
updateMeetingFields();
</script>

</body>
</html>