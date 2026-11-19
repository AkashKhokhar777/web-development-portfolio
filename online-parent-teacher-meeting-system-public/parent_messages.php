<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once "config/database.php";

// Parent access only
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'parent'
) {
    header("Location: parent.php");
    exit;
}

$parent_id = (int) $_SESSION['user_id'];
$message = "";
$error = "";

// Get parent's linked children
$stmt = $pdo->prepare("
    SELECT id, student_name, admission_number, class_name, section
    FROM students
    WHERE parent_id = ?
    ORDER BY student_name
");
$stmt->execute([$parent_id]);
$children = $stmt->fetchAll();

// Selected child and teacher
$student_id = isset($_REQUEST['student_id'])
    ? (int) $_REQUEST['student_id']
    : (count($children) > 0 ? (int)$children[0]['id'] : 0);

$selected_student = null;

foreach ($children as $child) {
    if ((int)$child['id'] === $student_id) {
        $selected_student = $child;
        break;
    }
}

if (!$selected_student) {
    $student_id = 0;
}

// Get teachers who have meetings booked for this child
$teachers = [];

if ($student_id > 0) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            t.id AS teacher_id,
            u.full_name AS teacher_name,
            t.subject
        FROM bookings b
        INNER JOIN meetings m ON b.meeting_id = m.id
        INNER JOIN teachers t ON m.teacher_id = t.id
        INNER JOIN users u ON t.user_id = u.id
        WHERE b.parent_id = ?
          AND b.student_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$parent_id, $student_id]);
    $teachers = $stmt->fetchAll();
}

// Selected teacher
$teacher_id = isset($_REQUEST['teacher_id'])
    ? (int)$_REQUEST['teacher_id']
    : (count($teachers) > 0 ? (int)$teachers[0]['teacher_id'] : 0);

$selected_teacher = null;

foreach ($teachers as $teacher) {
    if ((int)$teacher['teacher_id'] === $teacher_id) {
        $selected_teacher = $teacher;
        break;
    }
}

if (!$selected_teacher) {
    $teacher_id = 0;
}

// Send message
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['send_message'])) {
    $new_message = trim($_POST['message'] ?? '');

    if (!$selected_student) {
        $error = "Please select a valid child.";
    } elseif (!$selected_teacher) {
        $error = "No valid teacher is available for this student.";
    } elseif ($new_message === "") {
        $error = "Please enter a message.";
    } elseif (strlen($new_message) > 5000) {
        $error = "Message cannot exceed 5000 characters.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO messages
            (parent_id, teacher_id, student_id, sender_role, message)
            VALUES (?, ?, ?, 'parent', ?)
        ");

        $stmt->execute([
            $parent_id,
            $teacher_id,
            $student_id,
            $new_message
        ]);

        header(
            "Location: parent_messages.php?student_id=" .
            $student_id . "&teacher_id=" . $teacher_id .
            "&sent=1"
        );
        exit;
    }
}

if (isset($_GET['sent'])) {
    $message = "Your message was sent successfully.";
}

// Get conversation
$conversation = [];

if ($student_id > 0 && $teacher_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM messages
        WHERE parent_id = ?
          AND student_id = ?
          AND teacher_id = ?
        ORDER BY sent_at ASC, id ASC
    ");

    $stmt->execute([$parent_id, $student_id, $teacher_id]);
    $conversation = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Messages - Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .messages-container {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
        }

        .selection-box,
        .conversation-box {
            background: #fff;
            padding: 22px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }

        label {
            display: block;
            margin: 12px 0 6px;
            font-weight: bold;
        }

        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .send-btn {
            background: #243b64;
            color: white;
            padding: 11px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 10px;
        }

        .message-item {
            padding: 15px;
            margin: 12px 0;
            border-radius: 8px;
            max-width: 85%;
            overflow-wrap: anywhere;
        }

        .parent-message {
            background: #e5efff;
            margin-left: auto;
        }

        .teacher-message {
            background: #e7f6ec;
            margin-right: auto;
        }

        .sender {
            font-weight: bold;
            margin-bottom: 7px;
        }

        .sent-time {
            display: block;
            font-size: 12px;
            color: #666;
            margin-top: 8px;
        }

        .notice {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            background: #e7f6ec;
            color: #176b35;
        }

        .error {
            background: #fde8e8;
            color: #a12626;
        }

        .empty {
            background: #f4f7fb;
            padding: 20px;
            text-align: center;
            border-radius: 7px;
        }

        .back-btn {
            display: inline-block;
            background: #243b64;
            color: white;
            padding: 10px 16px;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>

<div class="messages-container">

    <h1>Online Parent-Teacher Meeting System</h1>

    <a href="parent_dashboard.php" class="back-btn">
        Back to Parent Dashboard
    </a>

    <h2>Discussion with Teacher</h2>

    <p>Send messages to your child's teacher and view their replies.</p>

    <?php if ($message !== ""): ?>
        <div class="notice">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="notice error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($children)): ?>

        <div class="empty">
            No student is linked to your parent account.
        </div>

    <?php else: ?>

        <div class="selection-box">

            <form method="GET">

                <label>Select Child</label>

                <select name="student_id" required>
                    <?php foreach ($children as $child): ?>
                        <option
                            value="<?php echo (int)$child['id']; ?>"
                            <?php echo $student_id == $child['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(
                                $child['student_name'] . " (" .
                                $child['admission_number'] . ")"
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="send-btn">
                    Select Child
                </button>

            </form>

            <?php if ($student_id > 0): ?>

                <?php if (empty($teachers)): ?>

                    <p>No teacher is available for discussion yet. A teacher will appear here after a meeting is booked for this child.</p>

                <?php else: ?>

                    <form method="GET">

                        <input type="hidden"
                               name="student_id"
                               value="<?php echo $student_id; ?>">

                        <label>Select Teacher</label>

                        <select name="teacher_id" required>

                            <?php foreach ($teachers as $teacher): ?>

                                <option
                                    value="<?php echo (int)$teacher['teacher_id']; ?>"
                                    <?php echo $teacher_id == $teacher['teacher_id'] ? 'selected' : ''; ?>>

                                    <?php echo htmlspecialchars(
                                        $teacher['teacher_name'] .
                                        " - " . $teacher['subject']
                                    ); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <button type="submit" class="send-btn">
                            Open Conversation
                        </button>

                    </form>

                <?php endif; ?>

            <?php endif; ?>

        </div>

        <?php if ($selected_teacher): ?>

            <div class="conversation-box">

                <h3>
                    Conversation with
                    <?php echo htmlspecialchars($selected_teacher['teacher_name']); ?>
                </h3>

                <?php if (empty($conversation)): ?>

                    <div class="empty">
                        No messages yet. Start the conversation below.
                    </div>

                <?php else: ?>

                    <?php foreach ($conversation as $chat): ?>

                        <div class="message-item
                            <?php echo $chat['sender_role'] === 'parent'
                                ? 'parent-message'
                                : 'teacher-message'; ?>">

                            <div class="sender">
                                <?php echo $chat['sender_role'] === 'parent'
                                    ? 'You'
                                    : 'Teacher'; ?>
                            </div>

                            <?php echo nl2br(htmlspecialchars($chat['message'])); ?>

                            <span class="sent-time">
                                <?php echo htmlspecialchars($chat['sent_at']); ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

                <form method="POST">

                    <input type="hidden"
                           name="student_id"
                           value="<?php echo $student_id; ?>">

                    <input type="hidden"
                           name="teacher_id"
                           value="<?php echo $teacher_id; ?>">

                    <label>Write Your Message</label>

                    <textarea
                        name="message"
                        rows="4"
                        maxlength="5000"
                        placeholder="Type your message here..."
                        required></textarea>

                    <button type="submit"
                            name="send_message"
                            class="send-btn">
                        Send Message
                    </button>

                </form>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

</body>
</html>