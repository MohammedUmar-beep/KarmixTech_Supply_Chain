<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Handle partial receipt — creates backorder if needed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'')==='receive_items') {
    $po_id     = intval($_POST['po_id']);
    $item_ids  = $_POST['item_id']   ?? [];
    $received  = $_POST['qty_recv']  ?? [];
    $user_id   = intval($_SESSION['user_id']);

    $po = $conn->query("SELECT * FROM purchase_orders WHERE id=$po_id")->fetch_assoc();
    if (!$po) { header("Location: backorders.php"); exit(); }

    $conn->begin_transaction();
    try {
        $has_pending = false;
        $backorder_items = [];

        foreach ($item_ids as $idx => $item_id) {
            $item_id  = intval($item_id);
            $recv_qty = intval($received[$idx] ?? 0);
            $item     = $conn->query("SELECT * FROM purchase_order_items WHERE id=$item_id AND purchase_order_id=$po_id")->fetch_assoc();
            if (!$item) continue;
            $ordered  = intval($item['quantity']);
            $already  = intval($item['qty_received']);
            $total_recv = $already + $recv_qty;
            $pending  = max(0, $ordered - $total_recv);

            $conn->query("UPDATE purchase_order_items SET qty_received=$total_recv, qty_pending=$pending WHERE id=$item_id");

            if ($recv_qty > 0) {
                $pid = intval($item['product_id']);
                $conn->query("UPDATE products SET stock_level=stock_level+$recv_qty WHERE id=$pid");
            }
            if ($pending > 0) {
                $has_pending = true;
                $backorder_items[] = ['product_id'=>$item['product_id'],'quantity'=>$pending,'unit_price'=>$item['unit_price'],'subtotal'=>$item['unit_price']*$pending];
            }
        }

        // Create backorder PO if items still pending
        if ($has_pending) {
            $bo_str_tmp = 'PO-BO-TMP';
            $conn->query("INSERT INTO purchase_orders (purchase_id_str,supplier_id,warehouse_id,order_date,total_amount,status,parent_po_id,is_backorder)
                VALUES ('$bo_str_tmp',{$po['supplier_id']},{$po['warehouse_id']},NOW(),0,'Draft',$po_id,1)");
            $bo_id  = $conn->insert_id;
            $bo_str = 'BO-'.str_pad($bo_id,4,'0',STR_PAD_LEFT);
            $conn->query("UPDATE purchase_orders SET purchase_id_str='$bo_str' WHERE id=$bo_id");
            $bo_total = 0;
            $ist = $conn->prepare("INSERT INTO purchase_order_items (purchase_order_id,product_id,quantity,unit_price,subtotal,qty_pending) VALUES (?,?,?,?,?,?)");
            foreach ($backorder_items as $bi) {
                $ist->bind_param("iidddi",$bo_id,$bi['product_id'],$bi['quantity'],$bi['unit_price'],$bi['subtotal'],$bi['quantity']);
                $ist->execute();
                $bo_total += $bi['subtotal'];
            }
            $ist->close();
            $conn->query("UPDATE purchase_orders SET total_amount=$bo_total WHERE id=$bo_id");
            log_activity($conn,'Create','Backorder',$bo_str,"Auto-created backorder from PO {$po['purchase_id_str']}");
        }

        // Update original PO status
        $all_recv = $conn->query("SELECT SUM(qty_pending) FROM purchase_order_items WHERE purchase_order_id=$po_id")->fetch_row()[0];
        $new_status = ($all_recv == 0) ? 'Received' : 'Issued';
        $conn->query("UPDATE purchase_orders SET status='$new_status' WHERE id=$po_id");
        log_activity($conn,'Update','PurchaseOrder',$po['purchase_id_str'],"Partial receipt recorded");
        $conn->commit();
        header("Location: backorders.php?msg=received".($has_pending?'&backorder=1':'')); exit();
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Error: '.$e->getMessage();
    }
}

// Fetch backorders
$backorders=[];
$res=$conn->query("SELECT po.*, s.supplier_name, parent.purchase_id_str AS parent_str
    FROM purchase_orders po
    JOIN suppliers s ON s.id=po.supplier_id
    LEFT JOIN purchase_orders parent ON parent.id=po.parent_po_id
    WHERE po.is_backorder=1 AND po.status!='Received'
    ORDER BY po.id DESC");
while ($r=$res->fetch_assoc()) $backorders[]=$r;

// Fetch open POs needing receipt
$open_pos=[];
$res2=$conn->query("SELECT po.*, s.supplier_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id
    WHERE po.status IN ('Issued','Received') AND po.is_backorder=0
    ORDER BY po.id DESC LIMIT 50");
while ($r=$res2->fetch_assoc()) $open_pos[]=$r;

$page_title='Backorders'; $current_page='purchase_orders.php';
include 'includes/header.php';
?>
<style>
.bo-table { width:100%; border-collapse:collapse; }
.bo-table th { font-size:12px; color:var(--text-muted); font-weight:600; padding:10px 14px; border-bottom:1px solid var(--border-color); text-align:left; }
.bo-table td { font-size:13px; padding:10px 14px; border-bottom:1px solid var(--border-color); }
.bo-table tr:hover td { background:var(--bg-light); }
.status-pill { font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.recv-grid { display:grid; grid-template-columns:1fr 80px 80px 80px; gap:10px; align-items:center; font-size:13px; margin-bottom:6px; }
.recv-grid.header { font-size:11px; color:var(--text-muted); font-weight:600; margin-bottom:4px; }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left"><h1 style="font-size:24px;">Backorders</h1></div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if (isset($_GET['success'])): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);">
    Receipt recorded.<?= isset($_GET['backorder'])?' A backorder has been automatically created for remaining items.':' All items fully received.' ?>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--danger-color);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Receive items button -->
<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px 8px;">
    <h2 style="font-size:16px;font-weight:600;">Active Backorders</h2>
    <button onclick="document.getElementById('receiveModal').style.display='flex'" class="btn btn-primary" style="padding:8px 16px;font-size:13px;font-weight:600;">
        Receive Purchase Order Items
    </button>
</div>

<div style="padding:0 24px 30px;overflow-x:auto;">
<?php if (!empty($backorders)): ?>
<table class="bo-table">
    <thead><tr>
        <th>Backorder #</th><th>Original PO</th><th>Supplier</th><th>Items</th><th>Total</th><th>Created</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($backorders as $bo): ?>
    <tr>
        <td style="font-family:monospace;font-weight:600;"><?= htmlspecialchars($bo['purchase_id_str']) ?></td>
        <td style="font-family:monospace;color:var(--text-muted)"><?= htmlspecialchars($bo['parent_str']??'—') ?></td>
        <td><?= htmlspecialchars($bo['supplier_name']) ?></td>
        <td><?php
            $ic=$conn->query("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id={$bo['id']}")->fetch_row()[0];
            echo $ic.' items';
        ?></td>
        <td><?= $currency[\'symbol\'] . number_format($bo['total_amount'],2) ?></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y',strtotime($bo['created_at'])) ?></td>
        <td>
            <a href="view_purchase_order.php?id=<?= $bo['id'] ?>" style="font-size:12px;padding:4px 10px;border:1px solid var(--border-color);border-radius:5px;text-decoration:none;color:var(--text-dark);">View PO</a>
            <button onclick="openReceive(<?= $bo['id'] ?>, '<?= htmlspecialchars($bo['purchase_id_str']) ?>')" style="font-size:12px;padding:4px 10px;border:1px solid var(--primary-color);border-radius:5px;background:none;cursor:pointer;color:var(--primary-color);margin-left:4px;">Receive</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<div style="text-align:center;padding:50px;color:var(--text-muted);">No active backorders. All purchase orders are fully received.</div>
<?php endif; ?>
</div>

<!-- Receive Items Modal -->
<div id="receiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:40px 16px;">
<div style="background:var(--card-bg);border-radius:14px;padding:28px;width:700px;max-width:95vw;margin:auto;">
    <h3 style="margin-bottom:6px;">Receive Purchase Order Items</h3>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Enter how many units you actually received. Any shortfall auto-creates a backorder.</p>
    <form method="POST" id="receiveForm">
        <input type="hidden" name="action" value="receive_items">
        <input type="hidden" name="po_id" id="recv_po_id">
        <div style="margin-bottom:14px;">
            <label class="form-label">Select Purchase Order</label>
            <select class="form-select" id="recv_po_select" onchange="loadPOItems(this.value)">
                <option value="">— Select PO —</option>
                <?php foreach ($open_pos as $po): ?>
                <option value="<?= $po['id'] ?>"><?= htmlspecialchars($po['purchase_id_str']) ?> — <?= htmlspecialchars($po['supplier_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="recv_items_wrap" style="display:none;">
            <div class="recv-grid header"><span>Product</span><span style="text-align:center">Ordered</span><span style="text-align:center">Already recv.</span><span style="text-align:center">Now receiving</span></div>
            <div id="recv_items_list"></div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
            <button type="button" onclick="document.getElementById('receiveModal').style.display='none'" style="padding:9px 16px;border:1px solid var(--border-color);border-radius:7px;background:none;cursor:pointer;">Cancel</button>
            <button type="submit" class="btn btn-primary" style="padding:9px 20px;" id="recv_submit" disabled>Record Receipt</button>
        </div>
    </form>
</div>
</div>

<script>
function openReceive(po_id, po_str) {
    document.getElementById('recv_po_id').value   = po_id;
    document.getElementById('recv_po_select').value = po_id;
    document.getElementById('receiveModal').style.display = 'flex';
    loadPOItems(po_id);
}
function loadPOItems(po_id) {
    if (!po_id) return;
    document.getElementById('recv_po_id').value = po_id;
    fetch('ajax_po_items.php?po_id='+po_id)
        .then(r=>r.json())
        .then(items=>{
            let html='';
            items.forEach(it=>{
                const pending=parseInt(it.quantity)-parseInt(it.qty_received||0);
                html+=`<div class="recv-grid" style="border-bottom:1px solid var(--border-color);padding:6px 0;">
                    <span>${it.product_name}</span>
                    <input type="hidden" name="item_id[]" value="${it.id}">
                    <span style="text-align:center;color:var(--text-muted)">${it.quantity}</span>
                    <span style="text-align:center;color:var(--text-muted)">${it.qty_received||0}</span>
                    <input type="number" name="qty_recv[]" class="form-input" value="${pending}" min="0" max="${pending}" style="text-align:center;padding:6px;">
                </div>`;
            });
            document.getElementById('recv_items_list').innerHTML = html;
            document.getElementById('recv_items_wrap').style.display='block';
            document.getElementById('recv_submit').disabled = false;
        });
}
</script>
<?php include 'includes/footer.php'; ?>
