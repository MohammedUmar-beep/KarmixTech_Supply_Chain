<?php
require_once 'includes/auth_guard.php';

// Role check — only admin/manager may save coupons
if (!in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: coupons.php");
    exit();
}

// CSRF verification
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    header("Location: coupons.php?msg=error_csrf");
    exit();
}

// Sanitise inputs
$code            = strtoupper(trim($_POST['code']    ?? ''));
$name            = trim($_POST['name']               ?? '');
$type            = $_POST['type']                    ?? 'fixed';
$value           = floatval($_POST['value']          ?? 0);
$applies_to      = $_POST['applies_to']              ?? 'All Products';
$applies_target  = trim($_POST['applies_target']     ?? '');
$min_order_value = floatval($_POST['min_order_value']?? 0);

$start_date  = !empty($_POST['start_date'])                                 ? $_POST['start_date'] : null;
$end_date    = (!empty($_POST['end_date']) && empty($_POST['no_duration'])) ? $_POST['end_date']   : null;
$usage_limit = (!empty($_POST['usage_limit']) && empty($_POST['no_limit'])) ? intval($_POST['usage_limit']) : null;

// Graceful migration
$conn->query("ALTER TABLE coupons ADD COLUMN IF NOT EXISTS applies_target varchar(255) DEFAULT NULL");

$is_edit   = isset($_POST['coupon_id']) && (int)$_POST['coupon_id'] > 0;
$coupon_id = $is_edit ? (int)$_POST['coupon_id'] : 0;

if ($is_edit) {
    $stmt = $conn->prepare(
        "UPDATE coupons
            SET code=?, name=?, type=?, value=?,
                applies_to=?, applies_target=?, min_order_value=?,
                start_date=?, end_date=?, usage_limit=?
          WHERE id=?"
    );
    $stmt->bind_param("sssdssdssii",
        $code, $name, $type, $value,
        $applies_to, $applies_target, $min_order_value,
        $start_date, $end_date, $usage_limit, $coupon_id
    );
    if ($stmt->execute()) {
        log_activity($conn, 'Update', 'Coupon', "ID: $coupon_id", "Updated coupon $code");
        $stmt->close();
        header("Location: coupons.php?msg=updated");
    } else {
        $stmt->close();
        header("Location: edit_coupon.php?id=$coupon_id&msg=error_db");
    }
} else {
    $stmt = $conn->prepare(
        "INSERT INTO coupons
            (code, name, type, value, applies_to, applies_target, min_order_value, start_date, end_date, usage_limit, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')"
    );
    $stmt->bind_param("sssdssdssii",
        $code, $name, $type, $value,
        $applies_to, $applies_target, $min_order_value,
        $start_date, $end_date, $usage_limit
    );
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        log_activity($conn, 'Add', 'Coupon', "ID: $new_id", "Created coupon $code");
        $stmt->close();
        header("Location: coupons.php?msg=added");
    } else {
        $stmt->close();
        header("Location: add_coupon.php?msg=error_db");
    }
}
exit();
?>
