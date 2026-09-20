<?php
require_once 'includes/auth_guard.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

if (!isset($_GET['id'])) { header("Location: coupons.php"); exit(); }

$c_id   = intval($_GET['id']);
$coupon = $conn->query("SELECT * FROM coupons WHERE id = $c_id")->fetch_assoc();
if (!$coupon) { header("Location: coupons.php"); exit(); }

$page_title   = 'Edit Coupon';
$current_page = 'coupons.php';
include 'includes/header.php';

$types = ['Fixed Discount', 'Percentage Discount', 'Free Shipping'];
?>
<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="coupons.php" class="back-link">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Coupons
            </a>
            <h1 style="font-size:24px;">Edit Coupon</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <?php if (isset($_GET['error'])): ?>
        <div style="margin:16px 24px; background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#ef4444; padding:10px 16px; border-radius:8px; font-size:13px;">Error: <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <form action="save_coupon.php" method="POST" class="coupon-form"
        style="max-width:800px; margin:24px auto; background:var(--card-bg); padding:30px; border-radius:12px; border:1px solid var(--border-color);">

        <?= csrf_token() ?>
        <input type="hidden" name="coupon_id" value="<?= $coupon['id'] ?>">

        <!-- Section 1 -->
        <h3 style="font-size:15px; font-weight:600; margin-bottom:4px;">Coupon Information</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">This code is entered by the cashier at POS checkout.</p>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px;">
            <div>
                <label class="form-label" style="font-weight:500;">Coupon Code <span style="color:#ef4444;">*</span></label>
                <input type="text" name="code" class="form-input" value="<?= htmlspecialchars($coupon['code']) ?>" required
                    style="background:transparent; text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()">
            </div>
            <div>
                <label class="form-label" style="font-weight:500;">Coupon Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($coupon['name']) ?>" required style="background:transparent;">
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border-color); margin-bottom:28px;">

        <!-- Section 2: Type -->
        <h3 style="font-size:15px; font-weight:600; margin-bottom:4px;">Coupon Type</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:16px;">Choose how the discount is applied at checkout.</p>

        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:24px;">
            <?php
            $type_defs = [
                'Fixed Discount'      => ['icon'=>'<svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" fill="none" stroke-width="2" style="margin-bottom:8px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', 'label'=>'Fixed Amount',      'hint'=>'e.g. '+\$currency['symbol']+'100 off'],
                'Percentage Discount' => ['icon'=>'<svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" fill="none" stroke-width="2" style="margin-bottom:8px;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="16" y1="8" x2="8" y2="16"/><circle cx="9" cy="9" r="1" fill="currentColor"/><circle cx="15" cy="15" r="1" fill="currentColor"/></svg>', 'label'=>'Percentage Off', 'hint'=>'e.g. 15% off total'],
                'Free Shipping'       => ['icon'=>'<svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" fill="none" stroke-width="2" style="margin-bottom:8px;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>', 'label'=>'Free Shipping', 'hint'=>'Waives delivery charge'],
            ];
            foreach ($type_defs as $tval => $tdef): $isActive = ($coupon['type'] === $tval); ?>
            <label class="coupon-type-card <?= $isActive?'active':'' ?>" onclick="selectType(this,'<?= $tval ?>')">
                <input type="radio" name="type" value="<?= $tval ?>" <?= $isActive?'checked':'' ?> style="display:none;">
                <?= $tdef['icon'] ?>
                <span style="font-weight:600;"><?= $tdef['label'] ?></span>
                <span style="font-size:11px; opacity:.7;"><?= $tdef['hint'] ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
            <div>
                <label class="form-label" style="font-weight:500;">Discount Value <span id="valueHint" style="font-weight:400; color:var(--text-muted);"></span></label>
                <input type="number" step="0.01" min="0" name="value" class="form-input" value="<?= htmlspecialchars($coupon['value']) ?>" required style="background:transparent;">
            </div>
            <div>
                <label class="form-label" style="font-weight:500;">Minimum Order Value <span style="font-weight:400; color:var(--text-muted);">(optional)</span></label>
                <input type="number" step="0.01" min="0" name="min_order_value" class="form-input"
                    value="<?= htmlspecialchars($coupon['min_order_value'] ?? 0) ?>" placeholder="0.00" style="background:transparent;">
            </div>
        </div>

        <div style="margin-bottom:28px;">
            <label class="form-label" style="font-weight:500;">Applies To</label>
            <select name="applies_to" class="form-input" style="background:transparent; max-width:320px;">
                <?php foreach (['All Products','Specific Category','Specific Product'] as $opt): ?>
                <option value="<?= $opt ?>" <?= $coupon['applies_to']===$opt?'selected':'' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <hr style="border:0; border-top:1px solid var(--border-color); margin-bottom:28px;">

        <!-- Section 3: Validity -->
        <h3 style="font-size:15px; font-weight:600; margin-bottom:4px;">Validity & Limits</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Control when and how many times this coupon can be used.</p>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
            <div>
                <label class="form-label" style="font-weight:500;">Active Period</label>
                <div style="display:flex; gap:10px;">
                    <input type="date" name="start_date" class="form-input" value="<?= $coupon['start_date'] ?>" style="background:transparent; flex:1;" title="Start Date">
                    <input type="date" name="end_date" id="end_date" class="form-input" value="<?= $coupon['end_date'] ?>" style="background:transparent; flex:1;" title="End Date" <?= is_null($coupon['end_date'])?'disabled':'' ?>>
                </div>
                <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12px; color:var(--text-muted); cursor:pointer;">
                    <input type="checkbox" name="no_duration" id="no_duration" onchange="toggleEndDate()" <?= is_null($coupon['end_date'])?'checked':'' ?>> No expiry date
                </label>
            </div>
            <div>
                <label class="form-label" style="font-weight:500;">Usage Limit</label>
                <input type="number" min="1" name="usage_limit" id="usage_limit" class="form-input"
                    value="<?= htmlspecialchars($coupon['usage_limit'] ?? '') ?>" placeholder="e.g. 100"
                    style="background:transparent;" <?= is_null($coupon['usage_limit'])?'disabled':'' ?>>
                <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12px; color:var(--text-muted); cursor:pointer;">
                    <input type="checkbox" name="no_limit" id="no_limit" onchange="toggleLimit()" <?= is_null($coupon['usage_limit'])?'checked':'' ?>> Unlimited uses
                </label>
            </div>
        </div>

        <!-- Usage info -->
        <div style="background:var(--bg-light); border:1px solid var(--border-color); border-radius:8px; padding:12px 16px; font-size:13px; color:var(--text-muted); margin-bottom:8px;">
            This coupon has been used <strong style="color:var(--text-dark);"><?= intval($coupon['times_used']) ?> time<?= $coupon['times_used']!=1?'s':'' ?></strong> so far.
        </div>

        <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:28px; border-top:1px solid var(--border-color); padding-top:20px;">
            <button type="button" class="btn btn-outline" onclick="window.location.href='coupons.php'">Cancel</button>
            <button type="submit" class="btn btn-primary" style="padding:9px 32px; font-weight:600;">Save Changes</button>
        </div>
    </form>
</main>

<style>
.coupon-type-card {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:18px 10px; border:1.5px solid var(--border-color); border-radius:10px;
    cursor:pointer; color:var(--text-muted); font-size:13px; gap:2px; transition:all .2s;
}
.coupon-type-card:hover  { border-color:var(--primary-color); color:var(--primary-color); }
.coupon-type-card.active { border:2px solid var(--primary-color); color:var(--primary-color); background:var(--primary-bg,rgba(109,74,255,.06)); }
</style>

<script>
function selectType(el, typeValue) {
    document.querySelectorAll('.coupon-type-card').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    const hint = document.getElementById('valueHint');
    if (typeValue === 'Percentage Discount') hint.textContent = '(enter %, e.g. 15 = 15%)';
    else if (typeValue === 'Free Shipping')  hint.textContent = '(leave 0 — no subtotal deduction)';
    else                                     hint.textContent = '(fixed amount off)';
}
function toggleEndDate() { document.getElementById('end_date').disabled = document.getElementById('no_duration').checked; }
function toggleLimit()   { document.getElementById('usage_limit').disabled = document.getElementById('no_limit').checked; }
// Set initial hint
document.addEventListener('DOMContentLoaded', () => {
    const checked = document.querySelector('input[name="type"]:checked');
    if (checked) selectType(checked.closest('.coupon-type-card'), checked.value);
});
</script>

<?php include 'includes/footer.php'; ?>
