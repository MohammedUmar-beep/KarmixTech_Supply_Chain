<?php
require_once 'includes/auth_guard.php';

$action = $_POST['action'] ?? '';
$module = $_POST['module'] ?? '';
$ids    = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];

// Module → table map
$module_table_map = [
    'products'         => 'products',
    'customers'        => 'customers',
    'suppliers'        => 'suppliers',
    'invoices'         => 'invoices',
    'sales_orders'     => 'sales_orders',
    'purchase_orders'  => 'purchase_orders',
    'employees'        => 'employees',
    'warehouses'       => 'warehouses',
    'categories'       => 'categories',
    'coupons'          => 'coupons',
    'stock_transfers'  => 'stock_transfers',
    'shipment'         => 'shipment',
    'payments_made'    => 'payments_made',
    'payments_received'=> 'payments_received',
    'credit_notes'     => 'credit_notes',
    'supplier_credits' => 'supplier_credits',
    'sales_returns'    => 'sales_returns',
    'purchase_returns' => 'purchase_returns',
];

if (!array_key_exists($module, $module_table_map) || empty($ids)) {
    header('Location: ' . ($module ? $module . '.php?msg=error_invalid_data' : 'dashboard.php'));
    exit;
}

$table = $module_table_map[$module];
$safe_table = $conn->real_escape_string($table);
$ids_str = implode(',', $ids);

if ($action === 'delete') {
    $user_role = strtolower($_SESSION['role'] ?? 'staff');
    if (!in_array($user_role, ['admin', 'manager'])) {
        header('Location: ' . $module . '.php?msg=error_unauthorized');
        exit;
    }
    $conn->query("DELETE FROM `$safe_table` WHERE id IN ($ids_str)");
    log_activity($conn, 'delete', $table, $ids_str, 'Bulk delete: ' . count($ids) . ' records');
    header('Location: ' . $module . '.php?msg=deleted');
    exit;
}

if ($action === 'export_csv') {
    $result = $conn->query("SELECT * FROM `$safe_table` WHERE id IN ($ids_str) ORDER BY id DESC");
    if (!$result) {
        header('Location: ' . $module . '.php?msg=error_1');
        exit;
    }

    $filename = 'Export_' . $module . '_selected_' . date('Y-m-d_H-i') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    $first = true;
    while ($row = $result->fetch_assoc()) {
        if ($first) { fputcsv($output, array_keys($row)); $first = false; }
        fputcsv($output, array_values($row));
    }
    fclose($output);
    exit;
}

header('Location: ' . $module . '.php');
exit;
?>
