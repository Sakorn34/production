<?php
/**
 * includes/smart_search.php — ค้นหาอัจฉริยะ sidebar
 *
 * ใช้โดย smart_search.php?ajax=1&q=...
 *
 * ครอบ 14 แหล่งข้ามทั้ง 5 ฐาน
 *   production   เครื่อง · ลูกค้า/ไซต์งาน · คนทำงาน · รุ่นสินค้า · MA · อัปเดต FW/HW · อะไหล่
 *   tech_parts   ใบเบิก/รับเข้า
 *   stockparts   ทะเบียนสินค้า stock
 *   maintenance  งานซ่อม
 *   setup        ประวัติขาย/เคลม · ใบส่งมอบ Order
 *   leasing      เครื่องเช่า · สัญญาเช่า · MA เครื่องเช่า
 *
 * ฐานที่อยู่นอก production (stock/parts/maintenance/setup/leasing) ต่อไม่ติดได้ — ทุกก้อน
 * จึงห่อ try ไว้ และคืนผลเท่าที่ได้ ไม่ใช่ทั้งช่องค้นหาพัง เพราะฐานเดียวล่ม
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

/**
 * ชื่อลูกค้าในระบบเช่าจาก cus_id
 *
 * เรียกครั้งเดียวหลังได้ผลครบทุกก้อน — cus_id เป็น PK จึงถูกกว่าการ join ตาราง
 * 2,700 แถวเข้าไปในทุก query แล้วให้ LIKE วิ่งบนคอลัมน์ที่ join มา
 *
 * @param int[] $ids
 * @return array<int,string>  cus_id => ชื่อ
 */
function smart_search_rent_customer_names(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !function_exists('dbLeasing') || !dbLeasing()) {
        return [];
    }
    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = rent_q_try(
            "SELECT cus_id, cus_name FROM tbl_customer WHERE cus_id IN ($ph)",
            str_repeat('i', count($ids)),
            $ids
        );
        if ($q['ok'] && !empty($q['result'])) {
            while ($r = $q['result']->fetch_assoc()) {
                $out[(int) $r['cus_id']] = trim((string) ($r['cus_name'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        error_log('[smart_search] rent customer names: ' . $e->getMessage());
    }
    return $out;
}

/**
 * จับ S/N จากระบบอื่นกลับมาที่ทะเบียนเครื่องของเรา
 *
 * ทำทีเดียวทั้งชุดหลังเก็บผลครบ ไม่ยิงต่อแถว — S/N หนึ่งตัวอาจตรงกับ asset_code
 * หรือ factory_serial ก็ได้ จึงต้องเทียบทั้งสองคอลัมน์
 *
 * @param string[] $serials  S/N ที่ normalize แล้ว (ตัวพิมพ์ใหญ่)
 * @return array<string,int>  S/N => asset id
 */
function smart_search_asset_ids_by_serial(array $serials): array
{
    $serials = array_values(array_unique(array_filter(array_map('trim', $serials))));
    if (!$serials) {
        return [];
    }
    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($serials), '?'));
        $res = qr(
            "SELECT id, asset_code, factory_serial FROM assets
             WHERE asset_code IN ($ph) OR factory_serial IN ($ph)",
            str_repeat('s', count($serials) * 2),
            array_merge($serials, $serials)
        );
        while ($r = $res->fetch_assoc()) {
            foreach ([$r['asset_code'], $r['factory_serial']] as $sn) {
                $sn = strtoupper(trim((string) $sn));
                if ($sn !== '' && !isset($out[$sn])) {
                    $out[$sn] = (int) $r['id'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[smart_search] asset by serial: ' . $e->getMessage());
    }
    return $out;
}

/**
 * ปลายทางของผลลัพธ์ระบบเช่า
 *
 * เครื่องที่อยู่ในทะเบียนของเราด้วย ให้ไปหน้าเครื่อง — หน้านั้นมีการ์ดสถานะเช่า
 * ประวัติ MA และประวัติขายรวมอยู่แล้ว ดีกว่าเด้งออกไปอีกเว็บโดยไม่จำเป็น
 * ที่เหลือค่อยออกไปหน้าประวัติการใช้งานของระบบเช่า
 *
 * @param string             $sn
 * @param array<string,int>  $assetMap
 * @return string
 */
function smart_search_rent_href(string $sn, array $assetMap): string
{
    $sn = strtoupper(trim($sn));
    if ($sn !== '' && isset($assetMap[$sn])) {
        return 'asset.php?id=' . $assetMap[$sn];
    }
    if ($sn !== '') {
        return 'https://bit-online.net/rent/product_history_usage.php?serial_number='
            . rawurlencode($sn);
    }
    return 'https://bit-online.net/rent/detail_product.php';
}

// ─ main query ────────────────────────────────────────────────────────────────

/**
 * ค้นทุกแหล่งที่มีข้อความตรงกับคำค้น รวมเป็นผลลัพธ์เดียว
 *
 * เรียงตามความน่าจะใช่: เครื่อง → ลูกค้า/ไซต์ → คน → รุ่น → MA → อัปเดต → อะไหล่ →
 * ใบเบิก → ทะเบียน stock → งานซ่อม → ขาย/เคลม → ส่งมอบ → เช่า  (ผู้ใช้พิมพ์ S/N บ่อยที่สุด)
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

    // ── ระบบเช่า (ฐาน biton_leasing — อ่านอย่างเดียว) ──
    // ทะเบียนผลิตกับระบบเช่าตอบคนละคำถาม: ของเราบอกว่าเครื่องผลิต/อัปเดตอะไรมาบ้าง
    // ระบบเช่าบอกว่าตอนนี้เครื่องอยู่ไซต์ไหน สัญญาใบไหน หมดอายุเมื่อไหร่ · และมี S/N
    // อีกจำนวนมากที่อยู่เฉพาะในระบบเช่า ไม่เคยเข้าทะเบียนผลิต
    $leaseDb = function_exists('dbLeasing') ? dbLeasing() : null;
    if ($leaseDb) {
        require_once __DIR__ . '/rent_ma_bridge.php';
        $leaseRows = [];   // สะสมไว้ก่อน แล้วค่อยจับคู่ S/N กับทะเบียนเครื่องทีเดียวตอนท้าย

        // ลูกค้าในระบบเช่าที่ชื่อตรง — เอา id ไปกรองต่อ เพราะ p_cus_id/r_cus_id มี index
        // ส่วน LIKE บน cus_name ที่ join เข้ามาใช้ index ไม่ได้ (วัดแล้วช้ากว่า 2-3 เท่า)
        $rentCusIds = [];
        $rentCusName = [];
        try {
            $cq = rent_q_try(
                'SELECT cus_id, cus_name FROM tbl_customer
                 WHERE cus_name LIKE ? OR IFNULL(cus_sname, "") LIKE ? OR IFNULL(ecus_name, "") LIKE ?
                 LIMIT 200',
                'sss',
                [$like, $like, $like]
            );
            if ($cq['ok'] && !empty($cq['result'])) {
                while ($r = $cq['result']->fetch_assoc()) {
                    $cid = (int) $r['cus_id'];
                    $rentCusIds[] = $cid;
                    $rentCusName[$cid] = trim((string) ($r['cus_name'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            error_log('[smart_search] tbl_customer: ' . $e->getMessage());
        }
        $cusIn = $rentCusIds ? implode(',', array_fill(0, count($rentCusIds), '?')) : '';

        // ── เครื่องเช่า: ทะเบียนสินค้าเช่า ──
        // ไม่เอา pro_status เข้าเงื่อนไขค้น เพราะค่าเป็นอังกฤษคำสั้น ๆ (rent 3,713 แถว
        // · MA 777) พิมพ์ "MA" ทีเดียวจะได้เครื่องเช่าโผล่มาแทนงาน MA ที่ตั้งใจหา
        $rentSns = [];
        try {
            $pq = rent_q_try(
                'SELECT pro_id, pro_sn, pro_name, pro_status, pro_date, pro_remarks, pro_bundle
                 FROM tbl_product
                 WHERE pro_sn LIKE ? OR IFNULL(pro_name, "") LIKE ?
                    OR IFNULL(pro_remarks, "") LIKE ? OR IFNULL(pro_bundle, "") LIKE ?
                 ORDER BY (pro_sn LIKE ?) DESC, pro_date DESC, pro_id DESC
                 LIMIT ' . (int) $limitPerKind,
                'sssss',
                [$like, $like, $like, $like, $prefix]
            );
            if ($pq['ok'] && !empty($pq['result'])) {
                while ($r = $pq['result']->fetch_assoc()) {
                    $sn = rent_normalize_sn($r['pro_sn'] ?? '');
                    if ($sn === '') { continue; }
                    $rentSns[$sn] = [
                        'product' => trim((string) ($r['pro_name'] ?? '')),
                        'status'  => trim((string) ($r['pro_status'] ?? '')),
                        'remark'  => trim((string) ($r['pro_remarks'] ?? '')),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[smart_search] tbl_product: ' . $e->getMessage());
        }

        // บรรทัดสัญญาของเครื่อง — ยิงรอบเดียวทำสองหน้าที่: เติมไซต์/ลูกค้าให้ S/N ที่เจอ
        // ข้างบน และหาเครื่องเพิ่มจากชื่อไซต์/ชื่อลูกค้า · ตารางนี้ไม่มี index บน p_sn
        // แยกเป็นสอง query จึงเท่ากับสแกน 10,000 แถวสองรอบโดยไม่จำเป็น
        $rentLine = [];
        try {
            $snList = array_keys($rentSns);
            $snIn = $snList ? implode(',', array_fill(0, count($snList), '?')) : '';
            $sql = 'SELECT rp.p_id, rp.p_sn, rp.p_cus_id, rp.p_status, rp.p_siteid, rp.p_sitename,
                           rp.p_product, r.r_po, r.r_code, r.r_startdate, r.r_enddate
                    FROM tbl_rent_product rp
                    LEFT JOIN tbl_rent r ON r.r_id = rp.p_r_id
                    WHERE IFNULL(rp.p_sitename, "") LIKE ? OR IFNULL(rp.p_siteid, "") LIKE ?
                       OR IFNULL(r.r_po, "") LIKE ? OR IFNULL(r.r_code, "") LIKE ?'
                 . ($snIn !== '' ? " OR rp.p_sn IN ($snIn)" : '')
                 . ($cusIn !== '' ? " OR rp.p_cus_id IN ($cusIn)" : '')
                 . ' ORDER BY CASE WHEN rp.p_status = "active" THEN 0 ELSE 1 END, rp.p_id DESC
                    LIMIT 60';
            $lq = rent_q_try(
                $sql,
                'ssss' . str_repeat('s', count($snList)) . str_repeat('i', count($rentCusIds)),
                array_merge([$like, $like, $like, $like], $snList, $rentCusIds)
            );
            if ($lq['ok'] && !empty($lq['result'])) {
                while ($r = $lq['result']->fetch_assoc()) {
                    // แถวแรกของแต่ละ S/N คือบรรทัดที่ใช้ (active ก่อน แล้วใหม่สุด) ตาม ORDER BY
                    $sn = rent_normalize_sn($r['p_sn'] ?? '');
                    if ($sn === '' || isset($rentLine[$sn])) { continue; }
                    $rentLine[$sn] = $r;
                }
            }
        } catch (Throwable $e) {
            error_log('[smart_search] tbl_rent_product: ' . $e->getMessage());
        }

        // เครื่องที่เจอจากชื่อไซต์/ลูกค้าแต่ยังไม่มีในชุดข้างบน — เติมเข้าไปให้ครบ
        $rentCap = $limitPerKind * 2;
        foreach ($rentLine as $sn => $r) {
            if (count($rentSns) >= $rentCap) { break; }
            if (isset($rentSns[$sn])) { continue; }
            $rentSns[$sn] = [
                'product' => trim((string) ($r['p_product'] ?? '')),
                'status'  => '',
                'remark'  => '',
            ];
        }

        foreach ($rentSns as $sn => $info) {
            $line = isset($rentLine[$sn]) ? $rentLine[$sn] : null;
            $cid = $line ? (int) ($line['p_cus_id'] ?? 0) : 0;
            $leaseRows[] = [
                'kind'       => 'rentasset',
                'kind_label' => 'เครื่องเช่า',
                'id'         => 0,
                'code'       => $sn,
                'title'      => $sn,
                'sn'         => $sn,
                'cus_id'     => $cid,
                'parts'      => [
                    $info['product'] !== '' ? $info['product'] : (string) ($line['p_product'] ?? ''),
                    rent_leasing_status_label($info['status'], (string) ($line['p_status'] ?? '')),
                    '@cus' . $cid,
                    (string) ($line['p_sitename'] ?? ''),
                    (string) ($line['r_po'] ?? ''),
                    $info['remark'],
                ],
            ];
        }

        // ── สัญญาเช่า ──
        try {
            $sql = 'SELECT r.r_id, r.r_code, r.r_po, r.r_product, r.r_sitename, r.r_siteid,
                           r.r_startdate, r.r_enddate, r.r_status_rent, r.r_cus_id
                    FROM tbl_rent r
                    WHERE IFNULL(r.r_code, "") LIKE ? OR IFNULL(r.r_po, "") LIKE ?
                       OR IFNULL(r.r_po_renew, "") LIKE ? OR IFNULL(r.r_sitename, "") LIKE ?
                       OR IFNULL(r.r_siteid, "") LIKE ? OR IFNULL(r.r_addrjob, "") LIKE ?
                       OR IFNULL(r.r_contract1, "") LIKE ? OR IFNULL(r.r_contract2, "") LIKE ?
                       OR IFNULL(r.r_contract3, "") LIKE ? OR IFNULL(r.r_tel1, "") LIKE ?
                       OR IFNULL(r.r_tel2, "") LIKE ? OR IFNULL(r.r_tel3, "") LIKE ?
                       OR IFNULL(r.r_remarks, "") LIKE ? OR IFNULL(r.r_product, "") LIKE ?'
                 . ($cusIn !== '' ? " OR r.r_cus_id IN ($cusIn)" : '')
                 . ' ORDER BY r.r_startdate DESC, r.r_id DESC
                    LIMIT ' . (int) $limitPerKind;
            $rq = rent_q_try(
                $sql,
                str_repeat('s', 14) . str_repeat('i', count($rentCusIds)),
                array_merge(array_fill(0, 14, $like), $rentCusIds)
            );
            if ($rq['ok'] && !empty($rq['result'])) {
                while ($r = $rq['result']->fetch_assoc()) {
                    $span = trim((string) ($r['r_startdate'] ?? ''));
                    $end = trim((string) ($r['r_enddate'] ?? ''));
                    if ($span !== '' && $end !== '') { $span .= ' – ' . $end; }
                    $po = trim((string) ($r['r_po'] ?? ''));
                    $site = trim((string) ($r['r_sitename'] ?? ''));
                    $leaseRows[] = [
                        'kind'       => 'rent',
                        'kind_label' => 'สัญญาเช่า',
                        'id'         => (int) $r['r_id'],
                        'code'       => $po,
                        'title'      => $po !== '' ? $po : (string) ($r['r_code'] ?? ''),
                        'sn'         => '',
                        'cus_id'     => (int) ($r['r_cus_id'] ?? 0),
                        'parts'      => [
                            '@cus' . (int) ($r['r_cus_id'] ?? 0),
                            $site,
                            (string) ($r['r_product'] ?? ''),
                            (string) ($r['r_status_rent'] ?? ''),
                            $span,
                        ],
                        // หน้าสัญญาของระบบเช่ากรองด้วยชื่อ ไม่ใช่ id — ส่งชื่อไซต์ไปให้ตรงใบ
                        'href'       => 'https://bit-online.net/rent/view_rent.php?sitename_rent='
                            . rawurlencode($site),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[smart_search] tbl_rent: ' . $e->getMessage());
        }

        // ── MA เครื่องเช่า ──
        // อาการเสียของเครื่องเช่าอยู่ที่ ma_remarks ที่เดียว ทะเบียนของเราไม่มีข้อความนี้
        try {
            $mq = rent_q_try(
                'SELECT ma_id, ma_sn, ma_product, ma_date, ma_remarks, ma_status
                 FROM tbl_product_ma
                 WHERE ma_sn LIKE ? OR IFNULL(ma_product, "") LIKE ? OR IFNULL(ma_remarks, "") LIKE ?
                 ORDER BY ma_date DESC, ma_id DESC
                 LIMIT ' . (int) $limitPerKind,
                'sss',
                [$like, $like, $like]
            );
            if ($mq['ok'] && !empty($mq['result'])) {
                while ($r = $mq['result']->fetch_assoc()) {
                    $sn = rent_normalize_sn($r['ma_sn'] ?? '');
                    $leaseRows[] = [
                        'kind'       => 'rentma',
                        'kind_label' => 'MA เช่า',
                        'id'         => (int) $r['ma_id'],
                        'code'       => $sn,
                        'title'      => $sn !== '' ? $sn : (string) ($r['ma_product'] ?? ''),
                        'sn'         => $sn,
                        'cus_id'     => 0,
                        'parts'      => [
                            (string) ($r['ma_product'] ?? ''),
                            (string) ($r['ma_remarks'] ?? ''),
                            rent_leasing_status_label((string) ($r['ma_status'] ?? ''), ''),
                            (string) ($r['ma_date'] ?? ''),
                        ],
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[smart_search] tbl_product_ma: ' . $e->getMessage());
        }

        // จับคู่ S/N ทั้งชุดกับทะเบียนเครื่องของเราในนัดเดียว แล้วค่อยเติมชื่อลูกค้ากับ href
        $needSn = [];
        $needCus = [];
        foreach ($leaseRows as $row) {
            if ($row['sn'] !== '') { $needSn[$row['sn']] = true; }
            if ($row['cus_id'] > 0 && !isset($rentCusName[$row['cus_id']])) {
                $needCus[$row['cus_id']] = true;
            }
        }
        $rentCusName += smart_search_rent_customer_names(array_keys($needCus));
        $assetBySn = smart_search_asset_ids_by_serial(array_keys($needSn));

        foreach ($leaseRows as $row) {
            $parts = [];
            foreach ($row['parts'] as $p) {
                if (strpos($p, '@cus') === 0) {
                    $p = (string) ($rentCusName[(int) substr($p, 4)] ?? '');
                }
                if (trim($p) !== '' && $p !== '—') { $parts[] = trim($p); }
            }
            $out[] = [
                'kind'       => $row['kind'],
                'kind_label' => $row['kind_label'],
                'id'         => $row['id'],
                'asset_id'   => isset($assetBySn[$row['sn']]) ? $assetBySn[$row['sn']] : 0,
                'code'       => $row['code'],
                'title'      => $row['title'],
                'subtitle'   => smart_search_excerpt(implode(' · ', $parts), 96),
                'href'       => isset($row['href'])
                    ? $row['href']
                    : smart_search_rent_href($row['sn'], $assetBySn),
            ];
        }
    }

    return $out;
}
