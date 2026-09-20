<?php
require_once 'includes/auth_guard.php';

// Only admin and manager can edit suppliers
if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("Location: suppliers.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header("Location: suppliers.php");
    exit();
}

// Handle POST – save updated supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_name   = $conn->real_escape_string(trim($_POST['supplier_name'] ?? ''));
    $contact_person  = $conn->real_escape_string(trim($_POST['contact_person'] ?? ''));
    $email           = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $phone           = $conn->real_escape_string(trim($_POST['phone_ext'] ?? '+91')) . ' ' . $conn->real_escape_string(trim($_POST['phone_num'] ?? ''));
    $address         = $conn->real_escape_string(trim($_POST['address'] ?? ''));
    $status          = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

    $stmt = $conn->prepare("UPDATE suppliers SET supplier_name=?, contact_person=?, email=?, phone=?, address=?, status=? WHERE id=?");
    $stmt->bind_param("ssssssi", $supplier_name, $contact_person, $email, $phone, $address, $status, $id);

    if ($stmt->execute()) {
        log_activity($conn, 'Update', 'Supplier', $id, "Updated supplier: $supplier_name");
        header("Location: suppliers.php?msg=updated");
    } else {
        header("Location: edit_supplier.php?id=$id&msg=error_1");
    }
    $stmt->close();
    exit();
}

// Fetch existing supplier data
$res = $conn->query("SELECT * FROM suppliers WHERE id = $id");
if (!$res || $res->num_rows === 0) {
    header("Location: suppliers.php");
    exit();
}
$supplier = $res->fetch_assoc();

// Split stored phone into ext + number (stored as "+91 98765 43210")
$phone_parts = explode(' ', $supplier['phone'] ?? '', 2);
$phone_ext   = $phone_parts[0] ?? '+91';
$phone_num   = $phone_parts[1] ?? '';

$page_title = 'Edit Supplier';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="suppliers.php"
                style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Suppliers
            </a>
            <h1>Edit Supplier</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div style="max-width: 680px; margin: 0 auto;">
        <div class="card" style="padding: 32px 36px; border-radius: 14px;">
            <h2 style="font-size:18px; font-weight:700; margin:0 0 24px; color:var(--text-dark);">
                <?= htmlspecialchars($supplier['supplier_id_str']) ?> — <?= htmlspecialchars($supplier['supplier_name']) ?>
            </h2>

            <form action="edit_supplier.php?id=<?= $id ?>" method="POST">
                <div style="display:flex; flex-direction:column; gap:20px;">

                    <!-- Supplier ID (read-only) -->
                    <div>
                        <label class="form-label">Supplier ID</label>
                        <input type="text" class="form-input"
                            value="<?= htmlspecialchars($supplier['supplier_id_str']) ?>"
                            readonly
                            style="background:var(--bg-light); cursor:not-allowed; color:var(--text-muted);">
                    </div>

                    <!-- Supplier Name -->
                    <div>
                        <label class="form-label">Company / Supplier Name <span style="color:var(--danger-color);">*</span></label>
                        <input type="text" name="supplier_name" class="form-input"
                            value="<?= htmlspecialchars($supplier['supplier_name']) ?>"
                            placeholder="e.g. Panda, Inc." required>
                    </div>

                    <!-- Contact Person -->
                    <div>
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-input"
                            value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"
                            placeholder="Jane Doe">
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input"
                            value="<?= htmlspecialchars($supplier['email'] ?? '') ?>"
                            placeholder="jane.doe@example.com">
                    </div>

                    <!-- Phone -->
                    <div>
                        <label class="form-label">Phone</label>
                        <div style="display:flex; gap:10px;">
                            <select class="form-input" style="width:115px; padding-left:10px;" name="phone_ext">
                                <option value="+91" <?= $phone_ext === '+91' ? 'selected' : '' ?>>IN (+91)</option>
                                <option value="+01" <?= $phone_ext === '+01' ? 'selected' : '' ?>>US (+01)</option>
                                <option value="+02" <?= $phone_ext === '+02' ? 'selected' : '' ?>>UK (+02)</option>
                            </select>
                            <input type="text" name="phone_num" class="form-input"
                                value="<?= htmlspecialchars($phone_num) ?>"
                                placeholder="00000 00000" style="flex:1;">
                        </div>
                    </div>

                    <!-- Address -->
                    <div>
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-input" rows="3"
                            placeholder="Street, City, State, PIN"
                            style="resize:vertical; min-height:72px;"><?= htmlspecialchars($supplier['address'] ?? '') ?></textarea>
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="Active"   <?= $supplier['status'] === 'Active'   ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $supplier['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                </div>

                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:28px; padding-top:20px; border-top:1px solid var(--border-color);">
                    <a href="suppliers.php" class="btn btn-outline" style="padding:10px 22px; text-decoration:none;">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="padding:10px 28px; font-size:15px; font-weight:600;">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>
</div><!-- /app-container -->
<?php include 'includes/footer.php'; ?>
</body>
</html>
