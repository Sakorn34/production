<?php
/**
 * ดึงข้อมูล asset + part_movements สำหรับ sync ไป Stock ช่าง
 */
require __DIR__ . '/../../config.php';

$assetId = (int)($argv[1] ?? 18413);

$a = qr("SELECT a.id, a.asset_code, a.produced_at, p.name pname
         FROM assets a JOIN products p ON p.id=a.product_id WHERE a.id=?", 'i', [$assetId])->fetch_assoc();

if (!$a) {
    echo "Asset not found\n";
    exit(1);
}

echo "=== Asset #{$assetId} ===\n";
echo "S/N: {$a['asset_code']}\n";
echo "Model: {$a['pname']}\n";
echo "Produced: {$a['produced_at']}\n\n";

$res = qr("SELECT pm.id, pm.moved_at, pm.qty, pm.made_by, pm.remark, pm.tech_stock_out_id,
                  pt.id part_id, pt.name pname, pt.stock_code, pt.part_code
           FROM part_movements pm
           JOIN parts pt ON pt.id=pm.part_id
           WHERE pm.ref_asset_id=? AND pm.direction='out'
           ORDER BY pm.moved_at, pm.id", 'i', [$assetId]);

echo "Part movements:\n";
$n = 0;
while ($r = $res->fetch_assoc()) {
    $n++;
    echo sprintf(
        "#%d mid=%d qty=%s stock_code=%s tech_out=%s date=%s by=%s remark=%s\n",
        $n,
        $r['id'],
        $r['qty'],
        $r['stock_code'] ?: '(none)',
        $r['tech_stock_out_id'] ?: '-',
        $r['moved_at'],
        $r['made_by'],
        $r['remark']
    );
}
if (!$n) echo "(none)\n";

// BOM for reference
$bom = qr("SELECT b.qty_per_unit, pt.name, pt.stock_code
            FROM bom_items b JOIN parts pt ON pt.id=b.part_id
            JOIN assets a ON a.product_id=b.product_id
            WHERE a.id=?", 'i', [$assetId]);
echo "\nBOM:\n";
while ($b = $bom->fetch_assoc()) {
    echo "  {$b['stock_code']} | {$b['name']} x {$b['qty_per_unit']}\n";
}
