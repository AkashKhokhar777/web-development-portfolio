
<?php
session_start();

if (isset($_SESSION["user_id"]) && $_SESSION["role"] === "parent") {
    header("Location: parent_dashboard.php");
    exit;
}

$loginMessage = $_SESSION["login_error"] ?? "";
unset($_SESSION["login_error"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Login | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/parent.css">

    <style>
        .registration-link {
            text-align: center;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
            color: #4b5563;
            font-size: 14px;
        }

        .registration-link a {
            color: #1e3a8a;
            font-weight: bold;
            text-decoration: none;
        }

        .registration-link a:hover {
            text-decoration: underline;
        }
    </style>
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
            <span class="section-label">PARENT PORTAL</span>

            <h1>Stay connected to your child's <span>progress.</span></h1>

            <p>
                Access your parent portal to view available parent-teacher
                meetings, manage bookings, and stay informed about your child's
                school progress.
            </p>

            <div class="login-benefits">
                <div class="benefit-item">
                    <span class="benefit-check">✓</span>
                    View available meeting times
                </div>

                <div class="benefit-item">
                    <span class="benefit-check">✓</span>
                    Manage meeting bookings
                </div>

                <div class="benefit-item">
                    <span class="benefit-check">✓</span>
                    Keep track of teacher meetings
                </div>
            </div>

            <div class="login-help">
                <strong>Parent access</strong>
                <p>
                    Sign in with your approved account or register using
                    your child's valid student admission number.
                </p>
            </div>
        </section>

        <section class="login-card">
            <div class="login-card-heading">
                <div class="login-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="9" cy="8" r="3.5"
                                stroke="currentColor" stroke-width="1.8"/>
                        <path d="M2.8 20V18a6.2 6.2 0 0 1 12.4 0v2"
                              stroke="currentColor" stroke-width="1.8"
                              stroke-linecap="round"/>
                        <path d="M16 5.5a3.5 3.5 0 0 1 0 6.8"
                              stroke="currentColor" stroke-width="1.8"
                              stroke-linecap="round"/>
                        <path d="M18 14a5 5 0 0 1 3.2 4.7V20"
                              stroke="currentColor" stroke-width="1.8"
                              stroke-linecap="round"/>
                    </svg>
                </div>

                <h2>Parent Sign In</h2>
                <p>Enter your account details to continue.</p>
            </div>

            <?php if ($loginMessage !== ""): ?>
                <div class="login-message" role="status">
                    <?php
                    echo htmlspecialchars(
                        $loginMessage,
                        ENT_QUOTES,
                        "UTF-8"
                    );
                    ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login_process.php">

                <input type="hidden" name="role" value="parent">

                <div class="form-group">
                    <label for="parent-email">Email address</label>
                    <input
                        type="email"
                        id="parent-email"
                        name="email"
                        placeholder="parent@example.com"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="parent-password">Password</label>
                    <input
                        type="password"
                        id="parent-password"
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

            <div class="registration-link">
                Don't have a parent account?
                <a href="parent_register.php">Register Here</a>
            </div>

            <div class="login-security-note">
                <span aria-hidden="true">🔒</span>
                <span>Your child's information should remain private.</span>
            </div>

            <div class="login-card-footer">
                <a href="index.php">Return to Online Parent-Teacher Meeting System homepage</a>
            </div>
        </section>

    </div>
</main>

<footer class="site-footer">
    <div class="container login-footer">
        <p>© <?php echo date("Y"); ?> Online Parent-Teacher Meeting System</p>
        <p>Connecting parents, teachers, and schools.</p>
    </div>
</footer>

</body>
</html>
