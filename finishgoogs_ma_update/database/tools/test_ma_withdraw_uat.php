<?php
/**
 * UAT script — ทดสอบ flow เบิกอะไหล่ MA: ตัดสต็อก → rollback → คืนสต็อก
 *
 * รัน: php finishgoogs_ma_update/database/tools/test_ma_withdraw_uat.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/config.php';

$actor = 'uat_ma_withdraw_test';
$errors = [];
$checks = [];

/**
 * บันทึกผลการตรวจ
 *
 * @param string $label
 * @param bool   $ok
 * @param string $detail
 */
function uat_check($label, $ok, $detail = '') {
    global $checks;
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        global $errors;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
}

// หา part ที่มี stock_code และคงเหลือ >= 2
$part = null;
$res = db()->query(
    "SELECT p.id, p.name, p.stock_code FROM parts p
     WHERE p.is_active=1 AND p.stock_code IS NOT NULL AND TRIM(p.stock_code)<>'' LIMIT 200"
);
while ($row = $res->fetch_assoc()) {
    $qty = tech_parts_qty_by_part_id((int)$row['id']);
    if ($qty !== null && $qty >= 2) {
        $part = $row;
        $part['stock_qty'] = $qty;
        break;
    }
}
if (!$part) {
    fwrite(STDERR, "SKIP: ไม่พบอะไหล่ที่มี stock_code และคงเหลือ >= 2\n");
    exit(0);
}

// หา asset ใดก็ได้
$asset = qr("SELECT id, asset_code FROM assets ORDER BY id DESC LIMIT 1")->fetch_assoc();
if (!$asset) {
    fwrite(STDERR, "FAIL: ไม่มี assets ใน DB\n");
    exit(1);
}

$partId = (int)$part['id'];
$assetId = (int)$asset['id'];
$assetCode = (string)$asset['asset_code'];
$beforeQty = (int)$part['stock_qty'];
$testQty = 1.0;

// ตรวจ schema ma_record_id
$col = db()->query("SHOW COLUMNS FROM part_movements LIKE 'ma_record_id'");
uat_check('schema ma_record_id', $col && $col->num_rows > 0);

// สร้าง ma_record ทดสอบ
$round = (int)qr("SELECT COALESCE(MAX(ma_round),0)+1 r FROM ma_records WHERE asset_id=?", 'i', [$assetId])->fetch_assoc()['r'];
q(
    "INSERT INTO ma_records (asset_id,ma_round,visited_at,result,ok_items,replace_items,repair_items,fw_version,remark,done_by)
     VALUES (?,?,CURDATE(),'ok','UAT test',NULL,NULL,NULL,'uat_ma_withdraw',?)",
    'iis',
    [$assetId, $round, $actor]
);
$maRecordId = (int)db()->insert_id;
uat_check('insert ma_records', $maRecordId > 0, "id=$maRecordId");

// เบิก
$w = ma_withdraw_parts($maRecordId, $assetId, $assetCode, [['part_id' => $partId, 'qty' => $testQty]], $actor);
uat_check('ma_withdraw_parts ok', !empty($w['ok']), $w['error'] ?? 'count=' . ($w['count'] ?? 0));

$afterQty = tech_parts_qty_by_part_id($partId);
uat_check('stock decreased', $afterQty === $beforeQty - 1, "before=$beforeQty after=$afterQty");

$movCount = ma_withdrawal_count($maRecordId);
uat_check('part_movements linked', $movCount === 1, "count=$movCount");

$mov = qr(
    "SELECT mode, ref_asset_id, ma_record_id, tech_stock_out_id FROM part_movements
     WHERE ma_record_id=? AND direction='out' LIMIT 1",
    'i',
    [$maRecordId]
)->fetch_assoc();
uat_check('movement mode=MA', ($mov['mode'] ?? '') === 'MA');
uat_check('movement ref_asset_id', (int)($mov['ref_asset_id'] ?? 0) === $assetId);
uat_check('movement tech_stock_out_id', (int)($mov['tech_stock_out_id'] ?? 0) > 0);

$html = ma_parts_withdrawn_html($maRecordId);
uat_check('ma_parts_withdrawn_html', strpos($html, '🔩 เบิก:') !== false);

// rollback
$rb = ma_rollback_withdrawals($maRecordId, $actor);
uat_check('ma_rollback_withdrawals ok', !empty($rb['ok']), $rb['error'] ?? '');

$restoredQty = tech_parts_qty_by_part_id($partId);
uat_check('stock restored', $restoredQty === $beforeQty, "expected=$beforeQty got=$restoredQty");

$movAfter = ma_withdrawal_count($maRecordId);
uat_check('movements deleted after rollback', $movAfter === 0, "count=$movAfter");

// ลบ ma_record ทดสอบ
q("DELETE FROM ma_records WHERE id=?", 'i', [$maRecordId]);

// สรุป
echo "=== MA Withdrawal UAT ===\n";
echo "Part: {$part['name']} (id=$partId, stock_code={$part['stock_code']})\n";
echo "Asset: $assetCode (id=$assetId)\n\n";
foreach ($checks as $c) {
    echo ($c['ok'] ? '[PASS]' : '[FAIL]') . ' ' . $c['label'];
    if ($c['detail'] !== '') {
        echo ' — ' . $c['detail'];
    }
    echo "\n";
}

if ($errors) {
    echo "\nFAILED " . count($errors) . " check(s)\n";
    exit(1);
}
echo "\nALL CHECKS PASSED\n";
exit(0);
