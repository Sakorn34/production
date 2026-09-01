<?php
/**
 * includes/smart_search.php — ค้นหาอัจฉริยะ sidebar (S/N, MA, อัปเดต FW/HW)
 *
 * ใช้โดย smart_search.php?ajax=1&q=...
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
 * เลือกข้อความที่ match คำค้นมากที่สุดจากฟิลด์ MA
 *
 * @param array<string,mixed> $row
 * @param string              $q
 * @return string
 */
function smart_search_ma_snippet(array $row, string $q): string
{
    $fields = [
        (string) ($row['remark'] ?? ''),
        (string) ($row['ok_items'] ?? ''),
        (string) ($row['replace_items'] ?? ''),
        (string) ($row['repair_items'] ?? ''),
        (string) ($row['fw_version'] ?? ''),
    ];
    foreach ($fields as $f) {
        $f = trim($f);
        if ($f !== '' && mb_stripos($f, $q) !== false) {
            return smart_search_excerpt($f);
        }
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
    foreach ($parts as $f) {
        if (mb_stripos($f, $q) !== false) {
            return smart_search_excerpt($f);
        }
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
 * ค้นหาเครื่อง / MA / อัปเดต รวมเป็นผลลัพธ์เดียว
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

    $assetRes = qr(
        'SELECT a.id, a.asset_code, a.factory_serial, a.status, p.name pname
         FROM assets a
         JOIN products p ON p.id = a.product_id
         WHERE a.asset_code LIKE ? OR a.factory_serial LIKE ?
         ORDER BY (a.asset_code LIKE ?) DESC, (a.factory_serial LIKE ?) DESC, a.asset_code ASC
         LIMIT ' . (int) $limitPerKind,
        'ssss',
        [$like, $like, $prefix, $prefix]
    );
    while ($r = $assetRes->fetch_assoc()) {
        $out[] = [
            'kind'       => 'asset',
            'kind_label' => 'เครื่อง',
            'id'         => (int) $r['id'],
            'asset_id'   => (int) $r['id'],
            'code'       => (string) $r['asset_code'],
            'title'      => (string) $r['asset_code'],
            'subtitle'   => (string) $r['pname'] . ' · ' . status_th((string) $r['status']),
            'href'       => 'asset.php?id=' . (int) $r['id'],
        ];
    }

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
         ORDER BY u.updated_at DESC, u.id DESC
         LIMIT ' . (int) $limitPerKind,
        'ssssss',
        [$like, $like, $like, $like, $like, $like]
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

    return $out;
}
