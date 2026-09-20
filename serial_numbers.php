<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Handle bulk add serials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'bulk_add') {
        $pid    = intval($_POST['product_id']);
        $serials_raw = trim($_POST['serials'] ?? '');
        $lines  = array_filter(array_map('trim', explode("\n", $serials_raw)));
        $added  = 0; $dupes = 0;
        foreach ($lines as $sn) {
            $sn = $conn->real_escape_string($sn);
            if ($conn->query("INSERT IGNORE INTO product_serials (product_id, serial_number) VALUES ($pid, '$sn')")) {
                if ($conn->affected_rows > 0) {
                    $added++;
                    $conn->query("INSERT INTO serial_movements (serial_id,movement_type,from_status,to_status,moved_by) VALUES ({$conn->insert_id},'Purchase','','Available',{$_SESSION['user_id']})");
                } else $dupes++;
            }
        }
        header("Location: serial_numbers.php?msg=added&added=$added&dupes=$dupes"); exit();
    }
    if ($_POST['action'] === 'update_status') {
        $sid     = intval($_POST['serial_id']);
        $new_st  = $conn->real_escape_string($_POST['new_status']);
        $notes   = $conn->real_escape_string($_POST['notes'] ?? '');
        $cur_st  = $conn->query("SELECT status FROM product_serials WHERE id=$sid")->fetch_assoc()['status'] ?? '';
        $conn->query("UPDATE product_serials SET status='$new_st', notes='$notes' WHERE id=$sid");
        $conn->query("INSERT INTO serial_movements (serial_id,movement_type,from_status,to_status,moved_by,notes) VALUES ($sid,'Adjustment','$cur_st','$new_st',{$_SESSION['user_id']},'$notes')");
        header("Location: serial_numbers.php?msg=updated"); exit();
    }
}

$filter_product = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$filter_status  = $conn->real_escape_string($_GET['status'] ?? '');
$where = "1=1";
if ($filter_product) $where .= " AND ps.product_id=$filter_product";
if ($filter_status)  $where .= " AND ps.status='$filter_status'";

$serials = [];
$res = $conn->query("SELECT ps.*, p.product_name, p.sku_code FROM product_serials ps
    JOIN products p ON p.id=ps.product_id WHERE $where ORDER BY ps.id DESC LIMIT 500");
while ($r = $res->fetch_assoc()) $serials[] = $r;

$products_res = $conn->query("SELECT id, product_name, sku_code FROM products WHERE is_serialised=1 ORDER BY product_name");
$serialised_products = [];
while ($p = $products_res->fetch_assoc()) $serialised_products[] = $p;

$all_products_res = $conn->query("SELECT id, product_name, sku_code FROM products WHERE status='Available' ORDER BY product_name");
$all_products = [];
while ($p = $all_products_res->fetch_assoc()) $all_products[] = $p;

// Status counts
$cnt = ['Available'=>0,'Sold'=>0,'Returned'=>0,'Damaged'=>0,'Reserved'=>0];
$cr = $conn->query("SELECT status, COUNT(*) c FROM product_serials GROUP BY status");
while ($r = $cr->fetch_assoc()) $cnt[$r['status']] = $r['c'];

$page_title   = 'Serial Numbers';
$current_page = 'serial_numbers.php';
include 'includes/header.php';
?>
<style>
.sn-table { width:100%; border-collapse:collapse; }
.sn-table th { font-size:12px; font-weight:600; color:var(--text-muted); padding:10px 14px; border-bottom:1px solid var(--border-color); text-align:left; }
.sn-table td { font-size:13px; padding:10px 14px; border-bottom:1px solid var(--border-color); }
.sn-table tr:hover td { background:var(--bg-light); }
.sn-serial { font-family:monospace; font-weight:600; font-size:13px; }
.status-pill { font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.s-available { background:rgba(16,185,129,.12); color:#059669; }
.s-sold      { background:rgba(109,74,255,.1); color:var(--primary-color); }
.s-returned  { background:rgba(245,158,11,.12); color:#d97706; }
.s-damaged   { background:rgba(239,68,68,.1); color:#dc2626; }
.s-reserved  { background:rgba(59,130,246,.1); color:#2563eb; }
.stat-card { flex:1; background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:14px 16px; text-align:center; }
.stat-num  { font-size:22px; font-weight:700; }
.stat-lbl  { font-size:11px; color:var(--text-muted); margin-top:2px; }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left"><h1 style="font-size:24px;">Serial Numbers</h1></div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if (isset($_GET['success'])): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);">
    <?php
    if ($_GET['success']==='added') echo "Added {$_GET['added']} serial(s). ".($_GET['dupes']>0?"{$_GET['dupes']} duplicate(s) skipped.":'');
    elseif ($_GET['success']==='updated') echo 'Serial status updated.';
    ?>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="page-metrics-grid" style="padding: 0 24px; margin-bottom: 20px; grid-template-columns: repeat(5, 1fr);">
    <a href="serial_numbers.php?status=Available" class="page-metric-card border-green" style="text-decoration:none; cursor:pointer;">
        <div class="page-metric-title">Available</div>
        <div class="page-metric-value" style="color:#10b981;"><?= $cnt['Available'] ?></div>
        <div class="page-metric-trend">Ready to assign</div>
    </a>
    <a href="serial_numbers.php?status=Sold" class="page-metric-card border-blue" style="text-decoration:none; cursor:pointer;">
        <div class="page-metric-title">Sold</div>
        <div class="page-metric-value" style="color:var(--primary-color);"><?= $cnt['Sold'] ?></div>
        <div class="page-metric-trend">Dispatched to customers</div>
    </a>
    <a href="serial_numbers.php?status=Returned" class="page-metric-card border-yellow" style="text-decoration:none; cursor:pointer;">
        <div class="page-metric-title">Returned</div>
        <div class="page-metric-value" style="color:#f59e0b;"><?= $cnt['Returned'] ?></div>
        <div class="page-metric-trend">Back in warehouse</div>
    </a>
    <a href="serial_numbers.php?status=Damaged" class="page-metric-card border-red" style="text-decoration:none; cursor:pointer;">
        <div class="page-metric-title">Damaged</div>
        <div class="page-metric-value" style="color:#ef4444;"><?= $cnt['Damaged'] ?></div>
        <div class="page-metric-trend">Written off</div>
    </a>
    <a href="serial_numbers.php?status=Reserved" class="page-metric-card border-purple" style="text-decoration:none; cursor:pointer;">
        <div class="page-metric-title">Reserved</div>
        <div class="page-metric-value" style="color:#8b5cf6;"><?= $cnt['Reserved'] ?></div>
        <div class="page-metric-trend">On hold</div>
    </a>
</div>

<!-- Controls -->
<div style="display:flex;align-items:center;gap:12px;padding:16px 24px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;">
        <select name="product_id" class="form-select" style="width:220px;">
            <option value="">All serialised products</option>
            <?php foreach ($serialised_products as $sp): ?>
            <option value="<?= $sp['id'] ?>" <?= $filter_product==$sp['id']?'selected':'' ?>><?= htmlspecialchars($sp['product_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select" style="width:150px;">
            <option value="">All statuses</option>
            <?php foreach (array_keys($cnt) as $st): ?>
            <option value="<?= $st ?>" <?= $filter_status===$st?'selected':'' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:8px 16px;">Filter</button>
        <a href="serial_numbers.php" style="padding:8px 14px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;text-decoration:none;color:var(--text-dark);">Clear</a>
    </form>
    <button onclick="document.getElementById('addSerialsModal').style.display='flex'" class="btn btn-primary" style="padding:8px 16px;font-size:13px;font-weight:600;">
        + Add Serials
    </button>
    <!-- Enable serialisation on a product -->
    <button onclick="document.getElementById('enableSerial').style.display='flex'" style="padding:8px 14px;border:1px solid var(--border-color);border-radius:6px;font-size:13px;cursor:pointer;background:none;color:var(--text-dark);">
        Enable on Product
    </button>
</div>

<!-- Serial Table -->
<div style="padding:0 24px 40px;overflow-x:auto;">
    <?php if (!empty($serials)): ?>
    <table class="sn-table">
        <thead>
            <tr>
                <th>Serial Number</th><th>Product</th><th>Status</th><th>Created</th><th>Notes</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($serials as $s): $sc = 's-'.strtolower($s['status']); ?>
        <tr>
            <td><span class="sn-serial"><?= htmlspecialchars($s['serial_number']) ?></span></td>
            <td><?= htmlspecialchars($s['product_name']) ?><br><span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($s['sku_code']) ?></span></td>
            <td><span class="status-pill <?= $sc ?>"><?= $s['status'] ?></span></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($s['notes'] ?? '—') ?></td>
            <td>
                <button onclick="openEditSerial(<?= $s['id'] ?>, '<?= $s['status'] ?>')" style="font-size:12px;padding:4px 10px;border:1px solid var(--border-color);border-radius:5px;background:none;cursor:pointer;color:var(--text-dark);">Edit Status</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:60px;color:var(--text-muted);">No serials found. Add serials to a product to get started.</div>
    <?php endif; ?>
</div>

<!-- Add Serials Modal -->
<div id="addSerialsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:var(--card-bg);border-radius:14px;padding:28px;width:500px;max-width:92vw;">
        <h3 style="margin-bottom:16px;">Add Serial Numbers</h3>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_add">
            <div style="margin-bottom:14px;">
                <label class="form-label">Product <span style="color:#ef4444">*</span></label>
                <select name="product_id" class="form-select" required>
                    <option value="">— Select product —</option>
                    <?php foreach ($all_products as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Serial Numbers (one per line)</label>
                <textarea name="serials" class="form-input" rows="8" placeholder="SN-001&#10;SN-002&#10;SN-003" required style="font-family:monospace;font-size:13px;"></textarea>
                <span style="font-size:11px;color:var(--text-muted);">Paste or type one serial number per line. Duplicates are automatically skipped.</span>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('addSerialsModal').style.display='none'" style="padding:9px 16px;border:1px solid var(--border-color);border-radius:7px;background:none;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 20px;">Add Serials</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Status Modal -->
<div id="editSerialModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:var(--card-bg);border-radius:14px;padding:28px;width:400px;max-width:92vw;">
        <h3 style="margin-bottom:16px;">Update Serial Status</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="serial_id" id="edit_serial_id">
            <div style="margin-bottom:14px;">
                <label class="form-label">New Status</label>
                <select name="new_status" class="form-select" id="edit_new_status">
                    <option value="Available">Available</option>
                    <option value="Reserved">Reserved</option>
                    <option value="Damaged">Damaged</option>
                    <option value="Returned">Returned</option>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-input" placeholder="Reason for change">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('editSerialModal').style.display='none'" style="padding:9px 16px;border:1px solid var(--border-color);border-radius:7px;background:none;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 20px;">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Enable serialisation modal -->
<div id="enableSerial" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:var(--card-bg);border-radius:14px;padding:28px;width:420px;max-width:92vw;">
        <h3 style="margin-bottom:10px;">Enable Serial Tracking</h3>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Mark a product as serialised so it appears in the product selector above.</p>
        <form method="POST" action="ajax_enable_serial.php">
            <select name="product_id" class="form-select" style="margin-bottom:14px;">
                <?php foreach ($all_products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('enableSerial').style.display='none'" style="padding:9px 16px;border:1px solid var(--border-color);border-radius:7px;background:none;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 20px;">Enable</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditSerial(id, currentStatus) {
    document.getElementById('edit_serial_id').value = id;
    document.getElementById('edit_new_status').value = currentStatus;
    document.getElementById('editSerialModal').style.display = 'flex';
}
</script>
<?php include 'includes/footer.php'; ?>
