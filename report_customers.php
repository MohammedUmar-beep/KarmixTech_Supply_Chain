<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

if (!in_array(strtolower($user_role), ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

// Fetch Report Data
$query = "
    SELECT 
        c.customer_name, c.email, c.contact_number,
        COALESCE(SUM(i.amount), 0) as total_invoiced,
        COALESCE((
            SELECT SUM(pr.amount) 
            FROM payments_received pr 
            JOIN invoices inv ON pr.invoice_id = inv.id 
            WHERE inv.customer_id = c.id
        ), 0) as total_paid
    FROM customers c
    LEFT JOIN invoices i ON c.id = i.customer_id AND i.status != 'Draft'
    GROUP BY c.id
    ORDER BY (COALESCE(SUM(i.amount), 0) - COALESCE((
            SELECT SUM(pr.amount) 
            FROM payments_received pr 
            JOIN invoices inv ON pr.invoice_id = inv.id 
            WHERE inv.customer_id = c.id
        ), 0)) DESC
";
$result = $conn->query($query);

// Calc Totals
$totals_res = $conn->query("
    SELECT 
        COALESCE(SUM(i.amount), 0) as all_invoiced,
        (SELECT COALESCE(SUM(amount), 0) FROM payments_received) as all_paid
    FROM invoices i
    WHERE i.status != 'Draft'
");
$totals = $totals_res ? $totals_res->fetch_assoc() : ['all_invoiced' => 0, 'all_paid' => 0];
$total_outstanding = $totals['all_invoiced'] - $totals['all_paid'];

$current_page = 'reports.php';
$page_title = 'Customer Balances Report';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="reports.php"
                style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Reports
            </a>
            <div style="display:flex; align-items:center; gap: 15px;">
                <h1 style="margin:0;">Customer Balances</h1>
                <a href="export.php?module=report_customers"
                    style="background:var(--primary-color); color:white; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;">Export
                    CSV</a>
            </div>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="metrics-grid" style="margin-top: 20px;">
        <div class="metric-card">
            <div class="metric-title">Total Accounts Receivable</div>
            <div class="metric-value" style="color:var(--danger-color);">$
                <?= number_format($total_outstanding > 0 ? $total_outstanding : 0, 2) ?>
            </div>
            <div class="metric-change negative">Outstanding payments</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Total Invoiced</div>
            <div class="metric-value">$
                <?= number_format($totals['all_invoiced'], 2) ?>
            </div>
            <div class="metric-change">All Time</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Total Receipts</div>
            <div class="metric-value" style="color:var(--success-color);">$
                <?= number_format($totals['all_paid'], 2) ?>
            </div>
            <div class="metric-change positive">Successfully collected</div>
        </div>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th style="text-align: right;">Total Invoiced</th>
                        <th style="text-align: right;">Total Paid</th>
                        <th style="text-align: right;">Outstanding Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()):
                            $balance = $row['total_invoiced'] - $row['total_paid'];
                            if ($balance < 0)
                                $balance = 0; // Avoid negative balances from overpayments for this simple view
                            ?>
                            <tr>
                                <td style="font-weight: 500;">
                                    <?= htmlspecialchars($row['customer_name']) ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 13px;">
                                    <?= htmlspecialchars($row['email']) ?><br>
                                    <?= htmlspecialchars($row['contact_number']) ?>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">$
                                    <?= number_format($row['total_invoiced'], 2) ?>
                                </td>
                                <td style="text-align: right; color: var(--success-color);">$
                                    <?= number_format($row['total_paid'], 2) ?>
                                </td>
                                <td
                                    style="text-align: right; font-weight: 600; color: <?= $balance > 0 ? 'var(--danger-color)' : 'var(--text-dark)' ?>;">
                                    $
                                    <?= number_format($balance, 2) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 20px; color: var(--text-muted);">No Customer
                                Data Found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>