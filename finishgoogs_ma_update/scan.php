<?php
/**
 * scan.php — สแกน QR / Barcode บนตัวเครื่อง
 *
 * นอกจากหน้าตา ยังเพิ่มของที่ใช้จริงหน้างาน:
 *   - ไฟฉาย (โรงงาน/หลังตู้มักมืด) และสลับกล้องหน้า-หลัง
 *   - ตอบสนองตอนสแกนติด: กรอบเขียว + สั่น + เสียง ก่อนเด้งไปหน้าเครื่อง
 *     เดิมเด้งทันทีเงียบ ๆ จนไม่แน่ใจว่าอ่านรหัสได้หรือยัง
 *   - ประวัติที่เพิ่งสแกน เก็บใน localStorage ของเครื่องผู้ใช้เอง
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();
page_header('สแกน QR / Barcode');
?>

<div class="scan-hero">
  <div class="scan-hero-glow" aria-hidden="true"></div>
  <div class="scan-hero-body">
    <div class="scan-hero-icon"><?= ui_icon_html('scan', 30) ?></div>
    <div>
      <h2>สแกนหาเครื่องได้ทันที</h2>
      <p>เล็งกล้องไปที่ QR หรือบาร์โค้ดบนตัวเครื่อง · ใช้กับเครื่องยิงบาร์โค้ด USB ได้ (ยิงแล้วกด Enter)</p>
    </div>
  </div>
</div>

<div class="scan-grid">

  <section class="scan-cam-card">
    <div class="scan-card-head">
      <span class="scan-dot" id="scan-dot"></span>
      <h3>กล้องสแกน</h3>
      <div class="scan-cam-tools">
        <button type="button" class="scan-tool" id="btn-torch" hidden title="ไฟฉาย">🔦</button>
        <button type="button" class="scan-tool" id="btn-flip" hidden title="สลับกล้อง">🔄</button>
      </div>
    </div>

    <div class="scan-stage" id="scan-stage">
      <div id="reader"></div>
      <div class="scan-frame" aria-hidden="true">
        <i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i>
        <div class="scan-laser"></div>
      </div>
      <div class="scan-hit" id="scan-hit" hidden>
        <div class="scan-hit-mark">✓</div>
        <div class="scan-hit-text">พบรหัสแล้ว</div>
        <div class="scan-hit-code" id="scan-hit-code"></div>
      </div>
    </div>

    <p class="scan-status" id="cam-status">
      <span class="scan-spin" aria-hidden="true"></span> กำลังเปิดกล้อง…
    </p>
  </section>

  <aside class="scan-side">
    <section class="scan-panel">
      <div class="scan-card-head"><h3>⌨️ พิมพ์รหัสเอง</h3></div>
      <form method="get" action="<?= BASE_URL ?>/asset.php" class="scan-manual">
        <input type="text" name="code" id="manual-code" class="asset-search" data-nav="1"
               placeholder="เช่น BP23021294" autocomplete="off" autofocus>
        <button type="submit" class="btn btn-primary btn-with-icon"><?= ui_btn_label('search', 'เปิดหน้าเครื่อง', 15) ?></button>
      </form>
      <p class="scan-hint">พิมพ์บางส่วนก็ได้ ระบบจะแนะนำรหัสที่ตรงให้เลือก</p>
    </section>

    <section class="scan-panel" id="recent-panel" hidden>
      <div class="scan-card-head">
        <h3>🕘 เพิ่งสแกนไป</h3>
        <button type="button" class="scan-clear" id="btn-clear-recent">ล้าง</button>
      </div>
      <div class="scan-recent" id="recent-list"></div>
    </section>

    <section class="scan-panel scan-tips">
      <div class="scan-card-head"><h3>💡 สแกนไม่ติด?</h3></div>
      <ul>
        <li>📏 ถือห่างประมาณ <b>10–20 ซม.</b> ให้รหัสเต็มกรอบ</li>
        <li>🔦 ที่มืดกดปุ่มไฟฉายมุมขวาบน</li>
        <li>🧽 เช็ดฝุ่นบนสติกเกอร์ก่อน</li>
        <li>⌨️ สุดท้ายพิมพ์รหัสเองได้เสมอ</li>
      </ul>
    </section>
  </aside>
</div>

<style>
/* แอตทริบิวต์ hidden ต้องชนะเสมอ — กฎ display ของคลาส (.scan-hit เป็น flex) และ
   กฎกลาง button{display:inline-block} ใน style.css มี specificity สูงกว่า [hidden]
   ของเบราว์เซอร์ ทำให้ของที่สั่งซ่อนไว้โผล่ออกมาหมด */
.scan-stage [hidden],
.scan-cam-tools [hidden],
.scan-grid [hidden] { display: none !important; }

/* ── Hero ── */
.scan-hero {
  position: relative; overflow: hidden;
  border-radius: var(--radius); margin-bottom: 16px; padding: 20px 22px;
  background: linear-gradient(120deg, var(--sidebar-bg, #4e2985), var(--primary-dark, #c01862));
  color: #fff; box-shadow: var(--shadow-md);
}
.scan-hero-glow {
  position: absolute; inset: -60% -20% auto auto; width: 320px; height: 320px; border-radius: 50%;
  background: radial-gradient(circle, rgba(255,255,255,.28), transparent 65%);
  animation: scanGlow 7s ease-in-out infinite;
}
@keyframes scanGlow { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-30px,26px) scale(1.15); } }
.scan-hero-body { position: relative; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.scan-hero-icon {
  width: 58px; height: 58px; flex-shrink: 0; border-radius: 16px;
  background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28);
  display: flex; align-items: center; justify-content: center; color: #fff;
  animation: scanFloat 3.4s ease-in-out infinite;
}
@keyframes scanFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
.scan-hero h2 { margin: 0; font-size: calc(20px * var(--font-scale, 1)); }
.scan-hero p { margin: 3px 0 0; font-size: calc(13px * var(--font-scale, 1)); color: rgba(255,255,255,.85); }

/* ── Layout ── */
.scan-grid { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 16px; align-items: start; }
.scan-cam-card, .scan-panel {
  background: #fff; border: 1px solid var(--border, #e7e0f5);
  border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 14px 16px;
}
.scan-side { display: flex; flex-direction: column; gap: 16px; }
.scan-card-head { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.scan-card-head h3 { margin: 0; font-size: calc(14.5px * var(--font-scale, 1)); flex: 1; }

/* ไฟสถานะกล้อง */
.scan-dot { width: 9px; height: 9px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0; }
.scan-dot.live { background: #16a34a; box-shadow: 0 0 0 0 rgba(22,163,74,.55); animation: scanPulse 1.8s infinite; }
.scan-dot.err { background: var(--danger, #b91c1c); }
@keyframes scanPulse { 70% { box-shadow: 0 0 0 9px rgba(22,163,74,0); } 100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); } }

.scan-cam-tools { display: flex; gap: 6px; }
.scan-tool {
  background: var(--bg, #f4f1fb); border: 1px solid var(--border, #e7e0f5); color: inherit;
  width: 36px; height: 36px; min-height: 0; padding: 0; border-radius: 10px;
  font-size: 16px; line-height: 1; cursor: pointer; transition: transform .12s, background .15s;
}
.scan-tool:hover { background: #ece5fb; transform: translateY(-1px); }
.scan-tool.on { background: #fef9c3; border-color: #fde047; }

/* ── เวทีกล้อง + กรอบเล็ง ── */
/* จำกัดขนาดไม่ให้กล้องบานจนดันเนื้อหาตกจอบนหน้าจอกว้าง */
.scan-stage {
  position: relative; border-radius: 14px; overflow: hidden;
  background: #150f22; aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center;
  width: 100%; max-width: 520px; max-height: 56vh; margin: 0 auto;
}
.scan-stage #reader { width: 100%; }
.scan-stage #reader video { width: 100% !important; height: 100% !important; object-fit: cover; display: block; }
.scan-frame { position: absolute; inset: 12%; pointer-events: none; }
.scan-frame .c { position: absolute; width: 30px; height: 30px; border: 3px solid var(--primary, #e11d74); border-radius: 4px; }
.scan-frame .tl { top: 0; left: 0; border-right: 0; border-bottom: 0; }
.scan-frame .tr { top: 0; right: 0; border-left: 0; border-bottom: 0; }
.scan-frame .bl { bottom: 0; left: 0; border-right: 0; border-top: 0; }
.scan-frame .br { bottom: 0; right: 0; border-left: 0; border-top: 0; }
.scan-laser {
  position: absolute; left: 6%; right: 6%; height: 2px; border-radius: 2px;
  background: linear-gradient(90deg, transparent, var(--primary, #e11d74), transparent);
  box-shadow: 0 0 12px 2px rgba(225, 29, 116, .55);
  animation: scanSweep 2.4s ease-in-out infinite;
}
@keyframes scanSweep { 0%,100% { top: 4%; opacity: .35; } 50% { top: 94%; opacity: 1; } }

/* จังหวะสแกนติด */
.scan-hit {
  position: absolute; inset: 0; display: flex; flex-direction: column; gap: 6px;
  align-items: center; justify-content: center; background: rgba(22,163,74,.92); color: #fff;
  /* forwards เพื่อให้จบที่ scale(1) แน่นอน ไม่ค้างย่อถ้า animation ถูกขัดจังหวะ */
  animation: scanHitIn .25s ease-out forwards;
}
@keyframes scanHitIn { from { opacity: 0; transform: scale(.94); } to { opacity: 1; transform: scale(1); } }
.scan-hit-mark { font-size: 46px; line-height: 1; animation: scanPop .35s cubic-bezier(.2,1.6,.4,1); }
@keyframes scanPop { from { transform: scale(0); } to { transform: scale(1); } }
.scan-hit-text { font-weight: 700; }
.scan-hit-code { font-size: calc(15px * var(--font-scale, 1)); font-weight: 800; letter-spacing: .04em; }

.scan-status {
  margin: 10px 0 0; font-size: calc(12.5px * var(--font-scale, 1)); color: #6b6480;
  display: flex; align-items: center; gap: 7px;
}
.scan-spin {
  width: 13px; height: 13px; border-radius: 50%; flex-shrink: 0;
  border: 2px solid var(--border, #e7e0f5); border-top-color: var(--primary, #e11d74);
  animation: scanSpin .7s linear infinite;
}
@keyframes scanSpin { to { transform: rotate(360deg); } }

/* ── ฝั่งขวา ── */
.scan-manual { display: flex; flex-direction: column; gap: 8px; }
.scan-manual input { width: 100%; }
.scan-hint { margin: 8px 0 0; font-size: calc(12px * var(--font-scale, 1)); color: #8b83a5; }
.scan-clear { background: none; border: 0; color: var(--primary); font-size: 12px; cursor: pointer; padding: 2px 6px; min-height: 0; }
.scan-clear:hover { background: var(--primary-soft, #fce7f3); border-radius: 6px; }
.scan-recent { display: flex; flex-direction: column; gap: 6px; }
.scan-recent a {
  display: flex; align-items: center; gap: 8px; padding: 8px 10px;
  border: 1px solid var(--border, #e7e0f5); border-radius: 10px;
  text-decoration: none; color: inherit; font-size: calc(13px * var(--font-scale, 1));
  transition: border-color .15s, transform .12s, background .15s;
}
.scan-recent a:hover { border-color: var(--primary); background: var(--primary-soft, #fce7f3); transform: translateX(2px); }
.scan-recent b { font-variant-numeric: tabular-nums; }
.scan-recent .t { margin-left: auto; font-size: 11px; color: #8b83a5; }
.scan-tips ul { margin: 0; padding-left: 2px; list-style: none; display: flex; flex-direction: column; gap: 7px; }
.scan-tips li { font-size: calc(12.5px * var(--font-scale, 1)); color: #5c5670; line-height: 1.5; }

@media (max-width: 900px) {
  .scan-grid { grid-template-columns: 1fr; }
  .scan-stage { aspect-ratio: 1 / 1; }
}
@media (prefers-reduced-motion: reduce) {
  .scan-hero-glow, .scan-hero-icon, .scan-laser, .scan-dot.live, .scan-spin, .scan-hit, .scan-hit-mark { animation: none; }
}
</style>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
  var BASE = <?= json_encode(BASE_URL) ?>;
  var RECENT_KEY = 'scan_recent_v1';
  var statusEl = document.getElementById('cam-status');
  var dot = document.getElementById('scan-dot');
  var hit = document.getElementById('scan-hit');
  var hitCode = document.getElementById('scan-hit-code');
  var btnTorch = document.getElementById('btn-torch');
  var btnFlip = document.getElementById('btn-flip');

  function setStatus(html, state) {
    statusEl.innerHTML = html;
    dot.className = 'scan-dot' + (state ? ' ' + state : '');
  }

  // ── ประวัติที่เพิ่งสแกน (เก็บในเครื่องผู้ใช้ ไม่ขึ้นเซิร์ฟเวอร์) ──
  function readRecent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) { return []; }
  }
  function pushRecent(code) {
    var list = readRecent().filter(function (r) { return r.c !== code; });
    list.unshift({ c: code, t: Date.now() });
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 6))); } catch (e) {}
  }
  function ago(ts) {
    var s = Math.round((Date.now() - ts) / 1000);
    if (s < 60) return 'เมื่อครู่';
    if (s < 3600) return Math.floor(s / 60) + ' นาที';
    if (s < 86400) return Math.floor(s / 3600) + ' ชม.';
    return Math.floor(s / 86400) + ' วัน';
  }
  function renderRecent() {
    var list = readRecent();
    var panel = document.getElementById('recent-panel');
    var box = document.getElementById('recent-list');
    if (!list.length) { panel.hidden = true; return; }
    panel.hidden = false;
    box.innerHTML = list.map(function (r) {
      var code = String(r.c).replace(/[<>&"]/g, '');
      return '<a href="' + BASE + '/asset.php?code=' + encodeURIComponent(r.c) + '">'
           + '<span>📟</span><b>' + code + '</b><span class="t">' + ago(r.t) + '</span></a>';
    }).join('');
  }
  document.getElementById('btn-clear-recent').addEventListener('click', function () {
    try { localStorage.removeItem(RECENT_KEY); } catch (e) {}
    renderRecent();
  });
  renderRecent();

  // ── ตอบสนองตอนสแกนติด ──
  function beep() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx(), o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = 880;
      g.gain.setValueAtTime(.09, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(.0001, ctx.currentTime + .18);
      o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + .18);
    } catch (e) {}
  }

  if (typeof Html5Qrcode === 'undefined') {
    setStatus('📴 โหลดตัวสแกนไม่ได้ (ไม่มีอินเทอร์เน็ต) — พิมพ์รหัสในช่องด้านขวาแทนได้', 'err');
    return;
  }

  var scanner = new Html5Qrcode('reader');
  var cameras = [];
  var camIndex = 0;
  var torchOn = false;
  var done = false;

  function onScan(text) {
    if (done) return;
    done = true;
    var code = String(text).trim();
    hitCode.textContent = code;
    hit.hidden = false;
    if (navigator.vibrate) { try { navigator.vibrate([40, 50, 40]); } catch (e) {} }
    beep();
    pushRecent(code);
    scanner.stop().catch(function () {});
    setTimeout(function () {
      window.location = BASE + '/asset.php?code=' + encodeURIComponent(code);
    }, 620);
  }

  function start(camConfig) {
    return scanner.start(camConfig, { fps: 10, qrbox: 240 }, onScan, function () {})
      .then(function () {
        setStatus('🎯 เล็งกล้องไปที่ QR หรือบาร์โค้ดบนตัวเครื่อง', 'live');
        // ไฟฉายรองรับเฉพาะบางกล้อง/บางเบราว์เซอร์ — ซ่อนปุ่มถ้าใช้ไม่ได้จริง
        try {
          var caps = scanner.getRunningTrackCapabilities();
          if (caps && caps.torch) { btnTorch.hidden = false; }
        } catch (e) {}
        if (cameras.length > 1) { btnFlip.hidden = false; }
      });
  }

  Html5Qrcode.getCameras().then(function (list) {
    cameras = list || [];
  }).catch(function () {}).then(function () {
    return start({ facingMode: 'environment' });
  }).catch(function (e) {
    setStatus('🚫 เปิดกล้องไม่ได้ (' + e + ') — พิมพ์รหัสในช่องด้านขวาแทนได้', 'err');
  });

  btnFlip.addEventListener('click', function () {
    if (!cameras.length) return;
    camIndex = (camIndex + 1) % cameras.length;
    btnTorch.hidden = true; btnTorch.classList.remove('on'); torchOn = false;
    setStatus('<span class="scan-spin"></span> กำลังสลับกล้อง…');
    scanner.stop().then(function () { return start({ deviceId: { exact: cameras[camIndex].id } }); })
      .catch(function (e) { setStatus('🚫 สลับกล้องไม่ได้: ' + e, 'err'); });
  });

  btnTorch.addEventListener('click', function () {
    torchOn = !torchOn;
    scanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] })
      .then(function () { btnTorch.classList.toggle('on', torchOn); })
      .catch(function () { btnTorch.hidden = true; });
  });
})();
</script>
<?php page_footer();
