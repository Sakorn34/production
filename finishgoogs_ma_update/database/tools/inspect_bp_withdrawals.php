<?php
require __DIR__ . '/../../config.php';
$pdo = dbParts();
echo "=== stock_out BP260720 ===\n";
$st = $pdo->query("SELECT id, doc_no, asset_code, note, issued_by, created_at FROM stock_out WHERE asset_code LIKE '%BP260720%' OR asset_code LIKE '%26072015%' ORDER BY id");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\n=== production assets BP260720 ===\n";
$res = db()->query("SELECT id, asset_code, produced_at FROM assets WHERE asset_code LIKE 'BP260720%' ORDER BY asset_code");
while ($r = $res->fetch_assoc()) {
    echo "{$r['id']} | {$r['asset_code']} | {$r['produced_at']}\n";
}
echo "\n=== stock_out items 40,41 ===\n";
foreach ([40, 41] as $id) {
    echo "stock_out $id:\n";
    $st = $pdo->prepare('SELECT soi.*, p.code, p.name FROM stock_out_items soi JOIN products p ON p.id = soi.product_id WHERE stock_out_id = ?');
    $st->execute([$id]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$r['code']} | {$r['name']} x{$r['quantity']}\n";
    }
}
echo "\n=== part_movements BP26072015-24 (sample) ===\n";
$res = db()->query("SELECT pm.id, a.asset_code, pt.stock_code, pm.qty, pm.tech_stock_out_id
    FROM part_movements pm
    JOIN assets a ON a.id = pm.ref_asset_id
    JOIN parts pt ON pt.id = pm.part_id
    WHERE a.asset_code >= 'BP26072015' AND a.asset_code <= 'BP26072024' AND pm.direction = 'out'
    ORDER BY a.asset_code, pm.id LIMIT 25");
while ($r = $res->fetch_assoc()) {
    echo implode(' | ', $r) . "\n";
}
$c = db()->query("SELECT COUNT(*) c FROM part_movements pm JOIN assets a ON a.id=pm.ref_asset_id WHERE a.asset_code >= 'BP26072015' AND a.asset_code <= 'BP26072024' AND pm.direction='out'")->fetch_assoc();
echo "total movements: {$c['c']}\n";
