<?php
require_once 'includes/auth_guard.php';

$user_name = $_SESSION['name'] ?? 'User';
$user_role = ucfirst($_SESSION['role'] ?? 'staff');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$category = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $category = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$category) {
    header("Location: categories.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_id_str = $conn->real_escape_string($_POST['category_id_str']);
    $category_name = $conn->real_escape_string($_POST['category_name']);
    $description = $conn->real_escape_string($_POST['description']);

    $stmt = $conn->prepare("UPDATE categories SET category_id_str=?, category_name=?, description=? WHERE id=?");
    $stmt->bind_param("sssi", $category_id_str, $category_name, $description, $id);

    if ($stmt->execute()) {
        header("Location: categories.php?msg=updated");
        exit();
    } else {
        $error = "Error updating category.";
    }
    $stmt->close();
}

$current_page = 'categories.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Category - Agile Inventory</title>
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
    <style>
        .edit-form-container {
            background: var(--white);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            max-width: 500px;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-area">
            <div class="topbar">
                <div class="topbar-left">
                    <a href="categories.php"
                        style="color:var(--primary-color); font-size:14px; font-weight:600; margin-bottom:10px; display:flex; align-items:center; gap:6px; text-decoration:none;">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                        Back to Categories</a>
                    <h1>Edit Category</h1>
                </div>
                <?php include 'includes/topbar_right.php'; ?>
            </div>

            <div class="edit-form-container">
                <?php if (isset($error)): ?>
                        <div style="color: red; margin-bottom: 15px;">
                            <?= $error ?>
                        </div>
                <?php endif; ?>

                <form action="edit_category.php?id=<?= $id ?>" method="POST">
                    <div class="form-grid">
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <div>
                                <label class="form-label">Category Name</label>
                                <input type="text" name="category_name" class="form-input"
                                    value="<?= htmlspecialchars($category['category_name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Category ID</label>
                                <input type="text" name="category_id_str" class="form-input"
                                    value="<?= htmlspecialchars($category['category_id_str']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-input" rows="3"
                                    style="resize:vertical;"><?= htmlspecialchars($category['description']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 30px; display:flex; gap:12px;">
                        <button type="submit" class="btn btn-primary"
                            style="padding: 10px 24px; border-radius:8px; font-weight:500;">Save Changes</button>
                        <a href="categories.php" class="btn btn-outline"
                            style="padding: 10px 24px; border-radius:8px; font-weight:500; text-decoration:none;">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
<?php include 'includes/footer.php'; ?>
