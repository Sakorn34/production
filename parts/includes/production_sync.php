<?php
/**
 * production_sync.php — sync รายการเบิก/รับระหว่าง biton_tech_parts กับ biton_production
 *
 * ใช้เมื่อ stock_out / stock_in ในแอป parts ต้องสะท้อน part_movements ใน production
 * Mapping อะไหล่: parts.stock_code = products.code
 *
 * Flow ตัวอย่าง (เบิกรายชิ้นจาก parts UI):
 *   stockOutItem(..., assetCode) → INSERT stock_out → production_create_out_movement(...)
 */

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * PDO ไป biton_production (singleton ต่อ request)
 *
 * @return PDO
 */
function production_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg = require 'D:/AppServ/secrets/production/finishgoogs.secrets.php';
    $c = $cfg['production'];
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['db'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * เพิ่มคอลัมน์ sync ใน tech_parts และ production (รันครั้งเดียวต่อ request)
 *
 * @param PDO $techDb biton_tech_parts
 * @return void
 */
function ensure_stock_production_sync_schema(PDO $techDb): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $techCols = [
        'stock_out'       => [
            'asset_code'        => 'VARCHAR(80) NULL DEFAULT NULL',
            'part_movement_id'  => 'BIGINT UNSIGNED NULL DEFAULT NULL',
        ],
        'stock_out_items' => [
            'part_movement_id'  => 'BIGINT UNSIGNED NULL DEFAULT NULL',
        ],
        'stock_in'        => [
            'part_movement_id'  => 'BIGINT UNSIGNED NULL DEFAULT NULL',
        ],
    ];
    foreach ($techCols as $table => $cols) {
        foreach ($cols as $col => $def) {
            $st = $techDb->query("SHOW COLUMNS FROM `{$table}` LIKE " . $techDb->quote($col));
            if ($st && !$st->fetch()) {
                $techDb->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
            }
        }
    }

    try {
        $prod = production_db();
        $st = $prod->query("SHOW COLUMNS FROM part_movements LIKE 'tech_stock_out_id'");
        if ($st && !$st->fetch()) {
            $prod->exec('ALTER TABLE part_movements ADD COLUMN tech_stock_out_id INT UNSIGNED NULL DEFAULT NULL AFTER remark');
        }
    } catch (Throwable $e) {
        error_log('[ensure_stock_production_sync_schema] production: ' . $e->getMessage());
    }
}

/**
 * หา part_id ใน production จากรหัส products.code
 *
 * @param string $productCode
 * @return int|null
 */
function production_part_id_by_product_code(string $productCode): ?int
{
    $productCode = trim($productCode);
    if ($productCode === '') {
        return null;
    }
    $prod = production_db();
    $st = $prod->prepare('SELECT id FROM parts WHERE stock_code = ? LIMIT 1');
    $st->execute([$productCode]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * หา asset_id จากหมายเลขสินค้า (S/N)
 *
 * @param string|null $assetCode
 * @return int|null
 */
function production_asset_id_by_code(?string $assetCode): ?int
{
    $assetCode = trim((string) $assetCode);
    if ($assetCode === '' || mb_strlen($assetCode) > 80) {
        return null;
    }
    $prod = production_db();
    $st = $prod->prepare('SELECT id FROM assets WHERE asset_code = ? OR factory_serial = ? LIMIT 1');
    $st->execute([$assetCode, $assetCode]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * สร้าง part_movements (out) ใน production หลังเบิกจาก parts app
 *
 * @param int         $partId       parts.id ใน production
 * @param float       $qty
 * @param string|null $assetCode    S/N สินค้า
 * @param string      $mode         เช่น เบิกใช้ / เบิกผลิต
 * @param string      $madeBy
 * @param string|null $remark
 * @param int|null    $stockOutId   stock_out.id ใน tech_parts
 * @return int part_movements.id
 */
function production_create_out_movement(
    int $partId,
    float $qty,
    ?string $assetCode,
    string $mode,
    string $madeBy,
    ?string $remark,
    ?int $stockOutId = null
): int {
    $refId = production_asset_id_by_code($assetCode);
    $prod = production_db();
    $st = $prod->prepare(
        "INSERT INTO part_movements (part_id, moved_at, direction, qty, mode, ref_asset_id, made_by, remark, tech_stock_out_id)
         VALUES (?, NOW(), 'out', ?, ?, ?, ?, ?, ?)"
    );
    $st->execute([
        $partId,
        $qty,
        $mode,
        $refId,
        $madeBy !== '' ? $madeBy : 'parts',
        $remark,
        $stockOutId,
    ]);
    return (int) $prod->lastInsertId();
}

/**
 * อัปเดต part_movements ที่เชื่อมกับ stock_out
 *
 * @param int         $movementId
 * @param float       $qty
 * @param string|null $assetCode
 * @param string|null $remark
 * @param string      $madeBy
 * @return void
 */
function production_update_out_movement(
    int $movementId,
    float $qty,
    ?string $assetCode,
    ?string $remark,
    string $madeBy
): void {
    if ($movementId <= 0) {
        return;
    }
    $refId = production_asset_id_by_code($assetCode);
    $prod = production_db();
    $st = $prod->prepare(
        'UPDATE part_movements SET qty = ?, ref_asset_id = ?, remark = ?, made_by = ? WHERE id = ? AND direction = ?'
    );
    $st->execute([$qty, $refId, $remark, $madeBy, $movementId, 'out']);
}

/**
 * ลบ part_movements ที่เชื่อม (ไม่ปรับ stock tech_parts — caller จัดการเอง)
 *
 * @param int $movementId
 * @return void
 */
function production_delete_out_movement(int $movementId): void
{
    if ($movementId <= 0) {
        return;
    }
    $prod = production_db();
    $st = $prod->prepare("DELETE FROM part_movements WHERE id = ? AND direction = 'out'");
    $st->execute([$movementId]);
}

/**
 * ผูก stock_out ↔ part_movements สองทาง
 *
 * @param PDO         $techDb
 * @param int         $stockOutId
 * @param int         $movementId
 * @param string|null $assetCode
 * @return void
 */
function production_link_stock_out(PDO $techDb, int $stockOutId, int $movementId, ?string $assetCode = null): void
{
    $st = $techDb->prepare('UPDATE stock_out SET part_movement_id = ?, asset_code = ? WHERE id = ?');
    $st->execute([$movementId, trim((string) $assetCode) ?: null, $stockOutId]);

    try {
        $prod = production_db();
        $st2 = $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE id = ?');
        $st2->execute([$stockOutId, $movementId]);
    } catch (Throwable $e) {
        error_log('[production_link_stock_out] ' . $e->getMessage());
    }
}

/**
 * ผูก part_movement_id ที่ stock_out_items (ใช้กับเบิก Set)
 *
 * @param PDO $techDb
 * @param int $itemId       stock_out_items.id
 * @param int $movementId
 * @return void
 */
function production_link_stock_out_item(PDO $techDb, int $itemId, int $movementId): void
{
    $st = $techDb->prepare('UPDATE stock_out_items SET part_movement_id = ? WHERE id = ?');
    $st->execute([$movementId, $itemId]);
}

function tech_parts_sync_db(): PDO
{
    if (function_exists('dbParts')) {
        return dbParts();
    }
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg = require 'D:/AppServ/secrets/production/finishgoogs.secrets.php';
    $c = $cfg['techparts'];
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['db'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * sync การแก้ไขจาก production → stock_out ที่ผูกไว้
 *
 * @param int         $movementId
 * @param float       $qty
 * @param string|null $assetCode
 * @param string|null $note
 * @return bool true ถ้ามี stock_out ที่ sync แล้ว
 */
function production_sync_stock_out_from_movement(
    int $movementId,
    float $qty,
    ?string $assetCode,
    ?string $note
): bool {
    $prod = production_db();
    $st = $prod->prepare('SELECT tech_stock_out_id FROM part_movements WHERE id = ?');
    $st->execute([$movementId]);
    $row = $st->fetch();
    if (!$row || empty($row['tech_stock_out_id'])) {
        return false;
    }
    $outId = (int) $row['tech_stock_out_id'];
    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $detail = $tech->prepare('SELECT soi.id, soi.product_id, soi.quantity, p.quantity AS stock_qty
                              FROM stock_out_items soi
                              JOIN products p ON p.id = soi.product_id
                              WHERE soi.stock_out_id = ? LIMIT 1');
    $detail->execute([$outId]);
    $item = $detail->fetch();
    if (!$item) {
        return false;
    }

    $newQty = (int) round($qty);
    $oldQty = (int) $item['quantity'];
    $diff = $newQty - $oldQty;

    $tech->beginTransaction();
    try {
        if ($diff !== 0) {
            if ($diff > 0 && (int) $item['stock_qty'] < $diff) {
                throw new RuntimeException('สต็ock tech_parts ไม่พอสำหรับ sync');
            }
            $tech->prepare('UPDATE stock_out_items SET quantity = ? WHERE id = ?')->execute([$newQty, $item['id']]);
            $tech->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$diff, $item['product_id']]);
        }
        $tech->prepare('UPDATE stock_out SET asset_code = ?, note = ? WHERE id = ?')
            ->execute([trim((string) $assetCode) ?: null, $note, $outId]);
        $tech->commit();
        return true;
    } catch (Throwable $e) {
        if ($tech->inTransaction()) {
            $tech->rollBack();
        }
        error_log('[production_sync_stock_out_from_movement] ' . $e->getMessage());
        return false;
    }
}

/**
 * ลบ stock_out ใน tech_parts + คืนสต็อก + ลบ part_movements ที่เชื่อม
 *
 * @param int $stockOutId stock_out.id ใน biton_tech_parts
 * @return bool
 */
function production_sync_delete_stock_out_by_id(int $stockOutId): bool
{
    if ($stockOutId <= 0) {
        return false;
    }

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $check = $tech->prepare('SELECT id FROM stock_out WHERE id = ?');
    $check->execute([$stockOutId]);
    if (!$check->fetch()) {
        return false;
    }

    $movementIds = [];
    $prod = production_db();

    $st = $prod->prepare('SELECT id FROM part_movements WHERE tech_stock_out_id = ?');
    $st->execute([$stockOutId]);
    while ($r = $st->fetch()) {
        $movementIds[(int) $r['id']] = true;
    }

    $header = $tech->prepare('SELECT part_movement_id FROM stock_out WHERE id = ?');
    $header->execute([$stockOutId]);
    $so = $header->fetch();
    if ($so && !empty($so['part_movement_id'])) {
        $movementIds[(int) $so['part_movement_id']] = true;
    }

    $itemSt = $tech->prepare('SELECT part_movement_id FROM stock_out_items WHERE stock_out_id = ?');
    $itemSt->execute([$stockOutId]);
    while ($r = $itemSt->fetch()) {
        if (!empty($r['part_movement_id'])) {
            $movementIds[(int) $r['part_movement_id']] = true;
        }
    }

    $items = $tech->prepare('SELECT product_id, quantity FROM stock_out_items WHERE stock_out_id = ?');
    $items->execute([$stockOutId]);
    $rows = $items->fetchAll();

    $tech->beginTransaction();
    try {
        foreach ($rows as $r) {
            $tech->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                ->execute([(int) $r['quantity'], (int) $r['product_id']]);
        }
        $tech->prepare('DELETE FROM stock_out_items WHERE stock_out_id = ?')->execute([$stockOutId]);
        $tech->prepare('DELETE FROM stock_out WHERE id = ?')->execute([$stockOutId]);
        $tech->commit();
    } catch (Throwable $e) {
        if ($tech->inTransaction()) {
            $tech->rollBack();
        }
        error_log('[production_sync_delete_stock_out_by_id] ' . $e->getMessage());
        return false;
    }

    foreach (array_keys($movementIds) as $mid) {
        production_delete_out_movement($mid);
    }

    return true;
}

/**
 * sync การลบจาก production → ลบ stock_out ที่ผูกและคืนสต็อก
 *
 * @param int $movementId
 * @return bool
 */
function production_sync_delete_stock_out_from_movement(int $movementId): bool
{
    $prod = production_db();
    $st = $prod->prepare('SELECT tech_stock_out_id FROM part_movements WHERE id = ?');
    $st->execute([$movementId]);
    $row = $st->fetch();
    if (!$row || empty($row['tech_stock_out_id'])) {
        return false;
    }

    return production_sync_delete_stock_out_by_id((int) $row['tech_stock_out_id']);
}

/**
 * ลบรายการเบิกทั้งหมดของเครื่อง — sync production + tech_parts + คืนสต็อก
 *
 * เรียกก่อน DELETE assets เมื่อลบเครื่อง
 *
 * @param int         $assetId
 * @param string|null $assetCode S/N (optional — อ่านจาก DB ถ้าไม่ส่ง)
 * @return array{movements:int,stock_outs:int}
 */
function production_sync_delete_all_withdrawals_for_asset(int $assetId, ?string $assetCode = null): array
{
    $stats = ['movements' => 0, 'stock_outs' => 0];
    $deletedOutIds = [];
    $prod = production_db();

    if ($assetCode === null || trim($assetCode) === '') {
        $st = $prod->prepare('SELECT asset_code FROM assets WHERE id = ? LIMIT 1');
        $st->execute([$assetId]);
        $row = $st->fetch();
        $assetCode = $row['asset_code'] ?? '';
    }
    $assetCode = trim((string) $assetCode);

    $st = $prod->prepare(
        "SELECT id, part_id, qty, tech_stock_out_id FROM part_movements
         WHERE ref_asset_id = ? AND direction = 'out'"
    );
    $st->execute([$assetId]);
    $movements = $st->fetchAll();

    $actor = function_exists('actor_name') ? actor_name() : 'system';

    foreach ($movements as $m) {
        $mid = (int) $m['id'];
        $outId = (int) ($m['tech_stock_out_id'] ?? 0);

        if ($outId > 0 && !isset($deletedOutIds[$outId])) {
            if (production_sync_delete_stock_out_by_id($outId)) {
                $deletedOutIds[$outId] = true;
                $stats['stock_outs']++;
            } else {
                production_delete_out_movement($mid);
            }
        } elseif ($outId <= 0 && function_exists('tech_parts_stock_in_by_part_id')) {
            tech_parts_stock_in_by_part_id(
                (int) $m['part_id'],
                (float) $m['qty'],
                'ลบเครื่อง (คืนสต็อก)',
                $actor
            );
            production_delete_out_movement($mid);
        } else {
            production_delete_out_movement($mid);
        }

        $stats['movements']++;
    }

    if ($assetCode !== '') {
        $tech = tech_parts_sync_db();
        ensure_stock_production_sync_schema($tech);
        $st2 = $tech->prepare('SELECT id FROM stock_out WHERE TRIM(asset_code) = ?');
        $st2->execute([$assetCode]);
        foreach ($st2->fetchAll() as $row) {
            $oid = (int) $row['id'];
            if (isset($deletedOutIds[$oid])) {
                continue;
            }
            if (production_sync_delete_stock_out_by_id($oid)) {
                $deletedOutIds[$oid] = true;
                $stats['stock_outs']++;
            }
        }
    }

    return $stats;
}
