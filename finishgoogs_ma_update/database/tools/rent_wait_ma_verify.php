<?php
/**
 * database/tools/rent_wait_ma_verify.php — ตรวจคิวรอ MA แยกตามรุ่นผลิต
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__, 2) . '/includes/rent_ma_bridge.php';

$checks = [
    35 => 'Router 4G',
    36 => 'Router WIFI',
    38 => 'โทรศัพท์มือถือ',
    12 => 'bitSupply 1203',
    34 => 'Smart Card Reader S',
    37 => 'Printer 80mm.',
    24 => 'Card Camera',
];

$fail = 0;
foreach ($checks as $pid => $label) {
    $split = rent_queue_rows_for_product($pid);
    if (!$split['ok']) {
        echo "FAIL id $pid: {$split['error']}\n";
        $fail++;
        continue;
    }
    $n = count($split['matched']) + count($split['unmatched']);
    echo sprintf("id %2d %-22s matched=%d unmatched=%d total=%d\n", $pid, $label, count($split['matched']), count($split['unmatched']), $n);
}

$counts = rent_wait_ma_counts_by_product();
echo "\ntotal_matched={$counts['total_matched']}\n";
foreach ([35, 36, 38, 12, 34, 37, 24] as $pid) {
    $c = (int) ($counts['counts'][$pid] ?? 0);
    if ($c > 0) {
        echo "  count[$pid]=$c\n";
    }
}

exit($fail > 0 ? 1 : 0);
