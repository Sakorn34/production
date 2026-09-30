<?php
/**
 * rent_idle_check.php — เครื่องค้างในคลังเช่า (30 ก.ย. 2569)
 *
 * เครื่องที่ทีมเช่าลงทะเบียนไว้ในทะเบียนเช่าเป็น "คลังพร้อมเช่า" (finished goods)
 * แต่ไม่เคยมีสัญญาเช่าเลยสักฉบับ — ปกติคือเครื่องพร้อมส่งที่ยังไม่ได้ปล่อย
 * แต่ถ้าค้างนานผิดปกติ หรือมีหลักฐานว่าถูกเบิกขายไปแล้ว แปลว่าแถวในทะเบียนเช่าเป็นของค้าง
 *
 * อ่านอย่างเดียวทั้งหน้า ไม่เขียนอะไรกลับไปทั้งฝั่งเช่าและฝั่งเรา
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/stockparts_withdraw.php';
require_once __DIR__ . '/includes/rent_ma_bridge.php';
require_once __DIR__ . '/includes/asset_status_sync.php';
require_login();

$B = BASE_URL;

// ─── คำสั่งจากหน้า (ทั้งสองอย่างเขียนเฉพาะฐานของเรา ไม่แตะฝั่งเช่า) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_can('assets');
    $act = (string) ($_POST['act'] ?? '');
    $back = $B . '/rent_idle_check.php' . (isset($_POST['f']) && $_POST['f'] !== '' ? '?f=' . rawurlencode((string) $_POST['f']) : '');

    if ($act === 'register') {
        // ลงทะเบียนเครื่องจากคลังเช่าเข้าทะเบียนของเรา — จับคู่รุ่นจากชื่อฝั่งเช่า
        $sns = array_values(array_filter(array_map('trim', (array) ($_POST['sn'] ?? []))));
        $ok = 0; $fail = 0; $msgs = [];
        foreach (array_slice($sns, 0, 150) as $sn) {
            $q = rent_q_try('SELECT pro_name FROM tbl_product WHERE pro_sn = ? LIMIT 1', 's', [$sn]);
            $row = ($q['ok'] && !empty($q['result'])) ? $q['result']->fetch_assoc() : null;
            $pid = $row ? rent_product_id_from_lease_name((string) $row['pro_name']) : 0;
            if ($pid <= 0) {
                $fail++;
                $msgs[] = $sn . ': จับคู่รุ่น "' . (string) ($row['pro_name'] ?? '?') . '" ไม่ได้';
                continue;
            }
            $res = rent_register_single_asset($pid, $sn, 'ลงทะเบียนจากคลังเช่า (ยังไม่เคยปล่อยเช่า)', ['finished goods']);
            if (!empty($res['ok'])) { $ok++; } else { $fail++; $msgs[] = (string) $res['message']; }
        }
        // ให้ตัวซิงก์ตัดสินสถานะทันที เครื่องที่มีใบเบิกขายจะได้เป็น "ขายแล้ว" เลย
        if ($ok > 0) {
            $newRows = [];
            foreach (array_chunk($sns, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $rs = qr("SELECT id, asset_code, factory_serial, status FROM assets WHERE asset_code IN ($ph)",
                    str_repeat('s', count($chunk)), $chunk);
                while ($x = $rs->fetch_assoc()) { $newRows[] = $x; }
            }
            if ($newRows) {
                try { asset_status_sync_batch($newRows, true); } catch (Throwable $e) { error_log('[rent_idle_check] ' . $e->getMessage()); }
            }
        }
        flash_set('ลงทะเบียนสำเร็จ ' . number_format($ok) . ' เครื่อง'
            . ($fail > 0 ? ' · ไม่สำเร็จ ' . number_format($fail) . ' — ' . h(implode(' / ', array_slice($msgs, 0, 3))) : ''),
            $fail > 0 ? 'err' : 'ok');
        header('Location: ' . $back);
        exit;
    }

    if ($act === 'resync') {
        // สั่งตัวซิงก์ตัดสินสถานะใหม่ เฉพาะเครื่องในรายการนี้ที่มีในทะเบียนเราแล้ว
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['asset_id'] ?? []))));
        $rows = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rs = qr("SELECT id, asset_code, factory_serial, status FROM assets WHERE id IN ($ph)",
                str_repeat('i', count($chunk)), $chunk);
            while ($x = $rs->fetch_assoc()) { $rows[] = $x; }
        }
        $changed = 0;
        if ($rows) {
            try {
                $res = asset_status_sync_batch($rows, true);
                $changed = (int) ($res['changed'] ?? 0);
            } catch (Throwable $e) {
                flash_set('ซิงก์สถานะไม่สำเร็จ — ' . $e->getMessage(), 'err');
                header('Location: ' . $back);
                exit;
            }
        }
        flash_set($changed > 0
            ? 'ตรวจสถานะ ' . number_format(count($rows)) . ' เครื่อง — เปลี่ยนให้ ' . number_format($changed) . ' เครื่อง'
            : 'ตรวจสถานะ ' . number_format(count($rows)) . ' เครื่องแล้ว — ทุกเครื่องตรงอยู่แล้ว');
        header('Location: ' . $back);
        exit;
    }
}

$flt = isset($_GET['f']) ? (string) $_GET['f'] : '';
$rows = [];
$err = '';

$l = dbLeasing();
if (!$l) {
    $err = 'เชื่อมต่อระบบเช่าไม่ได้ — ' . dbLeasingError();
} else {
    // S/N ที่เคยมีสัญญาเช่าจริง (เคยปล่อยเช่าแล้ว ไม่ใช่ของค้าง)
    $hasContract = [];
    $r = $l->query('SELECT DISTINCT p_sn FROM tbl_rent_product');
    while ($r && ($x = $r->fetch_assoc())) {
        $sn = rent_normalize_sn($x['p_sn'] ?? '');
        if ($sn !== '') {
            $hasContract[$sn] = true;
        }
    }
    // เครื่องในคลังเช่าที่ยังไม่เคยปล่อยเช่า
    $cand = [];
    $r = $l->query("SELECT pro_sn, pro_date, pro_user_add, pro_name FROM tbl_product
                    WHERE pro_status = 'finished goods' ORDER BY pro_date ASC");
    while ($r && ($x = $r->fetch_assoc())) {
        $sn = rent_normalize_sn($x['pro_sn'] ?? '');
        if ($sn === '' || isset($hasContract[$sn])) {
            continue;
        }
        $cand[$sn] = ['sn' => $sn, 'date' => (string) $x['pro_date'], 'by' => trim((string) $x['pro_user_add']),
                      'lease_model' => trim((string) ($x['pro_name'] ?? ''))];
    }

    if ($cand) {
        // จับคู่กับทะเบียนเครื่องของเรา
        $codes = array_keys($cand);
        foreach (array_chunk($codes, 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $res = qr("SELECT a.id, a.asset_code, a.status, a.produced_at, p.name pname
                       FROM assets a JOIN products p ON p.id = a.product_id
                       WHERE a.asset_code IN ($ph)", str_repeat('s', count($chunk)), $chunk);
            while ($a = $res->fetch_assoc()) {
                $sn = rent_normalize_sn($a['asset_code']);
                if (isset($cand[$sn])) {
                    $cand[$sn]['asset'] = $a;
                }
            }
        }
        $saleMap = asset_stockparts_sale_status_by_sn($codes);
        $today = new DateTime('today');
        foreach ($cand as $sn => $c) {
            $sale = $saleMap[$sn] ?? null;
            $days = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($c['date'], 0, 10)) && substr($c['date'], 0, 10) !== '0000-00-00') {
                $d = date_create(substr($c['date'], 0, 10));
                if ($d) {
                    $days = (int) $today->diff($d)->format('%a');
                }
            }
            $rows[] = [
                'sn' => $sn,
                'date' => $c['date'],
                'days' => $days,
                'by' => $c['by'],
                'asset' => $c['asset'] ?? null,
                'lease_model' => (string) ($c['lease_model'] ?? ''),
                'pid' => empty($c['asset']) ? rent_product_id_from_lease_name((string) ($c['lease_model'] ?? '')) : 0,
                'sold' => $sale && !empty($sale['sold']),
                'sold_hard' => $sale && !empty($sale['sold']) && empty($sale['from_delivery']),
                'sale_ref' => trim((string) ($sale['setup_id'] ?? '')),
            ];
        }
    }
}

// เรียงค้างนานสุดก่อน
usort($rows, function ($a, $b) {
    return ($b['days'] ?? -1) <=> ($a['days'] ?? -1);
});

const RENT_IDLE_LONG = 180;   // ค้างเกินครึ่งปีถือว่านานผิดปกติ
$nSold = 0; $nLong = 0; $nNoAsset = 0;
foreach ($rows as $x) {
    if ($x['sold']) { $nSold++; }
    if (($x['days'] ?? 0) >= RENT_IDLE_LONG) { $nLong++; }
    if (!$x['asset']) { $nNoAsset++; }
}
$show = $rows;
if ($flt === 'sold') {
    $show = array_values(array_filter($rows, function ($x) { return $x['sold']; }));
} elseif ($flt === 'long') {
    $show = array_values(array_filter($rows, function ($x) { return ($x['days'] ?? 0) >= RENT_IDLE_LONG; }));
} elseif ($flt === 'noasset') {
    $show = array_values(array_filter($rows, function ($x) { return !$x['asset']; }));
}

/** ชิปตัวกรอง — ต้องมีคลาส btn ไม่งั้นได้กล่องเหลี่ยมหลุดธีม */
function ric_chip(string $f, string $label, string $cur): string
{
    $on = $cur === $f;
    return '<a class="btn btn-sm btn-line ric-chip' . ($on ? ' is-on' : '') . '" href="?'
        . ($f !== '' ? 'f=' . rawurlencode($f) : '') . '">' . $label . '</a>';
}

page_header('เครื่องค้างในคลังเช่า', true,
    number_format(count($rows)) . ' เครื่องลงทะเบียนไว้แต่ยังไม่เคยปล่อยเช่า', $B . '/settings.php');
?>
<p class="muted ric-lead">
  เครื่องที่ทีมเช่าลงทะเบียนไว้เป็น <b>คลังพร้อมเช่า</b> แต่ยังไม่มีสัญญาเช่าสักฉบับ —
  ส่วนใหญ่คือเครื่องพร้อมส่งตามปกติ ที่ต้องดูคือตัวที่ <b>มีหลักฐานว่าถูกเบิกขายไปแล้ว</b>
  หรือ <b>ค้างนานผิดปกติ</b> ซึ่งแปลว่าแถวในทะเบียนเช่าน่าจะเป็นของค้างที่ลืมลบ
</p>

<?php if ($err !== '') { ?>
<div class="panel"><p class="muted" style="margin:0"><?= h($err) ?></p></div>
<?php } else { ?>

<div class="ric-stats">
  <div class="ric-stat"><div class="ric-num"><?= number_format(count($rows)) ?></div><div class="muted ric-lbl">ทั้งหมด</div></div>
  <div class="ric-stat"><div class="ric-num" style="color:<?= h(status_palette_entry('lost')['fg']) ?>"><?= number_format($nSold) ?></div><div class="muted ric-lbl">มีหลักฐานเบิกขาย</div></div>
  <div class="ric-stat"><div class="ric-num" style="color:<?= h(status_palette_entry('spare')['fg']) ?>"><?= number_format($nLong) ?></div><div class="muted ric-lbl">ค้างเกิน <?= RENT_IDLE_LONG ?> วัน</div></div>
  <div class="ric-stat"><div class="ric-num"><?= number_format($nNoAsset) ?></div><div class="muted ric-lbl">ไม่มีในทะเบียนเรา</div></div>
</div>

<div class="ric-chips">
  <?= ric_chip('', 'ทั้งหมด (' . number_format(count($rows)) . ')', $flt) ?>
  <?= ric_chip('sold', 'มีหลักฐานเบิกขาย (' . number_format($nSold) . ')', $flt) ?>
  <?= ric_chip('long', 'ค้างเกิน ' . RENT_IDLE_LONG . ' วัน (' . number_format($nLong) . ')', $flt) ?>
  <?= ric_chip('noasset', 'ไม่มีในทะเบียนเรา (' . number_format($nNoAsset) . ')', $flt) ?>
</div>

<?php // ชื่อรุ่นฝั่งเช่าที่ยังไม่ได้ผูกกับรุ่นของเรา — บอกไว้ให้ไปตั้งที่หลังบ้านก่อน
$noMap = [];
foreach ($rows as $x) { if (!$x['asset'] && ($x['pid'] ?? 0) <= 0 && $x['lease_model'] !== '') { $noMap[$x['lease_model']] = ($noMap[$x['lease_model']] ?? 0) + 1; } }
arsort($noMap); ?>
<?php if ($noMap) { ?>
<p class="muted ric-nomap-note">ชื่อรุ่นฝั่งเช่าที่ยังไม่ได้ผูกกับรุ่นของเรา จึงลงทะเบียนอัตโนมัติไม่ได้:
  <?php $i = 0; foreach ($noMap as $nm => $c) { echo $i++ ? ' · ' : ' '; ?><b><?= h($nm) ?></b> (<?= (int) $c ?>)<?php } ?>
  — ตั้งชื่อรุ่นคู่กันได้ที่ <a href="<?= $B ?>/settings.php">ตั้งค่าระบบ → รุ่นสินค้า</a> แล้วกลับมาหน้านี้ใหม่</p>
<?php } ?>

<?php $canAct = function_exists('can') && can('assets');
      $pageAssetIds = [];
      $pageRegSns = [];
      foreach ($show as $x) {
          if ($x['asset']) { $pageAssetIds[] = (int) $x['asset']['id']; }
          elseif (($x['pid'] ?? 0) > 0) { $pageRegSns[] = (string) $x['sn']; }
      } ?>
<?php if ($canAct) { ?>
<div class="ric-actbar">
  <span class="ric-actbar-lbl">คำสั่งกับรายการที่เห็นอยู่ (<?= number_format(count($show)) ?>)</span>

  <?php if ($pageAssetIds) { ?>
  <form method="post" class="ric-inline" onsubmit="return confirm('ตรวจและตั้งสถานะใหม่ให้ <?= count($pageAssetIds) ?> เครื่องที่มีในทะเบียนเรา?\n\nระบบจะตัดสินจากหลักฐานล่าสุดทั้งฝั่งเช่าและฝั่งขาย')">
    <?= csrf_field() ?><input type="hidden" name="act" value="resync"><input type="hidden" name="f" value="<?= h($flt) ?>">
    <?php foreach ($pageAssetIds as $aid) { ?><input type="hidden" name="asset_id[]" value="<?= (int) $aid ?>"><?php } ?>
    <button type="submit" class="btn btn-sm btn-line btn-with-icon"><?= ui_btn_label('refresh', 'เคลียร์สถานะ ' . number_format(count($pageAssetIds)) . ' เครื่อง') ?></button>
  </form>
  <?php } ?>

  <?php if ($pageRegSns) { ?>
  <form method="post" class="ric-inline" id="ric-reg-form"
        onsubmit="return confirm('ลงทะเบียนเครื่องที่เลือกเข้าทะเบียนของเรา?\n\nระบบจะสร้างเครื่องใหม่ตามรุ่นที่จับคู่ได้ วันผลิตใช้วันที่ลงคลังเช่า แล้วตัดสินสถานะให้เอง')">
    <?= csrf_field() ?><input type="hidden" name="act" value="register"><input type="hidden" name="f" value="<?= h($flt) ?>">
    <div id="ric-reg-hidden"></div>
    <button type="submit" class="btn btn-sm btn-with-icon" id="ric-reg-btn" disabled><?= ui_btn_label('plus', 'ลงทะเบียนที่เลือก') ?> <span id="ric-reg-n"></span></button>
  </form>
  <button type="button" class="btn btn-sm btn-line" id="ric-pick-all">เลือกทุกตัวที่ยังไม่ลงทะเบียน (<?= number_format(count($pageRegSns)) ?>)</button>
  <?php } ?>

  <button type="button" class="btn btn-sm btn-line btn-with-icon" id="ric-copy"><?= ui_btn_label('copy', 'คัดลอกรายการส่งทีมเช่า') ?></button>
</div>
<?php } ?>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css) ?>
  <thead>
  <tr>
    <?php if ($canAct) { ?><th data-pri="1" style="width:34px;text-align:center"></th><?php } ?>
    <th data-pri="1">รหัสเครื่อง</th><th data-pri="2">รุ่น</th>
    <th data-pri="1">ลงคลังเช่าเมื่อ</th><th data-pri="1">ค้างมาแล้ว</th>
    <th data-pri="3">ผู้ลงทะเบียน</th><th data-pri="2">สถานะในทะเบียนเรา</th><th data-pri="1">หลักฐานเบิกขาย</th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$show) { ?>
  <tr><td colspan="8" class="muted" style="text-align:center;padding:20px">ไม่มีเครื่องที่ตรงกับเงื่อนไข</td></tr>
  <?php } ?>
  <?php foreach ($show as $x) { $long = ($x['days'] ?? 0) >= RENT_IDLE_LONG; ?>
  <tr<?= $x['sold'] ? ' class="ric-row-warn"' : '' ?>>
    <?php if ($canAct) { ?>
    <td data-pri="1" style="text-align:center">
      <?php if (!$x['asset'] && ($x['pid'] ?? 0) > 0) { ?>
      <input type="checkbox" class="ric-pick" value="<?= h($x['sn']) ?>" aria-label="เลือก <?= h($x['sn']) ?>">
      <?php } ?>
    </td>
    <?php } ?>
    <td data-pri="1">
      <?php if ($x['asset']) { ?>
      <a href="<?= $B ?>/asset.php?id=<?= (int) $x['asset']['id'] ?>"><b><?= h($x['sn']) ?></b></a>
      <?php } else { ?><b><?= h($x['sn']) ?></b><?php } ?>
      <span class="cell-sub"><?= h($x['asset']['pname'] ?? 'ไม่มีในทะเบียนเรา') ?> · <?= h(dthai($x['date'])) ?></span>
    </td>
    <td data-pri="2"><?php if ($x['asset']) { echo h($x['asset']['pname']); }
        elseif (($x['pid'] ?? 0) > 0) { ?><span class="muted"><?= h($x['lease_model']) ?></span><span class="ric-flag is-ok">ลงทะเบียนได้</span><?php }
        else { ?><span class="muted"><?= h($x['lease_model'] ?: '—') ?></span><span class="ric-flag is-nomap">ยังไม่ได้ผูกรุ่น</span><?php } ?></td>
    <td data-pri="1" data-nowrap><?= h(dthai($x['date'])) ?></td>
    <td data-pri="1" data-nowrap><?= $x['days'] === null ? '<span class="muted">—</span>'
        : ('<span class="' . ($long ? 'ric-long' : '') . '">' . number_format($x['days']) . ' วัน</span>') ?></td>
    <td data-pri="3"><?= h($x['by'] !== '' ? $x['by'] : '—') ?></td>
    <td data-pri="2"><?= $x['asset'] ? status_badge($x['asset']['status']) : '<span class="muted">—</span>' ?></td>
    <td data-pri="1">
      <?php if ($x['sold_hard']) { ?>
        <span class="ric-flag is-hard">มีใบเบิกขาย</span>
        <?php if ($x['sale_ref'] !== '') { ?><div class="cell-sub muted"><?= h($x['sale_ref']) ?></div><?php } ?>
      <?php } elseif ($x['sold']) { ?>
        <span class="ric-flag is-soft">มีประวัติส่งมอบ</span>
        <?php if ($x['sale_ref'] !== '') { ?><div class="cell-sub muted"><?= h($x['sale_ref']) ?></div><?php } ?>
      <?php } else { ?><span class="muted">—</span><?php } ?>
    </td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<p class="muted ric-foot">
  <b>มีใบเบิกขาย</b> = หลักฐานหนัก ระบบจะตัดสินสถานะเครื่องเป็น “ขายแล้ว” ให้เอง —
  แถวในทะเบียนเช่าควรให้ทีมเช่าลบออก ·
  <b>มีประวัติส่งมอบ</b> = หลักฐานอ่อน (บันทึกไซต์งาน ไม่มีใบเบิก) ระบบไม่แตะสถานะให้ ต้องตรวจเอง ·
  หน้านี้อ่านอย่างเดียว ไม่แก้ข้อมูลฝั่งไหนทั้งนั้น
</p>
<?php } ?>

<script>
(function () {
  var picks = function () { return [].slice.call(document.querySelectorAll('.ric-pick:checked')); };
  var btn = document.getElementById('ric-reg-btn');
  var nEl = document.getElementById('ric-reg-n');
  var hidden = document.getElementById('ric-reg-hidden');
  function refresh() {
    var n = picks().length;
    if (btn) { btn.disabled = n === 0; }
    if (nEl) { nEl.textContent = n ? '(' + n + ')' : ''; }
    if (hidden) {
      hidden.innerHTML = '';
      picks().forEach(function (cb) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'sn[]'; i.value = cb.value;
        hidden.appendChild(i);
      });
    }
  }
  document.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('ric-pick')) { refresh(); }
  });
  var all = document.getElementById('ric-pick-all');
  if (all) {
    all.addEventListener('click', function () {
      var boxes = [].slice.call(document.querySelectorAll('.ric-pick'));
      var on = picks().length < boxes.length;
      boxes.forEach(function (cb) { cb.checked = on; });
      refresh();
    });
  }
  // คัดลอกรายการไปส่งทีมเช่าให้ลบแถวค้างออกเอง — ระบบเราไม่ลบข้อมูลของเขา
  var copy = document.getElementById('ric-copy');
  if (copy) {
    copy.addEventListener('click', function () {
      var lines = [].slice.call(document.querySelectorAll('tbody tr')).map(function (tr) {
        var td = tr.querySelectorAll('td');
        if (td.length < 4) { return ''; }
        var sn = (tr.querySelector('td b') || {}).textContent || '';
        var flag = (tr.querySelector('.ric-flag.is-hard') || tr.querySelector('.ric-flag.is-soft') || {}).textContent || '';
        var days = (tr.querySelector('.ric-long') || td[td.length - 4] || {}).textContent || '';
        return [sn.trim(), days.trim(), flag.trim()].filter(Boolean).join(' · ');
      }).filter(Boolean);
      if (!lines.length) { return; }
      var txt = 'เครื่องที่ค้างในทะเบียนเช่า (ลงไว้แต่ไม่เคยปล่อยเช่า) — รบกวนตรวจและลบแถวที่ไม่ใช้แล้ว\n\n' + lines.join('\n');
      var done = function () {
        var old = copy.textContent;
        copy.textContent = 'คัดลอกแล้ว ' + lines.length + ' รายการ';
        setTimeout(function () { copy.textContent = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done).catch(function () { window.prompt('คัดลอกข้อความนี้', txt); });
      } else { window.prompt('คัดลอกข้อความนี้', txt); }
    });
  }
  refresh();
})();
</script>
<style>
.ric-actbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 10px;
  padding: 10px 14px; background: var(--surface-2, #faf9fd); border: 1px solid var(--border, #dde3ec);
  border-left: 3px solid var(--primary); border-radius: 8px; }
.ric-actbar-lbl { font-size: calc(12.5px * var(--font-scale, 1)); color: var(--text-muted, #6b6480); font-weight: 600; }
.ric-inline { display: inline-flex; margin: 0; }
.ric-pick { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
.ric-flag.is-ok { background: var(--success-soft, #dcfce7); color: var(--success, #16a34a); margin-left: 6px; }
.ric-flag.is-nomap { background: var(--surface-2, #f1efe8); color: var(--text-muted, #5f5e5a); margin-left: 6px; }
.ric-nomap-note { margin: 0 0 10px; font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.8; }
.ric-lead { margin-bottom: 14px; line-height: 1.85; }
.ric-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 720px) { .ric-stats { grid-template-columns: repeat(2, 1fr); } }
.ric-stat { background: var(--surface, #fff); border: 1px solid var(--border, #dde3ec); border-radius: 10px; padding: 12px 8px; text-align: center; }
.ric-num { font-size: calc(22px * var(--font-scale, 1)); font-weight: 700; color: var(--primary); line-height: 1.2; }
.ric-lbl { font-size: calc(11px * var(--font-scale, 1)); margin-top: 2px; }
.ric-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }
.ric-chip.is-on { background: var(--primary-soft, #fce7f3); color: var(--primary); font-weight: 600;
  box-shadow: inset 0 0 0 1.5px var(--primary); }
.ric-chip.is-on:hover { background: var(--primary-soft, #fce7f3); }
.ric-flag { font-size: calc(11.5px * var(--font-scale, 1)); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
.ric-flag.is-hard { background: var(--danger-soft, #fee2e2); color: var(--danger, #b91c1c); }
.ric-flag.is-soft { background: var(--warning-soft, #fef9c3); color: var(--warning, #a16207); }
.ric-long { color: var(--warning, #a16207); font-weight: 600; }
tr.ric-row-warn td { background: var(--surface-2, #faf9fd); }
.ric-foot { margin-top: 12px; font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.8; }
</style>
<?php page_footer(); ?>
