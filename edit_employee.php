<?php
require_once 'includes/auth_guard.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: employees.php'); exit(); }

// ── Handle POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name          = $conn->real_escape_string(trim($_POST['full_name']         ?? ''));
    $email              = $conn->real_escape_string(trim($_POST['email']             ?? ''));
    $phone              = $conn->real_escape_string(trim($_POST['phone']             ?? ''));
    $warehouse_id       = $conn->real_escape_string($_POST['warehouse_id']           ?? '');
    $department_id      = $conn->real_escape_string(trim($_POST['department_id']     ?? ''));
    $government_id_type = $conn->real_escape_string($_POST['government_id_type']     ?? '');
    $state              = $conn->real_escape_string($_POST['state']                  ?? '');
    $pincode            = $conn->real_escape_string(trim($_POST['pincode']           ?? ''));
    $address            = $conn->real_escape_string(trim($_POST['address']           ?? ''));
    $hire_date          = $conn->real_escape_string($_POST['hire_date']              ?? '');
    $status             = $conn->real_escape_string($_POST['status']                 ?? 'On Duty');

    // ── Handle new document uploads (optional on edit) ───────────────
    $doc_update_sql = '';
    $uploaded_paths = [];
    if (isset($_FILES['employee_docs']) && is_array($_FILES['employee_docs']['error'])) {
        // Fetch existing employee_id_str
        $id_res = $conn->query("SELECT employee_id_str, document_paths FROM employees WHERE id=$id");
        if ($id_res && $id_res->num_rows > 0) {
            $id_row = $id_res->fetch_assoc();
            $emp_id_str = $id_row['employee_id_str'];
            // Merge with existing docs if any
            $existing = $id_row['document_paths'] ? json_decode($id_row['document_paths'], true) : [];
            $upload_dir = 'uploads/employees/' . $emp_id_str . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            foreach ($_FILES['employee_docs']['error'] as $key => $error) {
                if ($error === UPLOAD_ERR_OK) {
                    $tmp_name    = $_FILES['employee_docs']['tmp_name'][$key];
                    $name        = basename($_FILES['employee_docs']['name'][$key]);
                    $target_path = $upload_dir . uniqid() . '_' . $name;
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        $uploaded_paths[] = $target_path;
                    }
                }
            }
            if (!empty($uploaded_paths)) {
                $merged = array_merge($existing, $uploaded_paths);
                $doc_json = $conn->real_escape_string(json_encode($merged));
                $doc_update_sql = ", document_paths='$doc_json'";
            }
        }
    }

    // ── Handle Profile Picture Upload (optional on edit) ────────────────
    $pp_update_sql = '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $pp_tmp  = $_FILES['profile_picture']['tmp_name'];
        $pp_name = basename($_FILES['profile_picture']['name']);
        $pp_ext  = strtolower(pathinfo($pp_name, PATHINFO_EXTENSION));
        $allowed_pp_exts = ['jpg','jpeg','png','gif','webp'];
        if (in_array($pp_ext, $allowed_pp_exts)) {
            // Get emp_id_str if not already fetched
            if (!isset($emp_id_str)) {
                $id_row2 = $conn->query("SELECT employee_id_str, profile_picture FROM employees WHERE id=$id")->fetch_assoc();
                $emp_id_str = $id_row2['employee_id_str'];
                $old_pp = $id_row2['profile_picture'] ?? null;
            } else {
                $old_pp = $id_row['profile_picture'] ?? null;
            }
            $pp_dir = 'uploads/employees/' . $emp_id_str . '/';
            if (!is_dir($pp_dir)) mkdir($pp_dir, 0777, true);
            // Delete old profile picture if exists
            if (!empty($old_pp) && file_exists($old_pp)) @unlink($old_pp);
            $pp_path = $pp_dir . 'profile_' . uniqid() . '.' . $pp_ext;
            if (move_uploaded_file($pp_tmp, $pp_path)) {
                $pp_path_esc = $conn->real_escape_string($pp_path);
                $pp_update_sql = ", profile_picture='$pp_path_esc'";
            }
        }
    }

    $stmt = $conn->prepare(
        "UPDATE employees SET full_name=?, email=?, phone=?, warehouse_id=?,
         department_id=?, government_id_type=?, state=?, pincode=?,
         address=?, hire_date=?, status=? WHERE id=?"
    );
    $stmt->bind_param("sssssssssssi",
        $full_name, $email, $phone, $warehouse_id,
        $department_id, $government_id_type, $state, $pincode,
        $address, $hire_date, $status, $id
    );

    $ok = $stmt->execute();
    $stmt->close();

    // Apply doc update separately if needed
    if ($ok && !empty($doc_update_sql)) {
        $conn->query("UPDATE employees SET document_paths='{$doc_json}' WHERE id=$id");
    }
    // Apply profile picture update separately if needed
    if ($ok && !empty($pp_update_sql)) {
        $conn->query("UPDATE employees SET profile_picture='{$pp_path_esc}' WHERE id=$id");
    }

    if ($ok) {
        header("Location: employees.php?msg=added");
    } else {
        header("Location: edit_employee.php?id=$id&msg=error_1");
    }
    exit();
}

// ── Fetch employee ────────────────────────────────────────────────────
$res = $conn->query("SELECT * FROM employees WHERE id=$id");
if (!$res || $res->num_rows === 0) { header('Location: employees.php'); exit(); }
$emp = $res->fetch_assoc();

// ── Warehouses list ───────────────────────────────────────────────────
$wh_res = $conn->query("SELECT id, warehouse_name FROM warehouses WHERE status='Active' ORDER BY warehouse_name");
$warehouses = [];
if ($wh_res) while ($r = $wh_res->fetch_assoc()) $warehouses[] = $r;

$states_list = [
    'Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat',
    'Haryana','Himachal Pradesh','Jammu and Kashmir','Jharkhand','Karnataka','Kerala',
    'Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha',
    'Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh',
    'Uttarakhand','West Bengal','Delhi','Chandigarh','Puducherry',
];

$current_page = 'employees.php';
$page_title   = 'Edit Employee';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="employees.php" style="color:var(--primary-color);font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px;text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                </svg>
                Back
            </a>
            <h1>Edit Employee</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <?php if (isset($_GET['error'])): ?>
    <div style="background:rgba(239,68,68,.1);border:1px solid #ef4444;border-radius:8px;padding:12px 16px;margin-bottom:20px;color:#ef4444;font-size:13px;font-weight:600;">
        ⚠️ Failed to update employee. Please try again.
    </div>
    <?php endif; ?>

    <div style="background:var(--white);border:1px solid var(--border-color);border-radius:12px;padding:32px;max-width:900px;">

        <!-- Employee header strip -->
        <div style="display:flex;align-items:center;gap:16px;padding-bottom:24px;margin-bottom:28px;border-bottom:1px solid var(--border-color);">
            <div style="width:52px;height:52px;border-radius:50%;background:var(--primary-color);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;flex-shrink:0;">
                <?= strtoupper(substr($emp['full_name'], 0, 1)) ?>
            </div>
            <div>
                <div style="font-size:17px;font-weight:700;color:var(--text-dark);"><?= htmlspecialchars($emp['full_name']) ?></div>
                <div style="font-size:13px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($emp['employee_id_str']) ?> &nbsp;·&nbsp; Added <?= date('d M Y', strtotime($emp['created_at'])) ?></div>
            </div>
            <span style="margin-left:auto;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:<?= $emp['status'] === 'On Duty' ? 'rgba(16,185,129,.12)' : ($emp['status'] === 'On Break' ? 'rgba(245,158,11,.12)' : 'rgba(239,68,68,.12)') ?>;color:<?= $emp['status'] === 'On Duty' ? '#10b981' : ($emp['status'] === 'On Break' ? '#f59e0b' : '#ef4444') ?>;">
                <?= htmlspecialchars($emp['status']) ?>
            </span>
        </div>

        <form action="edit_employee.php?id=<?= $id ?>" method="POST" enctype="multipart/form-data">

            <!-- Profile Picture Upload -->
            <div style="display:flex;flex-direction:column;align-items:center;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border-color);">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:12px;">
                    Profile Picture <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional — click to replace)</span>
                </div>
                <input type="file" name="profile_picture" id="editProfilePicInput" accept="image/*" style="display:none;"
                    onchange="
                        const file = this.files[0];
                        if (!file) return;
                        const reader = new FileReader();
                        reader.onload = e => {
                            document.getElementById('editProfilePicPreview').src = e.target.result;
                            document.getElementById('editProfilePicPreview').style.display = 'block';
                            document.getElementById('editProfilePicIcon').style.display = 'none';
                        };
                        reader.readAsDataURL(file);
                    ">
                <div onclick="document.getElementById('editProfilePicInput').click();"
                    style="width:100px;height:100px;border-radius:50%;border:2.5px dashed var(--border-color);background:var(--bg-light);display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;transition:border-color .2s;"
                    onmouseenter="this.style.borderColor='var(--primary-color)'" onmouseleave="this.style.borderColor='var(--border-color)'">
                    <?php if (!empty($emp['profile_picture']) && file_exists($emp['profile_picture'])): ?>
                    <img id="editProfilePicPreview" src="<?= htmlspecialchars($emp['profile_picture']) ?>" alt="Profile"
                        style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <div id="editProfilePicIcon" style="display:none;flex-direction:column;align-items:center;">
                    <?php else: ?>
                    <img id="editProfilePicPreview" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <div id="editProfilePicIcon" style="display:flex;flex-direction:column;align-items:center;">
                    <?php endif; ?>
                        <svg viewBox="0 0 24 24" width="28" height="28" stroke="var(--primary-color)" fill="none" stroke-width="1.5">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                    </div>
                    <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.45);color:#fff;font-size:10px;font-weight:600;text-align:center;padding:4px 0;border-radius:0 0 50px 50px;">
                        <?php echo !empty($emp['profile_picture']) ? 'REPLACE' : 'UPLOAD'; ?>
                    </div>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">PNG, JPG, WEBP (max 5MB)</div>
            </div>

            <!-- Section: Personal Info -->
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Personal Information
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">
                <div>
                    <label class="form-label">Full Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="full_name" class="form-input" value="<?= htmlspecialchars($emp['full_name']) ?>" required placeholder="Full name">
                </div>
                <div>
                    <label class="form-label">Email Address <span style="color:#ef4444">*</span></label>
                    <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($emp['email']) ?>" required placeholder="employee@company.com">
                </div>
                <div>
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($emp['phone'] ?? '') ?>" placeholder="+91 98765 43210">
                </div>
                <div>
                    <label class="form-label">Hire Date</label>
                    <input type="date" name="hire_date" class="form-input" value="<?= htmlspecialchars($emp['hire_date'] ?? '') ?>">
                </div>
            </div>

            <!-- Section: Work Details -->
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                Work Details
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">
                <div>
                    <label class="form-label">Department</label>
                    <input type="text" name="department_id" class="form-input" value="<?= htmlspecialchars($emp['department_id'] ?? '') ?>" placeholder="e.g. Warehouse, Sales, Finance">
                </div>
                <div>
                    <label class="form-label">Assigned Warehouse</label>
                    <select name="warehouse_id" class="form-input">
                        <option value="">— None —</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= ($emp['warehouse_id'] == $wh['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($wh['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input">
                        <?php foreach (['On Duty','On Break','Off Duty'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($emp['status'] === $s) ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Government ID Type</label>
                    <select name="government_id_type" class="form-input">
                        <option value="">— Select —</option>
                        <?php foreach (['Aadhaar','PAN','Passport','Voter ID','Driving Licence'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($emp['government_id_type'] === $g) ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Section: Address -->
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Address
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">
                <div>
                    <label class="form-label">State</label>
                    <select name="state" class="form-input">
                        <option value="">— Select State —</option>
                        <?php foreach ($states_list as $st): ?>
                            <option value="<?= $st ?>" <?= ($emp['state'] === $st) ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" class="form-input" value="<?= htmlspecialchars($emp['pincode'] ?? '') ?>" placeholder="400001">
                </div>
                <div style="grid-column:1/-1;">
                    <label class="form-label">Full Address</label>
                    <textarea name="address" class="form-input" rows="2" placeholder="Street, area, city"><?= htmlspecialchars($emp['address'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Section: Documents -->
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Documents
            </div>
            <?php
                $existing_docs = $emp['document_paths'] ? json_decode($emp['document_paths'], true) : [];
            ?>
            <?php if (!empty($existing_docs)): ?>
            <div style="margin-bottom:16px; background:#f4f5fa; border:1px solid var(--border-color); border-radius:8px; padding:14px;">
                <div style="font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:10px;">Uploaded Documents</div>
                <?php foreach ($existing_docs as $doc): ?>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="var(--primary-color)" fill="none" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <a href="<?= htmlspecialchars($doc) ?>" target="_blank" style="font-size:13px; color:var(--primary-color); text-decoration:none; word-break:break-all;">
                        <?= htmlspecialchars(basename($doc)) ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="margin-bottom:16px; background:#fff8f0; border:1px solid #f59e0b33; border-radius:8px; padding:12px 14px; font-size:13px; color:#b45309;">
                ⚠️ No documents uploaded yet. Please upload ID and/or Offer Letter below.
            </div>
            <?php endif; ?>
            <div style="margin-bottom:28px;">
                <label class="form-label">Upload / Replace Documents <span style="font-size:11px;color:var(--text-muted);font-weight:400;">(new files will be added to existing)</span></label>
                <input type="file" name="employee_docs[]" multiple id="editEmpDocsInput" style="display:none;"
                    onchange="
                        const files = this.files;
                        const list = document.getElementById('editEmpDocsList');
                        list.innerHTML = '';
                        for(let i=0; i<files.length; i++) {
                            list.innerHTML += '<div style=\'font-size:12px;color:var(--text-dark);margin-top:4px;\'>&bull; ' + files[i].name + '</div>';
                        }
                    ">
                <div class="form-input" onclick="document.getElementById('editEmpDocsInput').click();"
                    style="height:90px; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f4f5fa; border:2px dashed #e5e7eb; cursor:pointer; text-align:center;">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="var(--primary-color)" fill="none" style="margin-bottom:6px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <span style="color:var(--primary-color);font-weight:500;font-size:13px;">Click to upload</span>
                    <span style="color:var(--text-muted);font-size:11px;margin-top:3px;">PDF, PNG, JPG (max 2MB each)</span>
                </div>
                <div id="editEmpDocsList" style="margin-top:6px;"></div>
            </div>

            <!-- Footer -->
            <div style="display:flex;justify-content:space-between;align-items:center;padding-top:20px;border-top:1px solid var(--border-color);">
                <a href="employees.php" class="btn btn-outline" style="padding:10px 20px;text-decoration:none;">Cancel</a>
                <button type="submit" class="btn btn-primary" style="padding:10px 28px;font-size:14px;">
                    Save Changes
                </button>
            </div>

        </form>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
