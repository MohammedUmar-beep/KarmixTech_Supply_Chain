<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$warehouse = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $warehouse = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$warehouse) {
    header("Location: warehouses.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $warehouse_name = $conn->real_escape_string($_POST['warehouse_name']);
    $warehouse_id_str = $conn->real_escape_string($_POST['warehouse_id_str']);

    $country_code = $_POST['country_code'] ?? '+1';
    $contact_num_post = $_POST['contact_num'] ?? '';
    $contact_number = $conn->real_escape_string($country_code . ' ' . $contact_num_post);

    $email = $conn->real_escape_string($_POST['email']);
    $state = $conn->real_escape_string($_POST['state']);
    $pincode = $conn->real_escape_string($_POST['pincode']);
    $address = $conn->real_escape_string($_POST['address']);
    $capacity = intval($_POST['capacity']);

    $stmt = $conn->prepare("UPDATE warehouses SET warehouse_name=?, warehouse_id_str=?, contact_number=?, email=?, state=?, pincode=?, address=?, capacity=? WHERE id=?");
    $stmt->bind_param("ssssssssi", $warehouse_name, $warehouse_id_str, $contact_number, $email, $state, $pincode, $address, $capacity, $id);

    if ($stmt->execute()) {
        header("Location: warehouses.php?msg=updated");
        exit();
    } else {
        $error = "Error updating warehouse.";
    }
    $stmt->close();
}

$current_page = 'warehouses.php'; // For sidebar active state
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warehouse - Agile Inventory</title>
        <script>
        (function () {
            var t = localStorage.getItem('agile_theme') || 'light';
            var c = localStorage.getItem('agile_color') || '#6d4aff';
            document.documentElement.setAttribute('data-theme', t);
            if (c === 'monochrome') {
                document.documentElement.style.setProperty('--primary-color', t === 'dark' ? '#ffffff' : '#000000');
                document.documentElement.style.setProperty('--primary-hover', t === 'dark' ? '#e5e5e5' : '#333333');
            } else {
                document.documentElement.style.setProperty('--primary-color', c);
                if (c === '#1E5EFF') document.documentElement.style.setProperty('--primary-hover', '#1648c9');
                else if (c === '#336DFF') document.documentElement.style.setProperty('--primary-hover', '#2452c7');
            }
        })();
    </script><link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .edit-form-container {
            background: var(--white);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            max-width: 800px;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-area">
            <div class="topbar">
                <div class="topbar-left">
                    <a href="warehouses.php"
                        style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                        Back to Warehouses</a>
                    <h1>Edit Warehouse</h1>
                </div>
                <?php include 'includes/topbar_right.php'; ?>
            </div>

            <div class="edit-form-container">
                <?php if (isset($error)): ?>
                    <div style="color: red; margin-bottom: 15px;">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form action="edit_warehouse.php?id=<?= $id ?>" method="POST">
                    <div class="form-grid">
                        <div class="form-grid-2" style="display:grid; gap:20px;">
                            <div>
                                <label class="form-label">Warehouse Name</label>
                                <input type="text" name="warehouse_name" class="form-input"
                                    value="<?= htmlspecialchars($warehouse['warehouse_name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Warehouse ID</label>
                                <input type="text" name="warehouse_id_str" class="form-input"
                                    value="<?= htmlspecialchars($warehouse['warehouse_id_str']) ?>" required>
                            </div>
                        </div>

                        <div style="margin-top:20px;">
                            <label class="form-label">Warehouse Contact number</label>
                            <?php
                            $current_code = '+91';
                            $contact_only = $warehouse['contact_number'];
                            if (preg_match('/^(\+\d+)\s*(.*)$/', $warehouse['contact_number'], $matches)) {
                                $current_code = $matches[1];
                                $contact_only = $matches[2];
                            }
                            ?>
                            <div class="input-group" style="display:flex;">
                                <select name="country_code" class="form-input"
                                    style="width: auto; border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; background: var(--bg-light);">
                                    <option value="+1" <?= $current_code == '+1' ? 'selected' : '' ?>>US (+1)</option>
                                    <option value="+44" <?= $current_code == '+44' ? 'selected' : '' ?>>UK (+44)</option>
                                    <option value="+91" <?= $current_code == '+91' ? 'selected' : '' ?>>IN (+91)</option>
                                    <option value="+61" <?= $current_code == '+61' ? 'selected' : '' ?>>AU (+61)</option>
                                </select>
                                <input type="text" name="contact_num" class="form-input"
                                    style="flex:1; border-top-left-radius: 0; border-bottom-left-radius: 0;"
                                    value="<?= htmlspecialchars($contact_only) ?>" placeholder="955 000 0000" required>
                            </div>
                        </div>

                        <div style="margin-top:20px;">
                            <label class="form-label">Email-Id</label>
                            <input type="email" name="email" class="form-input"
                                value="<?= htmlspecialchars($warehouse['email']) ?>" required>
                        </div>

                        <div class="form-grid-2" style="display:grid; gap:20px; margin-top:20px;">
                            <div>
                                <label class="form-label">State</label>
                                <select name="state" id="wh_state" class="form-input" required>
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
                                <script>document.getElementById('wh_state').value = <?= json_encode($warehouse['state'] ?? '') ?>;</script>
                            </div>
                            <div>
                                <label class="form-label">Pincode</label>
                                <input type="text" name="pincode" class="form-input"
                                    value="<?= htmlspecialchars($warehouse['pincode']) ?>" required>
                            </div>
                        </div>

                        <div style="margin-top:20px;">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-input"
                                value="<?= htmlspecialchars($warehouse['address']) ?>" required>
                        </div>

                        <div style="margin-top:20px;">
                            <label class="form-label">Capacity (in SKUs)</label>
                            <input type="number" name="capacity" class="form-input"
                                value="<?= htmlspecialchars($warehouse['capacity']) ?>" required>
                        </div>
                    </div>

                    <div style="margin-top: 30px; display:flex; gap:12px;">
                        <button type="submit" class="btn btn-primary"
                            style="padding: 10px 24px; border-radius:8px; font-weight:500;">Save Changes</button>
                        <a href="warehouses.php" class="btn btn-outline"
                            style="padding: 10px 24px; border-radius:8px; font-weight:500; text-decoration:none;">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
<?php include 'includes/footer.php'; ?>
