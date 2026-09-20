<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';

if (!isset($_GET['id'])) {
    header("Location: warehouses.php");
    exit();
}

$warehouse_id_val = intval($_GET['id']);
$wh_res = $conn->query("SELECT * FROM warehouses WHERE id = $warehouse_id_val");
if (!$wh_res || $wh_res->num_rows == 0) {
    header("Location: warehouses.php");
    exit();
}
$warehouse = $wh_res->fetch_assoc();
$warehouse_name = $conn->real_escape_string($warehouse['warehouse_name']);

// Fetch Products in this warehouse
$products_res = $conn->query("SELECT * FROM products WHERE warehouse_id = $warehouse_id_val ORDER BY id DESC");

// Fetch Employees in this warehouse
$employees_res = $conn->query("SELECT * FROM employees WHERE warehouse_id = '$warehouse_name' OR warehouse_id = '$warehouse_id_val' ORDER BY id DESC");

$current_page = 'warehouses.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($warehouse['warehouse_name']) ?> - Warehouse View</title>
        <script>
        (function () {
            var t = localStorage.getItem('agile_theme') || 'light';
            var c = localStorage.getItem('agile_color') || '#6d4aff';
            document.documentElement.setAttribute('data-theme', t);
            if (c === 'monochrome') {
                document.documentElement.style.setProperty('--primary-color', t === 'dark' ? '#ffffff' : '#000000');
                document.documentElement.style.setProperty('--primary-hover', t === 'dark' ? '#e5e5e5' : '#333333');
            } else {
                document.documentElement.style.setProperty('--primary-color', c);
                if (c === '#1E5EFF') document.documentElement.style.setProperty('--primary-hover', '#1648c9');
                else if (c === '#336DFF') document.documentElement.style.setProperty('--primary-hover', '#2452c7');
            }
        })();
    </script><link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>
    <div class="app-container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-area">
            <div class="topbar">
                <div class="topbar-left">
                    <a href="warehouses.php" style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Back to Warehouses</a>
                    <h1><?= htmlspecialchars($warehouse['warehouse_name']) ?> <span style="font-size:16px; color:var(--text-muted); font-weight:normal;">(<?= htmlspecialchars($warehouse['warehouse_id_str']) ?>)</span></h1>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <?php
                    $viewer_role = strtolower($_SESSION['role'] ?? 'staff');
                    if (in_array($viewer_role, ['admin', 'manager'])): ?>
                        <a href="add_stock_transfer.php?from_warehouse_id=<?= $warehouse_id_val ?>"
                            class="btn btn-primary"
                            style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px; text-decoration:none; color:#fff; font-weight:500; font-size:14px;">
                            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="17 1 21 5 17 9"></polyline>
                                <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                                <polyline points="7 23 3 19 7 15"></polyline>
                                <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                            </svg>
                            Internal Stock Transfer
                        </a>
                    <?php endif; ?>
                    <?php include 'includes/topbar_right.php'; ?>
                </div>
            </div>

            <div class="page-metrics-grid">
                <div class="page-metric-card primary-bg">
                    <div class="page-metric-title">Capacity</div>
                    <div class="page-metric-value"><?= number_format($warehouse['capacity']) ?></div>
                </div>
                <div class="page-metric-card">
                    <div class="page-metric-title">Products Stored</div>
                    <div class="page-metric-value"><?= $products_res->num_rows ?></div>
                </div>
                <div class="page-metric-card">
                    <div class="page-metric-title">Assigned Staff</div>
                    <div class="page-metric-value"><?= $employees_res->num_rows ?></div>
                </div>
            </div>

            <h2 style="font-size:18px; margin-bottom:16px;">Products in <?= htmlspecialchars($warehouse['warehouse_name']) ?></h2>
            <div class="data-table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th>Stock Level</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($products_res && $products_res->num_rows > 0): ?>
                            <?php while ($p = $products_res->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight:500;"><?= htmlspecialchars($p['product_name']) ?></td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?= htmlspecialchars($p['sku_code']) ?></td>
                                    <td><?= htmlspecialchars($p['category']) ?></td>
                                    <td><?= number_format($p['stock_level']) ?></td>
                                    <td>$<?= number_format($p['selling_price'], 2) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:30px;">No products stored here.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h2 style="font-size:18px; margin-bottom:16px; margin-top:30px;">Staff in <?= htmlspecialchars($warehouse['warehouse_name']) ?></h2>
            <div class="data-table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($employees_res && $employees_res->num_rows > 0): ?>
                            <?php while ($e = $employees_res->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight:500;"><?= htmlspecialchars($e['full_name']) ?></td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?= htmlspecialchars($e['email']) ?></td>
                                    <td><?= htmlspecialchars($e['phone']) ?></td>
                                    <td><?= statusBadge($e['status']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding:30px;">No staff assigned here.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
<?php include 'includes/footer.php'; ?>
