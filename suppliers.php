<?php
$search_options = array(
    'supplier_name' => 'Name',
    'supplier_id_str' => 'Supplier ID',
    'email' => 'Email',
    'phone' => 'Phone',
    'address' => 'Address',
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
        $conn->query("DELETE FROM suppliers WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM suppliers WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';
            $conn->query("UPDATE suppliers SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: suppliers.php");
    exit();
}

// Fetch Metrics
$total_res = $conn->query("SELECT COUNT(*) FROM suppliers");
$total_suppliers = $total_res ? $total_res->fetch_row()[0] : 0;

$active_res = $conn->query("SELECT COUNT(*) FROM suppliers WHERE status='Active'");
$active_suppliers = $active_res ? $active_res->fetch_row()[0] : 0;

$inactive_suppliers = $total_suppliers - $active_suppliers;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search & Filter
$search_sql = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $search_sql = "WHERE status = '$status_filter'";
}

// Fetch Data
$suppliers = $conn->query("SELECT * FROM suppliers $search_sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset");

$current_page = 'suppliers.php';

// Generate next Supplier ID
$next_id_res = $conn->query("SELECT MAX(id) FROM suppliers");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_sup_id = 'SUP-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$page_title = 'Suppliers';
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
            <h1>Suppliers</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Suppliers</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_suppliers ?></div>
            <div class="page-metric-trend">Total partners recorded</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Active Suppliers</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $active_suppliers ?></div>
            <div class="page-metric-trend">Currently engaged</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Inactive Suppliers</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= $inactive_suppliers ?></div>
            <div class="page-metric-trend">Past partners</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Products Supplied</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                <?= $conn->query("SELECT COUNT(*) FROM products WHERE supplier_id IS NOT NULL")->fetch_row()[0] ?? 0 ?>
            </div>
            <div class="page-metric-trend">Items linked to suppliers</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Supplier Directory</h2>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Active' => 'Active', 'Inactive' => 'Inactive'] as $val => $label):
                        $href = $val === '' ? 'suppliers.php' : "suppliers.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">

                <button class="btn btn-outline"
                    onclick="document.getElementById('addSupplierModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    New Supplier
                </button>


            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Supplier Name</th>
                    <th>Supplier ID</th>
                    <th>Contact Person</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>StatusBadge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                    <?php while ($row = $suppliers->fetch_assoc()): ?>
                        <?php
                        ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="suppliers">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['supplier_name']) ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-dark);">
                                <?= htmlspecialchars($row['supplier_id_str']) ?>
                            </td>
                            <td style="font-size:14px; font-weight:500;">
                                <?= htmlspecialchars($row['contact_person']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['email']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['phone']) ?>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="edit_supplier.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon"
                                            viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                    <a href="suppliers.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this supplier?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="suppliers.php?action=toggle&id=<?= $row['id'] ?>" title="Toggle Active Status"><svg
                                            class="toggle-icon" viewBox="0 0 24 24" style="stroke: var(--primary-color);">
                                            <polyline points="1 4 1 10 7 10"></polyline>
                                            <polyline points="23 20 23 14 17 14"></polyline>
                                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
                                        </svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg><div class="empty-state-title">No Suppliers Yet</div><div class="empty-state-desc">Add your first supplier to manage purchasing.</div><a href="add_supplier.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Supplier</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_suppliers, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Supplier Modal -->
<div class="modal-overlay" id="addSupplierModal">
    <div class="modal-content" style="max-width: 500px; width: 90%;">
        <div class="modal-header">
            <h2>Add New Supplier</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="btn btn-outline"
                    style="padding: 6px 12px; font-size:13px; color:var(--text-muted); border-color:var(--border-color);"
                    onclick="document.forms['addSupplierForm'].reset()">Clear</button>
                <button class="close-modal"
                    onclick="document.getElementById('addSupplierModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form id="addSupplierForm" action="add_supplier.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div>
                        <label class="form-label">Supplier ID</label>
                        <input type="text" name="supplier_id_str" class="form-input" value="<?= $gen_sup_id ?>" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Company/Supplier Name</label>
                        <input type="text" name="supplier_name" class="form-input" placeholder="Panda, Inc." required>
                    </div>
                    <div>
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-input" placeholder="Jane Doe">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" placeholder="jane.doe@example.com">
                    </div>
                    <div>
                        <label class="form-label">Phone</label>
                        <div style="display:flex; gap:10px;">
                            <select class="form-input" style="width:105px; padding-left:10px;" name="phone_ext">
                                <option value="+91" selected>IN (+91)</option>
                                <option value="+01">US (+01)</option>
                                <option value="+02">UK (+02)</option>
                            </select>
                            <input type="text" name="phone_num" class="form-input" placeholder="00000 00000"
                                style="flex:1;">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-input" placeholder="Street, City, State, PIN" rows="3"
                            style="resize:vertical; min-height:72px;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Save
                    Supplier</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addSupplierModal').classList.add('active');
            window.history.replaceState({}, document.title, "suppliers.php");
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>