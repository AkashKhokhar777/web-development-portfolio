
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

$teacherId = null;
$students = [];
$records = [];
$error = "";
$success = "";
$editRecord = null;

$uploadDirectory = __DIR__ . "/uploads/student_documents/";
$uploadRelativeDirectory = "uploads/student_documents/";

function escapeOutput($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function uploadAcademicDocument($file, $uploadDirectory, $relativeDirectory) {
    if (!isset($file) || $file["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("The document upload failed. Please try again.");
    }

    if ($file["size"] > 5 * 1024 * 1024) {
        throw new RuntimeException("The PDF file must not exceed 5 MB.");
    }

    $originalName = basename($file["name"]);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension !== "pdf") {
        throw new RuntimeException("Only PDF documents are allowed.");
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file["tmp_name"]);

    if ($mimeType !== "application/pdf") {
        throw new RuntimeException("The uploaded file is not a valid PDF.");
    }

    if (!is_dir($uploadDirectory)) {
        if (!mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException("Unable to create the document upload folder.");
        }
    }

    // Prevent direct public access to uploaded documents.
    $htaccessFile = $uploadDirectory . ".htaccess";

    if (!file_exists($htaccessFile)) {
        file_put_contents(
            $htaccessFile,
            "Require all denied\n"
        );
    }

    $newFileName = bin2hex(random_bytes(16)) . ".pdf";
    $destination = $uploadDirectory . $newFileName;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        throw new RuntimeException("Unable to save the uploaded PDF.");
    }

    return [
        "path" => $relativeDirectory . $newFileName,
        "name" => $originalName
    ];
}

function removeAcademicDocument($relativePath) {
    if (!$relativePath) {
        return;
    }

    $baseDirectory = realpath(__DIR__ . "/uploads/student_documents");

    if ($baseDirectory === false) {
        return;
    }

    $fullPath = __DIR__ . "/" . ltrim($relativePath, "/");
    $realPath = realpath($fullPath);

    if (
        $realPath !== false &&
        strpos($realPath, $baseDirectory . DIRECTORY_SEPARATOR) === 0 &&
        is_file($realPath)
    ) {
        unlink($realPath);
    }
}

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1"
    );
    $stmt->execute(["user_id" => $_SESSION["user_id"]]);
    $teacherId = $stmt->fetchColumn();

    if (!$teacherId) {
        die("Teacher profile not found. Please contact the administrator.");
    }

    $stmt = $pdo->query(
        "SELECT id, student_name, admission_number, class_name, section
         FROM students
         ORDER BY student_name ASC"
    );
    $students = $stmt->fetchAll();

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $action = $_POST["action"] ?? "";

        if ($action === "save_record") {
            $recordId = (int) ($_POST["record_id"] ?? 0);
            $studentId = (int) ($_POST["student_id"] ?? 0);
            $recordTitle = trim($_POST["record_title"] ?? "");
            $recordType = $_POST["record_type"] ?? "";
            $description = trim($_POST["description"] ?? "");
            $recordDate = $_POST["record_date"] ?? "";

            $allowedTypes = [
                "test_result",
                "assignment",
                "report",
                "document"
            ];

            if (
                $studentId <= 0 ||
                $recordTitle === "" ||
                !in_array($recordType, $allowedTypes, true) ||
                $recordDate === ""
            ) {
                $error = "Please complete all required fields correctly.";
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM students WHERE id = :student_id"
                );
                $stmt->execute(["student_id" => $studentId]);

                if ((int) $stmt->fetchColumn() === 0) {
                    $error = "The selected student does not exist.";
                } else {
                    $existingRecord = null;

                    if ($recordId > 0) {
                        $stmt = $pdo->prepare(
                            "SELECT * FROM student_records
                             WHERE id = :record_id AND teacher_id = :teacher_id
                             LIMIT 1"
                        );
                        $stmt->execute([
                            "record_id" => $recordId,
                            "teacher_id" => $teacherId
                        ]);
                        $existingRecord = $stmt->fetch();

                        if (!$existingRecord) {
                            $error = "The academic record was not found.";
                        }
                    }

                    if ($error === "") {
                        $uploadedDocument = null;

                        try {
                            $uploadedDocument = uploadAcademicDocument(
                                $_FILES["academic_document"] ?? null,
                                $uploadDirectory,
                                $uploadRelativeDirectory
                            );

                            if (
                                $recordType === "document" &&
                                !$uploadedDocument &&
                                !$existingRecord
                            ) {
                                throw new RuntimeException(
                                    "Please select a PDF document to upload."
                                );
                            }

                            if (
                                $recordType === "document" &&
                                !$uploadedDocument &&
                                $existingRecord &&
                                empty($existingRecord["file_path"])
                            ) {
                                throw new RuntimeException(
                                    "Please upload a PDF document for this record."
                                );
                            }

                            if ($recordId > 0) {
                                $newPath = $uploadedDocument
                                    ? $uploadedDocument["path"]
                                    : ($existingRecord["file_path"] ?? null);

                                $newName = $uploadedDocument
                                    ? $uploadedDocument["name"]
                                    : ($existingRecord["original_file_name"] ?? null);

                                $stmt = $pdo->prepare(
                                    "UPDATE student_records
                                     SET student_id = :student_id,
                                         record_title = :record_title,
                                         record_type = :record_type,
                                         description = :description,
                                         record_date = :record_date,
                                         file_path = :file_path,
                                         original_file_name = :original_file_name
                                     WHERE id = :record_id
                                       AND teacher_id = :teacher_id"
                                );

                                $stmt->execute([
                                    "student_id" => $studentId,
                                    "record_title" => $recordTitle,
                                    "record_type" => $recordType,
                                    "description" => $description,
                                    "record_date" => $recordDate,
                                    "file_path" => $newPath,
                                    "original_file_name" => $newName,
                                    "record_id" => $recordId,
                                    "teacher_id" => $teacherId
                                ]);

                                if ($uploadedDocument) {
                                    removeAcademicDocument(
                                        $existingRecord["file_path"] ?? null
                                    );
                                }

                                header("Location: teacher_academics.php?updated=1");
                                exit;
                            } else {
                                $stmt = $pdo->prepare(
                                    "INSERT INTO student_records
                                     (student_id, teacher_id, record_title,
                                      record_type, description, record_date,
                                      file_path, original_file_name)
                                     VALUES
                                     (:student_id, :teacher_id, :record_title,
                                      :record_type, :description, :record_date,
                                      :file_path, :original_file_name)"
                                );

                                $stmt->execute([
                                    "student_id" => $studentId,
                                    "teacher_id" => $teacherId,
                                    "record_title" => $recordTitle,
                                    "record_type" => $recordType,
                                    "description" => $description,
                                    "record_date" => $recordDate,
                                    "file_path" => $uploadedDocument["path"] ?? null,
                                    "original_file_name" => $uploadedDocument["name"] ?? null
                                ]);

                                header("Location: teacher_academics.php?added=1");
                                exit;
                            }
                        } catch (RuntimeException $uploadError) {
                            if ($uploadedDocument) {
                                removeAcademicDocument($uploadedDocument["path"]);
                            }

                            $error = $uploadError->getMessage();
                        }
                    }
                }
            }
        }

        if ($action === "delete_record") {
            $recordId = (int) ($_POST["record_id"] ?? 0);

            $stmt = $pdo->prepare(
                "SELECT file_path FROM student_records
                 WHERE id = :record_id AND teacher_id = :teacher_id"
            );
            $stmt->execute([
                "record_id" => $recordId,
                "teacher_id" => $teacherId
            ]);
            $documentPath = $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "DELETE FROM student_records
                 WHERE id = :record_id AND teacher_id = :teacher_id"
            );
            $stmt->execute([
                "record_id" => $recordId,
                "teacher_id" => $teacherId
            ]);

            if ($stmt->rowCount() > 0) {
                removeAcademicDocument($documentPath);
            }

            header("Location: teacher_academics.php?deleted=1");
            exit;
        }
    }

    if (isset($_GET["edit"])) {
        $editId = (int) $_GET["edit"];

        $stmt = $pdo->prepare(
            "SELECT *
             FROM student_records
             WHERE id = :record_id AND teacher_id = :teacher_id
             LIMIT 1"
        );
        $stmt->execute([
            "record_id" => $editId,
            "teacher_id" => $teacherId
        ]);

        $editRecord = $stmt->fetch();

        if (!$editRecord) {
            $error = "The requested record was not found.";
        }
    }

    $stmt = $pdo->prepare(
        "SELECT sr.*, s.student_name, s.admission_number,
                s.class_name, s.section
         FROM student_records sr
         INNER JOIN students s ON sr.student_id = s.id
         WHERE sr.teacher_id = :teacher_id
         ORDER BY sr.record_date DESC, sr.id DESC"
    );
    $stmt->execute(["teacher_id" => $teacherId]);
    $records = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Teacher academic records error: " . $e->getMessage());
    $error = "Unable to process academic records. Please try again.";
}

if (isset($_GET["added"])) {
    $success = "Academic record added successfully.";
} elseif (isset($_GET["updated"])) {
    $success = "Academic record updated successfully.";
} elseif (isset($_GET["deleted"])) {
    $success = "Academic record deleted successfully.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Records - Online Parent-Teacher Meeting System</title>

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
            max-width: 1100px;
            margin: 35px auto;
        }

        .page-title {
            margin-bottom: 25px;
        }

        .page-title h2 {
            font-size: 27px;
            margin-bottom: 8px;
        }

        .page-title p {
            color: #6b7280;
            line-height: 1.5;
        }

        .panel {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .panel h3 {
            color: #1e3a8a;
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
            gap: 8px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .btn {
            display: inline-block;
            border: none;
            background: #1e3a8a;
            color: white;
            padding: 11px 18px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }

        .btn:hover {
            background: #162e6b;
        }

        .cancel-btn {
            background: #6b7280;
        }

        .edit-btn {
            background: #2563eb;
        }

        .delete-btn {
            background: #dc2626;
        }

        .message {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #eff6ff;
            color: #1e3a8a;
        }

        .actions-cell {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .actions-cell form {
            display: inline;
        }

        .small-btn {
            padding: 8px 11px;
            font-size: 12px;
        }

        .empty {
            color: #6b7280;
            padding: 15px 0;
        }

        @media (max-width: 650px) {
            .header {
                flex-direction: column;
                gap: 15px;
                padding: 18px 20px;
            }

            .container {
                width: 94%;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
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
        <h2>Student Academic Records</h2>
        <p>Add, update, and manage academic information for students.</p>
    </div>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= escapeOutput($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?= escapeOutput($error) ?>
        </div>
    <?php endif; ?>

    <section class="panel">

        <h3>
            <?= $editRecord ? "Update Academic Record" : "Add Academic Record" ?>
        </h3>

        <?php if (count($students) === 0): ?>

            <p class="empty">
                No students are available. Please contact the administrator
                to add student records first.
            </p>

        <?php else: ?>

            <form method="POST" action="teacher_academics.php" enctype="multipart/form-data">

                <input type="hidden" name="action" value="save_record">

                <input
                    type="hidden"
                    name="record_id"
                    value="<?= escapeOutput($editRecord["id"] ?? "") ?>"
                >

                <div class="form-grid">

                    <div class="form-group">
                        <label for="student_id">Select Student *</label>

                        <select id="student_id" name="student_id" required>
                            <option value="">Choose a student</option>

                            <?php foreach ($students as $student): ?>
                                <option
                                    value="<?= (int) $student["id"] ?>"
                                    <?= (
                                        (int) ($editRecord["student_id"] ?? 0)
                                        === (int) $student["id"]
                                    ) ? "selected" : "" ?>
                                >
                                    <?= escapeOutput($student["student_name"]) ?>
                                    (<?= escapeOutput($student["admission_number"]) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="record_type">Record Type *</label>

                        <select id="record_type" name="record_type" required>
                            <option value="">Choose record type</option>

                            <option value="test_result"
                                <?= (($editRecord["record_type"] ?? "") === "test_result") ? "selected" : "" ?>>
                                Test Result
                            </option>

                            <option value="assignment"
                                <?= (($editRecord["record_type"] ?? "") === "assignment") ? "selected" : "" ?>>
                                Assignment
                            </option>

                            <option value="report"
                                <?= (($editRecord["record_type"] ?? "") === "report") ? "selected" : "" ?>>
                                Progress Report
                            </option>

                            <option value="document"
                                <?= (($editRecord["record_type"] ?? "") === "document") ? "selected" : "" ?>>
                                Document
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="record_title">Record Title *</label>

                        <input
                            type="text"
                            id="record_title"
                            name="record_title"
                            maxlength="150"
                            required
                            value="<?= escapeOutput($editRecord["record_title"] ?? "") ?>"
                            placeholder="e.g. Mathematics Test 1"
                        >
                    </div>

                    <div class="form-group">
                        <label for="record_date">Record Date *</label>

                        <input
                            type="date"
                            id="record_date"
                            name="record_date"
                            required
                            value="<?= escapeOutput($editRecord["record_date"] ?? date("Y-m-d")) ?>"
                        >
                    </div>

                    <div class="form-group full-width">
                        <label for="description">Description</label>

                        <textarea
                            id="description"
                            name="description"
                            maxlength="5000"
                            placeholder="Enter marks, performance details, comments, or document information."
                        ><?= escapeOutput($editRecord["description"] ?? "") ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="academic_document">Upload PDF Document / DMC (Maximum 5 MB)</label>

                        <input
                            type="file"
                            id="academic_document"
                            name="academic_document"
                            accept=".pdf,application/pdf"
                        >

                        <?php if (!empty($editRecord["original_file_name"])): ?>
                            <p class="empty">
                                Current document:
                                <?= escapeOutput($editRecord["original_file_name"]) ?>
                            </p>
                            <p class="empty">
                                Choose another PDF only if you want to replace the current document.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-width">
                        <div>
                            <button type="submit" class="btn">
                                <?= $editRecord ? "Update Record" : "Save Record" ?>
                            </button>

                            <?php if ($editRecord): ?>
                                <a href="teacher_academics.php" class="btn cancel-btn">
                                    Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </form>

        <?php endif; ?>

    </section>

    <section class="panel">

        <h3>Academic Records List</h3>

        <?php if (count($records) > 0): ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Admission Number</th>
                            <th>Class</th>
                            <th>Record Title</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($records as $record): ?>

                            <tr>
                                <td><?= escapeOutput($record["student_name"]) ?></td>

                                <td><?= escapeOutput($record["admission_number"]) ?></td>

                                <td>
                                    <?= escapeOutput($record["class_name"]) ?>
                                    <?= escapeOutput($record["section"]) ?>
                                </td>

                                <td><?= escapeOutput($record["record_title"]) ?></td>

                                <td>
                                    <?= escapeOutput(
                                        ucwords(str_replace("_", " ", $record["record_type"]))
                                    ) ?>
                                </td>

                                <td><?= escapeOutput($record["record_date"]) ?></td>

                                <td>
                                    <div class="actions-cell">

                                        <a
                                            class="btn edit-btn small-btn"
                                            href="teacher_academics.php?edit=<?= (int) $record["id"] ?>"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="teacher_academics.php"
                                            onsubmit="return confirm('Are you sure you want to delete this academic record?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_record"
                                            >

                                            <input
                                                type="hidden"
                                                name="record_id"
                                                value="<?= (int) $record["id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn delete-btn small-btn"
                                            >
                                                Delete
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php else: ?>

            <p class="empty">
                No academic records have been added yet.
            </p>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
