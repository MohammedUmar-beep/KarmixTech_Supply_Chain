<?php
$search_options = array(
    'pr.payment_id_str' => 'Payment ID',
    'i.invoice_id_str' => 'Invoice ID',
    'pr.reference_number' => 'Reference',
    'pr.payment_method' => 'Method',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

// ── Merged from add_payment.php ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_ref_str'])) {
    $payment_ref_str = $conn->real_escape_string($_POST['payment_ref_str']);
    $customer_id     = intval($_POST['customer_id']);
    $invoice_id      = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : null;
    $payment_date    = $conn->real_escape_string($_POST['payment_date']);
    $amount          = floatval($_POST['amount']);
    $payment_method  = $conn->real_escape_string($_POST['payment_method']);
    $notes           = $conn->real_escape_string($_POST['notes'] ?? '');

    $conn->begin_transaction();
    try {
        if ($invoice_id !== null) {
            $stmt = $conn->prepare("INSERT INTO payments_received (payment_ref_str, customer_id, invoice_id, payment_date, amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siisdss", $payment_ref_str, $customer_id, $invoice_id, $payment_date, $amount, $payment_method, $notes);
            $stmt->execute();
            $conn->query("UPDATE invoices SET status = 'Paid' WHERE id = $invoice_id");
        } else {
            $stmt = $conn->prepare("INSERT INTO payments_received (payment_ref_str, customer_id, payment_date, amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisdss", $payment_ref_str, $customer_id, $payment_date, $amount, $payment_method, $notes);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        header("Location: payments_received.php?msg=added");
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: payments_received.php?msg=error_1");
    }
    exit();
}
// ─────────────────────────────────────────────────────────────────────────────

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        // Find invoice tied to this payment to optionally revert 
        // For simplicity, just deleting the payment record here
        $conn->query("DELETE FROM payments_received WHERE id = $id");
    }
    header("Location: payments_received.php");
    exit();
}

// Fetch Metrics
$total_receipts_res = $conn->query("SELECT COUNT(*) FROM payments_received");
$total_receipts = $total_receipts_res ? $total_receipts_res->fetch_row()[0] : 0;

$total_amount_res = $conn->query("SELECT SUM(amount) FROM payments_received");
$total_amount = $total_amount_res ? floatval($total_amount_res->fetch_row()[0]) : 0.00;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_receipts_res = $conn->query("SELECT COUNT(*) FROM payments_received");
$total_receipts = $total_receipts_res ? $total_receipts_res->fetch_row()[0] : 0;

// Method filter pill
$method_filter = $_GET['method'] ?? 'All';
$allowed_methods = ['All', 'Cash', 'Bank Transfer', 'Credit Card'];
if (!in_array($method_filter, $allowed_methods)) $method_filter = 'All';

$method_where = '';
if ($method_filter !== 'All') {
    $safe_method = $conn->real_escape_string($method_filter);
    $method_where = "WHERE pr.payment_method = '$safe_method'";
}

// Fetch Payments with Customer and Invoice Name
$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];
    if (in_array($col, $allowed_cols)) {
        $search_sql = $method_where ? "AND $col LIKE '%$search%'" : "WHERE $col LIKE '%$search%'";
    }
}
$where_combined = $method_where . $search_sql;
$query = "
    SELECT pr.*, 
        c.customer_name,
        inv.invoice_number
    FROM payments_received pr
    LEFT JOIN customers c ON pr.customer_id = c.id
    LEFT JOIN invoices inv ON pr.invoice_id = inv.id
    $where_combined
    ORDER BY pr.created_at DESC
    LIMIT $limit OFFSET $offset
";
$payments = $conn->query($query);
$total_filtered_res = $conn->query("SELECT COUNT(*) FROM payments_received pr LEFT JOIN customers c ON pr.customer_id = c.id LEFT JOIN invoices inv ON pr.invoice_id = inv.id $where_combined");
$total_filtered = $total_filtered_res ? (int)$total_filtered_res->fetch_row()[0] : 0;

// Fetch Customers for Add Modal
$customers_res = $conn->query("SELECT id, customer_name FROM customers WHERE status = 'Active'");
$customers_list = [];
if ($customers_res) {
    while ($row = $customers_res->fetch_assoc()) {
        $customers_list[] = $row;
    }
}

// Fetch Unpaid Invoices for Add Modal
$invoices_res = $conn->query("SELECT id, invoice_number, amount FROM invoices WHERE status IN ('Unpaid', 'Overdue')");
$invoices_list = [];
if ($invoices_res) {
    while ($row = $invoices_res->fetch_assoc()) {
        $invoices_list[] = $row;
    }
}

$current_page = 'payments_received.php';

// Generate next Payment Received Ref
$next_id_res = $conn->query("SELECT MAX(id) FROM payments_received");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_pr_id = 'PAY-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$current_page = 'payments_received.php';
$page_title = 'Payments Received';
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
            <h1>Sales Hub</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <!-- Hub Navigation Pill Tabs -->
    <div class="tab-navigation">
        <a href="sales_orders.php" class="tab-link">Orders</a>
        <a href="payments_received.php" class="tab-link active">Payments</a>
        <a href="sales_returns.php" class="tab-link">Returns</a>
        <a href="credit_notes.php" class="tab-link">Credit Notes</a>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Receipts</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_receipts ?></div>
            <div class="page-metric-trend">Payments logged</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Total Amount Received</div>
            <div class="page-metric-value" style="color:#10b981;">$<?= number_format($total_amount, 2) ?></div>
            <div class="page-metric-trend">Gross cashflow</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Avg Payment Value</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                $<?= $total_receipts > 0 ? number_format($total_amount / $total_receipts, 2) : '0.00' ?></div>
            <div class="page-metric-trend">Per transaction</div>
        </div>
        <div class="page-metric-card border-purple">
            <div class="page-metric-title">Recent (30 days)</div>
            <div class="page-metric-value" style="color:#8b5cf6;">
                <?= $conn->query("SELECT COUNT(*) FROM payments_received WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_row()[0] ?? 0 ?>
            </div>
            <div class="page-metric-trend">This month's payments</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Recent Payments</h2>

    <div class="data-table-container">
        <!-- Method filter tabs -->
        <?php
        $method_counts = [];
        foreach (['All','Cash','Bank Transfer','Credit Card'] as $m) {
            $wh = $m === 'All' ? '' : "WHERE payment_method = '" . $conn->real_escape_string($m) . "'";
            $method_counts[$m] = (int)$conn->query("SELECT COUNT(*) FROM payments_received $wh")->fetch_row()[0];
        }
        $base_url = '?';
        $carry = $_GET;
        unset($carry['method'], $carry['page']);
        if ($carry) $base_url .= http_build_query($carry) . '&';
        ?>

        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['All','Cash','Bank Transfer','Credit Card'] as $m):
                        $active = $method_filter === $m; ?>
                        <a href="<?= $base_url ?>method=<?= urlencode($m) ?>"
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
                    Log Payment
                </button>
            </div>
        </div>


        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Reference #</th>
                    <th>Customer</th>
                    <th>Invoice Ref</th>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($payments && $payments->num_rows > 0): ?>
                    <?php while ($row = $payments->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="payments_received">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['payment_ref_str']) ?>
                            </td>
                            <td style="font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-dark);">
                                <?php
                                $display_ref = 'Direct Payment';
                                if (!empty($row['invoice_number'])) {
                                    $display_ref = $row['invoice_number'];
                                } else if (!empty($row['notes'])) {
                                    if (strpos($row['notes'], 'Sales Order') !== false) {
                                        preg_match('/SO-\d+/', $row['notes'], $matches);
                                        if (!empty($matches[0]))
                                            $display_ref = 'SO: ' . $matches[0];
                                    } else if (strpos($row['notes'], 'Credit Note') !== false) {
                                        preg_match('/CN-\d+/', $row['notes'], $matches);
                                        if (!empty($matches[0]))
                                            $display_ref = 'CN: ' . $matches[0];
                                    }
                                }
                                echo htmlspecialchars($display_ref);
                                ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['payment_date']) ?>
                            </td>
                            <td>
                                <span class="method-badge">
                                    <?= htmlspecialchars($row['payment_method']) ?>
                                </span>
                            </td>
                            <td style="font-size: 14px; font-weight: 600; color: #065f46;">
                                $
                                <?= number_format($row['amount'], 2) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="payments_received.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this payment record?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <?php if (strpos($display_ref, 'CN:') !== 0): ?>
                                        <a href="view_invoice.php?payment_ref=<?= $row['payment_ref_str'] ?>"
                                            title="View Invoice"><svg class="invoice-icon" viewBox="0 0 24 24"
                                                style="stroke: var(--text-muted); width: 18px; height: 18px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                                <polyline points="10 9 9 9 8 9"></polyline>
                                            </svg></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><div class="empty-state-title">No Payments Received Yet</div><div class="empty-state-desc">Record your first payment received from customers.</div><a href="payments_received.php?add=1" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Payment</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_filtered, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Payment Modal -->
<div class="modal-overlay" id="addPaymentModal">
    <div class="modal-content" style="max-width: 500px; width: 90%;">
        <div class="modal-header">
            <h2>Log Received Payment</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="close-modal"
                    onclick="document.getElementById('addPaymentModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="payments_received.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div>
                        <label class="form-label">Payment Reference #</label>
                        <input type="text" name="payment_ref_str" class="form-input" value="<?= $gen_pr_id ?>" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-input" required>
                            <option value="" disabled selected>Select Customer</option>
                            <?php foreach ($customers_list as $cust): ?>
                                <option value="<?= htmlspecialchars($cust['id']) ?>">
                                    <?= htmlspecialchars($cust['customer_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Settle Invoice (Optional)</label>
                        <select name="invoice_id" class="form-input" onchange="autoFillPayment(this)">
                            <option value="" selected>Advance / Direct Payment</option>
                            <?php foreach ($invoices_list as $inv): ?>
                                <option value="<?= htmlspecialchars($inv['id']) ?>" data-amount="<?= $inv['amount'] ?>">
                                    <?= htmlspecialchars($inv['invoice_number']) ?> ('+\$currency['symbol']+'
                                    <?= $inv['amount'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div>
                            <label class="form-label">Date</label>
                            <input type="date" name="payment_date" class="form-input" value="<?= date('Y-m-d') ?>"
                                required>
                        </div>
                        <div>
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-input" required>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer" selected>Bank Transfer</option>
                                <option value="Credit Card">Credit Card</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Amount Received ('+\$currency['symbol']+')</label>
                        <input type="number" step="0.01" name="amount" id="payment_amount" class="form-input"
                            placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="form-label">Internal Notes</label>
                        <textarea name="notes" class="form-input" rows="2" placeholder="Optional details..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Log Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
    function autoFillPayment(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const amount = parseFloat(selectedOption.getAttribute('data-amount') || 0);
        if (amount > 0) {
            document.getElementById('payment_amount').value = amount.toFixed(2);
        }
    }
</script>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addPaymentModal').classList.add('active');
            window.history.replaceState({}, document.title, "payments_received.php");
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>