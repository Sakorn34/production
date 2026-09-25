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

/**
 * แถวในคลังช่างที่น่าจะเป็นอะไหล่ตัวเดียวกัน
 *
 * ไล่ 3 ทาง: ร่องรอยการเบิกครั้งก่อน (แม่นสุด — เป็นแถวเดิมจริง ๆ) · ชื่อตรงกัน · เลขในรหัสตรงกัน
 *
 * @param array<string,mixed> $part แถว parts ของเรา (ต้องมี id, name, code)
 * @return array{row:array<string,mixed>,why:string}|null
 */
function plc_guess_stock_row(array $part): ?array
{
    $pdo = function_exists('dbParts') ? dbParts() : null;
    if (!$pdo) {
        return null;
    }
    // 1) เคยเบิกสำเร็จมาก่อน → ตามรอยใบเบิกเดิมว่าไปตัดแถวไหนในคลังช่าง
    $mv = qr("SELECT tech_stock_out_id FROM part_movements
              WHERE part_id = ? AND direction = 'out' AND tech_stock_out_id > 0
              ORDER BY moved_at DESC, id DESC LIMIT 1", 'i', [(int) $part['id']])->fetch_assoc();
    if ($mv) {
        $st = $pdo->prepare('SELECT p.* FROM stock_out_items i JOIN products p ON p.id = i.product_id
                             WHERE i.stock_out_id = ? ORDER BY i.id DESC LIMIT 1');
        $st->execute([(int) $mv['tech_stock_out_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['row' => $row, 'why' => 'ตามรอยจากใบเบิกครั้งล่าสุดของอะไหล่ตัวนี้'];
        }
    }
    // 2) ชื่อตรงกันเป๊ะ
    $st = $pdo->prepare('SELECT * FROM products WHERE name = ? LIMIT 1');
    $st->execute([(string) $part['name']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['row' => $row, 'why' => 'ชื่อตรงกับรายการในคลังช่าง'];
    }
    // 3) เลขในรหัสตรงกัน (P151 กับ P00151 คือตัวเดียวกัน แค่เติมศูนย์ไม่เท่ากัน)
    $num = ltrim(preg_replace('/\D+/', '', (string) $part['code']), '0');
    if ($num !== '') {
        $st = $pdo->prepare('SELECT * FROM products WHERE code REGEXP ? LIMIT 2');
        $st->execute(['^[A-Za-z]*0*' . $num . '$']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            return ['row' => $rows[0], 'why' => 'เลขในรหัสตรงกัน ต่างแค่การเติมศูนย์'];
        }
    }
    return null;
}

/**
 * รายการที่แอปอะไหล่บันทึกไว้ตอนลบ — ตอบได้ว่าอะไหล่ที่หายไปถูกลบเมื่อไหร่ โดยใคร
 *
 * แอปอะไหล่ลบแถวออกจากตารางจริง (ไม่ได้ปิดใช้งาน) แต่เขียนบรรทัดไว้ที่ parts/logs/deletions.log
 * รูปแบบ: 2026-06-23 06:47:10 | deleted_by=don | force=1 | id=4 | code=P004 | name=ท่อ PVC
 *
 * @return array<string,array{at:string,by:string,code:string,name:string}> คีย์ = รหัสตัวใหญ่ และชื่อตัวใหญ่
 */
function plc_deleted_log(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $file = dirname(__DIR__, 2) . '/parts/logs/deletions.log';
    if (!is_file($file) || !is_readable($file)) {
        return $map;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) {
            continue;
        }
        $row = ['at' => $parts[0], 'by' => '', 'code' => '', 'name' => ''];
        foreach ($parts as $p) {
            if (strpos($p, 'deleted_by=') === 0) { $row['by'] = substr($p, 11); }
            if (strpos($p, 'code=') === 0) { $row['code'] = substr($p, 5); }
            if (strpos($p, 'name=') === 0) { $row['name'] = substr($p, 5); }
        }
        if ($row['code'] !== '') { $map[mb_strtoupper($row['code'])] = $row; }
        if ($row['name'] !== '') { $map[mb_strtoupper($row['name'])] = $row; }
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_part'])) {
    csrf_check();
    $pid = (int) $_POST['fix_part'];
    $code = trim((string) ($_POST['new_code'] ?? ''));
    $part = qr('SELECT id, name, stock_code FROM parts WHERE id = ?', 'i', [$pid])->fetch_assoc();
    $hit = $code !== '' && function_exists('tech_parts_product_by_code') ? tech_parts_product_by_code($code) : null;
    if (!$part || !$hit) {
        flash_set('แก้รหัสไม่ได้ — ไม่พบอะไหล่ หรือรหัสใหม่ไม่มีในคลังช่าง', 'err');
    } else {
        q('UPDATE parts SET stock_code = ? WHERE id = ?', 'si', [$code, $pid]);
        if (function_exists('activity_log_write')) {
            activity_log_write([
                'system_key' => 'production', 'actor_name' => actor_name(), 'action_key' => 'part_stock_code_fix',
                'summary' => 'แก้รหัส Stock ของอะไหล่ ' . $part['name'] . ': ' . (string) $part['stock_code'] . ' → ' . $code,
                'entity_type' => 'part', 'entity_id' => (string) $pid,
            ]);
        }
        flash_set('แก้รหัส Stock ของ "' . $part['name'] . '" เป็น ' . $code . ' แล้ว — เบิกได้ตามปกติ');
    }
    header('Location: ' . $B . '/parts_link_check.php' . (!empty($_POST['all']) ? '?all=1' : ''));
    exit;
}

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
// ตัวที่จับคู่ไม่ได้ — ลองเดาว่าในคลังช่างมันคือแถวไหน (เดาเฉพาะที่แสดงอยู่ ไม่ต้องยิงทั้งตาราง)
$delLog = plc_deleted_log();
foreach ($show as &$x) {
    $x['guess'] = $x['state'] === 'missing' ? plc_guess_stock_row($x) : null;
    $x['deleted'] = $x['state'] === 'missing'
        ? ($delLog[mb_strtoupper((string) $x['code'])] ?? $delLog[mb_strtoupper((string) $x['name'])] ?? null)
        : null;
}
unset($x);

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
    <tr><th data-pri="1">อะไหล่</th><th data-pri="2">รหัส Stock</th><th data-pri="1">คลังช่าง</th><th data-pri="1">น่าจะเป็นตัวนี้</th><th data-pri="3">อยู่ในชุดเบิก</th></tr>
    <?php foreach ($show as $x) { ?>
    <tr>
      <td data-pri="1"><b><?= h((string) $x['name']) ?></b><?= trim((string) $x['unit']) !== '' ? ' <span class="muted">(' . h((string) $x['unit']) . ')</span>' : '' ?>
        <div class="cell-sub muted"><?= h((string) ($x['code'] !== '' ? $x['code'] : 'ไม่ได้ตั้งรหัส Stock')) ?></div></td>
      <td data-pri="2" class="plc-code"><?= h((string) ($x['code'] !== '' ? $x['code'] : '—')) ?></td>
      <td data-pri="1">
        <?php if ($x['state'] === 'missing') { ?><span class="plc-badge is-bad">ไม่มีในคลังช่าง</span>
          <?php if (!empty($x['deleted'])) { ?><div class="muted" style="font-size:12px">ถูกลบ <?= h(substr((string) $x['deleted']['at'], 0, 16)) ?><?= $x['deleted']['by'] !== '' ? ' โดย ' . h((string) $x['deleted']['by']) : '' ?></div><?php } ?>
        <?php } elseif ($x['state'] === 'empty') { ?><span class="plc-badge is-warn">ของหมด (0)</span>
        <?php } else { ?><span class="plc-badge is-ok">เหลือ <?= number_format((int) $x['qty']) ?></span><?php } ?>
      </td>
      <td data-pri="1">
        <?php $g = $x['guess'] ?? null; if ($g) { ?>
        <b class="plc-code"><?= h((string) $g['row']['code']) ?></b> · <?= h((string) $g['row']['name']) ?>
        <div class="muted" style="font-size:12px">เหลือ <?= number_format((int) $g['row']['quantity']) ?> · <?= h($g['why']) ?></div>
        <form method="post" style="margin-top:4px">
          <?= csrf_field() ?><input type="hidden" name="fix_part" value="<?= (int) $x['id'] ?>">
          <input type="hidden" name="new_code" value="<?= h((string) $g['row']['code']) ?>">
          <?php if ($showAll) { ?><input type="hidden" name="all" value="1"><?php } ?>
          <button type="submit" class="btn btn-sm btn-line" onclick="return confirm('เปลี่ยนรหัส Stock ของ <?= h((string) $x['name']) ?> เป็น <?= h((string) $g['row']['code']) ?>?')">ใช้รหัสนี้</button>
        </form>
        <?php } elseif ($x['state'] === 'missing') { ?>
        <span class="muted">ไม่พบตัวที่ใกล้เคียง — ให้ทีมอะไหล่เพิ่มรายการกลับ</span>
        <?php } else { ?><span class="muted">—</span><?php } ?>
      </td>
      <td data-pri="3"><?= $x['bom'] > 0 ? number_format($x['bom']) . ' รุ่น' : '<span class="muted">—</span>' ?><?= $x['used'] > 0 ? '<div class="muted" style="font-size:12px">เคยเบิก ' . number_format($x['used']) . ' ครั้ง</div>' : '' ?></td>
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
