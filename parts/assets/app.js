/**
 * app.js — sidebar overlay (เมาส์ชิดขอบซ้ายเปิดทันที) + modal S/N basket
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

    /* ─ Modal S/N basket ─ */
    var modal = document.getElementById('sn-basket-modal');
    var modalBody = document.getElementById('sn-basket-body');
    var basePath = document.querySelector('link[href*="style.css"]');
    var partsBase = basePath ? basePath.getAttribute('href').replace(/\/assets\/style\.css.*$/, '') : '';

    function openBasket(sn) {
        if (!modal || !modalBody || !sn) return;
        modalBody.innerHTML = '<p class="text-muted text-center" style="padding:2rem">กำลังโหลด…</p>';
        modal.hidden = false;
        document.body.classList.add('modal-open');

        fetch(partsBase + '/pages/history.php?ajax=sn_basket&sn=' + encodeURIComponent(sn))
            .then(function (r) { return r.text(); })
            .then(function (html) {
                modalBody.innerHTML = html;
            })
            .catch(function () {
                modalBody.innerHTML = '<p class="text-danger">โหลดข้อมูลไม่สำเร็จ</p>';
            });
    }

    function closeBasket() {
        if (!modal) return;
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', function (e) {
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
        if (e.target.closest('.modal-close') || e.target === modal) {
            closeBasket();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (body.classList.contains('sidebar-hover')) {
                hideSidebar();
            } else {
                closeBasket();
            }
        }
    });
})();
