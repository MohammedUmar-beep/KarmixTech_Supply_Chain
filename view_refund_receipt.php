<?php
require_once 'includes/auth_guard.php';

$sr_id = isset($_GET['sr_id']) ? intval($_GET['sr_id']) : 0;
if (!$sr_id) { header("Location: sales_returns.php"); exit(); }

// ── Fetch Sales Return ────────────────────────────────────────────────────────
$res = $conn->query("
    SELECT sr.*,
           c.customer_name, c.email, c.contact_number, c.address,
           so.order_id_str AS so_number,
           (SELECT inv.invoice_number FROM invoices inv
            WHERE inv.sales_order_id = sr.sales_order_id
            ORDER BY inv.created_at DESC LIMIT 1) AS invoice_number
    FROM sales_returns sr
    LEFT JOIN customers c  ON sr.customer_id  = c.id
    LEFT JOIN sales_orders so ON sr.sales_order_id = so.id
    WHERE sr.id = $sr_id
");
if (!$res || $res->num_rows === 0) { echo "Sales return not found."; exit(); }
$row = $res->fetch_assoc();

// ── Fetch line items ──────────────────────────────────────────────────────────
$items_res = $conn->query("
    SELECT sri.*, p.product_name, p.product_id_str
    FROM sales_return_items sri
    LEFT JOIN products p ON sri.product_id = p.id
    WHERE sri.sales_return_id = $sr_id
");
$items = [];
if ($items_res) { while ($item = $items_res->fetch_assoc()) $items[] = $item; }

$ref_number   = $row['return_id_str'];
$return_date  = $row['return_date'];
$refund_total = floatval($row['total_refund_amount']);
$status       = $row['status'];
$reason       = $row['reason'] ?? '';
$so_number    = $row['so_number'] ?? '';
$inv_number   = $row['invoice_number'] ?? '';
$customer     = $row;

$biz = get_business_settings($conn);

// Currency (from v6 global helper)
if (!isset($currency)) $currency = get_currency($conn);
$sym = $currency['symbol'] ?? '₹';

$status_map = ['Pending' => 'pending', 'Processed' => 'completed', 'Rejected' => 'cancelled', 'Approved' => 'completed'];
$badge_cls  = $status_map[$status] ?? 'pending';

$page_title = 'Refund Receipt – ' . htmlspecialchars($ref_number);
include 'includes/header.php';
?>
<style>
:root {
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
.doc-topbar .btn-primary:hover { opacity: .88; color: #fff; }
.doc-topbar-right { display: flex; gap: 10px; }

.receipt-wrap { max-width: 840px; margin: 0 auto 60px; padding: 0 16px; }
.receipt-card {
    background: var(--c-white); border-radius: 14px; overflow: hidden;
    box-shadow: 0 4px 28px rgba(0,0,0,.08);
}
.rec-stripe { height: 6px; background: linear-gradient(90deg, var(--primary-color, #6d4aff), #a78bfa); }
.rec-body { padding: 48px 52px; }

.rec-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
.rec-title h1 { font-size: 30px; font-weight: 800; letter-spacing: -.5px; color: var(--c-text); margin: 0 0 6px; }
.rec-title p  { font-size: 14px; color: var(--c-muted); margin: 0; }
.rec-logo { display: flex; align-items: center; gap: 12px; }
.rec-logo img { width: 40px; height: 40px; object-fit: contain; }
.rec-logo-fallback { width: 44px; height: 44px; border-radius: 10px; background: var(--primary-color,#6d4aff); color: #fff; font-size: 20px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.rec-logo-text strong { display: block; font-size: 15px; font-weight: 700; color: var(--c-text); }
.rec-logo-text span   { font-size: 11px; color: var(--c-muted); }

.doc-type-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(109,74,255,.10); color: var(--primary-color,#6d4aff);
    border: 1px solid rgba(109,74,255,.25);
    font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;
    padding: 4px 12px; border-radius: 20px; margin-bottom: 10px;
}

.rec-stats {
    display: grid; grid-template-columns: repeat(3,1fr);
    gap: 0; border: 1px solid var(--c-border); border-radius: 12px; overflow: hidden; margin-bottom: 36px;
}
.rec-stat { padding: 18px 20px; border-right: 1px solid var(--c-border); text-align: center; }
.rec-stat:last-child { border-right: none; }
.rec-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--c-muted); margin-bottom: 8px; }
.rec-stat-value { font-size: 22px; font-weight: 800; color: var(--c-text); }
.rec-stat-value.accent { color: var(--primary-color,#6d4aff); }

.rec-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 36px; }
.party-label  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--primary-color,#6d4aff); margin-bottom: 10px; }
.party-name   { font-size: 16px; font-weight: 700; color: var(--c-text); margin-bottom: 4px; }
.party-detail { font-size: 13px; color: var(--c-muted); line-height: 1.75; }

.rec-detail-grid {
    display: grid; grid-template-columns: repeat(3,1fr);
    gap: 0; border: 1px solid var(--c-border); border-radius: 10px; overflow: hidden; margin-bottom: 32px;
}
.rec-detail-cell { padding: 16px 18px; border-right: 1px solid var(--c-border); }
.rec-detail-cell:last-child { border-right: none; }
.rec-detail-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--c-muted); margin-bottom: 6px; }
.rec-detail-value { font-size: 14px; font-weight: 600; color: var(--c-text); }

.rec-items-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 32px; border: 1px solid var(--c-border); border-radius: 10px; overflow: hidden; }
.rec-items-table thead th { padding: 12px 14px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--c-muted); background: var(--c-bg); border-bottom: 1px solid var(--c-border); }
.rec-items-table thead th:last-child { text-align: right; }
.rec-items-table tbody td { padding: 12px 14px; border-bottom: 1px solid var(--c-border); color: var(--c-text); }
.rec-items-table tbody tr:last-child td { border-bottom: none; }
.rec-items-table tfoot td { padding: 12px 14px; font-weight: 700; font-size: 14px; border-top: 1px solid var(--c-border); background: var(--c-bg); }

.rec-amount-block {
    background: rgba(109,74,255,.05); border: 1.5px solid rgba(109,74,255,.20);
    border-radius: 12px; padding: 24px 28px;
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;
}
.rec-amount-label { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--c-muted); margin-bottom: 6px; }
.rec-amount-value { font-size: 36px; font-weight: 900; color: var(--primary-color,#6d4aff); letter-spacing: -1px; }
.rec-amount-sub   { font-size: 12px; color: var(--c-muted); margin-top: 4px; }

.rec-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 20px; letter-spacing: .2px; }
.rec-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.badge-completed  { background:rgba(5,150,105,.1);  color:var(--c-green); border:1px solid rgba(5,150,105,.2); }
.badge-pending    { background:rgba(217,119,6,.1);  color:var(--c-amber); border:1px solid rgba(217,119,6,.2); }
.badge-cancelled  { background:rgba(220,38,38,.1);  color:var(--c-red);   border:1px solid rgba(220,38,38,.2); }

.inst-badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(109,74,255,.10); color: var(--primary-color,#6d4aff); }

.rec-notes { background: rgba(109,74,255,.04); border-left: 4px solid var(--primary-color,#6d4aff); border-radius: 0 8px 8px 0; padding: 14px 18px; margin-bottom: 30px; }
.rec-notes h4 { font-size: 13px; font-weight: 700; margin: 0 0 4px; color: var(--c-text); }
.rec-notes p  { font-size: 13px; color: var(--c-muted); line-height: 1.6; margin: 0; }

.rec-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--c-border); }
.rec-footer-note { font-size: 13px; color: var(--c-muted); max-width: 360px; line-height: 1.6; }
.rec-footer-note strong { display: block; font-size: 14px; color: var(--c-text); margin-bottom: 4px; }
.rec-footer-from { text-align: right; font-size: 12px; color: var(--c-muted); line-height: 1.8; }
.rec-footer-from strong { display: block; font-size: 14px; color: var(--c-text); }

@media print {
    .doc-topbar, .sidebar, nav { display: none !important; }
    .main-area { margin: 0 !important; padding: 0 !important; }
    .receipt-wrap { padding: 0; max-width: 100%; margin: 0; }
    .receipt-card { box-shadow: none; border-radius: 0; }
    .rec-body { padding: 32px 40px; }
    .rec-stripe { height: 4px; }
}
@media (max-width: 640px) {
    .rec-body { padding: 28px 20px; }
    .rec-header { flex-direction: column-reverse; gap: 20px; }
    .rec-stats, .rec-detail-grid { grid-template-columns: 1fr 1fr; }
    .rec-parties { grid-template-columns: 1fr; }
    .rec-footer { flex-direction: column; gap: 20px; }
    .rec-amount-block { flex-direction: column; gap: 12px; text-align: center; }
}
</style>

<main class="main-area" style="padding-bottom:60px;">

    <div class="doc-topbar">
        <a href="sales_returns.php">
            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Sales Returns
        </a>
        <div class="doc-topbar-right">
            <button class="btn-primary" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Receipt
            </button>
        </div>
    </div>

    <div class="receipt-wrap">
        <div class="receipt-card">
            <div class="rec-stripe"></div>
            <div class="rec-body">

                <div class="rec-header">
                    <div class="rec-title">
                        <div class="doc-type-pill">
                            <svg viewBox="0 0 24 24" width="10" height="10" stroke="currentColor" fill="none" stroke-width="2.5"><polyline points="15 14 20 9 15 4"/><path d="M4 20v-7a4 4 0 0 1 4-4h12"/></svg>
                            Sales Return
                        </div>
                        <h1>REFUND RECEIPT</h1>
                        <p>REF #<?= htmlspecialchars($ref_number) ?> &nbsp;·&nbsp; <?= date('d M Y', strtotime($return_date)) ?></p>
                    </div>
                    <div class="rec-logo">
                        <?php if (file_exists(__DIR__ . '/assets/images/logo.png')): ?>
                            <img src="assets/images/logo.png" alt="Logo">
                        <?php else: ?>
                            <div class="rec-logo-fallback"><?= strtoupper(substr($biz['company_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="rec-logo-text">
                            <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                            <span><?= htmlspecialchars($biz['company_website'] ?? '') ?></span>
                        </div>
                    </div>
                </div>

                <div class="rec-stats">
                    <div class="rec-stat">
                        <div class="rec-stat-label">Refund Amount</div>
                        <div class="rec-stat-value accent"><?= $sym . number_format($refund_total, 2) ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">Return Date</div>
                        <div class="rec-stat-value" style="font-size:16px;"><?= date('d M Y', strtotime($return_date)) ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">Status</div>
                        <div class="rec-stat-value" style="font-size:15px;">
                            <span class="rec-badge badge-<?= $badge_cls ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                    </div>
                </div>

                <div class="rec-parties">
                    <div>
                        <div class="party-label">Returned By (Customer)</div>
                        <div class="party-name"><?= htmlspecialchars($customer['customer_name'] ?? 'Unknown') ?></div>
                        <div class="party-detail">
                            <?php if (!empty($customer['contact_number'])): ?><?= htmlspecialchars($customer['contact_number']) ?><br><?php endif; ?>
                            <?php if (!empty($customer['email'])): ?><?= htmlspecialchars($customer['email']) ?><br><?php endif; ?>
                            <?php if (!empty($customer['address'])): ?><?= nl2br(htmlspecialchars($customer['address'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div class="party-label">Received By</div>
                        <div class="party-name"><?= htmlspecialchars($biz['company_name']) ?></div>
                        <div class="party-detail">
                            <?= htmlspecialchars($biz['company_address'] ?? '') ?><br>
                            <?= htmlspecialchars($biz['company_city'] ?? '') ?><?= !empty($biz['company_state']) ? ', ' . htmlspecialchars($biz['company_state']) : '' ?><?= !empty($biz['company_pincode']) ? ' – ' . htmlspecialchars($biz['company_pincode']) : '' ?><br>
                            <?= htmlspecialchars($biz['company_phone'] ?? '') ?><br>
                            <?= htmlspecialchars($biz['company_email'] ?? '') ?>
                        </div>
                    </div>
                </div>

                <div class="rec-detail-grid">
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Return Ref #</div>
                        <div class="rec-detail-value" style="color:var(--primary-color);"><?= htmlspecialchars($ref_number) ?></div>
                    </div>
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Original Sales Order</div>
                        <div class="rec-detail-value">
                            <?php if ($so_number): ?>
                                <span class="inst-badge"><?= htmlspecialchars($so_number) ?></span>
                            <?php else: ?>
                                <span style="color:var(--c-muted);font-weight:400;">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Invoice Reference</div>
                        <div class="rec-detail-value">
                            <?php if ($inv_number): ?>
                                <span class="inst-badge"><?= htmlspecialchars($inv_number) ?></span>
                            <?php else: ?>
                                <span style="color:var(--c-muted);font-weight:400;">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($items)): ?>
                <table class="rec-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:center;">Qty Returned</th>
                            <th style="text-align:right;">Unit Price</th>
                            <th style="text-align:right;">Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <span style="font-weight:600;"><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?></span>
                                <?php if (!empty($item['product_id_str'])): ?>
                                    <span style="display:block;font-size:11px;color:var(--c-muted);"><?= htmlspecialchars($item['product_id_str']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;"><?= intval($item['quantity_returned']) ?></td>
                            <td style="text-align:right;"><?= $sym . number_format(floatval($item['unit_price']), 2) ?></td>
                            <td style="text-align:right;font-weight:600;"><?= $sym . number_format(floatval($item['subtotal']), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right;color:var(--c-muted);font-size:12px;">Total Refund</td>
                            <td style="text-align:right;font-size:16px;color:var(--primary-color);"><?= $sym . number_format($refund_total, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>

                <div class="rec-amount-block">
                    <div>
                        <div class="rec-amount-label">Total Refund Amount</div>
                        <div class="rec-amount-value"><?= $sym . number_format($refund_total, 2) ?></div>
                        <div class="rec-amount-sub">Amount to be refunded to customer for returned goods</div>
                    </div>
                    <div style="text-align:right;">
                        <div class="rec-amount-label">Return Status</div>
                        <span class="rec-badge badge-<?= $badge_cls ?>" style="font-size:14px;padding:8px 16px;"><?= htmlspecialchars($status) ?></span>
                    </div>
                </div>

                <?php if (!empty($reason)): ?>
                <div class="rec-notes">
                    <h4>Reason for Return</h4>
                    <p><?= nl2br(htmlspecialchars($reason)) ?></p>
                </div>
                <?php endif; ?>

                <div class="rec-footer">
                    <div class="rec-footer-note">
                        <strong>Sales Return Refund Receipt</strong>
                        This receipt confirms that goods valued at <?= $sym . number_format($refund_total, 2) ?>
                        were returned by <?= htmlspecialchars($customer['customer_name'] ?? 'the customer') ?>
                        on <?= date('d M Y', strtotime($return_date)) ?>.
                        <?php if (!empty($biz['invoice_notes'])): ?>
                            <br><?= nl2br(htmlspecialchars($biz['invoice_notes'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="rec-footer-from">
                        <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                        <?= htmlspecialchars($biz['company_phone'] ?? '') ?><br>
                        <?= htmlspecialchars($biz['company_email'] ?? '') ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

</main>

<?php include 'includes/footer.php'; ?>
