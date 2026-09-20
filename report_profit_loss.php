<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Date filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$df = $conn->real_escape_string($date_from);
$dt = $conn->real_escape_string($date_to);

// ── INCOME (Payments Received) ───────────────────────────────
$revenue = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM payments_received WHERE DATE(payment_date) BETWEEN '$df' AND '$dt'")->fetch_row()[0];

// Credit notes issued (reduce revenue)
$credit_notes = $conn->query("SELECT COALESCE(SUM(credit_amount),0) FROM credit_notes WHERE DATE(issue_date) BETWEEN '$df' AND '$dt'")->fetch_row()[0];

$net_revenue = $revenue - $credit_notes;

// ── COST OF GOODS SOLD ───────────────────────────────────────
$cogs = $conn->query("
    SELECT COALESCE(SUM(poi.subtotal),0) AS total
    FROM purchase_order_items poi
    JOIN purchase_orders po ON po.id=poi.purchase_order_id
    WHERE po.status='Received' AND DATE(po.order_date) BETWEEN '$df' AND '$dt'")->fetch_row()[0];

$gross_profit = $net_revenue - $cogs;
$gross_margin = $net_revenue > 0 ? ($gross_profit / $net_revenue * 100) : 0;

// ── OPERATING EXPENSES (Payments Made — non-inventory) ──────
$expenses = $conn->query("SELECT COALESCE(SUM(amount),0) FROM payments_made WHERE DATE(payment_date) BETWEEN '$df' AND '$dt'")->fetch_row()[0];

$operating_profit = $gross_profit - $expenses;
$net_margin = $net_revenue > 0 ? ($operating_profit / $net_revenue * 100) : 0;

// ── BALANCE SHEET SNAPSHOTS ──────────────────────────────────
// Assets
$inventory_value = $conn->query("SELECT COALESCE(SUM(stock_level * purchasing_price),0) FROM products WHERE status != 'Deleted'")->fetch_row()[0];
$accounts_recv   = $conn->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status IN ('Unpaid','Overdue')")->fetch_row()[0];
$cash_on_hand    = $conn->query("SELECT COALESCE(SUM(amount),0) FROM payments_received")->fetch_row()[0];

// Liabilities
$accounts_pay = $conn->query("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE status IN ('Draft','Issued')")->fetch_row()[0];
$supplier_creds = $conn->query("SELECT COALESCE(SUM(amount),0) FROM supplier_credits WHERE status='Open'")->fetch_row()[0];

$total_assets = $inventory_value + $accounts_recv + $cash_on_hand;
$total_liab   = $accounts_pay + $supplier_creds;
$net_worth    = $total_assets - $total_liab;

// ── MONTHLY BREAKDOWN ────────────────────────────────────────
$monthly = [];
$mr = $conn->query("SELECT DATE_FORMAT(payment_date,'%Y-%m') AS mo, SUM(amount) AS rev
    FROM payments_received WHERE DATE(payment_date) BETWEEN '$df' AND '$dt' GROUP BY mo ORDER BY mo");
while ($r = $mr->fetch_assoc()) $monthly[$r['mo']]['revenue'] = $r['rev'];
$mc = $conn->query("SELECT DATE_FORMAT(po.order_date,'%Y-%m') AS mo, SUM(poi.subtotal) AS cogs
    FROM purchase_order_items poi JOIN purchase_orders po ON po.id=poi.purchase_order_id
    WHERE po.status='Received' AND DATE(po.order_date) BETWEEN '$df' AND '$dt' GROUP BY mo ORDER BY mo");
while ($r = $mc->fetch_assoc()) $monthly[$r['mo']]['cogs'] = $r['cogs'];
ksort($monthly);

// ── TOP PRODUCTS BY REVENUE ──────────────────────────────────
$top_products = [];
$tp = $conn->query("SELECT p.product_name, SUM(soi.quantity) qty, SUM(soi.subtotal) rev
    FROM sales_order_items soi
    JOIN products p ON p.id=soi.product_id
    JOIN sales_orders so ON so.id=soi.sales_order_id
    WHERE DATE(so.order_date) BETWEEN '$df' AND '$dt'
    GROUP BY p.id ORDER BY rev DESC LIMIT 10");
while ($r = $tp->fetch_assoc()) $top_products[] = $r;

$page_title   = 'P&L Report';
$current_page = 'reports.php';
include 'includes/header.php';
?>
<style>
.pl-card  { background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:22px; }
.pl-grid  { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.stat-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color); font-size:14px; }
.stat-row:last-child { border:none; }
.stat-row.total { font-weight:700; font-size:15px; border-top:2px solid var(--border-color); border-bottom:none; margin-top:4px; padding-top:10px; }
.stat-row.sub { color:var(--text-muted); font-size:13px; }
.profit-val { color:var(--success-color); font-weight:600; }
.loss-val   { color:var(--danger-color);  font-weight:600; }
.section-head { font-size:15px; font-weight:700; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.bs-row { display:flex; justify-content:space-between; padding:7px 0; font-size:13px; border-bottom:1px solid var(--border-color); }
.bs-row:last-child { border:none; }
.bs-row.total { font-weight:700; font-size:14px; border-top:2px solid var(--border-color); padding-top:10px; margin-top:4px; }
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.kpi-box { background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:16px; }
.kpi-val  { font-size:22px; font-weight:700; margin-bottom:4px; }
.kpi-lbl  { font-size:12px; color:var(--text-muted); }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left">
        <a href="reports.php" class="back-link">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Reports
        </a>
        <h1 style="font-size:24px;">Profit &amp; Loss / Balance Sheet</h1>
    </div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<!-- Date filter -->
<form method="GET" style="display:flex;align-items:center;gap:10px;padding:16px 24px;flex-wrap:wrap;">
    <label style="font-size:13px;color:var(--text-muted)">From</label>
    <input type="date" name="date_from" value="<?= $date_from ?>" class="form-input" style="width:160px;">
    <label style="font-size:13px;color:var(--text-muted)">To</label>
    <input type="date" name="date_to"   value="<?= $date_to ?>"   class="form-input" style="width:160px;">
    <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Apply</button>
    <a href="?date_from=<?= date('Y-01-01') ?>&date_to=<?= date('Y-12-31') ?>" style="padding:9px 14px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;text-decoration:none;color:var(--text-dark);">This Year</a>
    <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>"   style="padding:9px 14px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;text-decoration:none;color:var(--text-dark);">This Month</a>
</form>

<!-- KPIs -->
<div class="kpi-row" style="padding:0 24px 16px;">
    <div class="kpi-box">
        <div class="kpi-val"><?= $currency[\'symbol\'] . number_format($net_revenue,2) ?></div>
        <div class="kpi-lbl">Net Revenue</div>
    </div>
    <div class="kpi-box">
        <div class="kpi-val <?= $gross_profit<0?'loss-val':'profit-val' ?>"><?= $currency[\'symbol\'] . number_format(abs($gross_profit),2) ?><?= $gross_profit<0?' (Loss)':'' ?></div>
        <div class="kpi-lbl">Gross Profit</div>
    </div>
    <div class="kpi-box">
        <div class="kpi-val <?= $operating_profit<0?'loss-val':'profit-val' ?>"><?= $currency[\'symbol\'] . number_format(abs($operating_profit),2) ?><?= $operating_profit<0?' (Loss)':'' ?></div>
        <div class="kpi-lbl">Net Profit</div>
    </div>
    <div class="kpi-box">
        <div class="kpi-val" style="color:<?= $net_margin<0?'var(--danger-color)':($net_margin<10?'#d97706':'var(--success-color)') ?>"><?= number_format($net_margin,1) ?>%</div>
        <div class="kpi-lbl">Net Margin</div>
    </div>
</div>

<div class="pl-grid" style="padding:0 24px 24px;">

    <!-- P&L Statement -->
    <div class="pl-card">
        <div class="section-head">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="var(--primary-color)" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Profit &amp; Loss — <?= date('d M Y', strtotime($date_from)) ?> to <?= date('d M Y', strtotime($date_to)) ?>
        </div>
        <div class="stat-row"><span>Gross revenue</span><span><?= $currency[\'symbol\'] . number_format($revenue,2) ?></span></div>
        <div class="stat-row sub"><span style="padding-left:14px">Less: credit notes</span><span style="color:var(--danger-color)">-'+\$currency['symbol']+'<?= number_format($credit_notes,2) ?></span></div>
        <div class="stat-row total"><span>Net Revenue</span><span><?= $currency[\'symbol\'] . number_format($net_revenue,2) ?></span></div>
        <div style="margin-top:12px;"></div>
        <div class="stat-row sub"><span>Less: Cost of Goods Sold</span><span style="color:var(--danger-color)">-'+\$currency['symbol']+'<?= number_format($cogs,2) ?></span></div>
        <div class="stat-row total">
            <span>Gross Profit</span>
            <span class="<?= $gross_profit<0?'loss-val':'profit-val' ?>">
                <?= $gross_profit<0?'-':'' ?><?= $currency[\'symbol\'] . number_format(abs($gross_profit),2) ?>
                <small style="font-size:11px;font-weight:400;color:var(--text-muted)"> (<?= number_format($gross_margin,1) ?>%)</small>
            </span>
        </div>
        <div style="margin-top:12px;"></div>
        <div class="stat-row sub"><span>Less: Operating expenses (payments made)</span><span style="color:var(--danger-color)">-'+\$currency['symbol']+'<?= number_format($expenses,2) ?></span></div>
        <div class="stat-row total" style="font-size:16px;">
            <span>Net Profit / Loss</span>
            <span class="<?= $operating_profit<0?'loss-val':'profit-val' ?>">
                <?= $operating_profit<0?'-':'' ?><?= $currency[\'symbol\'] . number_format(abs($operating_profit),2) ?>
                <small style="font-size:11px;font-weight:400;color:var(--text-muted)"> (<?= number_format($net_margin,1) ?>%)</small>
            </span>
        </div>
    </div>

    <!-- Balance Sheet -->
    <div class="pl-card">
        <div class="section-head">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="var(--primary-color)" fill="none" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Balance Sheet (as of today)
        </div>
        <p style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Assets</p>
        <div class="bs-row"><span>Inventory value (cost)</span><span><?= $currency[\'symbol\'] . number_format($inventory_value,2) ?></span></div>
        <div class="bs-row"><span>Accounts receivable (open invoices)</span><span><?= $currency[\'symbol\'] . number_format($accounts_recv,2) ?></span></div>
        <div class="bs-row"><span>Cash received (all-time payments)</span><span><?= $currency[\'symbol\'] . number_format($cash_on_hand,2) ?></span></div>
        <div class="bs-row total"><span>Total Assets</span><span><?= $currency[\'symbol\'] . number_format($total_assets,2) ?></span></div>

        <p style="font-size:12px;font-weight:700;color:var(--text-muted);margin:16px 0 8px;text-transform:uppercase;letter-spacing:.05em;">Liabilities</p>
        <div class="bs-row"><span>Accounts payable (open POs)</span><span><?= $currency[\'symbol\'] . number_format($accounts_pay,2) ?></span></div>
        <div class="bs-row"><span>Supplier credit notes (open)</span><span><?= $currency[\'symbol\'] . number_format($supplier_creds,2) ?></span></div>
        <div class="bs-row total"><span>Total Liabilities</span><span><?= $currency[\'symbol\'] . number_format($total_liab,2) ?></span></div>

        <div style="margin-top:16px;background:<?= $net_worth>=0?'rgba(16,185,129,.08)':'rgba(239,68,68,.08)' ?>;border:1px solid <?= $net_worth>=0?'rgba(16,185,129,.25)':'rgba(239,68,68,.25)' ?>;border-radius:8px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:15px;">Net Worth (Assets − Liabilities)</span>
            <span style="font-size:18px;font-weight:700;color:<?= $net_worth>=0?'var(--success-color)':'var(--danger-color)' ?>"><?= $currency[\'symbol\'] . number_format($net_worth,2) ?></span>
        </div>
    </div>
</div>

<?php if (!empty($monthly) || !empty($top_products)): ?>
<div class="pl-grid" style="padding:0 24px 40px;">
    <!-- Monthly breakdown -->
    <div class="pl-card">
        <div class="section-head">Monthly Breakdown</div>
        <?php if (!empty($monthly)): ?>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr>
                <th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">Month</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">Revenue</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">COGS</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">Gross Profit</th>
            </tr></thead>
            <tbody>
            <?php foreach ($monthly as $mo=>$d):
                $r = $d['revenue']??0; $c = $d['cogs']??0; $gp = $r - $c;
            ?>
            <tr>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);"><?= date('M Y', strtotime($mo.'-01')) ?></td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);text-align:right;"><?= $currency[\'symbol\'] . number_format($r,2) ?></td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);text-align:right;color:var(--text-muted);"><?= $currency[\'symbol\'] . number_format($c,2) ?></td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);text-align:right;" class="<?= $gp<0?'loss-val':'profit-val' ?>"><?= $currency[\'symbol\'] . number_format($gp,2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:var(--text-muted);font-size:13px;">No data in this date range.</p>
        <?php endif; ?>
    </div>

    <!-- Top products -->
    <div class="pl-card">
        <div class="section-head">Top Products by Revenue</div>
        <?php if (!empty($top_products)): ?>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead><tr>
                <th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">Product</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">Units</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;">Revenue</th>
            </tr></thead>
            <tbody>
            <?php foreach ($top_products as $tp): ?>
            <tr>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);"><?= htmlspecialchars($tp['product_name']) ?></td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);text-align:right;color:var(--text-muted);"><?= number_format($tp['qty']) ?></td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--border-color);text-align:right;font-weight:500;"><?= $currency[\'symbol\'] . number_format($tp['rev'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:var(--text-muted);font-size:13px;">No sales data in this date range.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
