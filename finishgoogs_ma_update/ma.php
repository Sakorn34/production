<?php
/**
 * ma.php — บันทึก MA
 *
 * ขั้นที่ 1: เลือกรุ่นสินค้า (การ์ดรูป)
 * ขั้นที่ 2: ฟอร์มบันทึก + รายการ MA ของรุ่นนั้น
 *
 * เครื่องสำรอง = บันทึกเฉพาะระบบผลิต ไม่เรียกปิดคิว/ไม่เขียนระบบเช่า
 * เครื่องเช่า = ซิงก์ระบบเช่าตาม rent_close_action (ซ่อมแล้ว / เสื่อมสภาพ) เหมือนเดิม
 */
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/includes/ma_snippets.php';
require __DIR__ . '/includes/rent_ma_bridge.php';
require_once __DIR__ . '/includes/sd_image.php';

/** แปลงข้อความรายการ (คั่นด้วย ,) เป็น array */
function ma_items($s) {
    if ($s === null || trim($s) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $s)), function ($x) { return $x !== '' && $x !== '-'; }));
}

/**
 * ดึงรายการ OK/Replace/Repair จากคอลัมน์ใหม่ หรือ versions_json ของข้อมูลเก่า
 *
 * @param array<string,mixed> $rec แถว ma_records
 * @param string $col ชื่อคอลัมน์ ok_items|replace_items|repair_items
 * @param string $jsonKey คีย์ใน versions_json (OK|Replace|Repair)
 * @param bool $jsonFallback อนุญาต fallback จาก versions_json (ปิดเมื่อโหลดฟอร์มแก้ไข)
 * @return array<int,string>
 */
function ma_record_items(array $rec, $col, $jsonKey, $jsonFallback = true) {
    $colVal = isset($rec[$col]) ? $rec[$col] : null;
    if ($colVal !== null && trim((string) $colVal) !== '') {
        return ma_items($colVal);
    }
    if (!$jsonFallback) {
        return [];
    }
    if (!empty($rec['versions_json'])) {
        $vj = json_decode($rec['versions_json'], true) ?: [];
        if (!empty($vj[$jsonKey])) {
            return ma_items($vj[$jsonKey]);
        }
    }
    return [];
}

/**
 * ตรวจว่ารายการ MA บันทึกในคอลัมน์ใหม่แล้วหรือยัง
 *
 * @param array<string,mixed> $rec แถว ma_records
 * @return bool
 */
function ma_record_has_column_items(array $rec): bool {
    foreach (['ok_items', 'replace_items', 'repair_items'] as $c) {
        $v = isset($rec[$c]) ? trim((string) $rec[$c]) : '';
        if ($v !== '') {
            return true;
        }
    }
    return false;
}

/**
 * ดึงรายการสำหรับฟอร์มแก้ไข MA
 * — ใช้คอลัมน์ใหม่เป็นหลัก, fallback versions_json เฉพาะข้อมูล legacy
 *
 * @param array<string,mixed> $rec แถว ma_records
 * @param string $col ok_items|replace_items|repair_items
 * @param string $jsonKey OK|Replace|Repair
 * @return array<int,string>
 */
function ma_record_items_for_edit(array $rec, $col, $jsonKey): array {
    $colVal = isset($rec[$col]) ? $rec[$col] : null;
    if ($colVal !== null && trim((string) $colVal) !== '') {
        return ma_items($colVal);
    }
    if (ma_record_has_column_items($rec)) {
        return [];
    }
    return ma_record_items($rec, $col, $jsonKey, true);
}

/**
 * ดึง FW และหมายเหตุจาก MA ครั้งล่าสุดของรุ่น (product)
 *
 * @param int $productId
 * @return array{fw_version:string,remark:string}
 */
function ma_last_product_prefill(int $productId): array {
    if ($productId <= 0) {
        return ['fw_version' => '', 'remark' => ''];
    }
    $row = qr(
        "SELECT m.fw_version, m.remark FROM ma_records m
         INNER JOIN assets a ON a.id = m.asset_id
         WHERE a.product_id = ?
         ORDER BY m.visited_at DESC, m.id DESC LIMIT 1",
        'i',
        [$productId]
    )->fetch_assoc();
    return [
        'fw_version' => trim((string)($row['fw_version'] ?? '')),
        'remark'     => trim((string)($row['remark'] ?? '')),
    ];
}

/**
 * MA เครื่องสำรอง — ไม่ซิงก์/ไม่ปิดคิวระบบเช่า (เสื่อมสภาพใช้กับเครื่องเช่าเท่านั้น)
 *
 * @param string $postedStatus rental|spare จากฟอร์ม
 * @return bool
 */
function ma_spare_skips_rent_sync($postedStatus): bool
{
    return $postedStatus === 'spare';
}

/**
 * ช่อง "สิ่งที่ทำ" ในตารางประวัติ — ซ่อมก่อน แล้วเปลี่ยน ส่วนรายการปกติยุบเหลือจำนวน
 * (รายการปกติเต็มอยู่ใน popup รายละเอียดเมื่อคลิกแถว และใน title ของบรรทัดจำนวน)
 *
 * @param array<string,mixed> $rec
 * @return string HTML
 */
function ma_list_done_cell(array $rec) {
    $repair = ma_record_items($rec, 'repair_items', 'Repair');
    $replace = ma_record_items($rec, 'replace_items', 'Replace');
    $ok = ma_record_items($rec, 'ok_items', 'OK');
    $html = '';
    if ($repair || $replace) {
        $html .= '<div class="ma-list-item-chips">';
        foreach ($repair as $it) {
            $html .= '<span class="ma-list-item-chip ma-list-item-chip-repair" title="ซ่อม">' . h($it) . '</span>';
        }
        foreach ($replace as $it) {
            $html .= '<span class="ma-list-item-chip ma-list-item-chip-replace" title="เปลี่ยน">' . h($it) . '</span>';
        }
        $html .= '</div>';
    }
    if ($ok) {
        $html .= '<div class="ma-list-okn" title="' . h(implode(', ', $ok)) . '">✓ ตรวจปกติ ' . count($ok) . ' รายการ</div>';
    }
    return $html !== '' ? $html : '<span class="muted">—</span>';
}

/** แสดงรายการเป็นบรรทัดพร้อมหัวข้อ */
function ma_items_html($rec) {
    $out = [];
    // เปลี่ยน/ซ่อมมาก่อน "ปกติ" เพราะเป็นสิ่งที่คนเปิดดูมองหา · แต่ละรายการแยกบรรทัดมีไอคอนนำหน้า
    // (ของเดิมต่อกันเป็นพรืดด้วย · ทำให้ไล่สายตาไม่ออกว่ารายการไหนจบตรงไหน)
    $groups = [
        ['replace_items', 'Replace', 'status-replace', 'เปลี่ยนอะไหล่', 'is-rep'],
        ['repair_items', 'Repair', 'status-repair', 'ซ่อม', 'is-fix'],
        ['ok_items', 'OK', 'status-ok', 'ตรวจแล้วปกติ', 'is-ok'],
    ];
    foreach ($groups as $g) {
        $items = ma_record_items($rec, $g[0], $g[1]);
        if (!$items) {
            continue;
        }
        $li = '';
        foreach ($items as $it) {
            $li .= '<div class="ma-li">' . ui_icon_html($g[2], 14, 'ma-li-ic') . '<span>' . h($it) . '</span></div>';
        }
        $out[] = '<div class="ma-grp ' . $g[4] . '"><div class="ma-grp-t">' . h($g[3]) . ' (' . count($items) . ')</div>'
               . '<div class="ma-cols">' . $li . '</div></div>';
    }
    // ข้อมูลอื่นจาก JSON เดิม (Pin IO, เวอร์ชันบอร์ด ฯลฯ)
    if (!empty($rec['versions_json'])) {
        $others = '';
        $n = 0;
        foreach (json_decode($rec['versions_json'], true) ?: [] as $k => $v) {
            if (in_array($k, ['OK', 'Replace', 'Repair'], true)) continue;
            $others .= '<div class="ma-li ma-li-kv"><span>' . h($k) . '</span><b>' . h((string) $v) . '</b></div>';
            $n++;
        }
        if ($n) {
            $out[] = '<div class="ma-grp"><div class="ma-grp-t">ข้อมูลอื่น (' . $n . ')</div><div class="ma-cols">' . $others . '</div></div>';
        }
    }
    $remark = trim((string) ($rec['remark'] ?? ''));
    if ($remark !== '' && $remark !== '-') {
        $out[] = '<div class="ma-note"><b>หมายเหตุ</b><div>' . nl2br(h($remark)) . '</div></div>';
    }
    return $out ? implode('', $out) : '<span class="muted">-</span>';
}

/**
 * อะไหล่ที่เบิกในรอบ MA นี้ — ชื่อซ้าย จำนวนขวา เรียง 2 คอลัมน์แบบเดียวกับรายการตรวจ
 *
 * @param int $maRecordId
 * @return string
 */
function ma_detail_parts_html($maRecordId) {
    if (!function_exists('ma_parts_withdrawn_items')) {
        return '';
    }
    $items = ma_parts_withdrawn_items($maRecordId);
    if (!$items) {
        return '';
    }
    $li = '';
    foreach ($items as $r) {
        $q = rtrim(rtrim(number_format((float) $r['qty'], 2), '0'), '.');
        $li .= '<div class="ma-li ma-li-part"><span>' . h($r['name']) . '</span>'
             . '<b>×' . h($q) . ($r['unit'] !== '' ? ' ' . h($r['unit']) : '') . '</b></div>';
    }
    return '<div class="ma-grp"><div class="ma-grp-t">อะไหล่ที่เบิก (' . count($items) . ')</div>'
         . '<div class="ma-cols">' . $li . '</div></div>';
}

/**
 * วันเวลาที่อ่านง่าย — ข้อมูลเก่าหลายรายการไม่มีเวลา (00:00) ตัดทิ้งไม่ต้องโชว์
 *
 * @param string $visitedAt
 * @return string
 */
function ma_when_label($visitedAt) {
    $when = dthai_full($visitedAt);
    [$d, $t] = array_pad(explode(' ', $when, 2), 2, '');
    return $t === '' || $t === '00:00' ? $d : $when;
}

/**
 * ชิปสรุปผลการตรวจของ MA 1 ครั้ง — เปลี่ยนอะไหล่/ซ่อมมาก่อน เพราะเป็นสิ่งที่คนอ่านมองหา
 *
 * @param array<string,mixed> $rec
 * @param bool                $showFw
 * @return string
 */
function ma_hist_chips_html($rec, $showFw) {
    $out = '';
    $groups = [
        ['replace_items', 'Replace', 'เปลี่ยน', 'is-rep'],
        ['repair_items', 'Repair', 'ซ่อม', 'is-fix'],
        ['ok_items', 'OK', 'ปกติ', 'is-ok'],
    ];
    foreach ($groups as $g) {
        $n = count(ma_record_items($rec, $g[0], $g[1]));
        if ($n > 0) {
            $out .= '<span class="ma-chip ' . $g[3] . '">' . h($g[2]) . ' ' . $n . '</span>';
        }
    }
    if ($showFw && trim((string) $rec['fw_version']) !== '') {
        $out .= '<span class="ma-chip is-fw">' . h($rec['fw_version']) . '</span>';
    }
    return $out !== '' ? '<div class="ma-chips">' . $out . '</div>' : '';
}

/**
 * สรุปย่อบรรทัดเดียว: ของที่เปลี่ยน/ซ่อมก่อน แล้วต่อด้วยหมายเหตุ — รายละเอียดเต็มอยู่ใน popup
 *
 * @param array<string,mixed> $rec
 * @return string
 */
function ma_hist_gist_html($rec) {
    $parts = array_merge(
        ma_record_items($rec, 'replace_items', 'Replace'),
        ma_record_items($rec, 'repair_items', 'Repair')
    );
    $text = implode(' · ', $parts);
    $remark = trim((string) $rec['remark']);
    if ($remark !== '' && $remark !== '-') {
        $text = ($text !== '' ? $text . ' — ' : '') . $remark;
    }
    if ($text === '') {
        $n = count(ma_record_items($rec, 'ok_items', 'OK'));
        $text = $n > 0 ? 'ตรวจตามรอบ ไม่มีการเปลี่ยนอะไหล่' : '-';
    }
    return '<div class="ma-hist-gist">' . h(mb_strimwidth($text, 0, 120, '…')) . '</div>';
}

/**
 * ตรวจและทำความสะอาดรายการ MA — ห้ามซ้ำทั้งในช่องเดียวกันและข้ามช่อง ✅/🔄/🔧
 *
 * @return array{ok: string, replace: string, repair: string}|null null = มีรายการซ้ำข้ามช่อง
 */
function ma_sanitize_item_sets($okRaw, $repRaw, $fixRaw) {
    $sets = [
        'ok' => ma_items($okRaw),
        'replace' => ma_items($repRaw),
        'repair' => ma_items($fixRaw),
    ];
    $seen = [];
    foreach (['ok', 'replace', 'repair'] as $key) {
        $unique = [];
        foreach ($sets[$key] as $it) {
            if (isset($seen[$it])) {
                return null;
            }
            $seen[$it] = $key;
            $unique[] = $it;
        }
        $sets[$key] = $unique;
    }
    return [
        'ok' => $sets['ok'] ? implode(' , ', $sets['ok']) : null,
        'replace' => $sets['replace'] ? implode(' , ', $sets['replace']) : null,
        'repair' => $sets['repair'] ? implode(' , ', $sets['repair']) : null,
    ];
}

/**
 * สร้างข้อความสรุป MA สำหรับบันทึกไประบบเช่า — ส่งเฉพาะหัวข้อที่มีข้อมูล (ไม่ใส่ FW/Note ว่าง)
 *
 * ซ่อมแล้ว (close_fg) ขึ้นต้นว่าใช้งานได้ปกติตามงานเช่า Office
 * เสื่อมสภาพ (retire) ส่งเฉพาะหมายเหตุ — ห้ามบอกว่าใช้งานได้ปกติ
 *
 * @param string|null $replace รายการเปลี่ยนอะไหล่
 * @param string|null $repair  รายการซ่อม
 * @param string|null $fw       เวอร์ชัน FW
 * @param string|null $remark   หมายเหตุ
 * @param string      $action  close_fg|retire|เสื่อมสภาพ
 * @return string
 */
function ma_rental_office_summary($replace, $repair, $fw, $remark, $action = 'close_fg') {
    $action = trim((string)$action);
    $isRetire = ($action === 'retire' || $action === 'เสื่อมสภาพ');
    if (!$isRetire && $action !== 'close_fg' && $action !== 'keep' && $action !== 'ซ่อมแล้ว' && $action !== 'ไม่ปิดคิว') {
        $isRetire = function_exists('mb_strpos') && mb_strpos(trim((string)$remark), 'เสื่อมสภาพ') === 0;
    }
    if ($isRetire) {
        return function_exists('rent_ensure_retire_remark')
            ? rent_ensure_retire_remark($remark)
            : (trim((string)$remark) !== '' ? trim((string)$remark) : 'เสื่อมสภาพ');
    }
    $parts = ['ใช้งานได้ปกติ เช็ดทำความสะอาด'];
    $rep = trim((string)$replace);
    $fix = trim((string)$repair);
    $fw = trim((string)$fw);
    $remark = trim((string)$remark);
    if ($rep !== '') {
        $parts[] = 'เปลี่ยน: ' . $rep;
    }
    if ($fix !== '') {
        $parts[] = 'แก้ไข: ' . $fix;
    }
    if ($fw !== '') {
        $parts[] = 'FW: ' . $fw;
    }
    if ($remark !== '') {
        $parts[] = 'Note: ' . $remark;
    }
    return implode(' · ', $parts);
}

/**
 * แปลงรหัสเครื่องเป็น MAC Address สำหรับตั้งค่าใน cmdline.txt
 *
 * ใช้เลข 8 หลักท้ายจากรหัส (เช่น BS22120047 → 22:12:00:47)
 *
 * @param string $assetCode รหัสเครื่อง / asset_code
 * @return string MAC หรือค่าว่างถ้าแปลงไม่ได้
 */
function ma_asset_code_to_mac($assetCode) {
    $assetCode = trim((string)$assetCode);
    if ($assetCode === '') {
        return '';
    }
    $digits = preg_replace('/\D/', '', $assetCode);
    if ($digits === '') {
        return '';
    }
    $digits = str_pad(substr($digits, -8), 8, '0', STR_PAD_LEFT);
    return substr($digits, 0, 2) . ':'
        . substr($digits, 2, 2) . ':'
        . substr($digits, 4, 2) . ':'
        . substr($digits, 6, 2);
}

/**
 * data-* สำหรับแถวตาราง MA (เปิด popup รายละเอียด)
 *
 * @param array<string,mixed> $rec
 * @return string
 */
function ma_row_data_attrs(array $rec) {
    $ok = ma_record_items($rec, 'ok_items', 'OK');
    $rep = ma_record_items($rec, 'replace_items', 'Replace');
    $fix = ma_record_items($rec, 'repair_items', 'Repair');
    $extra = [
        'data-date' => dthai_full($rec['visited_at']),
        'data-round' => $rec['ma_round'] ? (string)(int)$rec['ma_round'] : '-',
        'data-asset' => (string)$rec['asset_code'],
        'data-ok' => $ok ? implode("\n", $ok) : '-',
        'data-replace-disp' => $rep ? implode("\n", $rep) : '-',
        'data-repair-disp' => $fix ? implode("\n", $fix) : '-',
        'data-parts-withdraw' => ma_parts_withdrawn_text((int)$rec['id']),
        'data-by' => (string)($rec['done_by'] ?: '-'),
    ];
    $out = ma_snippet_data_attrs([
        'code' => (string)$rec['asset_code'],
        'replace' => implode(' , ', ma_record_items($rec, 'replace_items', 'Replace')),
        'repair' => implode(' , ', ma_record_items($rec, 'repair_items', 'Repair')),
        'fw' => (string)($rec['fw_version'] ?? ''),
        'remark' => (string)($rec['remark'] ?? ''),
    ]);
    $extra['data-rent-close'] = ma_rent_close_label($rec);
    $kind = ma_list_machine_kind($rec);
    $extra['data-machine-kind'] = status_th_chip($kind);
    foreach ($extra as $k => $v) {
        $out .= ' ' . $k . '="' . h($v) . '"';
    }
    return $out;
}

/**
 * ประเภทเครื่องตอนบันทึก MA สำหรับรายการในตาราง (เช่า / สำรอง)
 *
 * @param array<string,mixed> $rec
 * @return string rental|spare
 */
function ma_list_machine_kind(array $rec)
{
    if (($rec['src'] ?? '') === 'rent') {
        return 'rental';
    }
    $ms = trim((string) ($rec['machine_status'] ?? ''));
    if ($ms === 'spare') {
        return 'spare';
    }
    if ($ms === 'rental') {
        return 'rental';
    }
    // รายการก่อนมีคอลัมน์ machine_status — อนุมานจากสถานะทะเบียนเครื่อง
    $ast = trim((string) ($rec['ast_status'] ?? ''));
    if ($ast === 'spare') {
        return 'spare';
    }
    return 'rental';
}

/**
 * ป้ายประเภทเครื่อง (เช่า / สำรอง) ในตาราง MA
 *
 * @param string $kind rental|spare
 * @return string HTML
 */
function ma_list_kind_chip_html($kind)
{
    $kind = ($kind === 'spare') ? 'spare' : 'rental';
    $e = status_palette_entry($kind);
    return '<span class="ma-kind-chip ma-kind-chip-' . h($kind) . '" style="background:' . h($e['bg'])
        . ';color:' . h($e['fg']) . '">' . h($e['chip']) . '</span>';
}

/**
 * ตรวจค่าวันที่จาก query (input type=date)
 *
 * @param mixed $raw
 * @return string Y-m-d หรือว่างถ้าไม่ถูกต้อง
 */
function ma_list_parse_date_param($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return '';
    }
    return $raw;
}

/**
 * @return bool มีตัวกรองรายการ MA อยู่หรือไม่
 */
function ma_list_filter_active($mq, $mby, $mround, $mkind, $mfrom, $mto, $mok, $mrepl, $mrepair, $mrLegacy = '')
{
    return $mq !== '' || $mby !== '' || $mround !== '' || $mkind !== ''
        || $mfrom !== '' || $mto !== ''
        || $mok !== '' || $mrepl !== '' || $mrepair !== ''
        || $mrLegacy !== '';
}

/**
 * เพิ่มเงื่อนไข SQL กรอง ok/replace/repair (หรือ legacy mr)
 *
 * @param list<string> $maWhere
 */
function ma_list_apply_item_sql_filters(array &$maWhere, &$maTypes, array &$maParams, $mok, $mrepl, $mrepair, $mrLegacy)
{
    $mok = trim((string) $mok);
    $mrepl = trim((string) $mrepl);
    $mrepair = trim((string) $mrepair);
    $mrLegacy = trim((string) $mrLegacy);
    if ($mrLegacy !== '' && $mok === '' && $mrepl === '' && $mrepair === '') {
        $maWhere[] = '(m.ok_items LIKE ? OR m.replace_items LIKE ? OR m.repair_items LIKE ? OR m.remark LIKE ?)';
        $maTypes .= 'ssss';
        $like = '%' . $mrLegacy . '%';
        array_push($maParams, $like, $like, $like, $like);
        return;
    }
    if ($mok !== '') {
        $maWhere[] = 'm.ok_items LIKE ?';
        $maTypes .= 's';
        $maParams[] = '%' . $mok . '%';
    }
    if ($mrepl !== '') {
        $maWhere[] = 'm.replace_items LIKE ?';
        $maTypes .= 's';
        $maParams[] = '%' . $mrepl . '%';
    }
    if ($mrepair !== '') {
        $maWhere[] = 'm.repair_items LIKE ?';
        $maTypes .= 's';
        $maParams[] = '%' . $mrepair . '%';
    }
}

/**
 * กรองรายการระบบเช่าตามช่องรายการตรวจ (ข้อความรวมใน remark)
 *
 * @param array<string,mixed> $r
 */
function ma_list_rent_passes_item_filters(array $r, $mok, $mrepl, $mrepair, $mrLegacy)
{
    $note = (string) ($r['remark'] ?? '');
    $mok = trim((string) $mok);
    $mrepl = trim((string) $mrepl);
    $mrepair = trim((string) $mrepair);
    $mrLegacy = trim((string) $mrLegacy);
    if ($mrLegacy !== '' && $mok === '' && $mrepl === '' && $mrepair === '') {
        return mb_stripos($note, $mrLegacy) !== false;
    }
    if ($mok !== '' && mb_stripos($note, $mok) === false) {
        return false;
    }
    if ($mrepl !== '' && mb_stripos($note, $mrepl) === false) {
        return false;
    }
    if ($mrepair !== '' && mb_stripos($note, $mrepair) === false) {
        return false;
    }
    return true;
}

/**
 * ข้อความผลต่อระบบเช่าของรายการ MA หนึ่งแถว
 *
 * @param array<string,mixed> $rec
 * @return string ซ่อมแล้ว|เสื่อมสภาพ|ไม่ปิดคิว|—
 */
function ma_rent_close_label(array $rec)
{
    $st = trim((string) ($rec['ma_status'] ?? $rec['rent_close_status'] ?? ''));
    if ($st === 'Asset Retirement') {
        return 'เสื่อมสภาพ';
    }
    if ($st === 'finished goods') {
        return 'ซ่อมแล้ว';
    }
    if (($rec['src'] ?? '') !== 'rent' && ($rec['machine_status'] ?? '') === 'spare') {
        return 'ไม่ปิดคิว';
    }
    return '—';
}

/**
 * เซลล์คอลัมน์ผลต่อระบบเช่า
 *
 * @param array<string,mixed> $rec
 * @return string
 */
function ma_rent_close_cell(array $rec)
{
    $label = ma_rent_close_label($rec);
    if ($label === 'เสื่อมสภาพ') {
        return '<span class="ma-pill ma-pill-urgent">เสื่อมสภาพ</span>';
    }
    if ($label === 'ซ่อมแล้ว') {
        return '<span class="ma-pill ma-pill-done">ซ่อมแล้ว</span>';
    }
    return '<span class="muted">' . h($label) . '</span>';
}

/**
 * สร้าง query string สำหรับหน้ารายการ MA (คงค่าค้นหา/เรียง)
 *
 * @param int                 $productId
 * @param array<string,mixed> $over
 * @return string
 */
function ma_list_qs($productId, array $over = []) {
    $q = [
        'product' => (int)$productId,
        'mq' => isset($_GET['mq']) ? trim((string)$_GET['mq']) : '',
        'mby' => isset($_GET['mby']) ? trim((string)$_GET['mby']) : '',
        'mr' => isset($_GET['mr']) ? trim((string)$_GET['mr']) : '',
        'mok' => isset($_GET['mok']) ? trim((string)$_GET['mok']) : '',
        'mrepl' => isset($_GET['mrepl']) ? trim((string)$_GET['mrepl']) : '',
        'mrepair' => isset($_GET['mrepair']) ? trim((string)$_GET['mrepair']) : '',
        'mfrom' => ma_list_parse_date_param($_GET['mfrom'] ?? ''),
        'mto' => ma_list_parse_date_param($_GET['mto'] ?? ''),
        'mround' => isset($_GET['mround']) ? trim((string)$_GET['mround']) : '',
        'msort' => isset($_GET['msort']) ? trim((string)$_GET['msort']) : 'date_desc',
        // mrent=0 = ซ่อนรายการจากระบบเช่า — ต้องพกไปกับลิงก์เรียง/แบ่งหน้าด้วย
        'mrent' => isset($_GET['mrent']) ? trim((string)$_GET['mrent']) : '',
        'mkind' => isset($_GET['mkind']) ? trim((string)$_GET['mkind']) : '',
        'page' => isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1,
    ];
    foreach ($over as $k => $v) {
        $q[$k] = $v;
    }
    $parts = ['product=' . (int)$q['product']];
    foreach (['mq', 'mby', 'mr', 'mok', 'mrepl', 'mrepair', 'mfrom', 'mto', 'mround', 'msort', 'mrent', 'mkind'] as $k) {
        if ($q[$k] !== '') $parts[] = rawurlencode($k) . '=' . rawurlencode($q[$k]);
    }
    if ((int)$q['page'] > 1) $parts[] = 'page=' . (int)$q['page'];
    return '?' . implode('&', $parts);
}

/**
 * ลิงก์หัวคอลัมน์เรียงข้อมูล MA
 *
 * @param string $label
 * @param string $ascKey
 * @param string $descKey
 * @param string $msort
 * @param int    $productId
 * @return string
 */
function ma_sort_th($label, $ascKey, $descKey, $msort, $productId) {
    $next = ($msort === $ascKey) ? $descKey : $ascKey;
    $arrow = '';
    if ($msort === $ascKey) $arrow = ' ↑';
    elseif ($msort === $descKey) $arrow = ' ↓';
    return '<a href="' . h(ma_list_qs($productId, ['msort' => $next, 'page' => 1])) . '" class="ma-sort-link">' . $label . $arrow . '</a>';
}

// ---------------------------------------------------------------
// AJAX: ประวัติ MA ของเครื่อง (HTML)
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    header('Content-Type: text/html; charset=utf-8');
    $code = trim(isset($_GET['code']) ? $_GET['code'] : '');
    $a = qr("SELECT a.*, p.name pname FROM assets a JOIN products p ON p.id=a.product_id
             WHERE a.asset_code=? OR a.factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
    if (!$a) { echo '<p class="muted">❌ ไม่พบเครื่องรหัส "' . h($code) . '" ในระบบ</p>'; exit; }

    echo '<div style="background:#eef4fb; border:1px solid #c9d9ef; border-radius:8px; padding:12px 14px; margin-bottom:10px">';
    echo '<b><a href="' . BASE_URL . '/asset.php?id=' . $a['id'] . '" target="_blank">' . h($a['asset_code']) . '</a></b> — ' . h($a['pname'])
       . ' · ' . status_badge($a['status']);
    if (product_show_fw((int) $a['product_id'])) {
        $fwCur = trim((string) ($a['current_fw_version'] ?? ''));
        echo ' · FW: ' . h($fwCur !== '' ? $fwCur : '-');
    }
    echo ' · ผลิต ' . dthai($a['produced_at']);
    echo '</div>';

    // แจ้งเตือนอะไหล่ครบกำหนดเปลี่ยน (SD Card / Battery Backup RTC / Battery Power) ให้เห็นก่อนบันทึก MA
    echo part_alerts_html($a['id'], $a['produced_at']);
    $sdHist = sd_image_info($a, (string) $a['pname']);
    if (!empty($sdHist['tracked'])) {
        echo '<p class="muted" style="margin:8px 0 0">SD image: ' . h($sdHist['reason'] !== '' ? $sdHist['reason'] : 'ยังไม่ระบุบอร์ด') . '</p>';
    }

    $canMa = can('ma');
    $histShowFw = product_show_fw((int) $a['product_id']);
    $res = qr("SELECT * FROM ma_records WHERE asset_id=? ORDER BY visited_at DESC, id DESC LIMIT 15", 'i', [$a['id']]);
    echo '<b>📅 ประวัติ MA (' . $res->num_rows . ' ครั้งล่าสุด)</b>';
    if ($res->num_rows) {
        // 1 บรรทัดต่อครั้ง: ชิปสรุปผล + สรุปย่อ — รายละเอียดเต็มอยู่ใน popup (ajax=ma_detail)
        echo '<div class="table-wrap">';
        echo '<table class="list ma-hist" style="margin:6px 0 12px"><tr><th data-pri="1">วันเวลา</th><th data-pri="1">ผลการตรวจ</th>'
           . ($canMa ? '<th data-pri="1"></th>' : '') . '</tr>';
        while ($m = $res->fetch_assoc()) {
            $when = dthai_full($m['visited_at']);
            [$wDate, $wTime] = array_pad(explode(' ', $when, 2), 2, '');
            // ข้อมูลเก่าหลายรายการไม่มีเวลา (00:00) และไม่รู้ผู้ตรวจ — ไม่ต้องโชว์ให้รก
            $sub = array_filter([$wTime !== '' && $wTime !== '00:00' ? $wTime : '', trim((string) $m['done_by'])]);
            echo '<tr class="ma-hist-row" data-ma="' . (int) $m['id'] . '" data-when="' . h(ma_when_label($m['visited_at'])) . '" tabindex="0">'
               . '<td data-pri="1" data-nowrap><b>' . h($wDate) . '</b>'
               . ($sub ? '<div class="muted ma-hist-sub">' . h(implode(' · ', $sub)) . '</div>' : '') . '</td>'
               . '<td data-pri="1">' . ma_hist_chips_html($m, $histShowFw) . ma_hist_gist_html($m) . '</td>'
               . ($canMa ? '<td data-pri="1" class="row-actions"><a class="btn btn-sm btn-line" href="' . BASE_URL . '/ma.php?edit=' . $m['id'] . '">แก้ไข</a>'
                    . '<form method="post" onsubmit="return confirm(\'ลบรายการ MA นี้?\')">' . csrf_field()
                    . '<input type="hidden" name="del_ma" value="1"><input type="hidden" name="ma_id" value="' . $m['id'] . '">'
                    . '<button class="btn-sm btn-danger" type="submit">ลบ</button></form></td>' : '')
               . '</tr>';
        }
        echo '</table>';
        echo '</div>';
    } else echo '<p class="muted" style="margin:4px 0 12px">ยังไม่เคยเข้า MA</p>';

    // การอัปเดต/เปลี่ยนชิ้นส่วนจาก update_logs
    $res = qr("SELECT updated_at, update_type, component_name, old_value, new_value, detail FROM update_logs
               WHERE asset_id=? ORDER BY updated_at DESC, id DESC LIMIT 8", 'i', [$a['id']]);
    if ($res->num_rows) {
        echo '<b>🔩 การอัปเดต FW/HW (' . $res->num_rows . ' ครั้งล่าสุด)</b>';
        echo '<div class="table-wrap">';
        echo '<table class="list" style="margin:6px 0 12px"><tr><th>วันเวลา</th><th>ประเภท</th><th>รายละเอียด</th></tr>';
        while ($u = $res->fetch_assoc()) {
            $d = [];
            if ($u['component_name']) $d[] = $u['component_name'];
            if ($u['old_value'] || $u['new_value']) $d[] = ($u['old_value'] ?: '?') . ' → ' . ($u['new_value'] ?: '?');
            if ($u['detail']) $d[] = $u['detail'];
            echo '<tr><td>' . dthai_full($u['updated_at']) . '</td>'
               . '<td>' . ['firmware' => '📲 FW', 'hardware' => '🔩 HW', 'other' => '⚙️'][$u['update_type']] . '</td>'
               . '<td style="max-width:400px; font-size:13px">' . h(mb_strimwidth(implode(' | ', $d), 0, 170, '…')) . '</td></tr>';
        }
        echo '</table>';
        echo '</div>';
    }
    exit;
}

// ---------------------------------------------------------------
// AJAX: รายละเอียด MA 1 ครั้ง — เนื้อหาใน popup ของตารางประวัติ
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ma_detail') {
    header('Content-Type: text/html; charset=utf-8');
    $m = qr("SELECT m.*, a.asset_code, a.product_id, p.name pname FROM ma_records m
             JOIN assets a ON a.id = m.asset_id JOIN products p ON p.id = a.product_id
             WHERE m.id = ?", 'i', [(int) ($_GET['id'] ?? 0)])->fetch_assoc();
    if (!$m) { echo '<p class="muted">ไม่พบรายการ MA นี้</p>'; exit; }
    echo '<div class="ma-pop">';
    $sub = array_filter([
        trim((string) $m['done_by']) !== '' ? 'ผู้ตรวจ ' . $m['done_by'] : '',
        product_show_fw((int) $m['product_id']) && trim((string) $m['fw_version']) !== '' ? 'FW ' . $m['fw_version'] : '',
    ]);
    echo '<div class="ma-pop-head"><b>' . h(ma_when_label($m['visited_at'])) . '</b>'
       . '<span class="muted"> · ' . h($m['asset_code']) . ' · ' . h($m['pname']) . '</span>'
       . ($sub ? '<div class="muted">' . h(implode(' · ', $sub)) . '</div>' : '') . '</div>';
    echo '<div class="ma-pop-body">' . ma_items_html($m) . ma_detail_parts_html((int) $m['id']) . '</div>';
    if (can('ma')) {
        echo '<div class="ma-pop-acts"><a class="btn btn-sm btn-line" href="' . BASE_URL . '/ma.php?edit=' . (int) $m['id'] . '">แก้ไขรายการนี้</a>'
           . '<form method="post" onsubmit="return confirm(\'ลบรายการ MA นี้?\')">' . csrf_field()
           . '<input type="hidden" name="del_ma" value="1"><input type="hidden" name="ma_id" value="' . (int) $m['id'] . '">'
           . '<button class="btn-sm btn-danger" type="submit">ลบรายการนี้</button></form></div>';
    }
    echo '</div>';
    exit;
}

// ---------------------------------------------------------------
// AJAX: รายการอะไหล่/จุดตรวจที่เคยใช้กับรุ่นนี้ (สำหรับ dropdown + ค่าเริ่มต้น)
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim(isset($_GET['code']) ? $_GET['code'] : '');
    $a = qr("SELECT id, product_id, status, asset_code, produced_at, current_fw_version FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
    if (!$a) { echo json_encode(['found' => false]); exit; }

    // pool รายการ = config หลังบ้านเท่านั้น (ไม่รวมประวัติเก่า) — แยกตามช่อง
    $poolOk = effective_ma_pool($a['product_id'], 'ok');
    $poolReplace = effective_ma_pool($a['product_id'], 'replace');
    $poolRepair = effective_ma_pool($a['product_id'], 'repair');
    $poolAll = effective_ma_pool($a['product_id']);

    // ค่าเริ่มต้นช่อง "ปกติ" = รายการจาก MA ครั้งล่าสุดของเครื่องนี้
    $lastMa = qr("SELECT ok_items, replace_items, repair_items, versions_json FROM ma_records WHERE asset_id=? ORDER BY visited_at DESC, id DESC LIMIT 1", 'i', [$a['id']])->fetch_assoc();
    echo json_encode([
        'found' => true,
        'status' => $a['status'],
        'pool' => $poolAll,
        'pool_ok' => $poolOk,
        'pool_replace' => $poolReplace,
        'pool_repair' => $poolRepair,
        'fw_options' => product_show_fw($a['product_id']) ? effective_fw_options($a['product_id']) : [],
        'prefill_ok' => $lastMa ? ma_record_items($lastMa, 'ok_items', 'OK') : [],
        'sd' => sd_image_ajax_payload(sd_image_info($a)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// AJAX: รายการอะไหล่สำหรับฟอร์มเบิก MA
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'parts') {
    header('Content-Type: application/json; charset=utf-8');
    $rows = [];
    $rp = qr("SELECT id, name, unit, part_code, stock_code, icon_path FROM parts WHERE is_active=1 ORDER BY name");
    while ($r = $rp->fetch_assoc()) {
        $rows[] = $r;
    }
    $qtyMap = tech_parts_qty_map_for_parts($rows);
    $out = ['all_parts' => []];
    foreach ($rows as $r) {
        $codeKey = part_row_stock_code($r);
        if ($codeKey === '') {
            $codeKey = trim((string)($r['part_code'] ?? ''));
        }
        $out['all_parts'][] = [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'unit'       => (string)($r['unit'] ?? ''),
            'part_code'  => (string)($r['part_code'] ?? ''),
            'stock_code' => (string)($r['stock_code'] ?? ''),
            'search'     => part_search_key($r),
            'icon'       => img_url($r['icon_path'] ?? '') ?: '',
            'stock_qty'  => ($codeKey !== '' && isset($qtyMap[$codeKey])) ? (float)$qtyMap[$codeKey] : null,
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// ลงทะเบียน S/N เก่าจากคิวเช่าเข้าระบบผลิต
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_rent_assets'])) {
    csrf_check();
    require_can('ma');
    $regProductId = (int)($_POST['product_id'] ?? 0);
    $regSerials = [];
    foreach ((array)($_POST['register_sns'] ?? []) as $sn) {
        $sn = rent_normalize_sn($sn);
        if ($sn !== '') {
            $regSerials[] = $sn;
        }
    }
    if ($regProductId <= 0 || !$regSerials) {
        flash_set('กรุณาเลือก S/N อย่างน้อย 1 รายการ', 'err');
        header('Location: ' . BASE_URL . '/ma.php' . ($regProductId > 0 ? '?product=' . $regProductId : ''));
        exit;
    }
    $reg = rent_register_assets_to_product($regProductId, $regSerials, (string)($_POST['register_note'] ?? ''));
    $summary = 'ลงทะเบียนผลิต: สำเร็จ ' . (int)$reg['success'] . ' · ไม่สำเร็จ ' . (int)$reg['fail'];
    if ($reg['messages']) {
        $summary .= ' — ' . implode(' | ', array_slice($reg['messages'], 0, 10));
        if (count($reg['messages']) > 10) {
            $summary .= ' …';
        }
    }
    flash_set($summary, $reg['ok'] ? 'ok' : 'err', $summary);
    header('Location: ' . BASE_URL . '/ma.php?product=' . $regProductId);
    exit;
}

// ---------------------------------------------------------------
// เคลียร์รายการเสื่อมสภาพหลาย S/N ที่ค้าง
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_retire_clear_ma'])) {
    csrf_check();
    require_can('ma');
    rent_bulk_retire_pending_clear();
    $clearProductId = (int)($_POST['bulk_retire_product'] ?? 0);
    flash_set('ล้างรายการเสื่อมสภาพที่ค้างแล้ว', 'ok');
    $clearUrl = BASE_URL . '/ma.php';
    if ($clearProductId > 0) {
        $clearUrl .= '?product=' . $clearProductId;
    }
    header('Location: ' . $clearUrl);
    exit;
}

// ---------------------------------------------------------------
// เสื่อมสภาพหลาย S/N ในครั้งเดียว
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_retire_ma'])) {
    csrf_check();
    require_can('ma');
    $bulkProductId = (int)($_POST['bulk_retire_product'] ?? 0);
    $bulkReturnUrl = BASE_URL . '/ma.php';
    if ($bulkProductId > 0) {
        $bulkReturnUrl .= '?product=' . $bulkProductId;
    }
    $serials = rent_parse_serial_list((string)($_POST['bulk_serials'] ?? ''));
    if (!$serials) {
        flash_set('กรุณากรอกหมายเลขสินค้าอย่างน้อย 1 รายการ', 'err');
        header('Location: ' . $bulkReturnUrl);
        exit;
    }
    $bulk = rent_bulk_retire($serials, (string)($_POST['bulk_remark'] ?? ''));
    $successN = (int)$bulk['success'];
    $failN = (int)$bulk['fail'];
    if ($failN > 0 && !empty($bulk['failed_serials'])) {
        rent_bulk_retire_pending_save([
            'serials'      => implode("\n", $bulk['failed_serials']),
            'remark'       => trim((string)($_POST['bulk_remark'] ?? '')),
            'errors'       => $bulk['failed_messages'] ?? [],
            'failed_items' => $bulk['failed_items'] ?? [],
            'success'      => $successN,
            'fail'         => $failN,
        ]);
        $firstReasons = array_slice($bulk['failed_items'] ?? [], 0, 3);
        $reasonHint = '';
        foreach ($firstReasons as $row) {
            if (!is_array($row) || empty($row['sn'])) {
                continue;
            }
            $reasonHint .= ' · ' . $row['sn'] . ': ' . ($row['reason'] ?? '');
        }
        $summary = 'เสื่อมสภาพ: สำเร็จ ' . $successN . ' · ไม่สำเร็จ ' . $failN
            . ' — ดูสาเหตุในตารางด้านล่าง' . $reasonHint;
        if ($failN > 3) {
            $summary .= ' …';
        }
        flash_set($summary, 'err', $summary);
    } else {
        rent_bulk_retire_pending_clear();
        $summary = 'เสื่อมสภาพ: สำเร็จ ' . $successN . ' รายการ';
        flash_set($summary, 'ok', $summary);
    }
    header('Location: ' . $bulkReturnUrl);
    exit;
}

// ---------------------------------------------------------------
// บันทึกการเข้า MA — เปลี่ยนสถานะเครื่อง (เครื่องเช่า/เครื่องสำรอง)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ma'])) {
    csrf_check(); require_can('ma');
    $code = trim($_POST['asset_code']);
    $a = qr("SELECT id, asset_code, factory_serial, product_id FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
    if (!$a) { flash_set("ไม่พบเครื่องรหัส $code", 'err'); header('Location: ' . BASE_URL . '/ma.php'); exit; }
    $maProductId = (int)$a['product_id'];

    $visited = dt_from_input($_POST['visited_at'] ?? '');
    $san = ma_sanitize_item_sets($_POST['ok_items'] ?? '', $_POST['replace_items'] ?? '', $_POST['repair_items'] ?? '');
    if ($san === null) {
        flash_set('รายการใน ✅ ปกติ / 🔄 เปลี่ยน / 🔧 ซ่อม ห้ามซ้ำกันข้ามช่อง', 'err');
        header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId);
        exit;
    }
    $sdErr = sd_image_validate_post($maProductId, $_POST);
    if ($sdErr !== '') {
        flash_set($sdErr, 'err');
        header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId);
        exit;
    }
    $okItems = $san['ok'];
    $repItems = $san['replace'];
    $fixItems = $san['repair'];
    // ผลรวมของรอบนี้: มีซ่อม > มีเปลี่ยน > ปกติ
    $result = $fixItems ? 'repair' : ($repItems ? 'replace' : ($okItems ? 'ok' : null));
    $fw      = resolve_fw_post_value($maProductId);
    $postedStatus = in_array($_POST['machine_status'] ?? '', ['rental', 'spare'], true) ? (string) $_POST['machine_status'] : 'rental';
    $remark = trim((string)($_POST['remark'] ?? ''));
    $rentAction = isset($_POST['rent_close_action']) ? (string)$_POST['rent_close_action'] : '';
    if (!in_array($rentAction, ['close_fg', 'retire', 'keep'], true)) {
        $rentAction = 'close_fg';
    }
    if ($postedStatus === 'spare') {
        $rentAction = 'keep';
    }
    $newStatus = $postedStatus;
    if ($rentAction === 'retire') {
        $newStatus = 'rental';
        $remark = rent_ensure_retire_remark($remark);
    }
    $roundAlloc = ma_round_lock_acquire((int) $a['id']);
    if (!$roundAlloc['ok']) {
        flash_set($roundAlloc['error'], 'err');
        header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId);
        exit;
    }
    $round = $roundAlloc['round'];
    $maRoundLock = $roundAlloc['lock_key'];

    try {
    q("INSERT INTO ma_records (asset_id,ma_round,visited_at,result,ok_items,replace_items,repair_items,fw_version,machine_status,remark,done_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)", 'iisssssssss',
      [$a['id'], $round, $visited, $result, $okItems ?: null, $repItems ?: null, $fixItems ?: null,
       $fw ?: null, $newStatus, $remark !== '' ? $remark : null, actor_name()]);

    $maRecordId = (int)db()->insert_id;
    $withdrawLines = ma_parse_withdraw_lines(
        (array)($_POST['ma_part_id'] ?? []),
        (array)($_POST['ma_part_qty'] ?? [])
    );
    $w = ['count' => 0];
    if ($withdrawLines) {
        $w = ma_withdraw_parts($maRecordId, (int)$a['id'], $a['asset_code'], $withdrawLines, actor_name());
        if (!$w['ok']) {
            q("DELETE FROM ma_records WHERE id=?", 'i', [$maRecordId]);
            flash_set($w['error'], 'err');
            header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId);
            exit;
        }
    }

    q("UPDATE assets SET status=? WHERE id=?", 'si', [$newStatus, $a['id']]);
    if ($fw !== '') q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$fw, $a['id']]);

    $flashMsg = "บันทึก MA ของ {$a['asset_code']} สำเร็จแล้ว — สถานะเครื่อง: " . status_th($newStatus);
    if (!empty($w['count'])) {
        $flashMsg .= ' · เบิกอะไหล่ ' . (int)$w['count'] . ' รายการ';
    }
    $sdRes = sd_image_apply_post((int) $a['id'], $maProductId, $_POST);
    if (!empty($sdRes['message'])) {
        $flashMsg .= ' · ' . $sdRes['message'];
    }
    if ($result === 'repair' && function_exists('line_notify_instant_enabled') && line_notify_instant_enabled('ma.repair_required') && date('Y-m-d', strtotime($visited)) === date('Y-m-d')) {
        line_notify_dispatch('ma.repair_required', [
            'asset_id'     => (int)$a['id'],
            'asset_code'   => (string)$a['asset_code'],
            'ma_round'     => (int)$round,
            'visited_at'   => $visited,
            'repair_items' => (string)$fixItems,
            'remark'       => $remark,
            'done_by'      => actor_name(),
            'entity_id'    => $maRecordId,
        ], [
            'dedup_key' => 'ma.repair_required:' . $maRecordId,
        ]);
    }
    if (ma_spare_skips_rent_sync($postedStatus)) {
        flash_set($flashMsg, 'ok', $flashMsg);
    } else {
    $rentRes = rent_apply_after_ma_save_for_asset($a, $rentAction, $repItems, $fixItems, $fw, $remark);
    // เสื่อมสภาพนับว่าสำเร็จเฉพาะตอนระบบเช่ารับแล้วจริง — ถ้าไม่รับ cron รอบหน้าจะ sync สถานะกลับ
    // ต้องขึ้นเป็นข้อความเตือน ไม่ใช่เขียวผ่านเหมือนเดิม ไม่งั้นคนบันทึกไม่รู้ว่าสถานะไม่เปลี่ยน
    $rentDone = $rentAction === 'retire'
        ? (!empty($rentRes['ok']) && in_array((string)($rentRes['code'] ?? ''), ['closed', 'already'], true))
        : !empty($rentRes['ok']);
    if ($rentAction === 'retire' && $rentDone && rent_mark_asset_retired((int)$a['id'])) {
        $flashMsg = "บันทึก MA ของ {$a['asset_code']} สำเร็จแล้ว — สถานะเครื่อง: " . status_th('retired')
            . (!empty($w['count']) ? ' · เบิกอะไหล่ ' . (int)$w['count'] . ' รายการ' : '');
    }
    $flashMsg .= ' · ' . $rentRes['message'];
    if ($rentAction === 'retire' && !$rentDone) {
        $flashMsg .= ' — สถานะเครื่องยังไม่เป็นเสื่อมสภาพ';
    }
    flash_set($flashMsg, $rentDone ? 'ok' : 'err', $flashMsg);
    }
    header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId); exit;
    } finally {
        ma_round_lock_release($maRoundLock);
    }
}

// ---------------------------------------------------------------
// แก้ไขรายการ MA
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_ma'])) {
    csrf_check(); require_can('ma');
    $mid = (int)$_POST['edit_id'];
    $rec = qr("SELECT m.asset_id, m.fw_version, a.product_id FROM ma_records m JOIN assets a ON a.id=m.asset_id WHERE m.id=?", 'i', [$mid])->fetch_assoc();
    if (!$rec) { flash_set('ไม่พบรายการ MA ที่จะแก้ไข', 'err'); header('Location: ' . BASE_URL . '/ma.php'); exit; }
    $maProductId = (int)$rec['product_id'];
    $visited = dt_from_input($_POST['visited_at'] ?? '');
    $san = ma_sanitize_item_sets($_POST['ok_items'] ?? '', $_POST['replace_items'] ?? '', $_POST['repair_items'] ?? '');
    if ($san === null) {
        flash_set('รายการใน ✅ ปกติ / 🔄 เปลี่ยน / 🔧 ซ่อม ห้ามซ้ำกันข้ามช่อง', 'err');
        header('Location: ' . BASE_URL . '/ma.php?edit=' . $mid);
        exit;
    }
    $sdErr = sd_image_validate_post($maProductId, $_POST);
    if ($sdErr !== '') {
        flash_set($sdErr, 'err');
        header('Location: ' . BASE_URL . '/ma.php?edit=' . $mid);
        exit;
    }
    $okItems = $san['ok'];
    $repItems = $san['replace'];
    $fixItems = $san['repair'];
    $result = $fixItems ? 'repair' : ($repItems ? 'replace' : ($okItems ? 'ok' : null));
    $fw = resolve_fw_post_value((int) $rec['product_id'], (string) ($rec['fw_version'] ?? ''));
    $remark = trim((string)($_POST['remark'] ?? ''));
    $rentAction = isset($_POST['rent_close_action']) ? (string)$_POST['rent_close_action'] : '';
    if (!in_array($rentAction, ['close_fg', 'retire', 'keep'], true)) {
        $rentAction = 'close_fg';
    }
    $postedStatus = in_array($_POST['machine_status'] ?? '', ['rental', 'spare'], true) ? (string) $_POST['machine_status'] : 'rental';
    if ($postedStatus === 'spare') {
        $rentAction = 'keep';
    }
    $newStatus = $postedStatus;
    if ($rentAction === 'retire') {
        $newStatus = 'rental';
        $remark = rent_ensure_retire_remark($remark);
    }
    q("UPDATE ma_records SET visited_at=?, result=?, ok_items=?, replace_items=?, repair_items=?, fw_version=?, machine_status=?, remark=?, versions_json=NULL WHERE id=?",
      'ssssssssi', [$visited, $result, $okItems ?: null, $repItems ?: null, $fixItems ?: null, $fw ?: null,
       $newStatus, $remark !== '' ? $remark : null, $mid]);
    if ($newStatus) q("UPDATE assets SET status=? WHERE id=?", 'si', [$newStatus, $rec['asset_id']]);
    if ($fw !== '') q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$fw, $rec['asset_id']]);
    $assetRow = qr('SELECT asset_code, factory_serial FROM assets WHERE id=?', 'i', [(int)$rec['asset_id']])->fetch_assoc();
    $withdrawLines = ma_parse_withdraw_edit_lines(
        (array)($_POST['ma_w_movement_id'] ?? []),
        (array)($_POST['ma_part_id'] ?? []),
        (array)($_POST['ma_part_qty'] ?? [])
    );
    $sync = ma_sync_withdrawals_on_edit(
        $mid,
        (int)$rec['asset_id'],
        (string)($assetRow['asset_code'] ?? ''),
        $withdrawLines,
        actor_name()
    );
    if (!$sync['ok']) {
        flash_set($sync['error'] ?? 'แก้ไขรายการเบิก MA ไม่สำเร็จ', 'err');
        header('Location: ' . BASE_URL . '/ma.php?edit=' . $mid);
        exit;
    }
    $sdRes = sd_image_apply_post((int) $rec['asset_id'], $maProductId, $_POST);
    $sdNote = !empty($sdRes['message']) ? ' · ' . $sdRes['message'] : '';
    if (ma_spare_skips_rent_sync($postedStatus)) {
        flash_set('แก้ไขรายการ MA เรียบร้อยแล้ว' . $sdNote, 'ok');
    } else {
    $rentRes = rent_apply_after_ma_save_for_asset($assetRow ?: [], $rentAction, $repItems, $fixItems, $fw, $remark);
    $rentDone = $rentAction === 'retire'
        ? (!empty($rentRes['ok']) && in_array((string)($rentRes['code'] ?? ''), ['closed', 'already'], true))
        : !empty($rentRes['ok']);
    if ($rentAction === 'retire' && $rentDone) {
        rent_mark_asset_retired((int)$rec['asset_id']);
    }
    // ย้อนจากเสื่อมสภาพเป็นซ่อมแล้ว — ต้องเปลี่ยนสถานะเครื่องกลับจาก retired เป็น rental
    if ($rentAction === 'close_fg' && $rentDone) {
        $curSt = qr('SELECT status FROM assets WHERE id=? LIMIT 1', 'i', [(int)$rec['asset_id']])->fetch_assoc();
        if ($curSt && (string)$curSt['status'] === 'retired') {
            q('UPDATE assets SET status=? WHERE id=?', 'si', ['rental', (int)$rec['asset_id']]);
            q('INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
              'isss', [(int)$rec['asset_id'], 'in', 'แก้ไข MA: ย้อนเสื่อมสภาพ → ซ่อมแล้ว (retired → rental)', actor_name() ?: 'system']);
        }
    }
    $flashMsg = 'แก้ไขรายการ MA เรียบร้อยแล้ว' . $sdNote . ' · ' . $rentRes['message'];
    if ($rentAction === 'retire' && !$rentDone) {
        $flashMsg .= ' — สถานะเครื่องยังไม่เป็นเสื่อมสภาพ';
    }
    flash_set($flashMsg, $rentDone ? 'ok' : 'err', $flashMsg);
    }
    header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId); exit;
}
// ---------------------------------------------------------------
// ลบรายการ MA
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ma'])) {
    csrf_check(); require_can('ma');
    $mid = (int)$_POST['ma_id'];
    $rec = qr("SELECT m.asset_id, m.machine_status, m.remark, a.asset_code, a.factory_serial, a.status ast_status
               FROM ma_records m JOIN assets a ON a.id = m.asset_id WHERE m.id=?", 'i', [$mid])->fetch_assoc();
    if ($rec && ma_withdrawal_count($mid) > 0) {
        $rb = ma_rollback_withdrawals($mid, actor_name());
        if (!$rb['ok']) {
            flash_set($rb['error'], 'err');
            $back = (isset($_POST['back']) && $_POST['back'] !== '') ? $_POST['back']
                  : BASE_URL . '/asset.php?id=' . $rec['asset_id'];
            header('Location: ' . $back);
            exit;
        }
    }
    // ถ้ารายการที่ลบเป็นเสื่อมสภาพ ย้อนสถานะระบบเช่ากลับเป็น finished goods
    $delRetireReverted = false;
    if ($rec) {
        $wasRetire = (string)($rec['ast_status'] ?? '') === 'retired'
            || mb_strpos(trim((string)($rec['remark'] ?? '')), 'เสื่อมสภาพ') === 0;
        if ($wasRetire) {
            $revertRes = rent_apply_after_ma_save_for_asset(
                ['asset_code' => (string)$rec['asset_code'], 'factory_serial' => (string)($rec['factory_serial'] ?? '')],
                'close_fg', null, null, '', ''
            );
            if (!empty($revertRes['ok']) && in_array((string)($revertRes['code'] ?? ''), ['closed'], true)) {
                $delRetireReverted = true;
            }
        }
    }
    q("DELETE FROM ma_records WHERE id=?", 'i', [$mid]);
    if ($rec) {
        recompute_asset_status_from_ma((int) $rec['asset_id']);
        recompute_asset_fw((int) $rec['asset_id']);
    }
    $delFlash = 'ลบรายการ MA เรียบร้อยแล้ว';
    if ($delRetireReverted) {
        $delFlash .= ' · ย้อนสถานะระบบเช่ากลับเป็นคลังพร้อมเช่าแล้ว';
    }
    flash_set($delFlash);
    $back = (isset($_POST['back']) && $_POST['back'] !== '') ? $_POST['back']
          : ($rec ? BASE_URL . '/asset.php?id=' . $rec['asset_id'] : BASE_URL . '/ma.php');
    header('Location: ' . $back); exit;
}

// ---------------------------------------------------------------
// ลบรายการ MA ที่บันทึกจากระบบเช่า (tbl_product_ma)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_rent_ma'])) {
    csrf_check(); require_can('ma');
    $rentMaId = (int)$_POST['rent_ma_id'];
    $rentSn   = trim((string)($_POST['rent_sn'] ?? ''));

    $rentDel = rent_delete_ma_record($rentMaId, $rentSn);
    if ($rentDel['ok']) {
        // sync สถานะฝั่ง production — อ่านจากระบบเช่าอีกครั้งเพื่อให้ assets.status ตรงกัน
        $sn = $rentDel['sn'] ?: $rentSn;
        if ($sn !== '') {
            $assetRow = qr('SELECT id FROM assets WHERE UPPER(TRIM(asset_code)) = ? LIMIT 1',
                           's', [strtoupper(trim($sn))])->fetch_assoc();
            if ($assetRow) {
                asset_status_sync_one((int)$assetRow['id'], true);
            }
        }
        flash_set($rentDel['message']);
    } else {
        flash_set($rentDel['message'], 'err');
    }
    $back = (isset($_POST['back']) && $_POST['back'] !== '') ? $_POST['back'] : BASE_URL . '/ma.php';
    header('Location: ' . $back); exit;
}

require __DIR__ . '/includes/layout.php';

$productId  = (int)(isset($_GET['product']) ? $_GET['product'] : 0);
$recAssetId = (int)(isset($_GET['record']) ? $_GET['record'] : 0);
$recAsset = $recAssetId ? qr("SELECT id, asset_code, status, product_id, produced_at, current_fw_version FROM assets WHERE id=?", 'i', [$recAssetId])->fetch_assoc() : null;
$editId = (int)(isset($_GET['edit']) ? $_GET['edit'] : 0);
$editRec = $editId ? qr("SELECT m.*, a.asset_code, a.status ast_status, a.product_id, a.produced_at, a.current_fw_version
                         FROM ma_records m JOIN assets a ON a.id=m.asset_id WHERE m.id=?", 'i', [$editId])->fetch_assoc() : null;
$formOpen = ($recAsset || $editRec) ? true : false;

// ห้าม browser cache หน้าแก้ไข — ป้องกัน bfcache แสดงฟอร์มเก่าหลังบันทึกแล้วกดแก้ไขซ้ำ
if ($editId > 0 && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

// ถ้าเข้าแบบแก้ไข / บันทึกจากเครื่อง — ดึง product_id ของรุ่นนั้นให้อัตโนมัติ
if ($productId <= 0 && $editRec) {
    $productId = (int)$editRec['product_id'];
}
if ($productId <= 0 && $recAsset) {
    $productId = (int)$recAsset['product_id'];
}

// ─ ขั้นที่ 1: เลือกรุ่นสินค้า ─────────────────────────────────────────────────
if ($productId <= 0) {
    $products = qr("SELECT p.id, p.name, p.icon_path, p.product_code,
                           (SELECT COUNT(*) FROM assets a WHERE a.product_id=p.id) assets_n,
                           (SELECT COUNT(*) FROM ma_records m
                            JOIN assets a2 ON a2.id=m.asset_id
                            WHERE a2.product_id=p.id) ma_n
                    FROM products p
                    WHERE p.is_active=1
                    ORDER BY ma_n DESC, p.name ASC");

    page_header('บันทึก MA — เลือกรุ่นสินค้า');
    echo rent_bulk_retire_pending_alert_html();
    $sdCounts = sd_image_queue_counts_by_product();
    $sdAll = 0;
    foreach ($sdCounts as $n) {
        $sdAll += (int) $n;
    }
    if ($sdAll > 0) {
        echo '<p class="ma-sd-banner">มี ' . number_format($sdAll)
            . ' เครื่องเช่า/สำรองที่ยังต้องดูบอร์ดหรือเขียน SD image ชุด AL-26-10-14 — '
            . '<a href="' . h(BASE_URL . '/sd_image_check.php') . '">เปิดคิว</a></p>';
    }
    $rentMaCounts = rent_wait_ma_counts_by_product();
    if ($rentMaCounts['ok'] && $rentMaCounts['total_matched'] > 0) {
        echo '<p class="muted" style="margin:0 0 12px">มี ' . number_format($rentMaCounts['total_matched'])
            . ' เครื่องรอ MA จากระบบเช่า — เลือกรุ่นสินค้าเพื่อดูคิวและบันทึก</p>';
    } elseif (!$rentMaCounts['ok']) {
        echo '<p class="muted" style="margin:0 0 12px">เชื่อมระบบเช่าไม่ได้ — คิวรอ MA จะไม่แสดง (' . h($rentMaCounts['error']) . ')</p>';
    }
    echo '<div style="margin-bottom:14px">' . rent_bulk_retire_btn_html() . '</div>';
    echo rent_bulk_retire_panel_html();
    ?>
    <p class="muted" style="margin:0 0 12px">เลือกรุ่นสินค้าเพื่อดูประวัติ MA และบันทึกรายการของรุ่นนั้น</p>
    <div style="margin-bottom:12px">
      <input type="text" id="prod-filter" placeholder="พิมพ์กรองชื่อรุ่น…" style="width:min(320px,100%)">
    </div>
    <div class="grid-products" id="prod-grid">
      <?php while ($p = $products->fetch_assoc()) {
          $waitN = ($rentMaCounts['ok'] && isset($rentMaCounts['counts'][(int)$p['id']]))
              ? (int)$rentMaCounts['counts'][(int)$p['id']]
              : 0;
          $sdN = (int) ($sdCounts[(int) $p['id']] ?? 0);
          ?>
        <a class="pcard" href="<?= BASE_URL ?>/ma.php?product=<?= (int)$p['id'] ?>"
           data-name="<?= h(mb_strtolower($p['name'] . ' ' . $p['product_code'])) ?>"
           style="text-decoration:none; color:inherit">
          <?= img_tag($p['icon_path'], $p['name'], 'thumb-lg') ?>
          <div class="pname"><?= h($p['name']) ?></div>
          <div class="pmeta">
            <?= h($p['product_code']) ?><br>
            <?= number_format((int)$p['assets_n']) ?> เครื่อง ·
            <b><?= number_format((int)$p['ma_n']) ?></b> รายการ MA
            <?php if ($waitN > 0) { ?><br><b style="color:var(--primary)">รอ MA <?= number_format($waitN) ?></b><?php } ?>
            <?php if ($sdN > 0) { ?><br><b style="color:#b45309">รอเขียน image <?= number_format($sdN) ?></b><?php } ?>
          </div>
        </a>
      <?php } ?>
    </div>
    <script>
    document.getElementById('prod-filter').addEventListener('input', function(){
      var q = this.value.trim().toLowerCase();
      document.querySelectorAll('#prod-grid .pcard').forEach(function(el){
        el.style.display = (!q || (el.dataset.name || '').indexOf(q) !== -1) ? '' : 'none';
      });
    });
    </script>
    <?php
    page_footer();
    exit;
}

// ─ ขั้นที่ 2: ฟอร์ม + รายการ MA ของรุ่นที่เลือก ───────────────────────────────
$product = qr("SELECT id, name, icon_path, product_code FROM products WHERE id=? AND is_active=1", 'i', [$productId])->fetch_assoc();
if (!$product) {
    flash_set('ไม่พบรุ่นสินค้า', 'err');
    header('Location: ' . BASE_URL . '/ma.php');
    exit;
}
$maShowSnippets = product_show_snippets($productId);

$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$per = 50;
$off = ($page - 1) * $per;

$mq = trim(isset($_GET['mq']) ? $_GET['mq'] : '');       // หมายเลขเครื่อง
$mby = trim(isset($_GET['mby']) ? $_GET['mby'] : '');     // ผู้บันทึก
$mr = trim(isset($_GET['mr']) ? $_GET['mr'] : '');       // legacy รวมรายการ
$mok = trim(isset($_GET['mok']) ? $_GET['mok'] : '');
$mrepl = trim(isset($_GET['mrepl']) ? $_GET['mrepl'] : '');
$mrepair = trim(isset($_GET['mrepair']) ? $_GET['mrepair'] : '');
$mfrom = ma_list_parse_date_param($_GET['mfrom'] ?? '');
$mto = ma_list_parse_date_param($_GET['mto'] ?? '');
$mround = trim(isset($_GET['mround']) ? $_GET['mround'] : ''); // รอบ MA
$mkind = trim(isset($_GET['mkind']) ? $_GET['mkind'] : ''); // ประเภทเครื่องตอน MA
if (!in_array($mkind, ['', 'rental', 'spare'], true)) {
    $mkind = '';
}
$msort = trim(isset($_GET['msort']) ? $_GET['msort'] : 'date_desc');

$maWhere = ['a.product_id=?'];
$maTypes = 'i';
$maParams = [$productId];
if ($mq !== '') { $maWhere[] = 'a.asset_code LIKE ?'; $maTypes .= 's'; $maParams[] = "%$mq%"; }
if ($mby !== '') { $maWhere[] = 'm.done_by LIKE ?'; $maTypes .= 's'; $maParams[] = "%$mby%"; }
if ($mfrom !== '') {
    $maWhere[] = 'm.visited_at >= ?';
    $maTypes .= 's';
    $maParams[] = $mfrom . ' 00:00:00';
}
if ($mto !== '') {
    $maWhere[] = 'm.visited_at <= ?';
    $maTypes .= 's';
    $maParams[] = $mto . ' 23:59:59';
}
// mround ไม่ใช้กรองใน SQL อีก — คำนวณรอบรวมหลัง merge กับรายการระบบเช่าก่อนจึงกรองทีเดียว
ma_list_apply_item_sql_filters($maWhere, $maTypes, $maParams, $mok, $mrepl, $mrepair, $mr);
$maSortMap = [
    'date_desc'     => 'm.visited_at DESC, a.asset_code DESC, m.id DESC',
    'date_asc'      => 'm.visited_at ASC, a.asset_code ASC, m.id ASC',
    'round_desc'    => 'm.ma_round DESC, m.visited_at DESC, m.id DESC',
    'round_asc'     => 'm.ma_round ASC, m.visited_at DESC, m.id DESC',
    'asset_asc'     => 'a.asset_code ASC, m.visited_at DESC, m.id DESC',
    'asset_desc'    => 'a.asset_code DESC, m.visited_at DESC, m.id DESC',
    'ok_asc'        => 'm.ok_items ASC, m.visited_at DESC, m.id DESC',
    'ok_desc'       => 'm.ok_items DESC, m.visited_at DESC, m.id DESC',
    'replace_asc'   => 'm.replace_items ASC, m.visited_at DESC, m.id DESC',
    'replace_desc'  => 'm.replace_items DESC, m.visited_at DESC, m.id DESC',
    'repair_asc'    => 'm.repair_items ASC, m.visited_at DESC, m.id DESC',
    'repair_desc'   => 'm.repair_items DESC, m.visited_at DESC, m.id DESC',
    'fw_asc'        => 'm.fw_version ASC, m.visited_at DESC, m.id DESC',
    'fw_desc'       => 'm.fw_version DESC, m.visited_at DESC, m.id DESC',
    'by_asc'        => 'm.done_by ASC, m.visited_at DESC, m.id DESC',
    'by_desc'       => 'm.done_by DESC, m.visited_at DESC, m.id DESC',
];
$maOrder = isset($maSortMap[$msort]) ? $maSortMap[$msort] : $maSortMap['date_desc'];
$maW = 'WHERE ' . implode(' AND ', $maWhere);

// ─── รายการของเรา ───
// ดึงทั้งชุดของรุ่นนี้ (ไม่ LIMIT ใน SQL) เพราะต้องเอาไปรวมกับรายการจากระบบเช่าก่อน
// แล้วค่อยเรียงและแบ่งหน้าพร้อมกัน — จำนวนต่อรุ่นหลักร้อยถึงพันแถว ไม่หนัก
$ourRows = [];
$res = qr("SELECT m.*, a.asset_code, a.id asset_id, a.status ast_status FROM ma_records m
           JOIN assets a ON a.id=m.asset_id
           $maW", $maTypes, $maParams);
while ($r = $res->fetch_assoc()) {
    $r['src'] = 'own';
    $ourRows[] = $r;
}

// ─── รายการจากระบบเช่า (ช่างบางคน MA ที่นั่นอย่างเดียว และงานเก่าก็อยู่ที่นั่นทั้งหมด) ───
$maShowRent = !isset($_GET['mrent']) || $_GET['mrent'] !== '0';
$rentRows = [];
$rentTotal = 0;
if ($maShowRent) {
    // คีย์กันซ้ำ: งานที่เราปิดจากระบบเรา จะถูกเขียนลงระบบเช่าด้วยอยู่แล้ว
    $ourKeys = [];
    foreach ($ourRows as $r) {
        $ourKeys[strtoupper(trim((string) $r['asset_code'])) . '|' . substr((string) $r['visited_at'], 0, 10)] = true;
    }
    try {
        $rentRows = rent_ma_records_for_product($productId, $ourKeys);
    } catch (Throwable $e) {
        $rentRows = [];   // ระบบเช่าล่ม — รายการของเรายังแสดงได้ตามปกติ
    }
    // ใช้ตัวกรองชุดเดียวกับฝั่งเรา (ยกเว้น mround — คำนวณหลัง merge)
    $rentItemFilter = ($mr !== '' || $mok !== '' || $mrepl !== '' || $mrepair !== '');
    if ($mq !== '' || $mby !== '' || $rentItemFilter) {
        $rentRows = array_values(array_filter($rentRows, function ($r) use ($mq, $mby, $mok, $mrepl, $mrepair, $mr) {
            if ($mq !== '' && mb_stripos((string) $r['asset_code'], $mq) === false) { return false; }
            if ($mby !== '' && mb_stripos((string) $r['done_by'], $mby) === false) { return false; }
            if (!ma_list_rent_passes_item_filters($r, $mok, $mrepl, $mrepair, $mr)) { return false; }
            return true;
        }));
    }
    $rentTotal = count($rentRows);
}

$allRows = array_merge($ourRows, $rentRows);

// คำนวณรอบ MA รวมจากทั้งสองระบบ — นับต่อเครื่องเรียงตามวันที่
$_roundGroups = [];
foreach ($allRows as $idx => $row) {
    $code = strtoupper(trim((string)($row['asset_code'] ?? '')));
    if ($code !== '') { $_roundGroups[$code][] = $idx; }
}
foreach ($_roundGroups as $_indices) {
    usort($_indices, function ($a, $b) use ($allRows) {
        $cmp = strcmp((string)($allRows[$a]['visited_at'] ?? ''), (string)($allRows[$b]['visited_at'] ?? ''));
        return $cmp !== 0 ? $cmp : ($a - $b);
    });
    $n = 1;
    foreach ($_indices as $idx) {
        $allRows[$idx]['ma_round'] = $n++;
    }
}
unset($_roundGroups);

// กรองวันที่หลัง merge (ครอบคลุมรายการระบบเช่าที่ไม่ผ่าน SQL ฝั่งเรา)
if ($mfrom !== '' || $mto !== '') {
    $allRows = array_values(array_filter($allRows, function ($r) use ($mfrom, $mto) {
        $day = substr((string) ($r['visited_at'] ?? ''), 0, 10);
        if ($day === '' || $day === '0000-00-00') {
            return false;
        }
        if ($mfrom !== '' && $day < $mfrom) {
            return false;
        }
        if ($mto !== '' && $day > $mto) {
            return false;
        }
        return true;
    }));
}

// กรองรอบ MA (mround) หลังคำนวณรอบรวมแล้ว — ครอบคลุมทั้งรายการของเราและระบบเช่า
if ($mround !== '') {
    $allRows = array_values(array_filter($allRows, function ($r) use ($mround) {
        return mb_stripos((string)($r['ma_round'] ?? ''), $mround) !== false;
    }));
}

// กรองประเภทเครื่อง (เช่า/สำรอง) หลัง merge — ใช้ logic เดียวกับป้ายในตาราง
if ($mkind === 'rental' || $mkind === 'spare') {
    $allRows = array_values(array_filter($allRows, function ($r) use ($mkind) {
        return ma_list_machine_kind($r) === $mkind;
    }));
}

$total = count($allRows);
$ownTotal = count(array_filter($allRows, function ($r) { return ($r['src'] ?? '') !== 'rent'; }));
$pages = max(1, (int)ceil($total / $per));

// ─── เรียงตามหัวคอลัมน์ที่เลือก (แทน ORDER BY เดิม) ───
$maSortPhp = [
    'date'    => 'visited_at',  'round'  => 'ma_round',      'asset' => 'asset_code',
    'ok'      => 'ok_items',    'replace' => 'replace_items', 'repair' => 'repair_items',
    'fw'      => 'fw_version',  'by'     => 'done_by',
];
$sortKey = 'visited_at';
$sortDir = -1;
if (preg_match('/^(.*)_(asc|desc)$/', $msort, $mm) && isset($maSortPhp[$mm[1]])) {
    $sortKey = $maSortPhp[$mm[1]];
    $sortDir = $mm[2] === 'asc' ? 1 : -1;
}
usort($allRows, function ($x, $y) use ($sortKey, $sortDir) {
    $a = (string) ($x[$sortKey] ?? '');
    $b = (string) ($y[$sortKey] ?? '');
    $c = ($sortKey === 'ma_round') ? ((int) $a <=> (int) $b) : strcmp($a, $b);
    if ($c !== 0) { return $c * $sortDir; }
    // ตัวตัดสินรอง: ใหม่สุดก่อนเสมอ ไม่ว่าจะเรียงด้วยคอลัมน์ไหน
    return strcmp((string) ($y['visited_at'] ?? ''), (string) ($x['visited_at'] ?? ''));
});
$recentMA = array_slice($allRows, $off, $per);
$rentCloseMap = [];
if (function_exists('rent_ma_close_status_map')) {
    $sns = [];
    foreach ($recentMA as $m) {
        $sns[] = (string) ($m['asset_code'] ?? '');
    }
    try {
        $rentCloseMap = rent_ma_close_status_map($sns);
    } catch (Throwable $e) {
        $rentCloseMap = [];
    }
}
foreach ($recentMA as $i => $m) {
    if (trim((string) ($m['ma_status'] ?? '')) !== '') {
        continue;
    }
    $sn = function_exists('rent_normalize_sn')
        ? rent_normalize_sn($m['asset_code'] ?? '')
        : strtoupper(trim((string) ($m['asset_code'] ?? '')));
    $key = $sn . '|' . substr((string) ($m['visited_at'] ?? ''), 0, 10);
    $recentMA[$i]['rent_close_status'] = $rentCloseMap[$key] ?? '';
}

$maListUrl = BASE_URL . '/ma.php?product=' . $productId;
$maClearUrl = $maListUrl;
$maListShowFw = product_show_fw($productId);

page_header('บันทึก MA — ' . $product['name'] . ' (' . number_format($total) . ')');
echo rent_bulk_retire_pending_alert_html();
echo rent_wait_ma_panel_html($productId);
if (sd_image_slug_for_product($productId) !== '') {
    $sdQ = sd_image_queue('queue', $productId, '');
    $sdN = (int) ($sdQ['counts']['queue'] ?? 0);
    if ($sdN > 0) {
        echo '<p class="ma-sd-banner">มี ' . number_format($sdN)
            . ' เครื่องเช่า/สำรองของรุ่นนี้ที่ยังต้องดูบอร์ดหรือเขียน SD image — '
            . '<a href="' . h(BASE_URL . '/sd_image_check.php?product=' . $productId) . '">เปิดคิว</a></p>';
    }
}
require __DIR__ . '/includes/list_search.php';
?>
<?php if (can('ma')) { $ea = $editRec; $maProductPrefill = $ea ? ['fw_version' => '', 'remark' => ''] : ma_last_product_prefill($productId); ?>
<div class="ma-section-head">
  <?= img_tag($product['icon_path'], $product['name'], 'thumb') ?>
  <div class="ma-section-head-info">
    <h2 style="margin:0">บันทึกการเข้า MA</h2>
    <div class="muted"><?= h($product['name']) ?> · <?= h($product['product_code']) ?> · <?= number_format($total) ?> รายการ MA
      · <a href="<?= BASE_URL ?>/ma.php">← เลือกรุ่นอื่น</a></div>
  </div>
</div>
<?php } else { ?>
<p class="muted" style="margin-bottom:10px">
  <?= h($product['name']) ?> · <?= h($product['product_code']) ?> · <?= number_format($total) ?> รายการ MA
  · <a href="<?= BASE_URL ?>/ma.php">← เลือกรุ่นอื่น</a>
</p>
<?php } ?>

<script src="<?= BASE_URL ?>/assets/ma-snippets.js?v=<?= @filemtime(__DIR__ . '/assets/ma-snippets.js') ?: time() ?>"></script>
<script>
(function(){
  window.maOpenRowModal = function(tr){
    if (!tr || !tr.dataset) return;
    var d = tr.dataset;
    var escHtml = function(s){
      var el = document.createElement('div');
      el.textContent = s == null ? '' : s;
      return el.innerHTML;
    };
    var dl = document.getElementById('ma-detail-dl');
    if (dl) {
      var rows = [
        ['วันที่เข้า MA', d.date || '-', false],
        ['รอบ MA', d.round || '-', false],
        ['รหัสเครื่อง', d.asset || d.code || '-', false],
        ['สถานะเครื่อง', d.machineKind || '-', false],
        ['✅ ใช้งานได้ปกติ', d.ok || '-', true],
        ['🔄 เปลี่ยนอะไหล่', d.replaceDisp || '-', true],
        ['🔩 อะไหล่ที่เบิก (MA)', d.partsWithdraw || '-', true],
        ['🔧 ซ่อม', d.repairDisp || '-', true],
        ['ผลต่อระบบเช่า', d.rentClose || '-', false],
      ];
      if (MA_SHOW_FW) {
        rows.push(['Firmware หลังตรวจ', d.fw || '-', false]);
      }
      rows.push(['ผู้บันทึก', d.by || '-', false]);
      rows.push(['หมายเหตุ', d.remark || '-', false]);
      dl.innerHTML = rows.map(function(r){
        var val = r[1];
        var dd = r[2] && val && val !== '-' && val.indexOf('\n') >= 0
          ? val.split('\n').map(escHtml).join('<br>')
          : escHtml(val);
        return '<div class="ma-detail-row"><dt>' + escHtml(r[0]) + '</dt><dd>' + dd + '</dd></div>';
      }).join('');
    }
    // ส่วนคำสั่งมีใน popup เฉพาะรุ่นที่เปิดแผงคำสั่งไว้
    if (document.getElementById('ma-modal-sn-serial')) {
      window.resetMaCopyButtonsInScope('ma-modal-sn');
      window.maFillSnippets('ma-modal-sn', {
        code: d.code || '',
        replace: d.replace || '',
        repair: d.repair || '',
        fw: d.fw || '',
        remark: d.remark || '',
        rentClose: d.rentClose || ''
      });
    }
    var titleEl = document.getElementById('ma-detail-modal-title');
    if (titleEl) {
      titleEl.textContent = 'รายละเอียด MA — ' + (d.asset || d.code || '');
    }
    document.getElementById('ma-detail-overlay').hidden = false;
  };
})();
</script>

<?php if (can('ma')) { ?>
<p class="muted" style="margin-bottom:10px">การบันทึก MA จะเปลี่ยนสถานะเครื่องเป็น "เครื่องเช่า" หรือ "เครื่องสำรอง" ตามที่เลือก</p>
<div class="ma-action-btns" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px">
<button type="button" class="btn" id="ma-add-btn" onclick="document.getElementById('ma-form-wrap').hidden=false; this.hidden=true;" <?= $formOpen ? 'hidden' : '' ?>>➕ เพิ่มรายการ MA</button>
<?= rent_bulk_retire_btn_html() ?>
</div>
<?= rent_bulk_retire_panel_html($productId) ?>
<div id="ma-form-wrap" <?= $formOpen ? '' : 'hidden' ?>>
<h3 style="margin:4px 0 10px" class="h-with-icon"><?= ui_icon_html('edit', 15, 'h-svg') ?><span><?= $ea ? 'แก้ไขรายการ MA' : 'เพิ่มรายการ MA ใหม่' ?></span></h3>
<?php
$maShowFw = product_show_fw($productId);
$maFwOpts = $maShowFw ? effective_fw_options($productId) : [];
$maFwMode = effective_fw_input_mode($productId);
$maMachineStatus = 'rental';
if ($ea) {
    $maMachineStatus = in_array($ea['machine_status'] ?? '', ['rental', 'spare'], true)
        ? (string) $ea['machine_status']
        : (in_array($ea['ast_status'] ?? '', ['rental', 'spare'], true) ? (string) $ea['ast_status'] : 'rental');
} elseif ($recAsset && in_array((string) ($recAsset['status'] ?? ''), ['rental', 'spare'], true)) {
    $maMachineStatus = (string) $recAsset['status'];
}
$maRentAction = $maMachineStatus === 'spare' ? 'keep' : 'close_fg';
$maSdInit = ['tracked' => false];
$sdSrc = null;
if ($ea) {
    $sdSrc = [
        'id'                  => (int) ($ea['asset_id'] ?? 0),
        'product_id'          => (int) ($ea['product_id'] ?? $productId),
        'asset_code'          => (string) ($ea['asset_code'] ?? ''),
        'status'              => (string) ($ea['ast_status'] ?? ''),
        'produced_at'         => (string) ($ea['produced_at'] ?? ''),
        'current_fw_version'  => (string) ($ea['current_fw_version'] ?? ($ea['fw_version'] ?? '')),
    ];
} elseif ($recAsset) {
    $sdSrc = $recAsset;
}
if ($sdSrc) {
    $maSdInit = sd_image_ajax_payload(sd_image_info($sdSrc, (string) $product['name']));
}
?>
<div class="<?= $maShowSnippets ? 'ma-page-grid' : '' ?>">
  <div class="ma-form-col">
    <form method="post" class="formgrid form-wide ma-formgrid<?= $maMachineStatus === 'spare' ? ' ma-spare-mode' : '' ?>" id="ma-form">
      <?= csrf_field() ?>
      <?php if ($ea) { ?><input type="hidden" name="edit_ma" value="1"><input type="hidden" name="edit_id" value="<?= (int)$editId ?>"><?php } else { ?><input type="hidden" name="new_ma" value="1"><?php } ?>
      <input type="hidden" name="ok_items" id="h-ok">
      <input type="hidden" name="replace_items" id="h-replace">
      <input type="hidden" name="repair_items" id="h-repair">

      <label for="ma_code">รหัสเครื่อง</label>
      <div>
        <input type="text" name="asset_code" id="ma_code" class="asset-search" data-product="<?= (int)$productId ?>" value="<?= h($ea ? $ea['asset_code'] : ($recAsset ? $recAsset['asset_code'] : '')) ?>" <?= $ea ? 'readonly' : '' ?> required placeholder="พิมพ์เลือก S/N">
        <?php
        $maKnownProduced = $ea ? ($ea['produced_at'] ?? '') : ($recAsset ? ($recAsset['produced_at'] ?? '') : '');
        $maKnownAge = dt_age_text($maKnownProduced);
        $maKnownCode = $ea ? (string) ($ea['asset_code'] ?? '') : ($recAsset ? (string) ($recAsset['asset_code'] ?? '') : '');
        $maKnownSymptom = '';
        if ($maKnownCode !== '') {
            $symMap = rent_wait_ma_symptoms([$maKnownCode]);
            $maKnownSymptom = trim((string) ($symMap[rent_normalize_sn($maKnownCode)] ?? ''));
        }
        ?>
        <div class="ma-field-hint ma-asset-meta-hint">
          <div id="ma-age-hint" class="ma-asset-meta-age"<?= $maKnownAge === '' ? ' hidden' : '' ?>><?= $maKnownAge !== '' ? 'อายุเครื่อง <b>' . h($maKnownAge) . '</b>' : '' ?></div>
          <div id="ma-symptom-hint" class="ma-asset-meta-symptom"<?= $maKnownSymptom === '' ? ' hidden' : '' ?>><?= $maKnownSymptom !== '' ? '<b>' . h($maKnownSymptom) . '</b>' : '' ?></div>
        </div>
      </div>

      <label for="ma_visited_at">วันเวลาเข้า MA</label>
      <input type="datetime-local" name="visited_at" id="ma_visited_at" value="<?= h(dt_for_input($ea ? $ea['visited_at'] : dt_now())) ?>">

      <label id="machine_status_label">สถานะเครื่อง</label>
      <div class="ma-field-stack">
        <div class="ma-choice-group" id="machine_status_group" role="group" aria-labelledby="machine_status_label">
          <input type="hidden" name="machine_status" id="machine_status" value="<?= h($maMachineStatus) ?>">
          <button type="button" class="ma-choice-btn ma-choice-btn-rental<?= $maMachineStatus === 'rental' ? ' is-active' : '' ?>" data-value="rental">เครื่องเช่า</button>
          <button type="button" class="ma-choice-btn ma-choice-btn-spare<?= $maMachineStatus === 'spare' ? ' is-active' : '' ?>" data-value="spare">เครื่องสำรอง</button>
        </div>
        <p id="ma-spare-rent-hint" class="muted ma-field-hint"<?= $maMachineStatus === 'spare' ? '' : ' hidden' ?>>เครื่องสำรองบันทึกเฉพาะระบบผลิต — ไม่ปิดคิวรอ MA ในระบบเช่า</p>
      </div>

      <label id="rent_close_action_label" class="ma-lbl-top">ผลต่อระบบเช่า</label>
      <div class="ma-field-stack" id="ma-rent-close-stack">
        <div class="ma-choice-group" id="rent_close_action_group" role="group" aria-labelledby="rent_close_action_label">
          <input type="hidden" name="rent_close_action" id="rent_close_action" value="<?= h($maRentAction) ?>">
          <button type="button" class="ma-choice-btn<?= $maRentAction === 'close_fg' ? ' is-active' : '' ?>" data-value="close_fg">ซ่อมแล้ว</button>
          <button type="button" class="ma-choice-btn ma-choice-btn-retire<?= $maRentAction === 'retire' ? ' is-active' : '' ?>" data-value="retire">เสื่อมสภาพ</button>
        </div>
        <ul class="ma-rent-hint muted">
          <li><strong>ซ่อมแล้ว</strong> — ปิดคิวรอ MA ในระบบเช่า · สรุปใน「สรุปส่งงานเช่า Office」= รายการซ่อมในเช่า</li>
          <li><strong>เสื่อมสภาพ</strong> — ปิดคิว Asset Retirement · กรอกแค่หมายเหตุ · ข้ามรายการตรวจ</li>
        </ul>
      </div>

      <label id="ma-sd-label" hidden>SD image</label>
      <div id="ma-sd-slot" class="ma-field-stack" hidden></div>

      <div id="ma-detail-fields" class="ma-detail-fields">
      <label class="ma-lbl-top">✅ ใช้งานได้ปกติ</label>
      <div class="ma-field" id="mf-ok"></div>

      <label class="ma-lbl-top">🔄 เปลี่ยนอะไหล่</label>
      <div class="ma-field" id="mf-replace"></div>

      <label class="ma-lbl-top">🔧 ซ่อม</label>
      <div class="ma-field" id="mf-repair"></div>

      <?php if (!$ea) { ?>
      <label class="ma-lbl-top">🔩 อะไหล่ที่เบิกในรอบ MA</label>
      <div class="ma-field ma-parts-section">
        <p class="muted ma-field-hint">ไม่บังคับ — เลือกเมื่อมีการเปลี่ยน/ซ่อมที่ใช้อะไหล่จริง (ตัดสต็อก Stock ช่างทันที)</p>
        <div id="ma-parts-fields"><div id="ma-parts-list"></div></div>
        <button type="button" class="btn btn-line btn-sm" id="ma-parts-add" style="margin-top:6px">➕ เพิ่มอะไหล่ที่เบิก</button>
      </div>
      <?php } elseif ($editId) { ?>
      <label class="ma-lbl-top">🔩 อะไหล่ที่เบิกในรอบ MA</label>
      <div class="ma-field ma-parts-section">
        <p class="muted ma-field-hint">แก้ไขจำนวนหรือเพิ่ม/ลบรายการได้ — บันทึกแล้วจะ sync Stock ช่างอัตโนมัติ</p>
        <div id="ma-parts-fields"><div id="ma-parts-list"></div></div>
        <button type="button" class="btn btn-line btn-sm" id="ma-parts-add" style="margin-top:6px">➕ เพิ่มอะไหล่ที่เบิก</button>
      </div>
      <?php } ?>

      <?php if ($maShowFw) { ?>
      <label for="ma_fw_input" class="ma-lbl-top">Firmware หลังตรวจ</label>
      <div class="ma-field-stack">
        <div id="ma-fw-slot" class="ma-fw-wrap">
          <?php $maFwVal = $ea ? (string)$ea['fw_version'] : (string)($maProductPrefill['fw_version'] ?? ''); ?>
          <input type="text" name="fw_version" id="ma_fw_input" class="ma-fw-fallback"
            value="<?= h($maFwVal) ?>"
            placeholder="พิมพ์หรือเลือกเวอร์ชัน Firmware"
            <?= $maFwOpts ? 'list="ma-fw-list"' : '' ?>>
          <?php if ($maFwOpts) { ?>
          <datalist id="ma-fw-list">
            <?php foreach ($maFwOpts as $fv) { ?><option value="<?= h($fv) ?>"><?php } ?>
          </datalist>
          <?php } ?>
        </div>
        <p class="muted ma-field-hint">ไม่บังคับ — บันทึกเวอร์ชัน FW หลังตรวจ/ซ่อม (แสดงในประวัติ MA)</p>
      </div>
      <?php } ?>
      </div><!-- /ma-detail-fields -->

      <label for="ma_remark" class="ma-lbl-top">หมายเหตุ</label>
      <textarea name="remark" id="ma_remark" class="field-note ma-remark" rows="2" placeholder="บันทึกเพิ่มเติม…"><?= h($ea ? $ea['remark'] : ($maProductPrefill['remark'] ?? '')) ?></textarea>

      <div class="full ma-form-actions">
        <button type="submit" id="ma-submit-btn"><?= $ea ? 'บันทึกการแก้ไข' : 'บันทึก MA' ?></button>
        <?php if ($ea) { ?><a class="btn btn-line" href="<?= h($maListUrl) ?>">ยกเลิก</a><?php } ?>
      </div>
    </form>
    <div id="ma-history" class="ma-history-box"></div>
  </div>

  <?php if ($maShowSnippets) { ?>
  <aside class="panel ma-snippets-panel<?= $maMachineStatus === 'spare' ? ' ma-spare-mode' : '' ?>" id="ma-snippets">
    <?= ma_snippets_inner_html('ma-sn') ?>
  </aside>
  <?php } ?>
</div>
</div><!-- /ma-form-wrap -->

<script>
<?php $maEditWithdrawLines = $editId ? ma_withdrawal_lines($editId) : []; ?>
var maEditMode = <?= $editRec ? 'true' : 'false' ?>;
var maShowSnippets = <?= $maShowSnippets ? 'true' : 'false' ?>;
var maEditWithdrawLines = <?= json_encode($maEditWithdrawLines, JSON_UNESCAPED_UNICODE) ?>;
var BASE = '<?= BASE_URL ?>';
var MA_FW_OPTS = <?= json_encode(array_values($maFwOpts), JSON_UNESCAPED_UNICODE) ?>;
var MA_FW_MODE = <?= json_encode($maFwMode, JSON_UNESCAPED_UNICODE) ?>;
var MA_SHOW_FW = <?= $maShowFw ? 'true' : 'false' ?>;
var MA_SD_INIT = <?= json_encode($maSdInit, JSON_UNESCAPED_UNICODE) ?>;
var itemPools = { ok: [], replace: [], repair: [] };
function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function normItem(s){ return (s == null ? '' : String(s)).trim(); }

function chipsInField(key){
  var box = document.getElementById('chips-' + key);
  if (!box) return {};
  var out = {};
  Array.prototype.forEach.call(box.children, function(c){
    if (c.dataset.v) out[c.dataset.v] = true;
  });
  return out;
}

function chipValues(key){
  return Object.keys(chipsInField(key));
}

function allChipValues(exceptKey){
  var out = {};
  FIELDS.forEach(function(k){
    if (k === exceptKey) return;
    var here = chipsInField(k);
    Object.keys(here).forEach(function(v){ out[v] = k; });
  });
  return out;
}

function refreshMaList(key){
  var wrap = document.querySelector('#mf-' + key + ' .ma-chip-dd');
  if (!wrap) return;
  var list = wrap.querySelector('.chip-dd-list');
  if (!list || list.hidden) return;
  var input = wrap.querySelector('.chip-dd-filter');
  showList(wrap, input ? input.value.trim().toLowerCase() : '', false);
}

function maDupMsg(val, where){
  var labels = { ok: '✅ ปกติ', replace: '🔄 เปลี่ยน', repair: '🔧 ซ่อม' };
  return 'รายการ "' + val + '" มีใน ' + (labels[where] || where) + ' แล้ว — ห้ามซ้ำข้ามช่อง';
}

function maFwValue(){
  var slot = document.getElementById('ma-fw-slot');
  var hid = slot ? slot.querySelector('input[data-chip-val]') : null;
  if (hid && hid.value.trim()) return hid.value.trim();
  var filt = slot ? slot.querySelector('.chip-dd-filter') : null;
  if (filt && filt.value.trim()) return filt.value.trim();
  var inp = document.getElementById('ma_fw_input');
  return inp ? inp.value.trim() : '';
}

function updateMaSnippets(){
  if (maShowSnippets) {
    window.maFillSnippets('ma-sn', {
      code: (document.getElementById('ma_code') || {}).value || '',
      replace: chipValues('replace').join(' , '),
      repair: chipValues('repair').join(' , '),
      fw: maFwValue(),
      remark: (document.getElementById('ma_remark') || {}).value || '',
      action: (document.getElementById('rent_close_action') || {}).value || ''
    });
  }
  if (typeof syncMaSpareMode === 'function') syncMaSpareMode();
  if (typeof refreshMaSdFromFw === 'function') refreshMaSdFromFw();
}

var fwFb = document.getElementById('ma_fw_input');
if (fwFb) fwFb.addEventListener('input', updateMaSnippets);

// ---------- ฟิลด์รายการ (chip-dd — พิมพ์ค้นหาแล้วเลือกได้ทันที) ----------
var FIELDS = ['ok', 'replace', 'repair'];
function initField(key){
  var box = document.getElementById('mf-' + key);
  box.innerHTML = '<div class="ma-chip-dd chip-dd chip-dd-multi" data-key="' + key + '">'
    + '<div class="chip-dd-box">'
    + '<div class="chip-dd-chips" id="chips-' + key + '"></div>'
    + '<input type="text" class="chip-dd-filter" placeholder="พิมพ์หรือเลือก…" autocomplete="off">'
    + '<button type="button" class="chip-dd-btn" tabindex="-1" aria-label="เปิดรายการ">▾</button>'
    + '</div>'
    + '<div class="chip-dd-list ma-multi-list" hidden></div></div>';
  var wrap = box.querySelector('.ma-chip-dd');
  var filt = wrap.querySelector('.chip-dd-filter');
  filt.addEventListener('focus', function(){
    showList(wrap, filt.value.trim().toLowerCase(), true);
  });
  filt.addEventListener('input', function(){
    showList(wrap, filt.value.trim().toLowerCase(), false);
  });
  filt.addEventListener('keydown', function(e){
    if (e.key === 'Enter') {
      e.preventDefault();
      if (addChip(key, filt.value)) filt.value = '';
    }
  });
  wrap.querySelector('.chip-dd-box').addEventListener('click', function(e){
    if (!e.target.closest('.chip-dd-btn')) filt.focus();
  });
  document.getElementById('chips-' + key).addEventListener('click', function(e){
    if (!e.target.closest('.chip b')) return;
    e.target.closest('.chip').remove();
    refreshMaList(key);
    updateMaSnippets();
  });
}
function addChip(key, val, silent){
  val = normItem(val);
  if (!val) return false;
  var chips = document.getElementById('chips-' + key);
  if (!chips) return false;
  if (chipsInField(key)[val]) return false;
  var other = allChipValues(key);
  if (other[val]) {
    if (!silent) alert(maDupMsg(val, other[val]));
    return false;
  }
  var el = document.createElement('span');
  el.className = 'chip chip-pick chip-' + key;
  el.dataset.v = val;
  el.innerHTML = esc(val) + ' <b title="ลบ">×</b>';
  chips.appendChild(el);
  refreshMaList(key);
  updateMaSnippets();
  return true;
}
FIELDS.forEach(initField);

// ---------- อะไหล่ที่เบิกในรอบ MA ----------
var maPartsAll = [];
function maPartById(id){
  id = parseInt(id, 10) || 0;
  for (var i = 0; i < maPartsAll.length; i++) if (maPartsAll[i].id === id) return maPartsAll[i];
  return null;
}
function maPartThumbHtml(partId){
  var p = maPartById(partId);
  if (p && p.icon) return '<img src="' + esc(p.icon) + '" alt="" class="thumb-sm ma-part-opt-thumb" loading="lazy" onerror="this.classList.add(\'broken\')">';
  return '<span class="thumb-sm noimg ma-part-opt-thumb">—</span>';
}
function maPartStockHtml(p){
  if (!p || p.stock_qty == null) return '<span class="ma-part-opt-stock ma-part-opt-stock-na">ไม่มีใน Stock ช่าง</span>';
  var cls = 'ma-part-opt-stock';
  if (p.stock_qty <= 0) cls += ' ma-part-opt-stock-out';
  else if (p.stock_qty <= 5) cls += ' ma-part-opt-stock-low';
  var unit = p.unit ? ' ' + esc(p.unit) : '';
  return '<span class="' + cls + '">คงเหลือ ' + p.stock_qty + unit + '</span>';
}
function maPartSelectedChipHtml(partId){
  var p = maPartById(partId);
  if (!p) return '';
  var label = p.name + (p.unit ? ' (' + p.unit + ')' : '');
  // ป้ายสถานะคลังช่าง — อะไหล่ที่ไม่มีในคลังช่างจะเบิกไม่ได้ตอนบันทึก
  var st = p.stock_qty == null ? '<span class="ma-part-chip-stock is-na">ไม่มีในคลังช่าง</span>'
         : (p.stock_qty <= 0 ? '<span class="ma-part-chip-stock is-out">ของหมด</span>'
                             : '<span class="ma-part-chip-stock">เหลือ ' + p.stock_qty + '</span>');
  return '<span class="chip chip-pick ma-part-chip-sel">'
    + maPartThumbHtml(partId)
    + '<span class="ma-part-chip-label">' + esc(label) + '</span>' + st + '</span>';
}
function maPartOptsHtml(selId, filter){
  filter = (filter || '').toLowerCase();
  var list = maPartsAll.filter(function(p){ return partMatchesQuery(p, filter); });
  if (!list.length) {
    return '<div class="ma-part-opt-empty muted">ไม่พบอะไหล่' + (filter ? ' ที่ตรงกับ "' + esc(filter) + '"' : '') + '</div>';
  }
  return '<div class="ma-part-opt-items">' + list.map(function(p){
    var sel = p.id === selId ? ' is-selected' : '';
    return '<div class="ma-part-opt-row' + sel + '" data-pid="' + p.id + '" role="button" tabindex="0">'
      + maPartThumbHtml(p.id)
      + '<div class="ma-part-opt-body">'
      + '<span class="ma-part-opt-name">' + esc(p.name) + '</span>'
      + partCodeLineHtml(p)
      + maPartStockHtml(p)
      + '</div></div>';
  }).join('') + '</div>';
}
function maPartChipHtml(partId){
  var label = maPartById(partId);
  return '<div class="chip-dd ma-part-dd" data-pid="' + (partId || 0) + '">'
    + '<input type="hidden" name="ma_part_id[]" value="' + (partId || '') + '">'
    + '<div class="chip-dd-box">'
    + '<div class="chip-dd-chips">' + (label ? maPartSelectedChipHtml(partId) : '') + '</div>'
    + '<input type="text" class="chip-dd-filter ma-part-filter" placeholder="' + (label ? '' : 'ค้นหา — ชื่อ / รหัสอะไหล่') + '" autocomplete="off">'
    + '<button type="button" class="chip-dd-btn" tabindex="-1">▾</button>'
    + '</div>'
    + '<div class="chip-dd-list" hidden><div class="chip-dd-opts">' + maPartOptsHtml(partId, '') + '</div></div>'
    + '</div>';
}
function setMaPart(dd, partId){
  var hid = dd.querySelector('input[type=hidden][name="ma_part_id[]"]');
  hid.value = partId || '';
  dd.dataset.pid = partId || 0;
  var chips = dd.querySelector('.chip-dd-chips');
  chips.innerHTML = partId ? maPartSelectedChipHtml(partId) : '';
  var filt = dd.querySelector('.ma-part-filter');
  if (filt) {
    filt.value = '';
    filt.placeholder = partId ? '' : 'ค้นหา — ชื่อ / รหัสอะไหล่';
  }
  dd.querySelector('.chip-dd-opts').innerHTML = maPartOptsHtml(partId, '');
}
function maPartRowHtml(partId, qty, movementId){
  var mid = movementId ? parseInt(movementId, 10) : 0;
  return '<div class="ma-parts-row">'
    + '<input type="hidden" name="ma_w_movement_id[]" value="' + (mid > 0 ? mid : '0') + '">'
    + maPartChipHtml(partId)
    + '<input type="text" class="ma-parts-qty" name="ma_part_qty[]" value="' + esc(String(qty != null ? qty : 1)) + '" inputmode="decimal" autocomplete="off" placeholder="จำนวน" title="จำนวนที่เบิก">'
    + '<button type="button" class="btn-sm btn-line" onclick="this.closest(\'.ma-parts-row\').remove()">ลบ</button>'
    + '</div>';
}
function initMaEditWithdrawLines(lines){
  var list = document.getElementById('ma-parts-list');
  if (!list || !lines || !lines.length) return;
  list.innerHTML = '';
  lines.forEach(function(row){
    list.insertAdjacentHTML('beforeend', maPartRowHtml(row.part_id, row.qty, row.movement_id || 0));
  });
}
function addMaPartRow(){
  var list = document.getElementById('ma-parts-list');
  if (!list) return;
  list.insertAdjacentHTML('beforeend', maPartRowHtml(0, 1));
}
function initMaPartsPicker(){
  var addBtn = document.getElementById('ma-parts-add');
  if (!addBtn) return;
  fetch(BASE + '/ma.php?ajax=parts')
    .then(function(r){ return r.json(); })
    .then(function(d){
      maPartsAll = d.all_parts || [];
      if (typeof maEditWithdrawLines !== 'undefined' && maEditWithdrawLines.length) {
        initMaEditWithdrawLines(maEditWithdrawLines);
      }
    })
    .catch(function(){});
  addBtn.addEventListener('click', addMaPartRow);
}
document.addEventListener('focusin', function(e){
  if (!e.target.classList || !e.target.classList.contains('ma-part-filter')) return;
  var dd = e.target.closest('.ma-part-dd');
  if (!dd) return;
  var selId = parseInt(dd.dataset.pid, 10) || 0;
  dd.querySelector('.chip-dd-opts').innerHTML = maPartOptsHtml(selId, e.target.value.trim());
  dd.querySelector('.chip-dd-list').hidden = false;
  dd.classList.add('chip-dd-open');
});
document.addEventListener('click', function(e){
  var mbtn = e.target.closest('.ma-part-dd .chip-dd-btn');
  if (mbtn) {
    var dd = mbtn.closest('.ma-part-dd');
    var list = dd.querySelector('.chip-dd-list');
    var selId = parseInt(dd.dataset.pid, 10) || 0;
    var filtInp = dd.querySelector('.ma-part-filter');
    if (list.hidden) {
      dd.querySelector('.chip-dd-opts').innerHTML = maPartOptsHtml(selId, filtInp ? filtInp.value.trim() : '');
      list.hidden = false;
      dd.classList.add('chip-dd-open');
    } else { list.hidden = true; dd.classList.remove('chip-dd-open'); }
    return;
  }
  var mchip = e.target.closest('.ma-part-dd .ma-part-opt-row[data-pid]');
  if (mchip) {
    var dd2 = mchip.closest('.ma-part-dd');
    setMaPart(dd2, parseInt(mchip.dataset.pid, 10));
    dd2.querySelector('.chip-dd-list').hidden = true;
    dd2.classList.remove('chip-dd-open');
    return;
  }
});
document.addEventListener('input', function(e){
  if (!e.target.classList || !e.target.classList.contains('ma-part-filter')) return;
  var dd = e.target.closest('.ma-part-dd');
  var selId = parseInt(dd.dataset.pid, 10) || 0;
  dd.querySelector('.chip-dd-opts').innerHTML = maPartOptsHtml(selId, e.target.value.trim());
  dd.querySelector('.chip-dd-list').hidden = false;
  dd.classList.add('chip-dd-open');
});
initMaPartsPicker();

// FW — อัปเกรดเป็น chip-dd หลัง footer โหลด chipDdHtml (DOMContentLoaded)
function initMaFwField(){
  var slot = document.getElementById('ma-fw-slot');
  if (!slot || typeof chipDdHtml !== 'function') return;
  var cur = '';
  var inp = document.getElementById('ma_fw_input');
  if (inp) cur = inp.value;
  slot.innerHTML = chipDdHtml('fw_version', cur, MA_FW_OPTS, '', MA_FW_MODE || 'chip_single_free');
  initChipDd(slot);
  slot.addEventListener('click', function(){ setTimeout(updateMaSnippets, 0); });
  slot.addEventListener('keyup', updateMaSnippets, true);
  var fwFilt = slot.querySelector('.chip-dd-filter');
  if (fwFilt) fwFilt.addEventListener('input', updateMaSnippets);
  setTimeout(updateMaSnippets, 0);
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initMaFwField);
} else {
  initMaFwField();
}

<?php if ($editRec) { ?>
var maEditChipData = <?= json_encode([
  'ok' => ma_record_items_for_edit($editRec, 'ok_items', 'OK'),
  'replace' => ma_record_items_for_edit($editRec, 'replace_items', 'Replace'),
  'repair' => ma_record_items_for_edit($editRec, 'repair_items', 'Repair'),
], JSON_UNESCAPED_UNICODE) ?>;
function populateMaEditChips(){
  if (!maEditMode || !maEditChipData) return;
  FIELDS.forEach(function(k){
    var box = document.getElementById('chips-' + k);
    if (box) box.innerHTML = '';
    (maEditChipData[k] || []).forEach(function(v){ addChip(k, v, true); });
  });
  updateMaSnippets();
}
<?php } else { ?>
function populateMaEditChips(){}
<?php } ?>

document.addEventListener('click', function(e){
  var btn = e.target.closest('.ma-chip-dd .chip-dd-btn');
  if (btn) {
    var wrap = btn.closest('.ma-chip-dd');
    var list = wrap.querySelector('.chip-dd-list');
    var filt = wrap.querySelector('.chip-dd-filter');
    if (list.hidden) showList(wrap, filt ? filt.value.trim().toLowerCase() : '', true);
    else { list.hidden = true; wrap.classList.remove('chip-dd-open'); }
    return;
  }
  var tool = e.target.closest('.ma-pick-tools button[data-act]');
  if (tool) {
    e.preventDefault();
    e.stopPropagation();
    var wrapT = tool.closest('.ma-chip-dd');
    var keyT = wrapT.dataset.key;
    if (tool.dataset.act === 'all') {
      wrapT.querySelectorAll('.ma-pick-add[data-v]').forEach(function(row){
        addChip(keyT, row.dataset.v, true);
      });
    } else if (tool.dataset.act === 'clear') {
      var chipBox = document.getElementById('chips-' + keyT);
      if (chipBox) chipBox.innerHTML = '';
      refreshMaList(keyT);
      updateMaSnippets();
    }
    return;
  }
  var pick = e.target.closest('.ma-pick-add[data-v]');
  if (pick) {
    e.preventDefault();
    e.stopPropagation();
    addChip(pick.closest('.ma-chip-dd').dataset.key, pick.dataset.v);
    return;
  }
  if (e.target.closest('.ma-pick-row') || e.target.closest('.ma-pick-tools')) return;
  document.querySelectorAll('.ma-chip-dd .chip-dd-list').forEach(function(l){
    if (!l.parentNode.contains(e.target)) {
      l.hidden = true;
      l.closest('.ma-chip-dd').classList.remove('chip-dd-open');
    }
  });
});

function showList(wrap, filter, focusSearch){
  var list = wrap.querySelector('.chip-dd-list');
  var key = wrap.dataset.key;
  filter = filter || '';
  var pool = itemPools[key] && itemPools[key].length ? itemPools[key] : (itemPools.ok || []);
  var usedOther = allChipValues(key);
  var selectedHere = chipsInField(key);
  var shown = pool.filter(function(o){
    if (usedOther[o]) return false;
    if (selectedHere[o]) return false;
    return !filter || o.toLowerCase().indexOf(filter) !== -1;
  });
  var selectedN = Object.keys(selectedHere).length;
  var tools = (function () {
    var btns = '';
    if (shown.length) {
      btns += '<button type="button" class="btn-sm btn-line" data-act="all">เลือกทั้งหมดที่แสดง</button>';
    }
    if (selectedN) {
      btns += '<button type="button" class="btn-sm btn-line" data-act="clear">เคลียร์ทั้งหมด</button>';
    }
    if (!btns) return '';
    var hint = shown.length
      ? 'ติ๊กเลือกทีละรายการ · เหลือ ' + shown.length
      : 'เลือกครบแล้ว';
    return '<div class="ma-pick-tools">' + btns
      + '<span class="muted ma-pick-count">' + hint + '</span></div>';
  })();
  if (!pool.length) {
    list.innerHTML = tools + '<div class="muted ma-pick-empty">ยังไม่มีรายการของรุ่นนี้ — พิมพ์แล้วกด Enter เพื่อเพิ่มเอง</div>';
  } else if (!shown.length) {
    var msg = selectedN
      ? 'เลือกครบแล้ว — กดเคลียร์ทั้งหมด หรือลบแท็กด้านบนเพื่อเลือกใหม่'
      : 'ไม่มีรายการที่เลือกได้ (ถูกใช้ในช่องอื่นแล้ว หรือไม่ตรงคำค้น)';
    list.innerHTML = tools + '<div class="muted ma-pick-empty">' + msg + '</div>';
  } else {
    var rows = shown.map(function(o){
      return '<label class="ma-pick-row ma-pick-add dd-panel-check" data-v="' + esc(o) + '">'
        + '<input type="checkbox" tabindex="-1">'
        + '<span class="ma-pick-label">' + esc(o) + '</span></label>';
    }).join('');
    list.innerHTML = tools + '<div class="dd-panel-items">' + rows + '</div>';
  }
  list.hidden = false;
  wrap.classList.add('chip-dd-open');
  if (focusSearch) {
    var filt = wrap.querySelector('.chip-dd-filter');
    if (filt) setTimeout(function(){ filt.focus(); }, 0);
  }
}

document.getElementById('ma-form').addEventListener('submit', function(e){
  var fwDd = document.querySelector('#ma-fw-slot .chip-dd');
  if (fwDd && typeof chipDdFlushFreeText === 'function') chipDdFlushFreeText(fwDd);
  var seen = {};
  for (var i = 0; i < FIELDS.length; i++) {
    var key = FIELDS[i];
    var vals = chipValues(key);
    for (var j = 0; j < vals.length; j++) {
      if (seen[vals[j]]) {
        e.preventDefault();
        alert(maDupMsg(vals[j], seen[vals[j]]));
        return;
      }
      seen[vals[j]] = key;
    }
    document.getElementById({ok:'h-ok', replace:'h-replace', repair:'h-repair'}[key]).value = vals.join(' , ');
  }
  var btn = document.getElementById('ma-submit-btn');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'กำลังบันทึก…';
  }
});

window.addEventListener('pageshow', function(e) {
  if (e.persisted && maEditMode) {
    populateMaEditChips();
    var btn = document.getElementById('ma-submit-btn');
    if (btn) {
      btn.disabled = false;
      btn.textContent = btn.dataset.defaultLabel || 'บันทึกการแก้ไข';
    }
    return;
  }
  if (!e.persisted) return;
  var btn = document.getElementById('ma-submit-btn');
  if (!btn) return;
  btn.disabled = false;
  btn.textContent = btn.dataset.defaultLabel || 'บันทึก MA';
});
(function(){
  var btn = document.getElementById('ma-submit-btn');
  if (btn && !btn.dataset.defaultLabel) {
    btn.dataset.defaultLabel = btn.textContent;
  }
})();

document.getElementById('ma_remark').addEventListener('input', updateMaSnippets);

// อายุเครื่อง / อาการที่แจ้ง (คิวรอ MA) — อัปเดตเมื่อเลือก S/N จากค้นหาหรือตารางคิว
(function(){
  var input = document.getElementById('ma_code');
  var ageSlot = document.getElementById('ma-age-hint');
  var symSlot = document.getElementById('ma-symptom-hint');
  if (!input || !ageSlot) return;
  var pickedCode = input.value.trim(); // ค่าที่ server render มา (โหมดแก้ไข/มาจากหน้าเครื่อง)
  function setMetaLine(el, labelHtml) {
    if (!el) return;
    if (labelHtml) {
      el.innerHTML = labelHtml;
      el.hidden = false;
    } else {
      el.innerHTML = '';
      el.hidden = true;
    }
  }
  function renderSymptom(sym) {
    setMetaLine(symSlot, sym ? '<b>' + esc(sym) + '</b>' : '');
  }
  input.addEventListener('asset-picked', function(e){
    var age = (e.detail && e.detail.age) ? e.detail.age : '';
    var symptom = (e.detail && e.detail.symptom) ? e.detail.symptom : (input.dataset.pickedSymptom || '');
    pickedCode = (e.detail && e.detail.code) ? e.detail.code : input.value.trim();
    setMetaLine(ageSlot, age ? 'อายุเครื่อง <b>' + esc(age) + '</b>' : '');
    renderSymptom(symptom);
  });
  input.addEventListener('input', function(){
    // พิมพ์แก้เองจนไม่ตรงกับเครื่องที่เลือกไว้ — ซ่อนอายุ/อาการ กันแสดงค่าของเครื่องอื่น
    if (input.value.trim() !== pickedCode) {
      setMetaLine(ageSlot, '');
      renderSymptom('');
    }
  });
})();

(function(){
  var input = document.getElementById('ma_code');
  var box = document.getElementById('ma-history');
  var timer = null;
  function loadAll(){
    updateMaSnippets();
    var code = input.value.trim();
    if (code.length < 4) { box.innerHTML = ''; renderMaSdPanel({tracked: false}); return; }
    box.innerHTML = '<span class="muted">กำลังค้นหาประวัติ…</span>';
    fetch(BASE + '/ma.php?ajax=history&code=' + encodeURIComponent(code))
      .then(function(r){ return r.text(); }).then(function(html){ box.innerHTML = html; }).catch(function(){});
    fetch(BASE + '/ma.php?ajax=items&code=' + encodeURIComponent(code))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.found) { renderMaSdPanel({tracked: false}); return; }
        itemPools.ok = d.pool_ok || d.pool || [];
        itemPools.replace = d.pool_replace || d.pool || [];
        itemPools.repair = d.pool_repair || d.pool || [];
        renderMaSdPanel(d.sd || {tracked: false});
        // โหมดแก้ไข — ใช้ข้อมูลจากรายการ MA ที่เลือกเท่านั้น ห้าม prefill จาก MA ล่าสุดของเครื่อง
        if (maEditMode) return;
        if (d.status === 'spare' || d.status === 'rental') {
          setMaChoiceValue('machine_status_group', d.status);
          syncMaSpareMode();
        }
        if (document.getElementById('chips-ok').children.length === 0) {
          (d.prefill_ok || []).forEach(function(it){ addChip('ok', it, true); });
        }
      }).catch(function(){});
  }
  // กดแถวประวัติ = เปิด popup รายละเอียดครั้งนั้น (กดปุ่มแก้ไข/ลบ ไม่นับ)
  box.addEventListener('click', function(e){
    if (e.target.closest('.row-actions')) return;
    var tr = e.target.closest('.ma-hist-row');
    if (!tr) return;
    showListModal('MA ' + tr.getAttribute('data-when'), BASE + '/ma.php?ajax=ma_detail&id=' + tr.getAttribute('data-ma'));
  });
  box.addEventListener('keydown', function(e){
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var tr = e.target.closest ? e.target.closest('.ma-hist-row') : null;
    if (!tr) return;
    e.preventDefault();
    showListModal('MA ' + tr.getAttribute('data-when'), BASE + '/ma.php?ajax=ma_detail&id=' + tr.getAttribute('data-ma'));
  });
  input.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(loadAll, 600); });
  input.addEventListener('change', loadAll);
  if (input.value.trim() !== '') loadAll();
  else updateMaSnippets();
})();

function syncMaRetireMode(){
  var form = document.getElementById('ma-form');
  var rent = document.getElementById('rent_close_action');
  if (!form || !rent) return;
  var isRetire = rent.value === 'retire';
  form.classList.toggle('ma-retire-mode', isRetire);
  var remark = document.getElementById('ma_remark');
  if (remark) {
    remark.placeholder = isRetire ? 'ระบุรายละเอียดการเสื่อมสภาพ…' : 'บันทึกเพิ่มเติม…';
  }
  var spareBtn = document.querySelector('#machine_status_group .ma-choice-btn-spare');
  if (spareBtn) spareBtn.disabled = isRetire;
}

function syncMaSpareMode(){
  var form = document.getElementById('ma-form');
  var status = document.getElementById('machine_status');
  var rent = document.getElementById('rent_close_action');
  if (!form || !status) return;
  var isSpare = status.value === 'spare';
  form.classList.toggle('ma-spare-mode', isSpare);
  var panel = document.getElementById('ma-snippets');
  if (panel) panel.classList.toggle('ma-spare-mode', isSpare);
  var hint = document.getElementById('ma-spare-rent-hint');
  if (hint) hint.hidden = !isSpare;
  if (rent) {
    if (isSpare) {
      if (rent.value !== 'retire') rent.value = 'keep';
    } else if (rent.value === 'keep') {
      setMaChoiceValue('rent_close_action_group', 'close_fg');
    }
  }
  var titleEl = document.querySelector('#ma-snippets .ma-snippets-title-text');
  if (titleEl) {
    var rentalTitle = titleEl.getAttribute('data-title-rental') || '';
    var plainTitle = titleEl.getAttribute('data-title-plain') || '';
    if (rentalTitle && plainTitle) titleEl.textContent = isSpare ? plainTitle : rentalTitle;
  }
}

function setMaChoiceValue(groupId, value){
  var group = document.getElementById(groupId);
  if (!group) return;
  var hidden = group.querySelector('input[type=hidden]');
  if (hidden) hidden.value = value;
  group.querySelectorAll('.ma-choice-btn').forEach(function(btn){
    btn.classList.toggle('is-active', btn.getAttribute('data-value') === value);
  });
}

function initMaChoiceGroup(groupId, onChange){
  var group = document.getElementById(groupId);
  if (!group) return;
  var hidden = group.querySelector('input[type=hidden]');
  if (!hidden) return;
  group.querySelectorAll('.ma-choice-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var v = btn.getAttribute('data-value') || '';
      hidden.value = v;
      group.querySelectorAll('.ma-choice-btn').forEach(function(b){
        b.classList.toggle('is-active', b === btn);
      });
      if (typeof onChange === 'function') onChange(v, hidden);
    });
  });
}

function renderMaSdPanel(sd){
  var slot = document.getElementById('ma-sd-slot');
  var label = document.getElementById('ma-sd-label');
  if (!slot) return;
  if (!sd || !sd.tracked) {
    slot.innerHTML = '';
    slot.hidden = true;
    if (label) label.hidden = true;
    return;
  }
  slot.hidden = false;
  if (label) label.hidden = false;
  var files = sd.files || {};
  var html = '<div class="ma-sd-panel" id="ma-sd-panel">';
  if (sd.slug === 's') {
    html += '<p class="ma-sd-hint muted" style="margin:0 0 8px">รุ่น S เป็น Pi3 อย่างเดียว</p>';
    html += '<input type="hidden" name="sd_raspberry" id="sd_raspberry" value="Pi3">';
  } else {
    html += '<p class="ma-sd-hint muted" style="margin:0 0 8px">Plus / M ใช้ไฟล์คนละตัวตามบอร์ด — Pi2 ยังเป็นชุดปี 2023 · Pi3 ต้องเขียนชุด AL-26-10-14</p>';
    if (!sd.raspberry && sd.pi_guess) {
      html += '<p class="ma-sd-hint muted" style="margin:0 0 8px">จาก S/N น่าจะเป็น <b>' + esc(sd.pi_guess) + '</b>';
      if (sd.pi3_cutoff) html += ' · เริ่มใช้ Pi3 ตั้งแต่ ' + esc(sd.pi3_cutoff);
      html += ' — กดเลือกบอร์ดที่เห็นจริง</p>';
    }
    html += '<div class="ma-choice-group" id="sd_raspberry_group" role="group">';
    html += '<input type="hidden" name="sd_raspberry" id="sd_raspberry" value="' + esc(sd.raspberry || '') + '">';
    ['Pi2', 'Pi3'].forEach(function(opt){
      html += '<button type="button" class="ma-choice-btn' + (sd.raspberry === opt ? ' is-active' : '') + '" data-value="' + opt + '">' + opt + '</button>';
    });
    html += '</div>';
  }
  html += '<p class="ma-sd-file" id="ma-sd-file"></p>';
  html += '<p class="ma-sd-done" id="ma-sd-done" hidden>เขียนชุดปัจจุบันแล้ว</p>';
  html += '<p class="ma-sd-hint muted" id="ma-sd-infer" hidden></p>';
  if (sd.hints_html) html += '<div class="ma-sd-refs">' + sd.hints_html + '</div>';
  html += '</div>';
  slot.innerHTML = html;
  slot._sd = { files: files, have: sd.have_file || '', campaign: sd.campaign_pis || ['Pi3'], slug: sd.slug || '', versions: sd.versions || {} };
  if (sd.slug !== 's') {
    initMaChoiceGroup('sd_raspberry_group', function(v){ updateMaSdHint(v); });
  }
  updateMaSdHint(sd.slug === 's' ? 'Pi3' : (sd.raspberry || ''));
}

function maSdFwNorm(fw){
  return String(fw || '').replace(/\s+/g, '').toLowerCase();
}
function maSdFwParse(fw){
  var t = maSdFwNorm(fw);
  var m = t.match(/^(\d+)\.(\d+)(?:\.(\d+))?([a-z]*)(?:v(\d+))?$/);
  if (!m) return null;
  return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3] || '0', 10), m[4] || '', parseInt(m[5] || '0', 10)];
}
function maSdFwCmp(a, b){
  if (maSdFwNorm(a) === maSdFwNorm(b)) return 0;
  var pa = maSdFwParse(a), pb = maSdFwParse(b);
  if (!pa || !pb) {
    var na = maSdFwNorm(a), nb = maSdFwNorm(b);
    return na < nb ? -1 : (na > nb ? 1 : 0);
  }
  var i;
  for (i = 0; i < 3; i++) { if (pa[i] !== pb[i]) return pa[i] < pb[i] ? -1 : 1; }
  if (pa[3] !== pb[3]) {
    if (pa[3] === '') return -1;
    if (pb[3] === '') return 1;
    return pa[3] < pb[3] ? -1 : (pa[3] > pb[3] ? 1 : 0);
  }
  if (pa[4] !== pb[4]) return pa[4] < pb[4] ? -1 : 1;
  return 0;
}
function maSdFwAtLeast(fw, start){
  fw = String(fw || '').trim();
  start = String(start || '').trim();
  if (!fw || !start) return false;
  if (maSdFwNorm(fw) === maSdFwNorm(start)) return true;
  if (!maSdFwParse(fw) || !maSdFwParse(start)) return false;
  return maSdFwCmp(fw, start) >= 0;
}
function maSdFileForFw(versions, fw){
  var best = { file: '', fw_start: '' };
  var bestStart = '';
  (versions || []).forEach(function(row){
    var file = row && row.file ? String(row.file) : '';
    var start = row && row.fw_start ? String(row.fw_start).trim() : '';
    if (!file || !start || !maSdFwAtLeast(fw, start)) return;
    if (bestStart === '' || maSdFwCmp(start, bestStart) > 0) {
      best = { file: file, fw_start: start };
      bestStart = start;
    }
  });
  return best;
}
function refreshMaSdFromFw(){
  var piEl = document.getElementById('sd_raspberry');
  updateMaSdHint(piEl ? piEl.value : '');
}

function updateMaSdHint(pi){
  var slot = document.getElementById('ma-sd-slot');
  var fileEl = document.getElementById('ma-sd-file');
  var doneEl = document.getElementById('ma-sd-done');
  var panel = document.getElementById('ma-sd-panel');
  if (!slot || !fileEl || !slot._sd) return;
  var files = slot._sd.files || {};
  var have = slot._sd.have || '';
  var campaign = slot._sd.campaign || [];
  var versions = (slot._sd.versions && pi && slot._sd.versions[pi]) ? slot._sd.versions[pi] : [];
  var inferEl = document.getElementById('ma-sd-infer');
  var file = pi && files[pi] ? files[pi] : '';
  var inCampaign = campaign.indexOf(pi) !== -1;
  var fw = typeof maFwValue === 'function' ? maFwValue() : '';
  var fromFw = maSdFileForFw(versions, fw);
  var done = file !== '' && have === file;
  var fwIsCurrent = file !== '' && fromFw.file === file;
  var ok = done || fwIsCurrent;
  if (panel) {
    panel.classList.toggle('is-warn', !ok && (pi === '' || inCampaign));
    panel.classList.toggle('is-ok', ok);
  }
  if (inferEl) {
    inferEl.hidden = true;
    inferEl.textContent = '';
  }
  if (doneEl) doneEl.hidden = true;
  if (pi === '') {
    fileEl.innerHTML = 'ยังไม่รู้ว่าเป็น Pi2 หรือ Pi3 — เปิดเครื่องดูบอร์ดแล้วกดเลือก';
    return;
  }
  if (!inCampaign) {
    var shownPi = (fromFw.file && fromFw.file !== file) ? fromFw.file : file;
    fileEl.innerHTML = pi + ' ใช้ <b>' + esc(shownPi) + '</b> — ไม่ต้องเขียนชุดใหม่รอบนี้';
    return;
  }
  if (done) {
    fileEl.innerHTML = 'ชุดปัจจุบัน: <b>' + esc(file) + '</b>';
    if (doneEl) doneEl.hidden = false;
    return;
  }
  if (fwIsCurrent) {
    fileEl.innerHTML = 'ตาม version FW ที่เลือก ใช้ชุดปัจจุบัน: <b>' + esc(file) + '</b>';
    if (inferEl) {
      inferEl.hidden = false;
      inferEl.textContent = 'เริ่มที่ ' + fromFw.fw_start + ' — บันทึก MA แล้วจะยืนยันชุดนี้ให้';
    }
    return;
  }
  if (fromFw.file) {
    fileEl.innerHTML = 'ตาม version FW ที่เลือก ใช้ไฟล์เก่า: <b>' + esc(fromFw.file) + '</b>';
    if (inferEl) {
      inferEl.hidden = false;
      inferEl.textContent = 'ชุดปัจจุบันคือ ' + file + ' (เริ่มที่ FW ที่สูงกว่านี้)';
    }
    return;
  }
  fileEl.innerHTML = 'ชุดปัจจุบัน: <b>' + esc(file) + '</b>';
  if (inferEl) {
    inferEl.hidden = false;
    inferEl.textContent = fw
      ? 'version FW นี้ยังไม่ถึงจุดเริ่มชุดปัจจุบัน — เลือกเวอร์ชันที่เขียน image แล้วด้านล่าง'
      : 'เลือก version FW ด้านล่าง ระบบจะเทียบไฟล์ image ให้เอง';
  }
}

(function(){
  initMaChoiceGroup('machine_status_group', function(){
    syncMaSpareMode();
    if (typeof updateMaSnippets === 'function') updateMaSnippets();
  });
  initMaChoiceGroup('rent_close_action_group', function(v){
    if (v === 'retire') setMaChoiceValue('machine_status_group', 'rental');
    syncMaRetireMode();
    syncMaSpareMode();
    if (typeof updateMaSnippets === 'function') updateMaSnippets();
  });
  syncMaRetireMode();
  syncMaSpareMode();
  if (MA_SD_INIT && MA_SD_INIT.tracked) renderMaSdPanel(MA_SD_INIT);
})();

populateMaEditChips();
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', populateMaEditChips);
}

/* ── ร่างที่ยังไม่ได้บันทึก (UX เฟส 3 ข้อ 3) ─────────────────────────────────
   ช่างกรอกฟอร์มนี้หน้างาน ซึ่งสัญญาณหลุดและจอดับได้ตลอด · ฟอร์มยาว 3.4 จอบนมือถือ
   กรอกใหม่ทั้งชุดคือเสียเวลาจริง จึงเก็บร่างไว้ในเครื่องผูกกับ S/N

   ไม่เติมค่ากลับให้เองเงียบ ๆ — ขึ้นแถบถามก่อนเสมอ เพราะถ้าเผลอเติมค่าของเครื่องก่อน
   หน้าลงในเครื่องใหม่แล้วกดบันทึก จะได้ข้อมูล MA ที่ผิดเครื่องโดยไม่มีใครรู้
   โหมดแก้ไขไม่เก็บ ที่นั่นมีค่าจากฐานอยู่แล้ว ร่างจะทับของจริง                */
(function(){
  var form = document.getElementById("ma-form");
  if (!form || maEditMode) return;
  var codeInput = document.getElementById("ma_code");
  if (!codeInput) return;

  var PREFIX = "fg-ma-draft:";
  var MAX_AGE = 24 * 60 * 60 * 1000;   // ร่างข้ามวันไม่ควรเด้งกลับมา งานคนละรอบแล้ว
  var saveTimer = null;

  function keyFor(code) {
    code = (code || "").trim().toUpperCase();
    return code ? PREFIX + code : "";
  }

  function collect() {
    var data = { f: {}, chips: {} };
    [].forEach.call(form.querySelectorAll("input, select, textarea"), function (el) {
      if (!el.name || el.type === "hidden" || el.type === "file" || el.name === "csrf") return;
      if (el.type === "checkbox" || el.type === "radio") { data.f[el.name] = el.checked ? el.value : ""; }
      else { data.f[el.name] = el.value; }
    });
    FIELDS.forEach(function (k) { data.chips[k] = chipValues(k); });
    return data;
  }

  function isEmpty(d) {
    var any = false;
    FIELDS.forEach(function (k) { if ((d.chips[k] || []).length) any = true; });
    if (any) return false;
    return !(d.f.remark || "").trim() && !(d.f.fw_version || "").trim();
  }

  function save() {
    try {
      var key = keyFor(codeInput.value);
      if (!key) return;
      var d = collect();
      if (isEmpty(d)) { localStorage.removeItem(key); return; }
      localStorage.setItem(key, JSON.stringify({ at: Date.now(), data: d }));
    } catch (e) { /* โหมดส่วนตัว/พื้นที่เต็ม — ไม่ต้องรบกวนผู้ใช้ */ }
  }

  function clearDraft() {
    try { var k = keyFor(codeInput.value); if (k) localStorage.removeItem(k); } catch (e) {}
  }

  function apply(d) {
    Object.keys(d.f).forEach(function (name) {
      var el = form.querySelector("[name=\"" + name + "\"]");
      if (!el || el.readOnly) return;
      if (el.type === "checkbox" || el.type === "radio") { el.checked = !!d.f[name]; }
      else { el.value = d.f[name]; }
    });
    FIELDS.forEach(function (k) {
      var box = document.getElementById("chips-" + k);
      if (box) box.innerHTML = "";
      (d.chips[k] || []).forEach(function (v) { addChip(k, v, true); });
    });
    if (typeof updateMaSnippets === "function") updateMaSnippets();
  }

  function offer() {
    var key = keyFor(codeInput.value);
    if (!key) return;
    var raw;
    try { raw = localStorage.getItem(key); } catch (e) { return; }
    if (!raw) return;
    var saved;
    try { saved = JSON.parse(raw); } catch (e) { localStorage.removeItem(key); return; }
    if (!saved || !saved.data || (Date.now() - (saved.at || 0)) > MAX_AGE) { localStorage.removeItem(key); return; }
    if (document.querySelector(".ma-draft-bar")) return;

    var when = new Date(saved.at);
    var bar = document.createElement("div");
    bar.className = "ma-draft-bar";
    bar.innerHTML = "<span class=\"ma-draft-text\">มีร่างที่กรอกค้างไว้ของเครื่องนี้ เมื่อ "
      + String(when.getHours()).padStart(2, "0") + ":" + String(when.getMinutes()).padStart(2, "0")
      + "</span>";
    var use = document.createElement("button");
    use.type = "button"; use.className = "btn btn-sm btn-primary"; use.textContent = "ใช้ร่างนี้";
    var drop = document.createElement("button");
    drop.type = "button"; drop.className = "btn btn-sm btn-line"; drop.textContent = "ทิ้งร่าง";
    use.addEventListener("click", function () { apply(saved.data); bar.remove(); });
    drop.addEventListener("click", function () { clearDraft(); bar.remove(); });
    bar.appendChild(use); bar.appendChild(drop);
    form.insertBefore(bar, form.firstChild);
  }

  function queueSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 600);
  }
  form.addEventListener("input", queueSave);
  // ชิป (ตรวจผ่าน/เปลี่ยน/ซ่อม) เพิ่มและลบด้วยการคลิก ไม่ยิง input — ดักคลิกด้วย
  form.addEventListener("click", queueSave);
  // บันทึกสำเร็จแล้วร่างต้องหายไป ไม่งั้นครั้งหน้าจะถูกถามถึงงานที่ทำจบไปแล้ว
  form.addEventListener("submit", clearDraft);
  codeInput.addEventListener("asset-picked", offer);
  offer();
})();
</script>
<?php } ?>

<h2>MA ของรุ่นนี้<?= $total ? ' (' . number_format($total) . ')' : '' ?></h2>
<p class="muted ma-table-hint">คลิกแถวเพื่อดูรายละเอียด MA · คลิกหัวคอลัมน์เพื่อจัดเรียง · กรองจากแผงด้านล่าง (กดค้นหาเมื่อพร้อม)</p>
<?php
$maListHasFilter = ma_list_filter_active($mq, $mby, $mround, $mkind, $mfrom, $mto, $mok, $mrepl, $mrepair, $mr);
$maListPanelOpen = $maListHasFilter;
$maListColspan = 5 + ($maListShowFw ? 1 : 0) + (can('ma') ? 1 : 0);
$maListSymptoms = [];
if (!empty($recentMA)) {
    $maListSnBatch = [];
    foreach ($recentMA as $maRowSym) {
        $code = trim((string) ($maRowSym['asset_code'] ?? ''));
        if ($code !== '') {
            $maListSnBatch[] = $code;
        }
    }
    if ($maListSnBatch) {
        $maListSymptoms = rent_wait_ma_symptoms($maListSnBatch);
    }
}
$maListFilterBits = [];
if ($mfrom !== '' || $mto !== '') {
    $maListFilterBits[] = ($mfrom !== '' ? dthai($mfrom) : '…') . ' – ' . ($mto !== '' ? dthai($mto) : '…');
}
if ($mq !== '') {
    $maListFilterBits[] = 'S/N: ' . $mq;
}
if ($mround !== '') {
    $maListFilterBits[] = 'รอบ: ' . $mround;
}
if ($mkind === 'rental') {
    $maListFilterBits[] = 'เครื่องเช่า';
} elseif ($mkind === 'spare') {
    $maListFilterBits[] = 'เครื่องสำรอง';
}
if ($mok !== '') {
    $maListFilterBits[] = 'ปกติ: ' . $mok;
}
if ($mrepl !== '') {
    $maListFilterBits[] = 'เปลี่ยน: ' . $mrepl;
}
if ($mrepair !== '') {
    $maListFilterBits[] = 'ซ่อม: ' . $mrepair;
}
if ($mr !== '' && $mok === '' && $mrepl === '' && $mrepair === '') {
    $maListFilterBits[] = 'รายละเอียด: ' . $mr;
}
if ($mby !== '') {
    $maListFilterBits[] = 'โดย: ' . $mby;
}
?>
<div class="ma-list-filter-panel<?= $maListPanelOpen ? ' is-open' : '' ?>" id="ma-list-filter-panel">
  <div class="ma-list-toolbar">
    <button type="button" id="ma-list-filter-toggle" class="btn btn-primary btn-sm ma-list-filter-toggle" aria-expanded="<?= $maListPanelOpen ? 'true' : 'false' ?>" aria-controls="ma-list-filter-body">กรองรายการ</button>
    <?php if ($maListHasFilter) { ?>
    <span class="muted ma-list-filter-active">กำลังกรอง: <?= h(implode(' · ', $maListFilterBits)) ?></span>
    <a href="<?= h($maClearUrl) ?>" class="btn btn-sm btn-line">ล้างเงื่อนไข</a>
    <?php } ?>
  </div>
  <div class="ma-list-filter-body" id="ma-list-filter-body"<?= $maListPanelOpen ? '' : ' hidden' ?>>
    <form class="filter ma-list-filter-form" method="get" data-live-search="0">
      <input type="hidden" name="product" value="<?= (int) $productId ?>">
      <?php if ($msort !== 'date_desc') { ?>
      <input type="hidden" name="msort" value="<?= h($msort) ?>">
      <?php } ?>
      <?php if (isset($_GET['mrent']) && (string) $_GET['mrent'] === '0') { ?>
      <input type="hidden" name="mrent" value="0">
      <?php } ?>
      <div class="ma-list-filter-cards">
        <div class="ma-list-filter-top2">
          <section class="ma-list-filter-card">
            <h3 class="ma-list-filter-card-hd">วันเวลา MA <span class="ma-list-filter-card-note">ว่างทั้งคู่ = ไม่กรองวันที่</span></h3>
            <div class="ma-list-filter-dates">
              <label class="ma-list-filter-field"><span>ตั้งแต่</span>
                <input type="date" name="mfrom" value="<?= h($mfrom) ?>"></label>
              <label class="ma-list-filter-field"><span>ถึง</span>
                <input type="date" name="mto" value="<?= h($mto) ?>"></label>
            </div>
          </section>
          <section class="ma-list-filter-card">
            <h3 class="ma-list-filter-card-hd">เครื่องและผู้บันทึก</h3>
            <div class="ma-list-filter-grid4">
              <label class="ma-list-filter-field"><span>รหัสเครื่อง (S/N)</span>
                <input type="text" name="mq" value="<?= h($mq) ?>" placeholder="เช่น BM22050949" autocomplete="off"></label>
              <label class="ma-list-filter-field"><span>รอบ MA</span>
                <input type="text" name="mround" value="<?= h($mround) ?>" placeholder="เลขรอบ" autocomplete="off"></label>
              <label class="ma-list-filter-field"><span>ประเภท</span>
                <select name="mkind">
                  <option value=""<?= $mkind === '' ? ' selected' : '' ?>>ทั้งหมด</option>
                  <option value="rental"<?= $mkind === 'rental' ? ' selected' : '' ?>>เครื่องเช่า</option>
                  <option value="spare"<?= $mkind === 'spare' ? ' selected' : '' ?>>เครื่องสำรอง</option>
                </select></label>
              <label class="ma-list-filter-field"><span>ผู้บันทึก</span>
                <input type="text" name="mby" value="<?= h($mby) ?>" placeholder="ชื่อ" autocomplete="off"></label>
            </div>
          </section>
        </div>
        <section class="ma-list-filter-card ma-list-filter-card-wide">
          <h3 class="ma-list-filter-card-hd">รายการตรวจ <span class="ma-list-filter-card-note">แยกตามคอลัมน์ในตาราง</span></h3>
          <div class="ma-list-filter-items">
            <label class="ma-list-filter-field ma-list-filter-ok"><span>✅ ปกติ</span>
              <input type="text" name="mok" value="<?= h($mok) ?>" placeholder="เช่น HDMI, SD Card" autocomplete="off"></label>
            <label class="ma-list-filter-field ma-list-filter-repl"><span>🔄 เปลี่ยน</span>
              <input type="text" name="mrepl" value="<?= h($mrepl) ?>" placeholder="เช่น Battery" autocomplete="off"></label>
            <label class="ma-list-filter-field ma-list-filter-repair"><span>🔧 ซ่อม</span>
              <input type="text" name="mrepair" value="<?= h($mrepair) ?>" placeholder="เช่น RTC" autocomplete="off"></label>
          </div>
        </section>
      </div>
      <div class="ma-list-filter-ft">
        <button type="submit" class="btn btn-primary btn-with-icon"><?= ui_btn_label('search', 'ค้นหา') ?></button>
        <button type="reset" class="btn btn-line btn-sm">ล้างในฟอร์ม</button>
        <span class="muted ma-list-filter-ft-note">กดค้นหาเมื่อพร้อม · แผงเปิดค้างหลังค้นหา</span>
      </div>
    </form>
  </div>
</div>
<div class="table-wrap">
<?php if ($rentTotal > 0 || !$maShowRent) { ?>
<p class="muted ma-src-note">
  <?php if ($maShowRent) { ?>
    รวมรายการที่บันทึกไว้ในระบบเช่าด้วย <b><?= number_format($rentTotal) ?></b> รายการ
    (ของระบบเรา <?= number_format($ownTotal) ?>) · งานที่บันทึกทั้งสองที่นับครั้งเดียว
    · <a href="<?= h(ma_list_qs($productId, ['mrent' => '0', 'page' => 1])) ?>">ซ่อนรายการจากระบบเช่า</a>
  <?php } else { ?>
    แสดงเฉพาะรายการที่บันทึกในระบบเรา
    · <a href="<?= h(ma_list_qs($productId, ['mrent' => '', 'page' => 1])) ?>">รวมรายการจากระบบเช่าด้วย</a>
  <?php } ?>
</p>
<?php } ?>
<table class="list ma-list-table">
  <thead>
  <tr class="ma-list-sort-row">
    <th class="ma-c-when"><?= ma_sort_th('วันเวลา', 'date_asc', 'date_desc', $msort, $productId) ?></th>
    <th class="ma-c-asset"><?= ma_sort_th('เครื่อง', 'asset_asc', 'asset_desc', $msort, $productId) ?></th>
    <th class="ma-c-sym" title="จากระบบเช่า — ข้อความที่แจ้งตอนเข้า MA">อาการที่แจ้ง</th>
    <th class="ma-c-done" title="ซ่อม · เปลี่ยน · จำนวนรายการที่ตรวจแล้วปกติ">สิ่งที่ทำ</th>
    <th class="ma-c-rent" title="ผลต่อระบบเช่า: ซ่อมแล้ว หรือ เสื่อมสภาพ">ระบบเช่า</th>
    <?php if ($maListShowFw) { ?><th class="ma-c-fw"><?= ma_sort_th('FW', 'fw_asc', 'fw_desc', $msort, $productId) ?></th><?php } ?>
    <?= can('ma') ? '<th class="ma-c-act"></th>' : '' ?>
  </tr>
  </thead>
  <tbody>
  <?php if ($total === 0) { ?>
  <tr><td colspan="<?= (int) $maListColspan ?>" class="muted" style="padding:14px 10px"><?php
      echo $maListHasFilter
          ? 'ไม่พบรายการตามเงื่อนไข — แก้แผงกรองหรือล้างเงื่อนไข'
          : 'ยังไม่มีรายการ MA ของรุ่นนี้';
  ?></td></tr>
  <?php } ?>
  <?php foreach ($recentMA as $m) {
      $isRent = ($m['src'] ?? '') === 'rent';
      $machineKind = ma_list_machine_kind($m);
      $maSymSn = rent_normalize_sn((string) ($m['asset_code'] ?? ''));
      $maSymText = trim((string) ($maListSymptoms[$maSymSn] ?? ''));
  ?>
  <?php
      $maWhen = ma_when_label((string) $m['visited_at']);
      [$maDay, $maTime] = array_pad(explode(' ', $maWhen, 2), 2, '');
      $maBy = trim((string) ($m['done_by'] ?? ''));
      $maRemark = trim((string) ($m['remark'] ?? ''));
  ?>
  <tr class="ma-row-click" title="<?= $maShowSnippets ? 'คลิกดูรายละเอียด MA และคำสั่งตั้งค่าหมายเลขสินค้า' : 'คลิกดูรายละเอียด MA' ?> · <?= h(status_th($machineKind)) ?>" <?= ma_row_data_attrs($m) ?>>
    <td class="ma-c-when">
      <b><?= h($maDay) ?></b>
      <small><?= h(implode(' · ', array_filter([$maTime, $maBy]))) ?></small>
      <?php if ($isRent) { ?><span class="ma-src-tag">ระบบเช่า</span><?php } ?>
    </td>
    <td class="ma-list-asset-cell ma-c-asset">
      <a href="<?= BASE_URL ?>/asset.php?id=<?= (int)$m['asset_id'] ?>"><?= h($m['asset_code']) ?></a>
      <?= ma_list_kind_chip_html($machineKind) ?>
      <?php if ($m['ma_round']) { ?><small>รอบที่ <?= (int) $m['ma_round'] ?></small><?php } ?>
    </td>
    <td class="ma-list-symptom ma-c-sym">
      <?= $maSymText !== '' ? h($maSymText) : '<span class="muted">—</span>' ?>
    </td>
    <?php if ($isRent) {
        // ฝั่งระบบเช่าบันทึกงานเป็นข้อความก้อนเดียว ไม่ได้แยก ซ่อม/เปลี่ยน/ปกติ — แสดงตามที่บันทึกไว้
    ?>
    <td class="ma-rent-note ma-c-done"><?= $maRemark !== '' ? nl2br(h($maRemark)) : '<span class="muted">ไม่มีรายละเอียด</span>' ?></td>
    <?php } else { ?>
    <td class="ma-c-done"><?= ma_list_done_cell($m) ?></td>
    <?php } ?>
    <td class="ma-rent-close ma-c-rent"><?= ma_rent_close_cell($m) ?></td>
    <?php if ($maListShowFw) { ?><td class="ma-c-fw"><?= h($m['fw_version'] ?: '-') ?></td><?php } ?>
    <?php if (can('ma')) { ?>
    <td class="ma-row-actions row-actions ma-c-act">
      <?php if ($isRent) {
          $rentUrl = function_exists('rent_leasing_record_url') ? rent_leasing_record_url((string) $m['asset_code'], (int) $m['id']) : '';
      ?>
        <?php if ($rentUrl !== '') { ?>
        <a class="ma-ib" href="<?= h($rentUrl) ?>" target="_blank" rel="noopener" title="เปิดในระบบเช่า" aria-label="เปิดในระบบเช่า"><?= ui_icon_html('external-link', 14) ?></a>
        <?php } ?>
        <form method="post" onsubmit="return confirm('ลบรายการ MA นี้จากระบบเช่า?\nลบแล้วสถานะเครื่องจะ sync ตาม MA ล่าสุด')">
          <?= csrf_field() ?>
          <input type="hidden" name="del_rent_ma" value="1">
          <input type="hidden" name="rent_ma_id" value="<?= (int)$m['id'] ?>">
          <input type="hidden" name="rent_sn" value="<?= h($m['asset_code'] ?? '') ?>">
          <input type="hidden" name="back" value="<?= h($maListUrl) ?>">
          <button class="ma-ib is-del" type="submit" title="ลบ" aria-label="ลบรายการ MA"><?= ui_icon_html('trash', 14) ?></button>
        </form>
      <?php } else { ?>
      <a class="ma-ib" href="<?= BASE_URL ?>/ma.php?product=<?= $productId ?>&edit=<?= (int)$m['id'] ?>" title="แก้ไข" aria-label="แก้ไขรายการ MA"><?= ui_icon_html('edit', 14) ?></a>
      <form method="post" onsubmit="return confirm('ลบรายการ MA นี้?')">
        <?= csrf_field() ?><input type="hidden" name="del_ma" value="1"><input type="hidden" name="ma_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="back" value="<?= h($maListUrl) ?>">
        <button class="ma-ib is-del" type="submit" title="ลบ" aria-label="ลบรายการ MA"><?= ui_icon_html('trash', 14) ?></button>
      </form>
      <?php } ?>
    </td>
    <?php } ?>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php
if ($total > 0) {
    echo page_pager_html($page, $pages, $per, $total, function ($n) use ($productId) {
        return ma_list_qs($productId, ['page' => $n]);
    }, 'รายการ');
}
?>
<div id="ma-detail-overlay" class="notif-overlay ma-sn-overlay" hidden>
  <div class="notif-box ma-detail-modal" role="dialog" aria-modal="true" aria-labelledby="ma-detail-modal-title">
    <div class="ma-sn-modal-hd">
      <div>
        <h2 id="ma-detail-modal-title" class="ma-snippets-title">รายละเอียด MA</h2>
        <p class="muted ma-snippets-lead"><?= $maShowSnippets ? 'ข้อมูลการ MA และคำสั่งตั้งค่าหมายเลขสินค้า · กดคัดลอกทีละข้อ' : 'ข้อมูลการ MA' ?></p>
      </div>
      <button type="button" class="btn-sm btn-line" onclick="closeOverlay('ma-detail-overlay')">✕ ปิด</button>
    </div>
    <section class="ma-detail-section">
      <h3 class="ma-detail-sub h-with-icon"><?= ui_icon_html('clipboard', 14, 'h-svg') ?>ข้อมูลการ MA</h3>
      <dl class="ma-detail-dl" id="ma-detail-dl"></dl>
    </section>
    <?php // รุ่นที่ปิดแผงคำสั่ง (ตั้งค่า → คำสั่งตั้งค่าหมายเลขสินค้า) ต้องไม่โผล่ใน popup ด้วย ?>
    <?php if ($maShowSnippets) { ?>
    <section class="ma-detail-section">
      <h3 class="ma-detail-sub h-with-icon"><?= ui_icon_html("clipboard", 14, "h-svg") ?><?= h(ma_snippets_title(true)) ?></h3>
      <p class="muted ma-snippets-lead">อัปเดตตามรหัสเครื่องและฟอร์ม · กดคัดลอกทีละข้อ</p>
      <?= ma_snippets_inner_html('ma-modal-sn', ['title' => false, 'lead' => false]) ?>
    </section>
    <?php } ?>
  </div>
</div>
<script>
document.addEventListener('click', function(e){
  if (e.target.closest('.ma-row-actions a, .ma-row-actions button, .ma-row-actions form')) return;
  if (e.target.closest('a')) return;
  var row = e.target.closest('tr.ma-row-click');
  if (row) { maOpenRowModal(row); return; }
  var overlay = document.getElementById('ma-detail-overlay');
  if (overlay && !overlay.hidden && e.target === overlay) closeOverlay('ma-detail-overlay');
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') {
    var overlay = document.getElementById('ma-detail-overlay');
    if (overlay && !overlay.hidden) closeOverlay('ma-detail-overlay');
  }
});
(function(){
  var panel = document.getElementById('ma-list-filter-panel');
  var toggle = document.getElementById('ma-list-filter-toggle');
  var body = document.getElementById('ma-list-filter-body');
  if (!panel || !toggle || !body) return;
  function setOpen(open){
    panel.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    body.hidden = !open;
  }
  toggle.addEventListener('click', function(){
    setOpen(body.hidden);
  });
})();
</script>
<?php
page_footer();
