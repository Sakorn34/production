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
                    if (iconPid) iconPid.value = pid;
                    if (iconName) iconName.textContent = pname;
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

    document.querySelectorAll('.product-active-switch').forEach(function (input) {
        input.addEventListener('change', function () {
            var form = input.closest('form');
            if (form) form.submit();
        });
    });
})();
