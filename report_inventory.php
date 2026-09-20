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
    SELECT sku_code, product_name, stock_level, purchasing_price, selling_price, (stock_level * purchasing_price) as inventory_value
    FROM products
    WHERE stock_level > 0
    ORDER BY inventory_value DESC
";
$result = $conn->query($query);

// Calc Totals
$total_value_res = $conn->query("SELECT SUM(stock_level * purchasing_price) FROM products WHERE stock_level > 0");
$total_valuation = $total_value_res ? $total_value_res->fetch_row()[0] : 0;

$current_page = 'reports.php';
$page_title = 'Inventory Summary Report';
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
                <h1 style="margin:0;">Inventory Summary</h1>
                <a href="export.php?module=report_inventory"
                    style="background:var(--primary-color); color:white; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;">Export
                    CSV</a>
            </div>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="metrics-grid" style="margin-top: 20px;">
        <div class="metric-card" style="grid-column: span 4;">
            <div class="metric-title">Total Capital Tied in Inventory</div>
            <div class="metric-value" style="font-size: 32px; color: var(--primary-color);">$
                <?= number_format($total_valuation, 2) ?>
            </div>
        </div>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th style="text-align: right;">Stock Level</th>
                        <th style="text-align: right;">Unit Cost</th>
                        <th style="text-align: right;">Unit Retail</th>
                        <th style="text-align: right;">Total Asset Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($row['sku_code']) ?>
                                </td>
                                <td style="font-weight: 500; color: var(--text-dark);">
                                    <?= htmlspecialchars($row['product_name']) ?>
                                </td>
                                <td style="text-align: right;">
                                    <?= number_format($row['stock_level']) ?>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">$
                                    <?= number_format($row['purchasing_price'], 2) ?>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">$
                                    <?= number_format($row['selling_price'], 2) ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">$
                                    <?= number_format($row['inventory_value'], 2) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px; color: var(--text-muted);">No
                                inventory assets found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>