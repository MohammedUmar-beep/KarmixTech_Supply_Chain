<?php
$search_options = array(
    'p.product_name' => 'Name',
    'p.sku_code' => 'SKU',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle) - mainly for consistency with products
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM products WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM products WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Available') ? 'Out of Stock' : 'Available';
            $conn->query("UPDATE products SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: price_list.php");
    exit();
}

$total_products_res = $conn->query("SELECT COUNT(*) FROM products");
$total_products = $total_products_res ? $total_products_res->fetch_row()[0] : 0;

// Fetch Metrics for pricing context
$avg_margin_res = $conn->query("SELECT AVG(CAST(REPLACE(selling_price_margin, '%', '') AS DECIMAL)) FROM products");
$avg_margin = $avg_margin_res ? number_format($avg_margin_res->fetch_row()[0], 1) : '0.0';

$highest_priced_res = $conn->query("SELECT MAX(selling_price) FROM products");
$highest_priced = $highest_priced_res ? number_format($highest_priced_res->fetch_row()[0], 2) : '0.00';

// Fetch Products
$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];

    if (in_array($col, $allowed_cols)) {
        $search_sql = "WHERE $col LIKE '%$search%'";
    }
}
$products = $conn->query("SELECT * FROM products $search_sql ORDER BY id DESC LIMIT 50");

$page_title = 'Price List';
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
            <h1>Price List</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Products Priced</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_products ?></div>
            <div class="page-metric-trend">In the catalog</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Average Margin (%)</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $avg_margin ?>%</div>
            <div class="page-metric-trend">Avg profit margin</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Highest Price</div>
            <div class="page-metric-value" style="color:#f59e0b;">$<?= $highest_priced ?></div>
            <div class="page-metric-trend">Top priced product</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Lowest Price</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                $<?= $conn->query("SELECT MIN(selling_price) FROM products WHERE selling_price > 0")->fetch_row()[0] ? number_format($conn->query("SELECT MIN(selling_price) FROM products WHERE selling_price > 0")->fetch_row()[0], 2) : '0.00' ?>
            </div>
            <div class="page-metric-trend">Entry-level product</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Product Price List</h2>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php
                // Dynamically check if status column exists and fetch distinct statuses
                $status_query = "SELECT DISTINCT status FROM `products` WHERE status IS NOT NULL AND status != ''";
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

            <div style="display:flex; gap: 12px;">
                <button class="btn btn-primary"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Export Price List
                </button>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>SKU Code</th>
                    <th>Category</th>
                    <th>Purchasing Price</th>
                    <th>Margin</th>
                    <th>Selling Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products->num_rows > 0): ?>
                    <?php while ($row = $products->fetch_assoc()): ?>
                        <tr>

                            <td style="font-weight: 500; display:flex; align-items:center; gap:10px;">
                                <div
                                    style="width:30px; height:30px; border-radius:4px; background:var(--bg-light); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="var(--text-muted)" fill="none">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                </div>
                                <?= htmlspecialchars($row['product_name']) ?>
                            </td>
                            <td style="color: var(--text-muted); font-size:13px;">
                                <?= htmlspecialchars($row['sku_code']) ?>
                            </td>
                            <td style="font-size: 13px; font-weight:500; text-decoration:underline;">
                                <?= htmlspecialchars($row['category']) ?>
                            </td>
                            <td style="color: #991b1b; font-weight: 500;">$
                                <?= number_format($row['purchasing_price'], 2) ?>
                            </td>
                            <td>
                                <span
                                    style="background:var(--bg-light); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:12px; color:var(--text-dark);">
                                    <?= htmlspecialchars($row['selling_price_margin']) ?>
                                </span>
                            </td>
                            <td style="color: #065f46; font-weight: 600;">$
                                <?= number_format($row['selling_price'], 2) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="edit_product.php?id=<?= $row['id'] ?>" title="Edit Source Product"><svg
                                            class="edit-icon" viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding: 40px;">No products found in price list.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($total_products > 10): ?>
            <div class="pagination">
                <button class="btn btn-outline" style="padding: 6px 12px; border-radius:6px; font-size: 13px;">&larr;
                    Previous</button>
                <div class="page-numbers">
                    <span class="page-num active">1</span>
                    <span class="page-num">2</span>
                    <span class="page-num">3</span>
                </div>
                <button class="btn btn-outline" style="padding: 6px 12px; border-radius:6px; font-size: 13px;">Next
                    &rarr;</button>
            </div>
        <?php endif; ?>
    </div>
    </div>
</main>
</div>

<?php include 'includes/footer.php'; ?>