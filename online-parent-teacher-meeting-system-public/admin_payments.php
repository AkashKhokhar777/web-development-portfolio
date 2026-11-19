<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

// Use the same admin session verification as admin_dashboard.php
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: admin.php");
    exit;
}

require_once __DIR__ . "/config/database.php";

$message = "";
$messageType = "";

// Process approval or rejection
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $paymentId = filter_input(INPUT_POST, "payment_id", FILTER_VALIDATE_INT);
    $action = $_POST["action"] ?? "";

    if (!$paymentId || !in_array($action, ["approve", "reject"], true)) {
        $message = "Invalid payment request.";
        $messageType = "error";

    } else {

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT id, student_fee_id, payment_status
                FROM payments
                WHERE id = ?
                  AND student_fee_id IS NOT NULL
                FOR UPDATE
            ");

            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();

            if (!$payment || $payment["payment_status"] !== "pending") {
                throw new Exception("This payment has already been processed or does not exist.");
            }

            if ($action === "approve") {

                // Approve payment
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_status = 'completed',
                        paid_at = NOW()
                    WHERE id = ?
                      AND payment_status = 'pending'
                ");

                $stmt->execute([$paymentId]);

                // Mark student fee as paid
                $stmt = $pdo->prepare("
                    UPDATE student_fees
                    SET fee_status = 'paid'
                    WHERE id = ?
                ");

                $stmt->execute([$payment["student_fee_id"]]);

                $message = "Payment approved successfully. Student fee marked as paid.";
                $messageType = "success";

            } else {

                // Reject payment; fee remains pending
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_status = 'failed'
                    WHERE id = ?
                      AND payment_status = 'pending'
                ");

                $stmt->execute([$paymentId]);

                $message = "Payment rejected. Student fee remains pending.";
                $messageType = "success";
            }

            $pdo->commit();

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e->getMessage();
            $messageType = "error";
        }
    }
}

// Get all student fee payment records
$stmt = $pdo->query("
    SELECT
        p.id AS payment_id,
        p.amount,
        p.payment_method,
        p.transaction_reference,
        p.payment_status,
        p.created_at,
        sf.fee_title,
        sf.due_date,
        sf.fee_status,
        s.student_name,
        s.admission_number,
        u.full_name AS parent_name,
        u.email AS parent_email
    FROM payments p
    INNER JOIN student_fees sf
        ON p.student_fee_id = sf.id
    INNER JOIN students s
        ON sf.student_id = s.id
    INNER JOIN users u
        ON s.parent_id = u.id
    ORDER BY
        CASE
            WHEN p.payment_status = 'pending' THEN 0
            ELSE 1
        END,
        p.created_at DESC
");

$payments = $stmt->fetchAll();

// Calculate payment totals
$pendingCount = 0;
$completedCount = 0;
$rejectedCount = 0;

foreach ($payments as $payment) {

    if ($payment["payment_status"] === "pending") {
        $pendingCount++;

    } elseif ($payment["payment_status"] === "completed") {
        $completedCount++;

    } elseif ($payment["payment_status"] === "failed") {
        $rejectedCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Verification - Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        .payment-page {
            width: 92%;
            max-width: 1250px;
            margin: 35px auto;
        }

        .header {
            background: #1e3a8a;
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 25px;
        }

        .header p {
            margin: 0;
            color: #dbeafe;
        }

        .back-btn {
            background: white;
            color: #1e3a8a;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-weight: bold;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 23px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        }

        .summary-card h3 {
            color: #6b7280;
            font-size: 15px;
            margin: 0 0 12px;
        }

        .summary-card p {
            color: #1e3a8a;
            font-size: 30px;
            font-weight: bold;
            margin: 0;
        }

        .message {
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-container {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
            overflow-x: auto;
        }

        .table-container h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #1e3a8a;
        }

        table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        th, td {
            padding: 13px 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: #eff4fb;
            color: #1e3a8a;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .completed {
            background: #dcfce7;
            color: #166534;
        }

        .failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-form {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            border: none;
            padding: 8px 10px;
            border-radius: 5px;
            color: white;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
        }

        .approve-btn {
            background: #15803d;
        }

        .reject-btn {
            background: #dc2626;
        }

        .empty-message {
            text-align: center;
            padding: 35px;
            color: #6b7280;
        }

        @media (max-width: 700px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }

            .payment-page {
                width: 95%;
            }

            .header {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="payment-page">

    <header class="header">

        <div>
            <h1>Online Parent-Teacher Meeting System</h1>
            <p>Student Fee Payment Verification</p>
        </div>

        <a href="admin_dashboard.php" class="back-btn">
            Back to Dashboard
        </a>

    </header>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>

    <section class="summary-cards">

        <div class="summary-card">
            <h3>Pending Payments</h3>
            <p><?php echo $pendingCount; ?></p>
        </div>

        <div class="summary-card">
            <h3>Approved Payments</h3>
            <p><?php echo $completedCount; ?></p>
        </div>

        <div class="summary-card">
            <h3>Rejected Payments</h3>
            <p><?php echo $rejectedCount; ?></p>
        </div>

    </section>

    <section class="table-container">

        <h2>Payment Records</h2>

        <?php if (count($payments) > 0): ?>

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Parent</th>
                        <th>Fee</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction Reference</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($payments as $payment): ?>

                    <tr>

                        <td>
                            <?php echo (int)$payment["payment_id"]; ?>
                        </td>

                        <td>
                            <strong>
                                <?php echo htmlspecialchars($payment["student_name"]); ?>
                            </strong>
                            <br>
                            <small>
                                <?php echo htmlspecialchars($payment["admission_number"]); ?>
                            </small>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($payment["parent_name"]); ?>
                            <br>
                            <small>
                                <?php echo htmlspecialchars($payment["parent_email"]); ?>
                            </small>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($payment["fee_title"]); ?>
                            <br>
                            <small>
                                Due: <?php echo htmlspecialchars($payment["due_date"]); ?>
                            </small>
                        </td>

                        <td>
                            PKR <?php echo number_format((float)$payment["amount"], 2); ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                ucwords(str_replace("_", " ", $payment["payment_method"]))
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo $payment["transaction_reference"] !== null
                                && $payment["transaction_reference"] !== ""
                                ? htmlspecialchars($payment["transaction_reference"])
                                : "Not provided";
                            ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($payment["created_at"]); ?>
                        </td>

                        <td>
                            <span class="status <?php echo htmlspecialchars($payment["payment_status"]); ?>">
                                <?php echo htmlspecialchars(ucfirst($payment["payment_status"])); ?>
                            </span>
                        </td>

                        <td>

                            <?php if ($payment["payment_status"] === "pending"): ?>

                                <form method="POST"
                                      class="action-form"
                                      onsubmit="return confirm('Are you sure you want to process this payment?');">

                                    <input
                                        type="hidden"
                                        name="payment_id"
                                        value="<?php echo (int)$payment["payment_id"]; ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="action"
                                        value="approve"
                                        class="action-btn approve-btn"
                                    >
                                        Approve
                                    </button>

                                    <button
                                        type="submit"
                                        name="action"
                                        value="reject"
                                        class="action-btn reject-btn"
                                    >
                                        Reject
                                    </button>

                                </form>

                            <?php else: ?>

                                Processed

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php else: ?>

            <div class="empty-message">
                <h3>No Payment Records Found</h3>
                <p>Payments submitted by parents will appear here.</p>
            </div>

        <?php endif; ?>

    </section>

</div>

</body>
</html>