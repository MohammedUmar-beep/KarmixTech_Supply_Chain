<?php
$search_options = array(
    'warehouse_name' => 'Name',
    'warehouse_id_str' => 'Warehouse ID',
    'manager_name' => 'Manager Name',
    'location' => 'Location',
    'contact_number' => 'Phone',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM warehouses WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM warehouses WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';
            $conn->query("UPDATE warehouses SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: warehouses.php");
    exit();
}

// Fetch Metrics
$active_count = $conn->query("SELECT COUNT(*) FROM warehouses WHERE status='Active'")->fetch_row()[0] ?? 0;
$inactive_count = $conn->query("SELECT COUNT(*) FROM warehouses WHERE status='Inactive'")->fetch_row()[0] ?? 0;
$deleted_count = $conn->query("SELECT COUNT(*) FROM warehouses WHERE status='Deleted'")->fetch_row()[0] ?? 0;

$total_warehouses = $active_count + $inactive_count + $deleted_count;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Warehouses
$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];

    if (in_array($col, $allowed_cols)) {
        $search_sql = "WHERE $col LIKE '%$search%'";
    }
}
// Apply filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $search_sql .= ($search_sql == '') ? "WHERE status = '$status_filter'" : " AND status = '$status_filter'";
}

$warehouses = $conn->query("SELECT * FROM warehouses $search_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");

// Generate next Warehouse ID
$next_id_res = $conn->query("SELECT MAX(id) FROM warehouses");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_warehouse_id = 'WH-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$page_title = 'Warehouses';
include 'includes/header.php';
?>

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
            <h1>Warehouses</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Warehouses</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_warehouses ?></div>
            <div class="page-metric-trend">All registered</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Active Warehouses</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $active_count ?></div>
            <div class="page-metric-trend">Currently operational</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Inactive Warehouses</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $inactive_count ?></div>
            <div class="page-metric-trend">Temporarily closed</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Deleted / Archived</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= $deleted_count ?></div>
            <div class="page-metric-trend">Removed from system</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Active Warehouses</h2>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Active' => 'Active', 'Inactive' => 'Inactive'] as $val => $label):
                        $href = $val === '' ? 'warehouses.php' : "warehouses.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addWarehouseModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add New Warehouse
                </button>
                <a href="export.php?module=warehouses&format=csv" class="btn btn-primary"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px; text-decoration:none; color:white; height:38px; font-weight:500;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Export
                </a>
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Warehouse Name</th>
                    <th>Warehouse ID</th>
                    <th>Location</th>
                    <th>Capacity <br><span style="font-size:11px;">(In SKUs)</span></th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($warehouses->num_rows > 0): ?>
                    <?php while ($row = $warehouses->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="warehouses">
                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="font-weight: 500;">
                                <?= htmlspecialchars($row['warehouse_name']) ?>
                            </td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:13px;">
                                <?= htmlspecialchars($row['warehouse_id_str']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-muted);">
                                <?= htmlspecialchars($row['state']) ?> <span
                                    style="background:var(--bg-light); border:1px solid var(--border-color); border-radius:4px; padding:2px 4px; font-size:10px; cursor:pointer;"
                                    title="<?= htmlspecialchars($row['address']) ?>">...</span>
                            </td>
                            <td>
                                <?= number_format($row['capacity']) ?>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_warehouse.php?id=<?= $row['id'] ?>" title="View Inventory & Staff">
                                        <svg class="inbox-icon" viewBox="0 0 24 24" style="stroke: var(--primary-color);">
                                            <polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline>
                                            <path
                                                d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z">
                                            </path>
                                        </svg>
                                    </a>
                                    <a href="edit_warehouse.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon"
                                            viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                    <a href="warehouses.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this warehouse?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="warehouses.php?action=toggle&id=<?= $row['id'] ?>" title="Toggle Active/Inactive">
                                        <svg class="toggle-icon <?= $row['status'] == 'Active' ? 'active' : 'inactive' ?>"
                                            viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;">
                                            <polyline points="23 4 23 10 17 10"></polyline>
                                            <polyline points="1 20 1 14 7 14"></polyline>
                                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15">
                                            </path>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="7"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg><div class="empty-state-title">No Warehouses Yet</div><div class="empty-state-desc">Add your first warehouse to manage inventory locations.</div><a href="add_warehouse.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Warehouse</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_warehouses, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Warehouse Modal -->
<div class="modal-overlay" id="addWarehouseModal">
    <div class="modal-content modal-warehouse">
        <div class="modal-header">
            <h2>Add New Warehouse</h2>
            <button class="close-modal"
                onclick="document.getElementById('addWarehouseModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_warehouse.php" method="POST">
            <div class="modal-body form-grid">
                <div class="form-grid-2" style="display:grid; gap:20px;">
                    <div>
                        <label class="form-label">Warehouse Name</label>
                        <input type="text" name="warehouse_name" class="form-input" placeholder="Enter Full Name"
                            required>
                    </div>
                    <div>
                        <label class="form-label">Warehouse ID</label>
                        <input type="text" name="warehouse_id_str" class="form-input" value="<?= $gen_warehouse_id ?>"
                            readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                </div>

                <div>
                    <label class="form-label">Warehouse Contact number</label>
                    <div class="input-group" style="display:flex;">
                        <select name="country_code" class="form-input"
                            style="width: auto; border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; background: var(--bg-light);">
                            <option value="+1">US (+1)</option>
                            <option value="+44">UK (+44)</option>
                            <option value="+91" selected>IN (+91)</option>
                            <option value="+61">AU (+61)</option>
                        </select>
                        <input type="text" name="contact_num" class="form-input"
                            style="flex:1; border-top-left-radius: 0; border-bottom-left-radius: 0;"
                            placeholder="955 000 0000" required>
                    </div>
                </div>

                <div>
                    <label class="form-label">Email-Id</label>
                    <input type="email" name="email" class="form-input" placeholder="Enter your Email-ID" required>
                </div>

                <div class="form-grid-2" style="display:grid; gap:20px;">
                    <div>
                        <label class="form-label">State</label>
                        <select name="state" class="form-input" required>
                            <option value="" disabled selected>Select your state</option>
                            <option value="Andhra Pradesh">Andhra Pradesh</option>
                            <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                            <option value="Assam">Assam</option>
                            <option value="Bihar">Bihar</option>
                            <option value="Chandigarh">Chandigarh</option>
                            <option value="Chhattisgarh">Chhattisgarh</option>
                            <option value="Delhi">Delhi</option>
                            <option value="Goa">Goa</option>
                            <option value="Gujarat">Gujarat</option>
                            <option value="Haryana">Haryana</option>
                            <option value="Himachal Pradesh">Himachal Pradesh</option>
                            <option value="Jammu and Kashmir">Jammu and Kashmir</option>
                            <option value="Jharkhand">Jharkhand</option>
                            <option value="Karnataka">Karnataka</option>
                            <option value="Kerala">Kerala</option>
                            <option value="Madhya Pradesh">Madhya Pradesh</option>
                            <option value="Maharashtra">Maharashtra</option>
                            <option value="Manipur">Manipur</option>
                            <option value="Meghalaya">Meghalaya</option>
                            <option value="Mizoram">Mizoram</option>
                            <option value="Nagaland">Nagaland</option>
                            <option value="Odisha">Odisha</option>
                            <option value="Punjab">Punjab</option>
                            <option value="Rajasthan">Rajasthan</option>
                            <option value="Sikkim">Sikkim</option>
                            <option value="Tamil Nadu">Tamil Nadu</option>
                            <option value="Telangana">Telangana</option>
                            <option value="Tripura">Tripura</option>
                            <option value="Uttar Pradesh">Uttar Pradesh</option>
                            <option value="Uttarakhand">Uttarakhand</option>
                            <option value="West Bengal">West Bengal</option>
                            <option value="Andaman and Nicobar Islands">Andaman and Nicobar</option>
                            <option value="Dadra and Nagar Haveli and Daman and Diu">Daman & Diu</option>
                            <option value="Lakshadweep">Lakshadweep</option>
                            <option value="Puducherry">Puducherry</option>
                            <option value="Ladakh">Ladakh</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" class="form-input" placeholder="Enter Pincode" required>
                    </div>
                </div>

                <div>
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-input" placeholder="Enter your Address" required>
                </div>

                <div>
                    <label class="form-label">Capacity (in SKUs)</label>
                    <input type="number" name="capacity" class="form-input" placeholder="Enter capacity" required>
                </div>
            </div>
            <div class="modal-footer">

                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Add
                    Warehouse</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addWarehouseModal').classList.add('active');
            window.history.replaceState({}, document.title, "warehouses.php");
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>