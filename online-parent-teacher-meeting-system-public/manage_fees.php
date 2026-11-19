<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once __DIR__ . "/config/database.php";

// Only admins can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: admin.php");
    exit;
}

$message = "";
$error = "";

// Assign a new fee.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $student_id = filter_input(INPUT_POST, "student_id", FILTER_VALIDATE_INT);
    $fee_title = trim($_POST["fee_title"] ?? "");
    $amount = filter_input(INPUT_POST, "amount", FILTER_VALIDATE_FLOAT);
    $due_date = $_POST["due_date"] ?? "";

    if (
        !$student_id ||
        $fee_title === "" ||
        strlen($fee_title) > 150 ||
        !$amount ||
        $amount <= 0 ||
        $due_date === ""
    ) {
        $error = "Please enter all fee details correctly.";
    } else {
        $date = DateTime::createFromFormat("Y-m-d", $due_date);

        if (!$date || $date->format("Y-m-d") !== $due_date) {
            $error = "Please select a valid due date.";
        } else {
            try {
                $studentCheck = $pdo->prepare(
                    "SELECT id FROM students WHERE id = ?"
                );
                $studentCheck->execute([$student_id]);

                if (!$studentCheck->fetch()) {
                    $error = "Selected student does not exist.";
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO student_fees
                         (student_id, fee_title, amount, due_date)
                         VALUES (?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $student_id,
                        $fee_title,
                        $amount,
                        $due_date
                    ]);

                    header("Location: manage_fees.php?added=1");
                    exit;
                }
            } catch (PDOException $e) {
                error_log("Fee assignment error: " . $e->getMessage());
                $error = "Unable to assign the fee. Please try again.";
            }
        }
    }
}

if (isset($_GET["added"])) {
    $message = "Student fee assigned successfully.";
}

// Get students for the dropdown.
$studentsStmt = $pdo->query(
    "SELECT id, student_name, admission_number, class_name, section
     FROM students
     ORDER BY student_name"
);
$students = $studentsStmt->fetchAll();

// Get all assigned fees.
$feesStmt = $pdo->query(
    "SELECT
        sf.id,
        sf.fee_title,
        sf.amount,
        sf.due_date,
        sf.fee_status,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section
     FROM student_fees sf
     INNER JOIN students s ON sf.student_id = s.id
     ORDER BY sf.created_at DESC"
);
$fees = $feesStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Student Fees | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .fee-container {
            max-width: 1150px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .fee-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .fee-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.07);
        }

        .fee-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        .fee-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .fee-form .full-width {
            grid-column: 1 / -1;
        }

        .fee-form label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .fee-form input,
        .fee-form select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d5dce5;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .fee-form button,
        .back-link {
            display: inline-block;
            padding: 11px 20px;
            border: none;
            border-radius: 6px;
            background: #1769aa;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }

        .fee-table-wrapper {
            overflow-x: auto;
        }

        .fee-table {
            width: 100%;
            border-collapse: collapse;
        }

        .fee-table th,
        .fee-table td {
            padding: 13px;
            border-bottom: 1px solid #e8edf2;
            text-align: left;
            white-space: nowrap;
        }

        .fee-table th {
            background: #f4f7fb;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .pending {
            background: #fff1d6;
            color: #966000;
        }

        .paid {
            background: #dff5e8;
            color: #187443;
        }

        .waived {
            background: #e8eaf0;
            color: #4b5563;
        }

        .success-message {
            background: #dff5e8;
            color: #187443;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .error-message {
            background: #fde8e8;
            color: #a32626;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        @media (max-width: 650px) {
            .fee-form {
                grid-template-columns: 1fr;
            }

            .fee-form .full-width {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<div class="fee-container">

    <div class="fee-header">
        <div>
            <h1>Student Fee Management</h1>
            <p>Assign and monitor school fees.</p>
        </div>

        <a href="admin_dashboard.php" class="back-link">
            Back to Dashboard
        </a>
    </div>

    <?php if ($message): ?>
        <div class="success-message">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error-message">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="fee-card">

        <h2>Assign Fee to Student</h2>

        <?php if (count($students) > 0): ?>

            <form method="POST" class="fee-form">

                <div class="full-width">
                    <label for="student_id">Select Student</label>

                    <select name="student_id" id="student_id" required>
                        <option value="">Choose a student</option>

                        <?php foreach ($students as $student): ?>
                            <option value="<?= (int)$student["id"] ?>">
                                <?= htmlspecialchars(
                                    $student["student_name"] .
                                    " (" . $student["admission_number"] . ")" .
                                    " - Class " . $student["class_name"] .
                                    " " . $student["section"]
                                ) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label for="fee_title">Fee Title</label>
                    <input
                        type="text"
                        name="fee_title"
                        id="fee_title"
                        maxlength="150"
                        placeholder="e.g. Monthly Tuition Fee"
                        required
                    >
                </div>

                <div>
                    <label for="amount">Amount (PKR)</label>
                    <input
                        type="number"
                        name="amount"
                        id="amount"
                        min="0.01"
                        step="0.01"
                        placeholder="Enter amount"
                        required
                    >
                </div>

                <div>
                    <label for="due_date">Due Date</label>
                    <input
                        type="date"
                        name="due_date"
                        id="due_date"
                        required
                    >
                </div>

                <div class="full-width">
                    <button type="submit">Assign Fee</button>
                </div>

            </form>

        <?php else: ?>

            <p>No students are available. Please add a student first.</p>

        <?php endif; ?>

    </div>

    <div class="fee-card">

        <h2>Assigned Student Fees</h2>

        <div class="fee-table-wrapper">

            <table class="fee-table">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission No.</th>
                        <th>Class</th>
                        <th>Fee Title</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($fees) > 0): ?>

                        <?php foreach ($fees as $fee): ?>

                            <tr>
                                <td><?= htmlspecialchars($fee["student_name"]) ?></td>

                                <td><?= htmlspecialchars($fee["admission_number"]) ?></td>

                                <td>
                                    <?= htmlspecialchars(
                                        $fee["class_name"] . " " . $fee["section"]
                                    ) ?>
                                </td>

                                <td><?= htmlspecialchars($fee["fee_title"]) ?></td>

                                <td>
                                    PKR <?= number_format((float)$fee["amount"], 2) ?>
                                </td>

                                <td><?= htmlspecialchars($fee["due_date"]) ?></td>

                                <td>
                                    <span class="status <?= htmlspecialchars($fee["fee_status"]) ?>">
                                        <?= htmlspecialchars($fee["fee_status"]) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7">No student fees have been assigned yet.</td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>