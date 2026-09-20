<?php
require_once 'includes/auth_guard.php';
require_once 'includes/loyalty_helper.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'staff';

// ────────────────────────────────────────────────────────────────────────────
// Read JSON body once for all POST handlers
// JS sends Content-Type: application/json, so $_POST is empty — we must read
// php://input directly and derive the action from the decoded body.
// ────────────────────────────────────────────────────────────────────────────
$post_data   = null;
$post_action = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_body  = file_get_contents('php://input');
    $post_data = json_decode($raw_body, true);
    $post_action = $post_data['action'] ?? ($_POST['action'] ?? null);
}

// ────────────────────────────────────────────────────────────────────────────
// AJAX: CHECKOUT
// ────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'checkout') {
    header('Content-Type: application/json');
    $data = $post_data;
    if (empty($data['cart'])) { echo json_encode(['success'=>false,'message'=>'Cart is empty.']); exit(); }

    $customer_id     = intval($data['customer_id'] ?? 0);
    $payment_method  = $conn->real_escape_string($data['payment_method'] ?? 'Cash');
    $discount_code   = strtoupper(trim($conn->real_escape_string($data['discount_code'] ?? '')));
    $amount_tendered = floatval($data['amount_tendered'] ?? 0);
    $note            = $conn->real_escape_string($data['note'] ?? '');
    $split_payments  = $data['split_payments'] ?? [];
    $points_to_redeem = max(0, intval($data['points_to_redeem'] ?? 0));

    $conn->begin_transaction();
    try {
        // 1. Subtotal
        $raw_subtotal = 0;
        foreach ($data['cart'] as $item) {
            $raw_subtotal += floatval($item['price']) * intval($item['qty']);
        }

        // 2. Coupon discount
        $discount_amount = 0; $applied_code = null;
        if (!empty($discount_code)) {
            $c_res = $conn->query("SELECT * FROM coupons WHERE code='$discount_code' AND status='Active' LIMIT 1");
            if ($c_res && $c_res->num_rows > 0) {
                $cp    = $c_res->fetch_assoc();
                $today = date('Y-m-d');

                // Minimum order value
                $min_order = floatval($cp['min_order_value'] ?? 0);
                if ($min_order > 0 && $raw_subtotal < $min_order) {
                    $conn->rollback();
                    echo json_encode(['success'=>false, 'message'=>'Minimum order ' . $currency['symbol'] . number_format($min_order,2) . ' required for this coupon.']);
                    exit();
                }

                // Date + usage re-validation
                if ((!empty($cp['end_date'])   && $today > $cp['end_date'])   ||
                    (!empty($cp['start_date'])  && $today < $cp['start_date']) ||
                    (!empty($cp['usage_limit']) && intval($cp['times_used']) >= intval($cp['usage_limit']))) {
                    $conn->rollback();
                    echo json_encode(['success'=>false, 'message'=>'Coupon is no longer valid.']);
                    exit();
                }

                $ctype = strtolower(trim($cp['type']));
                if (str_contains($ctype, 'percentage')) {
                    $discount_amount = $raw_subtotal * ($cp['value'] / 100);
                } elseif (str_contains($ctype, 'shipping') || str_contains($ctype, 'free')) {
                    $discount_amount = 0; // shipping waiver — no subtotal reduction
                } else {
                    $discount_amount = floatval($cp['value']);
                }
                if ($discount_amount > $raw_subtotal) $discount_amount = $raw_subtotal;
                $applied_code = $cp['code'];

                // Increment usage counter
                $conn->query("UPDATE coupons SET times_used = times_used + 1 WHERE code = '$discount_code'");
            } else {
                $conn->rollback();
                echo json_encode(['success'=>false, 'message'=>'Invalid or expired coupon code.']);
                exit();
            }
        }

        // 3. Tax
        $taxable_amount = $raw_subtotal - $discount_amount;
        $tax_amount     = 0;
        $total_tax_rate = 0;
        $t_res = $conn->query("SELECT SUM(rate_percent) as r FROM tax_rules WHERE is_active=1");
        if ($t_res) { $total_tax_rate = floatval($t_res->fetch_assoc()['r']); $tax_amount = $taxable_amount * ($total_tax_rate / 100); }
        $grand_total = $taxable_amount + $tax_amount;

        // 3b. Loyalty redemption — applied after tax on the final total
        $loyalty_discount = 0;
        $points_redeemed  = 0;
        if ($customer_id > 0 && $points_to_redeem > 0) {
            $loyalty_discount = redeem_loyalty_points($conn, $customer_id, $points_to_redeem, $grand_total, 'POSSale', 0);
            $cfg_r = $conn->query("SELECT redemption_rate FROM loyalty_config WHERE id=1")->fetch_row();
            $rate  = $cfg_r ? floatval($cfg_r[0]) : 0.05;
            $points_redeemed = $rate > 0 ? (int)round($loyalty_discount / $rate) : 0;
            $grand_total = max(0, $grand_total - $loyalty_discount);
        }

        // 4. Sales order — store tax_rate + tax_amount so the invoice always
        // shows the tax that was actually charged, even if rates change later.
        $order_date     = date('Y-m-d');
        $so_placeholder = 'POS-TMP-' . uniqid();
        if ($customer_id > 0) {
            $stmt = $conn->prepare("INSERT INTO sales_orders (order_id_str,customer_id,order_date,total_amount,fulfillment_type,status,tax_rate,tax_amount,loyalty_discount,points_redeemed) VALUES (?,?,?,?,'Take Away','Completed',?,?,?,?)");
            $stmt->bind_param("sisddddi", $so_placeholder, $customer_id, $order_date, $grand_total, $total_tax_rate, $tax_amount, $loyalty_discount, $points_redeemed);
        } else {
            $stmt = $conn->prepare("INSERT INTO sales_orders (order_id_str,order_date,total_amount,fulfillment_type,status,tax_rate,tax_amount) VALUES (?,?,?,'Take Away','Completed',?,?)");
            $stmt->bind_param("ssddd", $so_placeholder, $order_date, $grand_total, $total_tax_rate, $tax_amount);
        }
        $stmt->execute();
        $sales_order_id = $conn->insert_id;
        $order_id_str   = 'POS-' . date('ym') . '-' . str_pad($sales_order_id, 4, '0', STR_PAD_LEFT);
        $conn->query("UPDATE sales_orders SET order_id_str='$order_id_str' WHERE id=$sales_order_id");
        $stmt->close();

        // 5. Line items + stock deduction
        $item_stmt = $conn->prepare("INSERT INTO sales_order_items (sales_order_id,product_id,quantity,unit_price,subtotal,bundle_sale_id) VALUES (?,?,?,?,?,?)");
        $bndl_stmt = $conn->prepare("INSERT INTO bundle_sales (sales_order_id,bundle_id,quantity,bundle_price_at_sale,subtotal,cost_at_sale,profit_at_sale) VALUES (?,?,?,?,?,?,?)");
        foreach ($data['cart'] as $item) {
            if (!empty($item['_is_bundle'])) {
                // Bundle — record in bundle_sales then expand to component items
                $bid   = intval($item['id']);
                $bqty  = intval($item['qty']);
                $bprice = floatval($item['price']);
                $bsub  = $bprice * $bqty;

                $b_row = $conn->query("SELECT total_cost FROM bundles WHERE id=$bid")->fetch_assoc();
                $bcost = $b_row ? floatval($b_row['total_cost']) * $bqty : 0;
                $bprofit = $bsub - $bcost;

                $bndl_stmt->bind_param("iiidddd", $sales_order_id, $bid, $bqty, $bprice, $bsub, $bcost, $bprofit);
                $bndl_stmt->execute();
                $bundle_sale_id = $conn->insert_id;

                // Expand components — insert sales_order_items + deduct stock per component
                $comps = $conn->query("SELECT product_id, quantity, unit_cost_snapshot, unit_sell_snapshot FROM bundle_items WHERE bundle_id=$bid");
                while ($comp = $comps->fetch_assoc()) {
                    $cpid   = intval($comp['product_id']);
                    $cqty   = intval($comp['quantity']) * $bqty;
                    $cprice = floatval($comp['unit_sell_snapshot']);
                    $csub   = $cprice * $cqty;
                    $item_stmt->bind_param("iiiddi", $sales_order_id, $cpid, $cqty, $cprice, $csub, $bundle_sale_id);
                    $item_stmt->execute();
                    $conn->query("UPDATE products SET stock_level=stock_level-$cqty WHERE id=$cpid AND stock_level>=$cqty");
                }
            } else {
                $pid=$item['id']; $qty=intval($item['qty']); $price=floatval($item['price']); $sub=$price*$qty;
                $null_bsid = null;
                $item_stmt->bind_param("iiiddi", $sales_order_id, $pid, $qty, $price, $sub, $null_bsid);
                $item_stmt->execute();
                $conn->query("UPDATE products SET stock_level=stock_level-$qty WHERE id=$pid AND stock_level>=$qty");
            }
        }
        $item_stmt->close();
        $bndl_stmt->close();

        // 6. Payment record — same walk-in branch logic for payments_received
        $pay_placeholder = 'POSPAY-TMP-' . uniqid();
        $pay_notes       = "POS Checkout - $order_id_str" . ($note ? " | Note: $note" : "");
        if ($customer_id > 0) {
            $pr_stmt = $conn->prepare("INSERT INTO payments_received (payment_ref_str,customer_id,payment_date,amount,payment_method,notes) VALUES (?,?,?,?,?,?)");
            $pr_stmt->bind_param("sisdss", $pay_placeholder, $customer_id, $order_date, $grand_total, $payment_method, $pay_notes);
        } else {
            $pr_stmt = $conn->prepare("INSERT INTO payments_received (payment_ref_str,payment_date,amount,payment_method,notes) VALUES (?,?,?,?,?)");
            $pr_stmt->bind_param("ssdss", $pay_placeholder, $order_date, $grand_total, $payment_method, $pay_notes);
        }
        $pr_stmt->execute();
        $new_pr_id       = $conn->insert_id;
        $payment_ref_str = 'POSPAY-' . str_pad($new_pr_id, 4, '0', STR_PAD_LEFT);
        $conn->query("UPDATE payments_received SET payment_ref_str='$payment_ref_str' WHERE id=$new_pr_id");
        $pr_stmt->close();

        log_activity($conn, 'Create', 'POS Sale', $order_id_str, "POS sale \$$grand_total via $payment_method");
        $conn->commit();

        // Award loyalty points to customer after successful commit
        $pts_earned = 0;
        if ($customer_id > 0) {
            $pts_earned = award_loyalty_points($conn, $customer_id, $grand_total, 'POSSale', $sales_order_id);
        }

        $change = max(0, $amount_tendered - $grand_total);
        echo json_encode([
            'success'        => true,
            'order_id'       => $sales_order_id,
            'ref'            => $order_id_str,
            'subtotal'       => $raw_subtotal,
            'discount'       => $discount_amount,
            'loyalty_discount' => $loyalty_discount,
            'points_redeemed'  => $points_redeemed,
            'pts_earned'     => $pts_earned,
            'tax'            => $tax_amount,
            'total'          => $grand_total,
            'tendered'       => $amount_tendered,
            'change'         => $change,
            'method'         => $payment_method,
            'date'           => date('d M Y, g:i A'),
            'cashier'        => $user_name,
        ]);
        exit();
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'Transaction failed: '.$e->getMessage()]);
        exit();
    }
}

// ────────────────────────────────────────────────────────────────────────────
// AJAX: PRODUCT SEARCH
// ────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'search') {
    header('Content-Type: application/json');
    $q   = $conn->real_escape_string(trim($_GET['q'] ?? ''));
    $cat = $conn->real_escape_string(trim($_GET['cat'] ?? ''));
    $where = "status != 'Deleted'";
    if ($q)   $where .= " AND (product_name LIKE '%$q%' OR sku_code LIKE '%$q%' OR barcode_number LIKE '%$q%')";
    if ($cat && $cat !== 'All') $where .= " AND category = '$cat'";
    $res  = $conn->query("SELECT id,product_name,sku_code,barcode_number,selling_price,discounted_price,discount_type,discount_value,stock_level,category,description FROM products WHERE $where ORDER BY product_name LIMIT 500");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// AJAX: BUNDLE SEARCH
// ────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'search_bundles') {
    header('Content-Type: application/json');
    $q     = $conn->real_escape_string(trim($_GET['q'] ?? ''));
    $today = date('Y-m-d');
    $where = "b.status = 'Active' AND (b.valid_until IS NULL OR b.valid_until >= '$today')";
    if ($q) $where .= " AND (b.bundle_name LIKE '%$q%' OR b.sku_code LIKE '%$q%')";

    $res = $conn->query("
        SELECT b.id, b.bundle_name, b.sku_code, b.bundle_price, b.normal_sell_value,
               b.description, b.total_cost,
               MIN(bi_stock.min_stock) AS stock_level
        FROM bundles b
        LEFT JOIN (
            SELECT bi.bundle_id,
                   MIN(FLOOR(p.stock_level / bi.quantity)) AS min_stock
            FROM bundle_items bi
            JOIN products p ON bi.product_id = p.id
            GROUP BY bi.bundle_id
        ) bi_stock ON bi_stock.bundle_id = b.id
        WHERE $where
        GROUP BY b.id
        ORDER BY b.bundle_name
        LIMIT 200
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['_is_bundle']    = true;
        $r['stock_level']   = $r['stock_level'] ?? 0;
        $r['selling_price'] = $r['bundle_price'];
        $r['discounted_price'] = null;
        $r['category']      = 'Bundles';
        $rows[] = $r;
    }
    echo json_encode($rows);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'validate_discount') {
    header('Content-Type: application/json');
    $data     = $post_data;
    $code     = strtoupper(trim($conn->real_escape_string($data['code'] ?? '')));
    $subtotal = floatval($data['subtotal'] ?? 0);
    $today    = date('Y-m-d');

    // ── Helper: compute discount amount from a coupon row ──────────────────
    function pos_coupon_amount(array $cp, float $subtotal): array {
        $type  = strtolower(trim($cp['type']));
        $value = floatval($cp['value']);

        if (str_contains($type, 'percentage')) {
            $amt   = $subtotal * ($value / 100);
            $label = $value . '% off';
        } elseif (str_contains($type, 'shipping') || str_contains($type, 'free')) {
            $amt   = 0;
            $label = 'Free shipping';
        } else {
            // Fixed / Price Discount
            $amt   = $value;
            $label = ''+\$currency['symbol']+'' . number_format($value, 2) . ' off';
        }
        return ['amount' => min($amt, $subtotal), 'label' => $label];
    }

    // ── Look up coupons table only ─────────────────────────────────────────
    $c_res = $conn->query("SELECT * FROM coupons WHERE code='$code' AND status='Active' LIMIT 1");
    if ($c_res && $c_res->num_rows > 0) {
        $cp = $c_res->fetch_assoc();

        // Minimum order value check
        $min_order = floatval($cp['min_order_value'] ?? 0);
        if ($min_order > 0 && $subtotal < $min_order) {
            echo json_encode(['valid'=>false, 'message'=>'Minimum order '+\$currency['symbol']+''.number_format($min_order,2).' required for this coupon.']);
            exit();
        }

        // Date range check
        if (!empty($cp['start_date']) && $today < $cp['start_date']) {
            echo json_encode(['valid'=>false, 'message'=>'Coupon not active yet (starts '.$cp['start_date'].').']);
            exit();
        }
        if (!empty($cp['end_date']) && $today > $cp['end_date']) {
            echo json_encode(['valid'=>false, 'message'=>'Coupon expired on '.$cp['end_date'].'.']);
            exit();
        }

        // Usage limit check
        if (!empty($cp['usage_limit']) && intval($cp['times_used']) >= intval($cp['usage_limit'])) {
            echo json_encode(['valid'=>false, 'message'=>'Coupon usage limit reached.']);
            exit();
        }

        $result = pos_coupon_amount($cp, $subtotal);
        echo json_encode(['valid'=>true, 'code'=>$code, 'amount'=>$result['amount'], 'label'=>$result['label']]);
        exit();
    }

    echo json_encode(['valid'=>false, 'message'=>'Invalid or expired coupon code.']);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// AJAX: LOYALTY INFO
// ────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'loyalty') {
    header('Content-Type: application/json');
    $cid = intval($_GET['customer_id'] ?? 0);
    if ($cid <= 0) { echo json_encode(['active'=>false]); exit(); }

    $cfg_res = $conn->query("SELECT * FROM loyalty_config WHERE id=1 AND is_active=1");
    if (!$cfg_res || $cfg_res->num_rows === 0) { echo json_encode(['active'=>false]); exit(); }
    $cfg = $cfg_res->fetch_assoc();

    $bal_res = $conn->query("SELECT loyalty_points_balance FROM customers WHERE id=$cid");
    $balance = $bal_res ? intval($bal_res->fetch_row()[0]) : 0;

    echo json_encode([
        'active'        => true,
        'balance'       => $balance,
        'min_pts'       => intval($cfg['min_points_redeem']),
        'redeem_rate'   => floatval($cfg['redemption_rate']),
        'max_pct'       => floatval($cfg['max_redemption_pct']),
    ]);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// AJAX: RECENT SALES
// ────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'recent') {
    header('Content-Type: application/json');
    $res = $conn->query("SELECT so.id,so.order_id_str,so.total_amount,so.created_at,COALESCE(c.customer_name,'Walk-in') as customer_name FROM sales_orders so LEFT JOIN customers c ON so.customer_id=c.id WHERE so.fulfillment_type='Take Away' ORDER BY so.created_at DESC LIMIT 8");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// PAGE DATA
// ────────────────────────────────────────────────────────────────────────────
$cats_res = $conn->query("SELECT DISTINCT category FROM products WHERE status!='Deleted' AND category!='' ORDER BY category");
$cats     = ['All', 'Bundles'];
while ($r = $cats_res->fetch_row()) $cats[] = $r[0];

$cust_res  = $conn->query("SELECT id,customer_name,contact_number FROM customers WHERE status='Active' ORDER BY customer_name");
$customers = [];
while ($r = $cust_res->fetch_assoc()) $customers[] = $r;

$conn->query("CREATE TABLE IF NOT EXISTS tax_rules (id INT AUTO_INCREMENT PRIMARY KEY,tax_name VARCHAR(50) NOT NULL,rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$tax_row  = $conn->query("SELECT SUM(rate_percent) FROM tax_rules WHERE is_active=1");
$tax_rate = $tax_row ? floatval($tax_row->fetch_row()[0]) : 0;

$page_title   = 'Point of Sale';
$current_page = 'pos.php';
$extra_head   = '<style>
/* ── POS layout overrides ── */
.sidebar { display: none !important; }
.main-area { margin-left: 0 !important; padding: 0 !important; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
body, html { overflow: hidden; }

/* ── POS product cards ── */
.pos-card {
    background: var(--card-bg);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 13px 12px;
    cursor: pointer;
    transition: border-color .15s, transform .12s, box-shadow .15s;
    position: relative;
    user-select: none;
}
.pos-card:hover { border-color: var(--primary-color); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.1); }
.pos-card.in-cart { border-color: var(--primary-color); background: var(--primary-bg, rgba(109,74,255,.06)); }
.pos-card.out-of-stock { opacity: .45; cursor: not-allowed; }
.pos-card.out-of-stock:hover { transform: none; box-shadow: none; border-color: var(--border-color); }

/* ── Cart row hover ── */
.cart-row:hover { background: var(--bg-hover); }

/* ── Pill scrollbar hide ── */
.pill-bar::-webkit-scrollbar { display: none; }

/* ── Numpad ── */
.numpad-key {
    aspect-ratio: 1;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    background: var(--card-bg);
    color: var(--text-dark);
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    transition: all .1s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.numpad-key:hover { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
.numpad-key.wide { grid-column: span 2; aspect-ratio: auto; padding: 14px; }
.numpad-key.action { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
.numpad-key.danger { background: #ef4444; color: #fff; border-color: #ef4444; }

/* ── Hold badge ── */
.hold-badge { background: var(--primary-color); color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }

/* ── Scrollbars ── */
#productsContainer::-webkit-scrollbar, #cartItems::-webkit-scrollbar, #recentList::-webkit-scrollbar { width: 3px; }
#productsContainer::-webkit-scrollbar-thumb, #cartItems::-webkit-scrollbar-thumb, #recentList::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }

@keyframes spin { to { transform: rotate(360deg); } }
@keyframes slideUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
@keyframes popIn  { from { opacity:0; transform:scale(.9); } to { opacity:1; transform:scale(1); } }
.slide-up { animation: slideUp .2s ease-out; }
.pop-in   { animation: popIn  .2s cubic-bezier(.34,1.56,.64,1); }
</style>';

include 'includes/header.php';
?>

<main class="main-area">

<!-- ═══════════════════════════════════════════════════════════════════════════
     TOP BAR
════════════════════════════════════════════════════════════════════════════ -->
<div class="topbar" style="border-bottom:1px solid var(--border-color);padding:0 20px;height:60px;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;background:var(--card-bg);">
    <div style="display:flex;align-items:center;gap:18px;">

        <!-- Logo — doubles as home button to stock.php -->
        <a href="stock.php" title="Back to Inventory" style="display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0;padding:6px 10px 6px 0;border-right:1px solid var(--border-color);margin-right:2px;transition:opacity .15s;" onmouseover="this.style.opacity='.7'" onmouseout="this.style.opacity='1'">
            <img src="assets/images/logo.png" alt="Agile Logo" style="width:36px;height:36px;object-fit:contain;flex-shrink:0;">
            <div style="display:flex;flex-direction:column;line-height:1.15;">
                <span style="font-size:13px;font-weight:700;color:var(--primary-color);letter-spacing:-0.2px;">Agile Inventory</span>
                <span style="font-size:8px;font-weight:500;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);">System</span>
            </div>
        </a>

        <h1 style="font-size:18px;font-weight:700;margin:0;color:var(--text-dark);">Point of Sale</h1>
        <span style="background:var(--primary-color);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:.6px;">LIVE</span>
        <span id="posTime" style="font-size:13px;color:var(--text-muted);font-weight:500;"></span>
    </div>
    <?php include 'includes/topbar_right.php'; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     WORKSPACE
════════════════════════════════════════════════════════════════════════════ -->
<div style="display:flex;flex:1;overflow:hidden;">

<!-- ╔══════════════════════════════════════════════════════════════
     ║  LEFT — Products panel
     ╚══════════════════════════════════════════════════════════════ -->
<div style="flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;">

    <!-- Toolbar -->
    <div style="padding:12px 16px 10px;background:var(--card-bg);border-bottom:1px solid var(--border-color);flex-shrink:0;">
        <div style="display:flex;gap:8px;align-items:center;">

            <!-- Search -->
            <div style="flex:1;position:relative;">
                <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;" viewBox="0 0 24 24" width="15" height="15" stroke="var(--text-muted)" fill="none" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="posSearch" type="text" placeholder="Name, SKU or scan barcode…  (F2)" autofocus
                    style="width:100%;padding:9px 38px 9px 33px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--input-bg);color:var(--text-dark);font-size:13px;outline:none;box-sizing:border-box;transition:border-color .15s;"
                    onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='var(--border-color)'">
                <button id="clearSearchBtn" onclick="clearSearch()" style="display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;color:var(--text-muted);font-size:16px;line-height:1;padding:2px;">&times;</button>
            </div>

            <!-- Barcode status pill -->
            <div id="barcodeStatus" style="display:flex;align-items:center;gap:5px;font-size:11px;font-weight:500;color:var(--text-muted);background:var(--bg-body);border:1px solid var(--border-color);padding:7px 10px;border-radius:8px;white-space:nowrap;flex-shrink:0;transition:all .25s;">
                <svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2"><line x1="3" y1="5" x2="3" y2="19"/><line x1="7" y1="5" x2="7" y2="19"/><line x1="11" y1="5" x2="11" y2="19"/><line x1="15" y1="5" x2="15" y2="19"/><line x1="19" y1="5" x2="19" y2="19"/></svg>
                Ready
            </div>

            <!-- View toggle -->
            <div style="display:flex;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;flex-shrink:0;">
                <button id="btnGrid" onclick="setView('grid')" title="Grid view" style="padding:7px 10px;background:var(--primary-color);color:#fff;border:none;cursor:pointer;transition:all .15s;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </button>
                <button id="btnList" onclick="setView('list')" title="List view" style="padding:7px 10px;background:var(--card-bg);color:var(--text-muted);border:none;cursor:pointer;transition:all .15s;">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
            </div>

            <!-- Recent sales -->
            <button onclick="toggleRecent()" title="Recent sales" style="padding:7px 10px;border:1px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text-muted);cursor:pointer;flex-shrink:0;transition:all .15s;" onmouseover="this.style.borderColor='var(--primary-color)';this.style.color='var(--primary-color)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </button>

            <!-- Shortcuts hint -->
            <div style="font-size:10px;color:var(--text-muted);line-height:1.5;flex-shrink:0;display:none;" id="shortcutHint">
                <kbd style="font-family:inherit;background:var(--bg-body);border:1px solid var(--border-color);border-radius:3px;padding:1px 4px;">Ctrl+↵</kbd> Checkout
            </div>
        </div>

        <!-- Category pills -->
        <div class="pill-bar" style="display:flex;gap:6px;margin-top:9px;overflow-x:auto;padding-bottom:1px;">
            <?php foreach ($cats as $i => $cat): ?>
                <button onclick="filterCat('<?= htmlspecialchars(addslashes($cat)) ?>')" data-cat="<?= htmlspecialchars($cat) ?>"
                    class="cat-pill"
                    style="padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;cursor:pointer;border:1px solid <?= $i===0?'var(--primary-color)':'var(--border-color)' ?>;background:<?= $i===0?'var(--primary-color)':'transparent' ?>;color:<?= $i===0?'#fff':'var(--text-muted)' ?>;transition:all .15s;">
                    <?= htmlspecialchars($cat) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Product grid / list -->
    <div id="productsContainer" style="flex:1;overflow-y:auto;padding:14px 16px;"></div>

    <!-- Empty state -->
    <div id="emptyState" style="display:none;flex-direction:column;align-items:center;justify-content:center;flex:1;color:var(--text-muted);">
        <svg viewBox="0 0 24 24" width="44" height="44" stroke="currentColor" fill="none" stroke-width="1" style="opacity:.2;margin-bottom:10px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        <p style="font-size:13px;margin:0;">No products found</p>
    </div>

    <!-- Recent sales overlay -->
    <div id="recentPanel" style="display:none;position:absolute;top:120px;right:396px;width:300px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.12);z-index:200;overflow:hidden;">
        <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:13px;font-weight:600;color:var(--text-dark);">Recent POS Sales</span>
            <button onclick="toggleRecent()" style="border:none;background:none;cursor:pointer;color:var(--text-muted);font-size:18px;line-height:1;">&times;</button>
        </div>
        <div id="recentList" style="max-height:320px;overflow-y:auto;"></div>
    </div>
</div>

<!-- ╔══════════════════════════════════════════════════════════════
     ║  RIGHT — Cart panel
     ╚══════════════════════════════════════════════════════════════ -->
<div style="width:370px;flex-shrink:0;display:flex;flex-direction:column;background:var(--card-bg);border-left:1px solid var(--border-color);position:relative;">

    <!-- ── Cart header: customer + hold controls ── -->
    <div style="padding:11px 13px;border-bottom:1px solid var(--border-color);flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:8px;">
            <svg viewBox="0 0 24 24" width="14" height="14" stroke="var(--text-muted)" fill="none" stroke-width="2" style="flex-shrink:0;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <select id="customerSelect" onchange="onCustomerChange()" style="flex:1;border:1px solid var(--border-color);border-radius:7px;padding:6px 9px;font-size:12px;background:var(--input-bg);color:var(--text-dark);outline:none;">
                <option value="">Walk-in Customer</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?><?= $c['contact_number']?' · '.$c['contact_number']:'' ?></option>
                <?php endforeach; ?>
            </select>
            <!-- Hold order buttons -->
            <button onclick="holdOrder()" title="Hold order" style="padding:5px 8px;border:1px solid var(--border-color);border-radius:7px;background:transparent;color:var(--text-muted);cursor:pointer;font-size:11px;font-weight:600;white-space:nowrap;transition:all .15s;display:flex;align-items:center;gap:4px;" onmouseover="this.style.borderColor='#f59e0b';this.style.color='#f59e0b'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                Hold
            </button>
            <button onclick="openHeldOrders()" id="heldBtn" title="Held orders" style="padding:5px 8px;border:1px solid var(--border-color);border-radius:7px;background:transparent;color:var(--text-muted);cursor:pointer;font-size:11px;font-weight:600;white-space:nowrap;transition:all .15s;position:relative;display:none;" onmouseover="this.style.borderColor='var(--primary-color)';this.style.color='var(--primary-color)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2"><path d="M3 15v4c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2v-4M17 9l-5 5-5-5M12 12.8V2.5"/></svg>
                <span id="heldCount" style="position:absolute;top:-5px;right:-5px;background:var(--primary-color);color:#fff;border-radius:50%;width:14px;height:14px;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;">0</span>
            </button>
        </div>
        <input id="orderNote" type="text" placeholder="Order note (optional)…"
            style="width:100%;padding:6px 9px;border:1px solid var(--border-color);border-radius:7px;font-size:11px;background:var(--input-bg);color:var(--text-dark);outline:none;box-sizing:border-box;">

        <!-- ── Loyalty Points Panel ── -->
        <div id="loyaltyPanel" style="display:none;margin-top:8px;background:rgba(109,74,255,.05);border:1px solid rgba(109,74,255,.2);border-radius:8px;padding:9px 11px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <svg viewBox="0 0 24 24" width="12" height="12" stroke="var(--primary-color)" fill="none" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <span style="font-size:11px;font-weight:700;color:var(--primary-color);">Loyalty Points</span>
                    <span id="loyaltyBalance" style="font-size:10px;font-weight:700;background:rgba(109,74,255,.15);color:var(--primary-color);padding:1px 7px;border-radius:20px;">0 pts</span>
                </div>
                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:11px;color:var(--text-muted);">
                    <input type="checkbox" id="loyaltyToggle" onchange="toggleLoyaltyRedeem()" style="accent-color:var(--primary-color);width:13px;height:13px;"> Use points
                </label>
            </div>
            <div id="loyaltyRedeemRow" style="display:none;">
                <div style="display:flex;align-items:center;gap:7px;">
                    <input id="loyaltyPointsInput" type="number" min="0" value="0" placeholder="Points"
                        oninput="updateLoyaltyDiscount()"
                        style="flex:1;padding:5px 8px;border:1px solid rgba(109,74,255,.3);border-radius:6px;font-size:12px;background:var(--input-bg);color:var(--text-dark);outline:none;">
                    <button onclick="applyMaxLoyalty()" style="padding:5px 10px;border:1px solid var(--primary-color);background:transparent;color:var(--primary-color);border-radius:6px;cursor:pointer;font-size:11px;font-weight:700;white-space:nowrap;">Max</button>
                    <span id="loyaltyDiscountLabel" style="font-size:12px;font-weight:700;color:var(--success-color);white-space:nowrap;">$0.00 off</span>
                </div>
                <div id="loyaltyHint" style="font-size:10px;color:var(--text-muted);margin-top:4px;"></div>
            </div>
        </div>
    </div>

    <!-- ── Cart items ── -->
    <div id="cartItems" style="flex:1;overflow-y:auto;min-height:0;"></div>

    <!-- ── Cart footer ── -->
    <div style="padding:11px 13px;border-top:1px solid var(--border-color);flex-shrink:0;background:var(--bg-light);">

        <!-- Discount row -->
        <div style="display:flex;gap:6px;margin-bottom:9px;">
            <div style="flex:1;position:relative;">
                <svg style="position:absolute;left:8px;top:50%;transform:translateY(-50%);opacity:.4;" viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                <input id="discountCode" type="text" placeholder="Discount / coupon code"
                    style="width:100%;padding:7px 9px 7px 25px;border:1px solid var(--border-color);border-radius:7px;font-size:12px;text-transform:uppercase;background:var(--input-bg);color:var(--text-dark);outline:none;box-sizing:border-box;transition:border-color .15s;"
                    onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='var(--border-color)'"
                    onkeydown="if(event.key==='Enter')applyDiscount()">
            </div>
            <button id="discountBtn" onclick="applyDiscount()" style="padding:0 13px;background:var(--primary-color);color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;transition:background .15s;white-space:nowrap;" onmouseover="this.style.background='var(--primary-hover)'" onmouseout="this.style.background='var(--primary-color)'">Apply</button>
        </div>
        <div id="discountMsg" style="display:none;font-size:11px;padding:5px 9px;border-radius:6px;margin-bottom:8px;"></div>

        <!-- Totals -->
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:9px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span>Subtotal</span><span id="subtotalDisplay">$0.00</span></div>
            <div id="discountRow" style="display:none;justify-content:space-between;margin-bottom:3px;color:var(--success-color);"><span id="discountLabel">Discount</span><span id="discountDisplay">−$0.00</span></div>
            <div id="loyaltyDiscountRow" style="display:none;justify-content:space-between;margin-bottom:3px;color:var(--success-color);"><span>Points Redeemed</span><span id="loyaltyDiscountDisplay">−$0.00</span></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span>Tax (<?= $tax_rate ?>%)</span><span id="taxDisplay">$0.00</span></div>
            <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:2px dashed var(--border-color);margin-top:4px;font-size:17px;font-weight:700;color:var(--text-dark);">
                <span>Total</span>
                <span id="totalDisplay" style="color:var(--primary-color);">$0.00</span>
            </div>
        </div>

        <!-- Cash tendered (cash-only) -->
        <div id="cashSection" style="margin-bottom:9px;">
            <div style="display:flex;align-items:center;gap:7px;">
                <div style="flex:1;position:relative;">
                    <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:var(--text-muted);">$</span>
                    <input id="tenderedInput" type="number" min="0" step="0.01" placeholder="Amount tendered" oninput="updateChange()"
                        style="width:100%;padding:7px 8px 7px 20px;border:1.5px solid var(--border-color);border-radius:7px;font-size:14px;font-weight:600;background:var(--input-bg);color:var(--text-dark);outline:none;box-sizing:border-box;transition:border-color .15s;"
                        onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='var(--border-color)'">
                </div>
                <button onclick="openNumpad()" title="Numpad" style="padding:7px 9px;border:1.5px solid var(--border-color);border-radius:7px;background:var(--bg-body);color:var(--text-muted);cursor:pointer;transition:all .15s;flex-shrink:0;" onmouseover="this.style.borderColor='var(--primary-color)';this.style.color='var(--primary-color)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><rect x="4" y="2" width="4" height="4"/><rect x="10" y="2" width="4" height="4"/><rect x="16" y="2" width="4" height="4"/><rect x="4" y="8" width="4" height="4"/><rect x="10" y="8" width="4" height="4"/><rect x="16" y="8" width="4" height="4"/><rect x="4" y="14" width="4" height="4"/><rect x="10" y="14" width="4" height="4"/><rect x="4" y="20" width="4" height="4"/><rect x="10" y="20" width="4" height="4"/><rect x="16" y="14" width="4" height="8"/></svg>
                </button>
                <div style="font-size:11px;text-align:center;min-width:54px;flex-shrink:0;">
                    <div style="color:var(--text-muted);font-size:9px;margin-bottom:1px;">CHANGE</div>
                    <div id="changeDisplay" style="font-size:14px;font-weight:700;color:var(--success-color);">$0.00</div>
                </div>
            </div>
            <!-- Quick cash buttons -->
            <div id="quickCashBtns" style="display:flex;gap:5px;margin-top:6px;flex-wrap:wrap;"></div>
        </div>

        <!-- Payment method selector -->
        <div style="display:flex;gap:5px;margin-bottom:9px;" id="pmBtns">
            <button onclick="selectMethod('Cash')" data-method="Cash"
                style="flex:1;padding:7px 0;border-radius:7px;border:1.5px solid var(--primary-color);background:var(--primary-color);color:#fff;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Cash
            </button>
            <button onclick="selectMethod('Credit Card')" data-method="Credit Card"
                style="flex:1;padding:7px 0;border-radius:7px;border:1.5px solid var(--border-color);background:transparent;color:var(--text-muted);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Card
            </button>
            <button onclick="selectMethod('Bank Transfer')" data-method="Bank Transfer"
                style="flex:1;padding:7px 0;border-radius:7px;border:1.5px solid var(--border-color);background:transparent;color:var(--text-muted);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>Transfer
            </button>
        </div>

        <!-- Action row -->
        <div style="display:flex;gap:6px;">
            <button onclick="clearCart()" title="Clear cart" style="padding:0 11px;border:1.5px solid var(--border-color);background:transparent;color:var(--text-muted);border-radius:8px;cursor:pointer;transition:all .15s;flex-shrink:0;" onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </button>
            <button id="checkoutBtn" onclick="processCheckout()" disabled
                style="flex:1;padding:13px 0;background:var(--success-color);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:not-allowed;transition:all .2s;opacity:.45;letter-spacing:.2px;">
                Charge &mdash; <span id="checkoutTotal">$0.00</span>
            </button>
        </div>
        <div style="text-align:center;margin-top:5px;font-size:10px;color:var(--text-muted);display:flex;align-items:center;justify-content:center;gap:8px;">
            <span id="cartCountLabel">0 items</span>
            <span>·</span>
            <span style="opacity:.7;"><kbd style="font-family:inherit;background:var(--bg-body);border:1px solid var(--border-color);border-radius:3px;padding:1px 4px;font-size:9px;">Ctrl+↵</kbd> Checkout</span>
        </div>
    </div>
</div><!-- end cart panel -->

</div><!-- end workspace -->
</main>

<!-- ═══════════════════════════════════════════════════════════════════════════
     RECEIPT MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="receiptModal" class="modal-overlay">
    <div class="modal-content pop-in" style="max-width:400px;width:92%;border-radius:16px;overflow:hidden;">
        <div style="background:var(--success-color);padding:22px 24px;text-align:center;color:#fff;">
            <div style="width:50px;height:50px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="#fff" fill="none" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2 style="margin:0 0 3px;font-size:18px;font-weight:700;">Payment Complete</h2>
            <p style="margin:0;font-size:12px;opacity:.85;" id="receiptRef">–</p>
        </div>
        <div id="receiptBody" style="padding:18px 20px;font-size:13px;line-height:1.7;color:var(--text-dark);max-height:340px;overflow-y:auto;"></div>
        <div style="padding:14px 20px;border-top:1px solid var(--border-color);display:flex;gap:9px;">
            <button onclick="newSale()" style="flex:1;padding:10px;border:1.5px solid var(--border-color);background:transparent;color:var(--text-dark);border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:background .15s;" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'">New Sale</button>
            <a id="printReceiptBtn" href="#" target="_blank" style="flex:1;padding:10px;background:var(--primary-color);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:13px;text-align:center;display:block;transition:background .15s;" onmouseover="this.style.background='var(--primary-hover)'" onmouseout="this.style.background='var(--primary-color)'">Print Receipt</a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     NUMPAD MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="numpadModal" class="modal-overlay">
    <div class="modal-content" style="max-width:280px;width:90%;border-radius:14px;overflow:hidden;">
        <div style="padding:14px 16px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:14px;font-weight:600;color:var(--text-dark);">Enter Amount</span>
            <button onclick="closeNumpad()" style="border:none;background:none;cursor:pointer;color:var(--text-muted);font-size:18px;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px;">
            <div style="background:var(--bg-body);border:1.5px solid var(--border-color);border-radius:8px;padding:10px 14px;text-align:right;font-size:24px;font-weight:700;color:var(--text-dark);margin-bottom:14px;min-height:46px;font-family:monospace;" id="numpadDisplay">0</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                <?php foreach(['7','8','9','4','5','6','1','2','3','.','0'] as $k): ?>
                <button class="numpad-key" onclick="numpadPress('<?= $k ?>')"><?= $k ?></button>
                <?php endforeach; ?>
                <button class="numpad-key danger" onclick="numpadPress('back')">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/></svg>
                </button>
                <button class="numpad-key wide action" onclick="numpadConfirm()" style="grid-column:span 3;margin-top:2px;">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     HELD ORDERS MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="heldModal" class="modal-overlay">
    <div class="modal-content" style="max-width:420px;width:90%;border-radius:14px;overflow:hidden;">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:14px;font-weight:600;color:var(--text-dark);">Held Orders</span>
            <button onclick="document.getElementById('heldModal').classList.remove('active')" style="border:none;background:none;cursor:pointer;color:var(--text-muted);font-size:18px;">&times;</button>
        </div>
        <div id="heldList" style="max-height:380px;overflow-y:auto;padding:8px 0;"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════════════════════════════════ -->
<script>
// ── Constants ────────────────────────────────────────────────────────────────
const TAX_RATE  = <?= (float)$tax_rate ?>;
const CASHIER   = <?= json_encode($user_name) ?>;

// ── State ─────────────────────────────────────────────────────────────────────
let cart           = [];
let allProducts    = [];
let filteredProds  = [];
let currentCat     = 'All';
let currentSearch  = '';
let currentView    = 'grid';
let paymentMethod  = 'Cash';
let discountInfo   = null;   // { code, amount, label }
let heldOrders     = JSON.parse(localStorage.getItem('pos_held_orders') || '[]');
let barcodeBuffer  = '';
let barcodeTimer   = null;
let numpadValue    = '0';
let isProcessing   = false;

// ── Loyalty state ─────────────────────────────────────────────────────────────
let loyaltyInfo    = null;   // { active, balance, min_pts, redeem_rate, max_pct }
let loyaltyDiscount = 0;     // computed $ discount from points

// ── Boot ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    startClock();
    loadProducts('', 'All');
    buildQuickCash(0);
    updateHeldBtn();
    renderCart();

    document.getElementById('posSearch').addEventListener('input', e => {
        currentSearch = e.target.value;
        document.getElementById('clearSearchBtn').style.display = currentSearch ? 'block' : 'none';
        applyFilters();
    });

    document.addEventListener('keydown', handleGlobalKey);
    document.addEventListener('click', e => {
        if (!e.target.closest('#recentPanel') && !e.target.closest('button[onclick="toggleRecent()"]')) {
            document.getElementById('recentPanel').style.display = 'none';
        }
    });
});

// ── Clock ─────────────────────────────────────────────────────────────────────
function startClock() {
    const el = document.getElementById('posTime');
    const tick = () => el.textContent = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    tick(); setInterval(tick, 1000);
}

// ── Products ──────────────────────────────────────────────────────────────────
async function loadProducts(q, cat) {
    try {
        if (cat === 'Bundles') {
            const res  = await fetch(`pos.php?api=search_bundles&q=${encodeURIComponent(q)}`);
            allProducts = await res.json();
        } else if (cat === 'All' || cat === '') {
            const [prodRes, bndlRes] = await Promise.all([
                fetch(`pos.php?api=search&q=${encodeURIComponent(q)}&cat=`),
                fetch(`pos.php?api=search_bundles&q=${encodeURIComponent(q)}`)
            ]);
            const prods   = await prodRes.json();
            const bundles = await bndlRes.json();
            allProducts = [...prods, ...bundles];
        } else {
            const res  = await fetch(`pos.php?api=search&q=${encodeURIComponent(q)}&cat=${encodeURIComponent(cat)}`);
            allProducts = await res.json();
        }
        applyFilters();
    } catch(e) { console.error('Product load failed', e); }
}

function applyFilters() {
    const q = currentSearch.toLowerCase().trim();
    let p = allProducts;
    if (q) p = p.filter(x =>
        (x.product_name || x.bundle_name || '').toLowerCase().includes(q) ||
        (x.sku_code && x.sku_code.toLowerCase().includes(q)) ||
        (x.barcode_number && x.barcode_number.toLowerCase().includes(q))
    );
    filteredProds = p;
    renderProducts();
}

function clearSearch() {
    document.getElementById('posSearch').value = '';
    document.getElementById('clearSearchBtn').style.display = 'none';
    currentSearch = '';
    applyFilters();
    document.getElementById('posSearch').focus();
}

function filterCat(cat) {
    currentCat = cat;
    document.querySelectorAll('.cat-pill').forEach(b => {
        const active = b.dataset.cat === cat;
        b.style.background   = active ? 'var(--primary-color)' : 'transparent';
        b.style.color        = active ? '#fff'                  : 'var(--text-muted)';
        b.style.borderColor  = active ? 'var(--primary-color)' : 'var(--border-color)';
    });
    loadProducts(currentSearch, cat);
}

function setView(v) {
    currentView = v;
    document.getElementById('btnGrid').style.background = v==='grid' ? 'var(--primary-color)' : 'var(--card-bg)';
    document.getElementById('btnGrid').style.color      = v==='grid' ? '#fff'                  : 'var(--text-muted)';
    document.getElementById('btnList').style.background = v==='list' ? 'var(--primary-color)' : 'var(--card-bg)';
    document.getElementById('btnList').style.color      = v==='list' ? '#fff'                  : 'var(--text-muted)';
    renderProducts();
}

function getEffectivePrice(p) {
    return (p.discounted_price && parseFloat(p.discounted_price) > 0 && parseFloat(p.discounted_price) < parseFloat(p.selling_price))
        ? parseFloat(p.discounted_price)
        : parseFloat(p.selling_price);
}

function renderProducts() {
    const container  = document.getElementById('productsContainer');
    const emptyState = document.getElementById('emptyState');

    if (!filteredProds.length) {
        container.style.display  = 'none';
        emptyState.style.display = 'flex';
        return;
    }
    container.style.display  = '';
    emptyState.style.display = 'none';

    if (currentView === 'grid') {
        container.style.cssText = 'flex:1;overflow-y:auto;padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;align-content:start;';
        container.innerHTML = filteredProds.map(p => {
            const isBundle = !!p._is_bundle;
            const name    = isBundle ? p.bundle_name : p.product_name;
            const price   = getEffectivePrice(p);
            const orig    = parseFloat(p.selling_price);
            const hasDisc = !isBundle && price < orig;
            const stock   = parseInt(p.stock_level);
            const inCart  = cart.find(i => i.id === parseInt(p.id) && !!i._is_bundle === isBundle);
            const oos     = stock <= 0;
            const low     = !oos && stock <= 5;
            const clickFn = oos ? '' : (isBundle ? `addBundleToCart(${parseInt(p.id)})` : `addToCart(${parseInt(p.id)})`);
            return `<div class="pos-card${oos?' out-of-stock':''}${inCart?' in-cart':''}" onclick="${clickFn}" role="button">
                ${inCart ? `<div style="position:absolute;top:6px;right:6px;background:var(--primary-color);color:#fff;font-size:9px;font-weight:700;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;">${inCart.qty}</div>` : ''}
                ${isBundle ? `<div style="position:absolute;top:6px;left:6px;background:#7c3aed;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:3px;letter-spacing:.3px;">BUNDLE</div>` : ''}
                ${!isBundle && oos ? `<div style="position:absolute;top:6px;left:6px;background:#ef4444;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:3px;letter-spacing:.3px;">OUT</div>` : ''}
                ${!isBundle && !oos && low ? `<div style="position:absolute;top:6px;left:6px;background:#f59e0b;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:3px;">${stock} LEFT</div>` : ''}
                <div style="margin-bottom:4px;${isBundle?'margin-top:14px;':''}">
                    <span style="font-size:16px;font-weight:700;color:${isBundle?'#7c3aed':'var(--text-dark)'};">'+\$currency['symbol']+'${price.toFixed(2)}</span>
                    ${hasDisc ? `<span style="font-size:10px;color:var(--text-muted);text-decoration:line-through;margin-left:4px;">'+\$currency['symbol']+'${orig.toFixed(2)}</span>` : ''}
                    ${isBundle ? `<span style="font-size:10px;color:var(--text-muted);text-decoration:line-through;margin-left:4px;">'+\$currency['symbol']+'${parseFloat(p.normal_sell_value).toFixed(2)}</span>` : ''}
                </div>
                <div style="font-size:11px;color:var(--text-dark);font-weight:500;line-height:1.4;margin-bottom:4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${name}</div>
                <div style="font-size:10px;color:var(--text-muted);">${p.sku_code||'–'}</div>
                <div style="font-size:9px;color:${oos?'#ef4444':low?'#f59e0b':'var(--text-muted)'};margin-top:4px;font-weight:${oos||low?600:400};">${isBundle?(oos?'Out of stock':stock+' sets avail'):( oos?'Out of stock':stock+' in stock')}</div>
            </div>`;
        }).join('');

    } else {
        container.style.cssText = 'flex:1;overflow-y:auto;padding:0;';
        container.innerHTML = `
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="border-bottom:1px solid var(--border-color);color:var(--text-muted);font-size:10px;text-transform:uppercase;letter-spacing:.4px;position:sticky;top:0;background:var(--card-bg);z-index:1;">
                <th style="text-align:left;padding:8px 16px;font-weight:500;">Product</th>
                <th style="text-align:left;padding:8px 8px;font-weight:500;">SKU</th>
                <th style="text-align:right;padding:8px 8px;font-weight:500;">Stock</th>
                <th style="text-align:right;padding:8px 16px;font-weight:500;">Price</th>
                <th style="width:40px;"></th>
            </tr></thead><tbody>` +
            filteredProds.map(p => {
                const isBundle = !!p._is_bundle;
                const name   = isBundle ? p.bundle_name : p.product_name;
                const price  = getEffectivePrice(p);
                const orig   = parseFloat(p.selling_price);
                const hasD   = !isBundle && price < orig;
                const stock  = parseInt(p.stock_level);
                const oos    = stock <= 0;
                const low    = !oos && stock <= 5;
                const inCart = cart.find(i => i.id === parseInt(p.id) && !!i._is_bundle === isBundle);
                const clickFn = oos ? '' : (isBundle ? `addBundleToCart(${parseInt(p.id)})` : `addToCart(${parseInt(p.id)})`);
                return `<tr style="border-bottom:1px solid var(--border-color);opacity:${oos?.5:1};" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
                    <td style="padding:9px 16px;font-weight:500;color:var(--text-dark);">
                        ${isBundle?`<span style="background:#7c3aed;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:3px;margin-right:5px;">BUNDLE</span>`:''}
                        ${name}${inCart?`<span style="background:var(--primary-color);color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:10px;margin-left:5px;">${inCart.qty}</span>`:''}
                    </td>
                    <td style="padding:9px 8px;color:var(--text-muted);">${p.sku_code||'–'}</td>
                    <td style="padding:9px 8px;text-align:right;color:${oos?'#ef4444':low?'#f59e0b':'var(--text-muted)'};font-weight:${oos||low?600:400};">${stock}</td>
                    <td style="padding:9px 16px;text-align:right;white-space:nowrap;">
                        <span style="font-weight:700;color:${isBundle?'#7c3aed':'var(--text-dark)'};">'+\$currency['symbol']+'${price.toFixed(2)}</span>
                        ${hasD?`<span style="font-size:10px;color:var(--text-muted);text-decoration:line-through;margin-left:3px;">'+\$currency['symbol']+'${orig.toFixed(2)}</span>`:''}
                        ${isBundle?`<span style="font-size:10px;color:var(--text-muted);text-decoration:line-through;margin-left:3px;">'+\$currency['symbol']+'${parseFloat(p.normal_sell_value).toFixed(2)}</span>`:''}
                    </td>
                    <td style="padding:9px 12px;text-align:center;">
                        <button onclick="${clickFn}" ${oos?'disabled':''}
                            style="width:24px;height:24px;border-radius:50%;border:none;background:${oos?'var(--border-color)':isBundle?'#7c3aed':'var(--primary-color)'};color:#fff;cursor:${oos?'not-allowed':'pointer'};font-size:17px;display:inline-flex;align-items:center;justify-content:center;line-height:1;">+</button>
                    </td>
                </tr>`;
            }).join('') + '</tbody></table>';
    }
}

// ── Cart ──────────────────────────────────────────────────────────────────────
function addToCart(id) {
    const p = allProducts.find(x => parseInt(x.id) === parseInt(id));
    if (!p || parseInt(p.stock_level) <= 0) return;

    const price    = getEffectivePrice(p);
    const existing = cart.find(i => i.id === parseInt(p.id));
    if (existing) {
        if (existing.qty < parseInt(p.stock_level)) existing.qty++;
        else { showToast('Max stock reached for this product.', 'warning'); return; }
    } else {
        cart.unshift({ id: parseInt(p.id), name: p.product_name, price, origPrice: parseFloat(p.selling_price), qty: 1, stock: parseInt(p.stock_level), sku: p.sku_code });
    }

    // Flash search green
    const si = document.getElementById('posSearch');
    si.style.borderColor = 'var(--success-color)';
    si.value = ''; currentSearch = '';
    document.getElementById('clearSearchBtn').style.display = 'none';
    setTimeout(() => si.style.borderColor = 'var(--border-color)', 350);

    // Reset discount on cart change
    discountInfo = null;
    document.getElementById('discountCode').value = '';
    hideDiscountMsg();
    // Recalculate loyalty cap if active
    if (loyaltyInfo && document.getElementById('loyaltyToggle').checked) updateLoyaltyDiscount();

    renderCart();
    // Reload full product list for current category so clearing the search
    // actually shows ALL products, not just the previous search results.
    loadProducts('', currentCat);
}

function addBundleToCart(id) {
    const p = allProducts.find(x => parseInt(x.id) === parseInt(id) && x._is_bundle);
    if (!p) return;
    const stock = parseInt(p.stock_level);
    if (stock <= 0) return;

    const price    = parseFloat(p.bundle_price);
    const existing = cart.find(i => i.id === parseInt(p.id) && i._is_bundle);
    if (existing) {
        if (existing.qty < stock) existing.qty++;
        else { showToast('Max bundle stock reached.', 'warning'); return; }
    } else {
        cart.unshift({ id: parseInt(p.id), name: p.bundle_name, price, origPrice: parseFloat(p.normal_sell_value), qty: 1, stock, sku: p.sku_code, _is_bundle: true });
    }

    const si = document.getElementById('posSearch');
    si.style.borderColor = 'var(--success-color)';
    si.value = ''; currentSearch = '';
    document.getElementById('clearSearchBtn').style.display = 'none';
    setTimeout(() => si.style.borderColor = 'var(--border-color)', 350);

    discountInfo = null;
    document.getElementById('discountCode').value = '';
    hideDiscountMsg();
    if (loyaltyInfo && document.getElementById('loyaltyToggle').checked) updateLoyaltyDiscount();

    renderCart();
    loadProducts('', currentCat);
}

function updateQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;
    item.qty = Math.max(0, Math.min(item.qty + delta, item.stock));
    if (item.qty === 0) cart = cart.filter(i => i.id !== id);
    discountInfo = null;
    renderCart(); renderProducts();
}

function setQtyDirect(id, val) {
    const item = cart.find(i => i.id === id);
    if (!item) return;
    const n = Math.max(0, Math.min(parseInt(val)||0, item.stock));
    if (n === 0) cart = cart.filter(i => i.id !== id);
    else item.qty = n;
    discountInfo = null;
    renderCart(); renderProducts();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    discountInfo = null;
    renderCart(); renderProducts();
}

function clearCart() {
    if (!cart.length) return;
    showCustomConfirm('Clear all items from cart?', () => {
        cart = []; discountInfo = null;
        document.getElementById('discountCode').value = '';
        document.getElementById('tenderedInput').value = '';
        hideDiscountMsg();
        renderCart(); renderProducts();
    });
}

function renderCart() {
    const el = document.getElementById('cartItems');

    if (!cart.length) {
        el.innerHTML = `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:160px;color:var(--text-muted);text-align:center;padding:16px;">
            <svg viewBox="0 0 24 24" width="38" height="38" stroke="currentColor" fill="none" stroke-width="1" style="opacity:.2;margin-bottom:8px;"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            <p style="font-size:12px;margin:0 0 4px;">Cart is empty</p>
            <p style="font-size:10px;margin:0;opacity:.6;">Click a product or scan a barcode</p>
        </div>`;
        document.getElementById('checkoutBtn').disabled = true;
        document.getElementById('checkoutBtn').style.opacity = '.45';
        document.getElementById('checkoutBtn').style.cursor = 'not-allowed';
        document.getElementById('cartCountLabel').textContent = '0 items';
        updateTotals();
        return;
    }

    el.innerHTML = cart.map(item => {
        const lineTotal = (item.price * item.qty).toFixed(2);
        const hasDisc   = item.price < item.origPrice;
        return `<div class="cart-row" style="display:flex;align-items:center;padding:8px 11px;border-bottom:1px solid var(--border-color);gap:7px;transition:background .1s;">
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:600;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${item.name}</div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:1px;">
                    $${item.price.toFixed(2)}/ea${hasDisc?` <span style="text-decoration:line-through;opacity:.6;">$${item.origPrice.toFixed(2)}</span>`:''}
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:3px;flex-shrink:0;">
                <button onclick="updateQty(${item.id},-1)" style="width:21px;height:21px;border-radius:5px;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-dark);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .1s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-body)'">−</button>
                <input type="number" min="1" max="${item.stock}" value="${item.qty}"
                    onchange="setQtyDirect(${item.id},this.value)"
                    style="width:30px;text-align:center;border:1px solid var(--border-color);border-radius:5px;padding:2px 0;font-size:11px;background:var(--input-bg);color:var(--text-dark);outline:none;">
                <button onclick="updateQty(${item.id},1)" style="width:21px;height:21px;border-radius:5px;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-dark);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .1s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-body)'">+</button>
            </div>
            <div style="font-weight:700;font-size:12px;color:var(--text-dark);width:48px;text-align:right;flex-shrink:0;">$${lineTotal}</div>
            <button onclick="removeFromCart(${item.id})" style="background:none;border:none;cursor:pointer;padding:0 0 0 2px;opacity:.35;transition:opacity .15s;flex-shrink:0;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.35">
                <svg viewBox="0 0 24 24" width="13" height="13" stroke="#ef4444" fill="none" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>`;
    }).join('');

    const qty = cart.reduce((s, i) => s + i.qty, 0);
    document.getElementById('cartCountLabel').textContent = `${qty} item${qty!==1?'s':''}`;
    document.getElementById('checkoutBtn').disabled = false;
    document.getElementById('checkoutBtn').style.opacity = '1';
    document.getElementById('checkoutBtn').style.cursor = 'pointer';
    updateTotals();
}

// ── Totals ─────────────────────────────────────────────────────────────────────
function updateTotals() {
    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const discount = discountInfo ? discountInfo.amount : 0;
    const taxable  = subtotal - discount;
    const tax      = taxable * (TAX_RATE / 100);
    const preTotal = taxable + tax;
    const total    = Math.max(0, preTotal - loyaltyDiscount);

    document.getElementById('subtotalDisplay').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('taxDisplay').textContent      = '$' + tax.toFixed(2);
    document.getElementById('totalDisplay').textContent    = '$' + total.toFixed(2);
    document.getElementById('checkoutTotal').textContent   = '$' + total.toFixed(2);

    if (discountInfo) {
        document.getElementById('discountRow').style.display  = 'flex';
        document.getElementById('discountLabel').textContent  = `Discount (${discountInfo.code})`;
        document.getElementById('discountDisplay').textContent = '−$' + discount.toFixed(2);
    } else {
        document.getElementById('discountRow').style.display = 'none';
    }

    const loyRow = document.getElementById('loyaltyDiscountRow');
    if (loyaltyDiscount > 0) {
        loyRow.style.display = 'flex';
        document.getElementById('loyaltyDiscountDisplay').textContent = '−$' + loyaltyDiscount.toFixed(2);
    } else {
        loyRow.style.display = 'none';
    }

    updateChange();
    buildQuickCash(total);
    return { subtotal, discount, tax, total };
}

function updateChange() {
    const total    = parseFloat(document.getElementById('totalDisplay').textContent.replace('$','')) || 0;
    const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;
    const change   = Math.max(0, tendered - total);
    const el       = document.getElementById('changeDisplay');
    el.textContent = '$' + change.toFixed(2);
    el.style.color = tendered >= total && tendered > 0 ? 'var(--success-color)' : 'var(--text-muted)';
}

function buildQuickCash(total) {
    const c = document.getElementById('quickCashBtns');
    if (!c || total <= 0) { c.innerHTML = ''; return; }
    const amounts = [];
    const denoms  = [1, 2, 5, 10, 20, 50, 100, 200, 500];
    for (const d of denoms) {
        const r = Math.ceil(total / d) * d;
        if (!amounts.includes(r)) amounts.push(r);
        if (amounts.length >= 5) break;
    }
    c.innerHTML = amounts.slice(0, 5).map(a =>
        `<button onclick="document.getElementById('tenderedInput').value='${a.toFixed(2)}';updateChange();"
            style="padding:4px 9px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-dark);font-size:10px;font-weight:600;cursor:pointer;transition:all .12s;"
            onmouseover="this.style.borderColor='var(--primary-color)';this.style.color='var(--primary-color)'"
            onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-dark)'">$${a % 1 === 0 ? a : a.toFixed(2)}</button>`
    ).join('');
}

// ── Discount ──────────────────────────────────────────────────────────────────
async function applyDiscount() {
    const code = document.getElementById('discountCode').value.trim().toUpperCase();
    if (!code) { discountInfo = null; hideDiscountMsg(); updateTotals(); return; }

    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const btn      = document.getElementById('discountBtn');
    btn.textContent = '…'; btn.disabled = true;

    try {
        const res  = await fetch('pos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action:'validate_discount', code, subtotal })
        });
        const data = await res.json();

        if (data.valid) {
            discountInfo = { code, amount: data.amount, label: data.label };
            showDiscountMsg('success', `✓ ${data.label} applied — saves $${parseFloat(data.amount).toFixed(2)}`);
            updateTotals();
        } else {
            discountInfo = null;
            showDiscountMsg('error', '✕ ' + (data.message || 'Invalid code'));
            updateTotals();
        }
    } catch(e) {
        showDiscountMsg('error', 'Could not validate code');
    }

    btn.textContent = 'Apply'; btn.disabled = false;
}

function showDiscountMsg(type, text) {
    const el = document.getElementById('discountMsg');
    el.style.display     = 'block';
    el.style.background  = type === 'success' ? 'rgba(16,185,129,.12)' : 'rgba(239,68,68,.1)';
    el.style.color       = type === 'success' ? 'var(--success-color)'  : '#ef4444';
    el.style.border      = `1px solid ${type==='success'?'rgba(16,185,129,.25)':'rgba(239,68,68,.25)'}`;
    el.textContent       = text;
}

function hideDiscountMsg() {
    document.getElementById('discountMsg').style.display = 'none';
}

// ── Payment method ─────────────────────────────────────────────────────────────
function selectMethod(m) {
    paymentMethod = m;
    document.querySelectorAll('#pmBtns button').forEach(b => {
        const active = b.dataset.method === m;
        b.style.background  = active ? 'var(--primary-color)' : 'transparent';
        b.style.color       = active ? '#fff'                  : 'var(--text-muted)';
        b.style.borderColor = active ? 'var(--primary-color)' : 'var(--border-color)';
    });
    document.getElementById('cashSection').style.display = m === 'Cash' ? 'block' : 'none';
}

// ── Checkout ──────────────────────────────────────────────────────────────────
async function processCheckout() {
    if (isProcessing || !cart.length) return;

    const totals  = updateTotals();
    const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;

    if (paymentMethod === 'Cash' && tendered < totals.total - 0.001) {
        showToast('Tendered amount is less than the total.', 'warning');
        return;
    }

    isProcessing = true;
    const btn    = document.getElementById('checkoutBtn');
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2" style="animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Processing…';
    btn.disabled  = true;
    btn.style.opacity = '.8';

    const payload = {
        action:          'checkout',
        cart:            cart.map(i => ({ id: i.id, qty: i.qty, price: i.price })),
        customer_id:     document.getElementById('customerSelect').value,
        payment_method:  paymentMethod,
        discount_code:   discountInfo ? discountInfo.code : '',
        amount_tendered: tendered || totals.total,
        note:            document.getElementById('orderNote').value.trim(),
        points_to_redeem: loyaltyInfo && document.getElementById('loyaltyToggle').checked
                          ? parseInt(document.getElementById('loyaltyPointsInput').value || 0)
                          : 0,
    };

    try {
        const res  = await fetch('pos.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const data = await res.json();

        if (data.success) {
            showReceipt(data);
        } else {
            showToast(data.message || 'Checkout failed. Please try again.', 'error');
            resetCheckoutBtn(totals.total);
        }
    } catch(e) {
        showToast('Network error. Please check connection.', 'error');
        resetCheckoutBtn(totals.total);
    }

    isProcessing = false;
}

function resetCheckoutBtn(total) {
    const btn    = document.getElementById('checkoutBtn');
    btn.innerHTML = `Charge &mdash; $${total.toFixed(2)}`;
    btn.disabled  = false;
    btn.style.opacity = '1';
}

// ── Receipt ───────────────────────────────────────────────────────────────────
function showReceipt(data) {
    document.getElementById('receiptRef').textContent = 'Order ' + data.ref + ' · ' + data.date;
    document.getElementById('printReceiptBtn').href   = 'export.php?module=receipt&id=' + data.order_id;

    const itemRows = cart.map(i => `<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px;"><span style="color:var(--text-muted);">${i.name} <span style="opacity:.7;">×${i.qty}</span></span><span>$${(i.price*i.qty).toFixed(2)}</span></div>`).join('');

    const summaryRows = [
        { k:'Subtotal',   v:'$'+parseFloat(data.subtotal).toFixed(2) },
        data.discount > 0         ? { k:'Discount',         v:'−$'+parseFloat(data.discount).toFixed(2),          green:true } : null,
        data.loyalty_discount > 0 ? { k:'Points Redeemed',  v:'−$'+parseFloat(data.loyalty_discount).toFixed(2),  green:true } : null,
        data.tax > 0              ? { k:`Tax (${TAX_RATE}%)`, v:'$'+parseFloat(data.tax).toFixed(2) }              : null,
        { k:'TOTAL',      v:'$'+parseFloat(data.total).toFixed(2), bold:true },
        data.method==='Cash' && data.tendered > 0 ? { k:'Tendered', v:'$'+parseFloat(data.tendered).toFixed(2) } : null,
        data.method==='Cash' && data.tendered > 0 ? { k:'Change',   v:'$'+parseFloat(data.change).toFixed(2),  green:true } : null,
    ].filter(Boolean);

    const loyaltyEarnedNote = data.pts_earned > 0
        ? `<div style="margin-top:10px;padding:6px 10px;background:rgba(109,74,255,.08);border-radius:6px;font-size:11px;color:var(--primary-color);display:flex;align-items:center;gap:6px;">
            <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            +${data.pts_earned} loyalty points earned on this order!
           </div>`
        : '';

    document.getElementById('receiptBody').innerHTML =
        `<div style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px dashed var(--border-color);">${itemRows}</div>` +
        summaryRows.map(r => `<div style="display:flex;justify-content:space-between;margin-bottom:3px;">
            <span style="color:var(--text-muted);font-size:12px;">${r.k}</span>
            <span style="font-weight:${r.bold?700:500};font-size:${r.bold?'15':'12'}px;color:${r.bold?'var(--primary-color)':r.green?'var(--success-color)':'var(--text-dark)'};">${r.v}</span>
        </div>`).join('') +
        `<div style="margin-top:10px;padding-top:8px;border-top:1px dashed var(--border-color);font-size:11px;color:var(--text-muted);display:flex;justify-content:space-between;"><span>Cashier: ${data.cashier}</span><span>${data.method}</span></div>` +
        loyaltyEarnedNote;

    document.getElementById('receiptModal').classList.add('active');
}

function newSale() {
    cart = []; discountInfo = null; loyaltyDiscount = 0; loyaltyInfo = null;
    document.getElementById('receiptModal').classList.remove('active');
    ['discountCode','tenderedInput','orderNote'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('customerSelect').value = '';
    document.getElementById('loyaltyPanel').style.display = 'none';
    document.getElementById('loyaltyToggle').checked = false;
    document.getElementById('loyaltyRedeemRow').style.display = 'none';
    document.getElementById('loyaltyPointsInput').value = 0;
    clearSearch(); hideDiscountMsg();
    renderCart(); renderProducts();
    document.getElementById('posSearch').focus();
}

// ── Hold Orders ───────────────────────────────────────────────────────────────
function holdOrder() {
    if (!cart.length) { showToast('Cart is empty.', 'warning'); return; }
    const label = `Hold ${heldOrders.length + 1} — ${cart.length} item${cart.length>1?'s':''} · $${cart.reduce((s,i)=>s+i.price*i.qty,0).toFixed(2)}`;
    heldOrders.push({ id: Date.now(), label, cart: JSON.parse(JSON.stringify(cart)), customer: document.getElementById('customerSelect').value, note: document.getElementById('orderNote').value });
    localStorage.setItem('pos_held_orders', JSON.stringify(heldOrders));
    cart = [];
    renderCart(); renderProducts();
    updateHeldBtn();
    showToast(`Order held: ${label}`, 'info');
}

function openHeldOrders() {
    const list = document.getElementById('heldList');
    if (!heldOrders.length) {
        list.innerHTML = '<p style="padding:16px;font-size:13px;color:var(--text-muted);text-align:center;">No held orders</p>';
    } else {
        list.innerHTML = heldOrders.map(o => `
            <div style="padding:10px 16px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px;">
                <div style="flex:1;">
                    <div style="font-size:13px;font-weight:600;color:var(--text-dark);">${o.label}</div>
                    ${o.note?`<div style="font-size:11px;color:var(--text-muted);">${o.note}</div>`:''}
                </div>
                <button onclick="resumeOrder(${o.id})" style="padding:5px 12px;background:var(--primary-color);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">Resume</button>
                <button onclick="deleteHeld(${o.id})" style="padding:5px 8px;background:transparent;border:1px solid var(--border-color);border-radius:6px;cursor:pointer;color:var(--text-muted);">✕</button>
            </div>`).join('');
    }
    document.getElementById('heldModal').classList.add('active');
}

function resumeOrder(id) {
    const order = heldOrders.find(o => o.id === id);
    if (!order) return;
    if (cart.length) { showCustomConfirm('Replace current cart with held order?', () => _resumeOrder(order)); }
    else _resumeOrder(order);
}

function _resumeOrder(order) {
    cart = order.cart;
    document.getElementById('customerSelect').value = order.customer || '';
    document.getElementById('orderNote').value      = order.note || '';
    heldOrders = heldOrders.filter(o => o.id !== order.id);
    localStorage.setItem('pos_held_orders', JSON.stringify(heldOrders));
    document.getElementById('heldModal').classList.remove('active');
    updateHeldBtn();
    renderCart(); renderProducts();
    showToast('Order resumed.', 'success');
}

function deleteHeld(id) {
    heldOrders = heldOrders.filter(o => o.id !== id);
    localStorage.setItem('pos_held_orders', JSON.stringify(heldOrders));
    updateHeldBtn();
    openHeldOrders();
}

function updateHeldBtn() {
    const btn = document.getElementById('heldBtn');
    const cnt = document.getElementById('heldCount');
    if (heldOrders.length > 0) {
        btn.style.display = 'flex';
        cnt.textContent   = heldOrders.length;
    } else {
        btn.style.display = 'none';
    }
}

// ── Numpad ─────────────────────────────────────────────────────────────────────
function openNumpad() {
    const current = document.getElementById('tenderedInput').value;
    numpadValue   = current || '0';
    document.getElementById('numpadDisplay').textContent = numpadValue;
    document.getElementById('numpadModal').classList.add('active');
}

function closeNumpad() { document.getElementById('numpadModal').classList.remove('active'); }

function numpadPress(key) {
    if (key === 'back') {
        numpadValue = numpadValue.length > 1 ? numpadValue.slice(0, -1) : '0';
    } else if (key === '.') {
        if (!numpadValue.includes('.')) numpadValue += '.';
    } else {
        numpadValue = numpadValue === '0' ? key : numpadValue + key;
        if (numpadValue.includes('.')) {
            const parts = numpadValue.split('.');
            if (parts[1] && parts[1].length > 2) return;
        }
    }
    document.getElementById('numpadDisplay').textContent = numpadValue;
}

function numpadConfirm() {
    document.getElementById('tenderedInput').value = parseFloat(numpadValue).toFixed(2);
    updateChange();
    closeNumpad();
}

// ── Recent sales panel ─────────────────────────────────────────────────────────
async function toggleRecent() {
    const panel = document.getElementById('recentPanel');
    if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
    panel.style.display = 'block';
    const list  = document.getElementById('recentList');
    list.innerHTML = '<div style="padding:16px;font-size:12px;color:var(--text-muted);text-align:center;">Loading…</div>';
    try {
        const res  = await fetch('pos.php?api=recent');
        const rows = await res.json();
        if (!rows.length) { list.innerHTML = '<p style="padding:16px;font-size:12px;color:var(--text-muted);text-align:center;">No recent sales</p>'; return; }
        list.innerHTML = rows.map(r => `
            <a href="view_sales_order.php?order_id=${r.id}" target="_blank" style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border-color);text-decoration:none;transition:background .1s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
                <div>
                    <div style="font-size:12px;font-weight:600;color:var(--text-dark);">${r.order_id_str}</div>
                    <div style="font-size:10px;color:var(--text-muted);">${r.customer_name} · ${new Date(r.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}</div>
                </div>
                <div style="font-size:13px;font-weight:700;color:var(--primary-color);">$${parseFloat(r.total_amount).toFixed(2)}</div>
            </a>`).join('');
    } catch(e) { list.innerHTML = '<p style="padding:16px;font-size:12px;color:var(--text-muted);text-align:center;">Failed to load</p>'; }
}

// ── Loyalty ───────────────────────────────────────────────────────────────────
async function onCustomerChange() {
    const cid = document.getElementById('customerSelect').value;
    // Reset loyalty state whenever customer changes
    loyaltyInfo     = null;
    loyaltyDiscount = 0;
    document.getElementById('loyaltyPanel').style.display    = 'none';
    document.getElementById('loyaltyToggle').checked         = false;
    document.getElementById('loyaltyRedeemRow').style.display = 'none';
    document.getElementById('loyaltyPointsInput').value      = 0;
    document.getElementById('loyaltyDiscountRow').style.display = 'none';
    updateTotals();

    if (!cid) return;
    try {
        const res  = await fetch(`pos.php?api=loyalty&customer_id=${cid}`);
        const data = await res.json();
        if (!data.active || data.balance <= 0) return;
        loyaltyInfo = data;
        document.getElementById('loyaltyBalance').textContent = data.balance + ' pts';
        document.getElementById('loyaltyHint').textContent    =
            `Min ${data.min_pts} pts · each point = $${parseFloat(data.redeem_rate).toFixed(2)} · max ${data.max_pct}% of order`;
        document.getElementById('loyaltyPointsInput').max    = data.balance;
        document.getElementById('loyaltyPanel').style.display = 'block';
    } catch(e) { /* loyalty unavailable — fail silently */ }
}

function toggleLoyaltyRedeem() {
    const on  = document.getElementById('loyaltyToggle').checked;
    document.getElementById('loyaltyRedeemRow').style.display = on ? 'block' : 'none';
    if (!on) {
        loyaltyDiscount = 0;
        document.getElementById('loyaltyPointsInput').value = 0;
    }
    updateTotals();
}

function updateLoyaltyDiscount() {
    if (!loyaltyInfo) return;
    const pts      = Math.min(parseInt(document.getElementById('loyaltyPointsInput').value || 0), loyaltyInfo.balance);
    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const couponD  = discountInfo ? discountInfo.amount : 0;
    const taxable  = subtotal - couponD;
    const tax      = taxable * (TAX_RATE / 100);
    const preTotal = taxable + tax;
    const maxDisc  = preTotal * (loyaltyInfo.max_pct / 100);
    let disc       = pts * loyaltyInfo.redeem_rate;
    if (disc > maxDisc) disc = maxDisc;
    loyaltyDiscount = disc;
    document.getElementById('loyaltyDiscountLabel').textContent = '$' + disc.toFixed(2) + ' off';
    updateTotals();
}

function applyMaxLoyalty() {
    if (!loyaltyInfo) return;
    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const couponD  = discountInfo ? discountInfo.amount : 0;
    const taxable  = subtotal - couponD;
    const tax      = taxable * (TAX_RATE / 100);
    const preTotal = taxable + tax;
    const maxDisc  = preTotal * (loyaltyInfo.max_pct / 100);
    const maxPts   = Math.min(loyaltyInfo.balance, Math.floor(maxDisc / loyaltyInfo.redeem_rate));
    document.getElementById('loyaltyPointsInput').value = maxPts;
    updateLoyaltyDiscount();
}

// ── Barcode scanner ────────────────────────────────────────────────────────────
function handleGlobalKey(e) {
    if (document.getElementById('receiptModal').classList.contains('active')) return;
    if (document.getElementById('numpadModal').classList.contains('active')) {
        if (e.key >= '0' && e.key <= '9') numpadPress(e.key);
        if (e.key === '.') numpadPress('.');
        if (e.key === 'Backspace') numpadPress('back');
        if (e.key === 'Enter') numpadConfirm();
        return;
    }

    const tag = e.target.tagName, id = e.target.id;
    if ((tag === 'INPUT' && id !== 'posSearch') || tag === 'SELECT' || tag === 'TEXTAREA') return;

    if (e.key === 'Enter') {
        e.preventDefault();
        if (barcodeBuffer.length >= 3) {
            const p   = allProducts.find(x => x.barcode_number === barcodeBuffer || x.sku_code === barcodeBuffer);
            const statusEl = document.getElementById('barcodeStatus');
            if (p) {
                addToCart(p.id);
                statusEl.style.color       = 'var(--success-color)';
                statusEl.style.borderColor = 'var(--success-color)';
                statusEl.innerHTML = `<svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Scanned!`;
            } else {
                statusEl.style.color       = '#ef4444';
                statusEl.style.borderColor = '#ef4444';
                statusEl.innerHTML = `<svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Not found`;
            }
            setTimeout(() => {
                statusEl.style.color       = 'var(--text-muted)';
                statusEl.style.borderColor = 'var(--border-color)';
                statusEl.innerHTML = `<svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2"><line x1="3" y1="5" x2="3" y2="19"/><line x1="7" y1="5" x2="7" y2="19"/><line x1="11" y1="5" x2="11" y2="19"/><line x1="15" y1="5" x2="15" y2="19"/><line x1="19" y1="5" x2="19" y2="19"/></svg> Ready`;
            }, 1800);
        }
        barcodeBuffer = '';
        document.getElementById('posSearch').value = '';
        currentSearch = ''; applyFilters();
    } else if (e.key.length === 1) {
        barcodeBuffer += e.key;
        clearTimeout(barcodeTimer);
        barcodeTimer = setTimeout(() => barcodeBuffer = '', 80);
    }
}

// ── Keyboard shortcuts ─────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'F2')   { e.preventDefault(); document.getElementById('posSearch').focus(); }
    if (e.key === 'Escape') {
        ['receiptModal','numpadModal','heldModal'].forEach(id => document.getElementById(id).classList.remove('active'));
        document.getElementById('recentPanel').style.display = 'none';
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !isProcessing && cart.length) { e.preventDefault(); processCheckout(); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Backspace') { e.preventDefault(); clearCart(); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'h') { e.preventDefault(); holdOrder(); }
});
</script>

<?php include 'includes/footer.php'; ?>
