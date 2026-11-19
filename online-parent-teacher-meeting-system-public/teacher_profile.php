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

$success = "";
$error = "";

// Get the logged-in teacher's profile.
$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        t.id AS teacher_id,
        t.employee_number,
        t.subject,
        t.qualification
    FROM users u
    INNER JOIN teachers t ON t.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$_SESSION["user_id"]]);
$teacher = $stmt->fetch();

if (!$teacher) {
    die("Teacher profile not found. Please contact the administrator.");
}

// Update teacher profile.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $subject = trim($_POST["subject"] ?? "");
    $qualification = trim($_POST["qualification"] ?? "");

    if (
        $fullName === "" ||
        $email === "" ||
        $subject === "" ||
        $qualification === ""
    ) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        // Check whether another account uses this email.
        $checkStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        $checkStmt->execute([
            $email,
            $_SESSION["user_id"]
        ]);

        if ($checkStmt->fetch()) {
            $error = "This email address is already registered.";
        } else {

            try {
                $pdo->beginTransaction();

                $updateUser = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?
                    WHERE id = ?
                ");

                $updateUser->execute([
                    $fullName,
                    $email,
                    $_SESSION["user_id"]
                ]);

                $updateTeacher = $pdo->prepare("
                    UPDATE teachers
                    SET subject = ?, qualification = ?
                    WHERE user_id = ?
                ");

                $updateTeacher->execute([
                    $subject,
                    $qualification,
                    $_SESSION["user_id"]
                ]);

                $pdo->commit();

                // Update the name displayed in the session.
                $_SESSION["full_name"] = $fullName;

                $success = "Your profile has been updated successfully.";

                // Refresh profile information.
                $stmt->execute([$_SESSION["user_id"]]);
                $teacher = $stmt->fetch();

            } catch (PDOException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Teacher profile update error: " . $e->getMessage());
                $error = "Unable to update your profile. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Teacher Profile - Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #263238;
        }

        .container {
            max-width: 750px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        h1 {
            color: #1e3a8a;
            margin: 0;
        }

        .back-btn {
            display: inline-block;
            background: #1e3a8a;
            color: white;
            padding: 10px 16px;
            text-decoration: none;
            border-radius: 6px;
        }

        .panel {
            background: white;
            padding: 28px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        }

        .panel h2 {
            color: #1e3a8a;
            margin-top: 0;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }

        input[readonly] {
            background: #eef1f5;
            color: #657185;
        }

        button {
            background: #1e3a8a;
            color: white;
            padding: 12px 22px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover {
            background: #162e6b;
        }

        .success {
            background: #e5f7ec;
            color: #176b3a;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .note {
            color: #657185;
            font-size: 13px;
            margin-top: -10px;
            margin-bottom: 18px;
        }

        @media (max-width: 600px) {
            body {
                padding: 14px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .panel {
                padding: 20px;
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

    <div class="panel">

        <h2>Manage Teacher Profile</h2>

        <p>Update your personal and professional information.</p>

        <?php if ($success !== ""): ?>
            <div class="success">
                <?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <label>Full Name</label>
            <input
                type="text"
                name="full_name"
                value="<?= htmlspecialchars($teacher["full_name"], ENT_QUOTES, "UTF-8") ?>"
                required
            >

            <label>Email Address</label>
            <input
                type="email"
                name="email"
                value="<?= htmlspecialchars($teacher["email"], ENT_QUOTES, "UTF-8") ?>"
                required
            >

            <label>Employee Number</label>
            <input
                type="text"
                value="<?= htmlspecialchars($teacher["employee_number"], ENT_QUOTES, "UTF-8") ?>"
                readonly
            >

            <p class="note">
                Employee number can only be changed by the administrator.
            </p>

            <label>Subject</label>
            <input
                type="text"
                name="subject"
                value="<?= htmlspecialchars($teacher["subject"], ENT_QUOTES, "UTF-8") ?>"
                required
            >

            <label>Qualification</label>
            <input
                type="text"
                name="qualification"
                value="<?= htmlspecialchars($teacher["qualification"], ENT_QUOTES, "UTF-8") ?>"
                required
            >

            <button type="submit">
                Update Profile
            </button>

        </form>

    </div>

</div>

</body>
</html>