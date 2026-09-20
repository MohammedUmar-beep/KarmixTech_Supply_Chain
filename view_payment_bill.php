<?php
require_once 'includes/auth_guard.php';

if (!isset($_GET['id'])) { header("Location: payments_made.php"); exit(); }
$id = intval($_GET['id']);

// Fetch payment + supplier
$res = $conn->query("
    SELECT p.*, s.supplier_name, s.email, s.phone, s.contact_person, s.address
    FROM payments_made p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.id = $id
");
if (!$res || $res->num_rows === 0) { echo "Payment record not found."; exit(); }
$payment = $res->fetch_assoc();

// Fetch PO total for this supplier (best proxy for bill total)
$bill_total = floatval($payment['amount']);
$po_res = $conn->query("SELECT total_amount FROM purchase_orders WHERE supplier_id = " . intval($payment['supplier_id']) . " ORDER BY id DESC LIMIT 1");
if ($po_res && $po_res->num_rows > 0) $bill_total = floatval($po_res->fetch_row()[0]);

// Fetch installments for this payment via payment_installments table
$installments = [];
$total_paid   = 0;
$inst_res = $conn->query("SELECT * FROM payment_installments WHERE payment_made_id = $id ORDER BY installment_date ASC");
if ($inst_res) {
    while ($r = $inst_res->fetch_assoc()) {
        $installments[] = $r;
        $total_paid += floatval($r['amount']);
    }
}
// If no installments logged, use the payment amount itself
if (!$total_paid) $total_paid = floatval($payment['amount']);
$amount_due = max(0, $bill_total - $total_paid);

$biz = get_business_settings($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Receipt – <?= htmlspecialchars($payment['payment_ref_str']) ?></title>
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

.receipt-wrap { max-width: 840px; margin: 0 auto 60px; padding: 0 16px; }
.receipt-card {
    background: var(--c-white);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 4px 28px rgba(0,0,0,0.08);
}
.rec-stripe {
    height: 6px;
    background: linear-gradient(90deg, var(--c-accent), #a78bfa);
}
.rec-body { padding: 48px 52px; }

/* Header */
.rec-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
.rec-title h1 { font-size: 32px; font-weight: 800; letter-spacing: -0.5px; color: var(--c-text); margin: 0 0 6px; }
.rec-title p  { font-size: 14px; color: var(--c-muted); margin: 0; }
.rec-logo { display: flex; align-items: center; gap: 12px; }
.rec-logo img { width: 40px; height: 40px; object-fit: contain; }
.rec-logo-fallback { width: 44px; height: 44px; border-radius: 10px; background: var(--c-accent); color: #fff; font-size: 20px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.rec-logo-text strong { display: block; font-size: 15px; font-weight: 700; color: var(--c-text); }
.rec-logo-text span   { font-size: 11px; color: var(--c-muted); }

/* Stats strip */
.rec-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 0; border: 1px solid var(--c-border);
    border-radius: 12px; overflow: hidden; margin-bottom: 36px;
}
.rec-stat { padding: 18px 20px; border-right: 1px solid var(--c-border); text-align: center; }
.rec-stat:last-child { border-right: none; }
.rec-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: var(--c-muted); margin-bottom: 8px; }
.rec-stat-value { font-size: 22px; font-weight: 800; color: var(--c-text); }
.rec-stat-value.accent { color: var(--c-accent); }
.rec-stat-value.green  { color: var(--c-green); }
.rec-stat-value.red    { color: var(--c-red); }

/* Parties */
.rec-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 36px; }
.party-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--c-accent); margin-bottom: 10px; }
.party-name  { font-size: 16px; font-weight: 700; color: var(--c-text); margin-bottom: 4px; }
.party-detail { font-size: 13px; color: var(--c-muted); line-height: 1.75; }

/* Payment detail row */
.rec-detail-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 0; border: 1px solid var(--c-border);
    border-radius: 10px; overflow: hidden; margin-bottom: 32px;
}
.rec-detail-cell { padding: 16px 18px; border-right: 1px solid var(--c-border); }
.rec-detail-cell:last-child { border-right: none; }
.rec-detail-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--c-muted); margin-bottom: 6px; }
.rec-detail-value { font-size: 14px; font-weight: 600; color: var(--c-text); }

/* Status badge inline */
.rec-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 700; padding: 4px 10px;
    border-radius: 20px; letter-spacing: 0.2px;
}
.rec-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.badge-completed  { background:rgba(5,150,105,0.1);  color:var(--c-green);  border:1px solid rgba(5,150,105,0.2); }
.badge-inprogress { background:rgba(109,74,255,0.1); color:var(--c-accent); border:1px solid rgba(109,74,255,0.2); }
.badge-pending    { background:rgba(217,119,6,0.1);  color:var(--c-amber);  border:1px solid rgba(217,119,6,0.2); }
.badge-cancelled  { background:rgba(220,38,38,0.1);  color:var(--c-red);    border:1px solid rgba(220,38,38,0.2); }

/* Notes box */
.rec-notes {
    background: rgba(109,74,255,0.04);
    border-left: 4px solid var(--c-accent);
    border-radius: 0 8px 8px 0;
    padding: 14px 18px;
    margin-bottom: 30px;
}
.rec-notes h4 { font-size: 13px; font-weight: 700; margin: 0 0 4px; color: var(--c-text); }
.rec-notes p  { font-size: 13px; color: var(--c-muted); line-height: 1.6; margin: 0; }

/* Installments table */
.inst-section { border-top: 1px solid var(--c-border); padding-top: 28px; margin-top: 8px; }
.inst-section h3 { font-size: 15px; font-weight: 700; color: var(--c-text); margin: 0 0 16px; }
.inst-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.inst-table th { padding: 9px 12px; background: var(--c-bg); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--c-muted); text-align: left; border-bottom: 1px solid var(--c-border); }
.inst-table th.r, .inst-table td.r { text-align: right; }
.inst-table td { padding: 13px 12px; border-bottom: 1px solid #f1f5f9; color: var(--c-text); }
.inst-table tr:last-child td { border-bottom: none; }
.inst-table tr.current td { background: rgba(109,74,255,0.04); font-weight: 600; }
.inst-badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(109,74,255,0.1); color: var(--c-accent); }
.inst-amount { font-weight: 600; color: var(--c-green); }

/* Footer */
.rec-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--c-border); }
.rec-footer-note { font-size: 13px; color: var(--c-muted); max-width: 360px; line-height: 1.6; }
.rec-footer-note strong { display: block; font-size: 14px; color: var(--c-text); margin-bottom: 4px; }
.rec-footer-from { text-align: right; font-size: 12px; color: var(--c-muted); line-height: 1.8; }
.rec-footer-from strong { display: block; font-size: 14px; color: var(--c-text); }

/* Modal */
.modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); overflow-y: auto; }
.modal-box { background: var(--c-white); border-radius: 12px; padding: 32px; margin: 60px auto; width: 90%; max-width: 440px; }
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; }
.modal-head h2 { margin: 0; font-size: 18px; font-weight: 700; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--c-muted); }
.mform-group { margin-bottom: 16px; }
.mform-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.mform-group input, .mform-group select, .mform-group textarea { width: 100%; padding: 9px 12px; border: 1px solid var(--c-border); border-radius: 8px; font-size: 14px; font-family: inherit; outline: none; }
.mform-group input:focus, .mform-group select:focus { border-color: var(--c-accent); }
.mform-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.mform-footer button { padding: 9px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1.5px solid var(--c-border); background: var(--c-white); color: var(--c-text); }
.mform-footer .btn-confirm { background: var(--c-accent); color: #fff; border-color: var(--c-accent); }

@media print {
    .doc-topbar, .modal, .sidebar, nav { display: none !important; }
    .main-area { margin: 0 !important; padding: 0 !important; }
    .receipt-wrap { padding: 0; max-width: 100%; margin: 0; }
    .receipt-card { box-shadow: none; border-radius: 0; }
    .rec-body { padding: 32px 40px; }
    .rec-stripe { height: 4px; }
    .rec-stats { grid-template-columns: repeat(4,1fr); }
}
@media (max-width: 640px) {
    .rec-body { padding: 28px 20px; }
    .rec-header { flex-direction: column-reverse; gap: 20px; }
    .rec-stats, .rec-detail-grid { grid-template-columns: 1fr 1fr; }
    .rec-parties { grid-template-columns: 1fr; }
    .rec-footer { flex-direction: column; gap: 20px; }
}
</style>
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="main-area" style="padding-bottom: 60px;">

    <div class="doc-topbar">
        <a href="payments_made.php">
            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Payments
        </a>
        <div class="doc-topbar-right">
            <?php if ($amount_due > 0): ?>
            <button onclick="document.getElementById('installmentModal').style.display='flex'">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Record Installment
            </button>
            <?php endif; ?>
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

                <!-- Header -->
                <div class="rec-header">
                    <div class="rec-title">
                        <h1>PAYMENT RECEIPT</h1>
                        <p>REF #<?= htmlspecialchars($payment['payment_ref_str']) ?>
                           &nbsp;·&nbsp; <?= date('d M Y', strtotime($payment['payment_date'])) ?>
                        </p>
                    </div>
                    <div class="rec-logo">
                        <?php $logo_path = __DIR__ . '/assets/images/logo.png'; if (file_exists($logo_path)): ?>
                            <img src="assets/images/logo.png" alt="Logo">
                        <?php else: ?>
                            <div class="rec-logo-fallback"><?= strtoupper(substr($biz['company_name'],0,1)) ?></div>
                        <?php endif; ?>
                        <div class="rec-logo-text">
                            <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                            <span><?= htmlspecialchars($biz['company_website']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- 4-stat strip -->
                <div class="rec-stats">
                    <div class="rec-stat">
                        <div class="rec-stat-label">Bill Total</div>
                        <div class="rec-stat-value">$<?= $bill_total > 0 ? number_format($bill_total, 2) : '—' ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">This Payment</div>
                        <div class="rec-stat-value accent">$<?= number_format($payment['amount'], 2) ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">Total Paid</div>
                        <div class="rec-stat-value green">$<?= number_format($total_paid, 2) ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">Outstanding</div>
                        <div class="rec-stat-value <?= $amount_due > 0 ? 'red' : 'green' ?>">
                            $<?= number_format($amount_due, 2) ?>
                        </div>
                    </div>
                </div>

                <!-- Parties -->
                <div class="rec-parties">
                    <div>
                        <div class="party-label">Paid To (Supplier)</div>
                        <div class="party-name"><?= htmlspecialchars($payment['supplier_name'] ?? 'Unknown') ?></div>
                        <div class="party-detail">
                            <?php if (!empty($payment['contact_person'])): ?><?= htmlspecialchars($payment['contact_person']) ?><br><?php endif; ?>
                            <?php if (!empty($payment['email'])): ?><?= htmlspecialchars($payment['email']) ?><br><?php endif; ?>
                            <?php if (!empty($payment['phone'])): ?><?= htmlspecialchars($payment['phone']) ?><br><?php endif; ?>
                            <?php if (!empty($payment['address'] ?? '')): ?><?= nl2br(htmlspecialchars($payment['address'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div class="party-label">Paid By</div>
                        <div class="party-name"><?= htmlspecialchars($biz['company_name']) ?></div>
                        <div class="party-detail">
                            <?= htmlspecialchars($biz['company_address']) ?><br>
                            <?= htmlspecialchars($biz['company_city']) ?>, <?= htmlspecialchars($biz['company_state']) ?> – <?= htmlspecialchars($biz['company_pincode']) ?><br>
                            <?= htmlspecialchars($biz['company_phone']) ?><br>
                            <?= htmlspecialchars($biz['company_email']) ?>
                        </div>
                    </div>
                </div>

                <!-- Payment detail cells -->
                <div class="rec-detail-grid">
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Payment Date</div>
                        <div class="rec-detail-value"><?= date('d M Y', strtotime($payment['payment_date'])) ?></div>
                    </div>
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Payment Method</div>
                        <div class="rec-detail-value">
                            <span class="inst-badge"><?= htmlspecialchars($payment['payment_method']) ?></span>
                        </div>
                    </div>
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Status</div>
                        <div class="rec-detail-value">
                            <?php
                            $statusSlug = $payment['status'] ?? '';
                            $slugMap = ['Fully Paid'=>'completed','Partially Paid'=>'inprogress','On Credit'=>'pending'];
                            $cls = $slugMap[$statusSlug] ?? 'inprogress';
                            ?>
                            <span class="rec-badge badge-<?= $cls ?>"><?= htmlspecialchars($statusSlug) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <?php if (!empty($payment['notes'])): ?>
                <div class="rec-notes">
                    <h4>Internal Notes / Reference</h4>
                    <p><?= nl2br(htmlspecialchars($payment['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <!-- Installment History -->
                <?php if (!empty($installments)): ?>
                <div class="inst-section">
                    <h3>Full Payment History for this Bill</h3>
                    <table class="inst-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Method</th>
                                <th class="r">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($installments as $i => $inst):
                                $isCurrent = abs(floatval($inst['amount']) - floatval($payment['amount'])) < 0.01;
                            ?>
                            <tr<?= $isCurrent ? ' class="current"' : '' ?>>
                                <td style="color:var(--c-muted);"><?= $i + 1 ?></td>
                                <td><?= date('d M Y', strtotime($inst['installment_date'])) ?></td>
                                <td><span class="inst-badge"><?= htmlspecialchars($inst['payment_method']) ?></span></td>
                                <td class="r inst-amount">+$<?= number_format($inst['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="rec-footer">
                    <div class="rec-footer-note">
                        <strong>Receipt of Payment</strong>
                        This receipt confirms the above payment was made in full on <?= date('d M Y', strtotime($payment['payment_date'])) ?>.
                        <?= nl2br(htmlspecialchars($biz['invoice_notes'])) ?>
                    </div>
                    <div class="rec-footer-from">
                        <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                        <?= htmlspecialchars($biz['company_phone']) ?><br>
                        <?= htmlspecialchars($biz['company_email']) ?>
                    </div>
                </div>

            </div><!-- /rec-body -->
        </div><!-- /receipt-card -->
    </div><!-- /receipt-wrap -->

</main>
</div><!-- /app-container -->

<!-- Record Installment Modal -->
<?php if ($amount_due > 0): ?>
<div id="installmentModal" class="modal" style="display:none; align-items:flex-start; justify-content:center;">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Record Installment</h2>
            <button class="modal-close" onclick="document.getElementById('installmentModal').style.display='none'">&times;</button>
        </div>
        <form action="add_installment.php" method="POST">
            <input type="hidden" name="purchase_order_id" value="<?php
                // Resolve the PO id from this payment's notes tag, or fall back to latest PO for this supplier
                preg_match('/PO-ID:(\d+)/', $payment['notes'] ?? '', $m);
                $resolved_po = $m[1] ?? 0;
                if (!$resolved_po) {
                    $fb = $conn->query("SELECT id FROM purchase_orders WHERE supplier_id = " . intval($payment['supplier_id']) . " ORDER BY id DESC LIMIT 1");
                    if ($fb && $fb->num_rows > 0) $resolved_po = (int)$fb->fetch_row()[0];
                }
                echo (int)$resolved_po;
            ?>">
            <div class="mform-group">
                <label>Amount (Outstanding: $<?= number_format($amount_due, 2) ?>)</label>
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
                </select>
            </div>
            <div class="mform-group">
                <label>Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="UTR, reference number, etc."></textarea>
            </div>
            <div class="mform-footer">
                <button type="button" onclick="document.getElementById('installmentModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-confirm">Confirm</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
