<?php
require_once 'includes/auth_guard.php';

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 1. Find all products that are low on stock
$low_stock_query = "SELECT p.*, s.id as actual_supplier_id 
                    FROM products p 
                    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id_str
                    WHERE p.stock_level <= p.warning_threshold AND p.status = 'Available'";
$low_stock_res = $conn->query($low_stock_query);

if (!$low_stock_res || $low_stock_res->num_rows === 0) {
    header("Location: stock.php?msg=No low stock items found. All products are above their warning threshold.");
    exit();
}

// 2. Group products by supplier
$supplier_orders = [];
while ($product = $low_stock_res->fetch_assoc()) {
    $sup_id = $product['actual_supplier_id'] ?? 0; // Use integer supplier ID for purchase_orders relation
    if (!$sup_id)
        continue; // Skip if supplier not found

    if (!isset($supplier_orders[$sup_id])) {
        $supplier_orders[$sup_id] = [];
    }

    // Calculate suggested restock qty: enough to reach 2x warning threshold
    $suggested_qty = max(10, ($product['warning_threshold'] * 2) - $product['stock_level']);

    $supplier_orders[$sup_id][] = [
        'product_id' => $product['id'],
        'qty' => $suggested_qty,
        'price' => $product['purchasing_price']
    ];
}

if (empty($supplier_orders)) {
    header("Location: stock.php?msg=Low stock items found, but none have a linked supplier. Please assign suppliers to products first.");
    exit();
}

$pos_created = 0;

// 3. Create a Draft PO for each supplier
foreach ($supplier_orders as $sup_id => $items) {
    // Generate next PO ID String
    $po_str = 'PO-TMP-' . uniqid();

    // Calculate Grand Total
    $grand_total = 0;
    foreach ($items as $item) {
        $grand_total += ($item['qty'] * $item['price']);
    }

    $expected_date = date('Y-m-d', strtotime('+14 days')); // default to 2 weeks

    $stmt = $conn->prepare("INSERT INTO purchase_orders (purchase_id_str, supplier_id, order_date, expected_date, status, total_amount) VALUES (?, ?, CURDATE(), ?, 'Draft', ?)");
    $stmt->bind_param("sisd", $po_str, $sup_id, $expected_date, $grand_total);

    if ($stmt->execute()) {
        $new_po_id = $conn->insert_id;
        $po_str = generate_ref('PO', $new_po_id);
        $conn->query("UPDATE purchase_orders SET purchase_id_str = '$po_str' WHERE id = $new_po_id");
        $pos_created++;

        // Insert Items
        $item_stmt = $conn->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");

        foreach ($items as $item) {
            $t_price = $item['qty'] * $item['price'];
            $item_stmt->bind_param("iiidd", $new_po_id, $item['product_id'], $item['qty'], $item['price'], $t_price);
            $item_stmt->execute();
        }
        $item_stmt->close();

        log_activity($conn, 'System', 'Purchase Order', $po_str, "Auto-generated Draft PO due to low stock alerts. Total: $$grand_total.");

        // Create an alert notification
        $conn->query("INSERT INTO notifications (message, type, link) VALUES ('New Draft PO ($po_str) generated for low stock items.', 'info', 'purchase_orders.php')");
    }
    $stmt->close();
}

$conn->query("INSERT INTO notifications (message, type, link) VALUES ('System auto-generated $pos_created draft purchase orders.', 'success', 'purchase_orders.php')");

header("Location: purchase_orders.php?msg=Generated $pos_created draft POs from low stock alerts");
exit();
?>