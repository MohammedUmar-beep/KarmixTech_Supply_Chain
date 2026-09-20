<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['return_number_str'])) {
    $return_number_str = $conn->real_escape_string($_POST['return_number_str']);
    $supplier_id = intval($_POST['supplier_id']);
    $purchase_order_id = !empty($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : NULL;
    $return_date = $conn->real_escape_string($_POST['return_date']);
    $amount = floatval($_POST['amount']);
    $status = $conn->real_escape_string($_POST['status'] ?? 'Pending');

    if ($supplier_id > 0 && $amount > 0) {
        if ($purchase_order_id) {
            $stmt = $conn->prepare("INSERT INTO purchase_returns (return_number_str, supplier_id, purchase_order_id, return_date, amount, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siisds", $return_number_str, $supplier_id, $purchase_order_id, $return_date, $amount, $status);
        } else {
            $stmt = $conn->prepare("INSERT INTO purchase_returns (return_number_str, supplier_id, return_date, amount, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sisds", $return_number_str, $supplier_id, $return_date, $amount, $status);
        }

        if ($stmt->execute()) {
            header("Location: purchase_returns.php?msg=added");
        } else {
            header("Location: purchase_returns.php?msg=error_1");
        }
    } else {
        header("Location: purchase_returns.php?msg=error_invalid_data");
    }
} else {
    header("Location: purchase_returns.php");
}
?>