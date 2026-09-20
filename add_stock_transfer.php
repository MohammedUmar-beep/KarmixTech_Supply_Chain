<?php
require_once 'includes/auth_guard.php';

$user_id = $_SESSION['user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $transfer_ref = $conn->real_escape_string($_POST['transfer_ref']);
    $product_id = intval($_POST['product_id']);
    $from_warehouse_id = intval($_POST['from_warehouse_id']);
    $to_warehouse_id = intval($_POST['to_warehouse_id']);
    $quantity = intval($_POST['quantity']);
    $transfer_date = $conn->real_escape_string($_POST['transfer_date']);
    $notes = $conn->real_escape_string($_POST['notes']);
    $status = 'Pending';

    if ($from_warehouse_id === $to_warehouse_id) {
        $error = "Cannot transfer stock to the same warehouse.";
    } else {
        $stmt = $conn->prepare("INSERT INTO stock_transfers (transfer_ref, product_id, from_warehouse_id, to_warehouse_id, quantity, status, transfer_date, creator_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siiiissis", $transfer_ref, $product_id, $from_warehouse_id, $to_warehouse_id, $quantity, $status, $transfer_date, $user_id, $notes);

        if ($stmt->execute()) {
            log_activity($conn, 'Create', 'Stock Transfer', $transfer_ref, "Initiated internal movement of $quantity units.");
            header("Location: stock_transfers.php?msg=added");
            exit();
        } else {
            $error = "Database Error: " . $conn->error;
        }
        $stmt->close();
    }
}

// Generate New Transfer Ref
$next_tr_res = $conn->query("SELECT MAX(id) FROM stock_transfers");
$next_tr_id = $next_tr_res ? (intval($next_tr_res->fetch_row()[0]) + 1) : 1;
$transfer_ref_str = 'TRF-' . str_pad($next_tr_id, 4, '0', STR_PAD_LEFT);

// Fetch Lookups
$products = $conn->query("SELECT id, product_name, stock_level FROM products ORDER BY product_name ASC");
$warehouses = $conn->query("SELECT id, warehouse_name FROM warehouses ORDER BY warehouse_name ASC");
$warehouses_array = [];
if ($warehouses && $warehouses->num_rows > 0) {
    while ($wh = $warehouses->fetch_assoc()) {
        $warehouses_array[] = ['id' => $wh['id'], 'name' => $wh['warehouse_name']];
    }
}

$current_page = 'stock_transfers.php';
$page_title = 'New Stock Transfer';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="stock_transfers.php" class="back-link">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Transfers
            </a>
            <h1>Initiate Stock Transfer</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="form-container">
<?php if (isset($error)): ?>
<script>document.addEventListener('DOMContentLoaded',()=>showToast(<?= json_encode($error) ?>,'error'));</script>
<?php endif; ?>
<form action="add_stock_transfer.php" method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Transfer Reference</label>
                    <input type="text" name="transfer_ref" value="<?= $transfer_ref_str ?>" readonly class="form-input"
                        style="background: var(--bg-hover); cursor: not-allowed; color: var(--text-muted)">
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" required class="form-input">
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Select Product</label>
                    <select name="product_id" required class="form-select" style="width:100%;">
                        <option value="">-- Choose Product to Move --</option>
                        <?php if ($products && $products->num_rows > 0): ?>
                            <?php while ($p = $products->fetch_assoc()): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['product_name']) ?> (Available:
                                    <?= $p['stock_level'] ?>)
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">From Warehouse (Source)</label>
                    <select name="from_warehouse_id" required class="form-select" style="width:100%;">
                        <option value="">-- Select Source --</option>
                        <?php foreach ($warehouses_array as $wh): ?>
                            <option value="<?= $wh['id'] ?>">
                                <?= htmlspecialchars($wh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">To Warehouse (Destination)</label>
                    <select name="to_warehouse_id" required class="form-select" style="width:100%;">
                        <option value="">-- Select Destination --</option>
                        <?php foreach ($warehouses_array as $wh): ?>
                            <option value="<?= $wh['id'] ?>">
                                <?= htmlspecialchars($wh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Quantity to Transfer</label>
                    <input type="number" name="quantity" min="1" required class="form-input">
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Reason / Notes</label>
                    <textarea name="notes" rows="3" class="form-input" style="width:100%; resize: vertical;"></textarea>
                </div>
            </div>

            <div class="form-actions" style="margin-top: 30px; display:flex; gap:15px; justify-content:flex-end;">
                <a href="stock_transfers.php" class="btn btn-outline" style="text-decoration:none;">Cancel</a>
                <button type="submit" class="btn btn-primary" style="background:var(--primary-color);">Initiate
                    Transfer</button>
            </div>
        </form>
    </div>
</main>
<?php include 'includes/footer.php'; ?>