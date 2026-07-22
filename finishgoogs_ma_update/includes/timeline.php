<?php
/** timeline.php — สร้างรายการ timeline ของเครื่อง (ใช้ร่วมกันระหว่าง asset.php และ popup เจาะลึกจาก dashboard) */

/** จัดรูปเลขจำนวน (ตัด .00) */
function qty_fmt($v) { return rtrim(rtrim(number_format((float)$v, 2), '0'), '.'); }

/**
 * รวมประวัติทุกประเภทของเครื่อง → ['tl' => รายการเรียงใหม่→เก่า, 'partsUsed' => สรุปอะไหล่ที่เบิก]
 * แต่ละรายการ: type_key สำหรับจัดกลุ่ม · type เป็น HTML SVG · html เป็นเนื้อหา (escape แล้ว)
 */
function asset_timeline_items($id) {
    $id = (int)$id;
    $tl = [];
    $acRow = qr('SELECT asset_code FROM assets WHERE id=?', 'i', [$id])->fetch_assoc();
    $assetCode = $acRow ? (string)$acRow['asset_code'] : '';
    $res = qr("SELECT id, recorded_at d, made_by, assembly_by, fw_version, problems_found, fix, checklist, lot_label, extra_json
               FROM production_records WHERE asset_id=?", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $body = [];
        if ($r['made_by'])       $body[] = 'ผู้ผลิต: ' . h($r['made_by']);
        if ($r['assembly_by'])   $body[] = 'ประกอบ: ' . h($r['assembly_by']);
        if ($r['fw_version'])    $body[] = 'Firmware: ' . h($r['fw_version']);
        if ($r['lot_label'])     $body[] = 'Lot: ' . h($r['lot_label']);
        if ($r['problems_found'] && $r['problems_found'] !== '-') $body[] = 'ปัญหาที่พบ: ' . h($r['problems_found']);
        if ($r['fix'] && $r['fix'] !== '-')                       $body[] = 'การแก้ไข: ' . h($r['fix']);
        if ($r['checklist'])     $body[] = 'Checklist: ' . h($r['checklist']);
        if ($r['extra_json']) foreach (json_decode($r['extra_json'], true) ?: [] as $k => $v) $body[] = h("$k: $v");
        $tl[] = ['d' => $r['d'], 'type_key' => 'production', 'type' => ui_timeline_type_html('production'), 'html' => implode('<br>', $body), 'kind' => 'production', 'rid' => (int)$r['id']];
    }
    $res = qr("SELECT id, updated_at d, update_type, component_name, old_value, new_value, detail, image1, image2, made_by
               FROM update_logs WHERE asset_id=?", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $body = [];
        if ($r['component_name']) $body[] = 'ชิ้นส่วน: ' . h($r['component_name']);
        if ($r['old_value'] || $r['new_value']) $body[] = h($r['old_value'] ?: '?') . ' → ' . h($r['new_value'] ?: '?');
        if ($r['detail']) $body[] = h($r['detail']);
        if ($r['made_by']) $body[] = 'โดย: ' . h($r['made_by']);
        $imgs = '';
        foreach (['image1', 'image2'] as $f) {
            if ($r[$f]) $imgs .= '<a href="' . h(img_url($r[$f])) . '" target="_blank"><img src="' . h(img_url($r[$f])) . '" loading="lazy" onerror="this.parentNode.remove()"></a>';
        }
        if ($imgs) $body[] = '<span class="tl-imgs">' . $imgs . '</span>';
        $typeKey = $r['update_type'] === 'firmware' ? 'update_fw' : ($r['update_type'] === 'hardware' ? 'update_hw' : 'update');
        $fw = ($r['update_type'] === 'firmware') ? trim((string)($r['new_value'] ?? '')) : '';
        $tl[] = [
            'd' => $r['d'], 'type_key' => $typeKey, 'type' => ui_timeline_type_html($typeKey),
            'html' => implode('<br>', $body), 'kind' => 'update', 'rid' => (int)$r['id'],
            'snippet' => [
                'code' => $assetCode,
                'replace' => '',
                'repair' => '',
                'fw' => $fw,
                'remark' => trim((string)($r['detail'] ?? '')),
            ],
        ];
    }
    $res = qr("SELECT id, visited_at d, ma_round, result, fw_version, ok_items, replace_items, repair_items, versions_json, remark, done_by
               FROM ma_records WHERE asset_id=?", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $vj = $r['versions_json'] ? (json_decode($r['versions_json'], true) ?: []) : [];
        $grab = function ($col, $key) use ($r, $vj) {
            $txt = trim((string)$r[$col]);
            if ($txt === '' && isset($vj[$key])) $txt = (string)$vj[$key];
            return array_values(array_filter(array_map('trim', explode(',', $txt)), function ($x) { return $x !== '' && $x !== '-'; }));
        };
        $chipRow = function ($label, $items, $cls) {
            if (!$items) return '';
            $chips = '';
            foreach ($items as $it) $chips .= '<span class="chip ' . $cls . '">' . h($it) . '</span>';
            return '<div class="tl-sec"><span class="tl-sec-label">' . h($label) . '</span><span class="chips">' . $chips . '</span></div>';
        };
        $body = [];
        $head = [];
        if ($r['result']) $head[] = ['ok' => 'ปกติ', 'replace' => 'เปลี่ยนอุปกรณ์', 'repair' => 'ต้องซ่อม'][$r['result']];
        if ($r['fw_version']) $head[] = 'FW ' . h($r['fw_version']);
        if ($head) $body[] = '<b>' . implode(' · ', $head) . '</b>';
        $secs = $chipRow('ปกติ', $grab('ok_items', 'OK'), 'chip-ok')
              . $chipRow('เปลี่ยน', $grab('replace_items', 'Replace'), 'chip-replace')
              . $chipRow('ซ่อม', $grab('repair_items', 'Repair'), 'chip-repair');
        if ($secs) $body[] = $secs;
        $others = [];
        foreach ($vj as $k => $v) if (!in_array($k, ['OK', 'Replace', 'Repair'], true)) $others[] = "$k: $v";
        if ($others) $body[] = '<span class="muted" style="font-size:12.5px">' . h(implode('  |  ', $others)) . '</span>';
        if ($r['remark']) $body[] = h($r['remark']);
        if ($r['done_by']) $body[] = 'โดย: ' . h($r['done_by']);
        $tl[] = [
            'd' => $r['d'] . ' 00:00:00', 'type_key' => 'ma', 'type' => ui_timeline_type_html('ma'),
            'html' => implode('<br>', $body), 'kind' => 'ma', 'rid' => (int)$r['id'],
            'snippet' => [
                'code' => $assetCode,
                'replace' => implode(' , ', $grab('replace_items', 'Replace')),
                'repair' => implode(' , ', $grab('repair_items', 'Repair')),
                'fw' => (string)($r['fw_version'] ?? ''),
                'remark' => (string)($r['remark'] ?? ''),
            ],
        ];
    }
    $res = qr("SELECT r.opened_at d, r.reported_issue, r.assessment, r.action_taken, r.status, r.closed_at, c.name cust
               FROM repairs r LEFT JOIN customers c ON c.id=r.customer_id WHERE r.asset_id=?", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $body = [];
        if ($r['cust']) $body[] = 'ลูกค้า: ' . h($r['cust']);
        if ($r['reported_issue'] && $r['reported_issue'] !== '-') $body[] = 'อาการแจ้ง: ' . h($r['reported_issue']);
        if ($r['assessment']) $body[] = 'การประเมิน: ' . h($r['assessment']);
        if ($r['action_taken']) $body[] = 'การแก้ไข: ' . h($r['action_taken']);
        $stmap = ['received' => 'รับเครื่องแล้ว', 'in_progress' => 'กำลังซ่อม', 'done' => 'ซ่อมเสร็จ', 'returned' => 'ส่งคืนแล้ว'];
        $body[] = 'สถานะงาน: ' . $stmap[$r['status']] . ($r['closed_at'] ? ' (ปิดงาน ' . dthai($r['closed_at']) . ')' : '');
        $tl[] = ['d' => $r['d'] . ' 00:00:00', 'type_key' => 'repair', 'type' => ui_timeline_type_html('repair'), 'html' => implode('<br>', $body)];
    }
    $res = qr("SELECT moved_at d, direction, reason, made_by, remark FROM stock_movements WHERE asset_id=?", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $body = [];
        if ($r['reason']) $body[] = h($r['reason']);
        if ($r['made_by']) $body[] = 'โดย: ' . h($r['made_by']);
        if ($r['remark']) $body[] = h($r['remark']);
        $typeKey = $r['direction'] === 'in' ? 'stock_in' : 'stock_out';
        $tl[] = ['d' => $r['d'], 'type_key' => $typeKey, 'type' => ui_timeline_type_html($typeKey), 'html' => implode('<br>', $body)];
    }
    $res = qr("SELECT s.out_at d, s.expected_return_at, s.returned_at, s.status, a2.asset_code replaces, c.name cust
               FROM spare_loans s LEFT JOIN assets a2 ON a2.id=s.replaces_asset_id LEFT JOIN customers c ON c.id=s.customer_id
               WHERE s.spare_asset_id=?", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $body = [];
        if ($r['replaces']) $body[] = 'ไปแทนเครื่อง: ' . h($r['replaces']);
        if ($r['cust']) $body[] = 'ที่ลูกค้า: ' . h($r['cust']);
        if ($r['expected_return_at']) $body[] = 'กำหนดคืน: ' . dthai($r['expected_return_at']);
        $body[] = $r['status'] === 'out' ? 'สถานะ: ยังไม่คืน' : 'คืนแล้ว ' . dthai($r['returned_at']);
        $tl[] = ['d' => $r['d'] . ' 00:00:00', 'type_key' => 'spare_loan', 'type' => ui_timeline_type_html('spare_loan'), 'html' => implode('<br>', $body)];
    }
    $partsUsed = [];
    $res = qr("SELECT pm.id, pm.moved_at d, pm.direction, pm.qty, pm.mode, pm.made_by, pm.remark, p.name pname, p.unit
               FROM part_movements pm JOIN parts p ON p.id=pm.part_id WHERE pm.ref_asset_id=? ORDER BY pm.moved_at", 'i', [$id]);
    while ($r = $res->fetch_assoc()) {
        $qtyTxt = qty_fmt($r['qty']) . ($r['unit'] ? ' ' . h($r['unit']) : '');
        $body = ['อะไหล่: <b>' . h($r['pname']) . '</b> × ' . $qtyTxt];
        if ($r['mode'])    $body[] = h($r['mode']);
        if ($r['made_by']) $body[] = 'โดย: ' . h($r['made_by']);
        if ($r['remark'])  $body[] = h($r['remark']);
        $typeKey = $r['direction'] === 'out' ? 'part_out' : 'part_in';
        $tl[] = ['d' => $r['d'], 'type_key' => $typeKey, 'type' => ui_timeline_type_html($typeKey), 'html' => implode('<br>', $body), 'kind' => 'part_move', 'rid' => (int)$r['id']];
        if ($r['direction'] === 'out') {
            $key = $r['pname'] . '|' . (string)$r['unit'];
            if (!isset($partsUsed[$key])) $partsUsed[$key] = ['name' => $r['pname'], 'unit' => $r['unit'], 'qty' => 0];
            $partsUsed[$key]['qty'] += (float)$r['qty'];
        }
    }

    usort($tl, function ($x, $y) { return strcmp($y['d'], $x['d']); });
    return ['tl' => $tl, 'partsUsed' => $partsUsed];
}

/** แปลง timeline items → HTML <ul class="timeline"> */
function asset_timeline_html($tl) {
    $out = '<ul class="timeline">';
    foreach ($tl as $e) {
        $out .= '<li><div class="tl-date">' . dthai_full($e['d']) . '</div>'
              . '<div class="tl-type">' . $e['type'] . '</div>'
              . '<div style="font-size:13.5px; margin-top:3px">' . $e['html'] . '</div></li>';
    }
    $out .= '</ul>';
    if (!$tl) $out .= '<p class="muted">ยังไม่มีประวัติ</p>';
    return $out;
}
