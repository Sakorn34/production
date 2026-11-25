<?php
/**
 * includes/asset_sales_timeline.php — การ์ด "การขาย / เช่า" บนหน้าเครื่อง แบบไทม์ไลน์ข้ามระบบ
 *
 * ข้อมูลการขาย/เช่าของเครื่องหนึ่งกระจายอยู่หลายระบบ เดิมการ์ดยำทุกแหล่งรวมเป็นแถวเดียว
 * คนอ่านจึงไม่รู้ว่าค่าไหนมาจากไหน (เช่น "วันที่ขาย" ที่จริงคือวันลงทะเบียน stock)
 * และถ้าเจอในระบบเช่า การ์ดเช่าจะแสดงแทนการ์ดขายทั้งใบ ประวัติขายเลยหายไป
 *
 * การ์ดนี้แตกทุกแหล่งเป็นเหตุการณ์ที่มีวันที่ + ป้ายระบบต้นทาง แล้วเรียงเก่า → ใหม่
 *   prod    ระบบผลิต        assets.produced_at
 *   stock   ทะเบียน stock    biton_stockparts.stock · stock_movements · po_order_parts
 *   old     stock เก่า       biton_stockparts.stock_old
 *   sale    ระบบขาย         biton_setup.equipment_claim_history · po_order_part_serials
 *   install ประวัติติดตั้ง    installation_history (ระบบ installation เดิม 2010–2023)
 *   rent    ระบบเช่า        biton_leasing
 */

require_once __DIR__ . '/installation_history.php';

/** ชื่อและลำดับของแหล่งข้อมูล — ลำดับใช้ตัดสินเหตุการณ์วันเดียวกัน */
function ast_sources(): array
{
    return [
        'prod'    => 'ระบบผลิต',
        'stock'   => 'ทะเบียน stock',
        'old'     => 'stock เก่า',
        'sale'    => 'ระบบขาย',
        'install' => 'ประวัติติดตั้ง',
        'rent'    => 'ระบบเช่า',
    ];
}

/**
 * วันที่จากฐานต่าง ๆ ให้เหลือ Y-m-d — ค่าว่าง / 0000-00-00 / รูปแบบอื่น = ไม่รู้วัน
 *
 * @param mixed $v
 * @return string
 */
function ast_date($v): string
{
    $d = substr(trim((string) $v), 0, 10);
    if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $d, $m) || (int) $m[1] < 1990) {
        return '';
    }
    return $d;
}

/** แถว "ป้าย ค่า" ในบรรทัดรายละเอียด — ข้ามค่าว่าง */
function ast_meta(array $pairs): string
{
    $bits = [];
    foreach ($pairs as $p) {
        $label = (string) $p[0];
        $val = trim((string) $p[1]);
        if ($val === '' || $val === '-') {
            continue;
        }
        $raw = !empty($p[2]);
        $bits[] = ($label !== '' ? h($label) . ' ' : '') . ($raw ? $val : h($val));
    }
    return implode(' <span class="asset-sales-sep">·</span> ', $bits);
}

/**
 * หมายเหตุจาก stock_movements มักเป็นแค่ "ลงทะเบียนอุปกรณ์ - Serial: XXX" ซ้ำกับหัวเรื่อง — ตัดส่วนที่ซ้ำทิ้ง
 */
function ast_clean_note($note, string $serial, string $kind, array $known = []): string
{
    $note = trim((string) $note);
    if ($serial !== '') {
        $note = preg_replace('/\s*[-·]?\s*Serial\s*:\s*' . preg_quote($serial, '/') . '/iu', '', $note);
    }
    // เลขคำสั่ง / S/N เก่า ที่ขึ้นในบรรทัดเดียวกันอยู่แล้ว
    foreach ($known as $k) {
        $k = trim((string) $k);
        if ($k === '') {
            continue;
        }
        $note = preg_replace('/\(\s*(?:เคลมจาก\s*)?(?:Serial\s*:\s*)?' . preg_quote($k, '/') . '\s*\)/iu', '', (string) $note);
    }
    $note = trim(preg_replace('/\s{2,}/u', ' ', (string) $note));
    $note = trim((string) $note, " -·,;");
    return $note === $kind ? '' : $note;
}

/**
 * เหตุการณ์หนึ่งรายการ
 *
 * @return array<string,string>
 */
function ast_event(string $date, string $src, string $step, string $title, string $meta = '', string $extra = ''): array
{
    return ['date' => $date, 'src' => $src, 'step' => $step, 'title' => $title, 'meta' => $meta, 'extra' => $extra];
}

/**
 * รวมเหตุการณ์จากทุกระบบ พร้อมผลว่าแต่ละระบบ ตรวจเจอ / ไม่พบ / อ่านไม่ได้
 *
 * @param array<string,mixed> $asset แถว assets (asset_code, factory_serial, produced_at)
 * @param array<string,mixed> $stock ผลจาก asset_stockparts_withdraw_info()
 * @param array<string,mixed> $lease ผลจาก asset_leasing_info()
 * @return array{events:array<int,array<string,string>>,checked:array<string,array{state:string,note:string}>,set_table:string}
 */
function asset_sales_timeline_data(array $asset, array $stock, array $lease): array
{
    $events = [];
    $checked = [];
    $setTable = '';
    $serial = trim((string) ($asset['asset_code'] ?? ''));

    // ─ ระบบผลิต ─
    $produced = ast_date($asset['produced_at'] ?? '');
    if ($produced !== '') {
        $events[] = ast_event($produced, 'prod', 'ผลิต', 'ผลิตเสร็จ');
    }

    // ─ ทะเบียน stock + stock เก่า ─
    $stockOk = !empty($stock['ok']);
    $stockMsg = trim((string) ($stock['message'] ?? ''));
    $stockErr = !$stockOk && in_array($stockMsg, ['เชื่อมต่อระบบ stock ไม่ได้', 'อ่านทะเบียน stock ไม่ได้'], true);
    $row = is_array($stock['stock'] ?? null) ? $stock['stock'] : [];
    $realRow = $row && empty($stock['stock_synthetic']);
    $foundStock = false;

    if ($realRow) {
        $foundStock = true;
        $bits = [['โดย', $row['create_name'] ?? '']];
        if (isset($row['active']) && (int) $row['active'] !== 1) {
            $bits[] = ['', 'ตอนนี้ไม่นับสต็อก'];
        }
        $events[] = ast_event(ast_date($row['timestamp'] ?? ''), 'stock', 'เข้า stock', 'ลงทะเบียนเข้า stock', ast_meta($bits));
    }

    $f = $stockOk && function_exists('stockparts_withdraw_summary_fields') ? stockparts_withdraw_summary_fields($stock) : [];
    $claim = is_array($stock['claim'] ?? null) ? $stock['claim'] : null;
    $sale = is_array($stock['individual_sale'] ?? null) ? $stock['individual_sale'] : null;
    $reg = is_array($stock['registration'] ?? null) ? $stock['registration'] : null;
    $sum = is_array($stock['summary'] ?? null) ? $stock['summary'] : null;
    $orderRef = !empty($stock['has_withdraw']) ? trim((string) ($stock['setup_id'] ?? '')) : '';

    if ($claim) {
        $foundStock = true;
        $events[] = ast_event(ast_date($claim['movement_date'] ?? ''), 'stock', 'เคลม', 'เคลม'
            . (trim((string) ($claim['customer_hint'] ?? '')) !== '' ? ' · ' . h($claim['customer_hint']) : ''),
            ast_meta([
                ['เปลี่ยนจากเครื่อง', $claim['old_serial'] ?? ''],
                ['คำสั่ง', $orderRef !== '' ? '#' . $orderRef : ''],
                ['โดย', $claim['created_by'] ?? ''],
                ['หมายเหตุ', ast_clean_note($claim['notes'] ?? '', $serial, 'เคลม', [$orderRef, $claim['old_serial'] ?? ''])],
            ]));
    }
    if ($sale) {
        $foundStock = true;
        $events[] = ast_event(ast_date($sale['movement_date'] ?? ''), 'stock', 'ขาย', 'ขายแยกรายการ'
            . (trim((string) ($sale['customer_name'] ?? '')) !== '' ? ' · ' . h($sale['customer_name']) : ''),
            ast_meta([
                ['PO', $sale['po_no'] ?? ''],
                ['คำสั่ง', $orderRef !== '' ? '#' . $orderRef : ''],
                ['โดย', $sale['created_by'] ?? ''],
                ['หมายเหตุ', ast_clean_note($sale['notes'] ?? '', $serial, 'ขายแยกรายการ', [$orderRef, $sale['po_no'] ?? ''])],
            ]));
    }
    if ($reg) {
        $foundStock = true;
        $events[] = ast_event(ast_date($reg['movement_date'] ?? ''), 'stock', 'ลงทะเบียน', 'ลงทะเบียนอุปกรณ์'
            . (trim((string) ($reg['customer_name'] ?? '')) !== '' ? ' · ' . h($reg['customer_name']) : ''),
            ast_meta([
                ['คำสั่ง', $orderRef !== '' ? '#' . $orderRef : ''],
                ['โดย', $reg['created_by'] ?? ''],
                ['หมายเหตุ', ast_clean_note($reg['notes'] ?? '', $serial, 'ลงทะเบียนอุปกรณ์')],
            ]));
    }
    if (!empty($stock['has_withdraw']) && !$claim && !$sale && !$reg) {
        $foundStock = true;
        $ref = trim((string) ($stock['setup_id'] ?? ''));
        $cust = trim((string) ($sum['order_customer_name'] ?? ''));
        $src = (string) ($stock['resolve_source'] ?? '');
        $events[] = ast_event(ast_date($sum['created_at'] ?? ''), 'stock', 'เบิกขาย',
            'เบิกขาย' . ($ref !== '' ? ' #' . h($ref) : '') . ($cust !== '' ? ' · ' . h($cust) : ''),
            ast_meta([
                ['ชุด', $sum['order_product_type'] ?? ''],
                ['', !empty($stock['serial_delivered']) ? 'ส่งมอบแล้ว' : ''],
                ['อ้างอิงจาก', ($src !== '' && $src !== 'setup_id' && function_exists('stockparts_resolve_source_label'))
                    ? stockparts_resolve_source_label($src) : ''],
                ['', $sum ? '' : $stockMsg],
            ]));
    }
    $lines = is_array($stock['order_lines'] ?? null) ? $stock['order_lines'] : [];
    if (!empty($stock['has_withdraw']) && !empty($stock['has_order_detail']) && $lines
        && function_exists('stockparts_withdraw_order_table_html')) {
        $matched = is_array($stock['matched_line'] ?? null) ? $stock['matched_line'] : null;
        $setTable = stockparts_withdraw_order_table_html(
            $lines,
            (int) ($matched['id'] ?? 0),
            !empty($stock['serial_delivered']),
            is_array($stock['line_serial_map'] ?? null) ? $stock['line_serial_map'] : [],
            $serial
        );
    }
    $checked['stock'] = $stockErr ? ['state' => 'err', 'note' => $stockMsg]
        : ['state' => $foundStock ? 'ok' : 'none', 'note' => ''];

    $old = !empty($stock['has_stock_old']) && is_array($stock['stock_old'] ?? null) ? $stock['stock_old'] : null;
    if ($old) {
        $place = trim((string) ($old['setup_id'] ?? ''));
        $isLabel = $place !== '' && function_exists('stockparts_setup_id_is_customer_label')
            && stockparts_setup_id_is_customer_label($place);
        $title = 'ส่งมอบ' . ($place === '' ? '' : ($isLabel ? 'ที่ ' . h($place) : 'ตามคำสั่ง #' . h($place)));
        $events[] = ast_event(ast_date($old['timestamp'] ?? ''), 'old', 'ส่งมอบ', $title, ast_meta([
            ['รปภ.', $old['security_company'] ?? ''],
            ['โดย', $old['create_name'] ?? ''],
        ]));
    }
    $checked['old'] = $stockErr ? ['state' => 'err', 'note' => $stockMsg] : ['state' => $old ? 'ok' : 'none', 'note' => ''];

    // ─ ระบบขาย (setupsystem) ─
    if (function_exists('setup_sale_history_for_serial') && $serial !== '') {
        $hist = setup_sale_history_for_serial($serial);
        $rows = is_array($hist['rows'] ?? null) ? $hist['rows'] : [];
        // รายการเดียวกันมักอยู่ทั้ง equipment_claim_history และ po_order_part_serials (เช่นเคลม OPS-726)
        // รวมเป็นเหตุการณ์เดียว เติมช่องที่อีกตารางไม่มี
        $groups = [];
        foreach ($rows as $r) {
            $v = setup_sale_history_norm($r);
            $key = setup_sale_history_kind_label($r) . '|' . mb_strtolower($v['ref']) . '|' . ast_date($v['date']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['row' => $r, 'v' => $v, 'all' => [$r]];
                continue;
            }
            foreach ($v as $k => $val) {
                if (trim((string) $groups[$key]['v'][$k]) === '' && trim((string) $val) !== '') {
                    $groups[$key]['v'][$k] = $val;
                }
            }
            if (empty($groups[$key]['row']['lease_number']) && !empty($r['lease_number'])) {
                $groups[$key]['row']['lease_number'] = $r['lease_number'];
            }
            $groups[$key]['all'][] = $r;
        }
        foreach ($groups as $g) {
            $r = $g['row'];
            $v = $g['v'];
            $isClaim = ((string) ($r['issue_type'] ?? '')) === 'claim';
            $role = (string) ($r['_role'] ?? 'new');
            $kind = setup_sale_history_kind_label($r);
            $step = $isClaim ? ($role === 'old' ? 'เคลมออก' : 'เคลมเข้า')
                : (((string) ($r['_src'] ?? '')) === 'order' ? 'ส่งมอบ' : 'ขาย');
            $title = h($kind) . ($v['org'] !== '' ? ' · ' . h($v['org']) : '');
            $refHtml = '';
            if ($v['ref'] !== '') {
                $url = setup_sale_history_search_url($v['ref']);
                $refHtml = $url !== ''
                    ? '<a class="asset-sales-ref-link" href="' . h($url) . '" target="_blank" rel="noopener">#' . h($v['ref']) . '</a>'
                    : '#' . h($v['ref']);
            }
            $poHtml = '';
            $poNum = trim((string) preg_replace('/^po\s*/iu', '', $v['po']));
            if ($poNum !== '' && $poNum !== '-' && mb_strtolower($poNum) !== mb_strtolower($v['ref'])) {
                $url = setup_sale_history_search_url($poNum);
                $poHtml = $url !== ''
                    ? '<a class="asset-sales-ref-link" href="' . h($url) . '" target="_blank" rel="noopener">PO ' . h($poNum) . '</a>'
                    : 'PO ' . h($poNum);
            }
            $who = array_values(array_unique(array_filter([$v['person'], $v['contact']], 'strlen')));
            $meta = ast_meta([
                ['', $refHtml, true],
                ['', $poHtml, true],
                ['ไซต์', $v['dept']],
                ['ผู้ติดต่อ', implode(' · ', $who)],
                ['สัญญาเช่า', $r['lease_number'] ?? ''],
                ['ส่งมอบ', $v['delivery'] !== '' ? dthai($v['delivery']) : ''],
                ['ประกัน', $v['warranty']],
                ['สถานะ', $v['status']],
                ['โดย', $v['by']],
                ['หมายเหตุ', $v['remark']],
            ]);
            $extra = '';
            if ($isClaim && $v['other_sn'] !== '') {
                $verb = $role === 'new' ? 'เปลี่ยนมาจากเครื่อง' : 'ถูกเปลี่ยนเป็นเครื่อง';
                $extra .= '<p class="asset-sales-claim-pair">' . h($verb) . ' <b>' . h($v['other_sn']) . '</b></p>';
            }
            $conf = [];
            foreach ($g['all'] as $one) {
                foreach (setup_sale_history_conflicts($one, $f) as $c) {
                    $conf[$c] = true;
                }
            }
            foreach (array_keys($conf) as $c) {
                $extra .= '<p class="asset-sales-conflict">' . ui_icon_html('alert', 13, 'h-svg') . ' ' . h($c) . '</p>';
            }
            $events[] = ast_event(ast_date($v['date']), 'sale', $step, $title, $meta, $extra);
        }
        $err = trim((string) ($hist['error'] ?? ''));
        $checked['sale'] = (!$rows && $err !== '') ? ['state' => 'err', 'note' => $err]
            : ['state' => $rows ? 'ok' : 'none', 'note' => ''];
    }

    // ─ ประวัติติดตั้ง (ระบบ installation เดิม) ─
    if (function_exists('installation_history_available') && installation_history_available()) {
        $map = installation_history_map([$asset]);
        $e = $map[strtoupper($serial)] ?? null;
        $first = true;
        foreach ($e ? array_slice($e['rows'], 0, 10) : [] as $r) {
            $cust = trim((string) $r['end_user']) !== '' ? (string) $r['end_user'] : (string) $r['employer'];
            $extra = '';
            if ($first && (int) $e['jobs'] > 1) {
                $extra = '<p class="asset-sales-conflict">' . ui_icon_html('alert', 13, 'h-svg') . ' '
                    . h('S/N นี้พบใน ' . (int) $e['jobs'] . ' งาน — อาจเป็นชุด demo ที่หมุนไปหลายที่ หรือรหัสล็อตที่กรอกแทน serial') . '</p>';
            }
            $first = false;
            $events[] = ast_event(ast_date($r['sale_date'] ?: $r['install_date']), 'install', 'ติดตั้ง',
                'ติดตั้ง' . ($cust !== '' ? ' · ' . h($cust) : ''),
                ast_meta([
                    ['', $r['area']],
                    ['Invoice', $r['invoice']],
                    ['รปภ.', $r['security_company']],
                    ['ผู้ติดตั้ง', $r['trainer']],
                    ['สถานะงาน', $r['job_status']],
                ]), $extra);
        }
        $checked['install'] = ['state' => $e ? 'ok' : 'none', 'note' => ''];
    }

    // ─ ระบบเช่า ─
    if (!empty($lease['found'])) {
        $pro = (string) ($lease['pro_status'] ?? '');
        $inUse = function_exists('rent_leasing_is_current_use')
            && rent_leasing_is_current_use($pro, (string) ($lease['p_status'] ?? ''));
        $events[] = ast_event(ast_date($lease['pro_date'] ?? ''), 'rent', 'เข้าระบบเช่า', 'ลงทะเบียนในระบบเช่า',
            ast_meta([['รุ่น', $lease['product_name'] ?? '']]));
        $labels = [
            'active' => 'กำลังเช่า', 'received' => 'รับคืนแล้ว', 'claim' => 'เคลม', 'Awaiting Return' => 'รอรับคืน',
            'MA' => 'ส่ง MA', 'Lost' => 'สูญหาย', 'Asset Retirement' => 'ปลดระวาง',
        ];
        // ระบบเช่าลงวันรับคืนวันเดียวกันให้หลายสัญญาพร้อมกันได้ — รวมเป็นแถวเดียวต่อวัน
        $returns = [];
        foreach (is_array($lease['rent_history'] ?? null) ? $lease['rent_history'] : [] as $r) {
            $isNow = $inUse && $r['status'] === 'active';
            $bits = [
                ['ไซต์', trim($r['site'] . ($r['site_id'] !== '' ? ' (' . $r['site_id'] . ')' : ''))],
                ['สัญญา', $r['code']],
                ['PO', $r['po'] !== $r['code'] ? $r['po'] : ''],
                ['ถึง', $r['end'] !== '' ? dthai($r['end']) : ''],
                ['สถานะ', $labels[$r['status']] ?? $r['status']],
            ];
            if ($isNow) {
                $bits[] = ['อาการที่แจ้งเคลม', implode(' · ', is_array($lease['fault_notes'] ?? null) ? $lease['fault_notes'] : [])];
                $bits[] = ['หมายเหตุ', $lease['p_remarks'] ?? ''];
            }
            $events[] = ast_event(ast_date($r['start']), 'rent', 'เช่า',
                'เช่า' . ($r['customer'] !== '' ? ' · ' . h($r['customer']) : ''), ast_meta($bits));
            if (ast_date($r['claim_date']) !== '' || $r['claim'] !== '') {
                $events[] = ast_event(ast_date($r['claim_date']), 'rent', 'แจ้งเคลม', 'แจ้งเคลมระหว่างเช่า',
                    ast_meta([['', $r['claim']], ['สัญญา', $r['code']]]));
            }
            if (ast_date($r['returned']) !== '') {
                $returns[ast_date($r['returned'])][] = $r['code'];
            }
        }
        foreach ($returns as $day => $codes) {
            $codes = array_values(array_unique(array_filter($codes, 'strlen')));
            $events[] = ast_event($day, 'rent', 'รับคืน', 'รับคืนจากลูกค้า'
                . (count($codes) > 1 ? ' · ' . count($codes) . ' สัญญา' : ''),
                ast_meta([['สัญญา', implode(', ', $codes)]]));
        }
        if ($pro === 'Asset Retirement') {
            $meta = function_exists('rent_leasing_ma_retire_meta')
                ? rent_leasing_ma_retire_meta(is_array($lease['ma_history'] ?? null) ? $lease['ma_history'] : [])
                : ['date' => '', 'by' => ''];
            $events[] = ast_event(ast_date($meta['date']), 'rent', 'ปลดระวาง', 'ปลดระวาง',
                ast_meta([['เหตุผล', $lease['ma_retire_reason'] ?? ''], ['โดย', $meta['by']]]));
        }
        $checked['rent'] = ['state' => 'ok', 'note' => ''];
    } else {
        $msg = trim((string) ($lease['message'] ?? ''));
        $notFound = $msg === '' || $msg === 'ไม่พบ S/N ในระบบเช่า' || $msg === 'ไม่มี S/N';
        $checked['rent'] = $notFound ? ['state' => 'none', 'note' => ''] : ['state' => 'err', 'note' => $msg];
    }

    // เก่า → ใหม่ · วันเดียวกันเรียงตามลำดับแหล่ง (ผลิตก่อน stock ก่อนขาย ...) · ไม่รู้วันไปท้ายสุด
    $order = array_flip(array_keys(ast_sources()));
    foreach ($events as $i => &$ev) {
        $ev['_i'] = $i;
    }
    unset($ev);
    usort($events, function ($a, $b) use ($order) {
        $da = $a['date'] === '' ? '9999-99-99' : $a['date'];
        $db = $b['date'] === '' ? '9999-99-99' : $b['date'];
        return [$da, $order[$a['src']] ?? 9, $a['_i']] <=> [$db, $order[$b['src']] ?? 9, $b['_i']];
    });

    return ['events' => $events, 'checked' => $checked, 'set_table' => $setTable];
}

/**
 * แถบขั้นตอนด้านบน — เกิน 6 ขั้นแสดงขั้นแรก … แล้ว 4 ขั้นล่าสุด
 *
 * @param array<int,array<string,string>> $dated
 * @return string
 */
function ast_steps_html(array $dated): string
{
    if (!$dated) {
        return '';
    }
    // ขั้นเดียวกันติดกัน (เช่าต่อสัญญา 4 รอบ) ยุบเป็นขั้นเดียว "เช่า ×4" ใช้วันล่าสุด
    $steps = [];
    foreach ($dated as $ev) {
        $last = count($steps) - 1;
        if ($last >= 0 && $steps[$last]['step'] === $ev['step'] && $steps[$last]['src'] === $ev['src']) {
            $steps[$last]['date'] = $ev['date'];
            $steps[$last]['n']++;
            continue;
        }
        $steps[] = $ev + ['n' => 1];
    }
    $dated = $steps;
    $n = count($dated);
    $show = $n > 6 ? array_merge([$dated[0], null], array_slice($dated, -4)) : $dated;
    $out = '<ol class="ast-steps">';
    foreach ($show as $i => $ev) {
        if ($ev === null) {
            $out .= '<li class="ast-step is-gap"><i></i><b>…</b><span>อีก ' . ($n - 5) . ' ขั้น</span></li>';
            continue;
        }
        $cur = $i === count($show) - 1 ? ' is-cur' : '';
        $out .= '<li class="ast-step ast-c-' . h($ev['src']) . $cur . '"><i></i><b>' . h($ev['step'])
            . ($ev['n'] > 1 ? ' ×' . (int) $ev['n'] : '') . '</b>'
            . '<span>' . h(date('d/m/y', strtotime($ev['date']))) . '</span></li>';
    }
    return $out . '</ol>';
}

/**
 * ปุ่มเปิดเครื่องนี้ในระบบเช่า — ระบบเราอ่านอย่างเดียว แก้ข้อมูลเช่าต้องไปที่ต้นทาง
 * Site ID / ชื่อไซต์ ใช้เฉพาะตอนเช่าอยู่ (สัญญาเก่าไม่ใช่ที่อยู่ปัจจุบันของเครื่อง)
 */
function ast_rent_links_html(array $lease): string
{
    $sn = trim((string) ($lease['serial'] ?? ''));
    $inUse = function_exists('rent_leasing_is_current_use')
        && rent_leasing_is_current_use((string) ($lease['pro_status'] ?? ''), (string) ($lease['p_status'] ?? ''));
    $siteId = $inUse ? trim((string) ($lease['site_id'] ?? '')) : '';
    $siteName = $inUse ? trim((string) ($lease['site_name'] ?? '')) : '';
    $links = [
        ['ข้อมูลสินค้า', rent_leasing_detail_product_filter_url($siteId, $sn), $siteId !== '' ? 'Site ' . $siteId : ''],
        ['รายการประวัติ', rent_leasing_sn_search_url($sn), 'ประวัติการใช้งาน ' . $sn],
        ['รายการเช่า', $siteName !== '' ? rent_leasing_view_rent_site_url($siteName) : '', $siteName],
    ];
    $bits = [];
    foreach ($links as $l) {
        if ($l[1] === '') {
            continue;
        }
        $bits[] = '<a class="asset-sales-ref-link" href="' . h($l[1]) . '" target="_blank" rel="noopener"'
            . ($l[2] !== '' ? ' title="' . h($l[2]) . '"' : '') . '>' . h($l[0]) . '</a>';
    }
    return $bits ? '<p class="ast-rent-links"><span class="ast-src ast-c-rent">ระบบเช่า</span> ' . implode(' ', $bits) . '</p>' : '';
}

/** แถวเหตุการณ์ในรายการด้านล่าง */
function ast_row_html(array $ev): string
{
    $labels = ast_sources();
    $when = $ev['date'] !== '' ? h(dthai($ev['date'])) : '<span class="muted">—</span>';
    return '<div class="ast-row">'
        . '<div class="ast-when">' . $when . '</div>'
        . '<div class="ast-what"><div class="ast-title">' . $ev['title'] . '</div>'
        . ($ev['meta'] !== '' ? '<div class="ast-meta">' . $ev['meta'] . '</div>' : '')
        . $ev['extra'] . '</div>'
        . '<span class="ast-src ast-c-' . h($ev['src']) . '">' . h($labels[$ev['src']] ?? $ev['src']) . '</span>'
        . '</div>';
}

/**
 * การ์ด "การขาย / เช่า" — แถบขั้นตอน + รายการเหตุการณ์เก่าไปใหม่ + ระบบที่ตรวจแล้ว
 *
 * @param array<string,mixed> $asset
 * @param array<string,mixed> $stock    ผลจาก asset_stockparts_withdraw_info()
 * @param array<string,mixed> $lease    ผลจาก asset_leasing_info()
 * @param string              $topHtml  กล่องใต้หัวการ์ด (ผลเทียบสถานะเสื่อมสภาพกับระบบเช่า)
 * @param string              $footHtml บล็อกท้ายการ์ด (เครื่องอื่นที่ไซต์งานเดียวกัน)
 * @return string
 */
function asset_sales_timeline_card_html(array $asset, array $stock, array $lease, string $topHtml = '', string $footHtml = ''): string
{
    $data = asset_sales_timeline_data($asset, $stock, $lease);
    $dated = array_values(array_filter($data['events'], function ($e) { return $e['date'] !== ''; }));
    $undated = array_values(array_filter($data['events'], function ($e) { return $e['date'] === ''; }));

    $out = '<div class="asset-sales-card ast-card">';
    $prodBadge = status_badge((string) ($asset['status'] ?? ''));
    $out .= '<div class="asset-sales-card-head">' . ui_icon_html('history', 16) . '<b>การขาย / เช่า</b>'
        . '<span class="ast-head-now">' . $prodBadge;
    if (!empty($lease['found']) && function_exists('rent_leasing_status_badge_html')) {
        $rentBadge = rent_leasing_status_badge_html((string) ($lease['pro_status'] ?? ''), (string) ($lease['p_status'] ?? ''));
        // ป้ายเดียวกันสองอัน (เสื่อมสภาพ · เสื่อมสภาพ) ไม่บอกอะไรเพิ่ม
        if (trim(strip_tags($rentBadge)) !== trim(strip_tags($prodBadge))) {
            $out .= '<span class="ast-head-rent" title="สถานะในระบบเช่า">' . $rentBadge . '</span>';
        }
    }
    $out .= '</span></div><div class="asset-sales-card-body">';
    $out .= $topHtml;
    $out .= ast_steps_html($dated);

    if ($data['events']) {
        $out .= '<div class="ast-list">';
        foreach ($dated as $ev) {
            $out .= ast_row_html($ev);
        }
        if ($undated) {
            $out .= '<div class="ast-undated">ไม่ระบุวัน</div>';
            foreach ($undated as $ev) {
                $out .= ast_row_html($ev);
            }
        }
        $out .= '</div>';
    } else {
        $out .= '<p class="muted asset-sales-empty">ยังไม่มีข้อมูลการขายหรือเช่าในระบบใด</p>';
    }
    $out .= $data['set_table'];

    $labels = ast_sources();
    $chk = [];
    foreach ($data['checked'] as $src => $c) {
        $name = h($labels[$src] ?? $src);
        if ($c['state'] === 'ok') {
            $chk[] = '<span class="ast-chk is-ok">✓ ' . $name . '</span>';
        } elseif ($c['state'] === 'err') {
            $chk[] = '<span class="ast-chk is-err" title="' . h($c['note']) . '">⚠ ' . $name . ' (อ่านไม่ได้)</span>';
        } else {
            $chk[] = '<span class="ast-chk is-none">– ' . $name . '</span>';
        }
    }
    if ($chk) {
        $out .= '<div class="ast-checked"><span>ตรวจแล้ว:</span>' . implode('', $chk) . '</div>';
    }

    $serial = trim((string) ($asset['asset_code'] ?? ''));
    if (!empty($lease['found']) && function_exists('rent_leasing_sn_search_url')) {
        $out .= ast_rent_links_html($lease);
    }
    if ($serial !== '') {
        $out .= '<p class="asset-sales-foot muted"><a href="' . h(BASE_URL . '/share.php?q=' . rawurlencode($serial))
            . '">ดูในทะเบียน stock →</a></p>';
    }
    $out .= $footHtml;
    return $out . '</div></div>';
}
