<?php
/**
 * unknown_assets.php — ติดตามเครื่อง "ไม่มีสถานะ" (หน้าหลังบ้าน)
 *
 * เครื่องที่ตัดสถานะจากการนับสต็อกด้วยการสแกนแล้วไม่เจอ และไม่มีหลักฐานว่าขาย/เช่า/สำรอง/
 * เสื่อมสภาพ/สูญหาย จะค้างเป็น unknown — หน้านี้ใช้ไล่ตามว่าหายไปไหน แล้วตั้งสถานะทีละหลายเครื่อง
 *
 * ช่วยตัดสินใจด้วยหลักฐานล่าสุดจากระบบเช่าและใบเบิกขาย (เช็คสดเฉพาะแถวในหน้าที่แสดง)
 * ทุกการเปลี่ยนบันทึกลง stock_movements รูปแบบ "เหตุผล (unknown → X)" จึงย้อนกลับได้จากหน้าเคลียร์เครื่องค้าง
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/asset_status_sync.php';
require_login();

$B = BASE_URL;
const UNKNOWN_FOLLOW_TAG = 'ติดตามเครื่องไม่มีสถานะ';

$pid = (int) ($_GET['product'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$backUrl = $B . '/unknown_assets.php?' . http_build_query(array_filter(['product' => $pid ?: null, 'q' => $q !== '' ? $q : null, 'page' => (int) ($_GET['page'] ?? 0) ?: null]));

/**
 * สถานะแนะนำจากหลักฐานล่าสุด (ระบบเช่า / ใบเบิกขาย)
 *
 * @param array<int,array<string,mixed>> $rows แถว assets (id, asset_code, factory_serial)
 * @return array<int,array{target:string, reason:string}> asset id => คำแนะนำ
 */
function unknown_assets_suggest(array $rows)
{
    $out = [];
    if (!$rows) {
        return $out;
    }
    $codes = [];
    foreach ($rows as $r) {
        if (trim((string) $r['asset_code']) !== '') {
            $codes[] = trim((string) $r['asset_code']);
        }
    }
    $saleMap = $codes ? asset_stockparts_sale_status_by_sn($codes) : [];
    $leaseMap = asset_leasing_status_by_assets($rows);
    $installMap = installation_history_map($rows);
    foreach ($rows as $r) {
        $code = trim((string) $r['asset_code']);
        $key = strtoupper($code);
        $sale = $saleMap[$code] ?? null;
        $lease = $leaseMap[$key] ?? null;
        $t = asset_status_target_from_external('unknown', $sale, $lease);
        if (!empty($t['target']) && $t['target'] !== 'unknown') {
            $out[(int) $r['id']] = ['target' => (string) $t['target'], 'reason' => (string) $t['reason']];
        } elseif (empty($lease['found']) && empty($sale['sold'])
            && ($ih = installation_history_status_hint($r, $installMap[$key] ?? null))) {
            $out[(int) $r['id']] = ['target' => 'sold', 'reason' => $ih['reason']];
        } elseif ($sale && !empty($sale['sold'])) {
            // มีแต่ประวัติส่งมอบ (ไม่มีใบเบิก) — ตัวตัดสินกลางไม่ใช้กับเครื่องที่ไม่ใช่ "ใหม่" แต่ยังเป็นเบาะแสที่ดี
            $out[(int) $r['id']] = ['target' => 'sold', 'reason' => 'ส่งมอบให้ลูกค้าแล้ว (ไม่มีใบเบิก)'];
        }
    }
    return $out;
}

// ── ตั้งสถานะ ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    $note = trim((string) ($_POST['note'] ?? ''));
    if (!$ids) {
        flash_set('ยังไม่ได้เลือกเครื่อง', 'err');
        header('Location: ' . $backUrl); exit;
    }

    // ดึงเฉพาะที่ยังเป็น unknown อยู่ — มีคนเปลี่ยนไปแล้วระหว่างนั้นไม่ทับ
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = [];
    $res = qr("SELECT id, asset_code, factory_serial, produced_at FROM assets WHERE status = 'unknown' AND id IN ($ph)", str_repeat('i', count($ids)), $ids);
    while ($r = $res->fetch_assoc()) { $rows[(int) $r['id']] = $r; }

    $plan = [];   // id => [target, reason]
    if (isset($_POST['apply_suggest'])) {
        foreach (unknown_assets_suggest(array_values($rows)) as $id => $s) {
            $plan[$id] = [$s['target'], 'ตามหลักฐาน: ' . $s['reason']];
        }
    } else {
        $to = (string) ($_POST['to'] ?? '');
        if (!in_array($to, status_list(), true) || $to === 'unknown') {
            flash_set('เลือกสถานะที่จะตั้งก่อน', 'err');
            header('Location: ' . $backUrl); exit;
        }
        foreach ($rows as $id => $r) {
            $plan[$id] = [$to, 'ตั้งเอง'];
        }
    }

    $done = 0;
    $actor = actor_name() ?: 'system';
    foreach ($plan as $id => $p) {
        list($to, $why) = $p;
        // นับจาก statement เอง — affected_rows ของ connection ไม่สะท้อน prepared statement
        $upd = q("UPDATE assets SET status = ? WHERE id = ? AND status = 'unknown'", 'si', [$to, $id]);
        if ($upd->affected_rows < 1) { continue; }
        q(
            'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by, remark) VALUES (?, NOW(), ?, ?, ?, ?)',
            'issss',
            [$id, $to === 'new' ? 'in' : 'out', UNKNOWN_FOLLOW_TAG . ': ' . $why . ' (unknown → ' . $to . ')', $actor, $note !== '' ? mb_substr($note, 0, 255) : null]
        );
        $done++;
    }
    $skipped = count($ids) - $done;
    flash_set('ตั้งสถานะแล้ว ' . number_format($done) . ' เครื่อง'
        . ($skipped > 0 ? ' · ข้าม ' . number_format($skipped) . ' เครื่อง (' . (isset($_POST['apply_suggest']) ? 'ไม่มีหลักฐานให้ตั้ง หรือ' : '') . 'ไม่ได้เป็น "ไม่มีสถานะ" แล้ว)' : '')
        . ' — ย้อนกลับได้ที่หน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ', $done > 0 ? 'ok' : 'err');
    header('Location: ' . $backUrl); exit;
}

// ── ข้อมูล ───────────────────────────────────────────────────────────────
$models = [];
$res = qr("SELECT p.id, p.name, COUNT(*) n FROM assets a JOIN products p ON p.id = a.product_id
           WHERE a.status = 'unknown' GROUP BY p.id, p.name ORDER BY n DESC, p.name");
$totalUnknown = 0;
while ($r = $res->fetch_assoc()) { $models[] = $r; $totalUnknown += (int) $r['n']; }

$where = ["a.status = 'unknown'"];
$types = '';
$params = [];
if ($pid > 0) { $where[] = 'a.product_id = ?'; $types .= 'i'; $params[] = $pid; }
if ($q !== '') { $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)'; $types .= 'ss'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
$w = implode(' AND ', $where);

$per = 50;
$totalRows = (int) qr("SELECT COUNT(*) FROM assets a WHERE $w", $types, $params)->fetch_row()[0];
$pages = max(1, (int) ceil($totalRows / $per));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$off = ($page - 1) * $per;

$rows = [];
$res = qr(
    "SELECT a.id, a.asset_code, a.factory_serial, a.produced_at, p.name AS pname,
            (SELECT CONCAT(m.moved_at, '|', m.reason) FROM stock_movements m
             WHERE m.asset_id = a.id AND m.reason LIKE '%→ unknown)' ORDER BY m.id DESC LIMIT 1) AS became
     FROM assets a JOIN products p ON p.id = a.product_id
     WHERE $w
     ORDER BY p.name, a.produced_at DESC, a.id DESC
     LIMIT $per OFFSET $off",
    $types,
    $params
);
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$suggest = unknown_assets_suggest($rows);

page_header('ติดตามเครื่องไม่มีสถานะ', true, 'เครื่องที่นับสต็อกแล้วไม่เจอ และไม่มีหลักฐานว่าไปไหน — ตั้งสถานะให้ถูกทีละหลายเครื่อง', $B . '/settings.php');
?>
<div class="panel ua-models">
  <div class="ua-total"><b><?= number_format($totalUnknown) ?></b> <span class="muted">เครื่องที่ยังไม่มีสถานะ</span></div>
  <?php if ($models) { ?>
  <div class="ua-chips">
    <a class="ua-chip<?= $pid === 0 ? ' on' : '' ?>" href="<?= h($B) ?>/unknown_assets.php">ทุกรุ่น <b><?= number_format($totalUnknown) ?></b></a>
    <?php foreach ($models as $m) { ?>
    <a class="ua-chip<?= $pid === (int) $m['id'] ? ' on' : '' ?>" href="<?= h($B) ?>/unknown_assets.php?product=<?= (int) $m['id'] ?>"><?= h($m['name']) ?> <b><?= number_format((int) $m['n']) ?></b></a>
    <?php } ?>
  </div>
  <?php } ?>
</div>

<?php if ($totalUnknown === 0) { ?>
<div class="panel"><p class="muted" style="margin:0">ไม่มีเครื่องที่ค้างสถานะ "ไม่มีสถานะ" แล้ว</p></div>
<?php } else { ?>
<form method="get" class="panel ua-search">
  <?php if ($pid > 0) { ?><input type="hidden" name="product" value="<?= $pid ?>"><?php } ?>
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="ค้นหา S/N">
  <button type="submit" class="btn btn-line btn-sm">ค้นหา</button>
</form>

<form method="post" id="ua-form">
  <?= csrf_field() ?>
  <div class="panel ua-bar">
    <label class="ua-all"><input type="checkbox" id="ua-all"> เลือกทั้งหน้า</label>
    <span class="muted" id="ua-count">เลือก 0 เครื่อง</span>
    <span class="ua-sep"></span>
    <button type="submit" name="apply_suggest" value="1" class="btn btn-primary btn-sm"
      onclick="return uaConfirm('ตั้งสถานะตามหลักฐานที่พบ ให้เครื่องที่เลือก? (เครื่องที่ไม่มีหลักฐานจะถูกข้าม)');">ตั้งตามหลักฐาน</button>
    <select name="to" aria-label="สถานะที่จะตั้ง">
      <option value="">— ตั้งเป็น —</option>
      <?php foreach (status_list() as $st) { if ($st === 'unknown') { continue; } ?>
      <option value="<?= h($st) ?>"><?= h(status_th($st)) ?></option>
      <?php } ?>
    </select>
    <input type="text" name="note" maxlength="255" placeholder="หมายเหตุ (ถ้ามี) เช่น เจอที่ไซต์ลูกค้า">
    <button type="submit" name="apply_to" value="1" class="btn btn-line btn-sm"
      onclick="return uaConfirm('ตั้งสถานะที่เลือกให้เครื่องที่เลือกทั้งหมด?', true);">ตั้งสถานะ</button>
  </div>

  <div class="table-wrap table-wrap-fold">
  <table class="list">
    <thead>
      <tr>
        <th data-pri="1" style="width:34px"></th>
        <th data-pri="1">S/N</th>
        <th data-pri="3">รุ่น</th>
        <th data-pri="3">ผลิตเมื่อ</th>
        <th data-pri="2">เป็นไม่มีสถานะเมื่อ</th>
        <th data-pri="1">หลักฐานล่าสุด</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows) { ?>
      <tr><td data-pri="1" colspan="6" class="muted">ไม่พบเครื่องตามที่ค้นหา</td></tr>
    <?php } ?>
    <?php foreach ($rows as $r) {
        $became = (string) $r['became'];
        $bAt = $became !== '' ? substr($became, 0, 16) : '';
        $bWhy = $became !== '' ? (string) substr($became, strpos($became, '|') + 1) : '';
        $sg = $suggest[(int) $r['id']] ?? null;
    ?>
      <tr>
        <td data-pri="1"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="ua-pick" aria-label="เลือก <?= h((string) $r['asset_code']) ?>"></td>
        <td data-pri="1">
          <a href="<?= h($B . '/asset.php?id=' . (int) $r['id']) ?>" target="_blank" rel="noopener"><b><?= h((string) $r['asset_code']) ?></b></a>
          <div class="cell-sub"><?= h((string) $r['pname']) ?> · ผลิต <?= h(dthai(substr((string) $r['produced_at'], 0, 10))) ?></div>
        </td>
        <td data-pri="3"><?= h((string) $r['pname']) ?></td>
        <td data-pri="3" data-nowrap><?= h(dthai(substr((string) $r['produced_at'], 0, 10))) ?></td>
        <td data-pri="2"><?= $bAt !== '' ? h(dthai($bAt)) . '<div class="muted" style="font-size:12px">' . h(preg_replace('/\s*\([a-z_]+\s*→\s*[a-z_]+\)\s*$/u', '', $bWhy)) . '</div>' : '<span class="muted">—</span>' ?></td>
        <td data-pri="1">
          <?php if ($sg) { ?>
          แนะนำ <?= status_badge($sg['target']) ?>
          <div class="muted" style="font-size:12px"><?= h($sg['reason']) ?></div>
          <?php } else { ?>
          <span class="muted">ไม่พบหลักฐานในระบบเช่าและใบเบิก</span>
          <?php } ?>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
  </div>
</form>

<?php
echo page_pager_html($page, $pages, $per, $totalRows, function ($n) {
    $qs = $_GET;
    $qs['page'] = $n;
    return '?' . http_build_query($qs);
}, 'เครื่อง');
} ?>

<style>
.ua-models { display: flex; flex-direction: column; gap: 10px; }
.ua-total b { font-size: 26px; }
.ua-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.ua-chip { display: inline-flex; gap: 6px; align-items: center; padding: 5px 12px; border: 1px solid var(--border); border-radius: 999px; font-size: 13px; color: inherit; text-decoration: none; background: var(--surface, #fff); }
.ua-chip b { font-variant-numeric: tabular-nums; }
.ua-chip.on { border-color: var(--primary); color: var(--primary); background: var(--surface-soft); }
.ua-search { display: flex; gap: 8px; margin-bottom: 12px; }
.ua-search input[type="search"] { flex: 1; min-width: 0; }
.ua-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; position: sticky; top: 0; z-index: 5; }
.ua-bar input[type="text"] { flex: 1 1 200px; min-width: 0; }
.ua-sep { flex: 1; }
.ua-all { display: inline-flex; gap: 6px; align-items: center; font-size: 14px; }
</style>
<script>
(function () {
  var all = document.getElementById('ua-all');
  var count = document.getElementById('ua-count');
  if (!all) return;
  var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.ua-pick')); };
  function render() {
    var n = picks().filter(function (c) { return c.checked; }).length;
    count.textContent = 'เลือก ' + n + ' เครื่อง';
    all.checked = n > 0 && n === picks().length;
  }
  all.addEventListener('change', function () { picks().forEach(function (c) { c.checked = all.checked; }); render(); });
  document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('ua-pick')) { render(); } });
  window.uaConfirm = function (msg, needStatus) {
    var n = picks().filter(function (c) { return c.checked; }).length;
    if (!n) { alert('เลือกเครื่องก่อน'); return false; }
    if (needStatus && !document.querySelector('#ua-form select[name="to"]').value) { alert('เลือกสถานะที่จะตั้งก่อน'); return false; }
    return confirm(msg + '\n\nจำนวน ' + n + ' เครื่อง');
  };
})();
</script>
<?php page_footer();
