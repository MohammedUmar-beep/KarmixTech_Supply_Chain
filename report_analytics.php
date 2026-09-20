<?php
require_once 'includes/auth_guard.php';

$user_role = strtolower($_SESSION['role'] ?? 'staff');
if ($user_role !== 'admin' && $user_role !== 'manager') {
    die("Unauthorized access to advanced analytics.");
}

// 1. Timeframe Filter
$days_back = isset($_GET['timeframe']) ? intval($_GET['timeframe']) : 30;
$date_limit = date('Y-m-d H:i:s', strtotime("-$days_back days"));

// --- QUERY 1: Movement Velocity (Top Moving Products) ---
// Formula: Total quantity sold in timeframe / Current Stock Level
// Identifies products moving too fast or too slow relative to what we hold
$velocity_query = "
    SELECT 
        p.id, 
        p.product_name, 
        p.sku_code, 
        p.stock_level, 
        SUM(soi.quantity) as total_sold,
        (SUM(soi.quantity) / NULLIF(p.stock_level, 0)) as velocity_ratio
    FROM products p
    JOIN sales_order_items soi ON p.id = soi.product_id
    JOIN sales_orders so ON soi.sales_order_id = so.id
    WHERE so.status != 'Cancelled' AND so.order_date >= '$date_limit'
    GROUP BY p.id
    ORDER BY total_sold DESC
    LIMIT 15
";
$velocity_result = $conn->query($velocity_query);

// --- QUERY 2: Dead Stock Identification ---
// Identifies products that have stock but haven't sold a single unit in the given timeframe
$dead_stock_query = "
    SELECT 
        p.id, 
        p.product_name, 
        p.sku_code, 
        p.stock_level, 
        p.selling_price,
        (p.stock_level * p.selling_price) as trapped_capital,
        p.warehouse_id
    FROM products p
    WHERE p.stock_level > 0 
    AND p.id NOT IN (
        SELECT DISTINCT soi.product_id 
        FROM sales_order_items soi 
        JOIN sales_orders so ON soi.sales_order_id = so.id 
        WHERE so.order_date >= '$date_limit' AND so.status != 'Cancelled'
    )
    ORDER BY trapped_capital DESC
    LIMIT 20
";
$dead_stock_result = $conn->query($dead_stock_query);

// --- QUERY 3: Summary Metrics ---
// Total Trapped Capital (All dead stock)
$capital_query = "
    SELECT SUM(p.stock_level * p.selling_price) as total_trapped
    FROM products p
    WHERE p.stock_level > 0 
    AND p.id NOT IN (
        SELECT DISTINCT soi.product_id 
        FROM sales_order_items soi 
        JOIN sales_orders so ON soi.sales_order_id = so.id 
        WHERE so.order_date >= '$date_limit' AND so.status != 'Cancelled'
    )
";
$trapped_capital = $conn->query($capital_query)->fetch_assoc()['total_trapped'] ?? 0;

$current_page = 'reports.php';
$page_title = 'Inventory Analytics';
$extra_head = '<style>
    .analytics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; align-items: start; }
    @media (max-width: 1100px) { .analytics-grid { grid-template-columns: 1fr; } }
    .card-title { font-size: 16px; font-weight: 600; margin-bottom:  १५px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table th { text-align: left; padding: 10px; border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-weight: 500; }
    .data-table td { padding: 12px 10px; border-bottom: 1px solid var(--border-color); color: var(--text-dark); }
    .data-table tr:hover td { background: var(--bg-hover); }
    .ratio-badge { padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; }
    .ratio-high { background: var(--success-bg, #dcfce7); color: #059669; }
    .ratio-med { background: var(--warning-bg, #fefce8); color: #b45309; }
</style>';

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="reports.php" class="back-link">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Reports
            </a>
            <h1>Advanced Analytics Engine</h1>
        </div>
        <div class="topbar-right">
            <form method="GET" style="display:flex; align-items:center; gap:10px; background:var(--card-bg); padding:5px; border-radius:8px; border:1px solid var(--border-color);">
                <span style="font-size:13px; color:var(--text-muted); padding-left:10px;">Timeframe:</span>
                <select name="timeframe" onchange="this.form.submit()" style="border:none; padding:5px 10px; outline:none; font-weight:500; color:var(--text-dark); cursor:pointer;">
                    <option value="7" <?= $days_back == 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $days_back == 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90" <?= $days_back == 90 ? 'selected' : '' ?>>Last 90 Days</option>
                    <option value="365" <?= $days_back == 365 ? 'selected' : '' ?>>Last Year</option>
                </select>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 10px;">
        <div class="settings-card" style="border-left: 4px solid var(--danger-color); padding: 20px;">
            <div style="font-size:13px; color:var(--text-muted); font-weight:500;">Trapped Capital (Dead Stock Value)</div>
            <div style="font-size:28px; font-weight:700; color:var(--danger-color); margin-top:5px;">$<?= number_format($trapped_capital, 2) ?></div>
            <div style="font-size:12px; color:var(--text-muted); margin-top:5px;">Funds tied up in products with zero sales over the last <?= $days_back ?> days.</div>
        </div>
    </div>

    <div class="analytics-grid">
        <!-- Movement Velocity Pane -->
        <div class="form-container" style="margin:0;">
            <div class="card-title">
                Movement Velocity (Top Performers)
                <span title="Ratio of Units Sold vs Units Currently In Stock. High numbers mean you are selling out faster than you stock them." style="cursor:help; color:var(--text-muted);"><svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg></span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Sold</th>
                        <th>In Stock</th>
                        <th>Velocity Ratio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($velocity_result && $velocity_result->num_rows > 0): ?>
                        <?php while($vRow = $velocity_result->fetch_assoc()): 
                            $ratio = floatval($vRow['velocity_ratio']);
                            if ($ratio === null || $vRow['stock_level'] == 0) {
                                $ratio_display = "Out of Stock";
                                $ratio_class = "ratio-high";
                            } else {
                                $ratio_display = number_format($ratio, 2) . 'x';
                                $ratio_class = $ratio > 1 ? 'ratio-high' : 'ratio-med';
                            }
                        ?>
                        <tr>
                            <td style="font-weight:500;"><?= htmlspecialchars($vRow['product_name']) ?><br><span style="font-size:11px; color:var(--text-muted); font-weight:normal;"><?= $vRow['sku_code'] ?></span></td>
                            <td><?= number_format($vRow['total_sold']) ?></td>
                            <td><?= number_format($vRow['stock_level']) ?></td>
                            <td><span class="ratio-badge <?= $ratio_class ?>"><?= $ratio_display ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; padding:30px; color:var(--text-muted);">Not enough sales data for this timeframe.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Dead Stock Pane -->
        <div class="form-container" style="margin:0;">
            <div class="card-title">
                Dead Stock Identifications
                <a href="export.php?module=analytics&type=deadstock&days=<?= $days_back ?>" class="btn-outline" style="padding:4px 10px; font-size:12px; border-color:var(--border-color); color:var(--text-dark); text-decoration:none;">Export CSV</a>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Stagnant Product</th>
                        <th>Stock Qty</th>
                        <th>Capital Trapped</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($dead_stock_result && $dead_stock_result->num_rows > 0): ?>
                        <?php while($dRow = $dead_stock_result->fetch_assoc()): ?>
                        <tr>
                            <td style="font-weight:500;"><?= htmlspecialchars($dRow['product_name']) ?><br><span style="font-size:11px; color:var(--text-muted); font-weight:normal;"><?= $dRow['sku_code'] ?> • Unit Price: $<?= number_format($dRow['price'],2) ?></span></td>
                            <td><?= number_format($dRow['stock_level']) ?></td>
                            <td style="font-weight:600; color:var(--danger-color);">$<?= number_format($dRow['trapped_capital'], 2) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align:center; padding:30px; color:var(--text-muted);">Congratulations! No dead stock found for this timeframe.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if($trapped_capital > 0): ?>
                <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 15px; border-radius: 8px; margin-top: 20px; font-size: 13px; color: #991b1b; display:flex; align-items:flex-start; gap:10px;">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2" style="flex-shrink:0; margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    <div>
                        <strong>Action Recommended:</strong> You have $<?= number_format($trapped_capital, 2) ?> sitting in inventory that isn't moving. Consider creating a <a href="settings.php#finance" style="color:var(--primary-color);">Discount Rule</a> for these items directly, or use the Product Bundling module to attach them to high-velocity products (on the left) to force them out of your warehouse.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
