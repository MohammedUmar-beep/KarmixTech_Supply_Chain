<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['payment_ref_str'])) {
    $payment_ref_str = $conn->real_escape_string($_POST['payment_ref_str']);
    $supplier_id = intval($_POST['supplier_id']);
    $amount = floatval($_POST['amount']);
    $payment_date = $conn->real_escape_string($_POST['payment_date']);
    $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Bank Transfer');
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    $status = $conn->real_escape_string($_POST['status'] ?? 'Fully Paid');

    if ($supplier_id > 0 && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO payments_made (payment_ref_str, supplier_id, amount, payment_date, payment_method, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sidssss", $payment_ref_str, $supplier_id, $amount, $payment_date, $payment_method, $notes, $status);

        if ($stmt->execute()) {
            header("Location: payments_made.php?msg=added");
        } else {
            header("Location: payments_made.php?msg=error_1");
        }
    } else {
        header("Location: payments_made.php?msg=error_invalid_data");
    }
} else {
    header("Location: payments_made.php");
}
?>