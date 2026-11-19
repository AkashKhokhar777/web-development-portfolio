
<?php
session_start();

require_once __DIR__ . "/config/database.php";

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $admissionNumber = trim($_POST["admission_number"] ?? "");

    if (
        $fullName === "" ||
        $email === "" ||
        $password === "" ||
        $admissionNumber === ""
    ) {
        $error = "Please complete all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must contain at least 8 characters.";
    } else {
        try {
            $pdo->beginTransaction();

            // Check whether the email is already registered.
            $emailStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $emailStmt->execute([$email]);

            if ($emailStmt->fetch()) {
                throw new Exception("This email address is already registered.");
            }

            // Lock and verify the student record.
            $studentStmt = $pdo->prepare("
                SELECT id, student_name, parent_id
                FROM students
                WHERE admission_number = ?
                LIMIT 1
                FOR UPDATE
            ");
            $studentStmt->execute([$admissionNumber]);
            $student = $studentStmt->fetch();

            if (!$student) {
                throw new Exception("No student was found with this admission number. Please check it.");
            }

            if (!empty($student["parent_id"])) {
                throw new Exception("A parent account is already linked to this student. Please contact the school administration.");
            }

            // Create the parent account as inactive (pending approval).
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $userStmt = $pdo->prepare("
                INSERT INTO users
                (full_name, email, password, role, status)
                VALUES (?, ?, ?, 'parent', 'inactive')
            ");

            $userStmt->execute([
                $fullName,
                $email,
                $hashedPassword
            ]);

            $parentId = (int)$pdo->lastInsertId();

            // Link the student to the newly registered parent.
            $updateStudent = $pdo->prepare("
                UPDATE students
                SET parent_id = ?
                WHERE id = ?
            ");

            $updateStudent->execute([
                $parentId,
                $student["id"]
            ]);

            $pdo->commit();

            $success = "Registration submitted successfully. Your account is pending admin verification. You can log in after the school administration approves your account.";

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Parent registration error: " . $e->getMessage());
            $error = "Registration could not be completed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Parent Registration - Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .register-container {
            width: 100%;
            max-width: 470px;
            background: white;
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
        }

        .heading {
            text-align: center;
            margin-bottom: 25px;
        }

        .heading h1 {
            color: #1e3a8a;
            font-size: 27px;
            margin-bottom: 8px;
        }

        .heading p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }

        .field {
            margin-bottom: 17px;
        }

        label {
            display: block;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 7px;
            color: #374151;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
        }

        input:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 2px rgba(30, 58, 138, 0.1);
        }

        button {
            width: 100%;
            padding: 13px;
            background: #1e3a8a;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 5px;
        }

        button:hover {
            background: #172554;
        }

        .message {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.5;
        }

        .success {
            background: #e3f5e9;
            color: #176536;
        }

        .error {
            background: #fde8e8;
            color: #a12626;
        }

        .links {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .links a {
            color: #1e3a8a;
            text-decoration: none;
            font-weight: bold;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .note {
            margin-top: 18px;
            padding: 12px;
            background: #eff4ff;
            color: #334b75;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 480px) {
            .register-container {
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

<div class="register-container">

    <div class="heading">
        <h1>Online Parent-Teacher Meeting System</h1>
        <p>Parent Registration</p>
    </div>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <?php if ($success === ""): ?>

        <form method="POST" action="parent_register.php">

            <div class="field">
                <label for="full_name">Parent Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="100"
                    value="<?= htmlspecialchars($_POST["full_name"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    value="<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    required
                >
            </div>

            <div class="field">
                <label for="admission_number">Student Admission Number</label>
                <input
                    type="text"
                    id="admission_number"
                    name="admission_number"
                    maxlength="50"
                    placeholder="e.g. STU-001"
                    value="<?= htmlspecialchars($_POST["admission_number"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                    required
                >
            </div>

            <button type="submit">Submit Registration</button>

        </form>

    <?php endif; ?>

    <div class="note">
        Your registration must be verified by the school administrator.
        You will be able to log in after your account is approved.
    </div>

    <div class="links">
        Already registered?
        <a href="parent.php">Parent Login</a>
    </div>

</div>

</body>
</html>
