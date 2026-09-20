<?php
$host = "localhost";
$username = "root";
$password = "";
$dbname = "agile-inventory-system-5";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── CSRF Helpers ──────────────────────────────────────────────────────────────
// Call csrf_token() inside any form to get a hidden input.
// Call verify_csrf() at the top of any POST handler to abort on mismatch.
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

function verify_csrf() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('403 Forbidden: CSRF token mismatch.');
    }
}

// Global Activity Log Helper
function log_activity($conn, $action_type, $entity_type, $entity_id, $details = null)
{
    if (!isset($_SESSION['user_id']))
        return false;
    $user_id = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare(
        "INSERT INTO activity_logs (user_id, action_type, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issss", $user_id, $action_type, $entity_type, $entity_id, $details);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Global Reference String Generator
// Generates strings like INV-0001, BILL-0042 using insert_id after the fact
// Usage: generate_ref($prefix, $id) e.g. generate_ref('INV', 7) => 'INV-0007'
function generate_ref($prefix, $id)
{
    return $prefix . '-' . str_pad((int) $id, 4, '0', STR_PAD_LEFT);
}

// ── Global Status Badge Helper ────────────────────────────────────────────────
// Maps every status string to a canonical lowercase CSS slug so all
// case/spacing variants resolve to a single well-styled class.
function statusBadge($status) {
    if ($status === null || $status === '') return '<span style="color:var(--text-muted)">—</span>';

    // Normalise input: lowercase + strip spaces/hyphens = lookup key
    $key = strtolower(preg_replace('/[\s\-]+/', '', $status));

    $slugMap = [
        // ── Shipment ─────────────────────────────────
        'shipped'       => 'completed',
        'intransit'     => 'inprogress',
        // ── Supplier Credit ──────────────────────────
        'used'          => 'completed',
        // ── Payment methods ──────────────────────────
        'cash'          => 'completed',
        'banktransfer'  => 'inprogress',
        'creditcard'    => 'active',
        'cheque'        => 'pending',
        // ── Pending / awaiting ──────────────────────
        'pending'       => 'pending',
        // ── Active / open / positive-flow ───────────
        'active'        => 'active',
        'available'     => 'active',
        'open'          => 'active',
        'issued'        => 'active',
        'sent'          => 'active',
        'onduty'        => 'active',
        // ── In-progress / partial ────────────────────
        'inprogress'    => 'inprogress',
        'transfer'      => 'inprogress',
        'partial'       => 'inprogress',
        'partiallypaid' => 'pending',
        'oncredit'      => 'inprogress',
        'onbreak'       => 'pending',
        // ── Completed / success ──────────────────────
        'completed'     => 'completed',
        'received'      => 'completed',
        'processed'     => 'completed',
        'paid'          => 'completed',
        'fullypaid'     => 'completed',
        'healthy'       => 'completed',
        // ── Cancelled / danger ───────────────────────
        'cancelled'     => 'cancelled',
        'rejected'      => 'cancelled',
        'overdue'       => 'cancelled',
        'outofstock'    => 'cancelled',
        'offduty'       => 'cancelled',
        // ── Draft / inactive (amber + slate, not grey)
        'draft'         => 'draft',
        'inactive'      => 'inactive',
        'closed'        => 'inactive',
        'deleted'       => 'inactive',
        // ── Low / warning ────────────────────────────
        'low'           => 'low',
        'lowstock'      => 'low',
        'warning'       => 'low',
    ];

    $slug = $slugMap[$key] ?? $key;
    return '<span class="status-badge status-' . htmlspecialchars($slug) . '">'
         . htmlspecialchars($status) . '</span>';
}

// ── Currency Helper ───────────────────────────────────────────────────────────
// Returns ['symbol'=>'₹','code'=>'INR'] (or whatever is saved in system_settings).
// Falls back to INR if the table / row doesn't exist yet.
function get_currency($conn) {
    $defaults = ['symbol' => '₹', 'code' => 'INR'];
    try {
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('currency_symbol','currency_code')");
    } catch (\mysqli_sql_exception $e) { return $defaults; }
    if (!$res) return $defaults;
    while ($row = $res->fetch_assoc()) {
        if ($row['setting_key'] === 'currency_symbol') $defaults['symbol'] = $row['setting_value'];
        if ($row['setting_key'] === 'currency_code')   $defaults['code']   = $row['setting_value'];
    }
    return $defaults;
}

// ── Business Settings Helper ──────────────────────────────────────────────────
// Returns an associative array of all business_settings rows.
// Falls back to hardcoded defaults if the table doesn't exist yet.
function get_business_settings($conn) {
    $defaults = [
        'company_name'    => 'Agile Inventory, Inc.',
        'company_address' => '123 Business Avenue',
        'company_city'    => 'Tech City',
        'company_state'   => 'Maharashtra',
        'company_pincode' => '400001',
        'company_country' => 'India',
        'company_phone'   => '+91 98765 43210',
        'company_email'   => 'admin@agileinventory.com',
        'company_website' => 'www.agileinventory.com',
        'company_tax_id'  => '00XXXXXX1234X0XX',
        'invoice_notes'   => 'Please pay within 15 days of receiving this invoice.',
    ];
    try {
        $res = $conn->query("SELECT setting_key, setting_value FROM business_settings");
    } catch (\mysqli_sql_exception $e) {
        // Table doesn't exist yet — return defaults until it is created
        return $defaults;
    }
    if (!$res) return $defaults;
    while ($row = $res->fetch_assoc()) {
        $defaults[$row['setting_key']] = $row['setting_value'];
    }
    return $defaults;
}

// Global Pagination Helper
function renderPagination($total_items, $limit, $current_page)
{
    $limit = max(1, (int) $limit);
    $total_pages = ceil($total_items / $limit);
    if ($total_pages <= 1)
        return;

    $prev = max(1, $current_page - 1);
    $next = min($total_pages, $current_page + 1);

    // Preserve existing GET parameters except 'page'
    $query_args = $_GET;
    unset($query_args['page']);
    $query_string = http_build_query($query_args);
    $base_url = '?' . ($query_string ? $query_string . '&' : '') . 'page=';

    echo '<div class="pagination">';

    // Per-page selector
    $per_page_opts = [10, 25, 50, 100];
    $sel_html = '<select class="per-page-select" onchange="const u=new URLSearchParams(window.location.search);u.set(\'limit\',this.value);u.delete(\'page\');window.location.search=u.toString();">';
    foreach ($per_page_opts as $opt) {
        $selected = ($opt == $limit) ? ' selected' : '';
        $sel_html .= '<option value="' . $opt . '"' . $selected . '>' . $opt . ' / page</option>';
    }
    $sel_html .= '</select>';
    echo $sel_html;

    // Info
    $from = min(($current_page - 1) * $limit + 1, $total_items);
    $to   = min($current_page * $limit, $total_items);
    echo '<span class="pagination-info">Showing ' . $from . '–' . $to . ' of ' . $total_items . '</span>';

    // Previous Button
    $dis_prev = ($current_page <= 1) ? 'disabled style="pointer-events:none; opacity:0.5;"' : '';
    echo '<a href="' . $base_url . $prev . '" class="btn btn-outline" style="padding: 6px 12px; border-radius:6px; font-size: 13px;" ' . $dis_prev . '>&larr; Previous</a>';

    echo '<div class="page-numbers">';

    // Smart page numbers display (show up to 5 surrounding numbers)
    $start_p = max(1, $current_page - 2);
    $end_p = min($total_pages, $current_page + 2);

    if ($start_p > 1) {
        echo '<a href="' . $base_url . '1" class="page-num ' . (1 == $current_page ? 'active' : '') . '">1</a>';
        if ($start_p > 2)
            echo '<span class="page-num" style="cursor:default;">...</span>';
    }

    for ($i = $start_p; $i <= $end_p; $i++) {
        $active = ($i == $current_page) ? 'active' : '';
        echo '<a href="' . $base_url . $i . '" class="page-num ' . $active . '">' . $i . '</a>';
    }

    if ($end_p < $total_pages) {
        if ($end_p < $total_pages - 1)
            echo '<span class="page-num" style="cursor:default;">...</span>';
        echo '<a href="' . $base_url . $total_pages . '" class="page-num ' . (($total_pages == $current_page) ? 'active' : '') . '">' . $total_pages . '</a>';
    }

    echo '</div>';

    // Next Button
    $dis_next = ($current_page >= $total_pages) ? 'disabled style="pointer-events:none; opacity:0.5;"' : '';
    echo '<a href="' . $base_url . $next . '" class="btn btn-outline" style="padding: 6px 12px; border-radius:6px; font-size: 13px;" ' . $dis_next . '>Next &rarr;</a>';

    echo '</div>';
}
?>