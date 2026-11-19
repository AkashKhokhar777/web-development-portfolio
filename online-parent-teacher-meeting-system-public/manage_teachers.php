<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once __DIR__ . "/config/database.php";

// Allow administrators only.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: admin.php");
    exit;
}

$adminName = $_SESSION["full_name"] ?? "Administrator";
$message = "";
$messageType = "";
$editTeacher = null;

// CSRF protection.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Load teacher details when editing.
if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["edit"]) &&
    ctype_digit($_GET["edit"])
) {
    $editQuery = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email,
                t.employee_number, t.subject, t.qualification
         FROM teachers t
         INNER JOIN users u ON u.id = t.user_id
         WHERE u.id = :id AND u.role = 'teacher'
         LIMIT 1"
    );

    $editQuery->execute(["id" => (int)$_GET["edit"]]);
    $editTeacher = $editQuery->fetch();

    if (!$editTeacher) {
        $message = "Teacher not found.";
        $messageType = "error";
    }
}

// Process form submissions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $submittedToken)) {
        $message = "Invalid request. Please refresh the page and try again.";
        $messageType = "error";
    } else {

        $action = $_POST["action"] ?? "";

        // REGISTER NEW TEACHER.
        if ($action === "add") {

            $fullName = trim($_POST["full_name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $password = $_POST["password"] ?? "";
            $employeeNumber = trim($_POST["employee_number"] ?? "");
            $subject = trim($_POST["subject"] ?? "");
            $qualification = trim($_POST["qualification"] ?? "");

            if (
                $fullName === "" ||
                strlen($fullName) > 100 ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($email) > 150 ||
                strlen($password) < 8 ||
                $employeeNumber === "" ||
                strlen($employeeNumber) > 50 ||
                $subject === "" ||
                strlen($subject) > 100 ||
                $qualification === "" ||
                strlen($qualification) > 150
            ) {
                $message = "Please enter valid information in all fields. Password must be at least 8 characters.";
                $messageType = "error";
            } else {
                try {
                    $pdo->beginTransaction();

                    $checkEmail = $pdo->prepare(
                        "SELECT id FROM users WHERE email = :email LIMIT 1"
                    );
                    $checkEmail->execute(["email" => $email]);

                    $checkEmployee = $pdo->prepare(
                        "SELECT id FROM teachers
                         WHERE employee_number = :employee_number LIMIT 1"
                    );
                    $checkEmployee->execute([
                        "employee_number" => $employeeNumber
                    ]);

                    if ($checkEmail->fetch()) {
                        $pdo->rollBack();
                        $message = "This email address is already registered.";
                        $messageType = "error";
                    } elseif ($checkEmployee->fetch()) {
                        $pdo->rollBack();
                        $message = "This employee number is already in use.";
                        $messageType = "error";
                    } else {
                        $hashedPassword = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $addUser = $pdo->prepare(
                            "INSERT INTO users
                                (full_name, email, password, role, status)
                             VALUES
                                (:full_name, :email, :password, 'teacher', 'active')"
                        );

                        $addUser->execute([
                            "full_name" => $fullName,
                            "email" => $email,
                            "password" => $hashedPassword
                        ]);

                        $userId = $pdo->lastInsertId();

                        $addTeacher = $pdo->prepare(
                            "INSERT INTO teachers
                                (user_id, employee_number, subject, qualification)
                             VALUES
                                (:user_id, :employee_number, :subject, :qualification)"
                        );

                        $addTeacher->execute([
                            "user_id" => $userId,
                            "employee_number" => $employeeNumber,
                            "subject" => $subject,
                            "qualification" => $qualification
                        ]);

                        $pdo->commit();

                        header("Location: manage_teachers.php?registered=1");
                        exit;
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log("Teacher registration error: " . $e->getMessage());
                    $message = "Unable to register the teacher. Check that the email and employee number are unique.";
                    $messageType = "error";
                }
            }
        }

        // UPDATE EXISTING TEACHER.
        elseif ($action === "update") {

            $teacherId = (int)($_POST["teacher_id"] ?? 0);
            $fullName = trim($_POST["full_name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $employeeNumber = trim($_POST["employee_number"] ?? "");
            $subject = trim($_POST["subject"] ?? "");
            $qualification = trim($_POST["qualification"] ?? "");

            if (
                $teacherId <= 0 ||
                $fullName === "" ||
                strlen($fullName) > 100 ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($email) > 150 ||
                $employeeNumber === "" ||
                strlen($employeeNumber) > 50 ||
                $subject === "" ||
                strlen($subject) > 100 ||
                $qualification === "" ||
                strlen($qualification) > 150
            ) {
                $message = "Please enter valid information in all fields.";
                $messageType = "error";
            } else {
                try {
                    $checkTeacher = $pdo->prepare(
                        "SELECT t.id
                         FROM teachers t
                         INNER JOIN users u ON u.id = t.user_id
                         WHERE u.id = :id AND u.role = 'teacher'"
                    );
                    $checkTeacher->execute(["id" => $teacherId]);

                    if (!$checkTeacher->fetch()) {
                        throw new RuntimeException("Teacher account not found.");
                    }

                    $checkEmail = $pdo->prepare(
                        "SELECT id FROM users
                         WHERE email = :email AND id != :id LIMIT 1"
                    );
                    $checkEmail->execute([
                        "email" => $email,
                        "id" => $teacherId
                    ]);

                    $checkEmployee = $pdo->prepare(
                        "SELECT t.id
                         FROM teachers t
                         WHERE t.employee_number = :number
                         AND t.user_id != :user_id
                         LIMIT 1"
                    );
                    $checkEmployee->execute([
                        "number" => $employeeNumber,
                        "user_id" => $teacherId
                    ]);

                    if ($checkEmail->fetch()) {
                        $message = "This email address is already used by another account.";
                        $messageType = "error";
                    } elseif ($checkEmployee->fetch()) {
                        $message = "This employee number is already in use.";
                        $messageType = "error";
                    } else {
                        $pdo->beginTransaction();

                        $updateUser = $pdo->prepare(
                            "UPDATE users
                             SET full_name = :name, email = :email
                             WHERE id = :id AND role = 'teacher'"
                        );
                        $updateUser->execute([
                            "name" => $fullName,
                            "email" => $email,
                            "id" => $teacherId
                        ]);

                        $updateTeacher = $pdo->prepare(
                            "UPDATE teachers
                             SET employee_number = :number,
                                 subject = :subject,
                                 qualification = :qualification
                             WHERE user_id = :id"
                        );
                        $updateTeacher->execute([
                            "number" => $employeeNumber,
                            "subject" => $subject,
                            "qualification" => $qualification,
                            "id" => $teacherId
                        ]);

                        $pdo->commit();

                        header("Location: manage_teachers.php?updated=1");
                        exit;
                    }
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = $e->getMessage();
                    $messageType = "error";
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Teacher update error: " . $e->getMessage());
                    $message = "Unable to update the teacher. Please check the information.";
                    $messageType = "error";
                }
            }
        }

        // DELETE TEACHER.
        elseif ($action === "delete") {

            $teacherId = (int)($_POST["teacher_id"] ?? 0);

            if ($teacherId <= 0) {
                $message = "Invalid teacher account.";
                $messageType = "error";
            } else {
                try {
                    $teacherCheck = $pdo->prepare(
                        "SELECT id FROM teachers WHERE user_id = :id LIMIT 1"
                    );
                    $teacherCheck->execute(["id" => $teacherId]);
                    $teacherRecord = $teacherCheck->fetch();

                    if (!$teacherRecord) {
                        $message = "Teacher not found.";
                        $messageType = "error";
                    } else {
                        $teacherRecordId = (int)$teacherRecord["id"];

                        // Prevent deletion if other records depend on this teacher.
                        $checks = [
                            ["meetings", "teacher_id", $teacherRecordId],
                            ["student_records", "teacher_id", $teacherRecordId],
                            ["marksheets", "teacher_id", $teacherRecordId],
                            ["teacher_feedback", "teacher_id", $teacherRecordId],
                            ["messages", "teacher_id", $teacherRecordId]
                        ];

                        $hasRelatedRecords = false;

                        foreach ($checks as $check) {
                            $sql = "SELECT COUNT(*) FROM `" . $check[0] .
                                   "` WHERE `" . $check[1] . "` = :record_id";
                            $relatedQuery = $pdo->prepare($sql);
                            $relatedQuery->execute([
                                "record_id" => $check[2]
                            ]);

                            if ((int)$relatedQuery->fetchColumn() > 0) {
                                $hasRelatedRecords = true;
                                break;
                            }
                        }

                        if ($hasRelatedRecords) {
                            $message = "This teacher cannot be deleted because meeting slots, student records, marksheets, feedback, or messages are linked to this account. Keep the account to preserve these records.";
                            $messageType = "error";
                        } else {
                            $pdo->beginTransaction();

                            $deleteTeacher = $pdo->prepare(
                                "DELETE FROM teachers WHERE user_id = :id"
                            );
                            $deleteTeacher->execute(["id" => $teacherId]);

                            $deleteUser = $pdo->prepare(
                                "DELETE FROM users
                                 WHERE id = :id AND role = 'teacher'"
                            );
                            $deleteUser->execute(["id" => $teacherId]);

                            $pdo->commit();

                            header("Location: manage_teachers.php?deleted=1");
                            exit;
                        }
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Teacher deletion error: " . $e->getMessage());
                    $message = "Unable to delete this teacher because related database records may exist.";
                    $messageType = "error";
                }
            }
        }
    }
}

// Success messages after redirects.
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if (isset($_GET["registered"])) {
        $message = "Teacher registered successfully.";
        $messageType = "success";
    } elseif (isset($_GET["updated"])) {
        $message = "Teacher information updated successfully.";
        $messageType = "success";
    } elseif (isset($_GET["deleted"])) {
        $message = "Teacher deleted successfully.";
        $messageType = "success";
    }
}

// Load all teachers.
try {
    $teacherQuery = $pdo->query(
        "SELECT
            u.id,
            u.full_name,
            u.email,
            u.status,
            t.employee_number,
            t.subject,
            t.qualification
         FROM teachers t
         INNER JOIN users u ON t.user_id = u.id
         ORDER BY u.full_name ASC"
    );

    $teachers = $teacherQuery->fetchAll();
} catch (PDOException $e) {
    error_log("Teacher list error: " . $e->getMessage());
    $teachers = [];
    $message = "The teacher list could not be loaded.";
    $messageType = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers | Online Parent-Teacher Meeting System</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .page-main {
            max-width: 1100px;
            margin: 40px auto;
            padding: 24px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
        }

        .page-header h1 { margin-bottom: 8px; }

        .back-link {
            display: inline-block;
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .back-link:hover { text-decoration: underline; }

        .content-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .content-card h2 { margin-top: 0; }

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

        .form-group input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
        }

        .form-group input:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        .submit-button, .cancel-button, .edit-button, .delete-button {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .submit-button {
            background: #2563eb;
            color: #fff;
        }

        .submit-button:hover { background: #1d4ed8; }

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
            border: 1px solid #86efac;
        }

        .notice.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .table-wrapper { overflow-x: auto; }

        .teacher-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .teacher-table th, .teacher-table td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .teacher-table th {
            background: #f8fafc;
            color: #334155;
            font-size: 14px;
        }

        .teacher-table td {
            color: #475569;
            font-size: 14px;
        }

        .status-label {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
        }

        .status-label.inactive {
            background: #f1f5f9;
            color: #475569;
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-buttons form { margin: 0; }

        .empty-message {
            color: #64748b;
            line-height: 1.7;
        }

        @media (max-width: 600px) {
            .page-main {
                margin: 20px auto;
                padding: 16px;
            }

            .content-card { padding: 18px; }

            .form-grid { grid-template-columns: 1fr; }
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
            <h1>Manage Teachers</h1>
            <p>Welcome, <?php echo escape($adminName); ?>.</p>
        </div>

        <a href="admin_dashboard.php" class="back-link">
            &larr; Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ""): ?>
        <div class="notice <?php echo $messageType === "success" ? "success" : "error"; ?>" role="status">
            <?php echo escape($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($editTeacher): ?>

        <section class="content-card">
            <h2>Edit Teacher Information</h2>
            <p class="help-text">
                Update this teacher's account and professional details.
            </p>

            <form action="manage_teachers.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="teacher_id" value="<?php echo (int)$editTeacher["id"]; ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_full_name">Full Name</label>
                        <input type="text" id="edit_full_name" name="full_name" maxlength="100" value="<?php echo escape($editTeacher["full_name"]); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_email">Email Address</label>
                        <input type="email" id="edit_email" name="email" maxlength="150" value="<?php echo escape($editTeacher["email"]); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_employee_number">Employee Number</label>
                        <input type="text" id="edit_employee_number" name="employee_number" maxlength="50" value="<?php echo escape($editTeacher["employee_number"]); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_subject">Subject</label>
                        <input type="text" id="edit_subject" name="subject" maxlength="100" value="<?php echo escape($editTeacher["subject"]); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_qualification">Qualification</label>
                        <input type="text" id="edit_qualification" name="qualification" maxlength="150" value="<?php echo escape($editTeacher["qualification"]); ?>" required>
                    </div>
                </div>

                <button type="submit" class="submit-button">Save Changes</button>
                <a href="manage_teachers.php" class="cancel-button">Cancel</a>
            </form>
        </section>

    <?php else: ?>

        <section class="content-card">
            <h2>Register a New Teacher</h2>

            <p class="help-text">
                Enter the teacher's account and professional details.
                The teacher can use these account credentials to sign in.
            </p>

            <form action="manage_teachers.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" maxlength="100" autocomplete="name" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" maxlength="150" autocomplete="email" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Temporary Password</label>
                        <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                    </div>

                    <div class="form-group">
                        <label for="employee_number">Employee Number</label>
                        <input type="text" id="employee_number" name="employee_number" maxlength="50" required>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <input type="text" id="subject" name="subject" maxlength="100" required>
                    </div>

                    <div class="form-group">
                        <label for="qualification">Qualification</label>
                        <input type="text" id="qualification" name="qualification" maxlength="150" required>
                    </div>
                </div>

                <button type="submit" class="submit-button">Register Teacher</button>
            </form>
        </section>

    <?php endif; ?>

    <section class="content-card">
        <h2>Registered Teachers</h2>

        <p class="help-text">
            View, update, or delete teacher accounts.
        </p>

        <?php if (count($teachers) === 0): ?>

            <p class="empty-message">No teachers have been registered yet.</p>

        <?php else: ?>

            <div class="table-wrapper">
                <table class="teacher-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Teacher Name</th>
                            <th>Email</th>
                            <th>Employee No.</th>
                            <th>Subject</th>
                            <th>Qualification</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($teachers as $index => $teacher): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo escape($teacher["full_name"]); ?></td>
                                <td><?php echo escape($teacher["email"]); ?></td>
                                <td><?php echo escape($teacher["employee_number"]); ?></td>
                                <td><?php echo escape($teacher["subject"]); ?></td>
                                <td><?php echo escape($teacher["qualification"]); ?></td>
                                <td>
                                    <span class="status-label <?php echo $teacher["status"] === "active" ? "" : "inactive"; ?>">
                                        <?php echo escape(ucfirst($teacher["status"])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a class="edit-button" href="manage_teachers.php?edit=<?php echo (int)$teacher["id"]; ?>">
                                            Edit
                                        </a>

                                        <form action="manage_teachers.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this teacher account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION["csrf_token"]); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher["id"]; ?>">
                                            <button type="submit" class="delete-button">Delete</button>
                                        </form>
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

</body>
</html>