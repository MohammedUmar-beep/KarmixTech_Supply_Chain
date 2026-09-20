<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $credit_note_str = $conn->real_escape_string($_POST['credit_note_str']);
    $customer_id = intval($_POST['customer_id']);
    $sales_return_id = !empty($_POST['sales_return_id']) ? intval($_POST['sales_return_id']) : NULL;
    $issue_date = $conn->real_escape_string($_POST['issue_date']);
    $credit_amount = floatval($_POST['credit_amount']);
    $reason = $conn->real_escape_string($_POST['reason'] ?? '');

    $status = 'Open';

    if ($sales_return_id !== NULL) {
        $stmt = $conn->prepare("INSERT INTO credit_notes (credit_note_str, customer_id, sales_return_id, issue_date, credit_amount, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisdss", $credit_note_str, $customer_id, $sales_return_id, $issue_date, $credit_amount, $reason, $status);
    } else {
        $stmt = $conn->prepare("INSERT INTO credit_notes (credit_note_str, customer_id, issue_date, credit_amount, reason, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisdss", $credit_note_str, $customer_id, $issue_date, $credit_amount, $reason, $status);
    }

    if ($stmt->execute()) {
        header("Location: credit_notes.php?msg=added");
    } else {
        header("Location: credit_notes.php?msg=error_1");
    }
} else {
    header("Location: credit_notes.php");
}
?>