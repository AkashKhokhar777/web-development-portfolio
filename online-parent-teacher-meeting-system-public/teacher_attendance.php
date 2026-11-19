<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

// Only logged-in teachers can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "teacher"
) {
    header("Location: teacher.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$teacherId = null;
$students = [];
$attendanceRecords = [];
$error = "";
$success = "";

$allowedStatuses = ["present", "absent", "late", "excused"];

// Create a CSRF token.
if (empty($_SESSION["attendance_csrf"])) {
    $_SESSION["attendance_csrf"] = bin2hex(random_bytes(32));
}

try {
    // Find the logged-in teacher's profile.
    $stmt = $pdo->prepare(
        "SELECT id FROM teachers WHERE user_id = ? LIMIT 1"
    );
    $stmt->execute([$_SESSION["user_id"]]);
    $teacherId = $stmt->fetchColumn();

    if (!$teacherId) {
        die("Teacher profile not found. Please contact the administrator.");
    }

    // Load students using the same student selection approach
    // as the existing academic records module.
    $stmt = $pdo->query(
        "SELECT id, student_name, admission_number, class_name, section
         FROM students
         ORDER BY student_name ASC"
    );

    $students = $stmt->fetchAll();

    // Save or update attendance.
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        if (
            !isset($_POST["csrf_token"]) ||
            !hash_equals(
                $_SESSION["attendance_csrf"],
                $_POST["csrf_token"]
            )
        ) {
            throw new Exception(
                "Invalid security token. Please refresh the page and try again."
            );
        }

        $studentId = (int)($_POST["student_id"] ?? 0);
        $attendanceDate = trim($_POST["attendance_date"] ?? "");
        $attendanceStatus = $_POST["attendance_status"] ?? "";
        $remarks = trim($_POST["remarks"] ?? "");

        $dateObject = DateTime::createFromFormat(
            "!Y-m-d",
            $attendanceDate
        );

        $validDate = $dateObject &&
            $dateObject->format("Y-m-d") === $attendanceDate;

        if (
            $studentId <= 0 ||
            !$validDate ||
            !in_array($attendanceStatus, $allowedStatuses, true)
        ) {
            throw new Exception(
                "Please select a valid student, date and attendance status."
            );
        }

        // Confirm that the selected student exists.
        $stmt = $pdo->prepare(
            "SELECT id FROM students WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$studentId]);

        if (!$stmt->fetch()) {
            throw new Exception("The selected student does not exist.");
        }

        // Check whether attendance already exists for this student/date.
        $stmt = $pdo->prepare(
            "SELECT id, teacher_id
             FROM attendance
             WHERE student_id = ?
               AND attendance_date = ?
             LIMIT 1"
        );

        $stmt->execute([$studentId, $attendanceDate]);
        $existingAttendance = $stmt->fetch();

        if ($existingAttendance) {

            // Do not allow one teacher to overwrite another
            // teacher's attendance entry.
            if ((int)$existingAttendance["teacher_id"] !== (int)$teacherId) {
                throw new Exception(
                    "Attendance for this student and date has already been recorded by another teacher."
                );
            }

            // Update the existing attendance entry.
            $stmt = $pdo->prepare(
                "UPDATE attendance
                 SET attendance_status = ?,
                     remarks = ?
                 WHERE id = ?
                   AND teacher_id = ?"
            );

            $stmt->execute([
                $attendanceStatus,
                $remarks !== "" ? $remarks : null,
                $existingAttendance["id"],
                $teacherId
            ]);

            header("Location: teacher_attendance.php?updated=1");
            exit;

        } else {

            // Add a new attendance entry.
            $stmt = $pdo->prepare(
                "INSERT INTO attendance
                (
                    student_id,
                    teacher_id,
                    attendance_date,
                    attendance_status,
                    remarks
                )
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $studentId,
                $teacherId,
                $attendanceDate,
                $attendanceStatus,
                $remarks !== "" ? $remarks : null
            ]);

            header("Location: teacher_attendance.php?added=1");
            exit;
        }
    }

    // Load attendance records entered by this teacher.
    $stmt = $pdo->prepare(
        "SELECT
            a.id,
            a.attendance_date,
            a.attendance_status,
            a.remarks,
            s.student_name,
            s.admission_number,
            s.class_name,
            s.section
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id
         WHERE a.teacher_id = ?
         ORDER BY a.attendance_date DESC, a.id DESC"
    );

    $stmt->execute([$teacherId]);
    $attendanceRecords = $stmt->fetchAll();

} catch (PDOException $ex) {
    error_log("Teacher attendance error: " . $ex->getMessage());

    if ($ex->getCode() === "23000") {
        $error = "Attendance for this student and date already exists. Please refresh the page.";
    } else {
        $error = "Unable to process attendance. Please try again.";
    }

} catch (Exception $ex) {
    $error = $ex->getMessage();
}

if (isset($_GET["added"])) {
    $success = "Student attendance recorded successfully.";
} elseif (isset($_GET["updated"])) {
    $success = "Student attendance updated successfully.";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Attendance - Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #263445;
        }

        header {
            background: #17365d;
            color: white;
            padding: 22px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        header h1 {
            font-size: 23px;
        }

        header a {
            color: white;
            background: #28558a;
            padding: 10px 16px;
            border-radius: 6px;
            text-decoration: none;
        }

        .container {
            width: 90%;
            max-width: 1100px;
            margin: 30px auto;
        }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        }

        h2 {
            color: #17365d;
            margin-bottom: 12px;
            font-size: 21px;
        }

        .description {
            color: #68778a;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 17px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        button {
            background: #17365d;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }

        button:hover {
            background: #28558a;
        }

        .message {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .success {
            background: #e3f5e9;
            color: #176536;
        }

        .error {
            background: #fde8e8;
            color: #a12626;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #e6ebf0;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f0f4f8;
            color: #34465a;
        }

        .status {
            font-weight: bold;
            text-transform: capitalize;
        }

        .empty {
            color: #718096;
            padding: 18px 0;
        }

        @media (max-width: 650px) {
            .container {
                width: 94%;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Online Parent-Teacher Meeting System - Student Attendance</h1>
    <a href="teacher_dashboard.php">Back to Dashboard</a>
</header>

<main class="container">

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="panel">

        <h2>Record Student Attendance</h2>

        <p class="description">
            Select a student, attendance date and attendance status.
            You can update an existing attendance entry for the same
            student and date.
        </p>

        <?php if (count($students) > 0): ?>

            <form method="POST" action="teacher_attendance.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["attendance_csrf"]) ?>"
                >

                <div class="form-grid">

                    <div class="field full">
                        <label for="student_id">Select Student *</label>

                        <select
                            id="student_id"
                            name="student_id"
                            required
                        >
                            <option value="">-- Select Student --</option>

                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int)$student["id"] ?>">
                                    <?= e($student["student_name"]) ?>
                                    (<?= e($student["admission_number"]) ?>)
                                    - <?= e($student["class_name"]) ?>
                                    <?= e($student["section"]) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="field">
                        <label for="attendance_date">Attendance Date *</label>

                        <input
                            type="date"
                            id="attendance_date"
                            name="attendance_date"
                            value="<?= e(date("Y-m-d")) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="attendance_status">Attendance Status *</label>

                        <select
                            id="attendance_status"
                            name="attendance_status"
                            required
                        >
                            <option value="">-- Select Status --</option>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                            <option value="excused">Excused</option>
                        </select>
                    </div>

                    <div class="field full">
                        <label for="remarks">Remarks (Optional)</label>

                        <textarea
                            id="remarks"
                            name="remarks"
                            placeholder="Enter attendance remarks if required"
                        ></textarea>
                    </div>

                    <div class="field full">
                        <button type="submit">
                            Save Attendance
                        </button>
                    </div>

                </div>

            </form>

        <?php else: ?>

            <p class="empty">
                No students are available. Please contact the administrator.
            </p>

        <?php endif; ?>

    </section>

    <section class="panel">

        <h2>Attendance Records</h2>

        <p class="description">
            Attendance records entered by the logged-in teacher.
        </p>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission Number</th>
                        <th>Class</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Remarks</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($attendanceRecords) > 0): ?>

                        <?php foreach ($attendanceRecords as $record): ?>

                            <tr>
                                <td><?= e($record["student_name"]) ?></td>

                                <td><?= e($record["admission_number"]) ?></td>

                                <td>
                                    <?= e($record["class_name"] . " " . $record["section"]) ?>
                                </td>

                                <td><?= e($record["attendance_date"]) ?></td>

                                <td class="status">
                                    <?= e($record["attendance_status"]) ?>
                                </td>

                                <td>
                                    <?= e($record["remarks"] ?: "-") ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="empty">
                                No attendance records have been added yet.
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