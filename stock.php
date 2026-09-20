<?php
require_once 'includes/auth_guard.php';

$user_role = $_SESSION['role'] ?? 'staff';

$page_title = 'Stock Overview';

// Handle Actions (Toggle out of stock)
if (isset($_GET['action']) && isset($_GET['id']) && in_array($user_role, ['admin', 'manager'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM products WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Available') ? 'Out of Stock' : 'Available';
            $conn->query("UPDATE products SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: stock.php");
    exit();
}

// Stock Metrics
$res_av = $conn->query("SELECT SUM(stock_level) as c FROM products WHERE status='Available'");
$av_st = $res_av ? (int) $res_av->fetch_assoc()['c'] : 0;

$res_oos = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock_level <= 0 AND status='Available'");
$oos_st = $res_oos ? (int) $res_oos->fetch_assoc()['c'] : 0;

$res_low = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock_level <= warning_threshold AND stock_level > 0");
$low_st = $res_low ? (int) $res_low->fetch_assoc()['c'] : 0;

$res_val = $conn->query("SELECT SUM(stock_level * purchasing_price) as c FROM products WHERE stock_level > 0");
$stock_value = $res_val ? (float) $res_val->fetch_assoc()['c'] : 0;

// Search Logic
$search = $_GET['search'] ?? '';
$search_col = $_GET['search_col'] ?? 'product_name';

$allowed_cols = ['product_name', 'sku_code', 'category', 'status'];
if (!in_array($search_col, $allowed_cols)) {
    $search_col = 'product_name';
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_clauses = [];
if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "`$search_col` LIKE '%$safe_search%'";
}

if ($status_filter === 'Healthy') {
    $where_clauses[] = "(stock_level > warning_threshold AND stock_level > 0 AND status='Available')";
} elseif ($status_filter === 'Low Stock') {
    $where_clauses[] = "(stock_level <= warning_threshold AND stock_level > 0 AND status='Available')";
} elseif ($status_filter === 'Out of Stock') {
    $where_clauses[] = "(stock_level <= 0 OR status='Out of Stock')";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Pagination Logic
$limit = 20;
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Count Total
$count_query = "SELECT COUNT(*) as total FROM products $where_sql";
$count_result = $conn->query($count_query);
$total_records = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_records / $limit);

// Fetch Data
$query = "SELECT * FROM products $where_sql ORDER BY stock_level ASC LIMIT $limit OFFSET $offset";
$result = $conn->query($query);

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Stock Overview</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid" style="margin-bottom: 30px;">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Units Available</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= number_format($av_st) ?></div>
            <div class="page-metric-trend">Across all active items</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Out of Stock Items</div>
            <div class="page-metric-value" style="color: var(--danger-color);"><?= number_format($oos_st) ?></div>
            <div class="page-metric-trend">Zero inventory remaining</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Low Stock Alerts</div>
            <div class="page-metric-value" style="color: #f59e0b;"><?= number_format($low_st) ?></div>
            <div class="page-metric-trend">Below warning threshold</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Est. Stock Value</div>
            <div class="page-metric-value" style="color:#10b981;">$<?= number_format($stock_value, 2) ?></div>
            <div class="page-metric-trend">Based on purchasing price</div>
        </div>
    </div>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Healthy' => 'Healthy', 'Low Stock' => 'Low Stock', 'Out of Stock' => 'Out of Stock'] as $val => $label):
                        $href = $val === '' ? 'stock.php' : 'stock.php?status=' . urlencode($val);
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <?php if (in_array($user_role, ['admin', 'manager'])): ?>
                    <button class="btn btn-outline" onclick="window.location.href='generate_restock_pos.php'"
                        style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px; color:#d97706; border-color:#d97706;">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                            <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.3" />
                        </svg>
                        Generate Restock POs
                    </button>
                <?php endif; ?>

                <a href="#" onclick="window.print()" class="btn btn-primary"
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
                        <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                        <th>Product Info</th>
                        <th>Category</th>
                        <th>Stock Level</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr data-row-id="<?= $row['id'] ?>" data-row-table="stock">
                                <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                                <td>
                                    <div style="font-weight:600; color:var(--text-dark); display:flex; align-items:center;">
                                        <?= htmlspecialchars($row['product_name']) ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                                        SKU: <?= htmlspecialchars($row['sku_code']) ?> |
                                        Barcode: <?= htmlspecialchars($row['barcode_number']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td>
                                    <div style="font-weight:700; font-size:15px; color:<?= $row['stock_level'] <= $row['warning_threshold'] ? ($row['stock_level'] <= 0 ? 'var(--danger-color)' : '#d97706') : 'var(--text-dark)' ?>;">
                                        <?= $row['stock_level'] ?> Units
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:6px;">
                                        Min. threshold: <?= $row['warning_threshold'] ?>
                                    </div>
                                </td>
                                <td><?= statusBadge(
                                    $row['status'] === 'Out of Stock' || $row['stock_level'] <= 0 ? 'Out of Stock' :
                                    ($row['stock_level'] <= $row['warning_threshold'] ? 'Low' : 'Available')
                                ) ?></td>
                                <td>
                                    <div style="font-weight:500;">
                                        <?php
                                        if (!empty($row['warehouse_id'])) {
                                            // Fetch warehouse name
                                            $w_id = (int) $row['warehouse_id'];
                                            $w_res = $conn->query("SELECT warehouse_name FROM warehouses WHERE id = $w_id");
                                            if ($w_res && $w_row = $w_res->fetch_assoc()) {
                                                echo htmlspecialchars($w_row['warehouse_name'] ?? 'Unknown');
                                            } else {
                                                echo 'Unknown';
                                            }
                                        } else {
                                            echo 'Not Assigned';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <!-- Edit -->
                                        <a href="edit_product.php?id=<?= $row['id'] ?>" title="Edit Product"
                                            style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:6px; color:var(--primary-color); border:1px solid var(--border-color); background:var(--bg-light); transition:background 0.2s;"
                                            onmouseover="this.style.background='var(--primary-bg)'"
                                            onmouseout="this.style.background='var(--bg-light)'">
                                            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                        </a>
                                        <!-- Transfer -->
                                        <a href="add_stock_transfer.php?product_id=<?= $row['id'] ?>" title="Transfer Stock"
                                            style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:6px; color:var(--success-color); border:1px solid var(--border-color); background:var(--bg-light); transition:background 0.2s;"
                                            onmouseover="this.style.background='rgba(16,185,129,0.08)'"
                                            onmouseout="this.style.background='var(--bg-light)'">
                                            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="17 1 21 5 17 9"></polyline>
                                                <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                                                <polyline points="7 23 3 19 7 15"></polyline>
                                                <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-state-row"><td colspan="7"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg><div class="empty-state-title">No Stock Records Yet</div><div class="empty-state-desc">Add products to start tracking your stock levels.</div><a href="add_product.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Product</a></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&search_col=<?= $search_col ?>"
                        class="page-link">Previous</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&search_col=<?= $search_col ?>"
                        class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&search_col=<?= $search_col ?>"
                        class="page-link">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<style>
    /* Same table/card aesthetics as other pages */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
    }
</style>

<?php include 'includes/footer.php'; ?>