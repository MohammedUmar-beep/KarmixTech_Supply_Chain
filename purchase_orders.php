<?php
$search_options = array(
    'po.order_id_str' => 'Order ID',
    's.supplier_name' => 'Supplier Name',
    'po.grand_total' => 'Total Amount',
    'po.order_date' => 'Date',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle Status / Unreceive)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_po_id'])) {
        $id = intval($_POST['delete_po_id']);
        $remove_stock = isset($_POST['remove_stock']) ? true : false;

        // Check the current status of the PO before deleting
        $status_res = $conn->query("SELECT status FROM purchase_orders WHERE id = $id");
        $po_status = $status_res && $status_res->num_rows > 0 ? $status_res->fetch_row()[0] : '';

        // Only remove stock if it was actually received
        if ($remove_stock && $po_status === 'Received') {
            $items_res = $conn->query("SELECT product_id, quantity FROM purchase_order_items WHERE purchase_order_id = $id");
            if ($items_res) {
                while ($item = $items_res->fetch_assoc()) {
                    $pid = intval($item['product_id']);
                    $qty = intval($item['quantity']);
                    $conn->query("UPDATE products SET stock_level = GREATEST(0, stock_level - $qty) WHERE id = $pid");
                }
            }
        }
        $conn->query("DELETE FROM purchase_orders WHERE id = $id");
        header("Location: purchase_orders.php?msg=deleted");
        exit();
    } elseif (isset($_POST['unreceive_po_id'])) {
        $id = intval($_POST['unreceive_po_id']);
        $remove_stock = isset($_POST['remove_stock']) ? true : false;

        if ($remove_stock) {
            $items_res = $conn->query("SELECT product_id, quantity FROM purchase_order_items WHERE purchase_order_id = $id");
            if ($items_res) {
                while ($item = $items_res->fetch_assoc()) {
                    $pid = intval($item['product_id']);
                    $qty = intval($item['quantity']);
                    $conn->query("UPDATE products SET stock_level = GREATEST(0, stock_level - $qty) WHERE id = $pid");
                }
            }
        }
        $conn->query("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = $id");
        header("Location: purchase_orders.php?msg=unreceived");
        exit();
    }
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM purchase_orders WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM purchase_orders WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];

            // If it's already received and user clicked toggle, it should trigger the POST modal, 
            // but if they somehow get here via GET, just redirect them back.
            if ($current_status == 'Received') {
                header("Location: purchase_orders.php");
                exit();
            }

            $status_cycle = ['Draft' => 'Issued', 'Issued' => 'Received', 'Received' => 'Cancelled', 'Cancelled' => 'Draft'];
            $new_status = $status_cycle[$current_status];

            if ($new_status == 'Received') {
                // Add stock
                $items_res = $conn->query("SELECT product_id, quantity FROM purchase_order_items WHERE purchase_order_id = $id");
                if ($items_res) {
                    while ($item = $items_res->fetch_assoc()) {
                        $pid = intval($item['product_id']);
                        $qty = intval($item['quantity']);
                        $conn->query("UPDATE products SET stock_level = stock_level + $qty WHERE id = $pid");
                    }
                }
            }

            $conn->query("UPDATE purchase_orders SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: purchase_orders.php");
    exit();
}

// Fetch Metrics
$total_res = $conn->query("SELECT COUNT(*) FROM purchase_orders");
$total_orders = $total_res ? $total_res->fetch_row()[0] : 0;

$received_res = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status='Received'");
$received_orders = $received_res ? $received_res->fetch_row()[0] : 0;

$pending_res = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Draft', 'Issued')");
$pending_orders = $pending_res ? $pending_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Data
$search_sql = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    if ($status_filter === 'Pending') {
        $search_sql = "WHERE (p.status = 'Draft' OR p.status = 'Issued')";
    } elseif ($status_filter === 'Completed') {
        $search_sql = "WHERE p.status = 'Received'";
    } elseif ($status_filter === 'Cancelled') {
        $search_sql = "WHERE p.status = 'Cancelled'";
    }
}

if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];

    if (in_array($col, $allowed_cols)) {
        if ($search_sql == '') {
            $search_sql = "WHERE $col LIKE '%$search%'";
        } else {
            $search_sql .= " AND $col LIKE '%$search%'";
        }
    }
}
$query = "
    SELECT p.*, s.supplier_name 
    FROM purchase_orders p 
    LEFT JOIN suppliers s ON p.supplier_id = s.id 
    $search_sql
    ORDER BY p.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$orders = $conn->query($query);

// Fetch dropdown data for Add Modal
$suppliers_res = $conn->query("SELECT id, supplier_name, address FROM suppliers WHERE status='Active' ORDER BY supplier_name");
$suppliers_list = [];
if ($suppliers_res) {
    while ($row = $suppliers_res->fetch_assoc()) {
        $suppliers_list[] = $row;
    }
}

$products_res = $conn->query("SELECT id, product_name, purchasing_price, stock_level FROM products");
$products_list = [];
if ($products_res) {
    while ($row = $products_res->fetch_assoc()) {
        $products_list[] = $row;
    }
}

$warehouses_res = $conn->query("SELECT id, warehouse_name FROM warehouses WHERE status='Active' ORDER BY warehouse_name");
$warehouses_list = [];
if ($warehouses_res) {
    while ($row = $warehouses_res->fetch_assoc()) {
        $warehouses_list[] = $row;
    }
}

$current_page = 'purchase_orders.php';

// Generate next Purchase Order ID
$next_id_res = $conn->query("SELECT MAX(id) FROM purchase_orders");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_po_id = 'PO-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$page_title = 'Purchase Orders';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Purchase Hub</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <!-- Hub Navigation Pill Tabs -->
    <div class="tab-navigation">
        <a href="purchase_orders.php" class="tab-link active">Orders</a>
        <a href="payments_made.php" class="tab-link">Payments</a>
        <a href="purchase_returns.php" class="tab-link">Returns</a>
        <a href="supplier_credits.php" class="tab-link">Supplier Credits</a>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total POs</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_orders ?></div>
            <div class="page-metric-trend">Total orders placed</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Stock Received</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $received_orders ?></div>
            <div class="page-metric-trend">Completed purchases</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Pending Delivery</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $pending_orders ?></div>
            <div class="page-metric-trend">Awaiting stock</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Cancelled POs</div>
            <div class="page-metric-value" style="color:#ef4444;">
                <?= $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status='Cancelled'")->fetch_row()[0] ?? 0 ?>
            </div>
            <div class="page-metric-trend">Rejected or voided</div>
        </div>
    </div>


    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Pending' => 'Pending', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'] as $val => $label):
                        $href = $val === '' ? 'purchase_orders.php' : "purchase_orders.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addPurchaseOrderModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Create Purchase Order
                </button>


            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>PO #</th>
                    <th>Supplier</th>
                    <th>Order Date</th>
                    <th>Expected</th>
                    <th>Amount</th>
                    <th>StatusBadge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($orders && $orders->num_rows > 0): ?>
                    <?php while ($row = $orders->fetch_assoc()): ?>
                        <?php
                        ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="purchase_orders">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['purchase_id_str']) ?>
                            </td>
                            <td style="font-size:14px; font-weight:500;">
                                <?= htmlspecialchars($row['supplier_name'] ?? 'Unknown Supplier') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['order_date']) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['expected_date'] ?? 'N/A') ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">
                                $
                                <?= number_format($row['total_amount'], 2) ?>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_purchase_order.php?order_id=<?= $row['id'] ?>" title="View details">
                                        <svg class="inbox-icon" viewBox="0 0 24 24"
                                            style="stroke: var(--primary-color); width: 16px; height: 16px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round;">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </a>
                                    <a href="edit_purchase_order.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon"
                                            viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                    <a href="#" onclick="openDeleteModal(<?= $row['id'] ?>); return false;" title="Delete"><svg
                                            class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <?php if ($row['status'] == 'Received'): ?>
                                        <a href="#" onclick="openUnreceiveModal(<?= $row['id'] ?>); return false;"
                                            title="Change Status"><svg class="toggle-icon" viewBox="0 0 24 24"
                                                style="stroke: var(--primary-color);">
                                                <polyline points="1 4 1 10 7 10"></polyline>
                                                <polyline points="23 20 23 14 17 14"></polyline>
                                                <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15">
                                                </path>
                                            </svg></a>
                                    <?php else: ?>
                                        <a href="#"
                                            onclick="handleToggle(<?= $row['id'] ?>, '<?= addslashes($row['status']) ?>'); return false;"
                                            title="Cycle Status"><svg
                                                class="toggle-icon" viewBox="0 0 24 24" style="stroke: var(--primary-color);">
                                                <polyline points="1 4 1 10 7 10"></polyline>
                                                <polyline points="23 20 23 14 17 14"></polyline>
                                                <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15">
                                                </path>
                                            </svg></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg><div class="empty-state-title">No Purchase Orders Yet</div><div class="empty-state-desc">Create your first purchase order to manage buying.</div><a href="add_purchase_order.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Purchase Order</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_orders, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Create Purchase Order Modal -->
<div class="modal-overlay" id="addPurchaseOrderModal">
    <div class="modal-content" style="max-width: 800px; width: 95%;">
        <div class="modal-header">
            <h2>Create New Purchase Order</h2>
            <button class="close-modal"
                onclick="document.getElementById('addPurchaseOrderModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_purchase_order.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Purchase ID</label>
                        <input type="text" name="purchase_id_str" class="form-input" value="<?= $gen_po_id ?>" readonly
                            style="cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="po_supplier_id" class="form-input" required onchange="fillPOSupplierAddress(this)">
                            <option value="" disabled selected>Select Supplier</option>
                            <?php foreach ($suppliers_list as $sup): ?>
                                <option value="<?= $sup['id'] ?>" data-address="<?= htmlspecialchars($sup['address'] ?? '') ?>">
                                    <?= htmlspecialchars($sup['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Warehouse (Deliver To)</label>
                        <select name="warehouse_id" class="form-input" required>
                            <option value="" disabled selected>Select Warehouse</option>
                            <?php foreach ($warehouses_list as $wh): ?>
                                <option value="<?= $wh['id'] ?>">
                                    <?= htmlspecialchars($wh['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <label class="form-label">Supplier Address</label>
                    <input type="text" name="supplier_address" id="po_supplier_address" class="form-input"
                        placeholder="Auto-filled when supplier is selected">
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px; margin-top:20px;">
                    <div>
                        <label class="form-label">Order Date</label>
                        <input type="date" name="order_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Expected Date (Optional)</label>
                        <input type="date" name="expected_date" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" id="po_status_select" class="form-input" required onchange="togglePaymentSection(this)">
                            <option value="Draft" selected>Draft</option>
                            <option value="Issued">Issued</option>
                            <option value="Received">Received</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <h3 style="font-size: 16px; margin-bottom: 10px;">Purchase Items</h3>
                    <div id="orderItemsContainer" style="display:flex; flex-direction:column; gap:10px;">
                        <div class="line-item-row"
                            style="display:grid; grid-template-columns: 2fr 1fr 1fr 1fr 40px; gap:10px; align-items:end;">
                            <div>
                                <label class="form-label">Product ID</label>
                                <select name="product_id[]" class="form-input product-select" required
                                    onchange="updatePrice(this)">
                                    <option value="" disabled selected>Select Product</option>
                                    <?php foreach ($products_list as $prod): ?>
                                        <option value="<?= $prod['id'] ?>" data-price="<?= $prod['purchasing_price'] ?>">
                                            <?= htmlspecialchars($prod['product_name']) ?> (Qty: <?= intval($prod['stock_level']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity[]" class="form-input qty-input" min="1"
                                    placeholder="Enter Quantity here" required oninput="calculateRowData(this)">
                            </div>
                            <div>
                                <label class="form-label">Purchase Price / Unit</label>
                                <input type="number" step="0.01" name="unit_price[]" class="form-input price-input"
                                    placeholder="$0.00" required oninput="calculateRowData2(this)">
                            </div>
                            <div>
                                <label class="form-label">Total Product Price</label>
                                <input type="text" class="form-input subtotal-input" readonly placeholder="Total Price"
                                    style="color:var(--primary-color); font-weight:600;">
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline"
                                    style="padding:10px; border-color:#fee2e2; color:#991b1b;"
                                    onclick="removeRow(this)">&times;</button>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:center; margin-top:20px;">
                        <button type="button"
                            style="background:none; border:none; color:#6b21a8; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; font-size:14px;"
                            onclick="addLineItem()">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="16"></line>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                            </svg>
                            Add more products
                        </button>
                    </div>
                </div>

                <!-- Draft notice (shown only when Draft is selected) -->
                <div id="po_draft_notice" style="margin-top:20px; padding:12px 16px; border-radius:8px; background:rgba(107,33,168,0.06); border:1px solid rgba(107,33,168,0.2); display:flex; align-items:center; gap:10px;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#6b21a8" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span style="font-size:13px; color:#6b21a8; font-weight:500;">No payment will be processed for <strong>Draft</strong> orders. Change the status to <strong>Issued</strong> or <strong>Received</strong> to enable payment.</span>
                </div>

                <!-- Payment section (hidden when Draft is selected) -->
                <div id="po_payment_section"
                    style="display:none; margin-top: 20px; display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px; padding:15px; border-radius:8px;">
                    <div>
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" id="payment_status" class="form-input"
                            onchange="handlePaymentChange()">
                            <option value="Paid" selected>Paid</option>
                            <option value="Partial">Partial</option>
                            <option value="Credit">Credit</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" id="po_payment_method" class="form-input">
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cash">Cash</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Amount Paid ($)</label>
                        <input type="number" step="0.01" name="amount_paid" id="amount_paid" class="form-input"
                            value="0.00" oninput="calculateBalance()" readonly>
                        <div id="po_balance_display" style="display:none; margin-top:6px; padding:7px 10px; border-radius:6px; background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);">
                            <span style="font-size:12px; color:var(--text-muted);">Remaining balance:</span>
                            <span id="po_balance_amount" style="font-size:13px; font-weight:700; color:#f59e0b; margin-left:4px;">$0.00</span>
                            <span style="font-size:11px; color:var(--text-muted); margin-left:4px;">(supplier credit will be issued)</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; border-top: 2px solid #e5e7eb; padding-top: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; font-size:16px;">Total Order Amount:</span>
                        <h2 style="color:var(--primary-color); margin:0;" id="grandTotalDisplay" data-total="0">$
                            0.00</h2>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px 40px; border-radius:8px; font-size:15px; font-weight:500; background-color:#6b21a8; border-color:#6b21a8; color: #ffffff;">Place
                    Purchase Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Draft → Issued Confirmation Modal -->
<div class="modal-overlay" id="issuePoConfirmModal">
    <div class="modal-content" style="max-width: 440px; width: 90%;">
        <div class="modal-header">
            <h2 style="color:var(--text-dark);">Confirm Status Change</h2>
            <button class="close-modal"
                onclick="document.getElementById('issuePoConfirmModal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body" style="padding: 24px 20px;">
            <div style="display:flex; gap:14px; align-items:flex-start;">
                <div style="flex-shrink:0; width:40px; height:40px; border-radius:50%; background:rgba(245,158,11,0.12); display:flex; align-items:center; justify-content:center;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div>
                    <p style="margin:0 0 8px 0; font-weight:600; font-size:15px; color:var(--text-dark);">Issue this Purchase Order?</p>
                    <p style="margin:0; font-size:14px; color:var(--text-muted); line-height:1.6;">Changing the status from <strong>Draft</strong> to <strong>Issued</strong> will initiate payment processing for this order. Any configured payment will be recorded against this supplier.</p>
                    <p style="margin:10px 0 0 0; font-size:13px; color:#f59e0b; font-weight:500;">This action cannot be undone easily. Proceed?</p>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="justify-content:flex-end; gap:10px;">
            <button class="btn btn-outline"
                onclick="document.getElementById('issuePoConfirmModal').classList.remove('active')">Cancel</button>
            <a id="issuePoConfirmLink" href="#" class="btn btn-primary"
                style="background-color:#f59e0b; border-color:#f59e0b; color:#fff; text-decoration:none;">Yes, Issue Order</a>
        </div>
    </div>
</div>

<!-- Delete PO Modal -->
<div class="modal-overlay" id="deletePoModal">
    <div class="modal-content" style="max-width: 400px; width: 90%;">
        <div class="modal-header">
            <h2 style="color:var(--text-dark);">Delete Purchase Order</h2>
            <button class="close-modal"
                onclick="document.getElementById('deletePoModal').classList.remove('active')">&times;</button>
        </div>
        <form action="purchase_orders.php" method="POST">
            <div class="modal-body" style="padding: 20px;">
                <p style="margin-bottom: 20px; font-size: 15px; color: var(--text-dark);">Are you sure you want to
                    delete this purchase order?</p>
                <input type="hidden" name="delete_po_id" id="delete_po_id" value="">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="remove_stock" value="1" checked
                        style="width:16px; height:16px; cursor:pointer;">
                    <span style="font-size:14px; font-weight:500;">Deduct product quantities back from stock? (Only
                        applies if Received)</span>
                </label>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;">
                <button type="button" class="btn btn-outline" style="padding:8px 16px;"
                    onclick="document.getElementById('deletePoModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary"
                    style="padding:8px 16px; background-color:var(--danger-color); border-color:var(--danger-color);">Confirm
                    Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Unreceive PO Modal -->
<div class="modal-overlay" id="unreceivePoModal">
    <div class="modal-content" style="max-width: 450px; width: 90%;">
        <div class="modal-header">
            <h2 style="color:var(--text-dark);">Change Status</h2>
            <button class="close-modal"
                onclick="document.getElementById('unreceivePoModal').classList.remove('active')">&times;</button>
        </div>
        <form action="purchase_orders.php" method="POST">
            <div class="modal-body" style="padding: 20px;">
                <p style="margin-bottom: 20px; font-size: 15px; color: var(--text-dark);">This order is already
                    <strong>Received</strong>. Changing the status to Cancelled will reverse this action.
                </p>
                <input type="hidden" name="unreceive_po_id" id="unreceive_po_id" value="">

                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="remove_stock" value="1" checked
                        style="width:16px; height:16px; cursor:pointer;">
                    <span style="font-size:14px; font-weight:500;">Deduct product quantities back from stock?</span>
                </label>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;">
                <button type="button" class="btn btn-outline" style="padding:8px 16px;"
                    onclick="document.getElementById('unreceivePoModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary"
                    style="padding:8px 16px; background-color:var(--danger-color); border-color:var(--danger-color);">Confirm
                    Change</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addPurchaseOrderModal').classList.add('active');
            window.history.replaceState({}, document.title, "purchase_orders.php");
        });
    </script>
<?php endif; ?>

<script>
    function openUnreceiveModal(id) {
        document.getElementById('unreceive_po_id').value = id;
        document.getElementById('unreceivePoModal').classList.add('active');
    }

    function openDeleteModal(id) {
        document.getElementById('delete_po_id').value = id;
        document.getElementById('deletePoModal').classList.add('active');
    }

    // Intercept toggle: show confirmation when going Draft → Issued
    function handleToggle(id, currentStatus) {
        const toggleUrl = 'purchase_orders.php?action=toggle&id=' + id;
        if (currentStatus === 'Draft') {
            document.getElementById('issuePoConfirmLink').href = toggleUrl;
            document.getElementById('issuePoConfirmModal').classList.add('active');
        } else {
            window.location.href = toggleUrl;
        }
    }

    // Show/hide payment section based on selected PO status
    function togglePaymentSection(selectEl) {
        const isDraft = selectEl.value === 'Draft';
        const paySection  = document.getElementById('po_payment_section');
        const draftNotice = document.getElementById('po_draft_notice');
        if (isDraft) {
            paySection.style.display  = 'none';
            draftNotice.style.display = 'flex';
        } else {
            paySection.style.display  = 'grid';
            draftNotice.style.display = 'none';
        }
    }

    // Initialise payment section visibility when modal opens
    document.addEventListener('DOMContentLoaded', function () {
        const statusSel = document.getElementById('po_status_select');
        if (statusSel) togglePaymentSection(statusSel);
    });

    function updatePrice(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const price = parseFloat(selectedOption.getAttribute('data-price') || 0);

        const row = selectElement.closest('.line-item-row');
        const priceInput = row.querySelector('.price-input');
        const qtyInput = row.querySelector('.qty-input');

        priceInput.value = price.toFixed(2);
        calculateRowData(qtyInput);
    }

    function calculateRowData(qtyElement) {
        const row = qtyElement.closest('.line-item-row');
        const price = parseFloat(row.querySelector('.price-input').value || 0);
        const qty = parseInt(qtyElement.value || 0);
        const subtotalInput = row.querySelector('.subtotal-input');

        subtotalInput.value = (price * qty).toFixed(2);
        calculateGrandTotal();
    }

    function calculateRowData2(priceElement) {
        const row = priceElement.closest('.line-item-row');
        const qty = parseInt(row.querySelector('.qty-input').value || 0);
        const price = parseFloat(priceElement.value || 0);
        const subtotalInput = row.querySelector('.subtotal-input');

        subtotalInput.value = (price * qty).toFixed(2);
        calculateGrandTotal();
    }

    function calculateGrandTotal() {
        let total = 0;
        const subtotals = document.querySelectorAll('.subtotal-input');
        subtotals.forEach(input => {
            total += parseFloat(input.value || 0);
        });
        document.getElementById('grandTotalDisplay').innerText = '$ ' + total.toFixed(2);
        document.getElementById('grandTotalDisplay').dataset.total = total;
        handlePaymentChange();
    }

    function handlePaymentChange() {
        const status = document.getElementById('payment_status').value;
        const amountInput = document.getElementById('amount_paid');
        const total = parseFloat(document.getElementById('grandTotalDisplay').dataset.total || 0);

        if (status === 'Paid') {
            amountInput.value = total.toFixed(2);
            amountInput.readOnly = true;
        } else if (status === 'Credit') {
            amountInput.value = '0.00';
            amountInput.readOnly = true;
        } else {
            amountInput.readOnly = false;
            if (parseFloat(amountInput.value) >= total) {
                amountInput.value = '0.00';
            }
        }
        calculateBalance();
    }

    function calculateBalance() {
        const total   = parseFloat(document.getElementById('grandTotalDisplay').dataset.total || 0);
        const status  = document.getElementById('payment_status').value;
        let paid      = parseFloat(document.getElementById('amount_paid').value || 0);
        const display = document.getElementById('po_balance_display');
        const label   = document.getElementById('po_balance_amount');

        if (paid < 0) paid = 0;
        if (paid > total) {
            paid = total;
            document.getElementById('amount_paid').value = paid.toFixed(2);
        }

        if (status === 'Partial' && total > 0) {
            const remaining = total - paid;
            if (remaining > 0) {
                label.textContent = '$ ' + remaining.toFixed(2);
                display.style.display = 'block';
            } else {
                display.style.display = 'none';
            }
        } else {
            display.style.display = 'none';
        }
    }

    function addLineItem() {
        const container = document.getElementById('orderItemsContainer');
        const firstRow = container.querySelector('.line-item-row');
        const newRow = firstRow.cloneNode(true);

        newRow.querySelector('.product-select').selectedIndex = 0;
        newRow.querySelector('.qty-input').value = '';
        newRow.querySelector('.price-input').value = '';
        newRow.querySelector('.subtotal-input').value = '';

        container.appendChild(newRow);
        calculateGrandTotal();
    }

    function removeRow(btn) {
        const container = document.getElementById('orderItemsContainer');
        if (container.querySelectorAll('.line-item-row').length > 1) {
            btn.closest('.line-item-row').remove();
            calculateGrandTotal();
        } else {
            showCustomAlert("You need at least one item in the order.");
        }
    }
</script>
<script>
function fillPOSupplierAddress(sel) {
    var addr = sel.options[sel.selectedIndex].getAttribute('data-address') || '';
    document.getElementById('po_supplier_address').value = addr;
}
</script>
<?php include 'includes/footer.php'; ?>