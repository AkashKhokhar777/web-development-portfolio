
<?php
session_start();

require_once __DIR__ . "/config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";
$role = $_POST["role"] ?? "";

$allowedRoles = ["admin", "teacher", "parent"];

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    $password === "" ||
    !in_array($role, $allowedRoles, true)
) {
    $_SESSION["login_error"] = "Please enter valid login details.";
    header("Location: " . $role . ".php");
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, password, role, status
         FROM users
         WHERE email = :email AND role = :role
         LIMIT 1"
    );

    $stmt->execute([
        "email" => $email,
        "role" => $role
    ]);

    $user = $stmt->fetch();

    if (
        !$user ||
        $user["status"] !== "active" ||
        !password_verify($password, $user["password"])
    ) {
        $_SESSION["login_error"] = "Invalid email or password.";
        header("Location: " . $role . ".php");
        exit;
    }

    session_regenerate_id(true);

    $_SESSION["user_id"] = $user["id"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["email"] = $user["email"];
    $_SESSION["role"] = $user["role"];

    header("Location: " . $role . "_dashboard.php");
    exit;

} catch (PDOException $e) {
    error_log("Login database error: " . $e->getMessage());
    http_response_code(500);
    exit("A system error occurred. Please try again later.");
}
