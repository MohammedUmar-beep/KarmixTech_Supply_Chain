<?php
require_once 'includes/auth_guard.php';

$order_id    = isset($_GET['order_id'])    ? intval($_GET['order_id'])                       : 0;
$payment_ref = isset($_GET['payment_ref']) ? $conn->real_escape_string($_GET['payment_ref']) : '';
$return_id   = isset($_GET['return_id'])   ? intval($_GET['return_id'])                      : 0;

// Resolve invoice_id → order_id (used when navigating from the Invoices table)
if ($order_id === 0 && $payment_ref === '' && $return_id === 0 && isset($_GET['invoice_id'])) {
    $inv_lookup_id = intval($_GET['invoice_id']);
    $inv_lookup = $conn->query("SELECT sales_order_id, invoice_number, due_date FROM invoices WHERE id = $inv_lookup_id");
    if ($inv_lookup && $inv_lookup->num_rows > 0) {
        $inv_lookup_row = $inv_lookup->fetch_assoc();
        $order_id       = intval($inv_lookup_row['sales_order_id'] ?? 0);
        $invoice_number = $inv_lookup_row['invoice_number'];
        $due_date       = date('d M Y', strtotime($inv_lookup_row['due_date']));
    }
}

$sales_order    = null;
$customer       = null;
$items          = [];
$installments   = [];
$inv_id         = null;
$invoice_number = 'INV-0000';
$due_date       = date('d M Y', strtotime('+15 days'));
$total_paid     = 0;

// ── Resolve document reference ────────────────────────────────────────────────
if ($payment_ref !== '') {
    $pay_res = $conn->query("SELECT invoice_id, notes FROM payments_received WHERE payment_ref_str = '$payment_ref'");
    if ($pay_res && $pay_res->num_rows > 0) {
        $pay_row = $pay_res->fetch_assoc();
        $inv_id_tmp = $pay_row['invoice_id'];
        if ($inv_id_tmp) {
            $inv_res = $conn->query("SELECT sales_order_id, invoice_number, due_date FROM invoices WHERE id = $inv_id_tmp");
            if ($inv_res && $inv_res->num_rows > 0) {
                $inv_data       = $inv_res->fetch_assoc();
                $order_id       = $inv_data['sales_order_id'] ?? 0;
                $invoice_number = $inv_data['invoice_number'];
                $due_date       = date('d M Y', strtotime($inv_data['due_date']));
            }
        } elseif (preg_match('/SO-\d+/', $pay_row['notes'] ?? '', $m)) {
            $so_str    = $conn->real_escape_string($m[0]);
            $so_res_id = $conn->query("SELECT id FROM sales_orders WHERE order_id_str = '$so_str'");
            if ($so_res_id && $so_res_id->num_rows > 0) $order_id = $so_res_id->fetch_row()[0];
        }
    }
} elseif ($return_id > 0) {
    $ret_res = $conn->query("SELECT return_id_str, sales_order_id FROM sales_returns WHERE id = $return_id");
    if ($ret_res && $ret_res->num_rows > 0) {
        $ret_data       = $ret_res->fetch_assoc();
        $order_id       = $ret_data['sales_order_id'];
        $invoice_number = 'RET-' . $ret_data['return_id_str'];
        $due_date       = 'N/A (Refund)';
    }
}

// ── Fetch Sales Order ─────────────────────────────────────────────────────────
if ($order_id > 0) {
    $so_res = $conn->query("SELECT * FROM sales_orders WHERE id = $order_id");
    if ($so_res && $so_res->num_rows > 0) {
        $sales_order = $so_res->fetch_assoc();
        if ($invoice_number === 'INV-0000') $invoice_number = 'SO-' . $sales_order['order_id_str'];

        $cust_res = $conn->query("SELECT * FROM customers WHERE id = " . intval($sales_order['customer_id']));
        if ($cust_res && $cust_res->num_rows > 0) $customer = $cust_res->fetch_assoc();

        $items_res = $conn->query("
            SELECT soi.*, p.product_name, p.description
            FROM sales_order_items soi
            LEFT JOIN products p ON soi.product_id = p.id
            WHERE soi.sales_order_id = $order_id
        ");
        if ($items_res) while ($r = $items_res->fetch_assoc()) $items[] = $r;

        $inv_row = $conn->query("SELECT id, invoice_number, due_date FROM invoices WHERE sales_order_id = $order_id LIMIT 1");
        if ($inv_row && $inv_row->num_rows > 0) {
            $inv_data       = $inv_row->fetch_assoc();
            $inv_id         = $inv_data['id'];
            $invoice_number = $inv_data['invoice_number'];
            $due_date       = date('d M Y', strtotime($inv_data['due_date']));
            $inst_res       = $conn->query("SELECT * FROM installments WHERE invoice_id = $inv_id ORDER BY created_at ASC");
            if ($inst_res) while ($r = $inst_res->fetch_assoc()) { $installments[] = $r; $total_paid += floatval($r['amount']); }
        }
    }
}
if (!$sales_order) die("Error: Document not found.");

// ── Business settings ─────────────────────────────────────────────────────────
$biz = get_business_settings($conn);

// ── Tax ───────────────────────────────────────────────────────────────────────
// Prefer the tax_rate / tax_amount that were stored on the order at the time of
// sale (added by the tax-fix migration).  For legacy orders that predate the
// migration those columns are absent / zero, so we fall back to re-deriving the
// tax from the current tax_rules — same behaviour as before the fix.
$tax_name = 'Tax';
$tr_name_res = $conn->query("SELECT tax_name FROM tax_rules WHERE is_active = 1 ORDER BY rate_percent DESC LIMIT 1");
if ($tr_name_res && $tr_name_res->num_rows > 0) { $tax_name = $tr_name_res->fetch_assoc()['tax_name']; }

// ── Calculations ──────────────────────────────────────────────────────────────
$subtotal   = array_sum(array_column($items, 'subtotal'));
$shipping   = floatval($sales_order['delivery_charge'] ?? 0);
$discount   = floatval($sales_order['discount_amount'] ?? 0);

// Use stored tax values when available (non-zero rate saved on the order)
$stored_tax_rate   = floatval($sales_order['tax_rate']   ?? 0);
$stored_tax_amount = floatval($sales_order['tax_amount'] ?? 0);

if ($stored_tax_rate > 0) {
    // New orders — use exactly what was charged at sale time
    $tax_rate   = $stored_tax_rate;
    $tax_amount = $stored_tax_amount;
} else {
    // Legacy orders — re-derive from current tax_rules (original behaviour)
    $tax_rate = 0;
    $tax_res  = $conn->query("SELECT SUM(rate_percent) as r FROM tax_rules WHERE is_active = 1");
    if ($tax_res) { $tax_rate = floatval($tax_res->fetch_assoc()['r']); }
    $tax_amount = round(($subtotal - $discount) * ($tax_rate / 100), 2);
}

$grand_total = $subtotal - $discount + $tax_amount + $shipping;
$amount_due  = max(0, $grand_total - $total_paid);

// Payment status for badge
if ($amount_due <= 0)       $pay_status = 'Paid';
elseif ($total_paid > 0)    $pay_status = 'Partial';
elseif (strtotime($due_date) < time() && $due_date !== 'N/A (Refund)') $pay_status = 'Overdue';
else                        $pay_status = 'Pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($invoice_number) ?></title>
<script>
(function(){
    var t=localStorage.getItem('agile_theme')||'light',c=localStorage.getItem('agile_color')||'#6d4aff';
    document.documentElement.setAttribute('data-theme',t);
    if(c==='monochrome'){document.documentElement.style.setProperty('--primary-color',t==='dark'?'#fff':'#000');}
    else{document.documentElement.style.setProperty('--primary-color',c);}
})();
</script>
<link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
<style>
:root {
    --c-accent: var(--primary-color, #6d4aff);
    --c-text:   var(--text-dark, #0f172a);
    --c-muted:  var(--text-muted, #64748b);
    --c-border: var(--border-color, #e2e8f0);
    --c-bg:     var(--bg-light, #f8fafc);
    --c-white:  var(--card-bg, #ffffff);
    --c-green:  #059669;
    --c-red:    #dc2626;
    --c-amber:  #d97706;
}
*, *::before, *::after { box-sizing: border-box; }
.doc-topbar {
    max-width: 840px; margin: 24px auto 16px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; padding: 0 16px;
}
.doc-topbar a, .doc-topbar button {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 600; text-decoration: none;
    padding: 8px 16px; border-radius: 8px; cursor: pointer;
    border: 1.5px solid var(--border-color); background: var(--card-bg); color: var(--text-dark);
    transition: border-color 0.15s;
}
.doc-topbar a:hover, .doc-topbar button:hover { border-color: var(--primary-color); color: var(--primary-color); }
.doc-topbar .btn-primary { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
.doc-topbar .btn-primary:hover { opacity: 0.88; color: #fff; }
.doc-topbar-right { display: flex; gap: 10px; }

.invoice-wrap { max-width: 840px; margin: 0 auto 60px; padding: 0 16px; }
.invoice-card {
    background: var(--c-white);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 4px 28px rgba(0,0,0,0.08);
}

/* Colored top stripe */
.inv-stripe {
    height: 6px;
    background: linear-gradient(90deg, var(--c-accent), #a78bfa);
}

.inv-body { padding: 52px 56px; }

/* ── Header ── */
.inv-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 44px;
}
.inv-title-block h1 { font-size: 38px; font-weight: 800; letter-spacing: -1px; color: var(--c-text); margin: 0 0 6px; }
.inv-title-block p  { font-size: 14px; color: var(--c-muted); margin: 0; }
.inv-logo {
    display: flex; align-items: center; gap: 12px;
}
.inv-logo img { width: 40px; height: 40px; object-fit: contain; }
.inv-logo-fallback {
    width: 44px; height: 44px; border-radius: 10px;
    background: var(--c-accent); color: #fff;
    font-size: 20px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
}
.inv-logo-text { line-height: 1.2; }
.inv-logo-text strong { display: block; font-size: 15px; font-weight: 700; color: var(--c-text); }
.inv-logo-text span { font-size: 11px; color: var(--c-muted); }

/* ── Status chip ── */
.inv-status {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; padding: 5px 12px;
    border-radius: 20px; letter-spacing: 0.3px;
}
.inv-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status-Paid    { background: rgba(5,150,105,0.1);  color: var(--c-green); border: 1px solid rgba(5,150,105,0.2); }
.status-Partial { background: rgba(109,74,255,0.1); color: var(--c-accent); border: 1px solid rgba(109,74,255,0.2); }
.status-Overdue { background: rgba(220,38,38,0.1);  color: var(--c-red);   border: 1px solid rgba(220,38,38,0.2); }
.status-Pending { background: rgba(217,119,6,0.1);  color: var(--c-amber); border: 1px solid rgba(217,119,6,0.2); }

/* ── Meta strip ── */
.inv-meta {
    display: grid; grid-template-columns: repeat(3, 1fr);
    border: 1px solid var(--c-border); border-radius: 10px;
    overflow: hidden; margin-bottom: 40px;
}
.inv-meta-cell {
    padding: 18px 20px;
    border-right: 1px solid var(--c-border);
}
.inv-meta-cell:last-child { border-right: none; }
.inv-meta-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--c-muted); margin-bottom: 6px; }
.inv-meta-value { font-size: 14px; font-weight: 600; color: var(--c-text); line-height: 1.5; }
.inv-meta-value.muted { font-weight: 400; color: var(--c-muted); }

/* ── Party grid ── */
.inv-parties {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 32px; margin-bottom: 40px;
}
.party-block-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--c-accent); margin-bottom: 10px;
}
.party-name { font-size: 16px; font-weight: 700; color: var(--c-text); margin-bottom: 4px; }
.party-detail { font-size: 13px; color: var(--c-muted); line-height: 1.7; }

/* ── Line items table ── */
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
.inv-table thead tr { border-bottom: 2px solid var(--c-border); }
.inv-table th {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.7px; color: var(--c-muted);
    padding: 10px 12px 12px;
    text-align: left;
}
.inv-table th.r, .inv-table td.r { text-align: right; }
.inv-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.inv-table tbody tr:last-child { border-bottom: none; }
.inv-table td { padding: 16px 12px; font-size: 14px; color: var(--c-text); vertical-align: top; }
.item-name { font-weight: 600; margin-bottom: 3px; }
.item-desc { font-size: 12px; color: var(--c-muted); }

/* ── Totals ── */
.inv-totals-wrap {
    display: flex; justify-content: flex-end;
    border-top: 1px solid var(--c-border);
    padding-top: 24px; margin-top: 8px;
}
.inv-totals { width: 320px; }
.total-row {
    display: flex; justify-content: space-between;
    align-items: center; padding: 9px 0;
    font-size: 14px; color: var(--c-text);
    border-bottom: 1px solid #f1f5f9;
}
.total-row:last-child { border-bottom: none; }
.total-row.discount { color: var(--c-green); }
.total-row.grand {
    font-size: 16px; font-weight: 800; padding: 14px 0 10px;
    border-bottom: none;
}
.total-row.amount-due {
    background: var(--c-accent); color: #fff;
    padding: 14px 16px; border-radius: 8px; font-weight: 700;
    margin-top: 8px; border-bottom: none;
}

/* ── Installments history ── */
.inst-section {
    margin-top: 40px;
    border-top: 1px solid var(--c-border);
    padding-top: 28px;
}
.inst-section h3 { font-size: 15px; font-weight: 700; margin: 0 0 16px; color: var(--c-text); }
.inst-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.inst-table th { text-align: left; padding: 8px 12px; background: var(--c-bg); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--c-muted); border-bottom: 1px solid var(--c-border); }
.inst-table th.r, .inst-table td.r { text-align: right; }
.inst-table td { padding: 12px 12px; border-bottom: 1px solid #f1f5f9; color: var(--c-text); }
.inst-table tr:last-child td { border-bottom: none; }
.inst-badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(109,74,255,0.1); color: var(--c-accent); border: 1px solid rgba(109,74,255,0.2); }
.inst-amount { font-weight: 600; color: var(--c-green); }

/* ── Footer ── */
.inv-footer {
    display: flex; justify-content: space-between; align-items: flex-end;
    margin-top: 44px; padding-top: 24px;
    border-top: 1px solid var(--c-border);
}
.inv-footer-note { font-size: 13px; color: var(--c-muted); max-width: 380px; line-height: 1.6; }
.inv-footer-note strong { display: block; font-size: 14px; color: var(--c-text); margin-bottom: 4px; }
.inv-footer-contact { text-align: right; font-size: 12px; color: var(--c-muted); line-height: 1.8; }
.inv-footer-contact strong { display: block; font-size: 14px; color: var(--c-text); margin-bottom: 2px; }

/* ── Modal ── */
.modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); overflow-y: auto; }
.modal-box { background: var(--c-white); border-radius: 12px; padding: 32px; margin: 60px auto; width: 90%; max-width: 440px; }
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; }
.modal-head h2 { margin: 0; font-size: 18px; font-weight: 700; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--c-muted); line-height: 1; }
.mform-group { margin-bottom: 16px; }
.mform-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.mform-group input, .mform-group select, .mform-group textarea {
    width: 100%; padding: 9px 12px; border: 1px solid var(--c-border);
    border-radius: 8px; font-size: 14px; font-family: inherit; outline: none;
}
.mform-group input:focus, .mform-group select:focus { border-color: var(--c-accent); }
.mform-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.mform-footer button { padding: 9px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1.5px solid var(--c-border); background: var(--c-white); color: var(--c-text); }
.mform-footer .btn-confirm { background: var(--c-accent); color: #fff; border-color: var(--c-accent); }

/* ── Print ── */
@media print {
    body { background: white; padding: 0; font-size: 13px; }
    .doc-topbar, .modal { display: none !important; }
    .invoice-card { box-shadow: none; border-radius: 0; }
    .inv-body { padding: 32px 40px; }
    .inv-stripe { height: 4px; }
    .inv-meta-value, .inv-table td { font-size: 13px; }
}
@media (max-width: 600px) {
    .inv-body { padding: 28px 20px; }
    .inv-header { flex-direction: column-reverse; gap: 20px; }
    .inv-meta, .inv-parties { grid-template-columns: 1fr; }
    .inv-meta-cell { border-right: none; border-bottom: 1px solid var(--c-border); }
    .inv-totals { width: 100%; }
    .inv-footer { flex-direction: column; gap: 20px; }
}
</style>
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="main-area" style="padding-bottom:60px;">

<!-- Top action bar (hidden on print) -->
<div class="doc-topbar">
    <a href="javascript:history.back()">
        <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back
    </a>
    <div class="doc-topbar-right">
        <?php if ($amount_due > 0 && $inv_id): ?>
        <button onclick="document.getElementById('installmentModal').style.display='flex'">
            <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Record Payment
        </button>
        <?php endif; ?>
        <a href="export_pdf.php?type=invoice&order_id=<?= $order_id ?>" class="btn-primary">
            <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Export PDF
        </a>
    </div>
</div>

<div class="invoice-wrap">
    <div class="invoice-card">
        <div class="inv-stripe"></div>
        <div class="inv-body">

        <!-- Header -->
        <div class="inv-header">
            <div class="inv-title-block">
                <h1>INVOICE</h1>
                <p>#<?= htmlspecialchars($invoice_number) ?> &nbsp;·&nbsp;
                    <span class="inv-status status-<?= $pay_status ?>"><?= $pay_status ?></span>
                </p>
            </div>
            <div class="inv-logo">
                <?php
                $logo_path = __DIR__ . '/assets/images/logo.png';
                if (file_exists($logo_path)): ?>
                    <img src="assets/images/logo.png" alt="Logo">
                <?php else: ?>
                    <div class="inv-logo-fallback"><?= strtoupper(substr($biz['company_name'], 0, 1)) ?></div>
                <?php endif; ?>
                <div class="inv-logo-text">
                    <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                    <span><?= htmlspecialchars($biz['company_website']) ?></span>
                </div>
            </div>
        </div>

        <!-- Meta strip: dates + status -->
        <div class="inv-meta">
            <div class="inv-meta-cell">
                <div class="inv-meta-label">Issue Date</div>
                <div class="inv-meta-value"><?= date('d M Y', strtotime($sales_order['order_date'])) ?></div>
            </div>
            <div class="inv-meta-cell">
                <div class="inv-meta-label">Due Date</div>
                <div class="inv-meta-value"><?= $due_date ?></div>
            </div>
            <div class="inv-meta-cell">
                <div class="inv-meta-label">Amount Due</div>
                <div class="inv-meta-value" style="color:var(--c-accent); font-size:18px;">
                    $<?= number_format($amount_due, 2) ?>
                </div>
            </div>
        </div>

        <!-- Billed To / From -->
        <div class="inv-parties">
            <div>
                <div class="party-block-label">Billed To</div>
                <div class="party-name"><?= htmlspecialchars($customer['customer_name'] ?? 'Walk-in Customer') ?></div>
                <div class="party-detail">
                    <?= nl2br(htmlspecialchars($sales_order['shipping_address'] ?? '')) ?>
                    <?php if (!empty($customer['contact_number'])): ?><br><?= htmlspecialchars($customer['contact_number']) ?><?php endif; ?>
                    <?php if (!empty($customer['email'])): ?><br><?= htmlspecialchars($customer['email']) ?><?php endif; ?>
                </div>
            </div>
            <div>
                <div class="party-block-label">From</div>
                <div class="party-name"><?= htmlspecialchars($biz['company_name']) ?></div>
                <div class="party-detail">
                    <?= htmlspecialchars($biz['company_address']) ?><br>
                    <?= htmlspecialchars($biz['company_city']) ?>, <?= htmlspecialchars($biz['company_state']) ?> – <?= htmlspecialchars($biz['company_pincode']) ?><br>
                    <?= htmlspecialchars($biz['company_country']) ?><br>
                    <?= htmlspecialchars($biz['company_phone']) ?><br>
                    <?= htmlspecialchars($biz['company_email']) ?><br>
                    GST: <?= htmlspecialchars($biz['company_tax_id']) ?>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Item / Service</th>
                    <th class="r" style="width:70px;">Qty</th>
                    <th class="r" style="width:110px;">Rate</th>
                    <th class="r" style="width:120px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <div class="item-name"><?= htmlspecialchars($item['product_name'] ?? 'Item') ?></div>
                        <?php if (!empty($item['description'])): ?>
                        <div class="item-desc"><?= htmlspecialchars($item['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="r"><?= htmlspecialchars($item['quantity']) ?></td>
                    <td class="r">$<?= number_format($item['unit_price'], 2) ?></td>
                    <td class="r">$<?= number_format($item['subtotal'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($shipping > 0): ?>
                <tr>
                    <td><div class="item-name">Delivery Charge</div></td>
                    <td class="r">1</td>
                    <td class="r">$<?= number_format($shipping, 2) ?></td>
                    <td class="r">$<?= number_format($shipping, 2) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="inv-totals-wrap">
            <div class="inv-totals">
                <div class="total-row">
                    <span>Subtotal</span>
                    <span>$<?= number_format($subtotal, 2) ?></span>
                </div>
                <?php if ($discount > 0): ?>
                <div class="total-row discount">
                    <span>Discount</span>
                    <span>−$<?= number_format($discount, 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($shipping > 0): ?>
                <div class="total-row">
                    <span>Shipping</span>
                    <span>$<?= number_format($shipping, 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row">
                    <span><?= htmlspecialchars($tax_name) ?> (<?= $tax_rate ?>%)</span>
                    <span>$<?= number_format($tax_amount, 2) ?></span>
                </div>
                <div class="total-row grand">
                    <span>Total</span>
                    <span>$<?= number_format($grand_total, 2) ?></span>
                </div>
                <?php if ($total_paid > 0): ?>
                <div class="total-row" style="color:var(--c-green);">
                    <span>Amount Paid</span>
                    <span>−$<?= number_format($total_paid, 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($amount_due > 0): ?>
                <div class="total-row amount-due">
                    <span>Amount Due</span>
                    <span>$<?= number_format($amount_due, 2) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Installment History -->
        <?php if (!empty($installments)): ?>
        <div class="inst-section">
            <h3>Payment History</h3>
            <table class="inst-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Notes</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($installments as $inst): ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($inst['payment_date'])) ?></td>
                        <td><span class="inst-badge"><?= htmlspecialchars($inst['payment_method']) ?></span></td>
                        <td style="color:var(--c-muted);"><?= htmlspecialchars($inst['notes'] ?? '—') ?></td>
                        <td class="r inst-amount">+$<?= number_format($inst['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="inv-footer">
            <div class="inv-footer-note">
                <strong>Thank you for your business!</strong>
                <?= nl2br(htmlspecialchars($biz['invoice_notes'])) ?>
            </div>
            <div class="inv-footer-contact">
                <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                <?= htmlspecialchars($biz['company_phone']) ?><br>
                <?= htmlspecialchars($biz['company_email']) ?><br>
                <?= htmlspecialchars($biz['company_website']) ?>
            </div>
        </div>

    </div><!-- /inv-body -->
    </div><!-- /invoice-card -->
</div><!-- /invoice-wrap -->

<!-- Record Payment Modal -->
<?php if ($amount_due > 0 && $inv_id): ?>
<div id="installmentModal" class="modal" style="display:none; align-items:flex-start; justify-content:center;">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Record Payment</h2>
            <button class="modal-close" onclick="document.getElementById('installmentModal').style.display='none'">&times;</button>
        </div>
        <form action="add_installment.php" method="POST">
            <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($inv_id) ?>">
            <div class="mform-group">
                <label>Amount (Max: $<?= number_format($amount_due, 2) ?>)</label>
                <input type="number" step="0.01" min="0.01" max="<?= $amount_due ?>" name="amount" value="<?= $amount_due ?>" required>
            </div>
            <div class="mform-group">
                <label>Payment Date</label>
                <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="mform-group">
                <label>Payment Method</label>
                <select name="payment_method" required>
                    <option>Cash</option>
                    <option>Bank Transfer</option>
                    <option>Credit Card</option>
                    <option>Cheque</option>
                    <option>UPI</option>
                </select>
            </div>
            <div class="mform-group">
                <label>Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="Reference number, UTR, etc."></textarea>
            </div>
            <div class="mform-footer">
                <button type="button" onclick="document.getElementById('installmentModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-confirm">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const p = new URLSearchParams(window.location.search);
if (p.has('print')) window.addEventListener('load', () => window.print());
</script>

</main>
</div><!-- /app-container -->
<?php include 'includes/footer.php'; ?>
