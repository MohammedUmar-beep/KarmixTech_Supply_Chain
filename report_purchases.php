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
    SELECT po.purchase_id_str, s.supplier_name, po.order_date, po.status, po.total_amount
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    ORDER BY po.order_date DESC
";
$result = $conn->query($query);

// Calc Totals
$totals_res = $conn->query("
    SELECT 
        COUNT(*) as total_orders, 
        SUM(CASE WHEN status = 'Received' THEN total_amount ELSE 0 END) as total_received,
        SUM(CASE WHEN status != 'Received' THEN total_amount ELSE 0 END) as total_pending
    FROM purchase_orders
");
$totals = $totals_res ? $totals_res->fetch_assoc() : ['total_orders' => 0, 'total_received' => 0, 'total_pending' => 0];

$current_page = 'reports.php';
$page_title = 'Purchase Order Report';
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
                <h1 style="margin:0;">Purchase Orders Overview</h1>
                <a href="export.php?module=report_purchases"
                    style="background:var(--primary-color); color:white; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;">Export
                    CSV</a>
            </div>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="metrics-grid" style="margin-top: 20px;">
        <div class="metric-card">
            <div class="metric-title">Total POs Issued</div>
            <div class="metric-value">
                <?= number_format($totals['total_orders']) ?>
            </div>
            <div class="metric-change">All Time Pipeline</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Received (Invested)</div>
            <div class="metric-value" style="color:var(--success-color);">$
                <?= number_format($totals['total_received'], 2) ?>
            </div>
            <div class="metric-change positive">Capital converted to stock</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Pending Arrivals</div>
            <div class="metric-value" style="color:var(--warning-color);">$
                <?= number_format($totals['total_pending'], 2) ?>
            </div>
            <div class="metric-change negative">Drafts, Issued, Pending</div>
        </div>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order ID</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th style="text-align: right;">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td style="color: var(--text-muted);">
                                    <?= date('M d, Y', strtotime($row['order_date'])) ?>
                                </td>
                                <td style="font-weight: 500;">
                                    <?= htmlspecialchars($row['purchase_id_str']) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['supplier_name']) ?>
                                </td>
                                <td>
                                    <?= statusBadge($row['status']) ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">$
                                    <?= number_format($row['total_amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 20px; color: var(--text-muted);">No Purchase
                                Orders found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>