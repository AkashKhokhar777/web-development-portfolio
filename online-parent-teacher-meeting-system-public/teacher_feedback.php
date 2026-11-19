<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once "config/database.php";

// Only logged-in teachers can access this page.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: teacher.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$success = "";
$error = "";

// Get the current teacher's profile.
$stmt = $pdo->prepare("
    SELECT id
    FROM teachers
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id = $teacher['id'];

// Save feedback.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $feedback_type = $_POST['feedback_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $comments = trim($_POST['comments'] ?? '');
    $feedback_date = $_POST['feedback_date'] ?? '';

    if (!$student_id || $title === '' || $comments === '' || $feedback_date === '') {
        $error = "Please fill in all required fields.";
    } elseif (!in_array($feedback_type, ['academic', 'behavioral'], true)) {
        $error = "Please select a valid feedback type.";
    } else {

        // Confirm that the selected student exists.
        $check = $pdo->prepare("
            SELECT id
            FROM students
            WHERE id = ?
        ");
        $check->execute([$student_id]);

        if (!$check->fetch()) {
            $error = "Selected student was not found.";
        } else {
            try {
                $insert = $pdo->prepare("
                    INSERT INTO teacher_feedback
                    (student_id, teacher_id, feedback_type, title, comments, feedback_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $student_id,
                    $teacher_id,
                    $feedback_type,
                    $title,
                    $comments,
                    $feedback_date
                ]);

                header("Location: teacher_feedback.php?success=1");
                exit;

            } catch (PDOException $e) {
                $error = "Unable to save feedback. Please try again.";
            }
        }
    }
}

if (isset($_GET['success'])) {
    $success = "Feedback has been shared successfully.";
}

// Load students.
$stmt = $pdo->query("
    SELECT id, student_name, admission_number, class_name, section
    FROM students
    ORDER BY student_name ASC
");
$students = $stmt->fetchAll();

// Load feedback created by this teacher.
$stmt = $pdo->prepare("
    SELECT
        tf.*,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section
    FROM teacher_feedback tf
    INNER JOIN students s ON tf.student_id = s.id
    WHERE tf.teacher_id = ?
    ORDER BY tf.created_at DESC
");
$stmt->execute([$teacher_id]);
$feedback_list = $stmt->fetchAll();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Student Feedback | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/teacher.css">

    <style>
        .feedback-container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 25px;
        }

        .feedback-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.07);
        }

        .feedback-card h2 {
            margin-bottom: 20px;
        }

        .feedback-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .save-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 22px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: 600;
        }

        .save-btn:hover {
            background: #1d4ed8;
        }

        .message {
            padding: 12px 15px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .success-message {
            background: #dcfce7;
            color: #166534;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
        }

        .feedback-table {
            width: 100%;
            border-collapse: collapse;
        }

        .feedback-table th,
        .feedback-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        .feedback-table th {
            background: #f3f4f6;
        }

        .feedback-type {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 15px;
            background: #e0e7ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: 600;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 650px) {
            .feedback-form {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: auto;
            }

            .feedback-table {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>

<div class="feedback-container">

    <a href="teacher_dashboard.php" class="back-link">
        &larr; Back to Teacher Dashboard
    </a>

    <h1>Student Feedback</h1>
    <p>Share academic and behavioral feedback with students' parents.</p>

    <?php if ($success): ?>
        <div class="message success-message">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error-message">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="feedback-card">

        <h2>Share New Feedback</h2>

        <?php if (count($students) > 0): ?>

            <form method="POST" class="feedback-form">

                <div class="form-group full-width">
                    <label for="student_id">Select Student</label>

                    <select name="student_id" id="student_id" required>
                        <option value="">-- Select Student --</option>

                        <?php foreach ($students as $student): ?>
                            <option value="<?= e($student['id']) ?>"
                                <?= (isset($_POST['student_id']) &&
                                    (string)$_POST['student_id'] === (string)$student['id'])
                                    ? 'selected' : '' ?>>

                                <?= e($student['student_name']) ?>
                                - <?= e($student['admission_number']) ?>
                                (<?= e($student['class_name']) ?>
                                <?= e($student['section']) ?>)

                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="form-group">
                    <label for="feedback_type">Feedback Type</label>

                    <select name="feedback_type" id="feedback_type" required>
                        <option value="">-- Select Type --</option>
                        <option value="academic">Academic</option>
                        <option value="behavioral">Behavioral</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="feedback_date">Feedback Date</label>

                    <input
                        type="date"
                        name="feedback_date"
                        id="feedback_date"
                        value="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <div class="form-group full-width">
                    <label for="title">Feedback Title</label>

                    <input
                        type="text"
                        name="title"
                        id="title"
                        maxlength="150"
                        placeholder="e.g. Excellent Classroom Performance"
                        value="<?= e($_POST['title'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group full-width">
                    <label for="comments">Feedback Details</label>

                    <textarea
                        name="comments"
                        id="comments"
                        placeholder="Write academic progress or behavioral observations..."
                        required
                    ><?= e($_POST['comments'] ?? '') ?></textarea>
                </div>

                <div class="form-group full-width">
                    <button type="submit" class="save-btn">
                        Share Feedback with Parent
                    </button>
                </div>

            </form>

        <?php else: ?>

            <p>No students are available. Please contact the administrator.</p>

        <?php endif; ?>

    </div>

    <div class="feedback-card">

        <h2>Feedback Shared by Me</h2>

        <?php if (count($feedback_list) > 0): ?>

            <div style="overflow-x:auto;">

                <table class="feedback-table">

                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Details</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($feedback_list as $feedback): ?>

                            <tr>
                                <td>
                                    <strong><?= e($feedback['student_name']) ?></strong><br>
                                    <?= e($feedback['admission_number']) ?><br>
                                    <?= e($feedback['class_name']) ?>
                                    <?= e($feedback['section']) ?>
                                </td>

                                <td>
                                    <span class="feedback-type">
                                        <?= e(ucfirst($feedback['feedback_type'])) ?>
                                    </span>
                                </td>

                                <td><?= e($feedback['title']) ?></td>

                                <td><?= nl2br(e($feedback['comments'])) ?></td>

                                <td><?= e($feedback['feedback_date']) ?></td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p>You have not shared any feedback yet.</p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>