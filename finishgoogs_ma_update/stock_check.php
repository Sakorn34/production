<?php
/**
 * stock_check.php — เคลียร์เครื่องที่ค้างสถานะ "เครื่องใหม่" + นับสต็อกของเราเอง
 *
 * 3 แท็บ:
 *   หลักฐาน  — S/N ที่ระบบ Setup บันทึกว่าเบิกออกไปแล้ว ปิดได้ทันที
 *   ค้างเก่า — เครื่องที่ยังใหม่แต่เก่ากว่าวันนับสต็อก ให้คนเลือกปิดเอง
 *   นับสต็อก — ติ๊กเครื่องที่เจอจริงในคลัง เครื่องที่ไม่เจอจะไปโผล่ในแท็บค้างเก่า
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/stock_check.php';
require_login();
ensure_stock_check_schema();

$B = BASE_URL;
$tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'evidence';
if (!in_array($tab, ['evidence', 'stale', 'count', 'undo'], true)) {
    $tab = 'evidence';
}
$pid = (int) (isset($_GET['product']) ? $_GET['product'] : 0);
$q = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
$before = trim((string) (isset($_GET['before']) ? $_GET['before'] : ''));
$backUrl = $B . '/stock_check.php?tab=' . urlencode($tab)
    . ($pid > 0 ? '&product=' . $pid : '')
    . ($q !== '' ? '&q=' . urlencode($q) : '')
    . ($before !== '' ? '&before=' . urlencode($before) : '');

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['apply_status'])) {
        $ids = (array) (isset($_POST['asset_ids']) ? $_POST['asset_ids'] : []);
        $status = (string) (isset($_POST['new_status']) ? $_POST['new_status'] : 'sold');
        $reason = (string) (isset($_POST['reason']) ? $_POST['reason'] : '');
        if (!$ids) {
            flash_set('ยังไม่ได้เลือกเครื่อง', 'err');
        } else {
            $r = stock_check_apply_status($ids, $status, $reason);
            flash_set($r['message'], $r['ok'] ? 'ok' : 'err');
        }
        header('Location: ' . $backUrl); exit;
    }

    if (isset($_POST['undo_batch'])) {
        $r = stock_check_undo_apply(
            (int) ($_POST['min_id'] ?? 0),
            (int) ($_POST['max_id'] ?? 0),
            (string) ($_POST['reason_full'] ?? ''),
            (int) ($_POST['undo_product'] ?? 0)
        );
        flash_set($r['message'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . $B . '/stock_check.php?tab=undo'); exit;
    }

    if (isset($_POST['save_count'])) {
        $r = stock_count_save(
            (int) (isset($_POST['count_product']) ? $_POST['count_product'] : 0),
            (array) (isset($_POST['found_ids']) ? $_POST['found_ids'] : []),
            (string) (isset($_POST['count_note']) ? $_POST['count_note'] : '')
        );
        flash_set($r['message'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . $B . '/stock_check.php?tab=count&product=' . (int) ($_POST['count_product'] ?? 0)); exit;
    }
}

$evidence = $tab === 'evidence' ? stock_check_evidence_rows() : ['ok' => true, 'error' => '', 'rows' => []];
$stale = $tab === 'stale' ? stock_check_stale_rows(['product_id' => $pid, 'q' => $q, 'before' => $before]) : null;
$staleBy = $tab === 'stale' ? (isset($stale['by_product']) ? $stale['by_product'] : []) : [];

$products = [];
$res = qr("SELECT p.id, p.name, COUNT(a.id) n FROM products p
           LEFT JOIN assets a ON a.product_id = p.id AND a.status = 'new'
           WHERE p.is_active = 1 GROUP BY p.id HAVING n > 0 ORDER BY n DESC");
while ($r = $res->fetch_assoc()) { $products[] = $r; }

page_header('เคลียร์เครื่องค้างสถานะ', true, 'เครื่องที่ยังเป็น "เครื่องใหม่" ทั้งที่ออกจากคลังไปแล้ว');
?>
<div class="panel" style="margin-bottom:14px">
  <div class="sc-tabs">
    <a class="btn btn-sm <?= $tab === 'evidence' ? 'btn-primary' : 'btn-line' ?>" href="<?= h($B) ?>/stock_check.php?tab=evidence">1 · มีหลักฐานว่าเบิกออกแล้ว</a>
    <a class="btn btn-sm <?= $tab === 'stale' ? 'btn-primary' : 'btn-line' ?>" href="<?= h($B) ?>/stock_check.php?tab=stale">2 · ค้างเก่ากว่าวันนับสต็อก</a>
    <a class="btn btn-sm <?= $tab === 'count' ? 'btn-primary' : 'btn-line' ?>" href="<?= h($B) ?>/stock_check.php?tab=count">3 · นับสต็อกของเรา</a>
    <a class="btn btn-sm <?= $tab === 'undo' ? 'btn-primary' : 'btn-line' ?>" href="<?= h($B) ?>/stock_check.php?tab=undo">ประวัติ / ย้อนกลับ</a>
  </div>
</div>

<?php if ($tab === 'evidence') { ?>
<div class="panel">
  <p class="muted" style="margin:0 0 10px">
    เครื่องที่ระบบ Setup บันทึกไว้แล้วว่าเบิกออกจากคลังไป (ขาย · สั่งซื้อ · เคลม) แต่ทะเบียนเรายังขึ้นว่า "เครื่องใหม่"
    อยู่ · มีเลข PO และวันที่กำกับทุกเครื่อง ปิดได้เลยโดยไม่ต้องเดา
  </p>
  <?php if (!$evidence['ok']) { ?>
  <p style="margin:0;color:var(--danger,#dc2626)">อ่านข้อมูลจากระบบ Setup ไม่ได้: <?= h($evidence['error']) ?></p>
  <?php } elseif (!$evidence['rows']) { ?>
  <p class="muted" style="margin:0">ไม่มีเครื่องค้าง — ตรงกับระบบ Setup ทุกเครื่องแล้ว</p>
  <?php } else { ?>
  <form method="post" onsubmit="return confirm('เปลี่ยนสถานะเครื่องที่เลือกเป็น &quot;ขายแล้ว&quot;?');">
    <?= csrf_field() ?>
    <input type="hidden" name="new_status" value="sold">
    <input type="hidden" name="reason" value="เบิกออกจากคลังแล้วตามระบบ Setup">
    <div class="sc-bulk">
      <label class="sc-check"><input type="checkbox" id="sc-all-ev"> เลือกทั้งหมด (<?= number_format(count($evidence['rows'])) ?>)</label>
      <button type="submit" name="apply_status" value="1" class="btn btn-primary btn-sm">ตั้งเป็น "ขายแล้ว"</button>
    </div>
    <div class="table-wrap">
      <table class="list" style="margin:0">
        <tr><th style="width:34px"></th><th>รหัสเครื่อง</th><th>รุ่น</th><th>ประเภท</th><th>วันที่เบิกออก</th><th>PO</th><th>ลูกค้า</th></tr>
        <?php foreach ($evidence['rows'] as $r) { ?>
        <tr>
          <td><input type="checkbox" class="sc-pick-ev" name="asset_ids[]" value="<?= (int) $r['id'] ?>" <?= $r['issue_type'] === 'claim' ? '' : 'checked' ?>></td>
          <td><a href="<?= h($B . '/asset.php?id=' . (int) $r['id']) ?>"><b><?= h($r['asset_code']) ?></b></a></td>
          <td><?= h($r['pname']) ?></td>
          <td><?= h($r['issue_label']) ?></td>
          <td style="white-space:nowrap"><?= h(dthai($r['issue_date'])) ?></td>
          <td><?= h($r['issue_po'] !== '' ? $r['issue_po'] : '—') ?></td>
          <td class="muted"><?= h($r['issue_to']) ?></td>
        </tr>
        <?php } ?>
      </table>
    </div>
  </form>
  <p class="muted" style="font-size:12px;margin:10px 0 0">
    เครื่องที่เป็น "เคลม" ไม่ได้ติ๊กไว้ให้ เพราะบางทีเป็นเครื่องที่ส่งไปเปลี่ยนแล้วได้ของกลับมา — ดูก่อนแล้วค่อยติ๊กเอง
  </p>
  <?php } ?>
</div>

<?php } elseif ($tab === 'undo') {
    $batches = stock_check_undo_batches();
?>
<div class="panel">
  <p class="muted" style="margin:0 0 10px">
    ทุกครั้งที่เปลี่ยนสถานะจากหน้านี้ ระบบเก็บไว้ว่าเปลี่ยนจากอะไรเป็นอะไร จึงย้อนกลับได้ทีละรุ่น
    · ใช้ตอนเผลอปิดเครื่องที่ยังอยู่ในคลังจริง เช่น รุ่นที่ของค้างคลังมานานแต่ยังขายอยู่
  </p>
  <?php if (!$batches) { ?>
  <p class="muted" style="margin:0">ยังไม่มีประวัติการเปลี่ยนสถานะจากหน้านี้</p>
  <?php } else { ?>
  <div class="table-wrap" style="max-height:70vh;overflow:auto">
    <table class="list" style="margin:0">
      <tr><th>วันที่</th><th>รุ่น</th><th>เหตุผล</th><th>เปลี่ยน</th><th style="text-align:right">จำนวน</th><th style="width:110px"></th></tr>
      <?php foreach ($batches as $b) { ?>
      <tr>
        <td style="white-space:nowrap"><?= h(dthai((string) $b['day'])) ?></td>
        <td><b><?= h((string) $b['pname']) ?></b><div class="muted" style="font-size:12px">โดย <?= h((string) $b['made_by']) ?></div></td>
        <td class="muted"><?= h((string) $b['label']) ?></td>
        <td style="white-space:nowrap"><?= h(status_th((string) $b['from'])) ?> → <?= h(status_th((string) $b['to'])) ?></td>
        <td style="text-align:right"><?= number_format((int) $b['n']) ?>
          <?php if ((int) $b['undoable'] < (int) $b['n']) { ?>
          <div class="muted" style="font-size:12px">ย้อนได้ <?= number_format((int) $b['undoable']) ?></div>
          <?php } ?>
        </td>
        <td>
          <?php if ((int) $b['undoable'] > 0) { ?>
          <form method="post" onsubmit="return confirm('ย้อนสถานะ <?= (int) $b['undoable'] ?> เครื่องของรุ่น <?= h((string) $b['pname']) ?> กลับเป็น &quot;<?= h(status_th((string) $b['from'])) ?>&quot;?');">
            <?= csrf_field() ?>
            <input type="hidden" name="min_id" value="<?= (int) $b['min_id'] ?>">
            <input type="hidden" name="max_id" value="<?= (int) $b['max_id'] ?>">
            <input type="hidden" name="reason_full" value="<?= h((string) $b['reason']) ?>">
            <input type="hidden" name="undo_product" value="<?= (int) $b['product_id'] ?>">
            <button type="submit" name="undo_batch" value="1" class="btn btn-line btn-sm">ย้อนกลับ</button>
          </form>
          <?php } else { ?>
          <span class="muted" style="font-size:12px">ย้อนไม่ได้</span>
          <?php } ?>
        </td>
      </tr>
      <?php } ?>
    </table>
  </div>
  <?php } ?>
</div>

<?php } elseif ($tab === 'stale') { ?>
<div class="panel" style="margin-bottom:14px">
  <p class="muted" style="margin:0 0 10px">
    เครื่องที่ยังเป็น "เครื่องใหม่" แต่ผลิตก่อนวันนับสต็อกล่าสุดของรุ่นนั้น — ถ้าตอนนับไม่เจอในคลัง
    แปลว่าออกไปนานแล้ว · ระบบไม่เดาแทนว่าไปไหน ให้เลือกเองว่าจะปิดเป็นอะไร
  </p>
  <form method="get" class="filter" style="margin:0">
    <input type="hidden" name="tab" value="stale">
    <select name="product">
      <option value="0">ทุกรุ่น</option>
      <?php foreach ($staleBy as $b) { ?>
      <option value="<?= (int) $b['product_id'] ?>" <?= $pid === (int) $b['product_id'] ? 'selected' : '' ?>><?= h($b['name']) ?> (<?= number_format($b['n']) ?>)</option>
      <?php } ?>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหารหัสเครื่อง" style="max-width:200px">
    <label class="muted" style="font-size:12px">เก่ากว่าวันที่ <input type="date" name="before" value="<?= h($before) ?>"></label>
    <button class="btn btn-line btn-sm" type="submit">กรอง</button>
    <?php if ($pid || $q !== '' || $before !== '') { ?>
    <a class="btn btn-sm btn-line" href="<?= h($B) ?>/stock_check.php?tab=stale">ล้าง</a>
    <?php } ?>
  </form>
</div>

<div class="panel">
  <?php if (!$stale['ok']) { ?>
  <p style="margin:0;color:var(--danger,#dc2626)"><?= h($stale['error']) ?></p>
  <?php } elseif (!$stale['rows']) { ?>
  <p class="muted" style="margin:0">ไม่มีเครื่องค้างตามเงื่อนไขนี้</p>
  <?php } else { ?>
  <form method="post" onsubmit="return confirm('เปลี่ยนสถานะเครื่องที่เลือกทั้งหมด?');">
    <?= csrf_field() ?>
    <div class="sc-bulk">
      <label class="sc-check"><input type="checkbox" id="sc-all-st"> เลือกทั้งหมดในหน้านี้</label>
      <label class="sc-check">ปิดเป็น
        <select name="new_status">
          <option value="sold">ขายแล้ว</option>
          <option value="retired">เสื่อมสภาพ</option>
          <option value="lost">สูญหาย</option>
        </select>
      </label>
      <input type="text" name="reason" placeholder="เหตุผล (เช่น เคลียร์ยอดจากการนับสต็อก 2569)" style="flex:1;min-width:200px">
      <button type="submit" name="apply_status" value="1" class="btn btn-primary btn-sm">เปลี่ยนสถานะ</button>
    </div>
    <p class="muted" style="font-size:12px;margin:0 0 10px">
      พบ <b><?= number_format((int) $stale['total']) ?></b> เครื่อง
      <?= count($stale['rows']) < (int) $stale['total'] ? ' · แสดง ' . number_format(count($stale['rows'])) . ' เครื่องแรก (กรองรายรุ่นเพื่อดูให้ครบ)' : '' ?>
    </p>
    <div class="table-wrap" style="max-height:60vh;overflow:auto">
      <table class="list" style="margin:0">
        <tr><th style="width:34px"></th><th>รหัสเครื่อง</th><th>รุ่น</th><th>วันที่ผลิต</th><th>เส้นแบ่งที่ใช้</th></tr>
        <?php foreach ($stale['rows'] as $r) { ?>
        <tr>
          <td><input type="checkbox" class="sc-pick-st" name="asset_ids[]" value="<?= (int) $r['id'] ?>"></td>
          <td><a href="<?= h($B . '/asset.php?id=' . (int) $r['id']) ?>"><b><?= h($r['asset_code']) ?></b></a></td>
          <td><?= h($r['pname']) ?></td>
          <td style="white-space:nowrap"><?= h(dthai(substr((string) $r['produced_at'], 0, 10))) ?></td>
          <td class="muted" style="white-space:nowrap"><?= h(dthai($r['cutoff'])) ?> · <?= h($r['cutoff_source']) ?></td>
        </tr>
        <?php } ?>
      </table>
    </div>
  </form>
  <?php } ?>
</div>

<?php } else {
    $countProducts = $products;
    $countAssets = $pid > 0 ? stock_count_assets($pid) : [];
    $latest = $pid > 0 ? stock_count_latest($pid) : null;
    $missingIds = $pid > 0 ? stock_count_missing_ids($pid) : [];
?>
<div class="panel" style="margin-bottom:14px">
  <p class="muted" style="margin:0 0 10px">
    ติ๊กเครื่องที่ <b>เจอของจริงในคลัง</b> แล้วกดบันทึก · ระบบจะจำว่านับเมื่อไหร่ ใครนับ เจอกี่เครื่อง
    ส่วนเครื่องที่ไม่เจอจะยกไปให้ปิดสถานะในแท็บ "ค้างเก่า" — ไม่เปลี่ยนสถานะให้เองเพราะยังไม่รู้ว่าหายไปไหน
  </p>
  <form method="get" class="filter" style="margin:0">
    <input type="hidden" name="tab" value="count">
    <select name="product" onchange="this.form.submit()">
      <option value="0">— เลือกรุ่นที่จะนับ —</option>
      <?php foreach ($countProducts as $p) { ?>
      <option value="<?= (int) $p['id'] ?>" <?= $pid === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= number_format((int) $p['n']) ?> เครื่อง)</option>
      <?php } ?>
    </select>
    <noscript><button class="btn btn-line btn-sm" type="submit">เลือก</button></noscript>
  </form>
</div>

<?php if ($pid > 0) { ?>
<div class="panel">
  <?php if ($latest) { ?>
  <p class="muted" style="margin:0 0 10px">
    นับครั้งล่าสุด <b><?= h(dthai(substr((string) $latest['counted_at'], 0, 10))) ?></b>
    โดย <?= h((string) $latest['counted_by']) ?> · เจอ <?= number_format((int) $latest['found_qty']) ?> · ไม่เจอ <?= number_format((int) $latest['missing_qty']) ?>
    <?php if ($missingIds) { ?>
    · <a href="<?= h($B . '/stock_check.php?tab=stale&product=' . $pid) ?>">ดูเครื่องที่ยังไม่เจอ (<?= number_format(count($missingIds)) ?>)</a>
    <?php } ?>
  </p>
  <?php } ?>
  <?php if (!$countAssets) { ?>
  <p class="muted" style="margin:0">รุ่นนี้ไม่มีเครื่องสถานะ "ใหม่" ให้นับ</p>
  <?php } else { ?>
  <form method="post" onsubmit="return confirm('บันทึกผลการนับรุ่นนี้?');">
    <?= csrf_field() ?>
    <input type="hidden" name="count_product" value="<?= $pid ?>">
    <div class="sc-bulk">
      <label class="sc-check"><input type="checkbox" id="sc-all-cnt"> ติ๊กทั้งหมด (<?= number_format(count($countAssets)) ?>)</label>
      <span class="muted" style="font-size:13px">เจอแล้ว <b id="sc-found-n">0</b> เครื่อง</span>
      <input type="text" name="count_note" placeholder="หมายเหตุการนับ (ไม่บังคับ)" style="flex:1;min-width:200px">
      <button type="submit" name="save_count" value="1" class="btn btn-primary btn-sm">บันทึกผลการนับ</button>
    </div>
    <div class="table-wrap" style="max-height:60vh;overflow:auto">
      <table class="list" style="margin:0">
        <tr><th style="width:34px">เจอ</th><th>รหัสเครื่อง</th><th>S/N โรงงาน</th><th>วันที่ผลิต</th></tr>
        <?php foreach ($countAssets as $a) { ?>
        <tr>
          <td><input type="checkbox" class="sc-pick-cnt" name="found_ids[]" value="<?= (int) $a['id'] ?>"></td>
          <td><a href="<?= h($B . '/asset.php?id=' . (int) $a['id']) ?>"><b><?= h($a['asset_code']) ?></b></a></td>
          <td class="muted"><?= h((string) $a['factory_serial']) ?></td>
          <td style="white-space:nowrap"><?= h(dthai(substr((string) $a['produced_at'], 0, 10))) ?></td>
        </tr>
        <?php } ?>
      </table>
    </div>
  </form>
  <?php } ?>
</div>
<?php } ?>
<?php } ?>

<script>
(function () {
  function linkAll(allId, cls, counterId) {
    var all = document.getElementById(allId);
    if (!all) { return; }
    var boxes = document.querySelectorAll('.' + cls);
    var counter = counterId ? document.getElementById(counterId) : null;
    function refresh() {
      if (!counter) { return; }
      var n = 0;
      boxes.forEach(function (b) { if (b.checked) { n++; } });
      counter.textContent = n.toLocaleString('en-US');
    }
    all.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = all.checked; });
      refresh();
    });
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
  }
  linkAll('sc-all-ev', 'sc-pick-ev');
  linkAll('sc-all-st', 'sc-pick-st');
  linkAll('sc-all-cnt', 'sc-pick-cnt', 'sc-found-n');
})();
</script>

<style>
.sc-tabs { display:flex; gap:6px; flex-wrap:wrap; }
.sc-bulk { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 10px; }
.sc-check { display:inline-flex; align-items:center; gap:6px; font-size:13px; }
</style>

<?php page_footer();
