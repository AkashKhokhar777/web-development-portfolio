

<?php
session_start();

$loginError = $_SESSION["login_error"] ?? "";
unset($_SESSION["login_error"]);
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Administrator Login | Online Parent-Teacher Meeting System</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>

<body class="login-page">

    <!-- Simple header -->
    <header class="site-header">
        <div class="container navbar">

            <a href="index.php" class="brand">
                <span class="brand-icon">S</span>
                <span>
                    <strong>Online Parent-Teacher Meeting System</strong>
                    <small>Parent-Teacher Portal</small>
                </span>
            </a>

            <a href="index.php" class="back-link">
                ← Back to Home
            </a>

        </div>
    </header>


    <!-- Login section -->
    <main class="login-main">
        <div class="container login-layout">

            <!-- Left introduction panel -->
            <section class="login-intro">

                <span class="section-label">ADMINISTRATOR PORTAL</span>

                <h1>
                    Manage your school
                    <span>with confidence.</span>
                </h1>

                <p>
                    Sign in to manage parent registrations, teachers,
                    meeting schedules, bookings, and school reports.
                </p>

                <div class="login-benefits">

                    <div class="benefit-item">
                        <span class="benefit-check">✓</span>
                        Manage parent and teacher accounts
                    </div>

                    <div class="benefit-item">
                        <span class="benefit-check">✓</span>
                        Organize meeting events and time slots
                    </div>

                    <div class="benefit-item">
                        <span class="benefit-check">✓</span>
                        Review bookings and system reports
                    </div>

                </div>

                <div class="login-help">
                    <strong>Administrator access</strong>
                    <p>
                        This area is intended for authorized school
                        administrators only.
                    </p>
                </div>

            </section>


            <!-- Login form -->
            <section class="login-card">

                <div class="login-card-heading">
                    <div class="login-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="4" y="10" width="16" height="11"
                                  rx="2"
                                  stroke="currentColor"
                                  stroke-width="1.8"/>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"
                                  stroke="currentColor"
                                  stroke-width="1.8"
                                  stroke-linecap="round"/>
                            <circle cx="12" cy="15" r="1.3"
                                    fill="currentColor"/>
                        </svg>
                    </div>

                    <h2>Welcome back</h2>

                    <p>Enter your administrator credentials to continue.</p>
                </div>


                
                <?php if ($loginError !== ""): ?>
                    <div class="login-message" role="alert">
                        <?php echo htmlspecialchars($loginError, ENT_QUOTES, "UTF-8"); ?>
                    </div>
                <?php endif; ?>



                <form method="POST" action="login_process.php">

                <input type="hidden" name="role" value="admin">

                    <div class="form-group">
                        <label for="admin-email">Email address</label>

                        <input
                            type="email"
                            id="admin-email"
                            name="email"
                            placeholder="admin@school.com"
                            autocomplete="username"
                            required
                        >
                    </div>


                    <div class="form-group">
                        <label for="admin-password">Password</label>

                        <input
                            type="password"
                            id="admin-password"
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
                    <span>
                        For authorized administrators only.
                    </span>
                </div>

                <div class="login-card-footer">
                    <a href="index.php">Return to Online Parent-Teacher Meeting System homepage</a>
                </div>

            </section>

        </div>
    </main>


    <!-- Footer -->
    <footer class="site-footer">
        <div class="container login-footer">
            <p>
                © <?php echo date("Y"); ?> Online Parent-Teacher Meeting System
            </p>

            <p>Connecting parents, teachers, and schools.</p>
        </div>
    </footer>

</body>
</html>