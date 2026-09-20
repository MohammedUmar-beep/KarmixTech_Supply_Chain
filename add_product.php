<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Basic sanitization
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
    $grn_number = ''; // GRN field was replaced by Product ID in form
    $selling_price = floatval($_POST['selling_price']);
    $description = $conn->real_escape_string($_POST['description']);
    $selling_price_margin = $conn->real_escape_string($_POST['selling_price_margin']);
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : NULL;

    // Handle Image Upload
    $image_path = NULL;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['product_image']['name'];
        $filetype = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array(strtolower($filetype), $allowed)) {
            $new_filename = uniqid('prod_') . '.' . $filetype;
            $upload_dir = 'uploads/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $upload_dir . $new_filename)) {
                $image_path = $upload_dir . $new_filename;
            }
        }
    }

    // Get generated product ID
    $product_id_str = $conn->real_escape_string($_POST['product_id_str']);

    $stmt = $conn->prepare("INSERT INTO products (product_name, product_id_str, warehouse_id, supplier_id, category, sku_code, barcode_number, weight, stock_level, warning_threshold, purchasing_price, selling_price, selling_price_margin, dimensions, dimension_unit, grn_number, description, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssissssdiiddssssss", $product_name, $product_id_str, $warehouse_id, $supplier_id, $category, $sku_code, $barcode_number, $weight, $stock_level, $warning_threshold, $purchasing_price, $selling_price, $selling_price_margin, $dimensions, $dimension_unit, $grn_number, $description, $image_path);

    if ($stmt->execute()) {
        log_activity($conn, 'Create', 'Product', $product_id_str, "Added product: $product_name");
        header("Location: products.php?msg=added");
    } else {
        header("Location: products.php?msg=error_1");
    }
} else {
    header("Location: products.php");
}
?>