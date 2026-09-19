<?php
/**
 * leasing_move.php — ย้ายเครื่องผลิตใหม่ไปลงทะเบียนในระบบเช่า (แบบ ก · 19 ก.ย. 2026)
 *
 * ตัวกรอง: รุ่น · ช่วงวันที่ผลิต · ค้นหา/สแกน S/N → ติ๊กเลือก (หรือเลือกทั้งหมดที่กรอง) → ยืนยัน → ย้าย
 * แสดงเฉพาะเครื่องสถานะ "ใหม่" ที่ยังไม่อยู่ในระบบเช่า · รุ่นที่ยังไม่ตั้งชื่อในระบบเช่าบอกเหตุผลแทนช่องติ๊ก
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
    header('Location: ' . $B . '/leasing_move.php?' . http_build_query(array_intersect_key($_POST, array_flip(['product', 'from', 'to', 'q']))));
    exit;
}

$pid = (int) ($_GET['product'] ?? 0);
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$validDate = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : ''; };
$from = $validDate($from);
$to = $validDate($to);

// รุ่นที่มีเครื่องใหม่ (ตัวเลือกในตัวกรอง)
$products = [];
$res = db()->query("SELECT p.id, p.name, p.leasing_name, COUNT(a.id) n FROM products p JOIN assets a ON a.product_id = p.id AND a.status = 'new'
                    WHERE p.is_active = 1 GROUP BY p.id ORDER BY p.name");
while ($r = $res->fetch_assoc()) {
    $products[(int) $r['id']] = $r;
}

$where = ["a.status = 'new'"];
$types = '';
$args = [];
if ($pid > 0) { $where[] = 'a.product_id = ?'; $types .= 'i'; $args[] = $pid; }
if ($from !== '') { $where[] = 'a.produced_at >= ?'; $types .= 's'; $args[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'a.produced_at <= ?'; $types .= 's'; $args[] = $to . ' 23:59:59'; }
if ($q !== '') { $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)'; $types .= 'ss'; $args[] = "%$q%"; $args[] = "%$q%"; }
$rows = [];
$res = qr('SELECT a.id, a.asset_code, a.produced_at, a.product_id, p.name pname, p.leasing_name
           FROM assets a JOIN products p ON p.id = a.product_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, a.produced_at DESC, a.asset_code LIMIT 2000',
          $types, $args);
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$leaseOk = function_exists('dbLeasing') && dbLeasing();
$inLease = $leaseOk ? leasing_existing_sns(array_column($rows, 'asset_code')) : [];
$movable = [];
$blocked = [];   // รุ่นที่ย้ายไม่ได้ => [เหตุผล, จำนวน]
$alreadyN = 0;
foreach ($rows as $r) {
    if (isset($inLease[strtoupper(trim((string) $r['asset_code']))])) {
        $alreadyN++;
        continue;
    }
    $why = leasing_product_block_reason($r);
    if ($why !== '') {
        $blocked[$r['pname']] = [$why, ($blocked[$r['pname']][1] ?? 0) + 1];
        continue;
    }
    $movable[] = $r;
}

page_header('ย้ายไประบบเช่า', true, 'เครื่องใหม่ที่ยังไม่อยู่ในระบบเช่า → ลงทะเบียนเป็นคลังพร้อมเช่า (finished goods)', $B . '/assets.php');
?>
<?php if (!$leaseOk) { ?>
<div class="panel"><p class="err" style="margin:0">เชื่อมต่อระบบเช่าไม่ได้ — ย้ายไม่ได้ตอนนี้</p></div>
<?php } ?>
<form method="get" class="filter lm-filter">
  <select name="product" onchange="this.form.submit()">
    <option value="0">ทุกรุ่น</option>
    <?php foreach ($products as $p) { ?>
    <option value="<?= (int) $p['id'] ?>"<?= $pid === (int) $p['id'] ? ' selected' : '' ?>><?= h($p['name']) ?> (<?= (int) $p['n'] ?>)</option>
    <?php } ?>
  </select>
  <input type="date" name="from" value="<?= h($from) ?>" title="ผลิตตั้งแต่" onchange="this.form.submit()">
  <input type="date" name="to" value="<?= h($to) ?>" title="ผลิตถึง" onchange="this.form.submit()">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="ค้นหา / สแกน S/N" data-scan="submit">
  <button type="submit">ค้นหา</button>
  <?php if ($pid || $from || $to || $q !== '') { ?><a class="btn btn-line btn-sm" href="<?= h($B . '/leasing_move.php') ?>" data-same-tab>ล้างตัวกรอง</a><?php } ?>
</form>

<?php if ($blocked) { ?>
<details class="panel lm-blocked">
  <summary><b>รุ่นที่ยังย้ายไม่ได้ <?= count($blocked) ?> รุ่น</b> <span class="muted">— ตั้งชื่อรุ่นในระบบเช่าได้ที่ระบบหลังบ้าน</span></summary>
  <ul><?php foreach ($blocked as $name => $b) { ?><li><?= h($name) ?> (<?= (int) $b[1] ?> เครื่อง) — <?= h($b[0]) ?></li><?php } ?></ul>
</details>
<?php } ?>

<form method="post" id="lm-form">
  <?= csrf_field() ?>
  <?php foreach (['product' => $pid ?: '', 'from' => $from, 'to' => $to, 'q' => $q] as $k => $v) { ?><input type="hidden" name="<?= $k ?>" value="<?= h((string) $v) ?>"><?php } ?>
  <p class="muted lm-count">ย้ายได้ <?= number_format(count($movable)) ?> เครื่อง<?= $alreadyN ? ' · อยู่ในระบบเช่าแล้ว ' . number_format($alreadyN) . ' เครื่อง (ไม่แสดง)' : '' ?><?= count($rows) >= 2000 ? ' · แสดง 2,000 แรก — กรองให้แคบลง' : '' ?></p>
  <?php if (!$movable) { ?>
  <div class="panel"><p class="muted" style="margin:0">ไม่มีเครื่องที่ย้ายได้ตามตัวกรองนี้</p></div>
  <?php } else { ?>
  <div class="panel lm-list">
    <label class="lm-row lm-all"><input type="checkbox" id="lm-all"> <b>เลือกทั้ง <?= number_format(count($movable)) ?> เครื่องที่กรอง</b></label>
    <?php foreach ($movable as $r) { ?>
    <label class="lm-row">
      <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" data-model="<?= h($r['pname']) ?>" data-lname="<?= h((string) $r['leasing_name']) ?>">
      <span class="lm-code"><?= h($r['asset_code']) ?></span>
      <span class="muted lm-meta"><?= h($r['pname']) ?> · ผลิต <?= h(dthai(substr((string) $r['produced_at'], 0, 10))) ?></span>
    </label>
    <?php } ?>
  </div>
  <div class="lm-bar">
    <span id="lm-picked">เลือก 0 เครื่อง</span>
    <button type="button" class="btn" id="lm-go" disabled>ย้ายไประบบเช่า ›</button>
  </div>
  <?php } ?>
  <input type="hidden" name="move" value="1">
</form>

<div class="notif-overlay" id="lm-confirm" hidden>
  <div class="notif-box" style="width:min(460px,94vw)">
    <h2 style="margin:0 0 8px" id="lm-c-title"></h2>
    <p class="muted" style="margin:0 0 8px">ลงทะเบียนในระบบเช่าเป็น <b>คลังพร้อมเช่า (finished goods)</b> · ผู้บันทึก: <?= h(actor_name()) ?> · วันที่วันนี้<br>สถานะในระบบเราจะเปลี่ยนเป็น "เครื่องเช่า"</p>
    <ul id="lm-c-models" class="lm-c-models"></ul>
    <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px">
      <button type="button" class="btn btn-line" onclick="closeOverlay('lm-confirm')">ยกเลิก</button>
      <button type="button" class="btn" id="lm-c-ok">ยืนยันย้าย</button>
    </div>
  </div>
</div>

<script>
(function(){
  var form = document.getElementById('lm-form');
  var all = document.getElementById('lm-all');
  var go = document.getElementById('lm-go');
  if (!form || !go) return;
  var boxes = function(){ return Array.prototype.slice.call(form.querySelectorAll('input[name="ids[]"]')); };
  function picked(){ return boxes().filter(function(b){ return b.checked; }); }
  function render(){
    var n = picked().length;
    document.getElementById('lm-picked').textContent = 'เลือก ' + n.toLocaleString() + ' เครื่อง';
    go.disabled = n === 0;
    if (all) all.checked = n > 0 && n === boxes().length;
  }
  form.addEventListener('change', function(e){
    if (e.target === all) boxes().forEach(function(b){ b.checked = all.checked; });
    render();
  });
  go.addEventListener('click', function(){
    var sel = picked();
    if (!sel.length) return;
    var byModel = {};
    sel.forEach(function(b){
      var k = b.getAttribute('data-model') + ' ' + b.getAttribute('data-lname');
      byModel[k] = (byModel[k] || 0) + 1;
    });
    document.getElementById('lm-c-title').textContent = 'ยืนยันย้าย ' + sel.length.toLocaleString() + ' เครื่องไประบบเช่า';
    document.getElementById('lm-c-models').innerHTML = Object.keys(byModel).map(function(k){
      var p = k.split(' ');
      var e = function(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
      return '<li>' + e(p[0]) + ' → ชื่อในระบบเช่า "<b>' + e(p[1]) + '</b>" · ' + byModel[k] + ' เครื่อง</li>';
    }).join('');
    document.getElementById('lm-confirm').hidden = false;
  });
  document.getElementById('lm-c-ok').addEventListener('click', function(){
    this.disabled = true;
    this.textContent = 'กำลังย้าย…';
    form.submit();
  });
  render();
})();
</script>
<style>
.lm-blocked { border-left: 3px solid var(--warning, #b45309); border-radius: 0; }
.lm-blocked summary { cursor: pointer; }
.lm-blocked ul { margin: 6px 0 0; padding-left: 18px; line-height: 1.7; font-size: calc(13px * var(--font-scale, 1)); }
.lm-count { margin: 4px 0 8px; }
.lm-list { padding: 4px 0; }
.lm-row { display: flex; align-items: center; gap: 10px; padding: 8px 14px; border-bottom: 1px solid var(--border); cursor: pointer; min-height: 44px; }
.lm-row:last-child { border-bottom: 0; }
.lm-row:has(input:checked) { background: var(--primary-soft, #fce7f3); }
.lm-all { background: var(--surface-soft, #f4f1fa); }
.lm-code { font-weight: 600; font-variant-numeric: tabular-nums; }
.lm-meta { margin-left: auto; font-size: calc(12.5px * var(--font-scale, 1)); text-align: right; }
.lm-bar { position: sticky; bottom: 12px; z-index: 5; display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px 14px;
  background: var(--surface, #fff); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-md); }
.lm-bar .btn { margin-left: auto; }
.lm-c-models { margin: 0; padding-left: 18px; line-height: 1.7; }
@media (max-width: 640px) {
  .lm-bar { bottom: calc(76px + env(safe-area-inset-bottom, 0px)); }
  .lm-row { flex-wrap: wrap; }
  .lm-meta { margin-left: 30px; text-align: left; width: 100%; }
}
</style>
<?php page_footer();
