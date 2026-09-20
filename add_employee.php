<?php
require_once 'includes/auth_guard.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: employees.php?msg=error_unauthorized");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['full_name'])) {
    $full_name          = $conn->real_escape_string($_POST['full_name']);
    $employee_id_str    = "EMP-" . str_pad(rand(1, 9999), 4, "0", STR_PAD_LEFT);
    $warehouse_id       = $conn->real_escape_string($_POST['warehouse_id'] ?? '');
    $email              = $conn->real_escape_string($_POST['email']);
    $phone              = $conn->real_escape_string($_POST['phone_ext'] ?? '+91') . ' ' . $conn->real_escape_string($_POST['phone_num'] ?? '');
    $government_id_type = $conn->real_escape_string($_POST['government_id_type'] ?? '');
    $state              = $conn->real_escape_string($_POST['state'] ?? '');
    $pincode            = $conn->real_escape_string($_POST['pincode'] ?? '');
    $address            = $conn->real_escape_string($_POST['address'] ?? '');
    $hire_date          = $conn->real_escape_string($_POST['hire_date'] ?? date('Y-m-d'));
    $status             = $conn->real_escape_string($_POST['status'] ?? 'On Duty');

    // ── Validate: documents are mandatory ────────────────────────────
    $has_files = false;
    if (isset($_FILES['employee_docs']) && is_array($_FILES['employee_docs']['error'])) {
        foreach ($_FILES['employee_docs']['error'] as $err) {
            if ($err === UPLOAD_ERR_OK) { $has_files = true; break; }
        }
    }
    if (!$has_files) {
        header("Location: employees.php?msg=error_docs_required&add=1");
        exit();
    }

    // ── Handle File Uploads ──────────────────────────────────────────
    $uploaded_paths = [];
    $upload_dir = 'uploads/employees/' . $employee_id_str . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    foreach ($_FILES['employee_docs']['error'] as $key => $error) {
        if ($error == UPLOAD_ERR_OK) {
            $tmp_name    = $_FILES['employee_docs']['tmp_name'][$key];
            $name        = basename($_FILES['employee_docs']['name'][$key]);
            $target_path = $upload_dir . uniqid() . '_' . $name;
            if (move_uploaded_file($tmp_name, $target_path)) {
                $uploaded_paths[] = $target_path;
            }
        }
    }
    $document_paths = !empty($uploaded_paths) ? json_encode($uploaded_paths) : NULL;

    // ── Handle Profile Picture Upload (optional) ───────────────────────
    $profile_picture_path = NULL;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $pp_tmp  = $_FILES['profile_picture']['tmp_name'];
        $pp_name = basename($_FILES['profile_picture']['name']);
        $pp_ext  = strtolower(pathinfo($pp_name, PATHINFO_EXTENSION));
        $allowed_pp_exts = ['jpg','jpeg','png','gif','webp'];
        if (in_array($pp_ext, $allowed_pp_exts)) {
            $pp_dir = 'uploads/employees/' . $employee_id_str . '/';
            if (!is_dir($pp_dir)) mkdir($pp_dir, 0777, true);
            $pp_path = $pp_dir . 'profile_' . uniqid() . '.' . $pp_ext;
            if (move_uploaded_file($pp_tmp, $pp_path)) {
                $profile_picture_path = $pp_path;
            }
        }
    }

    if (!empty($full_name) && !empty($email)) {
        $stmt = $conn->prepare(
            "INSERT INTO employees (full_name, employee_id_str, warehouse_id, email, phone, government_id_type, state, pincode, address, hire_date, status, document_paths, profile_picture)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssssssssssss",
            $full_name, $employee_id_str, $warehouse_id, $email, $phone,
            $government_id_type, $state, $pincode, $address, $hire_date, $status, $document_paths, $profile_picture_path
        );

        if ($stmt->execute()) {
            header("Location: employees.php?msg=added");
        } else {
            header("Location: employees.php?msg=error_1");
        }
        $stmt->close();
    } else {
        header("Location: employees.php?msg=error_invalid_data");
    }
} else {
    header("Location: employees.php");
}
?>
