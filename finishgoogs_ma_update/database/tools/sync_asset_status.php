<?php
/**
 * database/tools/sync_asset_status.php — sync assets.status จากระบบเช่า + stockparts
 *
 * วัตถุประสงค์: แก้เครื่องที่ขาย/เช่าแล้วแต่สถานะยังเป็น new
 *
 * การใช้งาน:
 *   php database/tools/sync_asset_status.php              # dry-run
 *   php database/tools/sync_asset_status.php --apply    # เขียนจริง
 *   php database/tools/sync_asset_status.php --apply --id=589
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "รันผ่าน CLI เท่านั้น\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__, 2) . '/includes/asset_status_sync.php';

$apply = in_array('--apply', $argv, true);
$onlyId = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--id=') === 0) {
        $onlyId = (int) substr($arg, 5);
    }
}

echo $apply ? "=== APPLY sync assets.status ===\n" : "=== DRY-RUN sync assets.status ===\n";

if ($onlyId > 0) {
    $row = qr('SELECT id, asset_code, status FROM assets WHERE id=? LIMIT 1', 'i', [$onlyId])->fetch_assoc();
    if (!$row) {
        fwrite(STDERR, "ไม่พบ asset id={$onlyId}\n");
        exit(1);
    }
    $item = asset_status_sync_by_id($onlyId, $apply);
    if (!empty($item['changed'])) {
        echo "  {$row['asset_code']}: {$item['from']} -> {$item['to']} ({$item['reason']})\n";
    } else {
        echo "  {$row['asset_code']}: ไม่เปลี่ยน ({$item['reason']})\n";
    }
    exit(0);
}

$stats = asset_status_sync_all($apply, 200);
echo 'ตรวจ ' . number_format($stats['total']) . ' เครื่อง · เปลี่ยน ' . number_format($stats['changed']) . " รายการ\n";
foreach (array_slice($stats['items'], 0, 50) as $it) {
    echo '  ' . $it['asset_code'] . ': ' . $it['from'] . ' -> ' . $it['to'] . ' (' . $it['reason'] . ")\n";
}
if (count($stats['items']) > 50) {
    echo '  ... และอีก ' . number_format(count($stats['items']) - 50) . " รายการ\n";
}
