<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: admin.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";
$editEvent = null;

/* Handle form submissions */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "save") {
                $eventId = (int)($_POST["event_id"] ?? 0);
                $title = trim($_POST["title"] ?? "");
                $description = trim($_POST["description"] ?? "");
                $eventDate = $_POST["event_date"] ?? "";
                $startTime = $_POST["start_time"] ?? "";
                $endTime = $_POST["end_time"] ?? "";
                $status = $_POST["status"] ?? "scheduled";

                if (
                    $title === "" ||
                    $eventDate === "" ||
                    $startTime === "" ||
                    $endTime === ""
                ) {
                    throw new Exception("Please fill in all required fields.");
                }

                $date = DateTime::createFromFormat("Y-m-d", $eventDate);
                $start = DateTime::createFromFormat("H:i", $startTime);
                $end = DateTime::createFromFormat("H:i", $endTime);

                if (
                    !$date || $date->format("Y-m-d") !== $eventDate ||
                    !$start || $start->format("H:i") !== $startTime ||
                    !$end || $end->format("H:i") !== $endTime
                ) {
                    throw new Exception("Please enter a valid date and time.");
                }

                if ($endTime <= $startTime) {
                    throw new Exception("End time must be later than start time.");
                }

                if (!in_array($status, ["scheduled", "completed", "cancelled"], true)) {
                    throw new Exception("Invalid event status.");
                }

                if ($eventId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE ptm_events
                        SET title = ?, description = ?, event_date = ?,
                            start_time = ?, end_time = ?, status = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $title,
                        $description !== "" ? $description : null,
                        $eventDate,
                        $startTime,
                        $endTime,
                        $status,
                        $eventId
                    ]);

                    $message = "PTM event updated successfully.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO ptm_events
                        (title, description, event_date, start_time, end_time, status)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $title,
                        $description !== "" ? $description : null,
                        $eventDate,
                        $startTime,
                        $endTime,
                        $status
                    ]);

                    $message = "PTM event created successfully.";
                }
            } elseif ($action === "delete") {
                $eventId = (int)($_POST["event_id"] ?? 0);

                if ($eventId <= 0) {
                    throw new Exception("Invalid event selected.");
                }

                // Existing meeting slots and bookings are preserved.
                $stmt = $pdo->prepare("DELETE FROM ptm_events WHERE id = ?");
                $stmt->execute([$eventId]);

                $message = "PTM event deleted. Its meeting slots and bookings were preserved.";
            }

        } catch (PDOException $ex) {
            error_log("PTM event database error: " . $ex->getMessage());
            $error = "A database error occurred. Please check the event details and try again.";
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    }
}

/* Load event for editing */
if (isset($_GET["edit"])) {
    $editId = (int)$_GET["edit"];

    $stmt = $pdo->prepare("SELECT * FROM ptm_events WHERE id = ?");
    $stmt->execute([$editId]);
    $editEvent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editEvent) {
        $error = "The selected PTM event was not found.";
    }
}

/* Load events and associated meeting-slot counts */
$stmt = $pdo->query("
    SELECT
        e.*,
        COUNT(m.id) AS total_slots,
        SUM(CASE WHEN m.status = 'booked' THEN 1 ELSE 0 END) AS booked_slots
    FROM ptm_events e
    LEFT JOIN meetings m ON m.ptm_event_id = e.id
    GROUP BY e.id
    ORDER BY e.event_date DESC, e.start_time DESC
");

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PTM Event Management - Online Parent-Teacher Meeting System</title>

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

        header {
            background: #1e3a8a;
            color: white;
            padding: 18px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        header a {
            color: #1e3a8a;
            background: white;
            text-decoration: none;
            padding: 9px 15px;
            border-radius: 6px;
            font-weight: bold;
        }

        .container {
            width: 92%;
            max-width: 1200px;
            margin: 30px auto;
        }

        .panel {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,.06);
        }

        h2 {
            margin-bottom: 10px;
        }

        .intro {
            color: #6b7280;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input, textarea, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        button, .btn {
            display: inline-block;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }

        .primary {
            background: #1e3a8a;
            color: white;
        }

        .secondary {
            background: #64748b;
            color: white;
        }

        .danger {
            background: #dc2626;
            color: white;
        }

        .success-message {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        th {
            background: #eff6ff;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .scheduled {
            background: #dbeafe;
            color: #1e40af;
        }

        .completed {
            background: #dcfce7;
            color: #166534;
        }

        .cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .actions {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            align-items: center;
        }

        .inline-form {
            display: inline;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            header {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Online Parent-Teacher Meeting System - PTM Events</h1>
    <a href="admin_dashboard.php">Back to Dashboard</a>
</header>

<main class="container">

    <section class="panel">
        <h2><?= $editEvent ? "Edit PTM Event" : "Create PTM Event" ?></h2>

        <p class="intro">
            Create and manage parent-teacher meeting events.
        </p>

        <?php if ($message !== ""): ?>
            <div class="success-message"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="error-message"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="event_id" value="<?= e($editEvent["id"] ?? 0) ?>">

            <div class="form-grid">

                <div class="field full">
                    <label for="title">Event Title *</label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        maxlength="255"
                        required
                        value="<?= e($editEvent["title"] ?? "") ?>"
                        placeholder="Example: Annual Parent-Teacher Meeting"
                    >
                </div>

                <div class="field full">
                    <label for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="Enter event details..."
                    ><?= e($editEvent["description"] ?? "") ?></textarea>
                </div>

                <div class="field">
                    <label for="event_date">Event Date *</label>
                    <input
                        type="date"
                        id="event_date"
                        name="event_date"
                        required
                        value="<?= e($editEvent["event_date"] ?? "") ?>"
                    >
                </div>

                <div class="field">
                    <label for="status">Event Status *</label>
                    <select id="status" name="status" required>
                        <?php
                        $currentStatus = $editEvent["status"] ?? "scheduled";
                        ?>
                        <option value="scheduled" <?= $currentStatus === "scheduled" ? "selected" : "" ?>>Scheduled</option>
                        <option value="completed" <?= $currentStatus === "completed" ? "selected" : "" ?>>Completed</option>
                        <option value="cancelled" <?= $currentStatus === "cancelled" ? "selected" : "" ?>>Cancelled</option>
                    </select>
                </div>

                <div class="field">
                    <label for="start_time">Start Time *</label>
                    <input
                        type="time"
                        id="start_time"
                        name="start_time"
                        required
                        value="<?= e(isset($editEvent["start_time"]) ? substr($editEvent["start_time"], 0, 5) : "") ?>"
                    >
                </div>

                <div class="field">
                    <label for="end_time">End Time *</label>
                    <input
                        type="time"
                        id="end_time"
                        name="end_time"
                        required
                        value="<?= e(isset($editEvent["end_time"]) ? substr($editEvent["end_time"], 0, 5) : "") ?>"
                    >
                </div>

                <div class="field full">
                    <div class="actions">
                        <button type="submit" class="primary">
                            <?= $editEvent ? "Update PTM Event" : "Create PTM Event" ?>
                        </button>

                        <?php if ($editEvent): ?>
                            <a href="admin_ptm_events.php" class="btn secondary">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </form>
    </section>

    <section class="panel">
        <h2>All PTM Events</h2>

        <p class="intro">
            View event details and the meeting slots linked to each event.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Total Slots</th>
                        <th>Booked Slots</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($events)): ?>
                        <tr>
                            <td colspan="7">No PTM events have been created yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td>
                                    <strong><?= e($event["title"]) ?></strong><br>
                                    <?= e($event["description"] ?? "") ?>
                                </td>

                                <td><?= e($event["event_date"]) ?></td>

                                <td>
                                    <?= e(substr($event["start_time"], 0, 5)) ?>
                                    -
                                    <?= e(substr($event["end_time"], 0, 5)) ?>
                                </td>

                                <td>
                                    <span class="status <?= e($event["status"]) ?>">
                                        <?= e($event["status"]) ?>
                                    </span>
                                </td>

                                <td><?= (int)$event["total_slots"] ?></td>
                                <td><?= (int)$event["booked_slots"] ?></td>

                                <td>
                                    <div class="actions">
                                        <a
                                            class="btn primary"
                                            href="admin_ptm_events.php?edit=<?= (int)$event["id"] ?>"
                                        >Edit</a>

                                        <form
                                            method="POST"
                                            class="inline-form"
                                            onsubmit="return confirm('Delete this PTM event? Existing meeting slots and bookings will be preserved but detached from this event.');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="event_id" value="<?= (int)$event["id"] ?>">
                                            <button type="submit" class="danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>