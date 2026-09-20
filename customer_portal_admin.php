<?php
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin','manager'])) {
    header("Location: dashboard.php"); exit();
}

$error=''; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $cid    = intval($_POST['customer_id']);
    $email  = $conn->real_escape_string(trim($_POST['portal_email']));
    $pass   = $_POST['temp_password'] ?? bin2hex(random_bytes(5));
    $hash   = password_hash($pass, PASSWORD_DEFAULT);

    $cust   = $conn->query("SELECT * FROM customers WHERE id=$cid")->fetch_assoc();
    if (!$cust) { $error='Customer not found.'; }
    else {
        $existing = $conn->query("SELECT id FROM customer_portal_users WHERE customer_id=$cid")->fetch_row();
        if ($existing) {
            // Update password
            $conn->query("UPDATE customer_portal_users SET email='$email',password_hash='$hash',is_active=1 WHERE customer_id=$cid");
            $success = "Portal access updated for {$cust['customer_name']}. Temp password: <strong>$pass</strong>";
        } else {
            $conn->query("INSERT INTO customer_portal_users (customer_id,email,password_hash) VALUES ($cid,'$email','$hash')");
            $success = "Portal invite created for {$cust['customer_name']}. Share these credentials:<br>Email: <strong>$email</strong> | Temp password: <strong>$pass</strong>";
        }
        log_activity($conn,'Create','CustomerPortalInvite',"Customer:$cid","Portal access granted to $email");
        // Queue welcome email
        if (file_exists('includes/notify_helper.php')) {
            require_once 'includes/notify_helper.php';
            queue_notification($conn,'order_confirmed',['customer_name'=>$cust['customer_name'],'order_id'=>'Portal Access','total'=>'N/A','order_date'=>date('d M Y')],'customer',$cid);
        }
    }
}

$customers = [];
$cr = $conn->query("SELECT c.id, c.customer_name, c.email,
    (SELECT pu.id FROM customer_portal_users pu WHERE pu.customer_id=c.id) AS has_portal
    FROM customers c WHERE c.status='Active' ORDER BY c.customer_name");
while ($r=$cr->fetch_assoc()) $customers[]=$r;

$page_title='Customer Portal Management'; $current_page='customer_portal_admin.php';
include 'includes/header.php';
?>
<main class="main-area">
<div class="topbar">
    <div class="topbar-left"><h1 style="font-size:24px;">Customer Portal Access</h1></div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if ($success): ?><div style="margin:12px 24px;padding:12px 16px;border-radius:8px;font-size:13px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);"><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div style="margin:12px 24px;padding:12px 16px;border-radius:8px;font-size:13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--danger-color);"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;padding:20px 24px 40px;">
    <!-- Invite form -->
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:22px;">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:4px;">Invite / Reset Access</h3>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">Grant a customer access to the self-service portal. A temporary password will be generated.</p>
        <form method="POST">
            <div style="margin-bottom:14px;">
                <label class="form-label">Customer <span style="color:#ef4444">*</span></label>
                <select name="customer_id" class="form-select" required>
                    <option value="">— Select customer —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?> <?= $c['has_portal']?'(has access)':'' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Portal Login Email <span style="color:#ef4444">*</span></label>
                <input type="email" name="portal_email" class="form-input" placeholder="customer@example.com" required>
            </div>
            <div style="margin-bottom:18px;">
                <label class="form-label">Temporary Password (leave blank to auto-generate)</label>
                <input type="text" name="temp_password" class="form-input" placeholder="Auto-generated if empty">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;font-size:14px;">Grant Access</button>
        </form>
        <p style="font-size:11px;color:var(--text-muted);margin-top:10px;">Portal URL: <code><?= (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']).'/portal/' ?></code></p>
    </div>

    <!-- Customer list -->
    <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);font-size:15px;font-weight:600;">All Customers</div>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead><tr>
                <th style="text-align:left;padding:10px 16px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Customer</th>
                <th style="text-align:left;padding:10px 16px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Email</th>
                <th style="text-align:left;padding:10px 16px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Portal Access</th>
                <th style="text-align:left;padding:10px 16px;border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:12px;">Last Login</th>
            </tr></thead>
            <tbody>
            <?php foreach ($customers as $c):
                $pu=null;
                if ($c['has_portal']) $pu=$conn->query("SELECT * FROM customer_portal_users WHERE customer_id={$c['id']}")->fetch_assoc();
            ?>
            <tr>
                <td style="padding:10px 16px;border-bottom:1px solid var(--border-color);font-weight:500"><?= htmlspecialchars($c['customer_name']) ?></td>
                <td style="padding:10px 16px;border-bottom:1px solid var(--border-color);color:var(--text-muted)"><?= htmlspecialchars($c['email']) ?></td>
                <td style="padding:10px 16px;border-bottom:1px solid var(--border-color);">
                    <?php if ($pu): ?>
                    <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:rgba(16,185,129,.12);color:#059669;">Active</span>
                    <?php else: ?>
                    <span style="font-size:11px;color:var(--text-muted);">Not invited</span>
                    <?php endif; ?>
                </td>
                <td style="padding:10px 16px;border-bottom:1px solid var(--border-color);font-size:12px;color:var(--text-muted)">
                    <?= $pu && $pu['last_login'] ? date('d M Y H:i',strtotime($pu['last_login'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</main>
<?php include 'includes/footer.php'; ?>
