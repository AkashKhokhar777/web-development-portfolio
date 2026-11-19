<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

require_once 'config/database.php';

// Only logged-in teachers can access this page.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: teacher.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';
$editMarksheets = null;
$editSubjects = [];

// Get the teacher's database ID.
$stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
$stmt->execute([$userId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    die("Teacher profile not found. Please contact the administrator.");
}

$teacherId = (int) $teacher['id'];

// Handle add, update and delete actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // Delete a marksheet.
    if ($action === 'delete') {
        $marksheetId = (int) ($_POST['marksheet_id'] ?? 0);

        try {
            $stmt = $pdo->prepare(
                "DELETE FROM marksheets WHERE id = ? AND teacher_id = ?"
            );
            $stmt->execute([$marksheetId, $teacherId]);

            if ($stmt->rowCount() > 0) {
                header("Location: teacher_marksheets.php?deleted=1");
                exit;
            } else {
                $error = "Marksheets record not found or you do not have permission to delete it.";
            }
        } catch (PDOException $ex) {
            $error = "Unable to delete the marksheet. Please try again.";
        }
    }

    // Add or update a marksheet.
    if ($action === 'save') {

        $marksheetId = (int) ($_POST['marksheet_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $examName = trim($_POST['exam_name'] ?? '');
        $examDate = $_POST['exam_date'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');

        $subjectNames = $_POST['subject_name'] ?? [];
        $totalMarks = $_POST['total_marks'] ?? [];
        $obtainedMarks = $_POST['obtained_marks'] ?? [];

        $subjects = [];

        if ($studentId <= 0 || $examName === '' || $examDate === '') {
            $error = "Please enter the student, exam name and exam date.";
        } elseif (!is_array($subjectNames) || !is_array($totalMarks) || !is_array($obtainedMarks)) {
            $error = "Invalid subject information.";
        } elseif (count($subjectNames) === 0 || count($subjectNames) !== count($totalMarks) || count($subjectNames) !== count($obtainedMarks)) {
            $error = "Please add at least one complete subject.";
        } else {

            foreach ($subjectNames as $index => $subjectName) {
                $subjectName = trim($subjectName);
                $total = $totalMarks[$index] ?? '';
                $obtained = $obtainedMarks[$index] ?? '';

                if (
                    $subjectName === '' ||
                    $total === '' ||
                    $obtained === '' ||
                    !is_numeric($total) ||
                    !is_numeric($obtained) ||
                    (float)$total <= 0 ||
                    (float)$obtained < 0 ||
                    (float)$obtained > (float)$total
                ) {
                    $error = "Please enter valid subject names and marks. Obtained marks cannot exceed total marks.";
                    break;
                }

                $subjects[] = [
                    'name' => $subjectName,
                    'total' => $total,
                    'obtained' => $obtained
                ];
            }
        }

        // Check that the selected student exists.
        if ($error === '') {
            $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ?");
            $stmt->execute([$studentId]);

            if (!$stmt->fetch()) {
                $error = "The selected student was not found.";
            }
        }

        // Save the marksheet.
        if ($error === '') {
            try {
                $pdo->beginTransaction();

                if ($marksheetId > 0) {
                    // Update only this teacher's marksheet.
                    $stmt = $pdo->prepare(
                        "UPDATE marksheets
                         SET student_id = ?, exam_name = ?, exam_date = ?, remarks = ?
                         WHERE id = ? AND teacher_id = ?"
                    );

                    $stmt->execute([
                        $studentId,
                        $examName,
                        $examDate,
                        $remarks,
                        $marksheetId,
                        $teacherId
                    ]);

                    if ($stmt->rowCount() === 0) {
                        $check = $pdo->prepare(
                            "SELECT id FROM marksheets WHERE id = ? AND teacher_id = ?"
                        );
                        $check->execute([$marksheetId, $teacherId]);

                        if (!$check->fetch()) {
                            throw new Exception("Marksheets record not found or access denied.");
                        }
                    }

                    // Replace the existing subject marks.
                    $stmt = $pdo->prepare(
                        "DELETE FROM marksheet_subjects WHERE marksheet_id = ?"
                    );
                    $stmt->execute([$marksheetId]);

                } else {
                    // Create a new marksheet.
                    $stmt = $pdo->prepare(
                        "INSERT INTO marksheets
                         (student_id, teacher_id, exam_name, exam_date, remarks)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $studentId,
                        $teacherId,
                        $examName,
                        $examDate,
                        $remarks
                    ]);

                    $marksheetId = (int) $pdo->lastInsertId();
                }

                // Insert subject-wise marks.
                $stmt = $pdo->prepare(
                    "INSERT INTO marksheet_subjects
                     (marksheet_id, subject_name, total_marks, obtained_marks)
                     VALUES (?, ?, ?, ?)"
                );

                foreach ($subjects as $subject) {
                    $stmt->execute([
                        $marksheetId,
                        $subject['name'],
                        $subject['total'],
                        $subject['obtained']
                    ]);
                }

                $pdo->commit();

                header("Location: teacher_marksheets.php?saved=1");
                exit;

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = "Unable to save the marksheet: " . $ex->getMessage();
            }
        }
    }
}

// Success messages.
if (isset($_GET['saved'])) {
    $message = "Marksheets saved successfully.";
} elseif (isset($_GET['deleted'])) {
    $message = "Marksheets deleted successfully.";
}

// Load students for the form.
$stmt = $pdo->query(
    "SELECT id, student_name, admission_number, class_name, section
     FROM students
     ORDER BY student_name ASC"
);
$students = $stmt->fetchAll();

// Load an existing marksheet for editing.
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    $stmt = $pdo->prepare(
        "SELECT * FROM marksheets WHERE id = ? AND teacher_id = ?"
    );
    $stmt->execute([$editId, $teacherId]);
    $editMarksheets = $stmt->fetch();

    if ($editMarksheets) {
        $stmt = $pdo->prepare(
            "SELECT * FROM marksheet_subjects WHERE marksheet_id = ? ORDER BY id"
        );
        $stmt->execute([$editId]);
        $editSubjects = $stmt->fetchAll();
    } else {
        $error = "Marksheets record not found or access denied.";
    }
}

// Load marksheets created by this teacher.
$stmt = $pdo->prepare(
    "SELECT
        m.id,
        m.exam_name,
        m.exam_date,
        m.remarks,
        s.student_name,
        s.admission_number,
        s.class_name,
        s.section,
        COALESCE(SUM(ms.total_marks), 0) AS total_marks,
        COALESCE(SUM(ms.obtained_marks), 0) AS obtained_marks
     FROM marksheets m
     INNER JOIN students s ON m.student_id = s.id
     LEFT JOIN marksheet_subjects ms ON m.id = ms.marksheet_id
     WHERE m.teacher_id = ?
     GROUP BY
        m.id, m.exam_name, m.exam_date, m.remarks,
        s.student_name, s.admission_number, s.class_name, s.section
     ORDER BY m.exam_date DESC, m.id DESC"
);
$stmt->execute([$teacherId]);
$marksheets = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Marksheets | Online Parent-Teacher Meeting System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/teacher.css">

    <style>
        .marksheet-container {
            max-width: 1150px;
            margin: 30px auto;
            padding: 20px;
        }

        .marksheet-card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
        }

        .marksheet-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #d5dbe3;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .subject-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 12px;
            align-items: center;
        }

        .subject-row input {
            min-width: 0;
            width: 100%;
            padding: 10px;
            border: 1px solid #d5dbe3;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .primary-btn,
        .secondary-btn,
        .delete-btn,
        .edit-btn {
            display: inline-block;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            margin: 3px;
        }

        .primary-btn {
            background: #2563eb;
            color: white;
        }

        .secondary-btn {
            background: #64748b;
            color: white;
        }

        .edit-btn {
            background: #0d9488;
            color: white;
        }

        .delete-btn {
            background: #dc2626;
            color: white;
        }

        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            background: #dcfce7;
            color: #166534;
        }

        .error {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .marksheet-table {
            width: 100%;
            border-collapse: collapse;
        }

        .marksheet-table th,
        .marksheet-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .marksheet-table th {
            background: #f1f5f9;
        }

        .subject-heading {
            margin: 20px 0 12px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 18px;
            text-decoration: none;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .subject-row {
                grid-template-columns: 1fr 1fr;
            }

            .subject-row input:first-child {
                grid-column: span 2;
            }
        }
    </style>
</head>

<body>

<div class="marksheet-container">

    <a class="back-link" href="teacher_dashboard.php">
        &larr; Back to Teacher Dashboard
    </a>

    <div class="marksheet-card">
        <h2>Manage Student Marksheets</h2>

        <?php if ($message): ?>
            <div class="message"><?php echo e($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="teacher_marksheets.php">

            <input type="hidden" name="action" value="save">

            <input
                type="hidden"
                name="marksheet_id"
                value="<?php echo e($editMarksheets['id'] ?? ''); ?>"
            >

            <div class="form-grid">

                <div class="form-group">
                    <label for="student_id">Select Student</label>

                    <select name="student_id" id="student_id" required>
                        <option value="">Choose a student</option>

                        <?php foreach ($students as $student): ?>
                            <option
                                value="<?php echo e($student['id']); ?>"
                                <?php
                                if (
                                    isset($editMarksheets['student_id']) &&
                                    $editMarksheets['student_id'] == $student['id']
                                ) {
                                    echo 'selected';
                                }
                                ?>
                            >
                                <?php
                                echo e(
                                    $student['student_name'] .
                                    ' - ' .
                                    $student['admission_number'] .
                                    ' (' .
                                    $student['class_name'] .
                                    ' ' .
                                    $student['section'] .
                                    ')'
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="exam_name">Exam Name</label>
                    <input
                        type="text"
                        name="exam_name"
                        id="exam_name"
                        placeholder="e.g. First Term Examination"
                        value="<?php echo e($editMarksheets['exam_name'] ?? ''); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="exam_date">Exam Date</label>
                    <input
                        type="date"
                        name="exam_date"
                        id="exam_date"
                        value="<?php echo e($editMarksheets['exam_date'] ?? date('Y-m-d')); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="remarks">Remarks (Optional)</label>
                    <input
                        type="text"
                        name="remarks"
                        id="remarks"
                        placeholder="Overall performance remarks"
                        value="<?php echo e($editMarksheets['remarks'] ?? ''); ?>"
                    >
                </div>

            </div>

            <h3 class="subject-heading">Subject-wise Marks</h3>

            <div id="subjects-container">

                <?php if (!empty($editSubjects)): ?>

                    <?php foreach ($editSubjects as $subject): ?>
                        <div class="subject-row">
                            <input
                                type="text"
                                name="subject_name[]"
                                placeholder="Subject name"
                                value="<?php echo e($subject['subject_name']); ?>"
                                required
                            >

                            <input
                                type="number"
                                name="total_marks[]"
                                placeholder="Total marks"
                                min="1"
                                step="0.01"
                                value="<?php echo e($subject['total_marks']); ?>"
                                required
                            >

                            <input
                                type="number"
                                name="obtained_marks[]"
                                placeholder="Obtained"
                                min="0"
                                step="0.01"
                                value="<?php echo e($subject['obtained_marks']); ?>"
                                required
                            >

                            <button
                                type="button"
                                class="delete-btn"
                                onclick="removeSubject(this)"
                            >
                                Remove
                            </button>
                        </div>
                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="subject-row">
                        <input
                            type="text"
                            name="subject_name[]"
                            placeholder="Subject name"
                            required
                        >

                        <input
                            type="number"
                            name="total_marks[]"
                            placeholder="Total marks"
                            min="1"
                            step="0.01"
                            required
                        >

                        <input
                            type="number"
                            name="obtained_marks[]"
                            placeholder="Obtained"
                            min="0"
                            step="0.01"
                            required
                        >

                        <button
                            type="button"
                            class="delete-btn"
                            onclick="removeSubject(this)"
                        >
                            Remove
                        </button>
                    </div>

                <?php endif; ?>

            </div>

            <button
                type="button"
                class="secondary-btn"
                onclick="addSubject()"
            >
                + Add Another Subject
            </button>

            <br><br>

            <button type="submit" class="primary-btn">
                <?php echo $editMarksheets ? 'Update Marksheet' : 'Save Marksheet'; ?>
            </button>

            <?php if ($editMarksheets): ?>
                <a href="teacher_marksheets.php" class="secondary-btn">
                    Cancel Editing
                </a>
            <?php endif; ?>

        </form>
    </div>

    <div class="marksheet-card">
        <h2>Saved Student Marksheets</h2>

        <div class="table-wrapper">
            <table class="marksheet-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission No.</th>
                        <th>Exam</th>
                        <th>Date</th>
                        <th>Total Marks</th>
                        <th>Obtained Marks</th>
                        <th>Percentage</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($marksheets)): ?>

                        <tr>
                            <td colspan="8">No marksheets have been added yet.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($marksheets as $marksheet): ?>

                            <?php
                            $total = (float) $marksheet['total_marks'];
                            $obtained = (float) $marksheet['obtained_marks'];

                            $percentage = $total > 0
                                ? ($obtained / $total) * 100
                                : 0;
                            ?>

                            <tr>
                                <td>
                                    <?php echo e($marksheet['student_name']); ?>
                                    <br>
                                    <small>
                                        <?php
                                        echo e(
                                            $marksheet['class_name'] .
                                            ' ' .
                                            $marksheet['section']
                                        );
                                        ?>
                                    </small>
                                </td>

                                <td><?php echo e($marksheet['admission_number']); ?></td>

                                <td><?php echo e($marksheet['exam_name']); ?></td>

                                <td><?php echo e($marksheet['exam_date']); ?></td>

                                <td><?php echo e(number_format($total, 2)); ?></td>

                                <td><?php echo e(number_format($obtained, 2)); ?></td>

                                <td><?php echo e(number_format($percentage, 2)); ?>%</td>

                                <td>
                                    <a
                                        href="teacher_marksheets.php?edit=<?php echo e($marksheet['id']); ?>"
                                        class="edit-btn"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="teacher_marksheets.php"
                                        style="display:inline;"
                                        onsubmit="return confirm('Are you sure you want to delete this marksheet?');"
                                    >
                                        <input type="hidden" name="action" value="delete">

                                        <input
                                            type="hidden"
                                            name="marksheet_id"
                                            value="<?php echo e($marksheet['id']); ?>"
                                        >

                                        <button type="submit" class="delete-btn">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function addSubject() {
    const container = document.getElementById('subjects-container');

    const row = document.createElement('div');
    row.className = 'subject-row';

    row.innerHTML = `
        <input
            type="text"
            name="subject_name[]"
            placeholder="Subject name"
            required
        >

        <input
            type="number"
            name="total_marks[]"
            placeholder="Total marks"
            min="1"
            step="0.01"
            required
        >

        <input
            type="number"
            name="obtained_marks[]"
            placeholder="Obtained"
            min="0"
            step="0.01"
            required
        >

        <button
            type="button"
            class="delete-btn"
            onclick="removeSubject(this)"
        >
            Remove
        </button>
    `;

    container.appendChild(row);
}

function removeSubject(button) {
    const container = document.getElementById('subjects-container');

    if (container.children.length <= 1) {
        alert('At least one subject is required.');
        return;
    }

    button.closest('.subject-row').remove();
}
</script>

</body>
</html>