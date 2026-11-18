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
 * S/N ในชุด: po_order_part_serials ที่ biton_setup (หลัก) หรือ stock ฝั่งเดียวกัน · fallback stock_movements ลงทะเบียน
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
/**
 * แปลงแถวทะเบียนเก่า (stock_old) เป็นหลักฐานส่งมอบ
 *
 * stock_old คือทะเบียนของระบบเดิมก่อน import เข้าระบบปัจจุบัน แถวหนึ่งบันทึกว่า
 * เครื่องถูกส่งไปติดตั้งที่ไซต์งานไหน เมื่อไหร่ ใครเป็นคนบันทึก — เป็นหลักฐาน
 * คนละชุดกับใบเบิกขายใน stock ปัจจุบัน และไม่มีในระบบเช่า คนไล่ดูจึงหาไม่เจอ
 * ถ้าไม่บอกที่มาไว้ ต้องส่งรายละเอียดออกไปให้หน้าจอแสดงด้วย
 *
 * @param array<string,mixed> $oldRow แถวจาก stock_old
 * @return array<string,mixed>
 */
function stockparts_delivery_evidence(array $oldRow): array
{
    $label = trim((string) ($oldRow['setup_id'] ?? ''));
    return [
        'sold' => true,
        'setup_id' => $label !== '' ? $label : 'ส่งมอบแล้ว',
        'resolve_source' => 'stock_old',
        // หลักฐานมาจากประวัติส่งมอบ ไม่ใช่ใบเบิกขาย — ตัวตัดสินสถานะ
        // ต้องถ่วงน้ำหนักเบากว่า เพื่อไม่ให้ไปทับเครื่องที่อยู่ในสัญญาเช่า
        'from_delivery' => true,
        'delivery_site' => $label,
        'delivery_date' => substr(trim((string) ($oldRow['timestamp'] ?? '')), 0, 10),
        'delivery_by' => trim((string) ($oldRow['create_name'] ?? '')),
        'delivery_guard' => trim((string) ($oldRow['security_company'] ?? '')),
    ];
}

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
 * แผนที่ S/N → URL หน้าโปรไฟล์เครื่อง (assets) สำหรับป้ายในตารางเบิกขาย
 *
 * @param array<int,string> $serials
 * @return array<string,string> คีย์ S/N ตัวพิมพ์ใหญ่ → href
 */
function stockparts_asset_profile_url_map(array $serials): array
{
    $uniq = [];
    $seen = [];
    foreach ($serials as $s) {
        $s = trim((string) $s);
        if ($s === '') {
            continue;
        }
        $k = mb_strtoupper($s);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $uniq[] = $s;
    }
    if ($uniq === []) {
        return [];
    }
    $out = [];
    try {
        $db = db();
        $ph = implode(',', array_fill(0, count($uniq), '?'));
        $types = str_repeat('s', count($uniq) * 2);
        $st = $db->prepare(
            "SELECT id, asset_code, factory_serial
             FROM assets
             WHERE factory_serial IN ($ph) OR asset_code IN ($ph)"
        );
        if (!$st) {
            return [];
        }
        $st->bind_param($types, ...array_merge($uniq, $uniq));
        $st->execute();
        $res = $st->get_result();
        $base = defined('BASE_URL') ? BASE_URL : '';
        while ($row = $res->fetch_assoc()) {
            $url = $base . '/asset.php?id=' . (int) ($row['id'] ?? 0);
            foreach (['factory_serial', 'asset_code'] as $col) {
                $sn = trim((string) ($row[$col] ?? ''));
                if ($sn !== '') {
                    $out[mb_strtoupper($sn)] = $url;
                }
            }
        }
        $st->close();
    } catch (Throwable $e) {
        error_log('[stockparts_asset_profile_url_map] ' . $e->getMessage());
    }
    return $out;
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
 * แยก S/N ทั้งหมดจาก notes ลงทะเบียนอุปกรณ์ (มักคั่นด้วย comma)
 *
 * @param string $notes
 * @return array<int,string>
 */
function stockparts_parse_registration_serials(string $notes): array
{
    if (!preg_match('/Serial:\s*(.+)$/u', $notes, $m)) {
        return [];
    }
    $parts = preg_split('/\s*,\s*/u', trim((string) $m[1]));
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p, " \t\n\r\0\x0B(),");
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * แยก S/N ตัวแรกจาก notes ลงทะเบียนอุปกรณ์ (คงพฤติกรรมเดิมสำหรับ caller เก่า)
 *
 * @param string $notes
 * @return string|null
 */
function stockparts_parse_registration_serial(string $notes): ?string
{
    $all = stockparts_parse_registration_serials($notes);
    return $all !== [] ? $all[0] : null;
}

/**
 * ฐานที่เก็บ po_order_part_serials — ปกติอยู่ biton_setup ไม่ใช่ biton_stockparts
 *
 * @param mysqli $stockDb
 * @return mysqli|null
 */
function stockparts_db_for_order_part_serials($stockDb)
{
    if (function_exists('dbSetup')) {
        $setup = dbSetup();
        if ($setup) {
            return $setup;
        }
    }
    return $stockDb ?: null;
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
        $meta = [
            'movement_date' => trim((string) ($row['movement_date'] ?? '')),
            'customer_name' => trim((string) ($row['customer_name'] ?? '')),
        ];
        foreach (stockparts_parse_registration_serials((string) ($row['notes'] ?? '')) as $sn) {
            $out[] = ['serial' => $sn] + $meta;
        }
    }
    return $out;
}

/**
 * แปลงค่า map รายการ → รายการ S/N (รองรับ string เดี่ยวหรือ array)
 *
 * @param mixed $entry
 * @return array<int,string>
 */
function stockparts_line_serial_list($entry): array
{
    if (is_array($entry)) {
        $out = [];
        foreach ($entry as $sn) {
            $sn = trim((string) $sn);
            if ($sn !== '' && !in_array($sn, $out, true)) {
                $out[] = $sn;
            }
        }
        return $out;
    }
    $sn = trim((string) $entry);
    return $sn !== '' ? [$sn] : [];
}

/**
 * รวม S/N ทุกแถวใน line_serial_map เป็นชุดเดียว (ใช้เช็กซ้ำ)
 *
 * @param array<int,mixed> $map
 * @return array<int,string>
 */
function stockparts_line_serial_map_flat(array $map): array
{
    $flat = [];
    foreach ($map as $entry) {
        foreach (stockparts_line_serial_list($entry) as $sn) {
            if (!in_array($sn, $flat, true)) {
                $flat[] = $sn;
            }
        }
    }
    return $flat;
}

/**
 * เพิ่ม S/N ลง map ต่อ line_id (หลายตัวต่อแถวเมื่อ quantity > 1)
 *
 * @param array<int,mixed> $map
 * @param int              $lineId
 * @param string           $sn
 */
function stockparts_line_serial_map_add(array &$map, int $lineId, string $sn): void
{
    if ($lineId <= 0 || $sn === '') {
        return;
    }
    $list = stockparts_line_serial_list($map[$lineId] ?? null);
    if (!in_array($sn, $list, true)) {
        $list[] = $sn;
    }
    $map[$lineId] = $list;
}

/**
 * จับคู่ชื่อรายการในใบสั่งงานกับชื่อรุ่นจาก S/N
 *
 * @param string $partName ชื่อใน po_order_parts
 * @param string $prodName ชื่อจาก stock / production
 * @return bool
 */
function stockparts_part_name_matches(string $partName, string $prodName): bool
{
    $a = trim($partName);
    $b = trim($prodName);
    if ($a === '' || $b === '') {
        return false;
    }
    if (strcasecmp($a, $b) === 0) {
        return true;
    }
    $na = preg_replace('/\s+/u', ' ', mb_strtolower($a, 'UTF-8'));
    $nb = preg_replace('/\s+/u', ' ', mb_strtolower($b, 'UTF-8'));
    if ($na === $nb) {
        return true;
    }

    return strpos($na, $nb) !== false || strpos($nb, $na) !== false;
}

/**
 * รวบรวม S/N ในใบสั่งงาน → ชื่อรุ่น (ใช้จับคู่รายการ × n)
 *
 * @param mysqli                              $db
 * @param string                              $orderId
 * @param array<string,string>                $serialProducts  sn => ชื่อรุ่น (เติมเพิ่ม)
 * @param array<int,string>                   $usedSerials     S/N ที่ผูกแถวแล้ว
 */
function stockparts_collect_order_serial_product_names($stockDb, string $orderId, array &$serialProducts, array $usedSerials): void
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }
    $dbs = [];
    $setupDb = stockparts_db_for_order_part_serials($stockDb);
    if ($setupDb) {
        $dbs[] = $setupDb;
    }
    if ($stockDb && !in_array($stockDb, $dbs, true)) {
        $dbs[] = $stockDb;
    }
    foreach ($dbs as $db) {
        $st = $db->prepare(
            'SELECT DISTINCT serial_number FROM po_order_part_serials
             WHERE order_id = ? AND TRIM(IFNULL(serial_number, "")) <> ""'
        );
        if (!$st) {
            continue;
        }
        $st->bind_param('s', $orderId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $sn = trim((string) ($row['serial_number'] ?? ''));
            if ($sn === '' || in_array($sn, $usedSerials, true) || isset($serialProducts[$sn])) {
                continue;
            }
            $serialProducts[$sn] = stockparts_resolve_serial_product_name($stockDb, $sn);
        }
    }
}

/**
 * ดึง map line_id → S/N จาก po_order_part_serials บนฐานที่กำหนด
 *
 * @param mysqli $db
 * @param string $orderId
 * @return array<int,array<int,string>>
 */
function stockparts_fetch_line_serials_from_po_conn($db, string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return [];
    }

    $st = $db->prepare(
        'SELECT part_id, serial_number FROM po_order_part_serials
         WHERE order_id = ? AND TRIM(IFNULL(serial_number, "")) <> ""
         ORDER BY id'
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('s', $orderId);
    $st->execute();

    $map = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $sn = trim((string) ($row['serial_number'] ?? ''));
        $lineId = (int) ($row['part_id'] ?? 0);
        if ($sn !== '' && $lineId > 0) {
            stockparts_line_serial_map_add($map, $lineId, $sn);
        }
    }
    return $map;
}

/**
 * ดึง map line_id → S/N จาก po_order_part_serials (biton_setup เป็นหลัก)
 *
 * @param mysqli $stockDb
 * @param string $orderId
 * @return array<int,array<int,string>>
 */
function stockparts_fetch_line_serials_from_po($stockDb, string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return [];
    }
    $map = [];
    $setupDb = stockparts_db_for_order_part_serials($stockDb);
    if ($setupDb) {
        $map = stockparts_fetch_line_serials_from_po_conn($setupDb, $orderId);
    }
    if ($stockDb && $stockDb !== $setupDb) {
        $extra = stockparts_fetch_line_serials_from_po_conn($stockDb, $orderId);
        foreach ($extra as $lineId => $entry) {
            foreach (stockparts_line_serial_list($entry) as $sn) {
                stockparts_line_serial_map_add($map, (int) $lineId, $sn);
            }
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
 * @return array<int,array<int,string>> map line_id => รายการ serial_number
 */
function stockparts_map_lines_to_registered_serials($db, string $orderId, array $lines): array
{
    if (!$lines) {
        return [];
    }

    $map = stockparts_fetch_line_serials_from_po($db, $orderId);

    $lineIds = [];
    foreach ($lines as $line) {
        $lid = (int) ($line['id'] ?? 0);
        if ($lid > 0) {
            $lineIds[$lid] = true;
        }
    }
    foreach (array_keys($map) as $lid) {
        if (!isset($lineIds[$lid])) {
            unset($map[$lid]);
        }
    }

    $usedSerials = stockparts_line_serial_map_flat($map);
    $serialProducts = [];
    stockparts_collect_order_serial_product_names($db, $orderId, $serialProducts, $usedSerials);

    $registrations = stockparts_fetch_order_registration_serials($db, $orderId);
    foreach ($registrations as $reg) {
        $sn = $reg['serial'];
        if (in_array($sn, $usedSerials, true) || isset($serialProducts[$sn])) {
            continue;
        }
        $serialProducts[$sn] = stockparts_resolve_serial_product_name($db, $sn);
    }

    foreach ($lines as $line) {
        $lineId = (int) ($line['id'] ?? 0);
        if ($lineId <= 0) {
            continue;
        }
        $needQty = max(1, (int) ($line['quantity'] ?? 1));
        $have = count(stockparts_line_serial_list($map[$lineId] ?? null));
        if ($have >= $needQty) {
            continue;
        }
        $partName = trim((string) ($line['part_name'] ?? ''));
        if ($partName === '') {
            continue;
        }
        foreach ($serialProducts as $sn => $prodName) {
            if ($have >= $needQty) {
                break;
            }
            if (in_array($sn, $usedSerials, true)) {
                continue;
            }
            if ($prodName !== '' && stockparts_part_name_matches($partName, $prodName)) {
                stockparts_line_serial_map_add($map, $lineId, $sn);
                $usedSerials[] = $sn;
                $have++;
            }
        }
    }

    $flat = stockparts_line_serial_map_flat($map);
    $unmappedSerials = array_values(array_filter(array_keys($serialProducts), function ($sn) use ($flat) {
        return !in_array($sn, $flat, true);
    }));
    $unmappedLineIds = [];
    foreach ($lines as $line) {
        $lid = (int) ($line['id'] ?? 0);
        if ($lid > 0 && stockparts_line_serial_list($map[$lid] ?? null) === []) {
            $unmappedLineIds[] = $lid;
        }
    }
    if (count($unmappedSerials) === 1 && count($unmappedLineIds) === 1) {
        stockparts_line_serial_map_add($map, $unmappedLineIds[0], $unmappedSerials[0]);
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
            stockparts_line_serial_map_add($map, $lid, $unmappedSerials[$i]);
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
            $out[$sn] = stockparts_delivery_evidence($oldRows[$sn]);
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
            $out[$sn] = stockparts_delivery_evidence($oldRow);
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
 * รายการเครื่องในชุดที่ขายพร้อมกัน
 *
 * เหลือแค่ S/N กับชื่อรายการ — คอลัมน์หมวด จำนวน สถานะ และส่งมอบ มีค่าเดียวกันทุกแถว
 * แทบทุกใบ จึงไม่ได้บอกอะไรนอกจากกินที่ ส่วน "ส่งมอบแล้ว" บอกไว้ที่หัวขั้นแล้ว
 *
 * @param array<int,array<string,mixed>> $lines
 * @param int                            $matchedId     id ของแถวที่เป็นเครื่องนี้
 * @param bool                           $serialDelivered
 * @param array<int,array<int,string>|string> $lineSerialMap id แถว => S/N (หนึ่งหรือหลายตัว)
 * @param string                              $pageSerial   S/N ของหน้าเครื่องที่เปิดอยู่ (ไฮไลต์ในตาราง)
 */
function stockparts_withdraw_order_table_html(
    array $lines,
    int $matchedId = 0,
    bool $serialDelivered = false,
    array $lineSerialMap = [],
    string $pageSerial = ''
): string {
    if (!$lines) {
        return '';
    }
    $showSerial = !empty($lineSerialMap);
    $pageSerial = trim($pageSerial);
    $profileUrls = [];
    if ($showSerial) {
        $allSerials = [];
        foreach ($lines as $ln) {
            $lid = (int) ($ln['id'] ?? 0);
            foreach (stockparts_line_serial_list($lineSerialMap[$lid] ?? null) as $s) {
                $allSerials[] = $s;
            }
        }
        $profileUrls = stockparts_asset_profile_url_map($allSerials);
    }

    $out = '<div class="asset-sales-lines-wrap">';
    $out .= '<div class="asset-sales-lines-title">ในชุดนี้มี ' . count($lines) . ' รายการ';
    if ($showSerial) {
        $out .= ' <span class="muted">· S/N จากลงทะเบียนอุปกรณ์</span>';
    }
    $out .= '</div>';
    $out .= '<table class="asset-sales-lines' . ($showSerial ? ' has-part-sn' : '') . '"><tbody>';
    foreach ($lines as $ln) {
        $id = (int) ($ln['id'] ?? 0);
        $isMe = $matchedId > 0 && $id === $matchedId;
        $snList = stockparts_line_serial_list($lineSerialMap[$id] ?? null);
        $qty = (int) ($ln['quantity'] ?? 0);
        $out .= '<tr' . ($isMe ? ' class="is-matched"' : '') . '>';
        if ($showSerial) {
            $out .= '<td class="asset-sales-line-name">' . h(stockparts_fmt_field($ln['part_name'] ?? ''))
                . ($qty > 1 ? ' <span class="muted">×' . $qty . '</span>' : '')
                . ($isMe ? ' <span class="asset-sales-match-tag">เครื่องนี้</span>' : '')
                . '</td>';
            if ($snList) {
                $tags = '';
                foreach ($snList as $oneSn) {
                    $oneSn = trim((string) $oneSn);
                    $snKey = mb_strtoupper($oneSn);
                    $isPage = $pageSerial !== '' && $snKey === mb_strtoupper($pageSerial);
                    $cls = 'asset-sales-serial-tag' . ($isPage ? ' is-page-serial' : '');
                    $profileUrl = $profileUrls[$snKey] ?? '';
                    if ($profileUrl !== '') {
                        $tags .= '<a class="' . $cls . '" href="' . h($profileUrl) . '">' . h($oneSn) . '</a>';
                    } else {
                        $tags .= '<span class="' . $cls . '">' . h($oneSn) . '</span>';
                    }
                }
                $out .= '<td class="asset-sales-line-sn"><span class="asset-sales-serial-tags">' . $tags . '</span></td>';
            } else {
                $out .= '<td class="asset-sales-line-sn"><span class="muted">—</span></td>';
            }
        } else {
            $out .= '<td class="asset-sales-line-name">' . h(stockparts_fmt_field($ln['part_name'] ?? ''))
                . ($qty > 1 ? ' <span class="muted">×' . $qty . '</span>' : '')
                . ($isMe ? ' <span class="asset-sales-match-tag">เครื่องนี้</span>' : '')
                . '</td>';
            $out .= '<td class="asset-sales-line-sn">' . h(stockparts_fmt_field($ln['part_code'] ?? '')) . '</td>';
        }
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
 * เชิงอรรถบอกที่มาของข้อมูล — เดิมเป็นแถวหัวการ์ดเทียบเท่าข้อมูลจริง
 * ทั้งที่คนอ่านสนใจก็ต่อเมื่อสงสัยว่าตัวเลขมาจากไหน
 *
 * @param  array<string,mixed> $info
 * @return array<int,string>
 */
function stockparts_withdraw_provenance_bits(array $info): array
{
    $bits = [];
    $src = (string) ($info['resolve_source'] ?? '');
    if ($src !== '' && $src !== 'setup_id') {
        $bits[] = 'อ่านจาก ' . stockparts_resolve_source_label($src);
    }
    $stock = is_array($info['stock'] ?? null) ? $info['stock'] : [];
    if (!empty($stock['timestamp'])) {
        $bits[] = 'บันทึก ' . dthai_full($stock['timestamp']);
    }
    if (!empty($info['stock_synthetic'])) {
        $bits[] = 'ไม่มีใน stock ปัจจุบัน';
    }
    if (isset($stock['active']) && (int) $stock['active'] !== 1) {
        $bits[] = 'ไม่นับสต็อก';
    }
    return $bits;
}

/**
 * ขั้นหนึ่งของไทม์ไลน์ในการ์ด
 *
 * @param string $when  วันที่ (Y-m-d หรือ datetime) — ว่างได้
 * @param string $where แหล่งที่มาของข้อมูลขั้นนี้
 * @param string $title บรรทัดหลัก (HTML)
 * @param string $meta  บรรทัดรายละเอียด (escape มาแล้ว)
 * @param string $extra บล็อกต่อท้าย เช่น คำเตือนหรือตาราง
 */
function stockparts_withdraw_step_html(string $when, string $where, string $title, string $meta = '', string $extra = ''): string
{
    $head = [];
    if (trim($when) !== '') {
        // เวลา 00:00:00 คือ "ไม่รู้เวลา" ของทะเบียนเก่า ไม่ใช่เที่ยงคืนจริง จึงไม่ต้องโชว์
        $w = trim($when);
        $head[] = h((strlen($w) > 10 && substr($w, 11) !== "00:00:00") ? dthai_full($w) : dthai($w));
    }
    if (trim($where) !== '') {
        $head[] = h($where);
    }
    $out = '<div class="asset-sales-step">';
    if ($head) {
        $out .= '<div class="asset-sales-step-when">' . implode(' <span class="asset-sales-sep">·</span> ', $head) . '</div>';
    }
    if (trim($title) !== '') {
        $out .= '<div class="asset-sales-step-title">' . $title . '</div>';
    }
    if (trim($meta) !== '') {
        $out .= '<div class="asset-sales-step-meta muted">' . $meta . '</div>';
    }
    return $out . $extra . '</div>';
}

/**
 * ค่าที่ไม่ซ้ำ — เทียบแบบไม่สนตัวพิมพ์ ข้ามค่าว่างกับ "-"
 *
 * @param array<int,mixed> $vals
 * @return array<int,string>
 */
function stockparts_withdraw_uniq(array $vals): array
{
    $out = [];
    $seen = [];
    foreach ($vals as $v) {
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            continue;
        }
        $k = mb_strtolower($v);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $v;
    }
    return $out;
}

/**
 * แถวข้อเท็จจริงในการ์ดสรุป
 *
 * @param string $label
 * @param string $valueHtml ว่าง = ไม่วาดแถว
 * @param bool   $muted
 * @return string HTML
 */
function stockparts_withdraw_fact_row(string $label, string $valueHtml, bool $muted = false): string
{
    if (trim(html_entity_decode(strip_tags($valueHtml), ENT_QUOTES, 'UTF-8')) === '') {
        return '';
    }
    return '<dt>' . h($label) . '</dt><dd' . ($muted ? ' class="muted"' : '') . '>' . $valueHtml . '</dd>';
}

/**
 * การ์ด "การเบิกใช้งานขาย" — สรุปเรื่องขายครั้งเดียว แล้วบอกที่มาแบบย่อ
 *
 * ไม่เล่าเรื่องเดียวกันซ้ำตามแหล่งข้อมูล (ระบบขาย / stock / ส่งมอบ)
 * ลูกค้า PO Order อยู่บล็อกสรุป ที่มาอยู่ท้ายการ์ดเมื่ออยากไล่ว่าบันทึกที่ไหน
 *
 * @param array<string,mixed> $info     ผลจาก asset_stockparts_withdraw_info()
 * @param string              $footHtml บล็อกต่อท้ายการ์ด (เครื่องอื่นที่ไซต์งานเดียวกัน)
 */
function asset_stockparts_withdraw_card_html(array $info, string $footHtml = ''): string
{
    $serial = (string) ($info['serial'] ?? '');
    $ok = !empty($info['ok']);
    $f = $ok ? stockparts_withdraw_summary_fields($info) : [];

    $setupErr = '';
    $setupRows = [];
    if (function_exists('setup_sale_history_for_serial') && $serial !== '') {
        $hist = setup_sale_history_for_serial($serial);
        $setupErr = (string) ($hist['error'] ?? '');
        $setupRows = is_array($hist['rows'] ?? null) ? $hist['rows'] : [];
    }
    $installHtml = (function_exists('installation_history_html') && $serial !== '')
        ? installation_history_html($serial) : '';

    $hasWithdraw = $ok && !empty($info['has_withdraw']);
    $hasStockOld = $ok && !empty($info['has_stock_old']) && !empty($info['stock_old']);
    $delivered = $hasStockOld || ($ok && !empty($info['serial_delivered']));

    if (!$setupRows && !$hasWithdraw && !$hasStockOld && $installHtml === '') {
        $msg = $setupErr !== '' ? $setupErr : (string) ($info['message'] ?? 'ยังไม่มีข้อมูลการเบิกใช้งานขาย');
        return '<div class="asset-sales-card"><div class="asset-sales-card-head">'
            . ui_icon_html('stock-out-set', 16) . '<b>การเบิกใช้งานขาย</b></div>'
            . '<div class="asset-sales-card-body"><p class="muted asset-sales-empty">' . h($msg) . '</p>'
            . $footHtml . '</div></div>';
    }

    $pos = [];
    $orderRefs = [];
    $dates = [];
    $bys = [];
    $srcSale = [];
    $extraHtml = '';
    $conflictHtml = '';
    foreach ($setupRows as $r) {
        $v = function_exists('setup_sale_history_norm') ? setup_sale_history_norm($r) : [];
        $pos[] = (string) ($v['po'] ?? '');
        $orderRefs[] = (string) ($v['ref'] ?? '');
        $dates[] = (string) ($v['date'] ?? '');
        $bys[] = (string) ($v['by'] ?? '');
        $kind = function_exists('setup_sale_history_kind_label')
            ? setup_sale_history_kind_label($r) : '';
        $ref = trim((string) ($v['ref'] ?? ''));
        if ($kind !== '') {
            $srcSale[] = $kind . ($ref !== '' ? ' #' . $ref : '');
        }
        $isClaim = ((string) ($r['issue_type'] ?? '')) === 'claim';
        $role = (string) ($r['_role'] ?? 'new');
        $other = trim((string) ($v['other_sn'] ?? ''));
        if ($isClaim && $other !== '') {
            $verb = $role === 'new' ? 'เปลี่ยนมาจากเครื่อง' : 'ถูกเปลี่ยนเป็นเครื่อง';
            $extraHtml .= '<p class="asset-sales-claim-pair">' . h($verb) . ' <b>' . h($other) . '</b></p>';
        }
        if (function_exists('setup_sale_history_conflicts')) {
            foreach (setup_sale_history_conflicts($r, $f) as $c) {
                $conflictHtml .= '<p class="asset-sales-conflict">' . ui_icon_html('alert', 13, 'h-svg') . ' ' . h($c) . '</p>';
            }
        }
    }
    $pos[] = (string) ($f['po'] ?? '');
    $orderRefs[] = (string) ($f['ref'] ?? '');
    $dates[] = (string) ($f['date'] ?? '');
    $bys[] = (string) ($f['by'] ?? '');

    $custDisplay = function_exists('setup_sale_history_card_customer_fields')
        ? setup_sale_history_card_customer_fields($setupRows, $f)
        : ['head' => '', 'contact' => ''];
    $customer = (string) ($custDisplay['head'] ?? '');
    if ($customer === '' && trim((string) ($f['customer'] ?? '')) !== '') {
        $customer = trim((string) $f['customer']);
    }
    $customerContact = (string) ($custDisplay['contact'] ?? '');
    $poList = stockparts_withdraw_uniq($pos);
    $refList = [];
    $poKeys = [];
    foreach ($poList as $po) {
        $poKeys[mb_strtolower(preg_replace('/^po\s*/iu', '', $po))] = true;
        $refList[] = 'PO ' . preg_replace('/^po\s*/iu', '', $po);
    }
    foreach (stockparts_withdraw_uniq($orderRefs) as $ref) {
        $key = mb_strtolower(preg_replace('/^(order\s+|#)/iu', '', $ref));
        if (isset($poKeys[$key])) {
            continue;
        }
        $refList[] = preg_match('/^OPS-/i', $ref) ? 'Order ' . $ref : $ref;
    }
    $saleDate = '';
    foreach ($dates as $d) {
        $d = trim((string) $d);
        if ($d !== '') {
            $saleDate = (strlen($d) > 10 && substr($d, 11) !== '00:00:00') ? dthai_full($d) : dthai($d);
            break;
        }
    }
    $by = stockparts_withdraw_uniq($bys);
    $by = $by ? $by[0] : '';

    $note = trim((string) ($f['notes'] ?? ''));
    if ($note !== '' && $serial !== '' && mb_stripos($note, $serial) !== false) {
        $note = trim(preg_replace('/\s*[-·]?\s*Serial\s*:\s*' . preg_quote($serial, '/') . '/iu', '', $note), " -·");
    }
    foreach (array_merge($poList, $orderRefs) as $known) {
        $known = trim((string) $known);
        if ($known === '') {
            continue;
        }
        $note = preg_replace('/\(?\s*(?:PO\s*:?\s*)?' . preg_quote($known, '/') . '\s*\)?/iu', ' ', $note);
    }
    $note = trim(preg_replace('/\s+/u', ' ', (string) $note), " -·,;");
    $kindExtra = $note;
    if ($kindExtra === '' || $kindExtra === (string) ($f['kind'] ?? '')) {
        $k = (string) ($f['kind'] ?? '');
        $kindExtra = in_array($k, ['เคลม', 'ขายแยกรายการ', 'ลงทะเบียนอุปกรณ์'], true) ? $k : '';
    }
    foreach ($setupRows as $r) {
        if (((string) ($r['issue_type'] ?? '')) === 'claim') {
            $kindExtra = $kindExtra !== '' ? $kindExtra : 'เคลม';
            break;
        }
    }

    $siteHtml = '';
    $siteMuted = true;
    if ($hasStockOld) {
        $old = $info['stock_old'];
        $siteBits = [];
        $site = trim((string) ($old['setup_id'] ?? ''));
        $sec = trim((string) ($old['security_company'] ?? ''));
        if ($site !== '') {
            $siteBits[] = $site;
        }
        if ($sec !== '') {
            $siteBits[] = 'รปภ. ' . $sec;
        }
        if ($siteBits) {
            $siteHtml = h(implode(' · ', $siteBits));
            $siteMuted = false;
        }
    }
    if ($siteHtml === '' && function_exists('setup_sale_history_card_site_line')) {
        $siteFromSetup = setup_sale_history_card_site_line($setupRows);
        if ($siteFromSetup !== '') {
            $siteHtml = h($siteFromSetup);
            $siteMuted = false;
        }
    }
    if ($siteHtml === '' && $delivered) {
        $siteHtml = 'ไม่มีบันทึก';
    }

    $lines = (is_array($info['order_lines'] ?? null)) ? $info['order_lines'] : [];
    $setName = (string) ($f['set_name'] ?? '');
    $headBits = [];
    if ($setName !== '' && $setName !== '-') {
        $headBits[] = '<b>' . h($setName) . '</b>';
    }
    if ($lines) {
        $headBits[] = '<span class="muted">' . count($lines) . ' รายการในชุด</span>';
    }

    $out = '<div class="asset-sales-card">';
    $out .= '<div class="asset-sales-card-head">' . ui_icon_html('stock-out-set', 16) . '<b>การเบิกใช้งานขาย</b>';
    if ($headBits) {
        $out .= '<span class="asset-sales-head-sub">' . implode(' <span class="asset-sales-sep">·</span> ', $headBits) . '</span>';
    }
    if ($delivered) {
        $out .= '<span class="asset-sales-badge asset-sales-badge-stock-old asset-sales-head-flag">ส่งมอบแล้ว</span>';
    }
    $out .= '</div><div class="asset-sales-card-body">';

    $dateLine = $saleDate;
    if ($by !== '') {
        $dateLine = trim($dateLine . ($dateLine !== '' ? ' · ' : '') . 'ผู้บันทึก ' . $by);
    }
    $lineSerialMap = is_array($info['line_serial_map'] ?? null) ? $info['line_serial_map'] : [];
    $hideKindFact = $hasWithdraw && !empty($info['has_order_detail']) && $lines && $lineSerialMap !== [];
    $factsHtml = '';
    $factsHtml .= stockparts_withdraw_fact_row('ลูกค้า', $customer !== '' ? h($customer) : '');
    $refFactsHtml = function_exists('setup_sale_history_ref_facts_html')
        ? setup_sale_history_ref_facts_html($poList, $orderRefs)
        : ($refList ? h(implode(' · ', $refList)) : '');
    $factsHtml .= stockparts_withdraw_fact_row('อ้างอิง', $refFactsHtml);
    $factsHtml .= stockparts_withdraw_fact_row('ผู้ติดต่อ', $customerContact !== '' ? h($customerContact) : '');
    $factsHtml .= stockparts_withdraw_fact_row('วันที่ขาย', $dateLine !== '' ? h($dateLine) : '');
    if (!$hideKindFact) {
        $factsHtml .= stockparts_withdraw_fact_row('ลักษณะ', $kindExtra !== '' ? h($kindExtra) : '');
    }
    $factsHtml .= stockparts_withdraw_fact_row('ไซต์งาน', $siteHtml, $siteMuted);
    if ($factsHtml !== '') {
        $out .= '<dl class="asset-sales-facts">' . $factsHtml . '</dl>';
    }
    $out .= $extraHtml . $conflictHtml;

    $setTable = '';
    if ($hasWithdraw && !empty($info['has_order_detail']) && $lines) {
        $matched = is_array($info['matched_line'] ?? null) ? $info['matched_line'] : null;
        $setTable = stockparts_withdraw_order_table_html(
            $lines,
            (int) ($matched['id'] ?? 0),
            !empty($info['serial_delivered']),
            $lineSerialMap,
            $serial
        );
    }
    if ($setTable !== '') {
        $out .= $setTable;
    }
    if ($installHtml !== '') {
        $out .= $installHtml;
    }

    $srcLines = [];
    $saleSrc = stockparts_withdraw_uniq($srcSale);
    if ($saleSrc) {
        $srcLines[] = 'ระบบขาย · ' . h(implode(' และ', $saleSrc));
    } elseif ($setupErr !== '') {
        $srcLines[] = 'ระบบขาย · ' . h($setupErr);
    }
    $prov = $hasWithdraw || !empty($info['stock']) ? stockparts_withdraw_provenance_bits($info) : [];
    if ($prov) {
        $srcLines[] = 'ทะเบียน stock · ' . h(implode(' · ', $prov));
    }
    if ($srcLines) {
        $out .= '<div class="asset-sales-src">' . implode('<br>', $srcLines) . '</div>';
    }

    $links = [];
    if ($serial !== '') {
        $links[] = '<a href="' . h(BASE_URL . '/share.php?q=' . rawurlencode($serial)) . '">ดูในทะเบียน stock →</a>';
    }
    if ($links) {
        $out .= '<p class="asset-sales-foot muted">' . implode(' <span class="asset-sales-sep">·</span> ', $links) . '</p>';
    }
    $out .= $footHtml;

    return $out . '</div></div>';
}