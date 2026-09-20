<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$product = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$product) {
    header("Location: products.php");
    exit();
}

// Fetch Categories for dropdown
$categories_res = $conn->query("SELECT category_name FROM categories WHERE status = 'Active'");
$categories_list = [];
if ($categories_res) {
    while ($cat_row = $categories_res->fetch_assoc()) {
        $categories_list[] = $cat_row['category_name'];
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $product_name = $conn->real_escape_string($_POST['product_name']);
    $supplier_id = $conn->real_escape_string($_POST['supplier_id']);
    $weight = floatval($_POST['weight']);
    $category = $conn->real_escape_string($_POST['category']);
    $stock_level = intval($_POST['stock_level']);
    $dimensions = $conn->real_escape_string($_POST['dimensions']);
    $sku_code = $conn->real_escape_string($_POST['sku_code']);
    $warning_threshold = intval($_POST['warning_threshold']);
    $dimension_unit = $conn->real_escape_string($_POST['dimension_unit']);
    $barcode_number = $conn->real_escape_string($_POST['barcode_number']);
    $purchasing_price = floatval($_POST['purchasing_price']);
    $grn_number = $conn->real_escape_string($_POST['grn_number']);
    $selling_price = floatval($_POST['selling_price']);
    $description = $conn->real_escape_string($_POST['description']);
    $selling_price_margin = $conn->real_escape_string($_POST['selling_price_margin']);

    $stmt = $conn->prepare("UPDATE products SET product_name=?, supplier_id=?, weight=?, category=?, stock_level=?, dimensions=?, sku_code=?, warning_threshold=?, dimension_unit=?, barcode_number=?, purchasing_price=?, grn_number=?, selling_price=?, description=?, selling_price_margin=? WHERE id=?");
    $stmt->bind_param("ssdsissiissddssi", $product_name, $supplier_id, $weight, $category, $stock_level, $dimensions, $sku_code, $warning_threshold, $dimension_unit, $barcode_number, $purchasing_price, $grn_number, $selling_price, $description, $selling_price_margin, $id);

    if ($stmt->execute()) {
        header("Location: products.php?msg=updated");
        exit();
    } else {
        $error = "Error updating product.";
    }
    $stmt->close();
}

$current_page = 'products.php'; // For sidebar active state
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - Agile Inventory</title>
    <script>
        (function () {
            const t = localStorage.getItem('agile_theme') || 'light';
            const c = localStorage.getItem('agile_color') || '#6d4aff';
            document.documentElement.setAttribute('data-theme', t);
            if (c === 'monochrome') {
                document.documentElement.style.setProperty('--primary-color', t === 'dark' ? '#ffffff' : '#000000');
                document.documentElement.style.setProperty('--primary-hover', t === 'dark' ? '#e5e5e5' : '#333333');
            } else {
                document.documentElement.style.setProperty('--primary-color', c);
                if (c === '#1E5EFF') document.documentElement.style.setProperty('--primary-hover', '#1648c9');
                else if (c === '#336DFF') document.documentElement.style.setProperty('--primary-hover', '#2452c7');
            }
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .edit-form-container {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            max-width: 900px;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-area">
            <div class="topbar">
                <div class="topbar-left">
                    <a href="products.php"
                        style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Back to Products</a>
                    <h1>Edit Product</h1>
                </div>
                <?php include 'includes/topbar_right.php'; ?>
            </div>

            <div class="edit-form-container">
                <?php if (isset($error)): ?>
                    <div style="color: red; margin-bottom: 15px;">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form action="edit_product.php?id=<?= $id ?>" method="POST">
                    <div class="form-grid">

                        <!-- Row 1: Product ID | Product Name | Category -->
                        <div class="form-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
                            <div>
                                <label class="form-label">Product ID</label>
                                <input type="text" class="form-input"
                                    value="<?= htmlspecialchars($product['product_id_str']) ?>" readonly
                                    style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                            </div>
                            <div>
                                <label class="form-label">Product Name</label>
                                <input type="text" name="product_name" class="form-input"
                                    value="<?= htmlspecialchars($product['product_name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Category</label>
                                <select name="category" class="form-input" required>
                                    <option value="" disabled>Select Category</option>
                                    <?php foreach ($categories_list as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= ($product['category'] == $cat) ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Row 2: Warehouse | Stock Level | Warning Threshold -->
                        <div class="form-grid-3"
                            style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px;">
                            <div>
                                <label class="form-label">Warehouse</label>
                                <input type="text" class="form-input"
                                    value="<?= htmlspecialchars($product['warehouse_id'] ?? '') ?>" readonly
                                    style="background-color: var(--bg-light); cursor: not-allowed; color: var(--text-muted);">
                            </div>
                            <div>
                                <label class="form-label">Stock Level</label>
                                <input type="number" name="stock_level" class="form-input"
                                    value="<?= htmlspecialchars($product['stock_level']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Warning Threshold Stock Level</label>
                                <input type="number" name="warning_threshold" class="form-input"
                                    value="<?= htmlspecialchars($product['warning_threshold']) ?>" required>
                            </div>
                        </div>

                        <!-- Row 3: Purchase Price | Selling Price | Selling Price Margin % -->
                        <div class="form-grid-3"
                            style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px;">
                            <div>
                                <label class="form-label">Purchase Price</label>
                                <input type="number" step="0.01" name="purchasing_price" class="form-input"
                                    value="<?= htmlspecialchars($product['purchasing_price']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Selling Price</label>
                                <input type="number" step="0.01" name="selling_price" class="form-input"
                                    value="<?= htmlspecialchars($product['selling_price']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Selling Price Margin %</label>
                                <input type="text" name="selling_price_margin" class="form-input"
                                    value="<?= htmlspecialchars($product['selling_price_margin']) ?>" required>
                            </div>
                        </div>

                        <!-- Row 4: Weight | Dimensions | Dimension Unit -->
                        <div class="form-grid-3"
                            style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:20px;">
                            <div>
                                <label class="form-label">Weight in kg</label>
                                <input type="number" step="0.01" name="weight" class="form-input"
                                    value="<?= htmlspecialchars($product['weight']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Dimensions</label>
                                <input type="text" name="dimensions" class="form-input"
                                    value="<?= htmlspecialchars($product['dimensions']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Dimension Unit</label>
                                <select name="dimension_unit" class="form-input" required>
                                    <option value="cm" <?= $product['dimension_unit'] == 'cm' ? 'selected' : '' ?>>Cm
                                    </option>
                                    <option value="inch" <?= $product['dimension_unit'] == 'inch' ? 'selected' : '' ?>>inch
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Row 5: Image | SKU + Description | Barcode -->
                        <div
                            style="display:grid; grid-template-columns:180px 1fr 1fr; gap:20px; margin-top:20px; align-items:start;">
                            <div>
                                <label class="form-label">Insert Image (400px x 400 px)</label>
                                <div
                                    style="width:150px; height:150px; background-color:var(--bg-light); border:1px dashed var(--border-color); border-radius:8px; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                    <?php if (!empty($product['image_path']) && file_exists($product['image_path'])): ?>
                                        <img src="<?= htmlspecialchars($product['image_path']) ?>" alt="Product"
                                            style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" width="36" height="36" stroke="var(--border-color)"
                                            fill="none">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <line x1="12" y1="8" x2="12" y2="16"></line>
                                            <line x1="8" y1="12" x2="16" y2="12"></line>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:20px;">
                                <div>
                                    <label class="form-label">Sku Code</label>
                                    <input type="text" name="sku_code" class="form-input"
                                        value="<?= htmlspecialchars($product['sku_code']) ?>" required>
                                </div>
                                <div>
                                    <label class="form-label">Product Description</label>
                                    <input type="text" name="description" class="form-input"
                                        value="<?= htmlspecialchars($product['description']) ?>">
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Barcode Number</label>
                                <input type="text" name="barcode_number" class="form-input"
                                    value="<?= htmlspecialchars($product['barcode_number']) ?>" required>
                            </div>
                        </div>

                    </div>

                    <div style="margin-top: 30px; display:flex; gap:12px;">
                        <button type="submit" class="btn btn-primary"
                            style="padding: 10px 24px; border-radius:8px; font-weight:500;">Save Changes</button>
                        <a href="products.php" class="btn btn-outline"
                            style="padding: 10px 24px; border-radius:8px; font-weight:500; text-decoration:none;">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
        <?php include 'includes/footer.php'; ?>