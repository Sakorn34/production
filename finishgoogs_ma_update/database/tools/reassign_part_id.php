<?php
/**
 * reassign_part_id.php — ย้าย part_id จากแถวหนึ่งไปอีกแถว แล้วลบแถวต้นทาง
 *
 * ใช้เมื่อลบ parts ไม่ได้เพราะ part_movements FK และต้องการรวมประวัติเข้า part ปลายทาง
 *
 * รัน: php database/tools/reassign_part_id.php 144 141
 *      php database/tools/reassign_part_id.php 144 141 --dry-run
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config.php';

$fromId = isset($argv[1]) ? (int)$argv[1] : 0;
$toId   = isset($argv[2]) ? (int)$argv[2] : 0;
$dryRun = in_array('--dry-run', $argv, true);

if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
    fwrite(STDERR, "Usage: php reassign_part_id.php <from_id> <to_id> [--dry-run]\n");
    exit(1);
}

/**
 * นับแถวที่อ้าง part_id
 *
 * @param int $partId
 * @return array{movements:int,bom:int}
 */
function count_part_refs(int $partId): array
{
    $m = qr('SELECT COUNT(*) c FROM part_movements WHERE part_id = ?', 'i', [$partId])->fetch_assoc();
    $b = qr('SELECT COUNT(*) c FROM bom_items WHERE part_id = ?', 'i', [$partId])->fetch_assoc();
    return [
        'movements' => (int)($m['c'] ?? 0),
        'bom'       => (int)($b['c'] ?? 0),
    ];
}

$from = qr('SELECT id, stock_code, part_code, name FROM parts WHERE id = ?', 'i', [$fromId])->fetch_assoc();
$to   = qr('SELECT id, stock_code, part_code, name FROM parts WHERE id = ?', 'i', [$toId])->fetch_assoc();

if (!$from) {
    fwrite(STDERR, "ไม่พบ parts.id={$fromId}\n");
    exit(1);
}
if (!$to) {
    fwrite(STDERR, "ไม่พบ parts.id={$toId}\n");
    exit(1);
}

$beforeFrom = count_part_refs($fromId);
$beforeTo   = count_part_refs($toId);

echo "=== ก่อนดำเนินการ ===\n";
echo "FROM #{$fromId}: " . json_encode($from, JSON_UNESCAPED_UNICODE) . "\n";
echo "  part_movements: {$beforeFrom['movements']}, bom_items: {$beforeFrom['bom']}\n";
echo "TO   #{$toId}: " . json_encode($to, JSON_UNESCAPED_UNICODE) . "\n";
echo "  part_movements: {$beforeTo['movements']}, bom_items: {$beforeTo['bom']}\n";

if ($dryRun) {
    echo "\n[DRY-RUN] จะ UPDATE part_movements {$beforeFrom['movements']} แถว, bom_items {$beforeFrom['bom']} แถว\n";
    echo "[DRY-RUN] แล้ว DELETE parts.id={$fromId}\n";
    exit(0);
}

db()->begin_transaction();
try {
    if ($beforeFrom['movements'] > 0) {
        q('UPDATE part_movements SET part_id = ? WHERE part_id = ?', 'ii', [$toId, $fromId]);
    }
    if ($beforeFrom['bom'] > 0) {
        q('UPDATE bom_items SET part_id = ? WHERE part_id = ?', 'ii', [$toId, $fromId]);
    }
    q('DELETE FROM parts WHERE id = ?', 'i', [$fromId]);

    $chk = qr('SELECT id FROM parts WHERE id = ?', 'i', [$fromId])->fetch_assoc();
    if ($chk) {
        throw new RuntimeException("ลบ parts.id={$fromId} ไม่สำเร็จ");
    }

    db()->commit();
} catch (Throwable $e) {
    db()->rollback();
    fwrite(STDERR, 'ROLLBACK: ' . $e->getMessage() . "\n");
    exit(1);
}

$afterTo = count_part_refs($toId);
echo "\n=== สำเร็จ ===\n";
echo "ลบ parts.id={$fromId} แล้ว\n";
echo "TO #{$toId} part_movements: {$beforeTo['movements']} → {$afterTo['movements']}\n";
echo "TO #{$toId} bom_items: {$beforeTo['bom']} → {$afterTo['bom']}\n";
