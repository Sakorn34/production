/**
 * sidebar.js — docked rail sidebar: หุบ/ขยาย, ค้นหาอัจฉริยะ, user popover, localStorage
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'fg-sidebar-expanded';
    var SEARCH_MIN_LEN = 2;
    var HISTORY_KEY = 'fg-search-history';
    var HISTORY_MAX = 5;

    /**
     * @param {object} opts
     * @param {HTMLElement} [opts.appEl]
     * @param {HTMLElement} [opts.sidebarEl]
     * @param {HTMLElement} [opts.backdropEl]
     * @param {string} [opts.storageKey]
     * @param {string} [opts.smartSearchUrl]  URL ค้นหาอัจฉริยะ (production)
     */
    function initAppSidebar(opts) {
        opts = opts || {};
        var app = opts.appEl || document.querySelector('.app');
        var sidebar = opts.sidebarEl || (app && (app.querySelector('.sidebar') || document.getElementById('app-sidebar')));
        var backdrop = opts.backdropEl || document.getElementById('fg-nav-backdrop') || document.getElementById('sidebar-backdrop');
        var storageKey = opts.storageKey || STORAGE_KEY;

        if (!app || !sidebar || app.classList.contains('sidebar-top')) {
            return;
        }

        var pinBtn = sidebar.querySelector('.sidebar-pin-btn');
        var searchInput = sidebar.querySelector('.sidebar-search-input');
        var searchMiniBtn = sidebar.querySelector('.sidebar-search-mini');
        var userTrigger = sidebar.querySelector('.userbox-trigger');
        var userPopover = sidebar.querySelector('.userbox-popover');
        var navRoot = sidebar.querySelector('.sidebar-nav') || sidebar.querySelector('nav') || sidebar;
        var searchWrap = sidebar.querySelector('.sidebar-search');
        var smartSearchUrl = opts.smartSearchUrl
            || (searchWrap && (searchWrap.dataset.smartSearch || searchWrap.dataset.assetSuggest))
            || '';
        var smartAppBase = smartSearchUrl.replace(/\/smart_search\.php(\?.*)?$/, '')
            .replace(/\/assets\.php(\?.*)?$/, '');

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function isExpanded() {
            return app.classList.contains('nav-expanded');
        }

        function setExpanded(expanded, persist) {
            app.classList.toggle('nav-expanded', expanded);
            app.classList.toggle('nav-collapsed', !expanded);
            if (typeof hideTip === 'function') hideTip();
            if (pinBtn) {
                pinBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                pinBtn.setAttribute('aria-label', expanded ? 'หุบเมนู' : 'ขยายเมนู');
            }
            if (!expanded) {
                closeUserPopover();
                if (searchInput) {
                    searchInput.value = '';
                    hideSmartSuggest();
                }
            }
            syncTooltips();
            if (persist !== false) {
                try {
                    localStorage.setItem(storageKey, expanded ? '1' : '0');
                } catch (e) { /* ignore */ }
            }
            if (backdrop) {
                backdrop.hidden = !(expanded && window.matchMedia('(max-width: 800px)').matches);
            }
        }

        function toggleExpanded() {
            setExpanded(!isExpanded());
        }

        function loadExpanded() {
            try {
                return localStorage.getItem(storageKey) === '1';
            } catch (e) {
                return false;
            }
        }

        function syncTooltips() {
            var collapsed = app.classList.contains('nav-collapsed');
            navRoot.querySelectorAll('a.nav-cross, .nav-links a, nav a').forEach(function (a) {
                if (a.classList.contains('userbox-trigger')) return;
                var textEl = a.querySelector('.nav-text');
                var label = textEl ? textEl.textContent.trim() : a.textContent.trim();
                if (collapsed && label) {
                    a.setAttribute('data-tooltip', label);
                } else {
                    a.removeAttribute('data-tooltip');
                }
            });
        }

        function openUserPopover() {
            if (!userPopover || !userTrigger) return;
            userPopover.hidden = false;
            userTrigger.setAttribute('aria-expanded', 'true');
        }

        function closeUserPopover() {
            if (!userPopover || !userTrigger) return;
            userPopover.hidden = true;
            userTrigger.setAttribute('aria-expanded', 'false');
        }

        function toggleUserPopover() {
            if (!userPopover) return;
            if (userPopover.hidden) openUserPopover();
            else closeUserPopover();
        }

        var suggestBox = null;
        var searchTimer = null;
        var lastResults = [];

        function hideSmartSuggest() {
            if (suggestBox) suggestBox.hidden = true;
            lastResults = [];
        }

        // ── ประวัติการค้นหา ──
        // เก็บที่เครื่องผู้ใช้ ไม่ใช่ที่ server: คำค้นเป็นของส่วนตัวคนใช้ ไม่ใช่ข้อมูลระบบ
        // และไม่ควรมีใครอื่นเห็นว่าใครค้นหาอะไร · localStorage อาจถูกปิด จึงห่อ try ทุกที่
        function loadHistory() {
            try {
                var raw = localStorage.getItem(HISTORY_KEY);
                var arr = raw ? JSON.parse(raw) : [];
                return Array.isArray(arr) ? arr.filter(function (x) { return typeof x === 'string' && x; }) : [];
            } catch (e) { return []; }
        }
        function saveHistory(list) {
            try { localStorage.setItem(HISTORY_KEY, JSON.stringify(list.slice(0, HISTORY_MAX))); }
            catch (e) { /* โหมดส่วนตัว/ปิด storage — ประวัติหายได้ ไม่ใช่เรื่องคอขาดบาดตาย */ }
        }
        function rememberQuery(q) {
            q = (q || '').trim();
            if (q.length < SEARCH_MIN_LEN) return;
            var list = loadHistory().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); });
            list.unshift(q);
            saveHistory(list);
        }
        function forgetQuery(q) {
            saveHistory(loadHistory().filter(function (x) { return x !== q; }));
        }
        function renderHistory() {
            if (!suggestBox) return false;
            var list = loadHistory();
            if (!list.length) return false;
            lastResults = [];
            suggestBox.innerHTML = '<div class="sidebar-smart-head">ค้นหาล่าสุด'
                + '<button type="button" class="sidebar-hist-clear" data-hist-clear="1">ล้างทั้งหมด</button></div>'
                + list.map(function (q) {
                    return '<div class="sidebar-hist-row">'
                        + '<button type="button" class="sidebar-hist-item" data-hist="' + escHtml(q) + '">'
                        + '<span class="sidebar-hist-ic" aria-hidden="true">↺</span>'
                        + '<span class="sidebar-hist-q">' + escHtml(q) + '</span></button>'
                        + '<button type="button" class="sidebar-hist-x" data-hist-del="' + escHtml(q) + '"'
                        + ' aria-label="ลบ ' + escHtml(q) + ' ออกจากประวัติ">×</button>'
                        + '</div>';
                }).join('');
            suggestBox.hidden = false;
            return true;
        }

        // ผลจากระบบอื่น (ขาย/เคลม/เช่า) อยู่คนละเว็บ กดแล้วออกจากระบบนี้
        function isExternalHref(href) {
            return !!href && href.indexOf('http') === 0
                && href.indexOf(window.location.origin) !== 0;
        }

        function goHref(href) {
            if (!href) return;
            // ผลจากระบบอื่น (ประวัติขาย/เคลม) อยู่คนละเว็บ — เปิดแท็บใหม่
            // ไม่งั้นผู้ใช้ที่แค่อยากเช็คว่าขายให้ใคร จะหลุดออกจากงานที่ทำค้างอยู่
            if (isExternalHref(href)) {
                window.open(href, '_blank', 'noopener');
                return;
            }
            if (href.indexOf('http') === 0 || href.indexOf('/') === 0) {
                window.location.href = href;
                return;
            }
            window.location.href = smartAppBase + '/' + href.replace(/^\//, '');
        }

        function renderSmartSuggest(items) {
            if (!suggestBox) return;
            lastResults = items || [];
            if (!lastResults.length) {
                suggestBox.innerHTML = '<div class="sidebar-smart-empty">ไม่พบ S/N, MA หรืออัปเดตที่ตรง</div>';
                suggestBox.hidden = false;
                return;
            }
            suggestBox.innerHTML = lastResults.map(function (it) {
                // ลูกศรบอกว่ากดแล้วออกไปเว็บอื่น ผูกกับ href จริง ไม่ใช่ประเภทของผล —
                // เครื่องเช่าตัวเดียวกันไปหน้าเครื่องของเราได้ถ้าจับคู่ S/N ติด
                var ext = isExternalHref(it.href) ? ' is-ext' : '';
                return '<button type="button" class="sidebar-smart-item" data-href="' + escHtml(it.href || '') + '">'
                    + '<span class="sidebar-smart-row">'
                    + '<span class="sidebar-smart-kind' + ext + ' sidebar-smart-kind-' + escHtml(it.kind || 'asset') + '">'
                    + escHtml(it.kind_label || '') + '</span>'
                    + '<span class="sidebar-smart-title">' + escHtml(it.title || it.code || '') + '</span>'
                    + '</span>'
                    + '<span class="sidebar-smart-meta">' + escHtml(it.subtitle || '') + '</span>'
                    + '</button>';
            }).join('');
            suggestBox.hidden = false;
        }

        function fetchSmartSuggest(q) {
            if (!smartSearchUrl) return;
            fetch(smartSearchUrl + '?ajax=1&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (items) { renderSmartSuggest(items); })
                .catch(function () { hideSmartSuggest(); });
        }

        if (searchInput && smartSearchUrl && searchWrap) {
            suggestBox = document.createElement('div');
            suggestBox.className = 'sidebar-smart-suggest';
            suggestBox.hidden = true;
            searchWrap.appendChild(suggestBox);

            suggestBox.addEventListener('click', function (e) {
                var del = e.target.closest('[data-hist-del]');
                if (del) {
                    forgetQuery(del.getAttribute('data-hist-del'));
                    if (!renderHistory()) hideSmartSuggest();
                    if (searchInput) searchInput.focus();
                    return;
                }
                if (e.target.closest('[data-hist-clear]')) {
                    saveHistory([]);
                    hideSmartSuggest();
                    if (searchInput) searchInput.focus();
                    return;
                }
                var hist = e.target.closest('[data-hist]');
                if (hist) {
                    var hq = hist.getAttribute('data-hist');
                    if (searchInput) { searchInput.value = hq; searchInput.focus(); }
                    fetchSmartSuggest(hq);
                    return;
                }
                var btn = e.target.closest('.sidebar-smart-item');
                if (!btn) return;
                // จำคำที่พาไปถึงผลจริง ไม่ใช่ทุกตัวอักษรที่พิมพ์ระหว่างทาง
                rememberQuery(searchInput ? searchInput.value : '');
                goHref(btn.getAttribute('data-href'));
            });

            document.addEventListener('click', function (e) {
                if (!searchWrap.contains(e.target)) hideSmartSuggest();
            });
        }

        app.classList.add('nav-docked');
        // ── ป้ายชื่อเมนูตอนหุบ — ใบเดียวลอยที่ body หนี overflow ของ sidebar ──
        var tipEl = document.querySelector('.sidebar-tip');
        if (!tipEl) {
            tipEl = document.createElement('div');
            tipEl.className = 'sidebar-tip';
            tipEl.setAttribute('role', 'tooltip');
            document.body.appendChild(tipEl);
        }
        function showTip(a) {
            if (!app.classList.contains('nav-collapsed')) return;
            var label = a.getAttribute('data-tooltip');
            if (!label) return;
            var r = a.getBoundingClientRect();
            tipEl.textContent = label;
            tipEl.style.top = (r.top + r.height / 2) + 'px';
            if (app.classList.contains('sidebar-right')) {
                tipEl.style.left = 'auto';
                tipEl.style.right = (window.innerWidth - r.left + 10) + 'px';
            } else {
                tipEl.style.right = 'auto';
                tipEl.style.left = (r.right + 10) + 'px';
            }
            tipEl.classList.add('is-on');
        }
        function hideTip() { tipEl.classList.remove('is-on'); }

        sidebar.addEventListener('mouseover', function (e) {
            var a = e.target.closest ? e.target.closest('[data-tooltip]') : null;
            if (a && sidebar.contains(a)) showTip(a);
        });
        sidebar.addEventListener('mouseout', function (e) {
            var a = e.target.closest ? e.target.closest('[data-tooltip]') : null;
            if (a) hideTip();
        });
        // คีย์บอร์ดก็ต้องเห็นป้าย ไม่ใช่แค่เมาส์
        sidebar.addEventListener('focusin', function (e) {
            var a = e.target.closest ? e.target.closest('[data-tooltip]') : null;
            if (a) showTip(a);
        });
        sidebar.addEventListener('focusout', hideTip);
        // ป้ายลอยแบบ fixed ไม่เลื่อนตามเนื้อหา ต้องซ่อนเมื่อมีการเลื่อน
        sidebar.addEventListener('scroll', hideTip, true);
        window.addEventListener('scroll', hideTip, { passive: true });
        setExpanded(loadExpanded(), false);

        if (pinBtn) {
            pinBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleExpanded();
            });
        }

        // ปุ่มแว่นในรางแคบ: ขยายเมนูก่อน แล้วค่อยโฟกัสช่องค้นหา
        // รอ 280ms ให้ transition ของกล่อง (260ms) จบก่อน ไม่งั้นโฟกัสตอนช่องยังกว้าง 0
        // แล้วเบราว์เซอร์จะเลื่อนหน้าไปหา element ที่มองไม่เห็น
        if (searchMiniBtn) {
            searchMiniBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                setExpanded(true, true);
                if (searchInput) {
                    setTimeout(function () {
                        try { searchInput.focus({ preventScroll: true }); }
                        catch (err) { searchInput.focus(); }
                    }, 280);
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim();
                clearTimeout(searchTimer);
                if (!smartSearchUrl || q.length < SEARCH_MIN_LEN) {
                    // ช่องว่าง (หรือสั้นเกินจะค้น) = โอกาสดีที่จะเสนอคำที่เคยค้น
                    if (!q && renderHistory()) return;
                    hideSmartSuggest();
                    return;
                }
                searchTimer = setTimeout(function () {
                    fetchSmartSuggest(q);
                }, 280);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                var q = searchInput.value.trim();
                if (!q || !smartSearchUrl) return;
                e.preventDefault();
                rememberQuery(q);
                if (lastResults.length >= 1) {
                    goHref(lastResults[0].href);
                    return;
                }
                goHref('asset.php?code=' + encodeURIComponent(q));
            });
            searchInput.addEventListener('focus', function () {
                if (!isExpanded()) setExpanded(true);
                var q = searchInput.value.trim();
                if (smartSearchUrl && q.length >= SEARCH_MIN_LEN && lastResults.length) {
                    suggestBox.hidden = false;
                    return;
                }
                if (!q) renderHistory();
            });
        }

        if (userTrigger) {
            userTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!isExpanded()) {
                    setExpanded(true);
                    openUserPopover();
                    return;
                }
                toggleUserPopover();
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setExpanded(false);
            });
        }

        document.addEventListener('click', function (e) {
            if (userPopover && !userPopover.hidden) {
                if (!userPopover.contains(e.target) && !userTrigger.contains(e.target)) {
                    closeUserPopover();
                }
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (userPopover && !userPopover.hidden) {
                closeUserPopover();
                e.preventDefault();
                return;
            }
            if (isExpanded() && window.matchMedia('(max-width: 800px)').matches) {
                setExpanded(false);
            }
        });

        window.addEventListener('resize', function () {
            if (backdrop) {
                backdrop.hidden = !(isExpanded() && window.matchMedia('(max-width: 800px)').matches);
            }
        });

        syncTooltips();
    }

    global.initAppSidebar = initAppSidebar;
}(typeof window !== 'undefined' ? window : this));
