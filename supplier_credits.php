<?php
$search_options = array(
    'sc.credit_note_str' => 'Credit ID',
    'po.purchase_id_str' => 'PO Number',
    's.supplier_name'    => 'Supplier Name',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

// Ensure purchase_order_id column exists (safe to run on every load — instant no-op if already present)
$col_check = $conn->query("SHOW COLUMNS FROM supplier_credits LIKE 'purchase_order_id'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE supplier_credits ADD COLUMN purchase_order_id INT(11) DEFAULT NULL AFTER supplier_id");
    $conn->query("ALTER TABLE supplier_credits ADD KEY sc_po_id (purchase_order_id)");
}

// One-time migration: remap legacy status values to new labels
$conn->query("UPDATE supplier_credits SET status='Open' WHERE status='Active'");
$conn->query("UPDATE supplier_credits SET status='Completed' WHERE status='Used'");

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM supplier_credits WHERE id = $id");
    } elseif ($action == 'toggle_status') {
        $cur_res = $conn->query("SELECT status FROM supplier_credits WHERE id = $id");
        if ($cur_res && $cur = $cur_res->fetch_assoc()) {
            $new_status = ($cur['status'] === 'Open') ? 'Completed' : 'Open';
            $conn->query("UPDATE supplier_credits SET status='$new_status' WHERE id = $id");
        }
    }
    header("Location: supplier_credits.php");
    exit();
}

// Fetch Metrics
$total_res = $conn->query("SELECT SUM(amount) FROM supplier_credits");
$total_credits = $total_res ? $total_res->fetch_row()[0] : 0;

$active_res = $conn->query("SELECT SUM(amount) FROM supplier_credits WHERE status='Open'");
$active_credits = $active_res ? $active_res->fetch_row()[0] : 0;

$used_res = $conn->query("SELECT SUM(amount) FROM supplier_credits WHERE status='Completed'");
$used_credits = $used_res ? $used_res->fetch_row()[0] : 0;

// Count for pagination — evaluated after $search_sql is built below
$total_credits_count = 0; // will be set after search_sql is ready

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Data
$search_sql = '';
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $search_sql = "WHERE sc.status = '$status_filter'";
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
    SELECT sc.*,
           s.supplier_name,
           po.id              AS linked_po_id,
           po.purchase_id_str AS po_number,
           po.status          AS po_status
    FROM supplier_credits sc
    LEFT JOIN suppliers s       ON sc.supplier_id       = s.id
    LEFT JOIN purchase_orders po ON sc.purchase_order_id = po.id
    $search_sql
    ORDER BY sc.created_at DESC
    LIMIT $limit OFFSET $offset
";
// Count (now that $search_sql is ready)
$count_res = $conn->query("
    SELECT COUNT(*) FROM supplier_credits sc
    LEFT JOIN purchase_orders po ON sc.purchase_order_id = po.id
    LEFT JOIN suppliers s ON sc.supplier_id = s.id
    $search_sql
");
$total_credits_count = $count_res ? (int) $count_res->fetch_row()[0] : 0;

$credits = $conn->query($query);

// Fetch dropdown data for Add Modal
$suppliers_res = $conn->query("SELECT id, supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name");
$suppliers_list = [];
if ($suppliers_res) {
    while ($row = $suppliers_res->fetch_assoc()) {
        $suppliers_list[] = $row;
    }
}

// Fetch all open/issued POs for the PO dropdown (used by JS to filter by supplier)
$po_res = $conn->query("SELECT id, purchase_id_str, supplier_id, total_amount FROM purchase_orders WHERE status IN ('Issued','Pending','Received') ORDER BY created_at DESC");
$po_list = [];
if ($po_res) {
    while ($r = $po_res->fetch_assoc()) $po_list[] = $r;
}

$current_page = 'supplier_credits';
$page_title = 'Supplier Credits';
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
        <a href="purchase_returns.php" class="tab-link">Returns</a>
        <a href="supplier_credits.php" class="tab-link active">Supplier Credits</a>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Credit</div>
            <div class="page-metric-value" style="color:var(--primary-color);">
                $<?= number_format($total_credits ?? 0, 2) ?></div>
            <div class="page-metric-trend">Lifetime supplier credits</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Open Credit</div>
            <div class="page-metric-value" style="color:#10b981;">$<?= number_format($active_credits ?? 0, 2) ?></div>
            <div class="page-metric-trend">Available to use</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Completed Credit</div>
            <div class="page-metric-value" style="color:#f59e0b;">$<?= number_format($used_credits ?? 0, 2) ?></div>
            <div class="page-metric-trend">Already applied</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Remaining Balance</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                $<?= number_format(max(0, ($total_credits ?? 0) - ($used_credits ?? 0)), 2) ?></div>
            <div class="page-metric-trend">Net available credit</div>
        </div>
    </div>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Open' => 'Open', 'Completed' => 'Completed'] as $val => $label):
                        $href = $val === '' ? 'supplier_credits.php' : "supplier_credits.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addCreditModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    New Supplier Credit
                </button>

            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Credit Note #</th>
                    <th>Supplier</th>
                    <th>Date Issued</th>
                    <th>Amount</th>
                    <th>StatusBadge</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($credits && $credits->num_rows > 0): ?>
                    <?php while ($row = $credits->fetch_assoc()): ?>
                        <?php
                        ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="supplier_credits">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:14px;">
                                <?= htmlspecialchars($row['credit_note_str']) ?>
                            </td>
                            <td style="font-size:14px; font-weight:500;">
                                <?= htmlspecialchars($row['supplier_name'] ?? 'Unknown Supplier') ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-dark);">
                                <?= htmlspecialchars($row['date_issued']) ?>
                            </td>
                            <td style="font-size: 14px; font-weight: 600;">
                                $
                                <?= number_format($row['amount'], 2) ?>
                            </td>
                            <td>
                                <?php
                                $sc_slug = $row['status'] === 'Open' ? 'cancelled' : 'completed';
                                echo '<span class="status-badge status-' . $sc_slug . '">' . htmlspecialchars($row['status']) . '</span>';
                                ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="view_purchase_return_bill.php?sc_id=<?= $row['id'] ?>" target="_blank"
                                       title="View Credit Note Receipt"
                                       style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:rgba(109,74,255,0.10); text-decoration:none;">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--primary-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>
                                        </svg>
                                    </a>
                                    <a href="supplier_credits.php?action=toggle_status&id=<?= $row['id'] ?>"
                                       title="Toggle Status: <?= $row['status'] === 'Open' ? 'Mark Completed' : 'Reopen' ?>"
                                       style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:<?= $row['status'] === 'Open' ? 'rgba(16,185,129,0.12)' : 'rgba(100,116,139,0.12)' ?>; text-decoration:none;">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="<?= $row['status'] === 'Open' ? '#10b981' : 'var(--text-muted)' ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="23 4 23 10 17 10"></polyline>
                                            <polyline points="1 20 1 14 7 14"></polyline>
                                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                                        </svg>
                                    </a>
                                    <a href="supplier_credits.php?action=delete&id=<?= $row['id'] ?>"
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
                    <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/></svg><div class="empty-state-title">No Supplier Credits Yet</div><div class="empty-state-desc">Record supplier credits to track amounts owed by suppliers.</div><a href="add_supplier_credit.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Supplier Credit</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_credits_count, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Create Credit Modal -->
<div class="modal-overlay" id="addCreditModal">
    <div class="modal-content" style="max-width: 600px; width: 90%;">
        <div class="modal-header">
            <h2>Record Supplier Credit</h2>
            <button class="close-modal"
                onclick="document.getElementById('addCreditModal').classList.remove('active')">&times;</button>
        </div>
        <form action="add_supplier_credit.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Credit Note #</label>
                        <input type="text" name="credit_note_str" id="sc_credit_str" class="form-input" placeholder="Auto-generated" readonly
                            style="background:var(--bg-light); cursor:not-allowed; color:var(--text-muted);">
                        <input type="hidden" name="credit_note_str" id="sc_credit_str_hidden">
                    </div>
                    <div>
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="sc_supplier_id" class="form-input" required onchange="filterSCPOs(this.value)">
                            <option value="" disabled selected>Select Supplier</option>
                            <?php foreach ($suppliers_list as $sup): ?>
                                <option value="<?= $sup['id'] ?>">
                                    <?= htmlspecialchars($sup['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="form-label">Linked Purchase Order <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
                        <select name="purchase_order_id" id="sc_po_select" class="form-input">
                            <option value="">— No linked PO —</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" name="amount" id="sc_amount" class="form-input" placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="form-label">Date Issued</label>
                        <input type="date" name="date_issued" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px 40px; border-radius:8px; font-size:15px; font-weight:500;">Save
                    Credit</button>
            </div>
        </form>
    </div>
</div>

<script>
// All POs passed from PHP — keyed by supplier_id for fast lookup
const scPoData = <?= json_encode(array_map(fn($p) => [
    'id'              => $p['id'],
    'purchase_id_str' => $p['purchase_id_str'],
    'supplier_id'     => $p['supplier_id'],
    'total_amount'    => $p['total_amount'],
], $po_list)) ?>;

// Next credit note ref
const scNextRef = '<?= 'SCN-' . str_pad(($conn->query("SELECT COALESCE(MAX(id),0) FROM supplier_credits")->fetch_row()[0] + 1), 4, '0', STR_PAD_LEFT) ?>';

document.addEventListener('DOMContentLoaded', function () {
    const strDisplay = document.getElementById('sc_credit_str');
    const strHidden  = document.getElementById('sc_credit_str_hidden');
    if (strDisplay) { strDisplay.value = scNextRef; }
    if (strHidden)  { strHidden.value  = scNextRef; }
    // remove the duplicate readonly input — only hidden carries the value to POST
    if (strDisplay) strDisplay.removeAttribute('name');
});

function filterSCPOs(supplierId) {
    const sel = document.getElementById('sc_po_select');
    sel.innerHTML = '<option value="">— No linked PO —</option>';
    const filtered = scPoData.filter(p => String(p.supplier_id) === String(supplierId));
    filtered.forEach(function(p) {
        const opt = document.createElement('option');
        opt.value       = p.id;
        opt.textContent = p.purchase_id_str + '  ($' + parseFloat(p.total_amount).toFixed(2) + ')';
        opt.dataset.amount = p.total_amount;
        sel.appendChild(opt);
    });
    // Auto-select if only one PO for this supplier
    if (filtered.length === 1) {
        sel.value = filtered[0].id;
        document.getElementById('sc_amount').value = parseFloat(filtered[0].total_amount).toFixed(2);
    }
}

document.getElementById('sc_po_select')?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const amt = selected.dataset.amount;
    if (amt) document.getElementById('sc_amount').value = parseFloat(amt).toFixed(2);
});
</script>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addCreditModal').classList.add('active');
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>