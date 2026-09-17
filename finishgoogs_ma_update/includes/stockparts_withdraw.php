<?php
/**
 * includes/stockparts_withdraw.php — อ่านข้อมูลการเบิกใช้งานขายจาก biton_stockparts
 *
 * วัตถุประสงค์: แสดงบนโปรไฟล์เครื่องว่า S/N ถูกผูกกับรายการเบิก/setup ใด
 * แหล่งหลัก: stock.setup_id → po_order_parts.order_id
 * fallback เมื่อ setup_id ว่าง: po_order_part_serials, stock_movements, stock_old
 * ข้อมูลเคลม: อ่านจาก stock_movements.notes + stock_old (S/N เก่า, ลูกค้า)
 * ขายแยกชิ้น: อ่านจาก stock_movements (PO, ลูกค้า, วันที่เบิก) เมื่อไม่มี po_order_parts
 * stock_old: ประวัติเครื่องที่ส่งมอบให้ลูกค้าสำเร็จแล้ว (ไซต์งาน, รปภ.) — ไม่ใช่ stock คงคลัง
 * S/N ในชุด: po_order_part_serials (ถ้ามี) หรือ stock_movements ลงทะเบียนอุปกรณ์
 *
 * Flow ตัวอย่าง:
 *   $info = asset_stockparts_withdraw_info('AA626060436');
 *   echo asset_stockparts_withdraw_card_html($info);
 */

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * ตรวจว่า setup_id จาก stock หมายถึงรายการเบิกใช้งานขายหรือไม่
 *
 * @param mixed $setupId
 * @return bool
 */
function stockparts_setup_id_is_withdraw($setupId): bool
{
    $sid = trim((string) $setupId);
    return $sid !== '' && $sid !== '0';
}

/**
 * ตรวจว่า ref มีรูปแบบที่ใช้ join order ได้ (ตัวเลข order หรือ OPS-xxx)
 *
 * @param string $ref
 * @return bool
 */
function stockparts_ref_looks_valid(string $ref): bool
{
    $ref = trim($ref);
    return $ref !== '' && (
        (bool) preg_match('/^\d+$/', $ref)
        || (bool) preg_match('/^OPS-\d+$/i', $ref)
    );
}

/**
 * แปลง reference_no จาก stock_movements เป็น setup_id / order_id ที่ใช้ join ได้
 *
 * @param string $referenceNo เช่น ORDER-471, OPS-379, 471
 * @return string|null
 */
function stockparts_normalize_withdraw_ref(string $referenceNo): ?string
{
    $ref = trim($referenceNo);
    if ($ref === '') {
        return null;
    }
    if (preg_match('/^ORDER-(\d+)$/i', $ref, $m)) {
        return $m[1];
    }
    if (preg_match('/^OPS-(\d+)$/i', $ref, $m)) {
        return 'OPS-' . $m[1];
    }
    if (preg_match('/^\d+$/', $ref)) {
        return $ref;
    }
    return null;
}

/**
 * ป้ายแหล่งอ้างอิงสำหรับแสดงใน UI
 *
 * @param string $source
 * @return string
 */
function stockparts_resolve_source_label(string $source): string
{
    $labels = [
        'setup_id' => 'ทะเบียน stock (setup_id)',
        'po_order_part_serials' => 'po_order_part_serials',
        'stock_movements' => 'ประวัติเบิก stock_movements',
        'stock_old' => 'ประวัติการส่งมอบ (stock_old)',
    ];
    return $labels[$source] ?? $source;
}

/**
 * หา setup_id / order_id ของ S/N — ลอง stock.setup_id ก่อน แล้ว fallback ตามลำดับ
 *
 * @param mysqli $db
 * @param string $serial
 * @param mixed $setupIdFromStock ค่า setup_id จาก stock (อาจว่าง)
 * @return array{ref:string,source:string}|null
 */
function stockparts_resolve_withdraw_ref($db, string $serial, $setupIdFromStock = null): ?array
{
    $serial = trim($serial);
    if ($serial === '') {
        return null;
    }

    if (stockparts_setup_id_is_withdraw($setupIdFromStock)) {
        return [
            'ref' => trim((string) $setupIdFromStock),
            'source' => 'setup_id',
        ];
    }

    $st = $db->prepare(
        'SELECT order_id FROM po_order_part_serials WHERE serial_number = ? ORDER BY id DESC LIMIT 1'
    );
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row && stockparts_setup_id_is_withdraw($row['order_id'] ?? null)) {
            return [
                'ref' => trim((string) $row['order_id']),
                'source' => 'po_order_part_serials',
            ];
        }
    }

    $st = $db->prepare(
        'SELECT sm.reference_no
         FROM stock_movement_serials sms
         INNER JOIN stock_movements sm ON sm.id = sms.movement_id
         WHERE sms.serial_number = ?
         ORDER BY sm.id DESC LIMIT 1'
    );
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $ref = stockparts_normalize_withdraw_ref((string) ($row['reference_no'] ?? ''));
        if ($ref !== null) {
            return ['ref' => $ref, 'source' => 'stock_movements'];
        }
    }

    $st = $db->prepare(
        'SELECT reference_no FROM stock_movements
         WHERE notes LIKE CONCAT(\'%Serial: \', ?, \'%\')
         ORDER BY id DESC LIMIT 1'
    );
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $ref = stockparts_normalize_withdraw_ref((string) ($row['reference_no'] ?? ''));
        if ($ref !== null) {
            return ['ref' => $ref, 'source' => 'stock_movements'];
        }
    }

    $st = $db->prepare(
        'SELECT reference_no FROM stock_movements
         WHERE notes LIKE CONCAT(\'%\', ?, \'%\')
           AND notes LIKE \'%Serial:%\'
         ORDER BY id DESC LIMIT 1'
    );
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $ref = stockparts_normalize_withdraw_ref((string) ($row['reference_no'] ?? ''));
        if ($ref !== null) {
            return ['ref' => $ref, 'source' => 'stock_movements'];
        }
    }

    $st = $db->prepare(
        'SELECT setup_id FROM stock_old
         WHERE serial_number COLLATE utf8mb4_general_ci = ?
           AND setup_id IS NOT NULL AND setup_id <> \'\' AND setup_id <> \'0\'
         LIMIT 1'
    );
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row && stockparts_setup_id_is_withdraw($row['setup_id'] ?? null)
            && stockparts_ref_looks_valid(trim((string) $row['setup_id']))) {
            return [
                'ref' => trim((string) $row['setup_id']),
                'source' => 'stock_old',
            ];
        }
    }

    return null;
}

/**
 * resolve หลาย S/N พร้อมกัน — ใช้ในตารางรายการเครื่อง
 *
 * @param mysqli $db
 * @param array<string,mixed> $stockRows map serial => row จาก stock (ต้องมี setup_id)
 * @return array<string,array{ref:string,source:string}>
 */
function stockparts_batch_resolve_withdraw_refs($db, array $stockRows): array
{
    $out = [];
    $need = [];

    foreach ($stockRows as $serial => $row) {
        if (stockparts_setup_id_is_withdraw($row['setup_id'] ?? null)) {
            $out[$serial] = [
                'ref' => trim((string) $row['setup_id']),
                'source' => 'setup_id',
            ];
        } else {
            $need[] = $serial;
        }
    }
    if (!$need) {
        return $out;
    }

    $needSet = array_flip($need);
    $ph = implode(',', array_fill(0, count($need), '?'));
    $types = str_repeat('s', count($need));

    $st = $db->prepare("SELECT serial_number, order_id FROM po_order_part_serials WHERE serial_number IN ($ph)");
    if ($st) {
        $st->bind_param($types, ...$need);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $sn = $row['serial_number'];
            if (!isset($needSet[$sn]) || isset($out[$sn])) {
                continue;
            }
            if (stockparts_setup_id_is_withdraw($row['order_id'] ?? null)) {
                $out[$sn] = [
                    'ref' => trim((string) $row['order_id']),
                    'source' => 'po_order_part_serials',
                ];
            }
        }
    }

    $stillNeed = array_values(array_filter($need, function ($sn) use ($out) {
        return !isset($out[$sn]);
    }));
    if ($stillNeed) {
        $ph2 = implode(',', array_fill(0, count($stillNeed), '?'));
        $types2 = str_repeat('s', count($stillNeed));
        $st = $db->prepare(
            "SELECT sms.serial_number, sm.reference_no, sm.id
             FROM stock_movement_serials sms
             INNER JOIN stock_movements sm ON sm.id = sms.movement_id
             WHERE sms.serial_number IN ($ph2)
             ORDER BY sm.id DESC"
        );
        if ($st) {
            $st->bind_param($types2, ...$stillNeed);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $sn = $row['serial_number'];
                if (isset($out[$sn])) {
                    continue;
                }
                $ref = stockparts_normalize_withdraw_ref((string) ($row['reference_no'] ?? ''));
                if ($ref !== null) {
                    $out[$sn] = ['ref' => $ref, 'source' => 'stock_movements'];
                }
            }
        }
    }

    $stillNeed = array_values(array_filter($need, function ($sn) use ($out) {
        return !isset($out[$sn]);
    }));
    if ($stillNeed) {
        $stillSet = array_flip($stillNeed);
        $res = $db->query(
            "SELECT reference_no, notes FROM stock_movements
             WHERE notes LIKE '%Serial:%'
             ORDER BY id DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (!preg_match('/Serial:\s*(\S+)/u', (string) ($row['notes'] ?? ''), $m)) {
                    continue;
                }
                $sn = $m[1];
                if (!isset($stillSet[$sn]) || isset($out[$sn])) {
                    continue;
                }
                $ref = stockparts_normalize_withdraw_ref((string) ($row['reference_no'] ?? ''));
                if ($ref !== null) {
                    $out[$sn] = ['ref' => $ref, 'source' => 'stock_movements'];
                }
            }
        }
    }

    $stillNeed = array_values(array_filter($need, function ($sn) use ($out) {
        return !isset($out[$sn]);
    }));
    if ($stillNeed) {
        $ph3 = implode(',', array_fill(0, count($stillNeed), '?'));
        $types3 = str_repeat('s', count($stillNeed));
        $st = $db->prepare(
            "SELECT s.serial_number, o.setup_id
             FROM stock s
             INNER JOIN stock_old o ON o.serial_number COLLATE utf8mb4_general_ci = s.serial_number
             WHERE s.serial_number IN ($ph3)
               AND o.setup_id IS NOT NULL AND o.setup_id <> '' AND o.setup_id <> '0'"
        );
        if ($st) {
            $st->bind_param($types3, ...$stillNeed);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $sn = $row['serial_number'];
                if (isset($out[$sn])) {
                    continue;
                }
                if (stockparts_setup_id_is_withdraw($row['setup_id'] ?? null)
                    && stockparts_ref_looks_valid(trim((string) $row['setup_id']))) {
                    $out[$sn] = [
                        'ref' => trim((string) $row['setup_id']),
                        'source' => 'stock_old',
                    ];
                }
            }
        }
    }

    return $out;
}

/**
 * ดึงรายการ po_order_parts ตาม order_id / setup_id
 *
 * @param mysqli $db
 * @param string $orderId
 * @return array<int,array<string,mixed>>
 */
function stockparts_fetch_order_lines($db, string $orderId): array
{
    $st = $db->prepare(
        'SELECT id, order_id, part_code, part_name, category_name, quantity, unit,
                order_product_type, order_customer_name, status_product, is_delivered,
                created_at, updated_at
         FROM po_order_parts WHERE order_id = ? ORDER BY part_name'
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('s', $orderId);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * จับคู่รายการ order กับ model จาก stock
 *
 * @param array<int,array<string,mixed>> $lines
 * @param string $model
 * @return array{matched:?array,summary:?array}
 */
function stockparts_match_order_lines(array $lines, string $model): array
{
    $matchedLine = null;
    foreach ($lines as $line) {
        $partName = trim((string) ($line['part_name'] ?? ''));
        if ($model !== '' && strcasecmp($partName, $model) === 0) {
            $matchedLine = $line;
            break;
        }
    }
    if (!$matchedLine && count($lines) === 1) {
        $matchedLine = $lines[0];
    }
    $summary = $matchedLine ?: ($lines[0] ?? null);
    return ['matched' => $matchedLine, 'summary' => $summary];
}

/**
 * แยก S/N เก่าจาก notes ของ stock_movements (รูปแบบ "เคลมจาก Serial: …")
 *
 * @param string $notes
 * @return string|null
 */
function stockparts_parse_claim_old_serial(string $notes): ?string
{
    if (preg_match('/เคลมจาก\s+Serial:\s*([A-Za-z0-9]+)/u', $notes, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * ตรวจว่า notes บ่งชี้ว่าเป็นรายการเคลมหรือไม่
 *
 * @param string $notes
 * @return bool
 */
function stockparts_notes_is_claim(string $notes): bool
{
    return (bool) preg_match('/เคลม/u', $notes);
}

/**
 * setup_id ใน stock_old ที่เป็นชื่อลูกค้า/โครงการ (ไม่ใช่เลข order หรือ OPS-xxx)
 *
 * @param mixed $setupId
 * @return bool
 */
function stockparts_setup_id_is_customer_label($setupId): bool
{
    $sid = trim((string) $setupId);
    if ($sid === '' || $sid === '0') {
        return false;
    }
    return !stockparts_ref_looks_valid($sid);
}

/**
 * ดึง movement ล่าสุดที่เกี่ยวกับ S/N และอ้างอิง setup/order
 *
 * @param mysqli $db
 * @param string $serial
 * @param string $setupId
 * @return array<string,mixed>|null
 */
function stockparts_fetch_movement_for_serial($db, string $serial, string $setupId): ?array
{
    $serial = trim($serial);
    $setupId = trim($setupId);
    if ($serial === '') {
        return null;
    }

    $st = $db->prepare(
        'SELECT id, movement_type, reference_no, customer_name, notes, movement_date, created_by, created_at
         FROM stock_movements
         WHERE reference_no = ?
           AND (notes LIKE CONCAT(\'%Serial: \', ?, \'%\') OR notes LIKE CONCAT(\'%\', ?, \'%\'))
         ORDER BY id DESC LIMIT 1'
    );
    if ($st && $setupId !== '') {
        $st->bind_param('sss', $setupId, $serial, $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) {
            return $row;
        }
    }

    $st = $db->prepare(
        'SELECT id, movement_type, reference_no, customer_name, notes, movement_date, created_by, created_at
         FROM stock_movements
         WHERE notes LIKE CONCAT(\'%Serial: \', ?, \'%\')
         ORDER BY id DESC LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('s', $serial);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * อ่านแถว stock_old — ประวัติเครื่องที่ส่งมอบให้ลูกค้าแล้ว
 *
 * @param mysqli $db
 * @param string $serial
 * @return array<string,mixed>|null
 */
/**
 * อ่าน stock_old หลาย S/N ในคิวรีเดียว — ใช้ตอนเช็คเป็นชุด จะได้ไม่ยิงทีละตัว
 *
 * @param mysqli            $db
 * @param array<int,string> $serials
 * @return array<string,array<string,mixed>>  คีย์เป็น S/N ตามที่ส่งเข้ามา
 */
function stockparts_fetch_stock_old_rows($db, array $serials): array
{
    $serials = array_values(array_unique(array_filter(array_map('trim', $serials), 'strlen')));
    if (!$serials) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($serials), '?'));
    $st = $db->prepare(
        'SELECT serial_number, model, timestamp, create_name, setup_id, security_company, active
         FROM stock_old
         WHERE serial_number COLLATE utf8mb4_general_ci IN (' . $ph . ')'
    );
    if (!$st) {
        return [];
    }
    $st->bind_param(str_repeat('s', count($serials)), ...$serials);
    if (!$st->execute()) {
        $st->close();
        return [];
    }
    // เทียบกลับแบบไม่สนตัวพิมพ์ ให้ตรงกับ COLLATE ที่ใช้ในคิวรี
    $byUpper = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $byUpper[strtoupper(trim((string) $row['serial_number']))] = $row;
    }
    $st->close();

    $out = [];
    foreach ($serials as $sn) {
        $k = strtoupper($sn);
        if (isset($byUpper[$k])) {
            $out[$sn] = $byUpper[$k];
        }
    }
    return $out;
}
function stockparts_fetch_stock_old_row($db, string $serial): ?array
{
    $serial = trim($serial);
    if ($serial === '') {
        return null;
    }
    $st = $db->prepare(
        'SELECT serial_number, model, timestamp, create_name, setup_id, security_company, active
         FROM stock_old WHERE serial_number COLLATE utf8mb4_general_ci = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('s', $serial);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * รวมข้อมูลเคลมที่มีใน biton_stockparts (movement + stock_old)
 *
 * @param mysqli $db
 * @param string $serial S/N ใหม่/ปัจจุบัน
 * @param string $setupId
 * @return array<string,mixed>|null null เมื่อไม่ใช่รายการเคลม
 */
function stockparts_fetch_claim_context($db, string $serial, string $setupId): ?array
{
    $movement = stockparts_fetch_movement_for_serial($db, $serial, $setupId);
    if (!$movement) {
        return null;
    }

    $notes = trim((string) ($movement['notes'] ?? ''));
    $oldSerial = stockparts_parse_claim_old_serial($notes);
    $isClaim = stockparts_notes_is_claim($notes) || $oldSerial !== null;
    if (!$isClaim) {
        return null;
    }

    $oldRow = $oldSerial !== null ? stockparts_fetch_stock_old_row($db, $oldSerial) : null;

    $customer = trim((string) ($movement['customer_name'] ?? ''));
    if ($customer === '' && $oldRow && stockparts_setup_id_is_customer_label($oldRow['setup_id'] ?? null)) {
        $customer = trim((string) $oldRow['setup_id']);
    }

    return [
        'is_claim' => true,
        'reference_no' => trim((string) ($movement['reference_no'] ?? $setupId)),
        'movement_date' => trim((string) ($movement['movement_date'] ?? '')),
        'movement_type' => trim((string) ($movement['movement_type'] ?? '')),
        'notes' => $notes,
        'created_by' => trim((string) ($movement['created_by'] ?? '')),
        'customer_hint' => $customer,
        'old_serial' => $oldSerial ?? '',
        'old_model' => trim((string) ($oldRow['model'] ?? '')),
        'old_setup_id' => trim((string) ($oldRow['setup_id'] ?? '')),
        'old_recorded_at' => trim((string) ($oldRow['timestamp'] ?? '')),
    ];
}

/**
 * ตรวจว่า notes บ่งชี้ว่าเป็นขายแยกชิ้นหรือไม่
 *
 * @param string $notes
 * @return bool
 */
function stockparts_notes_is_individual_sale(string $notes): bool
{
    return (bool) preg_match('/เบิกแยกรายชิ้น/u', $notes);
}

/**
 * แยกเลข PO จาก notes หรือ reference_no
 *
 * @param string $notes
 * @param string $referenceNo
 * @return string
 */
function stockparts_parse_po_ref(string $notes, string $referenceNo): string
{
    if (preg_match('/\(PO:\s*(\d+)\)/u', $notes, $m)) {
        return trim($m[1]);
    }
    $ref = trim($referenceNo);
    if ($ref !== '' && preg_match('/^\d+$/', $ref)) {
        return $ref;
    }
    return '';
}

/**
 * แยก OPS-xxx จาก notes
 *
 * @param string $notes
 * @return string
 */
function stockparts_parse_ops_ref(string $notes): string
{
    if (preg_match('/\((OPS-\d+)\)/u', $notes, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * รวมข้อมูลขายแยกชิ้นจาก stock_movements (เมื่อไม่มี po_order_parts)
 *
 * @param mysqli $db
 * @param string $serial
 * @param string $setupId
 * @return array<string,mixed>|null
 */
function stockparts_fetch_individual_sale_context($db, string $serial, string $setupId): ?array
{
    $movement = stockparts_fetch_movement_for_serial($db, $serial, $setupId);
    if (!$movement) {
        return null;
    }

    $notes = trim((string) ($movement['notes'] ?? ''));
    if (!stockparts_notes_is_individual_sale($notes) || stockparts_notes_is_claim($notes)) {
        return null;
    }

    $referenceNo = trim((string) ($movement['reference_no'] ?? ''));
    $poNo = stockparts_parse_po_ref($notes, $referenceNo);
    $opsRef = stockparts_parse_ops_ref($notes);
    if ($opsRef === '') {
        $opsRef = trim($setupId);
    }

    return [
        'is_individual_sale' => true,
        'po_no' => $poNo,
        'ops_ref' => $opsRef,
        'reference_no' => $referenceNo,
        'movement_date' => trim((string) ($movement['movement_date'] ?? '')),
        'movement_type' => trim((string) ($movement['movement_type'] ?? '')),
        'customer_name' => trim((string) ($movement['customer_name'] ?? '')),
        'notes' => $notes,
        'created_by' => trim((string) ($movement['created_by'] ?? '')),
    ];
}

/**
 * อ่านชื่อรุ่นจาก production.assets เพื่อจับคู่รายการ order
 *
 * @param string $serial
 * @return string
 */
function stockparts_fetch_production_product_name(string $serial): string
{
    $serial = trim($serial);
    if ($serial === '') {
        return '';
    }
    try {
        $db = db();
        $st = $db->prepare(
            'SELECT p.name FROM assets a
             INNER JOIN products p ON p.id = a.product_id
             WHERE a.factory_serial = ? LIMIT 1'
        );
        if (!$st) {
            return '';
        }
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        return trim((string) ($row['name'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * อ่านชื่อรุ่น/สินค้าของ S/N จาก production, stock, stock_old
 *
 * @param mysqli $db
 * @param string $serial
 * @return string
 */
function stockparts_resolve_serial_product_name($db, string $serial): string
{
    $name = stockparts_fetch_production_product_name($serial);
    if ($name !== '') {
        return $name;
    }
    $st = $db->prepare('SELECT model FROM stock WHERE serial_number = ? LIMIT 1');
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $name = trim((string) ($row['model'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    $old = stockparts_fetch_stock_old_row($db, $serial);
    return trim((string) ($old['model'] ?? ''));
}

/**
 * รูปแบบ reference_no ที่อาจใช้กับ order เดียวกัน
 *
 * @param string $orderId
 * @return string[]
 */
function stockparts_order_reference_variants(string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return [];
    }
    $out = [$orderId];
    if (preg_match('/^\d+$/', $orderId)) {
        $out[] = 'ORDER-' . $orderId;
    } elseif (preg_match('/^ORDER-(\d+)$/i', $orderId, $m)) {
        $out[] = $m[1];
    }
    return array_values(array_unique($out));
}

/**
 * แยก S/N จาก notes ลงทะเบียนอุปกรณ์
 *
 * @param string $notes
 * @return string|null
 */
function stockparts_parse_registration_serial(string $notes): ?string
{
    if (preg_match('/Serial:\s*(\S+)/u', $notes, $m)) {
        return trim($m[1], " \t\n\r\0\x0B(),");
    }
    return null;
}

/**
 * ดึง S/N ที่ลงทะเบียนใน order จาก stock_movements
 *
 * @param mysqli $db
 * @param string $orderId
 * @return array<int,array{serial:string,movement_date:string,customer_name:string}>
 */
function stockparts_fetch_order_registration_serials($db, string $orderId): array
{
    $refs = stockparts_order_reference_variants($orderId);
    if (!$refs) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($refs), '?'));
    $types = str_repeat('s', count($refs));
    $st = $db->prepare(
        "SELECT notes, movement_date, customer_name
         FROM stock_movements
         WHERE reference_no IN ($ph)
           AND notes LIKE '%ลงทะเบียนอุปกรณ์%'
         ORDER BY id"
    );
    if (!$st) {
        return [];
    }
    $st->bind_param($types, ...$refs);
    $st->execute();
    $out = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $sn = stockparts_parse_registration_serial((string) ($row['notes'] ?? ''));
        if ($sn === null || $sn === '') {
            continue;
        }
        $out[] = [
            'serial' => $sn,
            'movement_date' => trim((string) ($row['movement_date'] ?? '')),
            'customer_name' => trim((string) ($row['customer_name'] ?? '')),
        ];
    }
    return $out;
}

/**
 * ดึง map line_id → S/N จาก po_order_part_serials (ข้อมูลลงทะเบียนชุด — ชัดที่สุด)
 *
 * @param mysqli $db
 * @param string $orderId
 * @return array<int,string>
 */
function stockparts_fetch_line_serials_from_po($db, string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return [];
    }

    $st = $db->prepare(
        'SELECT p.id AS line_id, s.serial_number
         FROM po_order_part_serials s
         INNER JOIN po_order_parts p ON p.id = s.part_id
         WHERE p.order_id = ?
         ORDER BY p.part_name, s.id'
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('s', $orderId);
    $st->execute();

    $map = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $lineId = (int) ($row['line_id'] ?? 0);
        $sn = trim((string) ($row['serial_number'] ?? ''));
        if ($lineId > 0 && $sn !== '' && !isset($map[$lineId])) {
            $map[$lineId] = $sn;
        }
    }

    if ($map) {
        return $map;
    }

    $st = $db->prepare(
        'SELECT s.part_id, s.serial_number, p.id AS line_id
         FROM po_order_part_serials s
         LEFT JOIN po_order_parts p ON p.id = s.part_id
         WHERE s.order_id = ?'
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('s', $orderId);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $lineId = (int) ($row['line_id'] ?? $row['part_id'] ?? 0);
        $sn = trim((string) ($row['serial_number'] ?? ''));
        if ($lineId > 0 && $sn !== '' && !isset($map[$lineId])) {
            $map[$lineId] = $sn;
        }
    }
    return $map;
}

/**
 * จับคู่รายการ po_order_parts กับ S/N ที่ลงทะเบียนแล้ว
 *
 * ลำดับ: po_order_part_serials → stock_movements ลงทะเบียน (จับคู่ชื่อสินค้า)
 *
 * @param mysqli $db
 * @param string $orderId
 * @param array<int,array<string,mixed>> $lines
 * @return array<int,string> map line_id => serial_number
 */
function stockparts_map_lines_to_registered_serials($db, string $orderId, array $lines): array
{
    if (!$lines) {
        return [];
    }

    $map = stockparts_fetch_line_serials_from_po($db, $orderId);

    $registrations = stockparts_fetch_order_registration_serials($db, $orderId);
    if (!$registrations) {
        return $map;
    }

    $serialProducts = [];
    foreach ($registrations as $reg) {
        $sn = $reg['serial'];
        if (in_array($sn, $map, true)) {
            continue;
        }
        $serialProducts[$sn] = stockparts_resolve_serial_product_name($db, $sn);
    }

    foreach ($lines as $line) {
        $lineId = (int) ($line['id'] ?? 0);
        if ($lineId <= 0 || isset($map[$lineId])) {
            continue;
        }
        $partName = trim((string) ($line['part_name'] ?? ''));
        if ($partName === '') {
            continue;
        }
        foreach ($serialProducts as $sn => $prodName) {
            if ($prodName !== '' && strcasecmp($partName, $prodName) === 0) {
                $map[$lineId] = $sn;
                break;
            }
        }
    }

    $unmappedSerials = array_values(array_filter(array_keys($serialProducts), function ($sn) use ($map) {
        return !in_array($sn, $map, true);
    }));
    $unmappedLineIds = [];
    foreach ($lines as $line) {
        $lid = (int) ($line['id'] ?? 0);
        if ($lid > 0 && !isset($map[$lid])) {
            $unmappedLineIds[] = $lid;
        }
    }
    if (count($unmappedSerials) === 1 && count($unmappedLineIds) === 1) {
        $map[$unmappedLineIds[0]] = $unmappedSerials[0];
    } elseif (count($unmappedSerials) > 1 && count($unmappedSerials) === count($unmappedLineIds)) {
        $lineMeta = [];
        foreach ($lines as $line) {
            $lid = (int) ($line['id'] ?? 0);
            if (in_array($lid, $unmappedLineIds, true)) {
                $lineMeta[$lid] = trim((string) ($line['part_code'] ?? ''));
            }
        }
        usort($unmappedLineIds, function ($a, $b) use ($lineMeta) {
            return strcmp($lineMeta[$a] ?? '', $lineMeta[$b] ?? '');
        });
        sort($unmappedSerials, SORT_STRING);
        foreach ($unmappedLineIds as $i => $lid) {
            $map[$lid] = $unmappedSerials[$i];
        }
    }

    return $map;
}

/**
 * แนบ map รายการ order → S/N ที่ลงทะเบียน
 *
 * @param mysqli $db
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function stockparts_attach_line_serial_map($db, array $result): array
{
    $lines = $result['order_lines'] ?? [];
    $orderId = trim((string) ($result['order_id'] ?? $result['setup_id'] ?? ''));
    if (!$lines || $orderId === '') {
        $result['line_serial_map'] = [];
        return $result;
    }
    $result['line_serial_map'] = stockparts_map_lines_to_registered_serials($db, $orderId, $lines);
    return $result;
}

/**
 * ตรวจว่า notes บ่งชี้ว่าเป็นการลงทะเบียนอุปกรณ์หรือไม่
 *
 * @param string $notes
 * @return bool
 */
function stockparts_notes_is_registration(string $notes): bool
{
    return (bool) preg_match('/ลงทะเบียนอุปกรณ์/u', $notes);
}

/**
 * รวมข้อมูลลงทะเบียนอุปกรณ์จาก stock_movements
 *
 * @param mysqli $db
 * @param string $serial
 * @param string $setupId
 * @return array<string,mixed>|null
 */
function stockparts_fetch_registration_context($db, string $serial, string $setupId): ?array
{
    $movement = stockparts_fetch_movement_for_serial($db, $serial, $setupId);
    if (!$movement) {
        return null;
    }
    $notes = trim((string) ($movement['notes'] ?? ''));
    if (!stockparts_notes_is_registration($notes)) {
        return null;
    }
    $ref = trim((string) ($movement['reference_no'] ?? ''));
    $orderId = stockparts_normalize_withdraw_ref($ref) ?? trim($setupId);

    return [
        'is_registration' => true,
        'reference_no' => $ref,
        'order_id' => $orderId,
        'movement_date' => trim((string) ($movement['movement_date'] ?? '')),
        'customer_name' => trim((string) ($movement['customer_name'] ?? '')),
        'notes' => $notes,
        'created_by' => trim((string) ($movement['created_by'] ?? '')),
    ];
}

/**
 * ประกอบผลลัพธ์เบิกขายเมื่อ S/N ไม่มีใน stock ปัจจุบัน แต่มีอ้างอิงจาก movement/order
 *
 * @param mysqli $db
 * @param string $serial
 * @param array<string,mixed> $base
 * @return array<string,mixed>|null
 */
function stockparts_build_withdraw_without_stock_row($db, string $serial, array $base): ?array
{
    $resolved = stockparts_resolve_withdraw_ref($db, $serial, null);
    if (!$resolved) {
        return null;
    }

    $orderId = $resolved['ref'];
    $productHint = stockparts_fetch_production_product_name($serial);
    $lines = stockparts_fetch_order_lines($db, $orderId);
    $movement = stockparts_fetch_movement_for_serial($db, $serial, $orderId);
    $claim = stockparts_fetch_claim_context($db, $serial, $orderId);
    $individualSale = $claim ? null : stockparts_fetch_individual_sale_context($db, $serial, $orderId);
    $registration = stockparts_fetch_registration_context($db, $serial, $orderId);

    $stockRow = [
        'serial_number' => $serial,
        'model' => $productHint,
        'timestamp' => trim((string) ($movement['movement_date'] ?? '')),
        'create_name' => trim((string) ($movement['created_by'] ?? 'System')),
        'setup_id' => $orderId,
        'active' => 1,
    ];

    $base['ok'] = true;
    $base['stock'] = $stockRow;
    $base['stock_synthetic'] = true;

    if (!$lines && $resolved['source'] !== 'setup_id') {
        $result = array_merge($base, [
            'has_withdraw' => true,
            'setup_id' => $orderId,
            'order_id' => $orderId,
            'resolve_source' => $resolved['source'],
            'order_lines' => [],
            'claim' => $claim,
            'individual_sale' => $individualSale,
            'registration' => $registration,
            'message' => ($claim || $individualSale || $registration) ? '' : ('พบการอ้างอิงจาก '
                . stockparts_resolve_source_label($resolved['source'])
                . ' แต่อ่านรายละเอียดคำสั่งไม่ได้'),
        ]);
        return stockparts_attach_stock_old($db, $result, $serial);
    }

    $result = stockparts_build_withdraw_result(
        $base,
        $stockRow,
        $serial,
        $orderId,
        $lines,
        $resolved['source']
    );
    $result['stock_synthetic'] = true;
    $result['claim'] = $claim;
    $result['individual_sale'] = $individualSale;
    $result['registration'] = $registration;
    if (($claim || $individualSale || $registration) && empty($result['has_order_detail'])) {
        $result['message'] = '';
    }
    return stockparts_attach_stock_old($db, $result, $serial);
}

/**
 * แนบประวัติการส่งมอบจาก stock_old เมื่อ S/N อยู่ในตารางนี้
 *
 * @param mysqli $db
 * @param array<string,mixed> $result
 * @param string $serial
 * @return array<string,mixed>
 */
function stockparts_attach_stock_old($db, array $result, string $serial): array
{
    $oldRow = stockparts_fetch_stock_old_row($db, $serial);
    if ($oldRow) {
        $result['has_stock_old'] = true;
        $result['stock_old'] = $oldRow;
        if (empty($result['has_withdraw']) && trim((string) ($result['message'] ?? '')) !== '') {
            $msg = trim((string) $result['message']);
            if ($msg === 'ไม่พบ S/N ในทะเบียน stock'
                || $msg === 'เครื่องนี้ยังไม่ถูกผูกกับรายการเบิกใช้งานขาย') {
                $result['message'] = '';
            }
        }
    }
    return stockparts_attach_line_serial_map($db, stockparts_attach_serial_delivered($db, $result, $serial));
}

/**
 * ประกอบผลลัพธ์มาตรฐานของการเบิกใช้งานขาย
 *
 * @param array<string,mixed> $base
 * @param array<string,mixed> $stockRow
 * @param string $serial
 * @param string $orderId
 * @param array<int,array<string,mixed>> $lines
 * @param string $resolveSource
 * @return array<string,mixed>
 */
function stockparts_build_withdraw_result(
    array $base,
    array $stockRow,
    string $serial,
    string $orderId,
    array $lines,
    string $resolveSource
): array {
    $match = stockparts_match_order_lines($lines, trim((string) ($stockRow['model'] ?? '')));
    $summary = $match['summary'];
    $hasSetup = stockparts_setup_id_is_withdraw($orderId);

    return [
        'ok' => true,
        'has_withdraw' => $hasSetup,
        'has_order_detail' => $summary !== null,
        'serial' => $serial,
        'stock' => $stockRow,
        'setup_id' => $orderId,
        'order_id' => $orderId,
        'resolve_source' => $resolveSource,
        'matched_line' => $match['matched'],
        'order_lines' => $lines,
        'summary' => $summary,
        'claim' => null,
        'individual_sale' => null,
        'registration' => null,
        'message' => $summary ? '' : ($hasSetup
            ? 'มี setup_id แต่ไม่พบรายละเอียดใน po_order_parts'
            : 'ยังไม่มีข้อมูลการเบิกใช้งานขาย'),
    ];
}

// ─ Main API ───────────────────────────────────────────────────────────────────

/**
 * ดึงข้อมูลการเบิกใช้งานขายของ S/N จาก biton_stockparts
 *
 * @param string $serial หมายเลขเครื่อง (asset_code)
 * @return array<string,mixed>
 */
function asset_stockparts_withdraw_info(string $serial): array
{
    $serial = trim($serial);
    $base = [
        'ok' => false,
        'has_withdraw' => false,
        'serial' => $serial,
        'message' => 'ยังไม่มีข้อมูลการเบิกใช้งานขาย',
    ];
    if ($serial === '') {
        return $base;
    }

    try {
        $db = dbStock();
    } catch (Throwable $e) {
        return array_merge($base, ['message' => 'เชื่อมต่อระบบ stock ไม่ได้']);
    }

    $st = $db->prepare(
        'SELECT serial_number, model, `timestamp`, create_name, setup_id, active
         FROM stock WHERE serial_number = ? LIMIT 1'
    );
    if (!$st) {
        return array_merge($base, ['message' => 'อ่านทะเบียน stock ไม่ได้']);
    }
    $st->bind_param('s', $serial);
    $st->execute();
    $stockRow = $st->get_result()->fetch_assoc();
    if (!$stockRow) {
        $oldRow = stockparts_fetch_stock_old_row($db, $serial);
        if ($oldRow) {
            return stockparts_attach_serial_delivered($db, array_merge($base, [
                'ok' => true,
                'has_withdraw' => false,
                'has_stock_old' => true,
                'stock_old' => $oldRow,
                'message' => '',
            ]), $serial);
        }
        $withoutStock = stockparts_build_withdraw_without_stock_row($db, $serial, $base);
        if ($withoutStock !== null) {
            return $withoutStock;
        }
        return array_merge($base, ['message' => 'ไม่พบ S/N ในทะเบียน stock']);
    }

    $base['ok'] = true;
    $base['stock'] = $stockRow;

    $resolved = stockparts_resolve_withdraw_ref($db, $serial, $stockRow['setup_id'] ?? null);
    if ($resolved === null) {
        return stockparts_attach_stock_old($db, array_merge($base, [
            'message' => 'เครื่องนี้ยังไม่ถูกผูกกับรายการเบิกใช้งานขาย',
        ]), $serial);
    }

    $orderId = $resolved['ref'];
    $claim = stockparts_fetch_claim_context($db, $serial, $orderId);
    $individualSale = $claim ? null : stockparts_fetch_individual_sale_context($db, $serial, $orderId);
    $registration = stockparts_fetch_registration_context($db, $serial, $orderId);
    $lines = stockparts_fetch_order_lines($db, $orderId);
    if (!$lines && $resolved['source'] !== 'setup_id') {
        return stockparts_attach_stock_old($db, array_merge($base, [
            'has_withdraw' => true,
            'setup_id' => $orderId,
            'order_id' => $orderId,
            'resolve_source' => $resolved['source'],
            'order_lines' => [],
            'claim' => $claim,
            'individual_sale' => $individualSale,
            'registration' => $registration,
            'message' => ($claim || $individualSale || $registration) ? '' : ('พบการอ้างอิงจาก ' . stockparts_resolve_source_label($resolved['source'])
                . ' แต่อ่านรายละเอียดคำสั่งไม่ได้'),
        ]), $serial);
    }

    $result = stockparts_build_withdraw_result(
        $base,
        $stockRow,
        $serial,
        $orderId,
        $lines,
        $resolved['source']
    );
    $result['claim'] = $claim;
    $result['individual_sale'] = $individualSale;
    $result['registration'] = $registration;
    if (($claim || $individualSale || $registration) && empty($result['has_order_detail'])) {
        $result['message'] = '';
    }
    return stockparts_attach_stock_old($db, $result, $serial);
}

/**
 * สถานะการเบิกขายของหลาย S/N พร้อมกัน — สำหรับตารางที่ต้องแสดงทั้งหน้า
 *
 * ใช้ logic เดียวกับ asset_stockparts_withdraw_info() รวม fallback
 *
 * @param string[] $serials
 * @return array<string,array{sold:bool,setup_id:string,resolve_source?:string}>
 */
function asset_stockparts_sale_status_by_sn(array $serials): array
{
    $serials = array_values(array_unique(array_filter(array_map('trim', $serials), 'strlen')));
    if (!$serials) {
        return [];
    }

    try {
        $db = dbStock();
    } catch (Throwable $e) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($serials), '?'));
    $st = $db->prepare("SELECT serial_number, setup_id FROM stock WHERE serial_number IN ($ph)");
    if (!$st) {
        return [];
    }
    $st->bind_param(str_repeat('s', count($serials)), ...$serials);
    $st->execute();

    $stockRows = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $stockRows[$row['serial_number']] = $row;
    }

    $resolvedMap = stockparts_batch_resolve_withdraw_refs($db, $stockRows);

    // เครื่องที่ยังมีทะเบียนใน stock แต่หาใบเบิกไม่เจอ อาจส่งมอบไปแล้วก็ได้
    // ต้องเช็ค stock_old ด้วย ไม่งั้นจะค้างเป็น "อยู่ในคลัง" ทั้งที่ติดตั้งที่ไซต์งานแล้ว
    $needOld = [];
    foreach ($stockRows as $sn => $row) {
        if (!isset($resolvedMap[$sn])) {
            $needOld[] = $sn;
        }
    }
    $oldRows = $needOld ? stockparts_fetch_stock_old_rows($db, $needOld) : [];

    $out = [];
    foreach ($stockRows as $sn => $row) {
        if (isset($resolvedMap[$sn])) {
            $out[$sn] = [
                'sold' => true,
                'setup_id' => $resolvedMap[$sn]['ref'],
                'resolve_source' => $resolvedMap[$sn]['source'],
            ];
        } elseif (isset($oldRows[$sn])) {
            $label = trim((string) ($oldRows[$sn]['setup_id'] ?? ''));
            $out[$sn] = [
                'sold' => true,
                'setup_id' => $label !== '' ? $label : 'ส่งมอบแล้ว',
                'resolve_source' => 'stock_old',
                // หลักฐานมาจากประวัติส่งมอบ ไม่ใช่ใบเบิกขาย — ตัวตัดสินสถานะ
                // ต้องถ่วงน้ำหนักเบากว่า เพื่อไม่ให้ไปทับเครื่องที่อยู่ในสัญญาเช่า
                'from_delivery' => true,
            ];
        } else {
            $out[$sn] = [
                'sold' => false,
                'setup_id' => trim((string) ($row['setup_id'] ?? '')),
            ];
        }
    }

    foreach ($serials as $sn) {
        if (isset($out[$sn])) {
            continue;
        }
        $resolved = stockparts_resolve_withdraw_ref($db, $sn, null);
        if ($resolved) {
            $out[$sn] = [
                'sold' => true,
                'setup_id' => $resolved['ref'],
                'resolve_source' => $resolved['source'],
            ];
            continue;
        }
        $oldRow = stockparts_fetch_stock_old_row($db, $sn);
        if ($oldRow) {
            $label = trim((string) ($oldRow['setup_id'] ?? ''));
            if ($label === '') {
                $label = 'ส่งมอบแล้ว';
            }
            $out[$sn] = [
                'sold' => true,
                'setup_id' => $label,
                'resolve_source' => 'stock_old',
                'from_delivery' => true,
            ];
        }
    }

    return $out;
}

/**
 * ค่าที่แสดงใน card — ว่างให้เป็น "-"
 *
 * @param mixed $value
 * @return string
 */
function stockparts_fmt_field($value): string
{
    $s = trim((string) $value);
    return ($s === '' || $s === '-') ? '-' : $s;
}

/**
 * ตรวจว่า S/N มีหลักฐานว่าส่งมอบ/ติดตั้งให้ลูกค้าแล้ว (ไม่พึ่งแค่ po_order_parts.is_delivered)
 *
 * แหล่ง: stock_old, stock.setup_id, stock_movements (ลงทะเบียน/เบิก/เคลม)
 *
 * @param mysqli $db
 * @param string $serial
 * @param string $orderId
 * @return bool
 */
function stockparts_serial_has_delivery_evidence($db, string $serial, string $orderId = ''): bool
{
    $serial = trim($serial);
    if ($serial === '') {
        return false;
    }

    if (stockparts_fetch_stock_old_row($db, $serial)) {
        return true;
    }

    $st = $db->prepare('SELECT setup_id FROM stock WHERE serial_number = ? LIMIT 1');
    if ($st) {
        $st->bind_param('s', $serial);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row && stockparts_setup_id_is_withdraw($row['setup_id'] ?? null)) {
            return true;
        }
    }

    $resolved = stockparts_resolve_withdraw_ref($db, $serial, null);
    if (!$resolved) {
        return false;
    }

    if ($orderId !== '' && $resolved['ref'] === $orderId) {
        return true;
    }

    $movement = stockparts_fetch_movement_for_serial($db, $serial, $resolved['ref']);
    if (!$movement) {
        return $orderId !== '' && $resolved['ref'] === $orderId;
    }

    $notes = trim((string) ($movement['notes'] ?? ''));
    $type = strtoupper(trim((string) ($movement['movement_type'] ?? '')));

    return $type === 'OUT'
        || stockparts_notes_is_registration($notes)
        || stockparts_notes_is_individual_sale($notes)
        || stockparts_notes_is_claim($notes);
}

/**
 * ป้ายสถานะการส่งมอบ
 *
 * @param mixed $isDelivered
 * @return string
 */
function stockparts_delivered_label($isDelivered): string
{
    if ($isDelivered === null || $isDelivered === '') {
        return '-';
    }
    return (int) $isDelivered === 1 ? 'ส่งแล้ว' : 'ยังไม่ส่ง';
}

/**
 * ป้ายส่งมอบสำหรับแถวที่ตรงกับเครื่อง — ใช้หลักฐาน S/N เมื่อ po_order_parts ยังไม่อัปเดต
 *
 * @param mixed $isDelivered ค่า is_delivered จาก po_order_parts
 * @param bool $serialDelivered มีหลักฐานว่า S/N ส่งมอบแล้ว
 * @param bool $asHtml คืน HTML (มี title เมื่อ override)
 * @return string
 */
function stockparts_delivered_label_for_asset($isDelivered, bool $serialDelivered, bool $asHtml = false): string
{
    if ($serialDelivered && (int) ($isDelivered ?? 0) !== 1) {
        $label = 'ส่งแล้ว';
        if ($asHtml) {
            return '<span class="asset-sales-delivered-confirmed" title="ยืนยันจาก S/N / movement / stock_old — รายการ order ยังไม่ update">'
                . h($label) . '</span>';
        }
        return $label;
    }
    $label = stockparts_delivered_label($isDelivered);
    return $asHtml ? h($label) : $label;
}

/**
 * แนบ flag serial_delivered ลงผลลัพธ์ withdraw
 *
 * @param mysqli $db
 * @param array<string,mixed> $result
 * @param string $serial
 * @param string $orderId
 * @return array<string,mixed>
 */
function stockparts_attach_serial_delivered($db, array $result, string $serial, string $orderId = ''): array
{
    $oid = trim($orderId);
    if ($oid === '') {
        $oid = trim((string) ($result['order_id'] ?? $result['setup_id'] ?? ''));
    }
    if (!empty($result['has_stock_old'])) {
        $result['serial_delivered'] = true;
        return $result;
    }
    $result['serial_delivered'] = stockparts_serial_has_delivery_evidence($db, $serial, $oid);
    return $result;
}

/**
 * ป้ายส่งมอบต่อแถว order — ใช้ S/N ที่ลงทะเบียน, หลักฐานเครื่องนี้, หรือ is_delivered
 *
 * @param array<string,mixed> $line
 * @param array<int,string> $lineSerialMap
 * @param bool $assetSerialDelivered
 * @param bool $isMatched
 * @return string HTML
 */
function stockparts_line_delivery_cell_html(array $line, array $lineSerialMap, bool $assetSerialDelivered, bool $isMatched): string
{
    $lineId = (int) ($line['id'] ?? 0);
    $isDelivered = $line['is_delivered'] ?? null;

    if ($lineId > 0 && isset($lineSerialMap[$lineId])) {
        $sn = $lineSerialMap[$lineId];
        $title = 'ลงทะเบียนแล้ว · S/N ' . $sn;
        if ((int) ($isDelivered ?? 0) !== 1) {
            return '<span class="asset-sales-delivered-confirmed" title="' . h($title . ' — รายการ order ยังไม่ update') . '">ส่งแล้ว</span>';
        }
        return '<span title="' . h($title) . '">ส่งแล้ว</span>';
    }

    if ($isMatched && $assetSerialDelivered) {
        return stockparts_delivered_label_for_asset($isDelivered, true, true);
    }

    return h(stockparts_delivered_label($isDelivered));
}

/**
 * แถว dl สำหรับ card การเบิกขาย
 *
 * @param string $label
 * @param string $value HTML-safe text
 * @return string
 */
function stockparts_withdraw_dl_row(string $label, string $value): string
{
    return '<dt>' . h($label) . '</dt><dd>' . $value . '</dd>';
}

/**
 * ตารางรายการ po_order_parts ทั้งชุด
 *
 * @param array<int,array<string,mixed>> $lines
 * @param int $matchedId id ของแถวที่ตรงกับรุ่นเครื่อง (0 = ไม่มี)
 * @return string
 */
/**
 * สร้าง HTML ส่วนข้อมูลเคลมจาก stock_movements / stock_old
 *
 * @param array<string,mixed> $claim
 * @param array<string,mixed> $stock
 * @param string $serial
 * @return string
 */
function stockparts_withdraw_claim_section_html(array $claim, array $stock, string $serial): string
{
    if (empty($claim['is_claim'])) {
        return '';
    }

    $ref = stockparts_fmt_field($claim['reference_no'] ?? '');
    $out = '<div class="asset-sales-section asset-sales-section-claim">';
    $out .= '<div class="asset-sales-section-title">'
        . '<span class="asset-sales-badge asset-sales-badge-claim">เคลม</span> '
        . h($ref !== '-' ? $ref : 'รายการเคลม') . '</div>';
    $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">';

    if (!empty($claim['movement_date'])) {
        $out .= stockparts_withdraw_dl_row('วันที่เบิก', h(dthai($claim['movement_date'])));
    }
    if (!empty($claim['customer_hint'])) {
        $out .= stockparts_withdraw_dl_row('ลูกค้า / โครงการ', '<b>' . h($claim['customer_hint']) . '</b>');
    }
    if (!empty($stock['model'])) {
        $out .= stockparts_withdraw_dl_row('สินค้า', h(stockparts_fmt_field($stock['model'])));
    }
    if (!empty($claim['old_serial'])) {
        $oldLabel = h($claim['old_serial']);
        if (!empty($claim['old_model'])) {
            $oldLabel .= ' <span class="muted">(' . h($claim['old_model']) . ')</span>';
        }
        $out .= stockparts_withdraw_dl_row('Serial เก่า', '<span class="asset-sales-serial-old">' . $oldLabel . '</span>');
    }
    $out .= stockparts_withdraw_dl_row(
        'Serial ใหม่',
        '<span class="asset-sales-serial-new"><b>' . h(stockparts_fmt_field($serial)) . '</b></span>'
    );
    if (!empty($claim['notes'])) {
        $out .= stockparts_withdraw_dl_row('หมายเหตุ', h($claim['notes']));
    }
    $recorder = trim((string) ($claim['created_by'] ?? ''));
    if ($recorder === '' && !empty($stock['create_name'])) {
        $recorder = trim((string) $stock['create_name']);
    }
    if ($recorder !== '') {
        $out .= stockparts_withdraw_dl_row('ผู้บันทึก', h($recorder));
    }
    $out .= '</dl></div>';
    return $out;
}

/**
 * สร้าง HTML ส่วนข้อมูลขายแยกชิ้นจาก stock_movements
 *
 * @param array<string,mixed> $sale
 * @param array<string,mixed> $stock
 * @param string $serial
 * @return string
 */
function stockparts_withdraw_individual_sale_section_html(array $sale, array $stock, string $serial): string
{
    if (empty($sale['is_individual_sale'])) {
        return '';
    }

    $poNo = stockparts_fmt_field($sale['po_no'] ?? '');
    $titleRef = ($poNo !== '-') ? $poNo : stockparts_fmt_field($sale['reference_no'] ?? '');

    $out = '<div class="asset-sales-section asset-sales-section-sale">';
    $out .= '<div class="asset-sales-section-title">'
        . '<span class="asset-sales-badge asset-sales-badge-sale">ขายแยกชิ้น</span> '
        . h($titleRef !== '-' ? $titleRef : 'รายการขาย') . '</div>';
    $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">';

    if (!empty($sale['movement_date'])) {
        $out .= stockparts_withdraw_dl_row('วันที่เบิก', h(dthai($sale['movement_date'])));
    }
    if (!empty($sale['customer_name'])) {
        $out .= stockparts_withdraw_dl_row('ลูกค้า', '<b>' . h($sale['customer_name']) . '</b>');
    }
    if (!empty($stock['model'])) {
        $out .= stockparts_withdraw_dl_row('สินค้า', h(stockparts_fmt_field($stock['model'])));
    }
    $out .= stockparts_withdraw_dl_row(
        'Serial',
        '<span class="asset-sales-serial-new"><b>' . h(stockparts_fmt_field($serial)) . '</b></span>'
    );
    if (!empty($sale['ops_ref'])) {
        $out .= stockparts_withdraw_dl_row('OPS', h($sale['ops_ref']));
    }
    $recorder = trim((string) ($sale['created_by'] ?? ''));
    if ($recorder === '' && !empty($stock['create_name'])) {
        $recorder = trim((string) $stock['create_name']);
    }
    if ($recorder !== '') {
        $out .= stockparts_withdraw_dl_row('ผู้บันทึก', h($recorder));
    }
    $out .= '</dl></div>';
    return $out;
}

/**
 * สร้าง HTML ส่วนลงทะเบียนอุปกรณ์จาก stock_movements
 *
 * @param array<string,mixed> $reg
 * @param array<string,mixed> $stock
 * @param string $serial
 * @return string
 */
function stockparts_withdraw_registration_section_html(array $reg, array $stock, string $serial): string
{
    if (empty($reg['is_registration'])) {
        return '';
    }

    $ref = stockparts_fmt_field($reg['reference_no'] ?? $reg['order_id'] ?? '');
    $out = '<div class="asset-sales-section asset-sales-section-registration">';
    $out .= '<div class="asset-sales-section-title">'
        . '<span class="asset-sales-badge asset-sales-badge-registration">ลงทะเบียนอุปกรณ์</span> '
        . h($ref !== '-' ? $ref : 'ORDER') . '</div>';
    $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">';

    if (!empty($reg['movement_date'])) {
        $out .= stockparts_withdraw_dl_row('วันที่ลงทะเบียน', h(dthai($reg['movement_date'])));
    }
    if (!empty($reg['customer_name'])) {
        $out .= stockparts_withdraw_dl_row('ลูกค้า', '<b>' . h($reg['customer_name']) . '</b>');
    }
    if (!empty($stock['model'])) {
        $out .= stockparts_withdraw_dl_row('สินค้า', h(stockparts_fmt_field($stock['model'])));
    }
    $out .= stockparts_withdraw_dl_row(
        'Serial',
        '<span class="asset-sales-serial-new"><b>' . h(stockparts_fmt_field($serial)) . '</b></span>'
    );
    if (!empty($reg['order_id'])) {
        $out .= stockparts_withdraw_dl_row('Order', h($reg['order_id']));
    }
    $recorder = trim((string) ($reg['created_by'] ?? ''));
    if ($recorder !== '') {
        $out .= stockparts_withdraw_dl_row('ผู้บันทึก', h($recorder));
    }
    $out .= '</dl></div>';
    return $out;
}

/**
 * สร้าง HTML ส่วนประวัติการส่งมอบจาก stock_old
 *
 * @param array<string,mixed> $row
 * @return string
 */
function stockparts_withdraw_stock_old_section_html(array $row): string
{
    $serial = stockparts_fmt_field($row['serial_number'] ?? '');
    if ($serial === '-') {
        return '';
    }

    $out = '<div class="asset-sales-section asset-sales-section-stock-old">';
    $out .= '<div class="asset-sales-section-title">'
        . '<span class="asset-sales-badge asset-sales-badge-stock-old">ส่งมอบแล้ว</span> '
        . 'ประวัติการส่งมอบ</div>';
    $out .= '<table class="asset-sales-lines asset-sales-stock-old-table"><thead><tr>';
    $out .= '<th>Serial</th><th>วันที่ส่ง</th><th>Model</th><th>ผู้บันทึก</th><th>ไซต์งาน</th><th>บริษัท รปภ.</th><th>Active</th>';
    $out .= '</tr></thead><tbody><tr>';
    $out .= '<td><b>' . h($serial) . '</b></td>';
    $out .= '<td>' . h(!empty($row['timestamp']) ? dthai($row['timestamp']) : '-') . '</td>';
    $out .= '<td>' . h(stockparts_fmt_field($row['model'] ?? '')) . '</td>';
    $out .= '<td>' . h(stockparts_fmt_field($row['create_name'] ?? '')) . '</td>';
    $out .= '<td>' . h(stockparts_fmt_field($row['setup_id'] ?? '')) . '</td>';
    $out .= '<td>' . h(stockparts_fmt_field($row['security_company'] ?? '')) . '</td>';
    $active = isset($row['active']) ? ((int) $row['active'] === 1 ? 'ใช้งาน' : 'ไม่นับ') : '-';
    $out .= '<td>' . h($active) . '</td>';
    $out .= '</tr></tbody></table></div>';
    return $out;
}

function stockparts_withdraw_order_table_html(array $lines, int $matchedId = 0, bool $serialDelivered = false, array $lineSerialMap = []): string
{
    if (!$lines) {
        return '';
    }

    $out = '<div class="asset-sales-lines-wrap">';
    $showSerialCol = !empty($lineSerialMap);
    $out .= '<div class="asset-sales-lines-title">รายการทั้งหมดในชุด (' . count($lines) . ' รายการ)';
    if ($showSerialCol) {
        $out .= ' <span class="muted">· S/N จากลงทะเบียนอุปกรณ์</span>';
    }
    $out .= '</div>';
    $out .= '<table class="asset-sales-lines' . ($showSerialCol ? ' has-serial-col' : '') . '"><thead><tr>';
    $out .= '<th>รหัส</th><th>รายการ</th><th>หมวด</th><th class="num">จำนวน</th>';
    if ($showSerialCol) {
        $out .= '<th>Serial</th>';
    }
    $out .= '<th>สถานะ</th><th>ส่งมอบ</th>';
    $out .= '</tr></thead><tbody>';

    foreach ($lines as $line) {
        $lineId = (int) ($line['id'] ?? 0);
        $isMatched = ($matchedId > 0 && $lineId === $matchedId);
        $statusRaw = trim((string) ($line['status_product'] ?? ''));
        $statusEmpty = ($statusRaw === '' || $statusRaw === '-');
        $qty = (int) ($line['quantity'] ?? 0);
        $unit = stockparts_fmt_field($line['unit'] ?? 'ชิ้น');

        $out .= '<tr' . ($isMatched ? ' class="is-matched"' : '') . '>';
        $out .= '<td>' . h(stockparts_fmt_field($line['part_code'] ?? '')) . '</td>';
        $out .= '<td>' . h(stockparts_fmt_field($line['part_name'] ?? ''));
        if ($isMatched) {
            $out .= ' <span class="asset-sales-match-tag">เครื่องนี้</span>';
        }
        $out .= '</td>';
        $out .= '<td>' . h(stockparts_fmt_field($line['category_name'] ?? '')) . '</td>';
        $out .= '<td class="num">' . ($qty > 0 ? h(number_format($qty) . ' ' . $unit) : '-') . '</td>';
        if ($showSerialCol) {
            $lineSn = ($lineId > 0 && isset($lineSerialMap[$lineId])) ? $lineSerialMap[$lineId] : '';
            if ($lineSn !== '') {
                $out .= '<td><span class="asset-sales-serial-tag">' . h($lineSn) . '</span></td>';
            } else {
                $out .= '<td><span class="muted">-</span></td>';
            }
        }
        $out .= '<td><span class="asset-sales-badge' . ($statusEmpty ? ' is-empty' : '') . '">'
            . h($statusEmpty ? 'ไม่ระบุ' : $statusRaw) . '</span></td>';
        $out .= '<td>' . stockparts_line_delivery_cell_html($line, $lineSerialMap, $serialDelivered, $isMatched) . '</td>';
        $out .= '</tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * ข้อมูลสรุปของการเบิก — รวมจากแหล่งไหนก็ได้ที่มี ให้เหลือชุดเดียว
 *
 * เดิมการ์ดแยกกล่องตาม "ตารางต้นทาง" ทุกกล่องจึงพก ลูกค้า / สินค้า / ผู้บันทึก /
 * วันที่ ติดมาเองซ้ำ ๆ — ในการ์ดใบเดียวเลขคำสั่งเบิกโผล่ 5 ครั้ง ชื่อรุ่น 5 ครั้ง
 * S/N 4 ครั้ง ทั้งที่ S/N กับรุ่นก็อยู่ในหัวเรื่องเหนือการ์ดอยู่แล้ว
 *
 * @param  array<string,mixed> $info
 * @return array<string,mixed>
 */
function stockparts_withdraw_summary_fields(array $info): array
{
    $claim = is_array($info['claim'] ?? null) ? $info['claim'] : null;
    $sale = is_array($info['individual_sale'] ?? null) ? $info['individual_sale'] : null;
    $reg = is_array($info['registration'] ?? null) ? $info['registration'] : null;
    $sum = is_array($info['summary'] ?? null) ? $info['summary'] : null;
    $stock = is_array($info['stock'] ?? null) ? $info['stock'] : [];

    if ($claim) {
        $kind = 'เคลม';
        $kindClass = 'claim';
    } elseif ($sale) {
        $kind = 'ขายแยกรายการ';
        $kindClass = 'sale';
    } elseif ($reg) {
        $kind = 'ลงทะเบียนอุปกรณ์';
        $kindClass = 'registration';
    } else {
        $kind = 'เบิกขาย';
        $kindClass = 'withdraw';
    }

    $first = static function (array $vals) {
        foreach ($vals as $v) {
            $v = trim((string) $v);
            if ($v !== '' && $v !== '-') {
                return $v;
            }
        }
        return '';
    };

    return [
        'kind'       => $kind,
        'kind_class' => $kindClass,
        'customer'   => $first([
            $reg['customer_name'] ?? '', $sale['customer_name'] ?? '',
            $claim['customer_hint'] ?? '', $sum['order_customer_name'] ?? '',
        ]),
        'date'       => $first([
            $reg['movement_date'] ?? '', $sale['movement_date'] ?? '',
            $claim['movement_date'] ?? '', $stock['timestamp'] ?? '',
        ]),
        'ref'        => trim((string) ($info['setup_id'] ?? '')),
        'by'         => $first([
            $reg['created_by'] ?? '', $claim['created_by'] ?? '',
            $sale['created_by'] ?? '', $stock['create_name'] ?? '',
        ]),
        'po'         => $first([$sale['po_no'] ?? '']),
        'old_serial' => $first([$claim['old_serial'] ?? '']),
        'notes'      => $first([$claim['notes'] ?? '', $sale['notes'] ?? '', $reg['notes'] ?? '']),
        'set_name'   => $first([$sum['order_product_type'] ?? '']),
    ];
}

/**
 * บรรทัดสรุปหัวการ์ด — ตอบว่า "ขายให้ใคร เมื่อไหร่ อ้างอิงอะไร" ในที่เดียว
 *
 * @param  array<string,mixed> $f
 * @return string
 */
function stockparts_withdraw_summary_html(array $f): string
{
    $bits = [];
    if ($f['customer'] !== '') {
        $bits[] = '<b class="asset-sales-cust">' . h(stockparts_fmt_field($f['customer'])) . '</b>';
    }
    if ($f['date'] !== '') {
        $bits[] = '<span class="asset-sales-when">' . h(dthai($f['date'])) . '</span>';
    }
    if ($f['ref'] !== '') {
        $bits[] = 'อ้างอิง <b class="asset-sales-setup-id">#' . h($f['ref']) . '</b>';
    }

    $out = '<div class="asset-sales-summary">'
        . '<span class="asset-sales-badge asset-sales-badge-' . h($f['kind_class']) . '">'
        . h($f['kind']) . '</span> '
        . implode(' <span class="asset-sales-sep">·</span> ', $bits)
        . '</div>';

    // รายละเอียดที่มีเฉพาะบางแบบ — ไม่ต้องมีกล่องของตัวเอง
    $extra = '';
    if ($f['old_serial'] !== '') {
        $extra .= stockparts_withdraw_dl_row('เปลี่ยนจาก S/N', '<b>' . h($f['old_serial']) . '</b>');
    }
    if ($f['po'] !== '') {
        $extra .= stockparts_withdraw_dl_row('PO', h($f['po']));
    }
    if ($f['set_name'] !== '') {
        $extra .= stockparts_withdraw_dl_row('ชุดสินค้า', h(stockparts_fmt_field($f['set_name'])));
    }
    if ($f['by'] !== '') {
        $extra .= stockparts_withdraw_dl_row('ผู้บันทึก', h(stockparts_fmt_field($f['by'])));
    }
    if ($f['notes'] !== '') {
        $extra .= stockparts_withdraw_dl_row('หมายเหตุ', nl2br(h($f['notes'])));
    }
    if ($extra !== '') {
        $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">' . $extra . '</dl>';
    }
    return $out;
}

/**
 * ตอบว่า "ส่งมอบแล้วหรือยัง" — stock_old เก็บได้แถวเดียวต่อเครื่อง
 * จึงเขียนเป็นบรรทัดสรุป ไม่ต้องเป็นตาราง 7 คอลัมน์
 *
 * @param  array<string,mixed> $info
 * @return string
 */
function stockparts_withdraw_delivery_html(array $info): string
{
    $old = is_array($info['stock_old'] ?? null) ? $info['stock_old'] : null;
    $hasOld = !empty($info['has_stock_old']) && $old;

    if (!$hasOld) {
        if (empty($info['serial_delivered'])) {
            return '';
        }
        return '<div class="asset-sales-block"><div class="asset-sales-block-title">การส่งมอบ</div>'
            . '<p class="asset-sales-delivered-line">'
            . '<span class="asset-sales-badge asset-sales-badge-stock-old">ส่งมอบแล้ว</span>'
            . ' <span class="muted">ไม่มีบันทึกไซต์งาน</span></p></div>';
    }

    $rows = '';
    $site = trim((string) ($old['setup_id'] ?? ''));
    $sec = trim((string) ($old['security_company'] ?? ''));
    $when = trim((string) ($old['timestamp'] ?? ''));
    if ($when !== '') {
        $rows .= stockparts_withdraw_dl_row('วันที่ส่ง', h(dthai($when)));
    }
    if ($site !== '') {
        $rows .= stockparts_withdraw_dl_row('ไซต์งาน', '<b>' . h($site) . '</b>');
    }
    if ($sec !== '') {
        $rows .= stockparts_withdraw_dl_row('บริษัท รปภ.', h($sec));
    }
    $by = trim((string) ($old['create_name'] ?? ''));
    if ($by !== '') {
        $rows .= stockparts_withdraw_dl_row('ผู้บันทึก', h(stockparts_fmt_field($by)));
    }

    return '<div class="asset-sales-block"><div class="asset-sales-block-title">'
        . '<span class="asset-sales-badge asset-sales-badge-stock-old">ส่งมอบแล้ว</span> การส่งมอบ</div>'
        . '<dl class="asset-sales-dl asset-sales-dl-inline">' . $rows . '</dl></div>';
}

/**
 * เชิงอรรถบอกที่มาของข้อมูล — เดิมเป็นแถวหัวการ์ดเทียบเท่าข้อมูลจริง
 * ทั้งที่คนอ่านสนใจก็ต่อเมื่อสงสัยว่าตัวเลขมาจากไหน
 *
 * @param  array<string,mixed> $info
 * @return string
 */
function stockparts_withdraw_provenance_html(array $info): string
{
    $bits = [];
    $src = (string) ($info['resolve_source'] ?? '');
    if ($src !== '' && $src !== 'setup_id') {
        $bits[] = 'อ่านจาก ' . stockparts_resolve_source_label($src);
    }
    $stock = is_array($info['stock'] ?? null) ? $info['stock'] : [];
    if (!empty($stock['timestamp'])) {
        $bits[] = 'บันทึกใน stock ' . dthai_full($stock['timestamp']);
    }
    if (!empty($info['stock_synthetic'])) {
        $bits[] = 'ไม่มีใน stock ปัจจุบัน';
    }
    if (isset($stock['active']) && (int) $stock['active'] !== 1) {
        $bits[] = 'ไม่นับ stock';
    }
    if (!$bits) {
        return '';
    }
    return '<p class="asset-sales-provenance muted">' . h(implode(' · ', $bits)) . '</p>';
}

/**
 * การ์ด "การเบิกใช้งานขาย" บนหน้าโปรไฟล์เครื่อง
 *
 * จัดตามคำถามของคนอ่าน ไม่ใช่ตามตารางต้นทาง — ขายให้ใครเมื่อไหร่ / ส่งมอบหรือยัง /
 * ในชุดมีอะไรบ้าง ส่วน S/N กับรุ่นไม่แสดงซ้ำ เพราะอยู่ในหัวเรื่องข้าง ๆ อยู่แล้ว
 *
 * @param  array<string,mixed> $info
 * @return string
 */
function asset_stockparts_withdraw_card_html(array $info): string
{
    $out = '<div class="asset-sales-card">';
    $out .= '<div class="asset-sales-card-head">' . ui_icon_html('stock-out-set', 16)
        . '<b>การเบิกใช้งานขาย</b></div>';
    $out .= '<div class="asset-sales-card-body">';

    // ประวัติจากระบบขาย (biton_setup) — 31 จาก 170 รายการไม่มีแถวใน stock เลย
    // การ์ดจึงต้องแสดงมันได้แม้ฝั่ง stock จะไม่มีอะไรเลย ไม่งั้นข้อมูลที่มีอยู่จริงหายไป
    $serial = (string) ($info['serial'] ?? '');
    $setupHtml = function_exists('setup_sale_history_html')
        ? setup_sale_history_html($serial, empty($info['ok']) ? [] : stockparts_withdraw_summary_fields($info))
        : '';
    // ประวัติติดตั้งจากระบบ installation เดิม (ปี 2010–2023) — ต่อท้ายประวัติขายของ setup
    if (function_exists('installation_history_html') && $serial !== '') {
        $setupHtml .= installation_history_html($serial);
    }

    if (empty($info['ok'])) {
        $msg = '<p class="muted asset-sales-empty">'
            . h((string) ($info['message'] ?? 'ไม่มีข้อมูล')) . '</p>';
        return $out . ($setupHtml !== '' ? $setupHtml : $msg) . '</div></div>';
    }

    $hasWithdraw = !empty($info['has_withdraw']);
    $hasStockOld = !empty($info['has_stock_old']) && !empty($info['stock_old']);
    if (!$hasWithdraw && !$hasStockOld) {
        $msg = '<p class="muted asset-sales-empty">'
            . h((string) ($info['message'] ?? 'ยังไม่มีการเบิกใช้งานขาย')) . '</p>';
        return $out . ($setupHtml !== '' ? $setupHtml : $msg) . '</div></div>';
    }

    // ① ขายให้ใคร เมื่อไหร่ อ้างอิงอะไร
    if ($hasWithdraw) {
        $out .= stockparts_withdraw_summary_html(stockparts_withdraw_summary_fields($info));
    }

    // ② ส่งมอบแล้วหรือยัง
    $out .= stockparts_withdraw_delivery_html($info);

    // ③ ในชุดที่ขายมีอะไรบ้าง — แถวของเครื่องนี้ถูกไฮไลต์ในตาราง
    //    จึงไม่ต้องมีกล่อง "รายการที่ตรงกับเครื่องนี้" ซ้ำอีกชุด
    if ($hasWithdraw && !empty($info['has_order_detail']) && !empty($info['order_lines'])) {
        $matched = is_array($info['matched_line'] ?? null) ? $info['matched_line'] : null;
        $out .= stockparts_withdraw_order_table_html(
            $info['order_lines'],
            (int) ($matched['id'] ?? 0),
            !empty($info['serial_delivered']),
            is_array($info['line_serial_map'] ?? null) ? $info['line_serial_map'] : []
        );
    } elseif ($hasWithdraw && !empty($info['message'])) {
        $out .= '<p class="muted asset-sales-empty">' . h((string) $info['message']) . '</p>';
    }

    $out .= $setupHtml;
    $out .= stockparts_withdraw_provenance_html($info);
    $out .= '<p class="asset-sales-foot muted"><a href="'
        . h(BASE_URL . '/share.php?q=' . rawurlencode((string) $info['serial']))
        . '">ดูในทะเบียน stock →</a></p>';

    return $out . '</div></div>';
}
