<?php
require_once 'includes/auth_guard.php';

$user_role = strtolower($_SESSION['role'] ?? 'staff');
if (!in_array($user_role, ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// ────────────────────────────────────────────────────────────────────────────
// DATE RANGE — default = current month (1st to today)
// ────────────────────────────────────────────────────────────────────────────
$today    = date('Y-m-d');
$from     = $_GET['from'] ?? date('Y-m-01');
$to       = $_GET['to']   ?? $today;
$preset   = $_GET['preset'] ?? 'month';

// Map week preset to correct start date for active detection
if ($preset === 'week' && (!isset($_GET['from']))) {
    $from = date('Y-m-d', strtotime('monday this week'));
}

// Sanitise
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : $today;

// Prior period (same length, immediately before)
$days_diff   = (strtotime($to) - strtotime($from)) / 86400 + 1;
$prior_to    = date('Y-m-d', strtotime($from . ' -1 day'));
$prior_from  = date('Y-m-d', strtotime($prior_to . ' -' . ($days_diff - 1) . ' days'));

// ────────────────────────────────────────────────────────────────────────────
// CSV EXPORT — handle before any HTML output
// ────────────────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $section = $_GET['export'];

    $queries = [
        'sales' => [
            'filename' => "Sales_Report_{$from}_to_{$to}.csv",
            'sql'      => "SELECT so.order_id_str AS 'Order ID', so.order_date AS 'Date',
                              COALESCE(c.customer_name,'Walk-in') AS 'Customer',
                              so.total_amount AS 'Revenue', so.status AS 'Status',
                              so.fulfillment_type AS 'Type'
                           FROM sales_orders so
                           LEFT JOIN customers c ON so.customer_id = c.id
                           WHERE so.order_date BETWEEN '$from' AND '$to'
                           ORDER BY so.order_date DESC"
        ],
        'top_products' => [
            'filename' => "Top_Products_{$from}_to_{$to}.csv",
            'sql'      => "SELECT p.sku_code AS 'SKU', p.product_name AS 'Product',
                              p.category AS 'Category',
                              SUM(soi.quantity) AS 'Units Sold',
                              SUM(soi.subtotal) AS 'Revenue',
                              p.selling_price AS 'Unit Price'
                           FROM sales_order_items soi
                           JOIN products p ON soi.product_id = p.id
                           JOIN sales_orders so ON soi.sales_order_id = so.id
                           WHERE so.status = 'Completed' AND so.order_date BETWEEN '$from' AND '$to'
                           GROUP BY p.id ORDER BY SUM(soi.subtotal) DESC"
        ],
        'purchasing' => [
            'filename' => "Purchase_Orders_{$from}_to_{$to}.csv",
            'sql'      => "SELECT po.purchase_id_str AS 'PO ID', po.order_date AS 'Date',
                              s.supplier_name AS 'Supplier', po.total_amount AS 'Amount',
                              po.status AS 'Status'
                           FROM purchase_orders po
                           JOIN suppliers s ON po.supplier_id = s.id
                           WHERE po.order_date BETWEEN '$from' AND '$to'
                           ORDER BY po.order_date DESC"
        ],
        'inventory' => [
            'filename' => "Inventory_Snapshot_{$today}.csv",
            'sql'      => "SELECT p.sku_code AS 'SKU', p.product_name AS 'Product',
                              p.category AS 'Category', p.stock_level AS 'Stock',
                              p.purchasing_price AS 'Cost Price', p.selling_price AS 'Sell Price',
                              ROUND((p.selling_price - p.purchasing_price) / p.selling_price * 100, 1) AS 'Margin %',
                              (p.stock_level * p.purchasing_price) AS 'Stock Value',
                              p.status AS 'Status'
                           FROM products p ORDER BY p.stock_level DESC"
        ],
        'cashflow' => [
            'filename' => "Cash_Flow_{$from}_to_{$to}.csv",
            'sql'      => "SELECT 'IN' AS 'Direction', pr.payment_date AS 'Date',
                              pr.payment_ref_str AS 'Reference',
                              COALESCE(c.customer_name,'Walk-in') AS 'Party',
                              pr.amount AS 'Amount', pr.payment_method AS 'Method', pr.notes AS 'Notes'
                           FROM payments_received pr
                           LEFT JOIN customers c ON pr.customer_id = c.id
                           WHERE pr.payment_date BETWEEN '$from' AND '$to'
                           UNION ALL
                           SELECT 'OUT', pm.payment_date,
                              pm.payment_ref_str, s.supplier_name,
                              pm.amount, pm.payment_method, pm.notes
                           FROM payments_made pm
                           JOIN suppliers s ON pm.supplier_id = s.id
                           WHERE pm.payment_date BETWEEN '$from' AND '$to'
                           ORDER BY Date DESC"
        ],
        'customers' => [
            'filename' => "Customer_Report_{$from}_to_{$to}.csv",
            'sql'      => "SELECT c.customer_name AS 'Customer', c.email AS 'Email',
                              c.contact_number AS 'Phone',
                              COUNT(DISTINCT so.id) AS 'Orders',
                              COALESCE(SUM(so.total_amount),0) AS 'Total Revenue',
                              COALESCE((SELECT SUM(i.amount) FROM invoices i WHERE i.customer_id=c.id AND i.status NOT IN ('Paid')),0) AS 'Outstanding',
                              COALESCE((SELECT COUNT(*) FROM sales_returns sr WHERE sr.customer_id=c.id),0) AS 'Returns'
                           FROM customers c
                           LEFT JOIN sales_orders so ON so.customer_id=c.id AND so.order_date BETWEEN '$from' AND '$to'
                           GROUP BY c.id ORDER BY SUM(so.total_amount) DESC"
        ],
        'returns' => [
            'filename' => "Returns_Credits_{$from}_to_{$to}.csv",
            'sql'      => "SELECT 'Sales Return' AS 'Type', sr.return_id_str AS 'Reference',
                              sr.return_date AS 'Date',
                              COALESCE(c.customer_name,'Unknown') AS 'Party',
                              sr.total_refund_amount AS 'Amount', sr.status AS 'Status', sr.reason AS 'Reason'
                           FROM sales_returns sr
                           LEFT JOIN customers c ON sr.customer_id=c.id
                           WHERE sr.return_date BETWEEN '$from' AND '$to'
                           UNION ALL
                           SELECT 'Purchase Return', pr.return_number_str, pr.return_date,
                              s.supplier_name, pr.amount, pr.status, NULL
                           FROM purchase_returns pr
                           JOIN suppliers s ON pr.supplier_id=s.id
                           WHERE pr.return_date BETWEEN '$from' AND '$to'
                           ORDER BY Date DESC"
        ],
        'coupons' => [
            'filename' => "Coupon_Report_{$from}_to_{$to}.csv",
            'sql'      => "SELECT code AS 'Code', name AS 'Name', type AS 'Type',
                              value AS 'Value', applies_to AS 'Applies To',
                              times_used AS 'Times Used', usage_limit AS 'Usage Limit',
                              start_date AS 'Start Date', end_date AS 'End Date',
                              status AS 'Status'
                           FROM coupons ORDER BY times_used DESC"
        ],
        'full_pl' => [
            'filename' => "Full_PL_{$from}_to_{$to}.csv",
            'sql'      => "SELECT 'Revenue' AS 'Category', 'Completed Sales' AS 'Description',
                              SUM(total_amount) AS 'Amount'
                           FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$from' AND '$to'
                           UNION ALL
                           SELECT 'Cost','Received Purchases', SUM(total_amount)
                           FROM purchase_orders WHERE status='Received' AND order_date BETWEEN '$from' AND '$to'
                           UNION ALL
                           SELECT 'Cash In','Payments Received', SUM(amount)
                           FROM payments_received WHERE payment_date BETWEEN '$from' AND '$to'
                           UNION ALL
                           SELECT 'Cash Out','Payments Made', SUM(amount)
                           FROM payments_made WHERE payment_date BETWEEN '$from' AND '$to'
                           UNION ALL
                           SELECT 'Refunds','Sales Returns', SUM(total_refund_amount)
                           FROM sales_returns WHERE return_date BETWEEN '$from' AND '$to'"
        ]
    ];

    if (isset($queries[$section])) {
        $q = $queries[$section];
        $res = $conn->query($q['sql']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $q['filename'] . '"');
        $out = fopen('php://output', 'w');
        if ($res && $res->num_rows > 0) {
            $first = true;
            while ($row = $res->fetch_assoc()) {
                if ($first) { fputcsv($out, array_keys($row)); $first = false; }
                fputcsv($out, array_values($row));
            }
        } else {
            fputcsv($out, ['No data for selected date range.']);
        }
        fclose($out);
        exit();
    }
}

// ────────────────────────────────────────────────────────────────────────────
// HELPER: run a query and return single numeric value
// ────────────────────────────────────────────────────────────────────────────
function qval($conn, $sql) {
    $r = $conn->query($sql);
    return $r ? floatval($r->fetch_row()[0] ?? 0) : 0;
}
function qpct($curr, $prev) {
    if ($prev == 0) return $curr > 0 ? 100 : 0;
    return round(($curr - $prev) / abs($prev) * 100, 1);
}

// ────────────────────────────────────────────────────────────────────────────
// KPI DATA (current + prior period for comparison arrows)
// ────────────────────────────────────────────────────────────────────────────
$kpi = [];

// Revenue
$kpi['revenue']      = qval($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$from' AND '$to'");
$kpi['revenue_prev'] = qval($conn, "SELECT SUM(total_amount) FROM sales_orders WHERE status='Completed' AND order_date BETWEEN '$prior_from' AND '$prior_to'");

// Orders
$kpi['orders']      = qval($conn, "SELECT COUNT(*) FROM sales_orders WHERE order_date BETWEEN '$from' AND '$to'");
$kpi['orders_prev'] = qval($conn, "SELECT COUNT(*) FROM sales_orders WHERE order_date BETWEEN '$prior_from' AND '$prior_to'");

// Purchase cost
$kpi['cogs']      = qval($conn, "SELECT SUM(total_amount) FROM purchase_orders WHERE status='Received' AND order_date BETWEEN '$from' AND '$to'");
$kpi['cogs_prev'] = qval($conn, "SELECT SUM(total_amount) FROM purchase_orders WHERE status='Received' AND order_date BETWEEN '$prior_from' AND '$prior_to'");

// Gross profit
$kpi['profit']      = $kpi['revenue'] - $kpi['cogs'];
$kpi['profit_prev'] = $kpi['revenue_prev'] - $kpi['cogs_prev'];

// Gross margin %
$kpi['margin']      = $kpi['revenue'] > 0 ? round($kpi['profit'] / $kpi['revenue'] * 100, 1) : 0;
$kpi['margin_prev'] = $kpi['revenue_prev'] > 0 ? round($kpi['profit_prev'] / $kpi['revenue_prev'] * 100, 1) : 0;

// Cash in / out
$kpi['cash_in']      = qval($conn, "SELECT SUM(amount) FROM payments_received WHERE payment_date BETWEEN '$from' AND '$to'");
$kpi['cash_in_prev'] = qval($conn, "SELECT SUM(amount) FROM payments_received WHERE payment_date BETWEEN '$prior_from' AND '$prior_to'");
$kpi['cash_out']      = qval($conn, "SELECT SUM(amount) FROM payments_made WHERE payment_date BETWEEN '$from' AND '$to'");
$kpi['cash_out_prev'] = qval($conn, "SELECT SUM(amount) FROM payments_made WHERE payment_date BETWEEN '$prior_from' AND '$prior_to'");
$kpi['net_cash']      = $kpi['cash_in'] - $kpi['cash_out'];
$kpi['net_cash_prev'] = $kpi['cash_in_prev'] - $kpi['cash_out_prev'];

// Inventory value (snapshot — no date filter)
$kpi['inv_value'] = qval($conn, "SELECT SUM(stock_level * purchasing_price) FROM products");

// ────────────────────────────────────────────────────────────────────────────
// SECTION DATA QUERIES
// ────────────────────────────────────────────────────────────────────────────

// --- TAB 1: Revenue & Sales ---
// Daily revenue for chart (last 30 points or date range, capped at 60 days for readability)
$sales_chart_days = min($days_diff, 60);
$chart_from = date('Y-m-d', strtotime("$to -" . ($sales_chart_days - 1) . " days"));
$daily_res = $conn->query(
    "SELECT order_date, SUM(total_amount) as rev, COUNT(*) as cnt
     FROM sales_orders WHERE order_date BETWEEN '$chart_from' AND '$to'
     GROUP BY order_date ORDER BY order_date ASC"
);
$daily_labels = []; $daily_rev = []; $daily_cnt = [];
while ($r = $daily_res->fetch_assoc()) {
    $daily_labels[] = date('d M', strtotime($r['order_date']));
    $daily_rev[]    = round(floatval($r['rev']), 2);
    $daily_cnt[]    = intval($r['cnt']);
}

// Orders by status
$status_res = $conn->query(
    "SELECT status, COUNT(*) as cnt, SUM(total_amount) as total
     FROM sales_orders WHERE order_date BETWEEN '$from' AND '$to'
     GROUP BY status"
);
$status_data = [];
while ($r = $status_res->fetch_assoc()) $status_data[] = $r;

// Fulfillment breakdown
$fulfill_res = $conn->query(
    "SELECT fulfillment_type, COUNT(*) as cnt, SUM(total_amount) as total
     FROM sales_orders WHERE order_date BETWEEN '$from' AND '$to'
     GROUP BY fulfillment_type"
);
$fulfill_data = [];
while ($r = $fulfill_res->fetch_assoc()) $fulfill_data[] = $r;

// --- TAB 2: Top Products ---
$top_products_res = $conn->query(
    "SELECT p.product_name, p.sku_code, p.category,
            SUM(soi.quantity) as units_sold,
            SUM(soi.subtotal) as revenue,
            p.selling_price, p.purchasing_price
     FROM sales_order_items soi
     JOIN products p ON soi.product_id = p.id
     JOIN sales_orders so ON soi.sales_order_id = so.id
     WHERE so.status = 'Completed' AND so.order_date BETWEEN '$from' AND '$to'
     GROUP BY p.id ORDER BY revenue DESC LIMIT 20"
);
$top_products = [];
while ($r = $top_products_res->fetch_assoc()) $top_products[] = $r;

// Revenue by category for chart
$cat_rev_res = $conn->query(
    "SELECT p.category, SUM(soi.subtotal) as revenue,
            SUM(soi.quantity * p.purchasing_price) as cost
     FROM sales_order_items soi
     JOIN products p ON soi.product_id = p.id
     JOIN sales_orders so ON soi.sales_order_id = so.id
     WHERE so.status = 'Completed' AND so.order_date BETWEEN '$from' AND '$to'
     GROUP BY p.category ORDER BY revenue DESC LIMIT 8"
);
$cat_labels = []; $cat_rev = []; $cat_cost = [];
while ($r = $cat_rev_res->fetch_assoc()) {
    $cat_labels[] = $r['category'] ?: 'Uncategorised';
    $cat_rev[]    = round(floatval($r['revenue']), 2);
    $cat_cost[]   = round(floatval($r['cost']), 2);
}

// --- TAB 3: Purchasing ---
$po_status_res = $conn->query(
    "SELECT status, COUNT(*) as cnt, SUM(total_amount) as total
     FROM purchase_orders WHERE order_date BETWEEN '$from' AND '$to'
     GROUP BY status"
);
$po_status = [];
while ($r = $po_status_res->fetch_assoc()) $po_status[] = $r;

$top_suppliers_res = $conn->query(
    "SELECT s.supplier_name, COUNT(po.id) as orders, SUM(po.total_amount) as total
     FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id
     WHERE po.order_date BETWEEN '$from' AND '$to'
     GROUP BY s.id ORDER BY total DESC LIMIT 10"
);
$top_suppliers = [];
while ($r = $top_suppliers_res->fetch_assoc()) $top_suppliers[] = $r;

// --- TAB 4: Profitability ---
$profit_products_res = $conn->query(
    "SELECT p.product_name, p.category,
            SUM(soi.quantity) as units,
            SUM(soi.subtotal) as revenue,
            SUM(soi.quantity * p.purchasing_price) as cost,
            SUM(soi.subtotal) - SUM(soi.quantity * p.purchasing_price) as profit
     FROM sales_order_items soi
     JOIN products p ON soi.product_id = p.id
     JOIN sales_orders so ON soi.sales_order_id = so.id
     WHERE so.status = 'Completed' AND so.order_date BETWEEN '$from' AND '$to'
     GROUP BY p.id ORDER BY profit DESC LIMIT 15"
);
$profit_products = [];
while ($r = $profit_products_res->fetch_assoc()) $profit_products[] = $r;

// --- TAB 5: Inventory ---
$low_stock_res = $conn->query(
    "SELECT product_name, sku_code, category, stock_level, warning_threshold,
            (stock_level * purchasing_price) as value
     FROM products WHERE stock_level <= warning_threshold AND stock_level > 0
     ORDER BY stock_level ASC LIMIT 15"
);
$low_stock = [];
while ($r = $low_stock_res->fetch_assoc()) $low_stock[] = $r;

$dead_stock_res = $conn->query(
    "SELECT p.product_name, p.sku_code, p.category, p.stock_level,
            (p.stock_level * p.purchasing_price) as trapped_capital
     FROM products p
     WHERE p.stock_level > 0
       AND p.id NOT IN (
           SELECT DISTINCT soi.product_id FROM sales_order_items soi
           JOIN sales_orders so ON soi.sales_order_id = so.id
           WHERE so.order_date BETWEEN '$from' AND '$to' AND so.status != 'Cancelled'
       )
     ORDER BY trapped_capital DESC LIMIT 15"
);
$dead_stock = [];
while ($r = $dead_stock_res->fetch_assoc()) $dead_stock[] = $r;

$cat_inv_res = $conn->query(
    "SELECT category, COUNT(*) as products, SUM(stock_level) as units,
            SUM(stock_level * purchasing_price) as value
     FROM products WHERE status != 'Deleted'
     GROUP BY category ORDER BY value DESC"
);
$cat_inv = [];
while ($r = $cat_inv_res->fetch_assoc()) $cat_inv[] = $r;

// --- TAB 6: Cash Flow ---
$payment_methods_res = $conn->query(
    "SELECT payment_method, SUM(amount) as total, COUNT(*) as cnt
     FROM payments_received WHERE payment_date BETWEEN '$from' AND '$to'
     GROUP BY payment_method ORDER BY total DESC"
);
$payment_methods = [];
while ($r = $payment_methods_res->fetch_assoc()) $payment_methods[] = $r;

$overdue_res = $conn->query(
    "SELECT i.invoice_number, c.customer_name, i.due_date, i.amount,
            DATEDIFF('$today', i.due_date) as days_overdue
     FROM invoices i JOIN customers c ON i.customer_id = c.id
     WHERE i.status NOT IN ('Paid') AND i.due_date < '$today'
     ORDER BY days_overdue DESC LIMIT 10"
);
$overdue = [];
while ($r = $overdue_res->fetch_assoc()) $overdue[] = $r;

// --- TAB 7: Customers ---
$top_customers_res = $conn->query(
    "SELECT c.customer_name, c.email,
            COUNT(so.id) as orders,
            SUM(so.total_amount) as revenue,
            AVG(so.total_amount) as avg_order
     FROM customers c
     JOIN sales_orders so ON so.customer_id = c.id
     WHERE so.order_date BETWEEN '$from' AND '$to'
     GROUP BY c.id ORDER BY revenue DESC LIMIT 15"
);
$top_customers = [];
while ($r = $top_customers_res->fetch_assoc()) $top_customers[] = $r;

$cust_balance_res = $conn->query(
    "SELECT c.customer_name, SUM(i.amount) as invoiced,
            COALESCE(SUM(pr.amount),0) as paid
     FROM customers c
     LEFT JOIN invoices i ON i.customer_id = c.id AND i.status NOT IN ('Paid')
     LEFT JOIN payments_received pr ON pr.customer_id = c.id
               AND pr.payment_date BETWEEN '$from' AND '$to'
     WHERE i.id IS NOT NULL
     GROUP BY c.id ORDER BY invoiced DESC LIMIT 10"
);
$cust_balances = [];
while ($r = $cust_balance_res->fetch_assoc()) $cust_balances[] = $r;

// --- TAB 8: Returns & Credits ---
// Sales Returns
$sales_returns_res = $conn->query(
    "SELECT sr.return_id_str, sr.return_date, c.customer_name,
            sr.total_refund_amount, sr.status, sr.reason, sr.id
     FROM sales_returns sr LEFT JOIN customers c ON sr.customer_id = c.id
     WHERE sr.return_date BETWEEN '$from' AND '$to'
     ORDER BY sr.return_date DESC LIMIT 20"
);
$sales_returns_data = [];
while ($r = $sales_returns_res->fetch_assoc()) $sales_returns_data[] = $r;

// Purchase Returns
$purchase_returns_res = $conn->query(
    "SELECT pr.return_number_str, pr.return_date, s.supplier_name,
            pr.amount, pr.status, pr.id,
            po.purchase_id_str AS po_number
     FROM purchase_returns pr
     LEFT JOIN suppliers s       ON pr.supplier_id       = s.id
     LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
     WHERE pr.return_date BETWEEN '$from' AND '$to'
     ORDER BY pr.return_date DESC LIMIT 20"
);
$purchase_returns_data = [];
while ($r = $purchase_returns_res->fetch_assoc()) $purchase_returns_data[] = $r;

// Credit Notes (customer)
$credit_notes_res = $conn->query(
    "SELECT cn.credit_note_str, cn.issue_date, c.customer_name,
            cn.credit_amount, cn.status, cn.reason, cn.id
     FROM credit_notes cn LEFT JOIN customers c ON cn.customer_id = c.id
     WHERE cn.issue_date BETWEEN '$from' AND '$to'
     ORDER BY cn.issue_date DESC LIMIT 20"
);
$credit_notes_data = [];
while ($r = $credit_notes_res->fetch_assoc()) $credit_notes_data[] = $r;

// Supplier Credits
$sc_col_check = $conn->query("SHOW COLUMNS FROM supplier_credits LIKE 'purchase_order_id'");
$sc_has_po_col = $sc_col_check && $sc_col_check->num_rows > 0;
$supplier_credits_res = $conn->query(
    "SELECT sc.credit_note_str, sc.date_issued, s.supplier_name,
            sc.amount, sc.status, sc.id" .
    ($sc_has_po_col ? ", po.purchase_id_str AS po_number" : ", NULL AS po_number") .
    " FROM supplier_credits sc
     LEFT JOIN suppliers s ON sc.supplier_id = s.id" .
    ($sc_has_po_col ? " LEFT JOIN purchase_orders po ON sc.purchase_order_id = po.id" : "") .
    " WHERE sc.date_issued BETWEEN '$from' AND '$to'
     ORDER BY sc.date_issued DESC LIMIT 20"
);
$supplier_credits_data = [];
while ($r = $supplier_credits_res->fetch_assoc()) $supplier_credits_data[] = $r;

// Summary totals for metric cards
$ret_total_sales_refunds   = qval($conn, "SELECT SUM(total_refund_amount) FROM sales_returns WHERE return_date BETWEEN '$from' AND '$to'");
$ret_total_purchase_refunds= qval($conn, "SELECT SUM(amount) FROM purchase_returns WHERE return_date BETWEEN '$from' AND '$to'");
$ret_total_credit_notes    = qval($conn, "SELECT SUM(credit_amount) FROM credit_notes WHERE issue_date BETWEEN '$from' AND '$to'");
$ret_total_supplier_credits= qval($conn, "SELECT SUM(amount) FROM supplier_credits WHERE date_issued BETWEEN '$from' AND '$to'");
$ret_count_sales           = qval($conn, "SELECT COUNT(*) FROM sales_returns WHERE return_date BETWEEN '$from' AND '$to'");
$ret_count_purchase        = qval($conn, "SELECT COUNT(*) FROM purchase_returns WHERE return_date BETWEEN '$from' AND '$to'");
$ret_count_credit_notes    = qval($conn, "SELECT COUNT(*) FROM credit_notes WHERE issue_date BETWEEN '$from' AND '$to'");
$ret_count_supplier_credits= qval($conn, "SELECT COUNT(*) FROM supplier_credits WHERE date_issued BETWEEN '$from' AND '$to'");

// --- TAB 9: Coupons & Discounts ---
$coupons_res = $conn->query(
    "SELECT code, name, type, value, applies_to, times_used, usage_limit,
            start_date, end_date, status
     FROM coupons ORDER BY times_used DESC"
);
$coupons_data = [];
while ($r = $coupons_res->fetch_assoc()) $coupons_data[] = $r;

$total_discount_given = qval($conn,
    "SELECT SUM(cn.credit_amount) FROM credit_notes cn
     WHERE cn.issue_date BETWEEN '$from' AND '$to' AND cn.reason LIKE '%Credit%'"
);
$total_coupon_uses = qval($conn, "SELECT SUM(times_used) FROM coupons WHERE status='Active'");

// ────────────────────────────────────────────────────────────────────────────
// RENDER
// ────────────────────────────────────────────────────────────────────────────
$page_title   = 'Reports';
$current_page = 'reports.php';
$extra_head   = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
include 'includes/header.php';

// Helper for change badge
function changeBadge($curr, $prev) {
    $pct = qpct($curr, $prev);
    if ($pct == 0) return '<span style="font-size:11px;color:var(--text-muted);">–</span>';
    $up = $pct > 0;
    $col = $up ? 'var(--success-color)' : '#ef4444';
    $bg  = $up ? 'rgba(16,185,129,.1)' : 'rgba(239,68,68,.1)';
    $arrow = $up ? '↑' : '↓';
    return "<span style='font-size:11px;font-weight:600;color:{$col};background:{$bg};padding:2px 7px;border-radius:10px;'>{$arrow} " . abs($pct) . "%</span>";
}
function fmt($n) { return '$' . number_format($n, 2); }
function fmtN($n) { return number_format($n); }
?>
<main class="main-area">

<div class="topbar">
    <div class="topbar-left" style="display:flex;align-items:center;gap:12px;">
        <h1>Reports</h1>
        <span style="font-size:13px;color:var(--text-muted);">
            <?= date('d M Y', strtotime($from)) ?> – <?= date('d M Y', strtotime($to)) ?>
        </span>
    </div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     DATE RANGE BAR
════════════════════════════════════════════════════════════════════════ -->
<div style="background:var(--card-bg);border-bottom:1px solid var(--border-color);padding:10px 24px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0;">
    <!-- Preset buttons -->
    <?php
    $presets = [
        'today'   => ['Today',      date('Y-m-d'),                               date('Y-m-d')],
        'week'    => ['This Week',  date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
        'month'   => ['This Month', date('Y-m-01'),                              date('Y-m-d')],
        'year'    => ['This Year',  date('Y-01-01'),                             date('Y-m-d')],
    ];
    foreach ($presets as $key => [$label, $pf, $pt]):
        $active = ($from === $pf && $to === $pt);
    ?>
        <a href="?from=<?= $pf ?>&to=<?= $pt ?>&preset=<?= $key ?>#tab-<?= $_GET['tab'] ?? 'summary' ?>"
            style="padding:5px 13px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid <?= $active ? 'var(--primary-color)' : 'var(--border-color)' ?>;background:<?= $active ? 'var(--primary-color)' : 'transparent' ?>;color:<?= $active ? '#fff' : 'var(--text-muted)' ?>;transition:all .15s;"><?= $label ?></a>
    <?php endforeach; ?>

    <span style="color:var(--border-color);font-size:16px;margin:0 2px;">|</span>

    <!-- Custom date range -->
    <form method="GET" style="display:flex;align-items:center;gap:8px;" id="dateForm">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? 'summary') ?>">
        <input type="date" name="from" value="<?= $from ?>"
            style="padding:5px 10px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;background:var(--input-bg);color:var(--text-dark);outline:none;">
        <span style="font-size:12px;color:var(--text-muted);">to</span>
        <input type="date" name="to" value="<?= $to ?>"
            style="padding:5px 10px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;background:var(--input-bg);color:var(--text-dark);outline:none;">
        <button type="submit"
            style="padding:5px 13px;background:var(--primary-color);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Apply</button>
    </form>

    <div style="margin-left:auto;font-size:11px;color:var(--text-muted);">
        vs <?= date('d M', strtotime($prior_from)) ?>–<?= date('d M Y', strtotime($prior_to)) ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     TAB NAVIGATION
════════════════════════════════════════════════════════════════════════ -->
<?php
$active_tab = $_GET['tab'] ?? 'summary';
$tabs = [
    'summary'    => 'Summary',
    'sales'      => 'Revenue & Sales',
    'products'   => 'Top Products',
    'purchasing' => 'Purchasing',
    'profit'     => 'Profitability',
    'inventory'  => 'Inventory',
    'cashflow'   => 'Cash Flow',
    'customers'  => 'Customers',
    'returns'    => 'Returns & Credits',
    'coupons'    => 'Discounts',
];
?>
<div style="background:var(--card-bg);border-bottom:1px solid var(--border-color);padding:0 24px;display:flex;gap:0;overflow-x:auto;scrollbar-width:none;flex-shrink:0;">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="?from=<?= $from ?>&to=<?= $to ?>&tab=<?= $key ?>"
            style="padding:12px 16px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;border-bottom:2px solid <?= $active_tab === $key ? 'var(--primary-color)' : 'transparent' ?>;color:<?= $active_tab === $key ? 'var(--primary-color)' : 'var(--text-muted)' ?>;transition:all .15s;display:block;">
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</div>

<div style="padding:24px;overflow-y:auto;flex:1;">

<?php // ══════════════════════════════════════════════════════════════════
// TAB: SUMMARY
// ══════════════════════════════════════════════════════════════════════
if ($active_tab === 'summary'): ?>

<!-- KPI cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;">
    <?php
    $kpis = [
        ['Revenue',        fmt($kpi['revenue']),   $kpi['revenue'],   $kpi['revenue_prev'],  '#10b981', true],
        ['Orders',         fmtN($kpi['orders']),   $kpi['orders'],    $kpi['orders_prev'],   'var(--primary-color)', true],
        ['Purchase Cost',  fmt($kpi['cogs']),      $kpi['cogs'],      $kpi['cogs_prev'],     '#ef4444', false],
        ['Gross Profit',   fmt($kpi['profit']),    $kpi['profit'],    $kpi['profit_prev'],   $kpi['profit'] >= 0 ? '#10b981' : '#ef4444', true],
        ['Gross Margin',   $kpi['margin'].'%',     $kpi['margin'],    $kpi['margin_prev'],   '#3b82f6', true],
        ['Net Cash Flow',  fmt($kpi['net_cash']),  $kpi['net_cash'],  $kpi['net_cash_prev'], $kpi['net_cash'] >= 0 ? '#10b981' : '#ef4444', true],
        ['Cash In',        fmt($kpi['cash_in']),   $kpi['cash_in'],   $kpi['cash_in_prev'],  '#10b981', true],
        ['Cash Out',       fmt($kpi['cash_out']),  $kpi['cash_out'],  $kpi['cash_out_prev'], '#ef4444', false],
        ['Inventory Value',fmt($kpi['inv_value']), 0, 0,              '#8b5cf6', true],
    ];
    foreach ($kpis as [$label, $val, $curr, $prev, $col, $higherGood]):
    ?>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:18px 20px;position:relative;overflow:hidden;">
        <div style="width:3px;height:100%;background:<?= $col ?>;position:absolute;left:0;top:0;border-radius:2px 0 0 2px;"></div>
        <div style="font-size:12px;color:var(--text-muted);font-weight:500;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;"><?= $label ?></div>
        <div style="font-size:24px;font-weight:700;color:var(--text-dark);margin-bottom:6px;"><?= $val ?></div>
        <div><?= $prev > 0 ? changeBadge($curr, $prev) : '<span style="font-size:11px;color:var(--text-muted);">No prior data</span>' ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Revenue trend chart -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:28px;">
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;font-size:15px;font-weight:600;">Daily Revenue</h3>
            <a href="?from=<?= $from ?>&to=<?= $to ?>&export=sales" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:500;display:flex;align-items:center;gap:4px;">
                <svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>
        </div>
        <div style="position:relative;height:220px;"><canvas id="revenueChart"></canvas></div>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Orders by Status</h3>
        <?php foreach ($status_data as $s): ?>
        <?php $cols = ['Completed'=>'#10b981','Pending'=>'#f59e0b','Cancelled'=>'#ef4444']; $c = $cols[$s['status']] ?? 'var(--primary-color)'; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <span style="font-size:13px;color:var(--text-dark);display:flex;align-items:center;gap:7px;">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= $c ?>;display:inline-block;"></span>
                <?= $s['status'] ?>
            </span>
            <span style="font-size:13px;font-weight:600;color:var(--text-dark);"><?= $s['cnt'] ?> <span style="color:var(--text-muted);font-weight:400;">(<?= fmt($s['total']) ?>)</span></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- P&L summary table -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;font-size:15px;font-weight:600;">P&amp;L Summary</h3>
        <a href="?from=<?= $from ?>&to=<?= $to ?>&export=full_pl" style="font-size:12px;color:var(--primary-color);text-decoration:none;font-weight:500;display:flex;align-items:center;gap:4px;">
            <svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export Full P&amp;L CSV
        </a>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <?php
        $pl_rows = [
            ['Revenue (Completed Sales)',   $kpi['revenue'],   '#10b981', false],
            ['Purchase Costs (Received POs)', $kpi['cogs'],   '#ef4444', false],
            ['Gross Profit',                $kpi['profit'],    $kpi['profit'] >= 0 ? '#10b981' : '#ef4444', true],
            ['Cash Inflows',                $kpi['cash_in'],   '#10b981', false],
            ['Cash Outflows',               $kpi['cash_out'],  '#ef4444', false],
            ['Net Cash Flow',               $kpi['net_cash'],  $kpi['net_cash'] >= 0 ? '#10b981' : '#ef4444', true],
        ];
        foreach ($pl_rows as [$label, $val, $col, $bold]):
        ?>
        <tr style="border-bottom:1px solid var(--border-color);<?= $bold ? 'background:var(--bg-light);' : '' ?>">
            <td style="padding:12px 20px;color:var(--text-dark);font-weight:<?= $bold ? 600 : 400 ?>;"><?= $label ?></td>
            <td style="padding:12px 20px;text-align:right;font-weight:700;color:<?= $col ?>;"><?= fmt($val) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: REVENUE & SALES
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'sales'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div>
        <span style="font-size:22px;font-weight:700;color:var(--text-dark);"><?= fmt($kpi['revenue']) ?></span>
        <span style="margin-left:10px;"><?= changeBadge($kpi['revenue'], $kpi['revenue_prev']) ?></span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;"><?= fmtN($kpi['orders']) ?> orders total</div>
    </div>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=sales" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export Sales CSV
    </a>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:20px;">
    <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Revenue Trend</h3>
    <div style="position:relative;height:260px;"><canvas id="salesTrendChart"></canvas></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Orders by Status</h3>
        <?php foreach ($status_data as $s): $cols = ['Completed'=>'#10b981','Pending'=>'#f59e0b','Cancelled'=>'#ef4444']; $c = $cols[$s['status']] ?? 'var(--primary-color)'; ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);">
            <span style="color:var(--text-dark);display:flex;align-items:center;gap:7px;font-size:13px;"><span style="width:8px;height:8px;border-radius:50%;background:<?= $c ?>;display:inline-block;"></span><?= $s['status'] ?></span>
            <span style="font-weight:600;font-size:13px;"><?= $s['cnt'] ?> · <?= fmt($s['total']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Fulfillment Breakdown</h3>
        <?php foreach ($fulfill_data as $f): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);">
            <span style="color:var(--text-dark);font-size:13px;"><?= $f['fulfillment_type'] ?: 'Standard' ?></span>
            <span style="font-weight:600;font-size:13px;"><?= $f['cnt'] ?> orders · <?= fmt($f['total']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: TOP PRODUCTS
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'products'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h2 style="margin:0;font-size:18px;font-weight:700;">Top Products by Revenue</h2>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=top_products" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:20px;">
    <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Revenue &amp; Cost by Category</h3>
    <div style="position:relative;height:260px;"><canvas id="catChart"></canvas></div>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;">
            <th style="padding:12px 16px;text-align:left;font-weight:500;">#</th>
            <th style="padding:12px 16px;text-align:left;font-weight:500;">Product</th>
            <th style="padding:12px 16px;text-align:left;font-weight:500;">Category</th>
            <th style="padding:12px 16px;text-align:right;font-weight:500;">Units</th>
            <th style="padding:12px 16px;text-align:right;font-weight:500;">Revenue</th>
            <th style="padding:12px 16px;text-align:right;font-weight:500;">Sell Price</th>
            <th style="padding:12px 16px;text-align:right;font-weight:500;">Margin</th>
        </tr></thead>
        <tbody>
        <?php foreach ($top_products as $i => $p):
            $margin = $p['selling_price'] > 0 ? round(($p['selling_price'] - $p['purchasing_price']) / $p['selling_price'] * 100, 1) : 0;
        ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:11px 16px;color:var(--text-muted);"><?= $i+1 ?></td>
            <td style="padding:11px 16px;font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($p['product_name']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= $p['sku_code'] ?></span></td>
            <td style="padding:11px 16px;color:var(--text-muted);"><?= htmlspecialchars($p['category']) ?></td>
            <td style="padding:11px 16px;text-align:right;"><?= number_format($p['units_sold']) ?></td>
            <td style="padding:11px 16px;text-align:right;font-weight:600;color:var(--text-dark);"><?= fmt($p['revenue']) ?></td>
            <td style="padding:11px 16px;text-align:right;"><?= fmt($p['selling_price']) ?></td>
            <td style="padding:11px 16px;text-align:right;"><span style="color:<?= $margin >= 20 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444') ?>;font-weight:600;"><?= $margin ?>%</span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($top_products)): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">No sales data for this period</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: PURCHASING
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'purchasing'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div>
        <span style="font-size:22px;font-weight:700;color:var(--text-dark);"><?= fmt($kpi['cogs']) ?></span>
        <span style="margin-left:10px;"><?= changeBadge($kpi['cogs'], $kpi['cogs_prev']) ?></span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Total purchase spend (received POs)</div>
    </div>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=purchasing" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 14px;font-size:15px;font-weight:600;">PO Status Breakdown</h3>
        <?php foreach ($po_status as $s): $cols = ['Received'=>'#10b981','Issued'=>'#3b82f6','Draft'=>'#9ca3af','Cancelled'=>'#ef4444']; $c = $cols[$s['status']] ?? 'var(--text-muted)'; ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);">
            <span style="color:var(--text-dark);display:flex;align-items:center;gap:7px;font-size:13px;"><span style="width:8px;height:8px;border-radius:50%;background:<?= $c ?>;display:inline-block;"></span><?= $s['status'] ?></span>
            <span style="font-weight:600;font-size:13px;"><?= $s['cnt'] ?> · <?= fmt($s['total']) ?></span>
        </div>
        <?php endforeach; if (empty($po_status)): ?><p style="color:var(--text-muted);font-size:13px;">No purchase orders in this period.</p><?php endif; ?>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 14px;font-size:15px;font-weight:600;">Top Suppliers by Spend</h3>
        <?php foreach (array_slice($top_suppliers, 0, 5) as $s): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);">
            <span style="color:var(--text-dark);font-size:13px;"><?= htmlspecialchars($s['supplier_name']) ?></span>
            <span style="font-weight:600;font-size:13px;"><?= fmt($s['total']) ?> <span style="color:var(--text-muted);font-weight:400;">(<?= $s['orders'] ?> POs)</span></span>
        </div>
        <?php endforeach; if (empty($top_suppliers)): ?><p style="color:var(--text-muted);font-size:13px;">No data.</p><?php endif; ?>
    </div>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);"><h3 style="margin:0;font-size:15px;font-weight:600;">All Suppliers</h3></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Supplier</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">POs</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Total Spent</th>
        </tr></thead>
        <tbody>
        <?php foreach ($top_suppliers as $s): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:11px 16px;font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($s['supplier_name']) ?></td>
            <td style="padding:11px 16px;text-align:right;color:var(--text-muted);"><?= $s['orders'] ?></td>
            <td style="padding:11px 16px;text-align:right;font-weight:600;color:var(--text-dark);"><?= fmt($s['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: PROFITABILITY
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'profit'): ?>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
    <?php foreach ([['Gross Margin', $kpi['margin'].'%', $kpi['margin'], $kpi['margin_prev']], ['Gross Profit', fmt($kpi['profit']), $kpi['profit'], $kpi['profit_prev']], ['Revenue', fmt($kpi['revenue']), $kpi['revenue'], $kpi['revenue_prev']]] as [$l,$v,$c,$p]): ?>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:18px;">
        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;"><?= $l ?></div>
        <div style="font-size:24px;font-weight:700;color:var(--text-dark);margin-bottom:5px;"><?= $v ?></div>
        <?= changeBadge($c, $p) ?>
    </div>
    <?php endforeach; ?>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:20px;">
    <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Revenue vs Cost by Category</h3>
    <div style="position:relative;height:260px;"><canvas id="profitCatChart"></canvas></div>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);"><h3 style="margin:0;font-size:15px;font-weight:600;">Profit by Product</h3></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Product</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Units</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Revenue</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Cost</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Profit</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Margin</th>
        </tr></thead>
        <tbody>
        <?php foreach ($profit_products as $p):
            $margin = $p['revenue'] > 0 ? round($p['profit'] / $p['revenue'] * 100, 1) : 0;
        ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:10px 16px;font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($p['product_name']) ?><br><span style="font-size:10px;color:var(--text-muted);"><?= $p['category'] ?></span></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= number_format($p['units']) ?></td>
            <td style="padding:10px 16px;text-align:right;"><?= fmt($p['revenue']) ?></td>
            <td style="padding:10px 16px;text-align:right;color:#ef4444;"><?= fmt($p['cost']) ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:600;color:<?= $p['profit'] >= 0 ? '#10b981' : '#ef4444' ?>;"><?= fmt($p['profit']) ?></td>
            <td style="padding:10px 16px;text-align:right;"><span style="font-weight:600;color:<?= $margin >= 30 ? '#10b981' : ($margin >= 15 ? '#f59e0b' : '#ef4444') ?>;"><?= $margin ?>%</span></td>
        </tr>
        <?php endforeach; if (empty($profit_products)): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">No data</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: INVENTORY
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'inventory'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div>
        <span style="font-size:22px;font-weight:700;color:var(--text-dark);"><?= fmt($kpi['inv_value']) ?></span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Total stock value (cost price × quantity)</div>
    </div>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=inventory" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export Full Inventory CSV
    </a>
</div>

<!-- Category breakdown -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;margin-bottom:20px;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);"><h3 style="margin:0;font-size:15px;font-weight:600;">Stock Value by Category</h3></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Category</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Products</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Units</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Stock Value</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cat_inv as $c): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:10px 16px;font-weight:500;"><?= htmlspecialchars($c['category'] ?: 'Uncategorised') ?></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= $c['products'] ?></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= number_format($c['units']) ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:600;"><?= fmt($c['value']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Low stock -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);background:rgba(245,158,11,.08);">
        <h3 style="margin:0;font-size:15px;font-weight:600;color:#d97706;">⚠ Low Stock (<?= count($low_stock) ?>)</h3>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody>
        <?php foreach ($low_stock as $p): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:9px 14px;">
                <div style="font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($p['product_name']) ?></div>
                <div style="font-size:10px;color:var(--text-muted);"><?= $p['sku_code'] ?> · <?= $p['category'] ?></div>
            </td>
            <td style="padding:9px 14px;text-align:right;">
                <span style="font-weight:700;color:#ef4444;"><?= $p['stock_level'] ?></span>
                <div style="font-size:10px;color:var(--text-muted);">min <?= $p['warning_threshold'] ?></div>
            </td>
        </tr>
        <?php endforeach; if (empty($low_stock)): ?><tr><td colspan="2" style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No low-stock items</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Dead stock -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);background:rgba(239,68,68,.06);">
        <h3 style="margin:0;font-size:15px;font-weight:600;color:#ef4444;">Dead Stock — no sales in period (<?= count($dead_stock) ?>)</h3>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody>
        <?php foreach ($dead_stock as $p): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:9px 14px;">
                <div style="font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($p['product_name']) ?></div>
                <div style="font-size:10px;color:var(--text-muted);"><?= $p['sku_code'] ?> · <?= $p['category'] ?></div>
            </td>
            <td style="padding:9px 14px;text-align:right;">
                <div style="font-weight:600;color:var(--text-dark);"><?= $p['stock_level'] ?> units</div>
                <div style="font-size:10px;color:#ef4444;"><?= fmt($p['trapped_capital']) ?> tied up</div>
            </td>
        </tr>
        <?php endforeach; if (empty($dead_stock)): ?><tr><td colspan="2" style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">All products sold in this period</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: CASH FLOW
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'cashflow'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div>
        <span style="font-size:22px;font-weight:700;color:<?= $kpi['net_cash'] >= 0 ? '#10b981' : '#ef4444' ?>;"><?= fmt($kpi['net_cash']) ?></span>
        <span style="margin-left:10px;"><?= changeBadge($kpi['net_cash'], $kpi['net_cash_prev']) ?></span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Net cash flow · In: <?= fmt($kpi['cash_in']) ?> · Out: <?= fmt($kpi['cash_out']) ?></div>
    </div>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=cashflow" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export Cash Flow CSV
    </a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 14px;font-size:15px;font-weight:600;">Payment Methods (Received)</h3>
        <?php foreach ($payment_methods as $m): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);">
            <span style="color:var(--text-dark);font-size:13px;"><?= htmlspecialchars($m['payment_method']) ?: 'Unknown' ?></span>
            <span style="font-weight:600;font-size:13px;"><?= fmt($m['total']) ?> <span style="color:var(--text-muted);font-weight:400;">(<?= $m['cnt'] ?>)</span></span>
        </div>
        <?php endforeach; if (empty($payment_methods)): ?><p style="color:var(--text-muted);font-size:13px;">No payments received in this period.</p><?php endif; ?>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <h3 style="margin:0 0 4px;font-size:15px;font-weight:600;">Summary</h3>
        <?php foreach ([['Cash In (Payments Received)', $kpi['cash_in'], '#10b981'], ['Cash Out (Payments Made)', $kpi['cash_out'], '#ef4444'], ['Net Position', $kpi['net_cash'], $kpi['net_cash'] >= 0 ? '#10b981' : '#ef4444']] as [$l,$v,$c]): ?>
        <div style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border-color);">
            <span style="font-size:13px;color:var(--text-dark);"><?= $l ?></span>
            <span style="font-weight:700;font-size:15px;color:<?= $c ?>;"><?= fmt($v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Overdue invoices -->
<?php if (!empty($overdue)): ?>
<div style="background:var(--card-bg);border:1px solid #ef4444;border-radius:12px;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);background:rgba(239,68,68,.06);">
        <h3 style="margin:0;font-size:15px;font-weight:600;color:#ef4444;">Overdue Invoices (<?= count($overdue) ?>)</h3>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Invoice</th>
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Customer</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Due Date</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Days Overdue</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Amount</th>
        </tr></thead>
        <tbody>
        <?php foreach ($overdue as $inv): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:10px 16px;font-weight:500;color:var(--text-dark);"><?= $inv['invoice_number'] ?></td>
            <td style="padding:10px 16px;color:var(--text-muted);"><?= htmlspecialchars($inv['customer_name']) ?></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= $inv['due_date'] ?></td>
            <td style="padding:10px 16px;text-align:right;"><span style="color:#ef4444;font-weight:600;"><?= $inv['days_overdue'] ?> days</span></td>
            <td style="padding:10px 16px;text-align:right;font-weight:600;color:#ef4444;"><?= fmt($inv['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: CUSTOMERS
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'customers'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h2 style="margin:0;font-size:18px;font-weight:700;">Customer Performance</h2>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=customers" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;margin-bottom:20px;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);"><h3 style="margin:0;font-size:15px;font-weight:600;">Top Customers by Revenue</h3></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Customer</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Orders</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Total Revenue</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Avg Order</th>
        </tr></thead>
        <tbody>
        <?php foreach ($top_customers as $c): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:10px 16px;font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($c['customer_name']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($c['email']) ?></span></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= $c['orders'] ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:600;color:var(--text-dark);"><?= fmt($c['revenue']) ?></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= fmt($c['avg_order']) ?></td>
        </tr>
        <?php endforeach; if (empty($top_customers)): ?><tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted);">No customer sales in this period</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($cust_balances)): ?>
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);"><h3 style="margin:0;font-size:15px;font-weight:600;">Outstanding Balances</h3></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Customer</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Invoiced</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Paid</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Outstanding</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cust_balances as $c):
            $outstanding = max(0, $c['invoiced'] - $c['paid']); ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:10px 16px;font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($c['customer_name']) ?></td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= fmt($c['invoiced']) ?></td>
            <td style="padding:10px 16px;text-align:right;color:#10b981;"><?= fmt($c['paid']) ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:600;color:<?= $outstanding > 0 ? '#ef4444' : '#10b981' ?>;"><?= fmt($outstanding) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: RETURNS & CREDITS
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'returns'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h2 style="margin:0;font-size:18px;font-weight:700;">Returns &amp; Credits</h2>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=returns" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<!-- ── 4 metric cards ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:16px 18px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px;">Sales Refunds</div>
        <div style="font-size:22px;font-weight:800;color:#ef4444;"><?= fmt($ret_total_sales_refunds) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= (int)$ret_count_sales ?> return<?= $ret_count_sales != 1 ? 's' : '' ?> · customer-side</div>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:16px 18px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px;">Purchase Returns</div>
        <div style="font-size:22px;font-weight:800;color:#ea580c;"><?= fmt($ret_total_purchase_refunds) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= (int)$ret_count_purchase ?> return<?= $ret_count_purchase != 1 ? 's' : '' ?> · supplier-side</div>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:16px 18px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px;">Credit Notes</div>
        <div style="font-size:22px;font-weight:800;color:#f59e0b;"><?= fmt($ret_total_credit_notes) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= (int)$ret_count_credit_notes ?> note<?= $ret_count_credit_notes != 1 ? 's' : '' ?> · issued to customers</div>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:16px 18px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px;">Supplier Credits</div>
        <div style="font-size:22px;font-weight:800;color:#0891b2;"><?= fmt($ret_total_supplier_credits) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= (int)$ret_count_supplier_credits ?> credit<?= $ret_count_supplier_credits != 1 ? 's' : '' ?> · from suppliers</div>
    </div>
</div>

<!-- ── Section label: Customer-side ── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:10px;padding-left:2px;">Customer-Side</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

<!-- Sales Returns -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:12px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;"></span>
        <h3 style="margin:0;font-size:14px;font-weight:600;">Sales Returns</h3>
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);"><?= (int)$ret_count_sales ?> total</span>
    </div>
    <?php if (empty($sales_returns_data)): ?>
        <p style="padding:20px;color:var(--text-muted);font-size:13px;">No sales returns in this period.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody>
        <?php foreach ($sales_returns_data as $r): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:9px 14px;">
                <a href="view_invoice.php?return_id=<?= $r['id'] ?>"
                   style="font-weight:600;color:var(--primary-color);text-decoration:none;font-size:12px;"><?= htmlspecialchars($r['return_id_str']) ?></a>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($r['customer_name']) ?> · <?= date('d M Y', strtotime($r['return_date'])) ?></div>
                <?php if ($r['reason']): ?><div style="font-size:10px;color:var(--text-muted);margin-top:1px;"><?= htmlspecialchars(substr($r['reason'],0,50)) ?></div><?php endif; ?>
            </td>
            <td style="padding:9px 14px;text-align:right;white-space:nowrap;">
                <span style="font-weight:700;color:#ef4444;"><?= fmt($r['total_refund_amount']) ?></span>
                <div style="font-size:10px;margin-top:2px;"><?= statusBadge($r['status']) ?></div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Credit Notes -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:12px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>
        <h3 style="margin:0;font-size:14px;font-weight:600;">Credit Notes</h3>
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);"><?= (int)$ret_count_credit_notes ?> total</span>
    </div>
    <?php if (empty($credit_notes_data)): ?>
        <p style="padding:20px;color:var(--text-muted);font-size:13px;">No credit notes in this period.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody>
        <?php foreach ($credit_notes_data as $cn): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:9px 14px;">
                <a href="view_sales_return_bill.php?cn_id=<?= $cn['id'] ?>" target="_blank"
                   style="font-weight:600;color:#f59e0b;text-decoration:none;font-size:12px;"><?= htmlspecialchars($cn['credit_note_str']) ?></a>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($cn['customer_name']) ?> · <?= date('d M Y', strtotime($cn['issue_date'])) ?></div>
                <?php if ($cn['reason']): ?><div style="font-size:10px;color:var(--text-muted);margin-top:1px;"><?= htmlspecialchars(substr($cn['reason'],0,50)) ?></div><?php endif; ?>
            </td>
            <td style="padding:9px 14px;text-align:right;white-space:nowrap;">
                <span style="font-weight:700;color:#f59e0b;"><?= fmt($cn['credit_amount']) ?></span>
                <div style="font-size:10px;margin-top:2px;"><?= statusBadge($cn['status']) ?></div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</div>

<!-- ── Section label: Supplier-side ── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:10px;padding-left:2px;">Supplier-Side</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

<!-- Purchase Returns -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:12px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#ea580c;display:inline-block;"></span>
        <h3 style="margin:0;font-size:14px;font-weight:600;">Purchase Returns</h3>
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);"><?= (int)$ret_count_purchase ?> total</span>
    </div>
    <?php if (empty($purchase_returns_data)): ?>
        <p style="padding:20px;color:var(--text-muted);font-size:13px;">No purchase returns in this period.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody>
        <?php foreach ($purchase_returns_data as $pr): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:9px 14px;">
                <a href="view_purchase_return_bill.php?pr_id=<?= $pr['id'] ?>" target="_blank"
                   style="font-weight:600;color:#ea580c;text-decoration:none;font-size:12px;"><?= htmlspecialchars($pr['return_number_str']) ?></a>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">
                    <?= htmlspecialchars($pr['supplier_name']) ?> · <?= date('d M Y', strtotime($pr['return_date'])) ?>
                </div>
                <?php if ($pr['po_number']): ?><div style="font-size:10px;color:var(--text-muted);margin-top:1px;">PO: <?= htmlspecialchars($pr['po_number']) ?></div><?php endif; ?>
            </td>
            <td style="padding:9px 14px;text-align:right;white-space:nowrap;">
                <span style="font-weight:700;color:#ea580c;"><?= fmt($pr['amount']) ?></span>
                <div style="font-size:10px;margin-top:2px;"><?= statusBadge($pr['status']) ?></div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Supplier Credits -->
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <div style="padding:12px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#0891b2;display:inline-block;"></span>
        <h3 style="margin:0;font-size:14px;font-weight:600;">Supplier Credits</h3>
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);"><?= (int)$ret_count_supplier_credits ?> total</span>
    </div>
    <?php if (empty($supplier_credits_data)): ?>
        <p style="padding:20px;color:var(--text-muted);font-size:13px;">No supplier credits in this period.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody>
        <?php foreach ($supplier_credits_data as $sc): ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:9px 14px;">
                <a href="view_purchase_return_bill.php?sc_id=<?= $sc['id'] ?>" target="_blank"
                   style="font-weight:600;color:#0891b2;text-decoration:none;font-size:12px;"><?= htmlspecialchars($sc['credit_note_str']) ?></a>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">
                    <?= htmlspecialchars($sc['supplier_name']) ?> · <?= date('d M Y', strtotime($sc['date_issued'])) ?>
                </div>
                <?php if ($sc['po_number']): ?><div style="font-size:10px;color:var(--text-muted);margin-top:1px;">PO: <?= htmlspecialchars($sc['po_number']) ?></div><?php endif; ?>
            </td>
            <td style="padding:9px 14px;text-align:right;white-space:nowrap;">
                <span style="font-weight:700;color:#0891b2;"><?= fmt($sc['amount']) ?></span>
                <div style="font-size:10px;margin-top:2px;"><?= statusBadge($sc['status']) ?></div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</div>

<?php // ══════════════════════════════════════════════════════════════════
// TAB: COUPONS & DISCOUNTS
// ══════════════════════════════════════════════════════════════════════
elseif ($active_tab === 'coupons'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div>
        <span style="font-size:22px;font-weight:700;color:var(--text-dark);"><?= number_format($total_coupon_uses) ?> total uses</span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">across all active coupons</div>
    </div>
    <a href="?from=<?= $from ?>&to=<?= $to ?>&export=coupons" class="btn btn-outline" style="display:flex;align-items:center;gap:6px;font-size:13px;padding:8px 16px;">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:11px;text-transform:uppercase;">
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Code</th>
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Name</th>
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Type</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Value</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Uses</th>
            <th style="padding:10px 16px;text-align:right;font-weight:500;">Limit</th>
            <th style="padding:10px 16px;text-align:center;font-weight:500;">Status</th>
            <th style="padding:10px 16px;text-align:left;font-weight:500;">Expires</th>
        </tr></thead>
        <tbody>
        <?php foreach ($coupons_data as $cp):
            $expired = $cp['end_date'] && $cp['end_date'] < $today;
            $full    = $cp['usage_limit'] && $cp['times_used'] >= $cp['usage_limit'];
            $sc  = $cp['status'] === 'Active' && !$expired && !$full ? '#10b981' : '#9ca3af';
            $sl  = $expired ? 'Expired' : ($full ? 'Limit reached' : $cp['status']);
        ?>
        <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
            <td style="padding:10px 16px;font-weight:700;font-family:monospace;color:var(--primary-color);"><?= htmlspecialchars($cp['code']) ?></td>
            <td style="padding:10px 16px;color:var(--text-dark);"><?= htmlspecialchars($cp['name']) ?></td>
            <td style="padding:10px 16px;color:var(--text-muted);"><?= $cp['type'] ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:600;">
                <?= str_contains(strtolower($cp['type']), 'percent') ? $cp['value'].'%' : fmt($cp['value']) ?>
            </td>
            <td style="padding:10px 16px;text-align:right;">
                <?php if ($cp['usage_limit']): ?>
                    <div style="background:var(--bg-body);border-radius:8px;height:6px;width:70px;display:inline-block;vertical-align:middle;overflow:hidden;">
                        <div style="height:100%;background:var(--primary-color);width:<?= min(100, round($cp['times_used']/$cp['usage_limit']*100)) ?>%;"></div>
                    </div>
                <?php endif; ?>
                <?= $cp['times_used'] ?>
            </td>
            <td style="padding:10px 16px;text-align:right;color:var(--text-muted);"><?= $cp['usage_limit'] ?: '∞' ?></td>
            <td style="padding:10px 16px;text-align:center;"><span style="font-size:11px;font-weight:600;color:<?= $sc ?>;background:<?= $sc === '#10b981' ? 'rgba(16,185,129,.1)' : 'rgba(156,163,175,.1)' ?>;padding:2px 8px;border-radius:10px;"><?= $sl ?></span></td>
            <td style="padding:10px 16px;font-size:12px;color:var(--text-muted);"><?= $cp['end_date'] ?: '–' ?></td>
        </tr>
        <?php endforeach; if (empty($coupons_data)): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);">No coupons created yet</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php endif; // end tab switch ?>

</div><!-- /padding wrapper -->
</main>

<script>
// Chart.js global defaults — uses CSS vars for dark mode compat
Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#9ca3af';
Chart.defaults.borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || '#e5e7eb';

const PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#6d4aff';

<?php if ($active_tab === 'summary' || $active_tab === 'sales'): ?>
// Revenue trend chart
const revenueCtx = document.getElementById('revenueChart') || document.getElementById('salesTrendChart');
if (revenueCtx) {
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($daily_labels) ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= json_encode($daily_rev) ?>,
                borderColor: PRIMARY,
                backgroundColor: PRIMARY + '18',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: <?= count($daily_labels) > 30 ? 0 : 3 ?>,
                pointHoverRadius: 5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() }, grid: { color: 'rgba(0,0,0,.05)' } },
                x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }
            }
        }
    });
}
<?php endif; ?>

<?php if ($active_tab === 'products' || $active_tab === 'profit'): ?>
// Category chart
const catEl = document.getElementById('catChart') || document.getElementById('profitCatChart');
if (catEl) {
    new Chart(catEl, {
        type: 'bar',
        data: {
            labels: <?= json_encode($cat_labels) ?>,
            datasets: [
                { label: 'Revenue', data: <?= json_encode($cat_rev) ?>, backgroundColor: '#10b981', borderRadius: 4 },
                { label: 'Cost',    data: <?= json_encode($cat_cost) ?>, backgroundColor: '#ef4444', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() }, grid: { color: 'rgba(0,0,0,.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
}
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
