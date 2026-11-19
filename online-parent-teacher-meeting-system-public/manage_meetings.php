<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";
require_once __DIR__ . "/config/database.php";

// Only logged-in teachers can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "teacher"
) {
    header("Location: teacher.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$error = "";
$success = "";

// Find the teacher profile linked to this login.
$stmt = $pdo->prepare(
    "SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1"
);
$stmt->execute(["user_id" => $userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    http_response_code(403);
    exit("Teacher profile not found. Please contact the administrator.");
}

$teacherId = (int) $teacher["id"];

// Create a meeting slot.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $meetingDate = $_POST["meeting_date"] ?? "";
    $startTime = $_POST["start_time"] ?? "";
    $endTime = $_POST["end_time"] ?? "";
    $meetingType = $_POST["meeting_type"] ?? "";
    $meetingLink = trim($_POST["meeting_link"] ?? "");
    $location = trim($_POST["location"] ?? "");

    $validDate = DateTime::createFromFormat("!Y-m-d", $meetingDate);
    $dateIsValid = $validDate &&
        $validDate->format("Y-m-d") === $meetingDate;

    $validTime = static function ($time) {
        return is_string($time) &&
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    };

    if (
        !$dateIsValid ||
        !$validTime($startTime) ||
        !$validTime($endTime) ||
        $startTime >= $endTime
    ) {
        $error = "Please enter a valid date and time. The end time must be after the start time.";
    } elseif ($meetingDate < date("Y-m-d")) {
        $error = "You cannot schedule a meeting in the past.";
    } elseif (!in_array($meetingType, ["online", "in_person"], true)) {
        $error = "Please select a valid meeting type.";
    } elseif (
        $meetingType === "online" &&
        (
            $meetingLink === "" ||
            !filter_var($meetingLink, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string) parse_url($meetingLink, PHP_URL_SCHEME)), ["http", "https"], true)
        )
    ) {
        $error = "For an online meeting, enter a valid link beginning with https:// or http://.";
    } elseif ($meetingType === "in_person" && $location === "") {
        $error = "Please enter the location for the in-person meeting.";
    } else {
        try {
            // Prevent overlapping active slots for the same teacher.
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM meetings
                 WHERE teacher_id = :teacher_id
                   AND meeting_date = :meeting_date
                   AND status IN ('available', 'booked')
                   AND start_time < :end_time
                   AND end_time > :start_time"
            );

            $check->execute([
                "teacher_id" => $teacherId,
                "meeting_date" => $meetingDate,
                "start_time" => $startTime . ":00",
                "end_time" => $endTime . ":00"
            ]);

            if ((int) $check->fetchColumn() > 0) {
                $error = "This time overlaps with another active meeting slot. Please choose a different time.";
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO meetings
                     (teacher_id, meeting_date, start_time, end_time,
                      meeting_type, meeting_link, location, status)
                     VALUES
                     (:teacher_id, :meeting_date, :start_time, :end_time,
                      :meeting_type, :meeting_link, :location, 'available')"
                );

                $insert->execute([
                    "teacher_id" => $teacherId,
                    "meeting_date" => $meetingDate,
                    "start_time" => $startTime . ":00",
                    "end_time" => $endTime . ":00",
                    "meeting_type" => $meetingType,
                    "meeting_link" => $meetingType === "online" ? $meetingLink : null,
                    "location" => $meetingType === "in_person" ? $location : null
                ]);

                header("Location: manage_meetings.php?created=1");
                exit;
            }
        } catch (PDOException $e) {
            error_log("Meeting creation error: " . $e->getMessage());
            $error = "The meeting could not be created. Please try again.";
        }
    }
}

if (isset($_GET["created"])) {
    $success = "Meeting slot created successfully.";
}

// Show only this teacher's meeting slots.
$list = $pdo->prepare(
    "SELECT m.*,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.meeting_id = m.id
               AND b.booking_status IN ('pending', 'confirmed')) AS booking_count
     FROM meetings m
     WHERE m.teacher_id = :teacher_id
     ORDER BY m.meeting_date DESC, m.start_time DESC"
);
$list->execute(["teacher_id" => $teacherId]);
$meetings = $list->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Meetings | Online Parent-Teacher Meeting System</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243047;
        }

        header {
            background: #173b70;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        header h1 { margin: 0; font-size: 24px; }

        header a, .back-link {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        main {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(20, 40, 80, 0.07);
        }

        h2 { margin-top: 0; color: #173b70; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field { display: flex; flex-direction: column; gap: 7px; }
        .full-width { grid-column: 1 / -1; }

        label { font-weight: bold; font-size: 14px; }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5e3;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus, select:focus {
            outline: 2px solid #9bc4ff;
            border-color: #3978c6;
        }

        button {
            border: 0;
            border-radius: 7px;
            background: #2463ad;
            color: white;
            padding: 13px 20px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #174b89; }

        .message {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .error { background: #fff0f0; color: #a32323; }
        .success { background: #eaf8ef; color: #176534; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #e4e9f1;
            text-align: left;
            font-size: 14px;
        }

        th { background: #f0f4fa; }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e6efff;
            color: #214f91;
            font-size: 12px;
            font-weight: bold;
        }

        .back-link { color: #2463ad; }

        @media (max-width: 650px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: auto; }
            .panel { padding: 18px; }
        }
    </style>
</head>
<body>
<header>
    <h1>Online Parent-Teacher Meeting System — Meeting Management</h1>
    <a href="teacher_dashboard.php">Back to Dashboard</a>
</header>

<main>
    <section class="panel">
        <h2>Create Meeting Slot</h2>
        <p>Schedule an available time for a parent-teacher meeting.</p>

        <?php if ($error !== ""): ?>
            <div class="message error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="message success" role="status">
                <?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="manage_meetings.php">
            <div class="form-grid">
                <div class="field">
                    <label for="meeting_date">Meeting Date</label>
                    <input type="date" id="meeting_date" name="meeting_date"
                           min="<?= date("Y-m-d") ?>" required>
                </div>

                <div class="field">
                    <label for="meeting_type">Meeting Type</label>
                    <select id="meeting_type" name="meeting_type" required>
                        <option value="">Select meeting type</option>
                        <option value="online">Online</option>
                        <option value="in_person">In-person</option>
                    </select>
                </div>

                <div class="field">
                    <label for="start_time">Start Time</label>
                    <input type="time" id="start_time" name="start_time" required>
                </div>

                <div class="field">
                    <label for="end_time">End Time</label>
                    <input type="time" id="end_time" name="end_time" required>
                </div>

                <div class="field full-width" id="link_field">
                    <label for="meeting_link">Online Meeting Link</label>
                    <input type="url" id="meeting_link" name="meeting_link"
                           placeholder="https://example.com/meeting">
                </div>

                <div class="field full-width" id="location_field">
                    <label for="location">Physical Location</label>
                    <input type="text" id="location" name="location"
                           maxlength="255" placeholder="e.g. School Room 12">
                </div>

                <div class="field full-width">
                    <button type="submit">Create Meeting Slot</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Your Meeting Slots</h2>

        <?php if (count($meetings) === 0): ?>
            <p>You have not created any meeting slots yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Bookings</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($meetings as $meeting): ?>
                        <tr>
                            <td><?= htmlspecialchars($meeting["meeting_date"], ENT_QUOTES, "UTF-8") ?></td>
                            <td>
                                <?= htmlspecialchars(substr($meeting["start_time"], 0, 5), ENT_QUOTES, "UTF-8") ?>
                                –
                                <?= htmlspecialchars(substr($meeting["end_time"], 0, 5), ENT_QUOTES, "UTF-8") ?>
                            </td>
                            <td><?= $meeting["meeting_type"] === "online" ? "Online" : "In-person" ?></td>
                            <td><?= (int) $meeting["booking_count"] ?></td>
                            <td><span class="status"><?= htmlspecialchars(ucfirst($meeting["status"]), ENT_QUOTES, "UTF-8") ?></span></td>
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