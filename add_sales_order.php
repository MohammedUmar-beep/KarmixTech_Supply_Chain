<?php
require_once 'includes/auth_guard.php';
require_once 'includes/loyalty_helper.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_id_str = $conn->real_escape_string($_POST['order_id_str']);
    $customer_id = intval($_POST['customer_id']);
    $order_date = $conn->real_escape_string($_POST['order_date']);
    $fulfillment_type = $conn->real_escape_string($_POST['fulfillment_type']);
    $shipping_address = $conn->real_escape_string($_POST['shipping_address'] ?? '');
    $points_to_redeem = max(0, intval($_POST['points_to_redeem'] ?? 0));

    // Delivery charge logic
    $delivery_charge = ($fulfillment_type === 'Delivery') ? 100.00 : 0.00;

    // Arrays for line items
    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity']   ?? [];

    $status = 'Pending'; // default

    // Start transaction
    $conn->begin_transaction();

    try {
        // --- Batch-fetch all product prices in ONE query (eliminates N+1) ---
        $valid_items = [];
        foreach ($product_ids as $index => $pid) {
            $pid = intval($pid);
            $qty = intval($quantities[$index] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $valid_items[$pid] = ($valid_items[$pid] ?? 0) + $qty; // accumulate qty per product
            }
        }

        $prices_map = [];
        if (!empty($valid_items)) {
            $id_list = implode(',', array_keys($valid_items));
            $price_res = $conn->query(
                "SELECT id, selling_price, product_id_str, product_name,
                        stock_level, warning_threshold, supplier_id, purchasing_price
                 FROM products WHERE id IN ($id_list)"
            );
            while ($pr = $price_res->fetch_assoc()) {
                $prices_map[$pr['id']] = $pr;
            }
        }

        $calculated_subtotal = 0;
        $items_to_insert     = [];

        foreach ($product_ids as $index => $pid) {
            $pid = intval($pid);
            $qty = intval($quantities[$index] ?? 0);

            if ($pid > 0 && $qty > 0 && isset($prices_map[$pid])) {
                $unit_price    = floatval($prices_map[$pid]['selling_price']);
                $line_subtotal = $unit_price * $qty;
                $calculated_subtotal += $line_subtotal;

                $items_to_insert[] = [
                    'product_id' => $pid,
                    'quantity'   => $qty,
                    'unit_price' => $unit_price,
                    'subtotal'   => $line_subtotal
                ];

                // Stock Deduction
                $conn->query("UPDATE products SET stock_level = stock_level - $qty WHERE id = $pid AND stock_level >= $qty");

                // Low-Stock Check: re-read updated stock level
                $p_data = $prices_map[$pid];
                $updated_stock_res = $conn->query("SELECT stock_level FROM products WHERE id = $pid");
                $updated_stock = $updated_stock_res ? floatval($updated_stock_res->fetch_row()[0]) : $p_data['stock_level'];

                if ($updated_stock <= $p_data['warning_threshold']) {
                    // Resolve supplier integer ID
                    $sup_stmt = $conn->prepare("SELECT id FROM suppliers WHERE supplier_id_str = ? OR supplier_name = ? LIMIT 1");
                    $sup_stmt->bind_param("ss", $p_data['supplier_id'], $p_data['supplier_id']);
                    $sup_stmt->execute();
                    $sup_res = $sup_stmt->get_result();
                    $sup_stmt->close();

                    if ($sup_res && $sup_res->num_rows > 0) {
                        $supplier_int_id = intval($sup_res->fetch_row()[0]);

                        // Avoid duplicate Draft POs for the same supplier today
                        $draft_chk_stmt = $conn->prepare(
                            "SELECT id FROM purchase_orders WHERE supplier_id = ? AND status = 'Draft' AND order_date = CURRENT_DATE() LIMIT 1"
                        );
                        $draft_chk_stmt->bind_param("i", $supplier_int_id);
                        $draft_chk_stmt->execute();
                        $draft_chk_res = $draft_chk_stmt->get_result();
                        $draft_chk_stmt->close();

                        if ($draft_chk_res->num_rows == 0) {
                            // Placeholder string — will be updated after insert using insert_id
                            $po_str_placeholder = 'PO-AUTO-TMP-' . uniqid();
                            $draft_qty    = 50;
                            $draft_amount = $draft_qty * floatval($p_data['purchasing_price']);

                            $ins_po = $conn->prepare(
                                "INSERT INTO purchase_orders (purchase_id_str, supplier_id, order_date, total_amount, status)
                                 VALUES (?, ?, CURRENT_DATE(), ?, 'Draft')"
                            );
                            $ins_po->bind_param("sid", $po_str_placeholder, $supplier_int_id, $draft_amount);
                            $ins_po->execute();
                            $new_po_id = $conn->insert_id;
                            $ins_po->close();

                            // Now set the correct reference string using the real ID
                            $po_str = generate_ref('PO-AUTO', $new_po_id);
                            $conn->query("UPDATE purchase_orders SET purchase_id_str = '$po_str' WHERE id = $new_po_id");

                            $ins_poi = $conn->prepare(
                                "INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, subtotal)
                                 VALUES (?, ?, ?, ?, ?)"
                            );
                            $ins_poi->bind_param("iiidd", $new_po_id, $pid, $draft_qty, $p_data['purchasing_price'], $draft_amount);
                            $ins_poi->execute();
                            $ins_poi->close();

                            log_activity($conn, 'System', 'Purchase Order', $po_str,
                                "Auto-generated Draft PO due to low stock on: " . $p_data['product_name']);

                            $notif_msg  = "Low Stock Alert: " . $p_data['product_name'] . " dropped to " . $updated_stock . ". Auto-Draft PO created.";
                            $notif_type = "low_stock";
                            $notif_link = "purchase_orders.php?search=" . urlencode($po_str) . "&search_col=po.purchase_id_str";
                            $notif_stmt = $conn->prepare("INSERT INTO notifications (message, type, link) VALUES (?, ?, ?)");
                            $notif_stmt->bind_param("sss", $notif_msg, $notif_type, $notif_link);
                            $notif_stmt->execute();
                            $notif_stmt->close();
                        }
                    }
                }
            }
        }

        // --- Tax & Discount Engine ---
        // Both tables are optional features — skip gracefully if they don't exist yet
        $tax_amount           = 0;
        $discount_amount      = 0;
        $applied_discount_code = null;

        $disc_stmt = $conn->prepare(
            "SELECT * FROM discount_rules WHERE is_active = 1 AND min_order_value <= ?
             ORDER BY discount_value DESC LIMIT 1"
        );
        if ($disc_stmt) {
            $disc_stmt->bind_param("d", $calculated_subtotal);
            $disc_stmt->execute();
            $disc_res = $disc_stmt->get_result();
            $disc_stmt->close();

            if ($disc_res && $disc_res->num_rows > 0) {
                $disc = $disc_res->fetch_assoc();
                $applied_discount_code = $disc['discount_code'];
                $discount_amount = $disc['discount_type'] === 'Percentage'
                    ? $calculated_subtotal * ($disc['discount_value'] / 100)
                    : floatval($disc['discount_value']);
                if ($discount_amount > $calculated_subtotal)
                    $discount_amount = $calculated_subtotal;
            }
        }

        $taxable_amount = $calculated_subtotal - $discount_amount;

        $total_tax_rate = 0;
        $tax_res = $conn->query("SELECT SUM(rate_percent) FROM tax_rules WHERE is_active = 1");
        if ($tax_res) {
            $total_tax_rate = floatval($tax_res->fetch_row()[0]);
            $tax_amount = $taxable_amount * ($total_tax_rate / 100);
        }

        $grand_total = $taxable_amount + $tax_amount + $delivery_charge;

        // Loyalty points redemption — apply before saving so grand_total is correct
        $loyalty_discount = 0;
        $points_redeemed  = 0;
        if ($customer_id > 0 && $points_to_redeem > 0) {
            $loyalty_discount = redeem_loyalty_points($conn, $customer_id, $points_to_redeem, $grand_total, 'SalesOrder', 0);
            // points_redeemed is derived inside helper; approximate from discount / rate
            $cfg_r = $conn->query("SELECT redemption_rate FROM loyalty_config WHERE id=1")->fetch_row();
            $rate  = $cfg_r ? floatval($cfg_r[0]) : 0.05;
            $points_redeemed = $rate > 0 ? (int)round($loyalty_discount / $rate) : 0;
            $grand_total = max(0, $grand_total - $loyalty_discount);
        }

        // Insert main order — store tax_rate and tax_amount so the invoice
        // always shows the tax that was actually charged, even if rates change later.
        $stmt = $conn->prepare(
            "INSERT INTO sales_orders
             (order_id_str, customer_id, order_date, total_amount,
              fulfillment_type, delivery_charge, shipping_address, status,
              tax_rate, tax_amount, loyalty_discount, points_redeemed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sisdsdssdddi",
            $order_id_str, $customer_id, $order_date, $grand_total,
            $fulfillment_type, $delivery_charge, $shipping_address, $status,
            $total_tax_rate, $tax_amount, $loyalty_discount, $points_redeemed
        );
        $stmt->execute();
        $sales_order_id = $conn->insert_id;
        $stmt->close();

        // Insert line items
        if (!empty($items_to_insert)) {
            $item_stmt = $conn->prepare(
                "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price, subtotal)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($items_to_insert as $item) {
                $item_stmt->bind_param("iiidd",
                    $sales_order_id, $item['product_id'], $item['quantity'],
                    $item['unit_price'], $item['subtotal']
                );
                $item_stmt->execute();
            }
            $item_stmt->close();
        }

        $payment_status      = $conn->real_escape_string($_POST['payment_status'] ?? 'Credit');
        $amount_paid         = floatval($_POST['amount_paid'] ?? 0);
        $payment_method_input = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');

        // Auto-generate Invoice — use insert_id for reference string
        $due_date = date('Y-m-d', strtotime($order_date . ' + 15 days'));

        // inv_status must match current invoice statuses: Unpaid, Paid, Overdue
        $inv_status = 'Unpaid';
        if ($payment_status === 'Paid')    $inv_status = 'Paid';
        if ($payment_status === 'Partial') $inv_status = 'Unpaid';

        $inv_placeholder = 'INV-TMP-' . uniqid();
        $inv_stmt = $conn->prepare(
            "INSERT INTO invoices (invoice_number, customer_id, sales_order_id, invoice_date, due_date, amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $inv_stmt->bind_param("siissds", $inv_placeholder, $customer_id, $sales_order_id, $order_date, $due_date, $grand_total, $inv_status);
        $inv_stmt->execute();
        $generated_invoice_id = $conn->insert_id;
        $inv_stmt->close();

        $invoice_number = generate_ref('INV', $generated_invoice_id);
        $conn->query("UPDATE invoices SET invoice_number = '$invoice_number' WHERE id = $generated_invoice_id");

        // Log initial payment
        if (($payment_status === 'Paid' || $payment_status === 'Partial') && $amount_paid > 0) {
            $pay_notes = "Initial Payment for Sales Order " . $order_id_str;

            $inst_stmt = $conn->prepare(
                "INSERT INTO installments (invoice_id, amount, payment_date, payment_method, notes)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $inst_stmt->bind_param("idsss", $generated_invoice_id, $amount_paid, $order_date, $payment_method_input, $pay_notes);
            $inst_stmt->execute();
            $inst_stmt->close();

            // Placeholder, update after insert
            $pay_placeholder = 'PAY-TMP-' . uniqid();
            $pr_stmt = $conn->prepare(
                "INSERT INTO payments_received
                 (payment_ref_str, customer_id, invoice_id, payment_date, amount, payment_method, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $pr_stmt->bind_param("siisdss",
                $pay_placeholder, $customer_id, $generated_invoice_id,
                $order_date, $amount_paid, $payment_method_input, $pay_notes
            );
            $pr_stmt->execute();
            $new_pay_id      = $conn->insert_id;
            $payment_ref_str = generate_ref('PAY', $new_pay_id);
            $conn->query("UPDATE payments_received SET payment_ref_str = '$payment_ref_str' WHERE id = $new_pay_id");
            $pr_stmt->close();
        }

        if (($payment_status === 'Partial' || $payment_status === 'Credit') && ($grand_total - $amount_paid) > 0) {
            $balance      = $grand_total - $amount_paid;
            $status_cn    = 'Open';
            $reason       = "Credit for Sales Order " . $order_id_str;
            $cn_placeholder = 'CN-TMP-' . uniqid();

            $cn_stmt = $conn->prepare(
                "INSERT INTO credit_notes (credit_note_str, customer_id, issue_date, credit_amount, reason, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $cn_stmt->bind_param("sisdss", $cn_placeholder, $customer_id, $order_date, $balance, $reason, $status_cn);
            $cn_stmt->execute();
            $new_cn_id       = $conn->insert_id;
            $credit_note_str = generate_ref('CN', $new_cn_id);
            $conn->query("UPDATE credit_notes SET credit_note_str = '$credit_note_str' WHERE id = $new_cn_id");
            $cn_stmt->close();
        }

        log_activity($conn, 'Create', 'Sales Order', $order_id_str, "Generated Sales Order with total amount: \$$grand_total");
        $conn->commit();

        // Award loyalty points to customer after successful commit
        if ($customer_id > 0) {
            award_loyalty_points($conn, $customer_id, $grand_total, 'SalesOrder', $sales_order_id);
        }

        header("Location: sales_orders.php?msg=added");

    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: sales_orders.php?msg=error_1");
    }

    $conn->close();
} else {
    header("Location: sales_orders.php");
}
?>