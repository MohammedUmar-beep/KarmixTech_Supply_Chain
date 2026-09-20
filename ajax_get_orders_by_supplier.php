<?php
require_once 'includes/auth_guard.php';

header('Content-Type: application/json');

$supplier_id = intval($_GET['supplier_id'] ?? 0);
if (!$supplier_id) { echo json_encode([]); exit; }

$res = $conn->query("
    SELECT id, purchase_id_str, total_amount, order_date
    FROM purchase_orders
    WHERE supplier_id = $supplier_id AND status IN ('Issued','Received')
    ORDER BY created_at DESC
");

$orders = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }
}
echo json_encode($orders);
