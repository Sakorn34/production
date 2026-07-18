<?php
/**
 * fix_production_withdrawal_data.php — แก้หมายเหตุเบิกผlิt + แยก BP26072015-24 รายเครื่อง
 *
 * Usage: php fix_production_withdrawal_data.php [--dry-run]
 */
require __DIR__ . '/../../config.php';
require_once dirname(__DIR__, 3) . '/parts/includes/helpers.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = dbParts();
ensure_stock_production_sync_schema($pdo);

$noteProduction = getStockOutNoteOptions()[0];
$noteRepair = getStockOutNoteOptions()[1];
$noteTest = getStockOutNoteOptions()[2];

echo "=== Fix production withdrawal data ===\n";
echo "Target note: {$noteProduction}\n";
if ($dryRun) {
    echo "(DRY RUN)\n\n";
}

// หมายเหตุ production ที่มี S/N แต่ยังไม่ใช่ เบิกผlิt / ซ่อม / test
$st = $pdo->prepare(
    'SELECT id, doc_no, asset_code, note FROM stock_out
     WHERE TRIM(COALESCE(asset_code, \'\')) <> \'\'
       AND note NOT IN (?, ?, ?)'
);
$st->execute([$noteProduction, $noteRepair, $noteTest]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo 'Step 1: normalize production notes (' . count($rows) . " rows)\n";
foreach ($rows as $r) {
    echo "  #{$r['id']} {$r['doc_no']} | {$r['asset_code']} | {$r['note']}\n";
}
if (!$dryRun && $rows) {
    $stUp = $pdo->prepare(
        'UPDATE stock_out SET note = ?
         WHERE TRIM(COALESCE(asset_code, \'\')) <> \'\'
           AND note NOT IN (?, ?, ?)'
    );
    $stUp->execute([$noteProduction, $noteProduction, $noteRepair, $noteTest]);
}

$bulkIds = [40, 41];
echo "\nStep 2: remove bulk stock_out " . implode(',', $bulkIds) . "\n";
foreach ($bulkIds as $outId) {
    $chk = $pdo->prepare('SELECT id, doc_no, asset_code FROM stock_out WHERE id = ?');
    $chk->execute([$outId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "  skip #{$outId} (not found)\n";
        continue;
    }
    echo "  delete #{$outId} {$row['doc_no']} | {$row['asset_code']}\n";
    if (!$dryRun) {
        $ok = production_sync_delete_stock_out_by_id((int) $outId);
        echo $ok ? "    -> deleted + stock restored\n" : "    -> FAILED\n";
    }
}

$assetIds = [];
$res = db()->query("SELECT id, asset_code FROM assets WHERE asset_code >= 'BP26072015' AND asset_code <= 'BP26072024' ORDER BY asset_code");
while ($r = $res->fetch_assoc()) {
    $assetIds[] = (int) $r['id'];
}
echo "\nStep 3: sync per-machine withdrawals (" . count($assetIds) . " assets)\n";

$syncScript = __DIR__ . '/sync_asset_withdrawals.php';
foreach ($assetIds as $aid) {
    echo "  asset #{$aid}\n";
    $cmd = 'php ' . escapeshellarg($syncScript) . ' ' . $aid . ($dryRun ? ' --dry-run' : '');
    passthru($cmd);
}

echo "\nDone.\n";
