<?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

$page_title = 'Activity Logs';

// Pagination and Filtering
$limit = 20;
$limit = isset($_GET["limit"]) && in_array((int)$_GET["limit"],[10,25,50,100]) ? (int)$_GET["limit"] : 10;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$where_clauses = ["1=1"];
if (!empty($_GET['action_type'])) {
    $safe_action = $conn->real_escape_string($_GET['action_type']);
    $where_clauses[] = "al.action_type = '$safe_action'";
}
if (!empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $where_clauses[] = "(al.entity_type LIKE '%$search%' OR al.entity_id LIKE '%$search%' OR al.details LIKE '%$search%' OR u.name LIKE '%$search%')";
}
$where_sql = implode(" AND ", $where_clauses);

$total_query = "SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $where_sql";
$total_res = $conn->query($total_query);
$total_logs = $total_res ? $total_res->fetch_row()[0] : 0;

$logs_query = "SELECT al.*, u.name as user_name 
               FROM activity_logs al 
               LEFT JOIN users u ON al.user_id = u.id 
               WHERE $where_sql 
               ORDER BY al.created_at DESC 
               LIMIT $limit OFFSET $offset";
$logs_res = $conn->query($logs_query);

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Activity Logs</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="data-table-container" data-no-filter-panel="1">
        <div class="table-header-controls" style="margin-bottom: 20px;">
            <form method="GET" style="display:flex; gap: 12px; align-items:center; width:100%;">
                <select name="action_type" class="status-filter" style="width:150px;" onchange="this.form.submit()">
                    <option value="">All Actions</option>
                    <option value="Create" <?= (isset($_GET['action_type']) && $_GET['action_type'] === 'Create') ? 'selected' : '' ?>>Create</option>
                    <option value="Update" <?= (isset($_GET['action_type']) && $_GET['action_type'] === 'Update') ? 'selected' : '' ?>>Update</option>
                    <option value="Delete" <?= (isset($_GET['action_type']) && $_GET['action_type'] === 'Delete') ? 'selected' : '' ?>>Delete</option>
                    <option value="Complete" <?= (isset($_GET['action_type']) && $_GET['action_type'] === 'Complete') ? 'selected' : '' ?>>Complete</option>
                    <option value="Cancel" <?= (isset($_GET['action_type']) && $_GET['action_type'] === 'Cancel') ? 'selected' : '' ?>>Cancel</option>
                    <option value="System" <?= (isset($_GET['action_type']) && $_GET['action_type'] === 'System') ? 'selected' : '' ?>>System</option>
                </select>
                <div class="search-box" style="max-width:300px; margin-bottom: 0;">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" name="search" placeholder="Search logs..."
                        value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <a href="activity_logs.php" class="btn btn-outline"
                    style="padding: 8px 16px; text-decoration:none;">Reset</a>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="cb-col"><input type="checkbox" class="select-all-cb"></th>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs_res && $logs_res->num_rows > 0): ?>
                    <?php while ($row = $logs_res->fetch_assoc()): ?>
                        <tr data-row-id="<?= $row['id'] ?>" data-row-table="activity_logs">
                            <td style="width:30px;padding-right:0"><button class="row-expand-btn" title="View details">+</button></td>
                            <td style="color:var(--text-muted); width:180px;">
                                <?= date('M d, Y H:i:s', strtotime($row['created_at'])) ?>
                            </td>
                            <td style="font-weight:500;">
                                <?= htmlspecialchars($row['user_name'] ?? 'System') ?>
                            </td>
                            <td>
                                <?php
                                $action_color = 'var(--text-dark)';
                                $action_bg = 'var(--bg-light)';
                                if (strtolower($row['action_type']) === 'create') {
                                    $action_color = '#10b981';
                                    $action_bg = 'rgba(16,185,129,0.1)';
                                } elseif (strtolower($row['action_type']) === 'update') {
                                    $action_color = '#3b82f6';
                                    $action_bg = 'rgba(59,130,246,0.1)';
                                } elseif (strtolower($row['action_type']) === 'delete') {
                                    $action_color = '#ef4444';
                                    $action_bg = 'rgba(239,68,68,0.1)';
                                } elseif (strtolower($row['action_type']) === 'cancel') {
                                    $action_color = '#f59e0b';
                                    $action_bg = 'rgba(245,158,11,0.1)';
                                } elseif (strtolower($row['action_type']) === 'system') {
                                    $action_color = '#8b5cf6';
                                    $action_bg = 'rgba(139,92,246,0.1)';
                                }
                                ?>
                                <span
                                    style="background:<?= $action_bg ?>; color:<?= $action_color ?>; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600; display:inline-block;">
                                    <?= htmlspecialchars($row['action_type']) ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color:var(--primary-color);">
                                    <?= htmlspecialchars($row['entity_type']) ?>
                                </strong>
                                <?php if ($row['entity_id']): ?>
                                    <span style="font-size:12px; color:var(--text-muted);">#
                                        <?= htmlspecialchars($row['entity_id']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-dark);">
                                <?= htmlspecialchars($row['details']) ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state-row"><td colspan="6"><div class="empty-state"><svg viewBox="0 0 24 24" width="56" height="56" stroke="currentColor" fill="none" stroke-width="1.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><div class="empty-state-title">No Activity Yet</div><div class="empty-state-desc">User actions and system events will appear here once recorded.</div></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_logs > 0): ?>
            <?php renderPagination($total_logs, $limit, $page); ?>
        <?php endif; ?>
    </div>
</main>
</div>

<?php include 'includes/footer.php'; ?>