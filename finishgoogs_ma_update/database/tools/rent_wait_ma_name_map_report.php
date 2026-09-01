<?php
/**
 * database/tools/rent_wait_ma_name_map_report.php — รายงานแมปชื่อรุ่น production ↔ รายการรอ MA
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__, 2) . '/includes/rent_ma_bridge.php';

if (!dbLeasing()) {
    fwrite(STDERR, 'dbLeasing fail: ' . dbLeasingError() . "\n");
    exit(1);
}

$products = rent_product_catalog();
$rentNames = [];
$qMa = rent_q_try(
    'SELECT pro_name, COUNT(*) c FROM tbl_product WHERE pro_status = ? GROUP BY pro_name ORDER BY pro_name',
    's',
    ['MA']
);
while ($r = $qMa['result']->fetch_assoc()) {
    $rentNames[] = ['name' => (string) $r['pro_name'], 'count' => (int) $r['c']];
}
$qTotal = rent_q_try('SELECT COUNT(*) c FROM tbl_product WHERE pro_status = ?', 's', ['MA']);
$totalMa = (int) ($qTotal['result']->fetch_assoc()['c'] ?? 0);

$nameList = array_column($rentNames, 'name');
$builtMap = rent_build_rent_name_product_map($nameList);
$prodById = [];
foreach ($products as $p) {
    $prodById[$p['id']] = $p['name'];
}

$mapped = [];
$unmapped = [];
foreach ($rentNames as $rn) {
    $key = rent_product_name_key($rn['name']);
    $pid = (int) ($builtMap[$key] ?? 0);
    if ($pid <= 0) {
        $unmapped[] = $rn;
        continue;
    }
    $mapped[] = [
        'rent_name' => $rn['name'],
        'rent_count' => $rn['count'],
        'prod_id' => $pid,
        'prod_name' => $prodById[$pid] ?? '?',
    ];
}

echo "=== แมปชื่อรุ่น production ↔ รายการรอ MA ===\n";
echo 'เครื่องรอ MA: ' . number_format($totalMa) . "\n";
echo 'ชื่อ distinct: ' . count($rentNames) . "\n";
echo 'แมปได้: ' . count($mapped) . ' | แมปไม่ได้: ' . count($unmapped) . "\n\n";

echo "--- ตัวอย่าง ---\n";
foreach (['Router 4G', 'Router', 'Portable Client', 'bitSupply 1224', 'Smart Card S'] as $sample) {
    $pid = rent_resolve_rent_name_to_product_id($sample);
    $pname = $prodById[$pid] ?? 'ไม่แมป';
    echo $sample . ' => ' . $pname . ($pid ? " (id $pid)" : '') . "\n";
}
echo "\n";

if ($unmapped) {
    echo "--- แมปไม่ได้ ---\n";
    foreach ($unmapped as $u) {
        echo sprintf("  %s (%d คิว)\n", $u['name'], $u['count']);
    }
} else {
    echo "✓ แมปครบทุกชื่อในคิวรอ MA\n\n";
}

echo "--- แมปได้ ---\n";
foreach ($mapped as $m) {
    echo sprintf("  %s (%d คิว) => id %d %s\n", $m['rent_name'], $m['rent_count'], $m['prod_id'], $m['prod_name']);
}

// ตรวจนับคิว vs แมป
$counts = rent_wait_ma_counts_by_product();
$matchedTotal = (int) ($counts['total_matched'] ?? 0);
echo "\n--- ตรวจ rent_wait_ma_counts_by_product ---\n";
echo 'total_matched: ' . $matchedTotal . ' / ' . $totalMa . ($matchedTotal === $totalMa ? ' ✓' : ' ✗') . "\n";
