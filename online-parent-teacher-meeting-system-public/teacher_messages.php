<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once "config/database.php";

// Only teachers can access this page.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: teacher.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Get the logged-in teacher's database ID.
$teacherStmt = $pdo->prepare("
    SELECT id, user_id
    FROM teachers
    WHERE user_id = ?
    LIMIT 1
");
$teacherStmt->execute([$_SESSION['user_id']]);
$teacher = $teacherStmt->fetch();

if (!$teacher) {
    die("Teacher profile not found. Please contact the administrator.");
}

$teacherId = (int)$teacher['id'];
$success = "";
$error = "";

// Get selected conversation.
$selectedParentId = isset($_GET['parent_id'])
    ? (int)$_GET['parent_id'] : 0;

$selectedStudentId = isset($_GET['student_id'])
    ? (int)$_GET['student_id'] : 0;

// Send teacher reply.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {

    $parentId = (int)($_POST['parent_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if ($parentId <= 0 || $studentId <= 0 || $message === '') {
        $error = "Please select a conversation and enter your message.";
    } else {

        // Confirm that this conversation belongs to this teacher.
        $checkStmt = $pdo->prepare("
            SELECT id
            FROM messages
            WHERE teacher_id = ?
              AND parent_id = ?
              AND student_id = ?
            LIMIT 1
        ");

        $checkStmt->execute([
            $teacherId,
            $parentId,
            $studentId
        ]);

        if (!$checkStmt->fetch()) {
            $error = "This conversation was not found.";
        } else {

            $insertStmt = $pdo->prepare("
                INSERT INTO messages
                (parent_id, teacher_id, student_id, sender_role, message)
                VALUES (?, ?, ?, 'teacher', ?)
            ");

            $insertStmt->execute([
                $parentId,
                $teacherId,
                $studentId,
                $message
            ]);

            header(
                "Location: teacher_messages.php?parent_id=" .
                $parentId .
                "&student_id=" .
                $studentId .
                "&sent=1"
            );
            exit;
        }
    }
}

if (isset($_GET['sent'])) {
    $success = "Your reply has been sent successfully.";
}

// Get all conversations for this teacher.
$threadsStmt = $pdo->prepare("
    SELECT DISTINCT
        m.parent_id,
        m.student_id,
        u.full_name AS parent_name,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section
    FROM messages m
    INNER JOIN users u ON m.parent_id = u.id
    INNER JOIN students s ON m.student_id = s.id
    WHERE m.teacher_id = ?
    ORDER BY u.full_name, s.student_name
");

$threadsStmt->execute([$teacherId]);
$threads = $threadsStmt->fetchAll();

// Validate selected conversation.
$selectedThread = null;

foreach ($threads as $thread) {
    if (
        (int)$thread['parent_id'] === $selectedParentId &&
        (int)$thread['student_id'] === $selectedStudentId
    ) {
        $selectedThread = $thread;
        break;
    }
}

// Load conversation messages.
$conversation = [];

if ($selectedThread) {

    $messagesStmt = $pdo->prepare("
        SELECT
            id,
            sender_role,
            message,
            sent_at
        FROM messages
        WHERE teacher_id = ?
          AND parent_id = ?
          AND student_id = ?
        ORDER BY sent_at ASC, id ASC
    ");

    $messagesStmt->execute([
        $teacherId,
        $selectedParentId,
        $selectedStudentId
    ]);

    $conversation = $messagesStmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Teacher Messages | Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f6fb;
            color: #263238;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 25px;
        }

        h1 {
            margin: 0;
            color: #263b63;
        }

        h2 {
            color: #263b63;
            margin-top: 0;
        }

        .back-btn {
            display: inline-block;
            padding: 10px 16px;
            background: #263b63;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }

        .panel {
            background: white;
            padding: 22px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }

        .conversation-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 12px;
        }

        .conversation-card {
            display: block;
            padding: 15px;
            border: 1px solid #dce2ec;
            border-radius: 8px;
            text-decoration: none;
            color: #263238;
            background: #fff;
        }

        .conversation-card:hover,
        .conversation-card.active {
            border-color: #315caa;
            background: #eef4ff;
        }

        .conversation-card strong {
            display: block;
            margin-bottom: 7px;
            color: #263b63;
        }

        .conversation-card small {
            color: #657185;
            line-height: 1.6;
        }

        .message-box {
            background: #f7f9fc;
            border: 1px solid #e1e6ef;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 12px;
            max-width: 85%;
            overflow-wrap: anywhere;
        }

        .message-box.parent {
            margin-right: auto;
            border-left: 4px solid #315caa;
        }

        .message-box.teacher {
            margin-left: auto;
            border-left: 4px solid #21966b;
            background: #effaf5;
        }

        .message-box strong {
            color: #263b63;
        }

        .message-box p {
            white-space: pre-wrap;
            margin: 10px 0;
            line-height: 1.5;
        }

        .message-box small {
            color: #788395;
        }

        textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #cbd3df;
            border-radius: 7px;
            font-family: Arial, sans-serif;
            font-size: 15px;
            resize: vertical;
        }

        button {
            background: #263b63;
            color: white;
            border: none;
            padding: 12px 22px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            margin-top: 10px;
        }

        button:hover {
            background: #1b2d4c;
        }

        .success {
            background: #e5f7ec;
            color: #176b3a;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .error {
            background: #fdeaea;
            color: #a52a2a;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .empty {
            color: #687386;
            padding: 15px 0;
        }

        @media (max-width: 600px) {
            body {
                padding: 14px;
            }

            .message-box {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>Online Parent-Teacher Meeting System</h1>
        <a href="teacher_dashboard.php" class="back-btn">
            Back to Dashboard
        </a>
    </div>

    <section class="panel">

        <h2>Parent Messages</h2>

        <p>
            View messages from parents and reply to their questions
            about their children's academic progress.
        </p>

        <?php if ($success): ?>
            <div class="success">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <h3>Parent Conversations</h3>

        <?php if (count($threads) > 0): ?>

            <div class="conversation-list">

                <?php foreach ($threads as $thread): ?>

                    <?php
                    $isActive =
                        (int)$thread['parent_id'] === $selectedParentId &&
                        (int)$thread['student_id'] === $selectedStudentId;
                    ?>

                    <a
                        class="conversation-card <?= $isActive ? 'active' : '' ?>"
                        href="teacher_messages.php?parent_id=<?= (int)$thread['parent_id'] ?>&student_id=<?= (int)$thread['student_id'] ?>"
                    >

                        <strong>
                            <?= e($thread['parent_name']) ?>
                        </strong>

                        <small>
                            Student: <?= e($thread['student_name']) ?><br>
                            Admission No: <?= e($thread['admission_number']) ?><br>
                            Class: <?= e($thread['class_name']) ?>
                            <?= e($thread['section']) ?>
                        </small>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="empty">
                No parent messages have been received yet.
                Conversations will appear here when parents contact you.
            </p>

        <?php endif; ?>

    </section>

    <?php if ($selectedThread): ?>

        <section class="panel">

            <h2>Conversation with <?= e($selectedThread['parent_name']) ?></h2>

            <p>
                Student: <strong><?= e($selectedThread['student_name']) ?></strong>
                (<?= e($selectedThread['admission_number']) ?>)
            </p>

            <hr>

            <?php if (count($conversation) > 0): ?>

                <?php foreach ($conversation as $msg): ?>

                    <div class="message-box <?= $msg['sender_role'] === 'teacher' ? 'teacher' : 'parent' ?>">

                        <strong>
                            <?= $msg['sender_role'] === 'teacher' ? 'You (Teacher)' : 'Parent' ?>
                        </strong>

                        <p><?= e($msg['message']) ?></p>

                        <small>
                            <?= e($msg['sent_at']) ?>
                        </small>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty">No messages in this conversation yet.</p>

            <?php endif; ?>

            <hr>

            <h3>Write Your Reply</h3>

            <form method="POST">

                <input
                    type="hidden"
                    name="parent_id"
                    value="<?= (int)$selectedParentId ?>"
                >

                <input
                    type="hidden"
                    name="student_id"
                    value="<?= (int)$selectedStudentId ?>"
                >

                <textarea
                    name="message"
                    placeholder="Write your reply to the parent..."
                    required
                ></textarea>

                <button type="submit" name="send_message">
                    Send Reply
                </button>

            </form>

        </section>

    <?php endif; ?>

</div>

</body>
</html>