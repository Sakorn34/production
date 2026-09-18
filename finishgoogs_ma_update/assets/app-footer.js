/**
 * app-footer.js — หน้าต่าง "แจ้งปัญหา" จากส่วนท้ายทุกหน้า (ใช้ทั้งแอปผลิตและแอปอะไหล่)
 *
 * รูปที่แนบย่อในเบราว์เซอร์ก่อนส่ง (JPEG) — รูปจากกล้องมือถือ 4-8MB ต่อรูป อัปโหลดผ่านเน็ตมือถือช้า
 * และ LINE ต้องการรูป preview ไม่เกิน 1MB จึงทำ 2 ขนาด: ตัวเต็ม ≤ 1600px · ตัวย่อ ≤ 480px
 */
(function () {
  'use strict';
  var form = document.getElementById('support-form');
  var overlay = document.getElementById('support-overlay');
  if (!form || !overlay) { return; }
  var MAX = 4;
  var imgsBox = document.getElementById('support-imgs');
  var fileInp = document.getElementById('support-file');
  var statusEl = document.getElementById('support-status');
  var sendBtn = document.getElementById('support-send');
  var msg = document.getElementById('support-msg');
  var shots = [];   // {full: Blob, preview: Blob, url: objectURL}

  function status(text, bad) {
    statusEl.textContent = text || '';
    statusEl.hidden = !text;
    statusEl.classList.toggle('is-bad', !!bad);
  }
  function open() {
    document.getElementById('support-page-title').textContent = (document.title || '').replace(/\s+[—-]\s+Production$/, '');
    status('');
    overlay.hidden = false;
    setTimeout(function () { try { msg.focus(); } catch (e) {} }, 50);
  }
  function close() {
    overlay.hidden = true;
  }
  function renderShots() {
    imgsBox.innerHTML = '';
    shots.forEach(function (s, i) {
      var d = document.createElement('div');
      d.className = 'support-img';
      d.innerHTML = '<img alt=""><button type="button" aria-label="เอารูปออก">✕</button>';
      d.querySelector('img').src = s.url;
      d.querySelector('button').addEventListener('click', function () {
        URL.revokeObjectURL(s.url);
        shots.splice(i, 1);
        renderShots();
      });
      imgsBox.appendChild(d);
    });
    form.querySelector('.support-add-img').hidden = shots.length >= MAX;
  }
  function shrink(img, maxSide, quality) {
    var w = img.naturalWidth, h = img.naturalHeight;
    var k = Math.min(1, maxSide / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(w * k));
    c.height = Math.max(1, Math.round(h * k));
    var g = c.getContext('2d');
    g.fillStyle = '#fff';               // PNG โปร่งใส → พื้นขาว (JPEG ไม่มีช่องโปร่งใส)
    g.fillRect(0, 0, c.width, c.height);
    g.drawImage(img, 0, 0, c.width, c.height);
    return new Promise(function (res) { c.toBlob(res, 'image/jpeg', quality); });
  }
  function addFile(file) {
    return new Promise(function (res) {
      if (!/^image\//.test(file.type)) { res(); return; }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        Promise.all([shrink(img, 1600, 0.82), shrink(img, 480, 0.7)]).then(function (b) {
          URL.revokeObjectURL(url);
          if (b[0] && b[1] && shots.length < MAX) {
            shots.push({ full: b[0], preview: b[1], url: URL.createObjectURL(b[1]) });
          }
          res();
        });
      };
      img.onerror = function () { URL.revokeObjectURL(url); status('อ่านรูป ' + file.name + ' ไม่ได้', true); res(); };
      img.src = url;
    });
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-support-open]')) { e.preventDefault(); open(); return; }
    if (e.target.closest('[data-support-close]') || e.target === overlay) { close(); }
  });
  document.addEventListener('keydown', function (e) {
    if (!overlay.hidden && (e.key === 'Escape' || e.key === 'Esc')) { close(); }
  });
  fileInp.addEventListener('change', function () {
    var files = Array.prototype.slice.call(fileInp.files || [], 0, MAX - shots.length);
    fileInp.value = '';
    status('กำลังเตรียมรูป…');
    files.reduce(function (p, f) { return p.then(function () { return addFile(f); }); }, Promise.resolve())
      .then(function () { if (statusEl.textContent === 'กำลังเตรียมรูป…') { status(''); } renderShots(); });
  });
  // วางรูปจากคลิปบอร์ด (จับภาพหน้าจอแล้ว Ctrl+V) ได้ด้วย
  msg.addEventListener('paste', function (e) {
    var items = (e.clipboardData && e.clipboardData.items) || [];
    var files = [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file' && /^image\//.test(items[i].type)) { files.push(items[i].getAsFile()); }
    }
    if (!files.length) { return; }
    e.preventDefault();
    files.slice(0, MAX - shots.length).reduce(function (p, f) { return p.then(function () { return addFile(f); }); }, Promise.resolve())
      .then(renderShots);
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!msg.value.trim() && !shots.length) { status('เล่าปัญหาหรือแนบรูปอย่างน้อย 1 อย่าง', true); msg.focus(); return; }
    var fd = new FormData();
    fd.append('csrf', form.getAttribute('data-csrf') || '');
    fd.append('message', msg.value);
    fd.append('page_url', location.href);
    fd.append('page_title', document.getElementById('support-page-title').textContent);
    shots.forEach(function (s, i) {
      fd.append('img_full[]', s.full, 'shot' + i + '.jpg');
      fd.append('img_preview[]', s.preview, 'shot' + i + '_s.jpg');
    });
    sendBtn.disabled = true;
    status('กำลังส่ง…');
    fetch(form.getAttribute('data-endpoint'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        sendBtn.disabled = false;
        if (d && d.ok) {
          msg.value = '';
          shots.forEach(function (s) { URL.revokeObjectURL(s.url); });
          shots = [];
          renderShots();
          close();
          if (window.__flashToast) { window.__flashToast(d.message, 'ok'); } else { alert(d.message); }
        } else {
          status((d && d.message) || 'ส่งไม่สำเร็จ', true);
        }
      })
      .catch(function () {
        sendBtn.disabled = false;
        status('ส่งไม่สำเร็จ — ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่', true);
      });
  });
})();
