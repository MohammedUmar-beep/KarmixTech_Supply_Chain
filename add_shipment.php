<?php
require_once 'includes/auth_guard.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['shipment_number_str'])) {
    $shipment_number_str = $conn->real_escape_string($_POST['shipment_number_str']);
    $purchase_order_id = intval($_POST['purchase_order_id']);
    $carrier = $conn->real_escape_string($_POST['carrier'] ?? '');
    $tracking_number = $conn->real_escape_string($_POST['tracking_number'] ?? '');
    $shipment_date = $conn->real_escape_string($_POST['shipment_date']);
    $expected_delivery = !empty($_POST['expected_delivery']) ? $conn->real_escape_string($_POST['expected_delivery']) : NULL;
    $status = $conn->real_escape_string($_POST['status'] ?? 'Shipped');

    if ($purchase_order_id > 0) {
        if ($expected_delivery) {
            $stmt = $conn->prepare("INSERT INTO purchase_shipments (shipment_number_str, purchase_order_id, carrier, tracking_number, status, shipment_date, expected_delivery) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisssss", $shipment_number_str, $purchase_order_id, $carrier, $tracking_number, $status, $shipment_date, $expected_delivery);
        } else {
            $stmt = $conn->prepare("INSERT INTO purchase_shipments (shipment_number_str, purchase_order_id, carrier, tracking_number, status, shipment_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sissss", $shipment_number_str, $purchase_order_id, $carrier, $tracking_number, $status, $shipment_date);
        }

        if ($stmt->execute()) {
            header("Location: shipment.php?msg=added");
        } else {
            header("Location: shipment.php?msg=error_1");
        }
    } else {
        header("Location: shipment.php?msg=error_invalid_data");
    }
} else {
    header("Location: shipment.php");
}
?>