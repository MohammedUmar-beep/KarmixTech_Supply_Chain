<?php
// Determine the root path or handle active sidebar states if necessary
// Load currency symbol globally for all pages
if (isset($conn) && !isset($currency)) {
    $currency = get_currency($conn);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? $page_title . ' - Agile Inventory' : 'Agile Inventory System' ?></title>

    <script>
        (function () {
            const savedTheme = localStorage.getItem('agile_theme') || 'light';
            const savedColor = localStorage.getItem('agile_color') || '#6d4aff';

            document.documentElement.setAttribute('data-theme', savedTheme);

            if (savedColor === 'monochrome') {
                if (savedTheme === 'dark') {
                    document.documentElement.style.setProperty('--primary-color', '#ffffff');
                    document.documentElement.style.setProperty('--primary-hover', '#e5e5e5');
                } else {
                    document.documentElement.style.setProperty('--primary-color', '#000000');
                    document.documentElement.style.setProperty('--primary-hover', '#333333');
                }
            } else {
                document.documentElement.style.setProperty('--primary-color', savedColor);
                if (savedColor === '#1E5EFF') {
                    document.documentElement.style.setProperty('--primary-hover', '#1648c9');
                } else if (savedColor === '#336DFF') {
                    document.documentElement.style.setProperty('--primary-hover', '#2452c7');
                }
            }
        })();
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            flatpickr("input[type=date]", { dateFormat: "Y-m-d", allowInput: true });
        });
    </script>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <?php if (isset($extra_head))
        echo $extra_head; ?>
    <script>
        window.CURRENCY = { symbol: '<?= isset($currency) ? addslashes($currency['symbol']) : '₹' ?>', code: '<?= isset($currency) ? addslashes($currency['code']) : 'INR' ?>' };
    </script>
</head>

<body>
    <div class="app-container">
        <!-- Global Custom Confirm Modal -->
        <div id="customConfirmModal" class="modal-overlay"
            style="z-index: 9999; flex-direction: column; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(2px);">
            <div
                style="background: var(--white); padding: 24px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); max-width: 400px; width: 90%; text-align: center; animation: modalFadeIn 0.2s ease-out;">
                <div style="margin-bottom: 16px; color: var(--danger-color);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" style="width: 48px; height: 48px;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <h3 style="margin: 0 0 8px 0; color: var(--text-dark); font-size: 18px;">Are you sure?</h3>
                <p id="customConfirmMessage"
                    style="margin: 0 0 24px 0; color: var(--text-muted); font-size: 14px; line-height: 1.5;"></p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button id="customConfirmCancelBtn" class="btn btn-outline"
                        style="flex: 1; padding: 10px; border-radius: 6px;">Cancel</button>
                    <button id="customConfirmOkBtn" class="btn btn-primary"
                        style="flex: 1; padding: 10px; border-radius: 6px; background: var(--danger-color); border-color: var(--danger-color);">Confirm</button>
                </div>
            </div>
        </div>
        <!-- Global Custom Alert Modal -->
        <div id="customAlertModal" class="modal-overlay"
            style="z-index: 10000; flex-direction: column; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(2px);">
            <div
                style="background: var(--white); padding: 24px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); max-width: 400px; width: 90%; text-align: center; animation: modalFadeIn 0.2s ease-out;">
                <h3 style="margin: 0 0 8px 0; color: var(--text-dark); font-size: 18px;">Attention</h3>
                <p id="customAlertMessage"
                    style="margin: 0 0 24px 0; color: var(--text-muted); font-size: 14px; line-height: 1.5;"></p>
                <button id="customAlertOkBtn" class="btn btn-primary"
                    style="width: 100%; padding: 10px; border-radius: 6px;">OK</button>
            </div>
        </div>

        <?php include 'sidebar.php'; ?>