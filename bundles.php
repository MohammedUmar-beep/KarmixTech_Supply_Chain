<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $bid = intval($_GET['id']);
    $conn->query("DELETE FROM bundle_items WHERE bundle_id=$bid");
    $conn->query("DELETE FROM bundles WHERE id=$bid");
    log_activity($conn,'Delete','Bundle',"ID:$bid","Deleted bundle");
    header("Location: bundles.php?msg=deleted"); exit();
}

// Handle toggle status
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    $bid  = intval($_GET['id']);
    $cur  = $conn->query("SELECT status FROM bundles WHERE id=$bid")->fetch_assoc()['status'] ?? 'Active';
    $next = ($cur === 'Active') ? 'Inactive' : 'Active';
    $conn->query("UPDATE bundles SET status='$next' WHERE id=$bid");
    header("Location: bundles.php"); exit();
}

// Filters
$tab   = $_GET['tab'] ?? 'all';
$today = date('Y-m-d');
$where = "1=1";
if ($tab === 'active')   $where .= " AND b.status='Active' AND (b.valid_until IS NULL OR b.valid_until >= '$today')";
if ($tab === 'inactive') $where .= " AND (b.status='Inactive' OR (b.valid_until IS NOT NULL AND b.valid_until < '$today'))";

$bundles = [];
$res = $conn->query("SELECT b.*, u.name AS created_by_name,
    (SELECT COUNT(*) FROM bundle_items bi WHERE bi.bundle_id=b.id) AS item_count
    FROM bundles b
    LEFT JOIN users u ON u.id=b.created_by
    WHERE $where ORDER BY b.id DESC");
while ($row = $res->fetch_assoc()) $bundles[] = $row;

$cnt_all      = $conn->query("SELECT COUNT(*) FROM bundles")->fetch_row()[0];
$cnt_active   = $conn->query("SELECT COUNT(*) FROM bundles WHERE status='Active' AND (valid_until IS NULL OR valid_until>='$today')")->fetch_row()[0];
$cnt_inactive = $conn->query("SELECT COUNT(*) FROM bundles WHERE status='Inactive' OR (valid_until IS NOT NULL AND valid_until<'$today')")->fetch_row()[0];

$flash = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') $flash = 'Bundle created successfully.';
    if ($_GET['success'] === 'updated') $flash = 'Bundle updated successfully.';
    if ($_GET['success'] === 'deleted') $flash = 'Bundle deleted.';
}

$page_title   = 'Bundles';
$current_page = 'bundles.php';
include 'includes/header.php';
?>
<style>
.bundle-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px; padding:20px 24px; }
.bundle-card { background:var(--card-bg); border:1.5px solid var(--border-color); border-radius:14px; padding:20px; display:flex; flex-direction:column; gap:12px; transition:box-shadow .15s; }
.bundle-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.08); }
.bc-header { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.bc-title { font-size:15px; font-weight:600; color:var(--text-dark); }
.bc-sku  { font-size:11px; color:var(--text-muted); font-family:monospace; margin-top:2px; }
.bc-items { display:flex; flex-wrap:wrap; gap:5px; }
.bc-item-tag { font-size:11px; padding:3px 8px; border-radius:6px; background:var(--bg-light); color:var(--text-muted); }
.bc-profit-row { display:flex; gap:12px; }
.bc-stat { flex:1; background:var(--bg-light); border-radius:8px; padding:8px 10px; }
.bc-stat-label { font-size:10px; color:var(--text-muted); margin-bottom:2px; }
.bc-stat-value { font-size:14px; font-weight:600; }
.bc-stat-value.profit { color:var(--success-color); }
.bc-stat-value.loss   { color:var(--danger-color); }
.bc-actions { display:flex; gap:8px; margin-top:4px; }
.bc-btn { flex:1; padding:7px; border-radius:7px; font-size:12px; font-weight:500; text-align:center; text-decoration:none; border:1px solid var(--border-color); background:transparent; color:var(--text-dark); cursor:pointer; transition:background .12s; }
.bc-btn:hover { background:var(--bg-light); }
.bc-btn.primary { background:var(--primary-color); color:#fff; border-color:var(--primary-color); }
.bc-btn.primary:hover { opacity:.88; }
.margin-bar { height:4px; border-radius:2px; background:var(--border-color); overflow:hidden; }
.margin-fill { height:100%; border-radius:2px; transition:width .3s; }
.tab-btn { padding:10px 0 12px; color:var(--text-muted); border-bottom:2px solid transparent; transition:color .15s,border-color .15s; display:flex; align-items:center; gap:6px; font-size:14px; text-decoration:none; }
.tab-btn.active { color:var(--primary-color); border-bottom-color:var(--primary-color); font-weight:600; }
.tab-count { font-size:10px; font-weight:700; padding:1px 6px; border-radius:20px; background:var(--bg-light); color:var(--text-muted); }
.tab-btn.active .tab-count { background:rgba(109,74,255,.1); color:var(--primary-color); }
.status-pill { font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.pill-active   { background:rgba(16,185,129,.12); color:#059669; }
.pill-inactive { background:rgba(107,114,128,.1);  color:#6b7280; }
.pill-expired  { background:rgba(239,68,68,.1);    color:#dc2626; }
.discount-badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:6px; background:rgba(109,74,255,.1); color:var(--primary-color); }
</style>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left"><h1 style="font-size:24px;">Bundles</h1></div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <?php if ($flash): ?>
        <div style="margin:0 24px;margin-top:16px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);padding:10px 16px;border-radius:8px;font-size:13px;"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;padding:0 24px;border-bottom:1px solid var(--border-color);">
        <div style="display:flex;gap:24px;">
            <a href="bundles.php?tab=all"      class="tab-btn <?= $tab==='all'?'active':'' ?>">All Bundles <span class="tab-count"><?= $cnt_all ?></span></a>
            <a href="bundles.php?tab=active"   class="tab-btn <?= $tab==='active'?'active':'' ?>">Active <span class="tab-count"><?= $cnt_active ?></span></a>
            <a href="bundles.php?tab=inactive" class="tab-btn <?= $tab==='inactive'?'active':'' ?>">Inactive <span class="tab-count"><?= $cnt_inactive ?></span></a>
        </div>
        <a href="add_bundle.php" class="btn btn-primary" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;">
            <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Bundle
        </a>
    </div>

    <?php if (count($bundles) > 0): ?>
    <div class="bundle-grid">
        <?php foreach ($bundles as $b):
            $eff_status = $b['status'];
            if ($eff_status === 'Active' && !empty($b['valid_until']) && $b['valid_until'] < $today) $eff_status = 'Expired';
            $pill_class = $eff_status === 'Active' ? 'pill-active' : ($eff_status === 'Expired' ? 'pill-expired' : 'pill-inactive');
            $profit = floatval($b['profit_amount']);
            $margin = floatval($b['profit_margin_pct']);
            $bar_color = $profit < 0 ? '#ef4444' : ($margin < 10 ? '#f59e0b' : '#10b981');
            $bar_w = max(0, min(100, $margin));

            if ($b['discount_type'] === 'percentage')
                $disc_label = number_format($b['discount_value'],0).'% off';
            elseif ($b['discount_type'] === 'fixed')
                $disc_label = $currency['symbol'].number_format($b['discount_value'],2).' off';
            else
                $disc_label = 'Custom price';

            // Fetch items preview
            $items_res = $conn->query("SELECT p.product_name, bi.quantity FROM bundle_items bi JOIN products p ON p.id=bi.product_id WHERE bi.bundle_id={$b['id']} LIMIT 4");
            $item_previews = [];
            while ($ir = $items_res->fetch_assoc()) $item_previews[] = $ir['quantity'].'× '.$ir['product_name'];
        ?>
        <div class="bundle-card">
            <?php if (!empty($b['image_path']) && file_exists($b['image_path'])): ?>
            <div style="width:100%;height:130px;overflow:hidden;border-radius:8px;background:var(--bg-light);display:flex;align-items:center;justify-content:center;margin-bottom:4px;">
                <img src="<?= htmlspecialchars($b['image_path']) ?>" alt="<?= htmlspecialchars($b['bundle_name']) ?>"
                    style="width:100%;height:100%;object-fit:cover;border-radius:8px;">
            </div>
            <?php endif; ?>
            <div class="bc-header">
                <div>
                    <div class="bc-title"><?= htmlspecialchars($b['bundle_name']) ?></div>
                    <div class="bc-sku">SKU: <?= htmlspecialchars($b['sku_code']) ?></div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
                    <span class="status-pill <?= $pill_class ?>"><?= $eff_status ?></span>
                    <span class="discount-badge"><?= $disc_label ?></span>
                </div>
            </div>

            <?php if (!empty($item_previews)): ?>
            <div class="bc-items">
                <?php foreach ($item_previews as $ip): ?>
                    <span class="bc-item-tag"><?= htmlspecialchars($ip) ?></span>
                <?php endforeach; ?>
                <?php if ($b['item_count'] > 4): ?>
                    <span class="bc-item-tag">+<?= $b['item_count']-4 ?> more</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="bc-profit-row">
                <div class="bc-stat">
                    <div class="bc-stat-label">Bundle Price</div>
                    <div class="bc-stat-value"><?= $currency['symbol'] . number_format($b['bundle_price'],2) ?></div>
                </div>
                <div class="bc-stat">
                    <div class="bc-stat-label">Total Cost</div>
                    <div class="bc-stat-value" style="color:var(--text-muted)"><?= $currency['symbol'] . number_format($b['total_cost'],2) ?></div>
                </div>
                <div class="bc-stat">
                    <div class="bc-stat-label">Profit</div>
                    <div class="bc-stat-value <?= $profit < 0 ? 'loss' : 'profit' ?>">
                        <?= $profit < 0 ? '-' : '' ?><?= $currency['symbol'] . number_format(abs($profit),2) ?>
                    </div>
                </div>
            </div>

            <div>
                <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px;">
                    <span>Margin</span><span style="font-weight:600;color:<?= $bar_color ?>"><?= number_format($margin,1) ?>%</span>
                </div>
                <div class="margin-bar">
                    <div class="margin-fill" style="width:<?= $bar_w ?>%;background:<?= $bar_color ?>;"></div>
                </div>
            </div>

            <div class="bc-actions">
                <a href="view_bundle.php?id=<?= $b['id'] ?>" class="bc-btn">View</a>
                <a href="edit_bundle.php?id=<?= $b['id'] ?>" class="bc-btn">Edit</a>
                <a href="bundles.php?action=toggle&id=<?= $b['id'] ?>" class="bc-btn"><?= $b['status']==='Active'?'Deactivate':'Activate' ?></a>
                <a href="bundles.php?action=delete&id=<?= $b['id'] ?>" class="bc-btn" style="color:var(--danger-color);"
                    onclick="return confirm('Delete this bundle? This cannot be undone.')">Delete</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:80px 24px;">
        <svg viewBox="0 0 24 24" width="48" height="48" stroke="var(--text-muted)" fill="none" stroke-width="1.5" style="margin-bottom:12px;"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
        <h3 style="font-size:18px;margin-bottom:8px;">No bundles yet</h3>
        <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px;">Create your first bundle to start selling discounted product groups.</p>
        <a href="add_bundle.php" class="btn btn-primary" style="text-decoration:none;color:#fff;padding:10px 20px;border-radius:8px;">Create First Bundle</a>
    </div>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
