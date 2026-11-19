
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

$message = "";
$error = "";

// Process the student registration form.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $studentName = trim($_POST["student_name"] ?? "");
    $admissionNumber = trim($_POST["admission_number"] ?? "");
    $className = trim($_POST["class_name"] ?? "");
    $section = trim($_POST["section"] ?? "");

    $parentInput = trim($_POST["parent_id"] ?? "");
    $parentId = null;

    if ($parentInput !== "") {
        $parentId = filter_var($parentInput, FILTER_VALIDATE_INT);
    }

    if (
        $studentName === "" ||
        $admissionNumber === "" ||
        $className === "" ||
        $section === ""
    ) {
        $error = "Please complete all required student fields.";
    } elseif (
        $parentInput !== "" &&
        (!$parentId || $parentId < 1)
    ) {
        $error = "Please select a valid parent account.";
    } else {
        try {
            // Check the selected parent only when one is provided.
            if ($parentId !== null) {
                $stmt = $pdo->prepare(
                    "SELECT id FROM users
                     WHERE id = :parent_id
                       AND role = 'parent'
                       AND status = 'active'
                     LIMIT 1"
                );

                $stmt->execute(["parent_id" => $parentId]);

                if (!$stmt->fetch()) {
                    throw new Exception(
                        "Please select a valid active parent account."
                    );
                }
            }

            // Check whether the admission number already exists.
            $stmt = $pdo->prepare(
                "SELECT id FROM students
                 WHERE admission_number = :admission_number
                 LIMIT 1"
            );

            $stmt->execute([
                "admission_number" => $admissionNumber
            ]);

            if ($stmt->fetch()) {
                throw new Exception(
                    "This admission number is already registered."
                );
            }

            // Register the student with or without a parent.
            $stmt = $pdo->prepare(
                "INSERT INTO students
                 (student_name, admission_number, class_name, section, parent_id)
                 VALUES
                 (:student_name, :admission_number, :class_name, :section, :parent_id)"
            );

            $stmt->execute([
                "student_name" => $studentName,
                "admission_number" => $admissionNumber,
                "class_name" => $className,
                "section" => $section,
                "parent_id" => $parentId
            ]);

            header("Location: manage_students.php?added=1");
            exit;

        } catch (Exception $e) {
            $error = $e->getMessage();

        } catch (PDOException $e) {
            error_log("Student registration error: " . $e->getMessage());
            $error = "Unable to register the student. Please try again.";
        }
    }
}

if (isset($_GET["added"]) && $_GET["added"] === "1") {
    $message = "Student registered successfully.";
}

// Load active parent accounts.
try {
    $stmt = $pdo->query(
        "SELECT id, full_name, email
         FROM users
         WHERE role = 'parent' AND status = 'active'
         ORDER BY full_name ASC"
    );

    $parents = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Parent list error: " . $e->getMessage());
    $parents = [];
    $error = "Unable to load parent accounts.";
}

// Load all students, including students without a parent.
try {
    $stmt = $pdo->query(
        "SELECT
            s.id,
            s.student_name,
            s.admission_number,
            s.class_name,
            s.section,
            s.created_at,
            u.full_name AS parent_name,
            u.email AS parent_email
         FROM students s
         LEFT JOIN users u ON s.parent_id = u.id
         ORDER BY s.id DESC"
    );

    $students = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Student list error: " . $e->getMessage());
    $students = [];
    $error = "Unable to load student records.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Students - Online Parent-Teacher Meeting System</title>

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

        .container {
            width: 90%;
            max-width: 1200px;
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

        .form-box,
        .table-box {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .form-box h3,
        .table-box h3 {
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 7px;
            font-weight: bold;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }

        .submit-btn {
            margin-top: 20px;
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }

        .submit-btn:hover {
            background: #172554;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f9fafb;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .empty {
            text-align: center;
            color: #6b7280;
            padding: 25px;
        }

        @media (max-width: 700px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .header {
                padding: 18px 20px;
                gap: 15px;
            }

            .container {
                width: 94%;
            }
        }

        @media (max-width: 450px) {
            .header {
                flex-direction: column;
            }

            .page-title h2 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Online Parent-Teacher Meeting System</h1>
    <a href="admin_dashboard.php">Back to Dashboard</a>
</header>

<main class="container">

    <div class="page-title">
        <h2>Manage Students</h2>
        <p>Register students and optionally link them to existing parent accounts.</p>
    </div>

    <?php if ($message !== ""): ?>
        <div class="success">
            <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <section class="form-box">
        <h3>Register New Student</h3>

        <form method="POST" action="manage_students.php">

            <div class="form-grid">

                <div class="form-group">
                    <label for="student_name">Student Name</label>
                    <input
                        type="text"
                        id="student_name"
                        name="student_name"
                        maxlength="150"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="admission_number">Admission Number</label>
                    <input
                        type="text"
                        id="admission_number"
                        name="admission_number"
                        maxlength="50"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="class_name">Class</label>
                    <input
                        type="text"
                        id="class_name"
                        name="class_name"
                        maxlength="50"
                        placeholder="e.g. Class 5"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="section">Section</label>
                    <input
                        type="text"
                        id="section"
                        name="section"
                        maxlength="20"
                        placeholder="e.g. A"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="parent_id">Select Parent (Optional)</label>
                    <select id="parent_id" name="parent_id">
                        <option value="">-- No Parent Assigned --</option>

                        <?php foreach ($parents as $parent): ?>
                            <option value="<?= (int) $parent["id"] ?>">
                                <?= htmlspecialchars(
                                    $parent["full_name"] . " (" . $parent["email"] . ")",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

            </div>

            <button type="submit" class="submit-btn">
                Register Student
            </button>

        </form>
    </section>

    <section class="table-box">
        <h3>Registered Students</h3>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student Name</th>
                        <th>Admission No.</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Parent</th>
                        <th>Parent Email</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($students) > 0): ?>

                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?= (int) $student["id"] ?></td>

                                <td>
                                    <?= htmlspecialchars($student["student_name"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student["admission_number"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student["class_name"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student["section"], ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student["parent_name"] ?? "Not Assigned", ENT_QUOTES, "UTF-8") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($student["parent_email"] ?? "Not Assigned", ENT_QUOTES, "UTF-8") ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty">
                                No students registered yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
