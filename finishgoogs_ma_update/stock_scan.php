<?php
/**
 * stock_scan.php — นับสต็อกด้วยการสแกน QR บนมือถือ แล้วตัดสถานะเครื่องที่ไม่เจอ
 *
 * ออกแบบให้ใช้มือถือเป็นหลัก: กล้องเปิดค้างไว้ สแกนเจอแล้วตรวจกับทะเบียน (ajax=check ไม่บันทึก)
 * ขึ้นการ์ดให้กดยืนยัน/ยกเลิก กดยืนยันจึงบันทึก (ajax=scan) · ตั้งให้บันทึกทันทีได้
 * หลายคนสแกนเข้ารอบเดียวกันได้
 * ขั้นตอน/กติกาการตัดสถานะอยู่ใน includes/stock_scan.php
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/asset_status_sync.php';
require_once __DIR__ . '/includes/stock_scan.php';
require_login();
ensure_stock_scan_schema();

$B = BASE_URL;
$open = stock_scan_open_session();

// ── AJAX: สแกน / ลบรายการ ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== csrf()) {
        echo json_encode(['ok' => false, 'message' => 'หมดเวลาการใช้งาน รีเฟรชหน้าแล้วลองใหม่'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$open) {
        echo json_encode(['ok' => false, 'message' => 'ไม่มีรอบนับที่เปิดอยู่'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_POST['ajax'] === 'check') {
        echo json_encode(stock_scan_check((int) $open['id'], (string) ($_POST['code'] ?? '')), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_POST['ajax'] === 'scan') {
        $r = stock_scan_add((int) $open['id'], (string) ($_POST['code'] ?? ''));
        $r['recent'] = stock_scan_recent((int) $open['id'], 15);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_POST['ajax'] === 'remove') {
        stock_scan_remove((int) $open['id'], (int) ($_POST['id'] ?? 0));
        echo json_encode([
            'ok'     => true,
            'count'  => stock_scan_count((int) $open['id']),
            'recent' => stock_scan_recent((int) $open['id'], 15),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'message' => 'ไม่รู้จักคำสั่ง'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ฟอร์มปกติ ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['start'])) {
        $all = !empty($_POST['scope_all']);
        $r = stock_scan_start($all ? [] : (array) ($_POST['products'] ?? []), (string) ($_POST['note'] ?? ''));
        if (!$all && empty($_POST['products'])) {
            flash_set('เลือกรุ่นที่จะนับอย่างน้อย 1 รุ่น หรือเลือก "นับทุกรุ่น"', 'err');
            header('Location: ' . $B . '/stock_scan.php'); exit;
        }
        flash_set($r['message'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . $B . '/stock_scan.php'); exit;
    }
    if (isset($_POST['cancel']) && $open) {
        $back = stock_scan_cancel((int) $open['id']);
        flash_set('ยกเลิกรอบนับแล้ว' . ($back > 0 ? ' — คืนสถานะเดิมให้ ' . number_format($back) . ' เครื่องที่สแกนไว้' : ''));
        header('Location: ' . $B . '/stock_scan.php'); exit;
    }
    if (isset($_POST['finish']) && $open) {
        $n = stock_scan_count((int) $open['id']);
        stock_scan_finish((int) $open['id']);
        flash_set('จบรอบนับแล้ว — นับได้ ' . number_format($n) . ' เครื่อง ตั้งเป็นเครื่องใหม่แล้ว เครื่องอื่นไม่ถูกเปลี่ยน');
        header('Location: ' . $B . '/stock_scan.php'); exit;
    }
    if (isset($_POST['apply'])) {
        $sid = (int) ($_POST['session_id'] ?? 0);
        $keep = (array) ($_POST['apply_keys'] ?? []);
        $allKeys = (array) ($_POST['all_keys'] ?? []);
        $skip = array_values(array_diff($allKeys, $keep));
        $r = stock_scan_plan($sid, true, $skip);
        flash_set($r['ok'] ? $r['message'] . ' — ย้อนกลับได้ที่หน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ' : $r['message'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . $B . '/stock_scan.php'); exit;
    }
}

// ?review=<id> ใช้ได้ทั้งรอบที่เปิดอยู่ และรอบที่จบแบบเก็บเฉพาะที่สแกน (ตัดเครื่องอื่นทีหลัง)
$reviewSession = null;
if (isset($_GET['review'])) {
    $rid = (int) $_GET['review'];
    $reviewSession = ($rid > 1 || !$open) ? stock_scan_session($rid) : $open;
    if ($reviewSession && !stock_scan_can_cut($reviewSession)) {
        $reviewSession = null;
    }
}
// ?session=<id> = ดูรายการ S/N ที่สแกนของรอบนั้น (ย้อนดูได้ทุกรอบ)
$detailSession = isset($_GET['session']) ? stock_scan_session((int) $_GET['session']) : null;
$view = $detailSession ? 'detail' : ($reviewSession ? 'review' : ($open ? 'scan' : 'start'));

$headSession = $detailSession ?: ($reviewSession ?: $open);
page_header('นับสต็อกด้วยการสแกน', true, $headSession ? 'รอบนับ #' . (int) $headSession['id'] . ' · เปิดโดย ' . $headSession['started_by'] : 'สแกน QR เครื่องที่อยู่ในคลังจริง แล้วตัดสถานะเครื่องที่ไม่เจอ');
?>

<?php if ($view === 'start') {
    $products = [];
    $res = qr("SELECT p.id, p.name, COUNT(a.id) total, SUM(a.status = 'new') in_stock
               FROM products p JOIN assets a ON a.product_id = p.id
               WHERE p.is_active = 1 GROUP BY p.id ORDER BY in_stock DESC, p.name");
    while ($r = $res->fetch_assoc()) { $products[] = $r; }
    $last = qr("SELECT * FROM stock_scan_sessions WHERE status = 'applied' ORDER BY id DESC LIMIT 1")->fetch_assoc();
?>
<?php // มีรอบที่จบแล้ว = คนกำลังดูผล พับวิธีใช้เก็บไว้ ให้สรุปผลขึ้นมาอยู่ใกล้ ๆ ?>
<details class="panel ss-intro"<?= $last ? '' : ' open' ?>>
  <summary><b>วิธีนับสต็อก</b></summary>
  <ol class="ss-steps">
    <li><b>เลือกรุ่นที่จะนับ</b> — นับทีละรุ่นหรือทุกรุ่นก็ได้ เครื่องรุ่นที่ไม่ได้เลือกจะไม่ถูกแตะ</li>
    <li><b>สแกน QR ทุกเครื่องที่อยู่ในคลังจริง</b> — กล้องเปิดค้างไว้ สแกนต่อกันได้เลย หลายคนสแกนพร้อมกันได้</li>
    <li><b>สแกนเจอ = ตั้งเป็นเครื่องใหม่ทันที</b> — รวมเครื่องที่อยู่ในระบบเช่าแล้วแต่วางอยู่ในคลัง · เครื่องอื่นยังไม่ถูกแตะ</li>
    <li><b>ตัดสถานะเครื่องที่ไม่เจอ</b> (ทำทีหลังได้) — ไม่เจอแต่มีหลักฐานขาย/เช่า = ตามหลักฐาน · ไม่เจอและไม่มีหลักฐานอะไรเลย = <span class="badge" style="<?= h(status_badge_style('unknown')) ?>">ไม่มีสถานะ</span></li>
  </ol>
</details>

<?php if ($last) {
    $lastRes = stock_scan_result($last);
    $lastSum = stock_scan_summary((int) $last['id']);
    $canCut = stock_scan_can_cut($last);
?>
<section class="panel ss-done">
  <div class="ss-done-head">
    <span class="ss-done-mark">✓</span>
    <div>
      <b>รอบนับ #<?= (int) $last['id'] ?> เสร็จแล้ว</b>
      <div class="muted" style="font-size:12px">จบเมื่อ <?= h(dthai((string) $last['applied_at'])) ?> โดย <?= h((string) $last['applied_by']) ?><?= $last['note'] ? ' · ' . h((string) $last['note']) : '' ?></div>
    </div>
  </div>

  <div class="ss-done-stats">
    <div><span class="ss-done-n"><?= number_format($lastSum['scanned']) ?></span><span class="muted">เครื่องที่สแกนเจอ = เครื่องใหม่แล้ว</span></div>
    <div><span class="ss-done-n"><?= number_format($lastSum['changed']) ?></span><span class="muted">ในนั้นเปลี่ยนสถานะมา<?php
      $fromTxt = [];
      foreach ($lastSum['from'] as $st => $n) { $fromTxt[] = status_th((string) $st) . ' ' . number_format($n); }
      echo $fromTxt ? ' (จาก ' . h(implode(' · ', $fromTxt)) . ')' : '';
    ?></span></div>
    <?php if ($lastSum['not_found'] || $lastSum['out_of_scope']) { ?>
    <div><span class="ss-done-n" style="color:#b91c1c"><?= number_format(count($lastSum['not_found']) + count($lastSum['out_of_scope'])) ?></span><span class="muted">รหัสที่สแกนแล้วไม่พบในทะเบียน / นอกรอบนับ</span></div>
    <?php } ?>
  </div>

  <a class="btn btn-line ss-big" style="margin:4px 0 6px" href="<?= h($B) ?>/stock_scan.php?session=<?= (int) $last['id'] ?>">ดูรายการ S/N ที่สแกน (<?= number_format($lastSum['scanned']) ?>)</a>

  <?php if ($lastSum['models']) { ?>
  <details class="ss-done-more">
    <summary>นับได้แยกรายรุ่น (<?= count($lastSum['models']) ?> รุ่น)</summary>
    <ul><?php foreach ($lastSum['models'] as $m) { ?><li><?= h($m['name']) ?> <b><?= number_format($m['n']) ?></b></li><?php } ?></ul>
  </details>
  <?php } ?>
  <?php if ($lastSum['not_found'] || $lastSum['out_of_scope']) { ?>
  <details class="ss-done-more">
    <summary>รหัสที่ไม่พบในทะเบียน / นอกรอบนับ</summary>
    <p class="muted" style="font-size:13px;margin:6px 0 0;overflow-wrap:anywhere"><?= h(implode(', ', array_merge($lastSum['not_found'], $lastSum['out_of_scope']))) ?></p>
  </details>
  <?php } ?>

  <?php if ($canCut) { ?>
  <div class="ss-next">
    <b>ขั้นต่อไป — เครื่องที่ไม่ได้สแกน ยังเป็นสถานะเดิมทุกตัว</b>
    <p class="muted">ถ้าพร้อมแล้ว กดดูว่าเครื่องที่ไม่เจอจะเปลี่ยนเป็นอะไรบ้าง (ขายแล้ว/เช่า ตามหลักฐาน หรือ "ไม่มีสถานะ") — ยังไม่เปลี่ยนจนกว่าจะกดยืนยันในหน้าถัดไป</p>
    <a class="btn btn-primary ss-big" href="<?= h($B) ?>/stock_scan.php?review=<?= (int) $last['id'] ?>">ดูเครื่องที่ไม่เจอ / ตัดสถานะเครื่องอื่น</a>
  </div>
  <?php } elseif (($lastRes['mode'] ?? '') === 'cut') { ?>
  <p class="muted" style="margin:10px 0 0;font-size:13px">ตัดสถานะเครื่องที่ไม่เจอไปแล้ว <?= number_format((int) ($lastRes['changed'] ?? 0)) ?> เครื่อง<?= !empty($lastRes['cut_at']) ? ' เมื่อ ' . h(dthai((string) $lastRes['cut_at'])) : '' ?> · ย้อนกลับได้ที่หน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ</p>
  <?php } ?>
</section>

<h3 style="margin:18px 0 8px;font-size:15px">เริ่มรอบนับใหม่</h3>
<?php } ?>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <label class="ss-all"><input type="checkbox" name="scope_all" value="1" id="ss-all"> <b>นับทุกรุ่น</b> <span class="muted">(ทั้งคลัง)</span></label>
  <div class="ss-products" id="ss-products">
    <?php foreach ($products as $p) { ?>
    <label class="ss-prod">
      <input type="checkbox" name="products[]" value="<?= (int) $p['id'] ?>">
      <span class="ss-prod-name"><?= h($p['name']) ?></span>
      <span class="muted ss-prod-n"><?= number_format((int) $p['in_stock']) ?> ในคลัง</span>
    </label>
    <?php } ?>
  </div>
  <input type="text" name="note" placeholder="หมายเหตุ (ไม่บังคับ) เช่น นับสิ้นเดือน ก.ย." class="ss-note">
  <button type="submit" name="start" value="1" class="btn btn-primary ss-big">เริ่มนับสต็อก</button>
</form>
<?php $history = stock_scan_sessions_list(10); if ($history) { ?>
<section class="panel" style="margin-top:14px">
  <b>ประวัติรอบนับ</b>
  <ul class="ss-history">
    <?php foreach ($history as $hs) {
        $hr = stock_scan_result($hs);
        $label = $hs['status'] === 'open' ? 'กำลังนับ'
            : ($hs['status'] === 'cancelled' ? 'ยกเลิกแล้ว'
            : ((($hr['mode'] ?? '') === 'cut')
                ? 'จบแล้ว · ตัดเครื่องอื่นแล้ว' . (isset($hr['changed']) ? ' ' . number_format((int) $hr['changed']) . ' เครื่อง' : '')
                : 'จบแล้ว · ยังไม่ตัดเครื่องอื่น'));
    ?>
    <li>
      <a href="<?= h($B) ?>/stock_scan.php?session=<?= (int) $hs['id'] ?>">
        <b>รอบ #<?= (int) $hs['id'] ?></b>
        <span class="muted"><?= h(dthai((string) $hs['started_at'])) ?> · <?= h((string) $hs['started_by']) ?></span>
        <span class="ss-h-n"><?= number_format((int) $hs['scanned']) ?> เครื่อง</span>
        <span class="ss-h-st ss-h-<?= h((string) $hs['status']) ?>"><?= h($label) ?></span>
      </a>
    </li>
    <?php } ?>
  </ul>
</section>
<?php } ?>
<script>
(function () {
  var all = document.getElementById('ss-all');
  var box = document.getElementById('ss-products');
  if (!all || !box) return;
  all.addEventListener('change', function () { box.classList.toggle('ss-disabled', all.checked); });
})();
</script>

<?php } elseif ($view === 'detail') {
    $items = stock_scan_items_all((int) $detailSession['id']);
    $okItems = array_values(array_filter($items, function ($r) { return $r['result'] === 'ok'; }));
    $badItems = array_values(array_filter($items, function ($r) { return $r['result'] !== 'ok'; }));
?>
<div class="panel">
  <div class="ss-detail-head">
    <div>
      <b>รอบนับ #<?= (int) $detailSession['id'] ?></b> ·
      <?= $detailSession['status'] === 'open' ? 'กำลังนับ' : ($detailSession['status'] === 'cancelled' ? 'ยกเลิกแล้ว' : 'จบแล้ว') ?>
      <div class="muted" style="font-size:12px">เริ่ม <?= h(dthai((string) $detailSession['started_at'])) ?> โดย <?= h((string) $detailSession['started_by']) ?><?= $detailSession['note'] ? ' · ' . h((string) $detailSession['note']) : '' ?></div>
    </div>
    <span class="ss-detail-n"><?= number_format(count($okItems)) ?> <small>เครื่อง</small></span>
  </div>
  <input type="search" id="ss-filter" placeholder="ค้นหา S/N หรือรุ่น" class="ss-note" style="margin:10px 0 0">
</div>

<div class="panel">
  <?php if (!$okItems) { ?>
  <p class="muted" style="margin:0">รอบนี้ยังไม่มีเครื่องที่สแกนเจอ</p>
  <?php } else { ?>
  <p class="muted" id="ss-filter-empty" style="margin:0 0 6px;font-size:13px" hidden>ไม่พบ S/N ที่ค้นหา</p>
  <ul class="ss-items" id="ss-detail-table">
    <?php foreach ($okItems as $r) {
        $prev = (string) $r['prev_status'];
        $cur = (string) $r['cur_status'];
    ?>
    <li data-q="<?= h(strtolower($r['asset_code'] . ' ' . $r['pname'])) ?>">
      <a class="ss-i-code" href="<?= h($B . '/asset.php?id=' . (int) $r['asset_id']) ?>"><?= h((string) $r['asset_code']) ?></a>
      <span class="ss-i-time"><?= h(substr((string) $r['scanned_at'], 11, 5)) ?> · <?= h((string) $r['scanned_by']) ?></span>
      <span class="ss-i-model"><?= h((string) $r['pname']) ?></span>
      <span class="ss-i-st">
        <?php if ($prev !== '') { ?><?= status_badge($prev) ?> <span class="muted">→</span> <?php } ?><?= status_badge($cur) ?>
        <?php if ($prev === '') { ?><span class="muted" style="font-size:12px">เป็นเครื่องใหม่อยู่แล้ว</span><?php } ?>
      </span>
    </li>
    <?php } ?>
  </ul>
  <?php } ?>
</div>

<?php if ($badItems) { ?>
<div class="panel">
  <b>สแกนแล้วไม่พบในทะเบียน / นอกรอบนับ (<?= number_format(count($badItems)) ?>)</b>
  <ul class="ss-bad-list">
    <?php foreach ($badItems as $r) { ?>
    <li><b><?= h((string) $r['raw_code']) ?></b> <span class="muted"><?= $r['result'] === 'not_found' ? 'ไม่พบในทะเบียน' : 'นอกรอบนับ' ?> · <?= h(substr((string) $r['scanned_at'], 11, 5)) ?></span></li>
    <?php } ?>
  </ul>
</div>
<?php } ?>

<div class="ss-actions">
  <?php if ($detailSession['status'] === 'open') { ?>
  <a class="btn btn-primary ss-big" href="<?= h($B) ?>/stock_scan.php">กลับไปสแกนต่อ</a>
  <?php } elseif (stock_scan_can_cut($detailSession)) { ?>
  <a class="btn btn-primary ss-big" href="<?= h($B) ?>/stock_scan.php?review=<?= (int) $detailSession['id'] ?>">ดูเครื่องที่ไม่เจอ / ตัดสถานะเครื่องอื่น</a>
  <?php } ?>
  <a class="btn btn-line btn-sm" href="<?= h($B) ?>/stock_scan.php">กลับหน้าหลักนับสต็อก</a>
</div>
<script>
(function () {
  var f = document.getElementById('ss-filter');
  var t = document.getElementById('ss-detail-table');
  if (!f || !t) return;
  var empty = document.getElementById('ss-filter-empty');
  f.addEventListener('input', function () {
    var q = f.value.trim().toLowerCase();
    var shown = 0;
    t.querySelectorAll('[data-q]').forEach(function (li) {
      var hit = q === '' || li.getAttribute('data-q').indexOf(q) >= 0;
      li.hidden = !hit;
      if (hit) { shown++; }
    });
    if (empty) { empty.hidden = shown > 0; }
  });
})();
</script>

<?php } elseif ($view === 'scan') {
    $count = stock_scan_count((int) $open['id']);
    $recent = stock_scan_recent((int) $open['id'], 15);
    $progress = stock_scan_progress($open);
?>
<div class="ss-wrap">
  <section class="ss-cam">
    <div class="ss-stage" id="ss-stage">
      <div class="ss-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
    </div>
    <div class="ss-tools">
      <span class="ss-status" id="ss-status">กำลังเปิดกล้อง…</span>
    </div>
    <div class="ss-camctl">
      <button type="button" class="ss-tool" id="ss-power">ปิดกล้อง</button>
      <button type="button" class="ss-tool" id="ss-flip">สลับเป็นกล้องหน้า</button>
      <button type="button" class="ss-tool" id="ss-torch" hidden>ไฟฉาย</button>
      <button type="button" class="ss-tool" id="ss-sound">เสียง: เปิด</button>
    </div>
    <div class="ss-zoom" id="ss-zoom-wrap" hidden>
      <span>ซูม</span>
      <input type="range" id="ss-zoom" min="1" max="4" step="0.1" value="1">
    </div>
  </section>

  <div class="ss-feedback ss-idle" id="ss-feedback" aria-live="polite">
    <div class="ss-fb-main" id="ss-fb-main">พร้อมสแกน</div>
    <div class="ss-fb-sub" id="ss-fb-sub">เล็งกล้องไปที่ QR บนตัวเครื่อง</div>
  </div>

  <!-- สแกนเจอแล้วยังไม่บันทึก — รอกดยืนยัน/ยกเลิก -->
  <div class="ss-confirm" id="ss-confirm" hidden aria-live="assertive">
    <div class="ss-cf-tag" id="ss-cf-tag"></div>
    <div class="ss-cf-code" id="ss-cf-code"></div>
    <div class="ss-cf-model" id="ss-cf-model"></div>
    <div class="ss-cf-msg" id="ss-cf-msg"></div>
    <div class="ss-cf-btns">
      <button type="button" class="btn btn-line ss-cf-no" id="ss-cf-no">ยกเลิก</button>
      <button type="button" class="btn btn-primary ss-cf-yes" id="ss-cf-yes">ยืนยัน นับเครื่องนี้</button>
    </div>
  </div>

  <label class="ss-auto">
    <input type="checkbox" id="ss-auto"> บันทึกทันทีเมื่อเจอเครื่องในระบบ ไม่ต้องกดยืนยัน
  </label>

  <details class="ss-legend">
    <summary>ความหมายของเสียง</summary>
    <ul>
      <li><b>ปิ๊บ</b> (เสียงใส 1 ครั้ง) — เจอเครื่องในระบบ เป็นเครื่องใหม่อยู่แล้ว</li>
      <li><b>ปิ๊บ-ปิ๊บ</b> (สูงขึ้น) — เจอเครื่องในระบบ สถานะอื่น กดยืนยันแล้วจะเปลี่ยนเป็นเครื่องใหม่</li>
      <li><b>ตึ๊ด-ตึ๊ด</b> (เสียงกลาง) — สแกนซ้ำ เครื่องนี้นับไปแล้ว</li>
      <li><b>บี๊บ</b> (ต่ำยาว) — ไม่พบในระบบ หรือเป็นรุ่นที่ไม่ได้เลือกนับ</li>
      <li><b>ติ๊ก</b> (สั้น) — กดยืนยันแล้ว บันทึกสำเร็จ</li>
      <li><b>ตึก ตึก ตึก</b> — ส่งข้อมูลไม่ได้ ให้สแกนใหม่</li>
    </ul>
    <p class="muted">iPhone ที่เปิดโหมดเงียบ (สวิตช์ข้างเครื่อง) จะไม่มีเสียงออก — Android มีสั่นด้วย</p>
  </details>

  <div class="ss-counter">
    <div><span class="ss-count" id="ss-count"><?= number_format($count) ?></span><span class="muted"> เครื่องที่นับแล้ว</span></div>
    <form class="ss-manual" id="ss-manual" autocomplete="off">
      <input type="text" id="ss-code" placeholder="พิมพ์รหัส / ยิงบาร์โค้ด" enterkeyhint="done">
      <button type="submit" class="btn btn-line btn-sm">เพิ่ม</button>
    </form>
  </div>

  <details class="panel ss-progress">
    <summary>ความคืบหน้ารายรุ่น (<?= count($progress) ?> รุ่น)</summary>
    <table class="list" style="margin:8px 0 0">
      <tr><th>รุ่น</th><th class="ss-num">นับได้</th><th class="ss-num">ทะเบียนบอกว่าอยู่คลัง</th></tr>
      <?php foreach ($progress as $pg) { ?>
      <tr><td><?= h($pg['name']) ?></td><td class="ss-num"><b><?= number_format($pg['scanned']) ?></b></td><td class="ss-num muted"><?= number_format($pg['in_stock']) ?></td></tr>
      <?php } ?>
    </table>
    <p class="muted" style="font-size:12px;margin:6px 0 0">ตัวเลขในหน้านี้อัปเดตเมื่อรีเฟรช · ตัวนับด้านบนอัปเดตทันที</p>
  </details>

  <section class="panel ss-recent-panel">
    <div class="ss-recent-head"><b>สแกนล่าสุด</b><span class="muted" style="font-size:12px">แตะ ✕ เพื่อลบที่ยิงผิด</span></div>
    <ul class="ss-recent" id="ss-recent"></ul>
  </section>

  <p class="muted ss-live-note">
    เครื่องที่กดยืนยันถูกตั้งเป็น <b>เครื่องใหม่</b> ทันที · เครื่องอื่นยังไม่ถูกแตะ ·
    <a href="<?= h($B) ?>/stock_scan.php?session=<?= (int) $open['id'] ?>">ดูรายการที่สแกนทั้งหมด</a>
  </p>
  <div class="ss-actions">
    <form method="post" onsubmit="return confirm('จบรอบนับนี้? เก็บสถานะเครื่องที่สแกนไว้ เครื่องอื่นไม่เปลี่ยน');">
      <?= csrf_field() ?>
      <button type="submit" name="finish" value="1" class="btn btn-primary ss-big">นับครบแล้ว — จบรอบ (ไม่แตะเครื่องอื่น)</button>
    </form>
    <a class="btn btn-line btn-sm" href="<?= h($B) ?>/stock_scan.php?review=<?= (int) $open['id'] ?>">ดูเครื่องที่ไม่เจอ / ตัดสถานะเครื่องอื่น (ทำทีหลังได้)</a>
    <form method="post" onsubmit="return confirm('ยกเลิกรอบนับนี้? เครื่องที่สแกนไว้จะถูกคืนเป็นสถานะเดิม');">
      <?= csrf_field() ?>
      <button type="submit" name="cancel" value="1" class="btn btn-line btn-sm">ยกเลิกรอบนับ (คืนสถานะที่สแกนไว้)</button>
    </form>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= h($B) ?>/assets/fast-scan.js?v=<?= (int) @filemtime(__DIR__ . '/assets/fast-scan.js') ?>"></script>
<script>
(function () {
  var URL_SELF = <?= json_encode($B . '/stock_scan.php') ?>;
  var CSRF = <?= json_encode(csrf()) ?>;
  var fb = document.getElementById('ss-feedback');
  var fbMain = document.getElementById('ss-fb-main');
  var fbSub = document.getElementById('ss-fb-sub');
  var countEl = document.getElementById('ss-count');
  var recentEl = document.getElementById('ss-recent');
  var statusEl = document.getElementById('ss-status');
  var codeInput = document.getElementById('ss-code');
  var cf = document.getElementById('ss-confirm');
  var cfTag = document.getElementById('ss-cf-tag');
  var cfCode = document.getElementById('ss-cf-code');
  var cfModel = document.getElementById('ss-cf-model');
  var cfMsg = document.getElementById('ss-cf-msg');
  var cfYes = document.getElementById('ss-cf-yes');
  var cfNo = document.getElementById('ss-cf-no');
  var autoEl = document.getElementById('ss-auto');
  var lastCode = '';
  var lastAt = 0;
  var busy = false;
  var pending = null;   // ผลตรวจที่รอกดยืนยัน
  var fbTimer = null;

  var LABEL = { ok: 'นับแล้ว', dup: 'ซ้ำ', not_found: 'ไม่พบในทะเบียน', out_of_scope: 'นอกรอบนับ' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function store(k, v) { try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) {} return null; }
  function vibrate(p) { if (navigator.vibrate) { try { navigator.vibrate(p); } catch (e) {} } }

  // ── เสียง ─────────────────────────────────────────────────────────────
  // มือถือไม่ยอมให้หน้าเว็บส่งเสียงจนกว่าจะแตะหน้าจอสักครั้ง และเสียงที่สร้างใหม่ตอนกล้องอ่านได้
  // (ไม่ได้มาจากการแตะ) จะถูกปิดเงียบ — เดิมสร้างใหม่ทุกครั้งเลยไม่มีเสียง
  // ตอนนี้ใช้ตัวเดียวทั้งหน้า และปลุกให้ทำงานตั้งแต่แตะครั้งแรก
  var audioCtx = null;
  var unlockedAt = 0;
  var soundOn = store('ssSound') !== '0';

  function unlockAudio() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var wasOn = audioCtx && audioCtx.state === 'running';
      if (!audioCtx) { audioCtx = new Ctx(); }
      if (!wasOn) { unlockedAt = Date.now(); }   // แตะครั้งนี้คือครั้งที่ปลุกเสียง
      if (audioCtx.state === 'suspended') { audioCtx.resume().then(function () { if (window.ssRenderButtons) { window.ssRenderButtons(); } }); }
      else if (window.ssRenderButtons) { window.ssRenderButtons(); }
    } catch (e) {}
  }
  ['pointerdown', 'touchend', 'click', 'keydown'].forEach(function (ev) {
    document.addEventListener(ev, unlockAudio, true);
  });

  // [ความถี่, วินาที, ชนิดคลื่น] เล่นต่อกัน
  var SOUND = {
    ok:      [[1047, 0.12]],
    changed: [[1047, 0.09], [1397, 0.14]],
    dup:     [[659, 0.08], [659, 0.08]],
    bad:     [[196, 0.45, 'square']],
    saved:   [[1568, 0.05]],
    error:   [[330, 0.07, 'square'], [330, 0.07, 'square'], [330, 0.07, 'square']]
  };
  function tone(kind) {
    if (!soundOn) return;
    unlockAudio();
    if (!audioCtx) return;
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

  function show(kind, main, sub) {
    fb.className = 'ss-feedback ss-' + kind;
    fbMain.textContent = main;
    fbSub.textContent = sub || '';
    clearTimeout(fbTimer);
    fbTimer = setTimeout(function () {
      fb.className = 'ss-feedback ss-idle';
      fbMain.textContent = 'พร้อมสแกน';
      fbSub.textContent = 'เล็งกล้องไปที่ QR บนตัวเครื่อง';
    }, 2600);
  }

  function renderRecent(list) {
    recentEl.innerHTML = (list || []).map(function (r) {
      var code = r.asset_code || r.raw_code;
      return '<li class="ss-r-' + esc(r.result) + '">'
        + '<span class="ss-r-code">' + esc(code) + '</span>'
        + '<span class="ss-r-meta">' + esc(r.pname || LABEL[r.result] || '') + ' · ' + esc(String(r.scanned_at).slice(11, 16)) + '</span>'
        + '<button type="button" class="ss-r-del" data-id="' + esc(r.id) + '" aria-label="ลบ">✕</button>'
        + '</li>';
    }).join('') || '<li class="muted" style="padding:8px 0">ยังไม่มีรายการ</li>';
  }

  function post(data) {
    var body = new URLSearchParams();
    body.append('csrf', CSRF);
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(URL_SELF, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); });
  }

  // ── 1) กล้องอ่านได้ → ตรวจก่อน ยังไม่บันทึก ──────────────────────────────
  function onScan(code) {
    code = String(code || '').trim();
    if (!code || busy || pending) return;
    var now = Date.now();
    // กล้องอ่าน QR เดิมซ้ำหลายเฟรมต่อวินาที — กันอ่านซ้ำภายใน 2.5 วินาที
    if (code === lastCode && now - lastAt < 2500) return;
    lastCode = code; lastAt = now;
    busy = true;
    post({ ajax: 'check', code: code }).then(function (d) {
      busy = false;
      if (!d || !d.ok) {
        show('err', 'ตรวจรหัสไม่ได้', (d && d.message) || 'ลองใหม่อีกครั้ง');
        tone('error');
        return;
      }
      if (d.result === 'ok') {
        tone(d.will_change ? 'changed' : 'ok');
        vibrate(60);
        if (autoEl.checked) { save(d.code); } else { openConfirm(d); }
      } else if (d.result === 'dup') {
        tone('dup');
        vibrate([40, 60, 40]);
        show('dup', '↺ ' + d.code, d.message);
      } else {
        tone('bad');
        vibrate([120, 60, 120]);
        openConfirm(d);
      }
    }).catch(function () {
      busy = false;
      lastCode = '';
      tone('error');
      show('err', 'ส่งข้อมูลไม่ได้', 'เช็คอินเทอร์เน็ต แล้วสแกนใหม่');
    });
  }

  // ── 2) การ์ดยืนยัน ────────────────────────────────────────────────────
  function openConfirm(d) {
    pending = d;
    clearTimeout(fbTimer);
    var found = d.result === 'ok';
    cf.className = 'ss-confirm ' + (found ? (d.will_change ? 'ss-cf-change' : 'ss-cf-ok') : 'ss-cf-bad');
    cfTag.textContent = found ? 'เจอเครื่องในระบบ' : (d.result === 'out_of_scope' ? 'ไม่ได้อยู่ในรอบนับนี้' : 'ไม่พบในระบบ');
    cfCode.textContent = d.code;
    cfModel.textContent = d.model ? d.model + (d.status_th ? ' · ตอนนี้: ' + d.status_th : '') : '';
    cfMsg.textContent = d.message + (d.lease ? ' · อยู่ในระบบเช่า (' + d.lease + ')' : '');
    cfYes.textContent = found ? 'ยืนยัน นับเครื่องนี้' : 'บันทึกไว้ตามทีหลัง';
    cf.hidden = false;
    fb.className = 'ss-feedback ss-idle';
    fbMain.textContent = 'รอยืนยัน';
    fbSub.textContent = 'กดยืนยันหรือยกเลิกด้านล่าง';
  }

  function closeConfirm() {
    cf.hidden = true;
    // กันกล้องอ่านเครื่องเดิมที่ยังเล็งอยู่ซ้ำทันทีหลังปิดการ์ด
    if (pending) { lastCode = pending.code; lastAt = Date.now(); }
    pending = null;
  }

  cfYes.addEventListener('click', function () {
    if (!pending) return;
    var code = pending.code;
    closeConfirm();
    save(code);
  });
  cfNo.addEventListener('click', function () {
    if (!pending) return;
    var code = pending.code;
    closeConfirm();
    show('dup', 'ยกเลิก ' + code, 'ไม่ได้บันทึก — สแกนเครื่องถัดไปได้เลย');
  });

  // ── 3) บันทึกจริง ─────────────────────────────────────────────────────
  function save(code) {
    busy = true;
    post({ ajax: 'scan', code: code }).then(function (d) {
      busy = false;
      if (!d || !d.ok) {
        show('err', 'บันทึกไม่ได้', (d && d.message) || 'ลองใหม่อีกครั้ง');
        tone('error');
        return;
      }
      countEl.textContent = Number(d.count).toLocaleString('en-US');
      renderRecent(d.recent);
      if (d.result === 'ok') {
        var note = d.model;
        if (d.changed_from) { note += ' · ' + d.message; }
        if (d.lease) { note += ' · อยู่ในระบบเช่า (' + d.lease + ')'; }
        show('ok', '✓ ' + d.code, note);
        if (!autoEl.checked) { tone('saved'); }
      } else if (d.result === 'dup') {
        show('dup', '↺ ' + d.code, d.message);
      } else {
        show('err', 'บันทึกไว้ ' + d.code, d.message + ' — ตามต่อได้ในรายการที่สแกน');
        tone('saved');
      }
    }).catch(function () {
      busy = false;
      tone('error');
      show('err', 'ส่งข้อมูลไม่ได้', 'เช็คอินเทอร์เน็ต แล้วสแกนใหม่');
    });
  }

  autoEl.checked = store('ssAuto') === '1';
  autoEl.addEventListener('change', function () { store('ssAuto', autoEl.checked ? '1' : '0'); });

  document.getElementById('ss-manual').addEventListener('submit', function (e) {
    e.preventDefault();
    if (pending) return;
    lastCode = '';
    onScan(codeInput.value);
    codeInput.value = '';
  });

  recentEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.ss-r-del');
    if (!btn) return;
    if (!confirm('ลบรายการนี้ออกจากรอบนับ?')) return;
    post({ ajax: 'remove', id: btn.getAttribute('data-id') }).then(function (d) {
      if (!d || !d.ok) return;
      countEl.textContent = Number(d.count).toLocaleString('en-US');
      renderRecent(d.recent);
    });
  });

  renderRecent(<?= json_encode($recent, JSON_UNESCAPED_UNICODE) ?>);

  // ── กล้อง: เปิด/ปิด · สลับหน้า-หลัง · ไฟฉาย · ซูม ─────────────────────────
  var stage = document.getElementById('ss-stage');
  var btnPower = document.getElementById('ss-power');
  var btnFlip = document.getElementById('ss-flip');
  var btnTorch = document.getElementById('ss-torch');
  var btnSound = document.getElementById('ss-sound');
  var zoomWrap = document.getElementById('ss-zoom-wrap');
  var zoomEl = document.getElementById('ss-zoom');
  var torch = false;
  var ctl = null;
  var camOn = true;
  var starting = false;
  var facing = store('ssFacing') === 'user' ? 'user' : 'environment';

  function renderButtons() {
    btnPower.textContent = camOn ? 'ปิดกล้อง' : 'เปิดกล้อง';
    btnPower.classList.toggle('on', !camOn);
    btnFlip.textContent = facing === 'user' ? 'สลับเป็นกล้องหลัง' : 'สลับเป็นกล้องหน้า';
    btnFlip.disabled = !camOn || starting;
    // มือถือยังไม่ยอมให้มีเสียงจนกว่าจะแตะหน้าจอครั้งแรก — บอกไว้ จะได้ไม่คิดว่าเสียงเสีย
    var ready = audioCtx && audioCtx.state === 'running';
    btnSound.textContent = !soundOn ? 'เสียง: ปิด' : (ready ? 'เสียง: เปิด' : 'แตะเพื่อเปิดเสียง');
    btnSound.classList.toggle('on', !soundOn);
    stage.classList.toggle('ss-off', !camOn);
  }

  function stopCam() {
    var c = ctl;
    ctl = null;
    torch = false;
    btnTorch.hidden = true;
    btnTorch.classList.remove('on');
    zoomWrap.hidden = true;
    return c ? c.stop() : Promise.resolve();
  }

  function startCam() {
    if (starting || ctl) return Promise.resolve();
    starting = true;
    renderButtons();
    return FastScan.start({
      stage: stage,
      facing: facing,
      onCode: onScan,
      onStatus: function (t) { statusEl.textContent = t; }
    }).then(function (c) {
      starting = false;
      if (!camOn) { c.stop(); renderButtons(); return; }   // กดปิดระหว่างกำลังเปิด
      ctl = c;
      // บอกกล้องที่ใช้และตัวอ่าน — ถ้ายังอ่านยาก จะได้รู้ว่าติดที่อะไร
      statusEl.textContent = (facing === 'user' ? 'กล้องหน้า' : 'กล้องหลัง') + ' · '
        + (c.engine === 'native' ? 'โหมดเร็ว (ตัวอ่านของมือถือ)' : 'โหมดมาตรฐาน');
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
      statusEl.textContent = 'เปิดกล้องไม่ได้ (' + (e && e.message ? e.message : e) + ') — พิมพ์รหัสในช่องด้านล่างแทนได้';
      renderButtons();
    });
  }

  btnPower.addEventListener('click', function () {
    camOn = !camOn;
    renderButtons();
    if (camOn) {
      startCam();
    } else {
      stopCam().then(function () { statusEl.textContent = 'ปิดกล้องแล้ว — กด "เปิดกล้อง" เพื่อสแกนต่อ หรือพิมพ์รหัสด้านล่าง'; });
    }
  });

  btnFlip.addEventListener('click', function () {
    if (!camOn || starting) return;
    facing = facing === 'user' ? 'environment' : 'user';
    store('ssFacing', facing);
    statusEl.textContent = 'กำลังสลับกล้อง…';
    stopCam().then(startCam);
  });

  btnSound.addEventListener('click', function () {
    // ยังไม่เคยแตะหน้าจอ = กดเพื่อเปิดเสียง ไม่ใช่กดปิด
    if (soundOn && Date.now() - unlockedAt < 1500) {
      tone('ok');
      return;
    }
    soundOn = !soundOn;
    store('ssSound', soundOn ? '1' : '0');
    renderButtons();
    if (soundOn) { tone('ok'); }
  });

  btnTorch.addEventListener('click', function () {
    if (!ctl) return;
    torch = !torch;
    ctl.setTorch(torch).then(function () {
      btnTorch.classList.toggle('on', torch);
    }).catch(function () { btnTorch.hidden = true; });
  });
  zoomEl.addEventListener('input', function () {
    if (ctl) { ctl.setZoom(zoomEl.value).catch(function () {}); }
  });

  // สลับไปแอปอื่น/ล็อกจอ → ปิดกล้องไว้ กลับมาแล้วเปิดให้เอง (ถ้าไม่ได้กดปิดไว้)
  document.addEventListener('visibilitychange', function () {
    if (!camOn) return;
    if (document.hidden) { stopCam(); } else { startCam(); }
  });

  window.ssRenderButtons = renderButtons;
  renderButtons();
  startCam();
})();
</script>

<?php } else {
    $plan = stock_scan_plan((int) $reviewSession['id'], false);
    $count = stock_scan_count((int) $reviewSession['id']);
    $risky = ['unknown_sold', 'unknown_rental', 'scan_conflict'];
?>
<div class="panel">
  <p style="margin:0 0 6px">นับได้ <b><?= number_format($count) ?></b> เครื่อง · ตรวจทั้งหมด <b><?= number_format((int) $plan['total']) ?></b> เครื่องในขอบเขตรอบนี้</p>
  <p class="muted" style="margin:0;font-size:13px">
    ติ๊กเฉพาะกลุ่มที่ต้องการเปลี่ยน · กลุ่มสีเหลืองคือกลุ่มที่ควรดูให้แน่ใจก่อน ·
    ทุกการเปลี่ยนย้อนกลับได้จากหน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ
  </p>
  <p class="muted" style="margin:6px 0 0;font-size:13px">
    เครื่องที่สแกนเจอใน<b>รอบอื่นภายใน 30 วัน</b>ถือว่าอยู่ในคลังด้วย — แบ่งนับหลายรอบได้ ไม่ถูกตัดทิ้ง
  </p>
</div>
<?php if ($count === 0) { ?>
<div class="panel" style="border-left:4px solid #dc2626">
  <b>รอบนี้ยังไม่ได้สแกนเครื่องไหนเลย</b>
  <p class="muted" style="margin:4px 0 0;font-size:13px">ตัดสถานะไม่ได้ — ถ้าตัด ทุกเครื่องในขอบเขตจะถูกมองว่าไม่อยู่ในคลัง</p>
</div>
<?php } ?>

<form method="post" onsubmit="return confirm('ตัดสถานะเครื่องที่ไม่เจอตามกลุ่มที่ติ๊กไว้?');">
  <?= csrf_field() ?>
  <input type="hidden" name="session_id" value="<?= (int) $reviewSession['id'] ?>">
  <div class="ss-groups">
    <?php foreach ($plan['groups'] as $g) {
        if ((int) $g['change'] === 0) { continue; }
        $isRisky = in_array($g['key'], $risky, true);
        $froms = [];
        foreach ($g['from'] as $st => $n) { $froms[] = status_th((string) $st) . ' ' . number_format($n); }
    ?>
    <label class="ss-group<?= $isRisky ? ' ss-risky' : '' ?>">
      <input type="hidden" name="all_keys[]" value="<?= h($g['key']) ?>">
      <input type="checkbox" name="apply_keys[]" value="<?= h($g['key']) ?>" checked>
      <span class="ss-g-body">
        <span class="ss-g-head">
          <b><?= number_format((int) $g['change']) ?></b> เครื่อง →
          <span class="badge" style="<?= h(status_badge_style((string) $g['to'])) ?>"><?= h(status_th((string) $g['to'])) ?></span>
        </span>
        <span class="ss-g-reason"><?= h($g['reason']) ?></span>
        <span class="muted ss-g-meta">จาก <?= h(implode(' · ', $froms)) ?><?= $g['sample'] ? ' · เช่น ' . h(implode(', ', $g['sample'])) : '' ?></span>
      </span>
    </label>
    <?php } ?>
  </div>

  <?php
  $unchanged = 0;
  foreach ($plan['groups'] as $g) { $unchanged += (int) $g['n'] - (int) $g['change']; }
  ?>
  <p class="muted" style="font-size:12px;margin:10px 0">สถานะเดิมถูกต้องอยู่แล้ว ไม่ต้องเปลี่ยน <?= number_format($unchanged) ?> เครื่อง</p>

  <?php if ($plan['not_found']) { ?>
  <details class="panel" style="margin-bottom:12px">
    <summary>สแกนแล้วไม่พบในทะเบียน / อยู่นอกรอบนับ (<?= number_format(count($plan['not_found'])) ?>)</summary>
    <p class="muted" style="font-size:13px;margin:8px 0 0"><?= h(implode(', ', array_slice($plan['not_found'], 0, 200))) ?></p>
  </details>
  <?php } ?>

  <div class="ss-actions">
    <button type="submit" name="apply" value="1" class="btn btn-primary ss-big">ตัดสถานะตามที่ติ๊ก</button>
    <a class="btn btn-line btn-sm" href="<?= h($B) ?>/stock_scan.php"><?= $open ? 'กลับไปสแกนต่อ' : 'กลับ (ยังไม่ตัดสถานะ)' ?></a>
  </div>
</form>
<?php } ?>

<style>
.ss-steps { margin: 8px 0 0; padding-left: 20px; font-size: 14px; line-height: 1.75; }
.ss-intro summary { cursor: pointer; }
.ss-all { display: flex; align-items: center; gap: 8px; font-size: 15px; margin-bottom: 10px; }
.ss-products { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr)); gap: 6px; margin-bottom: 12px; }
.ss-products.ss-disabled { opacity: .4; pointer-events: none; }
.ss-prod { display: flex; align-items: center; gap: 8px; padding: 9px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #fff); }
.ss-prod-name { flex: 1; min-width: 0; }
.ss-prod-n { font-size: 12px; white-space: nowrap; }
.ss-note { width: 100%; margin-bottom: 12px; }
.ss-big { width: 100%; padding: 13px 16px !important; font-size: 16px !important; text-align: center; justify-content: center; }

.ss-wrap { display: flex; flex-direction: column; gap: 12px; max-width: 560px; margin: 0 auto; }
.ss-cam { background: #0b0a10; border-radius: 14px; overflow: hidden; }
.ss-stage { position: relative; aspect-ratio: 1 / 1; max-width: 100%; }
.ss-stage .fs-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.ss-stage .fs-reader { width: 100%; height: 100%; }
.ss-stage .fs-reader video { width: 100% !important; height: 100% !important; object-fit: cover; }
.ss-frame { z-index: 2; }
.ss-tool { background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.3); border-radius: 8px; padding: 6px 12px; font: inherit; font-size: 13px; }
.ss-tool.on { background: #fde68a; color: #422006; }
.ss-tool:disabled { opacity: .45; }
.ss-camctl { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 6px; padding: 0 10px 10px; }
.ss-camctl .ss-tool { padding: 10px 8px; font-size: 14px; }
.ss-camctl [hidden] { display: none !important; }
.ss-stage.ss-off::after { content: 'กล้องปิดอยู่'; position: absolute; inset: 0; display: grid; place-items: center; color: #a39fb3; font-size: 15px; background: #0b0a10; z-index: 3; }
.ss-stage .fs-mirror, .ss-stage .fs-mirror video { transform: scaleX(-1); }
.ss-confirm { position: fixed; left: 0; right: 0; bottom: 0; z-index: 90; margin: 0 auto; max-width: 560px; background: var(--card, #fff); border-top: 6px solid #16a34a; border-radius: 16px 16px 0 0; box-shadow: 0 -8px 30px rgba(0,0,0,.25); padding: 14px 16px calc(14px + env(safe-area-inset-bottom)); text-align: center; }
.ss-confirm[hidden] { display: none !important; }
.ss-confirm.ss-cf-change { border-top-color: #2563eb; }
.ss-confirm.ss-cf-bad { border-top-color: #dc2626; }
.ss-cf-tag { display: inline-block; font-size: 13px; font-weight: 700; padding: 2px 10px; border-radius: 999px; background: #dcfce7; color: #166534; }
.ss-cf-change .ss-cf-tag { background: #dbeafe; color: #1e40af; }
.ss-cf-bad .ss-cf-tag { background: #fee2e2; color: #991b1b; }
.ss-cf-code { font-size: 26px; font-weight: 800; margin: 6px 0 2px; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
.ss-cf-model { font-size: 14px; color: var(--text-muted, #6b6480); }
.ss-cf-msg { font-size: 15px; margin: 6px 0 12px; }
.ss-cf-btns { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; }
.ss-cf-btns .btn { min-height: 54px; font-size: 17px; justify-content: center; }
.ss-auto { display: flex; align-items: center; gap: 8px; font-size: 14px; margin: 10px 2px 0; }
.ss-legend { font-size: 13px; margin: 8px 2px 0; }
.ss-legend summary { cursor: pointer; color: var(--text-muted, #6b6480); }
.ss-legend ul { margin: 6px 0 4px; padding-left: 18px; line-height: 1.7; }
.ss-legend p { margin: 0; font-size: 12px; }
.ss-zoom { display: flex; align-items: center; gap: 10px; padding: 0 12px 10px; color: #d6d2e4; font-size: 13px; }
.ss-zoom input { flex: 1; }
.ss-zoom[hidden] { display: none !important; }
.ss-frame { position: absolute; inset: 15%; pointer-events: none; }
.ss-frame i { position: absolute; width: 26px; height: 26px; border: 3px solid #fff; }
.ss-frame i:nth-child(1) { top: 0; left: 0; border-right: 0; border-bottom: 0; }
.ss-frame i:nth-child(2) { top: 0; right: 0; border-left: 0; border-bottom: 0; }
.ss-frame i:nth-child(3) { bottom: 0; left: 0; border-right: 0; border-top: 0; }
.ss-frame i:nth-child(4) { bottom: 0; right: 0; border-left: 0; border-top: 0; }
.ss-tools { display: flex; align-items: center; gap: 8px; padding: 8px 10px; color: #d6d2e4; font-size: 13px; }
.ss-tools .ss-status { flex: 1; }
.ss-tools [hidden] { display: none !important; }

.ss-feedback { border-radius: 12px; padding: 12px 14px; text-align: center; transition: background .15s; }
.ss-fb-main { font-size: 20px; font-weight: 700; line-height: 1.3; word-break: break-all; }
.ss-fb-sub { font-size: 13px; opacity: .85; }
.ss-idle { background: var(--surface-soft, #f3f0f9); color: var(--text-muted, #6b6480); }
.ss-ok { background: #dcfce7; color: #14532d; }
.ss-dup { background: #fef3c7; color: #78350f; }
.ss-err { background: #fee2e2; color: #7f1d1d; }

.ss-counter { display: flex; flex-direction: column; gap: 8px; }
.ss-count { font-size: 40px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
.ss-manual { display: flex; gap: 6px; }
.ss-manual input { flex: 1; min-width: 0; font-size: 16px; }

.ss-progress summary { cursor: pointer; font-weight: 600; }
.ss-num { text-align: right; white-space: nowrap; }
.ss-recent-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 6px; }
.ss-recent { list-style: none; margin: 0; padding: 0; }
.ss-recent li { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0 8px; padding: 8px 0; border-top: 1px solid var(--border); align-items: center; }
.ss-r-code { font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.ss-r-meta { grid-column: 1; font-size: 12px; color: var(--text-muted, #6b6480); }
.ss-r-del { grid-column: 2; grid-row: 1 / span 2; background: none; border: 1px solid var(--border); border-radius: 8px; width: 38px; height: 38px; font-size: 15px; color: var(--text-muted, #6b6480); cursor: pointer; }
.ss-recent li.ss-r-not_found .ss-r-code,
.ss-recent li.ss-r-out_of_scope .ss-r-code { color: #b91c1c; }
.ss-actions { display: flex; flex-direction: column; gap: 8px; align-items: stretch; margin-top: 4px; }
.ss-actions form { margin: 0; }
.ss-actions form .ss-big, .ss-actions form .btn-sm { width: 100%; }
.ss-live-note { font-size: 13px; text-align: center; margin: 0; }
.ss-done { border-color: #86efac; }
.ss-done-head { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; }
.ss-done-mark { width: 34px; height: 34px; border-radius: 99px; background: #16a34a; color: #fff; display: grid; place-items: center; font-weight: 700; flex-shrink: 0; }
.ss-done-stats { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
.ss-done-stats > div { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.ss-done-n { font-size: 24px; font-weight: 800; font-variant-numeric: tabular-nums; min-width: 2.5ch; }
.ss-done-more { margin: 6px 0; font-size: 14px; }
.ss-done-more summary { cursor: pointer; }
.ss-done-more ul { margin: 6px 0 0; padding-left: 18px; columns: 2; font-size: 13px; }
.ss-next { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border); }
.ss-next p { font-size: 13px; margin: 4px 0 10px; }
.ss-history { list-style: none; margin: 8px 0 0; padding: 0; }
.ss-history li a { display: grid; grid-template-columns: auto minmax(0,1fr) auto; gap: 2px 10px; padding: 10px 0; border-top: 1px solid var(--border); color: inherit; text-decoration: none; align-items: baseline; }
.ss-history .muted { font-size: 12px; }
.ss-h-n { font-weight: 600; white-space: nowrap; }
.ss-h-st { grid-column: 2 / -1; font-size: 12px; }
.ss-h-open { color: #1d4ed8; }
.ss-h-cancelled { color: #b91c1c; }
.ss-h-applied { color: #15803d; }
.ss-detail-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.ss-detail-n { font-size: 28px; font-weight: 800; white-space: nowrap; }
.ss-detail-n small { font-size: 13px; font-weight: 400; }
.ss-bad-list { margin: 8px 0 0; padding-left: 18px; font-size: 14px; }
.ss-items { list-style: none; margin: 0; padding: 0; }
.ss-items li { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 8px; padding: 10px 0; border-top: 1px solid var(--border); }
.ss-items li:first-child { border-top: 0; }
.ss-items li[hidden] { display: none !important; }
.ss-i-code { font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.ss-i-time { font-size: 12px; color: var(--text-muted, #6b6480); white-space: nowrap; }
.ss-i-model { grid-column: 1 / -1; font-size: 13px; color: var(--text-muted, #6b6480); }
.ss-i-st { grid-column: 1 / -1; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
#ss-filter-empty[hidden] { display: none !important; }

.ss-groups { display: flex; flex-direction: column; gap: 8px; }
.ss-group { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface, #fff); cursor: pointer; }
.ss-group input[type="checkbox"] { width: 20px; height: 20px; margin-top: 2px; flex-shrink: 0; }
.ss-group.ss-risky { border-color: #fbbf24; background: #fffbeb; }
.ss-g-body { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.ss-g-head { font-size: 15px; }
.ss-g-reason { font-size: 14px; }
.ss-g-meta { font-size: 12px; overflow-wrap: anywhere; }
</style>

<?php page_footer();
