<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['credit_note_str'])) {
    $credit_note_str   = $conn->real_escape_string($_POST['credit_note_str']);
    $supplier_id       = intval($_POST['supplier_id']);
    $date_issued       = $conn->real_escape_string($_POST['date_issued']);
    $amount            = floatval($_POST['amount']);
    $purchase_order_id = !empty($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : null;

    // Derive initial status: if the linked PO is already fully paid, mark Used immediately
    $status = 'Open';
    if ($purchase_order_id) {
        $po_status_res = $conn->query("SELECT status FROM purchase_orders WHERE id = $purchase_order_id");
        if ($po_status_res && $po_row = $po_status_res->fetch_assoc()) {
            if ($po_row['status'] === 'Received') $status = 'Completed';
        }
    }

    if ($supplier_id > 0 && $amount > 0) {
        if ($purchase_order_id) {
            $stmt = $conn->prepare("INSERT INTO supplier_credits (credit_note_str, supplier_id, purchase_order_id, date_issued, amount, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siisds", $credit_note_str, $supplier_id, $purchase_order_id, $date_issued, $amount, $status);
        } else {
            $stmt = $conn->prepare("INSERT INTO supplier_credits (credit_note_str, supplier_id, date_issued, amount, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sisds", $credit_note_str, $supplier_id, $date_issued, $amount, $status);
        }

        if ($stmt->execute()) {
            header("Location: supplier_credits.php?msg=added");
        } else {
            header("Location: supplier_credits.php?msg=error_1");
        }
    } else {
        header("Location: supplier_credits.php?msg=error_invalid_data");
    }
} else {
    header("Location: supplier_credits.php");
}
?>
