/**
 * kpo-drill.js — popup รายละเอียดยืนยันการเบิกจาก inventory (pre_stock + log_stock)
 */
(function () {
    'use strict';

    var rows = Array.isArray(window.KPO_DRILL_ROWS) ? window.KPO_DRILL_ROWS : [];
    var drill = document.getElementById('kpo-drill');
    var modal = document.getElementById('kpo-drill-detail-modal');
    var dl = document.getElementById('kpo-drill-detail-dl');
    var titleEl = document.getElementById('kpo-drill-detail-modal-title');

    if (!drill || !modal || !dl || !rows.length) {
        return;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function openModal() {
        modal.hidden = false;
        if (document.body.classList) {
            document.body.classList.add('modal-open');
        }
    }

    function addRow(label, valueHtml) {
        if (!valueHtml) {
            valueHtml = '—';
        }
        return '<div><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
    }

    function showDetail(idx) {
        var row = rows[idx];
        if (!row) {
            return;
        }
        var pk = row.pickup && typeof row.pickup === 'object' ? row.pickup : {};
        var doc = row.doc_no || '—';
        if (titleEl) {
            titleEl.textContent = doc !== '—' ? ('ใบ ' + doc) : 'รายละเอียดจาก inventory';
        }

        var confirmed = !!pk.is_pickup_confirmed;
        var statusHtml = esc(pk.pre_status_label || '—');
        if (confirmed) {
            statusHtml = '<span class="kpo-detail-ok">' + statusHtml + '</span>';
        } else if (pk.pre_status === 'cancel') {
            statusHtml = '<span class="kpo-detail-warn">' + statusHtml + '</span>';
        }

        var html = '';
        html += addRow('เลขใบ (pre_id)', esc(doc));
        html += addRow('สถานะใบเบิก', statusHtml);
        if (!pk.has_pre_line) {
            html += addRow('หมายเหตุ', '<span class="muted">ไม่พบแถว pre_stock ของอะไหล่นี้ในใบ — มีเฉพาะ log_stock</span>');
        }
        html += addRow('ผู้เบิก (pre_by)', esc(pk.requester || row.made_by || '—'));
        html += addRow('วันที่ในใบ', esc(pk.pre_date || '—'));
        html += addRow('จำนวนในใบ', pk.pre_amount != null ? esc(String(pk.pre_amount)) : '—');
        html += addRow('ผู้บันทึก log คลัง (log_by)', esc(pk.log_by || '—'));
        html += addRow('วันที่ log (log_date)', esc(pk.log_date_raw || row.moved_at || '—'));
        html += addRow('จำนวนใน log', esc(String(row.qty != null ? row.qty : '—')));
        html += addRow('mode', esc(row.mode || '—'));
        html += addRow('หมายเหตุการเบิก', esc(row.remark || '—'));
        if (pk.maintenance_job_url) {
            var mLabel = pk.maintenance_job_label || ('งาน #' + (pk.maintenance_ts_id || ''));
            html += addRow(
                'งานซ่อม (maintenance)',
                '<a class="kpo-detail-ext-link" href="' + esc(pk.maintenance_job_url) + '" target="_blank" rel="noopener noreferrer">'
                    + esc(mLabel) + '</a>'
            );
        }
        if (pk.production_pickup_url) {
            var pLabel = pk.production_pickup_label || 'เปิดใบเบิกรอผลิต';
            html += addRow(
                'ใบเบิกผลิต (production)',
                '<a class="kpo-detail-ext-link" href="' + esc(pk.production_pickup_url) + '" target="_blank" rel="noopener noreferrer">'
                    + esc(pLabel) + '</a>'
            );
        }
        if (pk.production_assets && pk.production_assets.length) {
            var snHtml = pk.production_assets.map(function (a) {
                if (a && a.url) {
                    return '<a class="kpo-detail-ext-link" href="' + esc(a.url) + '" target="_blank" rel="noopener noreferrer">'
                        + esc(a.serial || ('#' + a.id)) + '</a>';
                }
                return esc(a && a.serial ? a.serial : '—');
            }).join(', ');
            html += addRow('เครื่องที่ตัดจากใบนี้', snHtml);
        }

        dl.innerHTML = html;
        openModal();
    }

    drill.addEventListener('click', function (ev) {
        var tr = ev.target.closest('tr.kpo-drill-row');
        if (!tr || !drill.contains(tr)) {
            return;
        }
        var idx = parseInt(tr.getAttribute('data-kpo-row'), 10);
        if (Number.isNaN(idx)) {
            return;
        }
        showDetail(idx);
    });

    drill.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') {
            return;
        }
        var tr = ev.target.closest('tr.kpo-drill-row');
        if (!tr) {
            return;
        }
        ev.preventDefault();
        var idx = parseInt(tr.getAttribute('data-kpo-row'), 10);
        if (!Number.isNaN(idx)) {
            showDetail(idx);
        }
    });
})();
