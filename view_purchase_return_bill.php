<?php
require_once 'includes/auth_guard.php';

// Accept either sc_id (Supplier Credit) or pr_id (Purchase Return)
$sc_id = isset($_GET['sc_id']) ? intval($_GET['sc_id']) : 0;
$pr_id = isset($_GET['pr_id']) ? intval($_GET['pr_id']) : 0;

if (!$sc_id && !$pr_id) {
    header("Location: supplier_credits.php");
    exit();
}

$doc_type    = '';
$ref_number  = '';
$date_issued = '';
$amount      = 0;
$status      = '';
$reason      = '';
$supplier    = null;
$po_number   = '';
$back_url    = 'supplier_credits.php';
$back_label  = 'Supplier Credits';

if ($sc_id) {
    // ── Supplier Credit ───────────────────────────────────────────────────────
    $doc_type   = 'SUPPLIER CREDIT NOTE';
    $back_url   = 'supplier_credits.php';
    $back_label = 'Supplier Credits';

    $res = $conn->query("
        SELECT sc.*,
               s.supplier_name, s.email, s.phone, s.contact_person, s.address,
               po.purchase_id_str AS po_number
        FROM supplier_credits sc
        LEFT JOIN suppliers s        ON sc.supplier_id       = s.id
        LEFT JOIN purchase_orders po ON sc.purchase_order_id = po.id
        WHERE sc.id = $sc_id
    ");
    if (!$res || $res->num_rows === 0) { echo "Record not found."; exit(); }
    $row = $res->fetch_assoc();

    $ref_number  = $row['credit_note_str'];
    $date_issued = $row['date_issued'];
    $amount      = floatval($row['amount']);
    $status      = $row['status'];
    $po_number   = $row['po_number'] ?? '';
    $supplier    = $row;

} elseif ($pr_id) {
    // ── Purchase Return ───────────────────────────────────────────────────────
    $doc_type   = 'PURCHASE RETURN RECEIPT';
    $back_url   = 'purchase_returns.php';
    $back_label = 'Purchase Returns';

    $res = $conn->query("
        SELECT pr.*,
               s.supplier_name, s.email, s.phone, s.contact_person, s.address,
               po.purchase_id_str AS po_number
        FROM purchase_returns pr
        LEFT JOIN suppliers s        ON pr.supplier_id       = s.id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
        WHERE pr.id = $pr_id
    ");
    if (!$res || $res->num_rows === 0) { echo "Record not found."; exit(); }
    $row = $res->fetch_assoc();

    $ref_number  = $row['return_number_str'];
    $date_issued = $row['return_date'];
    $amount      = floatval($row['amount']);
    $status      = $row['status'];
    $po_number   = $row['po_number'] ?? '';
    $reason      = $row['reason'] ?? '';
    $supplier    = $row;
}

$biz = get_business_settings($conn);

// Status → badge class
$status_map = [
    'Open'      => 'inprogress',
    'Completed' => 'completed',
    'Pending'   => 'pending',
    'Cancelled' => 'cancelled',
    'Processed' => 'completed',
    'Approved'  => 'completed',
    'Rejected'  => 'cancelled',
];
$badge_cls = $status_map[$status] ?? 'inprogress';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $doc_type ?> – <?= htmlspecialchars($ref_number) ?></title>
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
    --c-orange: #ea580c;
}
*, *::before, *::after { box-sizing: border-box; }

/* ── Top bar ── */
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

/* ── Card ── */
.receipt-wrap { max-width: 840px; margin: 0 auto 60px; padding: 0 16px; }
.receipt-card {
    background: var(--c-white);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 4px 28px rgba(0,0,0,0.08);
}
.rec-stripe {
    height: 6px;
    background: linear-gradient(90deg, var(--c-orange, #ea580c), var(--c-amber, #d97706));
}
.rec-body { padding: 48px 52px; }

/* ── Header ── */
.rec-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
.rec-title h1 { font-size: 30px; font-weight: 800; letter-spacing: -0.5px; color: var(--c-text); margin: 0 0 6px; }
.rec-title p  { font-size: 14px; color: var(--c-muted); margin: 0; }
.rec-logo { display: flex; align-items: center; gap: 12px; }
.rec-logo img { width: 40px; height: 40px; object-fit: contain; }
.rec-logo-fallback { width: 44px; height: 44px; border-radius: 10px; background: var(--c-orange,#ea580c); color: #fff; font-size: 20px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.rec-logo-text strong { display: block; font-size: 15px; font-weight: 700; color: var(--c-text); }
.rec-logo-text span   { font-size: 11px; color: var(--c-muted); }

/* ── Type pill ── */
.doc-type-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(234,88,12,0.1); color: var(--c-orange,#ea580c);
    border: 1px solid rgba(234,88,12,0.25);
    font-size: 10px; font-weight: 800; letter-spacing: 1px;
    text-transform: uppercase; padding: 4px 12px; border-radius: 20px;
    margin-bottom: 10px;
}

/* ── Stat strip ── */
.rec-stats {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 0; border: 1px solid var(--c-border);
    border-radius: 12px; overflow: hidden; margin-bottom: 36px;
}
.rec-stat { padding: 18px 20px; border-right: 1px solid var(--c-border); text-align: center; }
.rec-stat:last-child { border-right: none; }
.rec-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: var(--c-muted); margin-bottom: 8px; }
.rec-stat-value { font-size: 22px; font-weight: 800; color: var(--c-text); }
.rec-stat-value.accent { color: var(--c-orange,#ea580c); }
.rec-stat-value.green  { color: var(--c-green); }
.rec-stat-value.red    { color: var(--c-red); }

/* ── Parties ── */
.rec-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 36px; }
.party-label  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--c-orange,#ea580c); margin-bottom: 10px; }
.party-name   { font-size: 16px; font-weight: 700; color: var(--c-text); margin-bottom: 4px; }
.party-detail { font-size: 13px; color: var(--c-muted); line-height: 1.75; }

/* ── Detail cells ── */
.rec-detail-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 0; border: 1px solid var(--c-border);
    border-radius: 10px; overflow: hidden; margin-bottom: 32px;
}
.rec-detail-cell { padding: 16px 18px; border-right: 1px solid var(--c-border); }
.rec-detail-cell:last-child { border-right: none; }
.rec-detail-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--c-muted); margin-bottom: 6px; }
.rec-detail-value { font-size: 14px; font-weight: 600; color: var(--c-text); }

/* ── Amount highlight block ── */
.rec-amount-block {
    background: rgba(234,88,12,0.05);
    border: 1.5px solid rgba(234,88,12,0.2);
    border-radius: 12px;
    padding: 24px 28px;
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 32px;
}
.rec-amount-label { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: var(--c-muted); margin-bottom: 6px; }
.rec-amount-value { font-size: 36px; font-weight: 900; color: var(--c-orange,#ea580c); letter-spacing: -1px; }
.rec-amount-sub   { font-size: 12px; color: var(--c-muted); margin-top: 4px; }

/* ── Badge ── */
.rec-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 700; padding: 4px 10px;
    border-radius: 20px; letter-spacing: 0.2px;
}
.rec-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.badge-completed  { background:rgba(5,150,105,0.1);  color:var(--c-green);        border:1px solid rgba(5,150,105,0.2); }
.badge-inprogress { background:rgba(234,88,12,0.1);  color:var(--c-orange,#ea580c); border:1px solid rgba(234,88,12,0.2); }
.badge-pending    { background:rgba(217,119,6,0.1);  color:var(--c-amber);        border:1px solid rgba(217,119,6,0.2); }
.badge-cancelled  { background:rgba(220,38,38,0.1);  color:var(--c-red);          border:1px solid rgba(220,38,38,0.2); }

.inst-badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(234,88,12,0.1); color: var(--c-orange,#ea580c); }

/* ── Notes ── */
.rec-notes {
    background: rgba(234,88,12,0.04);
    border-left: 4px solid var(--c-orange,#ea580c);
    border-radius: 0 8px 8px 0;
    padding: 14px 18px;
    margin-bottom: 30px;
}
.rec-notes h4 { font-size: 13px; font-weight: 700; margin: 0 0 4px; color: var(--c-text); }
.rec-notes p  { font-size: 13px; color: var(--c-muted); line-height: 1.6; margin: 0; }

/* ── Footer ── */
.rec-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--c-border); }
.rec-footer-note { font-size: 13px; color: var(--c-muted); max-width: 360px; line-height: 1.6; }
.rec-footer-note strong { display: block; font-size: 14px; color: var(--c-text); margin-bottom: 4px; }
.rec-footer-from { text-align: right; font-size: 12px; color: var(--c-muted); line-height: 1.8; }
.rec-footer-from strong { display: block; font-size: 14px; color: var(--c-text); }

/* ── Print ── */
@media print {
    .doc-topbar, .sidebar, nav { display: none !important; }
    .main-area { margin: 0 !important; padding: 0 !important; }
    .receipt-wrap { padding: 0; max-width: 100%; margin: 0; }
    .receipt-card { box-shadow: none; border-radius: 0; }
    .rec-body { padding: 32px 40px; }
    .rec-stripe { height: 4px; }
    .rec-stats { grid-template-columns: repeat(3,1fr); }
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
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="main-area" style="padding-bottom:60px;">

    <!-- Top bar -->
    <div class="doc-topbar">
        <a href="<?= $back_url ?>">
            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to <?= $back_label ?>
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

                <!-- Header -->
                <div class="rec-header">
                    <div class="rec-title">
                        <div class="doc-type-pill">
                            <svg viewBox="0 0 24 24" width="10" height="10" stroke="currentColor" fill="none" stroke-width="2.5"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
                            <?= $sc_id ? 'Supplier Credit' : 'Purchase Return' ?>
                        </div>
                        <h1><?= $doc_type ?></h1>
                        <p>
                            REF #<?= htmlspecialchars($ref_number) ?>
                            &nbsp;·&nbsp; <?= date('d M Y', strtotime($date_issued)) ?>
                        </p>
                    </div>
                    <div class="rec-logo">
                        <?php $logo_path = __DIR__ . '/assets/images/logo.png'; if (file_exists($logo_path)): ?>
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

                <!-- Stats strip -->
                <div class="rec-stats">
                    <div class="rec-stat">
                        <div class="rec-stat-label">Credit / Return Amount</div>
                        <div class="rec-stat-value accent">$<?= number_format($amount, 2) ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">Date Issued</div>
                        <div class="rec-stat-value" style="font-size:16px;"><?= date('d M Y', strtotime($date_issued)) ?></div>
                    </div>
                    <div class="rec-stat">
                        <div class="rec-stat-label">Status</div>
                        <div class="rec-stat-value" style="font-size:15px;">
                            <span class="rec-badge badge-<?= $badge_cls ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Parties -->
                <div class="rec-parties">
                    <div>
                        <div class="party-label"><?= $sc_id ? 'Credit From (Supplier)' : 'Return To (Supplier)' ?></div>
                        <div class="party-name"><?= htmlspecialchars($supplier['supplier_name'] ?? 'Unknown Supplier') ?></div>
                        <div class="party-detail">
                            <?php if (!empty($supplier['contact_person'])): ?><?= htmlspecialchars($supplier['contact_person']) ?><br><?php endif; ?>
                            <?php if (!empty($supplier['email'])): ?><?= htmlspecialchars($supplier['email']) ?><br><?php endif; ?>
                            <?php if (!empty($supplier['phone'])): ?><?= htmlspecialchars($supplier['phone']) ?><br><?php endif; ?>
                            <?php if (!empty($supplier['address'])): ?><?= nl2br(htmlspecialchars($supplier['address'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div class="party-label">Issued By</div>
                        <div class="party-name"><?= htmlspecialchars($biz['company_name']) ?></div>
                        <div class="party-detail">
                            <?= htmlspecialchars($biz['company_address'] ?? '') ?><br>
                            <?= htmlspecialchars($biz['company_city'] ?? '') ?><?= !empty($biz['company_state']) ? ', ' . htmlspecialchars($biz['company_state']) : '' ?><?= !empty($biz['company_pincode']) ? ' – ' . htmlspecialchars($biz['company_pincode']) : '' ?><br>
                            <?= htmlspecialchars($biz['company_phone'] ?? '') ?><br>
                            <?= htmlspecialchars($biz['company_email'] ?? '') ?>
                        </div>
                    </div>
                </div>

                <!-- Detail cells -->
                <div class="rec-detail-grid">
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Reference Number</div>
                        <div class="rec-detail-value" style="color:var(--c-orange,#ea580c);"><?= htmlspecialchars($ref_number) ?></div>
                    </div>
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Linked PO</div>
                        <div class="rec-detail-value">
                            <?php if ($po_number): ?>
                                <span class="inst-badge"><?= htmlspecialchars($po_number) ?></span>
                            <?php else: ?>
                                <span style="color:var(--c-muted); font-weight:400;">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rec-detail-cell">
                        <div class="rec-detail-label">Document Type</div>
                        <div class="rec-detail-value" style="font-size:13px;"><?= $sc_id ? 'Supplier Credit Note' : 'Purchase Return' ?></div>
                    </div>
                </div>

                <!-- Amount highlight -->
                <div class="rec-amount-block">
                    <div>
                        <div class="rec-amount-label"><?= $sc_id ? 'Credit Amount' : 'Return Amount' ?></div>
                        <div class="rec-amount-value">$<?= number_format($amount, 2) ?></div>
                        <div class="rec-amount-sub">
                            <?= $sc_id
                                ? 'Amount credited by supplier against your account'
                                : 'Value of goods returned to supplier' ?>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div class="rec-amount-label">Current Status</div>
                        <span class="rec-badge badge-<?= $badge_cls ?>" style="font-size:14px; padding:8px 16px;"><?= htmlspecialchars($status) ?></span>
                    </div>
                </div>

                <!-- Reason / Notes -->
                <?php if (!empty($reason)): ?>
                <div class="rec-notes">
                    <h4>Return Reason / Notes</h4>
                    <p><?= nl2br(htmlspecialchars($reason)) ?></p>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="rec-footer">
                    <div class="rec-footer-note">
                        <strong><?= $sc_id ? 'Supplier Credit Note' : 'Purchase Return Receipt' ?></strong>
                        This document confirms <?= $sc_id
                            ? "a supplier credit of \$$" . number_format($amount, 2) . " issued on " . date('d M Y', strtotime($date_issued))
                            : "a purchase return of \$$" . number_format($amount, 2) . " recorded on " . date('d M Y', strtotime($date_issued)) ?>.
                        <?php if ($biz['invoice_notes'] ?? ''): ?>
                            <br><?= nl2br(htmlspecialchars($biz['invoice_notes'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="rec-footer-from">
                        <strong><?= htmlspecialchars($biz['company_name']) ?></strong>
                        <?= htmlspecialchars($biz['company_phone'] ?? '') ?><br>
                        <?= htmlspecialchars($biz['company_email'] ?? '') ?>
                    </div>
                </div>

            </div><!-- /rec-body -->
        </div><!-- /receipt-card -->
    </div><!-- /receipt-wrap -->

</main>
</div><!-- /app-container -->
<?php include 'includes/footer.php'; ?>
