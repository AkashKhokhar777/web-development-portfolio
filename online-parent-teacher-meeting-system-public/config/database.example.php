<?php
// Copy this file to database.php and update these values for your own local setup.
// Do not commit database.php or real credentials to a public repository.

$host = "localhost";
$dbname = "online_ptm";
$username = "your_local_database_username";
$password = "your_local_database_password";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Avoid exposing database details in public-facing error messages.
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Check your local configuration.");
}
