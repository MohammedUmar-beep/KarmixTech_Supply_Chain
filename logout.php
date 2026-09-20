<?php
session_start();
if (isset($_SESSION['user_id'])) {
    require_once 'includes/db.php';
    $login_time = $_SESSION['login_time'] ?? time();
    $duration_seconds = time() - $login_time;
    $duration_mins = round($duration_seconds / 60);
    $duration_str = $duration_mins > 0 ? "$duration_mins minutes" : "$duration_seconds seconds";

    log_activity($conn, 'System', 'Session', $_SESSION['user_id'], "User logged out. Session length: $duration_str.");
}
session_destroy();
header("Location: login.php");
exit();
?>