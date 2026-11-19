<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "teacher"
) {
    header("Location: teacher.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$feedbackList = [];
$pageError = "";

try {
    // Find the teacher's profile.
    $stmt = $pdo->prepare(
        "SELECT id
         FROM teachers
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        "user_id" => $_SESSION["user_id"]
    ]);

    $teacher = $stmt->fetch();

    if ($teacher) {
        // Retrieve parent feedback for this teacher's meetings only.
        $stmt = $pdo->prepare(
            "SELECT
                f.id,
                f.rating,
                f.comments,
                f.submitted_at,
                parent.full_name AS parent_name,
                student.student_name,
                student.admission_number,
                m.meeting_date,
                m.start_time,
                m.end_time
             FROM feedback f
             INNER JOIN bookings b
                ON f.booking_id = b.id
             INNER JOIN meetings m
                ON b.meeting_id = m.id
             INNER JOIN users parent
                ON b.parent_id = parent.id
             INNER JOIN students student
                ON b.student_id = student.id
             WHERE m.teacher_id = :teacher_id
             ORDER BY f.submitted_at DESC"
        );

        $stmt->execute([
            "teacher_id" => $teacher["id"]
        ]);

        $feedbackList = $stmt->fetchAll();
    } else {
        $pageError = "Your teacher profile could not be found. Please contact the administrator.";
    }

} catch (PDOException $e) {
    error_log("Teacher parent feedback error: " . $e->getMessage());
    $pageError = "Unable to load parent feedback at this time.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Parent Feedback Review - Online Parent-Teacher Meeting System</title>

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

        .header {
            background: #1e3a8a;
            color: white;
            padding: 18px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 24px;
        }

        .header a {
            background: white;
            color: #1e3a8a;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-weight: bold;
        }

        .header a:hover {
            background: #e5e7eb;
        }

        .container {
            width: 90%;
            max-width: 1100px;
            margin: 35px auto;
        }

        .page-title {
            margin-bottom: 25px;
        }

        .page-title h2 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-title p {
            color: #6b7280;
            line-height: 1.5;
        }

        .feedback-card {
            background: white;
            padding: 24px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .feedback-card h3 {
            color: #1e3a8a;
            margin-bottom: 15px;
            font-size: 20px;
        }

        .feedback-info {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .feedback-info p {
            line-height: 1.5;
        }

        .rating {
            color: #b45309;
            font-weight: bold;
            font-size: 18px;
        }

        .comments {
            background: #f8fafc;
            border-left: 4px solid #1e3a8a;
            padding: 16px;
            border-radius: 6px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .empty {
            background: white;
            padding: 25px;
            border-radius: 10px;
            color: #6b7280;
            text-align: center;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .header {
                flex-direction: column;
                gap: 15px;
                padding: 18px 20px;
            }

            .container {
                width: 92%;
            }

            .feedback-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Online Parent-Teacher Meeting System</h1>
    <a href="teacher_dashboard.php">Back to Dashboard</a>
</header>

<main class="container">

    <div class="page-title">
        <h2>Parent Feedback Review</h2>
        <p>Review feedback submitted by parents after their meetings.</p>
    </div>

    <?php if ($pageError !== ""): ?>

        <div class="error">
            <?= htmlspecialchars($pageError, ENT_QUOTES, "UTF-8") ?>
        </div>

    <?php elseif (empty($feedbackList)): ?>

        <div class="empty">
            <h3>No Parent Feedback Yet</h3>
            <p>Parent feedback for your completed meetings will appear here after submission.</p>
        </div>

    <?php else: ?>

        <?php foreach ($feedbackList as $feedback): ?>

            <section class="feedback-card">

                <h3>
                    Feedback for
                    <?= htmlspecialchars($feedback["student_name"], ENT_QUOTES, "UTF-8") ?>
                </h3>

                <div class="feedback-info">

                    <p>
                        <strong>Parent Name:</strong>
                        <?= htmlspecialchars($feedback["parent_name"], ENT_QUOTES, "UTF-8") ?>
                    </p>

                    <p>
                        <strong>Student Admission Number:</strong>
                        <?= htmlspecialchars($feedback["admission_number"], ENT_QUOTES, "UTF-8") ?>
                    </p>

                    <p>
                        <strong>Meeting Date:</strong>
                        <?= htmlspecialchars($feedback["meeting_date"], ENT_QUOTES, "UTF-8") ?>
                    </p>

                    <p>
                        <strong>Meeting Time:</strong>
                        <?= htmlspecialchars(
                            date("h:i A", strtotime($feedback["start_time"])) .
                            " - " .
                            date("h:i A", strtotime($feedback["end_time"])),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </p>

                    <p>
                        <strong>Submitted On:</strong>
                        <?= htmlspecialchars($feedback["submitted_at"], ENT_QUOTES, "UTF-8") ?>
                    </p>

                    <p class="rating">
                        Rating:
                        <?= (int) $feedback["rating"] ?> / 5
                    </p>

                </div>

                <div class="comments">
                    <strong>Parent Comments:</strong>
                    <p>
                        <?= nl2br(htmlspecialchars($feedback["comments"], ENT_QUOTES, "UTF-8")) ?>
                    </p>
                </div>

            </section>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>