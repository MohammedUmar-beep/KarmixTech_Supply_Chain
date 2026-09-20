<?php
require_once 'includes/auth_guard.php';

$current_page = 'help.php';
$page_title   = 'Help & Knowledge Base';

$extra_head = '
<style>
.help-wrap { padding: 0 0 60px 0; }
.help-hero {
    background: linear-gradient(135deg, var(--primary-color) 0%, color-mix(in srgb, var(--primary-color) 70%, #000) 100%);
    border-radius: 16px; padding: 52px 44px;
    margin-bottom: 36px; position: relative; overflow: hidden;
}
.help-hero::before {
    content: ""; position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.05\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.help-hero-inner { position: relative; z-index: 1; }
.help-hero h1 { font-size: 32px; font-weight: 800; color: #fff; margin: 0 0 10px 0; letter-spacing: -0.5px; }
.help-hero p { color: rgba(255,255,255,0.85); font-size: 17px; margin: 0 0 28px 0; }
.help-search { position: relative; max-width: 560px; }
.help-search input {
    width: 100%; padding: 15px 20px 15px 52px; font-size: 15px;
    border: none; border-radius: 10px; background: rgba(255,255,255,0.15);
    color: #fff; outline: none; backdrop-filter: blur(8px);
    transition: background 0.2s; box-sizing: border-box;
}
.help-search input::placeholder { color: rgba(255,255,255,0.6); }
.help-search input:focus { background: rgba(255,255,255,0.25); }
.help-search svg { position: absolute; left: 17px; top: 50%; transform: translateY(-50%); stroke: rgba(255,255,255,0.7); width: 20px; height: 20px; fill: none; }
.search-no-results { display: none; text-align: center; padding: 60px 20px; color: var(--text-muted); font-size: 16px; }
.help-quicknav { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 40px; }
.help-quicknav a { padding: 8px 18px; border-radius: 20px; border: 1.5px solid var(--border-color); font-size: 14px; font-weight: 500; color: var(--text-muted); text-decoration: none; transition: all 0.18s; background: var(--white); }
.help-quicknav a:hover { border-color: var(--primary-color); color: var(--primary-color); background: rgba(109,74,255,0.06); }
.help-section { margin-bottom: 52px; }
.help-section-header { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; padding-bottom: 16px; border-bottom: 2px solid var(--border-color); }
.help-section-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(109,74,255,0.1); display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0; }
.help-section-header h2 { font-size: 20px; font-weight: 700; color: var(--text-dark); margin: 0; }
.help-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; }
.help-cards.two { grid-template-columns: repeat(auto-fill, minmax(440px, 1fr)); }
.help-card { background: var(--white); border: 1px solid var(--border-color); border-radius: 12px; padding: 22px 24px; transition: box-shadow 0.18s, border-color 0.18s, transform 0.18s; }
.help-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.07); border-color: var(--primary-color); transform: translateY(-2px); }
.help-card-title { font-size: 15px; font-weight: 700; color: var(--text-dark); margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.badge { font-size: 11px; font-weight: 600; letter-spacing: 0.4px; padding: 3px 9px; border-radius: 20px; background: rgba(109,74,255,0.1); color: var(--primary-color); text-transform: uppercase; }
.badge.green  { background: rgba(16,185,129,0.1); color: #10b981; }
.badge.orange { background: rgba(245,158,11,0.1); color: #f59e0b; }
.badge.red    { background: rgba(239,68,68,0.1); color: #ef4444; }
.help-card p { font-size: 14px; color: var(--text-muted); line-height: 1.7; margin: 0 0 12px 0; }
.help-card p:last-child { margin-bottom: 0; }
.help-steps { list-style: none; padding: 0; margin: 0; }
.help-steps li { display: flex; gap: 13px; align-items: flex-start; font-size: 14px; color: var(--text-muted); line-height: 1.65; margin-bottom: 11px; }
.help-steps li:last-child { margin-bottom: 0; }
.step-num { width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0; background: var(--primary-color); color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-top: 1px; }
.help-tip { display: flex; gap: 10px; align-items: flex-start; background: rgba(109,74,255,0.06); border-left: 3px solid var(--primary-color); border-radius: 0 8px 8px 0; padding: 12px 16px; margin-top: 14px; font-size: 13.5px; color: var(--text-muted); line-height: 1.65; }
.help-tip svg { flex-shrink: 0; color: var(--primary-color); margin-top: 1px; }
.help-tip.warning { background: rgba(245,158,11,0.07); border-left-color: #f59e0b; }
.help-tip.warning svg { color: #f59e0b; }
.help-tip.success { background: rgba(16,185,129,0.07); border-left-color: #10b981; }
.help-tip.success svg { color: #10b981; }
code { background: var(--bg-light); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 7px; font-size: 13px; font-family: "Courier New", monospace; color: var(--primary-color); }
.help-divider { display: flex; align-items: center; gap: 12px; margin: 12px 0 16px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); }
.help-divider::before, .help-divider::after { content: ""; flex: 1; height: 1px; background: var(--border-color); }
.help-table { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 8px; }
.help-table th { text-align: left; padding: 9px 12px; background: var(--bg-light); color: var(--text-muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-color); }
.help-table td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); color: var(--text-dark); vertical-align: top; line-height: 1.6; }
.help-table tr:last-child td { border-bottom: none; }
.help-table td:first-child { font-weight: 600; white-space: nowrap; }
.faq-item { border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 10px; overflow: hidden; background: var(--white); transition: border-color 0.18s; }
.faq-item.open { border-color: var(--primary-color); }
.faq-q { padding: 18px 22px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; font-size: 15px; font-weight: 600; color: var(--text-dark); user-select: none; gap: 12px; }
.faq-q:hover { color: var(--primary-color); }
.faq-chevron { flex-shrink: 0; width: 18px; height: 18px; stroke: var(--text-muted); transition: transform 0.25s; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.faq-item.open .faq-chevron { transform: rotate(180deg); stroke: var(--primary-color); }
.faq-a { display: none; padding: 0 22px 18px 22px; font-size: 14px; color: var(--text-muted); line-height: 1.75; }
.faq-item.open .faq-a { display: block; }
.help-contact { background: var(--white); border: 1px solid var(--border-color); border-radius: 16px; padding: 44px; text-align: center; margin-top: 20px; }
.help-contact h2 { font-size: 24px; font-weight: 800; color: var(--text-dark); margin: 0 0 10px 0; }
.help-contact p  { color: var(--text-muted); font-size: 15px; margin: 0 0 28px 0; }
.contact-cards { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
.contact-card { background: var(--bg-light); border: 1px solid var(--border-color); border-radius: 12px; padding: 22px 30px; min-width: 180px; text-align: center; transition: border-color 0.18s, transform 0.18s; }
.contact-card:hover { border-color: var(--primary-color); transform: translateY(-2px); }
.contact-card svg { stroke: var(--primary-color); margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto; }
.contact-card strong { display: block; font-size: 15px; color: var(--text-dark); margin-bottom: 5px; }
.contact-card span { font-size: 13px; color: var(--text-muted); }
mark.highlight { background: rgba(109,74,255,0.15); border-radius: 2px; color: inherit; }

/* ── Card focus overlay ─────────────────────────────── */
.help-card { cursor: pointer; }
.help-card-backdrop {
    display: none;
    position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,0); backdrop-filter: blur(0px);
    transition: background 0.3s ease, backdrop-filter 0.3s ease;
}
.help-card-backdrop.active {
    display: block;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(6px);
}
.help-card-focused {
    position: fixed !important;
    z-index: 1001 !important;
    box-shadow: 0 32px 80px rgba(0,0,0,0.28) !important;
    border-color: var(--primary-color) !important;
    cursor: default !important;
    overflow-y: auto;
    max-height: 82vh;
    transition: none;
}
.help-card-focused .help-card-close {
    display: flex !important;
}
.help-card-close {
    display: none;
    position: sticky; top: 0; right: 0;
    float: right; margin: -4px -4px 8px 12px;
    width: 28px; height: 28px;
    border-radius: 50%; background: var(--bg-light);
    border: 1px solid var(--border-color);
    align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0;
    color: var(--text-muted); font-size: 18px; line-height: 1;
    transition: background 0.15s, color 0.15s;
    z-index: 10;
}
.help-card-close:hover { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }

@media (max-width: 640px) {
    .help-hero { padding: 32px 20px; }
    .help-cards, .help-cards.two { grid-template-columns: 1fr; }
    .contact-cards { flex-direction: column; align-items: center; }
}
</style>';

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left"><h1>Help &amp; Knowledge Base</h1></div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="help-wrap">

        <!-- HERO -->
        <div class="help-hero">
            <div class="help-hero-inner">
                <h1>How can we help you?</h1>
                <p>Search our knowledge base or browse sections below to find answers fast.</p>
                <div class="help-search">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="helpSearch" placeholder='Search — e.g. "create sales order", "low stock", "POS"…' autocomplete="off">
                </div>
            </div>
        </div>

        <!-- QUICK NAV PILLS -->
        <div class="help-quicknav">
            <a href="#sec-start">🚀 Getting Started</a>
            <a href="#sec-inventory">📦 Inventory</a>
            <a href="#sec-sales">🛍️ Sales Hub</a>
            <a href="#sec-purchase">🛒 Purchase Hub</a>
            <a href="#sec-invoices">🧾 Invoices &amp; Payments</a>
            <a href="#sec-pos">🖥️ Point of Sale</a>
            <a href="#sec-reports">📊 Reports</a>
            <a href="#sec-settings">⚙️ Settings</a>
            <a href="#sec-people">👥 Employees &amp; Logs</a>
            <a href="#sec-data">📥 Import / Export</a>
            <a href="#sec-faq">❓ FAQ</a>
        </div>

        <div id="helpSearchNoResults" class="search-no-results">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:12px;stroke:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <p>No results found. Try a different search term.</p>
        </div>

        <!-- ====== 1. GETTING STARTED ====== -->
        <div class="help-section" id="sec-start">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
                <h2>Getting Started</h2>
            </div>
            <div class="help-cards two">

                <div class="help-card">
                    <div class="help-card-title">System Overview</div>
                    <p>Agile Inventory is an all-in-one inventory, sales, and purchasing platform. It manages products, warehouses, customers, suppliers, orders, invoices, payments, and reporting from a single interface.</p>
                    <p>The left sidebar is your main navigation, grouped into: <strong>Inventory</strong>, <strong>Sales</strong>, <strong>Purchasing</strong>, <strong>Employees</strong>, <strong>Reports</strong>, and <strong>Settings</strong>.</p>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Click the <strong>+</strong> icon next to any sidebar menu item to instantly open that section's Add modal without navigating away.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">First-Time Setup Checklist <span class="badge green">Start Here</span></div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div><strong>Add a Warehouse</strong> — Inventory → Warehouses. Every product requires a warehouse.</div></li>
                        <li><span class="step-num">2</span><div><strong>Add Categories</strong> — Inventory → Categories. Organise products into logical groups.</div></li>
                        <li><span class="step-num">3</span><div><strong>Add Suppliers</strong> — Purchasing → Suppliers. Enables auto-PO generation on low stock.</div></li>
                        <li><span class="step-num">4</span><div><strong>Add Products</strong> — Inventory → Products. Set SKU, prices, stock level, and warning threshold.</div></li>
                        <li><span class="step-num">5</span><div><strong>Add Customers</strong> — Sales → Customers. Required before creating sales orders.</div></li>
                        <li><span class="step-num">6</span><div><strong>Configure Taxes &amp; Discounts</strong> — Settings → Tax &amp; Discount Rules.</div></li>
                    </ul>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Understanding the Dashboard</div>
                    <table class="help-table">
                        <thead><tr><th>Metric</th><th>What it means</th></tr></thead>
                        <tbody>
                            <tr><td>Total Products</td><td>Count of all product SKUs in the catalog</td></tr>
                            <tr><td>Low Stock Alert</td><td>Products at or below their warning threshold</td></tr>
                            <tr><td>Out of Stock</td><td>Products with zero units remaining</td></tr>
                            <tr><td>New Additions (30d)</td><td>Products added in the last 30 days</td></tr>
                            <tr><td>Total Customers</td><td>All registered customers</td></tr>
                            <tr><td>Active Customers</td><td>Customers with Active status</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Navigation Tips</div>
                    <ul class="help-steps">
                        <li><span class="step-num">→</span><div>Use the <strong>Global Search</strong> bar (top right) to instantly find customers, orders, products, or suppliers.</div></li>
                        <li><span class="step-num">→</span><div>The <strong>bell icon</strong> shows real-time low-stock alerts and auto-generated PO notifications.</div></li>
                        <li><span class="step-num">→</span><div>Each listing page has <strong>Search + Column Filter</strong> — select which field to search against.</div></li>
                        <li><span class="step-num">→</span><div>All tables support <strong>pagination</strong> with Next / Prev links at the bottom.</div></li>
                        <li><span class="step-num">→</span><div>Change the app <strong>theme and colour</strong> anytime in Settings → System Settings.</div></li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ====== 2. INVENTORY ====== -->
        <div class="help-section" id="sec-inventory">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
                <h2>Inventory Management</h2>
            </div>
            <div class="help-cards">

                <div class="help-card">
                    <div class="help-card-title">Adding &amp; Editing Products</div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Inventory → Products</strong> and click <strong>Add Product</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Fill in: Product Name, Category, Warehouse, SKU Code, Barcode, Weight &amp; Dimensions.</div></li>
                        <li><span class="step-num">3</span><div>Set <strong>Purchasing Price</strong> (cost) and <strong>Selling Price</strong>. Margin is auto-calculated.</div></li>
                        <li><span class="step-num">4</span><div>Set <strong>Stock Level</strong> (current units) and <strong>Warning Threshold</strong> (triggers low-stock alerts).</div></li>
                        <li><span class="step-num">5</span><div>Assign a <strong>Supplier</strong> — links the product to auto Purchase Order generation.</div></li>
                    </ul>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        To edit a product, click the <strong>pencil icon</strong> in the Actions column of the Products table.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Stock Overview &amp; Low-Stock Alerts</div>
                    <p>Go to <strong>Inventory → Stock Overview</strong> to see all products with their unit counts, warehouse, and estimated stock value.</p>
                    <p>When a product's stock falls to or below its <strong>Warning Threshold</strong> after a sale, the system automatically:</p>
                    <ul class="help-steps">
                        <li><span class="step-num">①</span><div>Creates a <strong>Draft Purchase Order</strong> for 50 units to the linked supplier.</div></li>
                        <li><span class="step-num">②</span><div>Sends a <strong>notification</strong> (bell icon) with a direct link to the draft PO.</div></li>
                        <li><span class="step-num">③</span><div>Logs the event in <strong>Activity Logs</strong> as a System action.</div></li>
                    </ul>
                    <div class="help-tip success">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Only one Draft PO per supplier per day is created — no duplicates even if multiple products hit low stock simultaneously.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Stock Transfers</div>
                    <p>Transfer stock between warehouses without creating a sale or purchase order.</p>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Inventory → Stock Overview</strong>. Click the <strong>transfer icon</strong> next to a product.</div></li>
                        <li><span class="step-num">2</span><div>Select the Source Warehouse, Destination Warehouse, and quantity.</div></li>
                        <li><span class="step-num">3</span><div>Submit — stock is deducted from the source and added to the destination instantly.</div></li>
                    </ul>
                    <p>All transfers are viewable at <strong>Inventory → Stock Transfers</strong> with timestamps and references.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Categories &amp; Price List</div>
                    <div class="help-divider">Categories</div>
                    <p>Go to <strong>Inventory → Categories</strong> to add, edit, or delete product categories. Categories help filter products across the system.</p>
                    <div class="help-divider">Price List</div>
                    <p>Go to <strong>Inventory → Price List</strong> for a read-only snapshot of every product's purchasing price, selling price, and profit margin %. Use it as a quick pricing reference without editing products.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Warehouses</div>
                    <p>Manage storage locations under <strong>Inventory → Warehouses</strong>.</p>
                    <table class="help-table">
                        <thead><tr><th>Field</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td>Warehouse Name</td><td>Display name (e.g. "Main Store")</td></tr>
                            <tr><td>Address</td><td>Physical location, shown on purchase orders</td></tr>
                            <tr><td>Capacity</td><td>Maximum storage units for planning</td></tr>
                            <tr><td>Status</td><td>Only Active warehouses appear in dropdowns</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <!-- ====== 3. SALES HUB ====== -->
        <div class="help-section" id="sec-sales">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></div>
                <h2>Sales Hub</h2>
            </div>
            <div class="help-cards">

                <div class="help-card">
                    <div class="help-card-title">Creating a Sales Order</div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Sales → Sales Orders</strong> and click <strong>New Order</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Select a <strong>Customer</strong> — their address auto-fills the Shipping Address field.</div></li>
                        <li><span class="step-num">3</span><div>Choose <strong>Fulfillment Type</strong>: <code>Pickup</code> (free) or <code>Delivery</code> (₹100 delivery charge added).</div></li>
                        <li><span class="step-num">4</span><div>Add <strong>line items</strong> — select a product and quantity. Unit price and subtotal auto-calculate.</div></li>
                        <li><span class="step-num">5</span><div>Set <strong>Payment Status</strong>: Paid, Partial, or Credit. Enter amount paid if Paid or Partial.</div></li>
                        <li><span class="step-num">6</span><div>Submit — stock is deducted, an Invoice is auto-generated, and a Credit Note is created for any unpaid balance.</div></li>
                    </ul>
                    <div class="help-tip warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        If a product has insufficient stock, its quantity won't be deducted below zero. Always verify stock levels before large orders.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Order Status Cycle</div>
                    <table class="help-table">
                        <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
                        <tbody>
                            <tr><td>Pending</td><td>Order created, awaiting processing</td></tr>
                            <tr><td>Processing</td><td>Being prepared or packed</td></tr>
                            <tr><td>Completed</td><td>Delivered or picked up successfully</td></tr>
                            <tr><td>Cancelled</td><td>Order voided — stock is NOT auto-restored</td></tr>
                        </tbody>
                    </table>
                    <p style="margin-top:12px;">Use the <strong>cycle icon</strong> in the Actions column to advance the status, or open the order to edit directly.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Sales Returns <span class="badge orange">Returns Tab</span></div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Sales → Returns</strong> and click <strong>Log Return</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Select the Customer and the original <strong>Completed Sales Order</strong>. The max refund amount auto-fills.</div></li>
                        <li><span class="step-num">3</span><div>Enter the actual refund amount and a reason for the return.</div></li>
                        <li><span class="step-num">4</span><div>Once submitted (status: <code>Pending</code>), click the cycle icon to <strong>Process</strong> it.</div></li>
                        <li><span class="step-num">5</span><div>Choose: <strong>Restock to inventory</strong> (adds back all items) or <strong>Mark as Damaged</strong> (no restock).</div></li>
                    </ul>
                    <p>Status cycle: <code>Pending</code> → <code>Processed</code> → <code>Rejected</code></p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Credit Notes &amp; Payments Received</div>
                    <div class="help-divider">Credit Notes</div>
                    <p>A Credit Note is automatically created when a sales order has an unpaid or partially-paid balance. It records how much the customer still owes. Manage them under <strong>Sales → Credit Notes</strong>.</p>
                    <div class="help-divider">Payments Received</div>
                    <p>Any payment recorded on a sales order is automatically logged under <strong>Sales → Payments</strong> with a reference number. You can also manually add payments against an existing invoice from there.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Coupons &amp; Discount Rules <span class="badge">Auto-Applied</span></div>
                    <p>Manage from <strong>Sales → Coupons</strong> and <strong>Settings → Tax &amp; Discount Rules</strong>.</p>
                    <table class="help-table">
                        <thead><tr><th>Type</th><th>How it works</th></tr></thead>
                        <tbody>
                            <tr><td>Percentage</td><td>Deducts X% of the order subtotal</td></tr>
                            <tr><td>Fixed Amount</td><td>Deducts a flat currency amount</td></tr>
                            <tr><td>Min Order Value</td><td>Discount only applies if subtotal meets threshold</td></tr>
                        </tbody>
                    </table>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Discount rules are applied automatically at order creation — the highest-value qualifying rule wins.
                    </div>
                </div>

            </div>
        </div>

        <!-- ====== 4. PURCHASE HUB ====== -->
        <div class="help-section" id="sec-purchase">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></div>
                <h2>Purchase Hub</h2>
            </div>
            <div class="help-cards">

                <div class="help-card">
                    <div class="help-card-title">Creating a Purchase Order</div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Purchasing → Purchase Orders</strong> and click <strong>New PO</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Select a <strong>Supplier</strong> — their address auto-fills into the Supplier Address field.</div></li>
                        <li><span class="step-num">3</span><div>Select the <strong>Warehouse</strong> the goods will be delivered to.</div></li>
                        <li><span class="step-num">4</span><div>Add product line items with quantities and unit prices.</div></li>
                        <li><span class="step-num">5</span><div>Set initial <strong>Status</strong>: Draft, Issued (sent to supplier), or Received (goods arrived).</div></li>
                        <li><span class="step-num">6</span><div>Submit — a PO reference number is auto-generated (e.g. <code>PO-0021</code>).</div></li>
                    </ul>
                </div>

                <div class="help-card">
                    <div class="help-card-title">PO Status Cycle</div>
                    <table class="help-table">
                        <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
                        <tbody>
                            <tr><td>Draft</td><td>Internal draft — supplier not yet notified. Auto-POs start here.</td></tr>
                            <tr><td>Issued</td><td>Sent to supplier, awaiting delivery</td></tr>
                            <tr><td>Received</td><td>Goods confirmed received at warehouse</td></tr>
                            <tr><td>Cancelled</td><td>PO voided before fulfilment</td></tr>
                        </tbody>
                    </table>
                    <div class="help-tip warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Marking a PO as Received does <strong>not</strong> automatically update stock levels. Adjust product stock manually in Inventory → Products after confirming receipt.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Purchase Returns</div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Purchasing → Returns</strong> and click <strong>New Return</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Select the <strong>Supplier</strong> and optionally the original Purchase Order.</div></li>
                        <li><span class="step-num">3</span><div>Enter the return amount and status (Pending or Processed).</div></li>
                    </ul>
                    <div class="help-tip warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Purchase returns do not auto-deduct stock. If goods were returned to the supplier, manually reduce the stock level in Inventory → Products.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Inbound Shipments &amp; Supplier Credits</div>
                    <div class="help-divider">Inbound Shipments</div>
                    <p>Track incoming deliveries separately from POs at <strong>Purchasing → Shipments</strong>. Status flow: <code>Pending</code> → <code>In Transit</code> → <code>Delivered</code>.</p>
                    <div class="help-divider">Supplier Credits</div>
                    <p>Record credit memos issued by suppliers under <strong>Purchasing → Supplier Credits</strong>. These document overpayments or agreed discounts from a supplier.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Managing Suppliers</div>
                    <table class="help-table">
                        <thead><tr><th>Field</th><th>Use</th></tr></thead>
                        <tbody>
                            <tr><td>Supplier Name</td><td>Appears on POs and product assignments</td></tr>
                            <tr><td>Contact Person</td><td>Primary point of contact for orders</td></tr>
                            <tr><td>Address</td><td>Auto-fills when creating new Purchase Orders</td></tr>
                            <tr><td>Status</td><td>Inactive suppliers are hidden from PO dropdowns</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <!-- ====== 5. INVOICES & PAYMENTS ====== -->
        <div class="help-section" id="sec-invoices">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
                <h2>Invoices &amp; Payments</h2>
            </div>
            <div class="help-cards">

                <div class="help-card">
                    <div class="help-card-title">Auto-Generated Invoices</div>
                    <p>Every Sales Order automatically creates an Invoice — you do not need to create them manually. The invoice number (e.g. <code>INV-0042</code>) is linked to the order and tracks payment status.</p>
                    <table class="help-table">
                        <thead><tr><th>Invoice Status</th><th>When set</th></tr></thead>
                        <tbody>
                            <tr><td>Draft</td><td>Order created with Credit payment status</td></tr>
                            <tr><td>Partial</td><td>Order created with Partial payment</td></tr>
                            <tr><td>Paid</td><td>Order fully paid at time of creation</td></tr>
                            <tr><td>Overdue</td><td>Due date passed without full payment</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Recording Payments &amp; Installments</div>
                    <p>To record an additional payment against an existing invoice:</p>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Go to <strong>Sales → Payments</strong> and click <strong>Add Payment</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Select the Customer and Invoice, enter amount, date, and payment method.</div></li>
                        <li><span class="step-num">3</span><div>Submit — invoice status updates automatically (Partial → Paid when fully settled).</div></li>
                    </ul>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Installments (split payments) are tracked individually and summed against the invoice total.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Viewing &amp; Printing Invoices</div>
                    <p>Click the <strong>invoice icon</strong> in any Sales Orders or Invoices row to open a print-ready invoice view.</p>
                    <p>For Sales Returns, the same icon opens a <strong>Refund Invoice</strong> showing the return details and refund amount.</p>
                    <p>Use your browser's Print function (<code>Ctrl+P</code> / <code>Cmd+P</code>) or click <strong>Export PDF</strong> to save a copy.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Payments Made to Suppliers</div>
                    <p>Track outgoing supplier payments under <strong>Purchasing → Payments Made</strong>.</p>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Click <strong>Add Payment</strong>, select the supplier and reference PO.</div></li>
                        <li><span class="step-num">2</span><div>Enter amount, date, and payment method (Bank Transfer, Cash, Cheque, etc.).</div></li>
                        <li><span class="step-num">3</span><div>A <code>BILL-XXXX</code> reference is auto-generated for your records.</div></li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ====== 6. POINT OF SALE ====== -->
        <div class="help-section" id="sec-pos">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></div>
                <h2>Point of Sale (POS)</h2>
            </div>
            <div class="help-cards two">

                <div class="help-card">
                    <div class="help-card-title">Using the POS Terminal <span class="badge green">Walk-In Sales</span></div>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Open <strong>Sales → POS</strong>. The terminal loads all active products.</div></li>
                        <li><span class="step-num">2</span><div>Switch between <strong>Grid View</strong> and <strong>List View</strong> using the toggle buttons (top left of product panel).</div></li>
                        <li><span class="step-num">3</span><div>Click a product to add it to the cart. Click again to increase quantity, or adjust manually in the cart.</div></li>
                        <li><span class="step-num">4</span><div>Apply a <strong>coupon code</strong> in the cart panel if applicable.</div></li>
                        <li><span class="step-num">5</span><div>Select <strong>Payment Method</strong> (Cash, Card, UPI) and click <strong>Charge</strong> to complete.</div></li>
                        <li><span class="step-num">6</span><div>A receipt appears on screen. Stock is deducted automatically.</div></li>
                    </ul>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Hold Orders &amp; Recent Sales</div>
                    <div class="help-divider">Holding an Order</div>
                    <p>Click <strong>Hold</strong> in the cart panel to save the current cart without processing the sale. The cart is queued in a held orders list.</p>
                    <p>Click the <strong>Held Orders</strong> button (appears after at least one order is held) to resume a saved cart at any time.</p>
                    <div class="help-divider">Recent Sales</div>
                    <p>Click the <strong>Recent</strong> button (top right of POS) to view the last completed sales with totals and items — useful for quick reference without leaving the terminal.</p>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        POS sales are recorded as Sales Orders and appear in all reports and inventory counts.
                    </div>
                </div>

            </div>
        </div>

        <!-- ====== 7. REPORTS ====== -->
        <div class="help-section" id="sec-reports">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
                <h2>Reports &amp; Analytics</h2>
            </div>
            <div class="help-cards">

                <div class="help-card">
                    <div class="help-card-title">Main Reports Page</div>
                    <p>Access all reports from the <strong>Reports</strong> link in the sidebar. The overview page shows:</p>
                    <table class="help-table">
                        <thead><tr><th>Widget</th><th>What's shown</th></tr></thead>
                        <tbody>
                            <tr><td>Top Products</td><td>Best-selling products by revenue</td></tr>
                            <tr><td>Customer Performance</td><td>Top customers by total spend</td></tr>
                            <tr><td>Returns &amp; Credit Notes</td><td>Volume and value of returns/credits</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Detailed Report Pages</div>
                    <table class="help-table">
                        <thead><tr><th>Report</th><th>Contents</th></tr></thead>
                        <tbody>
                            <tr><td>Sales Report</td><td>Revenue, order counts, daily/monthly trends</td></tr>
                            <tr><td>Purchase Report</td><td>PO totals, supplier spend breakdown</td></tr>
                            <tr><td>Inventory Report</td><td>Stock levels, valuation, movement history</td></tr>
                            <tr><td>Financial Report</td><td>Revenue vs. costs, profit summary</td></tr>
                            <tr><td>Customer Report</td><td>Customer purchase history and totals</td></tr>
                            <tr><td>Discount Report</td><td>Applied discounts and coupon usage stats</td></tr>
                            <tr><td>Analytics</td><td>Charts and trend graphs across all KPIs</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Exporting Reports</div>
                    <p>Most data tables and all Report pages have an <strong>Export</strong> button in the table header controls.</p>
                    <ul class="help-steps">
                        <li><span class="step-num">CSV</span><div>Opens in Excel or Google Sheets. Best for data analysis and further filtering.</div></li>
                        <li><span class="step-num">PDF</span><div>Print-ready formatted document — ideal for sharing or archiving.</div></li>
                    </ul>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Exports always reflect the current search/filter state — filter first, then export for targeted reports.
                    </div>
                </div>

            </div>
        </div>

        <!-- ====== 8. SETTINGS ====== -->
        <div class="help-section" id="sec-settings">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
                <h2>Settings &amp; Configuration</h2>
            </div>
            <div class="help-cards two">

                <div class="help-card">
                    <div class="help-card-title">System Settings</div>
                    <table class="help-table">
                        <thead><tr><th>Setting</th><th>What it does</th></tr></thead>
                        <tbody>
                            <tr><td>Business Name</td><td>Appears on invoices and the browser tab title</td></tr>
                            <tr><td>Theme (Light/Dark)</td><td>Switches the entire UI between light and dark mode</td></tr>
                            <tr><td>Accent Colour</td><td>Changes the primary colour across buttons, links, and highlights</td></tr>
                            <tr><td>Currency Symbol</td><td>Displayed on all monetary values throughout the system</td></tr>
                        </tbody>
                    </table>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Theme and colour preferences are saved per-browser — they persist across sessions on the same device.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Tax Rules &amp; Discount Rules <span class="badge">Financial Engine</span></div>
                    <p>Go to <strong>Settings → Tax &amp; Discount Rules</strong>.</p>
                    <div class="help-divider">Tax Rules</div>
                    <p>Add named tax rules (e.g. <em>GST 18%</em>, <em>VAT 5%</em>). All active tax rules are <strong>summed</strong> and applied to every sales order's taxable amount automatically.</p>
                    <div class="help-divider">Discount Rules</div>
                    <p>Create automatic order-level discounts triggered when an order subtotal reaches a minimum value. Only the highest-value qualifying rule applies per order.</p>
                </div>

                <div class="help-card">
                    <div class="help-card-title">User Profile</div>
                    <p>Click your <strong>avatar / name</strong> in the top-right bar and select <strong>Profile</strong>. From here you can:</p>
                    <ul class="help-steps">
                        <li><span class="step-num">→</span><div>Update your display name and email address</div></li>
                        <li><span class="step-num">→</span><div>Upload a profile photo</div></li>
                        <li><span class="step-num">→</span><div>Change your password (current password required for confirmation)</div></li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ====== 9. EMPLOYEES & LOGS ====== -->
        <div class="help-section" id="sec-people">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <h2>Employees &amp; System Logs</h2>
            </div>
            <div class="help-cards two">

                <div class="help-card">
                    <div class="help-card-title">Managing Employees</div>
                    <p>Add and track staff members from <strong>Employees</strong> in the sidebar. Each record stores name, role, contact details, and status (Active / Inactive).</p>
                    <p>Inactive employees are hidden from operational dropdowns across the system.</p>
                    <div class="help-tip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Employee records are separate from login accounts. Login access is managed through the authentication system.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Activity Logs &amp; Audit Trail</div>
                    <div class="help-divider">Activity Logs</div>
                    <p>Go to <strong>Activity Logs</strong> in the sidebar for a chronological record of all create, edit, and delete actions — who did what, which record was affected, and when.</p>
                    <div class="help-divider">Audit Logs</div>
                    <p>Go to <strong>Audit Logs</strong> for a complete system-level trail, including automated events like auto-generated POs, low-stock triggers, and notification creation.</p>
                    <div class="help-tip warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Log entries are permanent and cannot be deleted — this is by design for accountability.
                    </div>
                </div>

            </div>
        </div>

        <!-- ====== 10. IMPORT / EXPORT ====== -->
        <div class="help-section" id="sec-data">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg></div>
                <h2>Data Import &amp; Export</h2>
            </div>
            <div class="help-cards two">

                <div class="help-card">
                    <div class="help-card-title">Importing via CSV <span class="badge green">Bulk Upload</span></div>
                    <p>Go to <strong>Settings → Data Import Wizard</strong> to bulk-upload records.</p>
                    <ul class="help-steps">
                        <li><span class="step-num">1</span><div>Select the data type: <strong>Products</strong>, <strong>Customers</strong>, or <strong>Suppliers</strong>.</div></li>
                        <li><span class="step-num">2</span><div>Download the <strong>sample CSV template</strong> to see the required column format.</div></li>
                        <li><span class="step-num">3</span><div>Fill in your data following the template exactly — do not rename or reorder columns.</div></li>
                        <li><span class="step-num">4</span><div>Upload the file and click <strong>Import</strong>. The system validates and inserts records.</div></li>
                    </ul>
                    <div class="help-tip warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Duplicate SKUs or IDs will be skipped with an error. Imports do not overwrite existing records.
                    </div>
                </div>

                <div class="help-card">
                    <div class="help-card-title">Exporting Data</div>
                    <p>Export buttons are available on most listing pages and all Report pages.</p>
                    <table class="help-table">
                        <thead><tr><th>Format</th><th>Best for</th></tr></thead>
                        <tbody>
                            <tr><td>CSV</td><td>Spreadsheet analysis, mail merge, data backup</td></tr>
                            <tr><td>PDF</td><td>Printing, filing, sharing with stakeholders</td></tr>
                        </tbody>
                    </table>
                    <p style="margin-top:12px;">Exports always reflect the <strong>current search/filter state</strong> — filter first, then export for targeted datasets.</p>
                </div>

            </div>
        </div>

        <!-- ====== 11. FAQ ====== -->
        <div class="help-section" id="sec-faq">
            <div class="help-section-header">
                <div class="help-section-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                <h2>Frequently Asked Questions</h2>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">Why was a Purchase Order automatically created without me doing anything?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">The system has a built-in <strong>Auto-PO feature</strong>. When a Sales Order is saved and a product's stock drops to or below its <em>Warning Threshold</em>, the system automatically creates a <strong>Draft Purchase Order</strong> for 50 units from that product's linked supplier. Review and modify it under <strong>Purchasing → Purchase Orders</strong> before issuing it. Only one Draft PO per supplier per day is ever created — no duplicates.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">What happens to stock when I process a Sales Return?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">When you process a Sales Return and choose <strong>"Restock to original inventory"</strong>, the system adds back the quantity of every item from the linked original sales order. If you choose <strong>"Mark as Damaged / Missing"</strong>, no stock is restored. This choice cannot be undone automatically — choose carefully.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">What is a Credit Note and when is it created?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">A <strong>Credit Note</strong> records money owed to a customer — essentially an IOU from your business. It is created automatically when a Sales Order is saved with a <em>Partial</em> or <em>Credit</em> payment status, representing the unpaid balance. View and manage all credit notes under <strong>Sales → Credit Notes</strong>.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">Can I cancel a Sales Order and have stock restored automatically?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">No — cancelling an order sets its status to <strong>Cancelled</strong> but does <strong>not</strong> auto-restore stock. To restore stock, either: (a) use the Sales Return workflow with the "Restock" option, or (b) manually adjust the stock level in Inventory → Products → Edit Product.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">How do taxes get applied to sales orders?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">All active tax rules under <strong>Settings → Tax &amp; Discount Rules</strong> are summed (e.g. GST 9% + SGST 9% = 18%) and applied to the taxable amount (subtotal minus any discounts) at the time of order creation. Tax is included in the order total and shown on the invoice. Tax rules are not applied retroactively to existing orders.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">Why doesn't receiving a Purchase Order automatically update my stock?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">Stock updates on PO receipt are intentionally manual to allow for quality checks and partial deliveries. After marking a PO as <strong>Received</strong>, go to <strong>Inventory → Products</strong>, edit the relevant products, and update the stock level to match what was actually received — which may differ from what was ordered.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">How do I change the currency symbol throughout the system?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">Go to <strong>Settings → System Settings</strong> and update the <strong>Currency Symbol</strong> field. Save — the symbol updates across all monetary displays immediately. Note: this changes the display symbol only and does not convert any stored numeric values.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">I deleted a record by mistake. Can I recover it?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">Most deletions are <strong>permanent</strong> through the interface. However, check the <strong>Activity Logs</strong> and <strong>Audit Logs</strong> to find the exact details of what was deleted (name, ID, timestamp). If you have database access, the record may be recoverable from a recent database backup. We strongly recommend scheduling regular database backups.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">What is the difference between Activity Logs and Audit Logs?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a"><strong>Activity Logs</strong> record user-initiated actions: creating, editing, or deleting records — showing who did what and when. <strong>Audit Logs</strong> capture both user and <em>system</em> actions, including automated events like auto-generated POs, low-stock triggers, and notification creation. Use Audit Logs for the most complete picture of everything that has happened in the system.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">Can multiple discount rules apply to a single order?<svg class="faq-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="faq-a">No. Only <strong>one discount rule</strong> applies per order — the one with the highest discount value among all qualifying rules (those whose minimum order value threshold is met). This prevents discount stacking. Coupon codes entered manually at POS operate separately from automatic discount rules.</div>
            </div>

        </div>

        <!-- CONTACT FOOTER -->
        <div class="help-contact">
            <h2>Still Need Help?</h2>
            <p>Can't find what you're looking for? Reach out through any of the channels below.</p>
            <div class="contact-cards">
                <div class="contact-card">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <strong>Email Support</strong>
                    <span>support@agileinventory.com</span>
                </div>
                <div class="contact-card">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <strong>Live Chat</strong>
                    <span>Available 9am – 6pm IST</span>
                </div>
                <div class="contact-card">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.65 3.18 2 2 0 0 1 3.62 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <strong>Phone</strong>
                    <span>+91 98765 43210</span>
                </div>
                <div class="contact-card">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <strong>Response Time</strong>
                    <span>Within 24 business hours</span>
                </div>
            </div>
        </div>

    </div><!-- /help-wrap -->

    <!-- Card focus backdrop -->
    <div class="help-card-backdrop" id="cardBackdrop"></div>

</main>

<script>
function toggleFaq(btn) {
    var item = btn.parentElement;
    var isOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item.open').forEach(function(el) { el.classList.remove('open'); });
    if (!isOpen) item.classList.add('open');
}

document.getElementById('helpSearch').addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    var cards = document.querySelectorAll('.help-card, .faq-item');
    var anyVisible = false;

    removeHighlights();

    if (!q) {
        cards.forEach(function(c) { c.style.display = ''; });
        document.querySelectorAll('.help-section').forEach(function(s) { s.style.display = ''; });
        document.getElementById('helpSearchNoResults').style.display = 'none';
        return;
    }

    cards.forEach(function(card) {
        var text = card.innerText.toLowerCase();
        if (text.indexOf(q) !== -1) {
            card.style.display = '';
            anyVisible = true;
            highlightText(card, q);
        } else {
            card.style.display = 'none';
        }
    });

    document.querySelectorAll('.help-section').forEach(function(sec) {
        var vis = sec.querySelectorAll('.help-card:not([style*="display: none"]), .faq-item:not([style*="display: none"])');
        sec.style.display = vis.length > 0 ? '' : 'none';
    });

    document.getElementById('helpSearchNoResults').style.display = anyVisible ? 'none' : 'block';
});

function highlightText(el, q) {
    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
    var nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(node) {
        if (!node.parentElement || node.parentElement.nodeName === 'SCRIPT' || node.parentElement.nodeName === 'STYLE') return;
        var idx = node.nodeValue.toLowerCase().indexOf(q);
        if (idx !== -1) {
            try {
                var mark = document.createElement('mark');
                mark.className = 'highlight';
                var range = document.createRange();
                range.setStart(node, idx);
                range.setEnd(node, idx + q.length);
                range.surroundContents(mark);
            } catch(e) {}
        }
    });
}

function removeHighlights() {
    document.querySelectorAll('mark.highlight').forEach(function(m) {
        var p = m.parentNode;
        if (p) { p.replaceChild(document.createTextNode(m.textContent), m); p.normalize(); }
    });
}

// ── Help-card zoom / focus ────────────────────────────────────────────
(function () {
    var backdrop   = document.getElementById('cardBackdrop');
    var focused    = null;
    var origStyles = {};

    // Inject close button into every help-card
    document.querySelectorAll('.help-card').forEach(function (card) {
        var btn = document.createElement('button');
        btn.className   = 'help-card-close';
        btn.title       = 'Close';
        btn.textContent = '×';
        btn.addEventListener('click', function (e) { e.stopPropagation(); closeCard(); });
        card.insertBefore(btn, card.firstChild);

        card.addEventListener('click', function (e) {
            if (focused === card) return;  // already focused
            if (focused) closeCard();
            openCard(card);
        });
    });

    backdrop.addEventListener('click', closeCard);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && focused) closeCard();
    });

    function openCard(card) {
        var rect = card.getBoundingClientRect();
        var vw   = window.innerWidth;
        var vh   = window.innerHeight;

        // Save original inline styles
        origStyles = { position: card.style.position, top: card.style.top, left: card.style.left,
                       width: card.style.width, transform: card.style.transform, transition: card.style.transition };

        // Animate from current position to centred
        card.style.position   = 'fixed';
        card.style.top        = rect.top + 'px';
        card.style.left       = rect.left + 'px';
        card.style.width      = rect.width + 'px';
        card.style.transform  = 'scale(1)';
        card.style.transition = 'top 0.32s cubic-bezier(.4,0,.2,1), left 0.32s cubic-bezier(.4,0,.2,1), width 0.32s cubic-bezier(.4,0,.2,1), transform 0.32s cubic-bezier(.4,0,.2,1), box-shadow 0.32s ease';

        // Force reflow then animate to centre
        card.getBoundingClientRect();

        var targetW = Math.min(660, vw * 0.92);
        var targetH; // let CSS max-height handle it
        var targetL = (vw - targetW) / 2;
        var targetT = Math.max(40, (vh - card.scrollHeight) / 2);

        requestAnimationFrame(function () {
            card.style.top   = targetT + 'px';
            card.style.left  = targetL + 'px';
            card.style.width = targetW + 'px';
            card.classList.add('help-card-focused');
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        focused = card;
    }

    function closeCard() {
        if (!focused) return;
        var card = focused;
        focused  = null;

        backdrop.classList.remove('active');
        document.body.style.overflow = '';

        card.style.transition = 'top 0.28s cubic-bezier(.4,0,.2,1), left 0.28s cubic-bezier(.4,0,.2,1), width 0.28s cubic-bezier(.4,0,.2,1), box-shadow 0.28s ease, opacity 0.22s ease';
        card.style.opacity = '0';

        setTimeout(function () {
            card.classList.remove('help-card-focused');
            // Restore original (non-fixed) positioning
            card.style.position  = origStyles.position  || '';
            card.style.top       = origStyles.top       || '';
            card.style.left      = origStyles.left      || '';
            card.style.width     = origStyles.width     || '';
            card.style.transform = origStyles.transform || '';
            card.style.transition= origStyles.transition|| '';
            card.style.opacity   = '';
        }, 280);
    }
})();


    a.addEventListener('click', function(e) {
        var target = document.querySelector(this.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
