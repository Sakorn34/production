/**
 * kpo-catalog.js — modal จัดการรายการอะไหล่สั่งต่างประเทศ
 */
(function () {
    'use strict';

    var cfg = window.KPO_CATALOG || {};
    var apiUrl = cfg.apiUrl || '';
    var csrf = cfg.csrf || '';
    var maxRows = cfg.maxRows || 20;

    var modal = document.getElementById('kpo-catalog-modal');
    if (!modal || !apiUrl) {
        return;
    }

    var searchInput = document.getElementById('kpo-catalog-search');
    var searchResults = document.getElementById('kpo-catalog-search-results');
    var editorBody = document.getElementById('kpo-catalog-editor-body');
    var saveBtn = document.getElementById('kpo-catalog-save');
    var statusEl = document.getElementById('kpo-catalog-status');
    var searchTimer = null;
    var searchGen = 0;
    /** inv_log_part / key ที่เพิ่มใน editor แล้ว (ยังไม่บันทึก) — กรองออกจากผลค้นหา */
    var dismissedPickIds = new Set();
    var rows = Array.isArray(cfg.initialRows) ? cfg.initialRows.slice() : [];

    function pickDismissId(kind, value) {
        var v = value == null ? '' : String(value).trim();
        return v === '' ? '' : kind + ':' + v;
    }

    function markPickDismissed(inv, key) {
        var idInv = pickDismissId('inv', inv);
        var idKey = pickDismissId('key', key);
        if (idInv) {
            dismissedPickIds.add(idInv);
        }
        if (idKey) {
            dismissedPickIds.add(idKey);
        }
    }

    function isPickDismissed(item) {
        if (!item) {
            return false;
        }
        var idInv = pickDismissId('inv', item.inv_log_part);
        var idKey = pickDismissId('key', item.suggested_key);
        return (idInv && dismissedPickIds.has(idInv)) || (idKey && dismissedPickIds.has(idKey));
    }

    function syncDismissedFromRows() {
        dismissedPickIds.clear();
        rows.forEach(function (row) {
            markPickDismissed(row.inv_log_part, row.key);
        });
    }

    function searchResultsPlaceholderHtml() {
        return '<p class="muted">พิมพ์ชื่ออะไหล่เพื่อค้นจาก inventory</p>';
    }

    /** ยุบบล็อกผลค้นหา — เหมือนกดกากบาทในช่องค้นหา (type=search) */
    function collapseSearchResults() {
        searchGen += 1;
        clearTimeout(searchTimer);
        searchTimer = null;
        if (searchInput) {
            searchInput.value = '';
        }
        if (searchResults) {
            searchResults.innerHTML = searchResultsPlaceholderHtml();
        }
    }

    function setStatus(msg, isErr) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'kpo-catalog-status' + (isErr ? ' is-error' : '');
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderEditor(focusKey) {
        if (!editorBody) return;
        editorBody.innerHTML = '';
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.dataset.key = row.key;
            if (focusKey && row.key === focusKey) {
                tr.className = 'is-focus';
            }
            tr.innerHTML =
                '<td><input type="text" class="kpo-ed-key" value="' + esc(row.key) + '" maxlength="32" pattern="[a-z][a-z0-9_-]*"></td>' +
                '<td><input type="text" class="kpo-ed-label" value="' + esc(row.label) + '" maxlength="120"></td>' +
                '<td><input type="text" class="kpo-ed-rev" value="' + esc(row.rev_label) + '" maxlength="80"></td>' +
                '<td><input type="text" class="kpo-ed-log" value="' + esc(row.inv_log_part) + '" maxlength="200"></td>' +
                '<td><button type="button" class="btn btn-line btn-sm kpo-ed-remove">ลบ</button></td>';
            editorBody.appendChild(tr);
        });
        if (focusKey) {
            var focusRow = editorBody.querySelector('tr.is-focus');
            if (focusRow) {
                focusRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }
    }

    function readEditorRows() {
        if (!editorBody) return [];
        var out = [];
        editorBody.querySelectorAll('tr').forEach(function (tr) {
            out.push({
                key: (tr.querySelector('.kpo-ed-key') || {}).value || '',
                label: (tr.querySelector('.kpo-ed-label') || {}).value || '',
                rev_label: (tr.querySelector('.kpo-ed-rev') || {}).value || '',
                inv_log_part: (tr.querySelector('.kpo-ed-log') || {}).value || ''
            });
        });
        return out;
    }

    /**
     * เพิ่มแถวจากผลค้นหา inventory ลง editor ชั่วคราว (ยังไม่บันทึกจนกด save)
     *
     * @param {object|null} item
     * @returns {boolean} true เมื่อเพิ่มสำเร็จ
     */
    function addRowFromPick(item) {
        if (!item || item.in_catalog) {
            return false;
        }
        if (rows.length >= maxRows) {
            setStatus('เกินจำนวนสูงสุด ' + maxRows + ' รายการ', true);
            return false;
        }
        rows.push({
            key: item.suggested_key || '',
            label: item.label || '',
            rev_label: item.rev_label || '—',
            inv_log_part: item.inv_log_part || ''
        });
        markPickDismissed(item.inv_log_part, item.suggested_key);
        renderEditor(item.suggested_key || '');
        setStatus('เพิ่มในรายการแล้ว — กดบันทึกเพื่อใช้งาน');
        return true;
    }

    function renderSearch(items) {
        if (!searchResults) return;
        var visible = (items || []).filter(function (it) {
            return !isPickDismissed(it);
        });
        if (!visible.length) {
            searchResults.innerHTML = items && items.length
                ? '<p class="muted">เพิ่มในรายการแก้ไขแล้ว — ค้นหาเพิ่มหรือกดบันทึก</p>'
                : '<p class="muted">ไม่พบชื่อที่ตรงกับคำค้น</p>';
            return;
        }
        var html = '<ul class="kpo-catalog-pick-list">';
        visible.forEach(function (it) {
            var stock = it.stock != null ? ' · คงเหลือ ' + it.stock : '';
            var min = it.min != null ? ' · ขั้นต่ำ ' + it.min : '';
            var badge = it.in_catalog
                ? '<span class="kpo-pick-badge in-list">มีในรายการแล้ว</span>'
                : '<button type="button" class="btn btn-primary btn-sm kpo-pick-add">เพิ่ม</button>';
            html += '<li class="kpo-catalog-pick"' +
                ' data-inv="' + esc(it.inv_log_part) + '"' +
                ' data-label="' + esc(it.label) + '"' +
                ' data-rev="' + esc(it.rev_label) + '"' +
                ' data-key="' + esc(it.suggested_key) + '"' +
                ' data-in-catalog="' + (it.in_catalog ? '1' : '0') + '">' +
                '<div class="kpo-pick-main"><b>' + esc(it.inv_log_part) + '</b>' +
                '<span class="muted">' + esc(it.label) + (it.rev_label && it.rev_label !== '—' ? ' · ' + esc(it.rev_label) : '') +
                stock + min + '</span></div>' + badge + '</li>';
        });
        html += '</ul>';
        searchResults.innerHTML = html;
    }

    function doSearch(q) {
        if (!searchResults) return;
        if (!q || q.length < 1) {
            searchResults.innerHTML = '<p class="muted">พิมพ์ชื่ออะไหล่เพื่อค้นจาก inventory</p>';
            return;
        }
        var gen = ++searchGen;
        searchResults.innerHTML = '<p class="muted">กำลังค้นหา…</p>';
        fetch(apiUrl + '?action=search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (gen !== searchGen) {
                    return;
                }
                if (!data.ok) {
                    searchResults.innerHTML = '<p class="text-danger">' + esc(data.error || 'ค้นหาไม่สำเร็จ') + '</p>';
                    return;
                }
                if (data.inv_connected === false && data.inv_error) {
                    searchResults.innerHTML = '<p class="text-warn">' + esc(data.inv_error) + '</p>';
                    return;
                }
                renderSearch(data.items || []);
            })
            .catch(function () {
                searchResults.innerHTML = '<p class="text-danger">เชื่อมต่อไม่สำเร็จ</p>';
            });
    }

    function removeCatalogItem(key, label) {
        if (!key) {
            return;
        }
        var title = label ? ('เอา «' + label + '» ออกจากรายการสรุป?') : 'เอารายการนี้ออกจากหน้าสรุป?';
        if (!window.confirm(title + '\n\nยอด log ใน inventory ไม่ถูกลบ — แค่ไม่แสดงบนหน้านี้')) {
            return;
        }
        var body = new FormData();
        body.append('action', 'remove');
        body.append('csrf', csrf);
        body.append('key', key);
        fetch(apiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    window.alert(data.error || 'ลบไม่สำเร็จ');
                    return;
                }
                window.location.reload();
            })
            .catch(function () {
                window.alert('ลบไม่สำเร็จ');
            });
    }

    function saveCatalog() {
        rows = readEditorRows();
        setStatus('กำลังบันทึก…');
        var body = new FormData();
        body.append('action', 'save');
        body.append('csrf', csrf);
        body.append('items_json', JSON.stringify(rows));
        fetch(apiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    setStatus(data.error || 'บันทึกไม่สำเร็จ', true);
                    return;
                }
                rows = data.items || rows;
                window.location.reload();
            })
            .catch(function () {
                setStatus('บันทึกไม่สำเร็จ', true);
            });
    }

    function prepareModal(focusKey) {
        rows = Array.isArray(cfg.initialRows) ? cfg.initialRows.slice() : [];
        syncDismissedFromRows();
        searchGen += 1;
        renderEditor(focusKey || '');
        setStatus('');
        if (searchInput) {
            searchInput.value = '';
        }
        if (searchResults) {
            searchResults.innerHTML = searchResultsPlaceholderHtml();
        }
    }

    document.addEventListener('click', function (ev) {
        var rm = ev.target.closest('.kpo-card-remove');
        if (rm) {
            ev.preventDefault();
            ev.stopPropagation();
            removeCatalogItem(
                rm.getAttribute('data-kpo-remove-key') || '',
                rm.getAttribute('data-kpo-remove-label') || ''
            );
            return;
        }
        var btn = ev.target.closest('[data-open-modal="kpo-catalog-modal"]');
        if (!btn) return;
        prepareModal(btn.getAttribute('data-kpo-focus-key') || '');
    }, true);

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            searchTimer = setTimeout(function () { doSearch(q); }, 280);
        });
    }

    modal.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.kpo-pick-add');
        if (!btn || !searchResults || !searchResults.contains(btn)) {
            return;
        }
        ev.preventDefault();
        var li = btn.closest('.kpo-catalog-pick');
        if (!li || li.getAttribute('data-in-catalog') === '1') {
            return;
        }
        var inv = li.getAttribute('data-inv') || '';
        var key = li.getAttribute('data-key') || '';
        var added = addRowFromPick({
            inv_log_part: inv,
            label: li.getAttribute('data-label') || '',
            rev_label: li.getAttribute('data-rev') || '—',
            suggested_key: key,
            in_catalog: false
        });
        if (added) {
            collapseSearchResults();
        }
    });

    if (editorBody) {
        editorBody.addEventListener('click', function (ev) {
            var rm = ev.target.closest('.kpo-ed-remove');
            if (!rm) return;
            var tr = rm.closest('tr');
            if (!tr || !editorBody) return;
            if (editorBody.querySelectorAll('tr').length <= 1) {
                setStatus('ต้องเหลืออย่างน้อย 1 รายการ', true);
                return;
            }
            tr.remove();
            rows = readEditorRows();
            setStatus('');
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', saveCatalog);
    }

})();
