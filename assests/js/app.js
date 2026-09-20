'use strict';

// ─── Shared helpers ───────────────────────────────────────────────────────────

function debounce(fn, wait) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}

function isNumeric(val) {
    return val !== '' && !isNaN(val) && !isNaN(parseFloat(val));
}

// ─── DOMContentLoaded ─────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {

    // ── Pincode → State auto-fill ─────────────────────────────────────────────
    const pincodeInputs = document.querySelectorAll('input[name="pincode"]');
    const stateSelects  = document.querySelectorAll('select[name="state"]');
    if (pincodeInputs.length > 0 && stateSelects.length > 0) {
        const pincodeStateMap = {
            '11':'Delhi','12':'Haryana','13':'Haryana','14':'Punjab','15':'Punjab','16':'Chandigarh',
            '17':'Himachal Pradesh','18':'Jammu and Kashmir','19':'Jammu and Kashmir',
            '20':'Uttar Pradesh','21':'Uttar Pradesh','22':'Uttar Pradesh','23':'Uttar Pradesh',
            '24':'Uttar Pradesh','25':'Uttar Pradesh','26':'Uttar Pradesh','27':'Uttar Pradesh','28':'Uttar Pradesh',
            '30':'Rajasthan','31':'Rajasthan','32':'Rajasthan','33':'Rajasthan','34':'Rajasthan',
            '36':'Gujarat','37':'Gujarat','38':'Gujarat','39':'Gujarat',
            '40':'Maharashtra','41':'Maharashtra','42':'Maharashtra','43':'Maharashtra','44':'Maharashtra',
            '45':'Madhya Pradesh','46':'Madhya Pradesh','47':'Madhya Pradesh','48':'Madhya Pradesh',
            '49':'Chhattisgarh','50':'Telangana','51':'Andhra Pradesh','52':'Andhra Pradesh','53':'Andhra Pradesh',
            '56':'Karnataka','57':'Karnataka','58':'Karnataka','59':'Karnataka',
            '60':'Tamil Nadu','61':'Tamil Nadu','62':'Tamil Nadu','63':'Tamil Nadu','64':'Tamil Nadu',
            '67':'Kerala','68':'Kerala','69':'Kerala',
            '70':'West Bengal','71':'West Bengal','72':'West Bengal','73':'West Bengal','74':'West Bengal',
            '75':'Odisha','76':'Odisha','77':'Odisha','78':'Assam','79':'Arunachal Pradesh',
            '80':'Bihar','81':'Bihar','82':'Bihar','83':'Bihar','84':'Bihar','85':'Bihar',
            '88':'Jharkhand','89':'Jharkhand',
            '90':'Jammu and Kashmir','91':'Jammu and Kashmir','92':'Jammu and Kashmir','93':'Jammu and Kashmir',
            '94':'Jammu and Kashmir','95':'Jammu and Kashmir','96':'Jammu and Kashmir','97':'Jammu and Kashmir',
            '98':'Jammu and Kashmir','99':'Jammu and Kashmir'
        };
        const handlePincode = debounce(function (e) {
            const val = e.target.value;
            if (val.length >= 2) {
                const stateName = pincodeStateMap[val.substring(0, 2)];
                if (stateName) stateSelects.forEach(s => { s.value = stateName; });
            }
        }, 200);
        pincodeInputs.forEach(input => input.addEventListener('input', handlePincode));
    }

    // ── Init all table enhancements ───────────────────────────────────────────
    document.querySelectorAll('.data-table-container').forEach(container => {
        initSorting(container);
        initBulkSelect(container);
        initColumnToggle(container);
        initRowExpand(container);
        initExportDropdown(container);
        initFilterPanel(container);
        initLiveSearch(container);
    });

    initKeyboardNav();
});

// ═══════════════════════════════════════════════════════
// FEATURE 1: Universal Table Sorting
// ═══════════════════════════════════════════════════════

function initSorting(container) {
    const table = container.querySelector('table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (rows.length === 1 && rows[0].querySelector('td[colspan]')) return;
    const headers = table.querySelectorAll('th');
    headers.forEach((th, index) => {
        const text = th.textContent.trim();
        if (!text || text === 'Actions' || th.querySelector('input[type="checkbox"]')) return;
        th.classList.add('sortable');
        let sortAsc = true;
        th.addEventListener('click', () => {
            headers.forEach(h => { if (h !== th) h.classList.remove('asc', 'desc'); });
            const visibleRows = Array.from(tbody.querySelectorAll('tr:not(.expanded-detail-row)'));
            visibleRows.sort((a, b) => {
                const cellA = a.children[index];
                const cellB = b.children[index];
                if (!cellA || !cellB) return 0;
                const valA = cellA.textContent.trim();
                const valB = cellB.textContent.trim();
                const numA = valA.replace(/[^0-9.-]+/g, '');
                const numB = valB.replace(/[^0-9.-]+/g, '');
                if (valA.match(/[a-zA-Z]/) || !isNumeric(numA) || !isNumeric(numB)) {
                    const strA = valA.toLowerCase();
                    const strB = valB.toLowerCase();
                    if (strA < strB) return sortAsc ? -1 : 1;
                    if (strA > strB) return sortAsc ? 1 : -1;
                    return 0;
                }
                return sortAsc ? parseFloat(numA) - parseFloat(numB) : parseFloat(numB) - parseFloat(numA);
            });
            sortAsc = !sortAsc;
            th.classList.toggle('asc', !sortAsc);
            th.classList.toggle('desc', sortAsc);
            const frag = document.createDocumentFragment();
            visibleRows.forEach(r => frag.appendChild(r));
            tbody.appendChild(frag);
        });
    });
}

// ═══════════════════════════════════════════════════════
// FEATURE 2: Bulk Select via the row-expand-btn (+/✓ toggle)
//
// The + button in each row IS the selector:
//   unselected → + icon, plain background
//   selected   → ✓ icon, primary-tinted background
// The select-all-cb checkbox in thead drives select-all.
// ═══════════════════════════════════════════════════════

function initBulkSelect(container) {
    const table = container.querySelector('table');
    if (!table) return;

    // Replace the plain <input type="checkbox" class="select-all-cb"> in thead
    // with a styled button that matches the row-select-btn look.
    const selectAllCbOrig = table.querySelector('thead .select-all-cb');
    let selectAllBtn = null;
    if (selectAllCbOrig) {
        selectAllBtn = document.createElement('button');
        selectAllBtn.className = 'row-select-btn select-all-btn';
        selectAllBtn.title = 'Select all rows';
        selectAllCbOrig.replaceWith(selectAllBtn);
    }

    // Build bulk action bar
    let bar = container.querySelector('.bulk-action-bar');
    if (!bar) {
        bar = document.createElement('div');
        bar.className = 'bulk-action-bar';
        bar.innerHTML =
            '<span class="selected-count">0</span>\u00a0rows selected' +
            '<div class="bulk-sep"></div>' +
            '<button class="btn-bulk bulk-export-btn">' +
              '<svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2">' +
              '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/>' +
              '<line x1="12" y1="15" x2="12" y2="3"/></svg> Export Selected' +
            '</button>' +
            '<button class="btn-bulk btn-bulk-danger bulk-delete-btn">' +
              '<svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2">' +
              '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>' +
              '</svg> Delete Selected' +
            '</button>' +
            '<button class="btn-bulk-deselect">&times;</button>';
        const firstTable = container.querySelector('table');
        if (firstTable) container.insertBefore(bar, firstTable);
    }

    // SVG icons
    const ICON_PLUS  = '<svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    const ICON_CHECK = '<svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    // Indeterminate (dash) — some rows selected
    const ICON_DASH  = '<svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>';

    if (selectAllBtn) selectAllBtn.innerHTML = ICON_PLUS;

    function getSelectBtns() {
        return Array.from(table.querySelectorAll('tbody .row-select-btn'));
    }

    function isSelected(btn) {
        return btn.classList.contains('selected');
    }

    function setSelected(btn, val) {
        if (val) {
            btn.classList.add('selected');
            btn.innerHTML = ICON_CHECK;
            btn.closest('tr').classList.add('row-selected');
        } else {
            btn.classList.remove('selected');
            btn.innerHTML = ICON_PLUS;
            btn.closest('tr').classList.remove('row-selected');
        }
    }

    function getSelectedIds() {
        return getSelectBtns().filter(isSelected).map(b => b.dataset.id);
    }

    function updateBar(skipHeaderSync) {
        const btns = getSelectBtns();
        const selectedBtns = btns.filter(isSelected);
        const count = selectedBtns.length;
        bar.querySelector('.selected-count').textContent = count;
        bar.classList.toggle('visible', count > 0);
        // Sync the header select-all button icon to reflect none/some/all state
        if (selectAllBtn && !skipHeaderSync) {
            const allSelected = btns.length > 0 && count === btns.length;
            const someSelected = count > 0 && count < btns.length;
            selectAllBtn.classList.toggle('selected', allSelected);
            selectAllBtn.classList.toggle('indeterminate', someSelected && !allSelected);
            selectAllBtn.innerHTML = allSelected ? ICON_CHECK : someSelected ? ICON_DASH : ICON_PLUS;
        }
    }

    // Convert existing .row-expand-btn buttons into .row-select-btn
    table.querySelectorAll('tbody .row-expand-btn').forEach(btn => {
        btn.classList.remove('row-expand-btn');
        btn.classList.add('row-select-btn');
        const tr = btn.closest('tr');
        if (tr) btn.dataset.id = tr.dataset.rowId || '';
        btn.title = 'Select row';
        btn.innerHTML = ICON_PLUS;
    });

    // Click handler on tbody
    table.querySelector('tbody').addEventListener('click', function(e) {
        const btn = e.target.closest('.row-select-btn');
        if (!btn) return;
        e.stopPropagation();
        setSelected(btn, !isSelected(btn));
        updateBar();
    });

    // Select-all header button
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            const btns = getSelectBtns();
            const allSelected = btns.length > 0 && btns.every(isSelected);
            btns.forEach(btn => setSelected(btn, !allSelected));
            updateBar(true);
            // Manually sync icon after toggle
            const nowAll = !allSelected;
            selectAllBtn.classList.toggle('selected', nowAll);
            selectAllBtn.classList.remove('indeterminate');
            selectAllBtn.innerHTML = nowAll ? ICON_CHECK : ICON_PLUS;
        });
    }

    // Deselect all button in bulk bar
    bar.querySelector('.btn-bulk-deselect').addEventListener('click', () => {
        getSelectBtns().forEach(btn => setSelected(btn, false));
        if (selectAllBtn) {
            selectAllBtn.classList.remove('selected', 'indeterminate');
            selectAllBtn.innerHTML = ICON_PLUS;
        }
        updateBar();
    });

    // Bulk delete
    bar.querySelector('.bulk-delete-btn').addEventListener('click', () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        showCustomConfirm('Delete ' + ids.length + ' selected record(s)? This cannot be undone.', () => {
            const form = document.createElement('form');
            form.method = 'POST'; form.action = 'ajax_bulk_action.php';
            const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); };
            add('action', 'delete');
            add('module', window.location.pathname.split('/').pop().replace('.php', ''));
            ids.forEach(id => add('ids[]', id));
            document.body.appendChild(form); form.submit();
        });
    });

    // Bulk export
    bar.querySelector('.bulk-export-btn').addEventListener('click', () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        const form = document.createElement('form');
        form.method = 'POST'; form.action = 'ajax_bulk_action.php'; form.target = '_blank';
        const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); };
        add('action', 'export_csv');
        add('module', window.location.pathname.split('/').pop().replace('.php', ''));
        ids.forEach(id => add('ids[]', id));
        document.body.appendChild(form); form.submit();
        setTimeout(() => form.remove(), 1000);
    });
}

// ═══════════════════════════════════════════════════════
// FEATURE 3: Column Visibility Toggle
// ═══════════════════════════════════════════════════════

function initColumnToggle(container) {
    const table = container.querySelector('table');
    if (!table) return;
    const headers = Array.from(table.querySelectorAll('thead th'));
    if (headers.length < 3) return;
    const controls = container.querySelector('.table-header-controls');
    if (!controls) return;
    if (controls.querySelector('.col-visibility-wrapper')) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'col-visibility-wrapper';

    const btn = document.createElement('button');
    btn.className = 'col-visibility-btn';
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Columns';

    const dropdown = document.createElement('div');
    dropdown.className = 'col-visibility-dropdown';

    headers.forEach((th, i) => {
        const label = th.textContent.trim().replace(/\s+/g, ' ');
        if (!label || label === 'Actions' || th.querySelector('input')) return;
        const item = document.createElement('label');
        item.className = 'col-visibility-item';
        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = true;
        cb.addEventListener('change', () => {
            table.querySelectorAll('tr > *:nth-child(' + (i + 1) + ')').forEach(cell => {
                cell.style.display = cb.checked ? '' : 'none';
            });
        });
        item.appendChild(cb);
        item.appendChild(document.createTextNode(' ' + label));
        dropdown.appendChild(item);
    });

    wrapper.appendChild(btn);
    wrapper.appendChild(dropdown);
    btn.addEventListener('click', e => { e.stopPropagation(); dropdown.classList.toggle('open'); });
    document.addEventListener('click', () => dropdown.classList.remove('open'));

    // Insert into the last DIRECT child div of controls (the right-side button group)
    // Using Array.from(children) avoids the querySelector(':last-child') nested-match bug
    const directChildren = Array.from(controls.children).filter(el => el.tagName === 'DIV');
    const rightSide = directChildren[directChildren.length - 1] || controls;
    rightSide.prepend(wrapper);
}

// ═══════════════════════════════════════════════════════
// FEATURE 4: Row Expand / Detail Drawer
// ═══════════════════════════════════════════════════════

function initRowExpand(container) {
    // Row expand is now handled entirely by the select button (see initBulkSelect)
    // This function is kept for future use only
}

// ═══════════════════════════════════════════════════════
// FEATURE 5: Export Split-Button Dropdown
// ═══════════════════════════════════════════════════════

function initExportDropdown(container) {
    const controls = container.querySelector('.table-header-controls');
    if (!controls) return;
    controls.querySelectorAll('a[href*="export.php"]').forEach(link => {
        const href = link.getAttribute('href') || '';
        const urlParams = new URLSearchParams(href.split('?')[1] || '');
        const module = urlParams.get('module');
        if (!module) return;
        if (link.closest('.export-dropdown-wrapper')) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'export-dropdown-wrapper';
        wrapper.style.position = 'relative';

        const splitBtn = document.createElement('div');
        splitBtn.className = 'btn-export-split';
        splitBtn.innerHTML =
            '<a href="export.php?module=' + module + '&format=csv" class="btn-export-main">' +
            '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2">' +
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/>' +
            '<line x1="12" y1="15" x2="12" y2="3"/></svg> Export</a>' +
            '<button class="btn-export-caret" title="More formats">\u25BE</button>';

        const menu = document.createElement('div');
        menu.className = 'export-dropdown-menu';
        menu.innerHTML =
            '<a href="export.php?module=' + module + '&format=csv" class="export-option">' +
            '<svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> CSV (.csv)</a>' +
            '<a href="export.php?module=' + module + '&format=excel" class="export-option">' +
            '<svg viewBox="0 0 24 24" stroke="#217346" fill="none" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Excel (.xlsx)</a>' +
            '<a href="export.php?module=' + module + '&format=pdf" class="export-option">' +
            '<svg viewBox="0 0 24 24" stroke="#dc2626" fill="none" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> PDF (.pdf)</a>';

        wrapper.appendChild(splitBtn);
        wrapper.appendChild(menu);
        splitBtn.querySelector('.btn-export-caret').addEventListener('click', e => {
            e.stopPropagation(); menu.classList.toggle('open');
        });
        document.addEventListener('click', () => menu.classList.remove('open'));
        link.parentNode.replaceChild(wrapper, link);
    });
}

// ═══════════════════════════════════════════════════════
// FEATURE 6: Advanced Filter Panel
// ═══════════════════════════════════════════════════════

function initFilterPanel(container) {
    if (container.dataset.noFilterPanel) return;
    const controls = container.querySelector('.table-header-controls');
    if (!controls) return;
    if (controls.querySelector('.filter-panel-btn')) return;
    const searchInput = controls.querySelector('.search-input input, input[name="search"]');
    if (!searchInput) return;

    const btn = document.createElement('button');
    btn.className = 'filter-panel-btn';
    btn.innerHTML =
        '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2">' +
        '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg> Filters ' +
        '<span class="filter-count"></span>';

    const params = new URLSearchParams(window.location.search);
    const drawer = document.createElement('div');
    drawer.className = 'filter-drawer';
    drawer.innerHTML =
        '<div class="filter-group"><label>Min Value</label><input type="number" name="filter_min" placeholder="0" value="' + (params.get('filter_min') || '') + '"></div>' +
        '<div class="filter-group"><label>Max Value</label><input type="number" name="filter_max" placeholder="99999" value="' + (params.get('filter_max') || '') + '"></div>' +
        '<div class="filter-group"><label>Date From</label><input type="date" name="filter_date_from" value="' + (params.get('filter_date_from') || '') + '"></div>' +
        '<div class="filter-group"><label>Date To</label><input type="date" name="filter_date_to" value="' + (params.get('filter_date_to') || '') + '"></div>' +
        '<div class="filter-actions"><button class="btn-filter-apply">Apply</button><button class="btn-filter-clear">Clear All</button></div>';

    controls.parentNode.insertBefore(drawer, controls.nextSibling);

    btn.addEventListener('click', e => {
        e.stopPropagation();
        btn.classList.toggle('active');
        drawer.classList.toggle('open');
    });

    drawer.querySelector('.btn-filter-apply').addEventListener('click', () => {
        const url = new URL(window.location);
        drawer.querySelectorAll('input[name]').forEach(el => {
            if (el.value) url.searchParams.set(el.name, el.value);
            else url.searchParams.delete(el.name);
        });
        url.searchParams.delete('page');
        window.location = url.toString();
    });

    drawer.querySelector('.btn-filter-clear').addEventListener('click', () => {
        const url = new URL(window.location);
        ['filter_min', 'filter_max', 'filter_date_from', 'filter_date_to'].forEach(p => url.searchParams.delete(p));
        window.location = url.toString();
    });

    const activeCount = ['filter_min', 'filter_max', 'filter_date_from', 'filter_date_to'].filter(k => params.has(k)).length;
    if (activeCount) {
        const badge = btn.querySelector('.filter-count');
        badge.textContent = activeCount; badge.classList.add('visible');
        btn.classList.add('active'); drawer.classList.add('open');
    }

    const leftSide = controls.querySelector('div:first-child') || controls;
    leftSide.appendChild(btn);
}

// ═══════════════════════════════════════════════════════
// FEATURE 7: Live Search (instant row filter on current page)
// ═══════════════════════════════════════════════════════

function initLiveSearch(container) {
    const searchInput = container.querySelector('.search-input input');
    if (!searchInput) return;
    const tbody = container.querySelector('table tbody');
    if (!tbody) return;

    const liveFilter = debounce(() => {
        const query = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;
        tbody.querySelectorAll('tr:not(.live-search-empty)').forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            const match = !query || row.textContent.toLowerCase().includes(query);
            row.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });
        let liveEmpty = tbody.querySelector('.live-search-empty');
        if (visibleCount === 0 && query) {
            if (!liveEmpty) {
                liveEmpty = document.createElement('tr');
                liveEmpty.className = 'live-search-empty';
                const td = document.createElement('td');
                td.colSpan = 100;
                td.innerHTML =
                    '<div class="empty-state">' +
                    '<svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" fill="none" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
                    '<div class="empty-state-title">No results for \u201c' + searchInput.value + '\u201d</div>' +
                    '<div class="empty-state-desc">Try a different term or clear the search.</div>' +
                    '</div>';
                tbody.appendChild(liveEmpty);
            }
        } else if (liveEmpty) {
            liveEmpty.remove();
        }
    }, 120);

    searchInput.addEventListener('input', liveFilter);
}

// ═══════════════════════════════════════════════════════
// FEATURE 8: Keyboard Navigation
// ═══════════════════════════════════════════════════════

function initKeyboardNav() {
    let focused = null;
    document.addEventListener('keydown', function(e) {
        const container = document.querySelector('.data-table-container');
        if (!container) return;
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
        const tbody = container.querySelector('tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr:not(.live-search-empty):not([style*="display: none"])'));
        if (!rows.length) return;

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const idx = focused ? rows.indexOf(focused) : -1;
            const next = e.key === 'ArrowDown'
                ? rows[Math.min(idx + 1, rows.length - 1)]
                : rows[Math.max(idx - 1, 0)];
            if (focused) focused.classList.remove('kb-focused');
            focused = next;
            focused.classList.add('kb-focused');
            focused.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        if (e.key === 'Enter' && focused) {
            const editLink = focused.querySelector('a[href*="edit_"]');
            if (editLink) window.location.href = editLink.href;
        }
        if (e.key === 'Escape' && focused) {
            focused.classList.remove('kb-focused');
            focused = null;
        }
    });
}

// ─── Custom Confirm Modal ─────────────────────────────────────────────────────

window.showCustomConfirm = function (message, onConfirm) {
    const modal     = document.getElementById('customConfirmModal');
    const msgEl     = document.getElementById('customConfirmMessage');
    const cancelBtn = document.getElementById('customConfirmCancelBtn');
    const okBtn     = document.getElementById('customConfirmOkBtn');
    if (!modal) { if (confirm(message)) onConfirm(); return; }
    msgEl.textContent = message;
    modal.classList.add('active');
    cancelBtn.addEventListener('click', () => modal.classList.remove('active'), { once: true });
    okBtn.addEventListener('click', () => { modal.classList.remove('active'); onConfirm(); }, { once: true });
};

// ─── Custom Alert Modal ───────────────────────────────────────────────────────

window.showCustomAlert = function (message) {
    const modal = document.getElementById('customAlertModal');
    const msgEl = document.getElementById('customAlertMessage');
    const okBtn = document.getElementById('customAlertOkBtn');
    if (!modal) { alert(message); return; }
    msgEl.textContent = message;
    modal.classList.add('active');
    okBtn.addEventListener('click', () => modal.classList.remove('active'), { once: true });
};
