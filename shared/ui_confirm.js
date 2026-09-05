/* shared/ui_confirm.js — กล่องยืนยันที่บอกผลกระทบ แทน confirm() บรรทัดเดียว
   ────────────────────────────────────────────────────────────────────────────
   confirm() เดิมบอกได้แค่ "ลบรายการเบิกนี้?" ไม่บอกว่ายอดคงเหลือจะเปลี่ยนเท่าไร
   และไม่มีทางรู้ว่าใบนี้เคยตัดสต็อกจริงหรือเปล่า — ใบที่ไม่เคยตัด (155 จาก 428 ใบ)
   ลบแล้วยอดจะไม่ขยับ ซึ่งตรงข้ามกับที่คนกดคาดไว้

   ฟอร์มเดิมเปลี่ยนแค่ attribute ฝั่ง POST ไม่ต้องแก้:
     <form method="POST"
           data-confirm="ลบใบเบิก SO-2569-0182?"
           data-confirm-verb="ลบใบเบิกนี้"
           data-confirm-impact='[{"name":"USB Hub 2 Port","from":128,"to":132}]'
           data-confirm-warn="ใบนี้ไม่เคยตัดสต็อก การลบจะไม่คืนของ">

   ปิด JavaScript แล้วฟอร์มยังส่งได้ตามปกติ — กล่องนี้เป็นชั้นเสริม ไม่ใช่ประตู
   ──────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(n) {
    var v = Number(n);
    return isFinite(v) ? v.toLocaleString('th-TH') : String(n);
  }

  function impactRows(list) {
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var it = list[i];
      var same = Number(it.from) === Number(it.to);
      html += '<div class="cf-row"><span>' + esc(it.name) + '</span><b>'
            + num(it.from) + ' → ' + num(it.to)
            + (same ? ' <i>(ไม่เปลี่ยน)</i>' : '')
            + '</b></div>';
    }
    return html;
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || form.nodeName !== 'FORM') { return; }
    if (!form.getAttribute('data-confirm') || form.getAttribute('data-confirmed') === '1') { return; }
    ev.preventDefault();

    // ปุ่มที่กดมา — ไว้คืนโฟกัสให้หลังปิดกล่อง (ผู้ใช้คีย์บอร์ดจะได้ไม่หลุดไปต้นหน้า)
    var opener = document.activeElement;

    var impact = [];
    try { impact = JSON.parse(form.getAttribute('data-confirm-impact') || '[]') || []; } catch (e) { impact = []; }
    var warn = form.getAttribute('data-confirm-warn') || '';
    var rows = impact.length ? impactRows(impact) : '';

    var dlg = document.createElement('dialog');
    dlg.className = 'cf-dialog';
    dlg.innerHTML =
        '<h2>' + esc(form.getAttribute('data-confirm')) + '</h2>'
      + (warn ? '<p class="cf-warn">' + esc(warn) + '</p>' : '')
      + (rows ? '<div class="cf-impact"><div class="cf-label">ยอดคงเหลือ ก่อน → หลัง</div>' + rows + '</div>' : '')
      + '<div class="cf-actions">'
      + '<button type="button" value="cancel" class="cf-btn-outline">ยกเลิก</button>'
      + '<button type="button" value="ok" class="cf-btn-danger">'
      + esc(form.getAttribute('data-confirm-verb') || 'ยืนยัน') + '</button>'
      + '</div>';
    document.body.appendChild(dlg);

    function close(ok) {
      try { dlg.close(); } catch (e) {}
      if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); }
      if (ok) {
        // ตั้งธงก่อนแล้วค่อย submit() — submit() ไม่ยิง event นี้ซ้ำอยู่แล้ว
        // แต่ธงกันไว้เผื่อหน้าไหนเรียก requestSubmit()
        form.setAttribute('data-confirmed', '1');
        form.submit();
      } else if (opener && document.contains(opener) && opener.focus) {
        opener.focus();
      }
    }

    dlg.addEventListener('click', function (e) {
      var v = e.target && e.target.value;
      if (v === 'ok' || v === 'cancel') { close(v === 'ok'); }
    });
    // Esc = ยกเลิก (ค่าเริ่มต้นของ <dialog> คือ cancel อยู่แล้ว)
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });

    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    } else {
      // เบราว์เซอร์เก่าที่ไม่มี <dialog> — ถอยไปใช้ confirm() เดิม ดีกว่าลบเงียบ ๆ
      if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); }
      if (window.confirm(form.getAttribute('data-confirm'))) { close(true); }
      return;
    }
    // ค่าเริ่มต้นอยู่ที่ทางที่ปลอดภัย — เผลอกด Enter ต้องไม่ลบ
    var cancelBtn = dlg.querySelector('[value=cancel]');
    if (cancelBtn) { cancelBtn.focus(); }
  });
})();
