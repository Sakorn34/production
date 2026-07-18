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

    try {
        $pdo->beginTransaction();

        if (!function_exists('generateDocNo')) {
            throw new RuntimeException('generateDocNo ไม่พร้อมใช้งาน');
        }
        $docNo = generateDocNo($pdo);

        $st = $pdo->prepare('INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code) VALUES (?, NULL, ?, ?, ?)');
        $st->execute([$docNo, $purpose, $issuedBy !== '' ? $issuedBy : 'finishgoogs', trim((string)$assetCode) ?: null]);
        $outId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)');
        $st->execute([$outId, (int)$product['id'], $quantity]);

        $st = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');
        $st->execute([$quantity, (int)$product['id']]);

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
 * สรุปสถานะการเบิกอะไหล่ของเครื่อง — ใช้แสดงใน assets.php / asset.php
 *
 * @param int $assetId
 * @return array{
 *   out_count:int,
 *   bom_count:int,
 *   bom_withdrawn:int,
 *   status:string,
 *   label:string,
 *   movements:array<int,array<string,mixed>>,
 *   stock_docs:array<int,array<string,mixed>>
 * }
 */
function asset_parts_withdraw_summary($assetId) {
    $assetId = (int)$assetId;
    $asset = qr('SELECT product_id, asset_code FROM assets WHERE id=?', 'i', [$assetId])->fetch_assoc();
    if (!$asset) {
        return [
            'out_count' => 0, 'bom_count' => 0, 'bom_withdrawn' => 0,
            'status' => 'none', 'label' => '—', 'movements' => [], 'stock_docs' => [],
        ];
    }

    $outCount = (int)qr(
        "SELECT COUNT(*) c FROM part_movements WHERE ref_asset_id=? AND direction='out'",
        'i', [$assetId]
    )->fetch_assoc()['c'];

    $bomCount = (int)qr(
        'SELECT COUNT(*) c FROM bom_items WHERE product_id=?',
        'i', [(int)$asset['product_id']]
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

    $movements = [];
    $res = qr(
        "SELECT pm.id, pm.qty, pm.moved_at, pm.remark, pm.tech_stock_out_id, pm.made_by,
                pt.name pname, pt.unit, pt.stock_code
         FROM part_movements pm
         JOIN parts pt ON pt.id=pm.part_id
         WHERE pm.ref_asset_id=? AND pm.direction='out'
         ORDER BY pm.moved_at DESC, pm.id DESC",
        'i', [$assetId]
    );
    while ($r = $res->fetch_assoc()) {
        $movements[] = $r;
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

    if ($outCount === 0) {
        $status = $bomCount > 0 ? 'pending' : 'none';
        $label = $bomCount > 0 ? 'ยังไม่เบิก' : '—';
    } elseif ($bomCount > 0 && $bomWithdrawn < $bomCount) {
        $status = 'partial';
        $label = "เบิกแล้ว {$bomWithdrawn}/{$bomCount}";
    } else {
        $status = 'done';
        $label = "เบิกแล้ว {$outCount} รายการ";
    }

    return [
        'out_count'      => $outCount,
        'bom_count'      => $bomCount,
        'bom_withdrawn'  => $bomWithdrawn,
        'status'         => $status,
        'label'          => $label,
        'asset_code'     => $asset['asset_code'] ?? '',
        'movements'      => $movements,
        'stock_docs'     => $stockDocs,
    ];
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
    return '<span class="parts-status parts-status-' . h($status) . '">' . $label . '</span>';
}
