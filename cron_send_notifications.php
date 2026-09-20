<?php
/**
 * cron_send_notifications.php
 * Set up a cron: */5 * * * * php /path/to/cron_send_notifications.php
 * This script must only run from the command line (CLI), never via browser.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden: This script may only be run from the command line.');
}
 *
 * Requires: composer require phpmailer/phpmailer twilio/sdk
 */

define('CLI_RUN', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notify_helper.php';

// Load SMTP + Twilio settings
function get_setting(mysqli $conn, string $key): string {
    $r = $conn->query("SELECT setting_value FROM business_settings WHERE setting_key='".$conn->real_escape_string($key)."' LIMIT 1")->fetch_row();
    return $r ? (string)$r[0] : '';
}

$smtp_host  = get_setting($conn, 'smtp_host');
$smtp_port  = (int) get_setting($conn, 'smtp_port') ?: 587;
$smtp_user  = get_setting($conn, 'smtp_user');
$smtp_pass  = get_setting($conn, 'smtp_pass');
$smtp_from  = get_setting($conn, 'smtp_from_email');
$smtp_fname = get_setting($conn, 'smtp_from_name');
$twilio_sid = get_setting($conn, 'twilio_sid');
$twilio_tok = get_setting($conn, 'twilio_token');
$twilio_frm = get_setting($conn, 'twilio_from');

// Fetch up to 50 pending items
$rows = [];
$res  = $conn->query("SELECT nq.*, nt.channel, nt.subject, nt.body_html, nt.body_sms
    FROM notification_queue nq
    JOIN notification_templates nt ON nt.id = nq.template_id
    WHERE nq.status='pending' AND nq.attempt_count < 5
    ORDER BY nq.scheduled_at ASC LIMIT 50");
while ($r = $res->fetch_assoc()) $rows[] = $r;

if (empty($rows)) { echo "[".date('Y-m-d H:i:s')."] No pending notifications.\n"; exit(); }

// PHPMailer (graceful fallback if not installed)
$mailer_available = class_exists('PHPMailer\PHPMailer\PHPMailer') || file_exists(__DIR__.'/vendor/autoload.php');
if ($mailer_available) require_once __DIR__.'/vendor/autoload.php';

foreach ($rows as $row) {
    $vars    = json_decode($row['payload_json'] ?? '{}', true) ?: [];
    $subject = render_template($row['subject']    ?? '', $vars);
    $html    = render_template($row['body_html']  ?? '', $vars);
    $sms     = render_template($row['body_sms']   ?? '', $vars);
    $sent    = false; $error = null;

    // ── Send Email ────────────────────────────────────────────
    if (in_array($row['channel'], ['email','both']) && !empty($row['recipient_email']) && $mailer_available && $smtp_host) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->Port       = $smtp_port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_user;
            $mail->Password   = $smtp_pass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->setFrom($smtp_from, $smtp_fname ?: 'Agile Inventory');
            $mail->addAddress($row['recipient_email'], $row['recipient_name'] ?? '');
            $mail->Subject  = $subject;
            $mail->isHTML(true);
            $mail->Body     = $html;
            $mail->AltBody  = strip_tags($html);
            $mail->send();
            $sent = true;
            $conn->query("INSERT INTO notification_log (queue_id,channel,response_code) VALUES ({$row['id']},'email','250')");
        } catch (\Exception $e) {
            $error = 'Email: '.$e->getMessage();
        }
    } elseif (in_array($row['channel'], ['email','both']) && $smtp_host && !$mailer_available) {
        // Fallback to PHP mail()
        $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: ".($smtp_fname?:'Agile Inventory')." <$smtp_from>\r\n";
        if (@mail($row['recipient_email'], $subject, $html, $headers)) {
            $sent = true;
            $conn->query("INSERT INTO notification_log (queue_id,channel,response_code) VALUES ({$row['id']},'email','200')");
        } else {
            $error = 'mail() failed';
        }
    }

    // ── Send SMS ──────────────────────────────────────────────
    if (in_array($row['channel'], ['sms','both']) && !empty($row['recipient_phone']) && !empty($sms) && $twilio_sid) {
        try {
            if (!class_exists('\Twilio\Rest\Client')) throw new \Exception('Twilio SDK not installed');
            $twilio = new \Twilio\Rest\Client($twilio_sid, $twilio_tok);
            $msg    = $twilio->messages->create($row['recipient_phone'], ['from'=>$twilio_frm,'body'=>$sms]);
            $sent   = true;
            $code   = $msg->status ?? 'queued';
            $conn->query("INSERT INTO notification_log (queue_id,channel,response_code) VALUES ({$row['id']},'sms','".$conn->real_escape_string($code)."')");
        } catch (\Exception $e) {
            $error = ($error ? $error.'; ' : '').'SMS: '.$e->getMessage();
        }
    }

    // ── Update queue row ──────────────────────────────────────
    $attempts = $row['attempt_count'] + 1;
    if ($sent) {
        $conn->query("UPDATE notification_queue SET status='sent', attempt_count=$attempts, sent_at=NOW() WHERE id={$row['id']}");
        echo "[".date('H:i:s')."] Sent #{$row['id']} ({$row['channel']}) to ".($row['recipient_email']??$row['recipient_phone'])."\n";
    } else {
        $new_status = $attempts >= 5 ? 'failed' : 'pending';
        $err_esc    = $conn->real_escape_string($error ?? 'Unknown error');
        $conn->query("UPDATE notification_queue SET status='$new_status', attempt_count=$attempts, error_message='$err_esc' WHERE id={$row['id']}");
        echo "[".date('H:i:s')."] Failed #{$row['id']}: $error\n";
    }
}

echo "[".date('Y-m-d H:i:s')."] Done. Processed ".count($rows)." notification(s).\n";
