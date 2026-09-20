<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Basic sanitization
    $warehouse_name = $conn->real_escape_string($_POST['warehouse_name']);
    $warehouse_id_str = $conn->real_escape_string($_POST['warehouse_id_str']);

    $country_code = $_POST['country_code'] ?? '+1';
    $contact_num = $_POST['contact_num'] ?? '';
    $contact_number = $conn->real_escape_string($country_code . ' ' . $contact_num);
    $email = $conn->real_escape_string($_POST['email']);
    $state = $conn->real_escape_string($_POST['state']);
    $pincode = $conn->real_escape_string($_POST['pincode']);
    $address = $conn->real_escape_string($_POST['address']);
    $capacity = intval($_POST['capacity']);

    $stmt = $conn->prepare("INSERT INTO warehouses (warehouse_name, warehouse_id_str, contact_number, email, state, pincode, address, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssi", $warehouse_name, $warehouse_id_str, $contact_number, $email, $state, $pincode, $address, $capacity);

    if ($stmt->execute()) {
        header("Location: warehouses.php?msg=added");
    } else {
        // Simple error handling for now (e.g. unique constraint violation)
        header("Location: warehouses.php?msg=error_1");
    }
} else {
    header("Location: warehouses.php");
}
?>