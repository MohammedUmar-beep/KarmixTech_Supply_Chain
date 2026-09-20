<?php
$search_options = array(
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'customer_id_str' => 'Customer ID',
    'email' => 'Email',
    'contact_number' => 'Phone',
    'company_name' => 'Company',
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
        $conn->query("DELETE FROM customers WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM customers WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';
            $conn->query("UPDATE customers SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: customers.php");
    exit();
}

$total_customers_res = $conn->query("SELECT COUNT(*) FROM customers");
$total_customers = $total_customers_res ? $total_customers_res->fetch_row()[0] : 0;

// Generate next Customer ID
$last_cust = $conn->query("SELECT customer_id_str FROM customers ORDER BY id DESC LIMIT 1");
if ($last_cust && $last_cust->num_rows > 0) {
    $last_id_str = $last_cust->fetch_row()[0];
    preg_match('/CUST-(\d+)/', $last_id_str, $matches);
    $next_num = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
} else {
    $next_num = 1;
}
$gen_cust_id = 'CUST-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

// Fetch Metrics
$active_count = $conn->query("SELECT COUNT(*) FROM customers WHERE status='Active'")->fetch_row()[0] ?? 0;
// Count invoices that are unpaid or partially paid (real pending payments)
$pending_res = $conn->query("SELECT COUNT(*) FROM invoices WHERE status IN ('Draft','Sent','Partial','Overdue')");
$pending_payment_count = $pending_res ? $pending_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $search_sql = "WHERE (customer_name LIKE '%$search%' OR email LIKE '%$search%' OR contact_number LIKE '%$search%')";
}

// Apply filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $search_sql .= ($search_sql == '') ? "WHERE status = '$status_filter'" : " AND status = '$status_filter'";
}

// Fetch Customers
$customers = $conn->query("SELECT * FROM customers $search_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");

$current_page = 'customers.php';
$page_title = 'Customers';
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
            <h1>Customers</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Customers</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_customers ?></div>
            <div class="page-metric-trend">All registered customers</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Active Customers</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $active_count ?></div>
            <div class="page-metric-trend">Currently active</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Pending Payments</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $pending_payment_count ?></div>
            <div class="page-metric-trend">Needs follow-up</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Inactive Customers</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= $total_customers - $active_count ?></div>
            <div class="page-metric-trend">Disabled accounts</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Customer List</h2>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Active' => 'Active', 'Inactive' => 'Inactive'] as $val => $label):
                        $href = $val === '' ? 'customers.php' : "customers.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addCustomerModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add New Customer
                </button>
                <a href="export.php?module=customers&format=csv" class="btn btn-primary"
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

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Customer Name</th>
                    <th>Customer ID</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers->num_rows > 0): ?>
                    <?php while ($row = $customers->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="customers">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="font-weight: 500; display:flex; align-items:center; gap:10px;">
                                <div
                                    style="width:30px; height:30px; border-radius:50%; background:var(--bg-light); color:var(--primary-color); display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px;">
                                    <?= strtoupper(substr($row['customer_name'], 0, 2)) ?>
                                </div>
                                <?= htmlspecialchars($row['customer_name']) ?>
                            </td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:13px;">
                                <?= htmlspecialchars($row['customer_id_str']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['contact_number']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-muted);">
                                <?= htmlspecialchars($row['email']) ?>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="edit_customer.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon"
                                            viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                    <a href="customers.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this customer?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="customers.php?action=toggle&id=<?= $row['id'] ?>"
                                        title="Toggle Active/Inactive"><svg
                                            class="toggle-icon"
                                            viewBox="0 0 24 24" style="stroke: var(--primary-color);">
                                            <polyline points="1 4 1 10 7 10"></polyline>
                                            <polyline points="23 20 23 14 17 14"></polyline>
                                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
                                        </svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="7"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><div class="empty-state-title">No Customers Yet</div><div class="empty-state-desc">Add your first customer to start managing sales.</div><a href="add_customer.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Customer</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_customers, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Customer Modal -->
<div class="modal-overlay" id="addCustomerModal">
    <div class="modal-content" style="max-width: 500px; width: 90%;">
        <div class="modal-header">
            <h2>Add new customer</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button type="button" class="btn btn-outline"
                    onclick="window.open('import_data.php?import_type=customers', '_self')"
                    style="padding: 6px 12px; font-size: 13px; border-radius: 20px; display:flex; align-items:center; gap:6px;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg> Bulk Upload
                </button>
                <button class="close-modal"
                    onclick="document.getElementById('addCustomerModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="add_customer.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div>
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-input" placeholder="Ex: John Doe" required>
                    </div>
                    <div>
                        <label class="form-label">Customer ID</label>
                        <input type="text" name="customer_id_str" class="form-input" value="<?= $gen_cust_id ?>"
                            readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Contact Number <span style="color:#ef4444;">*</span></label>
                        <div style="display:flex; gap:10px;">
                            <select class="form-input" style="width:105px; padding-left:10px;" name="phone_ext">
                                <option value="+91" selected>IN (+91)</option>
                                <option value="+01">US (+01)</option>
                                <option value="+02">UK (+02)</option>
                            </select>
                            <input type="text" name="phone_num" class="form-input" placeholder="00000 00000"
                                style="flex:1;" required>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Email Address <span style="color:var(--text-muted); font-weight:400; font-size:12px;">(optional)</span></label>
                        <input type="email" name="email" class="form-input" placeholder="john@example.com">
                    </div>
                    <div>
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-input" rows="3" placeholder="123 Block, City..."
                            style="resize:vertical;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">

                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Add Customer</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addCustomerModal').classList.add('active');
            window.history.replaceState({}, document.title, "customers.php");
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>