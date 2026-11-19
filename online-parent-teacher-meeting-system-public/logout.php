
<?php
session_start();

// Remember the user's role before destroying the session.
$role = $_SESSION['role'] ?? '';

// Clear all session data.
$_SESSION = [];

// Remove the session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session.
session_destroy();

// Redirect according to the user's role.
switch ($role) {
    case 'admin':
        header("Location: admin.php");
        break;

    case 'teacher':
        header("Location: teacher.php");
        break;

    case 'parent':
        header("Location: parent.php");
        break;

    default:
        header("Location: index.php");
        break;
}

exit;
