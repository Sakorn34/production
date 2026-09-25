<?php
/**
 * parts_link_check.php — ตรวจการจับคู่อะไหล่กับคลังช่าง (25 ก.ย. 2026)
 *
 * รายการอะไหล่ในฟอร์มผลิต/MA มาจากตาราง parts ของเรา แต่ตอนกดบันทึกจะไปตัดสต็อกจริง
 * ที่คลังช่าง (biton_tech_parts.products) โดยจับคู่ด้วย stock_code — ถ้ารหัสนั้นถูกลบ
 * หรือเปลี่ยนชื่อในแอปอะไหล่ รายการจะยังโผล่ในฟอร์มแต่เบิกไม่ได้ หน้านี้ไล่ให้ดูว่ามีตัวไหนบ้าง
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/part_stock_bridge.php';
require_login();

$B = BASE_URL;
$showAll = !empty($_GET['all']);

$rows = [];
$r = qr('SELECT id, name, unit, part_code, stock_code, icon_path FROM parts WHERE is_active = 1 ORDER BY name');
while ($x = $r->fetch_assoc()) {
    $rows[] = $x;
}
// จำนวนรุ่นที่ใช้อะไหล่นี้ในชุดเบิก + จำนวนครั้งที่เคยเบิกจริง (ไว้บอกว่าตัวไหนสำคัญ)
$bomCount = [];
$rb = db()->query('SELECT part_id, COUNT(DISTINCT product_id) n FROM bom_items GROUP BY part_id');
while ($x = $rb->fetch_row()) {
    $bomCount[(int) $x[0]] = (int) $x[1];
}
$useCount = [];
$ru = db()->query("SELECT part_id, COUNT(*) n FROM part_movements WHERE direction = 'out' GROUP BY part_id");
while ($x = $ru->fetch_row()) {
    $useCount[(int) $x[0]] = (int) $x[1];
}

$qtyMap = [];
$linkErr = '';
try {
    $qtyMap = tech_parts_qty_map_for_parts($rows);
} catch (Throwable $e) {
    $linkErr = 'ต่อคลังอะไหล่ช่างไม่ได้: ' . $e->getMessage();
}

$items = [];
$bad = 0;
$out = 0;
foreach ($rows as $x) {
    $code = part_row_stock_code($x);
    if ($code === '') {
        $code = trim((string) ($x['part_code'] ?? ''));
    }
    $qty = ($code !== '' && array_key_exists($code, $qtyMap)) ? (int) $qtyMap[$code] : null;
    $state = $qty === null ? 'missing' : ($qty <= 0 ? 'empty' : 'ok');
    if ($state === 'missing') {
        $bad++;
    } elseif ($state === 'empty') {
        $out++;
    }
    $items[] = $x + ['code' => $code, 'qty' => $qty, 'state' => $state,
                     'bom' => $bomCount[(int) $x['id']] ?? 0, 'used' => $useCount[(int) $x['id']] ?? 0];
}
// ตัวที่มีปัญหาขึ้นก่อน แล้วเรียงจากที่ใช้ในชุดเบิกมากสุด (กระทบการผลิตก่อน)
usort($items, function ($a, $b) {
    $rank = ['missing' => 0, 'empty' => 1, 'ok' => 2];
    return [$rank[$a['state']], -$a['bom'], $a['name']] <=> [$rank[$b['state']], -$b['bom'], $b['name']];
});
$show = $showAll ? $items : array_values(array_filter($items, function ($x) { return $x['state'] !== 'ok'; }));

page_header('ตรวจการจับคู่อะไหล่กับคลังช่าง', true,
    'อะไหล่ในระบบผลิต ' . number_format(count($items)) . ' รายการ · ไม่มีในคลังช่าง ' . number_format($bad) . ' · ของหมด ' . number_format($out),
    $B . '/settings.php');
?>
<div class="panel plc-note">
  รายการอะไหล่ในฟอร์มผลิตและฟอร์ม MA มาจากทะเบียนอะไหล่ของระบบเรา แต่ตอนบันทึกจะไป<b>ตัดสต็อกจริงที่คลังช่าง</b>
  โดยจับคู่ด้วย<b>รหัส Stock</b> — ถ้ารหัสนั้นไม่มีในคลังช่างแล้ว (ถูกลบหรือเปลี่ยนรหัส) รายการจะยังเลือกได้ในฟอร์ม แต่กดบันทึกแล้วจะไม่ผ่าน
</div>

<?php if ($linkErr !== '') { ?>
<div class="panel"><p class="err" style="margin:0"><?= h($linkErr) ?></p></div>
<?php } ?>

<div class="filter">
  <a class="btn btn-sm<?= $showAll ? ' btn-line' : '' ?>" href="<?= $B ?>/parts_link_check.php" data-same-tab>เฉพาะที่มีปัญหา (<?= number_format($bad + $out) ?>)</a>
  <a class="btn btn-sm<?= $showAll ? '' : ' btn-line' ?>" href="<?= $B ?>/parts_link_check.php?all=1" data-same-tab>ทั้งหมด (<?= number_format(count($items)) ?>)</a>
</div>

<div class="panel">
  <?php if (!$show) { ?>
  <p class="muted" style="margin:0">จับคู่ได้ครบทุกรายการ — อะไหล่ทุกตัวในฟอร์มเบิกได้จริง</p>
  <?php } else { ?>
  <div class="table-wrap"><table class="list">
    <tr><th data-pri="1">อะไหล่</th><th data-pri="2">รหัส Stock</th><th data-pri="1">คลังช่าง</th><th data-pri="2">อยู่ในชุดเบิกของรุ่น</th><th data-pri="3">เคยเบิก</th></tr>
    <?php foreach ($show as $x) { ?>
    <tr>
      <td data-pri="1"><b><?= h((string) $x['name']) ?></b><?= trim((string) $x['unit']) !== '' ? ' <span class="muted">(' . h((string) $x['unit']) . ')</span>' : '' ?>
        <div class="cell-sub muted"><?= h((string) ($x['code'] !== '' ? $x['code'] : 'ไม่ได้ตั้งรหัส Stock')) ?></div></td>
      <td data-pri="2" class="plc-code"><?= h((string) ($x['code'] !== '' ? $x['code'] : '—')) ?></td>
      <td data-pri="1">
        <?php if ($x['state'] === 'missing') { ?><span class="plc-badge is-bad">ไม่มีในคลังช่าง</span>
        <?php } elseif ($x['state'] === 'empty') { ?><span class="plc-badge is-warn">ของหมด (0)</span>
        <?php } else { ?><span class="plc-badge is-ok">เหลือ <?= number_format((int) $x['qty']) ?></span><?php } ?>
      </td>
      <td data-pri="2"><?= $x['bom'] > 0 ? number_format($x['bom']) . ' รุ่น' : '<span class="muted">—</span>' ?></td>
      <td data-pri="3"><?= $x['used'] > 0 ? number_format($x['used']) . ' ครั้ง' : '<span class="muted">—</span>' ?></td>
    </tr>
    <?php } ?>
  </table></div>
  <?php } ?>
</div>

<p class="muted plc-foot">วิธีแก้: ให้ทีมอะไหล่เพิ่มรายการนี้กลับเข้าคลังช่างด้วยรหัสเดิม หรือแก้ <b>รหัส Stock</b> ของอะไหล่ในระบบเราให้ตรงกับรหัสใหม่ (แอปอะไหล่ → รายละเอียดอะไหล่)</p>

<style>
.plc-note { background: #fff7ed; border-color: #f59e0b; color: #92400e; line-height: 1.7; }
.plc-code { font-family: ui-monospace, monospace; }
.plc-badge { display: inline-block; font-size: calc(12px * var(--font-scale, 1)); border-radius: 999px; padding: 2px 10px; white-space: nowrap; }
.plc-badge.is-ok { background: #d1fae5; color: #065f46; }
.plc-badge.is-warn { background: #fef3c7; color: #92400e; }
.plc-badge.is-bad { background: #fee2e2; color: #991b1b; }
.plc-foot { margin-top: 12px; font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.6; }
</style>
<?php page_footer(); ?>
