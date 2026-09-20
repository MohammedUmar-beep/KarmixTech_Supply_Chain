<?php
session_start();
// Already logged in? Go to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
require_once 'includes/db.php';

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email       = trim($_POST['email']       ?? '');
    $old_pass    = $_POST['old_password']     ?? '';
    $new_pass    = $_POST['new_password']     ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    if (empty($email) || empty($old_pass) || empty($new_pass) || empty($confirm)) {
        $error = 'All fields are required.';
    } elseif ($new_pass !== $confirm) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_pass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if ($old_pass === $user['password']) {
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->bind_param("si", $new_pass, $user['id']);
                if ($upd->execute()) {
                    $success = true;
                } else {
                    $error = 'Failed to update password. Please try again.';
                }
                $upd->close();
            } else {
                $error = 'Current password is incorrect.';
            }
        } else {
            $error = 'No account found with that email address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Agile Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script>
        (function () {
            const t = localStorage.getItem('agile_theme') || 'light';
            const c = localStorage.getItem('agile_color') || '#6d4aff';
            document.documentElement.setAttribute('data-theme', t);
            document.documentElement.style.setProperty('--primary-color', c);
        })();
    </script>
    <style>
        :root {
            --primary-color: #6d4aff;
            --bg-body: #f8fafc;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.05);
            --danger: #ef4444;
            --success: #10b981;
        }
        [data-theme="dark"] {
            --bg-body: #020617;
            --text-dark: #f8fafc;
            --text-muted: #94a3b8;
            --glass: rgba(15, 23, 42, 0.7);
            --glass-border: rgba(255, 255, 255, 0.05);
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body {
            background-color: var(--bg-body);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .bg-glow {
            position: fixed;
            width: 60vw; height: 60vw;
            border-radius: 50%;
            background: radial-gradient(circle, var(--primary-color), transparent 70%);
            filter: blur(100px);
            opacity: 0.08;
            z-index: -1;
            animation: pulse 15s infinite alternate;
        }
        @keyframes pulse {
            from { transform: translate(-10%, -10%) scale(1); }
            to   { transform: translate(10%, 10%) scale(1.1); }
        }
        .back-btn {
            position: fixed; top: 40px; left: 40px;
            color: var(--text-muted); text-decoration: none;
            font-size: 14px; font-weight: 600;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.3s;
        }
        .back-btn:hover { color: var(--primary-color); transform: translateX(-5px); }
        .card {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }
        .logo-box {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 28px;
        }
        .logo-box img { width: 40px; height: 40px; object-fit: contain; }
        .logo-box strong { font-size: 16px; font-weight: 700; color: var(--text-dark); }
        .logo-box span   { font-size: 12px; color: var(--text-muted); }
        .logo-box-text   { display: flex; flex-direction: column; }
        h2 { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
        .subtitle { font-size: 14px; color: var(--text-muted); margin-bottom: 28px; line-height: 1.5; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text-dark); }
        .form-input {
            width: 100%; padding: 12px 14px;
            border: 1.5px solid var(--glass-border);
            border-radius: 10px;
            background: transparent;
            color: var(--text-dark);
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
            outline: none;
        }
        .form-input:focus { border-color: var(--primary-color); box-shadow: 0 0 0 4px rgba(109,74,255,0.1); }
        .btn-submit {
            width: 100%; padding: 13px;
            background: var(--primary-color);
            color: #fff; border: none;
            border-radius: 10px;
            font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
            margin-top: 6px;
        }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); }
        .alert {
            padding: 12px 16px; border-radius: 10px;
            font-size: 14px; margin-bottom: 20px; line-height: 1.5;
        }
        .alert-error   { background: rgba(239,68,68,0.1);   color: var(--danger);  border: 1px solid rgba(239,68,68,0.2);  }
        .alert-success { background: rgba(16,185,129,0.1);  color: var(--success); border: 1px solid rgba(16,185,129,0.2); }
        .back-to-login { text-align: center; margin-top: 22px; font-size: 14px; color: var(--text-muted); }
        .back-to-login a { color: var(--primary-color); text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="bg-glow" style="top:-10%;left:-10%;"></div>
    <div class="bg-glow" style="bottom:-10%;right:-10%;animation-delay:-7s;"></div>

    <a href="login.php" class="back-btn">&larr; Back to Login</a>

    <div class="card">
        <div class="logo-box">
            <img src="assets/images/logo.png" alt="Agile Logo">
            <div class="logo-box-text">
                <strong>Agile Inventory</strong>
                <span>System</span>
            </div>
        </div>

        <?php if ($success): ?>
            <h2>Password Updated</h2>
            <p class="subtitle">Your password has been changed successfully.</p>
            <div class="alert alert-success">✓ You can now log in with your new password.</div>
            <a href="login.php" class="btn-submit" style="display:block;text-align:center;text-decoration:none;">Go to Login</a>
        <?php else: ?>
            <h2>Reset Password</h2>
            <p class="subtitle">Enter your email and current password to set a new one.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="forgot_password.php">
                <div class="form-group">
                    <label for="email">Work Email</label>
                    <input type="email" id="email" name="email" class="form-input"
                           placeholder="name@company.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="old_password">Current Password</label>
                    <input type="password" id="old_password" name="old_password"
                           class="form-input" placeholder="••••••••" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           class="form-input" placeholder="Min. 6 characters" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-input" placeholder="Repeat new password" required>
                </div>
                <button type="submit" class="btn-submit">Update Password</button>
            </form>

            <div class="back-to-login">
                Remembered it? <a href="login.php">Sign in</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
