<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "admin") {
    header("Location: admin.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$message = "";
$error = "";

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    // Approve a pending parent account
    if ($action === "approve") {

        $parentId = filter_input(INPUT_POST, "parent_id", FILTER_VALIDATE_INT);

        if (!$parentId) {
            $error = "Invalid parent account.";
        } else {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET status = 'active'
                     WHERE id = :id
                     AND role = 'parent'
                     AND status = 'inactive'"
                );

                $stmt->execute(["id" => $parentId]);

                if ($stmt->rowCount() > 0) {
                    $message = "Parent account approved successfully. The parent can now log in.";
                } else {
                    $error = "This parent account could not be approved. It may already be active.";
                }

            } catch (PDOException $e) {
                error_log("Parent approval error: " . $e->getMessage());
                $error = "Unable to approve this parent account.";
            }
        }

    // Create a parent account from the admin panel
    } elseif ($action === "create") {

        $fullName = trim($_POST["full_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($fullName === "" || $email === "" || $password === "") {
            $error = "Please fill in all fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {

            try {
                $stmt = $pdo->prepare(
                    "SELECT id FROM users WHERE email = :email LIMIT 1"
                );

                $stmt->execute(["email" => $email]);

                if ($stmt->fetch()) {
                    $error = "An account with this email already exists.";
                } else {

                    $hashedPassword = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        "INSERT INTO users
                        (full_name, email, password, role, status)
                        VALUES
                        (:full_name, :email, :password, 'parent', 'active')"
                    );

                    $stmt->execute([
                        "full_name" => $fullName,
                        "email" => $email,
                        "password" => $hashedPassword
                    ]);

                    $message = "Parent account created successfully.";
                }

            } catch (PDOException $e) {
                error_log("Parent creation error: " . $e->getMessage());
                $error = "Unable to create the parent account.";
            }
        }
    }
}

// Load all parent accounts
try {
    $stmt = $pdo->query(
        "SELECT id, full_name, email, status, created_at
         FROM users
         WHERE role = 'parent'
         ORDER BY
            CASE WHEN status = 'inactive' THEN 0 ELSE 1 END,
            id DESC"
    );

    $parents = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Parent list error: " . $e->getMessage());
    $parents = [];
    $error = "Unable to load parent accounts.";
}

$pendingCount = 0;

foreach ($parents as $parent) {
    if ($parent["status"] === "inactive") {
        $pendingCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Parents - Online Parent-Teacher Meeting System</title>

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
            padding: 9px 16px;
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
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-title p {
            color: #6b7280;
        }

        .form-box,
        .table-box {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .form-box h3,
        .table-box h3 {
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 7px;
            font-weight: bold;
        }

        .form-group input {
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .submit-btn {
            margin-top: 20px;
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 11px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        .submit-btn:hover {
            background: #172554;
        }

        .approve-btn {
            background: #15803d;
            color: white;
            border: none;
            padding: 8px 13px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .approve-btn:hover {
            background: #166534;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .pending-summary {
            background: #fff7ed;
            color: #9a3412;
            padding: 14px 18px;
            border-radius: 7px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        th {
            background: #f9fafb;
        }

        @media (max-width: 700px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .header {
                padding: 18px 20px;
            }

            .container {
                width: 94%;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Online Parent-Teacher Meeting System</h1>

    <a href="admin_dashboard.php">
        Back to Dashboard
    </a>
</header>

<main class="container">

    <div class="page-title">
        <h2>Manage Parents</h2>
        <p>Create parent accounts and verify parent registrations.</p>
    </div>

    <?php if ($message !== ""): ?>
        <div class="success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="pending-summary">
        Pending Parent Verifications: <?= $pendingCount ?>
    </div>

    <section class="form-box">

        <h3>Create Parent Account</h3>

        <form method="POST">

            <input type="hidden" name="action" value="create">

            <div class="form-grid">

                <div class="form-group">
                    <label for="full_name">Full Name</label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="6"
                        required
                    >
                </div>

            </div>

            <button type="submit" class="submit-btn">
                Create Parent
            </button>

        </form>

    </section>

    <section class="table-box">

        <h3>Registered Parents and Verification</h3>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($parents) > 0): ?>

                        <?php foreach ($parents as $parent): ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars($parent["id"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($parent["full_name"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($parent["email"]) ?>
                                </td>

                                <td>
                                    <?php if ($parent["status"] === "inactive"): ?>
                                        <span class="status status-pending">
                                            Pending Verification
                                        </span>
                                    <?php else: ?>
                                        <span class="status status-active">
                                            Approved
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($parent["created_at"]) ?>
                                </td>

                                <td>
                                    <?php if ($parent["status"] === "inactive"): ?>

                                        <form method="POST"
                                              onsubmit="return confirm('Are you sure you want to approve this parent account?');">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve"
                                            >

                                            <input
                                                type="hidden"
                                                name="parent_id"
                                                value="<?= (int)$parent["id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="approve-btn"
                                            >
                                                Approve
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span>Already Approved</span>

                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6">
                                No parent accounts found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>
</html>