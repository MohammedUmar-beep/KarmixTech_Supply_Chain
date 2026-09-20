<?php
require_once 'includes/auth_guard.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

// Fetch categories and products for the conditional dropdowns
$coupon_cats = [];
$cats_res = $conn->query("SELECT DISTINCT category FROM products WHERE status != 'Deleted' AND category != '' ORDER BY category");
if ($cats_res) { while ($r = $cats_res->fetch_row()) $coupon_cats[] = $r[0]; }

$coupon_prods = [];
$prods_res = $conn->query("SELECT id, product_name, sku_code FROM products WHERE status != 'Deleted' ORDER BY product_name");
if ($prods_res) { while ($r = $prods_res->fetch_assoc()) $coupon_prods[] = $r; }

$page_title   = 'Add Coupon';
$current_page = 'coupons.php';
include 'includes/header.php';
?>
<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="coupons.php" class="back-link">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Coupons
            </a>
            <h1 style="font-size:24px;">Add Coupon</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <form action="save_coupon.php" method="POST" class="coupon-form"
        style="max-width:800px; margin:24px auto; background:var(--card-bg); padding:30px; border-radius:12px; border:1px solid var(--border-color);">

        <?= csrf_token() ?>
        <!-- Section 1: Basic Info -->
        <h3 style="font-size:15px; font-weight:600; margin-bottom:4px;">Coupon Information</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">This code is entered by the cashier at POS checkout.</p>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px;">
            <div>
                <label class="form-label" style="font-weight:500;">Coupon Code <span style="color:#ef4444;">*</span></label>
                <input type="text" name="code" class="form-input" placeholder="e.g. SAVE20" required
                    style="background:transparent; text-transform:uppercase;"
                    oninput="this.value=this.value.toUpperCase()">
                <span style="font-size:11px; color:var(--text-muted);">Used at POS — keep it short and memorable</span>
            </div>
            <div>
                <label class="form-label" style="font-weight:500;">Coupon Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" class="form-input" placeholder="e.g. Summer Sale 20% Off" required
                    style="background:transparent;">
                <span style="font-size:11px; color:var(--text-muted);">Internal label shown in the coupons list</span>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border-color); margin-bottom:28px;">

        <!-- Section 2: Type -->
        <h3 style="font-size:15px; font-weight:600; margin-bottom:4px;">Coupon Type</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:16px;">Choose how the discount is applied at checkout.</p>

        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:24px;">
            <label class="coupon-type-card active" onclick="selectType(this,'Fixed Discount')">
                <input type="radio" name="type" value="Fixed Discount" checked style="display:none;">
                <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" fill="none" stroke-width="2" style="margin-bottom:8px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span style="font-weight:600;">Fixed Amount</span>
                <span style="font-size:11px; opacity:.7;">e.g. '+\$currency['symbol']+'100 off</span>
            </label>
            <label class="coupon-type-card" onclick="selectType(this,'Percentage Discount')">
                <input type="radio" name="type" value="Percentage Discount" style="display:none;">
                <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" fill="none" stroke-width="2" style="margin-bottom:8px;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="16" y1="8" x2="8" y2="16"/><circle cx="9" cy="9" r="1" fill="currentColor"/><circle cx="15" cy="15" r="1" fill="currentColor"/></svg>
                <span style="font-weight:600;">Percentage Off</span>
                <span style="font-size:11px; opacity:.7;">e.g. 15% off total</span>
            </label>
            <label class="coupon-type-card" onclick="selectType(this,'Free Shipping')">
                <input type="radio" name="type" value="Free Shipping" style="display:none;">
                <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" fill="none" stroke-width="2" style="margin-bottom:8px;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                <span style="font-weight:600;">Free Shipping</span>
                <span style="font-size:11px; opacity:.7;">Waives delivery charge</span>
            </label>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
            <div>
                <label class="form-label" style="font-weight:500;">Discount Value <span id="valueHint" style="font-weight:400; color:var(--text-muted);">(amount)</span></label>
                <input type="number" step="0.01" min="0" name="value" id="valueInput" class="form-input" placeholder="0.00" required
                    style="background:transparent;">
            </div>
            <div>
                <label class="form-label" style="font-weight:500;">Minimum Order Value <span style="font-weight:400; color:var(--text-muted);">(optional)</span></label>
                <input type="number" step="0.01" min="0" name="min_order_value" class="form-input" placeholder="0.00 — no minimum"
                    style="background:transparent;">
                <span style="font-size:11px; color:var(--text-muted);">Coupon won't apply if cart total is below this</span>
            </div>
        </div>

        <div style="margin-bottom:28px;">
            <label class="form-label" style="font-weight:500;">Applies To</label>
            <select name="applies_to" id="appliesToSelect" class="form-input" style="background:transparent; max-width:320px;" onchange="handleAppliesTo(this.value)">
                <option value="All Products">All Products</option>
                <option value="Specific Category">Specific Category</option>
                <option value="Specific Product">Specific Product</option>
            </select>

            <!-- Category picker — shown only when Specific Category is selected -->
            <div id="categoryPickerWrap" style="display:none; margin-top:12px;">
                <label class="form-label" style="font-weight:500;">Select Category <span style="color:#ef4444;">*</span></label>
                <select name="applies_target" id="categoryPicker" class="form-input" style="background:transparent; max-width:320px;">
                    <option value="">— Choose a category —</option>
                    <?php foreach ($coupon_cats as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:11px; color:var(--text-muted);">Coupon applies only to products in this category</span>
            </div>

            <!-- Product picker — shown only when Specific Product is selected -->
            <div id="productPickerWrap" style="display:none; margin-top:12px;">
                <label class="form-label" style="font-weight:500;">Select Product <span style="color:#ef4444;">*</span></label>
                <select name="applies_target" id="productPicker" class="form-input" style="background:transparent; max-width:320px;">
                    <option value="">— Choose a product —</option>
                    <?php foreach ($coupon_prods as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?><?= $p['sku_code'] ? ' (' . htmlspecialchars($p['sku_code']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:11px; color:var(--text-muted);">Coupon applies only to this specific product</span>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border-color); margin-bottom:28px;">

        <!-- Section 3: Validity -->
        <h3 style="font-size:15px; font-weight:600; margin-bottom:4px;">Validity & Limits</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Control when and how many times this coupon can be used.</p>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
            <div>
                <label class="form-label" style="font-weight:500;">Active Period</label>
                <div style="display:flex; gap:10px;">
                    <input type="date" name="start_date" class="form-input" style="background:transparent; flex:1;" title="Start Date">
                    <input type="date" name="end_date" id="end_date" class="form-input" style="background:transparent; flex:1;" title="End Date">
                </div>
                <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12px; color:var(--text-muted); cursor:pointer;">
                    <input type="checkbox" name="no_duration" id="no_duration" onchange="toggleEndDate()"> No expiry date
                </label>
            </div>
            <div>
                <label class="form-label" style="font-weight:500;">Usage Limit</label>
                <input type="number" min="1" name="usage_limit" id="usage_limit" class="form-input" placeholder="e.g. 100"
                    style="background:transparent;">
                <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12px; color:var(--text-muted); cursor:pointer;">
                    <input type="checkbox" name="no_limit" id="no_limit" onchange="toggleLimit()" checked> Unlimited uses
                </label>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:36px; border-top:1px solid var(--border-color); padding-top:20px;">
            <button type="button" class="btn btn-outline" onclick="window.location.href='coupons.php'">Cancel</button>
            <button type="submit" class="btn btn-primary" style="padding:9px 32px; font-weight:600;">Create Coupon</button>
        </div>
    </form>
</main>

<style>
.coupon-type-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 18px 10px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 13px;
    gap: 2px;
    transition: all 0.2s;
}
.coupon-type-card:hover { border-color: var(--primary-color); color: var(--primary-color); }
.coupon-type-card.active {
    border: 2px solid var(--primary-color);
    color: var(--primary-color);
    background: var(--primary-bg, rgba(109,74,255,.06));
}
</style>

<script>
// Unlock usage_limit — default is unlimited (checked), so disable input on load
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('usage_limit').disabled = true;
});

function selectType(el, typeValue) {
    document.querySelectorAll('.coupon-type-card').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    const hint = document.getElementById('valueHint');
    if (typeValue === 'Percentage Discount') hint.textContent = '(enter %, e.g. 15 = 15%)';
    else if (typeValue === 'Free Shipping')  hint.textContent = '(leave 0 — no deduction from subtotal)';
    else                                     hint.textContent = '(fixed amount off)';
}

function toggleEndDate() {
    document.getElementById('end_date').disabled = document.getElementById('no_duration').checked;
}

function toggleLimit() {
    document.getElementById('usage_limit').disabled = document.getElementById('no_limit').checked;
}

function handleAppliesTo(val) {
    const catWrap  = document.getElementById('categoryPickerWrap');
    const prodWrap = document.getElementById('productPickerWrap');
    const catPick  = document.getElementById('categoryPicker');
    const prodPick = document.getElementById('productPicker');

    catWrap.style.display  = val === 'Specific Category' ? 'block' : 'none';
    prodWrap.style.display = val === 'Specific Product'  ? 'block' : 'none';

    // Only require the visible one
    catPick.required  = val === 'Specific Category';
    prodPick.required = val === 'Specific Product';
}
</script>

<?php include 'includes/footer.php'; ?>
