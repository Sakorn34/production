/* shared/ui_table.js — ทำให้กรอบรายการเลื่อนเอง หัวหน้าอยู่กับที่
   ────────────────────────────────────────────────────────────────────────────
   ก่อนหน้านี้ตรึงเฉพาะหัวตารางด้วย position:sticky แต่พอ "หน้า" เลื่อน ชื่อหน้า
   ปุ่ม และแถวค้นหาก็เลื่อนหายไปหมด เหลือแต่หัวตารางลอยอยู่ ซึ่งอ่านแล้วไม่รู้ว่า
   อยู่หน้าไหนและกรองอะไรไว้

   วิธีที่ใช้ตอนนี้: ให้ .table-wrap-fold สูงพอดีกับที่ว่างที่เหลือบนจอ แล้วเลื่อน
   ในตัวเอง — ทุกอย่างเหนือมัน (ชื่อหน้า ปุ่ม ตัวกรอง) จึงอยู่กับที่โดยไม่ต้อง
   ตรึงอะไรเลย และหัวตารางที่ sticky อยู่แล้วก็ติดขอบบนของกรอบ

   ความสูงคำนวณจากตำแหน่งจริงของกรอบ ไม่ใช่ค่าคงที่ เพราะแต่ละหน้ามีปุ่ม/ตัวกรอง
   ไม่เท่ากัน และผู้ใช้ปรับขนาดตัวอักษรได้ 90–140% (เคยลองใส่ตัวเลขตายตัวแล้ว
   หน้าไหนหัวสูงกว่าที่เดาก็เกินจอ หน้าเว็บเลยเลื่อนซ้อนอีกชั้น ได้ scrollbar สองอัน)
   ──────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var MIN_H = 240;          /* เตี้ยกว่านี้แล้วเห็นไม่กี่แถว สู้เลื่อนทั้งหน้าไม่ได้ */
  var CARD_MODE_MAX = 760;  /* ต้องตรงกับ breakpoint การ์ดใน ui_table.css */

  function apply() {
    var wraps = document.querySelectorAll('.table-wrap-fold');
    for (var i = 0; i < wraps.length; i++) {
      var w = wraps[i];
      if (window.innerWidth <= CARD_MODE_MAX) {
        // โหมดการ์ดบนมือถือ — ให้หน้าเลื่อนตามปกติ กรอบซ้อนบนจอเล็กใช้ยากกว่า
        w.style.maxHeight = '';
        w.style.overflowY = '';
        continue;
      }
      // ระยะจากขอบบน viewport ถึงหัวกรอบ + เผื่อขอบล่างไว้หายใจ
      var top = w.getBoundingClientRect().top + (window.pageYOffset || 0)
              - (document.documentElement.getBoundingClientRect().top + (window.pageYOffset || 0));
      var avail = window.innerHeight - top - 20;
      w.style.maxHeight = Math.max(MIN_H, avail) + 'px';
      w.style.overflowY = 'auto';

      // ใต้กรอบยังมีของอีก (แถบแบ่งหน้า ป้ายเวอร์ชัน ระยะขอบล่าง) ที่วัดล่วงหน้าไม่ได้
      // เพราะแต่ละหน้ามีไม่เหมือนกัน — วัดส่วนที่ยังเกินจอแล้วหดกรอบลงเท่านั้น
      // ถ้าไม่ทำ หน้าจะยังเลื่อนได้อีกนิด แล้วหัวหน้ากับตัวกรองก็ลอยหายไปอยู่ดี
      // ทำซ้ำได้ไม่กี่รอบเพราะการหดกรอบทำให้ระยะขอบบางตัวยุบตามไปด้วย
      // รอบเดียวจึงมักเหลือเศษไม่กี่ px
      for (var pass = 0; pass < 3; pass++) {
        var over = document.documentElement.scrollHeight - window.innerHeight;
        if (over <= 0) { break; }
        avail -= over;
        w.style.maxHeight = Math.max(MIN_H, avail) + 'px';
      }
    }
  }

  var pending;
  function schedule() {
    clearTimeout(pending);
    pending = setTimeout(apply, 60);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
  window.addEventListener('resize', schedule);
  // ปรับขนาดตัวอักษร/พับเมนู ทำให้หัวหน้าสูงเปลี่ยน — วัดใหม่เมื่อ layout ขยับ
  if (window.ResizeObserver) {
    var ro = new ResizeObserver(schedule);
    if (document.body) { ro.observe(document.body); }
  }
})();
