<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Save loyalty config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_config') {
    $ppu  = floatval($_POST['points_per_unit']);
    $rate = floatval($_POST['redemption_rate']);
    $min  = intval($_POST['min_points_redeem']);
    $maxp = floatval($_POST['max_redemption_pct']);
    $expd = intval($_POST['points_expiry_days']);
    $act  = isset($_POST['is_active']) ? 1 : 0;
    $conn->query("UPDATE loyalty_config SET points_per_unit=$ppu, redemption_rate=$rate, min_points_redeem=$min, max_redemption_pct=$maxp, points_expiry_days=$expd, is_active=$act WHERE id=1");
    log_activity($conn,'Update','LoyaltyConfig','1','Updated loyalty program settings');
    header("Location: loyalty_program.php?msg=added"); exit();
}

// Manual points adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'adjust_points') {
    $cid    = intval($_POST['customer_id']);
    $pts    = intval($_POST['points']);
    $type   = in_array($_POST['type'], ['Earned','Adjusted','Reversed']) ? $_POST['type'] : 'Adjusted';
    $notes  = $conn->real_escape_string($_POST['notes'] ?? '');
    // Get current balance
    $cur    = $conn->query("SELECT loyalty_points_balance FROM customers WHERE id=$cid")->fetch_row()[0] ?? 0;
    $new_bal = max(0, $cur + ($type === 'Reversed' ? -$pts : $pts));
    $conn->query("UPDATE customers SET loyalty_points_balance=$new_bal WHERE id=$cid");
    $conn->query("INSERT INTO loyalty_points_ledger (customer_id,transaction_type,points,balance_after,notes) VALUES ($cid,'$type',$pts,$new_bal,'$notes')");
    log_activity($conn,'Update','Loyalty',"Customer:$cid","Manual $type: $pts pts (new balance: $new_bal)");
    header("Location: loyalty_program.php?msg=updated"); exit();
}

// Load config
$cfg = $conn->query("SELECT * FROM loyalty_config WHERE id=1")->fetch_assoc();

// Stats
$total_customers_enrolled = $conn->query("SELECT COUNT(*) FROM customers WHERE loyalty_points_balance > 0")->fetch_row()[0];
$total_points_outstanding = $conn->query("SELECT COALESCE(SUM(loyalty_points_balance),0) FROM customers")->fetch_row()[0];
$total_earned_ever = $conn->query("SELECT COALESCE(SUM(points),0) FROM loyalty_points_ledger WHERE transaction_type='Earned'")->fetch_row()[0];
$total_redeemed    = $conn->query("SELECT COALESCE(SUM(points),0) FROM loyalty_points_ledger WHERE transaction_type='Redeemed'")->fetch_row()[0];

// Top customers by points
$top_customers = [];
$tc = $conn->query("SELECT c.id, c.customer_name, c.email, c.loyalty_points_balance,
    (SELECT COUNT(*) FROM loyalty_points_ledger l WHERE l.customer_id=c.id AND l.transaction_type='Earned') AS earn_count
    FROM customers c WHERE c.loyalty_points_balance > 0
    ORDER BY c.loyalty_points_balance DESC LIMIT 15");
while ($r = $tc->fetch_assoc()) $top_customers[] = $r;

// Recent ledger
$recent_ledger = [];
$rl = $conn->query("SELECT l.*, c.customer_name FROM loyalty_points_ledger l
    JOIN customers c ON c.id=l.customer_id
    ORDER BY l.id DESC LIMIT 30");
while ($r = $rl->fetch_assoc()) $recent_ledger[] = $r;

// Customers list for manual adjustment
$customers_list = [];
$cl = $conn->query("SELECT id, customer_name, loyalty_points_balance FROM customers WHERE status='Active' ORDER BY customer_name");
while ($r = $cl->fetch_assoc()) $customers_list[] = $r;

$tab = $_GET['tab'] ?? 'overview';
$page_title   = 'Loyalty Program';
$current_page = 'loyalty_program.php';
include 'includes/header.php';
?>
<style>
.lp-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; padding:16px 24px; }
.lp-kpi { background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:16px; }
.lp-kpi-val { font-size:24px; font-weight:700; }
.lp-kpi-lbl { font-size:12px; color:var(--text-muted); margin-top:3px; }
.lp-table { width:100%; border-collapse:collapse; }
.lp-table th { font-size:12px; color:var(--text-muted); font-weight:600; padding:10px 14px; border-bottom:1px solid var(--border-color); text-align:left; }
.lp-table td { font-size:13px; padding:10px 14px; border-bottom:1px solid var(--border-color); }
.lp-table tr:hover td { background:var(--bg-light); }
.pts-badge { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:700; padding:3px 9px; border-radius:20px; }
.pts-earned   { background:rgba(16,185,129,.12); color:#059669; }
.pts-redeemed { background:rgba(109,74,255,.1); color:var(--primary-color); }
.pts-adjusted { background:rgba(59,130,246,.1); color:#2563eb; }
.pts-reversed { background:rgba(239,68,68,.1); color:#dc2626; }
.pts-expired  { background:rgba(107,114,128,.1); color:#6b7280; }
.tab-link { padding:10px 0 12px; color:var(--text-muted); border-bottom:2px solid transparent; font-size:14px; text-decoration:none; transition:color .15s, border-color .15s; }
.tab-link.active { color:var(--primary-color); border-bottom-color:var(--primary-color); font-weight:600; }
.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.config-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:24px; max-width:700px; }
.points-bar-bg { height:6px; border-radius:3px; background:var(--border-color); overflow:hidden; margin-top:6px; }
.points-bar-fill { height:100%; border-radius:3px; background:var(--primary-color); }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left"><h1 style="font-size:24px;">Loyalty Program</h1></div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if (isset($_GET['success'])): ?>
<div style="margin:12px 24px;padding:10px 16px;border-radius:8px;font-size:13px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);">
    <?= $_GET['success']==='adjusted' ? 'Points adjusted successfully.' : 'Loyalty settings saved.' ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:24px;padding:0 24px;border-bottom:1px solid var(--border-color);">
    <a href="?tab=overview"   class="tab-link <?= $tab==='overview'?'active':'' ?>">Overview</a>
    <a href="?tab=customers"  class="tab-link <?= $tab==='customers'?'active':'' ?>">Customers</a>
    <a href="?tab=ledger"     class="tab-link <?= $tab==='ledger'?'active':'' ?>">Transaction Ledger</a>
    <a href="?tab=settings"   class="tab-link <?= $tab==='settings'?'active':'' ?>">Settings</a>
</div>

<?php if ($tab === 'overview'): ?>
<!-- KPIs -->
<div class="page-metrics-grid" style="padding: 0 24px; margin-bottom: 20px;">
    <div class="page-metric-card border-blue">
        <div class="page-metric-title">Enrolled Customers</div>
        <div class="page-metric-value" style="color:var(--primary-color);"><?= number_format($total_customers_enrolled) ?></div>
        <div class="page-metric-trend">Customers with active points</div>
    </div>
    <div class="page-metric-card border-yellow">
        <div class="page-metric-title">Points Outstanding</div>
        <div class="page-metric-value" style="color:#f59e0b;"><?= number_format($total_points_outstanding) ?></div>
        <div class="page-metric-trend">Unredeemed liability</div>
    </div>
    <div class="page-metric-card border-green">
        <div class="page-metric-title">Points Earned (All Time)</div>
        <div class="page-metric-value" style="color:#10b981;"><?= number_format($total_earned_ever) ?></div>
        <div class="page-metric-trend">Cumulative points issued</div>
    </div>
    <div class="page-metric-card border-purple">
        <div class="page-metric-title">Points Redeemed</div>
        <div class="page-metric-value" style="color:#8b5cf6;"><?= number_format($total_redeemed) ?></div>
        <div class="page-metric-trend">Used by customers</div>
    </div>
</div>

<!-- Config summary -->
<?php if ($cfg): ?>
<div style="padding:0 24px 16px;">
    <div style="background:var(--bg-light);border-radius:10px;padding:14px 18px;display:flex;gap:24px;flex-wrap:wrap;font-size:13px;">
        <span><strong><?= number_format($cfg['points_per_unit'],2) ?></strong> pts per <?= $currency['symbol'] ?>1 spent</span>
        <span><strong><?= $currency['symbol'] . number_format($cfg['redemption_rate'],2) ?></strong> value per point</span>
        <span>Min redeem: <strong><?= number_format($cfg['min_points_redeem']) ?> pts</strong></span>
        <span>Max redeem: <strong><?= $cfg['max_redemption_pct'] ?>%</strong> of order</span>
        <span>Expiry: <strong><?= $cfg['points_expiry_days'] ?> days</strong></span>
        <span style="padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;background:<?= $cfg['is_active']?'rgba(16,185,129,.12)':'rgba(239,68,68,.1)' ?>;color:<?= $cfg['is_active']?'#059669':'#dc2626' ?>"><?= $cfg['is_active']?'Active':'Inactive' ?></span>
    </div>
</div>
<?php endif; ?>

<!-- Top customers -->
<div style="padding:0 24px 40px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <h3 style="font-size:15px;font-weight:600;">Top Customers by Points</h3>
        <button onclick="document.getElementById('adjustModal').style.display='flex'" style="padding:7px 14px;border:1px solid var(--primary-color);border-radius:7px;background:none;cursor:pointer;color:var(--primary-color);font-size:13px;">Manual Adjustment</button>
    </div>
    <?php if (!empty($top_customers)): ?>
    <table class="lp-table">
        <thead><tr><th>Customer</th><th>Email</th><th>Points Balance</th><th>Times Earned</th><th>Est. Value</th></tr></thead>
        <tbody>
        <?php foreach ($top_customers as $tc):
            $bal = intval($tc['loyalty_points_balance']);
            $est_val = $bal * floatval($cfg['redemption_rate'] ?? 0.05);
            $max_pts = $top_customers[0]['loyalty_points_balance'];
            $bar_w   = $max_pts > 0 ? round($bal/$max_pts*100) : 0;
        ?>
        <tr>
            <td style="font-weight:500"><?= htmlspecialchars($tc['customer_name']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($tc['email']) ?></td>
            <td>
                <div style="font-weight:700;color:var(--primary-color)"><?= number_format($bal) ?> pts</div>
                <div class="points-bar-bg" style="width:120px;"><div class="points-bar-fill" style="width:<?= $bar_w ?>%"></div></div>
            </td>
            <td style="color:var(--text-muted)"><?= $tc['earn_count'] ?>×</td>
            <td style="color:var(--success-color);font-weight:500"><?= $currency['symbol'] . number_format($est_val,2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted);">No customers have earned points yet. Points are awarded automatically on sales.</div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'customers'): ?>
<div style="padding:20px 24px 40px;overflow-x:auto;">
    <table class="lp-table">
        <thead><tr><th>Customer</th><th>Points Balance</th><th>Est. Redemption Value</th><th>Actions</th></tr></thead>
        <tbody>
        <?php
        $all_c = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM loyalty_points_ledger l WHERE l.customer_id=c.id) AS txn_count FROM customers c WHERE c.status='Active' ORDER BY c.loyalty_points_balance DESC");
        while ($c = $all_c->fetch_assoc()):
            $bal = intval($c['loyalty_points_balance']);
            $est = $bal * floatval($cfg['redemption_rate'] ?? 0.05);
        ?>
        <tr>
            <td>
                <div style="font-weight:500"><?= htmlspecialchars($c['customer_name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($c['email']) ?></div>
            </td>
            <td>
                <?php if ($bal > 0): ?>
                <span style="font-weight:700;color:var(--primary-color)"><?= number_format($bal) ?> pts</span>
                <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
            </td>
            <td><?= $bal > 0 ? $currency['symbol'].number_format($est,2) : '—' ?></td>
            <td>
                <button onclick="openAdjust(<?= $c['id'] ?>, '<?= htmlspecialchars($c['customer_name'],ENT_QUOTES) ?>', <?= $bal ?>)" style="font-size:12px;padding:4px 10px;border:1px solid var(--border-color);border-radius:5px;background:none;cursor:pointer;color:var(--text-dark);">Adjust</button>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'ledger'): ?>
<div style="padding:20px 24px 40px;overflow-x:auto;">
    <table class="lp-table">
        <thead><tr><th>Customer</th><th>Type</th><th>Points</th><th>Balance After</th><th>Reference</th><th>Notes</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($recent_ledger as $l):
            $tc_class = 'pts-'.strtolower($l['transaction_type']);
            $sign = in_array($l['transaction_type'],['Earned','Adjusted']) ? '+' : '-';
        ?>
        <tr>
            <td style="font-weight:500"><?= htmlspecialchars($l['customer_name']) ?></td>
            <td><span class="pts-badge <?= $tc_class ?>"><?= $l['transaction_type'] ?></span></td>
            <td style="font-weight:600"><?= $sign ?><?= number_format($l['points']) ?></td>
            <td style="color:var(--text-muted)"><?= number_format($l['balance_after']) ?></td>
            <td style="font-size:12px;font-family:monospace;color:var(--text-muted)"><?= htmlspecialchars($l['reference_type'] ? $l['reference_type'].'-'.$l['reference_id'] : '—') ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($l['notes'] ?? '—') ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y H:i', strtotime($l['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php else: // settings ?>
<div style="padding:20px 24px 40px;">
    <div class="config-card">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:4px;">Program Configuration</h3>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">These settings apply to all new sales. Existing earned points are unaffected.</p>
        <form method="POST">
            <input type="hidden" name="action" value="save_config">
            <div class="form-row-2">
                <div>
                    <label class="form-label">Points earned per <?= $currency['symbol'] ?>1 spent</label>
                    <input type="number" name="points_per_unit" class="form-input" step="0.01" min="0" value="<?= $cfg['points_per_unit'] ?? 1 ?>">
                    <span style="font-size:11px;color:var(--text-muted)">e.g. 1 = earn 1 point per <?= $currency['symbol'] ?>1</span>
                </div>
                <div>
                    <label class="form-label"><?= $currency['symbol'] ?> value per 1 point (redemption)</label>
                    <input type="number" name="redemption_rate" class="form-input" step="0.001" min="0" value="<?= $cfg['redemption_rate'] ?? 0.05 ?>">
                    <span style="font-size:11px;color:var(--text-muted)">e.g. 0.05 = 100 pts = <?= $currency['symbol'] ?>5 off</span>
                </div>
                <div>
                    <label class="form-label">Minimum points to redeem</label>
                    <input type="number" name="min_points_redeem" class="form-input" min="1" value="<?= $cfg['min_points_redeem'] ?? 100 ?>">
                </div>
                <div>
                    <label class="form-label">Max % of order redeemable</label>
                    <input type="number" name="max_redemption_pct" class="form-input" step="0.1" min="0" max="100" value="<?= $cfg['max_redemption_pct'] ?? 25 ?>">
                    <span style="font-size:11px;color:var(--text-muted)">e.g. 25 = max 25% of order can be paid with points</span>
                </div>
                <div>
                    <label class="form-label">Points expiry (days)</label>
                    <input type="number" name="points_expiry_days" class="form-input" min="1" value="<?= $cfg['points_expiry_days'] ?? 365 ?>">
                </div>
                <div style="display:flex;align-items:flex-end;padding-bottom:4px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
                        <input type="checkbox" name="is_active" value="1" <?= ($cfg['is_active']??1)?'checked':'' ?>>
                        Program is active
                    </label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="padding:10px 24px;">Save Settings</button>
        </form>
    </div>
    <div style="margin-top:16px;background:var(--bg-light);border-radius:10px;padding:14px 18px;max-width:700px;">
        <p style="font-size:13px;font-weight:600;margin-bottom:6px;">How points are awarded (automatic)</p>
        <p style="font-size:13px;color:var(--text-muted);">Points are awarded in <code>add_sales_order.php</code> and <code>pos.php</code> when a sale is completed. Add this call after a successful sale:</p>
        <pre style="font-size:12px;background:var(--card-bg);padding:10px;border-radius:6px;border:1px solid var(--border-color);overflow-x:auto;margin-top:8px;">require_once 'includes/loyalty_helper.php';
award_loyalty_points($conn, $customer_id, $order_total, 'SalesOrder', $sales_order_id);</pre>
    </div>
</div>
<?php endif; ?>
</main>

<!-- Manual Adjust Modal -->
<div id="adjustModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;">
<div style="background:var(--card-bg);border-radius:14px;padding:28px;width:440px;max-width:92vw;">
    <h3 style="margin-bottom:16px;">Manual Points Adjustment</h3>
    <form method="POST">
        <input type="hidden" name="action" value="adjust_points">
        <div style="margin-bottom:14px;">
            <label class="form-label">Customer</label>
            <select name="customer_id" class="form-select" id="adj_cid">
                <?php foreach ($customers_list as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?> (<?= number_format($c['loyalty_points_balance']) ?> pts)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                    <option value="Adjusted">Add points</option>
                    <option value="Reversed">Remove points</option>
                </select>
            </div>
            <div>
                <label class="form-label">Points</label>
                <input type="number" name="points" class="form-input" min="1" required placeholder="e.g. 100">
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <label class="form-label">Reason / Notes</label>
            <input type="text" name="notes" class="form-input" placeholder="e.g. Goodwill gesture, data correction">
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('adjustModal').style.display='none'" style="padding:9px 16px;border:1px solid var(--border-color);border-radius:7px;background:none;cursor:pointer;">Cancel</button>
            <button type="submit" class="btn btn-primary" style="padding:9px 20px;">Apply Adjustment</button>
        </div>
    </form>
</div>
</div>

<script>
function openAdjust(id, name, bal) {
    document.getElementById('adj_cid').value = id;
    document.getElementById('adjustModal').style.display = 'flex';
}
</script>
<?php include 'includes/footer.php'; ?>
