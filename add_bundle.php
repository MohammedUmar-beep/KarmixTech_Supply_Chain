<?php
require_once 'includes/auth_guard.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: dashboard.php"); exit();
}

// Handle POST save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = $conn->real_escape_string(trim($_POST['bundle_name'] ?? ''));
    // Always auto-generate SKU server-side (ignore POST value to prevent duplicates)
    $next_sku_id   = $conn->query("SELECT COALESCE(MAX(id),0)+1 FROM bundles")->fetch_row()[0];
    $sku           = 'SKU-BN-' . str_pad($next_sku_id, 3, '0', STR_PAD_LEFT);
    $desc          = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $disc_type     = $conn->real_escape_string($_POST['discount_type'] ?? 'percentage');
    $disc_val      = floatval($_POST['discount_value'] ?? 0);
    $valid_from    = !empty($_POST['valid_from'])  ? $conn->real_escape_string($_POST['valid_from'])  : 'NULL';
    $valid_until   = !empty($_POST['valid_until']) ? $conn->real_escape_string($_POST['valid_until']) : 'NULL';
    $product_ids   = $_POST['product_id']  ?? [];
    $quantities    = $_POST['quantity']    ?? [];
    $user_id       = intval($_SESSION['user_id']);
    $override      = isset($_POST['override_loss']) ? 1 : 0;

    if (empty($name) || empty($sku) || empty($product_ids)) {
        $error = 'Bundle name, SKU and at least one product are required.';
    } else {
        // Calculate totals from product prices
        $total_cost = 0; $normal_sell = 0; $items_data = [];
        foreach ($product_ids as $i => $pid) {
            $pid = intval($pid); $qty = intval($quantities[$i] ?? 1);
            if ($pid <= 0 || $qty <= 0) continue;
            $p = $conn->query("SELECT purchasing_price, selling_price FROM products WHERE id=$pid")->fetch_assoc();
            if (!$p) continue;
            $lc = $p['purchasing_price'] * $qty;
            $ls = $p['selling_price'] * $qty;
            $total_cost  += $lc;
            $normal_sell += $ls;
            $items_data[] = ['pid'=>$pid,'qty'=>$qty,'cost'=>$p['purchasing_price'],'sell'=>$p['selling_price'],'lc'=>$lc,'ls'=>$ls];
        }
        // Compute bundle price
        if ($disc_type === 'percentage')   $bundle_price = $normal_sell * (1 - $disc_val/100);
        elseif ($disc_type === 'fixed')    $bundle_price = $normal_sell - $disc_val;
        else                               $bundle_price = $disc_val;  // custom = user-set price
        $bundle_price = max(0, $bundle_price);
        $profit       = $bundle_price - $total_cost;
        $margin       = $bundle_price > 0 ? ($profit / $bundle_price * 100) : 0;

        if ($profit < 0 && !$override) {
            $error = 'Bundle price is below total cost. Check "Override loss" to save anyway.';
        } else {
            $conn->begin_transaction();
            try {
                $vf  = $valid_from  === 'NULL' ? 'NULL' : "'$valid_from'";
                $vu  = $valid_until === 'NULL' ? 'NULL' : "'$valid_until'";
                // 11 placeholders: name, sku, desc, disc_type, disc_val, bundle_price,
                //                  total_cost, normal_sell, profit, margin, user_id
                $sql = "INSERT INTO bundles (bundle_id_str,bundle_name,sku_code,description,discount_type,discount_value,bundle_price,total_cost,normal_sell_value,profit_amount,profit_margin_pct,valid_from,valid_until,status,created_by)
                        VALUES ('BNDL-TMP',?,?,?,?,?,?,?,?,?,?,$vf,$vu,'Active',?)";
                $stmt = $conn->prepare($sql);
                // Type string: 4×string, 6×double, 1×int = 11 chars
                $stmt->bind_param("ssssddddddi",
                    $name, $sku, $desc, $disc_type,
                    $disc_val, $bundle_price, $total_cost, $normal_sell,
                    $profit, $margin, $user_id);
                $stmt->execute();
                $bid = $conn->insert_id;
                $bid_str = 'BNDL-'.str_pad($bid,4,'0',STR_PAD_LEFT);
                $conn->query("UPDATE bundles SET bundle_id_str='$bid_str' WHERE id=$bid");
                $stmt->close();

                // ── Handle bundle image upload ────────────────────────────────
                if (isset($_FILES['bundle_image']) && $_FILES['bundle_image']['error'] === UPLOAD_ERR_OK) {
                    $img_tmp  = $_FILES['bundle_image']['tmp_name'];
                    $img_name = basename($_FILES['bundle_image']['name']);
                    $img_ext  = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));
                    $allowed_img_exts = ['jpg','jpeg','png','gif','webp'];
                    if (in_array($img_ext, $allowed_img_exts)) {
                        $img_dir = 'uploads/bundles/';
                        if (!is_dir($img_dir)) mkdir($img_dir, 0777, true);
                        $img_path = $img_dir . $bid_str . '_' . uniqid() . '.' . $img_ext;
                        if (move_uploaded_file($img_tmp, $img_path)) {
                            $img_path_esc = $conn->real_escape_string($img_path);
                            $conn->query("UPDATE bundles SET image_path='$img_path_esc' WHERE id=$bid");
                        }
                    }
                }

                foreach ($items_data as $it) {
                    $conn->query("INSERT INTO bundle_items (bundle_id,product_id,quantity,unit_cost_snapshot,unit_sell_snapshot,line_cost,line_sell)
                                  VALUES ($bid,{$it['pid']},{$it['qty']},{$it['cost']},{$it['sell']},{$it['lc']},{$it['ls']})");
                }
                if ($profit < 0)
                    log_activity($conn,'Create','Bundle',$bid_str,"Bundle created with override (loss: '+\$currency['symbol']+'".number_format(abs($profit),2).")");
                else
                    log_activity($conn,'Create','Bundle',$bid_str,"Created bundle: $name | Price: '+\$currency['symbol']+'$bundle_price | Margin: ".round($margin,1)."%");
                $conn->commit();
                header("Location: bundles.php?msg=created"); exit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Database error: '.$e->getMessage();
            }
        }
    }
}

// Fetch all products for selector
$products_res = $conn->query("SELECT id, product_name, sku_code, purchasing_price, selling_price, stock_level FROM products WHERE status='Available' ORDER BY product_name");
$products_list = [];
while ($p = $products_res->fetch_assoc()) $products_list[] = $p;

// Auto-generate next bundle SKU
$next_bndl_res = $conn->query("SELECT MAX(id) FROM bundles");
$next_bndl_id  = $next_bndl_res ? (intval($next_bndl_res->fetch_row()[0]) + 1) : 1;
$gen_sku       = 'SKU-BN-' . str_pad($next_bndl_id, 3, '0', STR_PAD_LEFT);

$page_title   = 'Create Bundle';
$current_page = 'bundles.php';
include 'includes/header.php';
?>
<style>
.bundle-form-wrap { max-width:860px; margin:24px auto; padding:0 24px; }
.form-section { background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:24px; margin-bottom:20px; }
.form-section h3 { font-size:15px; font-weight:600; margin-bottom:4px; }
.form-section p  { font-size:13px; color:var(--text-muted); margin-bottom:20px; }
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
.product-row { display:grid; grid-template-columns:1fr 80px 80px 80px 28px; gap:10px; align-items:center; margin-bottom:8px; }
.product-row.header { font-size:11px; color:var(--text-muted); font-weight:500; margin-bottom:4px; }
.pr-cost-cell { text-align:right; font-size:13px; color:var(--text-muted); }
.pr-sell-cell { text-align:right; font-size:13px; font-weight:500; }
.remove-row { background:none; border:none; cursor:pointer; color:var(--danger-color); font-size:16px; line-height:1; padding:0; }
.profit-panel { background:var(--bg-light); border:1.5px solid var(--border-color); border-radius:10px; padding:16px; margin-top:16px; }
.pp-row { display:flex; justify-content:space-between; font-size:13px; padding:4px 0; }
.pp-row.big { font-size:15px; font-weight:600; padding-top:8px; border-top:1px solid var(--border-color); margin-top:4px; }
.pp-profit  { color:var(--success-color); }
.pp-loss    { color:var(--danger-color); }
.warn-box { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); border-radius:8px; padding:10px 14px; font-size:13px; color:var(--danger-color); margin-top:10px; display:none; }
.ok-box   { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.25); border-radius:8px; padding:10px 14px; font-size:13px; color:var(--success-color); margin-top:10px; }
.margin-bar { height:5px; border-radius:3px; background:var(--border-color); overflow:hidden; margin-top:10px; }
.margin-fill { height:100%; border-radius:3px; transition:width .3s,background .3s; }
</style>

<main class="main-area">
<div class="topbar">
    <div class="topbar-left">
        <a href="bundles.php" class="back-link">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Bundles
        </a>
        <h1 style="font-size:24px;">Create Bundle</h1>
    </div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<?php if (!empty($error)): ?>
<div style="margin:0 24px;margin-top:12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--danger-color);padding:10px 16px;border-radius:8px;font-size:13px;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" class="bundle-form-wrap" id="bundleForm" enctype="multipart/form-data">

    <!-- Basic Info -->
    <div class="form-section">
        <h3>Bundle Information</h3>
        <p>Name and identify the bundle. SKU is auto-generated and used at POS.</p>
        <div class="form-grid-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">Bundle Name <span style="color:#ef4444">*</span></label>
                <input type="text" name="bundle_name" class="form-input" placeholder="e.g. Office Starter Pack" required value="<?= htmlspecialchars($_POST['bundle_name'] ?? '') ?>">
            </div>
            <div>
                <label class="form-label">SKU Code <span style="color:var(--text-muted);font-weight:400;font-size:12px;">(auto-generated)</span></label>
                <input type="text" name="sku_code" id="skuField" class="form-input" value="<?= htmlspecialchars($_POST['sku_code'] ?? $gen_sku) ?>" readonly
                    style="background:var(--bg-light);cursor:not-allowed;color:var(--text-muted);text-transform:uppercase;">
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-input" rows="2" placeholder="Optional — describe what's included"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
        </div>
        <!-- Bundle Image Upload -->
        <div>
            <label class="form-label">Bundle Image <span style="color:var(--text-muted);font-weight:400;font-size:12px;">(optional)</span></label>
            <input type="file" name="bundle_image" id="bundleImageInput" accept="image/*" style="display:none;"
                onchange="
                    const file = this.files[0];
                    if (!file) return;
                    const preview = document.getElementById('bundleImagePreview');
                    const placeholder = document.getElementById('bundleImagePlaceholder');
                    const info = document.getElementById('bundleImageInfo');
                    const reader = new FileReader();
                    reader.onload = e => {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                        placeholder.style.display = 'none';
                    };
                    reader.readAsDataURL(file);
                    info.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                ">
            <div onclick="document.getElementById('bundleImageInput').click();"
                style="border:2px dashed var(--border-color);border-radius:10px;padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;background:var(--bg-light);min-height:140px;transition:border-color .2s;"
                onmouseenter="this.style.borderColor='var(--primary-color)'" onmouseleave="this.style.borderColor='var(--border-color)'">
                <img id="bundleImagePreview" src="" alt="" style="display:none;max-height:100px;max-width:200px;border-radius:6px;object-fit:contain;margin-bottom:8px;">
                <div id="bundleImagePlaceholder" style="display:flex;flex-direction:column;align-items:center;gap:6px;">
                    <svg viewBox="0 0 24 24" width="32" height="32" stroke="var(--primary-color)" fill="none" stroke-width="1.5">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <span style="color:var(--primary-color);font-weight:500;font-size:13px;">Click to upload image</span>
                    <span style="color:var(--text-muted);font-size:12px;">PNG, JPG, WEBP (max 5MB)</span>
                </div>
                <span id="bundleImageInfo" style="font-size:12px;color:var(--text-muted);margin-top:6px;"></span>
            </div>
        </div>
    </div>

    <!-- Products -->
    <div class="form-section">
        <h3>Bundle Products <span style="color:#ef4444">*</span></h3>
        <p>Add the products that make up this bundle. Cost and sell value are pulled from your product catalogue.</p>

        <div class="product-row header">
            <span>Product</span><span>Qty</span><span style="text-align:right">Unit cost</span><span style="text-align:right">Unit sell</span><span></span>
        </div>
        <div id="product-rows"></div>

        <button type="button" onclick="addProductRow()" style="margin-top:8px;background:none;border:1.5px dashed var(--border-color);border-radius:8px;padding:8px 16px;width:100%;cursor:pointer;color:var(--primary-color);font-size:13px;font-weight:500;">
            + Add Product
        </button>
    </div>

    <!-- Discount & Pricing -->
    <div class="form-section">
        <h3>Discount &amp; Pricing</h3>
        <p>Set how the bundle is discounted. The profit preview updates live.</p>
        <div class="form-grid-3" style="margin-bottom:0">
            <div>
                <label class="form-label">Discount Type</label>
                <select name="discount_type" class="form-select" id="disc_type" onchange="recalc()">
                    <option value="percentage">Percentage % off</option>
                    <option value="fixed">Fixed '+\$currency['symbol']+' off</option>
                    <option value="custom">Custom price</option>
                </select>
            </div>
            <div>
                <label class="form-label" id="disc_value_label">Discount Value</label>
                <input type="number" name="discount_value" class="form-input" id="disc_value" value="10" min="0" step="0.01" oninput="recalc()">
            </div>
            <div>
                <label class="form-label">Valid Until (optional)</label>
                <input type="date" name="valid_until" class="form-input">
            </div>
        </div>

        <!-- Live profit panel -->
        <div class="profit-panel" id="profitPanel">
            <div class="pp-row"><span style="color:var(--text-muted)">Total product cost (purchasing prices)</span><span id="pp_cost">'+\$currency['symbol']+'0.00</span></div>
            <div class="pp-row"><span style="color:var(--text-muted)">Normal sell value (individual prices)</span><span id="pp_normal">'+\$currency['symbol']+'0.00</span></div>
            <div class="pp-row"><span style="color:var(--text-muted)">Your discount</span><span id="pp_disc" style="color:var(--danger-color)">-'+\$currency['symbol']+'0.00</span></div>
            <div class="pp-row big"><span>Bundle price</span><span id="pp_price">'+\$currency['symbol']+'0.00</span></div>
            <div class="pp-row big" id="pp_profit_row"><span>Profit</span><span id="pp_profit" class="pp-profit">'+\$currency['symbol']+'0.00</span></div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-top:6px;">
                <span>Profit margin</span><span id="pp_margin" style="font-weight:600">0.0%</span>
            </div>
            <div class="margin-bar"><div class="margin-fill" id="pp_bar" style="width:0%;background:#10b981;"></div></div>
            <div class="warn-box" id="pp_warn_loss">⚠ Bundle price is below total cost — you will make a loss on every sale.</div>
            <div class="warn-box" id="pp_warn_low" style="background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.3);color:#b45309;">⚠ Margin is below 10% — very tight profit.</div>
        </div>

        <!-- Override -->
        <div id="override_section" style="display:none;margin-top:12px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="override_loss" value="1"> Override and save loss-making bundle (reason will be logged)
            </label>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:12px;margin-bottom:40px;">
        <a href="bundles.php" style="padding:10px 20px;border-radius:8px;border:1px solid var(--border-color);font-size:14px;text-decoration:none;color:var(--text-dark);">Cancel</a>
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:14px;font-weight:600;">Create Bundle</button>
    </div>
</form>
</main>

<script>
const PRODUCTS = <?= json_encode($products_list) ?>;
const prodMap  = {};
PRODUCTS.forEach(p => { prodMap[p.id] = p; });
let rowCount = 0;

function addProductRow(pid=0, qty=1) {
    const i = rowCount++;
    const opts = PRODUCTS.map(p =>
        `<option value="${p.id}" ${p.id==pid?'selected':''}>${p.product_name} (${p.sku_code})</option>`
    ).join('');
    const row = document.createElement('div');
    row.className = 'product-row';
    row.id = `row_${i}`;
    row.innerHTML = `
        <select name="product_id[]" class="form-select" onchange="onProdChange(${i})" id="prod_${i}">
            <option value="">— Select product —</option>${opts}
        </select>
        <input type="number" name="quantity[]" class="form-input" value="${qty}" min="1" id="qty_${i}" oninput="recalc()" style="text-align:center">
        <div class="pr-cost-cell" id="cost_${i}">—</div>
        <div class="pr-sell-cell" id="sell_${i}">—</div>
        <button type="button" class="remove-row" onclick="removeRow(${i})">✕</button>`;
    document.getElementById('product-rows').appendChild(row);
    if (pid) { document.getElementById(`prod_${i}`).value = pid; onProdChange(i, qty); }
}

function onProdChange(i) {
    const pid  = document.getElementById(`prod_${i}`).value;
    const qty  = parseInt(document.getElementById(`qty_${i}`).value)||1;
    const p    = prodMap[pid];
    if (p) {
        document.getElementById(`cost_${i}`).textContent = ''+\$currency['symbol']+''+parseFloat(p.purchasing_price).toFixed(2);
        document.getElementById(`sell_${i}`).textContent = window.CURRENCY.symbol+parseFloat(p.selling_price).toFixed(2);
    } else {
        document.getElementById(`cost_${i}`).textContent = '—';
        document.getElementById(`sell_${i}`).textContent = '—';
    }
    recalc();
}

function removeRow(i) {
    const el = document.getElementById(`row_${i}`);
    if (el) el.remove();
    recalc();
}

function recalc() {
    let totalCost = 0, normalSell = 0;
    document.querySelectorAll('[name="product_id[]"]').forEach((sel, idx) => {
        const pid  = sel.value;
        const qtyEl = document.querySelectorAll('[name="quantity[]"]')[idx];
        const qty  = parseInt(qtyEl?.value||1)||1;
        const p    = prodMap[pid];
        if (!p) return;
        totalCost  += parseFloat(p.purchasing_price) * qty;
        normalSell += parseFloat(p.selling_price)    * qty;
    });

    const dtype = document.getElementById('disc_type').value;
    const dval  = parseFloat(document.getElementById('disc_value').value)||0;
    let bundlePrice;
    if (dtype === 'percentage')   bundlePrice = normalSell * (1 - dval/100);
    else if (dtype === 'fixed')   bundlePrice = normalSell - dval;
    else                          bundlePrice = dval;
    bundlePrice = Math.max(0, bundlePrice);

    const disc   = normalSell - bundlePrice;
    const profit = bundlePrice - totalCost;
    const margin = bundlePrice > 0 ? (profit/bundlePrice*100) : 0;
    const barW   = Math.max(0,Math.min(100,margin));
    const barCol = profit < 0 ? '#ef4444' : margin < 10 ? '#f59e0b' : '#10b981';

    document.getElementById('pp_cost').textContent   = window.CURRENCY.symbol+totalCost.toFixed(2);
    document.getElementById('pp_normal').textContent = window.CURRENCY.symbol+normalSell.toFixed(2);
    document.getElementById('pp_disc').textContent   = '-'+window.CURRENCY.symbol+Math.max(0,disc).toFixed(2);
    document.getElementById('pp_price').textContent  = window.CURRENCY.symbol+bundlePrice.toFixed(2);
    document.getElementById('pp_profit').textContent = (profit<0?'-':'')+window.CURRENCY.symbol+Math.abs(profit).toFixed(2);
    document.getElementById('pp_profit').className   = profit < 0 ? 'pp-loss' : 'pp-profit';
    document.getElementById('pp_margin').textContent = margin.toFixed(1)+'%';
    document.getElementById('pp_margin').style.color = barCol;
    document.getElementById('pp_bar').style.width    = barW+'%';
    document.getElementById('pp_bar').style.background = barCol;

    document.getElementById('pp_warn_loss').style.display = profit < 0 ? 'block' : 'none';
    document.getElementById('pp_warn_low').style.display  = (profit >= 0 && margin < 10) ? 'block' : 'none';
    document.getElementById('override_section').style.display = profit < 0 ? 'block' : 'none';

    // Update label
    const labels = {percentage:'Discount %', fixed:'Discount '+window.CURRENCY.symbol, custom:'Custom bundle price ('+window.CURRENCY.symbol+')'};
    document.getElementById('disc_value_label').textContent = labels[dtype] || 'Value';
}

// Start with one empty row
addProductRow();
recalc();
</script>
<?php include 'includes/footer.php'; ?>
