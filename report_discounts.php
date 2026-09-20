<?php
require_once 'includes/auth_guard.php';

$user_role = strtolower($_SESSION['role'] ?? 'staff');
if (!in_array($user_role, ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

$range = $_GET['range'] ?? '1m';
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

if (!$start_date || !$end_date) {
    $end_date = date('Y-m-d');
    switch ($range) {
        case '1d': $start_date = date('Y-m-d', strtotime('-1 days')); break;
        case '7d': $start_date = date('Y-m-d', strtotime('-7 days')); break;
        case '1m': $start_date = date('Y-m-d', strtotime('-1 months')); break;
        default:   $start_date = date('Y-m-d', strtotime('-1 months')); $range = '1m';
    }
} else {
    $range = 'custom';
}

$date_cond = " AND so.order_date BETWEEN '$start_date' AND '$end_date'";

// Currently Discounted Products
$q_active = "SELECT COUNT(*) as c FROM products WHERE discount_type IS NOT NULL";
$active_discounts = $conn->query($q_active)->fetch_assoc()['c'] ?? 0;

// Sales of Discounted Items vs Regular Items (within date range)
// We define "Discounted Sales" as sales of items that currently have a discount, as historical discount capture isn't present in the basic schema.
$q_sales = "
    SELECT 
        SUM(CASE WHEN p.discount_type IS NOT NULL THEN soi.quantity ELSE 0 END) as discounted_qty,
        SUM(CASE WHEN p.discount_type IS NULL THEN soi.quantity ELSE 0 END) as regular_qty,
        SUM(CASE WHEN p.discount_type IS NOT NULL THEN soi.subtotal ELSE 0 END) as discounted_revenue,
        SUM(CASE WHEN p.discount_type IS NULL THEN soi.subtotal ELSE 0 END) as regular_revenue
    FROM sales_order_items soi
    JOIN products p ON soi.product_id = p.id
    JOIN sales_orders so ON soi.sales_order_id = so.id
    WHERE so.status = 'Completed' $date_cond
";
$sales_res = $conn->query($q_sales)->fetch_assoc();

$discounted_qty = $sales_res['discounted_qty'] ?? 0;
$regular_qty = $sales_res['regular_qty'] ?? 0;
$discounted_rev = $sales_res['discounted_revenue'] ?? 0;
$regular_rev = $sales_res['regular_revenue'] ?? 0;

// Top Discounted Products
$q_top = "
    SELECT p.product_name, p.discount_type, p.discount_value, SUM(soi.quantity) as total_sold
    FROM products p
    JOIN sales_order_items soi ON p.id = soi.product_id
    JOIN sales_orders so ON soi.sales_order_id = so.id
    WHERE p.discount_type IS NOT NULL AND so.status = 'Completed' $date_cond
    GROUP BY p.id
    ORDER BY total_sold DESC
    LIMIT 10
";
$top_discounted = $conn->query($q_top);

$page_title = 'Discount Analytics';
$current_page = 'reports.php';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
include 'includes/header.php';
?>
<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="reports.php" style="color:var(--text-dark); font-size:14px; font-weight:600; margin-bottom:10px; display:inline-block;">&larr; Back to Reports</a>
            <h1>Discount Analytics</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div style="display:flex; gap: 12px; margin-bottom: 20px;">
        <?php
        $ranges = ['1d', '7d', '1m'];
        foreach ($ranges as $r) {
            $active = ($range === $r) ? 'background: var(--primary-color); color: white; border-color: var(--primary-color);' : 'background: white; border: 1px solid var(--border-color);';
            echo "<a href='report_discounts.php?range=$r' style='padding:8px 16px; border-radius:8px; text-decoration:none; color:inherit; $active'>$r</a>";
        }
        ?>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom: 30px;">
        <div style="background:white; padding:20px; border-radius:12px; border:1px solid var(--border-color);">
            <div style="font-size:14px; color:var(--text-muted); margin-bottom:10px;">Currently Active Discounts</div>
            <div style="font-size:28px; font-weight:700; color:var(--text-dark);"><?= number_format($active_discounts) ?></div>
        </div>
        <div style="background:white; padding:20px; border-radius:12px; border:1px solid var(--border-color);">
            <div style="font-size:14px; color:var(--text-muted); margin-bottom:10px;">Units Sold (Discounted)</div>
            <div style="font-size:28px; font-weight:700; color:#d97706;"><?= number_format($discounted_qty) ?></div>
        </div>
        <div style="background:white; padding:20px; border-radius:12px; border:1px solid var(--border-color);">
            <div style="font-size:14px; color:var(--text-muted); margin-bottom:10px;">Revenue from Discounted Items</div>
            <div style="font-size:28px; font-weight:700; color:#10b981;">$<?= number_format($discounted_rev, 2) ?></div>
        </div>
        <div style="background:white; padding:20px; border-radius:12px; border:1px solid var(--border-color);">
            <div style="font-size:14px; color:var(--text-muted); margin-bottom:10px;">Total Regular Revenue</div>
            <div style="font-size:28px; font-weight:700; color:var(--text-dark);">$<?= number_format($regular_rev, 2) ?></div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 30px;">
        <div style="background:white; padding:20px; border-radius:12px; border:1px solid var(--border-color);">
            <h3 style="margin-top:0; margin-bottom:20px;">Sales Volume: Regular vs Discounted</h3>
            <div style="height:300px;">
                <canvas id="volumeChart"></canvas>
            </div>
        </div>
        
        <div style="background:white; padding:20px; border-radius:12px; border:1px solid var(--border-color);">
            <h3 style="margin-top:0; margin-bottom:20px;">Top Selling Discounted Products</h3>
            <div class="table-responsive">
                <table class="data-table" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-color); text-align:left;">
                            <th style="padding:10px;">Product</th>
                            <th style="padding:10px;">Discount</th>
                            <th style="padding:10px; text-align:right;">Units Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($top_discounted && $top_discounted->num_rows > 0): ?>
                            <?php while($row = $top_discounted->fetch_assoc()): ?>
                                <tr style="border-bottom:1px solid var(--border-color);">
                                    <td style="padding:10px; font-weight:500;"><?= htmlspecialchars($row['product_name']) ?></td>
                                    <td style="padding:10px;">
                                        <?php if($row['discount_type'] == 'Percentage') echo $row['discount_value'].'% OFF'; else echo '$'.$row['discount_value'].' OFF'; ?>
                                    </td>
                                    <td style="padding:10px; text-align:right; font-weight:600;"><?= $row['total_sold'] ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="padding:20px; text-align:center; color:var(--text-muted);">No data available for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const ctxVolume = document.getElementById('volumeChart').getContext('2d');
        new Chart(ctxVolume, {
            type: 'doughnut',
            data: {
                labels: ['Discounted Sales', 'Regular Sales'],
                datasets: [{
                    data: [<?= $discounted_qty ?>, <?= $regular_qty ?>],
                    backgroundColor: ['#f59e0b', '#3b82f6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</main>
<?php include 'includes/footer.php'; ?>
