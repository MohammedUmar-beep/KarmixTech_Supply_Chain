<?php
require_once 'includes/auth_guard.php';

$user_id = (int)$_SESSION['user_id'];
$toast   = '';

// ── Handle POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'profile') {
        $name  = $conn->real_escape_string(trim($_POST['name']  ?? ''));
        $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        // Role is NOT accepted from user input — only admins may change roles via employee management

        // Profile picture upload
        $pic_sql = '';
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg','jpeg','png','gif','webp'];
            $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_file = $upload_dir . uniqid('prof_') . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $new_file)) {
                    $new_file_esc = $conn->real_escape_string($new_file);
                    $pic_sql = ", profile_picture='$new_file_esc'";
                    $_SESSION['profile_picture'] = $new_file;
                }
            }
        }

        if ($pic_sql) {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, profile_picture=? WHERE id=?");
            $new_file_val = $new_file ?? '';
            $stmt->bind_param("sssi", $name, $email, $new_file_val, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?");
            $stmt->bind_param("ssi", $name, $email, $user_id);
        }

        if ($stmt->execute()) {
            $_SESSION['name'] = $name;
            $toast = 'profile_saved';
        } else {
            $toast = 'error';
        }

    } elseif ($action === 'password') {
        $current  = $_POST['current_password']  ?? '';
        $new_pw   = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $res = $conn->query("SELECT password FROM users WHERE id=$user_id");
        $row = $res->fetch_assoc();

        $pw_match = ($row['password'] === $current);

        if (!$pw_match) {
            $toast = 'wrong_password';
        } elseif (strlen($new_pw) < 6) {
            $toast = 'pw_too_short';
        } elseif ($new_pw !== $confirm) {
            $toast = 'pw_mismatch';
        } else {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed, $user_id);
            $toast = $stmt->execute() ? 'pw_saved' : 'error';
        }
    }
}

// ── Fetch user ────────────────────────────────────────────────────────
$user = $conn->query("SELECT * FROM users WHERE id=$user_id")->fetch_assoc();
$user_pic = !empty($user['profile_picture'])
    ? htmlspecialchars($user['profile_picture'])
    : "https://ui-avatars.com/api/?name=" . urlencode($user['name']) . "&background=6d4aff&color=fff&bold=true&size=200";

$member_since = date('F Y', strtotime($user['created_at']));
$role_labels  = ['admin' => 'Administrator', 'manager' => 'Manager', 'staff' => 'Staff'];
$role_label   = $role_labels[strtolower($user['role'])] ?? ucfirst($user['role']);
$role_colors  = ['admin' => '#6d4aff', 'manager' => '#3b82f6', 'staff' => '#10b981'];
$role_color   = $role_colors[strtolower($user['role'])] ?? 'var(--primary-color)';

$current_page = 'profile.php';
$page_title   = 'My Profile';

$extra_head = '<style>
/* ── PROFILE PAGE ─────────────────────────────────────────── */
.profile-wrap {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
    align-items: start;
}

/* Left card */
.profile-left {
    background: var(--white);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    overflow: hidden;
    position: sticky;
    top: 24px;
}
.profile-cover {
    height: 90px;
    background: linear-gradient(135deg, var(--primary-color) 0%, color-mix(in srgb, var(--primary-color) 60%, #1e1b4b) 100%);
    position: relative;
}
.profile-cover::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle at 20% 50%, rgba(255,255,255,.12) 0%, transparent 60%),
                      radial-gradient(circle at 80% 20%, rgba(255,255,255,.08) 0%, transparent 50%);
}
.profile-avatar-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 24px 24px;
    margin-top: -44px;
    position: relative;
}
.profile-avatar-ring {
    position: relative;
    width: 88px;
    height: 88px;
}
.profile-avatar-ring img {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--white);
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
    display: block;
}
.profile-avatar-overlay {
    position: absolute;
    inset: 4px;
    border-radius: 50%;
    background: rgba(0,0,0,.55);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity .2s;
    cursor: pointer;
}
.profile-avatar-ring:hover .profile-avatar-overlay { opacity: 1; }
.profile-avatar-overlay svg { stroke: #fff; }
.profile-name {
    font-size: 18px;
    font-weight: 800;
    color: var(--text-dark);
    margin: 14px 0 4px;
    text-align: center;
}
.profile-email-display {
    font-size: 13px;
    color: var(--text-muted);
    text-align: center;
    margin-bottom: 12px;
    word-break: break-all;
}
.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
}
.profile-divider {
    height: 1px;
    background: var(--border-color);
    margin: 20px 0;
}
.profile-stat-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    font-size: 13px;
    color: var(--text-muted);
    transition: background .15s;
}
.profile-stat-row:hover { background: var(--bg-light); }
.profile-stat-row svg { flex-shrink:0; stroke: var(--text-muted); }
.profile-stat-row strong { color: var(--text-dark); font-weight: 600; }

/* Right card */
.profile-right {
    background: var(--white);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    overflow: hidden;
}
.profile-tabs {
    display: flex;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-light);
}
.profile-tab {
    padding: 14px 24px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all .15s;
    user-select: none;
    display: flex;
    align-items: center;
    gap: 7px;
    background: transparent;
    border-top: none;
    border-left: none;
    border-right: none;
}
.profile-tab:hover { color: var(--text-dark); }
.profile-tab.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
    background: var(--white);
}
.profile-tab svg { width:16px; height:16px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }

.profile-panel { display: none; padding: 32px; animation: panelIn .2s ease; }
.profile-panel.active { display: block; }
@keyframes panelIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }

/* Form elements */
.pf-section-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pf-section-title svg { stroke: var(--primary-color); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.pf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.pf-grid.single { grid-template-columns: 1fr; }
.pf-field { display: flex; flex-direction: column; gap: 6px; }
.pf-field label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-muted);
}
.pf-field input,
.pf-field select,
.pf-field textarea {
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 9px;
    background: var(--bg-light);
    color: var(--text-dark);
    font-size: 14px;
    font-family: var(--font-family);
    outline: none;
    transition: border-color .18s, box-shadow .18s, background .18s;
    width: 100%;
    box-sizing: border-box;
}
.pf-field input:focus,
.pf-field select:focus,
.pf-field textarea:focus {
    border-color: var(--primary-color);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(109,74,255,.1);
}
.pf-field input[readonly] {
    opacity: .6; cursor: not-allowed;
}

/* Photo upload zone */
.photo-drop-zone {
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    position: relative;
    overflow: hidden;
}
.photo-drop-zone:hover, .photo-drop-zone.drag-over {
    border-color: var(--primary-color);
    background: rgba(109,74,255,.04);
}
.photo-drop-zone input[type=file] {
    position: absolute; inset: 0;
    opacity: 0; cursor: pointer;
    width: 100%; height: 100%;
}
.photo-drop-zone svg { stroke: var(--text-muted); margin-bottom: 8px; }
.photo-drop-zone p { font-size: 13px; color: var(--text-muted); margin: 0; }
.photo-drop-zone strong { color: var(--primary-color); }
.photo-preview-img {
    width: 56px; height: 56px; border-radius: 50%;
    object-fit: cover; border: 2px solid var(--border-color);
    display: none; margin: 0 auto 8px;
}

/* Password strength */
.pw-strength { height: 4px; border-radius: 2px; margin-top: 6px; background: var(--border-color); overflow: hidden; }
.pw-strength-bar { height: 100%; border-radius: 2px; width: 0; transition: width .3s, background .3s; }

/* Footer */
.pf-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 32px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-light);
}
.pf-footer-hint { font-size: 12px; color: var(--text-muted); }

/* Toast */
.pf-toast {
    position: fixed;
    bottom: 28px; right: 28px;
    padding: 14px 20px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,.18);
    z-index: 9999;
    animation: toastIn .35s cubic-bezier(.34,1.56,.64,1);
    max-width: 340px;
}
@keyframes toastIn { from { opacity:0; transform:translateY(16px) scale(.95); } to { opacity:1; transform:none; } }
.pf-toast.success { background: #10b981; color: #fff; }
.pf-toast.error   { background: #ef4444; color: #fff; }
.pf-toast.warning { background: #f59e0b; color: #fff; }

@media (max-width: 860px) {
    .profile-wrap { grid-template-columns: 1fr; }
    .profile-left { position: static; }
    .pf-grid { grid-template-columns: 1fr; }
}
</style>';

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <h1>My Profile</h1>
        </div>
        <?php include 'includes/topbar_right.php'; ?>
    </div>

    <div class="profile-wrap">

        <!-- ── LEFT PANEL ──────────────────────────────────────────── -->
        <div class="profile-left">
            <div class="profile-cover"></div>
            <div class="profile-avatar-wrap">
                <div class="profile-avatar-ring" id="avatarRing" title="Click to change photo" style="cursor:pointer;" onclick="document.getElementById('avatarFileInput').click()">
                    <img src="<?= $user_pic ?>" alt="Profile" id="avatarPreviewLeft">
                    <div class="profile-avatar-overlay">
                        <svg width="20" height="20" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </div>
                </div>
                <div class="profile-name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="profile-email-display"><?= htmlspecialchars($user['email']) ?></div>
                <span class="role-badge" style="background:<?= $role_color ?>22;color:<?= $role_color ?>;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?= $role_label ?>
                </span>

                <div class="profile-divider"></div>

                <div style="width:100%;">
                    <div class="profile-stat-row">
                        <svg width="15" height="15" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Member since <strong><?= $member_since ?></strong></span>
                    </div>
                    <div class="profile-stat-row">
                        <svg width="15" height="15" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>User ID <strong>#<?= $user_id ?></strong></span>
                    </div>
                    <div class="profile-stat-row">
                        <svg width="15" height="15" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.82 19a19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span>Role <strong><?= $role_label ?></strong></span>
                    </div>
                </div>
                <div style="padding:16px 20px 4px;width:100%;box-sizing:border-box;">
                    <a href="dashboard.php" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:9px;border-radius:9px;border:1.5px solid var(--border-color);font-size:13px;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all .15s;width:100%;box-sizing:border-box;" onmouseover="this.style.borderColor='var(--primary-color)';this.style.color='var(--primary-color)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- ── RIGHT PANEL ─────────────────────────────────────────── -->
        <div class="profile-right">
            <!-- Tabs -->
            <div class="profile-tabs">
                <button class="profile-tab active" onclick="switchTab('account', this)">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Account
                </button>
                <button class="profile-tab" onclick="switchTab('photo', this)">
                    <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Photo
                </button>
                <button class="profile-tab" onclick="switchTab('security', this)">
                    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Security
                </button>
            </div>

            <!-- ── TAB: ACCOUNT ── -->
            <div class="profile-panel active" id="panel-account">
                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="profile">
                    <p class="pf-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Personal Information
                    </p>
                    <div class="pf-grid">
                        <div class="pf-field">
                            <label>Full Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required placeholder="Your full name">
                        </div>
                        <div class="pf-field">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required placeholder="you@example.com">
                        </div>
                        <div class="pf-field">
                            <label>System Role</label>
                            <input type="text" value="<?= htmlspecialchars($role_label) ?>" readonly title="Role can only be changed by an administrator.">
                        </div>
                        <div class="pf-field">
                            <label>Member Since</label>
                            <input type="text" value="<?= $member_since ?>" readonly>
                        </div>
                    </div>
                    <div class="pf-footer" style="margin:32px -32px -32px;">
                        <span class="pf-footer-hint">Changes apply immediately after saving.</span>
                        <button type="submit" class="btn btn-primary" style="padding:10px 28px;font-size:14px;border-radius:9px;">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- ── TAB: PHOTO ── -->
            <div class="profile-panel" id="panel-photo">
                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="profile">
                    <!-- carry over existing values so they don't get blanked -->
                    <input type="hidden" name="name"  value="<?= htmlspecialchars($user['name'] ?? '') ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">

                    <p class="pf-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Profile Photo
                    </p>

                    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
                        <div style="flex-shrink:0;">
                            <img src="<?= $user_pic ?>" id="currentAvatar" style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--border-color);box-shadow:0 4px 16px rgba(0,0,0,.1);">
                            <div style="font-size:11px;color:var(--text-muted);text-align:center;margin-top:6px;">Current</div>
                        </div>
                        <div style="flex:1;min-width:220px;">
                            <div class="photo-drop-zone" id="dropZone">
                                <input type="file" name="profile_picture" accept="image/*" id="avatarFileInput" onchange="previewPhoto(this)">
                                <img id="photoPreviewImg" class="photo-preview-img" src="" alt="Preview">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <p><strong>Click to upload</strong> or drag and drop</p>
                                <p style="margin-top:4px;font-size:11px;">JPG, PNG, GIF or WebP · Max 2MB</p>
                            </div>
                            <div id="photoFileName" style="font-size:12px;color:var(--text-muted);margin-top:8px;"></div>
                        </div>
                    </div>

                    <div class="pf-footer" style="margin:32px -32px -32px;">
                        <span class="pf-footer-hint">Square images work best. Recommended 256×256px.</span>
                        <button type="submit" class="btn btn-primary" style="padding:10px 28px;font-size:14px;border-radius:9px;">Upload Photo</button>
                    </div>
                </form>
            </div>

            <!-- ── TAB: SECURITY ── -->
            <div class="profile-panel" id="panel-security">
                <form action="profile.php" method="POST" id="pwForm">
                    <input type="hidden" name="action" value="password">
                    <p class="pf-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Change Password
                    </p>
                    <div class="pf-grid single" style="max-width:440px;">
                        <div class="pf-field">
                            <label>Current Password</label>
                            <div style="position:relative;">
                                <input type="password" name="current_password" id="currentPw" placeholder="Enter current password" required style="padding-right:40px;">
                                <button type="button" onclick="togglePw('currentPw',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="pf-field">
                            <label>New Password</label>
                            <div style="position:relative;">
                                <input type="password" name="new_password" id="newPw" placeholder="Min. 6 characters" required oninput="checkStrength(this.value)" style="padding-right:40px;">
                                <button type="button" onclick="togglePw('newPw',this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                            <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
                            <div id="pwLabel" style="font-size:11px;color:var(--text-muted);margin-top:3px;"></div>
                        </div>
                        <div class="pf-field">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" id="confirmPw" placeholder="Repeat new password" required>
                        </div>
                    </div>

                    <div style="background:var(--bg-light);border:1px solid var(--border-color);border-radius:10px;padding:14px 16px;margin-top:20px;font-size:12.5px;color:var(--text-muted);max-width:440px;">
                        <strong style="color:var(--text-dark);">Password tips:</strong>
                        <ul style="margin:6px 0 0 16px;line-height:1.7;">
                            <li>Use at least 8 characters</li>
                            <li>Mix uppercase, lowercase, numbers and symbols</li>
                            <li>Don't reuse passwords from other sites</li>
                        </ul>
                    </div>

                    <div class="pf-footer" style="margin:32px -32px -32px;">
                        <span class="pf-footer-hint">You will stay logged in after changing your password.</span>
                        <button type="submit" class="btn btn-primary" style="padding:10px 28px;font-size:14px;border-radius:9px;background:#ef4444;border-color:#ef4444;">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<!-- ── TOAST ───────────────────────────────────────────────── -->
<?php if ($toast): ?>
<div class="pf-toast <?= in_array($toast, ['profile_saved','pw_saved']) ? 'success' : ($toast === 'error' ? 'error' : 'warning') ?>" id="pfToast">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <?php if (in_array($toast, ['profile_saved','pw_saved'])): ?>
            <polyline points="20 6 9 17 4 12"/>
        <?php elseif ($toast === 'error'): ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        <?php else: ?>
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        <?php endif; ?>
    </svg>
    <?php
    $msgs = [
        'profile_saved'  => 'Profile updated successfully.',
        'pw_saved'       => 'Password changed successfully.',
        'error'          => 'Something went wrong. Please try again.',
        'wrong_password' => 'Current password is incorrect.',
        'pw_too_short'   => 'New password must be at least 6 characters.',
        'pw_mismatch'    => 'Passwords do not match.',
    ];
    echo $msgs[$toast] ?? 'Action completed.';
    ?>
</div>
<script>setTimeout(() => { const t = document.getElementById('pfToast'); if(t){t.style.opacity='0';t.style.transform='translateY(10px)';t.style.transition='all .3s';setTimeout(()=>t.remove(),300);} }, 4000);</script>
<?php endif; ?>

<script>
// Tab switching
function switchTab(name, btn) {
    document.querySelectorAll('.profile-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.profile-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('panel-' + name).classList.add('active');
}

// Photo preview
function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    document.getElementById('photoFileName').textContent = '📎 ' + file.name;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('photoPreviewImg');
        img.src = e.target.result;
        img.style.display = 'block';
        document.getElementById('avatarPreviewLeft').src = e.target.result;
    };
    reader.readAsDataURL(file);
}

// Drag & drop
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag-over');
        const fi = dz.querySelector('input[type=file]');
        if (fi && e.dataTransfer.files.length) {
            fi.files = e.dataTransfer.files;
            previewPhoto(fi);
        }
    });
}

// Password strength
function checkStrength(val) {
    const bar = document.getElementById('pwBar');
    const lbl = document.getElementById('pwLabel');
    if (!bar) return;
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        [0,  '#ef4444', ''],
        [20, '#ef4444', 'Weak'],
        [40, '#f59e0b', 'Fair'],
        [65, '#3b82f6', 'Good'],
        [85, '#10b981', 'Strong'],
        [100,'#10b981', 'Very Strong'],
    ];
    const [w, col, label] = levels[score];
    bar.style.width  = w + '%';
    bar.style.background = col;
    lbl.textContent  = label;
    lbl.style.color  = col;
}

// Toggle password visibility
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    if (!inp) return;
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

// Open photo tab if photo tab clicked from avatar
<?php if ($toast === 'profile_saved'): ?>
// stay on account tab after save
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
