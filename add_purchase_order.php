<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['purchase_id_str'])) {
    $purchase_id_str = $conn->real_escape_string($_POST['purchase_id_str']);
    $supplier_id = intval($_POST['supplier_id']);
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : NULL;
    $order_date = $conn->real_escape_string($_POST['order_date']);
    $expected_date = !empty($_POST['expected_date']) ? $conn->real_escape_string($_POST['expected_date']) : NULL;
    $status = $conn->real_escape_string($_POST['status'] ?? 'Draft');

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];

    $conn->begin_transaction();

    try {
        $grand_total = 0;
        $items_to_insert = [];

        foreach ($product_ids as $index => $pid) {
            $pid = intval($pid);
            $qty = intval($quantities[$index]);
            $price = floatval($unit_prices[$index]);

            if ($pid > 0 && $qty > 0 && $price >= 0) {
                $line_subtotal = $price * $qty;
                $grand_total += $line_subtotal;

                $items_to_insert[] = [
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'subtotal' => $line_subtotal
                ];

                // If status is received upon creation, add stock
                if ($status === 'Received') {
                    $conn->query("UPDATE products SET stock_level = stock_level + $qty WHERE id = $pid");
                }
            }
        }

        // Compute tax on the purchase subtotal
        $po_tax_rate   = 0;
        $po_tax_amount = 0;
        $t_res = $conn->query("SELECT SUM(rate_percent) as r FROM tax_rules WHERE is_active=1");
        if ($t_res) { $po_tax_rate = floatval($t_res->fetch_assoc()['r']); $po_tax_amount = $grand_total * ($po_tax_rate / 100); }
        $grand_total_with_tax = $grand_total + $po_tax_amount;

        if ($expected_date) {
            $stmt = $conn->prepare("INSERT INTO purchase_orders (purchase_id_str, supplier_id, warehouse_id, order_date, expected_date, total_amount, status, tax_rate, tax_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siissdsdd", $purchase_id_str, $supplier_id, $warehouse_id, $order_date, $expected_date, $grand_total_with_tax, $status, $po_tax_rate, $po_tax_amount);
        } else {
            $stmt = $conn->prepare("INSERT INTO purchase_orders (purchase_id_str, supplier_id, warehouse_id, order_date, total_amount, status, tax_rate, tax_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siisdsdd", $purchase_id_str, $supplier_id, $warehouse_id, $order_date, $grand_total_with_tax, $status, $po_tax_rate, $po_tax_amount);
        }

        $stmt->execute();
        $purchase_order_id = $conn->insert_id;
        $stmt->close();

        if (!empty($items_to_insert)) {
            $item_stmt = $conn->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($items_to_insert as $item) {
                $item_stmt->bind_param("iiidd", $purchase_order_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']);
                $item_stmt->execute();
            }
            $item_stmt->close();
        }

        // 1. Auto Create Shipment with default Pending status
        $ship_placeholder = 'SHIP-TMP-' . uniqid();
        $carrier = 'Pending';
        $tracking = 'Pending';
        $shipment_status = 'Pending';
        $ship_stmt = $conn->prepare("INSERT INTO purchase_shipments (shipment_number_str, purchase_order_id, carrier, tracking_number, status, shipment_date, expected_delivery) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ship_stmt->bind_param("sisssss", $ship_placeholder, $purchase_order_id, $carrier, $tracking, $shipment_status, $order_date, $expected_date);
        $ship_stmt->execute();
        $new_ship_id = $conn->insert_id;
        $shipment_number_str = generate_ref('SHIP', $new_ship_id);
        $conn->query("UPDATE purchase_shipments SET shipment_number_str = '$shipment_number_str' WHERE id = $new_ship_id");
        $ship_stmt->close();

        $payment_status = $conn->real_escape_string($_POST['payment_status'] ?? 'Credit');
        $amount_paid    = floatval($_POST['amount_paid'] ?? 0);
        $method         = $conn->real_escape_string($_POST['payment_method'] ?? 'Bank Transfer');

        // 2. Payments Made & 3. Supplier Credit — skipped entirely for Draft orders
        if ($status !== 'Draft') {
            // 2. Payments Made (only if something was actually paid)
            if (($payment_status === 'Paid' || $payment_status === 'Partial') && $amount_paid > 0) {
                $notes      = "Initial Payment for PO " . $purchase_id_str;
                $pay_status = ($payment_status === 'Paid') ? 'Fully Paid' : 'Partially Paid';

                $pay_out_placeholder = 'PAY-OUT-TMP-' . uniqid();
                $pm_stmt = $conn->prepare("INSERT INTO payments_made (payment_ref_str, supplier_id, payment_date, amount, status, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $pm_stmt->bind_param("sisdsss", $pay_out_placeholder, $supplier_id, $order_date, $amount_paid, $pay_status, $method, $notes);
                $pm_stmt->execute();
                $new_pm_id       = $conn->insert_id;
                $payment_ref_str = generate_ref('PAY', $new_pm_id);
                $conn->query("UPDATE payments_made SET payment_ref_str = '$payment_ref_str' WHERE id = $new_pm_id");
                $pm_stmt->close();
            }

            // 3. Supplier Credit for outstanding balance (use full with-tax total)
            if (($payment_status === 'Partial' || $payment_status === 'Credit') && ($grand_total_with_tax - $amount_paid) > 0) {
                $balance        = $grand_total_with_tax - $amount_paid;
                $sc_placeholder = 'SUP-CN-TMP-' . uniqid();
                $status_sc      = 'Open';

                $sc_stmt = $conn->prepare("INSERT INTO supplier_credits (credit_note_str, supplier_id, date_issued, amount, status) VALUES (?, ?, ?, ?, ?)");
                $sc_stmt->bind_param("sisds", $sc_placeholder, $supplier_id, $order_date, $balance, $status_sc);
                $sc_stmt->execute();
                $new_sc_id       = $conn->insert_id;
                $credit_note_str = generate_ref('SC', $new_sc_id);
                $conn->query("UPDATE supplier_credits SET credit_note_str = '$credit_note_str' WHERE id = $new_sc_id");
                $sc_stmt->close();
            }
        } // end: skip payment for Draft

        // 4. Notification
        $notif_msg  = "New Purchase Order created: " . $purchase_id_str;
        $notif_type = "purchase_order";
        $notif_link = "purchase_orders.php?search=" . urlencode($purchase_id_str) . "&search_col=po.order_id_str";
        $notif_stmt = $conn->prepare("INSERT INTO notifications (message, type, link) VALUES (?, ?, ?)");
        $notif_stmt->bind_param("sss", $notif_msg, $notif_type, $notif_link);
        $notif_stmt->execute();
        $notif_stmt->close();

        log_activity($conn, 'Create', 'Purchase Order', $purchase_id_str, "Generated Purchase Order with total amount: \$$grand_total");
        $conn->commit();
        header("Location: purchase_orders.php?msg=added");

    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: purchase_orders.php?msg=error_1");
    }
} else {
    header("Location: purchase_orders.php");
}
?>