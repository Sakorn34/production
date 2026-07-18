<?php
/** CLI: ตรวจสอบข้อมูล MA ตาม product_id */
require __DIR__ . '/../../config.php';

$pid = (int)($argv[1] ?? 23);
$p = qr('SELECT id, name FROM products WHERE id=?', 'i', [$pid])->fetch_assoc();
echo "Product: " . json_encode($p, JSON_UNESCAPED_UNICODE) . "\n";
$c = qr('SELECT COUNT(*) c FROM ma_records m JOIN assets a ON a.id=m.asset_id WHERE a.product_id=?', 'i', [$pid])->fetch_assoc();
echo "MA count: {$c['c']}\n";
$res = qr("SELECT m.id, a.asset_code, m.ma_round, m.ok_items, m.replace_items, m.repair_items,
                  LEFT(m.versions_json, 120) vj
           FROM ma_records m JOIN assets a ON a.id=m.asset_id
           WHERE a.product_id=? ORDER BY m.id DESC LIMIT 8", 'i', [$pid]);
while ($r = $res->fetch_assoc()) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
$empty = qr("SELECT COUNT(*) c FROM ma_records m JOIN assets a ON a.id=m.asset_id
             WHERE a.product_id=?
             AND (m.ok_items IS NULL OR TRIM(m.ok_items)='')
             AND (m.replace_items IS NULL OR TRIM(m.replace_items)='')
             AND (m.repair_items IS NULL OR TRIM(m.repair_items)='')
             AND (m.versions_json IS NULL OR TRIM(m.versions_json)='' OR m.versions_json='{}')", 'i', [$pid])->fetch_assoc();
echo "Records with empty status fields: {$empty['c']}\n";
