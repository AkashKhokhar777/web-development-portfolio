<?php
session_start();

require_once __DIR__ . "/config/session_timeout.php";

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
$error = "";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function money($amount) {
    return "PKR " . number_format((float)$amount, 2);
}

function validDate($date) {
    if ($date === "") {
        return false;
    }

    $dateObject = DateTime::createFromFormat("Y-m-d", $date);

    return $dateObject && $dateObject->format("Y-m-d") === $date;
}

// Add a new expense
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_expense"])) {
    $title = trim($_POST["expense_title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $amount = $_POST["amount"] ?? "";
    $expenseDate = $_POST["expense_date"] ?? "";

    if (
        $title === "" ||
        !is_numeric($amount) ||
        (float)$amount <= 0 ||
        !validDate($expenseDate)
    ) {
        $error = "Please enter a valid title, amount, and expense date.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO expenses
                (expense_title, description, amount, expense_date)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $title,
                $description !== "" ? $description : null,
                $amount,
                $expenseDate
            ]);

            header("Location: manage_expenses.php?added=1");
            exit;

        } catch (PDOException $e) {
            error_log("Expense insertion error: " . $e->getMessage());
            $error = "Unable to save the expense. Please try again.";
        }
    }
}

if (isset($_GET["added"])) {
    $message = "Expense added successfully.";
}

// Read reporting date range
$fromDate = trim($_GET["from_date"] ?? "");
$toDate = trim($_GET["to_date"] ?? "");

$filterActive = false;

if ($fromDate !== "" || $toDate !== "") {
    if (
        !validDate($fromDate) ||
        !validDate($toDate)
    ) {
        $error = "Please select both valid reporting dates.";
    } elseif ($fromDate > $toDate) {
        $error = "The start date cannot be later than the end date.";
    } else {
        $filterActive = true;
    }
}

// Build date conditions
$incomeCondition = "";
$expenseCondition = "";

if ($filterActive) {
    $incomeCondition = "
        AND DATE(COALESCE(p.paid_at, p.created_at))
        BETWEEN :from_date AND :to_date
    ";

    $expenseCondition = "
        WHERE expense_date BETWEEN :from_date AND :to_date
    ";
}

// Calculate approved fee income
$incomeSql = "
    SELECT COALESCE(SUM(p.amount), 0)
    FROM payments p
    WHERE p.student_fee_id IS NOT NULL
      AND p.payment_status = 'completed'
      $incomeCondition
";

$incomeStmt = $pdo->prepare($incomeSql);

if ($filterActive) {
    $incomeStmt->execute([
        ":from_date" => $fromDate,
        ":to_date" => $toDate
    ]);
} else {
    $incomeStmt->execute();
}

$totalIncome = (float)$incomeStmt->fetchColumn();

// Calculate expenses
$expenseSql = "
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    $expenseCondition
";

$expenseStmt = $pdo->prepare($expenseSql);

if ($filterActive) {
    $expenseStmt->execute([
        ":from_date" => $fromDate,
        ":to_date" => $toDate
    ]);
} else {
    $expenseStmt->execute();
}

$totalExpenses = (float)$expenseStmt->fetchColumn();

$profitLoss = $totalIncome - $totalExpenses;

// Fetch expense records
$expensesSql = "
    SELECT *
    FROM expenses
    $expenseCondition
    ORDER BY expense_date DESC, id DESC
";

$expensesStmt = $pdo->prepare($expensesSql);

if ($filterActive) {
    $expensesStmt->execute([
        ":from_date" => $fromDate,
        ":to_date" => $toDate
    ]);
} else {
    $expensesStmt->execute();
}

$expenses = $expensesStmt->fetchAll();

// Fetch approved student fee payments
$incomeDetailsSql = "
    SELECT
        p.id,
        p.amount,
        p.payment_method,
        p.paid_at,
        p.created_at,
        sf.fee_title,
        s.student_name
    FROM payments p
    INNER JOIN student_fees sf ON p.student_fee_id = sf.id
    INNER JOIN students s ON sf.student_id = s.id
    WHERE p.payment_status = 'completed'
      AND p.student_fee_id IS NOT NULL
      $incomeCondition
    ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
";

$incomeDetailsStmt = $pdo->prepare($incomeDetailsSql);

if ($filterActive) {
    $incomeDetailsStmt->execute([
        ":from_date" => $fromDate,
        ":to_date" => $toDate
    ]);
} else {
    $incomeDetailsStmt->execute();
}

$incomeDetails = $incomeDetailsStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Expense Management | Online Parent-Teacher Meeting System</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #263445;
        }

        header {
            background: #17365d;
            color: white;
            padding: 22px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        header h1 {
            font-size: 24px;
        }

        header a {
            color: white;
            text-decoration: none;
            background: #28558a;
            padding: 10px 16px;
            border-radius: 6px;
        }

        .container {
            width: 90%;
            max-width: 1150px;
            margin: 30px auto;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .card, .panel {
            background: white;
            border-radius: 10px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        }

        .card h3 {
            color: #68778a;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .card p {
            font-size: 23px;
            font-weight: bold;
            color: #17365d;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 25px;
        }

        .panel h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #17365d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
            font-size: 14px;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        button, .clear-btn {
            display: inline-block;
            background: #17365d;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
        }

        button:hover, .clear-btn:hover {
            background: #28558a;
        }

        .message {
            background: #e3f5e9;
            color: #176536;
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .error {
            background: #fde8e8;
            color: #a12626;
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #e6ebf0;
            font-size: 14px;
        }

        th {
            background: #f0f4f8;
            color: #34465a;
        }

        .profit {
            color: #168044 !important;
        }

        .loss {
            color: #c0392b !important;
        }

        .empty {
            color: #718096;
            padding: 18px 0;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .report-note {
            color: #68778a;
            font-size: 14px;
            margin-bottom: 18px;
        }

        @media (max-width: 650px) {
            .container {
                width: 94%;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            header {
                padding: 18px 4%;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Online Parent-Teacher Meeting System - Financial Management</h1>
    <a href="admin_dashboard.php">Back to Dashboard</a>
</header>

<div class="container">

    <?php if ($message !== ""): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Profit/Loss Report Duration</h2>

        <p class="report-note">
            Select a start date and end date to generate a financial report
            for that period. Leave the dates unselected to view all records.
        </p>

        <form method="GET" action="manage_expenses.php">
            <div class="form-grid">

                <div class="field">
                    <label for="from_date">Start Date</label>
                    <input
                        type="date"
                        id="from_date"
                        name="from_date"
                        value="<?= e($fromDate) ?>"
                    >
                </div>

                <div class="field">
                    <label for="to_date">End Date</label>
                    <input
                        type="date"
                        id="to_date"
                        name="to_date"
                        value="<?= e($toDate) ?>"
                    >
                </div>

                <div class="field full">
                    <div class="filter-actions">
                        <button type="submit">Generate Report</button>
                        <a href="manage_expenses.php" class="clear-btn">
                            Show All Records
                        </a>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <?php if ($filterActive): ?>
        <p class="report-note">
            Report period:
            <strong><?= e($fromDate) ?></strong>
            to
            <strong><?= e($toDate) ?></strong>
        </p>
    <?php else: ?>
        <p class="report-note">
            Showing financial records for all available dates.
        </p>
    <?php endif; ?>

    <div class="summary">

        <div class="card">
            <h3>Total Approved Student Fee Income</h3>
            <p><?= e(money($totalIncome)) ?></p>
        </div>

        <div class="card">
            <h3>Total School Expenses</h3>
            <p><?= e(money($totalExpenses)) ?></p>
        </div>

        <div class="card">
            <h3><?= $profitLoss >= 0 ? "Net Profit" : "Net Loss" ?></h3>
            <p class="<?= $profitLoss >= 0 ? "profit" : "loss" ?>">
                <?= e(money(abs($profitLoss))) ?>
            </p>
        </div>

    </div>

    <div class="panel">
        <h2>Add School Expense</h2>

        <form method="POST" action="manage_expenses.php">
            <div class="form-grid">

                <div class="field">
                    <label for="expense_title">Expense Title</label>
                    <input
                        type="text"
                        id="expense_title"
                        name="expense_title"
                        maxlength="150"
                        placeholder="e.g. Electricity Bill"
                        required
                    >
                </div>

                <div class="field">
                    <label for="amount">Amount (PKR)</label>
                    <input
                        type="number"
                        id="amount"
                        name="amount"
                        min="0.01"
                        step="0.01"
                        placeholder="Enter amount"
                        required
                    >
                </div>

                <div class="field">
                    <label for="expense_date">Expense Date</label>
                    <input
                        type="date"
                        id="expense_date"
                        name="expense_date"
                        value="<?= e(date("Y-m-d")) ?>"
                        required
                    >
                </div>

                <div class="field full">
                    <label for="description">Description (Optional)</label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="Enter expense details"
                    ></textarea>
                </div>

                <div class="field full">
                    <button type="submit" name="add_expense">
                        Add Expense
                    </button>
                </div>

            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Expense Records</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Expense Title</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Expense Date</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($expenses) > 0): ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?= e($expense["id"]) ?></td>
                                <td><?= e($expense["expense_title"]) ?></td>
                                <td><?= e($expense["description"] ?: "-") ?></td>
                                <td><?= e(money($expense["amount"])) ?></td>
                                <td><?= e($expense["expense_date"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty">
                                No expenses found for this reporting period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>Approved Student Fee Payments</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Fee Title</th>
                        <th>Payment Method</th>
                        <th>Amount</th>
                        <th>Payment Date</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($incomeDetails) > 0): ?>
                        <?php foreach ($incomeDetails as $income): ?>
                            <tr>
                                <td><?= e($income["student_name"]) ?></td>
                                <td><?= e($income["fee_title"]) ?></td>
                                <td>
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $income["payment_method"]
                                            )
                                        )
                                    ) ?>
                                </td>
                                <td><?= e(money($income["amount"])) ?></td>
                                <td>
                                    <?= e(
                                        $income["paid_at"] ?: $income["created_at"]
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty">
                                No approved student fee payments found
                                for this reporting period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>