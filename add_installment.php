<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php");
    exit();
}

$amount         = floatval($_POST['amount']);
$payment_date   = $conn->real_escape_string($_POST['payment_date']);
$payment_method = $conn->real_escape_string($_POST['payment_method']);
$notes          = $conn->real_escape_string($_POST['notes'] ?? '');
$invoice_id     = !empty($_POST['invoice_id'])     ? intval($_POST['invoice_id'])     : null;
$purchase_order_id = !empty($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : null;

if ($amount <= 0) { die("Invalid amount."); }

$conn->begin_transaction();
try {

    // ── SALES INSTALLMENT (invoice) ───────────────────────────────────────────
    if ($invoice_id) {
        $stmt = $conn->prepare("INSERT INTO installments (invoice_id, amount, payment_date, payment_method, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("idsss", $invoice_id, $amount, $payment_date, $payment_method, $notes);
        $stmt->execute();
        $stmt->close();

        // Update invoice status based on total paid
        $inv_res = $conn->query("
            SELECT inv.amount, inv.customer_id, inv.sales_order_id, COALESCE(SUM(inst.amount), 0) AS paid
            FROM invoices inv
            LEFT JOIN installments inst ON inv.id = inst.invoice_id
            WHERE inv.id = $invoice_id
            GROUP BY inv.id
        ");
        if ($inv_res && $row = $inv_res->fetch_assoc()) {
            if ($row['paid'] >= $row['amount']) {
                $conn->query("UPDATE invoices SET status = 'Paid' WHERE id = $invoice_id");
                $so_row = $conn->query("SELECT sales_order_id FROM invoices WHERE id = $invoice_id")->fetch_row();
                if ($so_row && $so_row[0]) {
                    $conn->query("UPDATE sales_orders SET status = 'Completed' WHERE id = " . intval($so_row[0]));
                }

                // Auto-close credit notes whose linked sales return points at this sales order
                $so_id_cn = intval($row['sales_order_id']);
                if ($so_id_cn > 0) {
                    $conn->query("
                        UPDATE credit_notes cn
                        INNER JOIN sales_returns sr ON cn.sales_return_id = sr.id
                        SET cn.status = 'Closed'
                        WHERE sr.sales_order_id = $so_id_cn
                          AND cn.status NOT IN ('Closed', 'Completed')
                    ");
                }
            } else {
                $conn->query("UPDATE invoices SET status = 'Partial' WHERE id = $invoice_id");
            }
        }

        // Also sync into payments_received
        $cust_row = $conn->query("SELECT customer_id FROM invoices WHERE id = $invoice_id")->fetch_row();
        $cust_id  = $cust_row ? intval($cust_row[0]) : 0;
        $tmp_ref  = 'PAY-TMP-' . uniqid();
        $pr_stmt  = $conn->prepare("INSERT INTO payments_received (payment_ref_str, customer_id, invoice_id, payment_date, amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $pr_stmt->bind_param("siisdss", $tmp_ref, $cust_id, $invoice_id, $payment_date, $amount, $payment_method, $notes);
        $pr_stmt->execute();
        $new_pr_id = $conn->insert_id;
        $real_ref  = generate_ref('PAY', $new_pr_id);
        $conn->query("UPDATE payments_received SET payment_ref_str = '$real_ref' WHERE id = $new_pr_id");
        $pr_stmt->close();

        $conn->commit();
        header("Location: view_invoice.php?invoice_id=$invoice_id&msg=added");
        exit();

    // ── PURCHASE INSTALLMENT (purchase order) ─────────────────────────────────
    } elseif ($purchase_order_id) {
        // Fetch PO details
        $po_res   = $conn->query("SELECT total_amount, supplier_id FROM purchase_orders WHERE id = $purchase_order_id");
        $po_row   = ($po_res) ? $po_res->fetch_assoc() : null;
        $po_total = $po_row ? floatval($po_row['total_amount']) : 0;
        $sup_id   = $po_row ? intval($po_row['supplier_id']) : 0;

        // ── Find the canonical payments_made row for this PO ─────────────────
        // Strategy 1: row tagged with PO-ID in notes (set by prior installments)
        $existing_pm = $conn->query("
            SELECT id, amount FROM payments_made
            WHERE notes LIKE '%PO-ID:$purchase_order_id%'
            ORDER BY id ASC LIMIT 1
        ");
        $existing_pm_row = $existing_pm ? $existing_pm->fetch_assoc() : null;

        // Strategy 2: original payment row created by add_payment_made.php
        // (it has no PO-ID tag — find it by supplier + amount matching PO total)
        if (!$existing_pm_row) {
            $orig_pm = $conn->query("
                SELECT id, amount FROM payments_made
                WHERE supplier_id = $sup_id
                  AND notes NOT LIKE '%PAY-OUT%'
                  AND notes NOT LIKE '%Refund%'
                ORDER BY id DESC LIMIT 1
            ");
            $existing_pm_row = $orig_pm ? $orig_pm->fetch_assoc() : null;

            // Stamp it with the PO-ID tag so future lookups succeed, and
            // seed payment_installments with the original amount if it has no entries yet
            if ($existing_pm_row) {
                $orig_pm_id     = intval($existing_pm_row['id']);
                $orig_pm_amount = floatval($existing_pm_row['amount']);

                // Check if payment_installments already seeded for this pm record
                $already_seeded = $conn->query("
                    SELECT COUNT(*) FROM payment_installments WHERE payment_made_id = $orig_pm_id
                ")->fetch_row()[0];

                if (!$already_seeded && $orig_pm_amount > 0) {
                    // Seed the original payment as installment #1
                    $seed_date = $conn->query("SELECT payment_date FROM payments_made WHERE id = $orig_pm_id")->fetch_row()[0];
                    $seed_method = $conn->query("SELECT payment_method FROM payments_made WHERE id = $orig_pm_id")->fetch_row()[0];
                    $pi_seed = $conn->prepare("INSERT INTO payment_installments (payment_made_id, installment_date, amount, payment_method) VALUES (?, ?, ?, ?)");
                    $pi_seed->bind_param("isds", $orig_pm_id, $seed_date, $orig_pm_amount, $seed_method);
                    $pi_seed->execute();
                    $pi_seed->close();
                }

                // Tag the notes with PO-ID for future lookups
                $conn->query("UPDATE payments_made SET notes = CONCAT(COALESCE(notes,''), ' PO-ID:$purchase_order_id') WHERE id = $orig_pm_id");
                $existing_pm_row['id'] = $orig_pm_id;
            }
        }

        // ── Sum ALL installments for this PO's payments_made record ──────────
        $pm_id = $existing_pm_row ? intval($existing_pm_row['id']) : 0;

        $paid_so_far = 0;
        if ($pm_id) {
            $paid_res    = $conn->query("SELECT COALESCE(SUM(amount),0) FROM payment_installments WHERE payment_made_id = $pm_id");
            $paid_so_far = $paid_res ? floatval($paid_res->fetch_row()[0]) : 0;
        }

        $new_total  = $paid_so_far + $amount;
        $pm_status  = $new_total >= $po_total ? 'Fully Paid' : 'Partially Paid';
        $note_tag   = "Installment for PO-ID:$purchase_order_id. $notes";

        if ($pm_id) {
            // Update running total on existing payments_made row
            $conn->query("
                UPDATE payments_made
                SET amount = $new_total, status = '$pm_status'
                WHERE id = $pm_id
            ");
        } else {
            // No prior payment found — create a new payments_made record
            $tmp_ref2    = 'PMNT-TMP-' . uniqid();
            $pm_stmt     = $conn->prepare("INSERT INTO payments_made (payment_ref_str, supplier_id, payment_date, amount, status, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $pm_stmt->bind_param("sisdsss", $tmp_ref2, $sup_id, $payment_date, $amount, $pm_status, $payment_method, $note_tag);
            $pm_stmt->execute();
            $pm_id       = $conn->insert_id;
            $real_pm_ref = 'PMNT-' . str_pad($pm_id, 5, '0', STR_PAD_LEFT);
            $conn->query("UPDATE payments_made SET payment_ref_str = '$real_pm_ref' WHERE id = $pm_id");
            $pm_stmt->close();
        }

        // Insert the new installment row
        $pi_stmt = $conn->prepare("INSERT INTO payment_installments (payment_made_id, installment_date, amount, payment_method) VALUES (?, ?, ?, ?)");
        $pi_stmt->bind_param("isds", $pm_id, $payment_date, $amount, $payment_method);
        $pi_stmt->execute();
        $pi_stmt->close();

        // Update PO status if fully paid
        if ($pm_status === 'Fully Paid') {
            $conn->query("UPDATE purchase_orders SET status = 'Received' WHERE id = $purchase_order_id");
            // Auto-mark any supplier credits linked to this PO as Completed.
            // The purchase_order_id column on supplier_credits is added lazily when
            // supplier_credits.php is first loaded — guard against it not existing yet.
            $sc_col_check = $conn->query("SHOW COLUMNS FROM supplier_credits LIKE 'purchase_order_id'");
            if ($sc_col_check && $sc_col_check->num_rows > 0) {
                $conn->query("UPDATE supplier_credits SET status = 'Completed' WHERE purchase_order_id = $purchase_order_id AND status != 'Completed'");
            }
        }

        $conn->commit();
        header("Location: view_payment_bill.php?id=$pm_id&msg=added");
        exit();

    } else {
        throw new Exception("No invoice or purchase order specified.");
    }

} catch (Exception $e) {
    $conn->rollback();
    die("Error logging installment: " . htmlspecialchars($e->getMessage()));
}
