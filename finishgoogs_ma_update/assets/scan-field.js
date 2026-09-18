/**
 * scan-field.js — ปุ่มสแกน S/N ชุดเดียวทั้งระบบ (ช่องค้นหา · ช่องรหัสเครื่อง · หน้าบันทึกผลิต)
 *
 * ใช้ตัวอ่านเดียวกับหน้า "นับสต็อกด้วยการสแกน" (assets/fast-scan.js) และจำค่ากล้องหน้า/หลัง ·
 * เสียงเปิด/ปิด ร่วมกันผ่าน localStorage (ssFacing · ssSound) — ตั้งที่หน้าไหนก็ได้ผลทุกหน้า
 *
 * ติดปุ่มให้เองกับ:
 *   input.asset-search           — data-nav="1" = สแกนแล้วเปิดหน้าเครื่อง · ไม่ใส่ = เติมรหัสลงช่อง
 *   input[data-scan]             — "fill" เติมรหัส + ยิง input/change · "submit" เติมแล้วส่งฟอร์ม ·
 *                                  "nav" เปิดหน้าเครื่อง · "off" ไม่ต้องมีปุ่ม
 *   data-scan-inline             — วางปุ่มต่อท้ายช่องโดยไม่ห่อ (ช่องที่อยู่ในกล่อง flex อยู่แล้ว)
 *
 * เรียกเองได้:  FgScan.open({ title, continuous, onCode(code, api) })
 *   api.feedback('ok'|'dup'|'bad', text) · api.close() · onCode คืน false = ยังไม่ปิด (โหมดเดี่ยว)
 */
(function () {
  'use strict';

  var BASE = (document.body && document.body.getAttribute('data-base')) || window.FG_BASE_URL || '';
  var HTML5_QRCODE_SRC = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
  var ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"></path><rect x="7" y="7" width="10" height="10" rx="1"></rect></svg>';

  function store(k, v) { try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) {} return null; }
  function vibrate(p) { if (navigator.vibrate) { try { navigator.vibrate(p); } catch (e) {} } }

  // ── เสียง: มือถือให้ส่งเสียงได้หลังแตะจอเท่านั้น — AudioContext ตัวเดียว ปลุกตอนแตะ ──
  var audioCtx = null;
  function unlockAudio() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) { return; }
      if (!audioCtx) { audioCtx = new Ctx(); }
      if (audioCtx.state === 'suspended') { audioCtx.resume(); }
    } catch (e) {}
  }
  ['pointerdown', 'touchend', 'keydown'].forEach(function (ev) { document.addEventListener(ev, unlockAudio, true); });
  var SOUND = {
    ok:  [[1047, 0.09], [1397, 0.14]],
    dup: [[659, 0.08], [659, 0.08]],
    bad: [[196, 0.45, 'square']]
  };
  function soundOn() { return store('ssSound') !== '0'; }
  function tone(kind) {
    if (!soundOn() || !audioCtx) { return; }
    try {
      var t = audioCtx.currentTime + 0.01;
      (SOUND[kind] || SOUND.ok).forEach(function (s) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = s[2] || 'sine';
        o.frequency.value = s[0];
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(s[2] === 'square' ? 0.18 : 0.4, t + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, t + s[1]);
        o.connect(g); g.connect(audioCtx.destination);
        o.start(t); o.stop(t + s[1] + 0.02);
        t += s[1] + 0.06;
      });
    } catch (e) {}
  }

  // iPhone ไม่มีตัวอ่านของระบบ ต้องใช้ html5-qrcode — โหลดเฉพาะตอนต้องใช้ ไม่ถ่วงทุกหน้า
  var libPromise = null;
  function ensureLib() {
    if ('BarcodeDetector' in window || typeof window.Html5Qrcode !== 'undefined') { return Promise.resolve(); }
    if (!libPromise) {
      libPromise = new Promise(function (resolve) {
        var s = document.createElement('script');
        s.src = HTML5_QRCODE_SRC;
        s.onload = resolve;
        s.onerror = resolve;   // โหลดไม่ได้ FastScan จะแจ้งเอง
        document.head.appendChild(s);
      });
    }
    return libPromise;
  }

  // ── หน้าต่างสแกน ──
  var ui = null;
  function buildUi() {
    if (ui) { return ui; }
    var el = document.createElement('div');
    el.className = 'fgs-overlay';
    el.hidden = true;
    el.innerHTML =
      '<div class="fgs-box" role="dialog" aria-modal="true" aria-labelledby="fgs-title">'
      + '<div class="fgs-head"><b id="fgs-title">สแกนรหัสเครื่อง</b><span class="fgs-count" hidden></span>'
      + '<button type="button" class="fgs-close" aria-label="ปิด">ปิด</button></div>'
      + '<div class="fgs-stage"><div class="fgs-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>'
      + '<div class="fgs-hit" hidden><div class="fgs-hit-mark"></div><div class="fgs-hit-text"></div></div></div>'
      + '<p class="fgs-status">กำลังเปิดกล้อง…</p>'
      + '<div class="fgs-ctl">'
      + '<button type="button" class="fgs-btn" data-act="flip"></button>'
      + '<button type="button" class="fgs-btn" data-act="torch" hidden>ไฟฉาย</button>'
      + '<button type="button" class="fgs-btn" data-act="sound"></button>'
      + '</div>'
      + '<div class="fgs-zoom" hidden><span>ซูม</span><input type="range" min="1" max="4" step="0.1" value="1"></div>'
      + '</div>';
    document.body.appendChild(el);
    ui = {
      root: el,
      title: el.querySelector('#fgs-title'),
      count: el.querySelector('.fgs-count'),
      stage: el.querySelector('.fgs-stage'),
      hit: el.querySelector('.fgs-hit'),
      hitMark: el.querySelector('.fgs-hit-mark'),
      hitText: el.querySelector('.fgs-hit-text'),
      status: el.querySelector('.fgs-status'),
      flip: el.querySelector('[data-act="flip"]'),
      torch: el.querySelector('[data-act="torch"]'),
      sound: el.querySelector('[data-act="sound"]'),
      zoomWrap: el.querySelector('.fgs-zoom'),
      zoom: el.querySelector('.fgs-zoom input')
    };
    el.querySelector('.fgs-close').addEventListener('click', close);
    el.addEventListener('click', function (e) { if (e.target === el) { close(); } });
    document.addEventListener('keydown', function (e) { if (!el.hidden && (e.key === 'Escape' || e.key === 'Esc')) { close(); } });
    ui.flip.addEventListener('click', function () {
      if (!state || state.starting) { return; }
      state.facing = state.facing === 'user' ? 'environment' : 'user';
      store('ssFacing', state.facing);
      ui.status.textContent = 'กำลังสลับกล้อง…';
      stopCam().then(startCam);
    });
    ui.sound.addEventListener('click', function () {
      store('ssSound', soundOn() ? '0' : '1');
      renderButtons();
      unlockAudio();
      tone('ok');
    });
    ui.torch.addEventListener('click', function () {
      if (!state || !state.ctl) { return; }
      state.torch = !state.torch;
      state.ctl.setTorch(state.torch).then(function () { ui.torch.classList.toggle('on', state.torch); })
        .catch(function () { ui.torch.hidden = true; });
    });
    ui.zoom.addEventListener('input', function () {
      if (state && state.ctl) { state.ctl.setZoom(ui.zoom.value).catch(function () {}); }
    });
    document.addEventListener('visibilitychange', function () {
      if (!state || ui.root.hidden) { return; }
      if (document.hidden) { stopCam(); } else { startCam(); }
    });
    return ui;
  }

  var state = null;
  function renderButtons() {
    if (!ui || !state) { return; }
    ui.flip.textContent = state.facing === 'user' ? 'สลับเป็นกล้องหลัง' : 'สลับเป็นกล้องหน้า';
    ui.flip.disabled = state.starting;
    ui.sound.textContent = soundOn() ? 'เสียง: เปิด' : 'เสียง: ปิด';
    ui.sound.classList.toggle('on', !soundOn());
  }
  function stopCam() {
    if (!state) { return Promise.resolve(); }
    var c = state.ctl;
    state.ctl = null;
    state.torch = false;
    ui.torch.hidden = true;
    ui.torch.classList.remove('on');
    ui.zoomWrap.hidden = true;
    return c ? c.stop() : Promise.resolve();
  }
  function startCam() {
    if (!state || state.starting || state.ctl) { return Promise.resolve(); }
    var s = state;
    s.starting = true;
    renderButtons();
    return ensureLib().then(function () {
      if (typeof window.FastScan === 'undefined') { throw new Error('โหลดตัวสแกนไม่ได้'); }
      return window.FastScan.start({
        stage: ui.stage,
        facing: s.facing,
        onCode: onCode,
        onStatus: function (t) { ui.status.textContent = t; }
      });
    }).then(function (c) {
      s.starting = false;
      if (state !== s || ui.root.hidden) { c.stop(); return; }   // ปิดหน้าต่างระหว่างกำลังเปิดกล้อง
      s.ctl = c;
      ui.status.textContent = (s.facing === 'user' ? 'กล้องหน้า' : 'กล้องหลัง') + ' · '
        + (c.engine === 'native' ? 'โหมดเร็ว' : 'โหมดมาตรฐาน') + ' · เล็งไปที่ QR หรือบาร์โค้ด';
      if (c.canTorch) { ui.torch.hidden = false; }
      if (c.zoom && c.zoom.max > c.zoom.min) {
        ui.zoom.min = c.zoom.min;
        ui.zoom.max = Math.min(c.zoom.max, 8);
        ui.zoom.step = c.zoom.step;
        ui.zoom.value = c.zoom.min;
        ui.zoomWrap.hidden = false;
      }
      renderButtons();
    }).catch(function (e) {
      s.starting = false;
      ui.status.textContent = 'เปิดกล้องไม่ได้ (' + (e && e.message ? e.message : e) + ') — พิมพ์รหัสเองแทนได้';
      renderButtons();
    });
  }

  var hitTimer = null;
  function feedback(kind, text) {
    tone(kind);
    vibrate(kind === 'bad' ? [300] : (kind === 'dup' ? [60, 60, 60] : [40]));
    ui.hit.className = 'fgs-hit fgs-' + kind;
    ui.hitMark.textContent = kind === 'ok' ? '✓' : (kind === 'dup' ? '!' : '✕');
    ui.hitText.textContent = text || '';
    ui.hit.hidden = false;
    clearTimeout(hitTimer);
    hitTimer = setTimeout(function () { ui.hit.hidden = true; }, kind === 'ok' ? 700 : 1500);
  }

  function onCode(text) {
    var s = state;
    var code = String(text || '').trim();
    if (!s || !code || s.done) { return; }
    // ป้ายเดิมยังค้างอยู่หน้ากล้อง — อ่านซ้ำได้หลายรอบต่อวินาที
    if (code === s.lastCode && Date.now() - s.lastAt < 2500) { return; }
    s.lastCode = code;
    s.lastAt = Date.now();
    var api = { feedback: feedback, close: close, setCount: setCount };
    if (!s.opts.continuous) {
      s.done = true;
      feedback('ok', code);
      stopCam();
      setTimeout(function () {
        close();
        if (s.opts.onCode) { s.opts.onCode(code, api); }
      }, 450);
      return;
    }
    if (s.opts.onCode) { s.opts.onCode(code, api); } else { feedback('ok', code); }
  }

  function setCount(text) {
    ui.count.textContent = text || '';
    ui.count.hidden = !text;
  }

  function open(opts) {
    // ปิดแป้นพิมพ์มือถือที่อาจค้างจากช่องที่เพิ่งแตะ ก่อนเปิดกล้อง
    if (document.activeElement && document.activeElement !== document.body && document.activeElement.blur) { document.activeElement.blur(); }
    buildUi();
    unlockAudio();
    if (state) { stopCam(); }
    state = {
      opts: opts || {},
      facing: store('ssFacing') === 'user' ? 'user' : 'environment',
      ctl: null, starting: false, torch: false, done: false, lastCode: '', lastAt: 0
    };
    ui.title.textContent = state.opts.title || 'สแกนรหัสเครื่อง';
    setCount(state.opts.count || '');
    ui.hit.hidden = true;
    ui.status.textContent = 'กำลังเปิดกล้อง…';
    ui.root.hidden = false;
    document.documentElement.classList.add('fgs-open');
    renderButtons();
    startCam();
  }

  function close() {
    if (!ui || ui.root.hidden) { return; }
    ui.root.hidden = true;
    document.documentElement.classList.remove('fgs-open');
    var s = state;
    stopCam().then(function () { if (state === s) { state = null; } });
    if (s && s.opts.onClose) { s.opts.onClose(); }
  }

  // ── ปุ่มสแกนต่อท้ายช่อง ──
  function modeOf(input) {
    var m = input.getAttribute('data-scan');
    if (m) { return m; }
    if (input.classList.contains('asset-search')) { return input.getAttribute('data-nav') === '1' ? 'nav' : 'fill'; }
    return 'fill';
  }
  function applyCode(input, code) {
    var mode = modeOf(input);
    if (mode === 'nav') {
      window.location.href = BASE + '/asset.php?code=' + encodeURIComponent(code);
      return;
    }
    input.value = code;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    if (mode === 'submit' && input.form) {
      if (typeof input.form.requestSubmit === 'function') { input.form.requestSubmit(); } else { input.form.submit(); }
      return;
    }
    // จอสัมผัสไม่โฟกัสช่อง — ไม่งั้นแป้นพิมพ์มือถือเด้งขึ้นหลังสแกนทุกครั้ง (สแกนแล้วไม่ต้องพิมพ์อะไรต่อ)
    if (!window.matchMedia('(pointer: coarse)').matches) {
      try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
    }
    // ช่องเลือกเครื่อง (.asset-search) — รอรายการแนะนำแล้วเลือกตัวที่ตรงรหัสให้เลย หน้านั้นจะได้ข้อมูลเครื่อง (อายุ ฯลฯ) ครบ
    if (input.classList.contains('asset-search')) {
      var tries = 0, key = code.toUpperCase();
      var timer = setInterval(function () {
        var box = input.parentNode && input.parentNode.querySelector('.ac-list');
        var hit = null;
        if (box && !box.hidden) {
          box.querySelectorAll('div[data-code]').forEach(function (d) {
            if (!hit && String(d.getAttribute('data-code')).toUpperCase() === key) { hit = d; }
          });
        }
        if (hit || ++tries > 20) {
          clearInterval(timer);
          if (hit) { hit.click(); }
        }
      }, 150);
    }
  }
  function attach(input) {
    if (!input || input.dataset.scanReady || input.getAttribute('data-scan') === 'off' || input.type === 'hidden') { return; }
    input.dataset.scanReady = '1';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'scan-field-btn';
    btn.title = 'สแกน QR / บาร์โค้ด';
    btn.setAttribute('aria-label', 'สแกน QR / บาร์โค้ด');
    btn.innerHTML = ICON;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      open({
        title: input.getAttribute('data-scan-title') || 'สแกนรหัสเครื่อง',
        onCode: function (code) { applyCode(input, code); }
      });
    });
    if (input.hasAttribute('data-scan-inline')) {
      input.insertAdjacentElement('afterend', btn);
      return;
    }
    // ห่อช่อง (หรือกล่อง autocomplete ที่ห่อช่องอยู่) ให้ปุ่มชิดขวา — ย้ายความกว้างที่ตั้งไว้กับช่องมาที่กล่อง
    var target = input.parentNode && input.parentNode.classList && input.parentNode.classList.contains('ac-wrap') ? input.parentNode : input;
    var wrap = document.createElement('span');
    wrap.className = 'scan-field';
    ['width', 'maxWidth', 'minWidth', 'flex', 'flexGrow', 'flexBasis'].forEach(function (p) {
      if (input.style[p]) { wrap.style[p] = input.style[p]; input.style[p] = ''; }
    });
    target.parentNode.insertBefore(wrap, target);
    wrap.appendChild(target);
    wrap.appendChild(btn);
  }
  function scanAll(root) {
    (root || document).querySelectorAll('input.asset-search, input[data-scan]').forEach(attach);
  }

  window.FgScan = { open: open, close: close, attach: attach, scanAll: scanAll, feedback: function (k, t) { if (ui) { feedback(k, t); } } };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scanAll(); });
  } else {
    scanAll();
  }
})();
