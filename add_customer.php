<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $customer_id_str = $conn->real_escape_string($_POST['customer_id_str']);
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $contact_number = $conn->real_escape_string($_POST['phone_ext'] ?? '+91') . ' ' . $conn->real_escape_string($_POST['phone_num'] ?? '');
    $email = $conn->real_escape_string($_POST['email']);
    $address = $conn->real_escape_string($_POST['address']);
    $status = 'Active';

    $stmt = $conn->prepare("INSERT INTO customers (customer_id_str, customer_name, contact_number, email, address, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $customer_id_str, $customer_name, $contact_number, $email, $address, $status);

    if ($stmt->execute()) {
        log_activity($conn, 'Create', 'Customer', $customer_id_str, "Added customer: $customer_name");
        header("Location: customers.php?msg=added");
    } else {
        header("Location: customers.php?msg=error_1");
    }
} else {
    header("Location: customers.php");
}
?>