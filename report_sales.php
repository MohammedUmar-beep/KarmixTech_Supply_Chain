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
    SELECT p.sku_code, p.product_name, SUM(soi.quantity) as total_sold, SUM(soi.subtotal) as total_revenue
    FROM products p
    JOIN sales_order_items soi ON p.id = soi.product_id
    JOIN sales_orders so ON soi.sales_order_id = so.id
    WHERE so.status = 'Completed'
    GROUP BY p.id
    ORDER BY total_revenue DESC
";
$result = $conn->query($query);

$current_page = 'reports.php';
$page_title = 'Sales by Item Report';
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
                <h1 style="margin:0;">Sales by Item</h1>
                <a href="export.php?module=report_sales"
                    style="background:var(--primary-color); color:white; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:500;">Export
                    CSV</a>
            </div>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th style="text-align: right;">Total Quantity Sold</th>
                        <th style="text-align: right;">Gross Revenue</th>
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
                                    <?= number_format($row['total_sold']) ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">$
                                    <?= number_format($row['total_revenue'], 2) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 20px; color: var(--text-muted);">No sales
                                data available.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>