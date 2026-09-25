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

require_once dirname(__DIR__, 2) . '/shared/app_paths.php';

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
    $cfg = require app_finishgoogs_secrets_path();
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
            'stock_deducted'    => 'TINYINT(1) NOT NULL DEFAULT 1',
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
 * ตรวจว่า stock_out หักสต็ockจริงหรือเป็นใบย้อนหลัง (sync backfill)
 *
 * @param array<string,mixed>|false $row แถว stock_deducted จาก stock_out
 * @return bool
 */
function production_stock_out_was_deducted_row($row): bool
{
    if (!$row || !is_array($row)) {
        return true;
    }
    if (!array_key_exists('stock_deducted', $row)) {
        return true;
    }
    return (int) ($row['stock_deducted'] ?? 1) === 1;
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
 * สร้างแถว parts ใน biton_production จาก products ใน biton_tech_parts
 *
 * @param array<string,mixed> $product แถว products (code, name, unit, quantity, min_stock, purchase_link, is_active)
 * @return int|null part id ใน production หรือ null ถ้า sync ไม่ได้
 */
function production_sync_create_part_from_product(array $product): ?int
{
    $code = trim((string) ($product['code'] ?? ''));
    $name = trim((string) ($product['name'] ?? ''));
    if ($code === '' || $name === '') {
        return null;
    }
    $existing = production_part_id_by_product_code($code);
    if ($existing) {
        return $existing;
    }
    // ยังไม่มีแถวที่ผูกรหัสนี้ — ก่อนสร้างใหม่ ลองหาแถวเดิมที่เป็นอะไหล่ตัวเดียวกันแต่รหัสหลุด
    // (ลบแล้วสร้างใหม่ในคลังช่างจะได้รหัสใหม่ ถ้าสร้างแถวใหม่เลยจะกลายเป็นอะไหล่ซ้ำในทะเบียนผลิต
    //  แล้วชุดเบิกของรุ่นกับประวัติเดิมก็ยังค้างอยู่กับแถวเก่าที่เบิกไม่ได้)
    $adopt = production_sync_adopt_orphan_part($code, $name);
    if ($adopt) {
        return $adopt;
    }
    $active = !isset($product['is_active']) || (int) $product['is_active'] === 1;
    $prod = production_db();
    $stmt = $prod->prepare(
        'INSERT INTO parts (part_code, stock_code, name, category, unit, stock_qty, stock_min, dealer, link, icon_path, is_active)
         VALUES (?, ?, ?, NULL, ?, ?, ?, NULL, ?, NULL, ?)'
    );
    $stmt->execute([
        $code,
        $code,
        $name,
        trim((string) ($product['unit'] ?? '')) ?: 'ชิ้น',
        (float) ($product['quantity'] ?? 0),
        (float) ($product['min_stock'] ?? 0),
        trim((string) ($product['purchase_link'] ?? '')) ?: null,
        $active ? 1 : 0,
    ]);
    return (int) $prod->lastInsertId();
}

/**
 * หาแถว parts เดิมที่เป็นอะไหล่ตัวเดียวกัน แล้วผูกรหัสใหม่ให้ (แทนการสร้างแถวซ้ำ)
 *
 * เงื่อนไข: ชื่อตรงกัน และรหัสเดิมของแถวนั้น "ใช้ไม่ได้แล้ว" คือว่างเปล่า
 * หรือชี้ไปยังรหัสที่ไม่มีในคลังช่างแล้ว — ถ้ารหัสเดิมยังใช้ได้อยู่ถือว่าเป็นคนละตัว ไม่แตะ
 *
 * @param string $code รหัสใหม่ใน products.code
 * @param string $name ชื่ออะไหล่
 * @return int|null part id ที่ผูกให้แล้ว
 */
function production_sync_adopt_orphan_part(string $code, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $prod = production_db();
    $st = $prod->prepare('SELECT id, stock_code FROM parts WHERE TRIM(name) = ? ORDER BY id LIMIT 5');
    $st->execute([$name]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return null;
    }
    $live = [];
    $stock = tech_parts_sync_db();
    foreach ($rows as $r) {
        $sc = trim((string) ($r['stock_code'] ?? ''));
        if ($sc === '') {
            continue;
        }
        $chk = $stock->prepare('SELECT 1 FROM products WHERE code = ? LIMIT 1');
        $chk->execute([$sc]);
        if ($chk->fetchColumn()) {
            $live[(int) $r['id']] = true;
        }
    }
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        if (isset($live[$id])) {
            continue;   // แถวนี้ยังผูกกับของที่มีอยู่จริง = คนละตัวกัน
        }
        $up = $prod->prepare('UPDATE parts SET stock_code = ?, is_active = 1 WHERE id = ?');
        $up->execute([$code, $id]);
        return $id;
    }
    return null;
}

/**
 * sync สถานะการใช้งานไป production.parts ตาม stock_code
 *
 * @param string $stockCode รหัส products.code
 * @param bool   $active
 * @return void
 */
function production_sync_product_active_by_code(string $stockCode, bool $active): void
{
    $stockCode = trim($stockCode);
    if ($stockCode === '') {
        return;
    }
    $prod = production_db();
    $st = $prod->prepare('UPDATE parts SET is_active = ? WHERE stock_code = ?');
    $st->execute([$active ? 1 : 0, $stockCode]);
}

/**
 * อัปเดต icon_path ใน production.parts ตาม stock_code
 *
 * @param string      $stockCode รหัส products.code
 * @param string|null $iconPath  relative path ใต้ uploads
 * @return bool true ถ้ามีแถว parts ที่อัปเดต
 */
function production_sync_part_icon_by_code(string $stockCode, ?string $iconPath): bool
{
    $stockCode = trim($stockCode);
    if ($stockCode === '') {
        return false;
    }
    $partId = production_part_id_by_product_code($stockCode);
    if (!$partId) {
        return false;
    }
    $path = trim((string) $iconPath);
    $prod = production_db();
    $st = $prod->prepare('UPDATE parts SET icon_path = ? WHERE stock_code = ?');
    $st->execute([$path !== '' ? $path : null, $stockCode]);
    return $st->rowCount() > 0;
}

/**
 * อัปเดต part_code ใน production.parts ตาม stock_code
 *
 * @param string $stockCode รหัส products.code
 * @param string $partCode  ค่า Code Part (ว่างได้)
 * @return bool true ถ้ามีแถว parts ที่อัปเดต
 */
function production_sync_part_code_by_code(string $stockCode, string $partCode): bool
{
    $stockCode = trim($stockCode);
    if ($stockCode === '') {
        return false;
    }
    $partId = production_part_id_by_product_code($stockCode);
    if (!$partId) {
        return false;
    }
    $partCode = trim($partCode);
    $prod = production_db();
    $st = $prod->prepare('UPDATE parts SET part_code = ? WHERE stock_code = ?');
    $st->execute([$partCode !== '' ? $partCode : null, $stockCode]);
    return $st->rowCount() > 0;
}

/**
 * อัปเดตชื่ออะไหล่ใน production.parts ตาม stock_code
 *
 * ฝั่งแสดงผลใช้ชื่อจาก production ก่อน (part_product_display_name) — ถ้าไม่ sync ตรงนี้
 * ชื่อที่แก้ในระบบ Stock จะถูกบันทึกแต่ไม่มีวันแสดง
 *
 * @param string $stockCode รหัส products.code
 * @param string $name      ชื่ออะไหล่ใหม่
 * @return bool true ถ้ามีแถว parts ที่อัปเดต
 */
function production_sync_part_name_by_code(string $stockCode, string $name): bool
{
    $stockCode = trim($stockCode);
    $name = trim($name);
    if ($stockCode === '' || $name === '') {
        return false;
    }
    if (!production_part_id_by_product_code($stockCode)) {
        return false;
    }
    $prod = production_db();
    $st = $prod->prepare('UPDATE parts SET name = ? WHERE stock_code = ?');
    $st->execute([$name, $stockCode]);
    return $st->rowCount() > 0;
}

/**
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

/** จำนวนสูงสุดเมื่อขยาย S/N จาก range ต่อใบเบิก */
define('EXPAND_ASSET_CODES_MAX', 500);

/**
 * ขยาย asset_code เป็นรายการ S/N เดี่ยว — ถ้าเป็น range ดึงจาก biton_production.assets
 *
 * @param string $assetCode ค่า stock_out.asset_code (เดี่ยวหรือ "START - END")
 * @return array<int,string>
 */
function expand_asset_codes(string $assetCode): array
{
    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        return [''];
    }
    if (strpos($assetCode, ' - ') === false) {
        return [$assetCode];
    }

    $parts = explode(' - ', $assetCode, 2);
    $start = trim($parts[0]);
    $end = trim($parts[1] ?? '');
    if ($start === '' || $end === '') {
        return [$assetCode];
    }
    if (!preg_match('/^[A-Za-z0-9]+$/', $start) || !preg_match('/^[A-Za-z0-9]+$/', $end)) {
        return [$assetCode];
    }

    try {
        $prod = production_db();
        $limit = (int) EXPAND_ASSET_CODES_MAX + 1;
        $st = $prod->prepare(
            "SELECT asset_code FROM assets WHERE asset_code >= ? AND asset_code <= ? ORDER BY asset_code LIMIT {$limit}"
        );
        $st->execute([$start, $end]);
        $codes = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($codes) > EXPAND_ASSET_CODES_MAX) {
            $codes = array_slice($codes, 0, EXPAND_ASSET_CODES_MAX);
        }
        if ($codes) {
            return array_values(array_map('strval', $codes));
        }
    } catch (Throwable $e) {
        error_log('[expand_asset_codes] ' . $e->getMessage());
    }

    return [$assetCode];
}

/**
 * แมป manual production part id → products.code (กรณีชื่อไม่ตรงกัน)
 *
 * @return array<int,string>
 */
function production_manual_stock_code_map(): array
{
    return [
        30  => 'P00030', // LED Red 3mm → LED แดง 3mm
        33  => 'P00033', // Capacitor 4700uF 35V → C4700 35v
        34  => 'P00034', // C 100μF 25V → c100 25v
        55  => 'P00055', // C 10000μF 35V → C10000 35v
        58  => 'P00058', // C 220μF 50V → C220 50v
        60  => 'P00060', // Inductor 12uH → coil 12uH
        62  => 'P00062', // C 1μF 50V → C 1uF 50v
        63  => 'P00063', // CR2032 → batt. CR2032
        64  => 'P00064', // 10440 AAA → batt. AAA bitScan
        119 => 'P00121', // USB Type-A → USB Connector Type A
        132 => 'P00134', // ชุดสายแพร 40P → ชุดสายแพร + IDE 40 Pin
        136 => 'P00138', // ข้องอ Type-C 180
    ];
}

/**
 * normalize ชื่ออะไหล่เพื่อเทียบ fuzzy
 *
 * @param string $label
 * @return string
 */
function production_match_label_normalize(string $label): string
{
    $s = mb_strtolower(trim($label));
    $s = str_replace(['μ', 'µ', 'uf'], 'u', $s);
    $s = preg_replace('/[^a-z0-9ก-๙]+/u', '', $s);
    return $s;
}

/**
 * โหลด products ทั้งหมดจาก biton_tech_parts ครั้งเดียวต่อ request (cache ใน memory)
 *
 * @param PDO $tech
 * @return array<int, array{id:int,code:string,name:string,unit:string}>
 */
function production_tech_products_all(PDO $tech): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $st = $tech->query('SELECT id, code, name, unit FROM products');
    if ($st) {
        while ($row = $st->fetch()) {
            $cache[] = $row;
        }
    }
    return $cache;
}

/**
 * หา products ใน biton_tech_parts ที่ตรงกับแถว parts ของ production
 *
 * ลำดับ: stock_code ถูกต้อง → manual map → part_code=code → ชื่อตรง → prefix สองทาง → normalize
 *
 * @param array<string,mixed> $partRow ต้องมี id, name, stock_code, part_code
 * @param PDO                 $tech
 * @return array{id:int,code:string,name:string,unit:string}|null
 */
function production_match_tech_product_row(array $partRow, PDO $tech): ?array
{
    static $matchCache = [];

    $partId = (int) ($partRow['id'] ?? 0);
    $stock = trim((string) ($partRow['stock_code'] ?? ''));
    $partCode = trim((string) ($partRow['part_code'] ?? ''));
    $name = trim((string) ($partRow['name'] ?? ''));

    if ($partId > 0) {
        $cacheKey = $partId . '|' . $stock . '|' . $partCode . '|' . $name;
        if (array_key_exists($cacheKey, $matchCache)) {
            return $matchCache[$cacheKey];
        }
    }
    if ($stock !== '') {
        $st = $tech->prepare('SELECT id, code, name, unit FROM products WHERE code = ? LIMIT 1');
        $st->execute([$stock]);
        $p = $st->fetch();
        if ($p) {
            if ($partId > 0) {
                $matchCache[$cacheKey] = $p;
            }
            return $p;
        }
    }

    $manual = production_manual_stock_code_map();
    if ($partId > 0 && isset($manual[$partId])) {
        $st = $tech->prepare('SELECT id, code, name, unit FROM products WHERE code = ? LIMIT 1');
        $st->execute([$manual[$partId]]);
        $p = $st->fetch();
        if ($p) {
            $matchCache[$cacheKey] = $p;
            return $p;
        }
    }

    if ($partCode !== '') {
        $st = $tech->prepare('SELECT id, code, name, unit FROM products WHERE code = ? LIMIT 1');
        $st->execute([$partCode]);
        $p = $st->fetch();
        if ($p) {
            if ($partId > 0) {
                $matchCache[$cacheKey] = $p;
            }
            return $p;
        }
    }

    if ($name === '') {
        if ($partId > 0) {
            $matchCache[$cacheKey] = null;
        }
        return null;
    }

    $st = $tech->prepare('SELECT id, code, name, unit FROM products WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $p = $st->fetch();
    if ($p) {
        if ($partId > 0) {
            $matchCache[$cacheKey] = $p;
        }
        return $p;
    }

    $st = $tech->prepare(
        'SELECT id, code, name, unit FROM products WHERE ? LIKE CONCAT(name, "%") ORDER BY CHAR_LENGTH(name) DESC LIMIT 1'
    );
    $st->execute([$name]);
    $p = $st->fetch();
    if ($p) {
        if ($partId > 0) {
            $matchCache[$cacheKey] = $p;
        }
        return $p;
    }

    $st = $tech->prepare(
        'SELECT id, code, name, unit FROM products WHERE name LIKE CONCAT(?, "%") ORDER BY CHAR_LENGTH(name) ASC LIMIT 1'
    );
    $st->execute([$name]);
    $p = $st->fetch();
    if ($p) {
        if ($partId > 0) {
            $matchCache[$cacheKey] = $p;
        }
        return $p;
    }

    $norm = production_match_label_normalize($name);
    if ($norm === '') {
        if ($partId > 0) {
            $matchCache[$cacheKey] = null;
        }
        return null;
    }
    $best = null;
    $bestLen = 0;
    foreach (production_tech_products_all($tech) as $row) {
        $tn = production_match_label_normalize((string) $row['name']);
        if ($tn === '') {
            continue;
        }
        if ($norm === $tn
            || strpos($norm, $tn) === 0
            || strpos($tn, $norm) === 0
            || strpos($tn, $norm) !== false
            || strpos($norm, $tn) !== false) {
            $len = strlen($tn);
            if ($len > $bestLen) {
                $best = $row;
                $bestLen = $len;
            }
        }
    }
    if ($partId > 0) {
        $matchCache[$cacheKey] = $best ?: null;
    }
    return $best ?: null;
}

/**
 * อัปเดต parts.stock_code ใน biton_production ให้ตรง products.code ใน biton_tech_parts
 *
 * @param bool $dryRun true = แสดงผลอย่างเดียว ไม่ UPDATE
 * @return array{updated:int,skipped:int,unchanged:int,unmapped:array<int,string>}
 */
function production_sync_stock_codes(bool $dryRun = false): array
{
    $prod = production_db();
    $tech = tech_parts_sync_db();
    $stats = ['updated' => 0, 'skipped' => 0, 'unchanged' => 0, 'unmapped' => []];

    $st = $prod->query('SELECT id, name, part_code, stock_code FROM parts ORDER BY id');
    while ($row = $st->fetch()) {
        $partId = (int) $row['id'];
        $current = trim((string) ($row['stock_code'] ?? ''));
        $match = production_match_tech_product_row($row, $tech);
        if (!$match) {
            $stats['unmapped'][$partId] = (string) $row['name'];
            continue;
        }
        $target = (string) $match['code'];
        if ($current === $target) {
            $stats['unchanged']++;
            continue;
        }
        if ($dryRun) {
            echo "  would set part #{$partId} [{$row['name']}] stock_code: "
                . ($current !== '' ? $current : '(ว่าง)') . " → {$target} ({$match['name']})\n";
            $stats['updated']++;
            continue;
        }
        $up = $prod->prepare('UPDATE parts SET stock_code = ? WHERE id = ?');
        $up->execute([$target, $partId]);
        $stats['updated']++;
    }
    return $stats;
}

/**
 * รัน production_sync_stock_codes ครั้งเดียวต่อ HTTP/CLI request (กัน timeout ตอน bulk sync)
 *
 * @param bool $dryRun
 * @return array{updated:int,skipped:int,unchanged:int,unmapped:array<int,string>}
 */
function production_sync_stock_codes_once(bool $dryRun = false): array
{
    static $done = false;
    static $stats = ['updated' => 0, 'skipped' => 0, 'unchanged' => 0, 'unmapped' => []];
    if ($done) {
        return $stats;
    }
    $stats = production_sync_stock_codes($dryRun);
    $done = true;
    return $stats;
}

/**
 * map part_id (production) → product ใน biton_tech_parts
 *
 * @param int $partId
 * @return array{id:int,code:string,name:string,unit:string}|null
 */
function production_resolve_tech_product(int $partId): ?array
{
    static $cache = [];
    if ($partId <= 0) {
        return null;
    }
    if (array_key_exists($partId, $cache)) {
        return $cache[$partId];
    }
    $prod = production_db();
    $st = $prod->prepare('SELECT id, name, stock_code, part_code, unit FROM parts WHERE id = ? LIMIT 1');
    $st->execute([$partId]);
    $row = $st->fetch();
    if (!$row) {
        $cache[$partId] = null;
        return null;
    }
    $cache[$partId] = production_match_tech_product_row($row, tech_parts_sync_db());
    return $cache[$partId];
}

/**
 * ดึง part_movements ที่ยังไม่ผูก stock_out — สำหรับแสดงใน history Parts
 *
 * @return array<int,array<string,mixed>>
 */
function production_unlinked_withdrawal_rows(): array
{
    $prod = production_db();
    $sql = "SELECT pm.id AS movement_id, pm.qty, pm.moved_at, pm.mode, pm.made_by, pm.remark,
                   a.asset_code, pt.id AS part_id, pt.name AS part_name, pt.unit AS part_unit, pt.stock_code
            FROM part_movements pm
            INNER JOIN assets a ON a.id = pm.ref_asset_id
            INNER JOIN parts pt ON pt.id = pm.part_id
            WHERE pm.direction = 'out'
              AND (pm.tech_stock_out_id IS NULL OR pm.tech_stock_out_id = 0)
              AND TRIM(COALESCE(a.asset_code, '')) <> ''
            ORDER BY pm.moved_at DESC, pm.id DESC";
    $st = $prod->query($sql);
    if (!$st) {
        return [];
    }
    $rows = [];
    while ($row = $st->fetch()) {
        $product = production_resolve_tech_product((int) $row['part_id']);
        $rows[] = [
            'movement_id'   => (int) $row['movement_id'],
            'asset_code'    => (string) $row['asset_code'],
            'moved_at'      => (string) $row['moved_at'],
            'qty'           => (float) $row['qty'],
            'mode'          => (string) ($row['mode'] ?? ''),
            'made_by'       => (string) ($row['made_by'] ?? ''),
            'remark'        => (string) ($row['remark'] ?? ''),
            'part_name'     => (string) $row['part_name'],
            'part_unit'     => (string) ($row['part_unit'] ?? 'ชิ้น'),
            'product_code'  => $product ? (string) $product['code'] : '',
            'product_name'  => $product ? (string) $product['name'] : (string) $row['part_name'],
            'product_unit'  => $product ? (string) ($product['unit'] ?? 'ชิ้น') : (string) ($row['part_unit'] ?? 'ชิ้น'),
        ];
    }
    return $rows;
}

/**
 * แปลง mode จาก part_movements เป็นหมายเหตุ stock_out มาตรฐาน
 *
 * รองรับ legacy จาก Google Sheet (เช่น "Card Camera: Out") และ mode ผลิต/BOM
 *
 * @param string|null $mode
 * @return string
 */
function production_stock_out_note_from_mode(?string $mode): string
{
    $mode = trim((string) $mode);
    if ($mode === '') {
        return 'เบิกผลิต';
    }
    if (preg_match('/: Out\s*$/i', $mode)) {
        return 'เบิกผลิต';
    }
    if ($mode === 'MA') {
        return 'MA';
    }
    if ($mode === 'เบิกใช้') {
        return 'เบิกใช้';
    }
    if ($mode === 'ซ่อม') {
        return 'เบิกงานซ่อม';
    }
    if (strpos($mode, 'ผลิต') !== false || strpos($mode, 'BOM') !== false || strpos($mode, 'ชุดอะไหล่') !== false) {
        return 'เบิกผลิต';
    }
    return $mode;
}

/**
 * แปลง mode legacy ใน production เป็นค่ามาตรฐาน (สำหรับ backfill part_movements.mode)
 *
 * @param string|null $mode
 * @return string|null null = ไม่ต้องเปลี่ยน
 */
function production_normalize_movement_mode(?string $mode): ?string
{
    $mode = trim((string) $mode);
    if ($mode === '') {
        return null;
    }
    if (preg_match('/: Out\s*$/i', $mode)) {
        return 'เบิกผลิต';
    }
    return null;
}

/**
 * แปลงแถว production movement เป็นรูปแบบเดียวกับ stock_out สำหรับ history UI
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function production_movement_as_history_item(array $row): array
{
    $mid = (int) ($row['movement_id'] ?? 0);
    $qty = (int) round((float) ($row['qty'] ?? 0));
    $code = trim((string) ($row['product_code'] ?? ''));
    $name = trim((string) ($row['product_name'] ?? ''));
    return [
        'id'                  => -$mid,
        'doc_no'              => 'PROD-' . $mid,
        'set_id'              => null,
        'set_code'            => null,
        'set_name'            => null,
        'note'                => production_stock_out_note_from_mode($row['mode'] ?? ''),
        'issued_by'           => (string) ($row['made_by'] ?? ''),
        'created_at'          => (string) ($row['moved_at'] ?? ''),
        'asset_code'          => (string) ($row['asset_code'] ?? ''),
        'part_movement_id'    => $mid,
        'total_qty'           => $qty,
        'single_product_code' => $code !== '' ? $code : '-',
        'single_product_name' => $name,
        'source'              => 'production',
    ];
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
    $techDb->prepare(
        'UPDATE stock_out SET asset_code = COALESCE(NULLIF(TRIM(asset_code), ""), ?) WHERE id = ?'
    )->execute([trim((string) $assetCode) ?: null, $stockOutId]);

    try {
        $prod = production_db();
        $mvSt = $prod->prepare('SELECT part_id FROM part_movements WHERE id = ?');
        $mvSt->execute([$movementId]);
        $mv = $mvSt->fetch();
        if ($mv && function_exists('production_resolve_tech_product')) {
            $product = production_resolve_tech_product((int) $mv['part_id']);
            if ($product) {
                $techDb->prepare(
                    'UPDATE stock_out_items SET part_movement_id = ?
                     WHERE stock_out_id = ? AND product_id = ?
                       AND (part_movement_id IS NULL OR part_movement_id = 0 OR part_movement_id = ?)
                     LIMIT 1'
                )->execute([$movementId, $stockOutId, (int) $product['id'], $movementId]);
            }
        }
        $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE id = ?')
            ->execute([$stockOutId, $movementId]);
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
    $cfg = require app_finishgoogs_secrets_path();
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
    if (!$row) {
        return false;
    }

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $outId = (int) ($row['tech_stock_out_id'] ?? 0);
    $item = null;

    if ($outId > 0) {
        $detail = $tech->prepare(
            'SELECT soi.id, soi.product_id, soi.quantity, p.quantity AS stock_qty
             FROM stock_out_items soi
             JOIN products p ON p.id = soi.product_id
             WHERE soi.stock_out_id = ? AND soi.part_movement_id = ?
             LIMIT 1'
        );
        $detail->execute([$outId, $movementId]);
        $item = $detail->fetch();
        if (!$item) {
            $detail = $tech->prepare(
                'SELECT soi.id, soi.product_id, soi.quantity, p.quantity AS stock_qty
                 FROM stock_out_items soi
                 JOIN products p ON p.id = soi.product_id
                 WHERE soi.stock_out_id = ?
                 LIMIT 1'
            );
            $detail->execute([$outId]);
            $item = $detail->fetch();
        }
    } else {
        $detail = $tech->prepare(
            'SELECT soi.id, soi.stock_out_id, soi.product_id, soi.quantity, p.quantity AS stock_qty
             FROM stock_out_items soi
             JOIN products p ON p.id = soi.product_id
             WHERE soi.part_movement_id = ?
             LIMIT 1'
        );
        $detail->execute([$movementId]);
        $item = $detail->fetch();
        if ($item) {
            $outId = (int) $item['stock_out_id'];
        }
    }

    if (!$item || $outId <= 0) {
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
            ->execute([trim((string) $assetCode) ?: null, production_stock_out_note_from_mode($note), $outId]);
        $tech->commit();

        if (empty($row['tech_stock_out_id'])) {
            $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE id = ?')->execute([$outId, $movementId]);
        }
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

    $headerSt = $tech->prepare('SELECT stock_deducted FROM stock_out WHERE id = ?');
    $headerSt->execute([$stockOutId]);
    $headerRow = $headerSt->fetch();
    $returnStock = production_stock_out_was_deducted_row($headerRow);

    $items = $tech->prepare('SELECT product_id, quantity FROM stock_out_items WHERE stock_out_id = ?');
    $items->execute([$stockOutId]);
    $rows = $items->fetchAll();

    $tech->beginTransaction();
    try {
        if ($returnStock) {
            foreach ($rows as $r) {
                $tech->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                    ->execute([(int) $r['quantity'], (int) $r['product_id']]);
            }
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
    if ($movementId <= 0) {
        return false;
    }

    $prod = production_db();
    $st = $prod->prepare('SELECT tech_stock_out_id, part_id, qty FROM part_movements WHERE id = ?');
    $st->execute([$movementId]);
    $row = $st->fetch();
    if (!$row) {
        return false;
    }

    $outId = (int) ($row['tech_stock_out_id'] ?? 0);
    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $itemSt = $tech->prepare(
        'SELECT soi.id, soi.stock_out_id, soi.product_id, soi.quantity, so.set_id
         FROM stock_out_items soi
         JOIN stock_out so ON so.id = soi.stock_out_id
         WHERE soi.part_movement_id = ?
         LIMIT 1'
    );
    $itemSt->execute([$movementId]);
    $item = $itemSt->fetch();

    if ($item) {
        $headerId = (int) $item['stock_out_id'];
        $dedSt = $tech->prepare('SELECT stock_deducted FROM stock_out WHERE id = ?');
        $dedSt->execute([$headerId]);
        $returnStock = production_stock_out_was_deducted_row($dedSt->fetch());
        $tech->beginTransaction();
        try {
            if ($returnStock) {
                $tech->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                    ->execute([(int) $item['quantity'], (int) $item['product_id']]);
            }
            $tech->prepare('DELETE FROM stock_out_items WHERE id = ?')->execute([(int) $item['id']]);
            $remain = $tech->prepare('SELECT COUNT(*) FROM stock_out_items WHERE stock_out_id = ?');
            $remain->execute([$headerId]);
            $cnt = (int) $remain->fetchColumn();
            if ($cnt === 0 && $headerId > 0) {
                $tech->prepare('DELETE FROM stock_out WHERE id = ?')->execute([$headerId]);
            }
            $tech->commit();
        } catch (Throwable $e) {
            if ($tech->inTransaction()) {
                $tech->rollBack();
            }
            error_log('[production_sync_delete_stock_out_from_movement item] ' . $e->getMessage());
            return false;
        }
        production_delete_out_movement($movementId);
        return true;
    }

    if ($outId <= 0) {
        return false;
    }

    return production_sync_delete_stock_out_by_id($outId);
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

/**
 * ผูก part_movements ↔ stock_out หนึ่งคู่ (สองทาง)
 *
 * @param PDO    $tech
 * @param int    $movementId
 * @param int    $outId
 * @param string $assetCode
 * @param int    $productId
 * @param int    $qty
 * @return void
 */
function production_sync_link_movement_pair(PDO $tech, int $movementId, int $outId, string $assetCode, int $productId, int $qty): void
{
    $tech->prepare(
        'UPDATE stock_out SET asset_code = COALESCE(NULLIF(TRIM(asset_code), ""), ?) WHERE id = ?'
    )->execute([$assetCode !== '' ? $assetCode : null, $outId]);

    $tech->prepare(
        'UPDATE stock_out_items SET part_movement_id = ?
         WHERE stock_out_id = ? AND product_id = ? AND quantity = ?
           AND (part_movement_id IS NULL OR part_movement_id = 0 OR part_movement_id = ?)
         LIMIT 1'
    )->execute([$movementId, $outId, $productId, $qty, $movementId]);

    $prod = production_db();
    $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE id = ?')->execute([$outId, $movementId]);
}

/**
 * รายการ stock_out.id ที่ production ผูกกับ movement อื่นแล้ว
 *
 * @param int $excludeMovementId ไม่นับ movement นี้
 * @return array<int, int>
 */
function production_sync_taken_stock_out_ids(int $excludeMovementId = 0): array
{
    $prod = production_db();
    $st = $prod->prepare(
        'SELECT DISTINCT tech_stock_out_id FROM part_movements
         WHERE tech_stock_out_id IS NOT NULL AND tech_stock_out_id > 0 AND id <> ?'
    );
    $st->execute([$excludeMovementId]);
    $ids = [];
    while ($row = $st->fetch()) {
        $ids[] = (int) $row['tech_stock_out_id'];
    }
    return $ids;
}

/**
 * ค้นหา stock_out ที่ยังไม่ผูก — จับคู่ตาม S/N + สินค้า + จำนวน + วันที่ (หรือไม่มี S/N)
 *
 * @param PDO    $tech
 * @param string $assetCode
 * @param int    $productId
 * @param int    $qty
 * @param string $mDate   Y-m-d
 * @param int    $movementId
 * @return int stock_out.id หรือ 0
 */
function production_sync_find_unlinked_stock_out(PDO $tech, string $assetCode, int $productId, int $qty, string $mDate, int $movementId): int
{
    $taken = production_sync_taken_stock_out_ids($movementId);
    $excludeSql = '';
    $excludeParams = [];
    if ($taken !== []) {
        $ph = implode(',', array_fill(0, count($taken), '?'));
        $excludeSql = " AND so.id NOT IN ($ph)";
        $excludeParams = $taken;
    }

    $queries = [];
    if ($assetCode !== '') {
        $queries[] = [
            "SELECT so.id AS out_id
             FROM stock_out so
             INNER JOIN stock_out_items soi ON soi.stock_out_id = so.id
             WHERE TRIM(so.asset_code) = ?
               AND soi.product_id = ? AND soi.quantity = ? AND DATE(so.created_at) = ?
               AND (so.part_movement_id IS NULL OR so.part_movement_id = 0 OR so.part_movement_id = ?)"
            . $excludeSql . '
             LIMIT 1',
            array_merge([$assetCode, $productId, $qty, $mDate, $movementId], $excludeParams),
        ];
    }
    $queries[] = [
        "SELECT so.id AS out_id
         FROM stock_out so
         INNER JOIN stock_out_items soi ON soi.stock_out_id = so.id
         WHERE (so.asset_code IS NULL OR TRIM(so.asset_code) = '')
           AND soi.product_id = ? AND soi.quantity = ? AND DATE(so.created_at) = ?
           AND (so.part_movement_id IS NULL OR so.part_movement_id = 0)"
        . $excludeSql . '
         LIMIT 1',
        array_merge([$productId, $qty, $mDate], $excludeParams),
    ];

    foreach ($queries as [$sql, $params]) {
        $find = $tech->prepare($sql);
        $find->execute($params);
        $match = $find->fetch();
        if ($match) {
            return (int) $match['out_id'];
        }
    }
    return 0;
}

/**
 * ลบ stock_out ทั้งหมดของ S/N (คืนสต็ockถ้าเคยหัก) — ไม่ลบ part_movements ใน production
 *
 * @param string $assetCode
 * @param bool   $returnStock
 * @return int จำนวนใบที่ลบ
 */
function production_purge_stock_out_for_sn(string $assetCode, bool $returnStock = true): int
{
    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        return 0;
    }

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);
    $ids = production_stock_out_ids_for_sn($tech, $assetCode);
    $deleted = 0;
    foreach ($ids as $oid) {
        if (production_sync_delete_stock_out_doc($tech, $oid, $returnStock)) {
            $deleted++;
        }
    }

    $prod = production_db();
    $prod->prepare(
        'UPDATE part_movements pm
         INNER JOIN assets a ON a.id = pm.ref_asset_id
         SET pm.tech_stock_out_id = NULL
         WHERE TRIM(a.asset_code) = ? AND pm.direction = ?'
    )->execute([$assetCode, 'out']);

    return $deleted;
}

/**
 * ลบใบเบิกที่ใช้ S/N แบบช่วง (เช่น BP26062001 - BP26072024) — ไม่ตรง production รายเครื่อง
 *
 * @param bool $dryRun
 * @return array{ok:bool,removed:int,errors:array<int,string>}
 */
function production_delete_range_stock_out_docs(bool $dryRun = false): array
{
    $result = ['ok' => true, 'removed' => 0, 'errors' => []];
    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $st = $tech->query(
        "SELECT id, doc_no, asset_code FROM stock_out
         WHERE asset_code LIKE '% - %' ORDER BY id"
    );
    while ($row = $st->fetch()) {
        $oid = (int) $row['id'];
        if ($dryRun) {
            $result['removed']++;
            continue;
        }
        if (!production_sync_delete_stock_out_doc($tech, $oid, true)) {
            $result['errors'][] = "ลบ stock_out #{$oid} [{$row['asset_code']}] ไม่สำเร็จ";
            $result['ok'] = false;
            continue;
        }
        $result['removed']++;
    }
    return $result;
}

// ─ Consolidated stock_out per S/N ─────────────────────────────────────────────

/**
 * รายการ stock_out.id ของ S/N (เรียง id น้อย → มาก)
 *
 * @param PDO    $tech
 * @param string $assetCode
 * @return array<int,int>
 */
function production_stock_out_ids_for_sn(PDO $tech, string $assetCode): array
{
    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        return [];
    }
    $st = $tech->prepare(
        'SELECT id FROM stock_out WHERE TRIM(COALESCE(asset_code, "")) = ? ORDER BY id ASC'
    );
    $st->execute([$assetCode]);
    $ids = [];
    while ($row = $st->fetch()) {
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/**
 * เลือกใบ canonical ของ S/N — ใบที่มี items มากสุด แล้ว id น้อยสุด
 *
 * @param PDO    $tech
 * @param string $assetCode
 * @return int stock_out.id หรือ 0
 */
function production_stock_out_canonical_for_sn(PDO $tech, string $assetCode): int
{
    $ids = production_stock_out_ids_for_sn($tech, $assetCode);
    if ($ids === []) {
        return 0;
    }
    if (count($ids) === 1) {
        return $ids[0];
    }

    $bestId = $ids[0];
    $bestCnt = -1;
    $cntSt = $tech->prepare('SELECT COUNT(*) FROM stock_out_items WHERE stock_out_id = ?');
    foreach ($ids as $oid) {
        $cntSt->execute([$oid]);
        $cnt = (int) $cntSt->fetchColumn();
        if ($cnt > $bestCnt || ($cnt === $bestCnt && $oid < $bestId)) {
            $bestCnt = $cnt;
            $bestId = $oid;
        }
    }
    return $bestId;
}

/**
 * หา/สร้างใบเบิกเดียวของ S/N
 *
 * @param PDO         $tech
 * @param string      $assetCode
 * @param string      $note
 * @param string      $issuedBy
 * @param string|null $createdAt
 * @param bool        $stockDeducted ค่า stock_deducted ของใบใหม่
 * @return int stock_out.id
 */
function production_get_or_create_stock_out_for_sn(
    PDO $tech,
    string $assetCode,
    string $note,
    string $issuedBy,
    ?string $createdAt = null,
    bool $stockDeducted = true
): int {
    $assetCode = trim($assetCode);
    $note = production_stock_out_note_from_mode($note);
    if ($assetCode === '') {
        throw new InvalidArgumentException('asset_code ว่าง');
    }

    $existing = production_stock_out_canonical_for_sn($tech, $assetCode);
    if ($existing > 0) {
        return $existing;
    }

    if (!function_exists('generateDocNo')) {
        throw new RuntimeException('generateDocNo ไม่พร้อมใช้งาน');
    }

    $docNo = generateDocNo($tech);
    $deductedVal = $stockDeducted ? 1 : 0;
    $tech->prepare(
        'INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code, part_movement_id, stock_deducted, created_at)
         VALUES (?, NULL, ?, ?, ?, NULL, ?, ?)'
    )->execute([
        $docNo,
        $note !== '' ? $note : 'เบิกใช้',
        $issuedBy !== '' ? $issuedBy : 'production',
        $assetCode,
        $deductedVal,
        $createdAt !== null && $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
    ]);

    return (int) $tech->lastInsertId();
}

/**
 * เพิ่มรายการในใบเบิก — กัน duplicate ตาม part_movement_id
 *
 * @param PDO  $tech
 * @param int  $outId
 * @param int  $productId
 * @param int  $qty
 * @param int  $movementId
 * @param bool $deductStock หักสต็ockจริง
 * @return int stock_out_items.id
 */
function production_stock_out_add_item(
    PDO $tech,
    int $outId,
    int $productId,
    int $qty,
    int $movementId = 0,
    bool $deductStock = true
): int {
    if ($outId <= 0 || $productId <= 0 || $qty <= 0) {
        throw new InvalidArgumentException('outId/productId/qty ไม่ถูกต้อง');
    }

    if ($movementId > 0) {
        $existItem = $tech->prepare(
            'SELECT id FROM stock_out_items WHERE stock_out_id = ? AND part_movement_id = ? LIMIT 1'
        );
        $existItem->execute([$outId, $movementId]);
        $existId = (int) ($existItem->fetchColumn() ?: 0);
        if ($existId > 0) {
            return $existId;
        }
    }

    if ($deductStock) {
        $pq = $tech->prepare('SELECT quantity FROM products WHERE id = ? FOR UPDATE');
        $pq->execute([$productId]);
        $prow = $pq->fetch();
        if (!$prow || (int) $prow['quantity'] < $qty) {
            throw new RuntimeException('สต็ock tech_parts ไม่พอสำหรับเบิก');
        }
        $tech->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')
            ->execute([$qty, $productId]);
    }

    $tech->prepare(
        'INSERT INTO stock_out_items (stock_out_id, product_id, quantity, part_movement_id)
         VALUES (?, ?, ?, ?)'
    )->execute([$outId, $productId, $qty, $movementId > 0 ? $movementId : null]);

    return (int) $tech->lastInsertId();
}

/**
 * ลบ stock_out + items (ไม่แตะ production DB)
 *
 * @param PDO  $tech
 * @param int  $outId
 * @param bool $returnStock คืนสต็ockจาก items
 * @return bool
 */
function production_sync_delete_stock_out_doc(PDO $tech, int $outId, bool $returnStock): bool
{
    if ($outId <= 0) {
        return false;
    }
    $check = $tech->prepare('SELECT id, stock_deducted FROM stock_out WHERE id = ?');
    $check->execute([$outId]);
    $header = $check->fetch();
    if (!$header) {
        return false;
    }

    $items = $tech->prepare('SELECT product_id, quantity FROM stock_out_items WHERE stock_out_id = ?');
    $items->execute([$outId]);
    $rows = $items->fetchAll();

    $doReturn = $returnStock && production_stock_out_was_deducted_row($header);
    if ($doReturn) {
        foreach ($rows as $r) {
            $tech->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                ->execute([(int) $r['quantity'], (int) $r['product_id']]);
        }
    }
    $tech->prepare('DELETE FROM stock_out_items WHERE stock_out_id = ?')->execute([$outId]);
    $tech->prepare('DELETE FROM stock_out WHERE id = ?')->execute([$outId]);
    return true;
}

/**
 * รวมหลายใบเบิกของ S/N เป็นใบเดียว
 *
 * @param string $assetCode
 * @param bool   $dryRun
 * @return array{ok:bool,sn:string,before_docs:int,after_docs:int,canonical_id:int,merged:int,errors:array<int,string>}
 */
function production_consolidate_stock_outs_for_sn(string $assetCode, bool $dryRun = false): array
{
    $result = [
        'ok'           => true,
        'sn'           => trim($assetCode),
        'before_docs'  => 0,
        'after_docs'   => 0,
        'canonical_id' => 0,
        'merged'       => 0,
        'errors'       => [],
    ];

    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        $result['ok'] = false;
        $result['errors'][] = 'S/N ว่าง';
        return $result;
    }

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $ids = production_stock_out_ids_for_sn($tech, $assetCode);
    $result['before_docs'] = count($ids);
    if (count($ids) <= 1) {
        $result['after_docs'] = count($ids);
        $result['canonical_id'] = $ids[0] ?? 0;
        return $result;
    }

    $canonical = production_stock_out_canonical_for_sn($tech, $assetCode);
    $result['canonical_id'] = $canonical;
    $others = array_values(array_filter($ids, static function ($id) use ($canonical) {
        return $id !== $canonical;
    }));

    if ($dryRun) {
        $result['merged'] = count($others);
        $result['after_docs'] = 1;
        return $result;
    }

    $prod = production_db();

    try {
        $tech->beginTransaction();

        foreach ($others as $oid) {
            $itemSt = $tech->prepare(
                'SELECT id, product_id, quantity, part_movement_id FROM stock_out_items WHERE stock_out_id = ?'
            );
            $itemSt->execute([$oid]);
            while ($item = $itemSt->fetch()) {
                $itemId = (int) $item['id'];
                $pmid = (int) ($item['part_movement_id'] ?? 0);
                if ($pmid > 0) {
                    $dupSt = $tech->prepare(
                        'SELECT id FROM stock_out_items WHERE stock_out_id = ? AND part_movement_id = ? LIMIT 1'
                    );
                    $dupSt->execute([$canonical, $pmid]);
                    if ($dupSt->fetch()) {
                        $tech->prepare('DELETE FROM stock_out_items WHERE id = ?')->execute([$itemId]);
                        continue;
                    }
                }
                $tech->prepare('UPDATE stock_out_items SET stock_out_id = ? WHERE id = ?')
                    ->execute([$canonical, $itemId]);
            }

            $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE tech_stock_out_id = ?')
                ->execute([$canonical, $oid]);

            production_sync_delete_stock_out_doc($tech, $oid, false);
            $result['merged']++;
        }

        $tech->prepare('UPDATE stock_out SET part_movement_id = NULL WHERE id = ?')->execute([$canonical]);
        $tech->prepare(
            'UPDATE stock_out SET asset_code = ? WHERE id = ? AND (asset_code IS NULL OR TRIM(asset_code) = "")'
        )->execute([$assetCode, $canonical]);

        $tech->commit();
    } catch (Throwable $e) {
        if ($tech->inTransaction()) {
            $tech->rollBack();
        }
        $result['ok'] = false;
        $result['errors'][] = $e->getMessage();
        error_log('[production_consolidate_stock_outs_for_sn] ' . $e->getMessage());
        return $result;
    }

    $result['after_docs'] = count(production_stock_out_ids_for_sn($tech, $assetCode));
    return $result;
}

/**
 * สร้าง stock_out จาก movement (บันทึกย้อนหลัง — ไม่หักสต็ockซ้ำ)
 *
 * @param PDO    $tech
 * @param int    $movementId
 * @param int    $productId
 * @param int    $qty
 * @param string $assetCode
 * @param string $movedAt
 * @param string $note
 * @param string $issuedBy
 * @return int stock_out.id
 */
function production_sync_create_stock_out_from_movement(
    PDO $tech,
    int $movementId,
    int $productId,
    int $qty,
    string $assetCode,
    string $movedAt,
    string $note,
    string $issuedBy
): int {
    $assetCode = trim($assetCode);
    $note = production_stock_out_note_from_mode($note);
    if ($movementId > 0) {
        $existItem = $tech->prepare(
            'SELECT soi.stock_out_id FROM stock_out_items soi WHERE soi.part_movement_id = ? LIMIT 1'
        );
        $existItem->execute([$movementId]);
        $existingId = (int) ($existItem->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }
    }

    if ($assetCode !== '') {
        $outId = production_get_or_create_stock_out_for_sn(
            $tech,
            $assetCode,
            $note,
            $issuedBy,
            $movedAt !== '' ? $movedAt : null,
            false
        );
        production_stock_out_add_item($tech, $outId, $productId, $qty, $movementId, false);
        return $outId;
    }

    if (!function_exists('generateDocNo')) {
        throw new RuntimeException('generateDocNo ไม่พร้อมใช้งาน');
    }

    $docNo = generateDocNo($tech);
    $tech->prepare(
        'INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code, part_movement_id, stock_deducted, created_at)
         VALUES (?, NULL, ?, ?, ?, ?, 0, ?)'
    )->execute([
        $docNo,
        $note,
        $issuedBy !== '' ? $issuedBy : 'production',
        null,
        $movementId,
        $movedAt !== '' ? $movedAt : date('Y-m-d H:i:s'),
    ]);
    $outId = (int) $tech->lastInsertId();
    $tech->prepare(
        'INSERT INTO stock_out_items (stock_out_id, product_id, quantity, part_movement_id)
         VALUES (?, ?, ?, ?)'
    )->execute([$outId, $productId, $qty, $movementId]);
    return $outId;
}

/**
 * sync เบิกอะไหล่ของเครื่องหนึ่ง — ผูกใบเบิกที่มีอยู่ หรือสร้างใบเบิกย้อนหลัง (ไม่หักสต็ockซ้ำ)
 *
 * @param int $assetId
 * @return array{ok:bool,linked:int,created:int,repaired:int,skipped:int,failed:int,errors:array<int,string>}
 */
function production_sync_asset_withdrawals(int $assetId): array
{
    $result = [
        'ok'       => true,
        'linked'   => 0,
        'created'  => 0,
        'repaired' => 0,
        'skipped'  => 0,
        'failed'   => 0,
        'errors'   => [],
    ];

    if ($assetId <= 0) {
        $result['ok'] = false;
        $result['errors'][] = 'รหัสเครื่องไม่ถูกต้อง';
        return $result;
    }

    $prod = production_db();
    $stAsset = $prod->prepare('SELECT asset_code FROM assets WHERE id = ? LIMIT 1');
    $stAsset->execute([$assetId]);
    $assetRow = $stAsset->fetch();
    if (!$assetRow) {
        $result['ok'] = false;
        $result['errors'][] = 'ไม่พบเครื่อง';
        return $result;
    }
    $assetCode = trim((string) ($assetRow['asset_code'] ?? ''));
    if ($assetCode === '') {
        $result['ok'] = false;
        $result['errors'][] = 'เครื่องนี้ไม่มี S/N — sync ไม่ได้';
        return $result;
    }

    production_sync_stock_codes_once(false);

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $repairSt = $prod->prepare(
        "SELECT pm.id AS mid, pm.tech_stock_out_id AS out_id
         FROM part_movements pm
         WHERE pm.ref_asset_id = ? AND pm.direction = 'out' AND pm.tech_stock_out_id > 0"
    );
    $repairSt->execute([$assetId]);
    while ($row = $repairSt->fetch()) {
        $mid = (int) $row['mid'];
        $outId = (int) $row['out_id'];
        $chk = $tech->prepare('SELECT id, part_movement_id FROM stock_out WHERE id = ?');
        $chk->execute([$outId]);
        $so = $chk->fetch();
        if (!$so) {
            $prod->prepare('UPDATE part_movements SET tech_stock_out_id = NULL WHERE id = ?')->execute([$mid]);
            continue;
        }
        if (empty($so['part_movement_id'])) {
            try {
                production_link_stock_out($tech, $outId, $mid, $assetCode);
                $result['repaired']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "ซ่อมลิงก์ #{$mid}: {$e->getMessage()}";
            }
        }
    }

    $sql = "SELECT pm.id AS movement_id, pm.part_id, pm.qty, pm.moved_at, pm.mode, pm.made_by, pm.remark, pm.tech_stock_out_id
            FROM part_movements pm
            WHERE pm.ref_asset_id = ? AND pm.direction = 'out'
              AND (pm.tech_stock_out_id IS NULL OR pm.tech_stock_out_id = 0)
            ORDER BY pm.moved_at ASC, pm.id ASC";
    $st = $prod->prepare($sql);
    $st->execute([$assetId]);

    while ($row = $st->fetch()) {
        $mid = (int) $row['movement_id'];
        $product = production_resolve_tech_product((int) $row['part_id']);
        if (!$product) {
            $result['skipped']++;
            $result['errors'][] = "movement #{$mid}: ไม่ map รหัสอะไหล่ (part_id={$row['part_id']})";
            continue;
        }

        $qty = (int) round((float) $row['qty']);
        if ($qty <= 0) {
            $result['skipped']++;
            continue;
        }

        $movedAt = (string) $row['moved_at'];
        $note = production_stock_out_note_from_mode($row['mode'] ?? '');
        $issuedBy = trim((string) ($row['made_by'] ?? '')) ?: 'production';

        $linkedItemSt = $tech->prepare(
            'SELECT stock_out_id FROM stock_out_items WHERE part_movement_id = ? LIMIT 1'
        );
        $linkedItemSt->execute([$mid]);
        $existOutId = (int) ($linkedItemSt->fetchColumn() ?: 0);
        if ($existOutId > 0) {
            $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE id = ?')
                ->execute([$existOutId, $mid]);
            $result['linked']++;
            continue;
        }

        try {
            $tech->beginTransaction();
            $outId = production_sync_create_stock_out_from_movement(
                $tech,
                $mid,
                (int) $product['id'],
                $qty,
                $assetCode,
                $movedAt,
                $note,
                $issuedBy
            );
            $prod->prepare('UPDATE part_movements SET tech_stock_out_id = ? WHERE id = ?')
                ->execute([$outId, $mid]);
            $tech->commit();
            $result['created']++;
        } catch (Throwable $e) {
            if ($tech->inTransaction()) {
                $tech->rollBack();
            }
            $result['failed']++;
            $result['errors'][] = "movement #{$mid}: {$e->getMessage()}";
        }
    }

    $cons = production_consolidate_stock_outs_for_sn($assetCode, false);
    if (!$cons['ok']) {
        foreach ($cons['errors'] as $err) {
            $result['errors'][] = $err;
        }
    } elseif (($cons['merged'] ?? 0) > 0) {
        $result['repaired'] += (int) $cons['merged'];
    }

    if ($result['failed'] > 0 && $result['linked'] === 0 && $result['created'] === 0 && $result['repaired'] === 0) {
        $result['ok'] = false;
    }

    return $result;
}
