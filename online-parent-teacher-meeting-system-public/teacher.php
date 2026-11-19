<?php
session_start();

// If a teacher is already logged in, open the dashboard.
if (
    isset($_SESSION["user_id"]) &&
    isset($_SESSION["role"]) &&
    $_SESSION["role"] === "teacher"
) {
    header("Location: teacher_dashboard.php");
    exit;
}

// Get the login error, if one was returned.
$loginMessage = $_SESSION["login_error"] ?? "";
unset($_SESSION["login_error"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Login | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/teacher.css">
</head>
<body class="login-page">

<header class="site-header">
    <div class="container navbar">
        <a href="index.php" class="brand">
            <span class="brand-icon">S</span>
            <span>
                <strong>Online Parent-Teacher Meeting System</strong>
                <small>Parent-Teacher Portal</small>
            </span>
        </a>

        <a href="index.php" class="back-link">← Back to Home</a>
    </div>
</header>

<main class="login-main">
    <div class="container login-layout">

        <section class="login-intro">
            <span class="section-label">TEACHER PORTAL</span>

            <h1>Make every parent meeting <span>meaningful.</span></h1>

            <p>
                Access your teacher portal to manage meeting schedules,
                review parent bookings, and prepare for student discussions.
            </p>

            <div class="login-benefits">
                <div class="benefit-item">
                    <span class="benefit-check">✓</span>
                    Manage meeting schedules
                </div>

                <div class="benefit-item">
                    <span class="benefit-check">✓</span>
                    Review parent meeting requests
                </div>

                <div class="benefit-item">
                    <span class="benefit-check">✓</span>
                    Prepare for student progress discussions
                </div>
            </div>

            <div class="login-help">
                <strong>Teacher access</strong>
                <p>
                    This portal is intended for authorized school teachers.
                </p>
            </div>
        </section>

        <section class="login-card">
            <div class="login-card-heading">
                <div class="login-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M3 9L12 4L21 9L12 14L3 9Z"
                              stroke="currentColor"
                              stroke-width="1.8"
                              stroke-linejoin="round"/>
                        <path d="M7 11.5V16C10 18.5 14 18.5 17 16V11.5"
                              stroke="currentColor"
                              stroke-width="1.8"
                              stroke-linecap="round"/>
                        <path d="M21 9V15"
                              stroke="currentColor"
                              stroke-width="1.8"
                              stroke-linecap="round"/>
                    </svg>
                </div>

                <h2>Teacher Sign In</h2>
                <p>Enter your teacher account details to continue.</p>
            </div>

            <?php if ($loginMessage !== ""): ?>
                <div class="login-message" role="alert">
                    <?= htmlspecialchars(
                        $loginMessage,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login_process.php">
                <input type="hidden" name="role" value="teacher">

                <div class="form-group">
                    <label for="teacher-email">Email address</label>
                    <input
                        type="email"
                        id="teacher-email"
                        name="email"
                        placeholder="teacher@school.com"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="teacher-password">Password</label>
                    <input
                        type="password"
                        id="teacher-password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit"
                        class="button button-primary login-submit">
                    Sign In <span aria-hidden="true">→</span>
                </button>
            </form>

            <div class="login-security-note">
                <span aria-hidden="true">🔒</span>
                <span>For authorized teachers only.</span>
            </div>

            <div class="login-card-footer">
                <a href="index.php">Return to Online Parent-Teacher Meeting System homepage</a>
            </div>
        </section>

    </div>
</main>

<footer class="site-footer">
    <div class="container login-footer">
        <p>© <?= date("Y") ?> Online Parent-Teacher Meeting System</p>
        <p>Connecting parents, teachers, and schools.</p>
    </div>
</footer>

</body>
</html>