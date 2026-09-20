<?php
$search_options = array(
    'tracking_number' => 'Tracking Number',
    'carrier' => 'Carrier',
    'order_id_str' => 'Order ID',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle Status)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM purchase_shipments WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status, shipment_number_str FROM purchase_shipments WHERE id = $id");
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $current_status = $row['status'];
            $shipment_number = $row['shipment_number_str'];

            $status_cycle = [
                'Pending'    => 'In Transit',
                'In Transit' => 'Shipped',
                'Shipped'    => 'Cancelled',
                'Cancelled'  => 'Pending',
            ];

            $new_status = isset($status_cycle[$current_status]) ? $status_cycle[$current_status] : 'Pending';
            $conn->query("UPDATE purchase_shipments SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: shipment.php");
    exit();
}

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Total For Pagination
$total_shipments_res = $conn->query("SELECT COUNT(*) FROM purchase_shipments");
$total_shipments = $total_shipments_res ? $total_shipments_res->fetch_row()[0] : 0;

// Fetch Data
$search_sql = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    if ($status_filter === 'Pending') {
        $search_sql = "WHERE sh.status = 'Pending'";
    } elseif ($status_filter === 'In Transit') {
        $search_sql = "WHERE sh.status = 'In Transit'";
    } elseif ($status_filter === 'Shipped') {
        $search_sql = "WHERE sh.status = 'Shipped'";
    } elseif ($status_filter === 'Cancelled') {
        $search_sql = "WHERE sh.status = 'Cancelled'";
    }
}

if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];

    if (in_array($col, $allowed_cols)) {
        if ($search_sql == '') {
            $search_sql = "WHERE sh.$col LIKE '%$search%'";
        } else {
            $search_sql .= " AND sh.$col LIKE '%$search%'";
        }
    }
}
$query = "
    SELECT sh.*, po.purchase_id_str, s.supplier_name 
    FROM purchase_shipments sh 
    LEFT JOIN purchase_orders po ON sh.purchase_order_id = po.id
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    $search_sql
    ORDER BY sh.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$shipments = $conn->query($query);

// Fetch dropdown data for Add Modal
$pos_res = $conn->query("SELECT id, purchase_id_str FROM purchase_orders ORDER BY created_at DESC");
$pos_list = [];
if ($pos_res) {
    while ($row = $pos_res->fetch_assoc()) {
        $pos_list[] = $row;
    }
}

$current_page = 'shipment.php';
$page_title = 'Inbound Shipments';
include 'includes/header.php';
?>
<style>
/* ── Shipment page styles ───────────────────────────── */
</style>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="dashboard.php"
                style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back
            </a>
            <h1>Inbound Shipments</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <?php
    $pending_shipment_res = $conn->query("SELECT COUNT(*) FROM purchase_shipments WHERE status='Pending'");
    $pending_shipments = $pending_shipment_res ? $pending_shipment_res->fetch_row()[0] : 0;

    $intransit_shipment_res = $conn->query("SELECT COUNT(*) FROM purchase_shipments WHERE status='In Transit'");
    $intransit_shipments = $intransit_shipment_res ? $intransit_shipment_res->fetch_row()[0] : 0;

    $shipped_shipment_res = $conn->query("SELECT COUNT(*) FROM purchase_shipments WHERE status='Shipped'");
    $shipped_shipments = $shipped_shipment_res ? $shipped_shipment_res->fetch_row()[0] : 0;
    ?>
    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Shipments</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_shipments ?></div>
            <div class="page-metric-trend">Inbound shipments</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Pending</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $pending_shipments ?></div>
            <div class="page-metric-trend">Awaiting dispatch</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">In Transit</div>
            <div class="page-metric-value" style="color:#3b82f6;"><?= $intransit_shipments ?></div>
            <div class="page-metric-trend">On the way</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Shipped</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $shipped_shipments ?></div>
            <div class="page-metric-trend">Successfully shipped</div>
        </div>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php
                    $current_tab = $_GET['status'] ?? '';
                    foreach (['' => 'All', 'Pending' => 'Pending', 'In Transit' => 'In Transit', 'Shipped' => 'Shipped', 'Cancelled' => 'Cancelled'] as $val => $label):
                        $href = $val === '' ? 'shipment.php' : 'shipment.php?status=' . urlencode($val);
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addShipmentModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    New Shipment Record
                </button>
            </div>
        </div>


        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Shipment #</th>
                    <th>Carrier</th>
                    <th>Tracking #</th>
                    <th>PO Reference</th>
                    <th>Expected</th>
                    <th>StatusBadge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($shipments && $shipments->num_rows > 0): ?>
                    <?php while ($row = $shipments->fetch_assoc()): ?>
                        <?php
                        ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="shipment">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['shipment_number_str']) ?>
                            </td>
                            <td style="font-size:14px; font-weight:500;">
                                <?= htmlspecialchars($row['carrier'] ?? 'N/A') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['tracking_number'] ?? 'N/A') ?>
                            </td>
                            <td style="font-size: 13px; font-weight: 500;">
                                <?= htmlspecialchars($row['purchase_id_str'] ?? 'N/A') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['expected_delivery'] ?? 'N/A') ?>
                            </td>
                            <td>
                                <?php echo statusBadge($row['status']); ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="shipment.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this shipment?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="shipment.php?action=toggle&id=<?= $row['id'] ?>" title="Cycle Status"><svg
                                            class="toggle-icon" viewBox="0 0 24 24" style="stroke: var(--primary-color);">
                                            <polyline points="1 4 1 10 7 10"></polyline>
                                            <polyline points="23 20 23 14 17 14"></polyline>
                                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15">
                                            </path>
                                        </svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg><div class="empty-state-title">No Shipments Yet</div><div class="empty-state-desc">Create a shipment to track incoming or outgoing goods.</div><a href="add_shipment.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Shipment</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_shipments, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Create Shipment Modal -->
<div class="modal-overlay" id="addShipmentModal">
    <div class="modal-content" style="max-width: 600px; width: 90%;">
        <div class="modal-header">
            <h2>Record New Shipment</h2>
            <button class="close-modal"
                onclick="document.getElementById('addShipmentModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_shipment.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Shipment Ref #</label>
                        <input type="text" name="shipment_number_str" class="form-input" placeholder="Ex: SHIP-001"
                            required>
                    </div>
                    <div>
                        <label class="form-label">Purchase Order</label>
                        <select name="purchase_order_id" class="form-input" required>
                            <option value="" disabled selected>Select PO</option>
                            <?php foreach ($pos_list as $po): ?>
                                <option value="<?= $po['id'] ?>">
                                    <?= htmlspecialchars($po['purchase_id_str']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Carrier</label>
                        <input type="text" name="carrier" class="form-input" placeholder="FedEx, UPS, etc.">
                    </div>
                    <div>
                        <label class="form-label">Tracking Number</label>
                        <input type="text" name="tracking_number" class="form-input" placeholder="ABC-12345">
                    </div>
                    <div>
                        <label class="form-label">Shipment Date</label>
                        <input type="date" name="shipment_date" class="form-input" value="<?= date('Y-m-d') ?>"
                            required>
                    </div>
                    <div>
                        <label class="form-label">Expected Delivery</label>
                        <input type="date" name="expected_delivery" class="form-input">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="Pending" selected>Pending</option>
                            <option value="In Transit">In Transit</option>
                            <option value="Shipped">Shipped</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px 40px; border-radius:8px; font-size:15px; font-weight:500;">Save
                    Shipment</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>