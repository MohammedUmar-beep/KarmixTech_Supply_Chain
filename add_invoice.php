<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $invoice_number = $conn->real_escape_string($_POST['invoice_number']);
    $customer_id = intval($_POST['customer_id']);
    // Sales order is optional for manual invoice creation
    $sales_order_id = !empty($_POST['sales_order_id']) ? intval($_POST['sales_order_id']) : NULL;
    $invoice_date = $conn->real_escape_string($_POST['invoice_date']);
    $due_date = $conn->real_escape_string($_POST['due_date']);
    $amount = floatval($_POST['amount']);

    $status = 'Unpaid'; // default

    // Insert Invoice
    if ($sales_order_id !== NULL) {
        $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, customer_id, sales_order_id, invoice_date, due_date, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siissds", $invoice_number, $customer_id, $sales_order_id, $invoice_date, $due_date, $amount, $status);
    } else {
        $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, amount, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissds", $invoice_number, $customer_id, $invoice_date, $due_date, $amount, $status);
    }

    if ($stmt->execute()) {
        header("Location: invoices.php?msg=added");
    } else {
        header("Location: invoices.php?msg=error_1");
    }
} else {
    header("Location: invoices.php");
}
?>