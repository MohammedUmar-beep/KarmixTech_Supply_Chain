<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Create new RFQ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'create_rfq') {
    $deadline  = !empty($_POST['deadline_date']) ? $conn->real_escape_string($_POST['deadline_date']) : null;
    $notes     = $conn->real_escape_string($_POST['notes'] ?? '');
    $sup_ids   = $_POST['supplier_id'] ?? [];
    $prod_ids  = $_POST['product_id']  ?? [];
    $quantities= $_POST['quantity']    ?? [];
    $user_id   = intval($_SESSION['user_id']);

    if (empty($sup_ids) || empty($prod_ids)) {
        $error = 'Select at least one supplier and one product.';
    } else {
        $conn->begin_transaction();
        try {
            $ddl = $deadline ? "'$deadline'" : 'NULL';
            $conn->query("INSERT INTO rfq_headers (rfq_number_str,requested_by,deadline_date,notes,status) VALUES ('RFQ-TMP',$user_id,$ddl,'$notes','Draft')");
            $rfq_id = $conn->insert_id;
            $rfq_str = 'RFQ-'.str_pad($rfq_id,4,'0',STR_PAD_LEFT);
            $conn->query("UPDATE rfq_headers SET rfq_number_str='$rfq_str' WHERE id=$rfq_id");

            foreach ($prod_ids as $i=>$pid) {
                $pid = intval($pid); $qty = intval($quantities[$i]??1);
                if ($pid>0 && $qty>0)
                    $conn->query("INSERT INTO rfq_items (rfq_id,product_id,quantity_needed) VALUES ($rfq_id,$pid,$qty)");
            }
            foreach ($sup_ids as $sid) {
                $sid   = intval($sid);
                $token = bin2hex(random_bytes(16));
                $conn->query("INSERT INTO rfq_responses (rfq_id,supplier_id,status,response_token) VALUES ($rfq_id,$sid,'Pending','$token')");
            }
            $conn->query("UPDATE rfq_headers SET status='Sent' WHERE id=$rfq_id");
            log_activity($conn,'Create','RFQ',$rfq_str,"Created RFQ for ".count($sup_ids)." supplier(s)");
            $conn->commit();
            header("Location: rfq.php?msg=created&rfq=$rfq_str"); exit();
        } catch (Exception $e) { $conn->rollback(); $error='DB error: '.$e->getMessage(); }
    }
}

// Convert accepted response to PO
if (isset($_GET['action']) && $_GET['action']==='convert' && isset($_GET['response_id'])) {
    $resp_id = intval($_GET['response_id']);
    $resp    = $conn->query("SELECT r.*,rfq.rfq_number_str FROM rfq_responses r JOIN rfq_headers rfq ON rfq.id=r.rfq_id WHERE r.id=$resp_id")->fetch_assoc();
    if ($resp) {
        // Mark accepted
        $conn->query("UPDATE rfq_responses SET status='Accepted' WHERE id=$resp_id");
        $conn->query("UPDATE rfq_responses SET status='Rejected' WHERE rfq_id={$resp['rfq_id']} AND id!=$resp_id");
        $conn->query("UPDATE rfq_headers SET status='Converted' WHERE id={$resp['rfq_id']}");
        log_activity($conn,'Convert','RFQ',$resp['rfq_number_str'],"Converted to PO from response $resp_id");
        header("Location: add_purchase_order.php?rfq_response=$resp_id"); exit();
    }
}

// Cancel RFQ
if (isset($_GET['action']) && $_GET['action']==='cancel' && isset($_GET['id'])) {
    $conn->query("UPDATE rfq_headers SET status='Cancelled' WHERE id=".intval($_GET['id']));
    header("Location: rfq.php"); exit();
}

// Fetch RFQs
$rfqs = [];
$res  = $conn->query("SELECT rh.*, u.name AS req_by_name,
    (SELECT COUNT(*) FROM rfq_responses rr WHERE rr.rfq_id=rh.id) AS supplier_count,
    (SELECT COUNT(*) FROM rfq_responses rr WHERE rr.rfq_id=rh.id AND rr.status='Responded') AS responded_count
    FROM rfq_headers rh LEFT JOIN users u ON u.id=rh.requested_by ORDER BY rh.id DESC");
while ($r=$res->fetch_assoc()) $rfqs[]=$r;

// Suppliers & products for modal
$suppliers = []; $sr=$conn->query("SELECT id,supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name");
while ($r=$sr->fetch_assoc()) $suppliers[]=$r;
$products  = []; $pr=$conn->query("SELECT id,product_name,sku_code FROM products WHERE status='Available' ORDER BY product_name");
while ($r=$pr->fetch_assoc()) $products[]=$r;

$page_title='Request for Quotation'; $current_page='rfq.php';
include 'includes/header.php';
?>
<style>
.rfq-table { width:100%; border-collapse:collapse; }
.rfq-table th { font-size:12px; font-weight:600; color:var(--text-muted); padding:10px 14px; border-bottom:1px solid var(--border-color); text-align:left; }
.rfq-table td { font-size:13px; padding:10px 14px; border-bottom:1px solid var(--border-color); }
.rfq-table tr:hover td { background:var(--bg-light); }
.status-pill { font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.s-draft     { background:rgba(107,114,128,.1); color:#6b7280; }
.s-sent      { background:rgba(59,130,246,.1); color:#2563eb; }
.s-responses { background:rgba(245,158,11,.12); color:#d97706; }
.s-converted { background:rgba(16,185,129,.12); color:#059669; }
.s-cancelled { background:rgba(239,68,68,.1); color:#dc2626; }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left"><h1 style="font-size:24px;">Request for Quotation</h1></div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if (isset($_GET['success'])): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);">
    RFQ <?= htmlspecialchars($_GET['rfq']??'') ?> created and sent to suppliers.
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--danger-color);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px;">
    <p style="font-size:13px;color:var(--text-muted);">Send quotation requests to multiple suppliers and convert the best price to a purchase order.</p>
    <button onclick="document.getElementById('createRFQModal').style.display='flex'" class="btn btn-primary" style="padding:8px 16px;font-size:13px;font-weight:600;white-space:nowrap;">
        + Create RFQ
    </button>
</div>

<div style="padding:0 24px 40px;overflow-x:auto;">
<?php if (!empty($rfqs)): ?>
<table class="rfq-table">
    <thead><tr>
        <th>RFQ #</th><th>Requested By</th><th>Suppliers</th><th>Responses</th><th>Deadline</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rfqs as $rfq):
        $sc_map=['Draft'=>'s-draft','Sent'=>'s-sent','Responses Received'=>'s-responses','Converted'=>'s-converted','Cancelled'=>'s-cancelled'];
        $sc=$sc_map[$rfq['status']]??'s-draft';
    ?>
    <tr>
        <td style="font-family:monospace;font-weight:600;"><?= htmlspecialchars($rfq['rfq_number_str']) ?></td>
        <td><?= htmlspecialchars($rfq['req_by_name']??'—') ?></td>
        <td><?= $rfq['supplier_count'] ?></td>
        <td>
            <?php if ($rfq['supplier_count']>0): ?>
            <span style="font-size:12px;"><?= $rfq['responded_count'] ?>/<?= $rfq['supplier_count'] ?> responded</span>
            <?php if ($rfq['responded_count']>0 && $rfq['status']==='Sent'): ?>
            <a href="rfq.php?action=view_responses&id=<?= $rfq['id'] ?>" style="font-size:11px;margin-left:6px;color:var(--primary-color);">Compare →</a>
            <?php endif; ?>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;color:var(--text-muted)"><?= $rfq['deadline_date'] ? date('d M Y',strtotime($rfq['deadline_date'])) : '—' ?></td>
        <td><span class="status-pill <?= $sc ?>"><?= $rfq['status'] ?></span></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="rfq.php?action=view&id=<?= $rfq['id'] ?>" style="font-size:12px;padding:4px 10px;border:1px solid var(--border-color);border-radius:5px;text-decoration:none;color:var(--text-dark);">View</a>
            <?php if (!in_array($rfq['status'],['Converted','Cancelled'])): ?>
            <a href="rfq.php?action=cancel&id=<?= $rfq['id'] ?>" style="font-size:12px;padding:4px 10px;border:1px solid var(--border-color);border-radius:5px;text-decoration:none;color:var(--danger-color);" onclick="return confirm('Cancel this RFQ?')">Cancel</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<div style="text-align:center;padding:80px;color:var(--text-muted);">
    <svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" fill="none" stroke-width="1.5" style="margin-bottom:12px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <h3 style="margin-bottom:8px;">No RFQs yet</h3>
    <p style="font-size:14px;margin-bottom:20px;">Create an RFQ to compare supplier quotes before placing a purchase order.</p>
</div>
<?php endif; ?>
</div>

<!-- Create RFQ Modal -->
<div id="createRFQModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:40px 16px;">
<div style="background:var(--card-bg);border-radius:14px;padding:28px;width:700px;max-width:95vw;margin:auto;">
    <h3 style="margin-bottom:20px;">Create Request for Quotation</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create_rfq">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
            <div>
                <label class="form-label">Deadline Date</label>
                <input type="date" name="deadline_date" class="form-input">
            </div>
            <div>
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-input" placeholder="Any special requirements…">
            </div>
        </div>
        <div style="margin-bottom:18px;">
            <label class="form-label">Suppliers to send RFQ to <span style="color:#ef4444">*</span></label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:120px;overflow-y:auto;padding:10px;border:1px solid var(--border-color);border-radius:8px;">
                <?php foreach ($suppliers as $s): ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer;">
                    <input type="checkbox" name="supplier_id[]" value="<?= $s['id'] ?>"> <?= htmlspecialchars($s['supplier_name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="margin-bottom:18px;">
            <label class="form-label">Products Needed <span style="color:#ef4444">*</span></label>
            <div id="rfq-prod-rows"></div>
            <button type="button" onclick="addRFQProd()" style="margin-top:6px;background:none;border:1.5px dashed var(--border-color);border-radius:6px;padding:6px 14px;cursor:pointer;color:var(--primary-color);font-size:13px;width:100%;">+ Add Product</button>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('createRFQModal').style.display='none'" style="padding:9px 16px;border:1px solid var(--border-color);border-radius:7px;background:none;cursor:pointer;">Cancel</button>
            <button type="submit" class="btn btn-primary" style="padding:9px 20px;">Create &amp; Send RFQ</button>
        </div>
    </form>
</div>
</div>

<script>
const PRODS = <?= json_encode($products) ?>;
let rfqRowCnt=0;
function addRFQProd(){
    const i=rfqRowCnt++;
    const opts=PRODS.map(p=>`<option value="${p.id}">${p.product_name} (${p.sku_code})</option>`).join('');
    const d=document.createElement('div');
    d.style.cssText='display:grid;grid-template-columns:1fr 80px 28px;gap:8px;margin-bottom:6px;';
    d.innerHTML=`<select name="product_id[]" class="form-select"><option value="">— Select —</option>${opts}</select>
        <input type="number" name="quantity[]" class="form-input" value="1" min="1" placeholder="Qty">
        <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:var(--danger-color);font-size:16px;">✕</button>`;
    document.getElementById('rfq-prod-rows').appendChild(d);
}
addRFQProd();
</script>
<?php include 'includes/footer.php'; ?>
