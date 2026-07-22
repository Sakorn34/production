<?php
/**
 * includes/part_stock_bridge.php — เชื่อมสต็อกอะไหล่กับ biton_tech_parts โดยตรง
 *
 * วัตถุประสงค์: แทน webhook — อ่าน/เบิก/คืนที่ `biton_tech_parts.products.quantity`
 * Mapping: `biton_production.parts.stock_code` = `biton_tech_parts.products.code`
 * (fallback: part_code → name ถ้ายังไม่ได้ตั้ง stock_code)
 *
 * Flow ตัวอย่าง:
 *   tech_parts_stock_out_by_part_id($partId, 1, 'ผลิต', 'Tom');
 *   → INSERT part_movements ใน production DB (ทำที่ caller)
 */

// ─ Schema ─────────────────────────────────────────────────────────────────────

/**
 * เพิ่มคอลัมน์ stock_code ใน parts ถ้ายังไม่มี
 *
 * @return void
 */
function ensure_parts_stock_code_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $res = db()->query("SHOW COLUMNS FROM parts LIKE 'stock_code'");
    if ($res && $res->num_rows === 0) {
        db()->query('ALTER TABLE parts ADD COLUMN stock_code VARCHAR(50) NULL DEFAULT NULL AFTER part_code');
    }
    $res2 = db()->query("SHOW COLUMNS FROM part_movements LIKE 'tech_stock_out_id'");
    if ($res2 && $res2->num_rows === 0) {
        db()->query('ALTER TABLE part_movements ADD COLUMN tech_stock_out_id INT UNSIGNED NULL DEFAULT NULL AFTER remark');
    }
    $res3 = db()->query("SHOW COLUMNS FROM part_movements LIKE 'ma_record_id'");
    if ($res3 && $res3->num_rows === 0) {
        db()->query('ALTER TABLE part_movements ADD COLUMN ma_record_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER ref_asset_id');
    }
}

ensure_parts_stock_code_schema();

$__partSyncPath = dirname(__DIR__, 2) . '/parts/includes/production_sync.php';
if (is_file($__partSyncPath)) {
    require_once $__partSyncPath;
}
$__helpersPath = dirname(__DIR__, 2) . '/parts/includes/helpers.php';
if (is_file($__helpersPath)) {
    require_once $__helpersPath;
}

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * แปลง qty จาก finishgoogs (float) เป็น int สำหรับ tech_parts
 *
 * @param float $qty
 * @return int
 */
function tech_parts_qty_to_int($qty) {
    $q = (float)$qty;
    if ($q <= 0) {
        return 0;
    }
    return (int)round($q);
}

/**
 * ดึงรหัสอะไหล่จาก part_id ใน production DB
 *
 * @param int $partId
 * @return string|null
 */
function part_resolve_code($partId) {
    $row = qr('SELECT stock_code, part_code, name FROM parts WHERE id=?', 'i', [(int)$partId])->fetch_assoc();
    if (!$row) {
        return null;
    }
    $stock = trim((string)($row['stock_code'] ?? ''));
    if ($stock !== '') {
        return $stock;
    }
    $code = trim((string)($row['part_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $name = trim((string)($row['name'] ?? ''));
    return $name !== '' ? $name : null;
}

/**
 * คืน stock_code จากแถว parts (ไม่ fallback)
 *
 * @param array<string,mixed> $row
 * @return string
 */
function part_row_stock_code(array $row) {
    return trim((string)($row['stock_code'] ?? ''));
}

/**
 * อ่านจำนวนคงเหลือจาก biton_tech_parts ตาม part_id
 *
 * @param int $partId
 * @return int|null null ถ้าไม่มีรหัสหรือไม่พบใน tech_parts
 */
function tech_parts_qty_by_part_id($partId) {
    $code = part_resolve_code($partId);
    if ($code === null) {
        return null;
    }
    return tech_parts_qty_by_code($code);
}

/**
 * อ่านจำนวนคงเหลือจาก biton_tech_parts ตาม product code
 *
 * @param string $code
 * @return int|null null ถ้าไม่พบ
 */
function tech_parts_qty_by_code($code) {
    $code = trim((string)$code);
    if ($code === '') {
        return null;
    }
    $st = dbParts()->prepare('SELECT quantity FROM products WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ? (int)$row['quantity'] : null;
}

/**
 * ดึงข้อมูล product จาก tech_parts ตาม code
 *
 * @param string $code
 * @return array<string,mixed>|null
 */
function tech_parts_product_by_code($code) {
    $code = trim((string)$code);
    if ($code === '') {
        return null;
    }
    $st = dbParts()->prepare('SELECT * FROM products WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    $st = dbParts()->prepare('SELECT * FROM products WHERE name = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    $st = dbParts()->prepare('SELECT * FROM products WHERE ? LIKE CONCAT(name, "%") ORDER BY CHAR_LENGTH(name) DESC LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ?: null;
}

// ─ Stock out / in ─────────────────────────────────────────────────────────────

/**
 * เบิกอะไหล่จาก biton_tech_parts (transaction ภายใน DB เดียว)
 *
 * @param string $productCode รหัส products.code
 * @param int    $quantity    จำนวน (int)
 * @param string $purpose     หมายเหตุ/วัตถุประสงค์
 * @param string      $issuedBy    ผู้เบิก
 * @param string|null $assetCode   S/N สินค้า (optional)
 * @return array{ok:bool, doc_no?:string, stock_out_id?:int, error?:string}
 */
function tech_parts_stock_out($productCode, $quantity, $purpose, $issuedBy, $assetCode = null) {
    $quantity = (int)$quantity;
    if ($quantity <= 0) {
        return ['ok' => false, 'error' => 'จำนวนเบิกต้องมากกว่า 0'];
    }

    $product = tech_parts_product_by_code($productCode);
    if (!$product) {
        return ['ok' => false, 'error' => 'ไม่พบอะไหล่ในระบบสต็อก รหัส: ' . $productCode];
    }
    if ((int)$product['quantity'] < $quantity) {
        return [
            'ok'    => false,
            'error' => 'อะไหล่ ' . $product['name'] . ' คงเหลือไม่พอ (ต้องการ ' . $quantity . ' ' . $product['unit'] . ', มี ' . (int)$product['quantity'] . ')',
        ];
    }

    $pdo = dbParts();
    if (function_exists('ensure_stock_production_sync_schema')) {
        ensure_stock_production_sync_schema($pdo);
    }

    $sn = trim((string) $assetCode);

    try {
        $pdo->beginTransaction();

        if ($sn !== '' && function_exists('production_get_or_create_stock_out_for_sn')) {
            $outId = production_get_or_create_stock_out_for_sn(
                $pdo,
                $sn,
                $purpose,
                $issuedBy !== '' ? $issuedBy : 'finishgoogs',
                null,
                true
            );
            production_stock_out_add_item($pdo, $outId, (int) $product['id'], $quantity, 0, true);
            $docSt = $pdo->prepare('SELECT doc_no FROM stock_out WHERE id = ?');
            $docSt->execute([$outId]);
            $docNo = (string) ($docSt->fetchColumn() ?: '');
            $pdo->commit();
            return ['ok' => true, 'doc_no' => $docNo, 'stock_out_id' => $outId];
        }

        if (!function_exists('generateDocNo')) {
            throw new RuntimeException('generateDocNo ไม่พร้อมใช้งาน');
        }
        $docNo = generateDocNo($pdo);

        $st = $pdo->prepare('INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code, stock_deducted) VALUES (?, NULL, ?, ?, ?, 1)');
        $st->execute([$docNo, $purpose, $issuedBy !== '' ? $issuedBy : 'finishgoogs', $sn !== '' ? $sn : null]);
        $outId = (int) $pdo->lastInsertId();

        $st = $pdo->prepare('INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)');
        $st->execute([$outId, (int) $product['id'], $quantity]);

        $st = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');
        $st->execute([$quantity, (int) $product['id']]);

        $pdo->commit();
        return ['ok' => true, 'doc_no' => $docNo, 'stock_out_id' => $outId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[tech_parts_stock_out] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'เบิกสต็อกไม่สำเร็จ — ' . $e->getMessage()];
    }
}

/**
 * รับคืน/เพิ่มสต็อกใน biton_tech_parts (reverse การเบิก)
 *
 * @param string $productCode
 * @param int    $quantity
 * @param string $note
 * @param string $receivedBy
 * @return array{ok:bool, error?:string}
 */
function tech_parts_stock_in($productCode, $quantity, $note, $receivedBy) {
    $quantity = (int)$quantity;
    if ($quantity <= 0) {
        return ['ok' => false, 'error' => 'จำนวนคืนต้องมากกว่า 0'];
    }

    $product = tech_parts_product_by_code($productCode);
    if (!$product) {
        return ['ok' => false, 'error' => 'ไม่พบอะไหล่ในระบบสต็อก รหัส: ' . $productCode];
    }

    $pdo = dbParts();
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('INSERT INTO stock_in (product_id, quantity, note, received_by) VALUES (?, ?, ?, ?)');
        $st->execute([(int)$product['id'], $quantity, $note, $receivedBy !== '' ? $receivedBy : 'finishgoogs']);

        $st = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
        $st->execute([$quantity, (int)$product['id']]);

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[tech_parts_stock_in] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'คืนสต็อกไม่สำเร็จ — ' . $e->getMessage()];
    }
}

/**
 * เบิกอะไหล่ตาม part_id ใน production catalog
 *
 * @param int    $partId
 * @param float  $qtyFloat
 * @param string $purpose
 * @param string $issuedBy
 * @return array{ok:bool, doc_no?:string, stock_out_id?:int, error?:string, code?:string, qty?:int}
 */
function tech_parts_stock_out_by_part_id($partId, $qtyFloat, $purpose, $issuedBy, $assetCode = null) {
    $code = part_resolve_code($partId);
    if ($code === null) {
        return ['ok' => false, 'error' => 'ไม่พบรหัสอะไหล่ (part_id=' . (int)$partId . ')'];
    }
    $qty = tech_parts_qty_to_int($qtyFloat);
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'จำนวนเบิกต้องมากกว่า 0'];
    }
    $res = tech_parts_stock_out($code, $qty, $purpose, $issuedBy, $assetCode);
    $res['code'] = $code;
    $res['qty'] = $qty;
    return $res;
}

/**
 * คืนอะไหล่ตาม part_id
 *
 * @param int    $partId
 * @param float  $qtyFloat
 * @param string $note
 * @param string $receivedBy
 * @return array{ok:bool, error?:string}
 */
function tech_parts_stock_in_by_part_id($partId, $qtyFloat, $note, $receivedBy) {
    $code = part_resolve_code($partId);
    if ($code === null) {
        return ['ok' => false, 'error' => 'ไม่พบรหัสอะไหล่ (part_id=' . (int)$partId . ')'];
    }
    $qty = tech_parts_qty_to_int($qtyFloat);
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'จำนวนคืนต้องมากกว่า 0'];
    }
    return tech_parts_stock_in($code, $qty, $note, $receivedBy);
}

/**
 * ปรับสต็ock tech_parts เมื่อแก้จำนวน movement (diff = ใหม่ - เก่า, บวก = เบิกเพิ่ม)
 *
 * @param int   $partId
 * @param float $diffQty
 * @param string $issuedBy
 * @param string $purpose
 * @return array{ok:bool, error?:string}
 */
function tech_parts_adjust_by_part_id($partId, $diffQty, $issuedBy, $purpose = 'ปรับรายการเบิก', $movementId = 0) {
    $qty = tech_parts_qty_to_int(abs((float)$diffQty));
    if ($qty <= 0) {
        return ['ok' => true];
    }
    if ($movementId > 0 && function_exists('production_sync_stock_out_from_movement')) {
        $row = qr('SELECT qty, remark, tech_stock_out_id FROM part_movements WHERE id=?', 'i', [$movementId])->fetch_assoc();
        if ($row && !empty($row['tech_stock_out_id'])) {
            $newQty = (float)$row['qty'] + (float)$diffQty;
            if ($newQty <= 0) {
                return ['ok' => false, 'error' => 'จำนวนหลังแก้ไขต้องมากกว่า 0'];
            }
            $assetRow = qr('SELECT a.asset_code FROM part_movements pm LEFT JOIN assets a ON a.id=pm.ref_asset_id WHERE pm.id=?', 'i', [$movementId])->fetch_assoc();
            $ok = production_sync_stock_out_from_movement($movementId, $newQty, $assetRow['asset_code'] ?? null, $row['remark'] ?? $purpose);
            return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'sync stock_out ไม่สำเร็จ'];
        }
    }
    if ((float)$diffQty > 0) {
        return tech_parts_stock_out_by_part_id($partId, $qty, $purpose, $issuedBy);
    }
    return tech_parts_stock_in_by_part_id($partId, $qty, $purpose, $issuedBy);
}

/**
 * โหลด quantity จาก tech_parts เป็น map code => qty
 *
 * @param array<int,array<string,mixed>> $partRows
 * @return array<string,int>
 */
function tech_parts_qty_map_for_parts(array $partRows) {
    $codes = [];
    foreach ($partRows as $r) {
        $c = part_row_stock_code($r);
        if ($c === '') {
            $c = trim((string)($r['part_code'] ?? ''));
        }
        if ($c === '') {
            $c = trim((string)($r['name'] ?? ''));
        }
        if ($c !== '') {
            $codes[$c] = true;
        }
    }
    if (!$codes) {
        return [];
    }
    $list = array_keys($codes);
    $placeholders = implode(',', array_fill(0, count($list), '?'));
    $st = dbParts()->prepare('SELECT code, quantity FROM products WHERE code IN (' . $placeholders . ')');
    $st->execute($list);
    $map = [];
    while ($row = $st->fetch()) {
        $map[(string)$row['code']] = (int)$row['quantity'];
    }
    return $map;
}

// ─ Asset parts status (production UI) ────────────────────────────────────────

/**
 * URL ฐานของแอป parts (biton_tech_parts)
 *
 * @return string
 */
function parts_app_base_url() {
    return str_replace('/finishgoogs_ma_update', '/parts', rtrim(BASE_URL, '/'));
}

/**
 * นับใบเบิกใน stock_out (parts) ตาม S/N — ใช้ gate การแสดงสถานะบน assets.php
 *
 * @param array<int, string> $assetCodes
 * @return array<string, int> sn => จำนวนใบเบิก
 */
function parts_stock_out_counts_by_sn(array $assetCodes): array {
    $unique = [];
    foreach ($assetCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $unique[$code] = true;
        }
    }
    if (!$unique) {
        return [];
    }
    $list = array_keys($unique);
    try {
        $pdo = dbParts();
        $ph = implode(',', array_fill(0, count($list), '?'));
        $st = $pdo->prepare(
            "SELECT TRIM(asset_code) AS sn, COUNT(*) AS cnt
             FROM stock_out
             WHERE TRIM(asset_code) IN ($ph)
             GROUP BY TRIM(asset_code)"
        );
        $st->execute($list);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$row['sn']] = (int)$row['cnt'];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[parts_stock_out_counts_by_sn] ' . $e->getMessage());
        return [];
    }
}

/**
 * ตรวจว่า S/N มีในประวัติเบิกอะไหล่ (parts stock_out) หรือไม่
 *
 * @param string $assetCode
 * @return bool
 */
function asset_has_parts_stock_out_history(string $assetCode): bool {
    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        return false;
    }
    $counts = parts_stock_out_counts_by_sn([$assetCode]);
    return isset($counts[$assetCode]) && $counts[$assetCode] > 0;
}

/**
 * คำนวณสถานะเบิกอะไหล่จากจำนวน movement / stock_out / BOM (ใช้ใน assets.php)
 *
 * @param int $movementCount  part_movements out ของเครื่อง
 * @param int $stockOutCount  stock_out ใน Parts ที่มี asset_code = SN
 * @param int $bomCount
 * @param int $bomWithdrawn
 * @return array{status:string,label:string,sync_state:string}
 */
function asset_parts_withdraw_row_status(int $movementCount, int $stockOutCount, int $bomCount, int $bomWithdrawn): array
{
    if ($movementCount > 0 && $stockOutCount > 0) {
        if ($bomCount > 0 && $bomWithdrawn < $bomCount) {
            return [
                'status'     => 'partial',
                'label'      => "เบิกแล้ว {$bomWithdrawn}/{$bomCount}",
                'sync_state' => 'synced',
            ];
        }
        return [
            'status'     => 'done',
            'label'      => "เบิกแล้ว {$movementCount} รายการ",
            'sync_state' => 'synced',
        ];
    }
    if ($movementCount > 0 && $stockOutCount === 0) {
        return [
            'status'     => 'movement_only',
            'label'      => 'มีบันทึกผลิต (ยังไม่ sync Stock)',
            'sync_state' => 'movement_only',
        ];
    }
    if ($movementCount === 0 && $stockOutCount > 0) {
        return [
            'status'     => 'stock_only',
            'label'      => 'มีเบิก Parts (ยังไม่ sync Production)',
            'sync_state' => 'stock_only',
        ];
    }
    if ($bomCount > 0) {
        return ['status' => 'pending', 'label' => 'ยังไม่เบิก', 'sync_state' => 'none'];
    }
    return ['status' => 'none', 'label' => '—', 'sync_state' => 'none'];
}

/**
 * URL หน้า parts.php (production) กรองตาม S/N
 *
 * @param string $assetCode
 * @return string
 */
function asset_parts_production_url(string $assetCode): string
{
    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        return BASE_URL . '/parts.php';
    }
    return BASE_URL . '/parts.php?rs=' . rawurlencode($assetCode);
}

/**
 * สรุปสถานะการเบิกอะไหล่ของเครื่อง — ใช้แสดงใน assets.php / asset.php
 *
 * sync_state: none | movement_only | stock_only | synced
 *
 * @param int  $assetId
 * @param bool $withBomQtyDetail  true = คำนวณ BOM qty-level (ช้า — ใช้เฉพาะหน้า asset เดี่ยว)
 * @return array<string,mixed>
 */
function asset_parts_withdraw_summary($assetId, $withBomQtyDetail = false) {
    $assetId = (int)$assetId;
    $asset = qr('SELECT product_id, asset_code FROM assets WHERE id=?', 'i', [$assetId])->fetch_assoc();
    if (!$asset) {
        return [
            'out_count' => 0, 'bom_count' => 0, 'bom_withdrawn' => 0,
            'status' => 'none', 'label' => '—', 'sync_state' => 'none',
            'movements' => [], 'stock_docs' => [], 'stock_out_count' => 0,
            'in_parts_history' => false, 'show_section' => false,
            'bom_match' => 'none', 'bom_qty_ok' => true,
            'bom_extra_parts' => 0, 'bom_missing_parts' => 0,
        ];
    }

    $bomCount = (int)qr(
        'SELECT COUNT(*) c FROM bom_items WHERE product_id=?',
        'i', [(int)$asset['product_id']]
    )->fetch_assoc()['c'];

    $sn = trim((string)($asset['asset_code'] ?? ''));
    $stockOutCount = 0;
    if ($sn !== '') {
        $counts = parts_stock_out_counts_by_sn([$sn]);
        $stockOutCount = (int)($counts[$sn] ?? 0);
    }

    $outCount = (int)qr(
        "SELECT COUNT(*) c FROM part_movements WHERE ref_asset_id=? AND direction='out'",
        'i', [$assetId]
    )->fetch_assoc()['c'];

    $bomWithdrawn = 0;
    if ($bomCount > 0) {
        $bomWithdrawn = (int)qr(
            "SELECT COUNT(DISTINCT pm.part_id) c
             FROM part_movements pm
             INNER JOIN bom_items b ON b.part_id=pm.part_id
             WHERE pm.ref_asset_id=? AND pm.direction='out' AND b.product_id=?",
            'ii', [$assetId, (int)$asset['product_id']]
        )->fetch_assoc()['c'];
    }

    $rowStatus = asset_parts_withdraw_row_status($outCount, $stockOutCount, $bomCount, $bomWithdrawn);

    $bomMatch = 'none';
    $bomQtyOk = true;
    $bomExtraParts = 0;
    $bomMissingParts = 0;
    if ($bomCount > 0 && $withBomQtyDetail && function_exists('production_bom_match_status')) {
        $bomSt = production_bom_match_status($assetId, (int) $asset['product_id'], $sn);
        $bomMatch = $bomSt['bom_match'];
        $bomQtyOk = (bool) $bomSt['bom_qty_ok'];
        $bomExtraParts = (int) $bomSt['bom_extra_parts'];
        $bomMissingParts = (int) $bomSt['bom_missing_parts'];
    }

    $linkedOutCount = 0;
    if ($outCount > 0) {
        $linkedOutCount = (int) qr(
            "SELECT COUNT(*) c FROM part_movements
             WHERE ref_asset_id=? AND direction='out'
               AND tech_stock_out_id IS NOT NULL AND tech_stock_out_id > 0",
            'i',
            [$assetId]
        )->fetch_assoc()['c'];
        $wst = asset_withdraw_list_sync_status_from_counts($outCount, $stockOutCount, $linkedOutCount);
        $rowStatus['label'] = $wst['label'];
        $rowStatus['status'] = $wst['match'] === 'ok' ? 'done' : ($wst['match'] === 'pending' ? 'partial' : $rowStatus['status']);
    }

    $movements = [];
    if ($outCount > 0) {
        $res = qr(
            "SELECT pm.id, pm.qty, pm.moved_at, pm.remark, pm.mode, pm.ma_record_id,
                    pm.tech_stock_out_id, pm.made_by,
                    pt.name pname, pt.part_code, pt.unit, pt.stock_code
             FROM part_movements pm
             JOIN parts pt ON pt.id=pm.part_id
             WHERE pm.ref_asset_id=? AND pm.direction='out'
             ORDER BY pm.moved_at DESC, pm.id DESC",
            'i', [$assetId]
        );
        while ($r = $res->fetch_assoc()) {
            $movements[] = $r;
        }
    }

    $stockDocs = [];
    $ids = [];
    foreach ($movements as $m) {
        $sid = (int)($m['tech_stock_out_id'] ?? 0);
        if ($sid > 0) {
            $ids[$sid] = true;
        }
    }
    if ($ids) {
        $idList = array_keys($ids);
        $ph = implode(',', array_fill(0, count($idList), '?'));
        try {
            $st = dbParts()->prepare(
                "SELECT id, doc_no, asset_code, note, issued_by, created_at FROM stock_out WHERE id IN ($ph)"
            );
            $st->execute($idList);
            while ($row = $st->fetch()) {
                $stockDocs[(int)$row['id']] = $row;
            }
        } catch (Throwable $e) {
            error_log('[asset_parts_withdraw_summary] ' . $e->getMessage());
        }
    }

    $showSection = $outCount > 0 || $stockOutCount > 0 || $bomCount > 0;

    return [
        'out_count'          => $outCount,
        'bom_count'          => $bomCount,
        'bom_withdrawn'      => $bomWithdrawn,
        'stock_out_count'    => $stockOutCount,
        'status'             => $rowStatus['status'],
        'label'              => $rowStatus['label'],
        'sync_state'         => $rowStatus['sync_state'],
        'asset_code'         => $sn,
        'movements'          => $movements,
        'stock_docs'         => $stockDocs,
        'in_parts_history'   => $stockOutCount > 0,
        'show_section'       => $showSection,
        'production_url'     => asset_parts_production_url($sn),
        'bom_match'          => $bomMatch,
        'bom_qty_ok'         => $bomQtyOk,
        'bom_extra_parts'    => $bomExtraParts,
        'bom_missing_parts'  => $bomMissingParts,
        'linked_out_count'   => $linkedOutCount,
        'withdraw_list_sync' => $outCount > 0
            ? asset_withdraw_list_sync_status_from_counts($outCount, $stockOutCount, $linkedOutCount)
            : ['match' => 'none', 'linked' => 0, 'total' => 0, 'label' => '—', 'needs_sync' => false],
    ];
}

/**
 * ตรวจว่าเครื่องนี้ควรมีปุ่ม Sync Stock (ยังมี movement ที่ไม่ผูกใบเบิก)
 *
 * @param array<string,mixed> $summary จาก asset_parts_withdraw_summary()
 * @return bool
 */
function asset_parts_needs_stock_sync(array $summary) {
    if ((int)($summary['out_count'] ?? 0) <= 0) {
        return false;
    }
    if (($summary['sync_state'] ?? '') === 'movement_only') {
        return true;
    }
    foreach ($summary['movements'] ?? [] as $mv) {
        if (empty($mv['tech_stock_out_id'])) {
            return true;
        }
    }
    return false;
}

/**
 * Sync เบิกอะไหล่ของเครื่อง → ผูก/สร้าง stock_out ใน Parts (ไม่หักสต็ockซ้ำ)
 *
 * @param int $assetId
 * @return array{ok:bool,linked:int,created:int,repaired:int,skipped:int,failed:int,errors:array<int,string>,message?:string}
 */
function asset_sync_parts_to_stock($assetId) {
    if (!function_exists('production_sync_asset_withdrawals')) {
        return ['ok' => false, 'linked' => 0, 'created' => 0, 'repaired' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['ระบบ sync ไม่พร้อม'], 'message' => 'ระบบ sync ไม่พร้อม'];
    }
    $r = production_sync_asset_withdrawals((int) $assetId);
    $parts = [];
    if ($r['repaired'] > 0) {
        $parts[] = 'ซ่อมลิงก์ ' . $r['repaired'];
    }
    if ($r['linked'] > 0) {
        $parts[] = 'ผูกใบเบิก ' . $r['linked'];
    }
    if ($r['created'] > 0) {
        $parts[] = 'สร้างใบเบิก ' . $r['created'];
    }
    if ($r['skipped'] > 0) {
        $parts[] = 'ข้าม ' . $r['skipped'];
    }
    if ($parts !== []) {
        $r['message'] = implode(' · ', $parts);
    } elseif ($r['failed'] === 0) {
        $r['message'] = 'Sync ครบแล้ว — ไม่มีรายการที่ต้องผูก';
    }
    return $r;
}

/**
 * รายการ asset.id ที่มี movement ยังไม่ผูก stock_out (ตาม filter เดียวกับ assets.php)
 *
 * @param string $whereSql ข้อความ WHERE ... หรือว่าง
 * @param string $types    bind types
 * @param array<int|string> $params
 * @return array<int, int>
 */
function asset_ids_needing_stock_sync($whereSql, $types, array $params) {
    $sql = "SELECT DISTINCT a.id
            FROM assets a
            JOIN products p ON p.id=a.product_id
            INNER JOIN part_movements pm ON pm.ref_asset_id=a.id
              AND pm.direction='out'
              AND (pm.tech_stock_out_id IS NULL OR pm.tech_stock_out_id = 0)
            $whereSql
            ORDER BY a.id";
    $res = qr($sql, $types, $params);
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/**
 * Sync เบิกอะไหล่หลายเครื่อง → ผูก/สร้าง stock_out ใน Parts
 *
 * @param array<int, int> $assetIds
 * @return array{ok:bool,assets:int,linked:int,created:int,repaired:int,skipped:int,failed:int,errors:array<int,string>,message?:string}
 */
function asset_sync_parts_bulk(array $assetIds) {
    $empty = [
        'ok' => false, 'assets' => 0, 'linked' => 0, 'created' => 0,
        'repaired' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [],
    ];
    if (!function_exists('production_sync_asset_withdrawals')) {
        $empty['errors'][] = 'ระบบ sync ไม่พร้อม';
        $empty['message'] = 'ระบบ sync ไม่พร้อม';
        return $empty;
    }
    $assetIds = array_values(array_filter(array_map('intval', $assetIds), function ($id) {
        return $id > 0;
    }));
    if ($assetIds === []) {
        $empty['ok'] = true;
        $empty['message'] = 'ไม่มีเครื่องที่ต้อง Sync';
        return $empty;
    }

    if (function_exists('production_sync_stock_codes_once')) {
        production_sync_stock_codes_once(false);
    } elseif (function_exists('production_sync_stock_codes')) {
        production_sync_stock_codes(false);
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $tot = [
        'ok' => true, 'assets' => 0, 'linked' => 0, 'created' => 0,
        'repaired' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [],
    ];
    foreach ($assetIds as $id) {
        $r = production_sync_asset_withdrawals($id);
        $tot['assets']++;
        $tot['linked'] += (int) $r['linked'];
        $tot['created'] += (int) $r['created'];
        $tot['repaired'] += (int) $r['repaired'];
        $tot['skipped'] += (int) $r['skipped'];
        $tot['failed'] += (int) $r['failed'];
        if (!empty($r['errors'])) {
            $tot['errors'] = array_merge($tot['errors'], $r['errors']);
        }
        if (!$r['ok'] && $tot['linked'] === 0 && $tot['created'] === 0 && $tot['repaired'] === 0) {
            $tot['ok'] = false;
        }
    }
    if (count($tot['errors']) > 8) {
        $tot['errors'] = array_slice($tot['errors'], 0, 8);
    }

    $parts = [];
    $parts[] = $tot['assets'] . ' เครื่อง';
    if ($tot['repaired'] > 0) {
        $parts[] = 'ซ่อมลิงก์ ' . $tot['repaired'];
    }
    if ($tot['linked'] > 0) {
        $parts[] = 'ผูกใบเบิก ' . $tot['linked'];
    }
    if ($tot['created'] > 0) {
        $parts[] = 'สร้างใบเบิก ' . $tot['created'];
    }
    if ($tot['skipped'] > 0) {
        $parts[] = 'ข้าม ' . $tot['skipped'];
    }
    if ($tot['failed'] > 0) {
        $parts[] = 'ผิดพลาด ' . $tot['failed'];
    }
    $tot['message'] = implode(' · ', $parts);

    return $tot;
}

/**
 * HTML badge สถานะเบิกอะไหล่ (ใช้ในตาราง assets)
 *
 * @param array<string,mixed> $summary จาก asset_parts_withdraw_summary ย่อ
 * @return string
 */
function asset_parts_status_badge(array $summary) {
    $status = $summary['status'] ?? 'none';
    $label = h($summary['label'] ?? '—');
    if ($status === 'none') {
        return '<span class="muted">—</span>';
    }
    if ($status === 'movement_only' || $status === 'stock_only') {
        return '<span class="parts-status parts-status-' . h($status) . '" title="'
            . h($status === 'movement_only' ? 'มี part_movements แต่ยังไม่มี stock_out ใน Parts' : 'มี stock_out แต่ยังไม่มี part_movements')
            . '">' . $label . '</span>';
    }
    return '<span class="parts-status parts-status-' . h($status) . '">' . $label . '</span>';
}

// ─ MA spare parts withdrawal ───────────────────────────────────────────────────

/**
 * แปลง POST ma_part_id[] / ma_part_qty[] เป็นรายการเบิกที่ไม่ซ้ำ part_id
 *
 * @param array<int|string> $partIds
 * @param array<int|string> $qtys
 * @return array<int,array{part_id:int,qty:float}>
 */
function ma_parse_withdraw_lines(array $partIds, array $qtys) {
    $lines = [];
    $seen = [];
    foreach ($partIds as $i => $partId) {
        $partId = (int)$partId;
        if ($partId <= 0 || isset($seen[$partId])) {
            continue;
        }
        $qty = max(0.01, (float)($qtys[$i] ?? 1));
        $lines[] = ['part_id' => $partId, 'qty' => $qty];
        $seen[$partId] = true;
    }
    return $lines;
}

/**
 * นับรายการเบิกที่ผูกกับรอบ MA
 *
 * @param int $maRecordId
 * @return int
 */
function ma_withdrawal_count($maRecordId) {
    $maRecordId = (int)$maRecordId;
    if ($maRecordId <= 0) {
        return 0;
    }
    return (int)qr(
        "SELECT COUNT(*) c FROM part_movements WHERE ma_record_id=? AND direction='out'",
        'i',
        [$maRecordId]
    )->fetch_assoc()['c'];
}

/**
 * เบิกอะไหล่สำหรับรอบ MA — ตัดสต็อก tech_parts + INSERT part_movements
 *
 * @param int    $maRecordId
 * @param int    $assetId
 * @param string $assetCode
 * @param array<int,array{part_id:int,qty:float}> $lines
 * @param string $actor
 * @return array{ok:bool, count?:int, error?:string}
 */
function ma_withdraw_parts($maRecordId, $assetId, $assetCode, array $lines, $actor) {
    $maRecordId = (int)$maRecordId;
    $assetId = (int)$assetId;
    if ($maRecordId <= 0 || !$lines) {
        return ['ok' => true, 'count' => 0];
    }

    $deducted = [];
    foreach ($lines as $line) {
        $partId = (int)$line['part_id'];
        $qty = (float)$line['qty'];
        $out = tech_parts_stock_out_by_part_id($partId, $qty, 'MA', $actor, $assetCode);
        if (!$out['ok']) {
            foreach (array_reverse($deducted) as $rev) {
                tech_parts_stock_in_by_part_id($rev['part_id'], $rev['qty'], 'ยกเลิกเบิก MA', $actor);
            }
            return ['ok' => false, 'error' => $out['error']];
        }

        q(
            "INSERT INTO part_movements (part_id,moved_at,direction,qty,mode,ref_asset_id,ma_record_id,made_by,remark,tech_stock_out_id)
             VALUES (?,NOW(),'out',?,'MA',?,?,?,NULL,?)",
            'idiisi',
            [
                $partId,
                $qty,
                $assetId,
                $maRecordId,
                $actor,
                (int)($out['stock_out_id'] ?? 0),
            ]
        );
        $mid = (int)db()->insert_id;
        if (!empty($out['stock_out_id']) && function_exists('production_link_stock_out')) {
            production_link_stock_out(dbParts(), (int)$out['stock_out_id'], $mid, $assetCode !== '' ? $assetCode : null);
        }
        $deducted[] = [
            'part_id' => $partId,
            'qty'     => (int)($out['qty'] ?? tech_parts_qty_to_int($qty)),
        ];
    }

    return ['ok' => true, 'count' => count($deducted)];
}

/**
 * คืนสต็อกและลบ part_movements ที่ผูกกับรอบ MA
 *
 * @param int    $maRecordId
 * @param string $actor
 * @return array{ok:bool, error?:string}
 */
function ma_rollback_withdrawals($maRecordId, $actor) {
    $maRecordId = (int)$maRecordId;
    if ($maRecordId <= 0) {
        return ['ok' => true];
    }

    $res = qr(
        "SELECT id, part_id, qty, tech_stock_out_id FROM part_movements
         WHERE ma_record_id=? AND direction='out' ORDER BY id DESC",
        'i',
        [$maRecordId]
    );
    while ($row = $res->fetch_assoc()) {
        $mid = (int)$row['id'];
        $synced = false;
        if (!empty($row['tech_stock_out_id']) && function_exists('production_sync_delete_stock_out_from_movement')) {
            $synced = production_sync_delete_stock_out_from_movement($mid);
        }
        if (!$synced) {
            $ret = tech_parts_stock_in_by_part_id(
                (int)$row['part_id'],
                (float)$row['qty'],
                'ลบรายการเบิก MA',
                $actor
            );
            if (!$ret['ok']) {
                return ['ok' => false, 'error' => $ret['error']];
            }
            q("DELETE FROM part_movements WHERE id=?", 'i', [$mid]);
        }
    }

    return ['ok' => true];
}

/**
 * ลบ part_movement (out) พร้อมคืนสต็ock tech_parts
 *
 * @param int    $movementId
 * @param string $actor
 * @return array{ok:bool, error?:string}
 */
function production_delete_out_movement_with_stock(int $movementId, string $actor): array
{
    if ($movementId <= 0) {
        return ['ok' => true];
    }
    $old = qr(
        "SELECT part_id, qty, tech_stock_out_id FROM part_movements WHERE id=? AND direction='out'",
        'i',
        [$movementId]
    )->fetch_assoc();
    if (!$old) {
        return ['ok' => true];
    }

    $synced = false;
    if (!empty($old['tech_stock_out_id']) && function_exists('production_sync_delete_stock_out_from_movement')) {
        $synced = production_sync_delete_stock_out_from_movement($movementId);
    }
    if (!$synced) {
        $ret = tech_parts_stock_in_by_part_id(
            (int) $old['part_id'],
            (float) $old['qty'],
            'ลบรายการเบิก',
            $actor
        );
        if (!$ret['ok']) {
            return $ret;
        }
        q("DELETE FROM part_movements WHERE id=?", 'i', [$movementId]);
    }
    return ['ok' => true];
}

/**
 * แก้ไขรายการเบิก (out) และ sync กลับ biton_tech_parts
 *
 * @param int         $movementId
 * @param float       $qty
 * @param string|null $assetCode S/N
 * @param string|null $remark
 * @param string      $actor
 * @param string|null $mode ประเภทการเบิก (เบิกใช้ / เบิกอัตโนมัติ / MA)
 * @return array{ok:bool, error?:string}
 */
function production_edit_out_movement(
    int $movementId,
    float $qty,
    ?string $assetCode,
    ?string $remark,
    string $actor,
    ?string $mode = null
): array {
    $qty = max(0.01, $qty);
    $old = qr(
        "SELECT part_id, qty, ref_asset_id, tech_stock_out_id, mode FROM part_movements WHERE id=? AND direction='out'",
        'i',
        [$movementId]
    )->fetch_assoc();
    if (!$old) {
        return ['ok' => false, 'error' => 'ไม่พบรายการที่จะแก้ไข'];
    }

    $refId = null;
    $code = trim((string) $assetCode);
    if ($code !== '') {
        $a = qr('SELECT id FROM assets WHERE asset_code=? OR factory_serial=?', 'ss', [$code, $code])->fetch_assoc();
        if ($a) {
            $refId = (int) $a['id'];
        }
    }
    $remark = trim((string) $remark) ?: null;
    $modeStored = $mode !== null && trim($mode) !== ''
        ? trim($mode)
        : trim((string) ($old['mode'] ?? ''));
    if ($modeStored === '') {
        $modeStored = 'เบิกใช้';
    }
    $diff = $qty - (float) $old['qty'];

    $synced = false;
    if (function_exists('production_sync_stock_out_from_movement')) {
        $synced = production_sync_stock_out_from_movement(
            $movementId,
            $qty,
            $code !== '' ? $code : null,
            $modeStored
        );
    }
    if (!$synced && abs($diff) > 0.0001) {
        $adj = tech_parts_adjust_by_part_id(
            (int) $old['part_id'],
            $diff,
            $actor,
            'ปรับรายการเบิก',
            $movementId
        );
        if (!$adj['ok']) {
            return $adj;
        }
    }

    q(
        'UPDATE part_movements SET qty=?, ref_asset_id=?, remark=?, mode=? WHERE id=?',
        'dissi',
        [$qty, $refId, $remark, $modeStored, $movementId]
    );
    return ['ok' => true];
}

/**
 * รายการเบิกที่ผูกกับรอบ MA
 *
 * @param int $maRecordId
 * @return array<int,array{movement_id:int,part_id:int,qty:float,name:string}>
 */
function ma_withdrawal_lines(int $maRecordId): array
{
    $maRecordId = (int) $maRecordId;
    if ($maRecordId <= 0) {
        return [];
    }
    $res = qr(
        "SELECT pm.id AS movement_id, pm.part_id, pm.qty, pt.name
         FROM part_movements pm
         JOIN parts pt ON pt.id=pm.part_id
         WHERE pm.ma_record_id=? AND pm.direction='out'
         ORDER BY pt.name, pm.id",
        'i',
        [$maRecordId]
    );
    $lines = [];
    while ($r = $res->fetch_assoc()) {
        $lines[] = [
            'movement_id' => (int) $r['movement_id'],
            'part_id'     => (int) $r['part_id'],
            'qty'         => (float) $r['qty'],
            'name'        => (string) $r['name'],
        ];
    }
    return $lines;
}

/**
 * แปลง POST แก้ไขเบิก MA → รายการ {movement_id, part_id, qty}
 *
 * @param array<int|string> $movementIds
 * @param array<int|string> $partIds
 * @param array<int|string> $qtys
 * @return array<int,array{movement_id:int,part_id:int,qty:float}>
 */
function ma_parse_withdraw_edit_lines(array $movementIds, array $partIds, array $qtys): array
{
    $lines = [];
    $count = max(count($movementIds), count($partIds), count($qtys));
    for ($i = 0; $i < $count; $i++) {
        $pid = (int) ($partIds[$i] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $lines[] = [
            'movement_id' => (int) ($movementIds[$i] ?? 0),
            'part_id'     => $pid,
            'qty'         => max(0.01, (float) ($qtys[$i] ?? 1)),
        ];
    }
    return $lines;
}

/**
 * sync รายการเบิก MA หลังแก้ไข — เพิ่ม/ลบ/ปรับ qty แล้ว sync stock
 *
 * @param int    $maRecordId
 * @param int    $assetId
 * @param string $assetCode
 * @param array<int,array{movement_id:int,part_id:int,qty:float}> $lines
 * @param string $actor
 * @return array{ok:bool, error?:string}
 */
function ma_sync_withdrawals_on_edit(int $maRecordId, int $assetId, string $assetCode, array $lines, string $actor): array
{
    $maRecordId = (int) $maRecordId;
    $assetId = (int) $assetId;
    $assetCode = trim($assetCode);

    $existing = ma_withdrawal_lines($maRecordId);
    $existingById = [];
    foreach ($existing as $e) {
        $existingById[$e['movement_id']] = $e;
    }
    $keptIds = [];

    foreach ($lines as $line) {
        $mid = (int) $line['movement_id'];
        $pid = (int) $line['part_id'];
        $qty = (float) $line['qty'];

        if ($mid > 0 && isset($existingById[$mid])) {
            $keptIds[$mid] = true;
            $old = $existingById[$mid];
            if ((int) $old['part_id'] !== $pid) {
                $del = production_delete_out_movement_with_stock($mid, $actor);
                if (!$del['ok']) {
                    return $del;
                }
                $w = ma_withdraw_parts($maRecordId, $assetId, $assetCode, [['part_id' => $pid, 'qty' => $qty]], $actor);
                if (!$w['ok']) {
                    return ['ok' => false, 'error' => $w['error'] ?? 'เบิกอะไหล่ใหม่ไม่สำเร็จ'];
                }
                continue;
            }
            if (abs((float) $old['qty'] - $qty) < 0.0001) {
                continue;
            }
            $edit = production_edit_out_movement($mid, $qty, $assetCode !== '' ? $assetCode : null, 'MA', $actor);
            if (!$edit['ok']) {
                return $edit;
            }
            continue;
        }

        $w = ma_withdraw_parts($maRecordId, $assetId, $assetCode, [['part_id' => $pid, 'qty' => $qty]], $actor);
        if (!$w['ok']) {
            return ['ok' => false, 'error' => $w['error'] ?? 'เบิกอะไหล่เพิ่มไม่สำเร็จ'];
        }
    }

    foreach ($existingById as $mid => $e) {
        if (isset($keptIds[$mid])) {
            continue;
        }
        $del = production_delete_out_movement_with_stock($mid, $actor);
        if (!$del['ok']) {
            return $del;
        }
    }

    return ['ok' => true];
}

/**
 * รายการอะไหล่ที่เบิกในรอบ MA
 *
 * @param int $maRecordId
 * @return array<int, array{name:string,qty:float,unit:string}>
 */
function ma_parts_withdrawn_items($maRecordId) {
    $maRecordId = (int)$maRecordId;
    if ($maRecordId <= 0) {
        return [];
    }
    $res = qr(
        "SELECT pm.qty, pt.name, pt.unit
         FROM part_movements pm
         JOIN parts pt ON pt.id=pm.part_id
         WHERE pm.ma_record_id=? AND pm.direction='out'
         ORDER BY pt.name",
        'i',
        [$maRecordId]
    );
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'name' => (string)$r['name'],
            'qty'  => (float)$r['qty'],
            'unit' => (string)($r['unit'] ?? ''),
        ];
    }
    return $items;
}

/**
 * ข้อความรายการอะไหล่ที่เบิกในรอบ MA (plain text)
 *
 * @param int $maRecordId
 * @return string
 */
function ma_parts_withdrawn_text($maRecordId) {
    $items = ma_parts_withdrawn_items($maRecordId);
    if (!$items) {
        return '-';
    }
    $parts = [];
    foreach ($items as $r) {
        $q = rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.');
        $parts[] = $r['name'] . ' × ' . $q . ($r['unit'] !== '' ? ' ' . $r['unit'] : '');
    }
    return implode("\n", $parts);
}

/**
 * HTML สรุปอะไหล่ที่เบิกในรอบ MA (สำหรับตารางประวัติ)
 *
 * @param int $maRecordId
 * @return string
 */
function ma_parts_withdrawn_html($maRecordId) {
    $items = ma_parts_withdrawn_items($maRecordId);
    if (!$items) {
        return '';
    }
    $parts = [];
    foreach ($items as $r) {
        $q = rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.');
        $parts[] = h($r['name']) . ' × ' . $q . ($r['unit'] !== '' ? ' ' . h($r['unit']) : '');
    }
    return '<div class="muted" style="font-size:12px;margin-top:4px">🔩 เบิก: ' . implode(' · ', $parts) . '</div>';
}

/**
 * แปลง mode การเบิกเป็นป้ายแสดงผล
 *
 * @param string|null $mode
 * @return string
 */
function part_movement_mode_label($mode) {
    $mode = trim((string)$mode);
    if ($mode === 'MA') {
        return 'MA';
    }
    if (preg_match('/: Out\s*$/i', $mode)) {
        return 'ภลิต';
    }
    if ($mode === 'ซ่อม') {
        return 'เบิกใช้';
    }
    if (strpos($mode, 'ผลิต') !== false || strpos($mode, 'BOM') !== false || strpos($mode, 'ชุดอะไหล่') !== false) {
        return 'ผลิต';
    }
    if ($mode === 'เบิกใช้') {
        return 'เบิกใช้';
    }
    return $mode !== '' ? $mode : 'อื่นๆ';
}

/**
 * ตัวเลือกประเภทสำหรับฟอร์มแก้ไข/เพิ่มรายการเบิก
 *
 * @return array<string,string> value => label
 */
function part_movement_mode_form_options(): array
{
    return [
        'เบิกใช้' => 'เบิกใช้',
        'ผลิต'   => 'ผลิต',
        'MA'     => 'MA',
    ];
}

/**
 * แปลง mode ในฐานข้อมูลเป็นค่า select ในฟอร์ม
 *
 * @param string|null $mode
 * @return string
 */
function part_movement_mode_form_value(?string $mode): string
{
    $label = part_movement_mode_label($mode);
    $options = part_movement_mode_form_options();
    if (isset($options[$label])) {
        return $label;
    }
    return ($label !== '' && $label !== 'อื่นๆ') ? $label : 'เบิกใช้';
}

/**
 * แปลงค่า select ฟอร์มเป็น mode ที่เก็บใน part_movements
 *
 * @param string $formValue
 * @return string
 */
function part_movement_mode_from_form(string $formValue): string
{
    $v = trim($formValue);
    if ($v === 'ผลิต') {
        if (function_exists('production_bom_movement_modes')) {
            return production_bom_movement_modes()[0] ?? 'เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)';
        }
        return 'เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)';
    }
    if ($v === 'MA') {
        return 'MA';
    }
    if ($v === 'เบิกใช้') {
        return 'เบิกใช้';
    }
    return $v !== '' ? $v : 'เบิกใช้';
}

$__bomSyncPath = dirname(__DIR__, 2) . '/parts/includes/production_bom_sync.php';
if (is_file($__bomSyncPath)) {
    require_once $__bomSyncPath;
}

$__withdrawSyncPath = dirname(__DIR__, 2) . '/parts/includes/production_asset_withdraw_sync.php';
if (is_file($__withdrawSyncPath)) {
    require_once $__withdrawSyncPath;
}

/**
 * ป้ายสถานะ BOM (qty-level) สำหรับแสดงใน UI
 *
 * @param array{bom_match:string,bom_count:int,bom_extra_parts:int,bom_missing_parts:int} $info
 * @return string
 */
function asset_bom_match_label(array $info): string
{
    $bomCount = (int) ($info['bom_count'] ?? 0);
    if ($bomCount <= 0) {
        return '—';
    }
    $match = (string) ($info['bom_match'] ?? 'none');
    if ($match === 'ok') {
        return 'BOM ตรง ' . $bomCount . '/' . $bomCount;
    }
    if ($match === 'extra') {
        $n = (int) ($info['bom_extra_parts'] ?? 0);
        return 'BOM ไม่ตรง (เกิน/ซ้ำ ' . $n . ' part)';
    }
    if ($match === 'missing') {
        $n = (int) ($info['bom_missing_parts'] ?? 0);
        return 'BOM ไม่ครบ (ขาด ' . $n . ' part)';
    }
    if ($match === 'mixed') {
        return 'BOM ไม่ตรง (เกิน+ขาด)';
    }
    return 'ยังไม่เบิก BOM';
}

/**
 * ตรวจว่าเครื่องควรมีปุ่ม Sync ตาม BOM
 *
 * @param array<string,mixed> $summary จาก asset_parts_withdraw_summary()
 * @return bool
 */
function asset_needs_bom_sync(array $summary): bool
{
    $bomCount = (int) ($summary['bom_count'] ?? 0);
    if ($bomCount <= 0) {
        return false;
    }
    $match = (string) ($summary['bom_match'] ?? 'none');
    return $match !== 'ok' && $match !== 'none';
}

/**
 * Sync ตาม BOM — reconcile แล้วผูก stock_out
 *
 * @param int    $assetId
 * @param string $actor
 * @return array{ok:bool,removed:int,adjusted:int,added:int,linked:int,created:int,errors:array<int,string>,message?:string}
 */
function asset_reconcile_bom_to_stock(int $assetId, string $actor): array
{
    if (!function_exists('production_reconcile_asset_bom')) {
        return [
            'ok' => false, 'removed' => 0, 'adjusted' => 0, 'added' => 0,
            'linked' => 0, 'created' => 0, 'errors' => ['ระบบ Sync BOM ไม่พร้อม'],
            'message' => 'ระบบ Sync BOM ไม่พร้อม',
        ];
    }
    return production_reconcile_asset_bom($assetId, $actor);
}

/**
 * SQL เงื่อนไขเครื่องที่ BOM ไม่ตรง (auto movement qty — ไม่รวม Set ข้าม DB)
 *
 * @return array{sql:string,types:string,params:array<int|string>}
 */
function asset_bom_mismatch_sql(): array
{
    $mode = production_bom_movement_modes()[0] ?? 'เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)';
    return [
        'sql' => ' AND EXISTS (SELECT 1 FROM bom_items b WHERE b.product_id = a.product_id)
          AND (
            EXISTS (
              SELECT 1 FROM bom_items b
              WHERE b.product_id = a.product_id
              AND ABS(
                (SELECT COALESCE(SUM(pm.qty), 0) FROM part_movements pm
                 WHERE pm.ref_asset_id = a.id AND pm.direction = \'out\' AND pm.mode = ?
                   AND pm.part_id = b.part_id) - b.qty_per_unit
              ) > 0.0001
            )
            OR EXISTS (
              SELECT 1 FROM part_movements pm
              WHERE pm.ref_asset_id = a.id AND pm.direction = \'out\' AND pm.mode = ?
                AND NOT EXISTS (
                  SELECT 1 FROM bom_items b
                  WHERE b.product_id = a.product_id AND b.part_id = pm.part_id
                )
            )
          )',
        'types' => 'ss',
        'params' => [$mode, $mode],
    ];
}

/**
 * นับเครื่องที่ BOM ไม่ตรง (query เดียว — ใช้แสดงปุ่ม bulk)
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return int
 */
function asset_bom_sync_pending_count_fast(string $whereSql, string $types, array $params): int
{
    $frag = asset_bom_mismatch_sql();
    $row = qr(
        "SELECT COUNT(*) c FROM assets a JOIN products p ON p.id = a.product_id
         $whereSql {$frag['sql']}",
        $types . $frag['types'],
        array_merge($params, $frag['params'])
    )->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

/**
 * นับเครื่อง BOM ไม่ตรง (cache session 60 วิ — ลด load ซ้ำ)
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return int
 */
function asset_bom_sync_pending_count_cached(string $whereSql, string $types, array $params): int
{
    $key = 'bom_pending_' . md5($whereSql . '|' . $types . '|' . serialize($params));
    $now = time();
    if (isset($_SESSION[$key], $_SESSION[$key . '_t']) && ($now - (int) $_SESSION[$key . '_t']) < 60) {
        return (int) $_SESSION[$key];
    }
    $cnt = asset_bom_sync_pending_count_fast($whereSql, $types, $params);
    $_SESSION[$key] = $cnt;
    $_SESSION[$key . '_t'] = $now;
    return $cnt;
}

/**
 * รายการ asset.id ที่ BOM ไม่ตรง (query เดียว — ใช้ตอน bulk POST)
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return array<int,int>
 */
function asset_ids_needing_bom_sync(string $whereSql, string $types, array $params): array
{
    $frag = asset_bom_mismatch_sql();
    $res = qr(
        "SELECT a.id FROM assets a JOIN products p ON p.id = a.product_id
         $whereSql {$frag['sql']}",
        $types . $frag['types'],
        array_merge($params, $frag['params'])
    );
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/**
 * Sync BOM หลายเครื่อง
 *
 * @param array<int,int> $assetIds
 * @param string         $actor
 * @return array{ok:bool,assets:int,removed:int,added:int,errors:array<int,string>,message?:string}
 */
function asset_reconcile_bom_bulk(array $assetIds, string $actor): array
{
    $result = ['ok' => true, 'assets' => 0, 'removed' => 0, 'added' => 0, 'errors' => []];
    foreach ($assetIds as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        $r = asset_reconcile_bom_to_stock($id, $actor);
        $result['assets']++;
        $result['removed'] += (int) ($r['removed'] ?? 0) + (int) ($r['adjusted'] ?? 0);
        $result['added'] += (int) ($r['added'] ?? 0);
        if (!empty($r['errors'])) {
            foreach ($r['errors'] as $e) {
                $result['errors'][] = "asset #{$id}: {$e}";
            }
        }
        if (!($r['ok'] ?? true) && empty($r['removed']) && empty($r['added'])) {
            $result['ok'] = false;
        }
    }
    if ($result['assets'] > 0) {
        $result['message'] = "Sync BOM {$result['assets']} เครื่อง · ลบ/ปรับ {$result['removed']} · เพิ่ม {$result['added']}";
    }
    return $result;
}

/**
 * สถานะ Sync ตามรายการเบิก (Stock N/N) จากตัวเลขสรุป
 *
 * @param int $outCount       จำนวน movement ในตาราง
 * @param int $stockOutCount  จำนวน stock_out ใน Parts ของ S/N
 * @param int $linkedCount    movement ที่มี tech_stock_out_id
 * @return array{match:string,linked:int,total:int,label:string,needs_sync:bool}
 */
function asset_withdraw_list_sync_status_from_counts(int $outCount, int $stockOutCount, int $linkedCount): array
{
    if ($outCount <= 0) {
        return ['match' => 'none', 'linked' => 0, 'total' => 0, 'label' => '—', 'needs_sync' => false];
    }
    $needs = $linkedCount < $outCount || $stockOutCount !== 1;
    if (!$needs) {
        return [
            'match'      => 'ok',
            'linked'     => $linkedCount,
            'total'      => $outCount,
            'label'      => 'Stock ตรง ' . $outCount . '/' . $outCount,
            'needs_sync' => false,
        ];
    }
    $label = 'Stock ค้าง ' . $linkedCount . '/' . $outCount;
    if ($stockOutCount > 1) {
        $label .= ' (' . $stockOutCount . ' ใบ)';
    }
    return [
        'match'      => 'pending',
        'linked'     => $linkedCount,
        'total'      => $outCount,
        'label'      => $label,
        'needs_sync' => true,
    ];
}

/**
 * สถานะ Sync ตามรายการเบิกจาก summary
 *
 * @param array<string,mixed> $summary
 * @return array{match:string,linked:int,total:int,label:string,needs_sync:bool}
 */
function asset_withdraw_list_sync_status(array $summary): array
{
    if (isset($summary['withdraw_list_sync']) && is_array($summary['withdraw_list_sync'])) {
        return $summary['withdraw_list_sync'];
    }
    $out = (int) ($summary['out_count'] ?? 0);
    $stockOut = (int) ($summary['stock_out_count'] ?? 0);
    $linked = (int) ($summary['linked_out_count'] ?? 0);
    if ($linked <= 0 && !empty($summary['movements'])) {
        foreach ($summary['movements'] as $mv) {
            if (!empty($mv['tech_stock_out_id'])) {
                $linked++;
            }
        }
    }
    return asset_withdraw_list_sync_status_from_counts($out, $stockOut, $linked);
}

/**
 * ป้ายสถานะ Stock ตามรายการเบิก
 *
 * @param array<string,mixed> $summary
 * @return string
 */
function asset_withdraw_list_sync_label(array $summary): string
{
    return asset_withdraw_list_sync_status($summary)['label'];
}

/**
 * ตรวจว่าเครื่องควรมีปุ่ม Sync ตามรายการเบิก
 *
 * @param array<string,mixed> $summary
 * @return bool
 */
function asset_needs_withdraw_list_sync(array $summary): bool
{
    return asset_withdraw_list_sync_status($summary)['needs_sync'];
}

/**
 * Sync ตามรายการเบิก — ผูก/สร้าง stock_out + ลบใบเบิกซ้ำ/เกิน
 *
 * @param int    $assetId
 * @param string $actor
 * @return array{ok:bool,removed:int,linked:int,created:int,repaired:int,skipped:int,failed:int,errors:array<int,string>,message?:string}
 */
function asset_reconcile_withdraw_list_to_stock(int $assetId, string $actor): array
{
    if (!function_exists('production_reconcile_asset_withdraw_list')) {
        return [
            'ok' => false, 'removed' => 0, 'linked' => 0, 'created' => 0,
            'repaired' => 0, 'skipped' => 0, 'failed' => 0,
            'errors' => ['ระบบ Sync รายการเบิกไม่พร้อม'],
            'message' => 'ระบบ Sync รายการเบิกไม่พร้อม',
        ];
    }
    return production_reconcile_asset_withdraw_list($assetId, $actor);
}

/**
 * SQL — movement ยังไม่ผูก stock_out
 *
 * @return array{sql:string,types:string,params:array<int|string>}
 */
function asset_withdraw_list_unlinked_sql(): array
{
    return [
        'sql' => " AND EXISTS (
            SELECT 1 FROM part_movements pm
            WHERE pm.ref_asset_id = a.id AND pm.direction = 'out'
              AND (pm.tech_stock_out_id IS NULL OR pm.tech_stock_out_id = 0)
          )",
        'types' => '',
        'params' => [],
    ];
}

/**
 * SQL — มีรายการเบิกในตาราง production
 *
 * @return array{sql:string,types:string,params:array<int|string>}
 */
function asset_withdraw_list_has_movements_sql(): array
{
    return [
        'sql' => " AND EXISTS (
            SELECT 1 FROM part_movements pm
            WHERE pm.ref_asset_id = a.id AND pm.direction = 'out'
          )",
        'types' => '',
        'params' => [],
    ];
}

/**
 * นับเครื่องที่ movement ยังไม่ผูก stock_out (query เดียว)
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return int
 */
function asset_withdraw_list_sync_pending_count_fast(string $whereSql, string $types, array $params): int
{
    $frag = asset_withdraw_list_unlinked_sql();
    $row = qr(
        "SELECT COUNT(*) c FROM assets a JOIN products p ON p.id = a.product_id
         $whereSql {$frag['sql']}",
        $types . $frag['types'],
        array_merge($params, $frag['params'])
    )->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

/**
 * นับเครื่องที่ Stock ไม่ตรงรายการเบิก (รวม stock_out เกิน/ขาด — ตรวจ cross-DB)
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return int
 */
function asset_withdraw_list_sync_pending_count_full(string $whereSql, string $types, array $params): int
{
    return count(asset_ids_needing_withdraw_list_sync($whereSql, $types, $params));
}

/**
 * cache session 60 วิ — นับเครื่องที่ต้อง Sync รายการเบิก
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return int
 */
function asset_withdraw_list_sync_pending_count_cached(string $whereSql, string $types, array $params): int
{
    $key = asset_withdraw_list_sync_pending_cache_key($whereSql, $types, $params);
    $now = time();
    if (isset($_SESSION[$key], $_SESSION[$key . '_t']) && ($now - (int) $_SESSION[$key . '_t']) < 60) {
        return (int) $_SESSION[$key];
    }
    $cnt = asset_withdraw_list_sync_pending_count_full($whereSql, $types, $params);
    $_SESSION[$key] = $cnt;
    $_SESSION[$key . '_t'] = $now;
    return $cnt;
}

/**
 * cache key สำหรับนับเครื่องที่ต้อง Sync รายการเบิก
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return string
 */
function asset_withdraw_list_sync_pending_cache_key(string $whereSql, string $types, array $params): string
{
    return 'withdraw_pending_' . md5($whereSql . '|' . $types . '|' . serialize($params));
}

/**
 * ล้าง cache นับ pending (หลัง bulk sync)
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return void
 */
function asset_withdraw_list_sync_pending_cache_clear(string $whereSql, string $types, array $params): void
{
    $key = asset_withdraw_list_sync_pending_cache_key($whereSql, $types, $params);
    unset($_SESSION[$key], $_SESSION[$key . '_t']);
}

/**
 * รายการ asset.id ที่ต้อง Sync ตามรายการเบิก
 *
 * @param string $whereSql
 * @param string $types
 * @param array<int|string> $params
 * @return array<int,int>
 */
function asset_ids_needing_withdraw_list_sync(string $whereSql, string $types, array $params): array
{
    $hasFrag = asset_withdraw_list_has_movements_sql();
    $res = qr(
        "SELECT a.id, TRIM(a.asset_code) AS asset_code,
                (SELECT COUNT(*) FROM part_movements pm
                 WHERE pm.ref_asset_id=a.id AND pm.direction='out') AS out_cnt,
                (SELECT COUNT(*) FROM part_movements pm
                 WHERE pm.ref_asset_id=a.id AND pm.direction='out'
                   AND pm.tech_stock_out_id IS NOT NULL AND pm.tech_stock_out_id > 0) AS linked_cnt
         FROM assets a JOIN products p ON p.id = a.product_id
         $whereSql {$hasFrag['sql']}",
        $types . $hasFrag['types'],
        array_merge($params, $hasFrag['params'])
    );
    $rows = [];
    $sns = [];
    while ($row = $res->fetch_assoc()) {
        $outCnt = (int) ($row['out_cnt'] ?? 0);
        $linkedCnt = (int) ($row['linked_cnt'] ?? 0);
        if ($outCnt <= 0) {
            continue;
        }
        if ($linkedCnt < $outCnt) {
            $rows[(int) $row['id']] = true;
            continue;
        }
        $sn = trim((string) ($row['asset_code'] ?? ''));
        if ($sn !== '') {
            $sns[$sn] = (int) $row['id'];
        }
    }
    if ($sns !== []) {
        $stockCounts = parts_stock_out_counts_by_sn(array_keys($sns));
        foreach ($sns as $sn => $aid) {
            $stockCnt = (int) ($stockCounts[$sn] ?? 0);
            if ($stockCnt !== 1) {
                $rows[$aid] = true;
            }
        }
    }
    $ids = array_keys($rows);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Sync รายการเบิกหลายเครื่อง
 *
 * @param array<int,int> $assetIds
 * @param string         $actor
 * @return array{ok:bool,assets:int,removed:int,linked:int,created:int,errors:array<int,string>,message?:string}
 */
function asset_reconcile_withdraw_list_bulk(array $assetIds, string $actor): array
{
    if (function_exists('production_sync_stock_codes_once')) {
        production_sync_stock_codes_once(false);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $result = ['ok' => true, 'assets' => 0, 'removed' => 0, 'linked' => 0, 'created' => 0, 'errors' => []];
    foreach ($assetIds as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        $r = asset_reconcile_withdraw_list_to_stock($id, $actor);
        $result['assets']++;
        $result['removed'] += (int) ($r['removed'] ?? 0);
        $result['linked'] += (int) ($r['linked'] ?? 0);
        $result['created'] += (int) ($r['created'] ?? 0);
        if (!empty($r['errors'])) {
            foreach ($r['errors'] as $e) {
                $result['errors'][] = "asset #{$id}: {$e}";
            }
        }
        if (!($r['ok'] ?? true) && empty($r['removed']) && empty($r['linked']) && empty($r['created'])) {
            $result['ok'] = false;
        }
    }
    if ($result['assets'] > 0) {
        $result['message'] = "Sync รายการเบิก {$result['assets']} เครื่อง · ลบ {$result['removed']} · ผูก {$result['linked']} · สร้าง {$result['created']}";
    }
    return $result;
}
