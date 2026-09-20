<?php
$search_options = array(
    'sr.return_id_str' => 'Return ID',
    'so.order_id_str' => 'Order ID',
    'c.first_name' => 'Customer Name',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

// ── Log Sales Return (with line items) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_id_str'])) {
    $return_id_str  = $conn->real_escape_string($_POST['return_id_str']);
    $customer_id    = intval($_POST['customer_id']);
    $sales_order_id = intval($_POST['sales_order_id']);
    $return_date    = $conn->real_escape_string($_POST['return_date']);
    $reason         = $conn->real_escape_string($_POST['reason'] ?? '');
    $status         = 'Pending';

    // Line items submitted as arrays
    $product_ids      = $_POST['product_id']      ?? [];
    $return_qtys      = $_POST['return_qty']       ?? [];
    $unit_prices      = $_POST['unit_price']       ?? [];

    // Compute total refund from items only (delivery charge excluded)
    $total_refund = 0;
    $line_items   = [];
    foreach ($product_ids as $i => $pid) {
        $pid   = intval($pid);
        $qty   = intval($return_qtys[$i] ?? 0);
        $price = floatval($unit_prices[$i] ?? 0);
        if ($pid > 0 && $qty > 0 && $price > 0) {
            $subtotal       = $qty * $price;
            $total_refund  += $subtotal;
            $line_items[]   = ['product_id' => $pid, 'qty' => $qty, 'unit_price' => $price, 'subtotal' => $subtotal];
        }
    }

    if (empty($line_items)) {
        header("Location: sales_returns.php?msg=error_no_items"); exit();
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO sales_returns (return_id_str, customer_id, sales_order_id, return_date, total_refund_amount, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisdss", $return_id_str, $customer_id, $sales_order_id, $return_date, $total_refund, $reason, $status);
        $stmt->execute();
        $new_return_id = $conn->insert_id;
        $stmt->close();

        $item_stmt = $conn->prepare("INSERT INTO sales_return_items (sales_return_id, product_id, quantity_returned, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        foreach ($line_items as $li) {
            $item_stmt->bind_param("iiidd", $new_return_id, $li['product_id'], $li['qty'], $li['unit_price'], $li['subtotal']);
            $item_stmt->execute();
        }
        $item_stmt->close();
        $conn->commit();
        header("Location: sales_returns.php?msg=added");
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: sales_returns.php?msg=error_1");
    }
    exit();
}
// ─────────────────────────────────────────────────────────────────────────────

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle Status)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM sales_returns WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status, sales_order_id FROM sales_returns WHERE id = $id");
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $current_status = $row['status'];
            $sales_order_id = $row['sales_order_id'];

            $status_cycle = ['Pending' => 'Processed', 'Processed' => 'Rejected', 'Rejected' => 'Pending'];
            $new_status = $status_cycle[$current_status];

            // Restock items if selected when moving to Processed
            if ($new_status === 'Processed' && isset($_GET['restock']) && $_GET['restock'] == '1') {
                // Restock using the actual returned quantities, not the full order quantities
                $items_res = $conn->query("SELECT product_id, quantity_returned FROM sales_return_items WHERE sales_return_id = $id");
                if ($items_res) {
                    while ($item = $items_res->fetch_assoc()) {
                        $pid = $item['product_id'];
                        $qty = $item['quantity_returned'];
                        $conn->query("UPDATE products SET stock_level = stock_level + $qty WHERE id = $pid");
                    }
                }
            }

            $conn->query("UPDATE sales_returns SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: sales_returns.php");
    exit();
}

// Fetch Metrics
$total_returns_res = $conn->query("SELECT COUNT(*) FROM sales_returns");
$total_returns = $total_returns_res ? $total_returns_res->fetch_row()[0] : 0;

$processed_returns_res = $conn->query("SELECT COUNT(*) FROM sales_returns WHERE status='Processed'");
$processed_returns = $processed_returns_res ? $processed_returns_res->fetch_row()[0] : 0;

$pending_returns_res = $conn->query("SELECT COUNT(*) FROM sales_returns WHERE status='Pending'");
$pending_returns = $pending_returns_res ? $pending_returns_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Status filter pill
$status_filter = $_GET['status'] ?? 'All';
$allowed_statuses = ['All', 'Approved', 'Pending', 'Rejected'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = 'All';
$status_where = $status_filter !== 'All' ? "WHERE sr.status = '" . $conn->real_escape_string($status_filter) . "'" : '';

// Fetch Returns with Customer Name
$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];
    if (in_array($col, $allowed_cols)) {
        $search_sql = $status_where ? "AND $col LIKE '%$search%'" : "WHERE $col LIKE '%$search%'";
    }
}
$where_combined = $status_where . $search_sql;
$query = "
    SELECT sr.*, 
        c.customer_name,
        so.order_id_str
    FROM sales_returns sr
    LEFT JOIN customers c ON sr.customer_id = c.id
    LEFT JOIN sales_orders so ON sr.sales_order_id = so.id
    $where_combined
    ORDER BY sr.created_at DESC
    LIMIT $limit OFFSET $offset
";
$returns = $conn->query($query);
$total_filtered_ret = (int)$conn->query("SELECT COUNT(*) FROM sales_returns sr $where_combined")->fetch_row()[0];

// Fetch Customers for Add Modal
$customers_res = $conn->query("SELECT id, customer_name FROM customers WHERE status = 'Active' ORDER BY customer_name");
$customers_list = [];
if ($customers_res) {
    while ($row = $customers_res->fetch_assoc()) {
        $customers_list[] = $row;
    }
}
// (Sales orders per customer loaded dynamically via AJAX)

$current_page = 'sales_returns.php';

// Generate next Sales Return Ref
$next_id_res = $conn->query("SELECT MAX(id) FROM sales_returns");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_sr_id = 'RET-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
$current_page = 'sales_returns.php';
$page_title = 'Sales Returns';

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
        <a href="sales_orders.php" class="tab-link">Orders</a>
        <a href="payments_received.php" class="tab-link">Payments</a>
        <a href="sales_returns.php" class="tab-link active">Returns</a>
        <a href="credit_notes.php" class="tab-link">Credit Notes</a>
    </div>
    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Returns</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_returns ?></div>
            <div class="page-metric-trend">Total logged returned goods</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Pending Action</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $pending_returns ?></div>
            <div class="page-metric-trend">Needs evaluation</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Processed Returns</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $processed_returns ?></div>
            <div class="page-metric-trend">Closed/Refunded</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Rejected Returns</div>
            <div class="page-metric-value" style="color:#ef4444;">
                <?= $conn->query("SELECT COUNT(*) FROM sales_returns WHERE status='Rejected'")->fetch_row()[0] ?? 0 ?>
            </div>
            <div class="page-metric-trend">Return denied</div>
        </div>
    </div>
    <h2 style="font-size: 20px; margin-bottom: 20px;">Recent Returns</h2>
    <div class="data-table-container">
        <!-- Status filter tabs -->
        <?php
        $ret_base = '?';
        $ret_carry = $_GET; unset($ret_carry['status'], $ret_carry['page']);
        if ($ret_carry) $ret_base .= http_build_query($ret_carry) . '&';
        ?>

        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['All','Approved','Pending','Rejected'] as $s):
                        $active = $status_filter === $s; ?>
                        <a href="<?= $ret_base ?>status=<?= urlencode($s) ?>"
                           style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap;
                                  <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                            <?= $s ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addReturnModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Log Return
                </button>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Return Ref #</th>
                    <th>Original Order</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Refund Amount</th>
                    <th>Status Badge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($returns && $returns->num_rows > 0): ?>
                    <?php while ($row = $returns->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="sales_returns">
                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['return_id_str']) ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-dark);">
                                <?= htmlspecialchars($row['order_id_str'] ?? 'Unknown Order') ?>
                            </td>
                            <td style="font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['return_date']) ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">$
                                <?= number_format($row['total_refund_amount'], 2) ?>
                            </td>
                            <td><?= statusBadge($row['status']) ?></td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_refund_receipt.php?sr_id=<?= $row['id'] ?>"
                                       title="View Refund Receipt"
                                       style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:rgba(109,74,255,0.10); text-decoration:none;">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--primary-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </a>
                                    <a href="sales_returns.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this return record?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="#"
                                        onclick="handleReturnToggle(<?= $row['id'] ?>, '<?= $row['status'] ?>'); return false;"
                                        title="Cycle Status"><svg class="toggle-icon" viewBox="0 0 24 24"
                                            style="stroke: var(--primary-color);">
                                            <polyline points="1 4 1 10 7 10"></polyline>
                                            <polyline points="23 20 23 14 17 14"></polyline>
                                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
                                        </svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><polyline points="15 14 20 9 15 4"/><path d="M4 20v-7a4 4 0 0 1 4-4h12"/></svg><div class="empty-state-title">No Sales Returns Yet</div><div class="empty-state-desc">Record returned goods received from customers.</div><a href="sales_returns.php?add=1" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Return</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_filtered_ret, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Return Modal -->
<div class="modal-overlay" id="addReturnModal">
    <div class="modal-content" style="max-width: 620px; width: 95%;">
        <div class="modal-header">
            <h2>Log Sales Return</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="close-modal" onclick="document.getElementById('addReturnModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="sales_returns.php" method="POST" id="addReturnForm">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:18px;">

                    <!-- Row 1: Ref + Date -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                        <div>
                            <label class="form-label">Return Reference #</label>
                            <input type="text" name="return_id_str" class="form-input" value="<?= $gen_sr_id ?>" readonly
                                style="background-color:var(--bg-light);cursor:not-allowed;color:var(--text-muted);">
                        </div>
                        <div>
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Row 2: Order Type Toggle -->
                    <div>
                        <label class="form-label">Order Type</label>
                        <div style="display:flex; border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                            <button type="button" id="btn_regular_order" onclick="switchOrderMode('regular')"
                                style="flex:1; padding:9px 14px; font-size:13px; font-weight:600; border:none; cursor:pointer; background:var(--primary-color); color:#fff; transition:all .15s;">
                                Regular (by Customer)
                            </button>
                            <button type="button" id="btn_pos_order" onclick="switchOrderMode('pos')"
                                style="flex:1; padding:9px 14px; font-size:13px; font-weight:600; border:none; border-left:1px solid var(--border-color); cursor:pointer; background:var(--bg-light); color:var(--text-muted); transition:all .15s;">
                                POS / Walk-in (by Order ID)
                            </button>
                        </div>
                    </div>

                    <!-- Regular Customer Lookup -->
                    <div id="sr_customer_wrap">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="sr_customer_id" class="form-input" onchange="srLoadOrders(this.value)">
                            <option value="" disabled selected>Select Customer</option>
                            <?php foreach ($customers_list as $cust): ?>
                                <option value="<?= htmlspecialchars($cust['id']) ?>">
                                    <?= htmlspecialchars($cust['customer_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- POS Order ID Direct Lookup -->
                    <div id="sr_pos_wrap" style="display:none;">
                        <label class="form-label">POS / Walk-in Order ID</label>
                        <div style="display:flex; gap:10px; align-items:flex-start;">
                            <input type="text" id="sr_pos_order_input" class="form-input" placeholder="e.g. SO-0042" style="flex:1; text-transform:uppercase;">
                            <button type="button" onclick="srLookupPosOrder()"
                                style="padding:9px 16px; border-radius:8px; border:1.5px solid var(--border-color); background:var(--card-bg); color:var(--text-dark); font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap;">
                                Look Up
                            </button>
                        </div>
                        <div id="sr_pos_status" style="font-size:12px; margin-top:6px; display:none;"></div>
                        <!-- Hidden fields for POS path -->
                        <input type="hidden" name="customer_id" id="sr_pos_customer_hidden" value="0">
                    </div>

                    <!-- Row 3: Order (loaded after customer is picked) -->
                    <div id="sr_order_wrap" style="display:none;">
                        <label class="form-label">Original Sales Order</label>
                        <select name="sales_order_id" id="sr_order_id" class="form-input" onchange="srLoadItems(this.value)">
                            <option value="" disabled selected>Select Order</option>
                        </select>
                        <span id="sr_order_loading" style="font-size:12px;color:var(--text-muted);display:none;">Loading orders…</span>
                    </div>

                    <!-- Row 4: Items table (loaded after order is picked) -->
                    <div id="sr_items_wrap" style="display:none;">
                        <label class="form-label" style="margin-bottom:8px;">
                            Select Items to Return
                            <span style="font-weight:400; color:var(--text-muted); font-size:12px; margin-left:6px;">— check items and enter quantity returned</span>
                        </label>
                        <div style="border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr style="background:var(--bg-light);">
                                        <th style="padding:8px 10px; text-align:left; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Return?</th>
                                        <th style="padding:8px 10px; text-align:left; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Product</th>
                                        <th style="padding:8px 10px; text-align:center; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Ordered</th>
                                        <th style="padding:8px 10px; text-align:center; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Qty to Return</th>
                                        <th style="padding:8px 10px; text-align:right; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Unit Price</th>
                                        <th style="padding:8px 10px; text-align:right; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Refund</th>
                                    </tr>
                                </thead>
                                <tbody id="sr_items_body">
                                    <!-- Rows inserted via JS -->
                                </tbody>
                                <tfoot>
                                    <!-- Delivery charge non-refundable notice -->
                                    <tr id="sr_delivery_row" style="display:none;">
                                        <td colspan="5" style="padding:8px 10px; font-size:12px; color:var(--text-muted); border-top:1px solid var(--border-color);">
                                            <span style="display:inline-flex;align-items:center;gap:5px;">
                                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                                Delivery Charge: <strong id="sr_delivery_amount">'+\$currency['symbol']+'0.00</strong> — Non-Refundable
                                            </span>
                                        </td>
                                        <td style="padding:8px 10px; text-align:right; font-size:12px; color:#ef4444; font-weight:600; border-top:1px solid var(--border-color);">—</td>
                                    </tr>
                                    <tr style="background:var(--bg-light);">
                                        <td colspan="5" style="padding:10px; font-weight:600; font-size:13px; border-top:1px solid var(--border-color);">Total Refund</td>
                                        <td style="padding:10px; text-align:right; font-weight:700; font-size:15px; color:var(--primary-color); border-top:1px solid var(--border-color);" id="sr_total_refund">'+\$currency['symbol']+'0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <span id="sr_items_loading" style="font-size:12px;color:var(--text-muted);display:none;">Loading items…</span>
                    </div>

                    <!-- Row 5: Reason -->
                    <div id="sr_reason_wrap" style="display:none;">
                        <label class="form-label">Reason for Return</label>
                        <textarea name="reason" class="form-input" rows="2" placeholder="e.g. Damaged, wrong item, quality issue…"></textarea>
                    </div>

                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" id="sr_submit_btn" class="btn btn-primary btn-submit" disabled
                    style="padding:14px; border-radius:8px; font-size:15px; font-weight:500; opacity:0.5; cursor:not-allowed;">
                    Submit Return
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Process Return Modal -->
<div class="modal-overlay" id="processReturnModal">
    <div class="modal-content" style="max-width: 450px; width: 90%;">
        <div class="modal-header">
            <h2 style="color:var(--text-dark);">Process Return</h2>
            <button class="close-modal" onclick="document.getElementById('processReturnModal').classList.remove('active')">&times;</button>
        </div>
        <form action="sales_returns.php" method="GET">
            <div class="modal-body" style="padding: 20px;">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" id="process_return_id" value="">
                <p style="margin-bottom: 20px; font-size: 15px; color: var(--text-dark);">How would you like to handle the returned goods?</p>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="restock" value="1" checked style="width:16px; height:16px; cursor:pointer;">
                        <span style="font-size:14px; font-weight:500;">Restock to original inventory</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="restock" value="0" style="width:16px; height:16px; cursor:pointer;">
                        <span style="font-size:14px; font-weight:500;">Mark as Damaged / Missing (Do not restock)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;">
                <button type="button" class="btn btn-outline" style="padding:8px 16px;"
                    onclick="document.getElementById('processReturnModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary"
                    style="padding:8px 16px; background-color:var(--primary-color); border-color:var(--primary-color);">Confirm Process</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Sales Return Modal JS ──────────────────────────────────────────────────

// ── Order mode toggle (Regular customer vs POS walk-in) ───────────────────
function switchOrderMode(mode) {
    const customerWrap = document.getElementById('sr_customer_wrap');
    const posWrap      = document.getElementById('sr_pos_wrap');
    const orderWrap    = document.getElementById('sr_order_wrap');
    const itemsWrap    = document.getElementById('sr_items_wrap');
    const reasonWrap   = document.getElementById('sr_reason_wrap');
    const btnReg       = document.getElementById('btn_regular_order');
    const btnPos       = document.getElementById('btn_pos_order');
    const custSel      = document.getElementById('sr_customer_id');
    const orderSel     = document.getElementById('sr_order_id');

    // Reset dependent sections
    orderWrap.style.display = 'none';
    itemsWrap.style.display = 'none';
    if (reasonWrap) reasonWrap.style.display = 'none';
    if (orderSel) { orderSel.innerHTML = '<option value="" disabled selected>Select Order</option>'; }
    document.getElementById('sr_items_body').innerHTML = '';
    srUpdateTotal();

    if (mode === 'pos') {
        customerWrap.style.display = 'none';
        posWrap.style.display      = 'block';
        if (custSel) custSel.removeAttribute('required');
        btnReg.style.background = 'var(--bg-light)';
        btnReg.style.color      = 'var(--text-muted)';
        btnPos.style.background = 'var(--primary-color)';
        btnPos.style.color      = '#fff';
    } else {
        customerWrap.style.display = 'block';
        posWrap.style.display      = 'none';
        if (custSel) custSel.setAttribute('required', 'required');
        btnReg.style.background = 'var(--primary-color)';
        btnReg.style.color      = '#fff';
        btnPos.style.background = 'var(--bg-light)';
        btnPos.style.color      = 'var(--text-muted)';
        const posStatus = document.getElementById('sr_pos_status');
        if (posStatus) { posStatus.style.display = 'none'; posStatus.textContent = ''; }
    }
}

// ── POS Order direct lookup ────────────────────────────────────────────────
function srLookupPosOrder() {
    const input     = document.getElementById('sr_pos_order_input');
    const status    = document.getElementById('sr_pos_status');
    const orderWrap = document.getElementById('sr_order_wrap');
    const orderSel  = document.getElementById('sr_order_id');
    const hiddenCust = document.getElementById('sr_pos_customer_hidden');

    const orderId = input.value.trim().toUpperCase();
    if (!orderId) {
        status.style.display = 'block';
        status.style.color   = 'var(--danger-color)';
        status.textContent   = 'Please enter an Order ID (e.g. SO-0042)';
        return;
    }

    status.style.display = 'block';
    status.style.color   = 'var(--text-muted)';
    status.textContent   = 'Looking up order…';

    fetch('ajax_get_orders_by_customer.php?pos_order_id=' + encodeURIComponent(orderId))
        .then(r => r.json())
        .then(data => {
            if (!data || data.error) {
                status.style.color = 'var(--danger-color)';
                status.textContent = data.error || 'Order not found. Check the Order ID and try again.';
                return;
            }
            // Success — populate the order select with this single order
            status.style.color   = '#059669';
            status.textContent   = '✓ Found: ' + data.order_id_str + ' — ' + data.customer_name + ' — $' + parseFloat(data.total_amount).toFixed(2);
            hiddenCust.value = data.customer_id || 0;

            orderSel.innerHTML = '<option value="' + data.id + '" selected>' + data.order_id_str + ' (' + data.order_date + ')</option>';
            orderWrap.style.display = 'block';
            srLoadItems(data.id);
        })
        .catch(() => {
            status.style.color = 'var(--danger-color)';
            status.textContent = 'Lookup failed. Please try again.';
        });
}

function srLoadOrders(customerId) {
    const wrap    = document.getElementById('sr_order_wrap');
    const sel     = document.getElementById('sr_order_id');
    const loading = document.getElementById('sr_order_loading');
    const itemsWrap = document.getElementById('sr_items_wrap');
    const reasonWrap = document.getElementById('sr_reason_wrap');

    // Reset downstream
    itemsWrap.style.display = 'none';
    reasonWrap.style.display = 'none';
    document.getElementById('sr_items_body').innerHTML = '';
    srUpdateTotal();

    if (!customerId) { wrap.style.display = 'none'; return; }

    wrap.style.display = 'block';
    loading.style.display = 'inline';
    sel.innerHTML = '<option value="" disabled selected>Loading…</option>';

    fetch('ajax_get_orders_by_customer.php?customer_id=' + customerId)
        .then(r => r.json())
        .then(orders => {
            loading.style.display = 'none';
            sel.innerHTML = '<option value="" disabled selected>Select Completed Order</option>';
            if (orders.length === 0) {
                sel.innerHTML = '<option value="" disabled selected>No completed orders found</option>';
                return;
            }
            orders.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = o.order_id_str + '  (' + o.order_date + ')';
                sel.appendChild(opt);
            });
        })
        .catch(() => { loading.style.display = 'none'; });
}

function srLoadItems(orderId) {
    const itemsWrap  = document.getElementById('sr_items_wrap');
    const reasonWrap = document.getElementById('sr_reason_wrap');
    const tbody      = document.getElementById('sr_items_body');
    const loading    = document.getElementById('sr_items_loading');
    const submitBtn  = document.getElementById('sr_submit_btn');

    tbody.innerHTML = '';
    srUpdateTotal();
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.5';
    submitBtn.style.cursor = 'not-allowed';

    if (!orderId) { itemsWrap.style.display = 'none'; reasonWrap.style.display = 'none'; return; }

    itemsWrap.style.display = 'block';
    loading.style.display = 'inline';

    fetch('ajax_get_sales_order_items.php?order_id=' + orderId)
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            const items = data.items || [];
            const deliveryCharge = parseFloat(data.delivery_charge || 0);

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:14px;color:var(--text-muted);">No items found for this order.</td></tr>';
                return;
            }

            items.forEach((item, idx) => {
                const row = document.createElement('tr');
                row.style.borderBottom = '1px solid var(--border-color)';
                row.innerHTML = `
                    <td style="padding:8px 10px; text-align:center;">
                        <input type="checkbox" class="sr-item-cb" data-idx="${idx}"
                            onchange="srToggleRow(this, ${idx})"
                            style="width:15px;height:15px;cursor:pointer;">
                    </td>
                    <td style="padding:8px 10px;">
                        <span style="font-weight:500;">${escHtml(item.product_name)}</span>
                        <span style="font-size:11px;color:var(--text-muted);display:block;">${escHtml(item.product_id_str)}</span>
                        <input type="hidden" name="product_id[]" value="${item.product_id}" disabled data-field="pid">
                        <input type="hidden" name="unit_price[]" value="${parseFloat(item.unit_price).toFixed(2)}" data-field="price">
                    </td>
                    <td style="padding:8px 10px; text-align:center; color:var(--text-muted);">${item.quantity}</td>
                    <td style="padding:8px 10px; text-align:center;">
                        <input type="number" name="return_qty[]" class="form-input sr-qty-input"
                            value="1" min="1" max="${item.quantity}"
                            data-idx="${idx}" data-max="${item.quantity}" data-price="${parseFloat(item.unit_price).toFixed(2)}"
                            disabled
                            onchange="srUpdateTotal()"
                            style="width:70px; text-align:center; padding:4px 8px; opacity:0.4;">
                    </td>
                    <td style="padding:8px 10px; text-align:right;">'+\$currency['symbol']+'${parseFloat(item.unit_price).toFixed(2)}</td>
                    <td style="padding:8px 10px; text-align:right; font-weight:600;" id="sr_row_total_${idx}">—</td>
                `;
                tbody.appendChild(row);
            });

            // Delivery charge row
            const delivRow = document.getElementById('sr_delivery_row');
            if (deliveryCharge > 0) {
                document.getElementById('sr_delivery_amount').textContent = ''+\$currency['symbol']+'' + deliveryCharge.toFixed(2);
                delivRow.style.display = '';
            } else {
                delivRow.style.display = 'none';
            }

            reasonWrap.style.display = 'block';
        })
        .catch(() => { loading.style.display = 'none'; });
}

function srToggleRow(cb, idx) {
    const qtyInput  = document.querySelector(`.sr-qty-input[data-idx="${idx}"]`);
    const pidInput  = cb.closest('tr').querySelector('[data-field="pid"]');

    if (cb.checked) {
        qtyInput.disabled = false;
        qtyInput.style.opacity = '1';
        if (pidInput) pidInput.disabled = false;
    } else {
        qtyInput.disabled = true;
        qtyInput.style.opacity = '0.4';
        if (pidInput) pidInput.disabled = true;
    }
    srUpdateTotal();
}

function srUpdateTotal() {
    let total = 0;
    document.querySelectorAll('.sr-item-cb:checked').forEach(cb => {
        const idx      = cb.dataset.idx;
        const qtyInput = document.querySelector(`.sr-qty-input[data-idx="${idx}"]`);
        const price    = parseFloat(qtyInput.dataset.price || 0);
        const qty      = Math.min(parseInt(qtyInput.value || 0), parseInt(qtyInput.dataset.max || 0));
        const rowTotal = qty * price;
        total += rowTotal;
        const cell = document.getElementById('sr_row_total_' + idx);
        if (cell) cell.textContent = ''+\$currency['symbol']+'' + rowTotal.toFixed(2);
    });

    // Clear unchecked row totals
    document.querySelectorAll('.sr-item-cb:not(:checked)').forEach(cb => {
        const idx  = cb.dataset.idx;
        const cell = document.getElementById('sr_row_total_' + idx);
        if (cell) cell.textContent = '—';
    });

    document.getElementById('sr_total_refund').textContent = window.CURRENCY.symbol + total.toFixed(2);

    const submitBtn = document.getElementById('sr_submit_btn');
    const anyChecked = document.querySelectorAll('.sr-item-cb:checked').length > 0;
    submitBtn.disabled = !anyChecked || total <= 0;
    submitBtn.style.opacity = (anyChecked && total > 0) ? '1' : '0.5';
    submitBtn.style.cursor  = (anyChecked && total > 0) ? 'pointer' : 'not-allowed';
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function handleReturnToggle(id, currentStatus) {
    if (currentStatus === 'Pending') {
        document.getElementById('process_return_id').value = id;
        document.getElementById('processReturnModal').classList.add('active');
    } else {
        window.location.href = `sales_returns.php?action=toggle&id=${id}&restock=0`;
    }
}
</script>

<?php if (isset($_GET['add'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('addReturnModal').classList.add('active');
    window.history.replaceState({}, document.title, "sales_returns.php");
});
</script>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
