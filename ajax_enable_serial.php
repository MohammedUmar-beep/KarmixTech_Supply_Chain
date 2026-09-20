<?php
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin','manager'])) {
    header("Location: serial_numbers.php"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['product_id'])) {
    $pid = intval($_POST['product_id']);
    $conn->query("UPDATE products SET is_serialised=1 WHERE id=$pid");
    log_activity($conn,'Update','Product',"ID:$pid","Enabled serial number tracking");
}
header("Location: serial_numbers.php");
