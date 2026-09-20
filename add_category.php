<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_id_str = $conn->real_escape_string($_POST['category_id_str']);
    $category_name = $conn->real_escape_string($_POST['category_name']);
    $description = $conn->real_escape_string($_POST['description']);
    $status = 'Active'; // default

    $stmt = $conn->prepare("INSERT INTO categories (category_id_str, category_name, description, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $category_id_str, $category_name, $description, $status);

    if ($stmt->execute()) {
        log_activity($conn, 'Create', 'Category', $category_name, "Added new category.");
        header("Location: categories.php?msg=added");
    } else {
        header("Location: categories.php?msg=error_1");
    }
} else {
    header("Location: categories.php");
}
?>