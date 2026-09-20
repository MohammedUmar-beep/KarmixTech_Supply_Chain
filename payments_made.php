<?php
$search_options = array(
    'pm.payment_id_str' => 'Payment ID',
    'po.order_id_str' => 'Order ID',
    'pm.reference_number' => 'Reference',
    's.supplier_name' => 'Supplier Name',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Delete Action
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM payments_made WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM payments_made WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $status_cycle = ['Fully Paid' => 'Partially Paid', 'Partially Paid' => 'On Credit', 'On Credit' => 'Fully Paid'];
            $new_status = isset($status_cycle[$current_status]) ? $status_cycle[$current_status] : 'Fully Paid';
            $conn->query("UPDATE payments_made SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: payments_made.php");
    exit();
}

// Fetch Metrics
$total_res = $conn->query("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) FROM payments_made");
$total_amount = $total_res ? $total_res->fetch_row()[0] : 0;
$refund_res = $conn->query("SELECT ABS(SUM(amount)) FROM payments_made WHERE amount < 0");
$total_refunds = $refund_res ? $refund_res->fetch_row()[0] : 0;

$count_res = $conn->query("SELECT COUNT(*) FROM payments_made");
$total_payments = $count_res ? $count_res->fetch_row()[0] : 0;

$this_month_res = $conn->query("SELECT SUM(amount) FROM payments_made WHERE amount > 0 AND MONTH(payment_date) = MONTH(CURRENT_DATE()) AND YEAR(payment_date) = YEAR(CURRENT_DATE())");
$this_month_amount = $this_month_res ? $this_month_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Method filter
$allowed_methods_pm = ['All','Cash','Bank Transfer','Credit Card'];
$method_filter_pm = $_GET['method'] ?? 'All';
if (!in_array($method_filter_pm, $allowed_methods_pm)) $method_filter_pm = 'All';

// Fetch Data
$search_sql = '';
$where_clauses = [];
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];
    if (in_array($col, $allowed_cols)) {
        $where_clauses[] = "$col LIKE '%$search%'";
    }
}
if ($method_filter_pm !== 'All') {
    $safe_method_pm = $conn->real_escape_string($method_filter_pm);
    $where_clauses[] = "p.payment_method = '$safe_method_pm'";
}
if ($where_clauses) $search_sql = 'WHERE ' . implode(' AND ', $where_clauses);

$query = "
    SELECT p.*, s.supplier_name 
    FROM payments_made p 
    LEFT JOIN suppliers s ON p.supplier_id = s.id 
    $search_sql
    ORDER BY p.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$payments = $conn->query($query);

// Fetch dropdown data for Add Modal
$suppliers_res = $conn->query("SELECT id, supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name");
$suppliers_list = [];
if ($suppliers_res) {
    while ($row = $suppliers_res->fetch_assoc()) {
        $suppliers_list[] = $row;
    }
}

$current_page = 'payments_made.php';

// Generate next Payment Made Ref
$next_id_res = $conn->query("SELECT MAX(id) FROM payments_made");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_pm_id = 'PAY-OUT-' . str_pad($next_id, 3, '0', STR_PAD_LEFT);

$page_title = 'Payments Made';
$extra_head = '<style>
    .method-badge {
        font-size: 13px;
        font-weight: 500;
        padding: 4px 8px;
        border-radius: 6px;
        background: var(--bg-light);
        color: var(--text-dark);
        border: 1px solid var(--border-color);
    }
</style>';
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
        <a href="payments_made.php" class="tab-link active">Payments</a>
        <a href="purchase_returns.php" class="tab-link">Returns</a>
        <a href="supplier_credits.php" class="tab-link">Supplier Credits</a>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Payments Amount</div>
            <div class="page-metric-value" style="color:var(--primary-color);">
                $<?= number_format($total_amount ?? 0, 2) ?></div>
            <div class="page-metric-trend">Lifetime vendor payments</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Total Transactions</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $total_payments ?></div>
            <div class="page-metric-trend">Number of payments made</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">This Month</div>
            <div class="page-metric-value" style="color:#f59e0b;">$<?= number_format($this_month_amount ?? 0, 2) ?>
            </div>
            <div class="page-metric-trend">Payments in current month</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Avg Payment Value</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                $<?= ($total_payments ?? 0) > 0 ? number_format(($total_amount ?? 0) / $total_payments, 2) : '0.00' ?>
            </div>
            <div class="page-metric-trend">Per transaction</div>
        </div>
    </div>


    <div class="data-table-container">
        <!-- Method filter tabs -->
        <?php
        $pm_base_url = '?';
        $pm_carry = $_GET; unset($pm_carry['method'], $pm_carry['page']);
        if ($pm_carry) $pm_base_url .= http_build_query($pm_carry) . '&';
        ?>
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['All','Cash','Bank Transfer','Credit Card'] as $m):
                        $active = $method_filter_pm === $m; ?>
                        <a href="<?= $pm_base_url ?>method=<?= urlencode($m) ?>"
                           style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap;
                                  <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                            <?= $m ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addPaymentModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Record Payment Made
                </button>


            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Payment Ref</th>
                    <th>Supplier</th>
                    <th>Payment Date</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>StatusBadge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($payments && $payments->num_rows > 0): ?>
                    <?php while ($row = $payments->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="payments_made">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['payment_ref_str']) ?>
                            </td>
                            <td style="font-size:14px; font-weight:500;">
                                <?= htmlspecialchars($row['supplier_name'] ?? 'Unknown Supplier') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['payment_date']) ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">
                                <?php if ($row['amount'] < 0): ?>
                                    <span style="color:#ef4444;">−$<?= number_format(abs($row['amount']), 2) ?></span>
                                    <span style="font-size:10px; font-weight:700; color:#ef4444; background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:4px; padding:1px 5px; margin-left:4px;">REFUND</span>
                                <?php else: ?>
                                    $<?= number_format($row['amount'], 2) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="method-badge">
                                    <?= htmlspecialchars($row['payment_method'] ?? '') ?>
                                </span>
                            </td>
                            <td>
                                <?= statusBadge($row['status'] ?? 'Fully Paid') ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_payment_bill.php?id=<?= $row['id'] ?>" title="View Bill">
                                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="var(--primary-color)"
                                            fill="none">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                        </svg>
                                    </a>
                                    <?php if ($row['status'] === 'Partially Paid' || $row['status'] === 'On Credit'): ?>
                                    <?php endif; ?>
                                    <a href="payments_made.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this payment record?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="payments_made.php?action=toggle&id=<?= $row['id'] ?>" title="Cycle Status"><svg
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
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg><div class="empty-state-title">No Payments Made Yet</div><div class="empty-state-desc">Record your first payment made to a supplier.</div><a href="add_payment_made.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Payment</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_payments, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Create Payment Modal -->
<div class="modal-overlay" id="addPaymentModal">
    <div class="modal-content" style="max-width: 600px; width: 90%;">
        <div class="modal-header">
            <h2>Record Payment Made</h2>
            <button class="close-modal"
                onclick="document.getElementById('addPaymentModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_payment_made.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Payment Ref #</label>
                        <input type="text" name="payment_ref_str" class="form-input" value="<?= $gen_pm_id ?>" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-input" required>
                            <option value="" disabled selected>Select Supplier</option>
                            <?php foreach ($suppliers_list as $sup): ?>
                                <option value="<?= $sup['id'] ?>">
                                    <?= htmlspecialchars($sup['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" name="amount" class="form-input" placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-input">
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Cash">Cash</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Payment Status</label>
                        <?php
                        // Dynamically check if status column exists and fetch distinct statuses
                        $status_query = "SELECT DISTINCT status FROM `payments_made` WHERE status IS NOT NULL AND status != ''";
                        $status_result = $conn->query($status_query);
                        $statuses = [];
                        if ($status_result) {
                            while ($row = $status_result->fetch_assoc()) {
                                $statuses[] = $row['status'];
                            }
                        }
                        ?>
                        <?php if (!empty($statuses)): ?>
                            <select name="status" class="status-filter"
                                onchange="const urlParams = new URLSearchParams(window.location.search); urlParams.set('status', this.value); urlParams.delete('page'); window.location.search = urlParams.toString();">
                                <option value="">All Statuses</option>
                                <?php foreach ($statuses as $st): ?>
                                    <option value="<?= htmlspecialchars($st) ?>" <?= (isset($_GET['status']) && $_GET['status'] === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-input" rows="2"
                            placeholder="Reference details..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px 40px; border-radius:8px; font-size:15px; font-weight:500;">Save
                    Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Installment Modal -->
<div class="modal-overlay" id="addInstallmentModal">
    <div class="modal-content" style="max-width: 500px; width: 90%;">
        <div class="modal-header">
            <h2>Add Installment</h2>
            <button class="close-modal"
                onclick="document.getElementById('addInstallmentModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_installment.php" method="POST">
            <input type="hidden" name="payment_id" id="inst_payment_id" value="">
            <input type="hidden" name="payment_type" value="made">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div>
                        <label class="form-label">Payment Ref</label>
                        <input type="text" id="inst_payment_ref" class="form-input" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Installment Amount</label>
                        <input type="number" step="0.01" name="amount" class="form-input" placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="form-label">Installment Date</label>
                        <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-input" required>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Cash">Cash</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">New Status</label>
                        <?php
                        // Dynamically check if status column exists and fetch distinct statuses
                        $status_query = "SELECT DISTINCT status FROM `payments_made` WHERE status IS NOT NULL AND status != ''";
                        $status_result = $conn->query($status_query);
                        $statuses = [];
                        if ($status_result) {
                            while ($row = $status_result->fetch_assoc()) {
                                $statuses[] = $row['status'];
                            }
                        }
                        ?>
                        <?php if (!empty($statuses)): ?>
                            <select name="status" class="status-filter"
                                onchange="const urlParams = new URLSearchParams(window.location.search); urlParams.set('status', this.value); urlParams.delete('page'); window.location.search = urlParams.toString();">
                                <option value="">All Statuses</option>
                                <?php foreach ($statuses as $st): ?>
                                    <option value="<?= htmlspecialchars($st) ?>" <?= (isset($_GET['status']) && $_GET['status'] === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px 40px; border-radius:8px; font-size:15px; font-weight:500;">Save
                    Installment</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openInstallmentModal(id, refStr, type) {
        document.getElementById('inst_payment_id').value = id;
        document.getElementById('inst_payment_ref').value = refStr;
        document.getElementById('addInstallmentModal').classList.add('active');
    }
</script>
<?php include 'includes/footer.php'; ?>