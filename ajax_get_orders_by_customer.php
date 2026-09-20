<?php
require_once 'includes/auth_guard.php';

header('Content-Type: application/json');

// POS direct lookup by order ID string (e.g. SO-0042)
if (!empty($_GET['pos_order_id'])) {
    $order_id_str = $conn->real_escape_string(strtoupper(trim($_GET['pos_order_id'])));
    $res = $conn->query("
        SELECT so.id, so.order_id_str, so.total_amount, so.delivery_charge,
               so.order_date, so.customer_id,
               COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.id
        WHERE so.order_id_str = '$order_id_str'
          AND so.status = 'Completed'
        LIMIT 1
    ");
    if ($res && $res->num_rows > 0) {
        echo json_encode($res->fetch_assoc());
    } else {
        echo json_encode(['error' => 'Order not found or not yet completed.']);
    }
    exit;
}

// Standard customer-based lookup
$customer_id = intval($_GET['customer_id'] ?? 0);
if (!$customer_id) { echo json_encode([]); exit; }

$res = $conn->query("
    SELECT id, order_id_str, total_amount, delivery_charge, order_date
    FROM sales_orders
    WHERE customer_id = $customer_id AND status = 'Completed'
    ORDER BY created_at DESC
");

$orders = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
}
echo json_encode($orders);
