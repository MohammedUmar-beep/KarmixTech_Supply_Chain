<?php
$search_options = array(
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'employee_id_str' => 'Employee ID',
    'email' => 'Email',
    'phone' => 'Phone',
    'role' => 'Role',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

// Only Admin can view employees
if (strtolower($_SESSION['role']) !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle Status)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("UPDATE employees SET status = 'Deleted' WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM employees WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $status_cycle = ['On Duty' => 'On Break', 'On Break' => 'Off Duty', 'Off Duty' => 'On Duty'];
            $new_status = isset($status_cycle[$current_status]) ? $status_cycle[$current_status] : 'On Duty';
            $conn->query("UPDATE employees SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: employees.php");
    exit();
}

// Fetch Metrics
$total_res = $conn->query("SELECT COUNT(*) FROM employees WHERE status IN ('On Duty','On Break','Off Duty')");
$total_employees = $total_res ? $total_res->fetch_row()[0] : 0;

$onduty_res = $conn->query("SELECT COUNT(*) FROM employees WHERE status = 'On Duty'");
$onduty_employees = $onduty_res ? $onduty_res->fetch_row()[0] : 0;

$onbreak_res = $conn->query("SELECT COUNT(*) FROM employees WHERE status = 'On Break'");
$onbreak_employees = $onbreak_res ? $onbreak_res->fetch_row()[0] : 0;

$offduty_res = $conn->query("SELECT COUNT(*) FROM employees WHERE status = 'Off Duty'");
$offduty_employees = $offduty_res ? $offduty_res->fetch_row()[0] : 0;

// Fetch active warehouses for dropdown
$wh_res = $conn->query("SELECT id, warehouse_name, warehouse_id_str FROM warehouses WHERE status='Active' ORDER BY warehouse_name");
$warehouses_list = [];
if ($wh_res) while ($r = $wh_res->fetch_assoc()) $warehouses_list[] = $r;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$status_sql = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $status_sql = "AND status = '$status_filter'";
}

$query = "
    SELECT * 
    FROM employees 
    WHERE status IN ('On Duty','On Break','Off Duty')
    $status_sql
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
";
$employees = $conn->query($query);

$current_page = 'employees.php';

$page_title = 'Employees';
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
            <h1>Employee</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Employees</div>
            <div class="page-metric-value" style="color:var(--primary-color);">
                <?= $total_employees ?>
            </div>
            <div class="page-metric-trend">All registered staff</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">On Duty</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $onduty_employees ?></div>
            <div class="page-metric-trend">Currently working</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">On Break</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $onbreak_employees ?></div>
            <div class="page-metric-trend">Temporarily away</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Off Duty</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= $offduty_employees ?></div>
            <div class="page-metric-trend">Not currently working</div>
        </div>
    </div>


    <h2 style="font-size: 20px; font-weight: 600; color: var(--text-dark); margin-bottom: 20px;">Employees</h2>
    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'On Duty' => 'On Duty', 'On Break' => 'On Break', 'Off Duty' => 'Off Duty'] as $val => $label):
                        $href = $val === '' ? 'employees.php' : 'employees.php?status=' . urlencode($val);
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addEmployeeModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add New Employee
                </button>
                <a href="export.php?module=employees&format=csv" class="btn btn-primary"
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
                    <th>Employee Name</th>
                    <th>Employee ID</th>
                    <th>Warehouse ID</th>
                    <th>Status</th>
                    <th>Hire Date</th>
                    <th>Phone Number</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($employees && $employees->num_rows > 0): ?>
                    <?php while ($row = $employees->fetch_assoc()): ?>
                        <?php
                        ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="employees">
                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="display:flex; align-items:center; gap:12px; font-weight:500;">
                                <img src="<?= (!empty($row['profile_picture']) && file_exists($row['profile_picture'])) ? htmlspecialchars($row['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($row['full_name']) . '&background=random&rounded=true' ?>"
                                    alt="<?= htmlspecialchars($row['full_name']) ?>"
                                    style="width:28px; height:28px; border-radius:50%;">
                                <?= htmlspecialchars($row['full_name']) ?>
                            </td>
                            <td style="font-size: 13px; font-weight: 500;">
                                <?= htmlspecialchars($row['employee_id_str']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['warehouse_id'] ?? 'N/A') ?>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= date('M j, Y', strtotime($row['hire_date'])) ?>
                            </td>
                            <td style="font-size: 13px; font-weight: 500;">
                                <?= htmlspecialchars($row['phone']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_employee_docs.php?id=<?= $row['id'] ?>" title="View Documents" style="color:var(--primary-color);"><svg viewBox="0 0 24 24" width="17" height="17" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></a>
                                    <a href="edit_employee.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon" viewBox="0 0 24 24">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg></a>
                                    <a href="employees.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this employee?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="employees.php?action=toggle&id=<?= $row['id'] ?>" title="Cycle Status"><svg
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
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><div class="empty-state-title">No Employees Yet</div><div class="empty-state-desc">Add your first employee to manage your team.</div><a href="add_employee.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Employee</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_employees, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Create Employee Modal -->
<div class="modal-overlay" id="addEmployeeModal">
    <div class="modal-content" style="max-width: 800px; width: 95%;">
        <div class="modal-header">
            <h2>Add New Employee</h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <button class="btn btn-outline"
                    style="display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:13px; border-radius:6px;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    Bulk Upload
                </button>
                <button class="close-modal"
                    onclick="document.getElementById('addEmployeeModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="add_employee.php" method="POST" enctype="multipart/form-data" id="addEmployeeForm">
            <div class="modal-body form-grid">
                <!-- Doc required error banner (hidden by default) -->
                <div id="docErrorBanner" style="display:none; background:rgba(239,68,68,.08); border:1px solid #ef4444; border-radius:8px; padding:10px 14px; margin-bottom:14px; color:#ef4444; font-size:13px; font-weight:600;">
                    ⚠️ Document upload is mandatory. Please upload the employee's ID and/or Offer Letter before submitting.
                </div>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'docs_required'): ?>
                <div style="background:rgba(239,68,68,.08); border:1px solid #ef4444; border-radius:8px; padding:10px 14px; margin-bottom:14px; color:#ef4444; font-size:13px; font-weight:600;">
                    ⚠️ Document upload is mandatory. Please upload the employee's ID and/or Offer Letter before submitting.
                </div>
                <?php endif; ?>

                <!-- Profile Picture Upload -->
                <div style="display:flex;flex-direction:column;align-items:center;margin-bottom:20px;">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">Profile Picture <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></div>
                    <input type="file" name="profile_picture" id="profilePicInput" accept="image/*" style="display:none;"
                        onchange="
                            const file = this.files[0];
                            if (!file) return;
                            const reader = new FileReader();
                            reader.onload = e => {
                                document.getElementById('profilePicPreview').src = e.target.result;
                                document.getElementById('profilePicPreview').style.display = 'block';
                                document.getElementById('profilePicIcon').style.display = 'none';
                            };
                            reader.readAsDataURL(file);
                        ">
                    <div onclick="document.getElementById('profilePicInput').click();"
                        style="width:96px;height:96px;border-radius:50%;border:2.5px dashed var(--border-color);background:var(--bg-light);display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;transition:border-color .2s;"
                        onmouseenter="this.style.borderColor='var(--primary-color)'" onmouseleave="this.style.borderColor='var(--border-color)'">
                        <img id="profilePicPreview" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <div id="profilePicIcon" style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                            <svg viewBox="0 0 24 24" width="24" height="24" stroke="var(--primary-color)" fill="none" stroke-width="1.5">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.45);color:#fff;font-size:10px;font-weight:600;text-align:center;padding:4px 0;border-radius:0 0 50px 50px;">UPLOAD</div>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">Click circle to upload</div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-input" placeholder="Enter Full Name" required>
                    </div>
                    <div>
                        <label class="form-label">Hiring Date</label>
                        <input type="date" name="hire_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div>
                        <label class="form-label">Phone number</label>
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
                        <label class="form-label">Warehouse/Store</label>
                        <select name="warehouse_id" class="form-input">
                            <option value="">— Select Warehouse —</option>
                            <?php foreach ($warehouses_list as $wh): ?>
                                <option value="<?= htmlspecialchars($wh['warehouse_id_str']) ?>">
                                    <?= htmlspecialchars($wh['warehouse_name']) ?> (<?= htmlspecialchars($wh['warehouse_id_str']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Email-Id</label>
                        <input type="email" name="email" class="form-input" placeholder="Enter your Email-ID" required>
                    </div>
                    <div>
                        <!-- Department ID removed intentionally -->
                    </div>

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
                        <label class="form-label">Government ID Type</label>
                        <select name="government_id_type" class="form-input">
                            <option value="" disabled selected>Select Specific ID</option>
                            <option>Aadhaar Card</option>
                            <option>Driver's License</option>
                            <option>Passport</option>
                            <option>PAN Card</option>
                            <option>Voter ID</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" class="form-input" placeholder="Enter Pincode">
                    </div>
                    <div style="grid-row: span 2;">
                        <label class="form-label">Upload Files (ID & Offer Letter)</label>
                        <input type="file" name="employee_docs[]" multiple id="empDocsInput" style="display:none;"
                            onchange="
                                const files = this.files;
                                const list = document.getElementById('empDocsList');
                                list.innerHTML = '';
                                for(let i=0; i<files.length; i++) {
                                    list.innerHTML += `<div style='font-size:12px; color:var(--text-dark); margin-top:4px;'>&bull; ${files[i].name}</div>`;
                                }
                            ">
                        <div class="form-input" onclick="document.getElementById('empDocsInput').click();"
                            style="height: 120px; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f4f5fa; border:2px dashed #e5e7eb; cursor:pointer; text-align:center;">
                            <svg viewBox="0 0 24 24" width="24" height="24" stroke="var(--primary-color)" fill="none"
                                style="margin-bottom:8px;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                            <span style="color:var(--primary-color); font-weight:500;">Click to upload</span>
                            <span style="color:var(--text-muted); font-size:12px; margin-top:4px;">SVG, PNG, JPG or
                                PDF (max. 2MB)</span>
                        </div>
                        <div id="empDocsList" style="margin-top:8px;"></div>
                    </div>

                    <div>
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-input" placeholder="Enter your Address">
                    </div>

                    <!-- Hidden required fields that usually generate automatically -->
                    <input type="hidden" name="employee_id_str" value="EMP-<?= rand(10000, 99999) ?>">
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="button" class="btn btn-primary btn-submit"
                    style="width: 100%; border-radius:8px; font-size:15px; font-weight:500; height: 48px;"
                    onclick="
                        const fileInput = document.getElementById('empDocsInput');
                        if (!fileInput.files || fileInput.files.length === 0) {
                            document.getElementById('docErrorBanner').style.display = 'block';
                            document.getElementById('docErrorBanner').scrollIntoView({behavior:'smooth', block:'center'});
                            return;
                        }
                        document.getElementById('docErrorBanner').style.display = 'none';
                        document.getElementById('addEmployeeForm').submit();
                    ">Add New Employee</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addEmployeeModal').classList.add('active');
        });
    </script>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'docs_required'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            showToast('Document upload is mandatory. Please upload the employee\'s ID and/or Offer Letter.', 'error');
            document.getElementById('addEmployeeModal').classList.add('active');
        });
    </script>
<?php elseif (isset($_GET['success'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            showToast('Employee added successfully!', 'success');
        });
    </script>
<?php elseif (isset($_GET['error']) && $_GET['error'] == '1'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            showToast('Failed to add employee. Please try again.', 'error');
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>