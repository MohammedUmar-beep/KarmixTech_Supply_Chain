<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');
$is_admin  = strtolower($user_role) === 'admin';

$current_page = 'settings.php';
$page_title   = 'Settings';

$biz = get_business_settings($conn);

// ── Merged from settings_taxes.php ───────────────────────────────────────────
$tax_success = '';
$tax_error   = '';

if ($is_admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_tax') {
        $tax_name = $conn->real_escape_string(trim($_POST['tax_name']));
        $rate     = floatval($_POST['rate_percent']);
        $stmt = $conn->prepare("INSERT INTO tax_rules (tax_name, rate_percent) VALUES (?, ?)");
        $stmt->bind_param("sd", $tax_name, $rate);
        if ($stmt->execute()) {
            $tax_success = "Tax rule '$tax_name' created.";
            log_activity($conn, 'Create', 'Tax Rule', $conn->insert_id, "Created tax $tax_name ($rate%)");
        } else {
            $tax_error = "Error creating rule.";
        }
        $stmt->close();
    }
    if (isset($_GET['tax_action'], $_GET['tax_id'])) {
        $tid = intval($_GET['tax_id']);
        if ($_GET['tax_action'] === 'toggle') {
            $conn->query("UPDATE tax_rules SET is_active = NOT is_active WHERE id = $tid");
            $tax_success = "Tax rule status updated.";
        } elseif ($_GET['tax_action'] === 'delete') {
            $conn->query("DELETE FROM tax_rules WHERE id = $tid");
            $tax_success = "Tax rule deleted.";
        }
        header("Location: settings.php?tab=taxes&msg=" . urlencode($tax_success));
        exit();
    }
    if (isset($_GET['msg'])) $tax_success = htmlspecialchars($_GET['msg']);
    $taxes_res   = $conn->query("SELECT * FROM tax_rules ORDER BY is_active DESC, tax_name ASC");
    $total_rate  = floatval($conn->query("SELECT COALESCE(SUM(rate_percent),0) FROM tax_rules WHERE is_active=1")->fetch_row()[0]);
}

$active_tab = $_GET['tab'] ?? 'general';
// ─────────────────────────────────────────────────────────────────────────────

$extra_head = '<style>
.settings-layout {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 28px;
    align-items: start;
    max-width: 1000px;
}
.settings-nav {
    background: var(--card-bg, var(--white));
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 8px;
    position: sticky;
    top: 20px;
}
.settings-nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}
.settings-nav-item:hover { background: var(--bg-hover); color: var(--text-dark); }
.settings-nav-item.active { background: var(--primary-bg, rgba(109,74,255,0.08)); color: var(--primary-color); font-weight: 600; }
.settings-nav-item svg { flex-shrink: 0; }
.settings-panels { display: flex; flex-direction: column; gap: 24px; }
.settings-card {
    background: var(--card-bg, var(--white));
    border-radius: 12px;
    padding: 28px 30px;
    border: 1px solid var(--border-color);
}
.settings-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
}
.settings-card-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: var(--primary-bg, rgba(109,74,255,0.1));
    color: var(--primary-color);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.settings-card-header h3 { margin: 0; font-size: 17px; font-weight: 700; color: var(--text-dark); }
.settings-card-header p  { margin: 3px 0 0 0; font-size: 13px; color: var(--text-muted); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-row.three { grid-template-columns: 1fr 1fr 1fr; }
.form-group { margin-bottom: 18px; }
.form-group:last-child { margin-bottom: 0; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 7px; color: var(--text-dark); }
.form-group input, .form-group textarea, .form-group select {
    width: 100%; padding: 9px 13px; border: 1px solid var(--border-color);
    border-radius: 8px; background: var(--input-bg, var(--bg-light));
    color: var(--text-dark); font-size: 14px; font-family: inherit;
    box-sizing: border-box; transition: border-color 0.15s; outline: none;
}
.form-group input:focus, .form-group textarea:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-bg, rgba(109,74,255,0.1));
}
.form-group textarea { resize: vertical; min-height: 80px; }
.save-bar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding-top: 20px; border-top: 1px solid var(--border-color); margin-top: 22px; }
.save-msg { font-size: 13px; color: var(--success-color, #059669); display: none; }
.color-options { display: flex; gap: 14px; flex-wrap: wrap; }
.color-swatch { width: 38px; height: 38px; border-radius: 50%; cursor: pointer; border: 3px solid transparent; transition: transform 0.2s, border-color 0.2s; }
.color-swatch:hover { transform: scale(1.1); }
.color-swatch.active { border-color: var(--text-dark); box-shadow: 0 0 0 2px var(--bg-light); }
.theme-toggle-group { display: flex; gap: 20px; }
.theme-option { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 15px; color: var(--text-dark); }
.theme-option input[type="radio"] { width: 18px; height: 18px; accent-color: var(--primary-color); }
.link-card { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border: 1px solid var(--border-color); border-radius: 10px; background: var(--bg-light); margin-bottom: 12px; text-decoration: none; transition: border-color 0.15s; }
.link-card:last-child { margin-bottom: 0; }
.link-card:hover { border-color: var(--primary-color); }
.link-card-left { display: flex; align-items: center; gap: 12px; }
.link-card-left svg { color: var(--primary-color); }
.link-card-title { font-size: 14px; font-weight: 600; color: var(--text-dark); }
.link-card-desc  { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.link-card-arrow { color: var(--text-muted); }
@media (max-width: 768px) {
    .settings-layout { grid-template-columns: 1fr; }
    .settings-nav { position: static; display: flex; flex-wrap: wrap; gap: 4px; }
    .form-row, .form-row.three { grid-template-columns: 1fr; }
}
</style>';

include 'includes/header.php';
?>
<main class="main-area">
    <div class="topbar">
        <div class="topbar-left"><h1>Settings</h1></div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="settings-layout">
        <nav class="settings-nav">
            <?php if ($is_admin): ?>
            <a class="settings-nav-item active" href="#biz">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                Business Info
            </a>
            <?php endif; ?>
            <a class="settings-nav-item" href="#appearance">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v2m0 18v-2m10-8h-2M4 12H2m15.07-7.07-1.41 1.41M7.34 16.66l-1.41 1.41M16.66 16.66l1.41 1.41M7.34 7.34 5.93 5.93"/></svg>
                Appearance
            </a>
            <?php if ($is_admin): ?>
            <a class="settings-nav-item" href="#finance">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Tax & Finance
            </a>
            <a class="settings-nav-item" href="#data">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                Data & Import
            </a>
            <?php endif; ?>
        </nav>

        <div class="settings-panels">

            <?php if ($is_admin): ?>
            <!-- Business Information -->
            <div class="settings-card" id="biz">
                <div class="settings-card-header">
                    <div class="settings-card-icon">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    </div>
                    <div>
                        <h3>Business Information</h3>
                        <p>Shown on all invoices, bills, and printed documents.</p>
                    </div>
                </div>
                <form id="bizForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Company / Business Name</label>
                            <input type="text" name="company_name" value="<?= htmlspecialchars($biz['company_name']) ?>" placeholder="Acme Corp Ltd." required>
                        </div>
                        <div class="form-group">
                            <label>Tax ID / GST Number</label>
                            <input type="text" name="company_tax_id" value="<?= htmlspecialchars($biz['company_tax_id']) ?>" placeholder="00XXXXXX1234X0XX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Street Address</label>
                        <input type="text" name="company_address" value="<?= htmlspecialchars($biz['company_address']) ?>" placeholder="123 Business Avenue, Suite 4">
                    </div>
                    <div class="form-row three">
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="company_city" value="<?= htmlspecialchars($biz['company_city']) ?>" placeholder="Mumbai">
                        </div>
                        <div class="form-group">
                            <label>State</label>
                            <input type="text" name="company_state" value="<?= htmlspecialchars($biz['company_state']) ?>" placeholder="Maharashtra">
                        </div>
                        <div class="form-group">
                            <label>PIN Code</label>
                            <input type="text" name="company_pincode" value="<?= htmlspecialchars($biz['company_pincode']) ?>" placeholder="400001">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="company_phone" value="<?= htmlspecialchars($biz['company_phone']) ?>" placeholder="+91 98765 43210">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="company_email" value="<?= htmlspecialchars($biz['company_email']) ?>" placeholder="admin@yourcompany.com">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Website</label>
                            <input type="text" name="company_website" value="<?= htmlspecialchars($biz['company_website']) ?>" placeholder="www.yourcompany.com">
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="company_country" value="<?= htmlspecialchars($biz['company_country']) ?>" placeholder="India">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Invoice Footer Notes</label>
                        <textarea name="invoice_notes" placeholder="e.g. Please pay within 15 days. Bank: HDFC XXXX..."><?= htmlspecialchars($biz['invoice_notes']) ?></textarea>
                    </div>
                    <div class="save-bar">
                        <span class="save-msg" id="bizSaveMsg"></span>
                        <button type="submit" class="btn btn-primary" style="padding:9px 24px;">Save Business Info</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Appearance -->
            <div class="settings-card" id="appearance">
                <div class="settings-card-header">
                    <div class="settings-card-icon">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v2m0 18v-2m10-8h-2M4 12H2m15.07-7.07-1.41 1.41M7.34 16.66l-1.41 1.41M16.66 16.66l1.41 1.41M7.34 7.34 5.93 5.93"/></svg>
                    </div>
                    <div>
                        <h3>Theme & Appearance</h3>
                        <p>Saved to your browser — applies only to your session.</p>
                    </div>
                </div>
                <div class="form-group">
                    <label>Mode</label>
                    <div class="theme-toggle-group">
                        <label class="theme-option"><input type="radio" name="theme_mode" value="light" id="theme-light"> Light Mode</label>
                        <label class="theme-option"><input type="radio" name="theme_mode" value="dark" id="theme-dark"> Dark Mode</label>
                    </div>
                </div>
                <div class="form-group" style="margin-top:22px;">
                    <label>Primary Colour</label>
                    <div class="color-options">
                        <div class="color-swatch" data-color="#6d4aff" style="background:#6d4aff;" title="Default Purple"></div>
                        <div class="color-swatch" data-color="#1E5EFF" style="background:#1E5EFF;" title="Royal Blue"></div>
                        <div class="color-swatch" data-color="#336DFF" style="background:#336DFF;" title="Sky Blue"></div>
                        <div class="color-swatch" data-color="#059669" style="background:#059669;" title="Emerald"></div>
                        <div class="color-swatch" data-color="#dc2626" style="background:#dc2626;" title="Red"></div>
                        <div class="color-swatch" data-color="monochrome" style="background:linear-gradient(135deg,#000 50%,#fff 50%);border:1px solid #ccc;" title="Monochrome"></div>
                    </div>
                </div>
                <p style="color:var(--text-muted);font-size:13px;margin-top:18px;">Changes are applied instantly and saved automatically to your browser.</p>
            </div>

            <?php if ($is_admin): ?>
            <!-- Tax & Finance -->
            <div class="settings-card" id="finance">
                <div class="settings-card-header">
                    <div class="settings-card-icon">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div><h3>Tax & Finance</h3><p>Manage global tax rules and currency settings.</p></div>
                </div>

                <!-- ── Currency Selector ───────────────────────────── -->
                <?php
                $curr = get_currency($conn);
                ?>
                <div style="margin-bottom:28px; padding-bottom:24px; border-bottom:1px solid var(--border-color);">
                    <div style="font-size:14px; font-weight:700; color:var(--text-dark); margin-bottom:4px;">Currency</div>
                    <div style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">Select the system-wide currency. Changing it updates all displayed amounts.</div>

                    <div id="currencyGrid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:10px; margin-bottom:16px;">
                        <?php
                        $currencies = [
                            ['code'=>'INR','symbol'=>'₹','name'=>'Indian Rupee','flag'=>'🇮🇳'],
                            ['code'=>'USD','symbol'=>'$','name'=>'US Dollar','flag'=>'🇺🇸'],
                            ['code'=>'JPY','symbol'=>'¥','name'=>'Japanese Yen','flag'=>'🇯🇵'],
                            ['code'=>'EUR','symbol'=>'€','name'=>'Euro','flag'=>'🇪🇺'],
                            ['code'=>'GBP','symbol'=>'£','name'=>'Brit. Pound','flag'=>'🇬🇧'],
                            ['code'=>'AED','symbol'=>'د.إ','name'=>'UAE Dirham','flag'=>'🇦🇪'],
                            ['code'=>'SGD','symbol'=>'S$','name'=>'Singapore $','flag'=>'🇸🇬'],
                            ['code'=>'CAD','symbol'=>'C$','name'=>'Canad. Dollar','flag'=>'🇨🇦'],
                        ];
                        foreach ($currencies as $c):
                            $isActive = ($curr['code'] === $c['code']);
                        ?>
                        <button type="button" class="currency-tile <?= $isActive ? 'active' : '' ?>"
                                data-code="<?= $c['code'] ?>" data-symbol="<?= htmlspecialchars($c['symbol']) ?>">
                            <span style="font-size:22px; display:block; margin-bottom:4px;"><?= $c['flag'] ?></span>
                            <span style="font-size:18px; font-weight:700; color:var(--primary-color);"><?= htmlspecialchars($c['symbol']) ?></span>
                            <span style="font-size:11px; font-weight:600; color:var(--text-dark); display:block;"><?= $c['code'] ?></span>
                            <span style="font-size:10px; color:var(--text-muted);"><?= $c['name'] ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <div id="currencyWarning" style="display:none; background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.4); color:#92400e; border-radius:8px; padding:10px 14px; font-size:13px; margin-bottom:12px;">
                        ⚠️ You've selected a different currency. If your existing prices are in <strong><?= $curr['code'] ?></strong>, enter the conversion rate below so we can update all product and order values automatically (leave at 1 to keep amounts as-is).
                    </div>
                    <div id="currencyRateRow" style="display:none; margin-bottom:12px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:5px;display:block;">Conversion rate from <?= $curr['code'] ?> → <span id="newCurrCode"><?= $curr['code'] ?></span></label>
                        <input type="number" id="currencyRate" step="0.0001" min="0.0001" value="1" class="form-input" style="max-width:200px;" placeholder="e.g. 0.012 for INR→USD">
                    </div>
                    <button id="saveCurrencyBtn" class="btn btn-primary" style="display:none; padding:8px 20px; font-size:13px;">
                        Apply Currency Change
                    </button>
                    <div id="currencySaveMsg" style="display:none; font-size:13px; margin-top:8px;"></div>
                </div>
                <style>
                .currency-tile {
                    display:flex; flex-direction:column; align-items:center; text-align:center;
                    padding:12px 8px; border-radius:10px; border:2px solid var(--border-color);
                    background:var(--card-bg,var(--white)); cursor:pointer; transition:all 0.18s;
                    font-family:inherit;
                }
                .currency-tile:hover { border-color:var(--primary-color); transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.08); }
                .currency-tile.active { border-color:var(--primary-color); background:var(--primary-bg,rgba(109,74,255,.07)); box-shadow:0 0 0 3px var(--primary-bg,rgba(109,74,255,.15)); }
                </style>
                <script>
                (function(){
                    var tiles = document.querySelectorAll('.currency-tile');
                    var warn  = document.getElementById('currencyWarning');
                    var rateRow = document.getElementById('currencyRateRow');
                    var saveBtn = document.getElementById('saveCurrencyBtn');
                    var saveMsg = document.getElementById('currencySaveMsg');
                    var selectedCode   = '<?= $curr['code'] ?>';
                    var selectedSymbol = '<?= addslashes($curr['symbol']) ?>';
                    var currentCode    = '<?= $curr['code'] ?>';

                    tiles.forEach(function(t){
                        t.addEventListener('click', function(){
                            tiles.forEach(function(x){ x.classList.remove('active'); });
                            t.classList.add('active');
                            selectedCode   = t.dataset.code;
                            selectedSymbol = t.dataset.symbol;
                            document.getElementById('newCurrCode').textContent = selectedCode;
                            if (selectedCode !== currentCode) {
                                warn.style.display    = 'block';
                                rateRow.style.display = 'block';
                                saveBtn.style.display = 'inline-block';
                            } else {
                                warn.style.display    = 'none';
                                rateRow.style.display = 'none';
                                saveBtn.style.display = 'none';
                            }
                        });
                    });

                    saveBtn && saveBtn.addEventListener('click', async function(){
                        var rate = parseFloat(document.getElementById('currencyRate').value) || 1;
                        saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
                        var fd = new FormData();
                        fd.set('action','save_currency');
                        fd.set('currency_symbol', selectedSymbol);
                        fd.set('currency_code',   selectedCode);
                        fd.set('conversion_rate', rate);
                        try {
                            var res  = await fetch('ajax_update_settings.php', {method:'POST', body:fd});
                            var json = await res.json();
                            saveMsg.style.display = 'block';
                            if (json.success) {
                                saveMsg.style.color = '#059669';
                                saveMsg.textContent = '✓ Currency updated to '+selectedCode+'. Reload to see changes.';
                                currentCode = selectedCode;
                                warn.style.display = 'none';
                                setTimeout(function(){ location.reload(); }, 1400);
                            } else {
                                saveMsg.style.color = '#dc2626';
                                saveMsg.textContent = '✗ ' + json.message;
                            }
                        } finally { saveBtn.disabled = false; saveBtn.textContent = 'Apply Currency Change'; }
                    });
                })();
                </script>
                <!-- Tax Rules — inline (merged from settings_taxes.php) -->
                <?php if ($tax_success): ?><div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:var(--success-color);padding:10px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;"><?= $tax_success ?></div><?php endif; ?>
                <?php if ($tax_error): ?><div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#ef4444;padding:10px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;"><?= $tax_error ?></div><?php endif; ?>

                <div style="background:var(--primary-bg,rgba(109,74,255,.06));border:1px solid var(--primary-color);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">Combined Active Rate</div>
                        <div style="font-size:26px;font-weight:700;color:var(--primary-color);"><?= number_format($total_rate,2) ?>%</div>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);text-align:right;line-height:1.6;">Applied to all sales<br>after coupon discounts</div>
                </div>

                <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;">
                    <input type="hidden" name="action" value="add_tax">
                    <div style="flex:2;min-width:140px;">
                        <label style="font-size:12px;font-weight:500;margin-bottom:5px;display:block;">Tax Name</label>
                        <input type="text" name="tax_name" placeholder="e.g. GST 18%, VAT" required class="form-input" style="width:100%;">
                    </div>
                    <div style="flex:1;min-width:100px;">
                        <label style="font-size:12px;font-weight:500;margin-bottom:5px;display:block;">Rate (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="rate_percent" placeholder="0.00" required class="form-input" style="width:100%;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="height:38px;padding:0 18px;white-space:nowrap;">Add Rule</button>
                </form>

                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:9px 12px;text-align:left;border-bottom:1px solid var(--border-color);font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Name</th>
                            <th style="padding:9px 12px;text-align:left;border-bottom:1px solid var(--border-color);font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Rate</th>
                            <th style="padding:9px 12px;text-align:left;border-bottom:1px solid var(--border-color);font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Status</th>
                            <th style="padding:9px 12px;text-align:right;border-bottom:1px solid var(--border-color);font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($taxes_res && $taxes_res->num_rows > 0): while ($t = $taxes_res->fetch_assoc()): ?>
                        <tr>
                            <td style="padding:11px 12px;border-bottom:1px solid var(--border-color);font-weight:500;color:var(--text-dark);"><?= htmlspecialchars($t['tax_name']) ?></td>
                            <td style="padding:11px 12px;border-bottom:1px solid var(--border-color);font-size:15px;font-weight:700;color:var(--primary-color);"><?= $t['rate_percent'] ?>%</td>
                            <td style="padding:11px 12px;border-bottom:1px solid var(--border-color);">
                                <?php if ($t['is_active']): ?>
                                    <span style="font-size:11px;background:rgba(16,185,129,.12);color:var(--success-color);border:1px solid rgba(16,185,129,.2);padding:2px 8px;border-radius:20px;font-weight:600;">Active</span>
                                <?php else: ?>
                                    <span style="font-size:11px;background:var(--bg-light);color:var(--text-muted);border:1px solid var(--border-color);padding:2px 8px;border-radius:20px;font-weight:600;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:11px 12px;border-bottom:1px solid var(--border-color);text-align:right;">
                                <a href="settings.php?tax_action=toggle&tax_id=<?= $t['id'] ?>" class="btn-outline" style="padding:4px 10px;font-size:12px;text-decoration:none;margin-right:4px;"><?= $t['is_active'] ? 'Disable' : 'Enable' ?></a>
                                <a href="settings.php?tax_action=delete&tax_id=<?= $t['id'] ?>" class="btn-outline" style="padding:4px 10px;font-size:12px;text-decoration:none;color:var(--danger-color);border-color:var(--danger-color);"
                                    onclick="event.preventDefault();showCustomConfirm('Delete this tax rule?',()=>{window.location.href=this.href||this.getAttribute('href');});">Delete</a>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No tax rules yet. Add one above.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Data & Import -->
            <div class="settings-card" id="data">
                <div class="settings-card-header">
                    <div class="settings-card-icon">
                        <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                    </div>
                    <div><h3>Data & Import</h3><p>Bulk import products, customers, and historical data.</p></div>
                </div>
                <a class="link-card" href="import_data.php">
                    <div class="link-card-left">
                        <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <div>
                            <div class="link-card-title">Data Importer Wizard</div>
                            <div class="link-card-desc">Mass-ingest records using predefined CSV templates</div>
                        </div>
                    </div>
                    <svg class="link-card-arrow" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currentTheme = localStorage.getItem('agile_theme') || 'light';
    const currentColor = localStorage.getItem('agile_color') || '#6d4aff';
    const radio = document.querySelector(`input[name="theme_mode"][value="${currentTheme}"]`);
    if (radio) radio.checked = true;
    document.querySelectorAll('.color-swatch').forEach(sw => {
        if (sw.getAttribute('data-color') === currentColor) sw.classList.add('active');
    });
    document.querySelectorAll('input[name="theme_mode"]').forEach(r => {
        r.addEventListener('change', e => {
            localStorage.setItem('agile_theme', e.target.value);
            document.documentElement.setAttribute('data-theme', e.target.value);
            applyMono();
        });
    });
    document.querySelectorAll('.color-swatch').forEach(sw => {
        sw.addEventListener('click', () => {
            document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
            sw.classList.add('active');
            const c = sw.getAttribute('data-color');
            localStorage.setItem('agile_color', c);
            if (c === 'monochrome') { applyMono(); return; }
            document.documentElement.style.setProperty('--primary-color', c);
            const h = {'#1E5EFF':'#1648c9','#336DFF':'#2452c7','#059669':'#047857','#dc2626':'#b91c1c'};
            document.documentElement.style.setProperty('--primary-hover', h[c] || '#5636db');
        });
    });
    function applyMono() {
        if (localStorage.getItem('agile_color') !== 'monochrome') return;
        const t = localStorage.getItem('agile_theme') || 'light';
        document.documentElement.style.setProperty('--primary-color', t === 'dark' ? '#ffffff' : '#000000');
        document.documentElement.style.setProperty('--primary-hover', t === 'dark' ? '#e5e5e5' : '#333333');
    }

    const bizForm = document.getElementById('bizForm');
    if (bizForm) {
        bizForm.addEventListener('submit', async e => {
            e.preventDefault();
            const data = new FormData(bizForm);
            data.set('action', 'save_business_settings');
            const btn = bizForm.querySelector('button[type="submit"]');
            const msg = document.getElementById('bizSaveMsg');
            btn.disabled = true; btn.textContent = 'Saving…';
            try {
                const res = await fetch('ajax_update_settings.php', { method: 'POST', body: data });
                const json = await res.json();
                msg.style.display = 'inline';
                if (json.success) {
                    msg.style.color = '#059669'; msg.textContent = '✓ Saved successfully';
                } else {
                    msg.style.color = '#dc2626'; msg.textContent = '✗ ' + json.message;
                }
                setTimeout(() => { msg.style.display = 'none'; }, 3500);
            } finally { btn.disabled = false; btn.textContent = 'Save Business Info'; }
        });
    }

    const sections = document.querySelectorAll('.settings-card[id]');
    const navItems = document.querySelectorAll('.settings-nav-item');
    const obs = new IntersectionObserver(entries => {
        entries.forEach(en => {
            if (en.isIntersecting) {
                navItems.forEach(n => n.classList.remove('active'));
                const m = document.querySelector(`.settings-nav-item[href="#${en.target.id}"]`);
                if (m) m.classList.add('active');
            }
        });
    }, { threshold: 0.5 });
    sections.forEach(s => obs.observe(s));
});
</script>
<?php include 'includes/footer.php'; ?>
