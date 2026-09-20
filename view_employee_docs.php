<?php
require_once 'includes/auth_guard.php';

if (strtolower($_SESSION['role']) !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: employees.php'); exit(); }

$res = $conn->query("SELECT full_name, employee_id_str, document_paths, status FROM employees WHERE id = $id AND status != 'Deleted'");
if (!$res || $res->num_rows === 0) { header('Location: employees.php'); exit(); }
$emp = $res->fetch_assoc();

$docs = $emp['document_paths'] ? json_decode($emp['document_paths'], true) : [];

// Helper: detect file type
function getFileType($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) return 'image';
    if ($ext === 'pdf') return 'pdf';
    return 'file';
}

$current_page = 'employees.php';
$page_title   = 'Employee Documents — ' . htmlspecialchars($emp['full_name']);
include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="employees.php" style="color:var(--primary-color);font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px;text-decoration:none;">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                </svg>
                Back to Employees
            </a>
            <h1>Employee Documents</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <!-- Employee Info Strip -->
    <div style="background:var(--white); border:1px solid var(--border-color); border-radius:12px; padding:20px 24px; margin-bottom:24px; display:flex; align-items:center; gap:16px;">
        <div style="width:48px;height:48px;border-radius:50%;background:var(--primary-color);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;flex-shrink:0;">
            <?= strtoupper(substr($emp['full_name'], 0, 1)) ?>
        </div>
        <div>
            <div style="font-size:16px;font-weight:700;color:var(--text-dark);"><?= htmlspecialchars($emp['full_name']) ?></div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($emp['employee_id_str']) ?></div>
        </div>
        <div style="margin-left:auto;display:flex;gap:10px;align-items:center;">
            <a href="edit_employee.php?id=<?= $id ?>" class="btn btn-outline" style="text-decoration:none;font-size:13px;padding:7px 14px;display:flex;align-items:center;gap:6px;">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit Employee
            </a>
        </div>
    </div>

    <!-- Documents Section -->
    <div style="background:var(--white); border:1px solid var(--border-color); border-radius:12px; padding:28px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:22px;">
            <div>
                <h2 style="font-size:17px;font-weight:700;color:var(--text-dark);margin:0;">Uploaded Documents</h2>
                <div style="font-size:13px;color:var(--text-muted);margin-top:3px;"><?= count($docs) ?> document<?= count($docs) !== 1 ? 's' : '' ?> on file</div>
            </div>
        </div>

        <?php if (empty($docs)): ?>
        <!-- Empty state -->
        <div style="text-align:center; padding:60px 20px;">
            <svg viewBox="0 0 24 24" width="56" height="56" stroke="var(--text-muted)" fill="none" stroke-width="1.2" style="margin-bottom:16px;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
            <div style="font-size:16px;font-weight:600;color:var(--text-dark);margin-bottom:6px;">No Documents Found</div>
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">This employee has no documents uploaded yet.</div>
            <a href="edit_employee.php?id=<?= $id ?>" class="btn btn-primary" style="text-decoration:none;color:#fff;font-size:13px;padding:9px 20px;">
                Upload Documents
            </a>
        </div>

        <?php else: ?>
        <!-- Document grid -->
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap:16px;">
            <?php foreach ($docs as $i => $doc): 
                $type     = getFileType($doc);
                $filename = basename($doc);
                $ext      = strtoupper(pathinfo($doc, PATHINFO_EXTENSION));
            ?>
            <div style="border:1px solid var(--border-color); border-radius:10px; overflow:hidden; background:#fafafa; display:flex; flex-direction:column;">
                <!-- Preview area -->
                <div style="height:150px; background:#f4f5fa; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;">
                    <?php if ($type === 'image'): ?>
                        <img src="<?= htmlspecialchars($doc) ?>" alt="<?= htmlspecialchars($filename) ?>"
                            style="width:100%;height:100%;object-fit:cover;">
                    <?php elseif ($type === 'pdf'): ?>
                        <div style="text-align:center;">
                            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#ef4444" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                            <div style="font-size:11px;font-weight:700;color:#ef4444;margin-top:6px;">PDF</div>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center;">
                            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="var(--primary-color)" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <div style="font-size:11px;font-weight:700;color:var(--primary-color);margin-top:6px;"><?= $ext ?></div>
                        </div>
                    <?php endif; ?>
                    <!-- Badge -->
                    <div style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.55);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;">
                        <?= $ext ?>
                    </div>
                </div>
                <!-- File info -->
                <div style="padding:12px 14px; flex:1; display:flex; flex-direction:column; gap:8px;">
                    <div style="font-size:12px;font-weight:600;color:var(--text-dark);word-break:break-all;line-height:1.4;" title="<?= htmlspecialchars($filename) ?>">
                        <?= htmlspecialchars(strlen($filename) > 40 ? substr($filename, 0, 37) . '...' : $filename) ?>
                    </div>
                    <div style="display:flex; gap:8px; margin-top:auto;">
                        <a href="<?= htmlspecialchars($doc) ?>" target="_blank"
                            style="flex:1; text-align:center; padding:6px; background:var(--primary-color); color:#fff; border-radius:6px; font-size:12px; font-weight:600; text-decoration:none;">
                            View
                        </a>
                        <a href="<?= htmlspecialchars($doc) ?>" download
                            style="flex:1; text-align:center; padding:6px; background:#f4f5fa; color:var(--text-dark); border:1px solid var(--border-color); border-radius:6px; font-size:12px; font-weight:600; text-decoration:none;">
                            Download
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
