<?php
/**
 * ma.php — บันทึก MA
 *
 * ขั้นที่ 1: เลือกรุ่นสินค้า (การ์ดรูป)
 * ขั้นที่ 2: ฟอร์มบันทึก + รายการ MA ของรุ่นนั้น
 */
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/includes/ma_snippets.php';

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
 * @return array<int,string>
 */
function ma_record_items(array $rec, $col, $jsonKey) {
    $items = ma_items(isset($rec[$col]) ? $rec[$col] : '');
    if ($items) return $items;
    if (!empty($rec['versions_json'])) {
        $vj = json_decode($rec['versions_json'], true) ?: [];
        if (!empty($vj[$jsonKey])) return ma_items($vj[$jsonKey]);
    }
    return [];
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

/** แสดงรายการในคอลัมน์ตาราง (ย่อถ้ายาว) */
function ma_items_cell(array $rec, $col, $jsonKey, $max = 6) {
    $items = ma_record_items($rec, $col, $jsonKey);
    if (!$items) return '<span class="muted">-</span>';
    $show = array_slice($items, 0, $max);
    $txt = h(implode(' · ', $show));
    if (count($items) > $max) $txt .= ' <span class="muted">(+' . (count($items) - $max) . ')</span>';
    return $txt;
}

/** แสดงรายการเป็นบรรทัดพร้อมหัวข้อ */
function ma_items_html($rec) {
    $out = [];
    $groups = [
        ['ok_items', 'OK', 'status-ok', 'ปกติ', 'var(--success)'],
        ['replace_items', 'Replace', 'status-replace', 'เปลี่ยนอะไหล่', 'var(--warning)'],
        ['repair_items', 'Repair', 'status-repair', 'ซ่อม', 'var(--danger)'],
    ];
    foreach ($groups as $g) {
        $items = ma_record_items($rec, $g[0], $g[1]);
        if ($items) $out[] = '<div style="margin:2px 0"><b class="ma-item-head" style="color:' . $g[4] . '">'
                           . ui_icon_html($g[2], 13, 'ma-item-svg') . ' ' . h($g[3]) . ' (' . count($items) . '):</b> '
                           . h(implode(' · ', $items)) . '</div>';
    }
    // ข้อมูลอื่นจาก JSON เดิม (Pin IO, เวอร์ชันบอร์ด ฯลฯ)
    if (!empty($rec['versions_json'])) {
        $others = [];
        foreach (json_decode($rec['versions_json'], true) ?: [] as $k => $v) {
            if (in_array($k, ['OK', 'Replace', 'Repair'], true)) continue;
            $others[] = "$k: $v";
        }
        if ($others) $out[] = '<div class="muted" style="font-size:12.5px">' . h(implode(' | ', $others)) . '</div>';
    }
    if (!empty($rec['remark'])) $out[] = '<div style="font-size:13px">' . ui_icon_html('edit', 12, 'ma-item-svg') . ' ' . h($rec['remark']) . '</div>';
    return $out ? implode('', $out) : '<span class="muted">-</span>';
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
 * สร้างข้อความสรุป MA สำหรับส่งงานเช่า Office (จากสูตร AppSheet เดิม)
 *
 * @param string|null $replace รายการเปลี่ยนอะไหล่
 * @param string|null $repair  รายการซ่อม
 * @param string|null $fw       เวอร์ชัน FW
 * @param string|null $remark   หมายเหตุ
 * @return string
 */
function ma_rental_office_summary($replace, $repair, $fw, $remark) {
    $lines = [
        '✅ ใช้งานได้ปกติ เช็ดทำความสะอาด',
    ];
    $rep = trim((string)$replace);
    $fix = trim((string)$repair);
    if ($rep !== '') {
        $lines[] = '✨ เปลี่ยน : ' . $rep;
    }
    if ($fix !== '') {
        $lines[] = '🛠️ แก้ไข : ' . $fix;
    }
    $lines[] = '🛜 FW : ' . trim((string)$fw);
    $lines[] = '📝 Note : ' . trim((string)$remark);
    return implode("\n", $lines);
}

/**
 * แปลงหมายเลขสินค้าเป็น MAC Address สำหรับตั้งค่าใน cmdline.txt
 *
 * ใช้เลข 8 หลักท้ายจากรหัส (เช่น BS22120047 → 22:12:00:47)
 *
 * @param string $assetCode หมายเลขสินค้า / asset_code
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
    foreach ($extra as $k => $v) {
        $out .= ' ' . $k . '="' . h($v) . '"';
    }
    return $out;
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
        'mround' => isset($_GET['mround']) ? trim((string)$_GET['mround']) : '',
        'msort' => isset($_GET['msort']) ? trim((string)$_GET['msort']) : 'date_desc',
        'page' => isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1,
    ];
    foreach ($over as $k => $v) {
        $q[$k] = $v;
    }
    $parts = ['product=' . (int)$q['product']];
    foreach (['mq', 'mby', 'mr', 'mround', 'msort'] as $k) {
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
       . ' · ' . status_badge($a['status'])
       . ' · FW: ' . h($a['current_fw_version'] ?: '-')
       . ' · ผลิต ' . dthai($a['produced_at']);
    echo '</div>';

    // แจ้งเตือนอะไหล่ครบกำหนดเปลี่ยน (SD Card / Battery Backup RTC) ให้เห็นก่อนบันทึก MA
    echo part_alerts_html($a['id'], $a['produced_at']);

    $canMa = can('ma');
    $res = qr("SELECT * FROM ma_records WHERE asset_id=? ORDER BY visited_at DESC, id DESC LIMIT 15", 'i', [$a['id']]);
    echo '<b>📅 ประวัติ MA (' . $res->num_rows . ' ครั้งล่าสุด)</b>';
    if ($res->num_rows) {
        echo '<table class="list" style="margin:6px 0 12px"><tr><th>วันเวลา</th><th>FW</th><th>รายละเอียด</th><th>โดย</th>' . ($canMa ? '<th></th>' : '') . '</tr>';
        while ($m = $res->fetch_assoc()) {
            echo '<tr><td style="white-space:nowrap">' . dthai_full($m['visited_at']) . '</td>'
               . '<td>' . h($m['fw_version'] ?: '-') . '</td>'
               . '<td style="max-width:420px">' . ma_items_html($m) . ma_parts_withdrawn_html((int)$m['id']) . '</td>'
               . '<td>' . h($m['done_by'] ?: '-') . '</td>'
               . ($canMa ? '<td style="white-space:nowrap"><a class="btn btn-sm btn-line" href="' . BASE_URL . '/ma.php?edit=' . $m['id'] . '">แก้ไข</a> '
                    . '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการ MA นี้?\')">' . csrf_field()
                    . '<input type="hidden" name="del_ma" value="1"><input type="hidden" name="ma_id" value="' . $m['id'] . '">'
                    . '<button class="btn-sm btn-danger" type="submit">ลบ</button></form></td>' : '')
               . '</tr>';
        }
        echo '</table>';
    } else echo '<p class="muted" style="margin:4px 0 12px">ยังไม่เคยเข้า MA</p>';

    // การอัปเดต/เปลี่ยนชิ้นส่วนจาก update_logs
    $res = qr("SELECT updated_at, update_type, component_name, old_value, new_value, detail FROM update_logs
               WHERE asset_id=? ORDER BY updated_at DESC, id DESC LIMIT 8", 'i', [$a['id']]);
    if ($res->num_rows) {
        echo '<b>🔩 การอัปเดต FW/HW (' . $res->num_rows . ' ครั้งล่าสุด)</b>';
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
    }
    exit;
}

// ---------------------------------------------------------------
// AJAX: รายการอะไหล่/จุดตรวจที่เคยใช้กับรุ่นนี้ (สำหรับ dropdown + ค่าเริ่มต้น)
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim(isset($_GET['code']) ? $_GET['code'] : '');
    $a = qr("SELECT id, product_id, status FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
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
        'fw_options' => effective_ma_fw_options($a['product_id']),
        'prefill_ok' => $lastMa ? ma_record_items($lastMa, 'ok_items', 'OK') : [],
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
            'id'        => (int)$r['id'],
            'name'      => (string)$r['name'],
            'unit'      => (string)($r['unit'] ?? ''),
            'icon'      => img_url($r['icon_path'] ?? '') ?: '',
            'stock_qty' => ($codeKey !== '' && isset($qtyMap[$codeKey])) ? (int)$qtyMap[$codeKey] : null,
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// บันทึกการเข้า MA — เปลี่ยนสถานะเครื่อง (เครื่องเช่า/เครื่องสำรอง)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ma'])) {
    csrf_check(); require_can('ma');
    $code = trim($_POST['asset_code']);
    $a = qr("SELECT id, asset_code, product_id FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
    if (!$a) { flash_set("ไม่พบเครื่องรหัส $code", 'err'); header('Location: ' . BASE_URL . '/ma.php'); exit; }
    $maProductId = (int)$a['product_id'];

    $visited = dt_from_input($_POST['visited_at'] ?? '');
    $san = ma_sanitize_item_sets($_POST['ok_items'] ?? '', $_POST['replace_items'] ?? '', $_POST['repair_items'] ?? '');
    if ($san === null) {
        flash_set('รายการใน ✅ ปกติ / 🔄 เปลี่ยน / 🔧 ซ่อม ห้ามซ้ำกันข้ามช่อง', 'err');
        header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId);
        exit;
    }
    $okItems = $san['ok'];
    $repItems = $san['replace'];
    $fixItems = $san['repair'];
    // ผลรวมของรอบนี้: มีซ่อม > มีเปลี่ยน > ปกติ
    $result = $fixItems ? 'repair' : ($repItems ? 'replace' : ($okItems ? 'ok' : null));
    $fw      = trim($_POST['fw_version']);
    $newStatus = in_array($_POST['machine_status'], ['rental','spare'], true) ? $_POST['machine_status'] : 'rental';
    $roundAlloc = ma_round_lock_acquire((int) $a['id']);
    if (!$roundAlloc['ok']) {
        flash_set($roundAlloc['error'], 'err');
        header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId);
        exit;
    }
    $round = $roundAlloc['round'];
    $maRoundLock = $roundAlloc['lock_key'];

    try {
    q("INSERT INTO ma_records (asset_id,ma_round,visited_at,result,ok_items,replace_items,repair_items,fw_version,remark,done_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)", 'iissssssss',
      [$a['id'], $round, $visited, $result, $okItems ?: null, $repItems ?: null, $fixItems ?: null,
       $fw ?: null, trim($_POST['remark']) ?: null, actor_name()]);

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
    if ($result === 'repair' && function_exists('line_notify_instant_enabled') && line_notify_instant_enabled('ma.repair_required') && date('Y-m-d', strtotime($visited)) === date('Y-m-d')) {
        line_notify_dispatch('ma.repair_required', [
            'asset_id'     => (int)$a['id'],
            'asset_code'   => (string)$a['asset_code'],
            'ma_round'     => (int)$round,
            'visited_at'   => $visited,
            'repair_items' => (string)$fixItems,
            'remark'       => trim((string)($_POST['remark'] ?? '')),
            'done_by'      => actor_name(),
            'entity_id'    => $maRecordId,
        ], [
            'dedup_key' => 'ma.repair_required:' . $maRecordId,
        ]);
    }
    flash_set($flashMsg);
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
    $rec = qr("SELECT m.asset_id, a.product_id FROM ma_records m JOIN assets a ON a.id=m.asset_id WHERE m.id=?", 'i', [$mid])->fetch_assoc();
    if (!$rec) { flash_set('ไม่พบรายการ MA ที่จะแก้ไข', 'err'); header('Location: ' . BASE_URL . '/ma.php'); exit; }
    $maProductId = (int)$rec['product_id'];
    $visited = dt_from_input($_POST['visited_at'] ?? '');
    $san = ma_sanitize_item_sets($_POST['ok_items'] ?? '', $_POST['replace_items'] ?? '', $_POST['repair_items'] ?? '');
    if ($san === null) {
        flash_set('รายการใน ✅ ปกติ / 🔄 เปลี่ยน / 🔧 ซ่อม ห้ามซ้ำกันข้ามช่อง', 'err');
        header('Location: ' . BASE_URL . '/ma.php?edit=' . $mid);
        exit;
    }
    $okItems = $san['ok'];
    $repItems = $san['replace'];
    $fixItems = $san['repair'];
    $result = $fixItems ? 'repair' : ($repItems ? 'replace' : ($okItems ? 'ok' : null));
    $fw = trim($_POST['fw_version']);
    q("UPDATE ma_records SET visited_at=?, result=?, ok_items=?, replace_items=?, repair_items=?, fw_version=?, remark=? WHERE id=?",
      'sssssssi', [$visited, $result, $okItems ?: null, $repItems ?: null, $fixItems ?: null, $fw ?: null, trim($_POST['remark']) ?: null, $mid]);
    $newStatus = in_array($_POST['machine_status'], ['rental', 'spare'], true) ? $_POST['machine_status'] : null;
    if ($newStatus) q("UPDATE assets SET status=? WHERE id=?", 'si', [$newStatus, $rec['asset_id']]);
    if ($fw !== '') q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$fw, $rec['asset_id']]);
    $assetRow = qr('SELECT asset_code FROM assets WHERE id=?', 'i', [(int)$rec['asset_id']])->fetch_assoc();
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
    flash_set('แก้ไขรายการ MA เรียบร้อยแล้ว');
    header('Location: ' . BASE_URL . '/ma.php?product=' . $maProductId); exit;
}
// ---------------------------------------------------------------
// ลบรายการ MA
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ma'])) {
    csrf_check(); require_can('ma');
    $mid = (int)$_POST['ma_id'];
    $rec = qr("SELECT asset_id FROM ma_records WHERE id=?", 'i', [$mid])->fetch_assoc();
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
    q("DELETE FROM ma_records WHERE id=?", 'i', [$mid]);
    if ($rec) {
        recompute_asset_status_from_ma((int) $rec['asset_id']);
    }
    flash_set('ลบรายการ MA เรียบร้อยแล้ว');
    $back = (isset($_POST['back']) && $_POST['back'] !== '') ? $_POST['back']
          : ($rec ? BASE_URL . '/asset.php?id=' . $rec['asset_id'] : BASE_URL . '/ma.php');
    header('Location: ' . $back); exit;
}

require __DIR__ . '/includes/layout.php';

$productId  = (int)(isset($_GET['product']) ? $_GET['product'] : 0);
$recAssetId = (int)(isset($_GET['record']) ? $_GET['record'] : 0);
$recAsset = $recAssetId ? qr("SELECT id, asset_code, status, product_id FROM assets WHERE id=?", 'i', [$recAssetId])->fetch_assoc() : null;
$editId = (int)(isset($_GET['edit']) ? $_GET['edit'] : 0);
$editRec = $editId ? qr("SELECT m.*, a.asset_code, a.status ast_status, a.product_id
                         FROM ma_records m JOIN assets a ON a.id=m.asset_id WHERE m.id=?", 'i', [$editId])->fetch_assoc() : null;
$formOpen = ($recAsset || $editRec) ? true : false;

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
    ?>
    <p class="muted" style="margin-bottom:12px">เลือกรุ่นสินค้าเพื่อดูประวัติ MA และบันทึกรายการของรุ่นนั้น</p>
    <div style="margin-bottom:12px">
      <input type="text" id="prod-filter" placeholder="พิมพ์กรองชื่อรุ่น…" style="width:min(320px,100%)">
    </div>
    <div class="grid-products" id="prod-grid">
      <?php while ($p = $products->fetch_assoc()) { ?>
        <a class="pcard" href="<?= BASE_URL ?>/ma.php?product=<?= (int)$p['id'] ?>"
           data-name="<?= h(mb_strtolower($p['name'] . ' ' . $p['product_code'])) ?>"
           style="text-decoration:none; color:inherit">
          <?= img_tag($p['icon_path'], $p['name'], 'thumb-lg') ?>
          <div class="pname"><?= h($p['name']) ?></div>
          <div class="pmeta">
            <?= h($p['product_code']) ?><br>
            <?= number_format((int)$p['assets_n']) ?> เครื่อง ·
            <b><?= number_format((int)$p['ma_n']) ?></b> รายการ MA
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

$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$per = 50;
$off = ($page - 1) * $per;

$mq = trim(isset($_GET['mq']) ? $_GET['mq'] : '');       // หมายเลขเครื่อง
$mby = trim(isset($_GET['mby']) ? $_GET['mby'] : '');     // ผู้บันทึก
$mr = trim(isset($_GET['mr']) ? $_GET['mr'] : '');       // รายละเอียด
$mround = trim(isset($_GET['mround']) ? $_GET['mround'] : ''); // รอบ MA
$msort = trim(isset($_GET['msort']) ? $_GET['msort'] : 'date_desc');

$maWhere = ['a.product_id=?'];
$maTypes = 'i';
$maParams = [$productId];
if ($mq !== '') { $maWhere[] = 'a.asset_code LIKE ?'; $maTypes .= 's'; $maParams[] = "%$mq%"; }
if ($mby !== '') { $maWhere[] = 'm.done_by LIKE ?'; $maTypes .= 's'; $maParams[] = "%$mby%"; }
if ($mround !== '') {
    $maWhere[] = 'CAST(m.ma_round AS CHAR) LIKE ?';
    $maTypes .= 's';
    $maParams[] = '%' . $mround . '%';
}
if ($mr !== '') {
    $maWhere[] = '(m.ok_items LIKE ? OR m.replace_items LIKE ? OR m.repair_items LIKE ? OR m.remark LIKE ?)';
    $maTypes .= 'ssss';
    $like = "%$mr%";
    array_push($maParams, $like, $like, $like, $like);
}
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

$total = (int)qr("SELECT COUNT(*) c FROM ma_records m
                  JOIN assets a ON a.id=m.asset_id
                  $maW", $maTypes, $maParams)->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $per));
$recentMA = qr("SELECT m.*, a.asset_code, a.id asset_id FROM ma_records m
                JOIN assets a ON a.id=m.asset_id
                $maW
                ORDER BY $maOrder
                LIMIT $per OFFSET $off", $maTypes, $maParams);

$maListUrl = BASE_URL . '/ma.php?product=' . $productId;
$maClearUrl = $maListUrl;

page_header('บันทึก MA — ' . $product['name'] . ' (' . number_format($total) . ')');
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
        ['หมายเลขสินค้า', d.asset || d.code || '-', false],
        ['✅ ใช้งานได้ปกติ', d.ok || '-', true],
        ['🔄 เปลี่ยนอะไหล่', d.replaceDisp || '-', true],
        ['🔩 อะไหล่ที่เบิก (MA)', d.partsWithdraw || '-', true],
        ['🔧 ซ่อม', d.repairDisp || '-', true],
        ['Firmware หลังตรวจ', d.fw || '-', false],
        ['ผู้บันทึก', d.by || '-', false],
        ['หมายเหตุ', d.remark || '-', false]
      ];
      dl.innerHTML = rows.map(function(r){
        var val = r[1];
        var dd = r[2] && val && val !== '-' && val.indexOf('\n') >= 0
          ? val.split('\n').map(escHtml).join('<br>')
          : escHtml(val);
        return '<div class="ma-detail-row"><dt>' + escHtml(r[0]) + '</dt><dd>' + dd + '</dd></div>';
      }).join('');
    }
    window.resetMaCopyButtonsInScope('ma-modal-sn');
    window.maFillSnippets('ma-modal-sn', {
      code: d.code || '',
      replace: d.replace || '',
      repair: d.repair || '',
      fw: d.fw || '',
      remark: d.remark || ''
    });
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
<button type="button" class="btn" id="ma-add-btn" onclick="document.getElementById('ma-form-wrap').hidden=false; this.hidden=true;" <?= $formOpen ? 'hidden' : '' ?>>➕ เพิ่มรายการ MA</button>
<div id="ma-form-wrap" <?= $formOpen ? '' : 'hidden' ?>>
<h3 style="margin:4px 0 10px" class="h-with-icon"><?= ui_icon_html('edit', 15, 'h-svg') ?><span><?= $ea ? 'แก้ไขรายการ MA' : 'เพิ่มรายการ MA ใหม่' ?></span></h3>
<?php $maFwOpts = effective_ma_fw_options($productId); ?>
<div class="ma-page-grid">
  <div class="ma-form-col">
    <form method="post" class="formgrid form-wide ma-formgrid" id="ma-form">
      <?= csrf_field() ?>
      <?php if ($ea) { ?><input type="hidden" name="edit_ma" value="1"><input type="hidden" name="edit_id" value="<?= (int)$editId ?>"><?php } else { ?><input type="hidden" name="new_ma" value="1"><?php } ?>
      <input type="hidden" name="ok_items" id="h-ok">
      <input type="hidden" name="replace_items" id="h-replace">
      <input type="hidden" name="repair_items" id="h-repair">

      <label for="ma_code">หมายเลขสินค้า</label>
      <div>
        <input type="text" name="asset_code" id="ma_code" class="asset-search" data-product="<?= (int)$productId ?>" value="<?= h($ea ? $ea['asset_code'] : ($recAsset ? $recAsset['asset_code'] : '')) ?>" <?= $ea ? 'readonly' : '' ?> required placeholder="พิมพ์เลือก S/N">
        <div class="muted ma-field-hint">รุ่น <?= h($product['name']) ?> · พิมพ์แล้วเลือกจากรายการ</div>
      </div>

      <label for="ma_visited_at">วันเวลาเข้า MA</label>
      <input type="datetime-local" name="visited_at" id="ma_visited_at" value="<?= h(dt_for_input($ea ? $ea['visited_at'] : dt_now())) ?>">

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

      <label for="machine_status">สถานะเครื่อง</label>
      <select name="machine_status" id="machine_status" required>
        <option value="rental" <?= $ea && $ea['ast_status'] === 'rental' ? 'selected' : '' ?>>เครื่องเช่า</option>
        <option value="spare" <?= $ea && $ea['ast_status'] === 'spare' ? 'selected' : '' ?>>เครื่องสำรอง</option>
      </select>

      <label for="ma_fw_input">Firmware หลังตรวจ</label>
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

      <label for="ma_remark" class="full">หมายเหตุ</label>
      <textarea name="remark" id="ma_remark" class="full field-note ma-remark" rows="2" placeholder="บันทึกเพิ่มเติม…"><?= h($ea ? $ea['remark'] : ($maProductPrefill['remark'] ?? '')) ?></textarea>

      <div class="full ma-form-actions">
        <button type="submit" id="ma-submit-btn"><?= $ea ? '💾 บันทึกการแก้ไข' : '💾 บันทึก MA' ?></button>
        <?php if ($ea) { ?><a class="btn btn-line" href="<?= h($maListUrl) ?>">ยกเลิก</a><?php } ?>
      </div>
    </form>
    <div id="ma-history" class="ma-history-box"></div>
  </div>

  <aside class="panel ma-snippets-panel" id="ma-snippets">
    <?= ma_snippets_inner_html('ma-sn') ?>
  </aside>
</div>
</div><!-- /ma-form-wrap -->

<script>
<?php $maEditWithdrawLines = $editId ? ma_withdrawal_lines($editId) : []; ?>
var maEditWithdrawLines = <?= json_encode($maEditWithdrawLines, JSON_UNESCAPED_UNICODE) ?>;
var BASE = '<?= BASE_URL ?>';
var MA_FW_OPTS = <?= json_encode(array_values($maFwOpts), JSON_UNESCAPED_UNICODE) ?>;
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
  window.maFillSnippets('ma-sn', {
    code: (document.getElementById('ma_code') || {}).value || '',
    replace: chipValues('replace').join(' , '),
    repair: chipValues('repair').join(' , '),
    fw: maFwValue(),
    remark: (document.getElementById('ma_remark') || {}).value || ''
  });
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
  return '<span class="chip chip-pick ma-part-chip-sel">'
    + maPartThumbHtml(partId)
    + '<span class="ma-part-chip-label">' + esc(label) + '</span></span>';
}
function maPartOptsHtml(selId, filter){
  filter = (filter || '').toLowerCase();
  var list = maPartsAll.filter(function(p){
    return !filter || p.name.toLowerCase().indexOf(filter) !== -1;
  });
  if (!list.length) {
    return '<div class="ma-part-opt-empty muted">ไม่พบอะไหล่' + (filter ? ' ที่ตรงกับ "' + esc(filter) + '"' : '') + '</div>';
  }
  return '<div class="ma-part-opt-items">' + list.map(function(p){
    var sel = p.id === selId ? ' is-selected' : '';
    return '<div class="ma-part-opt-row' + sel + '" data-pid="' + p.id + '" role="button" tabindex="0">'
      + maPartThumbHtml(p.id)
      + '<div class="ma-part-opt-body">'
      + '<span class="ma-part-opt-name">' + esc(p.name) + '</span>'
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
    + '<input type="text" class="chip-dd-filter ma-part-filter" placeholder="' + (label ? '' : 'ค้นหาอะไหล่…') + '" autocomplete="off">'
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
    filt.placeholder = partId ? '' : 'ค้นหาอะไหล่…';
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
  slot.innerHTML = chipDdHtml('fw_version', cur, MA_FW_OPTS, '', 'chip_single_free');
  initChipDd(slot);
  slot.addEventListener('click', function(){ setTimeout(updateMaSnippets, 0); });
  slot.addEventListener('keyup', updateMaSnippets, true);
  var fwFilt = slot.querySelector('.chip-dd-filter');
  if (fwFilt) fwFilt.addEventListener('input', updateMaSnippets);
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initMaFwField);
} else {
  initMaFwField();
}

<?php if ($editRec) { ?>
(function(){
  var ed = <?= json_encode([
    'ok' => ma_record_items($editRec, 'ok_items', 'OK'),
    'replace' => ma_record_items($editRec, 'replace_items', 'Replace'),
    'repair' => ma_record_items($editRec, 'repair_items', 'Repair'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  FIELDS.forEach(function(k){ (ed[k] || []).forEach(function(v){ addChip(k, v, true); }); });
})();
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
  if (!pool.length) {
    list.innerHTML = '<div class="muted ma-pick-empty">ยังไม่มีรายการของรุ่นนี้ — พิมพ์แล้วกด Enter เพื่อเพิ่มเอง</div>';
  } else if (!shown.length) {
    var msg = Object.keys(selectedHere).length
      ? 'เลือกครบแล้ว — ลบจากแท็กด้านบนเพื่อเลือกใหม่'
      : 'ไม่มีรายการที่เลือกได้ (ถูกใช้ในช่องอื่นแล้ว หรือไม่ตรงคำค้น)';
    list.innerHTML = '<div class="muted ma-pick-empty">' + msg + '</div>';
  } else {
    var tools = '<div class="ma-pick-tools">'
      + '<button type="button" class="btn-sm btn-line" data-act="all">เลือกทั้งหมดที่แสดง</button>'
      + '<span class="muted ma-pick-count">ติ๊กเลือกทีละรายการ · เหลือ ' + shown.length + '</span></div>';
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

document.getElementById('ma_remark').addEventListener('input', updateMaSnippets);

(function(){
  var input = document.getElementById('ma_code');
  var box = document.getElementById('ma-history');
  var timer = null;
  function loadAll(){
    updateMaSnippets();
    var code = input.value.trim();
    if (code.length < 4) { box.innerHTML = ''; return; }
    box.innerHTML = '<span class="muted">กำลังค้นหาประวัติ…</span>';
    fetch(BASE + '/ma.php?ajax=history&code=' + encodeURIComponent(code))
      .then(function(r){ return r.text(); }).then(function(html){ box.innerHTML = html; }).catch(function(){});
    fetch(BASE + '/ma.php?ajax=items&code=' + encodeURIComponent(code))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.found) return;
        itemPools.ok = d.pool_ok || d.pool || [];
        itemPools.replace = d.pool_replace || d.pool || [];
        itemPools.repair = d.pool_repair || d.pool || [];
        if (d.status === 'spare' || d.status === 'rental') document.getElementById('machine_status').value = d.status;
        if (document.getElementById('chips-ok').children.length === 0) {
          (d.prefill_ok || []).forEach(function(it){ addChip('ok', it, true); });
        }
      }).catch(function(){});
  }
  input.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(loadAll, 600); });
  input.addEventListener('change', loadAll);
  if (input.value.trim() !== '') loadAll();
  else updateMaSnippets();
})();
</script>
<?php } ?>

<h2>MA ของรุ่นนี้<?= $total ? ' (' . number_format($total) . ')' : '' ?></h2>
<p class="muted ma-table-hint">คลิกแถวเพื่อดูรายละเอียด MA และข้อความประจำสินค้า · กดหัวคอลัมน์เพื่อจัดเรียง</p>
<?php
list_search_form([
    ['name' => 'mq', 'placeholder' => 'หมายเลขเครื่อง', 'value' => $mq, 'width' => '140px'],
    ['name' => 'mround', 'placeholder' => 'รอบ MA', 'value' => $mround, 'width' => '80px'],
    ['name' => 'mby', 'placeholder' => 'ผู้บันทึก', 'value' => $mby, 'width' => '110px'],
    ['name' => 'mr', 'placeholder' => 'รายละเอียด', 'value' => $mr, 'width' => '160px'],
], $maClearUrl, ['product' => $productId, 'msort' => $msort !== 'date_desc' ? $msort : '']);
?>
<?php if ($total === 0) { ?>
  <p class="muted">ยังไม่มีรายการ MA ของรุ่นนี้</p>
<?php } else { ?>
<div class="table-wrap">
<table class="list ma-list-table">
  <tr>
    <th><?= ma_sort_th('วันเวลา', 'date_asc', 'date_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('รอบ', 'round_asc', 'round_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('เครื่อง', 'asset_asc', 'asset_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('✅ ปกติ', 'ok_asc', 'ok_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('🔄 เปลี่ยน', 'replace_asc', 'replace_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('🔧 ซ่อม', 'repair_asc', 'repair_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('FW', 'fw_asc', 'fw_desc', $msort, $productId) ?></th>
    <th><?= ma_sort_th('โดย', 'by_asc', 'by_desc', $msort, $productId) ?></th>
    <?= can('ma') ? '<th></th>' : '' ?>
  </tr>
  <?php while ($m = $recentMA->fetch_assoc()) { ?>
  <tr class="ma-row-click" title="คลิกดูรายละเอียด MA และข้อความประจำสินค้า" <?= ma_row_data_attrs($m) ?>>
    <td style="white-space:nowrap"><?= dthai_full($m['visited_at']) ?></td>
    <td style="text-align:center"><?= $m['ma_round'] ? (int)$m['ma_round'] : '-' ?></td>
    <td><a href="<?= BASE_URL ?>/asset.php?id=<?= (int)$m['asset_id'] ?>"><?= h($m['asset_code']) ?></a></td>
    <td style="max-width:200px; font-size:12.5px"><?= ma_items_cell($m, 'ok_items', 'OK') ?></td>
    <td style="max-width:180px; font-size:12.5px"><?= ma_items_cell($m, 'replace_items', 'Replace') ?></td>
    <td style="max-width:160px; font-size:12.5px"><?= ma_items_cell($m, 'repair_items', 'Repair') ?></td>
    <td><?= h($m['fw_version'] ?: '-') ?></td>
    <td><?= h($m['done_by'] ?: '-') ?></td>
    <?php if (can('ma')) { ?>
    <td class="ma-row-actions" style="white-space:nowrap">
      <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/ma.php?product=<?= $productId ?>&edit=<?= (int)$m['id'] ?>">แก้ไข</a>
      <form method="post" style="display:inline" onsubmit="return confirm('ลบรายการ MA นี้?')">
        <?= csrf_field() ?><input type="hidden" name="del_ma" value="1"><input type="hidden" name="ma_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="back" value="<?= h($maListUrl) ?>">
        <button class="btn-sm btn-danger" type="submit">ลบ</button>
      </form>
    </td>
    <?php } ?>
  </tr>
  <?php } ?>
</table>
</div>
<?php if ($pages > 1) { ?>
<div class="pager">
  <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
      echo $i === $page ? "<span class='cur'>$i</span>" : "<a href='" . h(ma_list_qs($productId, ['page' => $i])) . "'>$i</a>";
  } ?>
  <span class="muted" style="border:0;background:none"><?= number_format($total) ?> รายการ</span>
</div>
<?php }
}
?>
<div id="ma-detail-overlay" class="notif-overlay ma-sn-overlay" hidden>
  <div class="notif-box ma-detail-modal" role="dialog" aria-modal="true" aria-labelledby="ma-detail-modal-title">
    <div class="ma-sn-modal-hd">
      <div>
        <h2 id="ma-detail-modal-title" class="ma-snippets-title">รายละเอียด MA</h2>
        <p class="muted ma-snippets-lead">ข้อมูลการ MA และข้อความประจำสินค้า · กดคัดลอกทีละข้อ</p>
      </div>
      <button type="button" class="btn-sm btn-line" onclick="closeOverlay('ma-detail-overlay')">✕ ปิด</button>
    </div>
    <section class="ma-detail-section">
      <h3 class="ma-detail-sub h-with-icon"><?= ui_icon_html('clipboard', 14, 'h-svg') ?>ข้อมูลการ MA</h3>
      <dl class="ma-detail-dl" id="ma-detail-dl"></dl>
    </section>
    <section class="ma-detail-section">
      <h3 class="ma-detail-sub h-with-icon"><?= ui_icon_html('clipboard', 14, 'h-svg') ?>ข้อความประจำสินค้า</h3>
      <p class="muted ma-snippets-lead">อัปเดตตามหมายเลขสินค้าและฟอร์ม · กดคัดลอกทีละข้อ</p>
      <?= ma_snippets_inner_html('ma-modal-sn', ['title' => false, 'lead' => false]) ?>
    </section>
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
</script>
<?php
page_footer();
