<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $payment_ref_str = $conn->real_escape_string($_POST['payment_ref_str']);
    $customer_id = intval($_POST['customer_id']);
    // Invoice is optional
    $invoice_id = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : NULL;
    $payment_date = $conn->real_escape_string($_POST['payment_date']);
    $amount = floatval($_POST['amount']);
    $payment_method = $conn->real_escape_string($_POST['payment_method']);
    $notes = $conn->real_escape_string($_POST['notes']);

    // Start transaction to also update invoice status
    $conn->begin_transaction();

    try {
        if ($invoice_id !== NULL) {
            $stmt = $conn->prepare("INSERT INTO payments_received (payment_ref_str, customer_id, invoice_id, payment_date, amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siisdss", $payment_ref_str, $customer_id, $invoice_id, $payment_date, $amount, $payment_method, $notes);
            $stmt->execute();

            // Auto update invoice status to Paid (very basic logic)
            $conn->query("UPDATE invoices SET status = 'Paid' WHERE id = $invoice_id");
        } else {
            $stmt = $conn->prepare("INSERT INTO payments_received (payment_ref_str, customer_id, payment_date, amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisdss", $payment_ref_str, $customer_id, $payment_date, $amount, $payment_method, $notes);
            $stmt->execute();
        }
        $conn->commit();
        header("Location: payments_received.php?msg=added");

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: payments_received.php?msg=error_1");
    }
} else {
    header("Location: payments_received.php");
}
?>