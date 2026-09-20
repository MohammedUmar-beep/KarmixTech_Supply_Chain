<?php
$search_options = array(
    'cn.credit_note_id_str' => 'Credit Note ID',
    'r.return_id_str' => 'Return ID',
    'c.first_name' => 'Customer Name',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM credit_notes WHERE id = $id");
    } elseif ($action == 'toggle_status') {
        $cur_res = $conn->query("SELECT status FROM credit_notes WHERE id = $id");
        if ($cur_res && $cur = $cur_res->fetch_assoc()) {
            $new_status = ($cur['status'] === 'Open') ? 'Completed' : 'Open';
            $conn->query("UPDATE credit_notes SET status='$new_status' WHERE id = $id");
        }
    }
    header("Location: credit_notes.php");
    exit();
}

// Fetch Metrics
$total_cn_res = $conn->query("SELECT COUNT(*) FROM credit_notes");
$total_cn = $total_cn_res ? $total_cn_res->fetch_row()[0] : 0;

$open_cn_res = $conn->query("SELECT COUNT(*) FROM credit_notes WHERE status='Open'");
$open_cn = $open_cn_res ? $open_cn_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Credit Notes with Customer Name
$search_sql = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $search_sql = "WHERE cn.status = '$status_filter'";
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
    SELECT cn.*, 
        c.customer_name,
        sr.return_id_str,
        sr.sales_order_id,
        (SELECT inv.invoice_number FROM invoices inv
         WHERE inv.sales_order_id = sr.sales_order_id
         ORDER BY inv.created_at DESC LIMIT 1) as linked_invoice_number,
        (SELECT inv.id FROM invoices inv
         WHERE inv.sales_order_id = sr.sales_order_id
         ORDER BY inv.created_at DESC LIMIT 1) as linked_invoice_id,
        (SELECT inv.status FROM invoices inv
         WHERE inv.sales_order_id = sr.sales_order_id
         ORDER BY inv.created_at DESC LIMIT 1) as invoice_status
    FROM credit_notes cn
    LEFT JOIN customers c ON cn.customer_id = c.id
    LEFT JOIN sales_returns sr ON cn.sales_return_id = sr.id
    $search_sql
    ORDER BY cn.created_at DESC
    LIMIT $limit OFFSET $offset
";
$credit_notes = $conn->query($query);

// Fetch Customers for Add Modal
$customers_res = $conn->query("SELECT id, customer_name FROM customers WHERE status = 'Active'");
$customers_list = [];
if ($customers_res) {
    while ($row = $customers_res->fetch_assoc()) {
        $customers_list[] = $row;
    }
}

// Fetch Processed Returns for Add Modal (could auto-generate notes off these)
$sales_returns_res = $conn->query("SELECT id, return_id_str, total_refund_amount FROM sales_returns WHERE status = 'Processed'");
$returns_list = [];
if ($sales_returns_res) {
    while ($row = $sales_returns_res->fetch_assoc()) {
        $returns_list[] = $row;
    }
}

$current_page = 'credit_notes.php';

// Generate next Credit Note Ref
$next_id_res = $conn->query("SELECT MAX(id) FROM credit_notes");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_cn_id = 'CN-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$page_title = 'Credit Notes';
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
        <a href="sales_returns.php" class="tab-link">Returns</a>
        <a href="credit_notes.php" class="tab-link active">Credit Notes</a>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Credit Notes</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_cn ?></div>
            <div class="page-metric-trend">Issued all time</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Open Credits</div>
            <div class="page-metric-value" style="color:#3b82f6;"><?= $open_cn ?></div>
            <div class="page-metric-trend">Available to apply</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Completed Credits</div>
            <div class="page-metric-value" style="color:#10b981;">
                <?= $conn->query("SELECT COUNT(*) FROM credit_notes WHERE status='Completed'")->fetch_row()[0] ?? 0 ?>
            </div>
            <div class="page-metric-trend">Fully applied</div>
        </div>

    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Recent Credit Notes</h2>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Open' => 'Open', 'Completed' => 'Completed'] as $val => $label):
                        $href = $val === '' ? 'credit_notes.php' : "credit_notes.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addCreditNoteModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Issue Credit Note
                </button>

            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Credit Note #</th>
                    <th>Invoice Reference</th>
                    <th>Customer</th>
                    <th>Issue Date</th>
                    <th>Amount</th>
                    <th>Status Badge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($credit_notes && $credit_notes->num_rows > 0): ?>
                    <?php while ($row = $credit_notes->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="credit_notes">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['credit_note_str']) ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-dark);">
                                <?php if (!empty($row['linked_invoice_number'])): ?>
                                    <span style="display:inline-block; padding:2px 9px; border-radius:20px; font-size:12px; font-weight:600; background:rgba(109,74,255,0.09); color:var(--primary-color);">
                                        <?= htmlspecialchars($row['linked_invoice_number']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['customer_name'] ?? 'Unknown Customer') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['issue_date']) ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">
                                $
                                <?= number_format($row['credit_amount'], 2) ?>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_sales_return_bill.php?cn_id=<?= $row['id'] ?>"
                                       title="View Credit Note Receipt"
                                       style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:rgba(8,145,178,0.10); text-decoration:none;">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#0891b2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>
                                        </svg>
                                    </a>
                                    <a href="credit_notes.php?action=toggle_status&id=<?= $row['id'] ?>"
                                       title="Toggle Status: <?= $row['status'] === 'Open' ? 'Mark Completed' : 'Reopen' ?>"
                                       style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:<?= $row['status'] === 'Open' ? 'rgba(16,185,129,0.12)' : 'rgba(100,116,139,0.12)' ?>; text-decoration:none;">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="<?= $row['status'] === 'Open' ? '#10b981' : 'var(--text-muted)' ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="23 4 23 10 17 10"></polyline>
                                            <polyline points="1 20 1 14 7 14"></polyline>
                                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                                        </svg>
                                    </a>
                                    <a href="credit_notes.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this credit note?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="11" y2="17"/></svg><div class="empty-state-title">No Credit Notes Yet</div><div class="empty-state-desc">Issue credit notes to customers for returned or adjusted orders.</div><a href="add_credit_note.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Credit Note</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_cn, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Credit Note Modal -->
<div class="modal-overlay" id="addCreditNoteModal">
    <div class="modal-content" style="max-width: 500px; width: 90%;">
        <div class="modal-header">
            <h2>Issue Credit Note</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="close-modal"
                    onclick="document.getElementById('addCreditNoteModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="add_credit_note.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div>
                        <label class="form-label">Note Reference #</label>
                        <input type="text" name="credit_note_str" class="form-input" value="<?= $gen_cn_id ?>" readonly
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
                        <label class="form-label">Assoc. Return (Optional)</label>
                        <select name="sales_return_id" class="form-input" onchange="autoFillCredit(this)">
                            <option value="" selected>No associated return</option>
                            <?php foreach ($returns_list as $ret): ?>
                                <option value="<?= htmlspecialchars($ret['id']) ?>"
                                    data-amount="<?= $ret['total_refund_amount'] ?>">
                                    <?= htmlspecialchars($ret['return_id_str']) ?> ('+\$currency['symbol']+'
                                    <?= $ret['total_refund_amount'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Date</label>
                        <input type="date" name="issue_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Credit Amount ('+\$currency['symbol']+')</label>
                        <input type="number" step="0.01" name="credit_amount" id="credit_amount" class="form-input"
                            placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="form-label">Internal Reason/Notes</label>
                        <textarea name="reason" class="form-input" rows="2"
                            placeholder="Optional details..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Issue Credit
                    Note</button>
            </div>
        </form>
    </div>
</div>

<script>
    function autoFillCredit(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const amount = parseFloat(selectedOption.getAttribute('data-amount') || 0);
        if (amount > 0) {
            document.getElementById('credit_amount').value = amount.toFixed(2);
        }
    }
</script>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addCreditNoteModal').classList.add('active');
            window.history.replaceState({}, document.title, "credit_notes.php");
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>