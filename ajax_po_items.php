<?php
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(); }

$po_id = intval($_GET['po_id'] ?? 0);
if (!$po_id) { echo json_encode([]); exit(); }

$items = [];
$res = $conn->query("SELECT poi.id, poi.product_id, poi.quantity, poi.unit_price,
    COALESCE(poi.qty_received,0) AS qty_received,
    COALESCE(poi.qty_pending, poi.quantity - COALESCE(poi.qty_received,0)) AS qty_pending,
    p.product_name, p.product_id_str, p.sku_code
    FROM purchase_order_items poi
    JOIN products p ON p.id = poi.product_id
    WHERE poi.purchase_order_id = $po_id");
while ($r = $res->fetch_assoc()) $items[] = $r;

header('Content-Type: application/json');
echo json_encode($items);
