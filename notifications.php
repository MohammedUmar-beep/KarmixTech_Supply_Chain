<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

// Handle Actions (Delete / Mark All Read)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'delete' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $conn->query("DELETE FROM notifications WHERE id = $id");
    } elseif ($action == 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    }
    header("Location: notifications.php");
    exit();
}

// Pagination Setup
$limit = 15;
$page = isset($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch Total For Pagination
$total_res = $conn->query("SELECT COUNT(*) FROM notifications");
$total_items = $total_res ? (int) $total_res->fetch_row()[0] : 0;

$query = "SELECT * FROM notifications ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$notifications_result = $conn->query($query); // Renamed to avoid collision with topbar_right.php array

$current_page = 'notifications.php';
$page_title = 'Notifications';
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Notifications</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="data-table-container" style="margin-top: 20px;">
        <div class="table-header-controls" style="display:flex; justify-content:space-between; margin-bottom:15px;">
            <div></div> <!-- Spacer -->
            <?php if ($total_items > 0): ?>
                <a href="notifications.php?action=mark_all_read" class="btn btn-outline"
                    style="border-color:var(--primary-color); color:var(--primary-color);">Mark All as Read</a>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">Status</th>
                        <th>Message</th>
                        <th>Date & Time</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($notifications_result && $notifications_result->num_rows > 0): ?>
                        <?php while ($row = $notifications_result->fetch_assoc()): ?>
                            <tr style="background: <?= $row['is_read'] ? 'transparent' : 'var(--bg-light)' ?>;">
                                <td style="text-align:center;">
                                    <?php if (!$row['is_read']): ?>
                                        <div
                                            style="width:10px; height:10px; background:var(--primary-color); border-radius:50%; margin:0 auto;">
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['link']) && $row['link'] !== '#'): ?>
                                        <a href="<?= htmlspecialchars($row['link']) ?>"
                                            style="color:var(--text-dark); text-decoration:none; font-weight:500;">
                                            <?= htmlspecialchars($row['message']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="font-weight:500; color:var(--text-dark);">
                                            <?= htmlspecialchars($row['message']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>
                                </td>
                                <td style="text-align: right;">
                                    <div class="action-btn-group" style="justify-content: flex-end;">
                                        <a href="notifications.php?action=delete&id=<?= $row['id'] ?>" class="btn-icon delete"
                                            onclick="event.preventDefault(); showCustomConfirm('Delete this notification?', () => { window.location.href = this.href || this.getAttribute('href'); });" title="Delete">
                                            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                <path
                                                    d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                </path>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 20px;">No notifications found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_items > $limit): ?>
            <div class="pagination" style="margin-top: 20px; display:flex; gap:5px;">
                <?php
                $total_pages = ceil($total_items / $limit);
                for ($i = 1; $i <= $total_pages; $i++):
                    ?>
                    <a href="notifications.php?page=<?= $i ?>" class="page-link <?= ($page == $i) ? 'active' : '' ?>"
                        style="padding: 6px 12px; border:1px solid var(--border-color); border-radius:6px; text-decoration:none; color: <?= ($page == $i) ? 'white' : 'var(--text-dark)' ?>; background: <?= ($page == $i) ? 'var(--primary-color)' : 'transparent' ?>;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
