<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin'])) {
    header("Location: dashboard.php"); exit();
}

// Handle POST save/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    if ($action === 'save_template') {
        $id        = intval($_POST['id'] ?? 0);
        $channel   = $conn->real_escape_string($_POST['channel']);
        $subject   = $conn->real_escape_string($_POST['subject'] ?? '');
        $body_html = $conn->real_escape_string($_POST['body_html'] ?? '');
        $body_sms  = $conn->real_escape_string($_POST['body_sms'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $conn->query("UPDATE notification_templates SET channel='$channel',subject='$subject',body_html='$body_html',body_sms='$body_sms',is_active=$is_active WHERE id=$id");
        log_activity($conn,'Update','NotificationTemplate',"ID:$id","Updated template");
        header("Location: notification_templates.php?msg=added"); exit();
    }
    if ($action === 'save_settings') {
        $keys = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_from_name','twilio_sid','twilio_token','twilio_from'];
        foreach ($keys as $k) {
            $v = $conn->real_escape_string($_POST[$k] ?? '');
            $conn->query("INSERT INTO business_settings (setting_key,setting_value) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE setting_value='$v'");
        }
        header("Location: notification_templates.php?tab=settings&msg=saved"); exit();
    }
}

$templates = [];
$res = $conn->query("SELECT * FROM notification_templates ORDER BY id");
while ($r = $res->fetch_assoc()) $templates[] = $r;

// Load settings
$settings_res = $conn->query("SELECT setting_key, setting_value FROM business_settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'twilio_%'");
$settings = [];
while ($r = $settings_res->fetch_assoc()) $settings[$r['setting_key']] = $r['setting_value'];

// Queue stats
$q_pending = $conn->query("SELECT COUNT(*) FROM notification_queue WHERE status='pending'")->fetch_row()[0];
$q_sent    = $conn->query("SELECT COUNT(*) FROM notification_queue WHERE status='sent'")->fetch_row()[0];
$q_failed  = $conn->query("SELECT COUNT(*) FROM notification_queue WHERE status='failed'")->fetch_row()[0];

$tab = $_GET['tab'] ?? 'templates';
$page_title   = 'Notification Settings';
$current_page = 'notification_templates.php';
include 'includes/header.php';
?>
<style>
.tpl-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:14px; overflow:hidden; }
.tpl-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; cursor:pointer; }
.tpl-header:hover { background:var(--bg-light); }
.tpl-title { font-size:14px; font-weight:600; }
.tpl-meta  { font-size:12px; color:var(--text-muted); margin-top:3px; }
.tpl-body  { padding:18px; border-top:1px solid var(--border-color); display:none; }
.tpl-body.open { display:block; }
.channel-badge { font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.ch-email { background:rgba(59,130,246,.1); color:#2563eb; }
.ch-sms   { background:rgba(16,185,129,.1); color:#059669; }
.ch-both  { background:rgba(109,74,255,.1); color:var(--primary-color); }
.active-toggle { font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.t-active   { background:rgba(16,185,129,.1); color:#059669; }
.t-inactive { background:rgba(107,114,128,.1); color:#6b7280; }
.var-list { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
.var-tag { font-family:monospace; font-size:11px; background:var(--bg-light); border:1px solid var(--border-color); padding:2px 7px; border-radius:4px; color:var(--primary-color); cursor:pointer; }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left"><h1 style="font-size:24px;">Notifications</h1></div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if (isset($_GET['success'])): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);">Settings saved successfully.</div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:24px;padding:0 24px;border-bottom:1px solid var(--border-color);margin-bottom:0;">
    <a href="?tab=templates" style="padding:10px 0 12px;color:<?= $tab==='templates'?'var(--primary-color)':'var(--text-muted)' ?>;border-bottom:2px solid <?= $tab==='templates'?'var(--primary-color)':'transparent' ?>;font-size:14px;text-decoration:none;font-weight:<?= $tab==='templates'?'600':'400' ?>">Templates</a>
    <a href="?tab=queue"     style="padding:10px 0 12px;color:<?= $tab==='queue'?'var(--primary-color)':'var(--text-muted)' ?>;border-bottom:2px solid <?= $tab==='queue'?'var(--primary-color)':'transparent' ?>;font-size:14px;text-decoration:none;font-weight:<?= $tab==='queue'?'600':'400' ?>">Queue <span style="font-size:10px;padding:1px 6px;border-radius:20px;background:rgba(239,68,68,.1);color:#dc2626;"><?= $q_pending ?></span></a>
    <a href="?tab=settings"  style="padding:10px 0 12px;color:<?= $tab==='settings'?'var(--primary-color)':'var(--text-muted)' ?>;border-bottom:2px solid <?= $tab==='settings'?'var(--primary-color)':'transparent' ?>;font-size:14px;text-decoration:none;font-weight:<?= $tab==='settings'?'600':'400' ?>">SMTP &amp; SMS Config</a>
</div>

<?php if ($tab === 'templates'): ?>
<div style="padding:20px 24px;">
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Edit each template. Use <code>{variable}</code> placeholders — they are replaced automatically when the notification fires.</p>
    <?php foreach ($templates as $t):
        $ch_class = 'ch-'.$t['channel'];
        $vars_map = [
            'order_confirmed'       => ['{customer_name}','{order_id}','{total}','{order_date}'],
            'invoice_due'           => ['{customer_name}','{invoice_number}','{amount}','{due_date}'],
            'shipment_dispatched'   => ['{customer_name}','{order_id}','{tracking_number}'],
            'low_stock_alert'       => ['{product_name}','{stock_level}','{threshold}'],
            'sales_return_approved' => ['{customer_name}','{order_id}','{refund_amount}'],
        ];
        $vars = $vars_map[$t['event_name']] ?? [];
    ?>
    <div class="tpl-card">
        <div class="tpl-header" onclick="toggleTpl(<?= $t['id'] ?>)">
            <div>
                <div class="tpl-title"><?= htmlspecialchars($t['label']) ?></div>
                <div class="tpl-meta">Event: <code><?= htmlspecialchars($t['event_name']) ?></code></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="channel-badge <?= $ch_class ?>"><?= ucfirst($t['channel']) ?></span>
                <span class="active-toggle <?= $t['is_active'] ? 't-active' : 't-inactive' ?>"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span>
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" id="chevron_<?= $t['id'] ?>"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
        </div>
        <div class="tpl-body" id="tpl_body_<?= $t['id'] ?>">
            <form method="POST">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <?php if (!empty($vars)): ?>
                <div style="margin-bottom:12px;">
                    <p style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Click a variable to copy it:</p>
                    <div class="var-list">
                        <?php foreach ($vars as $v): ?>
                        <span class="var-tag" onclick="navigator.clipboard.writeText('<?= $v ?>')" title="Click to copy"><?= $v ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">
                    <div>
                        <label class="form-label">Channel</label>
                        <select name="channel" class="form-select">
                            <option value="email" <?= $t['channel']==='email'?'selected':'' ?>>Email only</option>
                            <option value="sms"   <?= $t['channel']==='sms'?'selected':'' ?>>SMS only</option>
                            <option value="both"  <?= $t['channel']==='both'?'selected':'' ?>>Both</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Email Subject</label>
                        <input type="text" name="subject" class="form-input" value="<?= htmlspecialchars($t['subject'] ?? '') ?>">
                    </div>
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Email Body (HTML)</label>
                    <textarea name="body_html" class="form-input" rows="4" style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($t['body_html'] ?? '') ?></textarea>
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">SMS Body (max 160 chars)</label>
                    <input type="text" name="body_sms" class="form-input" maxlength="160" value="<?= htmlspecialchars($t['body_sms'] ?? '') ?>" placeholder="Leave blank to skip SMS">
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= $t['is_active']?'checked':'' ?>> Active
                    </label>
                    <button type="submit" class="btn btn-primary" style="padding:7px 18px;font-size:13px;">Save Template</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php elseif ($tab === 'queue'): ?>
<div style="padding:20px 24px;">
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <div style="background:var(--bg-light);border-radius:8px;padding:12px 20px;text-align:center;min-width:100px;">
            <div style="font-size:22px;font-weight:700;"><?= $q_pending ?></div>
            <div style="font-size:11px;color:var(--text-muted)">Pending</div>
        </div>
        <div style="background:var(--bg-light);border-radius:8px;padding:12px 20px;text-align:center;min-width:100px;">
            <div style="font-size:22px;font-weight:700;color:var(--success-color)"><?= $q_sent ?></div>
            <div style="font-size:11px;color:var(--text-muted)">Sent</div>
        </div>
        <div style="background:var(--bg-light);border-radius:8px;padding:12px 20px;text-align:center;min-width:100px;">
            <div style="font-size:22px;font-weight:700;color:var(--danger-color)"><?= $q_failed ?></div>
            <div style="font-size:11px;color:var(--text-muted)">Failed</div>
        </div>
    </div>
    <?php
    $queue_res = $conn->query("SELECT nq.*, nt.label FROM notification_queue nq JOIN notification_templates nt ON nt.id=nq.template_id ORDER BY nq.id DESC LIMIT 100");
    $queue_list = [];
    while ($r = $queue_res->fetch_assoc()) $queue_list[] = $r;
    ?>
    <?php if (!empty($queue_list)): ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr>
            <th style="text-align:left;padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Event</th>
            <th style="text-align:left;padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Recipient</th>
            <th style="text-align:left;padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Status</th>
            <th style="text-align:left;padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Attempts</th>
            <th style="text-align:left;padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Queued</th>
        </tr></thead>
        <tbody>
        <?php foreach ($queue_list as $q): $st=$q['status']; $sc=($st==='sent'?'rgba(16,185,129,.1)':($st==='failed'?'rgba(239,68,68,.1)':'rgba(245,158,11,.1)')); $tc=($st==='sent'?'#059669':($st==='failed'?'#dc2626':'#d97706')); ?>
        <tr>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border-color);"><?= htmlspecialchars($q['label']) ?></td>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border-color);"><?= htmlspecialchars($q['recipient_email']??$q['recipient_phone']??'—') ?></td>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border-color);"><span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:<?= $sc ?>;color:<?= $tc ?>"><?= $st ?></span></td>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted)"><?= $q['attempt_count'] ?></td>
            <td style="padding:8px 12px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;"><?= date('d M Y H:i', strtotime($q['scheduled_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:60px;color:var(--text-muted);">No notifications in queue yet.</div>
    <?php endif; ?>
</div>

<?php else: // settings tab ?>
<div style="padding:20px 24px;max-width:700px;">
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:24px;margin-bottom:16px;">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;">SMTP (Email)</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><label class="form-label">SMTP Host</label><input type="text" name="smtp_host" class="form-input" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($settings['smtp_host']??'') ?>"></div>
                <div><label class="form-label">SMTP Port</label><input type="text" name="smtp_port" class="form-input" placeholder="587" value="<?= htmlspecialchars($settings['smtp_port']??'') ?>"></div>
                <div><label class="form-label">SMTP Username</label><input type="text" name="smtp_user" class="form-input" value="<?= htmlspecialchars($settings['smtp_user']??'') ?>"></div>
                <div><label class="form-label">SMTP Password</label><input type="password" name="smtp_pass" class="form-input" placeholder="••••••••"></div>
                <div><label class="form-label">From Email</label><input type="email" name="smtp_from_email" class="form-input" value="<?= htmlspecialchars($settings['smtp_from_email']??'') ?>"></div>
                <div><label class="form-label">From Name</label><input type="text" name="smtp_from_name" class="form-input" value="<?= htmlspecialchars($settings['smtp_from_name']??'') ?>"></div>
            </div>
        </div>
        <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:24px;margin-bottom:16px;">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;">Twilio (SMS)</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div><label class="form-label">Account SID</label><input type="text" name="twilio_sid" class="form-input" value="<?= htmlspecialchars($settings['twilio_sid']??'') ?>"></div>
                <div><label class="form-label">Auth Token</label><input type="password" name="twilio_token" class="form-input" placeholder="••••••••"></div>
                <div><label class="form-label">From Phone</label><input type="text" name="twilio_from" class="form-input" placeholder="+1234567890" value="<?= htmlspecialchars($settings['twilio_from']??'') ?>"></div>
            </div>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">After saving, set up a cron job to run <code>cron_send_notifications.php</code> every 5 minutes:<br><code>*/5 * * * * php /path/to/cron_send_notifications.php</code></p>
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;">Save Configuration</button>
    </form>
</div>
<?php endif; ?>
</main>

<script>
function toggleTpl(id) {
    const body = document.getElementById('tpl_body_'+id);
    const ch   = document.getElementById('chevron_'+id);
    body.classList.toggle('open');
    ch.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
}
</script>
<?php include 'includes/footer.php'; ?>
