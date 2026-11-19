<?php

// Final inactivity timeout: 20 minutes
$sessionTimeout = 20 * 60;

// Check whether the user has been inactive too long
if (
    isset($_SESSION["last_activity"]) &&
    (time() - $_SESSION["last_activity"]) > $sessionTimeout
) {
    // Clear session data
    $_SESSION = [];

    // Destroy the session
    session_destroy();

    // Redirect to the main login page
    header("Location: index.php?timeout=1");
    exit;
}

// Update last activity time
$_SESSION["last_activity"] = time();

?>