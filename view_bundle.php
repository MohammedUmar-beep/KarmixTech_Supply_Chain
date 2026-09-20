<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

$bid    = intval($_GET['id'] ?? 0);
$bundle = $conn->query("SELECT b.*, u.name AS created_by_name FROM bundles b LEFT JOIN users u ON u.id=b.created_by WHERE b.id=$bid")->fetch_assoc();
if (!$bundle) { header("Location: bundles.php"); exit(); }

// Items
$items = [];
$ir    = $conn->query("SELECT bi.*, p.product_name, p.sku_code, p.stock_level, p.selling_price AS current_sell, p.purchasing_price AS current_cost
    FROM bundle_items bi JOIN products p ON p.id=bi.product_id WHERE bi.bundle_id=$bid ORDER BY bi.id");
while ($r = $ir->fetch_assoc()) $items[] = $r;

// Sales history
$sales = [];
$sr    = $conn->query("SELECT bs.*, so.order_id_str, so.order_date, c.customer_name
    FROM bundle_sales bs
    JOIN sales_orders so ON so.id=bs.sales_order_id
    JOIN customers c ON c.id=so.customer_id
    WHERE bs.bundle_id=$bid ORDER BY bs.id DESC LIMIT 50");
while ($r = $sr->fetch_assoc()) $sales[] = $r;

// Aggregate sales stats
$sale_stats = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(quantity),0) AS units,
    COALESCE(SUM(subtotal),0) AS revenue, COALESCE(SUM(profit_at_sale),0) AS profit
    FROM bundle_sales WHERE bundle_id=$bid")->fetch_assoc();

// Minimum stock available for this bundle
$min_stock = null;
foreach ($items as $it) {
    $avail = floor($it['stock_level'] / $it['quantity']);
    if ($min_stock === null || $avail < $min_stock) $min_stock = $avail;
}

$today = date('Y-m-d');
$eff_status = $bundle['status'];
if ($eff_status === 'Active' && !empty($bundle['valid_until']) && $bundle['valid_until'] < $today) $eff_status = 'Expired';

$page_title   = 'Bundle — '.$bundle['bundle_name'];
$current_page = 'bundles.php';
include 'includes/header.php';
?>
<style>
.vb-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;padding:0 24px 40px}
.vb-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden}
.vb-card-header{padding:14px 18px;border-bottom:1px solid var(--border-color);font-size:15px;font-weight:600;display:flex;align-items:center;justify-content:space-between}
.vb-table{width:100%;border-collapse:collapse;font-size:13px}
.vb-table th{font-size:12px;color:var(--text-muted);font-weight:600;padding:9px 14px;border-bottom:1px solid var(--border-color);text-align:left}
.vb-table td{padding:10px 14px;border-bottom:1px solid var(--border-color)}
.vb-table tr:last-child td{border-bottom:none}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:16px 24px}
.kpi{background:var(--card-bg);border:1px solid var(--border-color);border-radius:10px;padding:14px}
.kpi-val{font-size:20px;font-weight:700}.kpi-lbl{font-size:11px;color:var(--text-muted);margin-top:2px}
.meta-row{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;border-bottom:1px solid var(--border-color)}
.meta-row:last-child{border-bottom:none}
.profit-bar-bg{height:6px;border-radius:3px;background:var(--border-color);overflow:hidden;margin-top:8px}
.profit-bar-fill{height:100%;border-radius:3px}
.status-pill{font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px}
.s-active{background:rgba(16,185,129,.12);color:#059669}
.s-inactive{background:rgba(107,114,128,.1);color:#6b7280}
.s-expired{background:rgba(239,68,68,.1);color:#dc2626}
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left">
        <a href="bundles.php" class="back-link">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Bundles
        </a>
        <h1 style="font-size:24px;"><?= htmlspecialchars($bundle['bundle_name']) ?></h1>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <?php include 'includes/topbar_right.php'; ?>
        <a href="edit_bundle.php?id=<?= $bid ?>" class="btn btn-primary" style="text-decoration:none;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;">Edit Bundle</a>
    </div>
</div>

<!-- KPIs -->
<div class="page-metrics-grid" style="padding: 0 24px; margin-bottom: 0;">
    <div class="page-metric-card border-blue">
        <div class="page-metric-title">Bundle Price</div>
        <div class="page-metric-value" style="color:var(--primary-color);"><?= $currency[\'symbol\'] . number_format($bundle['bundle_price'],2) ?></div>
        <div class="page-metric-trend">Selling price per bundle</div>
    </div>
    <div class="page-metric-card <?= $bundle['profit_amount'] < 0 ? 'border-red' : 'border-green' ?>">
        <div class="page-metric-title">Profit per Bundle<?= $bundle['profit_amount']<0?' (LOSS)':'' ?></div>
        <div class="page-metric-value" style="color:<?= $bundle['profit_amount']<0?'var(--danger-color)':'#10b981' ?>;"><?= $currency[\'symbol\'] . number_format(abs($bundle['profit_amount']),2) ?></div>
        <div class="page-metric-trend">After cost of goods</div>
    </div>
    <div class="page-metric-card <?= $bundle['profit_margin_pct']<0?'border-red':($bundle['profit_margin_pct']<10?'border-yellow':'border-green') ?>">
        <div class="page-metric-title">Profit Margin</div>
        <div class="page-metric-value" style="color:<?= $bundle['profit_margin_pct']<0?'var(--danger-color)':($bundle['profit_margin_pct']<10?'#f59e0b':'#10b981') ?>;"><?= number_format($bundle['profit_margin_pct'],1) ?>%</div>
        <div class="page-metric-trend"><?= $bundle['profit_margin_pct']<10?'Below 10% threshold':'Healthy margin' ?></div>
    </div>
    <div class="page-metric-card <?= $min_stock>5?'border-green':($min_stock>0?'border-yellow':'border-red') ?>">
        <div class="page-metric-title">Stock Available</div>
        <div class="page-metric-value" style="color:<?= $min_stock>5?'#10b981':($min_stock>0?'#f59e0b':'var(--danger-color)') ?>;"><?= $min_stock ?? '—' ?></div>
        <div class="page-metric-trend">Bundles ready to sell</div>
    </div>
</div>

<div class="vb-grid">
    <div>
        <!-- Component Products -->
        <div class="vb-card" style="margin-bottom:16px">
            <div class="vb-card-header">
                Component Products
                <span style="font-size:12px;color:var(--text-muted)"><?= count($items) ?> product<?= count($items)!==1?'s':'' ?></span>
            </div>
            <table class="vb-table">
                <thead><tr><th>Product</th><th>SKU</th><th>Qty in bundle</th><th>Cost (snapshotted)</th><th>Sell (snapshotted)</th><th>Current stock</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it):
                    $price_changed = (floatval($it['unit_sell_snapshot']) !== floatval($it['current_sell']));
                ?>
                <tr>
                    <td style="font-weight:500"><?= htmlspecialchars($it['product_name']) ?></td>
                    <td style="font-family:monospace;font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($it['sku_code']) ?></td>
                    <td style="text-align:center">× <?= $it['quantity'] ?></td>
                    <td><?= $currency[\'symbol\'] . number_format($it['unit_cost_snapshot'],2) ?></td>
                    <td>
                        '+\$currency['symbol']+'<?= number_format($it['unit_sell_snapshot'],2) ?>
                        <?php if ($price_changed): ?>
                        <span style="font-size:10px;background:rgba(245,158,11,.12);color:#d97706;padding:1px 6px;border-radius:4px;margin-left:4px;" title="Current selling price is '+\$currency['symbol']+'<?= number_format($it['current_sell'],2) ?>">Price changed</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:500;color:<?= $it['stock_level']<=$it['quantity']?'var(--danger-color)':'var(--success-color)' ?>"><?= number_format($it['stock_level']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $has_price_change = false;
            foreach ($items as $it) if (floatval($it['unit_sell_snapshot']) !== floatval($it['current_sell'])) $has_price_change = true;
            if ($has_price_change):
            ?>
            <div style="padding:10px 16px;background:rgba(245,158,11,.06);border-top:1px solid var(--border-color);font-size:12px;color:#d97706;">
                ⚠ One or more product prices have changed since this bundle was created. <a href="edit_bundle.php?id=<?= $bid ?>" style="color:var(--primary-color)">Edit and save</a> to re-snapshot prices.
            </div>
            <?php endif; ?>
        </div>

        <!-- Sales History -->
        <div class="vb-card">
            <div class="vb-card-header">
                Sales History
                <span style="font-size:12px;color:var(--text-muted)"><?= number_format($sale_stats['cnt']) ?> orders · <?= number_format($sale_stats['units']) ?> units sold</span>
            </div>
            <?php if (!empty($sales)): ?>
            <table class="vb-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Qty</th><th>Revenue</th><th>Profit</th></tr></thead>
                <tbody>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td style="font-family:monospace;font-weight:600"><?= htmlspecialchars($s['order_id_str']) ?></td>
                    <td><?= htmlspecialchars($s['customer_name']) ?></td>
                    <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y',strtotime($s['order_date'])) ?></td>
                    <td><?= $s['quantity'] ?></td>
                    <td><?= $currency[\'symbol\'] . number_format($s['subtotal'],2) ?></td>
                    <td style="font-weight:500;color:<?= $s['profit_at_sale']>=0?'var(--success-color)':'var(--danger-color)' ?>"><?= $currency[\'symbol\'] . number_format($s['profit_at_sale'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px;">No sales recorded for this bundle yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right column: Bundle details -->
    <div style="display:flex;flex-direction:column;gap:14px;">

        <!-- Status & Identity -->
        <div class="vb-card" style="padding:18px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:12px;">Bundle Details</div>
            <div class="meta-row"><span style="color:var(--text-muted)">Bundle ID</span><span style="font-family:monospace;font-weight:600"><?= htmlspecialchars($bundle['bundle_id_str']) ?></span></div>
            <div class="meta-row"><span style="color:var(--text-muted)">SKU</span><span style="font-family:monospace"><?= htmlspecialchars($bundle['sku_code']) ?></span></div>
            <div class="meta-row"><span style="color:var(--text-muted)">Status</span>
                <span class="status-pill s-<?= strtolower($eff_status) ?>"><?= $eff_status ?></span>
            </div>
            <div class="meta-row"><span style="color:var(--text-muted)">Discount</span>
                <span>
                    <?php
                    if ($bundle['discount_type']==='percentage') echo number_format($bundle['discount_value'],0).'% off';
                    elseif ($bundle['discount_type']==='fixed')  echo $currency['symbol'].number_format($bundle['discount_value'],2).' off';
                    else echo 'Custom '+\$currency['symbol']+''.number_format($bundle['bundle_price'],2);
                    ?>
                </span>
            </div>
            <div class="meta-row"><span style="color:var(--text-muted)">Normal value</span><span><?= $currency[\'symbol\'] . number_format($bundle['normal_sell_value'],2) ?></span></div>
            <div class="meta-row"><span style="color:var(--text-muted)">Total cost</span><span><?= $currency[\'symbol\'] . number_format($bundle['total_cost'],2) ?></span></div>
            <?php if ($bundle['valid_until']): ?>
            <div class="meta-row"><span style="color:var(--text-muted)">Valid until</span><span><?= date('d M Y',strtotime($bundle['valid_until'])) ?></span></div>
            <?php endif; ?>
            <div class="meta-row"><span style="color:var(--text-muted)">Created by</span><span><?= htmlspecialchars($bundle['created_by_name'] ?? '—') ?></span></div>
            <div class="meta-row"><span style="color:var(--text-muted)">Created</span><span style="font-size:12px"><?= date('d M Y',strtotime($bundle['created_at'])) ?></span></div>
        </div>

        <!-- Margin visual -->
        <div class="vb-card" style="padding:18px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:12px;">Profit Breakdown</div>
            <?php
            $margin = floatval($bundle['profit_margin_pct']);
            $bar_col = $margin < 0 ? '#ef4444' : ($margin < 10 ? '#f59e0b' : '#10b981');
            $bar_w   = max(0, min(100, $margin));
            ?>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                <span style="color:var(--text-muted)">Margin</span>
                <span style="font-weight:700;color:<?= $bar_col ?>"><?= number_format($margin,1) ?>%</span>
            </div>
            <div class="profit-bar-bg"><div class="profit-bar-fill" style="width:<?= $bar_w ?>%;background:<?= $bar_col ?>"></div></div>
            <div style="margin-top:14px;font-size:13px;display:flex;flex-direction:column;gap:6px;">
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted)">All-time revenue</span><span style="font-weight:500"><?= $currency[\'symbol\'] . number_format($sale_stats['revenue'],2) ?></span></div>
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted)">All-time profit</span><span style="font-weight:500;color:<?= $sale_stats['profit']>=0?'var(--success-color)':'var(--danger-color)' ?>"><?= $currency[\'symbol\'] . number_format($sale_stats['profit'],2) ?></span></div>
            </div>
        </div>

        <?php if ($bundle['description']): ?>
        <div class="vb-card" style="padding:18px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">Description</div>
            <p style="font-size:13px;color:var(--text-muted);line-height:1.6"><?= nl2br(htmlspecialchars($bundle['description'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
</main>
<?php include 'includes/footer.php'; ?>
