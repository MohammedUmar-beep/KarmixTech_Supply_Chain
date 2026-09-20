<?php
require_once 'includes/auth_guard.php';

header('Content-Type: application/json');

$table = $_GET['table'] ?? '';
$id    = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Whitelist of allowed tables
$allowed_tables = [
    'products', 'customers', 'suppliers', 'invoices',
    'sales_orders', 'purchase_orders', 'employees',
    'warehouses', 'categories', 'coupons', 'stock',
    'shipment', 'stock_transfers', 'payments_received',
    'payments_made', 'credit_notes', 'supplier_credits',
    'sales_returns', 'purchase_returns',
];

if (!in_array($table, $allowed_tables)) {
    echo json_encode(['error' => 'Invalid table']);
    exit;
}

if ($id <= 0) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$safe_table = $conn->real_escape_string($table);
$result = $conn->query("SELECT * FROM `$safe_table` WHERE id = $id LIMIT 1");

if (!$result || $result->num_rows === 0) {
    echo json_encode(['error' => 'Record not found']);
    exit;
}

$row = $result->fetch_assoc();

// Remove image paths and internal IDs from the detail view for cleanliness
$skip_fields = ['image_path', 'password', 'remember_token'];
foreach ($skip_fields as $f) {
    unset($row[$f]);
}

echo json_encode(['record' => $row]);
?>
