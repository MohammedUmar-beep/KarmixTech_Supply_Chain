<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $return_id_str = $conn->real_escape_string($_POST['return_id_str']);
    $customer_id = intval($_POST['customer_id']);
    $sales_order_id = intval($_POST['sales_order_id']);
    $return_date = $conn->real_escape_string($_POST['return_date']);
    $total_refund_amount = floatval($_POST['total_refund_amount']);
    $reason = $conn->real_escape_string($_POST['reason'] ?? '');

    $status = 'Pending';

    $stmt = $conn->prepare("INSERT INTO sales_returns (return_id_str, customer_id, sales_order_id, return_date, total_refund_amount, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siisdss", $return_id_str, $customer_id, $sales_order_id, $return_date, $total_refund_amount, $reason, $status);

    if ($stmt->execute()) {
        header("Location: sales_returns.php?msg=added");
    } else {
        header("Location: sales_returns.php?msg=error_1");
    }
} else {
    header("Location: sales_returns.php");
}
?>