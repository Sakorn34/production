<?php
/**
 * includes/smart_search.php — ค้นหาอัจฉริยะ sidebar
 *
 * ใช้โดย smart_search.php?ajax=1&q=...
 *
 * ครอบ 9 แหล่งข้ามทั้ง 4 ฐาน: เครื่อง · ลูกค้า/ไซต์งาน · รุ่นสินค้า · MA · อัปเดต FW/HW ·
 * อะไหล่ · ใบเบิก/รับเข้า · ทะเบียนสินค้า stock · งานซ่อม
 *
 * ฐานที่อยู่นอก production (stock/parts/maintenance) ต่อไม่ติดได้ — ทุกก้อนจึงห่อ try
 * ไว้ และคืนผลเท่าที่ได้ ไม่ใช่ทั้งช่องค้นหาพัง เพราะฐานเดียวล่ม
 */

// ─ helpers ─────────────────────────────────────────────────────────────────

/**
 * ตัดข้อความสำหรับแสดงในผลลัพธ์ค้นหา
 *
 * @param string $text
 * @param int    $max
 * @return string
 */
function smart_search_excerpt(string $text, int $max = 80): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, max(1, $max - 1)) . '…';
}

/**
 * เลือกข้อความที่ match คำค้นมากที่สุดจากรายการฟิลด์
 *
 * ผลลัพธ์ควรโชว์ "ตรงไหนที่ตรงกับที่พิมพ์" ไม่ใช่ฟิลด์แรกที่มีค่า ไม่งั้นผู้ใช้เห็น
 * รายการขึ้นมาแล้วเดาไม่ออกว่าทำไมมันขึ้น
 *
 * @param array<int,string> $fields
 * @param string            $q
 * @return string  '' ถ้าไม่มีฟิลด์ไหนตรง
 */
function smart_search_pick_match(array $fields, string $q): string
{
    foreach ($fields as $f) {
        $f = trim((string) $f);
        if ($f !== '' && mb_stripos($f, $q) !== false) {
            return smart_search_excerpt($f);
        }
    }
    return '';
}

/**
 * เลือกข้อความที่ match คำค้นมากที่สุดจากฟิลด์ MA
 *
 * @param array<string,mixed> $row
 * @param string              $q
 * @return string
 */
function smart_search_ma_snippet(array $row, string $q): string
{
    $hit = smart_search_pick_match([
        $row['remark'] ?? '',
        $row['ok_items'] ?? '',
        $row['replace_items'] ?? '',
        $row['repair_items'] ?? '',
        $row['fw_version'] ?? '',
    ], $q);
    if ($hit !== '') {
        return $hit;
    }
    return 'MA · ' . smart_search_excerpt((string) ($row['pname'] ?? ''));
}

/**
 * เลือกข้อความที่ match จากรายการอัปเดต
 *
 * @param array<string,mixed> $row
 * @param string              $q
 * @return string
 */
function smart_search_update_snippet(array $row, string $q): string
{
    $parts = array_filter([
        trim((string) ($row['detail'] ?? '')),
        trim((string) ($row['component_name'] ?? '')),
        trim((string) ($row['old_value'] ?? '')),
        trim((string) ($row['new_value'] ?? '')),
    ]);
    $hit = smart_search_pick_match($parts, $q);
    if ($hit !== '') {
        return $hit;
    }
    return smart_search_excerpt(implode(' · ', $parts));
}

/**
 * แปลงประเภทอัปเดตเป็นป้ายภาษาไทย
 *
 * @param string $type
 * @return string
 */
function smart_search_update_type_label(string $type): string
{
    static $map = [
        'firmware' => 'FW',
        'hardware' => 'HW',
        'other'    => 'อัปเดต',
    ];
    return $map[$type] ?? 'อัปเดต';
}

// ─ main query ────────────────────────────────────────────────────────────────

/**
 * ค้นทุกแหล่งที่มีข้อความตรงกับคำค้น รวมเป็นผลลัพธ์เดียว
 *
 * เรียงตามความน่าจะใช่: เครื่อง → ลูกค้า/ไซต์ → รุ่น → MA → อัปเดต → อะไหล่ →
 * ใบเบิก → ทะเบียน stock → งานซ่อม  (ผู้ใช้พิมพ์ S/N บ่อยที่สุด)
 *
 * @param string $q            คำค้น (อย่างน้อย 2 ตัวอักษร)
 * @param int    $limitPerKind จำกัดต่อประเภท
 * @return array<int,array<string,mixed>>
 */
function smart_search_query(string $q, int $limitPerKind = 5): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }

    $limitPerKind = max(1, min(8, $limitPerKind));
    $like = '%' . $q . '%';
    $prefix = $q . '%';
    $out = [];

    // ── เครื่อง ──
    // เดิมค้นแค่รหัสเครื่องกับ S/N โรงงาน ตอนนี้ครอบชื่อรุ่น · FW · หมายเหตุ · ล็อต
    // และชื่อลูกค้า/ไซต์งานที่เครื่องนั้นไปติดตั้งอยู่
    $assetRes = qr(
        'SELECT a.id, a.asset_code, a.factory_serial, a.status, p.name pname,
                COALESCE(
                  (SELECT CONCAT_WS(" · ", c.name, NULLIF(d.site_label, ""))
                     FROM deployments d
                     LEFT JOIN customers c ON c.id = d.customer_id
                    WHERE d.asset_id = a.id
                    ORDER BY (d.end_date IS NULL) DESC, d.start_date DESC, d.id DESC
                    LIMIT 1),
                  (SELECT c3.name FROM repairs r3
                     JOIN customers c3 ON c3.id = r3.customer_id
                    WHERE r3.asset_id = a.id
                    ORDER BY r3.opened_at DESC, r3.id DESC
                    LIMIT 1)
                ) AS site_where
         FROM assets a
         JOIN products p ON p.id = a.product_id
         WHERE a.asset_code LIKE ? OR a.factory_serial LIKE ?
            OR p.name LIKE ?
            OR IFNULL(a.current_fw_version, "") LIKE ?
            OR IFNULL(a.note, "") LIKE ?
            OR IFNULL(a.lot_label, "") LIKE ?
         ORDER BY (a.asset_code LIKE ?) DESC, (a.factory_serial LIKE ?) DESC, a.asset_code ASC
         LIMIT ' . (int) $limitPerKind,
        'ssssssss',
        [$like, $like, $like, $like, $like, $like, $prefix, $prefix]
    );
    while ($r = $assetRes->fetch_assoc()) {
        $out[] = [
            'kind'       => 'asset',
            'kind_label' => 'เครื่อง',
            'id'         => (int) $r['id'],
            'asset_id'   => (int) $r['id'],
            'code'       => (string) $r['asset_code'],
            'title'      => (string) $r['asset_code'],
            'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                (string) $r['pname'],
                status_th((string) $r['status']),
                (string) ($r['site_where'] ?? ''),
            ]))),
            'href'       => 'asset.php?id=' . (int) $r['id'],
        ];
    }

    // เครื่องที่เจอเพราะ "ชื่อลูกค้า/ไซต์งาน" ตรง — เดินจากตาราง customers ซึ่งเล็ก
    // (หลักสิบแถว) แทนที่จะให้ assets ทั้งหมื่นแถววิ่ง EXISTS ทีละแถว
    $seen = [];
    foreach ($out as $o) { if ($o['kind'] === 'asset') { $seen[(int) $o['id']] = true; } }
    $viaCust = qr(
        'SELECT DISTINCT a.id, a.asset_code, a.status, p.name pname, c.name cname, d.site_label
         FROM customers c
         LEFT JOIN deployments d ON d.customer_id = c.id
         LEFT JOIN repairs r ON r.customer_id = c.id
         JOIN assets a ON a.id = d.asset_id OR a.id = r.asset_id
         JOIN products p ON p.id = a.product_id
         WHERE c.name LIKE ? OR IFNULL(c.site_label, "") LIKE ? OR IFNULL(d.site_label, "") LIKE ?
         ORDER BY a.asset_code ASC
         LIMIT ' . (int) ($limitPerKind * 2),
        'sss',
        [$like, $like, $like]
    );
    while ($r = $viaCust->fetch_assoc()) {
        $id = (int) $r['id'];
        if (isset($seen[$id]) || count($seen) >= $limitPerKind * 2) { continue; }
        $seen[$id] = true;
        $out[] = [
            'kind'       => 'asset',
            'kind_label' => 'เครื่อง',
            'id'         => $id,
            'asset_id'   => $id,
            'code'       => (string) $r['asset_code'],
            'title'      => (string) $r['asset_code'],
            'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                (string) $r['pname'],
                status_th((string) $r['status']),
                (string) ($r['cname'] ?? ''),
                (string) ($r['site_label'] ?? ''),
            ]))),
            'href'       => 'asset.php?id=' . $id,
        ];
    }

    // ── ลูกค้า / ไซต์งาน ──
    // พิมพ์ชื่อลูกค้าแล้วต้องไปถึงรายการเครื่องของลูกค้ารายนั้นได้ในคลิกเดียว
    $custRes = qr(
        'SELECT c.id, c.name, c.site_label, c.contact_name, c.phone, c.security_company,
                (SELECT COUNT(DISTINCT a.id) FROM assets a
                  WHERE a.id IN (SELECT d.asset_id FROM deployments d
                                  WHERE d.customer_id = c.id AND d.end_date IS NULL)
                     OR a.id IN (SELECT r.asset_id FROM repairs r
                                  WHERE r.customer_id = c.id)) AS live_n
         FROM customers c
         WHERE c.name LIKE ? OR IFNULL(c.site_label, "") LIKE ?
            OR IFNULL(c.contact_name, "") LIKE ? OR IFNULL(c.phone, "") LIKE ?
            OR IFNULL(c.security_company, "") LIKE ?
         ORDER BY (c.name LIKE ?) DESC, c.name ASC
         LIMIT ' . (int) $limitPerKind,
        'ssssss',
        [$like, $like, $like, $like, $like, $prefix]
    );
    while ($r = $custRes->fetch_assoc()) {
        $n = (int) ($r['live_n'] ?? 0);
        $out[] = [
            'kind'       => 'customer',
            'kind_label' => 'ลูกค้า',
            'id'         => (int) $r['id'],
            'asset_id'   => 0,
            'code'       => (string) $r['name'],
            'title'      => (string) $r['name'],
            'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                (string) ($r['site_label'] ?? ''),
                (string) ($r['contact_name'] ?? ''),
                $n > 0 ? 'ติดตั้งอยู่ ' . number_format($n) . ' เครื่อง' : '',
            ]))),
            'href'       => 'repairs.php?customer=' . (int) $r['id'],
        ];
    }

    // ── คนทำงาน ──
    // ชื่อช่างโผล่กระจายอยู่หลายตาราง (ผู้บันทึกเครื่อง · ผู้ทำ MA · ผู้เบิก) ค้นชื่อจึงได้
    // ผลปนกันไปหมด · ทะเบียนคนทำงานเป็นจุดเดียวที่ตอบว่า "คนนี้ทำอะไรไปบ้างทั้งรอบ"
    try {
        $peopleRes = qr(
            'SELECT DISTINCT w.id, w.display_name, w.is_active
             FROM work_people w
             LEFT JOIN work_person_aliases al ON al.person_id = w.id
             WHERE w.display_name LIKE ? OR al.alias LIKE ?
             ORDER BY (w.display_name LIKE ?) DESC, w.display_name ASC
             LIMIT ' . (int) $limitPerKind,
            'sss',
            [$like, $like, $prefix]
        );
        while ($r = $peopleRes->fetch_assoc()) {
            $out[] = [
                'kind'       => 'person',
                'kind_label' => 'คน',
                'id'         => (int) $r['id'],
                'asset_id'   => 0,
                'code'       => (string) $r['display_name'],
                'title'      => (string) $r['display_name'],
                'subtitle'   => 'สรุปงานรายคน' . (((int) $r['is_active']) === 0 ? ' · ไม่ใช้งานแล้ว' : ''),
                'href'       => 'work_report.php?p=' . (int) $r['id'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[smart_search] work_people: ' . $e->getMessage());
    }

    // ── รุ่นสินค้า ──
    $prodRes = qr(
        'SELECT p.id, p.name, p.product_code, p.category,
                (SELECT COUNT(*) FROM assets a WHERE a.product_id = p.id) AS n
         FROM products p
         WHERE p.name LIKE ? OR IFNULL(p.product_code, "") LIKE ? OR IFNULL(p.category, "") LIKE ?
         ORDER BY (p.name LIKE ?) DESC, p.name ASC
         LIMIT ' . (int) $limitPerKind,
        'ssss',
        [$like, $like, $like, $prefix]
    );
    while ($r = $prodRes->fetch_assoc()) {
        $out[] = [
            'kind'       => 'product',
            'kind_label' => 'รุ่น',
            'id'         => (int) $r['id'],
            'asset_id'   => 0,
            'code'       => (string) $r['name'],
            'title'      => (string) $r['name'],
            'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                (string) ($r['product_code'] ?? ''),
                (string) ($r['category'] ?? ''),
                number_format((int) $r['n']) . ' เครื่อง',
            ]))),
            'href'       => 'assets.php?product=' . rawurlencode((string) $r['name']),
        ];
    }

    // ── MA ──
    $maRes = qr(
        'SELECT m.id, m.asset_id, m.visited_at, m.ma_round, m.remark, m.fw_version,
                m.ok_items, m.replace_items, m.repair_items, a.asset_code, p.name pname
         FROM ma_records m
         JOIN assets a ON a.id = m.asset_id
         JOIN products p ON p.id = a.product_id
         WHERE a.asset_code LIKE ? OR a.factory_serial LIKE ?
            OR m.remark LIKE ? OR m.ok_items LIKE ? OR m.replace_items LIKE ? OR m.repair_items LIKE ?
            OR IFNULL(m.fw_version, \'\') LIKE ? OR IFNULL(m.done_by, \'\') LIKE ?
         ORDER BY m.visited_at DESC, m.id DESC
         LIMIT ' . (int) $limitPerKind,
        'ssssssss',
        [$like, $like, $like, $like, $like, $like, $like, $like]
    );
    while ($r = $maRes->fetch_assoc()) {
        $round = isset($r['ma_round']) && $r['ma_round'] !== null ? (int) $r['ma_round'] : 0;
        $out[] = [
            'kind'       => 'ma',
            'kind_label' => 'MA',
            'id'         => (int) $r['id'],
            'asset_id'   => (int) $r['asset_id'],
            'code'       => (string) $r['asset_code'],
            'title'      => (string) $r['asset_code'] . ($round > 0 ? ' · รอบ ' . $round : ''),
            'subtitle'   => smart_search_ma_snippet($r, $q) . ' · ' . dthai((string) $r['visited_at']),
            'href'       => 'asset.php?id=' . (int) $r['asset_id'],
        ];
    }

    // ── อัปเดต FW/HW ──
    $updRes = qr(
        'SELECT u.id, u.asset_id, u.updated_at, u.update_type, u.detail,
                u.component_name, u.old_value, u.new_value, a.asset_code, p.name pname
         FROM update_logs u
         JOIN assets a ON a.id = u.asset_id
         JOIN products p ON p.id = a.product_id
         WHERE a.asset_code LIKE ? OR a.factory_serial LIKE ?
            OR IFNULL(u.detail, \'\') LIKE ?
            OR IFNULL(u.component_name, \'\') LIKE ?
            OR IFNULL(u.old_value, \'\') LIKE ?
            OR IFNULL(u.new_value, \'\') LIKE ?
            OR IFNULL(u.made_by, \'\') LIKE ?
         ORDER BY u.updated_at DESC, u.id DESC
         LIMIT ' . (int) $limitPerKind,
        'sssssss',
        [$like, $like, $like, $like, $like, $like, $like]
    );
    while ($r = $updRes->fetch_assoc()) {
        $typeLabel = smart_search_update_type_label((string) ($r['update_type'] ?? 'other'));
        $out[] = [
            'kind'       => 'update',
            'kind_label' => $typeLabel,
            'id'         => (int) $r['id'],
            'asset_id'   => (int) $r['asset_id'],
            'code'       => (string) $r['asset_code'],
            'title'      => (string) $r['asset_code'] . ' · ' . $typeLabel,
            'subtitle'   => smart_search_update_snippet($r, $q) . ' · ' . dthai((string) $r['updated_at']),
            'href'       => 'asset.php?id=' . (int) $r['asset_id'],
        ];
    }

    // ── อะไหล่ (ทะเบียนในระบบหลัก) ──
    try {
        $partRes = qr(
            'SELECT id, part_code, stock_code, name, category, unit, stock_qty
             FROM parts
             WHERE name LIKE ? OR IFNULL(part_code, "") LIKE ? OR IFNULL(stock_code, "") LIKE ?
                OR IFNULL(category, "") LIKE ? OR IFNULL(dealer, "") LIKE ?
             ORDER BY (name LIKE ?) DESC, name ASC
             LIMIT ' . (int) $limitPerKind,
            'ssssss',
            [$like, $like, $like, $like, $like, $prefix]
        );
        while ($r = $partRes->fetch_assoc()) {
            $out[] = [
                'kind'       => 'part',
                'kind_label' => 'อะไหล่',
                'id'         => (int) $r['id'],
                'asset_id'   => 0,
                'code'       => (string) ($r['part_code'] ?? ''),
                'title'      => (string) $r['name'],
                'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                    (string) ($r['part_code'] ?? ''),
                    (string) ($r['category'] ?? ''),
                    'คงเหลือ ' . number_format((int) ($r['stock_qty'] ?? 0)) . ' ' . (string) ($r['unit'] ?? ''),
                ]))),
                'href'       => 'parts.php?q=' . rawurlencode($q),
            ];
        }
    } catch (Throwable $e) {
        error_log('[smart_search] parts: ' . $e->getMessage());
    }

    // ── ใบเบิก / รับเข้า (ฐานสต็อกอะไหล่) ──
    $partsDb = function_exists('dbParts') ? dbParts() : null;
    if ($partsDb) {
        try {
            $st = $partsDb->prepare(
                'SELECT o.id, o.doc_no, o.note, o.issued_by, o.asset_code, o.created_at
                 FROM stock_out o
                 WHERE o.doc_no LIKE ? OR IFNULL(o.note, "") LIKE ?
                    OR IFNULL(o.issued_by, "") LIKE ? OR IFNULL(o.asset_code, "") LIKE ?
                 ORDER BY o.created_at DESC, o.id DESC
                 LIMIT ' . (int) $limitPerKind
            );
            $st->execute([$like, $like, $like, $like]);
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    'kind'       => 'stockout',
                    'kind_label' => 'ใบเบิก',
                    'id'         => (int) $r['id'],
                    'asset_id'   => 0,
                    'code'       => (string) $r['doc_no'],
                    'title'      => (string) $r['doc_no'],
                    'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                        (string) ($r['asset_code'] ?? ''),
                        (string) ($r['note'] ?? ''),
                        (string) ($r['issued_by'] ?? ''),
                    ]))),
                    'href'       => '../parts/pages/history.php?q=' . rawurlencode($q),
                ];
            }
        } catch (Throwable $e) {
            error_log('[smart_search] stock_out: ' . $e->getMessage());
        }
    }

    // ── ทะเบียนสินค้า (stock) ──
    $stockDb = function_exists('dbStock') ? dbStock() : null;
    if ($stockDb) {
        try {
            $sql = 'SELECT id, serial_number, model, create_name, setup_id, timestamp
                    FROM stock
                    WHERE serial_number LIKE ? OR IFNULL(model, "") LIKE ?
                       OR IFNULL(create_name, "") LIKE ? OR IFNULL(setup_id, "") LIKE ?
                    ORDER BY timestamp DESC
                    LIMIT ' . (int) $limitPerKind;
            $stmt = $stockDb->prepare($sql);
            $stmt->bind_param('ssss', $like, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $out[] = [
                    'kind'       => 'stock',
                    'kind_label' => 'ทะเบียน stock',
                    'id'         => (int) $r['id'],
                    'asset_id'   => 0,
                    'code'       => (string) $r['serial_number'],
                    'title'      => (string) $r['serial_number'],
                    'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                        (string) ($r['model'] ?? ''),
                        (string) ($r['create_name'] ?? ''),
                    ]))),
                    'href'       => 'share.php?q=' . rawurlencode((string) $r['serial_number']),
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[smart_search] stock: ' . $e->getMessage());
        }
    }

    // ── งานซ่อม (ฐานระบบซ่อม — อ่านอย่างเดียว) ──
    $maintDb = function_exists('dbMaintenance') ? dbMaintenance() : null;
    if ($maintDb) {
        try {
            $sql = 'SELECT trp_id, trp_sn, trp_product, trp_repair_inform, trp_repair_remarks,
                           trp_status_job, trp_receive_date
                    FROM transac_repair
                    WHERE trp_sn LIKE ? OR IFNULL(trp_product, "") LIKE ?
                       OR IFNULL(trp_repair_inform, "") LIKE ? OR IFNULL(trp_repair_remarks, "") LIKE ?
                    ORDER BY trp_receive_date DESC, trp_id DESC
                    LIMIT ' . (int) $limitPerKind;
            $stmt = $maintDb->prepare($sql);
            $stmt->bind_param('ssss', $like, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $hit = smart_search_pick_match([
                    $r['trp_repair_inform'] ?? '',
                    $r['trp_repair_remarks'] ?? '',
                    $r['trp_product'] ?? '',
                ], $q);
                $out[] = [
                    'kind'       => 'repair',
                    'kind_label' => 'ซ่อม',
                    'id'         => (int) $r['trp_id'],
                    'asset_id'   => 0,
                    'code'       => (string) $r['trp_sn'],
                    'title'      => (string) $r['trp_sn'],
                    'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                        $hit !== '' ? $hit : (string) ($r['trp_product'] ?? ''),
                        (string) ($r['trp_status_job'] ?? ''),
                    ]))),
                    'href'       => 'repairs.php?q=' . rawurlencode((string) $r['trp_sn']),
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[smart_search] transac_repair: ' . $e->getMessage());
        }
    }

    // ── ประวัติขาย / เคลม (ฐาน biton_setup ของระบบ setupsystem — อ่านอย่างเดียว) ──
    // แหล่งเดียวที่ตอบได้ว่า S/N ตัวนี้ขายให้ใคร ใบ PO ไหน หรือเคยเคลมเปลี่ยนตัวมาจากเครื่องใด
    // ผูก href กลับไปที่หน้าเดิมของระบบนั้น เพราะรายละเอียดเต็มอยู่ที่นั่น ไม่ได้ย้ายมา
    $setupDb = function_exists('dbSetup') ? dbSetup() : null;
    if ($setupDb) {
        try {
            $sql = 'SELECT id, issue_type, claim_number, product_name, product_code,
                           old_serial_number, new_serial_number, customer_name, site_name,
                           po_number, lease_number, claim_date
                    FROM equipment_claim_history
                    WHERE claim_number LIKE ? OR IFNULL(product_name, "") LIKE ?
                       OR IFNULL(old_serial_number, "") LIKE ? OR IFNULL(new_serial_number, "") LIKE ?
                       OR IFNULL(customer_name, "") LIKE ? OR IFNULL(site_name, "") LIKE ?
                       OR IFNULL(po_number, "") LIKE ? OR IFNULL(lease_number, "") LIKE ?
                    ORDER BY claim_date DESC, id DESC
                    LIMIT ' . (int) $limitPerKind;
            $stmt = $setupDb->prepare($sql);
            $stmt->bind_param('ssssssss', $like, $like, $like, $like, $like, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $isClaim = ((string) ($r['issue_type'] ?? '')) === 'claim';
                $sn = trim((string) ($r['new_serial_number'] ?? ''));
                if ($sn === '') { $sn = trim((string) ($r['old_serial_number'] ?? '')); }
                $out[] = [
                    'kind'       => $isClaim ? 'claim' : 'sale',
                    'kind_label' => $isClaim ? 'เคลม' : 'ขาย',
                    'id'         => (int) $r['id'],
                    'asset_id'   => 0,
                    'code'       => $sn,
                    'title'      => $sn !== '' ? $sn : (string) ($r['claim_number'] ?? ''),
                    'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                        (string) ($r['product_name'] ?? ''),
                        (string) ($r['customer_name'] ?? ''),
                        (string) ($r['site_name'] ?? ''),
                        $isClaim && trim((string) ($r['old_serial_number'] ?? '')) !== ''
                            ? 'เปลี่ยนจาก ' . (string) $r['old_serial_number'] : '',
                        (string) ($r['po_number'] ?? ''),
                        (string) ($r['claim_date'] ?? ''),
                    ])), 96),
                    'href'       => 'https://bit-online.net/setupsystem/claim_history.php?'
                        . ($sn !== '' ? 'serial=' . rawurlencode($sn)
                                     : 'claim_no=' . rawurlencode((string) ($r['claim_number'] ?? ''))),
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[smart_search] equipment_claim_history: ' . $e->getMessage());
        }
    }

    // ── ใบส่งมอบ Order (ฐาน biton_setup) ──
    // "ชื่อหน่วยงาน" อยู่ที่นี่ ไม่ได้อยู่ใน equipment_claim_history: setup_orders มี
    // company_name ครบ 509 แถว department 493 แถว ส่วนตารางเคลมมี site_name แค่ 12
    // จาก 170 · และ 449 จาก 619 S/N มีเฉพาะที่นี่ ค้นแหล่งเดียวจึงหาไม่เจอเป็นส่วนใหญ่
    if ($setupDb) {
        try {
            $sql = 'SELECT ps.id, ps.issue_ref, ps.issue_type, ps.issue_date, ps.serial_number,
                           ps.po_number, so.customer_name, so.company_name, so.department,
                           so.product, so.status
                    FROM po_order_part_serials ps
                    LEFT JOIN setup_orders so ON so.id = ps.order_id
                    WHERE ps.serial_number LIKE ? OR IFNULL(ps.old_serial_number, "") LIKE ?
                       OR IFNULL(ps.po_number, "") LIKE ? OR IFNULL(ps.issue_ref, "") LIKE ?
                       OR IFNULL(ps.company_name, "") LIKE ?
                       OR IFNULL(so.company_name, "") LIKE ? OR IFNULL(so.department, "") LIKE ?
                       OR IFNULL(so.customer_name, "") LIKE ? OR IFNULL(so.contact_person, "") LIKE ?
                    ORDER BY ps.issue_date DESC, ps.id DESC
                    LIMIT ' . (int) $limitPerKind;
            $stmt = $setupDb->prepare($sql);
            $stmt->bind_param('sssssssss', $like, $like, $like, $like, $like, $like, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $sn = trim((string) ($r['serial_number'] ?? ''));
                $out[] = [
                    'kind'       => 'order',
                    'kind_label' => 'ส่งมอบ',
                    'id'         => (int) $r['id'],
                    'asset_id'   => 0,
                    'code'       => $sn,
                    'title'      => $sn !== '' ? $sn : (string) ($r['issue_ref'] ?? ''),
                    'subtitle'   => smart_search_excerpt(implode(' · ', array_filter([
                        (string) ($r['company_name'] ?? ''),
                        (string) ($r['department'] ?? ''),
                        (string) ($r['product'] ?? ''),
                        (string) ($r['po_number'] ?? ''),
                        (string) ($r['issue_date'] ?? ''),
                    ])), 96),
                    'href'       => 'https://bit-online.net/setupsystem/claim_history.php?'
                        . ($sn !== '' ? 'serial=' . rawurlencode($sn)
                                     : 'claim_no=' . rawurlencode((string) ($r['issue_ref'] ?? ''))),
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[smart_search] po_order_part_serials: ' . $e->getMessage());
        }
    }

    return $out;
}
