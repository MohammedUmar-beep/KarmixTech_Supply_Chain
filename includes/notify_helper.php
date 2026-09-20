<?php
/**
 * notify_helper.php
 * Include this wherever you need to queue a notification.
 * Usage:
 *   require_once 'includes/notify_helper.php';
 *   queue_notification($conn, 'order_confirmed', [
 *       'customer_name' => 'Ravi Kumar',
 *       'order_id'      => 'SO-0042',
 *       'total'         => '₹1,200',
 *       'order_date'    => '17 Mar 2026',
 *   ], 'customer', $customer_id);
 */

function queue_notification(mysqli $conn, string $event_name, array $vars, string $recipient_type = 'customer', int $recipient_id = 0): bool
{
    // Load template
    $en   = $conn->real_escape_string($event_name);
    $tpl  = $conn->query("SELECT * FROM notification_templates WHERE event_name='$en' AND is_active=1 LIMIT 1")->fetch_assoc();
    if (!$tpl) return false;

    // Resolve recipient contact details
    $email = null; $phone = null; $name = null;

    if ($recipient_type === 'customer' && $recipient_id) {
        $c = $conn->query("SELECT customer_name, email, contact_number FROM customers WHERE id=$recipient_id")->fetch_assoc();
        if ($c) { $email = $c['email']; $phone = $c['contact_number']; $name = $c['customer_name']; }
    } elseif ($recipient_type === 'admin') {
        // Send to all admin users
        $ar = $conn->query("SELECT email, name FROM users WHERE role='admin' LIMIT 5");
        while ($a = $ar->fetch_assoc()) {
            _insert_queue_row($conn, $tpl['id'], $a['email'], null, $a['name'], $vars);
        }
        return true;
    }

    if (!$email && !$phone) return false;
    return _insert_queue_row($conn, $tpl['id'], $email, $phone, $name, $vars);
}

function _insert_queue_row(mysqli $conn, int $tpl_id, ?string $email, ?string $phone, ?string $name, array $vars): bool
{
    $email   = $email   ? $conn->real_escape_string($email)   : 'NULL';
    $phone   = $phone   ? $conn->real_escape_string($phone)   : 'NULL';
    $name    = $name    ? $conn->real_escape_string($name)     : 'NULL';
    $payload = $conn->real_escape_string(json_encode($vars));
    $eq      = $email === 'NULL' ? 'NULL' : "'$email'";
    $pq      = $phone === 'NULL' ? 'NULL' : "'$phone'";
    $nq      = $name  === 'NULL' ? 'NULL' : "'$name'";
    return $conn->query(
        "INSERT INTO notification_queue (template_id, recipient_email, recipient_phone, recipient_name, payload_json, status)
         VALUES ($tpl_id, $eq, $pq, $nq, '$payload', 'pending')"
    );
}

/**
 * Render a template string by replacing {variable} placeholders.
 */
function render_template(string $template, array $vars): string
{
    foreach ($vars as $k => $v) {
        $template = str_replace('{'.$k.'}', htmlspecialchars((string)$v, ENT_QUOTES), $template);
    }
    return $template;
}
