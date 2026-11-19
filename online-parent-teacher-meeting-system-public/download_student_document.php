
<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

// Only logged-in parents can access student documents.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "parent"
) {
    header("Location: parent.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$recordId = (int) ($_GET["id"] ?? 0);

// Check whether the parent requested to view or download the PDF.
$viewInline = isset($_GET["view"]) && $_GET["view"] === "1";

if ($recordId <= 0) {
    http_response_code(400);
    exit("Invalid document request.");
}

try {

    $stmt = $pdo->prepare(
        "SELECT sr.file_path, sr.original_file_name
         FROM student_records sr
         INNER JOIN students s ON sr.student_id = s.id
         WHERE sr.id = :record_id
           AND s.parent_id = :parent_id
           AND sr.file_path IS NOT NULL
         LIMIT 1"
    );

    $stmt->execute([
        "record_id" => $recordId,
        "parent_id" => $_SESSION["user_id"]
    ]);

    $document = $stmt->fetch();

    if (!$document) {
        http_response_code(404);
        exit("Document not found or you are not authorized to access it.");
    }

    $baseDirectory = realpath(__DIR__ . "/uploads/student_documents");

    if ($baseDirectory === false) {
        http_response_code(404);
        exit("Document folder not found.");
    }

    $filePath = __DIR__ . "/" . ltrim($document["file_path"], "/");
    $realPath = realpath($filePath);

    // Ensure the requested file is inside the protected document folder.
    if (
        $realPath === false ||
        strpos($realPath, $baseDirectory . DIRECTORY_SEPARATOR) !== 0 ||
        !is_file($realPath)
    ) {
        http_response_code(404);
        exit("Document file not found.");
    }

    $downloadName = basename(
        $document["original_file_name"] ?: "student-document.pdf"
    );

    $downloadName = str_replace(
        ["\"", "\r", "\n"],
        "",
        $downloadName
    );

    header("Content-Type: application/pdf");

    // View opens the PDF in the browser.
    // Download saves the PDF to the device.
    if ($viewInline) {
        header("Content-Disposition: inline; filename=\"" . $downloadName . "\"");
    } else {
        header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"");
    }

    header("Content-Length: " . filesize($realPath));
    header("X-Content-Type-Options: nosniff");

    readfile($realPath);
    exit;

} catch (PDOException $e) {

    error_log("Student document access error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to access the document. Please try again.");
}
?>
