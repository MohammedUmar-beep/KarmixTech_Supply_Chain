<?php
require_once 'includes/auth_guard.php';

// Handle Status Updates 
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];

    // Security check to ensure transfer exists
    $transfer_res = $conn->query("SELECT * FROM stock_transfers WHERE id = $id");
    if ($transfer_res && $transfer_res->num_rows > 0) {
        $transfer = $transfer_res->fetch_assoc();
        $current_status = $transfer['status'];
        $product_id = intval($transfer['product_id']);
        $qty = intval($transfer['quantity']);
        $from_wh = intval($transfer['from_warehouse_id']);
        $to_wh = intval($transfer['to_warehouse_id']);

        if ($action === 'complete' && $current_status !== 'Completed' && $current_status !== 'Cancelled') {
            // Transaction logic: Deduct from source, Add to destination
            $conn->begin_transaction();
            try {
                // 1. Deduct from Source Product
                $conn->query("UPDATE products SET stock_level = stock_level - $qty WHERE id = $product_id");

                // 2. Fetch the Source Product to duplicate it if necessary
                $src_res = $conn->query("SELECT * FROM products WHERE id = $product_id");
                $src_product = $src_res->fetch_assoc();
                $sku = $src_product['sku_code'];

                // 3. Search for existing product with same SKU in Destination Warehouse
                $dest_res = $conn->query("SELECT id FROM products WHERE sku_code = '$sku' AND warehouse_id = $to_wh");

                if ($dest_res && $dest_res->num_rows > 0) {
                    // exists in destination -> increment stock
                    $dest_id = $dest_res->fetch_assoc()['id'];
                    $conn->query("UPDATE products SET stock_level = stock_level + $qty WHERE id = $dest_id");
                } else {
                    // doesn't exist -> duplicate it
                    // Generate new Product ID string
                    $next_id_res = $conn->query("SELECT MAX(id) FROM products");
                    $next_id = $next_id_res ? (intval($next_id_res->fetch_row()[0]) + 1) : 1;
                    $new_prod_id = 'PROD-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

                    $stmt = $conn->prepare("
                        INSERT INTO products (
                            product_name, product_id_str, warehouse_id, supplier_id, category, 
                            sku_code, barcode_number, weight, stock_level, warning_threshold, 
                            purchasing_price, selling_price, selling_price_margin, dimensions, dimension_unit, 
                            grn_number, description, image_path, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "ssissssdiiddsssssss",
                        $src_product['product_name'],
                        $new_prod_id,
                        $to_wh,
                        $src_product['supplier_id'],
                        $src_product['category'],
                        $sku,
                        $src_product['barcode_number'],
                        $src_product['weight'],
                        $qty,
                        $src_product['warning_threshold'],
                        $src_product['purchasing_price'],
                        $src_product['selling_price'],
                        $src_product['selling_price_margin'],
                        $src_product['dimensions'],
                        $src_product['dimension_unit'],
                        $src_product['grn_number'],
                        $src_product['description'],
                        $src_product['image_path'],
                        $src_product['status']
                    );
                    $stmt->execute();
                }

                $conn->query("UPDATE stock_transfers SET status = 'Completed' WHERE id = $id");
                log_activity($conn, 'Complete', 'Stock Transfer', $transfer['transfer_ref'], "Completed stock transfer of $qty units.");
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
            }
        } elseif ($action === 'cancel' && $current_status !== 'Completed') {
            $conn->query("UPDATE stock_transfers SET status = 'Cancelled' WHERE id = $id");
            log_activity($conn, 'Cancel', 'Stock Transfer', $transfer['transfer_ref'], "Cancelled transfer.");
        }
    }
    header("Location: stock_transfers.php");
    exit();
}

$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"], [10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'] ?? 'st.transfer_ref';
    $allowed_cols = [
        'st.transfer_ref' => 'st.transfer_ref',
        'p.product_name'  => 'p.product_name',
        'wf.warehouse_name' => 'wf.warehouse_name',
        'wt.warehouse_name' => 'wt.warehouse_name'
    ];
    if (array_key_exists($col, $allowed_cols)) {
        $db_col = $allowed_cols[$col];
        $search_sql = "AND $db_col LIKE '%$search%'";
    }
}

// Global Filter for Status
$filter_sql = '';
if (isset($_GET['status']) && $_GET['status'] != '') {
    $status = $conn->real_escape_string($_GET['status']);
    $filter_sql = "AND st.status = '$status'";
}

$count_query = "SELECT COUNT(*) FROM stock_transfers st 
                JOIN products p ON st.product_id = p.id 
                JOIN warehouses wf ON st.from_warehouse_id = wf.id 
                JOIN warehouses wt ON st.to_warehouse_id = wt.id 
                WHERE 1=1 $search_sql $filter_sql";

$total_res = $conn->query($count_query);
$total_items = $total_res ? $total_res->fetch_row()[0] : 0;

$query = "SELECT st.*, p.product_name, wf.warehouse_name as from_warehouse, wt.warehouse_name as to_warehouse, u.name as creator_name
          FROM stock_transfers st
          JOIN products p ON st.product_id = p.id
          JOIN warehouses wf ON st.from_warehouse_id = wf.id
          JOIN warehouses wt ON st.to_warehouse_id = wt.id
          JOIN users u ON st.creator_id = u.id
          WHERE 1=1 $search_sql $filter_sql
          ORDER BY st.transfer_date DESC, st.created_at DESC
          LIMIT $limit OFFSET $offset";
$result = $conn->query($query);

// Count per status for pill tabs
$st_count_all       = (int)$conn->query("SELECT COUNT(*) FROM stock_transfers")->fetch_row()[0];
$st_count_pending   = (int)$conn->query("SELECT COUNT(*) FROM stock_transfers WHERE status='Pending'")->fetch_row()[0];
$st_count_transit   = (int)$conn->query("SELECT COUNT(*) FROM stock_transfers WHERE status='In Transit'")->fetch_row()[0];
$st_count_completed = (int)$conn->query("SELECT COUNT(*) FROM stock_transfers WHERE status='Completed'")->fetch_row()[0];
$st_count_cancelled = (int)$conn->query("SELECT COUNT(*) FROM stock_transfers WHERE status='Cancelled'")->fetch_row()[0];

$current_page = 'stock_transfers.php';
$page_title = 'Stock Transfers';
$extra_head = '';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Stock Transfers</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="table-header-controls">

        <?php
        $st_active = $_GET['status'] ?? '';
        $st_carry  = $_GET; unset($st_carry['status'], $st_carry['page']);
        $st_base   = '?' . ($st_carry ? http_build_query($st_carry) . '&' : '');
        ?>
        <div style="display:flex; gap:6px; align-items:center; background:var(--bg-light); border:1px solid var(--border-color); border-radius:10px; padding:4px;">
            <?php
            $st_tabs = ['' => 'All', 'Pending' => 'Pending', 'In Transit' => 'In Transit', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'];
            foreach ($st_tabs as $val => $label):
                $href = $val === '' ? 'stock_transfers.php' : $st_base . 'status=' . urlencode($val);
                $active = $st_active === $val; ?>
            <a href="<?= $href ?>" style="text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:7px; transition:all 0.15s; white-space:nowrap; <?= $active ? 'background:var(--primary-color); color:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.15);' : 'color:var(--text-muted); background:transparent;' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div style="flex:1;"></div>
        <div style="display:flex;align-items:center;gap:10px;">
        <a href="export.php?type=stock_transfers" class="btn btn-outline"
            style="display:flex; align-items:center; gap:8px; padding:8px 16px; height:38px; font-size:13px; font-weight:500; border-radius:8px; text-decoration:none;">
            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            Export Transfers
        </a>
        <a href="add_stock_transfer.php" class="btn btn-primary"
            style="display:flex; align-items:center; gap:8px; padding:8px 16px; height:38px; font-size:13px; font-weight:500; border-radius:8px; text-decoration:none; color:white;">
            <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            New Transfer
        </a>
        </div>
    </div>

    <div class="data-table-container">
        <div class="data-table-container">
            <table>
                <thead>
                    <tr>
                        <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Date</th>
                        <th>Transfer Ref</th>
                        <th>Product</th>
                        <th>From Warehouse</th>
                        <th>To Warehouse</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()):
                            $status_class = match (strtolower($row['status'])) {
                                'completed' => 'status-success',
                                'in transit' => 'status-primary',
                                'pending' => 'status-warning',
                                'cancelled' => 'status-danger',
                                default => 'status-pending'
                            };
                            ?>
                            <tr data-row-id="<?= $row['id'] ?>" data-row-table="stock_transfers">
                                <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                                <td style="color:var(--text-muted); font-size:13px;">
                                    <?= date('M d, Y', strtotime($row['transfer_date'])) ?>
                                </td>
                                <td style="font-weight: 500; color:var(--text-dark);">
                                    <?= htmlspecialchars($row['transfer_ref']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td><?= htmlspecialchars($row['from_warehouse']) ?></td>
                                <td><?= htmlspecialchars($row['to_warehouse']) ?></td>
                                <td style="font-weight: 600;"><?= number_format($row['quantity']) ?></td>
                                <td><?= statusBadge($row['status']) ?></td>
                                <td style="text-align: right;">
                                    <?php if ($row['status'] !== 'Completed' && $row['status'] !== 'Cancelled'): ?>
                                        <a href="?action=complete&id=<?= $row['id'] ?>" class="btn-primary"
                                            style="font-size:12px; padding:4px 8px; border-radius:4px; text-decoration:none;">Complete</a>
                                        <a href="?action=cancel&id=<?= $row['id'] ?>" class="btn-outline"
                                            style="font-size:12px; padding:4px 8px; border-radius:4px; text-decoration:none; margin-left:4px; border:1px solid var(--danger-color); color:var(--danger-color);">Cancel</a>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:12px; font-style:italic;">Closed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-state-row"><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg><div class="empty-state-title">No Stock Transfers Yet</div><div class="empty-state-desc">Move inventory between warehouses by creating a transfer.</div><a href="add_stock_transfer.php" class="btn btn-primary" style="text-decoration:none;color:#fff;margin-top:4px;">+ Add Transfer</a></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($total_items, $limit, $page); ?>
    </div>
</main>
<?php include 'includes/footer.php'; ?>