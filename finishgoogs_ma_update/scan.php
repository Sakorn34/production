<?php
/**
 * scan.php — สแกน QR / Barcode บนตัวเครื่อง
 *
 * นอกจากหน้าตา ยังเพิ่มของที่ใช้จริงหน้างาน:
 *   - ตัวอ่านชุดเดียวกับหน้า "นับสต็อกด้วยการสแกน" (assets/fast-scan.js) — ใช้ตัวอ่านของมือถือเมื่อมี
 *   - เปิด/ปิดกล้อง · สลับกล้องหน้า-หลัง · ไฟฉาย · ซูม · เปิด/ปิดเสียง (จำค่าไว้ในเครื่อง)
 *   - เช็กรหัสกับทะเบียนก่อนเปิดหน้าเครื่อง: เจอ = เสียงสั้น + กรอบเขียว แล้วไปหน้าเครื่อง
 *     ไม่เจอ = เสียงต่ำ + กรอบแดง กล้องสแกนต่อได้เลย ไม่ต้องย้อนกลับมาเปิดกล้องใหม่
 *   - ประวัติที่เพิ่งสแกน เก็บใน localStorage ของเครื่องผู้ใช้เอง
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

// เช็กรหัสจากกล้องก่อนเปิดหน้าเครื่อง
if (($_GET['ajax'] ?? '') === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim((string) ($_GET['code'] ?? ''));
    $a = $code === '' ? null : qr(
        'SELECT a.id, a.asset_code, a.status, p.name AS pname FROM assets a JOIN products p ON p.id = a.product_id
         WHERE a.asset_code = ? OR a.factory_serial = ? ORDER BY a.asset_code = ? DESC LIMIT 1',
        'sss', [$code, $code, $code]
    )->fetch_assoc();
    echo json_encode($a ? [
        'found'     => true,
        'id'        => (int) $a['id'],
        'code'      => (string) $a['asset_code'],
        'model'     => (string) $a['pname'],
        'status_th' => status_th((string) $a['status']),
    ] : ['found' => false, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}
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
    </div>

    <div class="scan-stage" id="scan-stage">
      <div class="scan-frame" aria-hidden="true">
        <i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i>
        <div class="scan-laser"></div>
      </div>
      <div class="scan-hit" id="scan-hit" hidden>
        <div class="scan-hit-mark" id="scan-hit-mark">✓</div>
        <div class="scan-hit-text" id="scan-hit-text">พบเครื่องแล้ว</div>
        <div class="scan-hit-code" id="scan-hit-code"></div>
        <div class="scan-hit-sub" id="scan-hit-sub"></div>
      </div>
    </div>

    <p class="scan-status" id="cam-status">
      <span class="scan-spin" aria-hidden="true"></span> กำลังเปิดกล้อง…
    </p>

    <div class="scan-ctl">
      <button type="button" class="scan-ctl-btn" id="btn-power"><?= ui_icon_html('scan', 16) ?><span>ปิดกล้อง</span></button>
      <button type="button" class="scan-ctl-btn" id="btn-flip"><?= ui_icon_html('refresh', 16) ?><span>สลับเป็นกล้องหน้า</span></button>
      <button type="button" class="scan-ctl-btn" id="btn-torch" hidden><?= ui_icon_html('updates', 16) ?><span>ไฟฉาย</span></button>
      <button type="button" class="scan-ctl-btn" id="btn-sound"><?= ui_icon_html('bell', 16) ?><span>เสียง: เปิด</span></button>
    </div>
    <div class="scan-zoom" id="zoom-wrap" hidden>
      <span>ซูม</span>
      <input type="range" id="zoom" min="1" max="4" step="0.1" value="1">
    </div>
  </section>

  <aside class="scan-side">
    <section class="scan-panel">
      <div class="scan-card-head"><h3><?= ui_icon_html('edit', 14, 'h-svg') ?> พิมพ์รหัสเอง</h3></div>
      <form method="get" action="<?= BASE_URL ?>/asset.php" class="scan-manual">
        <input type="text" name="code" id="manual-code" class="asset-search" data-nav="1" data-scan="off"
               placeholder="เช่น BP23021294" autocomplete="off" autofocus>
        <button type="submit" class="btn btn-primary btn-with-icon"><?= ui_btn_label('search', 'เปิดหน้าเครื่อง', 15) ?></button>
      </form>
      <p class="scan-hint">พิมพ์บางส่วนก็ได้ ระบบจะแนะนำรหัสที่ตรงให้เลือก</p>
    </section>

    <section class="scan-panel" id="recent-panel" hidden>
      <div class="scan-card-head">
        <h3><?= ui_icon_html('history', 14, 'h-svg') ?> เพิ่งสแกนไป</h3>
        <button type="button" class="scan-clear" id="btn-clear-recent">ล้าง</button>
      </div>
      <div class="scan-recent" id="recent-list"></div>
    </section>

    <section class="scan-panel scan-tips">
      <div class="scan-card-head"><h3><?= ui_icon_html('alert', 14, 'h-svg') ?> สแกนไม่ติด?</h3></div>
      <ul>
        <li>ถือห่างประมาณ <b>10–20 ซม.</b> ให้รหัสเต็มกรอบ</li>
        <li>ที่มืดกดปุ่มไฟฉายมุมขวาบน</li>
        <li>เช็ดฝุ่นบนสติกเกอร์ก่อน</li>
        <li>สุดท้ายพิมพ์รหัสเองได้เสมอ</li>
      </ul>
    </section>
  </aside>
</div>

<style>
/* แอตทริบิวต์ hidden ต้องชนะเสมอ — กฎ display ของคลาส (.scan-hit เป็น flex) และ
   กฎกลาง button{display:inline-block} ใน style.css มี specificity สูงกว่า [hidden]
   ของเบราว์เซอร์ ทำให้ของที่สั่งซ่อนไว้โผล่ออกมาหมด */
.scan-stage [hidden],
.scan-ctl [hidden], .scan-zoom[hidden],
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

/* ปุ่มกล้อง — ชุดเดียวกับหน้านับสต็อก: เปิด/ปิด · สลับ · ไฟฉาย · เสียง */
.scan-ctl { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 6px; margin-top: 10px; }
.scan-ctl-btn {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  background: var(--bg, #f4f1fb); border: 1px solid var(--border, #e7e0f5); color: inherit;
  border-radius: 10px; padding: 10px 8px; min-height: 44px; font: inherit; font-size: calc(14px * var(--font-scale, 1)); cursor: pointer;
}
.scan-ctl-btn:hover { background: #ece5fb; }
.scan-ctl-btn.on { background: #fef9c3; border-color: #fde047; }
.scan-ctl-btn:disabled { opacity: .45; cursor: default; }
.scan-zoom { display: flex; align-items: center; gap: 10px; margin-top: 8px; font-size: calc(13px * var(--font-scale, 1)); color: var(--text-muted, #6b6480); }
.scan-zoom input { flex: 1; }

/* ── เวทีกล้อง + กรอบเล็ง ── */
/* จำกัดขนาดไม่ให้กล้องบานจนดันเนื้อหาตกจอบนหน้าจอกว้าง */
.scan-stage {
  position: relative; border-radius: 14px; overflow: hidden;
  background: #150f22; aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center;
  width: 100%; max-width: 520px; max-height: 56vh; margin: 0 auto;
}
.scan-stage .fs-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.scan-stage .fs-reader { position: absolute; inset: 0; width: 100%; height: 100%; }
.scan-stage .fs-reader video { width: 100% !important; height: 100% !important; object-fit: cover; }
.scan-stage .fs-mirror, .scan-stage .fs-mirror video { transform: scaleX(-1); }
.scan-frame, .scan-hit { z-index: 2; }
.scan-stage.is-off::after { content: 'กล้องปิดอยู่'; position: absolute; inset: 0; display: grid; place-items: center; color: #a39fb3; background: #150f22; z-index: 3; }
.scan-stage.is-off .scan-frame { display: none; }
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
.scan-hit-code { font-size: calc(15px * var(--font-scale, 1)); font-weight: 800; letter-spacing: .04em; overflow-wrap: anywhere; padding: 0 12px; text-align: center; }
.scan-hit-sub { font-size: calc(13px * var(--font-scale, 1)); opacity: .9; padding: 0 12px; text-align: center; }
.scan-hit.is-miss { background: rgba(185,28,28,.92); }

.scan-status {
  margin: 10px 0 0; font-size: calc(12.5px * var(--font-scale, 1)); color: var(--text-muted, #6b6480);
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
<script src="<?= BASE_URL ?>/assets/fast-scan.js?v=<?= (int) @filemtime(__DIR__ . '/assets/fast-scan.js') ?>"></script>
<script>
(function () {
  var BASE = <?= json_encode(BASE_URL) ?>;
  var RECENT_KEY = 'scan_recent_v1';
  var statusEl = document.getElementById('cam-status');
  var dot = document.getElementById('scan-dot');
  var stage = document.getElementById('scan-stage');
  var hit = document.getElementById('scan-hit');
  var btnPower = document.getElementById('btn-power');
  var btnFlip = document.getElementById('btn-flip');
  var btnTorch = document.getElementById('btn-torch');
  var btnSound = document.getElementById('btn-sound');
  var zoomWrap = document.getElementById('zoom-wrap');
  var zoomEl = document.getElementById('zoom');

  function store(k, v) { try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) {} return null; }
  function setStatus(text, state) {
    statusEl.textContent = text;
    dot.className = 'scan-dot' + (state ? ' ' + state : '');
  }
  function label(btn, text) { btn.querySelector('span').textContent = text; }

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
      return '<a href="' + BASE + '/asset.php?code=' + encodeURIComponent(r.c) + '" data-same-tab="1">'
           + '<b>' + code + '</b><span class="t">' + ago(r.t) + '</span></a>';
    }).join('');
  }
  document.getElementById('btn-clear-recent').addEventListener('click', function () {
    try { localStorage.removeItem(RECENT_KEY); } catch (e) {}
    renderRecent();
  });
  renderRecent();

  // ── เสียง — มือถือไม่ให้ส่งเสียงจนกว่าจะแตะจอสักครั้ง จึงใช้ AudioContext ตัวเดียวแล้วปลุกตอนแตะ ──
  var audioCtx = null;
  var unlockedAt = 0;
  var soundOn = store('ssSound') !== '0';   // ใช้ค่าร่วมกับหน้านับสต็อก
  function unlockAudio() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var wasOn = audioCtx && audioCtx.state === 'running';
      if (!audioCtx) { audioCtx = new Ctx(); }
      if (!wasOn) { unlockedAt = Date.now(); }
      if (audioCtx.state === 'suspended') { audioCtx.resume().then(renderButtons); } else { renderButtons(); }
    } catch (e) {}
  }
  ['pointerdown', 'touchend', 'click', 'keydown'].forEach(function (ev) {
    document.addEventListener(ev, unlockAudio, true);
  });
  var SOUND = {
    ok:  [[1047, 0.09], [1397, 0.14]],
    bad: [[196, 0.45, 'square']]
  };
  function tone(kind) {
    if (!soundOn || !audioCtx) return;
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
  function vibrate(p) { if (navigator.vibrate) { try { navigator.vibrate(p); } catch (e) {} } }

  // ── กล้อง: เปิด/ปิด · สลับหน้า-หลัง · ไฟฉาย · ซูม ──
  var ctl = null, camOn = true, starting = false, torch = false;
  var facing = store('ssFacing') === 'user' ? 'user' : 'environment';

  // ── ผลการสแกน ──
  var busy = false, lastCode = '', lastAt = 0, missTimer = null;
  function showHit(found, code, sub) {
    hit.classList.toggle('is-miss', !found);
    document.getElementById('scan-hit-mark').textContent = found ? '✓' : '✕';
    document.getElementById('scan-hit-text').textContent = found ? 'พบเครื่องแล้ว' : 'ไม่พบรหัสนี้ในทะเบียน';
    document.getElementById('scan-hit-code').textContent = code;
    document.getElementById('scan-hit-sub').textContent = sub || '';
    hit.hidden = false;
  }
  function onScan(text) {
    var code = String(text || '').trim();
    if (!code || busy) return;
    if (code === lastCode && Date.now() - lastAt < 2500) return;   // ป้ายเดิมยังค้างอยู่หน้ากล้อง
    busy = true; lastCode = code; lastAt = Date.now();
    clearTimeout(missTimer);
    fetch(BASE + '/scan.php?ajax=lookup&code=' + encodeURIComponent(code), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.found) {
          tone('ok'); vibrate([40, 50, 40]);
          showHit(true, d.code, [d.model, d.status_th].filter(Boolean).join(' · '));
          pushRecent(d.code);
          camOn = false; stopCam();
          setTimeout(function () { window.location = BASE + '/asset.php?id=' + d.id; }, 350);
          return;
        }
        tone('bad'); vibrate([300]);
        showHit(false, code, 'สแกนป้ายอื่นต่อได้เลย หรือพิมพ์รหัสเอง');
        missTimer = setTimeout(function () { hit.hidden = true; busy = false; }, 1000);
      })
      .catch(function () {
        // เช็กไม่ได้ (เน็ตหลุด) — เปิดหน้าเครื่องด้วยรหัสแบบเดิม ให้หน้านั้นบอกเองถ้าไม่พบ
        pushRecent(code);
        window.location = BASE + '/asset.php?code=' + encodeURIComponent(code);
      });
  }

  function renderButtons() {
    label(btnPower, camOn ? 'ปิดกล้อง' : 'เปิดกล้อง');
    btnPower.classList.toggle('on', !camOn);
    label(btnFlip, facing === 'user' ? 'สลับเป็นกล้องหลัง' : 'สลับเป็นกล้องหน้า');
    btnFlip.disabled = !camOn || starting;
    var ready = audioCtx && audioCtx.state === 'running';
    label(btnSound, !soundOn ? 'เสียง: ปิด' : (ready ? 'เสียง: เปิด' : 'แตะเพื่อเปิดเสียง'));
    btnSound.classList.toggle('on', !soundOn);
    stage.classList.toggle('is-off', !camOn);
  }
  function stopCam() {
    var c = ctl;
    ctl = null; torch = false;
    btnTorch.hidden = true; btnTorch.classList.remove('on');
    zoomWrap.hidden = true;
    return c ? c.stop() : Promise.resolve();
  }
  function startCam() {
    if (starting || ctl) return Promise.resolve();
    if (typeof FastScan === 'undefined') {
      setStatus('โหลดตัวสแกนไม่ได้ — พิมพ์รหัสเองแทนได้', 'err');
      return Promise.resolve();
    }
    starting = true;
    renderButtons();
    return FastScan.start({
      stage: stage,
      facing: facing,
      onCode: onScan,
      onStatus: function (t) { setStatus(t); }
    }).then(function (c) {
      starting = false;
      if (!camOn) { c.stop(); renderButtons(); return; }   // กดปิดระหว่างกำลังเปิด
      ctl = c;
      setStatus((facing === 'user' ? 'กล้องหน้า' : 'กล้องหลัง') + ' · '
        + (c.engine === 'native' ? 'โหมดเร็ว (ตัวอ่านของมือถือ)' : 'โหมดมาตรฐาน')
        + ' · เล็งไปที่ QR หรือบาร์โค้ดบนตัวเครื่อง', 'live');
      if (c.canTorch) { btnTorch.hidden = false; }
      if (c.zoom && c.zoom.max > c.zoom.min) {
        zoomEl.min = c.zoom.min;
        zoomEl.max = Math.min(c.zoom.max, 8);
        zoomEl.step = c.zoom.step;
        zoomEl.value = c.zoom.min;
        zoomWrap.hidden = false;
      }
      renderButtons();
    }).catch(function (e) {
      starting = false;
      setStatus('เปิดกล้องไม่ได้ (' + (e && e.message ? e.message : e) + ') — พิมพ์รหัสเองแทนได้', 'err');
      renderButtons();
    });
  }

  btnPower.addEventListener('click', function () {
    camOn = !camOn;
    renderButtons();
    if (camOn) {
      hit.hidden = true; busy = false;
      startCam();
    } else {
      stopCam().then(function () { setStatus('ปิดกล้องแล้ว — กด "เปิดกล้อง" เพื่อสแกนต่อ'); });
    }
  });
  btnFlip.addEventListener('click', function () {
    if (!camOn || starting) return;
    facing = facing === 'user' ? 'environment' : 'user';
    store('ssFacing', facing);
    setStatus('กำลังสลับกล้อง…');
    stopCam().then(startCam);
  });
  btnSound.addEventListener('click', function () {
    // แตะครั้งแรกคือการปลุกเสียง ไม่ใช่กดปิด
    if (soundOn && Date.now() - unlockedAt < 1500) { tone('ok'); return; }
    soundOn = !soundOn;
    store('ssSound', soundOn ? '1' : '0');
    renderButtons();
    if (soundOn) { tone('ok'); }
  });
  btnTorch.addEventListener('click', function () {
    if (!ctl) return;
    torch = !torch;
    ctl.setTorch(torch).then(function () { btnTorch.classList.toggle('on', torch); })
      .catch(function () { btnTorch.hidden = true; });
  });
  zoomEl.addEventListener('input', function () {
    if (ctl) { ctl.setZoom(zoomEl.value).catch(function () {}); }
  });
  // สลับแอป/ล็อกจอ → ปิดกล้อง กลับมาแล้วเปิดให้เอง (ถ้าไม่ได้กดปิดไว้)
  document.addEventListener('visibilitychange', function () {
    if (!camOn) return;
    if (document.hidden) { stopCam(); } else { startCam(); }
  });
  // กลับมาหน้านี้ด้วยปุ่มย้อนกลับ (เบราว์เซอร์เก็บหน้าไว้ทั้งหน้า) → เปิดกล้องใหม่
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    hit.hidden = true; busy = false; camOn = true;
    renderButtons(); startCam();
  });

  renderButtons();
  startCam();
})();
</script>
<?php page_footer();

