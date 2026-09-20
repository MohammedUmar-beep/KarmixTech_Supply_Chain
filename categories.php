<?php
$search_options = array(
    'category_name' => 'Name',
    'category_id_str' => 'Category ID',
    'description' => 'Description',
);
$allowed_cols = array_keys($search_options);

require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Toggle)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    if ($action == 'delete') {
        $conn->query("DELETE FROM categories WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM categories WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';
            $conn->query("UPDATE categories SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: categories.php");
    exit();
}

$total_categories_res = $conn->query("SELECT COUNT(*) FROM categories");
$total_categories = $total_categories_res ? $total_categories_res->fetch_row()[0] : 0;

// Fetch Metrics
$active_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Active'")->fetch_row()[0] ?? 0;
$inactive_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Inactive'")->fetch_row()[0] ?? 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Categories
$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];

    if (in_array($col, $allowed_cols)) {
        $search_sql = "WHERE $col LIKE '%$search%'";
    }
}
// Apply filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $conn->real_escape_string($_GET['status']);
    $search_sql .= ($search_sql == '') ? "WHERE status = '$status_filter'" : " AND status = '$status_filter'";
}

$categories = $conn->query("SELECT * FROM categories $search_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");

$current_page = 'categories.php';

// Generate next Category ID
$next_id_res = $conn->query("SELECT MAX(id) FROM categories");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$gen_cat_id = 'CAT-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

$page_title = 'Categories';
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
            <h1>Categories</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Total Categories</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $total_categories ?></div>
            <div class="page-metric-trend">All defined categories</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Active Categories</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $active_count ?></div>
            <div class="page-metric-trend">Currently in use</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Inactive Categories</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $inactive_count ?></div>
            <div class="page-metric-trend">Hidden from catalog</div>
        </div>
        <div class="page-metric-card border-blue">
            <div class="page-metric-title">Products Categorized</div>
            <div class="page-metric-value" style="color:#3b82f6;">
                <?= $conn->query("SELECT COUNT(*) FROM products WHERE category IS NOT NULL AND category != ''")->fetch_row()[0] ?? 0 ?>
            </div>
            <div class="page-metric-trend">Assigned to categories</div>
        </div>
    </div>


    <h2 style="font-size: 20px; margin-bottom: 20px;">Active Categories</h2>

    <div class="data-table-container">
        <div class="table-header-controls">
            <div style="display:flex; gap: 12px; align-items:center;">
                <?php $current_tab = $_GET['status'] ?? ''; ?>
                <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
                    <?php foreach (['' => 'All', 'Active' => 'Active', 'Inactive' => 'Inactive'] as $val => $label):
                        $href = $val === '' ? 'categories.php' : "categories.php?status=$val";
                        $active = $current_tab === $val; ?>
                    <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addCategoryModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add New Category
                </button>
                <a href="export.php?module=categories&format=csv" class="btn btn-primary"
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

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Category Name</th>
                    <th>Category ID</th>
                    <th>Description</th>
                    <th>Recent Products</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($categories->num_rows > 0): ?>
                    <?php while ($row = $categories->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="categories">

                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="font-weight: 500;">
                                <?= htmlspecialchars($row['category_name']) ?>
                            </td>
                            <td style="color: var(--primary-color); font-weight: 500; font-size:13px;">
                                <?= htmlspecialchars($row['category_id_str']) ?>
                            </td>
                            <td
                                style="font-size: 13px; color: var(--text-muted); max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars($row['description']) ?>
                            </td>
                            <td>
                                <?php
                                $cat_name_safe = $conn->real_escape_string($row['category_name']);

                                // Get total products count for this category
                                $count_res = $conn->query("SELECT COUNT(*) FROM products WHERE category = '$cat_name_safe'");
                                $total_cat_prods = $count_res ? intval($count_res->fetch_row()[0]) : 0;

                                // Fetch top 3 products
                                $prod_res = $conn->query("SELECT product_name, image_path FROM products WHERE category = '$cat_name_safe' ORDER BY id DESC LIMIT 3");
                                $prods = [];
                                if ($prod_res) {
                                    while ($p = $prod_res->fetch_assoc()) {
                                        $prods[] = $p;
                                    }
                                }
                                $remaining = max(0, $total_cat_prods - 3);
                                ?>
                                <a href="products.php?category=<?= urlencode($row['category_name']) ?>"
                                    style="display:inline-flex; align-items:center; text-decoration:none; position:relative;">
                                    <?php foreach ($prods as $index => $p): ?>
                                        <div style="width:32px; height:32px; border-radius:50%; border:2px solid #fff; background:var(--bg-light); display:flex; align-items:center; justify-content:center; overflow:hidden; z-index:<?= 3 - $index ?>; margin-right:-10px;"
                                            title="<?= htmlspecialchars($p['product_name']) ?>">
                                            <?php if (!empty($p['image_path']) && file_exists($p['image_path'])): ?>
                                                <img src="<?= htmlspecialchars($p['image_path']) ?>" alt="Product"
                                                    style="width:100%; height:100%; object-fit:cover;">
                                            <?php else: ?>
                                                <span
                                                    style="font-size:11px; font-weight:600; color:var(--text-muted);"><?= strtoupper(substr(trim($p['product_name']), 0, 1)) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($remaining > 0): ?>
                                        <div
                                            style="width:32px; height:32px; border-radius:50%; border:2px solid #fff; background:var(--bg-light); display:flex; align-items:center; justify-content:center; z-index:0; font-size:12px; font-weight:500; color:var(--text-dark);">
                                            +<?= $remaining ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (empty($prods)): ?>
                                        <span style="color:var(--text-muted); font-size:13px;">None</span>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td>
                                <?= statusBadge($row['status']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="edit_category.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon"
                                            viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                    <a href="categories.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this category?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                                        title="Delete"><svg class="delete-icon" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg></a>
                                    <a href="categories.php?action=toggle&id=<?= $row['id'] ?>" title="Toggle Active/Inactive">
                                        <svg class="toggle-icon <?= $row['status'] == 'Active' ? 'active' : 'inactive' ?>"
                                            viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;">
                                            <polyline points="23 4 23 10 17 10"></polyline>
                                            <polyline points="1 20 1 14 7 14"></polyline>
                                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15">
                                            </path>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="7"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg><div class="empty-state-title">No Categories Yet</div><div class="empty-state-desc">Add product categories to organize your inventory.</div><a href="add_category.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Category</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_categories, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Category Modal -->
<div class="modal-overlay" id="addCategoryModal">
    <div class="modal-content" style="max-width: 500px; width: 90%;">
        <div class="modal-header">
            <h2>Add New Category</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="close-modal"
                    onclick="document.getElementById('addCategoryModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="add_category.php" method="POST">
            <div class="modal-body form-grid">
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div>
                        <label class="form-label">Category Name</label>
                        <input type="text" name="category_name" class="form-input" placeholder="Ex: Electronics"
                            required>
                    </div>
                    <div>
                        <label class="form-label">Category ID</label>
                        <input type="text" name="category_id_str" class="form-input" value="<?= $gen_cat_id ?>" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-input" rows="3" placeholder="Category description..."
                            style="resize:vertical;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Add Category</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addCategoryModal').classList.add('active');
            window.history.replaceState({}, document.title, "categories.php");
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>