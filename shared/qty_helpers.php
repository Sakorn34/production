<?php
/**
 * shared/qty_helpers.php — จำนวนอะไหล่แบบทศนิยม (แหล่งความจริงเดียวของทั้งสองแอป)
 *
 * ที่มา: อะไหล่บางตัวใช้เครื่องละ 0.5 ชิ้น (เช่น ชุดสายแพร + IDE 40 Pin ของ bitvisitor S)
 * แต่เดิมฝั่ง biton_tech_parts เก็บเป็น INT และโค้ด sync ใช้ (int) round() ทำให้ 0.5 ถูกปัดเป็น 1
 * ตัดสต็อกเกินจริงเท่าตัว — ไฟล์นี้รวมกติกา "จำนวนทศนิยม 2 ตำแหน่ง" ไว้ที่เดียว
 *
 * Main components:
 *   - qty_norm / qty_sql / qty_cmp / qty_lt / qty_fmt  ปัดเศษ, ส่งเข้า SQL, เทียบ, แสดงผล
 *   - qty_post                                         อ่านและ validation จำนวนจาก $_POST
 *   - tech_parts_ensure_decimal_qty_schema()           migrate คอลัมน์ INT → DECIMAL(12,2) แบบ idempotent
 *
 * Flow: require qty_helpers.php → qty_norm($raw) ก่อนคำนวณ → qty_sql($q) ตอนส่งเข้า query
 *
 * Example:
 *   $q = qty_norm($row['qty']);               // 0.5
 *   $st->execute([qty_sql($q), $productId]);  // '0.50'
 *   echo qty_fmt($q);                         // 0.5
 *
 * ห้ามไฟล์นี้อ่าน session หรือเรียก DB ยกเว้นฟังก์ชัน schema ที่รับ PDO จากผู้เรียก
 */

// ─ Constants ──────────────────────────────────────────────────────────────────

if (!defined('QTY_SCALE')) {
    /** จำนวนตำแหน่งทศนิยมของจำนวนอะไหล่ทั้งระบบ (ต้องตรงกับ DECIMAL(12,2) ใน DB) */
    define('QTY_SCALE', 2);
}

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * ปัดจำนวนให้เหลือ 2 ตำแหน่ง และกัน -0 / ค่า float เพี้ยน (เช่น 0.1+0.2)
 *
 * @param mixed $value ตัวเลข, สตริงตัวเลข หรือ null
 * @return float
 */
function qty_norm($value): float
{
    $f = round((float) $value, QTY_SCALE);
    return $f == 0.0 ? 0.0 : $f;
}

/**
 * แปลงจำนวนเป็นสตริงสำหรับ bind เข้า SQL — ไม่พึ่ง locale และไม่ออกมาเป็น 1.0E+3
 *
 * @param mixed $value
 * @return string เช่น '0.50'
 */
function qty_sql($value): string
{
    return number_format(qty_norm($value), QTY_SCALE, '.', '');
}

/**
 * เทียบสองจำนวนอย่างปลอดภัยจากเศษ float
 *
 * @param mixed $a
 * @param mixed $b
 * @return int -1 | 0 | 1
 */
function qty_cmp($a, $b): int
{
    $d = qty_norm($a) - qty_norm($b);
    if (abs($d) < 0.005) {
        return 0;
    }
    return $d < 0 ? -1 : 1;
}

/**
 * $a น้อยกว่า $b หรือไม่ (ใช้เช็คสต็อกไม่พอ)
 *
 * @param mixed $a
 * @param mixed $b
 * @return bool
 */
function qty_lt($a, $b): bool
{
    return qty_cmp($a, $b) < 0;
}

/**
 * จำนวนนี้มีเศษทศนิยมหรือไม่
 *
 * @param mixed $value
 * @return bool
 */
function qty_has_fraction($value): bool
{
    $f = qty_norm($value);
    return abs($f - round($f)) >= 0.005;
}

/**
 * ข้อความแสดงผลจำนวน — จำนวนเต็มไม่มีจุด, มีเศษแสดงสูงสุด 2 ตำแหน่ง (ตัด 0 ท้าย)
 *
 * @param mixed $value
 * @param bool  $thousands ใส่ , คั่นหลักพัน
 * @return string เช่น '1,200' | '0.5' | '12.25'
 */
function qty_fmt($value, bool $thousands = true): string
{
    $f = qty_norm($value);
    $s = number_format($f, QTY_SCALE, '.', $thousands ? ',' : '');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s === '' || $s === '-0' ? '0' : $s;
}

/**
 * ค่าสำหรับใส่ใน <input type="number" value=""> — ไม่มี , และไม่มี 0 ท้าย
 *
 * @param mixed $value
 * @return string
 */
function qty_input_value($value): string
{
    return qty_fmt($value, false);
}

/**
 * อ่านจำนวนจาก $_POST พร้อม validation (ไม่เชื่อ input)
 *
 * รับเฉพาะตัวเลขบวกไม่เกิน 2 ตำแหน่งทศนิยม — ค่าอื่นคืน 0.0 เพื่อให้ผู้เรียกตอบ
 * "จำนวนต้องมากกว่า 0" ตามเดิม แทนที่จะเดาค่าให้
 *
 * @param string $key ชื่อ field
 * @param array|null $src ค่าเริ่มต้น $_POST
 * @return float
 */
function qty_post(string $key, ?array $src = null): float
{
    $src = $src ?? $_POST;
    $raw = isset($src[$key]) ? trim((string) $src[$key]) : '';
    if ($raw === '' || !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
        return 0.0;
    }
    return qty_norm($raw);
}

// ─ Schema migration ───────────────────────────────────────────────────────────

/**
 * คอลัมน์ใน biton_tech_parts ที่ต้องเป็น DECIMAL(12,2)
 *
 * @return array<int,array{0:string,1:string}> [ตาราง, คอลัมน์]
 */
function tech_parts_decimal_qty_targets(): array
{
    return [
        ['products', 'quantity'],
        ['products', 'min_stock'],
        ['stock_in', 'quantity'],
        ['stock_out_items', 'quantity'],
        ['set_items', 'quantity'],
    ];
}

/**
 * หาคอลัมน์เป้าหมายที่ยังเป็นชนิดจำนวนเต็ม
 *
 * @param PDO $pdo ต่อกับ biton_tech_parts
 * @return array<int,array<string,mixed>> แถว: t, c, ctype, nullable, def
 */
function tech_parts_qty_columns_pending(PDO $pdo): array
{
    $targets = tech_parts_decimal_qty_targets();
    $tables = array_values(array_unique(array_column($targets, 0)));
    $ph = implode(',', array_fill(0, count($tables), '?'));
    $st = $pdo->prepare(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS dtype, COLUMN_TYPE AS ctype,
                IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
    );
    $st->execute($tables);

    $want = [];
    foreach ($targets as $pair) {
        $want[$pair[0] . '.' . $pair[1]] = true;
    }
    $intTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
    $pending = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($want[$r['t'] . '.' . $r['c']])) {
            continue;
        }
        if (in_array(strtolower((string) $r['dtype']), $intTypes, true)) {
            $pending[] = $r;
        }
    }
    return $pending;
}

/**
 * ทำให้คอลัมน์จำนวนของ biton_tech_parts เป็น DECIMAL(12,2) (idempotent, เรียกซ้ำได้)
 *
 * เหตุผล: ถ้าคอลัมน์ยังเป็น INT, MySQL จะ "ปัด" 0.5 เป็น 1 ตอน INSERT/UPDATE โดยไม่ error
 * จึงต้อง migrate ก่อนเขียนจำนวนทศนิยม — คงค่า NULL/DEFAULT/UNSIGNED เดิมไว้
 * ใช้ GET_LOCK กันสอง request ชน ALTER พร้อมกัน และห้ามเรียกใน transaction (ALTER commit ทันที)
 *
 * @param PDO $pdo ต่อกับ biton_tech_parts
 * @return bool true = พร้อมเก็บทศนิยม, false = migrate ไม่สำเร็จ (เช่น ไม่มีสิทธิ์ ALTER)
 */
function tech_parts_ensure_decimal_qty_schema(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    require_once __DIR__ . '/schema_once.php';
    if (schema_once_skip('tech_parts_decimal_qty')) {
        return $result = true;
    }
    try {
        if (tech_parts_qty_columns_pending($pdo) === []) {
            schema_once_mark('tech_parts_decimal_qty');
            return $result = true;
        }
        $lockName = 'tech_parts_decimal_qty_migration';
        $pdo->query("SELECT GET_LOCK('$lockName', 20)")->fetchColumn();
        try {
            foreach (tech_parts_qty_columns_pending($pdo) as $col) {
                $sql = 'ALTER TABLE `' . $col['t'] . '` MODIFY COLUMN `' . $col['c'] . '` DECIMAL(12,' . QTY_SCALE . ')';
                if (stripos((string) $col['ctype'], 'unsigned') !== false) {
                    $sql .= ' UNSIGNED';
                }
                if (strtoupper((string) $col['nullable']) === 'NO') {
                    $sql .= ' NOT NULL';
                    if ($col['def'] !== null) {
                        $sql .= ' DEFAULT ' . $pdo->quote(qty_sql($col['def']));
                    }
                } else {
                    $sql .= ' NULL DEFAULT ' . ($col['def'] === null ? 'NULL' : $pdo->quote(qty_sql($col['def'])));
                }
                $pdo->exec($sql);
                error_log('[qty_migration] ' . $col['t'] . '.' . $col['c'] . ' -> DECIMAL(12,' . QTY_SCALE . ')');
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('$lockName')")->fetchColumn();
        }
        $ok = tech_parts_qty_columns_pending($pdo) === [];
        if ($ok) {
            schema_once_mark('tech_parts_decimal_qty');
        }
        return $result = $ok;
    } catch (Throwable $e) {
        error_log('[tech_parts_ensure_decimal_qty_schema] ' . $e->getMessage());
        return $result = false;
    }
}
