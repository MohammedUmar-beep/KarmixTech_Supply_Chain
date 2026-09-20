<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agile Inventory - Smart Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script>
        (function () {
            var t = localStorage.getItem('agile_theme') || 'light';
            var c = localStorage.getItem('agile_color') || '#6d4aff';
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
            --glass-border: rgba(109, 74, 255, 0.18);
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
        }

        [data-theme="dark"] {
            --bg-body: #020617;
            --text-dark: #f8fafc;
            --text-muted: #94a3b8;
            --glass: rgba(15, 23, 42, 0.7);
            --glass-border: rgba(109, 74, 255, 0.2);
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }

        body {
            background-color: var(--bg-body);
            color: var(--text-dark);
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: background-color 0.3s, color 0.3s;
        }

        /* Animated background glows */
        .bg-glow {
            position: fixed;
            width: 50vw;
            height: 50vw;
            border-radius: 50%;
            background: radial-gradient(circle, var(--primary-color), transparent 70%);
            filter: blur(100px);
            opacity: 0.07;
            z-index: -1;
            animation: pulse 10s infinite alternate;
        }

        @keyframes pulse {
            from { transform: translate(-10%, -10%) scale(1); }
            to   { transform: translate(10%, 10%) scale(1.2); }
        }

        /* Header */
        header {
            padding: 28px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
        }

        .logo { display: flex; align-items: center; gap: 12px; }
        .logo img { height: 46px; width: 46px; object-fit: contain; }
        .logo-text { display: flex; flex-direction: column; line-height: 1.15; }
        .logo-text strong { font-size: 18px; font-weight: 700; letter-spacing: -0.3px; color: var(--primary-color); }
        .logo-text span { font-size: 10px; font-weight: 500; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted); margin-top: 1px; }

        /* Header right controls */
        .header-right { display: flex; align-items: center; gap: 16px; }

        /* Theme toggle — exact same style as in-app */
        #themeToggleBtn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            color: var(--text-dark);
            background: var(--glass);
            border: 1px solid var(--glass-border);
            cursor: pointer;
            overflow: hidden;
            transition: opacity 0.2s, background 0.3s, border-color 0.3s;
            backdrop-filter: blur(10px);
        }

        #themeToggleBtn:hover { opacity: 0.75; }

        #themeToggleBtn svg {
            position: absolute;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Main layout */
        main {
            flex: 1;
            display: grid;
            grid-template-columns: 1.1fr 1.6fr;
            padding: 0 80px 40px;
            align-items: center;
            gap: 60px;
        }

        /* Hero text */
        .hero-text h1 {
            font-size: 4.2rem;
            line-height: 1.05;
            margin-bottom: 24px;
            font-weight: 700;
            letter-spacing: -1.5px;
        }

        .hero-text h1 .gradient-word {
            background: linear-gradient(135deg, var(--primary-color) 0%, #a78bfa 60%, #c4b5fd 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-text p {
            font-size: 1.15rem;
            color: var(--text-muted);
            margin-bottom: 40px;
            max-width: 480px;
            line-height: 1.7;
        }

        /* CTA buttons */
        .cta-group { display: flex; gap: 16px; }

        .btn {
            padding: 16px 32px;
            border-radius: 100px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 10px 30px rgba(109, 74, 255, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 40px rgba(109, 74, 255, 0.45);
        }

        .btn-outline {
            border: 1.5px solid var(--glass-border);
            color: var(--text-dark);
            background: var(--glass);
            backdrop-filter: blur(10px);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Visual container */
        .visual-container {
            position: relative;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dashboard-mockup {
            width: 100%;
            border-radius: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--glass-border);
            transform: perspective(1000px) rotateY(-10deg) rotateX(5deg);
            transition: transform 0.8s ease, opacity 0.4s ease;
            display: block;
        }

        /* Two images stacked — JS switches opacity */
        .mockup-wrap {
            position: relative;
            width: 100%;
        }

        .mockup-wrap img {
            width: 100%;
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            transform: perspective(1000px) rotateY(-10deg) rotateX(5deg);
            transition: transform 0.8s ease, opacity 0.4s ease;
            display: block;
        }

        .mockup-wrap img.mockup-dark {
            position: absolute;
            top: 0; left: 0;
        }

        .visual-container:hover .mockup-wrap img {
            transform: perspective(1000px) rotateY(0deg) rotateX(0deg);
        }

        /* Floating chips */
        .feature-chip {
            position: absolute;
            background: var(--glass);
            backdrop-filter: blur(15px);
            border: 1px solid var(--glass-border);
            padding: 16px 20px;
            border-radius: 18px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: float 5s infinite ease-in-out;
            z-index: 5;
            cursor: default;
            transition: background 0.3s, border-color 0.3s;
        }

        .chip-1 { top: 10%; right: -20px; animation-delay: 0s; }
        .chip-2 { bottom: 15%; left: -40px; animation-delay: 1.5s; }
        .chip-3 { top: 50%; right: 8%; animation-delay: 3s; }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-14px); }
        }

        .feature-chip .icon {
            width: 42px; height: 42px;
            background: var(--primary-color);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 18px;
            flex-shrink: 0;
        }

        .feature-chip .info b { font-size: 15px; display: block; color: var(--text-dark); }
        .feature-chip .info span { font-size: 11px; opacity: 0.65; font-weight: 500; color: var(--text-dark); }

        /* Footer */
        .footer-copy {
            position: fixed;
            bottom: 20px; left: 60px;
            font-size: 0.78rem;
            opacity: 0.35;
            pointer-events: none;
            z-index: 10;
            color: var(--text-dark);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            main { grid-template-columns: 1fr; padding: 40px; overflow-y: auto; height: auto; }
            body { overflow-y: auto; height: auto; }
            .hero-text h1 { font-size: 3.2rem; }
            .feature-chip { display: none; }
        }

        @media (max-width: 600px) {
            header { padding: 20px; }
            main { padding: 20px; }
            .hero-text h1 { font-size: 2.6rem; }
            .cta-group { flex-direction: column; }
        }
    </style>

    <!-- ── Intro animation styles ── -->
    <style>
        #intro-overlay {
            position: fixed;
            inset: 0;
            background: #0d0f18;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.7s ease 0.3s, visibility 0.7s ease 0.3s;
        }
        #intro-overlay.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .intro-card {
            width: 300px;
            height: 200px;
            background: #1a1d2a;
            position: relative;
            display: grid;
            place-content: center;
            border-radius: 10px;
            overflow: hidden;
        }
        .intro-border {
            position: absolute;
            inset: 0px;
            border: 2px solid #6d4aff;
            opacity: 0;
            transform: rotate(10deg);
            transition: all 0.5s ease-in-out;
            border-radius: 10px;
        }
        /* Row: fixed "A" + sliding reveal container */
        .intro-content {
            display: flex;
            align-items: flex-start;
        }
        .intro-first-letter {
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
            background: linear-gradient(135deg, #6d4aff 0%, #c084fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            flex-shrink: 0;
        }
        /* Clipping container — width expands to reveal text */
        .intro-logo {
            position: relative;
            width: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        }
        /* "gile" */
        .intro-logo-title {
            white-space: nowrap;
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
            background: linear-gradient(135deg, #6d4aff 0%, #c084fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        /* "Inventory System" */
        .intro-logo-sub {
            white-space: nowrap;
            font-family: 'Outfit', sans-serif;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            margin-top: 6px;
            color: #38bdf8;
        }
        .intro-trail {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 100%;
            opacity: 0;
            pointer-events: none;
        }
        /* Play state */
        .intro-card.intro-play .intro-logo {
            width: 162px;
            animation: intro-cursor 1s ease-in-out;
        }
        .intro-card.intro-play .intro-border {
            inset: 15px;
            opacity: 1;
            transform: rotate(0);
        }
        .intro-card.intro-play .intro-trail {
            animation: intro-trail 1s ease-in-out;
        }
        @keyframes intro-cursor {
            0%   { border-right: 2px solid transparent; }
            5%   { border-right: 2px solid #c084fc; }
            85%  { border-right: 2px solid #c084fc; }
            100% { border-right: 2px solid transparent; }
        }
        @keyframes intro-trail {
            0%   { background: linear-gradient(90deg, rgba(192,132,252,0) 80%, rgba(192,132,252,0.85) 100%); opacity: 0; }
            25%  { background: linear-gradient(90deg, rgba(192,132,252,0) 60%, rgba(192,132,252,0.85) 100%); opacity: 1; }
            75%  { background: linear-gradient(90deg, rgba(192,132,252,0) 60%, rgba(192,132,252,0.85) 100%); opacity: 1; }
            100% { background: linear-gradient(90deg, rgba(192,132,252,0) 80%, rgba(192,132,252,0.85) 100%); opacity: 0; }
        }
    </style>
</head>

<body>
    <!-- ── Intro animation overlay ── -->
    <div id="intro-overlay">
        <div class="intro-card" id="introCard">
            <div class="intro-border"></div>
            <div class="intro-content">
                <!-- "A" always visible -->
                <span class="intro-first-letter">A</span>
                <!-- rest slides in -->
                <div class="intro-logo">
                    <span class="intro-logo-title">gile</span>
                    <span class="intro-logo-sub">Inventory System</span>
                    <span class="intro-trail"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-glow" style="top: -10%; left: -10%;"></div>
    <div class="bg-glow" style="bottom: -10%; right: -10%; animation-delay: -5s;"></div>

    <header>
        <div class="logo">
            <img src="assets/images/logo.png" alt="Agile Logo">
            <div class="logo-text">
                <strong>Agile Inventory</strong>
                <span>System</span>
            </div>
        </div>

        <div class="header-right">
            <!-- Theme toggle — same sun/moon as in-app -->
            <button id="themeToggleBtn" title="Toggle theme">
                <svg id="themeSun" viewBox="0 0 24 24" width="19" height="19" stroke="currentColor" fill="none"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
                <svg id="themeMoon" viewBox="0 0 24 24" width="19" height="19" stroke="currentColor" fill="none"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </button>

            <a href="login.php" class="btn btn-outline" style="padding: 11px 26px; font-size: 0.95rem;">Sign In</a>
        </div>
    </header>

    <main>
        <div class="hero-text">
            <h1>Master your stock with <span class="gradient-word">intelligence.</span></h1>
            <p>The modern toolkit for high-performance inventory management. Track, scale, and optimize your entire supply chain from one screen.</p>

            <div class="cta-group">
                <a href="login.php" class="btn btn-primary">Start Managing Now</a>
                <a href="login.php" class="btn btn-outline">Watch Demo</a>
            </div>
        </div>

        <div class="visual-container">
            <div class="feature-chip chip-1">
                <div class="icon">📦</div>
                <div class="info"><b>12,480 Items</b><span>Total Inventory</span></div>
            </div>
            <div class="feature-chip chip-2">
                <div class="icon">📈</div>
                <div class="info"><b>+24.5% Growth</b><span>This month</span></div>
            </div>
            <div class="feature-chip chip-3">
                <div class="icon">🏢</div>
                <div class="info"><b>5 Warehouses</b><span>Synced Live</span></div>
            </div>

            <!-- Dashboard preview: two images, JS toggles visibility -->
            <div class="mockup-wrap" id="mockupWrap">
                <img id="mockupLight" src="assets/images/dashboard-light.png" alt="Dashboard Preview Light">
                <img id="mockupDark"  src="assets/images/dashboard-dark.png"  alt="Dashboard Preview Dark" class="mockup-dark">
            </div>
        </div>
    </main>

    <div class="footer-copy">&copy; 2024-2026 Agile Inventory Management System. All Rights Reserved.</div>

    <script>
        // ── Theme toggle ──────────────────────────────────────────────
        const btn   = document.getElementById('themeToggleBtn');
        const sun   = document.getElementById('themeSun');
        const moon  = document.getElementById('themeMoon');
        const light = document.getElementById('mockupLight');
        const dark  = document.getElementById('mockupDark');

        function updateUI(theme) {
            if (theme === 'dark') {
                sun.style.transform  = 'scale(0.5) translateY(20px)';
                sun.style.opacity    = '0';
                moon.style.transform = 'scale(1) translateY(0)';
                moon.style.opacity   = '1';
                // Show dark mockup
                light.style.opacity = '0';
                dark.style.opacity  = '1';
            } else {
                sun.style.transform  = 'scale(1) translateY(0)';
                sun.style.opacity    = '1';
                moon.style.transform = 'scale(0.5) translateY(20px)';
                moon.style.opacity   = '0';
                // Show light mockup
                light.style.opacity = '1';
                dark.style.opacity  = '0';
            }
        }

        let currentTheme = localStorage.getItem('agile_theme') || 'light';
        // Apply immediately (no transition flash)
        sun.style.transition  = 'none';
        moon.style.transition = 'none';
        light.style.transition = 'none';
        dark.style.transition  = 'none';
        updateUI(currentTheme);
        // Re-enable transitions after first paint
        requestAnimationFrame(() => {
            sun.style.transition   = 'all 0.3s cubic-bezier(0.34,1.56,0.64,1)';
            moon.style.transition  = 'all 0.3s cubic-bezier(0.34,1.56,0.64,1)';
            light.style.transition = 'opacity 0.4s ease';
            dark.style.transition  = 'opacity 0.4s ease';
        });

        btn.addEventListener('click', () => {
            currentTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', currentTheme);
            localStorage.setItem('agile_theme', currentTheme);
            updateUI(currentTheme);
        });

        // ── Mouse parallax on mockup ──────────────────────────────────
        document.addEventListener('mousemove', (e) => {
            if (window.innerWidth <= 1200) return;
            const x = (window.innerWidth  / 2 - e.pageX) / 50;
            const y = (window.innerHeight / 2 - e.pageY) / 50;
            document.querySelectorAll('.mockup-wrap img').forEach(img => {
                img.style.transform = `perspective(1000px) rotateY(${-10 + x}deg) rotateX(${5 + y}deg)`;
            });
        });
        // ── Intro animation ──────────────────────────────────────────
        (function () {
            const card    = document.getElementById('introCard');
            const overlay = document.getElementById('intro-overlay');

            // Trigger the expand animation after a short pause
            setTimeout(() => { card.classList.add('intro-play'); }, 400);

            // Fade out and remove the overlay
            setTimeout(() => { overlay.classList.add('hidden'); }, 2200);
            setTimeout(() => { overlay.style.display = 'none'; }, 3000);
        })();
    </script>
</body>
</html>
