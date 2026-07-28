/**
 * app.js — sidebar overlay + modal ร่วมทุกหน้า Parts
 */
(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('app-sidebar');
    var edge = document.querySelector('.sidebar-edge');
    var toggle = document.getElementById('sidebar-toggle');
    var backdrop = document.getElementById('sidebar-backdrop');
    var hideTimer = null;

    function showSidebar() {
        clearTimeout(hideTimer);
        body.classList.add('sidebar-hover');
    }

    function hideSidebar() {
        clearTimeout(hideTimer);
        body.classList.remove('sidebar-hover');
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hideSidebar, 280);
    }

    if (edge) {
        edge.addEventListener('mouseenter', showSidebar);
        edge.addEventListener('mouseleave', function (e) {
            if (sidebar && sidebar.contains(e.relatedTarget)) return;
            scheduleHide();
        });
        edge.addEventListener('click', showSidebar);
    }

    if (sidebar) {
        sidebar.addEventListener('mouseenter', function () {
            clearTimeout(hideTimer);
            showSidebar();
        });
        sidebar.addEventListener('mouseleave', scheduleHide);
    }
    if (toggle) {
        toggle.addEventListener('click', function () {
            if (body.classList.contains('sidebar-hover')) hideSidebar();
            else showSidebar();
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', hideSidebar);
    }

    document.querySelectorAll('.sidebar .nav-links a, .sidebar .nav-cross').forEach(function (a) {
        a.addEventListener('click', hideSidebar);
    });

    /* ─ Modal ร่วม ─ */
    var basePath = document.querySelector('link[href*="style.css"]');
    var partsBase = basePath ? basePath.getAttribute('href').replace(/\/assets\/style\.css.*$/, '') : '';

    function anyModalOpen() {
        return !!document.querySelector('.modal-overlay:not([hidden])');
    }

    function updateBodyModalState() {
        if (anyModalOpen()) body.classList.add('modal-open');
        else body.classList.remove('modal-open');
    }

    function openModal(id) {
        var modal = id ? document.getElementById(id) : null;
        if (!modal) return;
        modal.hidden = false;
        updateBodyModalState();
        var focus = modal.querySelector('[data-autofocus], input:not([type=hidden]):not([readonly]), select, textarea');
        if (focus) setTimeout(function () { focus.focus(); }, 50);
        initProductSearchIn(modal);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        updateBodyModalState();
    }

    function closeAllModals() {
        document.querySelectorAll('.modal-overlay:not([hidden])').forEach(closeModal);
    }

    /* ─ Modal S/N basket ─ */
    var snModal = document.getElementById('sn-basket-modal');
    var snModalBody = document.getElementById('sn-basket-body');

    /* ─ กรองประเภทการเบิก (หน้า history) ─ */
    var historyFilters = document.querySelectorAll('[data-history-filter]');
    var historyBody = document.getElementById('history-list-body');
    var historyEmptyRow = document.getElementById('history-filter-empty');

    function applyHistoryKindFilter(kind) {
        if (!historyBody) return;
        var rows = historyBody.querySelectorAll('tr[data-withdraw-kinds]');
        var visible = 0;
        rows.forEach(function (row) {
            var kinds = (row.getAttribute('data-withdraw-kinds') || '').split(',');
            var show = kind === 'all' || kinds.indexOf(kind) !== -1;
            row.hidden = !show;
            if (show) visible++;
        });
        if (historyEmptyRow) {
            historyEmptyRow.hidden = kind === 'all' || visible > 0;
        }
    }

    historyFilters.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var kind = btn.getAttribute('data-history-filter') || 'all';
            historyFilters.forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            applyHistoryKindFilter(kind);
        });
    });

    function openBasket(sn) {
        if (!snModal || !snModalBody || !sn) return;
        snModalBody.innerHTML = '<p class="text-muted text-center" style="padding:2rem">กำลังโหลด…</p>';
        openModal('sn-basket-modal');

        fetch(partsBase + '/pages/history.php?ajax=sn_basket&sn=' + encodeURIComponent(sn))
            .then(function (r) { return r.text(); })
            .then(function (html) {
                snModalBody.innerHTML = html;
            })
            .catch(function () {
                snModalBody.innerHTML = '<p class="text-danger">โหลดข้อมูลไม่สำเร็จ</p>';
            });
    }

    /* ─ ค้นหาอะไหล่ใน select (modal / หน้าเบิกรายชิ้น) ─ */
    function initProductSearchIn(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-product-search]').forEach(function (searchInput) {
            if (searchInput.dataset.bound === '1') return;
            searchInput.dataset.bound = '1';
            var selectId = searchInput.getAttribute('data-product-search');
            var productSelect = selectId ? document.getElementById(selectId) : null;
            if (!productSelect) return;
            var originalOptions = Array.from(productSelect.options).map(function (option) {
                return { value: option.value, text: option.text, disabled: option.disabled };
            });
            searchInput.addEventListener('input', function () {
                var query = searchInput.value.trim().toLowerCase();
                productSelect.innerHTML = '';
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '-- เลือกอะไหล่ --';
                productSelect.appendChild(placeholder);
                originalOptions.forEach(function (optionData) {
                    if (!optionData.value) return;
                    if (!query || optionData.text.toLowerCase().includes(query)) {
                        var option = document.createElement('option');
                        option.value = optionData.value;
                        option.textContent = optionData.text;
                        if (optionData.disabled) option.disabled = true;
                        productSelect.appendChild(option);
                    }
                });
            });
        });
    }

    /* ─ combobox เลือกอะไหล่ (รูป + ชื่อ + คงเหลือ) ─ */
    function initProductPickers(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-product-picker]').forEach(function (picker) {
            if (picker.dataset.bound === '1') return;
            picker.dataset.bound = '1';

            var products = [];
            try {
                products = JSON.parse(picker.getAttribute('data-products') || '[]');
            } catch (err) {
                products = [];
            }

            var hidden = picker.querySelector('input[type="hidden"]');
            var input = picker.querySelector('.parts-product-picker-input');
            var list = picker.querySelector('.parts-product-picker-list');
            var thumb = picker.querySelector('.parts-product-picker-thumb');
            var clearBtn = picker.querySelector('.parts-product-picker-clear');
            if (!hidden || !input || !list || !thumb || !clearBtn) return;

            var activeIndex = -1;
            var visibleOptions = [];

            function findProduct(id) {
                id = String(id);
                for (var i = 0; i < products.length; i++) {
                    if (String(products[i].id) === id) return products[i];
                }
                return null;
            }

            function formatStock(p) {
                return 'คงเหลือ: ' + p.qty + ' ' + (p.unit || 'ชิ้น');
            }

            function formatSelectedLabel(p) {
                return p.label + ' (' + formatStock(p) + ')';
            }

            function setThumb(url, alt) {
                thumb.innerHTML = '';
                thumb.classList.add('is-empty');
                if (!url) return;
                var img = document.createElement('img');
                img.src = url;
                img.alt = alt || '';
                img.className = 'parts-product-picker-thumb-img';
                img.onerror = function () {
                    thumb.innerHTML = '';
                    thumb.classList.add('is-empty');
                };
                thumb.classList.remove('is-empty');
                thumb.appendChild(img);
            }

            function closeList() {
                list.hidden = true;
                activeIndex = -1;
                list.querySelectorAll('.parts-product-picker-option.is-active').forEach(function (el) {
                    el.classList.remove('is-active');
                });
            }

            function selectProduct(p) {
                if (!p || p.disabled) return;
                hidden.value = String(p.id);
                input.value = formatSelectedLabel(p);
                setThumb(p.icon, p.name);
                clearBtn.hidden = false;
                closeList();
            }

            function clearSelection() {
                hidden.value = '';
                input.value = '';
                setThumb('', '');
                clearBtn.hidden = true;
                closeList();
                input.focus();
            }

            function renderList(query) {
                query = (query || '').trim().toLowerCase();
                list.innerHTML = '';
                activeIndex = -1;
                visibleOptions = products.filter(function (p) {
                    if (!query) return true;
                    var hay = (p.label + ' ' + p.code + ' ' + p.name).toLowerCase();
                    return hay.indexOf(query) !== -1;
                });
                if (visibleOptions.length === 0) {
                    closeList();
                    return;
                }
                visibleOptions.forEach(function (p, idx) {
                    var li = document.createElement('li');
                    li.className = 'parts-product-picker-option' + (p.disabled ? ' is-disabled' : '');
                    li.setAttribute('role', 'option');
                    li.dataset.id = String(p.id);
                    li.dataset.index = String(idx);

                    var optThumb = document.createElement('div');
                    optThumb.className = 'parts-product-picker-option-thumb' + (p.icon ? '' : ' is-empty');
                    if (p.icon) {
                        var img = document.createElement('img');
                        img.src = p.icon;
                        img.alt = '';
                        img.onerror = function () {
                            optThumb.classList.add('is-empty');
                            this.remove();
                        };
                        optThumb.appendChild(img);
                    }

                    var body = document.createElement('div');
                    body.className = 'parts-product-picker-option-body';
                    var title = document.createElement('div');
                    title.className = 'parts-product-picker-option-title';
                    title.textContent = p.label;
                    var stock = document.createElement('div');
                    stock.className = 'parts-product-picker-option-stock' + (p.disabled ? ' is-out' : '');
                    stock.textContent = p.disabled ? 'หมด (' + formatStock(p) + ')' : formatStock(p);
                    body.appendChild(title);
                    body.appendChild(stock);

                    li.appendChild(optThumb);
                    li.appendChild(body);
                    list.appendChild(li);
                });
                list.hidden = false;
            }

            function setActiveIndex(next) {
                var items = list.querySelectorAll('.parts-product-picker-option:not(.is-disabled)');
                if (!items.length) return;
                items.forEach(function (el) { el.classList.remove('is-active'); });
                if (next < 0) next = items.length - 1;
                if (next >= items.length) next = 0;
                activeIndex = next;
                items[activeIndex].classList.add('is-active');
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            }

            var presetId = picker.getAttribute('data-selected') || hidden.value;
            if (presetId) {
                var preset = findProduct(presetId);
                if (preset) selectProduct(preset);
            }

            input.addEventListener('focus', function () {
                renderList(hidden.value ? '' : input.value);
            });

            input.addEventListener('input', function () {
                if (hidden.value) {
                    hidden.value = '';
                    setThumb('', '');
                    clearBtn.hidden = true;
                }
                renderList(input.value);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (list.hidden) renderList(input.value);
                    setActiveIndex(activeIndex + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (list.hidden) renderList(input.value);
                    setActiveIndex(activeIndex - 1);
                } else if (e.key === 'Enter') {
                    if (!list.hidden) {
                        var active = list.querySelector('.parts-product-picker-option.is-active:not(.is-disabled)');
                        var target = active || list.querySelector('.parts-product-picker-option:not(.is-disabled)');
                        if (target) {
                            e.preventDefault();
                            selectProduct(findProduct(target.dataset.id));
                        }
                    }
                } else if (e.key === 'Escape') {
                    closeList();
                }
            });

            list.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });

            list.addEventListener('click', function (e) {
                var option = e.target.closest('.parts-product-picker-option:not(.is-disabled)');
                if (!option) return;
                selectProduct(findProduct(option.dataset.id));
            });

            clearBtn.addEventListener('click', function () {
                clearSelection();
            });

            document.addEventListener('click', function (e) {
                if (!picker.contains(e.target)) closeList();
            });
        });
    }

    document.addEventListener('click', function (e) {
        var fillBtn = e.target.closest('[data-fill-modal]');
        if (fillBtn) {
            var modalId = fillBtn.getAttribute('data-fill-modal') || fillBtn.getAttribute('data-open-modal');
            var modal = modalId ? document.getElementById(modalId) : null;
            if (modal) {
                var pid = fillBtn.getAttribute('data-product-id') || '';
                var pname = fillBtn.getAttribute('data-product-name') || '';
                var price = fillBtn.getAttribute('data-product-price');
                if (modalId === 'product-icon-modal') {
                    var iconPid = modal.querySelector('#icon-modal-product-id');
                    var iconName = modal.querySelector('#icon-modal-product-name');
                    var iconReturn = modal.querySelector('#icon-modal-return-to');
                    if (iconPid) iconPid.value = pid;
                    if (iconName) iconName.textContent = pname;
                    if (iconReturn) iconReturn.value = fillBtn.getAttribute('data-product-return-to') || '';
                    var iconFile = modal.querySelector('input[type="file"][name="icon"]');
                    if (iconFile) iconFile.value = '';
                }
                if (modalId === 'product-price-modal') {
                    var pricePid = modal.querySelector('#price-modal-product-id');
                    var priceName = modal.querySelector('#price-modal-product-name');
                    var priceInput = modal.querySelector('#price-modal-input');
                    if (pricePid) pricePid.value = pid;
                    if (priceName) priceName.textContent = pname;
                    if (priceInput) priceInput.value = price !== null && price !== '' ? price : '';
                }
                if (modalId === 'product-vendor-modal') {
                    var vendorPid = modal.querySelector('#vendor-modal-product-id');
                    var vendorName = modal.querySelector('#vendor-modal-product-name');
                    var vendorSupplier = modal.querySelector('#vendor-modal-supplier');
                    var vendorLink = modal.querySelector('#vendor-modal-purchase-link');
                    if (vendorPid) vendorPid.value = pid;
                    if (vendorName) vendorName.textContent = pname;
                    if (vendorSupplier) vendorSupplier.value = fillBtn.getAttribute('data-product-supplier') || '';
                    if (vendorLink) vendorLink.value = fillBtn.getAttribute('data-product-purchase-link') || '';
                }
                if (modalId === 'product-edit-modal') {
                    var editPid = modal.querySelector('#edit-modal-product-id');
                    var editReturn = modal.querySelector('#edit-modal-return-to');
                    var editCode = modal.querySelector('#edit-modal-product-code');
                    var editDisplay = modal.querySelector('#edit-modal-product-display-name');
                    var editName = modal.querySelector('#edit-modal-name');
                    var editPartCode = modal.querySelector('#edit-modal-part-code');
                    var editUnit = modal.querySelector('#edit-modal-unit');
                    var editMin = modal.querySelector('#edit-modal-min-stock');
                    var editPrice = modal.querySelector('#edit-modal-price');
                    var editSupplier = modal.querySelector('#edit-modal-supplier');
                    var editLink = modal.querySelector('#edit-modal-purchase-link');
                    if (editPid) editPid.value = pid;
                    if (editReturn) editReturn.value = fillBtn.getAttribute('data-product-return-to') || '';
                    if (editCode) editCode.textContent = fillBtn.getAttribute('data-product-code') || '';
                    if (editDisplay) editDisplay.textContent = pname;
                    if (editName) editName.value = fillBtn.getAttribute('data-product-local-name') || pname;
                    if (editPartCode) editPartCode.value = fillBtn.getAttribute('data-product-part-code') || '';
                    if (editUnit) editUnit.value = fillBtn.getAttribute('data-product-unit') || 'ชิ้น';
                    if (editMin) editMin.value = fillBtn.getAttribute('data-product-min-stock') || '0';
                    if (editPrice) editPrice.value = fillBtn.getAttribute('data-product-price') || '';
                    if (editSupplier) editSupplier.value = fillBtn.getAttribute('data-product-supplier') || '';
                    if (editLink) editLink.value = fillBtn.getAttribute('data-product-purchase-link') || '';
                }
            }
        }

        var openBtn = e.target.closest('[data-open-modal]');
        if (openBtn) {
            e.preventDefault();
            openModal(openBtn.getAttribute('data-open-modal'));
            return;
        }

        var btn = e.target.closest('[data-sn-basket]');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            openBasket(btn.getAttribute('data-sn-basket'));
            return;
        }
        var row = e.target.closest('[data-sn-basket-row]');
        if (row && !e.target.closest('.table-actions, a, button, form')) {
            openBasket(row.getAttribute('data-sn-basket-row'));
            return;
        }

        var overlay = e.target.closest('.modal-overlay');
        if (overlay && (e.target === overlay || e.target.closest('.modal-close, .modal-close-btn'))) {
            closeModal(overlay);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (body.classList.contains('sidebar-hover')) {
            hideSidebar();
            return;
        }
        if (anyModalOpen()) closeAllModals();
    });

    document.querySelectorAll('.modal-overlay[data-auto-open]').forEach(function (m) {
        if (m.id) openModal(m.id);
    });

    initProductSearchIn(document);
    initProductPickers(document);

    document.querySelectorAll('.product-active-switch').forEach(function (input) {
        input.addEventListener('change', function () {
            var form = input.closest('form');
            if (form) form.submit();
        });
    });
})();
