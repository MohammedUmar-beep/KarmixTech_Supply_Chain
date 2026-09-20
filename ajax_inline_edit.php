<?php
require_once 'includes/auth_guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$table  = $_POST['table']  ?? '';
$field  = $_POST['field']  ?? '';
$id     = isset($_POST['id']) ? intval($_POST['id']) : 0;
$value  = $_POST['value']  ?? '';

$user_role = strtolower($_SESSION['role'] ?? 'staff');
$is_admin   = $user_role === 'admin';
$is_manager = in_array($user_role, ['admin', 'manager']);

// Fields restricted to admin/manager only
$restricted_fields = [
    'selling_price', 'purchasing_price', 'discount_value', 'role',
];

// Whitelist of allowed tables and their editable fields
$allowed = [
    'products'       => ['stock_level', 'selling_price', 'purchasing_price', 'warning_threshold', 'status'],
    'customers'      => ['status', 'contact_number', 'email'],
    'suppliers'      => ['status', 'contact_number', 'email'],
    'invoices'       => ['status', 'due_date'],
    'sales_orders'   => ['status'],
    'purchase_orders'=> ['status', 'expected_date'],
    'employees'      => ['status', 'role'],
    'warehouses'     => ['status'],
    'categories'     => ['status'],
    'coupons'        => ['status', 'discount_value'],
    'stock'          => ['stock_level', 'status'],
    'shipment'       => ['status'],
    'stock_transfers'=> ['status'],
];

if (!array_key_exists($table, $allowed)) {
    echo json_encode(['error' => 'Table not editable']);
    exit;
}

if (!in_array($field, $allowed[$table])) {
    echo json_encode(['error' => 'Field not editable']);
    exit;
}

if ($id <= 0) {
    echo json_encode(['error' => 'Invalid record ID']);
    exit;
}

// Enforce role restrictions on sensitive fields
if (in_array($field, $restricted_fields) && !$is_manager) {
    echo json_encode(['error' => 'Insufficient permissions to edit this field']);
    exit;
}

// employees.role can only be changed by admin
if ($table === 'employees' && $field === 'role' && !$is_admin) {
    echo json_encode(['error' => 'Only administrators can change employee roles']);
    exit;
}

$safe_value = $conn->real_escape_string($value);
$safe_table = $conn->real_escape_string($table);
$safe_field = $conn->real_escape_string($field);

$sql = "UPDATE `$safe_table` SET `$safe_field` = '$safe_value' WHERE id = $id";
$result = $conn->query($sql);

if ($result) {
    log_activity($conn, 'update', $table, (string)$id, "Inline edit: $field = $value");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Database update failed: ' . $conn->error]);
}
?>
