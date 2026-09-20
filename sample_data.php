<?php
/**
 * ============================================================
 *  SAMPLE DATA MANAGER — Agile Inventory System
 *  Permanent developer utility. DO NOT DELETE.
 * ============================================================
 *  Actions (via GET):
 *    ?action=seed   → Delete existing sample data, then reseed
 *    ?action=delete → Delete sample data only (no reseed)
 *
 *  All sample records use the prefix "SAMPLE-" in their ID
 *  strings so this script can cleanly target ONLY sample data
 *  without touching any real records.
 * ============================================================
 */

session_start();
require_once 'includes/db.php';

/* ─── Helpers ──────────────────────────────────────────────── */

function sd_exec($conn, $sql) {
    if (!$conn->query($sql)) {
        throw new RuntimeException("Query failed: " . $conn->error . "\nSQL: " . $sql);
    }
}

function sd_prepare($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Prepare failed: " . $conn->error);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException("Execute failed: " . $stmt->error);
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

/* ─── Delete all SAMPLE- records (reverse dependency order) ─ */

function delete_sample_data($conn) {
    $log = [];

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Credit Notes
    $conn->query("DELETE FROM credit_notes WHERE credit_note_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Credit Notes', 'count' => $conn->affected_rows];

    // Sales Return Items & Sales Returns
    $sr_ids_res = $conn->query("SELECT id FROM sales_returns WHERE return_id_str LIKE 'SAMPLE-%'");
    $sr_ids = [];
    while ($r = $sr_ids_res->fetch_assoc()) $sr_ids[] = $r['id'];
    if ($sr_ids) {
        $conn->query("DELETE FROM sales_return_items WHERE sales_return_id IN (" . implode(',', $sr_ids) . ")");
    }
    $conn->query("DELETE FROM sales_returns WHERE return_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Sales Returns', 'count' => $conn->affected_rows];

    // Purchase Return Items & Purchase Returns
    $pr_ids_res = $conn->query("SELECT id FROM purchase_returns WHERE return_number_str LIKE 'SAMPLE-%'");
    $pr_ids = [];
    while ($r = $pr_ids_res->fetch_assoc()) $pr_ids[] = $r['id'];
    if ($pr_ids) {
        $conn->query("DELETE FROM purchase_return_items WHERE purchase_return_id IN (" . implode(',', $pr_ids) . ")");
    }
    $conn->query("DELETE FROM purchase_returns WHERE return_number_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Purchase Returns', 'count' => $conn->affected_rows];

    // Invoices
    $conn->query("DELETE FROM invoices WHERE invoice_number LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Invoices', 'count' => $conn->affected_rows];

    // Sales Order Items & Sales Orders
    $so_ids_res = $conn->query("SELECT id FROM sales_orders WHERE order_id_str LIKE 'SAMPLE-%'");
    $so_ids = [];
    while ($r = $so_ids_res->fetch_assoc()) $so_ids[] = $r['id'];
    if ($so_ids) {
        $conn->query("DELETE FROM sales_order_items WHERE sales_order_id IN (" . implode(',', $so_ids) . ")");
    }
    $conn->query("DELETE FROM sales_orders WHERE order_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Sales Orders', 'count' => $conn->affected_rows];

    // Purchase Order Items & Purchase Orders
    $po_ids_res = $conn->query("SELECT id FROM purchase_orders WHERE purchase_id_str LIKE 'SAMPLE-%'");
    $po_ids = [];
    while ($r = $po_ids_res->fetch_assoc()) $po_ids[] = $r['id'];
    if ($po_ids) {
        $conn->query("DELETE FROM purchase_order_items WHERE purchase_order_id IN (" . implode(',', $po_ids) . ")");
    }
    $conn->query("DELETE FROM purchase_orders WHERE purchase_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Purchase Orders', 'count' => $conn->affected_rows];

    // Bundle items & bundle sales linked to sample bundles
    $bids = $conn->query("SELECT id FROM bundles WHERE bundle_id_str LIKE 'SAMPLE-%'");
    $bundle_ids = [];
    while ($r = $bids->fetch_assoc()) $bundle_ids[] = $r['id'];
    if ($bundle_ids) {
        $in = implode(',', $bundle_ids);
        $conn->query("DELETE FROM bundle_items WHERE bundle_id IN ($in)");
        $conn->query("DELETE FROM bundle_sales WHERE bundle_id IN ($in)");
    }

    // Bundles
    $res = $conn->query("DELETE FROM bundles WHERE bundle_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Bundles',    'count' => $conn->affected_rows];

    // Coupons
    $conn->query("DELETE FROM coupons WHERE code LIKE 'SAMPLE%'");
    $log[] = ['entity' => 'Coupons',    'count' => $conn->affected_rows];

    // Products — sales_order_items, purchase_order_items reference products
    $pids = $conn->query("SELECT id FROM products WHERE product_id_str LIKE 'SAMPLE-%'");
    $product_ids = [];
    while ($r = $pids->fetch_assoc()) $product_ids[] = $r['id'];
    if ($product_ids) {
        $in = implode(',', $product_ids);
        $conn->query("DELETE FROM sales_order_items     WHERE product_id IN ($in)");
        $conn->query("DELETE FROM purchase_order_items  WHERE product_id IN ($in)");
        $conn->query("DELETE FROM product_serials       WHERE product_id IN ($in)");
    }
    $conn->query("DELETE FROM products WHERE product_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Products',   'count' => $conn->affected_rows];

    // Categories
    $conn->query("DELETE FROM categories WHERE category_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Categories', 'count' => $conn->affected_rows];

    // Suppliers
    $conn->query("DELETE FROM suppliers WHERE supplier_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Suppliers',  'count' => $conn->affected_rows];

    // Customers
    $cids = $conn->query("SELECT id FROM customers WHERE customer_id_str LIKE 'SAMPLE-%'");
    $customer_ids = [];
    while ($r = $cids->fetch_assoc()) $customer_ids[] = $r['id'];
    if ($customer_ids) {
        $in = implode(',', $customer_ids);
        $conn->query("DELETE FROM loyalty_points_ledger WHERE customer_id IN ($in)");
        $conn->query("DELETE FROM customer_portal_users WHERE customer_id IN ($in)");
    }
    $conn->query("DELETE FROM customers WHERE customer_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Customers',  'count' => $conn->affected_rows];

    // Employees
    $conn->query("DELETE FROM employees WHERE employee_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Employees',  'count' => $conn->affected_rows];

    // Warehouses
    $conn->query("DELETE FROM warehouses WHERE warehouse_id_str LIKE 'SAMPLE-%'");
    $log[] = ['entity' => 'Warehouses', 'count' => $conn->affected_rows];

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    return $log;
}

/* ─── Seed all sample data ──────────────────────────────────── */

function seed_sample_data($conn) {
    $log = [];

    // ── 0. TAX RULES ───────────────────────────────────────────
    $conn->query("INSERT IGNORE INTO tax_rules (tax_name, rate_percent, is_active) VALUES ('GST 18%', 18.00, 1)");
    $log[] = ['entity' => 'Tax Rules', 'count' => 1];

    // ── 1. WAREHOUSES ──────────────────────────────────────────
    $wh1_id = sd_prepare($conn,
        "INSERT INTO warehouses (warehouse_name, warehouse_id_str, contact_number, email, state, pincode, address, capacity, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssssssss",
        [
            'Mumbai Central Warehouse', 'SAMPLE-WH-0001',
            '+91 98200 11111', 'mumbai.wh@sampleco.in',
            'Maharashtra', '400001',
            '42, Dharavi Industrial Estate, Sion, Mumbai', 5000,
            'Active'
        ]
    );

    $wh2_id = sd_prepare($conn,
        "INSERT INTO warehouses (warehouse_name, warehouse_id_str, contact_number, email, state, pincode, address, capacity, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssssssss",
        [
            'Pune East Warehouse', 'SAMPLE-WH-0002',
            '+91 98200 22222', 'pune.wh@sampleco.in',
            'Maharashtra', '411014',
            '17, Hadapsar Industrial Zone, Pune', 3000,
            'Active'
        ]
    );

    $log[] = ['entity' => 'Warehouses', 'count' => 2];


    // ── 2. CATEGORIES ──────────────────────────────────────────
    $cat_electronics = sd_prepare($conn,
        "INSERT INTO categories (category_id_str, category_name, description, status, tax_rate)
         VALUES (?, ?, ?, ?, ?)",
        "ssssd",
        ['SAMPLE-CAT-0001', 'Electronics', 'Consumer electronics and accessories', 'Active', 18.00]
    );

    $cat_clothing = sd_prepare($conn,
        "INSERT INTO categories (category_id_str, category_name, description, status, tax_rate)
         VALUES (?, ?, ?, ?, ?)",
        "ssssd",
        ['SAMPLE-CAT-0002', 'Clothing', 'Apparel and fashion items for all ages', 'Active', 5.00]
    );

    $cat_home = sd_prepare($conn,
        "INSERT INTO categories (category_id_str, category_name, description, status, tax_rate)
         VALUES (?, ?, ?, ?, ?)",
        "ssssd",
        ['SAMPLE-CAT-0003', 'Home & Kitchen', 'Household and kitchen essentials', 'Active', 12.00]
    );

    $log[] = ['entity' => 'Categories', 'count' => 3];


    // ── 3. SUPPLIERS ───────────────────────────────────────────
    $sup1_id = sd_prepare($conn,
        "INSERT INTO suppliers (supplier_id_str, supplier_name, contact_person, email, phone, status, address)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "sssssss",
        [
            'SAMPLE-SUP-0001', 'TechSource India Pvt Ltd', 'Vikram Nair',
            'vikram@techsource-sample.in', '+91 99100 33333',
            'Active', '88, SEEPZ, Andheri East, Mumbai - 400096'
        ]
    );

    $sup2_id = sd_prepare($conn,
        "INSERT INTO suppliers (supplier_id_str, supplier_name, contact_person, email, phone, status, address)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "sssssss",
        [
            'SAMPLE-SUP-0002', 'FashionHub Traders', 'Meena Kapoor',
            'meena@fashionhub-sample.in', '+91 99100 44444',
            'Active', '23, Linking Road, Bandra West, Mumbai - 400050'
        ]
    );

    $log[] = ['entity' => 'Suppliers', 'count' => 2];


    // ── 4. PRODUCTS (10 total) ──────────────────────────────────
    // Helper: margin string
    $margin = function($buy, $sell) {
        return round((($sell - $buy) / $sell) * 100, 2) . '%';
    };

    $products = [
        // ── Electronics (4) linked to supplier 1, warehouse 1
        [
            'SAMPLE-PRD-0001', 'Wireless Bluetooth Speaker',
            $wh1_id, 'SAMPLE-SUP-0001', 'Electronics',
            'SKU-BT-SPK-001', 'BAR-0000001', 1.20, 75, 10,
            1200.00, 1899.00, $margin(1200, 1899), '25x15x12', 'cm',
            'GRN-001', 'Portable Bluetooth 5.0 speaker with 12-hour battery life and IPX5 waterproofing.'
        ],
        [
            'SAMPLE-PRD-0002', 'USB-C Fast Charger 65W',
            $wh1_id, 'SAMPLE-SUP-0001', 'Electronics',
            'SKU-USB-CHG-002', 'BAR-0000002', 0.18, 120, 15,
            450.00, 799.00, $margin(450, 799), '8x4x4', 'cm',
            'GRN-001', '65W GaN USB-C fast charger compatible with laptops, phones, and tablets.'
        ],
        [
            'SAMPLE-PRD-0003', 'Noise Cancelling Earbuds',
            $wh1_id, 'SAMPLE-SUP-0001', 'Electronics',
            'SKU-NC-ERB-003', 'BAR-0000003', 0.05, 60, 8,
            1800.00, 2999.00, $margin(1800, 2999), '6x5x3', 'cm',
            'GRN-002', 'True wireless earbuds with active noise cancellation and 30-hour total playback.'
        ],
        [
            'SAMPLE-PRD-0004', 'Portable Power Bank 20000mAh',
            $wh1_id, 'SAMPLE-SUP-0001', 'Electronics',
            'SKU-PWR-BNK-004', 'BAR-0000004', 0.42, 90, 10,
            900.00, 1499.00, $margin(900, 1499), '16x7x2', 'cm',
            'GRN-002', '20000mAh slim power bank with dual USB-A and one USB-C output.'
        ],
        // ── Clothing (3) linked to supplier 2, warehouse 2
        [
            'SAMPLE-PRD-0005', "Men's Cotton Crew T-Shirt",
            $wh2_id, 'SAMPLE-SUP-0002', 'Clothing',
            'SKU-CLT-TSH-005', 'BAR-0000005', 0.22, 200, 20,
            120.00, 249.00, $margin(120, 249), 'M/L/XL', 'size',
            'GRN-003', '100% combed cotton unisex crew-neck T-shirt, pre-shrunk, available in 6 colours.'
        ],
        [
            'SAMPLE-PRD-0006', "Women's Denim Jacket",
            $wh2_id, 'SAMPLE-SUP-0002', 'Clothing',
            'SKU-CLT-DJK-006', 'BAR-0000006', 0.85, 50, 8,
            700.00, 1299.00, $margin(700, 1299), 'S/M/L/XL', 'size',
            'GRN-003', 'Classic stonewash denim jacket with button front and two chest pockets.'
        ],
        [
            'SAMPLE-PRD-0007', 'Unisex Sports Hoodie',
            $wh2_id, 'SAMPLE-SUP-0002', 'Clothing',
            'SKU-CLT-HDY-007', 'BAR-0000007', 0.55, 80, 10,
            350.00, 649.00, $margin(350, 649), 'S/M/L/XL/XXL', 'size',
            'GRN-004', 'Fleece-lined pullover hoodie with kangaroo pocket, moisture-wicking fabric.'
        ],
        // ── Home & Kitchen (3) linked to supplier 2, warehouse 2
        [
            'SAMPLE-PRD-0008', 'Stainless Steel Water Bottle 1L',
            $wh2_id, 'SAMPLE-SUP-0002', 'Home & Kitchen',
            'SKU-HK-WBT-008', 'BAR-0000008', 0.30, 150, 15,
            180.00, 349.00, $margin(180, 349), '28x8x8', 'cm',
            'GRN-004', 'Double-wall vacuum insulated bottle, keeps drinks cold 24h and hot 12h.'
        ],
        [
            'SAMPLE-PRD-0009', 'Non-Stick Frying Pan 26cm',
            $wh2_id, 'SAMPLE-SUP-0002', 'Home & Kitchen',
            'SKU-HK-FRY-009', 'BAR-0000009', 0.95, 40, 5,
            550.00, 999.00, $margin(550, 999), '26x5', 'cm',
            'GRN-005', 'PFOA-free granite-coated non-stick frying pan with induction-compatible base.'
        ],
        [
            'SAMPLE-PRD-0010', 'Digital Kitchen Scale 5kg',
            $wh2_id, 'SAMPLE-SUP-0002', 'Home & Kitchen',
            'SKU-HK-SCL-010', 'BAR-0000010', 0.45, 65, 8,
            280.00, 499.00, $margin(280, 499), '22x17x2', 'cm',
            'GRN-005', 'Precision kitchen scale with tare function, 1g accuracy, LCD display.'
        ],
    ];

    $product_ids = [];
    foreach ($products as $p) {
        $pid = sd_prepare($conn,
            "INSERT INTO products
             (product_id_str, product_name, warehouse_id, supplier_id, category,
              sku_code, barcode_number, weight, stock_level, warning_threshold,
              purchasing_price, selling_price, selling_price_margin,
              dimensions, dimension_unit, grn_number, description, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')",
            "ssissssdiiddsssss",
            [
                $p[0],  $p[1],  $p[2],  $p[3],  $p[4],
                $p[5],  $p[6],  $p[7],  $p[8],  $p[9],
                $p[10], $p[11], $p[12], $p[13], $p[14],
                $p[15], $p[16]
            ]
        );
        $product_ids[$p[0]] = $pid;
    }

    $log[] = ['entity' => 'Products', 'count' => 10];


    // ── 5. CUSTOMERS ───────────────────────────────────────────
    sd_prepare($conn,
        "INSERT INTO customers (customer_id_str, customer_name, contact_number, email, address, status, loyalty_points_balance)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "ssssssi",
        [
            'SAMPLE-CUST-0001', 'Rahul Mehta', '+91 91234 56789',
            'rahul.mehta@sample.in', '14, Shivaji Nagar, Pune - 411005',
            'Active', 250
        ]
    );

    sd_prepare($conn,
        "INSERT INTO customers (customer_id_str, customer_name, contact_number, email, address, status, loyalty_points_balance)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "ssssssi",
        [
            'SAMPLE-CUST-0002', 'Priya Sharma', '+91 91234 67890',
            'priya.sharma@sample.in', '8B, Hiranandani Gardens, Powai, Mumbai - 400076',
            'Active', 180
        ]
    );

    $log[] = ['entity' => 'Customers', 'count' => 2];


    // ── 6. EMPLOYEES (1 per warehouse) ─────────────────────────
    sd_prepare($conn,
        "INSERT INTO employees
         (full_name, employee_id_str, warehouse_id, email, phone,
          government_id_type, state, pincode, address, hire_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssssssssss",
        [
            'Arjun Patel', 'SAMPLE-EMP-0001', 'SAMPLE-WH-0001',
            'arjun.patel@sampleco.in', '+91 9920011111',
            'Aadhaar', 'Maharashtra', '400001',
            '42, Dharavi Estate Staff Quarters, Mumbai',
            '2024-06-01', 'On Duty'
        ]
    );

    sd_prepare($conn,
        "INSERT INTO employees
         (full_name, employee_id_str, warehouse_id, email, phone,
          government_id_type, state, pincode, address, hire_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssssssssss",
        [
            'Sneha Kulkarni', 'SAMPLE-EMP-0002', 'SAMPLE-WH-0002',
            'sneha.kulkarni@sampleco.in', '+91 9920022222',
            'PAN', 'Maharashtra', '411014',
            'C-12, Hadapsar Staff Colony, Pune',
            '2024-08-15', 'On Duty'
        ]
    );

    $log[] = ['entity' => 'Employees', 'count' => 2];


    // ── 7. BUNDLE (Speaker + Power Bank) ───────────────────────
    // Products: SAMPLE-PRD-0001 (Speaker '+\$currency['symbol']+'1899), SAMPLE-PRD-0004 (Power Bank INR 1499)
    $spk_cost  = 1200.00; $spk_sell  = 1899.00;
    $pwb_cost  = 900.00;  $pwb_sell  = 1499.00;

    $total_cost       = $spk_cost  + $pwb_cost;   // 2100
    $normal_sell      = $spk_sell  + $pwb_sell;   // 3398
    $discount_val     = 10.00;                    // 10%
    $bundle_price     = round($normal_sell * (1 - $discount_val / 100), 2); // 3058.20
    $profit_amount    = round($bundle_price - $total_cost, 2);
    $profit_margin    = round(($profit_amount / $bundle_price) * 100, 2);

    $bnd_id = sd_prepare($conn,
        "INSERT INTO bundles
         (bundle_id_str, bundle_name, sku_code, description,
          discount_type, discount_value, bundle_price, total_cost,
          normal_sell_value, profit_amount, profit_margin_pct,
          valid_from, valid_until, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssssddddddsssi",
        [
            'SAMPLE-BND-0001', 'Tech Starter Kit', 'SKU-BND-TSK-001',
            'Bundle of Wireless Bluetooth Speaker + 20000mAh Power Bank — perfect for on-the-go users.',
            'percentage', $discount_val, $bundle_price, $total_cost,
            $normal_sell, $profit_amount, $profit_margin,
            date('Y-m-d'), date('Y-m-d', strtotime('+180 days')),
            'Active', 1
        ]
    );

    // Bundle items
    sd_prepare($conn,
        "INSERT INTO bundle_items (bundle_id, product_id, quantity, unit_cost_snapshot, unit_sell_snapshot, line_cost, line_sell)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "iiidddd",
        [$bnd_id, $product_ids['SAMPLE-PRD-0001'], 1, $spk_cost, $spk_sell, $spk_cost, $spk_sell]
    );

    sd_prepare($conn,
        "INSERT INTO bundle_items (bundle_id, product_id, quantity, unit_cost_snapshot, unit_sell_snapshot, line_cost, line_sell)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "iiidddd",
        [$bnd_id, $product_ids['SAMPLE-PRD-0004'], 1, $pwb_cost, $pwb_sell, $pwb_cost, $pwb_sell]
    );

    $log[] = ['entity' => 'Bundles', 'count' => 1];


    // ── 8. COUPON ──────────────────────────────────────────────
    sd_prepare($conn,
        "INSERT INTO coupons (code, name, type, value, applies_to, min_order_value, start_date, end_date, usage_limit, times_used, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "sssdsdssiis",
        [
            'SAMPLE10OFF', 'Sample Welcome Discount', 'percentage',
            10.00, 'All Products', 500.00,
            date('Y-m-d'), date('Y-m-d', strtotime('+90 days')),
            100, 0, 'Active'
        ]
    );

    $log[] = ['entity' => 'Coupons', 'count' => 1];


    // ── 9. SALES ORDER ─────────────────────────────────────────
    // Rahul Mehta orders Speaker (2 units) + USB-C Charger (3 units)
    $spk_sell    = 1899.00;
    $chg_sell    = 799.00;
    $so_subtotal = (2 * $spk_sell) + (3 * $chg_sell);  // 6195
    $so_tax_rate = 18.00;
    $so_tax      = round($so_subtotal * $so_tax_rate / 100, 2);
    $so_total    = $so_subtotal + $so_tax;

    $cust_res = $conn->query("SELECT id FROM customers WHERE customer_id_str = 'SAMPLE-CUST-0001'");
    $cust1_id = $cust_res ? intval($cust_res->fetch_row()[0]) : 0;

    $so_id = sd_prepare($conn,
        "INSERT INTO sales_orders (order_id_str, customer_id, order_date, total_amount, status, fulfillment_type, tax_rate, tax_amount)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        "sisdssdd",
        ['SAMPLE-SO-0001', $cust1_id, date('Y-m-d', strtotime('-15 days')), $so_total, 'Completed', 'Delivery', $so_tax_rate, $so_tax]
    );

    sd_prepare($conn,
        "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)",
        "iiidd",
        [$so_id, $product_ids['SAMPLE-PRD-0001'], 2, $spk_sell, 2 * $spk_sell]
    );
    sd_prepare($conn,
        "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)",
        "iiidd",
        [$so_id, $product_ids['SAMPLE-PRD-0002'], 3, $chg_sell, 3 * $chg_sell]
    );

    $log[] = ['entity' => 'Sales Orders', 'count' => 1];


    // ── 10. INVOICE (linked to sample sales order) ─────────────
    $inv_id = sd_prepare($conn,
        "INSERT INTO invoices (invoice_number, customer_id, sales_order_id, invoice_date, due_date, amount, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "siisdds",
        ['SAMPLE-INV-0001', $cust1_id, $so_id, date('Y-m-d', strtotime('-15 days')), date('Y-m-d', strtotime('-5 days')), $so_total, 'Paid']
    );

    $log[] = ['entity' => 'Invoices', 'count' => 1];


    // ── 11. SALES RETURN (1 speaker returned from that order) ──
    $return_unit_price = $spk_sell;
    $return_qty        = 1;
    $return_subtotal   = $return_unit_price * $return_qty;

    $sr_id = sd_prepare($conn,
        "INSERT INTO sales_returns (return_id_str, customer_id, sales_order_id, return_date, total_refund_amount, reason, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "siisdss",
        ['SAMPLE-RET-0001', $cust1_id, $so_id, date('Y-m-d', strtotime('-10 days')), $return_subtotal,
         'Item received in damaged condition — speaker housing cracked on arrival.', 'Processed']
    );
    sd_prepare($conn,
        "INSERT INTO sales_return_items (sales_return_id, product_id, quantity_returned, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)",
        "iiidd",
        [$sr_id, $product_ids['SAMPLE-PRD-0001'], $return_qty, $return_unit_price, $return_subtotal]
    );

    $log[] = ['entity' => 'Sales Returns', 'count' => 1];


    // ── 12. CREDIT NOTE (linked to the sales return above) ─────
    sd_prepare($conn,
        "INSERT INTO credit_notes (credit_note_str, customer_id, sales_return_id, issue_date, credit_amount, reason, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "siisdss",
        ['SAMPLE-CN-0001', $cust1_id, $sr_id, date('Y-m-d', strtotime('-9 days')), $return_subtotal,
         'Credit issued for damaged Bluetooth Speaker returned under SAMPLE-RET-0001. Invoice SAMPLE-INV-0001.', 'Open']
    );

    $log[] = ['entity' => 'Credit Notes', 'count' => 1];


    // ── 13. PURCHASE ORDER ─────────────────────────────────────
    // 10 × Speaker @ purchasing price
    $po_unit_price = 1200.00;
    $po_qty        = 10;
    $po_subtotal   = $po_unit_price * $po_qty;
    $po_tax_rate   = 18.00;
    $po_tax        = round($po_subtotal * $po_tax_rate / 100, 2);
    $po_total      = $po_subtotal + $po_tax;
    $po_od         = date('Y-m-d', strtotime('-30 days'));
    $po_ed         = date('Y-m-d', strtotime('-20 days'));

    $po_id = sd_prepare($conn,
        "INSERT INTO purchase_orders
         (purchase_id_str, supplier_id, warehouse_id, order_date, expected_date, total_amount, status, tax_rate, tax_amount)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        "siissdsdd",
        ['SAMPLE-PO-0001', $sup1_id, $wh1_id, $po_od, $po_ed, $po_total, 'Received', $po_tax_rate, $po_tax]
    );
    sd_prepare($conn,
        "INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, subtotal, qty_received, qty_pending)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "iiiddii",
        [$po_id, $product_ids['SAMPLE-PRD-0001'], $po_qty, $po_unit_price, $po_subtotal, $po_qty, 0]
    );

    $log[] = ['entity' => 'Purchase Orders', 'count' => 1];


    // ── 14. PURCHASE RETURN (2 speakers back to supplier) ──────
    $pr_qty        = 2;
    $pr_unit_price = $po_unit_price;
    $pr_subtotal   = $pr_unit_price * $pr_qty;

    $pr_id = sd_prepare($conn,
        "INSERT INTO purchase_returns (return_number_str, supplier_id, purchase_order_id, return_date, amount, reason, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "siisdss",
        ['SAMPLE-PR-0001', $sup1_id, $po_id, date('Y-m-d', strtotime('-12 days')), $pr_subtotal,
         'Units received with defective driver board — returned to supplier for replacement.', 'Processed']
    );
    sd_prepare($conn,
        "INSERT INTO purchase_return_items (purchase_return_id, product_id, quantity_returned, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)",
        "iiidd",
        [$pr_id, $product_ids['SAMPLE-PRD-0001'], $pr_qty, $pr_unit_price, $pr_subtotal]
    );

    $log[] = ['entity' => 'Purchase Returns', 'count' => 1];

    return $log;
}


/* ─── Handle action ─────────────────────────────────────────── */

$action  = $_GET['action'] ?? null;
$results = [];
$error   = null;
$timing  = null;

if ($action === 'delete' || $action === 'seed') {
    $start = microtime(true);
    try {
        $conn->begin_transaction();

        $delete_log = delete_sample_data($conn);
        $results['delete'] = $delete_log;

        if ($action === 'seed') {
            $seed_log = seed_sample_data($conn);
            $results['seed'] = $seed_log;
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
    $timing = round((microtime(true) - $start) * 1000, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Data Manager — Agile Inventory</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ── Page shell ── */
        body { padding: 0; margin: 0; }

        .sdm-wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 32px 24px 60px;
        }

        /* ── Dev-tool warning banner ── */
        .sdm-devbanner {
            display: flex;
            align-items: center;
            gap: 12px;
            background: color-mix(in srgb, var(--warning, #f59e0b) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--warning, #f59e0b) 40%, transparent);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 28px;
        }
        .sdm-devbanner .icon { font-size: 22px; flex-shrink: 0; }
        .sdm-devbanner h2 { margin: 0 0 2px; font-size: 15px; color: var(--text-primary, #111); }
        .sdm-devbanner p  { margin: 0; font-size: 13px; color: var(--text-secondary, #555); }

        /* ── Page heading ── */
        .sdm-heading { margin-bottom: 28px; }
        .sdm-heading h1 { font-size: 26px; font-weight: 700; color: var(--text-primary, #111); margin: 0 0 6px; }
        .sdm-heading p  { font-size: 14px; color: var(--text-secondary, #666); margin: 0; }

        /* ── Action cards ── */
        .sdm-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 36px;
        }
        @media (max-width: 600px) { .sdm-cards { grid-template-columns: 1fr; } }

        .sdm-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 14px;
            padding: 26px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .sdm-card-icon { font-size: 32px; line-height: 1; }
        .sdm-card h3 { margin: 0; font-size: 17px; font-weight: 700; color: var(--text-primary, #111); }
        .sdm-card p  { margin: 0; font-size: 13px; color: var(--text-secondary, #666); line-height: 1.55; }

        .sdm-card ul {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            color: var(--text-secondary, #666);
            line-height: 1.8;
        }

        .sdm-btn {
            display: inline-block;
            padding: 11px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-align: center;
            text-decoration: none;
            transition: opacity .15s, transform .1s;
            width: 100%;
            box-sizing: border-box;
        }
        .sdm-btn:hover { opacity: .88; transform: translateY(-1px); }
        .sdm-btn:active { transform: translateY(0); }

        .sdm-btn-delete { background: #ef4444; color: #fff; }
        .sdm-btn-seed   { background: #22c55e; color: #fff; }

        /* ── Result panel ── */
        .sdm-result {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .sdm-result-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        .sdm-result-header h3 { margin: 0; font-size: 16px; font-weight: 700; }
        .sdm-result-meta {
            margin-left: auto;
            font-size: 12px;
            color: var(--text-muted, #9ca3af);
        }

        .sdm-section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text-muted, #9ca3af);
            margin: 14px 0 8px;
        }

        .sdm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .sdm-table th {
            text-align: left;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--text-muted, #9ca3af);
            border-bottom: 1px solid var(--border-color, #e5e7eb);
        }
        .sdm-table td {
            padding: 9px 12px;
            border-bottom: 1px solid var(--border-color, #e5e7eb);
            color: var(--text-primary, #111);
        }
        .sdm-table tr:last-child td { border-bottom: none; }
        .sdm-count {
            display: inline-block;
            background: color-mix(in srgb, var(--accent, #6366f1) 12%, transparent);
            color: var(--accent, #6366f1);
            border-radius: 20px;
            padding: 2px 10px;
            font-weight: 700;
            font-size: 13px;
        }
        .sdm-count-zero { background: transparent; color: var(--text-muted, #9ca3af); }

        .sdm-error {
            background: color-mix(in srgb, #ef4444 10%, transparent);
            border: 1px solid color-mix(in srgb, #ef4444 35%, transparent);
            border-radius: 10px;
            padding: 16px 20px;
            color: #b91c1c;
            font-size: 13.5px;
            margin-bottom: 20px;
        }
        .sdm-error strong { display: block; margin-bottom: 6px; }
        .sdm-error code { font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-word; }

        .sdm-success-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: color-mix(in srgb, #22c55e 12%, transparent);
            color: #15803d;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 13px;
            font-weight: 600;
        }

        /* ── Confirm modal ── */
        .sdm-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 9000;
            align-items: center;
            justify-content: center;
        }
        .sdm-overlay.active { display: flex; }
        .sdm-modal {
            background: var(--card-bg, #fff);
            border-radius: 16px;
            padding: 28px 28px 24px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
        }
        .sdm-modal h3 { margin: 0 0 10px; font-size: 18px; }
        .sdm-modal p  { margin: 0 0 22px; font-size: 14px; color: var(--text-secondary, #555); line-height: 1.5; }
        .sdm-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .sdm-btn-cancel {
            padding: 10px 18px;
            border-radius: 8px;
            border: 1px solid var(--border-color, #e5e7eb);
            background: transparent;
            font-size: 14px;
            cursor: pointer;
            color: var(--text-primary, #111);
            font-weight: 600;
        }
        .sdm-btn-confirm {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-weight: 700;
            color: #fff;
        }
        .sdm-btn-confirm.delete { background: #ef4444; }
        .sdm-btn-confirm.seed   { background: #22c55e; }
    </style>
</head>
<body>
<div class="sdm-wrap">

    <!-- ── Dev Banner ── -->
    <div class="sdm-devbanner">
        <div class="icon">🛠️</div>
        <div>
            <h2>Developer Tool — Sample Data Manager</h2>
            <p>This is a permanent utility file. It will not be deleted. Use it to seed or clean sample data at any time.</p>
        </div>
    </div>

    <!-- ── Heading ── -->
    <div class="sdm-heading">
        <h1>Sample Data Manager</h1>
        <p>Manage demo data for the Agile Inventory System. All sample records are identified by a <code>SAMPLE-</code> prefix in their ID strings, so real data is never affected.</p>
    </div>

    <!-- ── Result / Error panel ── -->
    <?php if ($error): ?>
    <div class="sdm-error">
        <strong>❌ Transaction failed — all changes have been rolled back.</strong>
        <code><?= htmlspecialchars($error) ?></code>
    </div>
    <?php elseif (!empty($results)): ?>
    <div class="sdm-result">
        <div class="sdm-result-header">
            <span style="font-size:22px"><?= $action === 'seed' ? '🔄' : '🗑️' ?></span>
            <h3><?= $action === 'seed' ? 'Wipe &amp; Reseed Complete' : 'Sample Data Deleted' ?></h3>
            <span class="sdm-result-meta">Completed in <?= $timing ?>ms &nbsp;·&nbsp; <?= date('d M Y, H:i:s') ?></span>
        </div>

        <?php if (!empty($results['delete'])): ?>
        <div class="sdm-section-label">🗑 Deleted</div>
        <table class="sdm-table">
            <thead><tr><th>Entity</th><th>Records Removed</th></tr></thead>
            <tbody>
            <?php foreach ($results['delete'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['entity']) ?></td>
                    <td>
                        <?php if ($row['count'] > 0): ?>
                            <span class="sdm-count"><?= $row['count'] ?></span>
                        <?php else: ?>
                            <span class="sdm-count sdm-count-zero">0 — none found</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($results['seed'])): ?>
        <div class="sdm-section-label" style="margin-top:20px">✅ Seeded</div>
        <table class="sdm-table">
            <thead><tr><th>Entity</th><th>Records Inserted</th></tr></thead>
            <tbody>
            <?php foreach ($results['seed'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['entity']) ?></td>
                    <td><span class="sdm-count"><?= $row['count'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Action Cards ── -->
    <div class="sdm-cards">

        <!-- Delete Card -->
        <div class="sdm-card">
            <div class="sdm-card-icon">🗑️</div>
            <h3>Delete Sample Data</h3>
            <p>Removes all sample records from the database. No new data will be inserted. Your real records are untouched.</p>
            <ul>
                <li>2 Warehouses</li>
                <li>3 Categories</li>
                <li>10 Products</li>
                <li>2 Suppliers &amp; 2 Customers</li>
                <li>2 Employees, 1 Bundle, 1 Coupon</li>
                <li>1 Sales Order + Invoice</li>
                <li>1 Sales Return + Credit Note</li>
                <li>1 Purchase Order + Purchase Return</li>
            </ul>
            <button class="sdm-btn sdm-btn-delete" onclick="openModal('delete')">
                🗑 Delete Sample Data
            </button>
        </div>

        <!-- Seed Card -->
        <div class="sdm-card">
            <div class="sdm-card-icon">🔄</div>
            <h3>Wipe &amp; Reseed</h3>
            <p>Deletes all existing sample records first, then inserts a fresh full set. Use this to reset demo data to a clean state.</p>
            <ul>
                <li>2 Warehouses (Mumbai &amp; Pune)</li>
                <li>3 Categories with tax rates</li>
                <li>10 Products (Electronics, Clothing, H&amp;K)</li>
                <li>2 Suppliers, 2 Customers, 2 Employees</li>
                <li>1 Bundle + 1 Coupon (10% off)</li>
                <li>1 Sales Order + Invoice</li>
                <li>1 Sales Return + Credit Note</li>
                <li>1 Purchase Order + Purchase Return</li>
            </ul>
            <button class="sdm-btn sdm-btn-seed" onclick="openModal('seed')">
                🔄 Wipe &amp; Reseed
            </button>
        </div>

    </div>

    <!-- ── What's included info box ── -->
    <div class="sdm-result" style="margin-bottom:0">
        <div class="sdm-result-header">
            <span style="font-size:20px">📋</span>
            <h3>What's Included in the Sample Dataset</h3>
        </div>
        <table class="sdm-table">
            <thead><tr><th>Entity</th><th>Details</th><th>ID Prefix</th></tr></thead>
            <tbody>
                <tr><td>Warehouses (2)</td><td>Mumbai Central &amp; Pune East</td><td><code>SAMPLE-WH-</code></td></tr>
                <tr><td>Categories (3)</td><td>Electronics (18% GST), Clothing (5%), Home &amp; Kitchen (12%)</td><td><code>SAMPLE-CAT-</code></td></tr>
                <tr><td>Suppliers (2)</td><td>TechSource India, FashionHub Traders</td><td><code>SAMPLE-SUP-</code></td></tr>
                <tr><td>Products (10)</td><td>4 Electronics · 3 Clothing · 3 Home &amp; Kitchen</td><td><code>SAMPLE-PRD-</code></td></tr>
                <tr><td>Customers (2)</td><td>Rahul Mehta (250 pts) &amp; Priya Sharma (180 pts)</td><td><code>SAMPLE-CUST-</code></td></tr>
                <tr><td>Employees (2)</td><td>Arjun Patel (WH1) &amp; Sneha Kulkarni (WH2)</td><td><code>SAMPLE-EMP-</code></td></tr>
                <tr><td>Bundle (1)</td><td>Tech Starter Kit — Speaker + Power Bank, 10% off</td><td><code>SAMPLE-BND-</code></td></tr>
                <tr><td>Coupon (1)</td><td>SAMPLE10OFF — 10% off, min order 500, 90-day validity</td><td><code>SAMPLE</code></td></tr>
                <tr><td>Sales Order (1)</td><td>Rahul Mehta — 2× Speaker + 3× USB-C Charger (Completed)</td><td><code>SAMPLE-SO-</code></td></tr>
                <tr><td>Invoice (1)</td><td>Linked to SAMPLE-SO-0001 — Status: Paid</td><td><code>SAMPLE-INV-</code></td></tr>
                <tr><td>Sales Return (1)</td><td>1× Speaker returned (damaged) — Status: Processed</td><td><code>SAMPLE-RET-</code></td></tr>
                <tr><td>Credit Note (1)</td><td>Issued against SAMPLE-RET-0001, linked to SAMPLE-INV-0001</td><td><code>SAMPLE-CN-</code></td></tr>
                <tr><td>Purchase Order (1)</td><td>10× Speakers from TechSource — Status: Received</td><td><code>SAMPLE-PO-</code></td></tr>
                <tr><td>Purchase Return (1)</td><td>2× Speakers returned to TechSource (defective) — Status: Processed</td><td><code>SAMPLE-PR-</code></td></tr>
            </tbody>
        </table>
    </div>

</div><!-- /.sdm-wrap -->


<!-- ── Confirm Modal ── -->
<div class="sdm-overlay" id="sdm-overlay">
    <div class="sdm-modal">
        <h3 id="modal-title">Confirm Action</h3>
        <p id="modal-body">Are you sure?</p>
        <div class="sdm-modal-actions">
            <button class="sdm-btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="sdm-btn-confirm" id="modal-confirm" onclick="doAction()">Confirm</button>
        </div>
    </div>
</div>

<script>
let pendingAction = null;

const messages = {
    delete: {
        title: '🗑 Delete Sample Data',
        body: 'This will permanently remove all records with a <strong>SAMPLE-</strong> prefix from the database. Real data will not be touched. Continue?',
        cls: 'delete',
        label: 'Yes, Delete'
    },
    seed: {
        title: '🔄 Wipe & Reseed Sample Data',
        body: 'This will <strong>delete all existing sample records</strong>, then insert a fresh full dataset (2 warehouses, 3 categories, 10 products, 2 suppliers, 2 customers, 2 employees, 1 bundle, 1 coupon, 1 sales order, 1 invoice, 1 sales return, 1 credit note, 1 purchase order, 1 purchase return). Continue?',
        cls: 'seed',
        label: 'Yes, Reseed'
    }
};

function openModal(action) {
    pendingAction = action;
    const m = messages[action];
    document.getElementById('modal-title').textContent    = m.title;
    document.getElementById('modal-body').innerHTML       = m.body;
    const btn = document.getElementById('modal-confirm');
    btn.className = 'sdm-btn-confirm ' + m.cls;
    btn.textContent = m.label;
    document.getElementById('sdm-overlay').classList.add('active');
}

function closeModal() {
    document.getElementById('sdm-overlay').classList.remove('active');
    pendingAction = null;
}

function doAction() {
    if (!pendingAction) return;
    window.location.href = 'sample_data.php?action=' + pendingAction;
}

// Close on overlay click
document.getElementById('sdm-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>
