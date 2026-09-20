<?php
$search_options = array(
    'i.invoice_id_str' => 'Invoice ID',
    'i.order_id_str' => 'Order ID',
    'c.first_name' => 'Customer Name',
    'i.amount' => 'Total Amount',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle Status)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM invoices WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM invoices WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            // Cycle: Unpaid -> Paid -> Overdue -> Unpaid
            $status_cycle = ['Unpaid' => 'Paid', 'Paid' => 'Overdue', 'Overdue' => 'Unpaid',
                             'Draft' => 'Unpaid', 'Sent' => 'Unpaid', 'Partial' => 'Unpaid'];
            $new_status = $status_cycle[$current_status] ?? 'Unpaid';
            $conn->query("UPDATE invoices SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: invoices.php");
    exit();
}

// One-time migration: collapse legacy statuses to Unpaid
$conn->query("UPDATE invoices SET status='Unpaid' WHERE status IN ('Draft','Sent','Partial')");

// Fetch Metrics
$total_invoices_res = $conn->query("SELECT COUNT(*) FROM invoices");
$total_invoices = $total_invoices_res ? $total_invoices_res->fetch_row()[0] : 0;

$paid_invoices_res = $conn->query("SELECT COUNT(*) FROM invoices WHERE status='Paid'");
$paid_invoices = $paid_invoices_res ? $paid_invoices_res->fetch_row()[0] : 0;

$unpaid_invoices_res = $conn->query("SELECT COUNT(*) FROM invoices WHERE status='Unpaid'");
$unpaid_invoices = $unpaid_invoices_res ? $unpaid_invoices_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Status filter (pill tabs)
$status_filter = '';
if (isset($_GET['status']) && in_array($_GET['status'], ['Paid','Unpaid','Overdue'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
}

// Fetch Invoices with Customer and Order Name
$search_sql = $status_filter ? "WHERE inv.status = '$status_filter'" : '';
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];
    if (in_array($col, $allowed_cols)) {
        $search_sql = $search_sql ? "$search_sql AND $col LIKE '%$search%'" : "WHERE $col LIKE '%$search%'";
    }
}
$count_res = $conn->query("SELECT COUNT(*) FROM invoices inv LEFT JOIN customers c ON inv.customer_id = c.id LEFT JOIN sales_orders so ON inv.sales_order_id = so.id $search_sql");
$total_invoices_filtered = $count_res ? (int)$count_res->fetch_row()[0] : 0;

$query = "
    SELECT inv.*, 
        c.customer_name,
        so.order_id_str
    FROM invoices inv
    LEFT JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN sales_orders so ON inv.sales_order_id = so.id
    $search_sql
    ORDER BY inv.created_at DESC
    LIMIT $limit OFFSET $offset
";
$invoices = $conn->query($query);

// Fetch Customers for Add Modal
$customers_res = $conn->query("SELECT id, customer_name FROM customers WHERE status = 'Active'");
$customers_list = [];
if ($customers_res) {
    while ($row = $customers_res->fetch_assoc()) {
        $customers_list[] = $row;
    }
}

// Fetch Completed Sales Orders for Add Modal
$sales_orders_res = $conn->query("SELECT id, order_id_str, total_amount FROM sales_orders WHERE status = 'Completed'");
$sales_orders_list = [];
if ($sales_orders_res) {
    while ($row = $sales_orders_res->fetch_assoc()) {
        $sales_orders_list[] = $row;
    }
}

$current_page = 'invoices.php';
$page_title = 'Invoices - Agile Inventory';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="dashboard.php"
                style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back
            </a>
            <h1>Invoices</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Invoices</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_invoices ?></div>
            <div class="page-metric-trend">All time invoices</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Paid Invoices</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $paid_invoices ?></div>
            <div class="page-metric-trend">Fully settled</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Unpaid Invoices</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $unpaid_invoices ?></div>
            <div class="page-metric-trend">Awaiting payment</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Overdue Invoices</div>
            <div class="page-metric-value" style="color:#ef4444;">
                <?= $conn->query("SELECT COUNT(*) FROM invoices WHERE status='Overdue'")->fetch_row()[0] ?? 0 ?></div>
            <div class="page-metric-trend">Past due date</div>
        </div>
    </div>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php
                    $tabs = ['' => 'All', 'Unpaid' => 'Unpaid', 'Paid' => 'Paid', 'Overdue' => 'Overdue'];
                    foreach ($tabs as $val => $label):
                        $href = $val === '' ? 'invoices.php' : "invoices.php?status=$val";
                        $active = $current_tab === $val;
                    ?>
                    <a href="<?= $href ?>"
                        style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s;
                        <?= $active
                            ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);'
                            : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addInvoiceModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>Generate Invoice
                </button>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Invoice #</th>
                    <th>Ref. Order</th>
                    <th>Customer</th>
                    <th>Due Date</th>
                    <th>Amount</th>
                    <th>Status Badge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($invoices && $invoices->num_rows > 0): ?>
                    <?php while ($row = $invoices->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="invoices">
                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['invoice_number']) ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-dark);">
                                <?= htmlspecialchars($row['order_id_str'] ?? 'N/A') ?>
                            </td>
                            <td style="font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['due_date']) ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">$
                                <?= number_format($row['amount'], 2) ?>
                            </td>
                            <td><?= statusBadge($row['status']) ?></td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_invoice.php?invoice_id=<?= $row['id'] ?>" title="View Invoice"
                                        style="color:var(--primary-color);">
                                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                            <polyline points="14 2 14 8 20 8"/>
                                            <line x1="8" y1="13" x2="16" y2="13"/>
                                            <line x1="8" y1="17" x2="16" y2="17"/>
                                            <polyline points="8 9 9 9 10 9"/>
                                        </svg>
                                    </a>
                                    <a href="invoices.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this invoice?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg>
                                    </a>
                                    <a href="invoices.php?action=toggle&id=<?= $row['id'] ?>"
                                        title="Cycle Status"><svg class="toggle-icon" viewBox="0 0 24 24"
                                            style="stroke: var(--primary-color);">
                                            <polyline points="1 4 1 10 7 10"></polyline>
                                            <polyline points="23 20 23 14 17 14"></polyline>
                                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10M23 14l-4.64 4.36A9 9 0 0 1 3.51 15"></path>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg><div class="empty-state-title">No Invoices Yet</div><div class="empty-state-desc">Create your first invoice to start billing customers.</div><a href="add_invoice.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Invoice</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_invoices_filtered ?: $total_invoices, $limit, $page); ?>
    </div>
</main>
<!-- Add Invoice Modal -->
    <div class="modal-overlay" id="addInvoiceModal">
        <div class="modal-content" style="max-width: 500px; width: 90%;">
            <div class="modal-header">
                <h2>Generate Invoice</h2>
                <div style="display:flex; gap:12px; align-items:center;"><button class="close-modal"
                        onclick="document.getElementById('addInvoiceModal').classList.remove('active')">&times;
                    </button></div>
            </div>
            <form action="add_invoice.php" method="POST">
                <div class="modal-body form-grid">
                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div><label class="form-label">Invoice Number</label><input type="text" name="invoice_number"
                                class="form-input" placeholder="Ex: INV-1001" required></div>
                        <div><label class="form-label">Customer</label><select name="customer_id" class="form-input"
                                required>
                                <option value="" disabled selected>Select Customer</option>
                                <?php foreach ($customers_list as $cust): ?>
                                    <option value="<?= htmlspecialchars($cust['id']) ?>">
                                        <?= htmlspecialchars($cust['customer_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></div>
                        <div><label class="form-label">Link to Sales Order (Optional)</label><select
                                name="sales_order_id" class="form-input" onchange="autoFillAmount(this)">
                                <option value="" selected>No associated order</option>
                                <?php foreach ($sales_orders_list as $so): ?>
                                    <option value="<?= htmlspecialchars($so['id']) ?>"
                                        data-amount="<?= $so['total_amount'] ?>">
                                        <?= htmlspecialchars($so['order_id_str']) ?>
                                        ('+\$currency['symbol']+'
                                        <?= $so['total_amount'] ?>
                                        )
                                    </option>
                                <?php endforeach; ?>
                            </select></div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div><label class="form-label">Date Issued</label><input type="date" name="invoice_date"
                                    class="form-input" value="<?= date('Y-m-d') ?>" required></div>
                            <div><label class="form-label">Due Date</label><input type="date" name="due_date"
                                    class="form-input" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                            </div>
                        </div>
                        <div><label class="form-label">Total Amount Due ('+\$currency['symbol']+')</label><input type="number" step="0.01"
                                name="amount" id="invoice_amount" class="form-input" placeholder="0.00" required></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary btn-submit"
                        style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Generate
                        Invoice</button></div>
            </form>
        </div>
    </div>
    <script>function autoFillAmount(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const amount = parseFloat(selectedOption.getAttribute('data-amount') || 0);

            if (amount > 0) {
                document.getElementById('invoice_amount').value = amount.toFixed(2);
            }
        }

    </script>
    <?php if (isset($_GET['add'])): ?>
        <script>document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addInvoiceModal').classList.add('active');
            window.history.replaceState({}, document.title, "invoices.php");
        });
        </script>
    <?php endif; ?><?php include 'includes/footer.php'; ?>