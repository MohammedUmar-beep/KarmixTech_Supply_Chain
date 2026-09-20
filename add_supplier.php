<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $supplier_id_str = $conn->real_escape_string($_POST['supplier_id_str']);
    $supplier_name = $conn->real_escape_string($_POST['supplier_name']);
    $contact_person = $conn->real_escape_string($_POST['contact_person'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $phone = $conn->real_escape_string($_POST['phone_ext'] ?? '+91') . ' ' . $conn->real_escape_string($_POST['phone_num'] ?? '');
    $address = $conn->real_escape_string(trim($_POST['address'] ?? ''));

    $stmt = $conn->prepare("INSERT INTO suppliers (supplier_id_str, supplier_name, contact_person, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    $stmt->bind_param("ssssss", $supplier_id_str, $supplier_name, $contact_person, $email, $phone, $address);

    if ($stmt->execute()) {
        log_activity($conn, 'Create', 'Supplier', $supplier_id_str, "Added supplier: $supplier_name");
        header("Location: suppliers.php?msg=added");
    } else {
        header("Location: suppliers.php?msg=error_1");
    }
} else {
    header("Location: suppliers.php");
}
?>