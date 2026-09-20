<?php
require_once 'includes/auth_guard.php';

if ($_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$success = '';
$error   = '';

// Handle POST: Create Tax Rule
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_tax') {
    $tax_name = $conn->real_escape_string(trim($_POST['tax_name']));
    $rate     = floatval($_POST['rate_percent']);

    $stmt = $conn->prepare("INSERT INTO tax_rules (tax_name, rate_percent) VALUES (?, ?)");
    $stmt->bind_param("sd", $tax_name, $rate);
    if ($stmt->execute()) {
        $success = "Tax rule '$tax_name' created.";
        log_activity($conn, 'Create', 'Tax Rule', $conn->insert_id, "Created tax $tax_name ($rate%)");
    } else {
        $error = "Error creating rule.";
    }
    $stmt->close();
}

// Handle GET: Toggle / Delete
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($_GET['action'] === 'toggle') {
        $conn->query("UPDATE tax_rules SET is_active = NOT is_active WHERE id = $id");
        $success = "Tax rule status updated.";
    } elseif ($_GET['action'] === 'delete') {
        $conn->query("DELETE FROM tax_rules WHERE id = $id");
        $success = "Tax rule deleted.";
    }
    header("Location: settings_taxes.php?msg=" . urlencode($success));
    exit();
}

if (isset($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg']);
}

// Fetch active tax rules and compute total rate
$taxes      = $conn->query("SELECT * FROM tax_rules ORDER BY is_active DESC, tax_name ASC");
$total_rate_res = $conn->query("SELECT SUM(rate_percent) FROM tax_rules WHERE is_active = 1");
$total_rate = $total_rate_res ? floatval($total_rate_res->fetch_row()[0]) : 0;

$current_page = 'settings.php';
$page_title   = 'Tax Rules';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="settings.php" class="back-link">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Settings
            </a>
            <h1>Tax Rules</h1>
        </div>
    </div>

    <div style="padding: 24px; max-width: 780px;">

        <?php if ($success): ?>
            <div style="background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.25); color:var(--success-color); padding:10px 16px; border-radius:8px; margin-bottom:18px; font-size:13px;"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#ef4444; padding:10px 16px; border-radius:8px; margin-bottom:18px; font-size:13px;"><?= $error ?></div>
        <?php endif; ?>

        <!-- Summary Banner -->
        <div style="background:var(--primary-bg, rgba(109,74,255,.06)); border:1px solid var(--primary-color); border-radius:10px; padding:16px 20px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <div style="font-size:12px; color:var(--text-muted); margin-bottom:2px;">Effective Tax Rate (all active rules)</div>
                <div style="font-size:28px; font-weight:700; color:var(--primary-color);"><?= number_format($total_rate, 2) ?>%</div>
            </div>
            <div style="font-size:12px; color:var(--text-muted); text-align:right; line-height:1.6;">
                Applied globally to all sales<br>after any coupon discounts
            </div>
        </div>

        <!-- Add Rule Form -->
        <div class="form-container" style="margin:0 0 24px;">
            <h3 style="font-size:15px; font-weight:600; margin-bottom:16px;">Add Tax Rule</h3>
            <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="action" value="add_tax">
                <div style="flex:2; min-width:160px;">
                    <label style="font-size:12px; font-weight:500; margin-bottom:5px; display:block;">Tax Name</label>
                    <input type="text" name="tax_name" placeholder="e.g. GST, VAT, Service Tax" required style="width:100%;">
                </div>
                <div style="flex:1; min-width:110px;">
                    <label style="font-size:12px; font-weight:500; margin-bottom:5px; display:block;">Rate (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="rate_percent" placeholder="0.00" required style="width:100%;">
                </div>
                <button type="submit" class="btn btn-primary" style="height:38px; padding:0 20px; white-space:nowrap;">Add Rule</button>
            </form>
        </div>

        <!-- Rules Table -->
        <div class="form-container" style="margin:0;">
            <h3 style="font-size:15px; font-weight:600; margin-bottom:16px;">Active Tax Rules</h3>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="padding:10px 12px; text-align:left; border-bottom:1px solid var(--border-color); font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted);">Name</th>
                        <th style="padding:10px 12px; text-align:left; border-bottom:1px solid var(--border-color); font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted);">Rate</th>
                        <th style="padding:10px 12px; text-align:left; border-bottom:1px solid var(--border-color); font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted);">Status</th>
                        <th style="padding:10px 12px; text-align:right; border-bottom:1px solid var(--border-color); font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($taxes->num_rows > 0): ?>
                        <?php while ($t = $taxes->fetch_assoc()): ?>
                            <tr>
                                <td style="padding:12px; border-bottom:1px solid var(--border-color); font-weight:500; color:var(--text-dark);"><?= htmlspecialchars($t['tax_name']) ?></td>
                                <td style="padding:12px; border-bottom:1px solid var(--border-color); font-size:16px; font-weight:700; color:var(--primary-color);"><?= $t['rate_percent'] ?>%</td>
                                <td style="padding:12px; border-bottom:1px solid var(--border-color);">
                                    <?php if ($t['is_active']): ?>
                                        <span style="font-size:11px; background:rgba(16,185,129,.12); color:var(--success-color); border:1px solid rgba(16,185,129,.2); padding:3px 9px; border-radius:20px; font-weight:600;">Active</span>
                                    <?php else: ?>
                                        <span style="font-size:11px; background:var(--bg-light); color:var(--text-muted); border:1px solid var(--border-color); padding:3px 9px; border-radius:20px; font-weight:600;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px; border-bottom:1px solid var(--border-color); text-align:right;">
                                    <a href="?action=toggle&id=<?= $t['id'] ?>" class="btn-outline" style="padding:5px 10px; font-size:12px; text-decoration:none; margin-right:4px;">
                                        <?= $t['is_active'] ? 'Disable' : 'Enable' ?>
                                    </a>
                                    <a href="?action=delete&id=<?= $t['id'] ?>" class="btn-outline" style="padding:5px 10px; font-size:12px; text-decoration:none; color:var(--danger-color); border-color:var(--danger-color);"
                                        onclick="event.preventDefault(); showCustomConfirm('Delete this tax rule?', () => { window.location.href = this.href || this.getAttribute('href'); });">Delete</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="padding:24px; text-align:center; color:var(--text-muted); font-size:13px;">No tax rules configured yet. Add one above.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($total_rate > 0): ?>
                <tfoot>
                    <tr style="background:var(--bg-light);">
                        <td colspan="2" style="padding:10px 12px; font-size:13px; font-weight:600; color:var(--text-dark);">Total effective rate</td>
                        <td colspan="2" style="padding:10px 12px; font-size:15px; font-weight:700; color:var(--primary-color); text-align:right;"><?= number_format($total_rate, 2) ?>%</td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <p style="margin-top:16px; font-size:12px; color:var(--text-muted); line-height:1.6;">
            <strong>How it works:</strong> All active tax rules are summed and applied as a single combined rate on every sale (in POS, Sales Orders, and Invoices), calculated on the post-discount subtotal. Manage coupons and discount codes in the <a href="coupons.php" style="color:var(--primary-color);">Coupons</a> tab.
        </p>

    </div>
</main>

<?php include 'includes/footer.php'; ?>
