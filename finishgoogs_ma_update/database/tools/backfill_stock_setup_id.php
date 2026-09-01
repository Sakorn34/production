<?php
/**
 * database/tools/backfill_stock_setup_id.php — เติม stock.setup_id จากแหล่งอ้างอิงอื่น
 *
 * วัตถุประสงค์: แก้กรณี S/N มีรายการเบิกขายใน po_order_part_serials / stock_movements / stock_old
 * แต่ stock.setup_id ยังว่าง ทำให้ card การเบิกใช้งานขายแสดงผิด
 *
 * การใช้งาน:
 *   php database/tools/backfill_stock_setup_id.php              # dry-run (default)
 *   php database/tools/backfill_stock_setup_id.php --apply      # เขียนจริง
 *   php database/tools/backfill_stock_setup_id.php --serial=AA626060442
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "รันผ่าน CLI เท่านั้น\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__, 2) . '/includes/stockparts_withdraw.php';

// ─ CLI args ───────────────────────────────────────────────────────────────────

$apply = in_array('--apply', $argv, true);
$onlySerial = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--serial=') === 0) {
        $onlySerial = trim(substr($arg, 9));
    }
}

try {
    $db = dbStock();
} catch (Throwable $e) {
    fwrite(STDERR, 'เชื่อมต่อ biton_stockparts ไม่ได้: ' . $e->getMessage() . "\n");
    exit(1);
}

// ─ Load candidates ────────────────────────────────────────────────────────────

$sql = "SELECT serial_number, model, setup_id FROM stock
        WHERE setup_id IS NULL OR setup_id = '' OR setup_id = '0'";
$params = [];
$types = '';

if ($onlySerial !== null && $onlySerial !== '') {
    $sql .= ' AND serial_number = ?';
    $params[] = $onlySerial;
    $types .= 's';
}
$sql .= ' ORDER BY serial_number';

$st = $db->prepare($sql);
if (!$st) {
    fwrite(STDERR, "prepare ล้มเหลว: {$db->error}\n");
    exit(1);
}
if ($params) {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result();

$candidates = [];
while ($row = $res->fetch_assoc()) {
    $candidates[$row['serial_number']] = $row;
}

echo '=== backfill stock.setup_id ===' . "\n";
echo 'mode: ' . ($apply ? 'APPLY (เขียนจริง)' : 'DRY-RUN (ดูอย่างเดียว)') . "\n";
echo 'candidates (setup_id ว่าง): ' . count($candidates) . "\n\n";

if (!$candidates) {
    echo "ไม่มีแถวที่ต้องเติม\n";
    exit(0);
}

// ─ Batch resolve (500 ต่อรอบ) ─────────────────────────────────────────────────

$updates = [];
$chunkSize = 500;
$chunks = array_chunk(array_keys($candidates), $chunkSize, true);

foreach ($chunks as $chunkKeys) {
    $batchRows = [];
    foreach ($chunkKeys as $sn) {
        $batchRows[$sn] = $candidates[$sn];
    }
    $resolvedMap = stockparts_batch_resolve_withdraw_refs($db, $batchRows);
    foreach ($resolvedMap as $sn => $resolved) {
        $updates[] = [
            'serial' => $sn,
            'model' => $candidates[$sn]['model'] ?? '',
            'ref' => $resolved['ref'],
            'source' => $resolved['source'],
        ];
    }
}

$noRef = count($candidates) - count($updates);
$bySource = [];
foreach ($updates as $u) {
    $bySource[$u['source']] = ($bySource[$u['source']] ?? 0) + 1;
}

echo 'พบการอ้างอิงที่เติมได้: ' . count($updates) . "\n";
echo 'ไม่พบแหล่งอ้างอิง: ' . $noRef . "\n";
foreach ($bySource as $src => $cnt) {
    echo "  - {$src}: {$cnt}\n";
}
echo "\n";

$show = array_slice($updates, 0, 30);
foreach ($show as $u) {
    echo sprintf(
        "%s | %s | setup_id => %s (%s)\n",
        $u['serial'],
        $u['model'],
        $u['ref'],
        $u['source']
    );
}
if (count($updates) > 30) {
    echo '... และอีก ' . (count($updates) - 30) . " รายการ\n";
}

if (!$apply) {
    echo "\nรันด้วย --apply เพื่อ UPDATE stock.setup_id จริง\n";
    exit(0);
}

// ─ Apply ──────────────────────────────────────────────────────────────────────

$upd = $db->prepare('UPDATE stock SET setup_id = ? WHERE serial_number = ?');
if (!$upd) {
    fwrite(STDERR, "prepare UPDATE ล้มเหลว: {$db->error}\n");
    exit(1);
}

$ok = 0;
$fail = 0;
foreach ($updates as $u) {
    $upd->bind_param('ss', $u['ref'], $u['serial']);
    if ($upd->execute()) {
        $ok++;
    } else {
        $fail++;
        fwrite(STDERR, "UPDATE ล้มเหลว: {$u['serial']} — {$upd->error}\n");
    }
}

echo "\nอัปเดตสำเร็จ: {$ok}, ล้มเหลว: {$fail}\n";
exit($fail > 0 ? 1 : 0);
