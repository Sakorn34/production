<?php
/**
 * rent_sale_conflict.php — เครื่องที่สองระบบขัดกัน (30 ก.ย. 2569)
 *
 * เครื่องที่ระบบเช่าบอกว่ายังอยู่ในสายงานเช่า แต่ฝั่งขายมีหลักฐานว่าออกไปแล้ว
 * (ใบเบิกขายใน stock · ใบสั่งงาน/ประวัติขายฝั่งระบบ setup · ประวัติส่งมอบ)
 *
 * ตัวซิงก์สถานะจะเชื่อระบบเช่าไว้ก่อนเสมอเมื่อสัญญายังเดินอยู่ เครื่องกลุ่มนี้
 * จึงตัดสินอัตโนมัติไม่ได้ ต้องมีคนไปตรวจว่าฝั่งไหนถูก หน้านี้รวมไว้ให้ไล่เคลียร์
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
$flt = isset($_GET['f']) ? (string) $_GET['f'] : '';

/** ระดับความขัดแย้ง — เรียงจากต้องดูก่อนไปหลัง */
const RSC_LEVELS = [
    'clash'  => ['th' => 'ขัดกันชัด',        'pal' => 'lost',   'note' => 'ระบบเช่าบอกว่าเครื่องอยู่กับลูกค้า แต่ฝั่งขายมีใบเบิกขายหรือใบสั่งงานจริง — ต้องตรวจว่าฝั่งไหนถูก'],
    'after'  => ['th' => 'อาจขายหลังรับคืน', 'pal' => 'spare',  'note' => 'เคยปล่อยเช่าแล้วรับคืนเข้าคลัง แล้วมีหลักฐานขายตามมา — ระบบยังนับเป็นเครื่องเช่าเพราะใบเบิกไม่ได้แยกว่าเบิกไปขายหรือไปเช่า'],
    'weak'   => ['th' => 'ที่อยู่ไม่ตรงกัน',  'pal' => 'new',    'note' => 'ระบบเช่าว่าเครื่องควรอยู่ในคลังหรือปลดระวางแล้ว แต่ทะเบียน stock ของระบบเดิมบันทึกว่าส่งไปติดตั้งที่ไซต์งาน — ยังไม่พอบอกว่าขาย แต่ที่อยู่ของเครื่องไม่ตรงกัน'],
];

$rows = [];
$err = '';

if (!dbLeasing()) {
    $err = 'เชื่อมต่อระบบเช่าไม่ได้ — ตรวจการตั้งค่าที่ Server / Deploy';
} else {
    // ผู้เข้าข่าย = เครื่องที่สถานะถูกตัดสินจากฝั่งระบบเช่า (เช่า · ปลดระวาง · สูญหาย)
    // เครื่องที่เป็น "ขายแล้ว" อยู่แล้วไม่ต้องดู เพราะฝั่งขายชนะไปแล้ว
    $cand = [];
    $res = db()->query(
        'SELECT a.id, a.asset_code, a.factory_serial, a.status, p.name pname
         FROM assets a JOIN products p ON p.id = a.product_id
         WHERE a.status IN ("rental", "retired", "lost")'
    );
    while ($a = $res->fetch_assoc()) {
        $cand[] = $a;
    }

    foreach (array_chunk($cand, 400) as $chunk) {
        $codes = array_column($chunk, 'asset_code');
        $saleMap  = asset_stockparts_sale_status_by_sn($codes);
        $setupMap = asset_status_setup_sold_map($codes);
        $leaseMap = asset_leasing_status_by_assets($chunk);

        foreach ($chunk as $a) {
            $code  = (string) $a['asset_code'];
            $lease = $leaseMap[$code] ?? null;
            if (!$lease || empty($lease['found'])) {
                continue;   // ไม่อยู่ในทะเบียนเช่า = ไม่มีอะไรขัดกัน
            }
            $sale  = $saleMap[$code] ?? null;
            $setup = $setupMap[strtoupper($code)] ?? null;
            $hasSale = $sale && !empty($sale['sold']);
            if (!$hasSale && !$setup) {
                continue;   // ฝั่งขายไม่มีหลักฐานอะไรเลย
            }

            // หลักฐานหนัก = ใบเบิกขายใน stock หรือใบสั่งงาน/ประวัติขายฝั่ง setup
            // หลักฐานอ่อน = ประวัติส่งมอบ (stock_old) ซึ่งเครื่องเช่าก็มีได้
            $hard = ($hasSale && empty($sale['from_delivery'])) || $setup !== null;
            $inRentFlow = asset_status_leasing_implies_rental($lease);

            // ประวัติส่งมอบของเครื่องที่ฝั่งเช่าบอกว่าอยู่กับลูกค้าอยู่แล้ว คือการส่งไปเช่านั่นเอง
            // ไม่ใช่ข้อมูลขัดกัน — ตัดออกไม่งั้นรายการเต็มไปด้วยเครื่องเช่าปกติ
            if (!$hard && $inRentFlow) {
                continue;
            }

            if (!$hard) {
                $level = 'weak';
            } elseif ($inRentFlow) {
                $level = 'clash';
            } else {
                $level = 'after';
            }

            // รายละเอียดหลักฐานฝั่งขาย — เอาตัวที่หนักที่สุดมาแสดง
            if ($setup) {
                $evLabel = ['order' => 'ใบสั่งงาน (setup)', 'claim' => 'ส่งออกไปเคลม', 'sale' => 'ขายตามระบบ setup'][(string) $setup['src']] ?? 'ส่งมอบแล้ว (setup)';
                $evRef   = trim((string) $setup['ref']);
                $evDate  = trim((string) $setup['date']);
                $evCus   = trim((string) $setup['customer']);
            } elseif ($hard) {
                $evLabel = 'ใบเบิกขาย (stock)';
                $evRef   = trim((string) ($sale['setup_id'] ?? ''));
                $evDate  = '';
                $evCus   = '';
            } else {
                // ทะเบียน stock ของระบบเดิม — ไม่มีในระบบเช่าและไม่ใช่ใบเบิกปัจจุบัน
                $evLabel = 'ส่งมอบ (ทะเบียนเก่า)';
                $evRef   = trim((string) ($sale['delivery_site'] ?? $sale['setup_id'] ?? ''));
                $evDate  = trim((string) ($sale['delivery_date'] ?? ''));
                $evCus   = trim((string) ($sale['delivery_guard'] ?? ''));
                $by      = trim((string) ($sale['delivery_by'] ?? ''));
                if ($by !== '') {
                    $evCus = trim($evCus . ' · บันทึกโดย ' . $by, ' ·');
                }
            }

            $rows[] = [
                'id'        => (int) $a['id'],
                'sn'        => $code,
                'pname'     => (string) $a['pname'],
                'status'    => (string) $a['status'],
                'level'     => $level,
                'lease_lbl' => (string) $lease['status_label'],
                'lease_cls' => (string) $lease['badge_class'],
                'lease_cus' => (string) $lease['customer_name'],
                'ev_label'  => $evLabel,
                'ev_ref'    => $evRef,
                'ev_date'   => $evDate,
                'ev_cus'    => $evCus,
            ];
        }
    }
}

// เรียงตามระดับความขัดแย้ง แล้วตามรหัสเครื่อง
$order = array_flip(array_keys(RSC_LEVELS));
usort($rows, function ($a, $b) use ($order) {
    return [$order[$a['level']], $a['sn']] <=> [$order[$b['level']], $b['sn']];
});

$nBy = ['clash' => 0, 'after' => 0, 'weak' => 0];
foreach ($rows as $x) {
    $nBy[$x['level']]++;
}
$show = $flt !== '' && isset($nBy[$flt])
    ? array_values(array_filter($rows, function ($x) use ($flt) { return $x['level'] === $flt; }))
    : $rows;

/** วันที่แบบไทย จาก Y-m-d — คืนค่าเดิมถ้าอ่านไม่ได้ */
function rsc_date(string $ymd): string
{
    $ymd = trim($ymd);
    $head = substr($ymd, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $head) || $head === '0000-00-00') {
        return $ymd;
    }
    $d = date_create($head);
    return $d ? $d->format('d/m/Y') : $ymd;
}

/** ชิปตัวกรอง — ต้องมีคลาส btn ไม่งั้นได้กล่องเหลี่ยมหลุดธีม */
function rsc_chip(string $f, string $label, string $cur): string
{
    $on = $cur === $f;
    return '<a class="btn btn-sm btn-line rsc-chip' . ($on ? ' is-on' : '') . '" href="?'
        . ($f !== '' ? 'f=' . rawurlencode($f) : '') . '">' . $label . '</a>';
}

page_header('เครื่องที่สองระบบขัดกัน', true,
    number_format(count($rows)) . ' เครื่องที่ฝั่งเช่ากับฝั่งขายบอกไม่ตรงกัน', $B . '/settings.php');
?>
<p class="muted rsc-lead">
  เครื่องที่ <b>ระบบเช่า</b> ยังถือว่าอยู่ในสายงานเช่า แต่ <b>ฝั่งขาย</b> มีหลักฐานว่าออกไปแล้ว —
  ตัวซิงก์สถานะเชื่อสัญญาเช่าที่ยังเดินอยู่ก่อนเสมอ เครื่องกลุ่มนี้จึงตัดสินให้อัตโนมัติไม่ได้
  ต้องมีคนไปตรวจว่าฝั่งไหนถูก แล้วให้ฝั่งที่ผิดแก้ข้อมูลของตัวเอง
  <span class="muted">(เครื่องที่ฝั่งเช่าว่าอยู่กับลูกค้าและมีแค่ประวัติส่งมอบ ไม่นับเป็นขัดกัน
  เพราะการส่งมอบนั้นคือการส่งไปเช่าเอง)</span>
</p>

<?php if ($err !== '') { ?>
<div class="panel"><p class="muted" style="margin:0"><?= h($err) ?></p></div>
<?php } else { ?>

<div class="rsc-stats">
  <?php foreach (RSC_LEVELS as $k => $L) { ?>
  <div class="rsc-stat">
    <div class="rsc-num" style="color:<?= h(status_palette_entry($L['pal'])['fg']) ?>"><?= number_format($nBy[$k]) ?></div>
    <div class="muted rsc-lbl"><?= h($L['th']) ?></div>
  </div>
  <?php } ?>
</div>

<div class="rsc-chips">
  <?= rsc_chip('', 'ทั้งหมด (' . number_format(count($rows)) . ')', $flt) ?>
  <?php foreach (RSC_LEVELS as $k => $L) { ?>
  <?= rsc_chip($k, $L['th'] . ' (' . number_format($nBy[$k]) . ')', $flt) ?>
  <?php } ?>
</div>

<?php if ($show) { ?>
<div class="rsc-actbar">
  <span class="rsc-actbar-lbl">รายการที่เห็นอยู่ <?= number_format(count($show)) ?> เครื่อง</span>
  <button type="button" class="btn btn-sm btn-line btn-with-icon" id="rsc-copy"><?= ui_btn_label('copy', 'คัดลอกรายการไปสอบถาม') ?></button>
</div>
<?php } ?>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css) ?>
  <thead>
  <tr>
    <th data-pri="1">รหัสเครื่อง</th><th data-pri="3">รุ่น</th>
    <th data-pri="2">สถานะในทะเบียนเรา</th><th data-pri="1">ระบบเช่าว่า</th>
    <th data-pri="1">ฝั่งขายว่า</th><th data-pri="1">ระดับ</th><th data-pri="2"></th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$show) { ?>
  <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">ไม่มีเครื่องที่ตรงกับเงื่อนไข — สองระบบตรงกันหมด</td></tr>
  <?php } ?>
  <?php foreach ($show as $x) { $L = RSC_LEVELS[$x['level']]; ?>
  <tr<?= $x['level'] === 'clash' ? ' class="rsc-row-warn"' : '' ?>>
    <td data-pri="1"><a href="<?= $B ?>/asset.php?id=<?= (int) $x['id'] ?>"><b><?= h($x['sn']) ?></b></a></td>
    <td data-pri="3" class="muted"><?= h($x['pname']) ?></td>
    <td data-pri="2"><span class="badge" style="<?= h(status_badge_style($x['status'])) ?>"><?= h(status_palette_entry($x['status'])['th']) ?></span></td>
    <td data-pri="1">
      <span class="badge <?= h($x['lease_cls']) ?>"><?= h($x['lease_lbl']) ?></span>
      <?php if ($x['lease_cus'] !== '') { ?><div class="cell-sub muted"><?= h($x['lease_cus']) ?></div><?php } ?>
    </td>
    <td data-pri="1">
      <span class="rsc-flag"><?= h($x['ev_label']) ?></span>
      <?php $sub = trim(implode(' · ', array_filter([$x['ev_ref'], rsc_date($x['ev_date']), $x['ev_cus']])));
      if ($sub !== '') { ?><div class="cell-sub muted"><?= h($sub) ?></div><?php } ?>
    </td>
    <td data-pri="1"><span class="badge" style="<?= h(status_badge_style($L['pal'])) ?>"><?= h($L['th']) ?></span></td>
    <td data-pri="2" class="rsc-acts">
      <a class="btn btn-sm btn-line" href="<?= $B ?>/asset.php?id=<?= (int) $x['id'] ?>">ดูเครื่อง</a>
      <?php $u = rent_leasing_record_url($x['sn']); if ($u !== '') { ?>
      <a class="btn btn-sm btn-line" href="<?= h($u) ?>" target="_blank" rel="noopener">ระบบเช่า</a>
      <?php } ?>
    </td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<div class="rsc-foot muted">
  <?php foreach (RSC_LEVELS as $k => $L) { ?>
  <p><span class="badge" style="<?= h(status_badge_style($L['pal'])) ?>"><?= h($L['th']) ?></span> <?= h($L['note']) ?></p>
  <?php } ?>
  <p>หน้านี้อ่านอย่างเดียว ไม่แก้ข้อมูลฝั่งไหนทั้งนั้น — เครื่องที่ตรวจแล้วว่าขายจริง
    ให้ทีมเช่าลบแถวในทะเบียนเช่าออก แล้วกดปุ่มเคลียร์สถานะที่หน้า
    <a href="<?= $B ?>/rent_idle_check.php">เครื่องค้างในคลังเช่า</a> ระบบจะตั้งเป็น “ขายแล้ว” ให้เอง</p>
</div>

<script>
(function () {
  // คัดลอกไปถามทีมเช่า/ทีมขายว่าฝั่งไหนถูก — ระบบเราไม่แก้ข้อมูลของใครเอง
  var copy = document.getElementById('rsc-copy');
  if (!copy) { return; }
  copy.addEventListener('click', function () {
    var lines = [].slice.call(document.querySelectorAll('tbody tr')).map(function (tr) {
      var td = tr.querySelectorAll('td');
      if (td.length < 6) { return ''; }
      var cell = function (i) { return (td[i].textContent || '').replace(/\s+/g, ' ').trim(); };
      return [cell(0), 'ระบบเช่า: ' + cell(3), 'ฝั่งขาย: ' + cell(4), cell(5)].join(' · ');
    }).filter(Boolean);
    if (!lines.length) { return; }
    var txt = 'เครื่องที่ข้อมูลสองระบบไม่ตรงกัน — รบกวนช่วยตรวจว่าฝั่งไหนถูก\n\n' + lines.join('\n');
    var done = function () {
      var old = copy.textContent;
      copy.textContent = 'คัดลอกแล้ว ' + lines.length + ' รายการ';
      setTimeout(function () { copy.textContent = old; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(done).catch(function () { window.prompt('คัดลอกข้อความนี้', txt); });
    } else { window.prompt('คัดลอกข้อความนี้', txt); }
  });
})();
</script>
<style>
.rsc-lead { margin-bottom: 14px; line-height: 1.85; }
.rsc-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 720px) { .rsc-stats { grid-template-columns: repeat(3, 1fr); } }
.rsc-stat { background: var(--surface, #fff); border: 1px solid var(--border, #dde3ec); border-radius: 10px; padding: 12px 8px; text-align: center; }
.rsc-num { font-size: calc(22px * var(--font-scale, 1)); font-weight: 700; line-height: 1.2; }
.rsc-lbl { font-size: calc(11px * var(--font-scale, 1)); margin-top: 2px; }
.rsc-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }
.rsc-chip.is-on { background: var(--primary-soft, #fce7f3); color: var(--primary); font-weight: 600;
  box-shadow: inset 0 0 0 1.5px var(--primary); }
.rsc-chip.is-on:hover { background: var(--primary-soft, #fce7f3); }
.rsc-actbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 10px;
  padding: 10px 14px; background: var(--surface-2, #faf9fd); border: 1px solid var(--border, #dde3ec);
  border-left: 3px solid var(--primary); border-radius: 8px; }
.rsc-actbar-lbl { font-size: calc(12.5px * var(--font-scale, 1)); color: var(--text-muted, #6b6480); font-weight: 600; }
.rsc-flag { font-size: calc(11.5px * var(--font-scale, 1)); border-radius: 999px; padding: 2px 9px; white-space: nowrap;
  background: var(--danger-soft, #fee2e2); color: var(--danger, #b91c1c); }
.rsc-acts { display: flex; gap: 6px; flex-wrap: wrap; }
tr.rsc-row-warn td { background: var(--surface-2, #faf9fd); }
.rsc-foot { margin-top: 12px; font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.8; }
.rsc-foot p { margin: 0 0 6px; }
</style>
<?php } ?>
<?php page_footer(); ?>
