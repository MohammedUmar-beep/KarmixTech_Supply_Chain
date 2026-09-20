<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

if (!in_array(strtolower($user_role), ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

// 1. Accrual Basis (Sales vs Purchases)
$accrual_res = $conn->query("
    SELECT 
        (SELECT COALESCE(SUM(total_amount), 0) FROM sales_orders WHERE status = 'Completed') as total_revenue,
        (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE status = 'Received') as total_cogs
");
$accrual = $accrual_res ? $accrual_res->fetch_assoc() : ['total_revenue' => 0, 'total_cogs' => 0];
$gross_profit = $accrual['total_revenue'] - $accrual['total_cogs'];
$margin = $accrual['total_revenue'] > 0 ? ($gross_profit / $accrual['total_revenue']) * 100 : 0;

// 2. Cash Basis (Payments Received vs Payments Made)
$cash_res = $conn->query("
    SELECT 
        (SELECT COALESCE(SUM(amount), 0) FROM payments_received) as cash_in,
        (SELECT COALESCE(SUM(amount), 0) FROM payments_made) as cash_out
");
$cash = $cash_res ? $cash_res->fetch_assoc() : ['cash_in' => 0, 'cash_out' => 0];
$net_cash_flow = $cash['cash_in'] - $cash['cash_out'];

$current_page = 'reports.php';
$page_title = 'Financial Summary Report';
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
            <h1>Financial Summary</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <h2 style="margin-top:20px; font-size: 18px; color: var(--text-dark);">Accrual Basis (Completed Orders)</h2>
    <div class="metrics-grid" style="margin-top: 15px;">
        <div class="metric-card">
            <div class="metric-title">Gross Revenue</div>
            <div class="metric-value">$
                <?= number_format($accrual['total_revenue'], 2) ?>
            </div>
            <div class="metric-change positive">All Completed Sales</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Cost of Goods (Purchases)</div>
            <div class="metric-value" style="color:var(--danger-color);">$
                <?= number_format($accrual['total_cogs'], 2) ?>
            </div>
            <div class="metric-change negative">All Received POs</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Gross Profit</div>
            <div class="metric-value"
                style="color: <?= $gross_profit >= 0 ? 'var(--success-color)' : 'var(--danger-color)' ?>;">$
                <?= number_format($gross_profit, 2) ?>
            </div>
            <div class="metric-change">
                <?= number_format($margin, 1) ?>% Margin
            </div>
        </div>
    </div>

    <h2 style="margin-top:30px; font-size: 18px; color: var(--text-dark);">Cash Basis (Actual Transactions)</h2>
    <div class="metrics-grid" style="margin-top: 15px;">
        <div class="metric-card">
            <div class="metric-title">Cash Inflow</div>
            <div class="metric-value">$
                <?= number_format($cash['cash_in'], 2) ?>
            </div>
            <div class="metric-change positive">Successful Receipts</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Cash Outflow</div>
            <div class="metric-value" style="color:var(--danger-color);">$
                <?= number_format($cash['cash_out'], 2) ?>
            </div>
            <div class="metric-change negative">Payments to Suppliers</div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Net Cash Flow</div>
            <div class="metric-value"
                style="color: <?= $net_cash_flow >= 0 ? 'var(--success-color)' : 'var(--danger-color)' ?>;">$
                <?= number_format($net_cash_flow, 2) ?>
            </div>
            <div class="metric-change">Liquidity Health</div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>