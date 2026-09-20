<?php
require_once 'includes/auth_guard.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) {
    header("Location: customers.php");
    exit();
}

// Ensure columns requested match DB structure. We'll use the ones that add_customer.php uses.
$res = $conn->query("SELECT * FROM customers WHERE id = $id");
if (!$res || $res->num_rows == 0) {
    header("Location: customers.php?msg=error_1");
    exit();
}
$customer = $res->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $email = $conn->real_escape_string($_POST['email']);

    // Combining country code securely if provided separately
    $phone_ext = $_POST['phone_ext'] ?? '+91';
    $phone_num = trim($_POST['phone_num'] ?? '');

    // If user edited the pre-combined number directly
    if (isset($_POST['contact_number'])) {
        $contact_number = $conn->real_escape_string($_POST['contact_number']);
    } else {
        $contact_number = $conn->real_escape_string($phone_ext . ' ' . $phone_num);
    }

    $address = $conn->real_escape_string($_POST['address']);
    $status = $conn->real_escape_string($_POST['status'] ?? 'Active');

    $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, contact_number = ?, email = ?, address = ?, status = ? WHERE id = ?");
    $stmt->bind_param("sssssi", $customer_name, $contact_number, $email, $address, $status, $id);

    if ($stmt->execute()) {
        log_activity($conn, 'Update', 'Customer', $customer['customer_id_str'], "Updated customer: $customer_name");
        header("Location: customers.php?msg=added");
    } else {
        $error = "Error updating customer.";
    }
}

$page_title = "Edit Customer: " . htmlspecialchars($customer['customer_id_str']);
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="customers.php"
                style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Customers
            </a>
            <h1>Edit Customer</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div
        style="background:var(--white); padding:30px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); max-width:800px; margin-top:20px;">
        <?php if (isset($error)): ?>
            <div
                style="background:var(--danger-color); color:var(--white); padding:10px; border-radius:6px; margin-bottom:20px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-grid">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div style="grid-column: 1 / -1;">
                        <label class="form-label">Customer ID (Read Only)</label>
                        <input type="text" class="form-input"
                            value="<?= htmlspecialchars($customer['customer_id_str']) ?>" disabled
                            style="background:var(--bg-light); color:var(--text-muted); cursor:not-allowed;">
                    </div>
                    <div>
                        <label class="form-label">Full Name / Company Name</label>
                        <input type="text" name="customer_name" class="form-input"
                            value="<?= htmlspecialchars($customer['customer_name']) ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input"
                            value="<?= htmlspecialchars($customer['email']) ?>">
                    </div>
                    <div>
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-input"
                            value="<?= htmlspecialchars($customer['contact_number']) ?>">
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input" required>
                            <option value="Active" <?= $customer['status'] == 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $customer['status'] == 'Inactive' ? 'selected' : '' ?>>Inactive
                            </option>
                        </select>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label class="form-label">Billing Address</label>
                        <textarea name="address" class="form-input"
                            rows="3"><?= htmlspecialchars($customer['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">Save Changes</button>
                <a href="customers.php" class="btn btn-outline"
                    style="padding: 10px 24px; text-decoration:none;">Cancel</a>
            </div>
        </form>
    </div>
</main>
</div>
<?php include 'includes/footer.php'; ?>