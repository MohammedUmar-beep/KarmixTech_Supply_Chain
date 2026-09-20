<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

if (strtolower($user_role) !== 'admin') {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h2>Access Denied</h2><p>You do not have permission to view the audit logs.</p><a href='dashboard.php' style='color:#4f46e5; text-decoration:none;'>Return to Dashboard</a></div>");
}

$limit = 20;
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"],[10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_items_res = $conn->query("SELECT COUNT(*) FROM audit_logs");
$total_items = $total_items_res ? $total_items_res->fetch_row()[0] : 0;

$search_sql = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = $conn->real_escape_string(trim($_GET['search']));
    $col = $_GET['search_col'] ?? 'user_name';
    $allowed_cols = ['user_name', 'action_type', 'entity_type', 'entity_id', 'details'];
    if (in_array($col, $allowed_cols)) {
        $search_sql = "WHERE $col LIKE '%$search%'";
    }
}

// Adjust total_items if searching
if ($search_sql) {
    $total_items_search_res = $conn->query("SELECT COUNT(*) FROM audit_logs $search_sql");
    $total_items = $total_items_search_res ? $total_items_search_res->fetch_row()[0] : 0;
}

$query = "SELECT * FROM audit_logs $search_sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$logs = $conn->query($query);

$current_page = 'audit_logs.php';
$page_title = 'Activity Audit Logs';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="settings.php"
                style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Settings
            </a>
            <h1>System Activity Logs</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-header-controls">
            <?php if (isset($_GET['search'])): ?>
                <a href="audit_logs.php" class="btn btn-outline" style="margin-left:8px; padding:0 12px;">Clear</a>
            <?php endif; ?>
        </div>

        
            <table>
                <thead>
                    <tr>
                        <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th style="width: 15%;">Timestamp</th>
                        <th style="width: 15%;">User</th>
                        <th style="width: 10%;">Action</th>
                        <th style="width: 15%;">Module & ID</th>
                        <th style="width: 45%;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while ($row = $logs->fetch_assoc()):

                            $action_color = match (strtolower($row['action_type'])) {
                                'create' => 'var(--success-color)',
                                'update' => 'var(--primary-color)',
                                'delete' => 'var(--danger-color)',
                                'login' => '#8b5cf6',
                                default => 'var(--text-muted)'
                            };

                            ?>
                            <tr data-row-id="<?= $row['id'] ?>" data-row-table="audit_logs">
                                <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                                <td style="color: var(--text-muted); font-size: 13px;">
                                    <?= date('M d, Y h:iA', strtotime($row['created_at'])) ?>
                                </td>
                                <td style="font-weight: 500; color: var(--text-dark);">
                                    <?= htmlspecialchars($row['user_name']) ?>
                                    <span style="display:block; font-size:11px; color:var(--text-muted);">ID:
                                        <?= $row['user_id'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span
                                        style="font-weight:600; font-size:12px; text-transform:uppercase; color: <?= $action_color ?>;">
                                        <?= htmlspecialchars($row['action_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color:var(--text-dark);">
                                        <?= htmlspecialchars($row['entity_type']) ?>
                                    </strong>
                                    <span style="display:block; font-size:12px; color:var(--primary-color);">
                                        <?= htmlspecialchars($row['entity_id']) ?>
                                    </span>
                                </td>
                                <td style="color: var(--text-muted); line-height:1.4;">
                                    <?= htmlspecialchars($row['details']) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-state-row"><td colspan="5"><div class="empty-state"><svg viewBox="0 0 24 24" width="52" height="52" stroke="currentColor" fill="none" stroke-width="1.2"><circle cx="12" cy="12" r="10"/><path d="M8 15s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg><div class="empty-state-title">No records found</div><div class="empty-state-desc">Try adjusting your search or filters.</div></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php renderPagination($total_items, $limit, $page); ?>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
