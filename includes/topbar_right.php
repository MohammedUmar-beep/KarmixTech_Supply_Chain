<?php
// Ensure variables are set
$user_name = $user_name ?? $_SESSION['name'] ?? 'User';
$user_role = $user_role ?? $_SESSION['role'] ?? 'Admin';

// Fetch Notifications
$notif_res = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");
$notifications = [];
$unread_count = 0;
if ($notif_res) {
    while ($row = $notif_res->fetch_assoc()) {
        $notifications[] = $row;
    }
}
$total_unread_res = $conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
if ($total_unread_res) {
    $unread_count = $total_unread_res->fetch_row()[0];
}
?>
<!-- Topbar Centre Search (rendered before topbar-right, floats to centre via CSS) -->
<div class="topbar-centre">
    <div class="global-search-container" id="globalSearchWrap">
        <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="2" fill="none"
            style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;z-index:1;">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <div id="gsSpinner" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);z-index:1;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" style="animation:gsSpin .7s linear infinite;">
                <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9"/>
            </svg>
        </div>
        <input type="text" id="globalSearchInput" placeholder="Search products, orders, customers…" autocomplete="off" class="global-search-input">
        <div id="globalSearchResults" class="global-search-results"></div>
    </div>
</div>

<div class="topbar-right" style="display: flex; align-items: center; gap: 20px;">
    <style>
    @keyframes gsSpin { to { transform: rotate(360deg); } }
    .topbar-centre {
        position:absolute; left:50%; transform:translateX(-50%);
        width:360px;
    }
    .global-search-container { position:relative; width:100%; }
    .global-search-input {
        width:100%; padding:8px 36px 8px 36px; border:1px solid var(--border-color);
        border-radius:8px; background:var(--input-bg); color:var(--text-dark);
        font-size:13px; outline:none; transition:border-color .2s, box-shadow .2s;
        box-sizing:border-box;
    }
    .global-search-input:focus {
        border-color:var(--primary-color);
        box-shadow:0 0 0 3px rgba(109,74,255,.1);
    }
    .global-search-results {
        display:none; position:absolute; top:calc(100% + 8px); left:50%;
        transform:translateX(-50%);
        width:520px;
        background:var(--card-bg); border:1px solid var(--border-color);
        border-radius:12px; box-shadow:0 20px 40px -8px rgba(0,0,0,.18);
        max-height:520px; overflow-y:auto; z-index:9999;
    }
    .gs-header {
        padding:10px 16px 8px; border-bottom:1px solid var(--border-color);
        display:flex; align-items:center; justify-content:space-between;
    }
    .gs-header-label {
        font-size:11px; font-weight:700; text-transform:uppercase;
        letter-spacing:.06em; color:var(--text-muted);
    }
    .gs-count {
        font-size:11px; color:var(--text-muted);
        background:var(--bg-light); padding:2px 8px; border-radius:20px;
    }
    .gs-group-label {
        padding:6px 16px 4px; font-size:10px; font-weight:700;
        text-transform:uppercase; letter-spacing:.08em;
        color:var(--text-muted); background:var(--bg-light);
        border-bottom:1px solid var(--border-color);
        border-top:1px solid var(--border-color);
        position:sticky; top:0; z-index:1;
    }
    .gs-item {
        display:flex; align-items:center; padding:10px 16px; gap:12px;
        text-decoration:none; border-bottom:1px solid var(--border-color);
        transition:background .12s; cursor:pointer;
    }
    .gs-item:last-child { border-bottom:none; }
    .gs-item:hover, .gs-item.active { background:var(--bg-light); }
    .gs-dot {
        width:34px; height:34px; border-radius:8px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
    }
    .gs-dot svg { width:16px; height:16px; }
    .gs-body { flex:1; min-width:0; }
    .gs-title {
        font-size:13px; font-weight:600; color:var(--text-dark);
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .gs-sub {
        font-size:11px; color:var(--text-muted); margin-top:2px;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .gs-badge {
        font-size:10px; font-weight:700; padding:2px 7px;
        border-radius:20px; white-space:nowrap; flex-shrink:0;
    }
    .gs-empty {
        padding:32px 20px; text-align:center;
        color:var(--text-muted); font-size:13px;
    }
    .gs-empty svg { margin-bottom:8px; stroke:var(--text-muted); }
    .gs-footer {
        padding:10px 16px; border-top:1px solid var(--border-color);
        font-size:11px; color:var(--text-muted);
        display:flex; align-items:center; gap:12px;
    }
    .gs-kbd {
        display:inline-flex; align-items:center; gap:4px;
        padding:1px 6px; background:var(--bg-light);
        border:1px solid var(--border-color); border-radius:4px;
        font-size:10px; font-weight:600; color:var(--text-muted);
    }
    [data-theme="dark"] .global-search-results { box-shadow:0 20px 40px -8px rgba(0,0,0,.5); }
    </style>
    <script>
    (function() {
        const input   = document.getElementById('globalSearchInput');
        const results = document.getElementById('globalSearchResults');
        const spinner = document.getElementById('gsSpinner');
        const wrap    = document.getElementById('globalSearchWrap');
        let timer, activeIdx = -1, items = [];

        // SVG icons per type
        const icons = {
            product:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
            customer: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            supplier: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
            order:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
            purchase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
            invoice:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            return:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-5.39"/></svg>',
            employee: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            warehouse:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            credit:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
            coupon:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            category: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            shipment: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'
        };

        function highlight(text, q) {
            if (!q) return escHtml(text);
            const esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return escHtml(text).replace(new RegExp('(' + esc + ')', 'gi'), '<mark style="background:rgba(109,74,255,.18);color:inherit;border-radius:2px;">$1</mark>');
        }
        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function render(data, q) {
            if (!data.length) {
                results.innerHTML = '<div class="gs-empty"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><div>No results for <strong>"' + escHtml(q) + '"</strong></div><div style="font-size:11px;margin-top:4px;">Try a different name, ID, SKU, or email</div></div>';
                results.style.display = 'block';
                return;
            }

            // Group by type
            const groups = {};
            data.forEach(item => {
                if (!groups[item.type]) groups[item.type] = [];
                groups[item.type].push(item);
            });

            let html = '<div class="gs-header"><span class="gs-header-label">Search Results</span><span class="gs-count">' + data.length + ' found</span></div>';
            items = [];

            Object.entries(groups).forEach(([type, rows]) => {
                html += '<div class="gs-group-label">' + escHtml(type) + 's</div>';
                rows.forEach(item => {
                    const idx = items.length;
                    items.push(item.url);
                    const dotBg = item.color + '22';
                    const iconSvg = icons[item.icon] || icons['product'];
                    const svgColored = iconSvg.replace('stroke="currentColor"', 'stroke="' + item.color + '"');
                    const badgeHtml = item.badge
                        ? '<span class="gs-badge" style="background:' + item.badge_color + '22;color:' + item.badge_color + '">' + escHtml(item.badge) + '</span>'
                        : '';
                    html += '<a class="gs-item" data-idx="' + idx + '" href="' + escHtml(item.url) + '">' +
                        '<div class="gs-dot" style="background:' + dotBg + '">' + svgColored + '</div>' +
                        '<div class="gs-body">' +
                            '<div class="gs-title">' + highlight(item.title, q) + '</div>' +
                            '<div class="gs-sub">' + highlight(item.subtitle, q) + '</div>' +
                        '</div>' +
                        badgeHtml + '</a>';
                });
            });

            html += '<div class="gs-footer"><span><span class="gs-kbd">↑↓</span> navigate</span><span><span class="gs-kbd">Enter</span> open</span><span><span class="gs-kbd">Esc</span> close</span></div>';
            results.innerHTML = html;
            results.style.display = 'block';
            activeIdx = -1;
        }

        function setActive(idx) {
            const els = results.querySelectorAll('.gs-item');
            els.forEach(el => el.classList.remove('active'));
            if (idx >= 0 && idx < els.length) {
                els[idx].classList.add('active');
                els[idx].scrollIntoView({ block:'nearest' });
            }
            activeIdx = idx;
        }

        function doSearch(q) {
            if (q.length < 2) { close(); return; }
            spinner.style.display = 'block';
            fetch('global_search_ajax.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => { spinner.style.display = 'none'; render(data, q); })
                .catch(() => { spinner.style.display = 'none'; });
        }

        function close() {
            results.style.display = 'none';
            results.innerHTML = '';
            items = []; activeIdx = -1;
        }

        input.addEventListener('input', function() {
            clearTimeout(timer);
            const q = this.value.trim();
            if (!q) { close(); return; }
            timer = setTimeout(() => doSearch(q), 280);
        });

        input.addEventListener('keydown', function(e) {
            const els = results.querySelectorAll('.gs-item');
            if (e.key === 'ArrowDown')  { e.preventDefault(); setActive(Math.min(activeIdx + 1, els.length - 1)); }
            if (e.key === 'ArrowUp')    { e.preventDefault(); setActive(Math.max(activeIdx - 1, 0)); }
            if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) { window.location.href = items[activeIdx]; }
            if (e.key === 'Escape')     { close(); input.blur(); }
        });

        input.addEventListener('focus', function() {
            if (this.value.trim().length >= 2 && !results.innerHTML) doSearch(this.value.trim());
            else if (results.innerHTML) results.style.display = 'block';
        });

        document.addEventListener('click', function(e) {
            if (!wrap.contains(e.target)) close();
        });
    })();
    </script>

    <!-- Theme Toggle -->
    <button id="themeToggleBtn"
        style="position: relative; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; color: var(--text-dark); background: transparent; border: none; cursor: pointer; overflow: hidden; transition: opacity 0.2s;">
        <svg id="themeSun" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round"
            style="position: absolute; transition: all 0.3s cubic-bezier(0.34,1.56,0.64,1);">
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
        <svg id="themeMoon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            style="position: absolute; transition: all 0.3s cubic-bezier(0.34,1.56,0.64,1);">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
    </button>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const btn = document.getElementById('themeToggleBtn');
            const sun = document.getElementById('themeSun');
            const moon = document.getElementById('themeMoon');

            function updateThemeUI(theme) {
                if (theme === 'dark') {
                    sun.style.transform = 'scale(0.5) translateY(20px)';
                    sun.style.opacity = '0';
                    moon.style.transform = 'scale(1) translateY(0)';
                    moon.style.opacity = '1';
                } else {
                    sun.style.transform = 'scale(1) translateY(0)';
                    sun.style.opacity = '1';
                    moon.style.transform = 'scale(0.5) translateY(20px)';
                    moon.style.opacity = '0';
                }
            }

            let currentTheme = localStorage.getItem('agile_theme') || 'light';
            updateThemeUI(currentTheme);

            btn.addEventListener('click', () => {
                currentTheme = currentTheme === 'light' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', currentTheme);
                localStorage.setItem('agile_theme', currentTheme);
                updateThemeUI(currentTheme);

                const savedColor = localStorage.getItem('agile_color') || '#6d4aff';
                if (savedColor === 'monochrome') {
                    if (currentTheme === 'dark') {
                        document.documentElement.style.setProperty('--primary-color', '#ffffff');
                        document.documentElement.style.setProperty('--primary-hover', '#e5e5e5');
                    } else {
                        document.documentElement.style.setProperty('--primary-color', '#000000');
                        document.documentElement.style.setProperty('--primary-hover', '#333333');
                    }
                }
            });
        });
    </script>

    <!-- Notification Icon & Dropdown -->
    <div class="notification-trigger" onclick="toggleNotificationDropdown(event)"
        style="position:relative; display:flex; align-items:center; cursor:pointer;">
        <svg class="topbar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round" style="width:24px; height:24px; color:var(--text-dark);">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <?php if ($unread_count > 0): ?>
            <span
                style="position:absolute; top:-4px; right:-4px; background:var(--danger-color); color:white; font-size:10px; font-weight:bold; width:16px; height:16px; display:flex; align-items:center; justify-content:center; border-radius:50%; border:2px solid var(--white);"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
        <?php endif; ?>

        <!-- Notification Dropdown -->
        <div class="notification-dropdown" id="notificationDropdown"
            style="display:none; position:absolute; top: 150%; right:-10px; background:var(--white); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); z-index: 1000; width: 320px; padding: 0; overflow:hidden;">
            <div
                style="padding: 16px; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:16px; color:var(--text-dark);">Notifications</h3>
                <?php if ($unread_count > 0): ?>
                    <span style="font-size:12px; color:var(--primary-color); cursor:pointer; font-weight:500;"
                        onclick="markNotificationsRead(event)">Mark all read</span>
                <?php endif; ?>
            </div>
            <div style="max-height: 300px; overflow-y:auto; overflow-x:hidden;">
                <?php if (empty($notifications)): ?>
                    <div style="padding: 20px; text-align:center; color:var(--text-muted); font-size:14px;">No notifications
                        yet.</div>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                        <a href="<?= $n['link'] ? htmlspecialchars($n['link']) : '#' ?>"
                            style="display:block; padding: 12px 16px; border-bottom: 1px solid var(--border-color); text-decoration:none; background: <?= $n['is_read'] ? 'var(--white)' : 'var(--bg-light)' ?>;">
                            <div style="font-size:13px; color:var(--text-dark); margin-bottom:4px;">
                                <?= htmlspecialchars($n['message']) ?>
                            </div>
                            <div style="font-size:11px; color:var(--text-muted);">
                                <?= date('M d, H:i', strtotime($n['created_at'])) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <a href="notifications.php"
                style="display:block; padding: 12px; text-align:center; font-size:13px; color:var(--primary-color); text-decoration:none; font-weight:500; background:var(--bg-light);">View
                All Notifications</a>
        </div>
    </div>

    <!-- User Profile Dropdown -->
    <div class="user-profile dropdown-trigger" onclick="toggleProfileDropdown(event)"
        style="display:flex; align-items:center; gap: 12px; position:relative; cursor:pointer; padding-left: 10px;">
        <?php
        if (!isset($_SESSION['profile_picture'])) {
            $uid = $_SESSION['user_id'] ?? 0;
            if ($uid) {
                $ures = $conn->query("SELECT profile_picture FROM users WHERE id = $uid");
                if ($ures && $urow = $ures->fetch_assoc()) {
                    $_SESSION['profile_picture'] = $urow['profile_picture'];
                }
            }
        }
        $user_pic = !empty($_SESSION['profile_picture']) ? $_SESSION['profile_picture'] : "https://ui-avatars.com/api/?name=" . urlencode($user_name) . "&background=00ffff&color=000&bold=true";
        ?>
        <img src="<?= htmlspecialchars($user_pic) ?>" alt="User"
            style="width:40px; height:40px; border-radius:50%; object-fit: cover;">
        <div class="user-info" style="display:flex; flex-direction:column; justify-content:center;">
            <span class="user-name" style="font-weight:600; font-size:15px; color:var(--text-dark);">
                <?= htmlspecialchars($user_name) ?>
            </span>
            <span class="user-role" style="font-size:13px; color:var(--text-muted); margin-top:-2px;">
                <?= htmlspecialchars(ucfirst($user_role)) ?>
            </span>
        </div>

        <!-- Dropdown Menu -->
        <div class="profile-dropdown" id="profileDropdown"
            style="display:none; position:absolute; top: 120%; right:0; background:var(--white); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 1000; min-width: 180px; padding: 8px 0;">
            <a href="profile.php"
                style="display:flex; align-items:center; gap:10px; padding: 10px 16px; color: var(--text-dark); text-decoration:none; font-size:14px; font-weight:500;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                Profile
            </a>
            <a href="settings.php"
                style="display:flex; align-items:center; gap:10px; padding: 10px 16px; color: var(--text-dark); text-decoration:none; font-size:14px; font-weight:500;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path
                        d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z">
                    </path>
                </svg>
                Settings
            </a>
            <hr style="border:none; border-top: 1px solid var(--border-color); margin: 8px 0;">
            <a href="logout.php" class="logout-dropdown-link"
                style="display:flex; align-items:center; gap:10px; padding: 10px 16px; text-decoration:none; font-size:14px; font-weight:500;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Logout
            </a>
        </div>
    </div>
</div>

<style>
    .profile-dropdown a:hover,
    .notification-dropdown a:hover {
        background-color: var(--bg-light) !important;
    }
</style>

<script>
    function toggleProfileDropdown(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('profileDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';

        const notifDrop = document.getElementById('notificationDropdown');
        if (notifDrop) notifDrop.style.display = 'none';
    }

    function toggleNotificationDropdown(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';

        const profileDrop = document.getElementById('profileDropdown');
        if (profileDrop) profileDrop.style.display = 'none';
    }

    function markNotificationsRead(e) {
        e.stopPropagation();
        fetch('ajax_mark_notifications_read.php', { method: 'POST' })
            .then(res => res.text())
            .then(() => {
                location.reload();
            });
    }

    document.addEventListener('click', function (e) {
        const profileDrop = document.getElementById('profileDropdown');
        if (profileDrop && profileDrop.style.display === 'block') {
            profileDrop.style.display = 'none';
        }

        const notifDrop = document.getElementById('notificationDropdown');
        if (notifDrop && notifDrop.style.display === 'block') {
            notifDrop.style.display = 'none';
        }
    });
</script>