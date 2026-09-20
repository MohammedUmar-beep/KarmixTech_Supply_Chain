<?php
require_once 'includes/auth_guard.php';

header('Content-Type: application/json');

$order_id = intval($_GET['order_id'] ?? 0);
if (!$order_id) { echo json_encode(['items' => [], 'delivery_charge' => 0]); exit; }

// Get delivery charge from order
$ord_res = $conn->query("SELECT delivery_charge FROM sales_orders WHERE id = $order_id LIMIT 1");
$delivery_charge = 0;
if ($ord_res && $ord_res->num_rows > 0) {
    $delivery_charge = floatval($ord_res->fetch_assoc()['delivery_charge']);
}

// Get line items with product names
$res = $conn->query("
    SELECT soi.id, soi.product_id, p.product_name, p.product_id_str,
           soi.quantity, soi.unit_price, soi.subtotal
    FROM sales_order_items soi
    LEFT JOIN products p ON soi.product_id = p.id
    WHERE soi.sales_order_id = $order_id
");

$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
}

echo json_encode(['items' => $items, 'delivery_charge' => $delivery_charge]);
