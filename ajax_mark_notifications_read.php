<?php
require_once 'includes/auth_guard.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND user_id = $user_id");
echo "OK";
?>