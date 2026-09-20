<?php
$search_options = array(
    'p.product_name' => 'Name',
    'p.sku_code' => 'SKU',
    'p.product_id_str' => 'Product ID',

    'p.category' => 'Category',
    'p.selling_price' => 'Price',
    'p.weight' => 'Weight',
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
        $conn->query("DELETE FROM products WHERE id = $id");
    } elseif ($action == 'toggle') {
        $res = $conn->query("SELECT status FROM products WHERE id = $id");
        if ($res->num_rows > 0) {
            $current_status = $res->fetch_row()[0];
            $new_status = ($current_status == 'Available') ? 'Out of Stock' : 'Available';
            $conn->query("UPDATE products SET status = '$new_status' WHERE id = $id");
        }
    }
    header("Location: products.php");
    exit();
}

$total_products_res = $conn->query("SELECT COUNT(*) FROM products");
$total_products = $total_products_res ? $total_products_res->fetch_row()[0] : 0;

// Fetch Metrics
$available_count = $conn->query("SELECT COUNT(*) FROM products WHERE status='Available'")->fetch_row()[0] ?? 0;
$out_stock_count = $conn->query("SELECT COUNT(*) FROM products WHERE stock_level <= 0 OR status='Out of Stock'")->fetch_row()[0] ?? 0;
$low_stock_count_res = $conn->query("SELECT COUNT(*) FROM products WHERE stock_level > 0 AND stock_level <= warning_threshold AND status = 'Available'");
$low_stock_count = $low_stock_count_res ? $low_stock_count_res->fetch_row()[0] : 0;

// Pagination Setup
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Products with Pagination
$category_filter = '';
$count_filter = '';
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $safe_cat = $conn->real_escape_string($_GET['category']);
    $category_filter = "WHERE category = '$safe_cat'";
    $count_filter = "WHERE category = '$safe_cat'";
}

if (isset($_GET['search']) && !empty(trim($_GET['search'])) && isset($_GET['search_col'])) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'];

    if (in_array($col, $allowed_cols)) {
        $db_col = str_replace('p.', '', $col);
        $s_clause = "($db_col LIKE '%$search%')";

        $category_filter = empty($category_filter) ? "WHERE $s_clause" : "$category_filter AND $s_clause";
        $count_filter = empty($count_filter) ? "WHERE $s_clause" : "$count_filter AND $s_clause";
    }
}

// Get total count for pagination
$total_filtered_res = $conn->query("SELECT COUNT(*) FROM products $count_filter");
$total_filtered_items = $total_filtered_res ? $total_filtered_res->fetch_row()[0] : 0;

$products = $conn->query("SELECT * FROM products $category_filter ORDER BY id DESC LIMIT $limit OFFSET $offset");

// Fetch Categories for dropdown
$categories_res = $conn->query("SELECT category_name FROM categories WHERE status = 'Active'");
$categories_list = [];
if ($categories_res) {
    while ($cat_row = $categories_res->fetch_assoc()) {
        $categories_list[] = $cat_row['category_name'];
    }
}

// Fetch Warehouses for dropdown
$warehouses_res = $conn->query("SELECT id, warehouse_name FROM warehouses WHERE status = 'Active'");
$warehouses_list = [];
if ($warehouses_res) {
    while ($wh_row = $warehouses_res->fetch_assoc()) {
        $warehouses_list[] = $wh_row;
    }
}

// Fetch Suppliers for dropdown
$suppliers_res = $conn->query("SELECT supplier_id_str, supplier_name FROM suppliers WHERE status = 'Active'");
$suppliers_list = [];
if ($suppliers_res) {
    while ($sup_row = $suppliers_res->fetch_assoc()) {
        $suppliers_list[] = $sup_row;
    }
}

// Generate next Product IDs
$next_id_res = $conn->query("SELECT MAX(id) FROM products");
$next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
$pad_id = str_pad($next_id, 4, '0', STR_PAD_LEFT);
$gen_prod_id = 'PROD-' . $pad_id;
$gen_sku_code = 'SKU-' . $pad_id;
$gen_barcode = 'BAR-' . $pad_id;

$page_title = 'Products';
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
            <h1>Products</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="page-metrics-grid">
        <div class="page-metric-card">
            <div class="page-metric-title">Available Products</div>
            <div class="page-metric-value" style="color:var(--primary-color);"><?= $available_count ?></div>
            <div class="page-metric-trend">In-stock items</div>
        </div>
        <div class="page-metric-card border-red">
            <div class="page-metric-title">Out of Stock</div>
            <div class="page-metric-value" style="color:#ef4444;"><?= $out_stock_count ?></div>
            <div class="page-metric-trend">Zero inventory</div>
        </div>
        <div class="page-metric-card border-yellow">
            <div class="page-metric-title">Low Stock Items</div>
            <div class="page-metric-value" style="color:#f59e0b;"><?= $low_stock_count ?></div>
            <div class="page-metric-trend">Below threshold</div>
        </div>
        <div class="page-metric-card border-green">
            <div class="page-metric-title">Total Catalog</div>
            <div class="page-metric-value" style="color:#10b981;"><?= $available_count + $out_stock_count ?></div>
            <div class="page-metric-trend">All products</div>
        </div>
    </div>

    <h2 style="font-size: 20px; margin-bottom: 20px;">Active Products</h2>

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
                <?php endif; ?>
            </div>
            <div style="display:flex; gap: 12px;">
                <button class="btn btn-outline"
                    onclick="document.getElementById('addProductModal').classList.add('active')"
                    style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add New Product
                </button>

                <a href="export.php?module=products&format=csv" class="btn btn-primary"
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
                    <th>Product Name</th>
                    <th>Product ID</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Weight</th>
                    <th>Stock Level <br><span style="font-size:11px;">(in units)</span></th>
                    <th>Rec. Level <br><span style="font-size:11px;">(in units)</span></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products->num_rows > 0): ?>
                    <?php while ($row = $products->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="products">
                            <td style="width:30px; padding-right:0;"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="font-weight: 500; display:flex; align-items:center; gap:10px;">
                                <div
                                    style="width:30px; height:30px; border-radius:4px; background:var(--bg-light); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                    <?php if (!empty($row['image_path']) && file_exists($row['image_path'])): ?>
                                        <img src="<?= htmlspecialchars($row['image_path']) ?>" alt="Product"
                                            style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="var(--text-muted)" fill="none">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <?= htmlspecialchars($row['product_name']) ?>
                            </td>
                            <td style="color: var(--text-muted); font-size:13px;">
                                <?= htmlspecialchars($row['product_id_str']) ?>
                            </td>

                            <td style="font-size: 13px; font-weight:500; text-decoration:underline;">
                                <?= htmlspecialchars($row['category']) ?>
                            </td>
                            <td data-edit-type="number" title="Double-click to edit">$
                                <?= number_format($row['selling_price'], 0) ?>
                            </td>
                            <td style="font-size: 13px; color: var(--text-muted);">
                                <?= htmlspecialchars($row['weight']) ?> lb
                            </td>
                            <td data-edit-type="number" title="Double-click to edit">
                                <?= number_format($row['stock_level']) ?>
                            </td>
                            <td>
                                <?= number_format($row['warning_threshold']) ?>
                            </td>
                            <td>
                                <div class="table-actions" style="display:flex; gap:12px; align-items:center;">
                                    <a href="edit_product.php?id=<?= $row['id'] ?>" title="Edit"><svg class="edit-icon"
                                            viewBox="0 0 24 24">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg></a>
                                    <a href="products.php?action=delete&id=<?= $row['id'] ?>"
                                        onclick="event.preventDefault(); showCustomConfirm('Are you sure you want to delete this product?', () => { window.location.href = this.href || this.getAttribute('href'); });"
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
                    <tr class="empty-state-row"><td colspan="11"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg><div class="empty-state-title">No Products Yet</div><div class="empty-state-desc">Add your first product to start tracking inventory.</div><a href="add_product.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Product</a></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php renderPagination($total_filtered_items, $limit, $page); ?>
    </div>
</main>
</div>

<!-- Add Product Modal -->
<div class="modal-overlay" id="addProductModal">
    <div class="modal-content modal-product">
        <div class="modal-header">
            <h2>Add new product</h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="btn btn-outline"
                    style="padding: 6px 12px; font-size: 13px; border-radius: 20px; display:flex; align-items:center; gap:6px;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg> Add Custom Field
                </button>
                <button type="button" class="btn btn-outline"
                    onclick="window.open('import_data.php?import_type=products', '_self')"
                    style="padding: 6px 12px; font-size: 13px; border-radius: 20px; display:flex; align-items:center; gap:6px;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg> Bulk Upload
                </button>
                <button class="close-modal"
                    onclick="document.getElementById('addProductModal').classList.remove('active')">&times;</button>
            </div>
        </div>
        <form action="add_product.php" method="POST" enctype="multipart/form-data">
            <div class="modal-body form-grid">

                <!-- Row 1: Product ID | Product Name | Category -->
                <div class="form-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
                    <div>
                        <label class="form-label">Product ID</label>
                        <input type="text" name="product_id_str" class="form-input" value="<?= $gen_prod_id ?>" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                    <div>
                        <label class="form-label">Product Name</label>
                        <input type="text" name="product_name" class="form-input" placeholder="Ex: Product 007"
                            required>
                    </div>
                    <div>
                        <label class="form-label">Category</label>
                        <select name="category" class="form-input" required>
                            <option value="" disabled selected>Select Category</option>
                            <?php foreach ($categories_list as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Warehouse | Stock Level | Warning Threshold -->
                <div class="form-grid-3"
                    style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px;">
                    <div>
                        <label class="form-label">Warehouse</label>
                        <select name="warehouse_id" class="form-input" required>
                            <option value="" disabled selected>Select Warehouse</option>
                            <?php foreach ($warehouses_list as $wh): ?>
                                <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Stock Level</label>
                        <input type="number" name="stock_level" class="form-input" placeholder="Ex: 2000" required>
                    </div>
                    <div>
                        <label class="form-label">Warning Threshold Stock Level</label>
                        <input type="number" name="warning_threshold" class="form-input" placeholder="Ex: 20" required>
                    </div>
                </div>

                <!-- Row 3: Purchase Price | Selling Price | Selling Price Margin % -->
                <div class="form-grid-3"
                    style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px;">
                    <div>
                        <label class="form-label">Purchase Price</label>
                        <input type="number" step="0.01" name="purchasing_price" class="form-input"
                            placeholder="Ex: 2000" required>
                    </div>
                    <div>
                        <label class="form-label">Selling Price</label>
                        <input type="number" step="0.01" name="selling_price" class="form-input" placeholder="Ex: 5000"
                            required>
                    </div>
                    <div>
                        <label class="form-label">Selling Price Margin % <span style="font-size:11px; font-weight:500; color:var(--primary-color); background:rgba(109,74,255,0.08); border-radius:4px; padding:2px 6px; margin-left:4px;">Auto</span></label>
                        <input type="text" name="selling_price_margin" id="marginField" class="form-input" placeholder="Auto-calculated" readonly
                            style="background:var(--bg-light); color:var(--primary-color); font-weight:600; cursor:not-allowed;">
                    </div>
                </div>

                <!-- Row 4: Weight | Dimensions | Dimension Unit -->
                <div class="form-grid-3"
                    style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px;">
                    <div>
                        <label class="form-label">Weight in kg</label>
                        <input type="number" step="0.01" name="weight" class="form-input" placeholder="Ex: 40" required>
                    </div>
                    <div>
                        <label class="form-label">Dimensions</label>
                        <input type="text" name="dimensions" class="form-input" placeholder="L × B × H" required>
                    </div>
                    <div>
                        <label class="form-label">Dimension Unit</label>
                        <select name="dimension_unit" class="form-input" required>
                            <option value="cm">Cm</option>
                            <option value="inch">inch</option>
                        </select>
                    </div>
                </div>

                <!-- Row 5: Image | SKU + Description | Barcode -->
                <div
                    style="display:grid; grid-template-columns:180px 1fr 1fr; gap:20px; margin-top:20px; align-items:start;">
                    <div>
                        <label class="form-label">Insert Image (400px x 400 px)</label>
                        <div style="position: relative; width: 150px; height: 150px; background-color: var(--bg-light); border: 1px dashed var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; overflow:hidden;"
                            onclick="document.getElementById('productImageInput').click();">
                            <img id="productImagePreview" src=""
                                style="width:100%; height:100%; object-fit:cover; display:none;" />
                            <svg id="productImagePlaceholder" viewBox="0 0 24 24" width="36" height="36"
                                stroke="var(--border-color)" fill="none">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="16"></line>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                            </svg>
                            <input type="file" name="product_image" id="productImageInput" accept="image/*"
                                style="display:none;"
                                onchange="const file=this.files[0]; if(file){const r=new FileReader(); r.onload=function(e){document.getElementById('productImagePreview').src=e.target.result; document.getElementById('productImagePreview').style.display='block'; document.getElementById('productImagePlaceholder').style.display='none';}; r.readAsDataURL(file);}">
                        </div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div>
                            <label class="form-label">Sku Code</label>
                            <input type="text" name="sku_code" class="form-input" value="<?= $gen_sku_code ?>" readonly
                                style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                        </div>
                        <div>
                            <label class="form-label">Product Description</label>
                            <input type="text" name="description" class="form-input"
                                placeholder="Ex: Type something about product here">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Barcode Number</label>
                        <input type="text" name="barcode_number" class="form-input" value="<?= $gen_barcode ?>" readonly
                            style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-submit"
                    style="padding: 14px; border-radius:8px; font-size:15px; font-weight:500;">Add Product</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['add'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('addProductModal').classList.add('active');
            window.history.replaceState({}, document.title, "products.php");
        });
    </script>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const purchasingPriceInput = document.querySelector('input[name="purchasing_price"]');
        const sellingPriceInput = document.querySelector('input[name="selling_price"]');
        const marginInput = document.querySelector('input[name="selling_price_margin"]');

        function calculateMargin() {
            const pPrice = parseFloat(purchasingPriceInput.value);
            const sPrice = parseFloat(sellingPriceInput.value);

            if (!isNaN(pPrice) && pPrice > 0 && !isNaN(sPrice) && sPrice !== '') {
                const margin = ((sPrice - pPrice) / pPrice) * 100;
                marginInput.value = margin.toFixed(2) + '%';
                marginInput.style.color = margin >= 0 ? 'var(--primary-color)' : '#ef4444';
            } else {
                marginInput.value = '';
            }
        }

        sellingPriceInput.addEventListener('input', calculateMargin);
        purchasingPriceInput.addEventListener('input', calculateMargin);
    });
</script>

<?php include 'includes/footer.php'; ?>