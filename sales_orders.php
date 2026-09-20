<?php
require_once 'includes/auth_guard.php';

$user_role = $_SESSION['role'] ?? 'staff';
$page_title = 'Sales Orders';

// Fetch active tax rate for live display in create order modal
$so_tax_rate = 0;
$tax_rate_res = $conn->query("SELECT SUM(rate_percent) FROM tax_rules WHERE is_active = 1");
if ($tax_rate_res) { $so_tax_rate = floatval($tax_rate_res->fetch_row()[0]); }

// CSRF token (generated globally in db.php via auth_guard)
$csrf_token = $_SESSION['csrf_token'] ?? '';

// DELETE — now POST only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && in_array($user_role, ['admin'])) {
    verify_csrf();
    $del_id = (int) $_POST['delete_id'];
    $stmt = $conn->prepare("DELETE FROM sales_orders WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    if ($stmt->execute()) {
        header("Location: sales_orders.php?msg=deleted");
        exit();
    }
    $stmt->close();
}

// STATUS CYCLE — Pending → Completed → Cancelled → Pending
// When moving TO Completed: deduct stock
// When moving TO Cancelled: restore stock (with confirmation if coming from Completed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cycle_status_id']) && in_array($user_role, ['admin', 'manager'])) {
    verify_csrf();
    $cycle_id       = (int) $_POST['cycle_status_id'];
    $restore_stock  = isset($_POST['restore_stock']) && $_POST['restore_stock'] === '1';

    $conn->begin_transaction();
    try {
        $res = $conn->query("SELECT status FROM sales_orders WHERE id = $cycle_id");
        if ($res && $row = $res->fetch_assoc()) {
            $current = $row['status'];
            $cycle   = ['Pending' => 'Completed', 'Completed' => 'Cancelled', 'Cancelled' => 'Pending'];
            $next    = $cycle[$current] ?? 'Pending';

            // Moving TO Completed: deduct stock
            if ($next === 'Completed') {
                $items = $conn->query("SELECT product_id, quantity FROM sales_order_items WHERE sales_order_id = $cycle_id");
                while ($it = $items->fetch_assoc()) {
                    $pid = (int) $it['product_id'];
                    $qty = (int) $it['quantity'];
                    $conn->query("UPDATE products SET stock_level = stock_level - $qty WHERE id = $pid AND stock_level >= $qty");
                }
            }

            // Moving TO Cancelled AND user confirmed restore: add stock back
            if ($next === 'Cancelled' && $restore_stock) {
                $items = $conn->query("SELECT product_id, quantity FROM sales_order_items WHERE sales_order_id = $cycle_id");
                while ($it = $items->fetch_assoc()) {
                    $pid = (int) $it['product_id'];
                    $qty = (int) $it['quantity'];
                    $conn->query("UPDATE products SET stock_level = stock_level + $qty WHERE id = $pid");
                }
            }

            $conn->query("UPDATE sales_orders SET status = '$next' WHERE id = $cycle_id");
            $conn->commit();
            log_activity($conn, 'Update', 'Sales Order', $cycle_id, "Status changed: $current → $next");
            header("Location: sales_orders.php?msg=status_updated");
            exit();
        }
        $conn->rollback();
    } catch (\Throwable $e) {
        $conn->rollback();
    }
}

// Search & Filter
$search = $_GET['search'] ?? '';
$search_col = $_GET['search_col'] ?? 'order_id_str';
$status_filter = $_GET['status'] ?? '';

$allowed_cols = ['order_id_str', 'customer_id_str', 'customer_name'];
if (!in_array($search_col, $allowed_cols)) {
    $search_col = 'order_id_str';
}

$where_clauses = [];
if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    if ($search_col === 'customer_id_str' || $search_col === 'customer_name') {
        $where_clauses[] = "c.`$search_col` LIKE '%$safe_search%'";
    } else {
        $where_clauses[] = "so.`$search_col` LIKE '%$safe_search%'";
    }
}
if (!empty($status_filter)) {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "so.status = '$safe_status'";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Pagination Logic
$limit = 20;
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Count Total
$count_query = "SELECT COUNT(*) as total FROM sales_orders so LEFT JOIN customers c ON so.customer_id = c.id $where_sql";
$count_result = $conn->query($count_query);
$total_records = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_records / $limit);

// Fetch Data
$query = "SELECT so.*, c.customer_name, c.customer_id_str 
          FROM sales_orders so 
          LEFT JOIN customers c ON so.customer_id = c.id 
          $where_sql 
          ORDER BY so.created_at DESC 
          LIMIT $limit OFFSET $offset";
$result = $conn->query($query);

// Delete Logic
// Fetch dropdown data for Add Modal
$customers_res = $conn->query("SELECT id, customer_name, address, loyalty_points_balance FROM customers WHERE status='Active' ORDER BY customer_name");
$customers_list = [];
if ($customers_res) {
    while ($row = $customers_res->fetch_assoc()) {
        $customers_list[] = $row;
    }
}

$products_res = $conn->query("SELECT id, product_name, selling_price, stock_level FROM products");
$products_list = [];
if ($products_res) {
    while ($row = $products_res->fetch_assoc()) {
        $products_list[] = $row;
    }
}

// Generate next Sales Order ID
$next_id_res = $conn->query("SELECT MAX(id) FROM sales_orders");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_so_id = 'SO-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Sales Hub</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <!-- Hub Navigation Pill Tabs -->
    <div class="tab-navigation">
        <a href="sales_orders.php" class="tab-link active">Orders</a>
        <a href="payments_received.php" class="tab-link">Payments</a>
        <a href="sales_returns.php" class="tab-link">Returns</a>
        <a href="credit_notes.php" class="tab-link">Credit Notes</a>
    </div>

    <!-- Metrics -->
    <?php
    $metrics_res = $conn->query(
        "SELECT
            SUM(status = 'Completed')                   AS comp_st,
            SUM(status = 'Pending')                     AS pend_st,
            SUM(status = 'Cancelled')                   AS canc_st,
            SUM(CASE WHEN status='Completed' THEN total_amount ELSE 0 END) AS tot_rev
         FROM sales_orders"
    );
    $metrics     = $metrics_res ? $metrics_res->fetch_assoc() : [];
    $comp_st     = (int)   ($metrics['comp_st'] ?? 0);
    $pend_st     = (int)   ($metrics['pend_st'] ?? 0);
    $canc_st     = (int)   ($metrics['canc_st'] ?? 0);
    $tot_rev     = (float) ($metrics['tot_rev'] ?? 0);
    ?>
    <div class="metrics-grid" style="margin-bottom: 30px;">
        <div class="metric-card border-green">
            <div class="metric-title">Completed Orders <span class="dots">⋮</span></div>
            <div class="metric-value"><?= number_format($comp_st) ?></div>
        </div>
        <div class="metric-card border-yellow">
            <div class="metric-title">Pending Orders <span class="dots">⋮</span></div>
            <div class="metric-value" style="color: #f59e0b;"><?= number_format($pend_st) ?></div>
        </div>
        <div class="metric-card border-red">
            <div class="metric-title">Cancelled Orders <span class="dots">⋮</span></div>
            <div class="metric-value" style="color:#ef4444;"><?= number_format($canc_st) ?></div>
        </div>
        <div class="metric-card border-blue">
            <div class="metric-title">Total Revenue <span class="dots">⋮</span></div>
            <div class="metric-value" style="color: #10b981;">$<?= number_format($tot_rev, 2) ?></div>
            <div class="metric-change positive">From completed sales</div>
        </div>
    </div>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <!-- Search Form -->

                <!-- Status Filter Tabs -->
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Pending' => 'Pending', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'] as $val => $label):
                        $href = $val === '' ? 'sales_orders.php' : "sales_orders.php?status=$val";
                        $active = $status_filter === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addSalesOrderModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px; text-decoration:none;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Create Sales Order
                </button>
                <a href="#" class="btn btn-primary"
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
<div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
        <th class="cb-col"><input type="checkbox" class="select-all-cb" id="selectAllCb" title="Select all"></th><th>Order Number</th>
                        <th>Customer</th>
                        <th>Order Date</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr data-row-id="<?= $row['id'] ?>" data-row-table="sales_orders">
                                <td style="width:30px;padding-right:0"><input type="checkbox" class="row-cb" value="<?= $row['id'] ?>"></td>
                                <td>
                                    <div style="font-weight:600; color:var(--text-dark);">
                                        <?= htmlspecialchars($row['order_id_str']) ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                                        Type: <?= htmlspecialchars($row['fulfillment_type'] ?? 'Unknown') ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['customer_id'] > 0): ?>
                                        <div style="font-weight:500;">
                                            <?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?>
                                        </div>
                                        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                                            <?= htmlspecialchars($row['customer_id_str'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-weight:500; font-style:italic; color:var(--text-muted);">Walk-in (POS)
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($row['order_date'])) ?></td>
                                <td>
                                    <div style="font-weight: 700; color:var(--text-dark);">
                                        $<?= number_format($row['total_amount'], 2) ?>
                                    </div>
                                </td>
                                <td><?= statusBadge($row['status']) ?></td>
                                <td>
                                    <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                        <a href="view_sales_order.php?order_id=<?= $row['id'] ?>" title="View details">
                                            <svg class="inbox-icon" viewBox="0 0 24 24"
                                                style="stroke: var(--primary-color); width: 16px; height: 16px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round;">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                        </a>
                                        <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                                            <?php
                                                $status_map = [
                                                    'Pending'   => ['next' => 'Completed', 'title' => 'Mark as Completed', 'color' => '#10b981'],
                                                    'Completed' => ['next' => 'Cancelled',  'title' => 'Mark as Cancelled',  'color' => '#ef4444'],
                                                    'Cancelled' => ['next' => 'Pending',    'title' => 'Mark as Pending',    'color' => '#f59e0b'],
                                                ];
                                                $s = $status_map[$row['status']] ?? null;
                                            ?>
                                            <?php if ($s): ?>
                                                <form method="POST" action="sales_orders.php" style="display:inline;"
                                                    onsubmit="event.preventDefault(); handleCycleSubmit(this, '<?= $row['status'] ?>', '<?= $s['next'] ?>');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <input type="hidden" name="cycle_status_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="restore_stock" value="0" class="restore-stock-input">
                                                    <button type="submit" title="<?= $s['title'] ?>"
                                                        style="background:none;border:none;cursor:pointer;padding:0;">
                                                        <svg class="toggle-icon" viewBox="0 0 24 24"
                                                            style="stroke:<?= $s['color'] ?>;">
                                                            <polyline points="1 4 1 10 7 10"></polyline>
                                                            <polyline points="23 20 23 14 17 14"></polyline>
                                                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (in_array($user_role, ['admin'])): ?>
                                            <form method="POST" action="sales_orders.php" style="display:inline;" onsubmit="event.preventDefault(); showCustomConfirm('Are you sure you want to permanently delete this order?', () => this.submit());">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                                <button type="submit" title="Delete" style="background:none;border:none;cursor:pointer;padding:0;">
                                                    <svg class="delete-icon" viewBox="0 0 24 24" style="width: 16px; height: 16px;">
                                                        <polyline points="3 6 5 6 21 6"></polyline>
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-state-row"><td colspan="7"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg><div class="empty-state-title">No Sales Orders Yet</div><div class="empty-state-desc">Create your first sales order to start selling.</div><a href="add_sales_order.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Sales Order</a></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&search_col=<?= $search_col ?>&status=<?= urlencode($status_filter) ?>"
                        class="page-link">Previous</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&search_col=<?= $search_col ?>&status=<?= urlencode($status_filter) ?>"
                        class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&search_col=<?= $search_col ?>&status=<?= urlencode($status_filter) ?>"
                        class="page-link">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<!-- Create Sales Order Modal -->
<div class="modal-overlay" id="addSalesOrderModal">
    <div class="modal-content" style="max-width: 800px; width: 95%;">
        <div class="modal-header">
            <h2>Create New Sales Order</h2>
            <button class="close-modal"
                onclick="document.getElementById('addSalesOrderModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_sales_order.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Sales Order ID</label>
                        <input type="text" name="order_id_str" class="form-input" value="<?= $gen_so_id ?>" readonly
                            style="cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="so_customer_id" class="form-input" required onchange="fillSOCustomerAddress(this); updateSOLoyalty(this);">
                            <option value="" disabled selected>Select Customer</option>
                            <?php foreach ($customers_list as $cust): ?>
                                <option value="<?= $cust['id'] ?>"
                                    data-address="<?= htmlspecialchars($cust['address'] ?? '') ?>"
                                    data-points="<?= intval($cust['loyalty_points_balance'] ?? 0) ?>">
                                    <?= htmlspecialchars($cust['customer_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Fulfillment Type</label>
                        <select name="fulfillment_type" id="fulfillment_type" class="form-input" required onchange="calculateGrandTotal()">
                            <option value="Pickup" selected>Pickup</option>
                            <option value="Delivery">Delivery</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:20px;">
                    <div>
                        <label class="form-label">Order Date</label>
                        <input type="date" name="order_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Shipping Address (Optional)</label>
                        <input type="text" name="shipping_address" id="so_shipping_address" class="form-input" placeholder="If delivery...">
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <h3 style="font-size: 16px; margin-bottom: 10px;">Order Items</h3>
                    <div id="soItemsContainer" style="display:flex; flex-direction:column; gap:10px;">
                        <div class="line-item-row"
                            style="display:grid; grid-template-columns: 2fr 1fr 1fr 1fr 40px; gap:10px; align-items:end;">
                            <div>
                                <label class="form-label">Product</label>
                                <select name="product_id[]" class="form-input product-select" required
                                    onchange="updatePrice(this)">
                                    <option value="" disabled selected>Select Product</option>
                                    <?php foreach ($products_list as $prod): ?>
                                        <option value="<?= $prod['id'] ?>" data-price="<?= $prod['selling_price'] ?>">
                                            <?= htmlspecialchars($prod['product_name']) ?> (Stock:
                                            <?= $prod['stock_level'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity[]" class="form-input qty-input" min="1"
                                    placeholder="Qty" required oninput="calculateRowData(this)">
                            </div>
                            <div>
                                <label class="form-label">Unit Price ($)</label>
                                <input type="number" step="0.01" class="form-input price-input" placeholder="$0.00"
                                    readonly style="color:var(--text-muted); cursor:not-allowed;">
                            </div>
                            <div>
                                <label class="form-label">Subtotal ($)</label>
                                <input type="text" class="form-input subtotal-input" readonly placeholder="0.00"
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
                            style="background:none; border:none; color:var(--primary-color); font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; font-size:14px;"
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

                <div
                    style="margin-top: 20px; display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px; padding:15px; border-radius:8px;">
                    <div>
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" id="payment_status" class="form-input" required
                            onchange="handlePaymentChange()">
                            <option value="Paid" selected>Paid</option>
                            <option value="Partial">Partial</option>
                            <option value="Credit">Credit</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-input" required>
                            <option value="Cash">Cash</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Amount Paid ($)</label>
                        <input type="number" step="0.01" name="amount_paid" id="amount_paid" class="form-input"
                            value="0.00" oninput="calculateBalance()" readonly>
                        <div id="balance_display" style="display:none; margin-top:6px; padding:7px 10px; border-radius:6px; background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25);">
                            <span style="font-size:12px; color:var(--text-muted);">Remaining balance:</span>
                            <span id="balance_amount" style="font-size:13px; font-weight:700; color:#f59e0b; margin-left:4px;">$0.00</span>
                            <span style="font-size:11px; color:var(--text-muted); margin-left:4px;">(credit note will be issued)</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; border-top: 2px solid var(--border-color); padding-top: 20px;">
                    <!-- Loyalty Points Redemption Panel -->
                    <div id="so_loyalty_panel" style="display:none; background:var(--bg-body); border:1.5px solid var(--border-color); border-radius:10px; padding:14px 16px; margin-bottom:16px;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                            <svg viewBox="0 0 24 24" width="16" height="16" stroke="var(--primary-color)" fill="none" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span style="font-size:13px; font-weight:600; color:var(--text-dark);">Loyalty Points</span>
                            <span id="so_loyalty_balance_badge" style="font-size:11px; font-weight:700; background:rgba(109,74,255,.1); color:var(--primary-color); padding:2px 8px; border-radius:20px;"></span>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:160px;">
                                <label style="font-size:11px; color:var(--text-muted); display:block; margin-bottom:4px;">Points to redeem</label>
                                <input type="number" id="so_points_input" name="points_to_redeem" min="0" value="0"
                                    style="width:100%; padding:7px 10px; border:1px solid var(--border-color); border-radius:7px; font-size:13px; background:var(--input-bg); color:var(--text-dark); outline:none; box-sizing:border-box;"
                                    oninput="updateSOLoyaltyDiscount()">
                            </div>
                            <div style="font-size:12px; color:var(--text-muted); padding-top:16px;">
                                = <span id="so_loyalty_discount_display" style="font-weight:700; color:var(--success-color);">$0.00 off</span>
                            </div>
                            <button type="button" onclick="applySOMaxPoints()" style="padding:7px 14px; border:1px solid var(--primary-color); background:transparent; color:var(--primary-color); border-radius:7px; cursor:pointer; font-size:12px; font-weight:600; margin-top:16px;">Use Max</button>
                        </div>
                        <div id="so_loyalty_hint" style="font-size:11px; color:var(--text-muted); margin-top:6px;"></div>
                    </div>

                    <!-- Order totals breakdown -->
                    <div style="border-top:1px solid var(--border-color); padding-top:14px; margin-top:4px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; color:var(--text-muted);">Subtotal (before tax):</span>
                            <span style="font-size:13px; color:var(--text-dark); font-weight:500;" id="subtotalBeforeTax">$ 0.00</span>
                        </div>
                        <div id="taxBreakdownRow" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; color:var(--text-muted);">Tax (<span id="taxRateLabel"><?= $so_tax_rate ?>%</span>):</span>
                            <span style="font-size:13px; color:var(--text-dark); font-weight:500;" id="taxAmountDisplay">$ 0.00</span>
                        </div>
                        <div id="deliveryBreakdownRow" style="display:none; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:13px; color:var(--text-muted);">Delivery Charge:</span>
                            <span style="font-size:13px; color:var(--text-dark); font-weight:500;">$ 100.00</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; padding-top:10px; border-top:1px solid var(--border-color); margin-top:4px;">
                            <span style="font-weight:700; font-size:15px;">Total (after tax):</span>
                            <h2 style="color:var(--primary-color); margin:0;" id="grandTotalDisplay" data-total="0">$ 0.00</h2>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="submit" class="btn btn-primary"
                    style="padding: 14px 40px; border-radius:8px; font-size:15px; font-weight:500;">Create Sales
                    Order</button>
            </div>
        </form>
    </div>
</div>

<script>
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

    function calculateGrandTotal() {
        let subtotal = 0;
        const subtotals = document.querySelectorAll('.subtotal-input');
        subtotals.forEach(input => {
            subtotal += parseFloat(input.value || 0);
        });

        const taxRate = parseFloat('<?= $so_tax_rate ?>') || 0;
        const taxAmount = subtotal * (taxRate / 100);
        const fulfillment = document.getElementById('fulfillment_type') ? document.getElementById('fulfillment_type').value : '';
        const deliveryCharge = (fulfillment === 'Delivery') ? 100 : 0;
        const total = subtotal + taxAmount + deliveryCharge;

        // Update breakdown rows
        document.getElementById('subtotalBeforeTax').textContent = '$ ' + subtotal.toFixed(2);
        document.getElementById('taxAmountDisplay').textContent  = '$ ' + taxAmount.toFixed(2);

        const delivRow = document.getElementById('deliveryBreakdownRow');
        if (delivRow) delivRow.style.display = deliveryCharge > 0 ? 'flex' : 'none';

        document.getElementById('grandTotalDisplay').innerText = '$ ' + total.toFixed(2);
        document.getElementById('grandTotalDisplay').dataset.total = total;
        handlePaymentChange();
        if (typeof updateSOLoyaltyDiscount === 'function') updateSOLoyaltyDiscount();
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
        const total = parseFloat(document.getElementById('grandTotalDisplay').dataset.total || 0);
        let paid = parseFloat(document.getElementById('amount_paid').value || 0);
        const status = document.getElementById('payment_status').value;
        const balanceDisplay = document.getElementById('balance_display');
        const balanceAmount = document.getElementById('balance_amount');

        if (paid < 0) paid = 0;
        if (paid > total) {
            paid = total;
            document.getElementById('amount_paid').value = paid.toFixed(2);
        }

        if (status === 'Partial' && total > 0) {
            const remaining = total - paid;
            if (remaining > 0) {
                balanceAmount.textContent = '$ ' + remaining.toFixed(2);
                balanceDisplay.style.display = 'block';
            } else {
                balanceDisplay.style.display = 'none';
            }
        } else {
            balanceDisplay.style.display = 'none';
        }
    }

    function addLineItem() {
        const container = document.getElementById('soItemsContainer');
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
        const container = document.getElementById('soItemsContainer');
        if (container.querySelectorAll('.line-item-row').length > 1) {
            btn.closest('.line-item-row').remove();
            calculateGrandTotal();
        } else {
            showCustomAlert("You need at least one item in the order.");
        }
    }
</script>

<!-- Restore Stock Confirmation Modal (shown when cycling Completed → Cancelled) -->
<div class="modal-overlay" id="restoreStockModal">
    <div class="modal-content" style="max-width:420px; width:90%;">
        <div class="modal-header">
            <h2>Restore Stock?</h2>
            <button class="close-modal" onclick="document.getElementById('restoreStockModal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px;">
            <p style="font-size:14px; color:var(--text-dark); margin-bottom:20px; line-height:1.6;">
                This order was <strong>Completed</strong> — its items were already deducted from inventory.<br><br>
                Do you want to add those products back to inventory when cancelling?
            </p>
            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button onclick="submitCycleForm(false)"
                    class="btn btn-outline" style="padding:10px 20px; border-radius:8px; font-size:14px;">
                    Cancel only (keep stock as-is)
                </button>
                <button onclick="submitCycleForm(true)"
                    class="btn btn-primary" style="padding:10px 20px; border-radius:8px; font-size:14px;">
                    Cancel &amp; restore stock
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let _pendingCycleForm = null;

function handleCycleSubmit(form, currentStatus, nextStatus) {
    if (currentStatus === 'Completed' && nextStatus === 'Cancelled') {
        // Show the restore-stock confirmation modal
        _pendingCycleForm = form;
        document.getElementById('restoreStockModal').classList.add('active');
    } else if (nextStatus === 'Cancelled') {
        // Cycling from Pending → Cancelled: simple confirm, no stock to restore
        showCustomConfirm('Mark this order as Cancelled?', () => {
            form.querySelector('.restore-stock-input').value = '0';
            form.submit();
        });
    } else if (nextStatus === 'Completed') {
        showCustomConfirm('Mark this order as Completed? Stock will be deducted from inventory.', () => {
            form.submit();
        });
    } else {
        // Cycling back to Pending — no stock action needed
        showCustomConfirm('Reset this order back to Pending?', () => {
            form.submit();
        });
    }
}

function submitCycleForm(restoreStock) {
    document.getElementById('restoreStockModal').classList.remove('active');
    if (_pendingCycleForm) {
        _pendingCycleForm.querySelector('.restore-stock-input').value = restoreStock ? '1' : '0';
        _pendingCycleForm.submit();
        _pendingCycleForm = null;
    }
}
</script>

<style>
    /* Same table/card aesthetics as other pages */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
    }
</style>

<script>
function fillSOCustomerAddress(sel) {
    var addr = sel.options[sel.selectedIndex].getAttribute('data-address') || '';
    document.getElementById('so_shipping_address').value = addr;
}

// ── Loyalty helpers ────────────────────────────────────────────────────────
const SO_REDEEM_RATE    = 0.05;   // $0.05 per point — matches loyalty_config default
const SO_MAX_REDEEM_PCT = 0.25;   // 25% of order max — matches loyalty_config default
const SO_MIN_POINTS     = 100;    // min points to redeem

let so_customer_points = 0;

function updateSOLoyalty(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const points = parseInt(opt.getAttribute('data-points') || 0);
    so_customer_points = points;
    const panel  = document.getElementById('so_loyalty_panel');

    if (!opt.value || points <= 0) {
        panel.style.display = 'none';
        document.getElementById('so_points_input').value = 0;
        return;
    }

    panel.style.display = 'block';
    document.getElementById('so_loyalty_balance_badge').textContent = points + ' pts available';
    document.getElementById('so_loyalty_hint').textContent =
        'Min ' + SO_MIN_POINTS + ' pts to redeem · each point = $' + SO_REDEEM_RATE.toFixed(2) + ' off · max 25% of order';
    document.getElementById('so_points_input').max = points;
    updateSOLoyaltyDiscount();
}

function updateSOLoyaltyDiscount() {
    const pts    = Math.min(parseInt(document.getElementById('so_points_input').value || 0), so_customer_points);
    const total  = parseFloat(document.getElementById('grandTotalDisplay').dataset.total || 0);
    const maxDisc = total * SO_MAX_REDEEM_PCT;
    let discount  = pts * SO_REDEEM_RATE;
    if (discount > maxDisc) discount = maxDisc;
    document.getElementById('so_loyalty_discount_display').textContent = '$' + discount.toFixed(2) + ' off';
}

function applySOMaxPoints() {
    const total    = parseFloat(document.getElementById('grandTotalDisplay').dataset.total || 0);
    const maxDisc  = total * SO_MAX_REDEEM_PCT;
    const maxByPct = Math.floor(maxDisc / SO_REDEEM_RATE);
    const maxPts   = Math.min(so_customer_points, maxByPct);
    document.getElementById('so_points_input').value = maxPts;
    updateSOLoyaltyDiscount();
}

// ── Checkbox select-all logic ──────────────────────────────────────────────
(function () {
    const selectAllCb = document.getElementById('selectAllCb');
    if (!selectAllCb) return;

    function getRowCbs() {
        return Array.from(document.querySelectorAll('table.data-table tbody .row-cb'));
    }

    function updateBulkBar() {
        const cbs = getRowCbs();
        const checked = cbs.filter(cb => cb.checked);
        let bar = document.getElementById('so-bulk-bar');
        if (checked.length > 0) {
            if (!bar) {
                bar = document.createElement('div');
                bar.id = 'so-bulk-bar';
                bar.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 24px;background:var(--primary-color);color:#fff;font-size:13px;font-weight:600;border-radius:8px;margin-bottom:12px;';
                bar.innerHTML = '<span id="so-bulk-count"></span><span style="opacity:.7;font-weight:400;">orders selected</span>';
                const tableWrap = document.querySelector('.table-responsive') || document.querySelector('.data-table-container');
                if (tableWrap) tableWrap.parentNode.insertBefore(bar, tableWrap);
            }
            document.getElementById('so-bulk-count').textContent = checked.length;
            bar.style.display = 'flex';
        } else {
            if (bar) bar.style.display = 'none';
        }
    }

    function syncSelectAll() {
        const cbs = getRowCbs();
        const checkedCount = cbs.filter(cb => cb.checked).length;
        if (checkedCount === 0) {
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
        } else if (checkedCount === cbs.length) {
            selectAllCb.checked = true;
            selectAllCb.indeterminate = false;
        } else {
            selectAllCb.checked = false;
            selectAllCb.indeterminate = true;
        }
        updateBulkBar();
    }

    selectAllCb.addEventListener('change', function () {
        getRowCbs().forEach(cb => { cb.checked = this.checked; });
        updateBulkBar();
    });

    document.querySelector('table.data-table tbody') && document.querySelector('table.data-table tbody').addEventListener('change', function (e) {
        if (e.target.classList.contains('row-cb')) syncSelectAll();
    });
})();
</script>
<?php include 'includes/footer.php'; ?>