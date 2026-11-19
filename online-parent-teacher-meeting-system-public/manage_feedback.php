<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once "config/database.php";

// Only administrators can access this page
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: admin.php");
    exit;
}

$message = "";

// Delete feedback
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_feedback'])) {
    $feedback_id = filter_input(INPUT_POST, 'feedback_id', FILTER_VALIDATE_INT);

    if ($feedback_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM feedback WHERE id = ?");
            $stmt->execute([$feedback_id]);

            $message = $stmt->rowCount() > 0
                ? "Feedback deleted successfully."
                : "Feedback could not be found.";
        } catch (PDOException $e) {
            $message = "Unable to delete feedback.";
        }
    }
}

// Fetch all parent feedback
try {
    $stmt = $pdo->query("
        SELECT
            f.id,
            f.rating,
            f.comments,
            f.submitted_at,
            parent.full_name AS parent_name,
            student.student_name,
            student.admission_number,
            teacher_user.full_name AS teacher_name,
            meeting.meeting_date,
            meeting.start_time,
            meeting.end_time
        FROM feedback f
        INNER JOIN bookings b ON f.booking_id = b.id
        INNER JOIN users parent ON b.parent_id = parent.id
        INNER JOIN students student ON b.student_id = student.id
        INNER JOIN meetings meeting ON b.meeting_id = meeting.id
        INNER JOIN teachers teacher ON meeting.teacher_id = teacher.id
        INNER JOIN users teacher_user ON teacher.user_id = teacher_user.id
        ORDER BY f.submitted_at DESC
    ");

    $feedback_list = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Unable to load feedback. Please check the database structure.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Parent Feedback - Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .feedback-container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 20px;
        }

        .feedback-card {
            background: #ffffff;
            padding: 22px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        }

        .feedback-card h3 {
            margin-top: 0;
            color: #243b64;
        }

        .feedback-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin: 15px 0;
        }

        .feedback-info p {
            margin: 5px 0;
        }

        .rating {
            color: #d69e00;
            font-weight: bold;
        }

        .comments {
            background: #f4f7fb;
            padding: 15px;
            border-radius: 7px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 5px;
            cursor: pointer;
        }

        .delete-btn:hover {
            background: #b02a37;
        }

        .message {
            background: #e7f6ec;
            color: #176b35;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .empty-message {
            background: #f4f7fb;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
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

        @media (max-width: 600px) {
            .feedback-container {
                padding: 12px;
            }
        }
    </style>
</head>

<body>

<div class="feedback-container">

    <h1>Online Parent-Teacher Meeting System</h1>

    <a href="admin_dashboard.php" class="back-btn">
        Back to Admin Dashboard
    </a>

    <h2>Manage Parent Feedback</h2>

    <p>View and manage feedback submitted by parents after completed meetings.</p>

    <?php if ($message !== ""): ?>
        <div class="message">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <h3>Total Feedback: <?php echo count($feedback_list); ?></h3>

    <?php if (empty($feedback_list)): ?>

        <div class="empty-message">
            <h3>No Parent Feedback Yet</h3>
            <p>Parent feedback will appear here after parents submit feedback for completed meetings.</p>
        </div>

    <?php else: ?>

        <?php foreach ($feedback_list as $feedback): ?>

            <div class="feedback-card">

                <h3>
                    Feedback #<?php echo (int)$feedback['id']; ?>
                </h3>

                <div class="feedback-info">

                    <p>
                        <strong>Parent:</strong>
                        <?php echo htmlspecialchars($feedback['parent_name']); ?>
                    </p>

                    <p>
                        <strong>Student:</strong>
                        <?php echo htmlspecialchars($feedback['student_name']); ?>
                    </p>

                    <p>
                        <strong>Admission Number:</strong>
                        <?php echo htmlspecialchars($feedback['admission_number']); ?>
                    </p>

                    <p>
                        <strong>Teacher:</strong>
                        <?php echo htmlspecialchars($feedback['teacher_name']); ?>
                    </p>

                    <p>
                        <strong>Meeting Date:</strong>
                        <?php echo htmlspecialchars($feedback['meeting_date']); ?>
                    </p>

                    <p>
                        <strong>Meeting Time:</strong>
                        <?php
                        echo htmlspecialchars(
                            date("h:i A", strtotime($feedback['start_time'])) .
                            " - " .
                            date("h:i A", strtotime($feedback['end_time']))
                        );
                        ?>
                    </p>

                    <p>
                        <strong>Submitted:</strong>
                        <?php echo htmlspecialchars($feedback['submitted_at']); ?>
                    </p>

                    <p class="rating">
                        <strong>Rating:</strong>
                        <?php echo (int)$feedback['rating']; ?> / 5
                        <?php echo str_repeat("★", (int)$feedback['rating']); ?>
                    </p>

                </div>

                <h4>Parent Comments</h4>

                <div class="comments">
                    <?php echo nl2br(htmlspecialchars($feedback['comments'])); ?>
                </div>

                <form method="POST"
                      onsubmit="return confirm('Are you sure you want to delete this feedback?');">

                    <input type="hidden"
                           name="feedback_id"
                           value="<?php echo (int)$feedback['id']; ?>">

                    <button type="submit"
                            name="delete_feedback"
                            class="delete-btn">
                        Delete Feedback
                    </button>

                </form>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>