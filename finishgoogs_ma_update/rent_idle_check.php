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
require_login();

$B = BASE_URL;
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
    $r = $l->query("SELECT pro_sn, pro_date, pro_user_add FROM tbl_product
                    WHERE pro_status = 'finished goods' ORDER BY pro_date ASC");
    while ($r && ($x = $r->fetch_assoc())) {
        $sn = rent_normalize_sn($x['pro_sn'] ?? '');
        if ($sn === '' || isset($hasContract[$sn])) {
            continue;
        }
        $cand[$sn] = ['sn' => $sn, 'date' => (string) $x['pro_date'], 'by' => trim((string) $x['pro_user_add'])];
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

<div class="table-wrap table-wrap-fold">
<table class="list">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css) ?>
  <thead>
  <tr>
    <th data-pri="1">รหัสเครื่อง</th><th data-pri="2">รุ่น</th>
    <th data-pri="1">ลงคลังเช่าเมื่อ</th><th data-pri="1">ค้างมาแล้ว</th>
    <th data-pri="3">ผู้ลงทะเบียน</th><th data-pri="2">สถานะในทะเบียนเรา</th><th data-pri="1">หลักฐานเบิกขาย</th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$show) { ?>
  <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">ไม่มีเครื่องที่ตรงกับเงื่อนไข</td></tr>
  <?php } ?>
  <?php foreach ($show as $x) { $long = ($x['days'] ?? 0) >= RENT_IDLE_LONG; ?>
  <tr<?= $x['sold'] ? ' class="ric-row-warn"' : '' ?>>
    <td data-pri="1">
      <?php if ($x['asset']) { ?>
      <a href="<?= $B ?>/asset.php?id=<?= (int) $x['asset']['id'] ?>"><b><?= h($x['sn']) ?></b></a>
      <?php } else { ?><b><?= h($x['sn']) ?></b><?php } ?>
      <span class="cell-sub"><?= h($x['asset']['pname'] ?? 'ไม่มีในทะเบียนเรา') ?> · <?= h(dthai($x['date'])) ?></span>
    </td>
    <td data-pri="2"><?= h($x['asset']['pname'] ?? '—') ?></td>
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

<style>
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
