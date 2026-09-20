<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = strtolower($_SESSION['role'] ?? 'staff');

// ── VIEW ──────────────────────────────────────────────────────────────
$allowed_views = ['products', 'customers', 'sales', 'purchase', 'shipment'];
$view = $_GET['view'] ?? 'products';
if (!in_array($view, $allowed_views)) $view = 'products';

// ── DATE RANGE ────────────────────────────────────────────────────────
$range     = $_GET['range'] ?? 'month';
$raw_start = $_GET['start_date'] ?? null;
$raw_end   = $_GET['end_date']   ?? null;

function sanitize_date($val) {
    if (!$val) return null;
    $d = DateTime::createFromFormat('Y-m-d', $val);
    return ($d && $d->format('Y-m-d') === $val) ? $val : null;
}
$start_date = sanitize_date($raw_start);
$end_date   = sanitize_date($raw_end);

if (!$start_date || !$end_date) {
    $end_date = date('Y-m-d');
    switch ($range) {
        case 'today': $start_date = date('Y-m-d'); break;
        case 'week':  $start_date = date('Y-m-d', strtotime('monday this week')); break;
        case 'year':  $start_date = date('Y-01-01'); break;
        default: $range = 'month'; $start_date = date('Y-m-01');
    }
} else {
    $range = 'custom';
}

// ── PRIOR PERIOD (for % change badges) ───────────────────────────────
$days_diff   = max(1, (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
$prior_end   = date('Y-m-d', strtotime($start_date . ' -1 day'));
$prior_start = date('Y-m-d', strtotime($prior_end   . ' -' . ($days_diff - 1) . ' days'));

// ── DATE CONDITIONS ───────────────────────────────────────────────────
$dc   = " AND created_at  BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
$odc  = " AND order_date  BETWEEN '$start_date' AND '$end_date'";
$pdc  = " AND created_at  BETWEEN '$prior_start 00:00:00' AND '$prior_end 23:59:59'";
$podc = " AND order_date  BETWEEN '$prior_start' AND '$prior_end'";

// ── HELPERS ───────────────────────────────────────────────────────────
function qv($conn, $sql) {
    $r = $conn->query($sql);
    return $r ? floatval($r->fetch_row()[0] ?? 0) : 0;
}
function badge($curr, $prev, $higher_good = true) {
    if ($prev == 0) return '<span style="font-size:11px;color:var(--text-muted);">–</span>';
    $pct = round(($curr - $prev) / abs($prev) * 100, 1);
    if ($pct == 0) return '<span style="font-size:11px;color:var(--text-muted);">0%</span>';
    $up   = $pct > 0;
    $good = $higher_good ? $up : !$up;
    $col  = $good ? '#10b981' : '#ef4444';
    $bg   = $good ? 'rgba(16,185,129,.1)' : 'rgba(239,68,68,.1)';
    return "<span style='font-size:11px;font-weight:700;color:{$col};background:{$bg};padding:2px 8px;border-radius:20px;'>"
         . ($up ? '↑' : '↓') . ' ' . abs($pct) . "%</span>";
}

// ── TODAY AT A GLANCE (always runs) ──────────────────────────────────
$gl_rev      = qv($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date=CURDATE()");
$gl_orders   = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE DATE(created_at)=CURDATE()");
$gl_cust     = qv($conn, "SELECT COUNT(*) FROM customers WHERE DATE(created_at)=CURDATE()");
$gl_drafts   = qv($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status='Draft'");
$gl_lowstock = qv($conn, "SELECT COUNT(*) FROM products WHERE stock_level <= warning_threshold AND stock_level > 0");
$gl_pending_ret = qv($conn, "SELECT COUNT(*) FROM sales_returns WHERE status='Pending'");

// Recent activity removed

$hide_topbar = true;
$page_title  = 'Dashboard';
$extra_head  = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── DASHBOARD STYLES ─────────────────────────────── */
.dash-range-bar {
    background: var(--white); border: 1px solid var(--border-color);
    border-radius: 12px; padding: 10px 16px;
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap; margin-bottom: 0;
    border-bottom-left-radius: 0; border-bottom-right-radius: 0;
    border-bottom: none;
}
.dash-view-tabs {
    background: var(--white);
    border: 1px solid var(--border-color);
    border-top: 1px solid var(--border-color);
    border-radius: 0 0 12px 12px;
    display: flex; overflow-x: auto;
    scrollbar-width: none; margin-bottom: 24px;
}
.dash-view-tabs::-webkit-scrollbar { display: none; }
.dash-tab {
    padding: 11px 20px; font-size: 13px; font-weight: 600;
    text-decoration: none; white-space: nowrap; color: var(--text-muted);
    border-bottom: 2px solid transparent;
    transition: all .15s; display: block;
}
.dash-tab:hover { color: var(--primary-color); }
.dash-tab.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

.view-select {
    padding: 6px 36px 6px 12px;
    border: 1.5px solid var(--border-color);
    background: var(--white); border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    outline: none; color: var(--text-dark);
    appearance: none; -webkit-appearance: none;
    background-image: url(\'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" stroke="%2364748b" fill="none" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>\');
    background-repeat: no-repeat; background-position: right 10px center;
}
.view-select:focus { border-color: var(--primary-color); }

.metric-link { text-decoration: none; display: block; }
.metric-link .page-metric-card { transition: transform .18s, box-shadow .18s, border-color .18s; }
.metric-link:hover .page-metric-card {
    transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.07);
    border-left-width: 4px;
}

.chart-container {
    background: var(--white); border-radius: 12px; padding: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,.02);
    border: 1px solid var(--border-color); position: relative; height: 350px;
}
.chart-canvas-wrapper { position: relative; height: calc(100% - 40px); width: 100%; }
.charts-wrapper { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
.chart-header { margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
.chart-header h3 { font-size: 15px; color: var(--text-dark); margin: 0; font-weight: 600; }

.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border-color); }
.data-table th { font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 11px; background: var(--bg-light); }
.data-table tbody tr:hover { background: var(--bg-light); }
.data-table tbody tr { transition: background .12s; }

.activity-feed { display: flex; flex-direction: column; gap: 0; }
.activity-item {
    display: flex; gap: 12px; padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
    align-items: flex-start;
}
.activity-item:last-child { border-bottom: none; }
.activity-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--primary-color); flex-shrink: 0; margin-top: 5px;
}
.activity-text { font-size: 13px; color: var(--text-dark); line-height: 1.4; }
.activity-time { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.section-card {
    background: var(--white); border: 1px solid var(--border-color);
    border-radius: 12px; padding: 20px; margin-top: 20px;
}
.section-card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px;
}
.section-card-header h3 { font-size: 15px; font-weight: 600; color: var(--text-dark); margin: 0; }

@media (max-width: 900px) {
    .dash-glance { grid-template-columns: repeat(3, 1fr); }
    .charts-wrapper { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .dash-glance { grid-template-columns: repeat(2, 1fr); }
}
</style>';
include 'includes/header.php';
?>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left">
        <h1>Welcome back, <?= htmlspecialchars($user_name) ?> 👋</h1>
    </div>
    <?php include 'includes/topbar_right.php'; ?>
</div>


<?php
$query_string = "view=" . urlencode($view);
$maintain_date = $range !== 'custom' ? "&range=$range" : "&start_date=$start_date&end_date=$end_date";
?>

<!-- ══ DATE RANGE BAR ════════════════════════════════════════════════ -->
<div class="dash-range-bar">
    <?php
    $presets = [
        'today' => ['Today',      date('Y-m-d'),                               date('Y-m-d')],
        'week'  => ['This Week',  date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
        'month' => ['This Month', date('Y-m-01'),                               date('Y-m-d')],
        'year'  => ['This Year',  date('Y-01-01'),                              date('Y-m-d')],
    ];
    foreach ($presets as $key => [$label, $pf, $pt]):
        $active = ($range === $key);
    ?>
        <a href="dashboard.php?<?= $query_string ?>&range=<?= $key ?>"
           style="padding:6px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid <?= $active ? 'var(--primary-color)' : 'var(--border-color)' ?>;background:<?= $active ? 'var(--primary-color)' : 'transparent' ?>;color:<?= $active ? '#fff' : 'var(--text-muted)' ?>;transition:all .15s;white-space:nowrap;"><?= $label ?></a>
    <?php endforeach; ?>

    <span style="color:var(--border-color);font-size:18px;margin:0 4px;">|</span>

    <form method="GET" action="dashboard.php" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"
            style="padding:6px 10px;border:1.5px solid var(--border-color);border-radius:8px;font-size:13px;background:var(--bg-light);color:var(--text-dark);outline:none;" required>
        <span style="font-size:13px;color:var(--text-muted);font-weight:500;">to</span>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"
            style="padding:6px 10px;border:1.5px solid var(--border-color);border-radius:8px;font-size:13px;background:var(--bg-light);color:var(--text-dark);outline:none;" required>
        <button type="submit"
            style="padding:6px 16px;background:var(--primary-color);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Apply</button>
    </form>

    <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">
        <?= date('d M Y', strtotime($start_date)) ?> – <?= date('d M Y', strtotime($end_date)) ?>
    </span>
</div>

<!-- ══ VIEW TABS ══════════════════════════════════════════════════════ -->
<div class="dash-view-tabs">
    <?php
    $tabs = ['products' => '📦 Products', 'customers' => '👥 Customers', 'sales' => '🛍️ Sales', 'purchase' => '🛒 Purchase', 'shipment' => '🚚 Shipment'];
    foreach ($tabs as $key => $label):
    ?>
        <a href="dashboard.php?view=<?= $key ?><?= $maintain_date ?>" class="dash-tab <?= $view === $key ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php // ══════════════════════════════════════════════════════════════
// VIEW: PRODUCTS
// ══════════════════════════════════════════════════════════════════
if ($view === 'products'):

    // Metrics (some are all-time, some date-filtered)
    $total_prod  = qv($conn, "SELECT COUNT(*) FROM products WHERE status != 'Deleted'");
    $low_stock   = qv($conn, "SELECT COUNT(*) FROM products WHERE stock_level <= warning_threshold AND stock_level > 0");
    $oos         = qv($conn, "SELECT COUNT(*) FROM products WHERE stock_level <= 0");
    $new_period  = qv($conn, "SELECT COUNT(*) FROM products WHERE created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $new_prev    = qv($conn, "SELECT COUNT(*) FROM products WHERE created_at BETWEEN '$prior_start 00:00:00' AND '$prior_end 23:59:59'");
    $stock_val   = qv($conn, "SELECT SUM(stock_level * purchasing_price) FROM products WHERE stock_level > 0");
    $dead_stock  = qv($conn, "SELECT COUNT(*) FROM products p WHERE p.stock_level > 0 AND p.id NOT IN (SELECT DISTINCT soi.product_id FROM sales_order_items soi JOIN sales_orders so ON soi.sales_order_id=so.id WHERE so.order_date BETWEEN '$start_date' AND '$end_date')");
?>

<div class="page-metrics-grid">
    <a href="products.php" class="metric-link">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Products</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= (int)$total_prod ?></div>
            <div class="page-metric-trend">Active catalog</div>
        </div>
    </a>
    <a href="stock.php?status=Low+Stock" class="metric-link">
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Low Stock</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= (int)$low_stock ?></div>
            <div class="page-metric-trend">Click to view →</div>
        </div>
    </a>
    <a href="stock.php?status=Out+of+Stock" class="metric-link">
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Out of Stock</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= (int)$oos ?></div>
            <div class="page-metric-trend">Zero inventory</div>
        </div>
    </a>
    <div class="page-metric-card border-green">
        <div class="page-metric-title">New in Period</div>
        <div class="page-metric-value" style="color:#10b981;"><?= (int)$new_period ?></div>
        <div class="page-metric-trend"><?= badge($new_period, $new_prev) ?> vs prior period</div>
    </div>
    <div class="page-metric-card border-blue">
        <div class="page-metric-title">Total Stock Value</div>
        <div class="page-metric-value" style="color:#3b82f6;font-size:22px;"><?= $currency['symbol'] . number_format($stock_val, 0) ?></div>
        <div class="page-metric-trend">Cost × units</div>
    </div>
    <a href="reports.php?tab=inventory" class="metric-link">
        <div class="page-metric-card border-purple">
            <div class="page-metric-title">Dead Stock</div>
            <div class="page-metric-value" style="color:#8b5cf6;"><?= (int)$dead_stock ?></div>
            <div class="page-metric-trend">No sales this period</div>
        </div>
    </a>
</div>

<?php
    // Charts
    $cat_res = $conn->query("SELECT category, COUNT(id) as count FROM products GROUP BY category ORDER BY count DESC LIMIT 6");
    $cat_labels = []; $cat_data = [];
    while ($r = $cat_res->fetch_assoc()) { $cat_labels[] = $r['category'] ?: 'Uncategorized'; $cat_data[] = $r['count']; }

    $ts_res = $conn->query("SELECT product_name, stock_level FROM products ORDER BY stock_level DESC LIMIT 5");
    $ts_labels = []; $ts_data = [];
    while ($r = $ts_res->fetch_assoc()) { $ts_labels[] = $r['product_name']; $ts_data[] = (int)$r['stock_level']; }

    // Stock health breakdown (for 3rd chart)
    $healthy_stock = qv($conn, "SELECT COUNT(*) FROM products WHERE stock_level > warning_threshold AND stock_level > 0 AND status != 'Deleted'");

    // Low stock table
    $ls_res = $conn->query("SELECT product_name, sku_code, stock_level, warning_threshold, id FROM products WHERE stock_level <= warning_threshold AND stock_level > 0 ORDER BY stock_level ASC LIMIT 8");
    $ls_rows = [];
    while ($r = $ls_res->fetch_assoc()) $ls_rows[] = $r;
?>

<div class="charts-wrapper" style="margin-bottom:20px;">
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Category Distribution</h3></div>
        <div class="chart-canvas-wrapper" style="display:flex;justify-content:center;align-items:center;height:290px;">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Top 5 Products by Stock Level</h3></div>
        <div class="chart-canvas-wrapper" style="height:290px;">
            <canvas id="topStockChart"></canvas>
        </div>
    </div>
</div>

<?php if (!empty($ls_rows)): ?>
<div class="section-card" style="margin-top:0;">
    <div class="section-card-header">
        <h3>⚠️ Low Stock Alert — Action Required</h3>
        <a href="stock.php?status=Low+Stock" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">View All →</a>
    </div>
    <table class="data-table">
        <thead><tr>
            <th>Product</th><th>SKU</th><th>Current Stock</th><th>Min Threshold</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($ls_rows as $p):
            $pct = $p['warning_threshold'] > 0 ? round($p['stock_level'] / $p['warning_threshold'] * 100) : 0;
        ?>
        <tr>
            <td style="font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($p['product_name']) ?></td>
            <td style="font-family:monospace;font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($p['sku_code']) ?></td>
            <td><span style="font-weight:700;color:#ef4444;"><?= $p['stock_level'] ?></span></td>
            <td style="color:var(--text-muted);"><?= $p['warning_threshold'] ?></td>
            <td>
                <div style="background:var(--bg-light);border-radius:20px;height:6px;width:80px;overflow:hidden;">
                    <div style="height:100%;background:<?= $pct < 50 ? '#ef4444' : '#f59e0b' ?>;width:<?= min(100,$pct) ?>%;"></div>
                </div>
            </td>
            <td><a href="edit_product.php?id=<?= $p['id'] ?>" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">Restock →</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="charts-wrapper" style="grid-template-columns:1fr 1fr;margin-top:20px;">
    <div class="chart-container" style="height:320px;">
        <div class="chart-header">
            <h3>Stock Health Breakdown</h3>
            <a href="stock.php" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">View Stock →</a>
        </div>
        <div class="chart-canvas-wrapper" style="display:flex;justify-content:center;align-items:center;height:250px;">
            <canvas id="stockHealthChart" style="max-height:100%;max-width:380px;"></canvas>
        </div>
    </div>
    <div class="chart-container" style="height:320px;">
        <div class="chart-header"><h3>Stock Health at a Glance</h3></div>
        <div style="padding-top:16px;">
            <?php
            $health_items = [
                ['Healthy',      $healthy_stock, '#10b981', 'stock.php?status=Healthy'],
                ['Low Stock',    $low_stock,     '#f59e0b', 'stock.php?status=Low+Stock'],
                ['Out of Stock', $oos,           '#ef4444', 'stock.php?status=Out+of+Stock'],
            ];
            $total_health = max(1, $healthy_stock + $low_stock + $oos);
            foreach ($health_items as [$label, $count, $color, $link]):
                $pct = round($count / $total_health * 100);
            ?>
            <a href="<?= $link ?>" style="text-decoration:none;display:block;margin-bottom:18px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:13px;color:var(--text-dark);font-weight:500;"><?= $label ?></span>
                    <span style="font-size:13px;font-weight:700;color:<?= $color ?>;"><?= (int)$count ?> <span style="color:var(--text-muted);font-weight:400;">(<?= $pct ?>%)</span></span>
                </div>
                <div style="background:var(--bg-light);border-radius:20px;height:8px;overflow:hidden;">
                    <div style="height:100%;background:<?= $color ?>;width:<?= $pct ?>%;border-radius:20px;transition:width .5s;"></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#6d4aff';
new Chart(document.getElementById('categoryChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($cat_labels) ?>.length ? <?= json_encode($cat_labels) ?> : ['No Data'],
        datasets: [{ data: <?= json_encode($cat_data) ?>.length ? <?= json_encode($cat_data) ?> : [1],
            backgroundColor: ['#6D4AFF','#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6'], borderWidth: 2, hoverOffset: 4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins: { legend: { position:'right' } } }
});
new Chart(document.getElementById('topStockChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($ts_labels) ?>,
        datasets: [{ label:'Stock Level', data: <?= json_encode($ts_data) ?>,
            backgroundColor: ['#6D4AFF','#3b82f6','#10b981','#f59e0b','#ef4444'], borderRadius: 6 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
        scales: { y: { beginAtZero:true }, x: { grid: { display:false } } },
        plugins: { legend: { display:false } }
    }
});
new Chart(document.getElementById('stockHealthChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Healthy','Low Stock','Out of Stock'],
        datasets: [{ data: [<?= $healthy_stock ?>,<?= $low_stock ?>,<?= $oos ?>],
            backgroundColor: ['rgba(16,185,129,.85)','rgba(245,158,11,.85)','rgba(239,68,68,.85)'],
            borderWidth: 2, hoverOffset: 6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins: { legend: { position:'bottom', labels: { boxWidth:12 } } } }
});
</script>

<?php // ══════════════════════════════════════════════════════════════
// VIEW: CUSTOMERS
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'customers'):
    $total_cust    = qv($conn, "SELECT COUNT(*) FROM customers");
    $active_cust   = qv($conn, "SELECT COUNT(*) FROM customers WHERE status='Active'");
    $new_cust      = qv($conn, "SELECT COUNT(*) FROM customers WHERE created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $new_cust_prev = qv($conn, "SELECT COUNT(*) FROM customers WHERE created_at BETWEEN '$prior_start 00:00:00' AND '$prior_end 23:59:59'");
    $rev_period    = qv($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $rev_prev      = qv($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$prior_start' AND '$prior_end'");
    $orders_period = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $outstanding   = qv($conn, "SELECT SUM(amount) FROM invoices WHERE status NOT IN ('Paid')");
?>

<div class="page-metrics-grid">
    <a href="customers.php" class="metric-link">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Customers</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= (int)$total_cust ?></div>
            <div class="page-metric-trend">All registered</div>
        </div>
    </a>
    <a href="customers.php?status=Active" class="metric-link">
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Active Customers</div>
            <div class="page-metric-value" style="color:#10b981;"><?= (int)$active_cust ?></div>
            <div class="page-metric-trend">Currently enabled</div>
        </div>
    </a>
    <div class="page-metric-card border-blue">
        <div class="page-metric-title">New in Period</div>
        <div class="page-metric-value" style="color:#3b82f6;"><?= (int)$new_cust ?></div>
        <div class="page-metric-trend"><?= badge($new_cust, $new_cust_prev) ?> vs prior period</div>
    </div>
    <a href="reports.php?tab=sales" class="metric-link">
        <div class="page-metric-card border-purple">
            <div class="page-metric-title">Revenue (Period)</div>
            <div class="page-metric-value" style="color:#8b5cf6;font-size:22px;"><?= $currency['symbol'] . number_format($rev_period, 0) ?></div>
            <div class="page-metric-trend"><?= badge($rev_period, $rev_prev) ?> vs prior period</div>
        </div>
    </a>
    <a href="sales_orders.php?status=Completed" class="metric-link">
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Completed Orders</div>
            <div class="page-metric-value" style="color:#10b981;"><?= (int)$orders_period ?></div>
            <div class="page-metric-trend">In selected period</div>
        </div>
    </a>
    <a href="invoices.php" class="metric-link">
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Outstanding Balance</div>
            <div class="page-metric-value" style="color:#ef4444;font-size:22px;"><?= $currency['symbol'] . number_format($outstanding, 0) ?></div>
            <div class="page-metric-trend">Unpaid invoices</div>
        </div>
    </a>
</div>

<?php
    // Monthly growth chart (last 6 months fixed range)
    $mo_res = $conn->query("SELECT DATE_FORMAT(created_at,'%b %Y') as mo, COUNT(*) as cnt FROM customers WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY YEAR(created_at), MONTH(created_at)");
    $mo_labels = []; $mo_data = [];
    while ($r = $mo_res->fetch_assoc()) { $mo_labels[] = $r['mo']; $mo_data[] = (int)$r['cnt']; }

    // Monthly revenue trend (last 6 months fixed range)
    $rev_mo_res = $conn->query("SELECT DATE_FORMAT(order_date,'%b %Y') as mo, SUM(total_amount) as rev FROM sales_orders WHERE status='Completed' AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY YEAR(order_date), MONTH(order_date) ORDER BY YEAR(order_date), MONTH(order_date)");
    $rev_mo_labels = []; $rev_mo_data = [];
    while ($r = $rev_mo_res->fetch_assoc()) { $rev_mo_labels[] = $r['mo']; $rev_mo_data[] = round(floatval($r['rev']), 2); }

    // Top customers by revenue in period
    $tc_res = $conn->query("SELECT c.customer_name, COUNT(so.id) as orders, SUM(so.total_amount) as revenue FROM customers c JOIN sales_orders so ON so.customer_id=c.id WHERE so.status='Completed' AND so.order_date BETWEEN '$start_date' AND '$end_date' GROUP BY c.id ORDER BY revenue DESC LIMIT 5");
    $tc_labels = []; $tc_data = []; $tc_orders = [];
    if ($tc_res) while ($r = $tc_res->fetch_assoc()) { $tc_labels[] = $r['customer_name']; $tc_data[] = round(floatval($r['revenue']), 2); $tc_orders[] = $r['orders']; }
    if (empty($tc_labels)) { $tc_labels = ['No data']; $tc_data = [0]; }
?>

<div class="charts-wrapper" style="margin-bottom:20px;">
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>New Customers — Last 6 Months</h3></div>
        <div class="chart-canvas-wrapper" style="height:290px;"><canvas id="custGrowthChart"></canvas></div>
    </div>
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Top 5 Customers by Revenue (Period)</h3></div>
        <div class="chart-canvas-wrapper" style="height:290px;"><canvas id="topCustChart"></canvas></div>
    </div>
</div>

<div class="charts-wrapper" style="grid-template-columns:1fr;margin-bottom:20px;">
    <div class="chart-container" style="height:320px;">
        <div class="chart-header">
            <h3>Monthly Revenue Trend — Last 6 Months</h3>
            <a href="reports.php?tab=sales" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">Full Report →</a>
        </div>
        <div class="chart-canvas-wrapper" style="height:250px;"><canvas id="custRevTrendChart"></canvas></div>
    </div>
</div>

<script>
new Chart(document.getElementById('custGrowthChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($mo_labels) ?>.length ? <?= json_encode($mo_labels) ?> : ['No Data'],
        datasets: [{ label:'New Customers', data: <?= json_encode($mo_data) ?>,
            borderColor: '#6D4AFF', backgroundColor: 'rgba(109,74,255,0.1)',
            borderWidth: 2, fill: true, tension: 0.4, pointRadius: 4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, scales: { y: { beginAtZero:true }, x: { grid: { display:false } } } }
});
new Chart(document.getElementById('topCustChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($tc_labels) ?>,
        datasets: [{ label:'Revenue', data: <?= json_encode($tc_data) ?>,
            backgroundColor: ['#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444'], borderRadius: 6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, indexAxis:'y',
        scales: { x: { beginAtZero:true, ticks: { callback: v => window.CURRENCY.symbol+v.toLocaleString() } }, y: { grid: { display:false } } },
        plugins: { legend: { display:false } }
    }
});
new Chart(document.getElementById('custRevTrendChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($rev_mo_labels) ?>.length ? <?= json_encode($rev_mo_labels) ?> : ['No Data'],
        datasets: [{ label:'Revenue', data: <?= json_encode($rev_mo_data) ?>.length ? <?= json_encode($rev_mo_data) ?> : [0],
            backgroundColor: 'rgba(109,74,255,0.75)', borderRadius: 6,
            borderColor: '#6D4AFF', borderWidth: 1 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
        scales: { y: { beginAtZero:true, ticks: { callback: v => window.CURRENCY.symbol+v.toLocaleString() } }, x: { grid: { display:false } } },
        plugins: { legend: { display:false } }
    }
});
</script>

<?php // ══════════════════════════════════════════════════════════════
// VIEW: SALES
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'sales'):
    $rev        = qv($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $rev_prev   = qv($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$prior_start' AND '$prior_end'");
    $ord_total  = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE status != 'Draft' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $ord_prev   = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE status != 'Draft' AND order_date BETWEEN '$prior_start' AND '$prior_end'");
    $comp       = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $pend       = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE status='Pending' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $canc       = qv($conn, "SELECT COUNT(*) FROM sales_orders WHERE status='Cancelled' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $avg_order  = $ord_total > 0 ? $rev / max(1, $comp) : 0;
    $returns    = qv($conn, "SELECT COUNT(*) FROM sales_returns WHERE status='Pending' AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $refunds    = qv($conn, "SELECT SUM(total_refund_amount) FROM sales_returns WHERE return_date BETWEEN '$start_date' AND '$end_date'");
    $refunds_prev = qv($conn, "SELECT SUM(total_refund_amount) FROM sales_returns WHERE return_date BETWEEN '$prior_start' AND '$prior_end'");
?>

<div class="page-metrics-grid">
    <a href="reports.php?tab=sales" class="metric-link">
        <div class="page-metric-card">
            <div class="page-metric-title">Revenue</div>
            <div class="page-metric-value" style="color:#10b981;font-size:24px;"><?= $currency['symbol'] . number_format($rev, 0) ?></div>
            <div class="page-metric-trend"><?= badge($rev, $rev_prev) ?> vs prior period</div>
        </div>
    </a>
    <a href="sales_orders.php" class="metric-link">
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Total Orders</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= (int)$ord_total ?></div>
            <div class="page-metric-trend"><?= badge($ord_total, $ord_prev) ?> vs prior period</div>
        </div>
    </a>
    <a href="sales_orders.php?status=Completed" class="metric-link">
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Completed</div>
            <div class="page-metric-value" style="color:#10b981;"><?= (int)$comp ?></div>
            <div class="page-metric-trend">Successfully fulfilled</div>
        </div>
    </a>
    <a href="sales_orders.php?status=Pending" class="metric-link">
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Pending</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= (int)$pend ?></div>
            <div class="page-metric-trend">Awaiting processing</div>
        </div>
    </a>
    <div class="page-metric-card border-purple">
        <div class="page-metric-title">Avg Order Value</div>
        <div class="page-metric-value" style="color:#8b5cf6;font-size:22px;"><?= $currency['symbol'] . number_format($avg_order, 0) ?></div>
        <div class="page-metric-trend">Per completed order</div>
    </div>
    <a href="sales_returns.php" class="metric-link">
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Refunds Issued</div>
            <div class="page-metric-value" style="color:#ef4444;font-size:22px;"><?= $currency['symbol'] . number_format($refunds, 0) ?></div>
            <div class="page-metric-trend"><?= badge($refunds, $refunds_prev, false) ?> <?= (int)$returns ?> pending</div>
        </div>
    </a>
</div>

<?php
    // Daily revenue chart
    $chart_days = min($days_diff, 60);
    $chart_from = date('Y-m-d', strtotime("$end_date -" . ($chart_days - 1) . " days"));
    $daily_res = $conn->query("SELECT order_date, SUM(total_amount) as rev, COUNT(*) as cnt FROM sales_orders WHERE order_date BETWEEN '$chart_from' AND '$end_date' GROUP BY order_date ORDER BY order_date ASC");
    $d_labels = []; $d_rev = []; $d_cnt = [];
    while ($r = $daily_res->fetch_assoc()) { $d_labels[] = date('d M', strtotime($r['order_date'])); $d_rev[] = round(floatval($r['rev']), 2); $d_cnt[] = (int)$r['cnt']; }

    // Fastest moving SKUs
    $fast_res = $conn->query("SELECT p.product_name, p.sku_code, SUM(soi.quantity) as total_sold FROM sales_order_items soi JOIN products p ON soi.product_id=p.id JOIN sales_orders so ON soi.sales_order_id=so.id WHERE so.status='Completed' AND so.order_date BETWEEN '$start_date' AND '$end_date' GROUP BY p.id ORDER BY total_sold DESC LIMIT 5");
    $fast_labels = []; $fast_data = [];
    while ($r = $fast_res->fetch_assoc()) { $fast_labels[] = $r['product_name']; $fast_data[] = (int)$r['total_sold']; }
?>

<div class="charts-wrapper" style="grid-template-columns: 2fr 1fr; margin-bottom:20px;">
    <div class="chart-container" style="height:360px;">
        <div class="chart-header">
            <h3>Daily Revenue Trend</h3>
            <a href="reports.php?tab=sales" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">Full Report →</a>
        </div>
        <div class="chart-canvas-wrapper" style="height:290px;"><canvas id="salesTrendChart"></canvas></div>
    </div>
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Top 5 SKUs Sold</h3></div>
        <div class="chart-canvas-wrapper" style="height:290px;"><canvas id="fastMovingChart"></canvas></div>
    </div>
</div>

<div class="charts-wrapper" style="grid-template-columns:1fr 1fr;margin-bottom:20px;">
    <div class="chart-container" style="height:320px;">
        <div class="chart-header"><h3>Order Status Breakdown</h3></div>
        <div class="chart-canvas-wrapper" style="display:flex;justify-content:center;align-items:center;height:250px;">
            <canvas id="orderStatusChart" style="max-height:100%;max-width:360px;"></canvas>
        </div>
    </div>
    <div class="chart-container" style="height:320px;">
        <div class="chart-header"><h3>Orders by Status</h3></div>
        <div style="padding-top:16px;">
            <?php
            $order_statuses = [
                ['Completed',  $comp,  '#10b981', 'sales_orders.php?status=Completed'],
                ['Pending',    $pend,  '#f59e0b', 'sales_orders.php?status=Pending'],
                ['Cancelled',  $canc,  '#ef4444', 'sales_orders.php?status=Cancelled'],
            ];
            $total_orders_all = max(1, $comp + $pend + $canc);
            foreach ($order_statuses as [$label, $count, $color, $link]):
                $pct = round($count / $total_orders_all * 100);
            ?>
            <a href="<?= $link ?>" style="text-decoration:none;display:block;margin-bottom:18px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:13px;color:var(--text-dark);font-weight:500;"><?= $label ?></span>
                    <span style="font-size:13px;font-weight:700;color:<?= $color ?>;"><?= (int)$count ?> <span style="color:var(--text-muted);font-weight:400;">(<?= $pct ?>%)</span></span>
                </div>
                <div style="background:var(--bg-light);border-radius:20px;height:8px;overflow:hidden;">
                    <div style="height:100%;background:<?= $color ?>;width:<?= $pct ?>%;border-radius:20px;transition:width .5s;"></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#6d4aff';
new Chart(document.getElementById('salesTrendChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($d_labels) ?>.length ? <?= json_encode($d_labels) ?> : ['No Data'],
        datasets: [{ label:'Revenue', data: <?= json_encode($d_rev) ?>,
            borderColor: PRIMARY, backgroundColor: PRIMARY + '18',
            borderWidth: 2, fill: true, tension: 0.4,
            pointRadius: <?= count($d_labels) > 30 ? 0 : 3 ?>, pointHoverRadius: 5 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
        plugins: { legend: { display:false } },
        scales: { y: { beginAtZero:true, ticks: { callback: v => window.CURRENCY.symbol+v.toLocaleString() }, grid: { color:'rgba(0,0,0,.04)' } }, x: { grid: { display:false }, ticks: { maxTicksLimit: 10 } } }
    }
});
new Chart(document.getElementById('fastMovingChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($fast_labels) ?>.length ? <?= json_encode($fast_labels) ?> : ['No Data'],
        datasets: [{ label:'Units Sold', data: <?= json_encode($fast_data) ?>.length ? <?= json_encode($fast_data) ?> : [0],
            backgroundColor: ['#6D4AFF','#3b82f6','#10b981','#f59e0b','#ef4444'], borderRadius: 4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, indexAxis:'y',
        scales: { x: { beginAtZero:true }, y: { grid: { display:false } } },
        plugins: { legend: { display:false } }
    }
});
new Chart(document.getElementById('orderStatusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Completed','Pending','Cancelled'],
        datasets: [{ data: [<?= $comp ?>,<?= $pend ?>,<?= $canc ?>],
            backgroundColor: ['rgba(16,185,129,.85)','rgba(245,158,11,.85)','rgba(239,68,68,.85)'],
            borderWidth: 2, hoverOffset: 6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins: { legend: { position:'bottom', labels: { boxWidth:12 } } } }
});
</script>

<?php // ══════════════════════════════════════════════════════════════
// VIEW: PURCHASE
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'purchase'):
    // FIXED: use order_date not created_at
    $total_pos     = qv($conn, "SELECT COUNT(*) FROM purchase_orders WHERE order_date BETWEEN '$start_date' AND '$end_date'");
    $total_pos_prev= qv($conn, "SELECT COUNT(*) FROM purchase_orders WHERE order_date BETWEEN '$prior_start' AND '$prior_end'");
    $total_spend   = qv($conn, "SELECT SUM(total_amount) FROM purchase_orders WHERE status='Received' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $spend_prev    = qv($conn, "SELECT SUM(total_amount) FROM purchase_orders WHERE status='Received' AND order_date BETWEEN '$prior_start' AND '$prior_end'");
    $drafts_po     = qv($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status='Draft' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $received_po   = qv($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status='Received' AND order_date BETWEEN '$start_date' AND '$end_date'");
    $arrivals      = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status IN ('Pending','In Transit') AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $overdue_del   = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status != 'Delivered' AND expected_delivery < CURDATE()");
?>

<div class="page-metrics-grid">
    <a href="purchase_orders.php" class="metric-link">
        <div class="page-metric-card">
            <div class="page-metric-title">Total POs</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= (int)$total_pos ?></div>
            <div class="page-metric-trend"><?= badge($total_pos, $total_pos_prev) ?> vs prior period</div>
        </div>
    </a>
    <div class="page-metric-card border-green">
        <div class="page-metric-title">Total Spend</div>
        <div class="page-metric-value" style="color:#10b981;font-size:22px;"><?= $currency['symbol'] . number_format($total_spend, 0) ?></div>
        <div class="page-metric-trend"><?= badge($total_spend, $spend_prev, false) ?> vs prior period</div>
    </div>
    <a href="purchase_orders.php?status=Draft" class="metric-link">
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Drafts (Pending)</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= (int)$drafts_po ?></div>
            <div class="page-metric-trend">Needs authorization</div>
        </div>
    </a>
    <a href="purchase_orders.php?status=Received" class="metric-link">
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Received POs</div>
            <div class="page-metric-value" style="color:#3b82f6;"><?= (int)$received_po ?></div>
            <div class="page-metric-trend">Goods confirmed</div>
        </div>
    </a>
    <a href="shipment.php" class="metric-link">
        <div class="page-metric-card border-purple">
            <div class="page-metric-title">Expected Arrivals</div>
            <div class="page-metric-value" style="color:#8b5cf6;"><?= (int)$arrivals ?></div>
            <div class="page-metric-trend">Inbound shipments</div>
        </div>
    </a>
    <a href="shipment.php" class="metric-link">
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Overdue Deliveries</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= (int)$overdue_del ?></div>
            <div class="page-metric-trend">Past expected date</div>
        </div>
    </a>
</div>

<?php
    // Supplier lead time
    $sup_res = $conn->query("SELECT s.supplier_name, AVG(DATEDIFF(ps.expected_delivery, ps.shipment_date)) as avg_days FROM purchase_shipments ps JOIN purchase_orders po ON ps.purchase_order_id=po.id JOIN suppliers s ON po.supplier_id=s.id WHERE ps.status='Delivered' GROUP BY s.id ORDER BY avg_days ASC LIMIT 6");
    $sup_labels = []; $sup_data = [];
    if ($sup_res) while ($r = $sup_res->fetch_assoc()) if ($r['avg_days'] !== null) { $sup_labels[] = $r['supplier_name']; $sup_data[] = round(floatval($r['avg_days']), 1); }

    // Top suppliers by spend
    $spend_res = $conn->query("SELECT s.supplier_name, COUNT(po.id) as pos, SUM(po.total_amount) as total FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.id WHERE po.order_date BETWEEN '$start_date' AND '$end_date' GROUP BY s.id ORDER BY total DESC LIMIT 5");
    $spend_labels = []; $spend_data = [];
    if ($spend_res) while ($r = $spend_res->fetch_assoc()) { $spend_labels[] = $r['supplier_name']; $spend_data[] = round(floatval($r['total']), 2); }

    // Monthly PO trend (last 6 months)
    $po_mo_res = $conn->query("SELECT DATE_FORMAT(order_date,'%b %Y') as mo, COUNT(*) as cnt FROM purchase_orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY YEAR(order_date), MONTH(order_date) ORDER BY YEAR(order_date), MONTH(order_date)");
    $po_mo_labels = []; $po_mo_data = [];
    if ($po_mo_res) while ($r = $po_mo_res->fetch_assoc()) { $po_mo_labels[] = $r['mo']; $po_mo_data[] = (int)$r['cnt']; }
?>

<div class="charts-wrapper" style="margin-bottom:20px;">
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Top Suppliers by Spend (Period)</h3></div>
        <div class="chart-canvas-wrapper" style="height:290px;"><canvas id="supplierSpendChart"></canvas></div>
    </div>
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Supplier Lead Time (Avg Days)</h3></div>
        <?php if (empty($sup_labels)): ?>
            <div style="display:flex;align-items:center;justify-content:center;height:290px;color:var(--text-muted);font-size:13px;text-align:center;padding:20px;">
                No delivered shipments yet — lead time data will appear once deliveries are completed.
            </div>
        <?php else: ?>
            <div class="chart-canvas-wrapper" style="height:290px;"><canvas id="leadTimeChart"></canvas></div>
        <?php endif; ?>
    </div>
</div>

<div class="charts-wrapper" style="grid-template-columns:1fr;margin-bottom:20px;">
    <div class="chart-container" style="height:320px;">
        <div class="chart-header">
            <h3>Monthly Purchase Orders — Last 6 Months</h3>
            <a href="purchase_orders.php" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">View All POs →</a>
        </div>
        <div class="chart-canvas-wrapper" style="height:250px;"><canvas id="poTrendChart"></canvas></div>
    </div>
</div>

<script>
new Chart(document.getElementById('supplierSpendChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($spend_labels) ?>.length ? <?= json_encode($spend_labels) ?> : ['No Data'],
        datasets: [{ label:'Spend', data: <?= json_encode($spend_data) ?>.length ? <?= json_encode($spend_data) ?> : [0],
            backgroundColor: ['#6D4AFF','#3b82f6','#10b981','#f59e0b','#ef4444'], borderRadius: 6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, indexAxis:'y',
        scales: { x: { beginAtZero:true, ticks: { callback: v => window.CURRENCY.symbol+v.toLocaleString() } }, y: { grid: { display:false } } },
        plugins: { legend: { display:false } }
    }
});
<?php if (!empty($sup_labels)): ?>
new Chart(document.getElementById('leadTimeChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($sup_labels) ?>,
        datasets: [{ label:'Avg Lead Time (Days)', data: <?= json_encode($sup_data) ?>,
            backgroundColor: '#10b981', borderRadius: 4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, indexAxis:'y',
        scales: { x: { beginAtZero:true }, y: { grid: { display:false } } }
    }
});
<?php endif; ?>
new Chart(document.getElementById('poTrendChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($po_mo_labels) ?>.length ? <?= json_encode($po_mo_labels) ?> : ['No Data'],
        datasets: [{ label:'Purchase Orders', data: <?= json_encode($po_mo_data) ?>.length ? <?= json_encode($po_mo_data) ?> : [0],
            backgroundColor: 'rgba(59,130,246,0.75)', borderRadius: 6, borderColor: '#3b82f6', borderWidth: 1 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
        scales: { y: { beginAtZero:true, ticks: { stepSize: 1 } }, x: { grid: { display:false } } },
        plugins: { legend: { display:false } }
    }
});
</script>

<?php // ══════════════════════════════════════════════════════════════
// VIEW: SHIPMENT
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'shipment'):
    $pend_ship = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status='Pending' AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $transit   = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status='In Transit' AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $shipped   = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status='Shipped' AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $delivered = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status='Delivered' AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $returned  = qv($conn, "SELECT COUNT(*) FROM purchase_returns WHERE status='Processed' AND created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'");
    $overdue2  = qv($conn, "SELECT COUNT(*) FROM purchase_shipments WHERE status != 'Delivered' AND expected_delivery < CURDATE()");

    // Avg fulfillment time (now displayed!)
    $avg_fulfill = 0;
    $ff_res = $conn->query("SELECT AVG(DATEDIFF(shipment_date, expected_delivery)) as avg_days FROM purchase_shipments WHERE status='Delivered' AND shipment_date BETWEEN '$start_date' AND '$end_date'");
    if ($ff_res) { $ffr = $ff_res->fetch_assoc(); $avg_fulfill = $ffr['avg_days'] !== null ? round(floatval($ffr['avg_days']), 1) : 0; }
?>

<div class="page-metrics-grid">
    <a href="shipment.php?status=Pending" class="metric-link">
        <div class="page-metric-card">
            <div class="page-metric-title">Pending</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= (int)$pend_ship ?></div>
            <div class="page-metric-trend">Needs attention</div>
        </div>
    </a>
    <a href="shipment.php?status=In+Transit" class="metric-link">
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">In Transit</div>
            <div class="page-metric-value" style="color:#3b82f6;"><?= (int)$transit ?></div>
            <div class="page-metric-trend">Out for delivery</div>
        </div>
    </a>
    <a href="shipment.php?status=Delivered" class="metric-link">
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Delivered</div>
            <div class="page-metric-value" style="color:#10b981;"><?= (int)$delivered ?></div>
            <div class="page-metric-trend">Completed handover</div>
        </div>
    </a>
    <a href="purchase_returns.php" class="metric-link">
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Returned to Origin</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= (int)$returned ?></div>
            <div class="page-metric-trend">Delivery failed</div>
        </div>
    </a>
    <a href="shipment.php" class="metric-link">
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Overdue Deliveries</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= (int)$overdue2 ?></div>
            <div class="page-metric-trend">Past expected date</div>
        </div>
    </a>
    <div class="page-metric-card border-purple">
        <div class="page-metric-title">Avg Fulfillment Days</div>
        <div class="page-metric-value" style="color:#8b5cf6;"><?= $avg_fulfill > 0 ? $avg_fulfill : '–' ?></div>
        <div class="page-metric-trend">Delivered shipments</div>
    </div>
</div>

<div class="charts-wrapper" style="grid-template-columns: 1fr 1fr; margin-bottom:20px;">
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Fulfillment Status Distribution</h3></div>
        <div class="chart-canvas-wrapper" style="display:flex;justify-content:center;align-items:center;height:290px;">
            <canvas id="fulfillmentChart" style="max-height:100%;max-width:400px;"></canvas>
        </div>
    </div>
    <div class="chart-container" style="height:360px;">
        <div class="chart-header"><h3>Status Breakdown</h3></div>
        <div style="padding-top:10px;">
            <?php
            $statuses = [
                ['Pending',   $pend_ship, 'var(--primary-color)', 'shipment.php?status=Pending'],
                ['In Transit',$transit,   '#3b82f6',              'shipment.php?status=In+Transit'],
                ['Shipped',   $shipped,   '#f59e0b',              'shipment.php?status=Shipped'],
                ['Delivered', $delivered, '#10b981',              'shipment.php?status=Delivered'],
                ['Returned',  $returned,  '#ef4444',              'purchase_returns.php'],
            ];
            $total_ship = max(1, $pend_ship + $transit + $shipped + $delivered + $returned);
            foreach ($statuses as [$label, $count, $color, $link]):
                $pct = round($count / $total_ship * 100);
            ?>
            <a href="<?= $link ?>" style="text-decoration:none;display:block;margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                    <span style="font-size:13px;color:var(--text-dark);font-weight:500;"><?= $label ?></span>
                    <span style="font-size:13px;font-weight:700;color:<?= $color ?>;"><?= (int)$count ?> <span style="color:var(--text-muted);font-weight:400;">(<?= $pct ?>%)</span></span>
                </div>
                <div style="background:var(--bg-light);border-radius:20px;height:7px;overflow:hidden;">
                    <div style="height:100%;background:<?= $color ?>;width:<?= $pct ?>%;border-radius:20px;transition:width .5s;"></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
    // Delivery performance trend — daily delivered vs overdue count over selected period
    $dp_res = $conn->query("SELECT DATE_FORMAT(expected_delivery,'%d %b') as day, SUM(status='Delivered') as delivered, SUM(status != 'Delivered' AND expected_delivery < CURDATE()) as overdue FROM purchase_shipments WHERE expected_delivery BETWEEN '$start_date' AND '$end_date' GROUP BY expected_delivery ORDER BY expected_delivery ASC LIMIT 30");
    $dp_labels = []; $dp_delivered = []; $dp_overdue = [];
    if ($dp_res) while ($r = $dp_res->fetch_assoc()) { $dp_labels[] = $r['day']; $dp_delivered[] = (int)$r['delivered']; $dp_overdue[] = (int)$r['overdue']; }
?>

<div class="charts-wrapper" style="grid-template-columns:1fr;margin-bottom:20px;">
    <div class="chart-container" style="height:320px;">
        <div class="chart-header">
            <h3>Delivery Performance Trend</h3>
            <a href="shipment.php" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:600;">View All Shipments →</a>
        </div>
        <div class="chart-canvas-wrapper" style="height:250px;"><canvas id="deliveryPerfChart"></canvas></div>
    </div>
</div>

<script>
new Chart(document.getElementById('fulfillmentChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Pending','In Transit','Shipped','Delivered','Returned'],
        datasets: [{ data: [<?= $pend_ship ?>,<?= $transit ?>,<?= $shipped ?>,<?= $delivered ?>,<?= $returned ?>],
            backgroundColor: ['rgba(109,74,255,.8)','rgba(59,130,246,.8)','rgba(245,158,11,.8)','rgba(16,185,129,.8)','rgba(239,68,68,.8)'],
            borderWidth: 2, hoverOffset: 4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins: { legend: { position:'bottom', labels: { boxWidth:12 } } } }
});
new Chart(document.getElementById('deliveryPerfChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dp_labels) ?>.length ? <?= json_encode($dp_labels) ?> : ['No Data'],
        datasets: [
            { label:'Delivered', data: <?= json_encode($dp_delivered) ?>.length ? <?= json_encode($dp_delivered) ?> : [0],
              backgroundColor: 'rgba(16,185,129,0.8)', borderRadius: 4 },
            { label:'Overdue',   data: <?= json_encode($dp_overdue) ?>.length ? <?= json_encode($dp_overdue) ?> : [0],
              backgroundColor: 'rgba(239,68,68,0.7)',  borderRadius: 4 }
        ]
    },
    options: { responsive:true, maintainAspectRatio:false,
        scales: { y: { beginAtZero:true, stacked:false }, x: { grid: { display:false } } },
        plugins: { legend: { position:'top', labels: { boxWidth:12 } } }
    }
});
</script>

<?php endif; // end view switch ?>

</main>
</div>
<?php include 'includes/footer.php'; ?>
