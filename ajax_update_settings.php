<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

// ── Save Currency ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_currency') {
    header('Content-Type: application/json');
    $new_symbol      = trim($_POST['currency_symbol'] ?? '₹');
    $new_code        = trim($_POST['currency_code']   ?? 'INR');
    $conversion_rate = floatval($_POST['conversion_rate'] ?? 1.0);

    // Ensure system_settings table has the rows (INSERT … ON DUPLICATE KEY UPDATE)
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach (['currency_symbol' => $new_symbol, 'currency_code' => $new_code] as $k => $v) {
            $stmt->bind_param("ss", $k, $v);
            $stmt->execute();
        }
        $stmt->close();

        if (abs($conversion_rate - 1.0) > 0.00001) {
            $r = floatval($conversion_rate);
            $tables = [
                "UPDATE products SET purchasing_price = purchasing_price * $r, selling_price = selling_price * $r, discounted_price = discounted_price * $r",
                "UPDATE products SET discount_value = discount_value * $r WHERE discount_type = 'fixed'",
                "UPDATE sales_orders SET total_amount = total_amount * $r, tax_amount = tax_amount * $r, discount_amount = discount_amount * $r",
                "UPDATE sales_order_items SET unit_price = unit_price * $r, subtotal = subtotal * $r",
                "UPDATE sales_returns SET amount = amount * $r",
                "UPDATE purchase_orders SET total_amount = total_amount * $r",
                "UPDATE purchase_order_items SET unit_price = unit_price * $r, subtotal = subtotal * $r",
                "UPDATE purchase_returns SET amount = amount * $r",
                "UPDATE payments_received SET amount = amount * $r",
                "UPDATE payments_made SET amount = amount * $r",
                "UPDATE credit_notes SET credit_amount = credit_amount * $r",
                "UPDATE installments SET amount = amount * $r",
                "UPDATE discount_rules SET min_order_value = min_order_value * $r",
                "UPDATE discount_rules SET discount_value = discount_value * $r WHERE discount_type = 'Fixed'",
                "UPDATE coupons SET value = value * $r WHERE type = 'Fixed'",
            ];
            foreach ($tables as $sql) { $conn->query($sql); }
        }
        $conn->commit();
        log_activity($conn, 'Update', 'System Settings', 'Currency', "Updated currency to $new_code ($new_symbol) rate=$conversion_rate");
        echo json_encode(['success' => true, 'message' => "Currency updated to $new_code."]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_business_settings') {
    header('Content-Type: application/json');
    $allowed = ['company_name','company_address','company_city','company_state',
                'company_pincode','company_country','company_phone','company_email',
                'company_website','company_tax_id','invoice_notes'];
    $stmt = $conn->prepare("INSERT INTO business_settings (setting_key, setting_value)
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($allowed as $key) {
        $val = trim($_POST[$key] ?? '');
        $stmt->bind_param("ss", $key, $val);
        $stmt->execute();
    }
    $stmt->close();
    log_activity($conn, 'Update', 'System Settings', 'Business Info', 'Updated business/company settings');
    echo json_encode(['success' => true, 'message' => 'Business settings saved.']);
    exit();
}

    header('Content-Type: application/json');

    $new_symbol = $_POST['currency_symbol'] ?? '$';
    $new_code = $_POST['currency_code'] ?? 'USD';
    $conversion_rate = floatval($_POST['conversion_rate'] ?? 1.0);

    $conn->begin_transaction();
    try {
        // 1. Update system_settings
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'currency_symbol'");
        $stmt->bind_param("s", $new_symbol);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'currency_code'");
        $stmt->bind_param("s", $new_code);
        $stmt->execute();

        // 2. Perform Mass Conversion if rate is not 1.0
        if ($conversion_rate != 1.0) {
            // Products
            $conn->query("UPDATE products SET purchasing_price = purchasing_price * $conversion_rate, selling_price = selling_price * $conversion_rate, discounted_price = discounted_price * $conversion_rate");
            $conn->query("UPDATE products SET discount_value = discount_value * $conversion_rate WHERE discount_type = 'fixed'");

            // Sales
            $conn->query("UPDATE sales_orders SET total_amount = total_amount * $conversion_rate, tax_amount = tax_amount * $conversion_rate, discount_amount = discount_amount * $conversion_rate");
            $conn->query("UPDATE sales_order_items SET unit_price = unit_price * $conversion_rate, subtotal = subtotal * $conversion_rate");
            $conn->query("UPDATE sales_returns SET amount = amount * $conversion_rate");

            // Purchases
            $conn->query("UPDATE purchase_orders SET total_amount = total_amount * $conversion_rate");
            $conn->query("UPDATE purchase_order_items SET unit_price = unit_price * $conversion_rate, subtotal = subtotal * $conversion_rate");
            $conn->query("UPDATE purchase_returns SET amount = amount * $conversion_rate");

            // Financials
            $conn->query("UPDATE payments_received SET amount = amount * $conversion_rate");
            $conn->query("UPDATE payments_made SET amount = amount * $conversion_rate");
            $conn->query("UPDATE credit_notes SET credit_amount = credit_amount * $conversion_rate");
            $conn->query("UPDATE installments SET amount = amount * $conversion_rate");

            // Rules
            $conn->query("UPDATE discount_rules SET min_order_value = min_order_value * $conversion_rate");
            $conn->query("UPDATE discount_rules SET discount_value = discount_value * $conversion_rate WHERE discount_type = 'Fixed'");
            $conn->query("UPDATE coupons SET value = value * $conversion_rate WHERE type = 'Fixed'");
        }

        $conn->commit();
        log_activity($conn, 'Update', 'System Settings', 'Currency', "Updated currency to $new_code ($new_symbol) with rate $conversion_rate");

        echo json_encode(['success' => true, 'message' => 'System settings and prices updated successfully.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}
