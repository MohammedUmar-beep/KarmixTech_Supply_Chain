<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

// Handle Deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $c_id = intval($_GET['id']);
    if ($conn->query("DELETE FROM coupons WHERE id = $c_id")) {
        log_activity($conn, 'Delete', 'Coupon', "ID: $c_id", "Deleted coupon");
        header("Location: coupons.php?msg=deleted");
        exit();
    }
}

// Handle Status Toggle
if (isset($_GET['action']) && $_GET['action'] == 'toggle' && isset($_GET['id'])) {
    $c_id       = intval($_GET['id']);
    $curr       = $conn->query("SELECT status FROM coupons WHERE id = $c_id")->fetch_assoc()['status'] ?? '';
    $new_status = ($curr == 'Active') ? 'Inactive' : 'Active';
    $conn->query("UPDATE coupons SET status = '$new_status' WHERE id = $c_id");
    log_activity($conn, 'Update', 'Coupon', "ID: $c_id", "Toggled status to $new_status");
    header("Location: coupons.php" . (isset($_GET['tab']) ? '?tab=' . $_GET['tab'] : ''));
    exit();
}

// Tab filter
$tab          = $_GET['tab'] ?? 'all';
$where_clause = "1=1";
$today        = date('Y-m-d');
if ($tab === 'active') {
    $where_clause .= " AND status = 'Active' AND (end_date IS NULL OR end_date >= '$today') AND (start_date IS NULL OR start_date <= '$today') AND (usage_limit IS NULL OR times_used < usage_limit)";
} elseif ($tab === 'expired') {
    $where_clause .= " AND (status = 'Inactive' OR (end_date IS NOT NULL AND end_date < '$today') OR (usage_limit IS NOT NULL AND times_used >= usage_limit))";
}

// Fetch
$coupons_res  = $conn->query("SELECT * FROM coupons WHERE $where_clause ORDER BY id DESC");
$coupons_list = [];
while ($row = $coupons_res->fetch_assoc()) $coupons_list[] = $row;

// Counts for tabs
$count_all     = $conn->query("SELECT COUNT(*) FROM coupons")->fetch_row()[0];
$count_active  = $conn->query("SELECT COUNT(*) FROM coupons WHERE status='Active' AND (end_date IS NULL OR end_date >= '$today') AND (start_date IS NULL OR start_date <= '$today') AND (usage_limit IS NULL OR times_used < usage_limit)")->fetch_row()[0];
$count_expired = $conn->query("SELECT COUNT(*) FROM coupons WHERE status='Inactive' OR (end_date IS NOT NULL AND end_date < '$today') OR (usage_limit IS NOT NULL AND times_used >= usage_limit)")->fetch_row()[0];

// Flash messages
$flash = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added')   $flash = 'Coupon created successfully.';
    if ($_GET['success'] === 'updated') $flash = 'Coupon updated successfully.';
    if ($_GET['success'] === 'deleted') $flash = 'Coupon deleted.';
}

$page_title   = 'Coupons';
$current_page = 'coupons.php';
include 'includes/header.php';

// Helper — type icon SVG
function coupon_icon(string $type): string {
    $t = strtolower($type);
    if (str_contains($t, 'percentage')) return '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="16" y1="8" x2="8" y2="16"/><circle cx="9" cy="9" r="1" fill="currentColor"/><circle cx="15" cy="15" r="1" fill="currentColor"/></svg>';
    if (str_contains($t, 'shipping'))  return '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
    // Fixed / Price Discount
    return '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
}

// Helper — format value
function coupon_value_label(array $c): string {
    global $currency;
    $t = strtolower($c['type']);
    if (str_contains($t, 'percentage')) return number_format($c['value'], 0) . '% off';
    if (str_contains($t, 'shipping'))   return 'Free shipping';
    return ($currency['symbol'] ?? '₹') . number_format($c['value'], 2) . ' off';
}

// Helper — effective status
function coupon_effective_status(array $c): string {
    $today = date('Y-m-d');
    if ($c['status'] !== 'Active')                                          return 'inactive';
    if (!empty($c['start_date']) && $today < $c['start_date'])              return 'scheduled';
    if (!empty($c['end_date'])   && $today > $c['end_date'])                return 'expired';
    if (!empty($c['usage_limit']) && $c['times_used'] >= $c['usage_limit']) return 'exhausted';
    return 'active';
}
?>

<style>
.coupon-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
    padding: 20px 24px;
}
.coupon-card {
    background: var(--card-bg);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    transition: box-shadow .15s, border-color .15s;
    position: relative;
}
.coupon-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.09); border-color: var(--primary-color); }
.coupon-card.status-active   { border-left: 4px solid var(--success-color); }
.coupon-card.status-inactive  { border-left: 4px solid var(--text-muted); }
.coupon-card.status-expired   { border-left: 4px solid #ef4444; opacity: .8; }
.coupon-card.status-exhausted { border-left: 4px solid #f59e0b; opacity: .85; }
.coupon-card.status-scheduled { border-left: 4px solid #3b82f6; }

.coupon-card-top {
    padding: 16px 18px 12px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.coupon-icon-wrap {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.coupon-code-badge {
    font-family: monospace;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .5px;
    background: var(--bg-light);
    border: 1px dashed var(--border-color);
    padding: 3px 10px;
    border-radius: 6px;
    color: var(--text-dark);
    display: inline-block;
    margin-bottom: 4px;
}
.coupon-divider {
    border: 0;
    border-top: 1px dashed var(--border-color);
    margin: 0;
}
.coupon-card-meta {
    padding: 10px 18px;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
}
.coupon-meta-item {
    display: flex; flex-direction: column; gap: 2px;
}
.coupon-meta-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--text-muted);
    font-weight: 500;
}
.coupon-meta-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-dark);
}
.coupon-card-footer {
    padding: 10px 18px;
    border-top: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-light);
}
.usage-bar-wrap {
    flex: 1;
    margin-right: 14px;
}
.usage-bar-track {
    height: 5px;
    background: var(--border-color);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 3px;
}
.usage-bar-fill {
    height: 100%;
    border-radius: 3px;
    transition: width .3s;
}
.status-pill {
    font-size: 10px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 20px;
    letter-spacing: .3px;
    text-transform: uppercase;
}
</style>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1 style="font-size:24px;">Coupons</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <?php if ($flash): ?>
        <div style="margin:0 24px; margin-top:16px; background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.25); color:var(--success-color); padding:10px 16px; border-radius:8px; font-size:13px;"><?= $flash ?></div>
    <?php endif; ?>

    <!-- Tab bar + Add button -->
    <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 24px;">
        <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expired' => 'Expired / Inactive'] as $val => $label):
                $active = $tab === $val; ?>
            <a href="coupons.php?tab=<?= $val ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="add_coupon.php" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; text-decoration:none; color:#fff; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600;">
            <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Coupon
        </a>
    </div>

    <!-- Coupon Cards -->
    <?php if (count($coupons_list) > 0): ?>
    <div class="coupon-grid">
        <?php foreach ($coupons_list as $c):
            $eff    = coupon_effective_status($c);
            $icon   = coupon_icon($c['type']);
            $vlabel = coupon_value_label($c);

            // Usage progress
            $usage_pct = 0;
            $usage_str = 'Unlimited';
            if (!empty($c['usage_limit']) && $c['usage_limit'] > 0) {
                $usage_pct = min(100, round($c['times_used'] / $c['usage_limit'] * 100));
                $usage_str = $c['times_used'] . ' / ' . $c['usage_limit'];
            } else {
                $usage_str = $c['times_used'] . ' uses';
            }

            // Bar colour
            $bar_color = 'var(--success-color)';
            if ($usage_pct >= 90)     $bar_color = '#ef4444';
            elseif ($usage_pct >= 60) $bar_color = '#f59e0b';

            // Icon + status colours
            $icon_bg   = 'rgba(109,74,255,.1)';
            $icon_color= 'var(--primary-color)';
            $pill_bg   = 'rgba(16,185,129,.12)'; $pill_color = 'var(--success-color)';
            $pill_text = 'Active';
            if ($eff === 'expired')   { $pill_bg = 'rgba(239,68,68,.1)';  $pill_color = '#ef4444'; $pill_text = 'Expired';   $icon_bg = 'rgba(239,68,68,.08)';  $icon_color = '#ef4444'; }
            if ($eff === 'inactive')  { $pill_bg = 'var(--bg-light)';     $pill_color = 'var(--text-muted)'; $pill_text = 'Inactive'; $icon_bg = 'var(--bg-light)'; $icon_color = 'var(--text-muted)'; }
            if ($eff === 'exhausted') { $pill_bg = 'rgba(245,158,11,.12)'; $pill_color = '#f59e0b'; $pill_text = 'Exhausted'; $icon_bg = 'rgba(245,158,11,.1)'; $icon_color = '#f59e0b'; }
            if ($eff === 'scheduled') { $pill_bg = 'rgba(59,130,246,.12)'; $pill_color = '#3b82f6'; $pill_text = 'Scheduled'; $icon_bg = 'rgba(59,130,246,.1)'; $icon_color = '#3b82f6'; }

            // Dates
            $start_label = $c['start_date'] ? date('d M Y', strtotime($c['start_date'])) : '—';
            $end_label   = $c['end_date']   ? date('d M Y', strtotime($c['end_date']))   : 'No expiry';
        ?>
        <div class="coupon-card status-<?= $eff ?>">
            <div class="coupon-card-top">
                <!-- Icon -->
                <div class="coupon-icon-wrap" style="background:<?= $icon_bg ?>; color:<?= $icon_color ?>;">
                    <?= $icon ?>
                </div>
                <!-- Name + code + type -->
                <div style="flex:1; min-width:0;">
                    <div style="font-size:15px; font-weight:700; color:var(--text-dark); margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= htmlspecialchars($c['name']) ?>
                    </div>
                    <span class="coupon-code-badge"><?= htmlspecialchars($c['code']) ?></span>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:2px;"><?= htmlspecialchars($c['type']) ?> · <?= htmlspecialchars($c['applies_to']) ?></div>
                </div>
                <!-- Value + status pill -->
                <div style="text-align:right; flex-shrink:0;">
                    <div style="font-size:22px; font-weight:800; color:<?= $icon_color ?>; line-height:1.1; margin-bottom:5px;"><?= $vlabel ?></div>
                    <span class="status-pill" style="background:<?= $pill_bg ?>; color:<?= $pill_color ?>;"><?= $pill_text ?></span>
                </div>
            </div>

            <hr class="coupon-divider">

            <!-- Meta row -->
            <div class="coupon-card-meta">
                <div class="coupon-meta-item">
                    <span class="coupon-meta-label">Start</span>
                    <span class="coupon-meta-value"><?= $start_label ?></span>
                </div>
                <div class="coupon-meta-item">
                    <span class="coupon-meta-label">Expires</span>
                    <span class="coupon-meta-value" style="color:<?= ($eff==='expired'?'#ef4444':'var(--text-dark)') ?>;"><?= $end_label ?></span>
                </div>
                <div class="coupon-meta-item">
                    <span class="coupon-meta-label">Applies to</span>
                    <span class="coupon-meta-value" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($c['applies_to']) ?></span>
                </div>
            </div>

            <hr class="coupon-divider">

            <!-- Footer: usage bar + actions -->
            <div class="coupon-card-footer">
                <div class="usage-bar-wrap">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:10px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted); font-weight:500;">Usage</span>
                        <span style="font-size:11px; font-weight:600; color:var(--text-dark);"><?= $usage_str ?></span>
                    </div>
                    <?php if (!empty($c['usage_limit'])): ?>
                    <div class="usage-bar-track">
                        <div class="usage-bar-fill" style="width:<?= $usage_pct ?>%; background:<?= $bar_color ?>;"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Action icons -->
                <div style="display:flex; gap:10px; align-items:center; flex-shrink:0;">
                    <a href="edit_coupon.php?id=<?= $c['id'] ?>" title="Edit" style="color:var(--text-muted); transition:color .15s;" onmouseover="this.style.color='var(--primary-color)'" onmouseout="this.style.color='var(--text-muted)'">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    </a>
                    <a href="coupons.php?action=toggle&id=<?= $c['id'] ?>&tab=<?= $tab ?>" title="<?= $c['status']==='Active'?'Deactivate':'Activate' ?>"
                        style="color:<?= $c['status']==='Active'?'var(--success-color)':'var(--text-muted)' ?>; transition:color .15s;"
                        onmouseover="this.style.color='var(--primary-color)'" onmouseout="this.style.color='<?= $c['status']==='Active'?'var(--success-color)':'var(--text-muted)' ?>'">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    </a>
                    <a href="coupons.php?action=delete&id=<?= $c['id'] ?>" title="Delete"
                        onclick="event.preventDefault(); showCustomConfirm('Delete coupon <?= addslashes(htmlspecialchars($c['code'])) ?>?', () => { window.location.href = this.href || this.getAttribute('href'); });"
                        style="color:var(--text-muted); transition:color .15s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='var(--text-muted)'">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 24px; color:var(--text-muted); text-align:center;">
        <svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1" style="opacity:.2; margin-bottom:16px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        <div style="font-size:16px; font-weight:600; margin-bottom:6px;">No coupons found</div>
        <div style="font-size:13px; margin-bottom:20px; opacity:.7;">
            <?= $tab !== 'all' ? 'Try switching to "All Coupons" or' : 'Get started by' ?> creating your first coupon.
        </div>
        <a href="add_coupon.php" class="btn btn-primary" style="text-decoration:none; color:#fff;">Add Coupon</a>
    </div>
    <?php endif; ?>

</main>

<?php include 'includes/footer.php'; ?>
