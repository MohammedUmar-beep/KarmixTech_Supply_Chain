<?php
require_once 'includes/auth_guard.php';

$order_id    = isset($_GET['order_id'])    ? intval($_GET['order_id'])                         : 0;
$payment_ref = isset($_GET['payment_ref']) ? $conn->real_escape_string($_GET['payment_ref'])   : '';
$return_id   = isset($_GET['return_id'])   ? intval($_GET['return_id'])                        : 0;

$sales_order    = null;
$customer       = null;
$items          = [];
$invoice_number = 'PO-0000';
$due_date       = date('d M, Y', strtotime('+15 days'));
$total_paid     = 0;

// ── Resolve the purchase order from different entry points ─────────────────────
if ($payment_ref !== '') {
    $pay_res = $conn->query("SELECT supplier_id, notes FROM payments_made WHERE payment_ref_str = '$payment_ref'");
    if ($pay_res && $pay_res->num_rows > 0) {
        $pay_row = $pay_res->fetch_assoc();
        if (preg_match('/PO-\\d+/i', $pay_row['notes'] ?? '', $m)) {
            $po_str  = $conn->real_escape_string($m[0]);
            $po_id_r = $conn->query("SELECT id FROM purchase_orders WHERE purchase_id_str = '$po_str'");
            if ($po_id_r && $po_id_r->num_rows > 0) $order_id = (int)$po_id_r->fetch_row()[0];
        }
        if (!$order_id && $pay_row['supplier_id']) {
            $po_fb = $conn->query("SELECT id FROM purchase_orders WHERE supplier_id = " . intval($pay_row['supplier_id']) . " ORDER BY id DESC LIMIT 1");
            if ($po_fb && $po_fb->num_rows > 0) $order_id = (int)$po_fb->fetch_row()[0];
        }
    }
} elseif ($return_id > 0) {
    $ret_res = $conn->query("SELECT return_number_str, purchase_order_id, return_date FROM purchase_returns WHERE id = $return_id");
    if ($ret_res && $ret_res->num_rows > 0) {
        $ret_data       = $ret_res->fetch_assoc();
        $order_id       = (int)$ret_data['purchase_order_id'];
        $invoice_number = 'RET-' . $ret_data['return_number_str'];
        $due_date       = 'N/A (Return)';
    }
}

// ── Fetch core PO ──────────────────────────────────────────────────────────────
if ($order_id > 0) {
    $so_res = $conn->query("SELECT * FROM purchase_orders WHERE id = $order_id");
    if ($so_res && $so_res->num_rows > 0) {
        $sales_order = $so_res->fetch_assoc();
        if ($invoice_number === 'PO-0000') $invoice_number = $sales_order['purchase_id_str'];
        if (!empty($sales_order['expected_date'])) $due_date = date('d M, Y', strtotime($sales_order['expected_date']));

        $cust_res = $conn->query("SELECT * FROM suppliers WHERE id = " . intval($sales_order['supplier_id']));
        if ($cust_res && $cust_res->num_rows > 0) $customer = $cust_res->fetch_assoc();

        $items_res = $conn->query("
            SELECT poi.*, p.product_name, p.description
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = $order_id
        ");
        if ($items_res) while ($row = $items_res->fetch_assoc()) $items[] = $row;

        $pay_agg    = $conn->query("SELECT COALESCE(SUM(amount),0) FROM payments_made WHERE supplier_id = " . intval($sales_order['supplier_id']) . " AND status IN ('Fully Paid','Partially Paid')");
        $total_paid = $pay_agg ? floatval($pay_agg->fetch_row()[0]) : 0;
    }
}

if (!$sales_order) die('Error: Purchase order not found.');

// ── Ledger ─────────────────────────────────────────────────────────────────────
$payments = []; $returns = []; $credits = [];
if ($order_id > 0) {
    $pay_res2 = $conn->query("SELECT * FROM payments_made WHERE supplier_id = " . intval($sales_order['supplier_id']) . " ORDER BY payment_date DESC LIMIT 10");
    if ($pay_res2) while ($r = $pay_res2->fetch_assoc()) $payments[] = $r;

    $ret_res2 = $conn->query("SELECT * FROM purchase_returns WHERE purchase_order_id = $order_id ORDER BY return_date DESC");
    if ($ret_res2) while ($r = $ret_res2->fetch_assoc()) $returns[] = $r;

    $sc_res = $conn->query("SELECT * FROM supplier_credits WHERE supplier_id = " . intval($sales_order['supplier_id']) . " ORDER BY date_issued DESC LIMIT 10");
    if ($sc_res) while ($r = $sc_res->fetch_assoc()) $credits[] = $r;
}

// ── Calculations ───────────────────────────────────────────────────────────────
$subtotal    = array_sum(array_column($items, 'subtotal'));
$shipping    = 0;

// Read tax stored on the order; fall back to live tax_rules for legacy orders
$po_tax_name   = 'Tax';
$tn_res = $conn->query("SELECT tax_name FROM tax_rules WHERE is_active=1 ORDER BY rate_percent DESC LIMIT 1");
if ($tn_res && $tn_res->num_rows > 0) { $po_tax_name = $tn_res->fetch_assoc()['tax_name']; }

$stored_po_tax_rate   = floatval($sales_order['tax_rate']   ?? 0);
$stored_po_tax_amount = floatval($sales_order['tax_amount'] ?? 0);

if ($stored_po_tax_rate > 0) {
    $po_tax_rate   = $stored_po_tax_rate;
    $po_tax_amount = $stored_po_tax_amount;
} else {
    $po_tax_rate   = 0;
    $t_res = $conn->query("SELECT SUM(rate_percent) as r FROM tax_rules WHERE is_active=1");
    if ($t_res) { $po_tax_rate = floatval($t_res->fetch_assoc()['r']); }
    $po_tax_amount = round($subtotal * ($po_tax_rate / 100), 2);
}

$grand_total = $subtotal + $po_tax_amount;
$amount_due  = max(0, $grand_total - $total_paid);
?>
<?php $extra_head = '<style>
        :root {
            --inv-border: var(--border-color, #e2e8f0);
            --inv-text:   var(--text-dark,    #1e293b);
            --inv-muted:  var(--text-muted,   #64748b);
            --inv-purple: var(--primary-color,#7c3aed);
            --inv-bg:     var(--bg-body,      #f8fafc);
            --inv-card:   var(--card-bg,      #ffffff);
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--inv-card);
            padding: 60px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            border-top: 8px solid var(--inv-text);
        }
        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 50px;
        }
        .inv-title {
            font-size: 36px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0 0 5px 0;
        }
        .inv-ref {
            font-size: 15px;
            color: var(--inv-muted);
            margin: 0;
        }
        .inv-logo-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .inv-logo-mark {
            width: 46px;
            height: 46px;
            object-fit: contain;
        }
        .inv-logo-text {
            color: var(--inv-purple);
            font-weight: 700;
            font-size: 16px;
            line-height: 1.15;
            letter-spacing: -0.2px;
        }
        .inv-logo-text span {
            display: block;
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--inv-muted);
            margin-top: 1px;
        }
        .inv-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            border-top: 1px solid var(--inv-border);
            border-bottom: 1px solid var(--inv-border);
            padding: 25px 0;
            margin-bottom: 50px;
        }
        .inv-info-col:not(:last-child) {
            border-right: 1px solid var(--inv-border);
            padding-right: 30px;
        }
        .inv-info-col:not(:first-child) { padding-left: 30px; }
        .inv-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--inv-text);
            margin-bottom: 12px;
            display: block;
        }
        .inv-value {
            font-size: 14px;
            color: var(--inv-muted);
            line-height: 1.6;
        }
        .inv-value strong {
            color: var(--inv-text);
            font-weight: 500;
        }
        .invoice-container table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        .invoice-container th {
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--inv-border);
            color: var(--inv-muted);
        }
        .invoice-container th.right,
        .invoice-container td.right { text-align: right; }
        .invoice-container td {
            padding: 20px 0;
            font-size: 14px;
            border-bottom: 1px solid var(--inv-border);
            color: var(--inv-text);
        }
        td.service-cell .service-name {
            font-weight: 600;
            color: var(--inv-text);
            margin-bottom: 5px;
        }
        td.service-cell .service-desc {
            font-size: 13px;
            color: var(--inv-muted);
        }
        .inv-totals-box {
            width: 350px;
            margin-left: auto;
            margin-bottom: 60px;
        }
        .inv-total-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--inv-text);
        }
        .inv-total-row:not(:last-child) { border-bottom: 1px solid var(--inv-border); }
        .inv-total-row.amount-due {
            border-top: 2px solid var(--inv-purple);
            border-bottom: 2px solid var(--inv-purple);
            color: var(--inv-purple);
            padding: 20px 0;
        }
        .inv-footer {
            border-top: 1px solid var(--inv-border);
            padding-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .footer-thanks { font-weight: 700; font-size: 14px; margin-bottom: 8px; }
        .footer-note {
            font-size: 13px;
            color: var(--inv-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .footer-contact {
            text-align: right;
            font-size: 13px;
            color: var(--inv-muted);
        }
        .footer-contact span { margin-left: 15px; }
        @media print {
            .topbar, .sidebar, nav { display: none !important; }
            .main-area { margin: 0 !important; padding: 0 !important; }
            .invoice-container {
                box-shadow: none;
                max-width: 100%;
                border-top: 8px solid black;
                padding: 40px;
            }
        }
    </style>';
?>
<?php include 'includes/header.php'; ?>
    <main class="main-area">
        <div class="topbar" style="margin-bottom: 20px;">
            <div class="topbar-left">
                <a href="purchase_orders.php"
                    style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    Back to Purchase Hub
                </a>
                <h1>View Purchase Order</h1>
            </div>
            <?php include 'includes/topbar_right.php'; ?>
        </div>

        <div class="invoice-container">

            <div class="inv-header">
                <div>
                    <h1 class="inv-title">PURCHASE ORDER</h1>
                    <p class="inv-ref">#
                        <?= htmlspecialchars($sales_order['purchase_id_str'] ?? 'Unknown') ?>
                    </p>
                </div>
                <div class="inv-logo-box">
                    <img src="assets/images/logo.png" alt="Agile Logo" class="inv-logo-mark">
                    <div class="inv-logo-text">
                        Agile Inventory<span>System</span>
                    </div>
                </div>
            </div>

            <div class="inv-info-grid">
                <div class="inv-info-col">
                    <span class="inv-label">Issued</span>
                    <div class="inv-value" style="margin-bottom: 15px;">
                        <?= date('d M, Y', strtotime($sales_order['order_date'])) ?>
                    </div>

                    <span class="inv-label">Due</span>
                    <div class="inv-value">
                        <?= $due_date ?>
                    </div>
                </div>

                <div class="inv-info-col">
                    <span class="inv-label">Supplier</span>
                    <div class="inv-value">
                        <strong>
                            <?= htmlspecialchars($customer['supplier_name'] ?? 'Walk-in') ?>
                        </strong><br>
                        <?= htmlspecialchars($sales_order['shipping_address'] ?? 'No Address Provided') ?><br>
                        <?= htmlspecialchars($customer['contact_number'] ?? '') ?>
                    </div>
                </div>

                <div class="inv-info-col">
                    <span class="inv-label">From</span>
                    <div class="inv-value">
                        <strong>Agile Inventory, Inc</strong><br>
                        123 Business Avenue<br>
                        Tech City, State, IN - 000 000<br>
                        TAX ID: 00XXXXXX1234X0XX
                    </div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Service</th>
                        <th style="width: 80px;">Qty</th>
                        <th class="right" style="width: 120px;">Rate</th>
                        <th class="right" style="width: 150px;">Line total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="service-cell">
                                <div class="service-name">
                                    <?= htmlspecialchars($item['product_name'] ?? 'Item') ?>
                                </div>
                                <div class="service-desc">
                                    <?= htmlspecialchars($item['description'] ?? 'Product sale') ?>
                                </div>
                            </td>
                            <td>
                                <?= $item['quantity'] ?>
                            </td>
                            <td class="right">$
                                <?= number_format($item['unit_price'], 2) ?>
                            </td>
                            <td class="right">$
                                <?= number_format($item['subtotal'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($shipping > 0): ?>
                        <tr>
                            <td class="service-cell">
                                <div class="service-name">Shipping Cost</div>
                                <div class="service-desc">Vendor shipping charges</div>
                            </td>
                            <td>1</td>
                            <td class="right">$
                                <?= number_format($shipping, 2) ?>
                            </td>
                            <td class="right">$
                                <?= number_format($shipping, 2) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="inv-totals-box">
                <div class="inv-total-row">
                    <span>Subtotal</span>
                    <span>$
                        <?= number_format($subtotal + $shipping, 2) ?>
                    </span>
                </div>
                <?php if ($po_tax_rate > 0): ?>
                <div class="inv-total-row">
                    <span><?= htmlspecialchars($po_tax_name) ?> (<?= $po_tax_rate ?>%)</span>
                    <span>$<?= number_format($po_tax_amount, 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="inv-total-row">
                    <span>Total</span>
                    <span>$
                        <?= number_format($grand_total, 2) ?>
                    </span>
                </div>

                <?php if ($total_paid > 0): ?>
                    <div class="inv-total-row" style="color:var(--inv-muted); font-weight:400;">
                        <span>Amount Paid</span>
                        <span>-$
                            <?= number_format($total_paid, 2) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="inv-total-row amount-due">
                    <span>Amount due</span>
                    <span>US$
                        <?= number_format($amount_due, 2) ?>
                    </span>
                </div>
            </div>

            <div class="inv-footer">
                <div>
                    <div class="footer-thanks">Entity View Generated</div>
                    <div class="footer-note">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"
                            style="color:var(--inv-muted);">
                            <path
                                d="M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z">
                            </path>
                        </svg>
                        This document merges the order, items, and all related lifecycle transactions.
                    </div>
                </div>
                <div class="footer-contact">
                    Agile Inventory
                </div>
            </div>

            <!-- NEW LEDGER SECTION -->
            <div class="ledger-section">
                <h2 class="ledger-title">Transaction Ledger</h2>

                <?php if (count($payments) > 0): ?>
                    <h3 style="font-size:15px; margin-bottom:10px;">Payments Made</h3>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Method</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['payment_date']) ?></td>
                                    <td><a href="payments_made.php"
                                            style="color:var(--primary-color); text-decoration:none;"><?= htmlspecialchars($p['payment_ref_str']) ?></a>
                                    </td>
                                    <td><span class="badge blue"><?= htmlspecialchars($p['payment_method']) ?></span></td>
                                    <td style="font-weight:600; color:#166534;">+$<?= number_format($p['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (count($returns) > 0): ?>
                    <h3 style="font-size:15px; margin-bottom:10px; margin-top:20px;">Purchase Returns</h3>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Return #</th>
                                <th>Status</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returns as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['return_date']) ?></td>
                                    <td><a href="purchase_returns.php"
                                            style="color:var(--primary-color); text-decoration:none;">RET-<?= htmlspecialchars($r['return_id_str'] ?? '') ?></a>
                                    </td>
                                    <td><span class="badge yellow"><?= htmlspecialchars($r['status']) ?></span></td>
                                    <td style="font-weight:600; color:#991b1b;">-$<?= number_format($r['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (count($credits) > 0): ?>
                    <h3 style="font-size:15px; margin-bottom:10px; margin-top:20px;">Supplier Credits</h3>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Credit #</th>
                                <th>Status</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($credits as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['date_issued']) ?></td>
                                    <td><a href="supplier_credits.php"
                                            style="color:var(--primary-color); text-decoration:none;"><?= htmlspecialchars($c['credit_note_str']) ?></a>
                                    </td>
                                    <td><span class="badge"><?= htmlspecialchars($c['status']) ?></span></td>
                                    <td style="font-weight:600;">$<?= number_format($c['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (count($payments) == 0 && count($returns) == 0 && count($credits) == 0): ?>
                    <div
                        style="padding: 30px; text-align:center; background:var(--inv-bg); border:1px solid var(--inv-border); border-radius:8px; color:var(--inv-muted); font-size:14px;">
                        No payments, returns, or credit notes found for this order.
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
    <?php include 'includes/footer.php'; ?>