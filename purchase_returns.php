<?php
$search_options = array(
    'prt.return_id_str' => 'Return ID',
    'po.order_id_str' => 'Order ID',
    's.supplier_name' => 'Supplier Name',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// ── Log Purchase Return (with line items) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_number_str'])) {
    $return_number_str  = $conn->real_escape_string($_POST['return_number_str']);
    $supplier_id        = intval($_POST['supplier_id']);
    $purchase_order_id  = !empty($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : null;
    $return_date        = $conn->real_escape_string($_POST['return_date']);
    $reason             = $conn->real_escape_string($_POST['reason'] ?? '');
    $status             = 'Pending';

    $product_ids  = $_POST['product_id']  ?? [];
    $return_qtys  = $_POST['return_qty']  ?? [];
    $unit_prices  = $_POST['unit_price']  ?? [];

    $total_amount = 0;
    $line_items   = [];
    foreach ($product_ids as $i => $pid) {
        $pid   = intval($pid);
        $qty   = intval($return_qtys[$i] ?? 0);
        $price = floatval($unit_prices[$i] ?? 0);
        if ($pid > 0 && $qty > 0 && $price > 0) {
            $subtotal       = $qty * $price;
            $total_amount  += $subtotal;
            $line_items[]   = ['product_id' => $pid, 'qty' => $qty, 'unit_price' => $price, 'subtotal' => $subtotal];
        }
    }

    // Fallback: no PO selected, use manually entered amount
    $use_manual = empty($line_items);
    if ($use_manual) {
        $total_amount = floatval($_POST['manual_amount'] ?? 0);
    }

    if ($supplier_id <= 0 || $total_amount <= 0) {
        header("Location: purchase_returns.php?msg=error_invalid_data"); exit();
    }

    $conn->begin_transaction();
    try {
        if ($purchase_order_id) {
            $stmt = $conn->prepare("INSERT INTO purchase_returns (return_number_str, supplier_id, purchase_order_id, return_date, amount, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siisdss", $return_number_str, $supplier_id, $purchase_order_id, $return_date, $total_amount, $reason, $status);
        } else {
            $stmt = $conn->prepare("INSERT INTO purchase_returns (return_number_str, supplier_id, return_date, amount, reason, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisdss", $return_number_str, $supplier_id, $return_date, $total_amount, $reason, $status);
        }
        $stmt->execute();
        $new_return_id = $conn->insert_id;
        $stmt->close();

        if (!$use_manual) {
            $item_stmt = $conn->prepare("INSERT INTO purchase_return_items (purchase_return_id, product_id, quantity_returned, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($line_items as $li) {
                $item_stmt->bind_param("iiidd", $new_return_id, $li['product_id'], $li['qty'], $li['unit_price'], $li['subtotal']);
                $item_stmt->execute();
            }
            $item_stmt->close();
        }
        $conn->commit();
        header("Location: purchase_returns.php?msg=added");
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: purchase_returns.php?msg=error_1");
    }
    exit();
}
// ─────────────────────────────────────────────────────────────────────────────

// Handle Actions (Delete / Toggle Status)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM purchase_returns WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status, supplier_id, amount, purchase_order_id FROM purchase_returns WHERE id = $id");
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $current_status = $row['status'];
            $supplier_id = $row['supplier_id'];
            $amount = $row['amount'];
            $purchase_order_id = $row['purchase_order_id'];

            $status_cycle = ['Pending' => 'Processed', 'Processed' => 'Pending'];
            $new_status = $status_cycle[$current_status];

            // Deduct stock when moving to Processed (return receipt handles the financial record)
            if ($new_status === 'Processed') {
                $items_res = $conn->query("SELECT product_id, quantity_returned FROM purchase_return_items WHERE purchase_return_id = $id");
                if ($items_res) {
                    while ($item = $items_res->fetch_assoc()) {
                        $pid = intval($item['product_id']);
                        $qty = intval($item['quantity_returned']);
                        $conn->query("UPDATE products SET stock_level = GREATEST(0, stock_level - $qty) WHERE id = $pid");
                    }
                }
            }

            $conn->query("UPDATE purchase_returns SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: purchase_returns.php");
    exit();
}

// Fetch Metrics
$total_res = $conn->query("SELECT COUNT(*) FROM purchase_returns");
$total_returns = $total_res ? $total_res->fetch_row()[0] : 0;

$pending_res = $conn->query("SELECT COUNT(*) FROM purchase_returns WHERE status='Pending'");
$pending_returns = $pending_res ? $pending_res->fetch_row()[0] : 0;

$processed_res = $conn->query("SELECT COUNT(*) FROM purchase_returns WHERE status='Processed'");
$processed_returns = $processed_res ? $processed_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Status filter
$allowed_statuses_pr = ['All','Pending','Processed'];
$status_filter_pr = $_GET['status'] ?? 'All';
if (!in_array($status_filter_pr, $allowed_statuses_pr)) $status_filter_pr = 'All';

// Fetch Data
$search_sql = '';
$where_clauses_pr = [];
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];
    if (in_array($col, $allowed_cols)) {
        $where_clauses_pr[] = "$col LIKE '%$search%'";
    }
}
if ($status_filter_pr !== 'All') {
    $safe_status_pr = $conn->real_escape_string($status_filter_pr);
    $where_clauses_pr[] = "pr.status = '$safe_status_pr'";
}
if ($where_clauses_pr) $search_sql = 'WHERE ' . implode(' AND ', $where_clauses_pr);

$query = "
    SELECT pr.*, s.supplier_name, po.purchase_id_str 
    FROM purchase_returns pr 
    LEFT JOIN suppliers s ON pr.supplier_id = s.id 
    LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
    $search_sql
    ORDER BY pr.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$returns = $conn->query($query);

// Fetch Suppliers for Add Modal (POs loaded dynamically via AJAX)
$suppliers_res = $conn->query("SELECT id, supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name");
$suppliers_list = [];
if ($suppliers_res) {
    while ($row = $suppliers_res->fetch_assoc()) {
        $suppliers_list[] = $row;
    }
}

$current_page = 'purchase_returns.php';

// Generate next Purchase Return Num
$next_id_res = $conn->query("SELECT MAX(id) FROM purchase_returns");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_rtn_id = 'RET-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$page_title = 'Purchase Returns';
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
        <a href="purchase_orders.php" class="tab-link">Orders</a>
        <a href="payments_made.php" class="tab-link">Payments</a>
        <a href="purchase_returns.php" class="tab-link active">Returns</a>
        <a href="supplier_credits.php" class="tab-link">Supplier Credits</a>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Returns</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_returns ?></div>
            <div class="page-metric-trend">Lifetime purchase returns</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Pending</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $pending_returns ?></div>
            <div class="page-metric-trend">Awaiting processing</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Processed</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $processed_returns ?></div>
            <div class="page-metric-trend">Successfully returned</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Total Return Value</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                $<?= number_format($conn->query("SELECT SUM(amount) FROM purchase_returns")->fetch_row()[0] ?? 0, 2) ?>
            </div>
            <div class="page-metric-trend">Lifetime refund amount</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Recent Returns</h2>

    <div class="data-table-container">
        <!-- Status filter tabs -->
        <?php
        $pr_base_url = '?';
        $pr_carry = $_GET; unset($pr_carry['status'], $pr_carry['page']);
        if ($pr_carry) $pr_base_url .= http_build_query($pr_carry) . '&';
        ?>
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['All','Pending','Processed'] as $s):
                        $active = $status_filter_pr === $s; ?>
                        <a href="<?= $pr_base_url ?>status=<?= urlencode($s) ?>"
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
                    New Purchase Return
                </button>
            </div>
        </div>


        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Return #</th>
                    <th>Supplier</th>
                    <th>PO Reference</th>
                    <th>Return Date</th>
                    <th>Amount</th>
                    <th>StatusBadge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($returns && $returns->num_rows > 0): ?>
                    <?php while ($row = $returns->fetch_assoc()): ?>
                        <?php
?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="purchase_returns">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['return_number_str']) ?>
                            </td>
                            <td style="font-size:14px; font-weight:500;">
                                <?= htmlspecialchars($row['supplier_name'] ?? 'Unknown Supplier') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['purchase_id_str'] ?? 'N/A') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['return_date']) ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">
                                $
                                <?= number_format($row['amount'], 2) ?>
                            </td>
                            <td><?= statusBadge($row['status'] ?? '') ?></td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_purchase_return_bill.php?pr_id=<?= $row['id'] ?>"
                                       title="View Return Receipt"
                                       style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:rgba(234,88,12,0.10); text-decoration:none;">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>
                                        </svg>
                                    </a>
                                    <a href="purchase_returns.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this return?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="#"
                                        onclick="handleReturnToggle(<?= $row['id'] ?>, '<?= $row['status'] ?>'); return false;"
                                        title="Cycle Status"><svg class="toggle-icon" viewBox="0 0 24 24"
                                            style="stroke: var(--primary-color);">
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
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg><div class="empty-state-title">No Purchase Returns Yet</div><div class="empty-state-desc">Record returned goods sent back to suppliers.</div><button onclick="document.getElementById('addReturnModal').classList.add('active')" class="btn btn-primary" style="margin-top:4px; cursor:pointer;">+ Add Return</button></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_returns, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Purchase Return Modal -->
<div class="modal-overlay" id="addReturnModal">
    <div class="modal-content" style="max-width: 640px; width: 95%;">
        <div class="modal-header">
            <h2>Record Purchase Return</h2>
            <button class="close-modal"
                onclick="document.getElementById('addReturnModal').classList.remove('active')">&times;</button>
        </div>
        <form action="purchase_returns.php" method="POST" id="addPrReturnForm">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:18px;">

                    <!-- Row 1: Ref + Date -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                        <div>
                            <label class="form-label">Return #</label>
                            <input type="text" name="return_number_str" class="form-input" value="<?= $gen_rtn_id ?>"
                                readonly style="background-color:var(--bg-light);cursor:not-allowed;color:var(--text-muted);">
                        </div>
                        <div>
                            <label class="form-label">Return Date</label>
                            <input type="date" name="return_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Row 2: Supplier -->
                    <div>
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="pr_supplier_id" class="form-input" required onchange="prLoadOrders(this.value)">
                            <option value="" disabled selected>Select Supplier</option>
                            <?php foreach ($suppliers_list as $sup): ?>
                                <option value="<?= $sup['id'] ?>">
                                    <?= htmlspecialchars($sup['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Row 3: Purchase Order (loaded after supplier is picked) -->
                    <div id="pr_order_wrap" style="display:none;">
                        <label class="form-label">Associated Purchase Order</label>
                        <select name="purchase_order_id" id="pr_order_id" class="form-input" onchange="prLoadItems(this.value)">
                            <option value="" selected>— No PO / Select to load items —</option>
                        </select>
                        <span id="pr_order_loading" style="font-size:12px;color:var(--text-muted);display:none;">Loading orders…</span>
                    </div>

                    <!-- Row 4: Items table (loaded after PO is picked) -->
                    <div id="pr_items_wrap" style="display:none;">
                        <label class="form-label" style="margin-bottom:8px;">
                            Select Items to Return
                            <span style="font-weight:400; color:var(--text-muted); font-size:12px; margin-left:6px;">— check items and enter quantity</span>
                        </label>
                        <div style="border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr style="background:var(--bg-light);">
                                        <th style="padding:8px 10px; text-align:left; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Return?</th>
                                        <th style="padding:8px 10px; text-align:left; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Product</th>
                                        <th style="padding:8px 10px; text-align:center; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Ordered</th>
                                        <th style="padding:8px 10px; text-align:center; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Qty to Return</th>
                                        <th style="padding:8px 10px; text-align:right; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Unit Cost</th>
                                        <th style="padding:8px 10px; text-align:right; color:var(--text-muted); font-weight:600; border-bottom:1px solid var(--border-color);">Credit</th>
                                    </tr>
                                </thead>
                                <tbody id="pr_items_body"></tbody>
                                <tfoot>
                                    <tr style="background:var(--bg-light);">
                                        <td colspan="5" style="padding:10px; font-weight:600; font-size:13px; border-top:1px solid var(--border-color);">Total Return Value</td>
                                        <td style="padding:10px; text-align:right; font-weight:700; font-size:15px; color:var(--primary-color); border-top:1px solid var(--border-color);" id="pr_total_amount">'+\$currency['symbol']+'0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <span id="pr_items_loading" style="font-size:12px;color:var(--text-muted);display:none;">Loading items…</span>
                    </div>

                    <!-- Row 5: Manual amount fallback (shown when no PO selected) -->
                    <div id="pr_manual_amount_wrap" style="display:none;">
                        <label class="form-label">Return Amount ('+\$currency['symbol']+')</label>
                        <input type="number" step="0.01" name="manual_amount" id="pr_manual_amount"
                            class="form-input" placeholder="0.00" min="0.01">
                        <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">
                            Enter amount manually since no purchase order is linked.
                        </span>
                    </div>

                    <!-- Row 6: Reason -->
                    <div id="pr_reason_wrap">
                        <label class="form-label">Reason for Return</label>
                        <textarea name="reason" class="form-input" rows="2" placeholder="e.g. Damaged goods, wrong item received, over-shipment…"></textarea>
                    </div>

                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" id="pr_submit_btn" class="btn btn-primary btn-submit"
                    style="padding:14px 40px; border-radius:8px; font-size:15px; font-weight:500;">
                    Save Return
                </button>
            </div>
        </form>
    </div>
</div>

</div>

<!-- Process Purchase Return Modal (restock confirmation) -->
<div class="modal-overlay" id="processReturnModal">
    <div class="modal-content" style="max-width: 450px; width: 90%;">
        <div class="modal-header">
            <h2 style="color:var(--text-dark);">Process Purchase Return</h2>
            <button class="close-modal"
                onclick="document.getElementById('processReturnModal').classList.remove('active')">&times;</button>
        </div>
        <form action="purchase_returns.php" method="GET">
            <div class="modal-body" style="padding: 20px;">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" id="process_return_id" value="">
                <p style="font-size: 15px; color: var(--text-dark); margin: 0;">
                    Mark this return as <strong>Processed</strong>. Stock levels will be adjusted for all returned items.
                    The return receipt is available via the document icon on the returns list.
                </p>
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
// ── Purchase Return Modal JS ──────────────────────────────────────────────────
function prLoadOrders(supplierId) {
    const wrap    = document.getElementById('pr_order_wrap');
    const sel     = document.getElementById('pr_order_id');
    const loading = document.getElementById('pr_order_loading');

    // Reset downstream
    document.getElementById('pr_items_wrap').style.display = 'none';
    document.getElementById('pr_items_body').innerHTML = '';
    document.getElementById('pr_manual_amount_wrap').style.display = 'none';
    document.getElementById('pr_manual_amount').removeAttribute('required');
    prUpdateTotal();

    if (!supplierId) { wrap.style.display = 'none'; return; }

    wrap.style.display = 'block';
    loading.style.display = 'inline';
    sel.innerHTML = '<option value="" selected>— No PO / Select to load items —</option>';

    fetch('ajax_get_orders_by_supplier.php?supplier_id=' + supplierId)
        .then(r => r.json())
        .then(orders => {
            loading.style.display = 'none';
            if (orders.length === 0) {
                sel.innerHTML = '<option value="" selected>— No purchase orders found —</option>';
                // Show manual amount since no POs
                document.getElementById('pr_manual_amount_wrap').style.display = 'block';
                document.getElementById('pr_manual_amount').setAttribute('required', 'required');
                return;
            }
            orders.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = o.purchase_id_str + '  (' + o.order_date + ')';
                sel.appendChild(opt);
            });
        })
        .catch(() => { loading.style.display = 'none'; });
}

function prLoadItems(poId) {
    const itemsWrap  = document.getElementById('pr_items_wrap');
    const tbody      = document.getElementById('pr_items_body');
    const loading    = document.getElementById('pr_items_loading');
    const manualWrap = document.getElementById('pr_manual_amount_wrap');
    const manualInp  = document.getElementById('pr_manual_amount');

    tbody.innerHTML = '';
    prUpdateTotal();

    if (!poId) {
        // No PO chosen — show manual amount input
        itemsWrap.style.display = 'none';
        manualWrap.style.display = 'block';
        manualInp.setAttribute('required', 'required');
        return;
    }

    // PO chosen — hide manual, show items
    manualWrap.style.display = 'none';
    manualInp.removeAttribute('required');
    manualInp.value = '';
    itemsWrap.style.display = 'block';
    loading.style.display = 'inline';

    fetch('ajax_po_items.php?po_id=' + poId)
        .then(r => r.json())
        .then(items => {
            loading.style.display = 'none';
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:14px;color:var(--text-muted);">No items found for this PO.</td></tr>';
                return;
            }
            items.forEach((item, idx) => {
                const row = document.createElement('tr');
                row.style.borderBottom = '1px solid var(--border-color)';
                row.innerHTML = `
                    <td style="padding:8px 10px; text-align:center;">
                        <input type="checkbox" class="pr-item-cb" data-idx="${idx}"
                            onchange="prToggleRow(this, ${idx})"
                            style="width:15px;height:15px;cursor:pointer;">
                    </td>
                    <td style="padding:8px 10px;">
                        <span style="font-weight:500;">${prEscHtml(item.product_name)}</span>
                        <span style="font-size:11px;color:var(--text-muted);display:block;">${prEscHtml(item.product_id_str)}</span>
                        <input type="hidden" name="product_id[]" value="${item.product_id}" disabled data-field="pid">
                        <input type="hidden" name="unit_price[]" value="${parseFloat(item.unit_price).toFixed(2)}" data-field="price">
                    </td>
                    <td style="padding:8px 10px; text-align:center; color:var(--text-muted);">${item.quantity}</td>
                    <td style="padding:8px 10px; text-align:center;">
                        <input type="number" name="return_qty[]" class="form-input pr-qty-input"
                            value="1" min="1" max="${item.quantity}"
                            data-idx="${idx}" data-max="${item.quantity}" data-price="${parseFloat(item.unit_price).toFixed(2)}"
                            disabled
                            onchange="prUpdateTotal()"
                            style="width:70px; text-align:center; padding:4px 8px; opacity:0.4;">
                    </td>
                    <td style="padding:8px 10px; text-align:right;">'+\$currency['symbol']+'${parseFloat(item.unit_price).toFixed(2)}</td>
                    <td style="padding:8px 10px; text-align:right; font-weight:600;" id="pr_row_total_${idx}">—</td>
                `;
                tbody.appendChild(row);
            });
        })
        .catch(() => { loading.style.display = 'none'; });
}

function prToggleRow(cb, idx) {
    const qtyInput = document.querySelector(`.pr-qty-input[data-idx="${idx}"]`);
    const pidInput = cb.closest('tr').querySelector('[data-field="pid"]');

    if (cb.checked) {
        qtyInput.disabled = false;
        qtyInput.style.opacity = '1';
        if (pidInput) pidInput.disabled = false;
    } else {
        qtyInput.disabled = true;
        qtyInput.style.opacity = '0.4';
        if (pidInput) pidInput.disabled = true;
    }
    prUpdateTotal();
}

function prUpdateTotal() {
    let total = 0;

    document.querySelectorAll('.pr-item-cb:checked').forEach(cb => {
        const idx      = cb.dataset.idx;
        const qtyInput = document.querySelector(`.pr-qty-input[data-idx="${idx}"]`);
        const price    = parseFloat(qtyInput.dataset.price || 0);
        const qty      = Math.min(parseInt(qtyInput.value || 0), parseInt(qtyInput.dataset.max || 0));
        const rowTotal = qty * price;
        total += rowTotal;
        const cell = document.getElementById('pr_row_total_' + idx);
        if (cell) cell.textContent = ''+\$currency['symbol']+'' + rowTotal.toFixed(2);
    });

    // Clear unchecked row totals
    document.querySelectorAll('.pr-item-cb:not(:checked)').forEach(cb => {
        const cell = document.getElementById('pr_row_total_' + cb.dataset.idx);
        if (cell) cell.textContent = '—';
    });

    document.getElementById('pr_total_amount').textContent = window.CURRENCY.symbol + total.toFixed(2);
}

function prEscHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// Validate before submit — need either items checked OR manual amount filled
document.getElementById('addPrReturnForm').addEventListener('submit', function(e) {
    const itemsVisible  = document.getElementById('pr_items_wrap').style.display !== 'none';
    const manualVisible = document.getElementById('pr_manual_amount_wrap').style.display !== 'none';
    const anyChecked    = document.querySelectorAll('.pr-item-cb:checked').length > 0;
    const manualVal     = parseFloat(document.getElementById('pr_manual_amount').value || 0);

    if (itemsVisible && !anyChecked) {
        e.preventDefault();
        alert('Please select at least one item to return.');
        return;
    }
    if (manualVisible && manualVal <= 0) {
        e.preventDefault();
        alert('Please enter a valid return amount.');
        return;
    }
    // If manual amount is used, inject hidden fields so POST handler receives them
    if (manualVisible && manualVal > 0) {
        // The PHP handler will read manual_amount when product_id[] is empty
        // (already handled in POST handler below)
    }
});

function handleReturnToggle(id, currentStatus) {
    if (currentStatus === 'Pending') {
        document.getElementById('process_return_id').value = id;
        document.getElementById('processReturnModal').classList.add('active');
    } else {
        window.location.href = `purchase_returns.php?action=toggle&id=${id}`;
    }
}
</script>

<?php if (isset($_GET['add'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('addReturnModal').classList.add('active');
    window.history.replaceState({}, document.title, "purchase_returns.php");
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>