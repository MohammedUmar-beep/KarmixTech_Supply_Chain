<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>

<!-- ─── Toast Notification System ──────────────────────────────────── -->
<style>
#toast-container {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}
.toast {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    min-width: 280px;
    max-width: 420px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.18);
    pointer-events: all;
    animation: toastIn 0.3s cubic-bezier(0.34,1.56,0.64,1) forwards;
    position: relative;
    overflow: hidden;
    line-height: 1.4;
}
.toast.hiding {
    animation: toastOut 0.3s ease-in forwards;
}
.toast-success { background: #064e3b; color: #a7f3d0; border: 1px solid #065f46; }
.toast-error   { background: #4c0519; color: #fecdd3; border: 1px solid #881337; }
.toast-warning { background: #451a03; color: #fde68a; border: 1px solid #78350f; }
.toast-info    { background: #0c2a4a; color: #bae6fd; border: 1px solid #164e63; }
.toast-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 3px;
    border-radius: 0 0 10px 10px;
    animation: toastProgress 5s linear forwards;
}
.toast-success .toast-progress { background: #34d399; }
.toast-error   .toast-progress { background: #f87171; }
.toast-warning .toast-progress { background: #fbbf24; }
.toast-info    .toast-progress { background: #38bdf8; }
.toast-icon { flex-shrink: 0; width: 18px; height: 18px; }
.toast-close {
    margin-left: auto;
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    opacity: 0.6;
    color: inherit;
    font-size: 16px;
    line-height: 1;
    padding: 0 0 0 8px;
}
.toast-close:hover { opacity: 1; }
@keyframes toastIn {
    from { opacity: 0; transform: translateY(16px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0)    scale(1);    }
}
@keyframes toastOut {
    from { opacity: 1; transform: translateY(0) scale(1);    }
    to   { opacity: 0; transform: translateY(8px) scale(0.96); }
}
@keyframes toastProgress {
    from { width: 100%; }
    to   { width: 0%;   }
}
</style>

<div id="toast-container"></div>

<script>
(function () {
    const ICONS = {
        success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
        error:   '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
        warning: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
        info:    '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
    };

    window.showToast = function (message, type) {
        type = type || 'info';
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML = (ICONS[type] || ICONS.info) +
            '<span>' + message + '</span>' +
            '<button class="toast-close" aria-label="Dismiss">&times;</button>' +
            '<div class="toast-progress"></div>';

        toast.querySelector('.toast-close').addEventListener('click', function () {
            dismiss(toast);
        });

        container.appendChild(toast);

        const timer = setTimeout(function () { dismiss(toast); }, 5000);
        toast._timer = timer;
    };

    function dismiss(toast) {
        clearTimeout(toast._timer);
        toast.classList.add('hiding');
        toast.addEventListener('animationend', function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, { once: true });
    }

    // ── Unified toast param handler ───────────────────────────────────
    // All redirects use ?msg=<key>. Legacy ?success=, ?error=, ?deleted=1,
    // ?unreceived=1 are normalised here so they all go through one path.
    const params = new URLSearchParams(window.location.search);

    // Normalise legacy params → single msg value + type override
    let _msg = null, _typeOverride = null;

    if (params.has('deleted') && params.get('deleted') === '1') {
        _msg = 'deleted'; _typeOverride = 'warning';
    } else if (params.has('unreceived') && params.get('unreceived') === '1') {
        _msg = 'unreceived'; _typeOverride = 'warning';
    } else if (params.has('success')) {
        _msg = params.get('success') || '1'; _typeOverride = 'success';
    } else if (params.has('error')) {
        _msg = 'error_' + (params.get('error') || '1'); _typeOverride = 'error';
    } else if (params.has('msg')) {
        _msg = params.get('msg');
    }

    if (_msg) {
        // Master message map — covers all keys used anywhere in the codebase
        const MSG_MAP = {
            // ── Success ──────────────────────────────────────────────
            '1':               ['Operation completed successfully.', 'success'],
            'added':           ['Record added successfully.',        'success'],
            'updated':         ['Record updated successfully.',      'success'],
            'edit':            ['Record updated successfully.',      'success'],
            'created':         ['Record created successfully.',      'success'],
            'saved':           ['Changes saved successfully.',       'success'],
            'adjusted':        ['Record adjusted successfully.',     'success'],
            'status_updated':  ['Status updated successfully.',      'success'],
            'received':        ['Items marked as received.',         'success'],
            // ── Warning / neutral ────────────────────────────────────
            'deleted':         ['Record permanently deleted.',       'warning'],
            'cancelled':       ['Order cancelled and stock restored.','warning'],
            'unreceived':      ['Purchase order marked as cancelled.','warning'],
            // ── Error ────────────────────────────────────────────────
            'error_1':         ['Something went wrong. Please try again.',        'error'],
            'error_invalid_data': ['Invalid data submitted. Please check inputs.','error'],
            'error_db':        ['Database error. Please try again.',              'error'],
            'error_csrf':      ['Security check failed. Please try again.',       'error'],
            'error_unauthorized': ['You are not authorised to perform this action.','error'],
            'error_docs_required': ['Required documents are missing.',            'error'],
        };

        let text, type;
        if (MSG_MAP[_msg]) {
            [text, type] = MSG_MAP[_msg];
        } else {
            // Auto-classify unknown keys by keyword
            type = _typeOverride || 'info';
            if (!_typeOverride) {
                if (/success|updated|created|added|saved|generated|received/i.test(_msg)) type = 'success';
                else if (/delet|remov|cancel/i.test(_msg)) type = 'warning';
                else if (/error|fail|invalid/i.test(_msg)) type = 'error';
            }
            // Humanise snake_case keys as fallback display text
            text = _msg.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }
        if (_typeOverride && MSG_MAP[_msg]) type = _typeOverride; // legacy override wins
        showToast(text, type);

        // Clean URL after showing (prevents re-toast on refresh)
        window.history.replaceState({}, document.title, window.location.pathname);
    }
})();
</script>
<?php if (isset($extra_scripts))
    echo $extra_scripts; ?>
</body>

</html>