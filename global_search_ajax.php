<?php
session_start();
require_once 'includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$query = trim($_GET['q'] ?? '');
$q = $conn->real_escape_string($query);

if (strlen($q) < 2) {
    echo json_encode([]);
    exit();
}

$results = [];

// ── 1. PRODUCTS ──────────────────────────────────────────────────────
$r = $conn->query("SELECT id, product_id_str, product_name, sku_code, category, stock_level, status
    FROM products
    WHERE product_name LIKE '%$q%' OR sku_code LIKE '%$q%'
       OR barcode_number LIKE '%$q%' OR product_id_str LIKE '%$q%'
       OR category LIKE '%$q%'
    LIMIT 4");
if ($r) while ($row = $r->fetch_assoc()) {
    $badge_color = $row['status'] === 'Active' ? '#10b981' : '#9ca3af';
    $results[] = [
        'type'     => 'Product',
        'color'    => '#6d4aff',
        'title'    => $row['product_name'],
        'subtitle' => 'SKU: ' . $row['sku_code'] . '  ·  Stock: ' . $row['stock_level'] . '  ·  ' . ($row['category'] ?: 'No category'),
        'badge'    => $row['status'],
        'badge_color' => $badge_color,
        'url'      => 'edit_product.php?id=' . $row['id'],
        'icon'     => 'product',
    ];
}

// ── 2. CUSTOMERS ─────────────────────────────────────────────────────
$r = $conn->query("SELECT id, customer_id_str, customer_name, contact_number, email, status
    FROM customers
    WHERE customer_name LIKE '%$q%' OR customer_id_str LIKE '%$q%'
       OR contact_number LIKE '%$q%' OR email LIKE '%$q%'
    LIMIT 4");
if ($r) while ($row = $r->fetch_assoc()) {
    $results[] = [
        'type'     => 'Customer',
        'color'    => '#3b82f6',
        'title'    => $row['customer_name'],
        'subtitle' => $row['email'] . '  ·  ' . $row['contact_number'],
        'badge'    => $row['customer_id_str'],
        'badge_color' => '#6b7280',
        'url'      => 'edit_customer.php?id=' . $row['id'],
        'icon'     => 'customer',
    ];
}

// ── 3. SUPPLIERS ─────────────────────────────────────────────────────
$r = $conn->query("SELECT id, supplier_id_str, supplier_name, contact_person, email, phone, status
    FROM suppliers
    WHERE supplier_name LIKE '%$q%' OR supplier_id_str LIKE '%$q%'
       OR email LIKE '%$q%' OR phone LIKE '%$q%' OR contact_person LIKE '%$q%'
    LIMIT 4");
if ($r) while ($row = $r->fetch_assoc()) {
    $results[] = [
        'type'     => 'Supplier',
        'color'    => '#f59e0b',
        'title'    => $row['supplier_name'],
        'subtitle' => ($row['contact_person'] ? $row['contact_person'] . '  ·  ' : '') . $row['email'],
        'badge'    => $row['supplier_id_str'],
        'badge_color' => '#6b7280',
        'url'      => 'suppliers.php?search=' . urlencode($row['supplier_id_str']) . '&search_col=supplier_id_str',
        'icon'     => 'supplier',
    ];
}

// ── 4. SALES ORDERS ──────────────────────────────────────────────────
$r = $conn->query("SELECT so.id, so.order_id_str, so.order_date, so.total_amount, so.status,
        c.customer_name
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.id
    WHERE so.order_id_str LIKE '%$q%' OR c.customer_name LIKE '%$q%'
    LIMIT 4");
if ($r) while ($row = $r->fetch_assoc()) {
    $scols = ['Completed'=>'#10b981','Pending'=>'#f59e0b','Cancelled'=>'#ef4444','Processing'=>'#3b82f6'];
    $sc = $scols[$row['status']] ?? '#6b7280';
    $results[] = [
        'type'     => 'Sales Order',
        'color'    => '#10b981',
        'title'    => $row['order_id_str'] . ' — ' . ($row['customer_name'] ?? 'Unknown'),
        'subtitle' => date('d M Y', strtotime($row['order_date'])) . '  ·  '+\$currency['symbol']+'' . number_format($row['total_amount'], 2),
        'badge'    => $row['status'],
        'badge_color' => $sc,
        'url'      => 'view_sales_order.php?id=' . $row['id'],
        'icon'     => 'order',
    ];
}

// ── 5. PURCHASE ORDERS ───────────────────────────────────────────────
$r = $conn->query("SELECT po.id, po.purchase_id_str, po.order_date, po.total_amount, po.status,
        s.supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.purchase_id_str LIKE '%$q%' OR s.supplier_name LIKE '%$q%'
    LIMIT 4");
if ($r) while ($row = $r->fetch_assoc()) {
    $pcols = ['Received'=>'#10b981','Issued'=>'#3b82f6','Draft'=>'#9ca3af','Cancelled'=>'#ef4444'];
    $pc = $pcols[$row['status']] ?? '#6b7280';
    $results[] = [
        'type'     => 'Purchase Order',
        'color'    => '#f59e0b',
        'title'    => $row['purchase_id_str'] . ' — ' . ($row['supplier_name'] ?? 'Unknown'),
        'subtitle' => date('d M Y', strtotime($row['order_date'])) . '  ·  '+\$currency['symbol']+'' . number_format($row['total_amount'], 2),
        'badge'    => $row['status'],
        'badge_color' => $pc,
        'url'      => 'view_purchase_order.php?id=' . $row['id'],
        'icon'     => 'purchase',
    ];
}

// ── 6. INVOICES ──────────────────────────────────────────────────────
$r = $conn->query("SELECT i.id, i.invoice_number, i.invoice_date, i.amount, i.status,
        c.customer_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.invoice_number LIKE '%$q%' OR c.customer_name LIKE '%$q%'
    LIMIT 4");
if ($r) while ($row = $r->fetch_assoc()) {
    $icols = ['Paid'=>'#10b981','Partial'=>'#f59e0b','Overdue'=>'#ef4444','Draft'=>'#9ca3af','Sent'=>'#3b82f6'];
    $ic = $icols[$row['status']] ?? '#6b7280';
    $results[] = [
        'type'     => 'Invoice',
        'color'    => '#8b5cf6',
        'title'    => $row['invoice_number'] . ' — ' . ($row['customer_name'] ?? 'Unknown'),
        'subtitle' => date('d M Y', strtotime($row['invoice_date'])) . '  ·  '+\$currency['symbol']+'' . number_format($row['amount'], 2),
        'badge'    => $row['status'],
        'badge_color' => $ic,
        'url'      => 'view_invoice.php?id=' . $row['id'],
        'icon'     => 'invoice',
    ];
}

// ── 7. SALES RETURNS ─────────────────────────────────────────────────
$r = $conn->query("SELECT sr.id, sr.return_id_str, sr.return_date, sr.total_refund_amount, sr.status,
        c.customer_name
    FROM sales_returns sr
    LEFT JOIN customers c ON sr.customer_id = c.id
    WHERE sr.return_id_str LIKE '%$q%' OR c.customer_name LIKE '%$q%' OR sr.reason LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $rcols = ['Processed'=>'#10b981','Pending'=>'#f59e0b','Rejected'=>'#ef4444'];
    $rc = $rcols[$row['status']] ?? '#6b7280';
    $results[] = [
        'type'     => 'Sales Return',
        'color'    => '#ef4444',
        'title'    => $row['return_id_str'] . ' — ' . ($row['customer_name'] ?? 'Unknown'),
        'subtitle' => date('d M Y', strtotime($row['return_date'])) . '  ·  '+\$currency['symbol']+'' . number_format($row['total_refund_amount'], 2),
        'badge'    => $row['status'],
        'badge_color' => $rc,
        'url'      => 'sales_returns.php',
        'icon'     => 'return',
    ];
}

// ── 8. PURCHASE RETURNS ──────────────────────────────────────────────
$r = $conn->query("SELECT pr.id, pr.return_number_str, pr.return_date, pr.amount, pr.status,
        s.supplier_name
    FROM purchase_returns pr
    LEFT JOIN suppliers s ON pr.supplier_id = s.id
    WHERE pr.return_number_str LIKE '%$q%' OR s.supplier_name LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $results[] = [
        'type'     => 'Purchase Return',
        'color'    => '#f59e0b',
        'title'    => $row['return_number_str'] . ' — ' . ($row['supplier_name'] ?? 'Unknown'),
        'subtitle' => date('d M Y', strtotime($row['return_date'])) . '  ·  '+\$currency['symbol']+'' . number_format($row['amount'], 2),
        'badge'    => $row['status'],
        'badge_color' => '#6b7280',
        'url'      => 'purchase_returns.php',
        'icon'     => 'return',
    ];
}

// ── 9. EMPLOYEES ─────────────────────────────────────────────────────
$r = $conn->query("SELECT id, full_name, employee_id_str, department_id, email, phone, status
    FROM employees
    WHERE full_name LIKE '%$q%' OR employee_id_str LIKE '%$q%'
       OR email LIKE '%$q%' OR department_id LIKE '%$q%' OR phone LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $active_statuses = ['On Duty', 'On Break'];
    $badge_color = in_array($row['status'], $active_statuses) ? '#10b981' : '#9ca3af';
    $results[] = [
        'type'     => 'Employee',
        'color'    => '#06b6d4',
        'title'    => $row['full_name'],
        'subtitle' => ($row['department_id'] ?: 'No department') . '  ·  ' . $row['email'],
        'badge'    => $row['status'],
        'badge_color' => $badge_color,
        'url'      => 'employees.php',
        'icon'     => 'employee',
    ];
}

// ── 10. WAREHOUSES ────────────────────────────────────────────────────
$r = $conn->query("SELECT id, warehouse_name, warehouse_id_str, state, status
    FROM warehouses
    WHERE warehouse_name LIKE '%$q%' OR warehouse_id_str LIKE '%$q%'
       OR state LIKE '%$q%' OR address LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $badge_color = $row['status'] === 'Active' ? '#10b981' : '#9ca3af';
    $results[] = [
        'type'     => 'Warehouse',
        'color'    => '#0ea5e9',
        'title'    => $row['warehouse_name'],
        'subtitle' => $row['warehouse_id_str'] . '  ·  ' . $row['state'],
        'badge'    => $row['status'],
        'badge_color' => $badge_color,
        'url'      => 'view_warehouse.php?id=' . $row['id'],
        'icon'     => 'warehouse',
    ];
}

// ── 11. CREDIT NOTES ─────────────────────────────────────────────────
$r = $conn->query("SELECT cn.id, cn.credit_note_str, cn.issue_date, cn.credit_amount, cn.status,
        c.customer_name
    FROM credit_notes cn
    LEFT JOIN customers c ON cn.customer_id = c.id
    WHERE cn.credit_note_str LIKE '%$q%' OR c.customer_name LIKE '%$q%' OR cn.reason LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $ccols = ['Open'=>'#f59e0b','Settled'=>'#10b981','Closed'=>'#9ca3af'];
    $cc = $ccols[$row['status']] ?? '#6b7280';
    $results[] = [
        'type'     => 'Credit Note',
        'color'    => '#8b5cf6',
        'title'    => $row['credit_note_str'] . ' — ' . ($row['customer_name'] ?? 'Unknown'),
        'subtitle' => date('d M Y', strtotime($row['issue_date'])) . '  ·  '+\$currency['symbol']+'' . number_format($row['credit_amount'], 2),
        'badge'    => $row['status'],
        'badge_color' => $cc,
        'url'      => 'credit_notes.php',
        'icon'     => 'credit',
    ];
}

// ── 12. COUPONS ──────────────────────────────────────────────────────
$r = $conn->query("SELECT id, code, name, type, value, status
    FROM coupons
    WHERE code LIKE '%$q%' OR name LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $badge_color = $row['status'] === 'Active' ? '#10b981' : '#9ca3af';
    $val_display = stripos($row['type'], 'percent') !== false ? $row['value'] . '%' : ''+\$currency['symbol']+'' . $row['value'];
    $results[] = [
        'type'     => 'Coupon',
        'color'    => '#ec4899',
        'title'    => $row['code'] . ' — ' . $row['name'],
        'subtitle' => $row['type'] . '  ·  ' . $val_display . ' discount',
        'badge'    => $row['status'],
        'badge_color' => $badge_color,
        'url'      => 'coupons.php',
        'icon'     => 'coupon',
    ];
}

// ── 13. CATEGORIES ────────────────────────────────────────────────────
$r = $conn->query("SELECT id, category_id_str, category_name, description
    FROM categories
    WHERE category_name LIKE '%$q%' OR category_id_str LIKE '%$q%' OR description LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $results[] = [
        'type'     => 'Category',
        'color'    => '#84cc16',
        'title'    => $row['category_name'],
        'subtitle' => $row['category_id_str'] . ($row['description'] ? '  ·  ' . substr($row['description'], 0, 50) : ''),
        'badge'    => null,
        'badge_color' => null,
        'url'      => 'categories.php',
        'icon'     => 'category',
    ];
}

// ── 14. SHIPMENTS ─────────────────────────────────────────────────────
$r = $conn->query("SELECT ps.id, ps.shipment_number_str, ps.tracking_number, ps.status, ps.shipment_date,
        s.supplier_name
    FROM purchase_shipments ps
    LEFT JOIN purchase_orders po ON ps.purchase_order_id = po.id
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE ps.shipment_number_str LIKE '%$q%' OR ps.tracking_number LIKE '%$q%'
       OR s.supplier_name LIKE '%$q%'
    LIMIT 3");
if ($r) while ($row = $r->fetch_assoc()) {
    $shcols = ['Delivered'=>'#10b981','In Transit'=>'#3b82f6','Pending'=>'#f59e0b'];
    $shc = $shcols[$row['status']] ?? '#6b7280';
    $results[] = [
        'type'     => 'Shipment',
        'color'    => '#0ea5e9',
        'title'    => $row['shipment_number_str'] . ($row['supplier_name'] ? ' — ' . $row['supplier_name'] : ''),
        'subtitle' => 'Tracking: ' . ($row['tracking_number'] ?: '—') . '  ·  ' . date('d M Y', strtotime($row['shipment_date'])),
        'badge'    => $row['status'],
        'badge_color' => $shc,
        'url'      => 'shipment.php',
        'icon'     => 'shipment',
    ];
}

echo json_encode(array_values($results));
