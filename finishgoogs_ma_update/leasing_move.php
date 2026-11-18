<?php
/**
 * leasing_move.php — ย้ายเครื่องผลิตใหม่ไปลงทะเบียนในระบบเช่า (แบบ ก · 19 ก.ย. 2026)
 *
 * เริ่มที่รายการรุ่นสินค้า (รูปประจำรุ่น + จำนวนที่ย้ายได้)
 * กดรุ่นแล้วเลือกหมายเลขเครื่องใน popup ไม่เปลี่ยนหน้า
 * ตัวกรอง: ช่วงวันที่ผลิต · ค้นหา/สแกน S/N → ติ๊กใน popup → ยืนยัน → ย้าย
 * แสดงเฉพาะเครื่องสถานะ "ใหม่" ที่ยังไม่อยู่ในระบบเช่า · รุ่นที่ยังไม่ตั้งชื่อในระบบเช่าบอกเหตุผลบนการ์ด
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/leasing_move.php';
require_login();
ensure_leasing_move_schema();

$B = BASE_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move'])) {
    csrf_check();
    $r = leasing_move_assets((array) ($_POST['ids'] ?? []), actor_name());
    if (!$r['ok']) {
        flash_set($r['error'], 'err');
    } else {
        $msg = 'ย้ายไประบบเช่าแล้ว ' . count($r['moved']) . ' เครื่อง (คลังพร้อมเช่า)';
        if ($r['skipped']) {
            $parts = [];
            foreach (array_slice($r['skipped'], 0, 5, true) as $code => $why) {
                $parts[] = $code . ': ' . $why;
            }
            $msg .= ' · ข้าม ' . count($r['skipped']) . ' เครื่อง — ' . implode(' · ', $parts) . (count($r['skipped']) > 5 ? ' …' : '');
        }
        flash_set($msg, $r['moved'] ? 'ok' : 'err');
    }
    header('Location: ' . $B . '/leasing_move.php?' . http_build_query(array_intersect_key($_POST, array_flip(['from', 'to', 'q']))));
    exit;
}

$pid = (int) ($_GET['product'] ?? 0);
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) {
    $q = mb_substr($q, 0, 80);
}
$validDate = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : ''; };
$from = $validDate($from);
$to = $validDate($to);

$where = ["a.status = 'new'", 'p.is_active = 1'];
$types = '';
$args = [];
if ($from !== '') { $where[] = 'a.produced_at >= ?'; $types .= 's'; $args[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'a.produced_at <= ?'; $types .= 's'; $args[] = $to . ' 23:59:59'; }
if ($q !== '') { $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)'; $types .= 'ss'; $args[] = "%$q%"; $args[] = "%$q%"; }
$rows = [];
$res = qr('SELECT a.id, a.asset_code, a.produced_at, a.product_id, p.name pname, p.leasing_name, p.icon_path
           FROM assets a JOIN products p ON p.id = a.product_id WHERE ' . implode(' AND ', $where) . '
           ORDER BY p.name, a.produced_at DESC, a.asset_code',
          $types, $args);
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$leaseOk = function_exists('dbLeasing') && dbLeasing();
$inLease = $leaseOk ? leasing_existing_sns(array_column($rows, 'asset_code')) : [];
$movable = [];
$groups = [];
$blockedGroups = [];
$alreadyN = 0;
foreach ($rows as $r) {
    $id = (int) $r['product_id'];
    $meta = [
        'id' => $id,
        'name' => (string) $r['pname'],
        'leasing_name' => (string) $r['leasing_name'],
        'icon_path' => (string) ($r['icon_path'] ?? ''),
    ];
    if (isset($inLease[strtoupper(trim((string) $r['asset_code']))])) {
        $alreadyN++;
        continue;
    }
    $why = leasing_product_block_reason($r);
    if ($why !== '') {
        if (!isset($blockedGroups[$id])) {
            $blockedGroups[$id] = $meta + ['n' => 0, 'why' => $why];
        }
        $blockedGroups[$id]['n']++;
        continue;
    }
    if (!isset($groups[$id])) {
        $groups[$id] = $meta + ['rows' => []];
    }
    $groups[$id]['rows'][] = $r;
    $movable[] = $r;
}

$openPid = ($pid > 0 && isset($groups[$pid])) ? $pid : 0;
if ($openPid === 0 && $q !== '' && count($groups) === 1) {
    $openPid = (int) array_keys($groups)[0];
}

page_header('ย้ายไประบบเช่า', true, 'เครื่องใหม่ที่ยังไม่อยู่ในระบบเช่า → ลงทะเบียนเป็นคลังพร้อมเช่า (finished goods)', $B . '/assets.php');
?>
<?php if (!$leaseOk) { ?>
<div class="panel"><p class="err" style="margin:0">เชื่อมต่อระบบเช่าไม่ได้ — ย้ายไม่ได้ตอนนี้</p></div>
<?php } ?>
<form method="get" class="filter lm-filter">
  <input type="date" name="from" value="<?= h($from) ?>" title="ผลิตตั้งแต่" onchange="this.form.submit()">
  <input type="date" name="to" value="<?= h($to) ?>" title="ผลิตถึง" onchange="this.form.submit()">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="ค้นหา / สแกน S/N" data-scan="submit">
  <button type="submit">ค้นหา</button>
  <?php if ($from || $to || $q !== '') { ?><a class="btn btn-line btn-sm" href="<?= h($B . '/leasing_move.php') ?>" data-same-tab>ล้างตัวกรอง</a><?php } ?>
</form>

<p class="muted lm-count">ย้ายได้ <?= number_format(count($movable)) ?> เครื่อง · กดการ์ดรุ่นเพื่อเลือกหมายเลข<?= $alreadyN ? ' · อยู่ในระบบเช่าแล้ว ' . number_format($alreadyN) . ' เครื่อง (ไม่แสดง)' : '' ?></p>

<?php if (!$groups && !$blockedGroups) { ?>
<div class="panel"><p class="muted" style="margin:0">ไม่มีเครื่องที่ย้ายได้ตามตัวกรองนี้</p></div>
<?php } ?>

<?php if ($groups) { ?>
<h2 class="lm-section-title">เลือกรุ่นสินค้า</h2>
<form method="post" id="lm-form">
  <?= csrf_field() ?>
  <?php foreach (['from' => $from, 'to' => $to, 'q' => $q] as $k => $v) { ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>"><?php } ?>
  <input type="hidden" name="move" value="1">
  <div class="model-card-grid lm-models">
    <?php foreach ($groups as $g) {
        $n = count($g['rows']);
        $sub = trim((string) $g['leasing_name']);
        ?>
    <button type="button" class="model-card clickable" data-lm-open="<?= (int) $g['id'] ?>"
      data-lm-title="<?= h($g['name']) ?>"
      data-lm-sub="<?= h($sub !== '' ? 'ชื่อในระบบเช่า · ' . $sub : '') ?>">
      <div class="model-card-img"><?= leasing_move_product_thumb($g['icon_path'], $g['name']) ?></div>
      <div class="model-card-body">
        <div class="model-card-name" title="<?= h($g['name']) ?>"><?= h($g['name']) ?></div>
        <?php if ($sub !== '') { ?><div class="model-card-sub muted">ชื่อในระบบเช่า · <?= h($sub) ?></div><?php } ?>
        <div class="model-card-num">ย้ายได้ <?= number_format($n) ?> เครื่อง</div>
      </div>
    </button>
    <?php } ?>
  </div>

  <div class="notif-overlay" id="lm-pick" hidden>
    <div class="notif-box lm-pick-box" role="dialog" aria-modal="true" aria-labelledby="lm-pick-title">
      <div class="lm-pick-head">
        <div class="lm-pick-head-img" id="lm-pick-img"></div>
        <div class="lm-pick-head-text">
          <h2 id="lm-pick-title"></h2>
          <div class="muted" id="lm-pick-sub"></div>
        </div>
        <button type="button" class="btn-sm btn-line btn-icon-only" onclick="closeOverlay('lm-pick')" aria-label="ปิด"><?= ui_icon_html('close', 16, 'btn-svg') ?></button>
      </div>
      <div class="lm-pick-body" id="lm-pick-body">
        <?php foreach ($groups as $g) { ?>
        <div class="lm-group" data-pid="<?= (int) $g['id'] ?>" hidden>
          <label class="lm-row lm-all">
            <input type="checkbox" class="lm-group-all">
            <b>เลือกทั้ง <?= number_format(count($g['rows'])) ?> เครื่องรุ่นนี้</b>
          </label>
          <?php foreach ($g['rows'] as $r) { ?>
          <label class="lm-row">
            <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" disabled
              data-model="<?= h($r['pname']) ?>" data-lname="<?= h((string) $r['leasing_name']) ?>"
              data-code="<?= h((string) $r['asset_code']) ?>">
            <span class="lm-code"><?= h($r['asset_code']) ?></span>
            <span class="muted lm-meta">ผลิต <?= h(dthai(substr((string) $r['produced_at'], 0, 10))) ?></span>
          </label>
          <?php } ?>
        </div>
        <?php } ?>
      </div>
      <div class="lm-pick-foot">
        <span id="lm-picked">เลือก 0 เครื่อง</span>
        <button type="button" class="btn" id="lm-go" disabled>ย้ายไประบบเช่า ›</button>
      </div>
    </div>
  </div>
</form>
<?php } ?>

<?php if ($blockedGroups) { ?>
<h2 class="lm-section-title">รุ่นที่ยังย้ายไม่ได้</h2>
<p class="muted lm-blocked-hint">ตั้งชื่อรุ่นในระบบเช่าได้ที่ระบบหลังบ้าน</p>
<div class="model-card-grid lm-models">
  <?php foreach ($blockedGroups as $g) { ?>
  <div class="model-card lm-model-blocked">
    <div class="model-card-img"><?= leasing_move_product_thumb($g['icon_path'], $g['name']) ?></div>
    <div class="model-card-body">
      <div class="model-card-name" title="<?= h($g['name']) ?>"><?= h($g['name']) ?></div>
      <div class="model-card-sub muted"><?= h($g['why']) ?></div>
      <div class="model-card-num"><?= number_format((int) $g['n']) ?> เครื่อง</div>
    </div>
  </div>
  <?php } ?>
</div>
<?php } ?>

<div class="notif-overlay" id="lm-confirm" hidden>
  <div class="notif-box" style="width:min(460px,94vw)">
    <h2 style="margin:0 0 8px" id="lm-c-title"></h2>
    <p class="muted" style="margin:0 0 8px">ลงทะเบียนในระบบเช่าเป็น <b>คลังพร้อมเช่า (finished goods)</b> · ผู้บันทึก: <?= h(actor_name()) ?> · วันที่วันนี้<br>สถานะในระบบเราจะเปลี่ยนเป็น "เครื่องเช่า"</p>
    <ul id="lm-c-models" class="lm-c-models"></ul>
    <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px">
      <button type="button" class="btn btn-line" id="lm-c-cancel">ยกเลิก</button>
      <button type="button" class="btn" id="lm-c-ok">ยืนยันย้าย</button>
    </div>
  </div>
</div>

<script>
(function(){
  var form = document.getElementById('lm-form');
  var go = document.getElementById('lm-go');
  var pick = document.getElementById('lm-pick');
  var imgHost = document.getElementById('lm-pick-img');
  var titleEl = document.getElementById('lm-pick-title');
  var subEl = document.getElementById('lm-pick-sub');
  if (!form || !go || !pick) return;
  var openPid = <?= (int) $openPid ?>;
  var q = <?= json_encode($q, JSON_UNESCAPED_UNICODE) ?>;

  function groups(){ return Array.prototype.slice.call(form.querySelectorAll('.lm-group')); }
  function activeGroup(){ return form.querySelector('.lm-group:not([hidden])'); }
  function boxes(g){
    g = g || activeGroup();
    if (!g) return [];
    return Array.prototype.slice.call(g.querySelectorAll('input[name="ids[]"]'));
  }
  function picked(){ return boxes().filter(function(b){ return b.checked && !b.disabled; }); }
  function setGroupLive(g, on){
    g.hidden = !on;
    Array.prototype.slice.call(g.querySelectorAll('input[type="checkbox"]')).forEach(function(b){
      b.disabled = !on;
      if (!on) b.checked = false;
    });
  }
  function render(){
    var all = boxes();
    var n = picked().length;
    var el = document.getElementById('lm-picked');
    if (el) el.textContent = 'เลือก ' + n.toLocaleString() + ' เครื่อง';
    go.disabled = n === 0;
    var g = activeGroup();
    var ga = g ? g.querySelector('.lm-group-all') : null;
    if (ga) ga.checked = n > 0 && n === all.length;
  }
  function openPick(pid, btn){
    groups().forEach(function(g){ setGroupLive(g, g.getAttribute('data-pid') === String(pid)); });
    var g = activeGroup();
    if (!g) return;
    if (btn) {
      titleEl.textContent = btn.getAttribute('data-lm-title') || '';
      subEl.textContent = btn.getAttribute('data-lm-sub') || '';
      var src = btn.querySelector('.model-card-img');
      imgHost.innerHTML = src ? src.innerHTML : '';
    }
    if (q) {
      var needle = String(q).toUpperCase();
      boxes(g).forEach(function(b){
        var code = (b.getAttribute('data-code') || '').toUpperCase();
        if (needle && code.indexOf(needle) !== -1) b.checked = true;
      });
    }
    openOverlay('lm-pick');
    render();
  }

  form.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-lm-open]') : null;
    if (!btn || !form.contains(btn)) return;
    openPick(btn.getAttribute('data-lm-open'), btn);
  });
  form.addEventListener('change', function(e){
    var t = e.target;
    if (t.classList && t.classList.contains('lm-group-all')) {
      boxes().forEach(function(b){ b.checked = t.checked; });
    }
    render();
  });
  go.addEventListener('click', function(){
    var sel = picked();
    if (!sel.length) return;
    var byModel = {};
    sel.forEach(function(b){
      var k = b.getAttribute('data-model') + '\0' + b.getAttribute('data-lname');
      byModel[k] = (byModel[k] || 0) + 1;
    });
    document.getElementById('lm-c-title').textContent = 'ยืนยันย้าย ' + sel.length.toLocaleString() + ' เครื่องไประบบเช่า';
    document.getElementById('lm-c-models').innerHTML = Object.keys(byModel).map(function(k){
      var p = k.split('\0');
      var e = function(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
      return '<li>' + e(p[0]) + ' → ชื่อในระบบเช่า "<b>' + e(p[1]) + '</b>" · ' + byModel[k] + ' เครื่อง</li>';
    }).join('');
    pick.style.visibility = 'hidden';
    openOverlay('lm-confirm');
  });
  document.getElementById('lm-c-ok').addEventListener('click', function(){
    this.disabled = true;
    this.textContent = 'กำลังย้าย…';
    form.submit();
  });
  document.getElementById('lm-c-cancel').addEventListener('click', function(){
    closeOverlay('lm-confirm');
  });

  if (window.MutationObserver) {
    var confirmOv = document.getElementById('lm-confirm');
    new MutationObserver(function(){
      if (!pick.hidden) return;
      if (confirmOv && !confirmOv.hidden) closeOverlay('lm-confirm');
      groups().forEach(function(g){ setGroupLive(g, false); });
    }).observe(pick, { attributes: true, attributeFilter: ['hidden'] });
    if (confirmOv) {
      new MutationObserver(function(){
        if (confirmOv.hidden) pick.style.visibility = '';
      }).observe(confirmOv, { attributes: true, attributeFilter: ['hidden'] });
    }
  }

  if (openPid) {
    var auto = form.querySelector('[data-lm-open="' + openPid + '"]');
    if (auto) openPick(String(openPid), auto);
  }
})();
</script>
<style>
.lm-count { margin: 4px 0 12px; }
.lm-section-title { margin: 4px 0 10px; font-size: calc(15px * var(--font-scale, 1)); font-weight: 700; }
.lm-blocked-hint { margin: -6px 0 10px; font-size: calc(12.5px * var(--font-scale, 1)); }
.lm-models .model-card-img { width: 72px; height: 72px; }
.lm-models .model-thumb,
.lm-models .model-thumb-ph { width: 68px; height: 68px; }
button.model-card {
  width: 100%; margin: 0; text-align: left; font: inherit; color: inherit;
  justify-content: flex-start;
  -webkit-appearance: none; appearance: none; cursor: pointer;
  background: var(--surface, #fff);
}
button.model-card:hover,
button.model-card:focus-visible,
button.model-card:active {
  background: var(--surface, #fff);
  color: inherit;
  border-color: var(--border-strong);
  box-shadow: var(--shadow-md);
}
.lm-model-blocked { cursor: default; opacity: .82; }
.lm-model-blocked:hover { box-shadow: none; border-color: var(--border); background: var(--surface, #fff); }
.lm-pick-box {
  width: min(560px, 96vw); max-height: 92vh; display: flex; flex-direction: column;
  overflow: hidden; padding: 14px 0 0;
}
.lm-pick-head { display: flex; align-items: center; gap: 12px; padding: 0 16px 12px; flex-shrink: 0; }
.lm-pick-head-img { flex-shrink: 0; width: 56px; height: 56px; display: flex; align-items: center; justify-content: center;
  background: var(--surface-muted, #f4f1fa); border-radius: var(--radius-sm, 8px); overflow: hidden; }
.lm-pick-head-img .model-thumb, .lm-pick-head-img .model-thumb-ph { width: 52px; height: 52px; }
.lm-pick-head-text { min-width: 0; flex: 1; }
.lm-pick-head-text h2 { margin: 0; font-size: calc(16px * var(--font-scale, 1)); font-weight: 700; }
.lm-pick-body { overflow: auto; flex: 1; min-height: 120px; border-top: 1px solid var(--border); }
.lm-pick-foot { display: flex; align-items: center; gap: 10px; padding: 10px 16px; flex-shrink: 0;
  border-top: 1px solid var(--border); background: var(--surface, #fff); }
.lm-pick-foot .btn { margin-left: auto; }
.lm-row { display: flex; align-items: center; gap: 10px; padding: 8px 14px; border-bottom: 1px solid var(--border); cursor: pointer; min-height: 44px; }
.lm-row:last-child { border-bottom: 0; }
.lm-row:has(input:checked) { background: var(--primary-soft, #fce7f3); }
.lm-all { background: var(--surface-soft, #f4f1fa); }
.lm-code { font-weight: 600; font-variant-numeric: tabular-nums; }
.lm-meta { margin-left: auto; font-size: calc(12.5px * var(--font-scale, 1)); text-align: right; }
.lm-c-models { margin: 0; padding-left: 18px; line-height: 1.7; }
@media (max-width: 640px) {
  .lm-row { flex-wrap: wrap; }
  .lm-meta { margin-left: 30px; text-align: left; width: 100%; }
}
</style>
<?php page_footer();
