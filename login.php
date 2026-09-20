<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Agile Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('agile_theme') || 'light';
            const savedColor = localStorage.getItem('agile_color') || '#6d4aff';
            document.documentElement.setAttribute('data-theme', savedTheme);
            document.documentElement.style.setProperty('--primary-color', savedColor);
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
        }

        [data-theme="dark"] {
            --bg-body: #020617;
            --text-dark: #f8fafc;
            --text-muted: #94a3b8;
            --glass: rgba(15, 23, 42, 0.7);
            --glass-border: rgba(255, 255, 255, 0.05);
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-dark);
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Animated Background Gradients (consistent with landing page) */
        .bg-glow {
            position: fixed;
            width: 60vw;
            height: 60vw;
            border-radius: 50%;
            background: radial-gradient(circle, var(--primary-color), transparent 70%);
            filter: blur(100px);
            opacity: 0.1;
            z-index: -1;
            animation: pulse 15s infinite alternate;
        }

        @keyframes pulse {
            from { transform: translate(-10%, -10%) scale(1); }
            to { transform: translate(10%, 10%) scale(1.1); }
        }

        /* Login Card */
        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            padding: 50px;
            box-shadow: var(--shadow);
            z-index: 10;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 35px;
        }

        .logo-box img { height: 44px; width: 44px; object-fit: contain; }
        .logo-box-text { display: flex; flex-direction: column; line-height: 1.15; }
        .logo-box-text strong { font-weight: 700; font-size: 18px; letter-spacing: -0.3px; color: var(--primary-color); }
        .logo-box-text span { font-size: 10px; font-weight: 500; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted); margin-top: 1px; }

        .header-text { text-align: center; margin-bottom: 35px; }
        .header-text h2 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .header-text p { color: var(--text-muted); font-size: 15px; }

        /* Form Controls */
        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; opacity: 0.8; }
        
        .form-input {
            width: 100%;
            padding: 14px 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            color: var(--text-dark);
            font-size: 15px;
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-input:focus {
            border-color: var(--primary-color);
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 0 0 4px rgba(109, 74, 255, 0.1);
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            font-size: 14px;
        }


        .forgot-link { color: var(--primary-color); text-decoration: none; font-weight: 600; }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 25px rgba(109, 74, 255, 0.25);
            margin-bottom: 20px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(109, 74, 255, 0.35);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 25px 0;
            color: var(--text-muted);
            font-size: 13px;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--glass-border);
        }

        .divider:not(:empty)::before { margin-right: 15px; }
        .divider:not(:empty)::after { margin-left: 15px; }

        .btn-google {
            width: 100%;
            padding: 14px;
            background: transparent;
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: var(--text-dark);
            font-weight: 601;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-google:hover { background: rgba(255, 255, 255, 0.05); }

        .signup-text { text-align: center; margin-top: 30px; font-size: 14px; color: var(--text-muted); }
        .signup-text a { color: var(--primary-color); text-decoration: none; font-weight: 700; }

        .error-toast {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 12px;
            border-radius: 12px;
            border: 1px solid rgba(239, 68, 68, 0.2);
            font-size: 14px;
            text-align: center;
            margin-bottom: 25px;
            display: none;
        }

        <?php if (isset($_GET['error'])): ?>
        .error-toast { display: block; animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
        <?php
endif; ?>

        @keyframes shake {
            10%, 90% { transform: translate3d(-1px, 0, 0); }
            20%, 80% { transform: translate3d(2px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-4px, 0, 0); }
            40%, 60% { transform: translate3d(4px, 0, 0); }
        }

        /* Back to site button */
        .back-btn {
            position: fixed;
            top: 40px;
            left: 40px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .back-btn:hover { color: var(--primary-color); transform: translateX(-5px); }
    </style>
</head>

<body>
    <div class="bg-glow" style="top: -10%; left: -10%;"></div>
    <div class="bg-glow" style="bottom: -10%; right: -10%; animation-delay: -7s;"></div>

    <a href="index.php" class="back-btn">&larr; Back to landing</a>

    <div class="login-card">
        <div class="logo-box">
            <img src="assets/images/logo.png" alt="Agile Logo">
            <div class="logo-box-text">
                <strong>Agile Inventory</strong>
                <span>System</span>
            </div>
        </div>

        <div class="header-text">
            <h2>Welcome Back</h2>
            <p>Access your dashboard and manage stock.</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
        <div class="error-toast">
            Invalid email or password. Please try again.
        </div>
        <?php
endif; ?>

        <form action="auth.php" method="POST">
            <div class="form-group">
                <label for="email">Work Email</label>
                <input type="email" id="email" name="email" class="form-input" placeholder="name@company.com" 
                       required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required>
            </div>

            <div class="form-footer">
                <span></span>
                <a href="forgot_password.php" class="forgot-link">Forgot?</a>
            </div>

            <button type="submit" class="btn-login">Sign In</button>

            <div class="divider">or continue with</div>

            <button type="button" class="btn-google">
                <svg viewBox="0 0 24 24" width="20" height="20" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                </svg>
                Google
            </button>
        </form>

        <p class="signup-text">
            Don't have an account? <a href="#" onclick="event.preventDefault(); customAlert('Please contact your administrator to create an account.');">Create one</a>
        </p>
    </div>

    <!-- Simple alert modal for login page -->
    <div id="loginAlertOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--glass,#fff);border:1px solid var(--glass-border,#ddd);padding:28px;border-radius:14px;max-width:360px;width:90%;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,0.2);">
            <p id="loginAlertMsg" style="margin:0 0 20px;color:var(--text-dark,#0f172a);font-size:15px;line-height:1.5;"></p>
            <button onclick="document.getElementById('loginAlertOverlay').style.display='none';" style="padding:10px 28px;border-radius:8px;border:none;background:var(--primary-color,#6d4aff);color:#fff;font-size:14px;font-weight:600;cursor:pointer;">OK</button>
        </div>
    </div>
    <script>
        function customAlert(msg) {
            document.getElementById('loginAlertMsg').textContent = msg;
            document.getElementById('loginAlertOverlay').style.display = 'flex';
        }
    </script>
</body>

</html>