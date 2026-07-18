<?php
/**
 * check_import_missing.php — ตรวจ S/N ใน CSV import ที่ยังไม่มีในตาราง assets
 */
require dirname(__DIR__, 2) . '/config.php';

$csv = dirname(__DIR__) . '/import/update_logs_from_history.csv';
if (!is_readable($csv)) {
    fwrite(STDERR, "ไม่พบไฟล์: $csv\n");
    exit(1);
}

$fh = fopen($csv, 'r');
$head = fgetcsv($fh);
if (isset($head[0])) {
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
}
$col = array_flip(array_map('strtolower', $head));
$byCode = [];

while (($r = fgetcsv($fh)) !== false) {
    $code = strtoupper(trim($r[$col['asset_code']] ?? ''));
    if ($code === '') {
        continue;
    }
    if (!isset($byCode[$code])) {
        $byCode[$code] = [
            'model' => trim($r[$col['model']] ?? ''),
            'source_sn' => trim($r[$col['source_sn']] ?? ''),
            'detail' => trim($r[$col['detail']] ?? ''),
            'rows' => 0,
        ];
    }
    $byCode[$code]['rows']++;
}
fclose($fh);

$missing = [];
$found = 0;
foreach ($byCode as $code => $meta) {
    $row = qr('SELECT id, product_id FROM assets WHERE asset_code=?', 's', [$code])->fetch_assoc();
    if ($row) {
        $found++;
    } else {
        $missing[$code] = $meta;
    }
}

echo 'รหัสไม่ซ้ำใน CSV: ' . count($byCode) . "\n";
echo 'มีในระบบ: ' . $found . "\n";
echo 'ไม่พบเครื่อง: ' . count($missing) . "\n\n";

$out = dirname(__DIR__) . '/import/update_logs_missing_assets.csv';
$lf = fopen($out, 'w');
fwrite($lf, "\xEF\xBB\xBF");
fputcsv($lf, ['asset_code', 'model_in_csv', 'source_sn', 'sample_detail', 'import_rows']);
foreach ($missing as $code => $m) {
    fputcsv($lf, [$code, $m['model'], $m['source_sn'], $m['detail'], $m['rows']]);
    echo "$code | model={$m['model']} | rows={$m['rows']}\n";
}
fclose($lf);
echo "\nบันทึก: $out\n";

// ตรวจใน stock (biton_stockparts) ด้วย
$stock = dbStock();
$stStock = $stock->prepare('SELECT model, `timestamp` FROM stock WHERE serial_number=?');
$inStock = 0;
echo "\n--- ใน stock แต่ไม่มีใน assets ---\n";
foreach (array_keys($missing) as $code) {
    $stStock->bind_param('s', $code);
    $stStock->execute();
    $sr = $stStock->get_result()->fetch_assoc();
    if ($sr) {
        $inStock++;
        echo "$code => model={$sr['model']}\n";
    }
}
echo "รวมใน stock: $inStock / " . count($missing) . "\n";
