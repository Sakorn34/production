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
 * @param string $sub บรรทัดเล็กใต้ขั้นบนแถบ เช่น ไซต์ที่ไปเช่า (ข้อความดิบ ยังไม่ escape)
 * @return array<string,string>
 */
function ast_event(string $date, string $src, string $step, string $title, string $meta = '', string $extra = '', string $sub = ''): array
{
    return ['date' => $date, 'src' => $src, 'step' => $step, 'title' => $title, 'meta' => $meta, 'extra' => $extra, 'sub' => $sub];
}

/** ไอคอนของแต่ละขั้นบนแถบ (ชื่อใน shared/ui_icons.php) */
function ast_step_icon(string $step): string
{
    static $map = [
        'ผลิต'         => 'assets',
        'เข้า stock'    => 'stock-in',
        'ลงทะเบียน'    => 'clipboard',
        'เบิกขาย'      => 'stock-out-set',
        'ขาย'          => 'tag',
        'ส่งมอบ'       => 'check',
        'เคลม'         => 'arrows-exchange',
        'เคลมเข้า'     => 'arrows-exchange',
        'เคลมออก'      => 'arrows-exchange',
        'ติดตั้ง'       => 'technicians',
        'เข้าระบบเช่า' => 'box',
        'เช่า'          => 'customers',
        'แจ้งเคลม'     => 'alert',
        'รับคืน'        => 'refresh',
        'ปลดระวาง'     => 'close',
    ];
    return $map[$step] ?? 'history';
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
        ]), '', $isLabel ? $place : '');
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
            $events[] = ast_event(ast_date($v['date']), 'sale', $step, $title, $meta, $extra, $v['dept'] !== '' ? $v['dept'] : $v['org']);
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
        $events[] = ast_event(ast_date($lease['pro_date'] ?? ''), 'rent', 'เข้าระบบเช่า', 'ลงทะเบียนในระบบเช่า',
            ast_meta([['รุ่น', $lease['product_name'] ?? '']]));
        // ระบบเช่าลงวันรับคืนวันเดียวกันให้หลายสัญญาพร้อมกันได้ — รวมเป็นแถวเดียวต่อวัน
        $returns = [];
        foreach (is_array($lease['rent_history'] ?? null) ? $lease['rent_history'] : [] as $r) {
            // บอกแค่ชื่อไซต์ — สัญญา/PO/วันสิ้นสุด/หมายเหตุ ดูได้ครบในส่วน "ประวัติการเช่า (ระบบเช่า)"
            $site = trim((string) $r['site']) !== '' ? trim((string) $r['site']) : trim((string) $r['customer']);
            $rentEv = ast_event(ast_date($r['start']), 'rent', 'เช่า',
                'เช่า' . ($site !== '' ? ' · ' . h($site) : ''), '', '', $r['site']);
            // วันที่เครื่องกลับมาจากสัญญานี้ — ใช้บอกบริบทของงาน MA
            // เอาวันที่เร็วกว่าระหว่างวันรับคืนกับวันสิ้นสุดสัญญา: ระบบเช่าเคยลงวันรับคืนทีหลังทีเดียวทุกสัญญา
            // (เช่น สัญญาที่จบปี 2025 มีวันรับคืน 2026) ซึ่งไม่ใช่วันที่เครื่องกลับมาจริง
            $ends = array_filter([ast_date($r['returned']), ast_date($r['end'])], 'strlen');
            $rentEv['until'] = $ends ? min($ends) : '';
            $rentEv['claim'] = trim((string) ($r['claim'] ?? ''));
            $events[] = $rentEv;
            if (ast_date($r['claim_date']) !== '' || $r['claim'] !== '') {
                $events[] = ast_event(ast_date($r['claim_date']), 'rent', 'แจ้งเคลม', 'แจ้งเคลมระหว่างเช่า',
                    ast_meta([['', $r['claim']], ['ไซต์', $site]]));
            }
            if (ast_date($r['returned']) !== '') {
                $returns[ast_date($r['returned'])][] = $site;
            }
        }
        foreach ($returns as $day => $sites) {
            $n = count($sites);
            $sites = array_values(array_unique(array_filter($sites, 'strlen')));
            $events[] = ast_event($day, 'rent', 'รับคืน', 'รับคืนจากลูกค้า' . ($n > 1 ? ' · ' . $n . ' สัญญา' : ''),
                ast_meta([['ไซต์', implode(', ', $sites)]]));
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

/** สีไล่สามระดับของแต่ละแหล่ง (อ่อน · กลาง · เข้ม) สำหรับไอคอน 3D บนแถบ */
function ast_source_tones(): array
{
    return [
        'prod'    => ['#b3afc2', '#6e6982', '#3f3b4f'],
        'stock'   => ['#b9a6f7', '#6a48d6', '#3a2290'],
        'old'     => ['#f3cf7a', '#c98a12', '#7a4f05'],
        'sale'    => ['#7fd8b6', '#159a72', '#0a5a42'],
        'install' => ['#f2a3c2', '#c2457a', '#7a1d45'],
        'rent'    => ['#8fbcf7', '#2f66c8', '#173a7a'],
    ];
}

/**
 * ไอคอน 3D ไม่มีกรอบ — เส้นไอคอนซ้อนสามชั้น: แสงเรืองจาง · เงาเข้มเลื่อนลง · เส้นไล่สี
 *
 * @param string $icon ชื่อใน shared/ui_icons.php
 * @param string $src  คีย์แหล่งข้อมูล (ใช้ gradient ที่ประกาศไว้ใน ast_steps_html)
 */
function ast_icon3d_html(string $icon, string $src): string
{
    $paths = function_exists('ui_icon_paths') ? (ui_icon_paths($icon) ?? ui_icon_paths('history')) : null;
    $tone = ast_source_tones()[$src] ?? ast_source_tones()['prod'];
    $g = static function (string $attrs) use ($paths): string {
        return '<g fill="none" stroke-linecap="round" stroke-linejoin="round" ' . $attrs . '>' . $paths . '</g>';
    };
    return '<svg class="ast-ico3d" viewBox="-3 -3 30 30" width="30" height="30" aria-hidden="true">'
        . $g('class="ast-glow" stroke="' . $tone[0] . '" stroke-width="5.5" opacity=".35" filter="url(#ast-blur)"')
        . $g('stroke="' . $tone[2] . '" stroke-width="2.8" opacity=".5" transform="translate(0 1.4)"')
        . $g('stroke="url(#ast-g-' . h($src) . ')" stroke-width="2.6"')
        . '</svg>';
}

/**
 * แถบขั้นตอน — ทุกการเคลื่อนไหว ไม่ยุบ ไม่ตัด
 *
 * ไม่เกิน 6 ขั้นเป็นแถวเดียว · มากกว่านั้นแถวละ 5 วนเป็นตัวยู (แถวคู่ซ้าย→ขวา แถวคี่ขวา→ซ้าย)
 * แถวกลับทิศใช้ direction:rtl ให้ grid เรียงจากขวา เส้นเชื่อม/ลูกศรใช้ logical property จึงกลับตาม
 * ลำดับขั้นตรงกับแถวในรายการด้านล่าง (data-ast-i) — กดขั้นแล้วเลื่อนไปแถวนั้น
 *
 * @param array<int,array<string,string>> $dated
 * @return string
 */
function ast_steps_html(array $dated, array $work = []): string
{
    $n = count($dated);
    if ($n === 0) {
        return '';
    }
    $defs = '<svg class="ast-defs" width="0" height="0" aria-hidden="true" focusable="false"><defs>';
    foreach (ast_source_tones() as $k => $t) {
        $defs .= '<linearGradient id="ast-g-' . $k . '" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $t[0] . '"/><stop offset="1" stop-color="' . $t[1] . '"/></linearGradient>';
    }
    $defs .= '<filter id="ast-blur" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="1.6"/></filter></defs></svg>';

    // จอกว้าง 5 ขั้นต่อแถว (ไม่เกิน 6 ขั้นอยู่แถวเดียว) / จอมือถือ 3 ขั้นต่อแถว — สลับด้วย CSS
    // defs อยู่นอกชุดที่ถูกซ่อน เพราะ gradient ใน display:none จะไม่ถูกวาด
    $wide = $n <= 6 ? $n : 5;
    $links = ast_work_links($dated, $work);
    $out = '<div class="ast-stepwrap">' . $defs;
    if ($n <= 3) {
        return $out . ast_snake_html($dated, $wide, '', $links) . '</div>';
    }
    return $out . ast_snake_html($dated, $wide, ' is-wide', $links) . ast_snake_html($dated, 3, ' is-narrow', $links) . '</div>';
}

/** ชนิดงานกับตัวเครื่องที่นับเป็นป้าย MA บนแถบ */
function ast_work_is_ma(string $kind): bool
{
    return $kind === 'ma' || $kind === 'rent_ma';
}

/**
 * ผูกงานกับตัวเครื่องเข้ากับแถบขั้นตอน
 *
 * งาน MA ที่วันอยู่หลังขั้น i-1 และไม่เกินขั้น i → ป้ายบนเส้นที่วิ่งเข้าขั้น i
 * งานหลังขั้นสุดท้าย → ป้ายต่อท้ายขั้นสุดท้าย · ขั้น "ผลิต" ชี้ไปบันทึกผลิต/QC
 *
 * @param array<int,array<string,string>> $dated
 * @param array<int,array{i:int,day:string,kind:string}> $work  จาก asset_work_marks()
 * @return array{seg:array<int,array>,after:array,prod:?int}
 */
function ast_work_links(array $dated, array $work): array
{
    $n = count($dated);
    $seg = [];
    $after = [];
    $prod = null;
    $ord = 0;
    foreach ($work as $w) {
        if ($w['kind'] === 'production' && $prod === null) {
            $prod = $w['i'];
        }
        if (!ast_work_is_ma($w['kind'])) {
            continue;
        }
        // ครั้งที่ของ MA เรียงตามเวลา — เลขเดียวกับป้ายในการ์ดงานกับตัวเครื่อง
        $w['n'] = ++$ord;
        if ($w['day'] === '' || $n === 0) {
            continue;
        }
        if ($w['day'] > $dated[$n - 1]['date']) {
            $after[] = $w;
            continue;
        }
        for ($k = 1; $k < $n; $k++) {
            if ($w['day'] > $dated[$k - 1]['date'] && $w['day'] <= $dated[$k]['date']) {
                $seg[$k][] = $w;
                break;
            }
        }
    }
    return ['seg' => $seg, 'after' => $after, 'prod' => $prod];
}

/** ป้าย "MA n" บนแถบ (n = ครั้งที่) — กดแล้วเลื่อนไปงานนั้นในการ์ดงานกับตัวเครื่อง */
function ast_ma_badge_html(array $ws, string $mod): string
{
    $days = [];
    $idx = [];
    foreach ($ws as $w) {
        $days[] = date('d/m/y', strtotime($w['day']));
        $idx[] = (int) $w['i'];
    }
    $first = (int) $ws[0]['n'];
    $last = (int) $ws[count($ws) - 1]['n'];
    $num = $first === $last ? (string) $first : $first . '–' . $last;
    $tip = 'งาน MA ครั้งที่ ' . $num . "\n" . implode(', ', $days) . "\n" . 'กดเพื่อดูในงานกับตัวเครื่อง';
    return '<span class="ast-mab' . $mod . '" data-ast-work="' . implode(',', $idx) . '" data-ast-tip="' . h($tip) . '">MA ' . $num . '</span>';
}

/** แถบขั้นแบบตัวยู — $per ขั้นต่อแถว แถวคี่วิ่งย้อนจากขวาไปซ้าย */
function ast_snake_html(array $dated, int $per, string $mod, array $links = ['seg' => [], 'after' => [], 'prod' => null]): string
{
    $n = count($dated);
    $labels = ast_sources();
    $out = '<div class="ast-snake' . $mod . '" style="--ast-per:' . $per . '">';
    foreach (array_chunk($dated, $per) as $r => $row) {
        $cls = 'ast-srow' . ($r % 2 === 1 ? ' is-rev' : '') . (($r + 1) * $per < $n ? ' has-next' : '');
        $out .= '<div class="' . $cls . '" style="--ast-end:' . (($r + 1) * $per - 1) . '">';
        foreach ($row as $c => $ev) {
            $i = $r * $per + $c;
            $date = date('d/m/y', strtotime($ev['date']));
            $tip = trim(strip_tags(html_entity_decode($ev['title'], ENT_QUOTES, 'UTF-8')));
            $tipSub = ($ev['sub'] !== '' && strpos($tip, $ev['sub']) === false) ? ' · ' . $ev['sub'] : '';
            $tipLines = array_filter([$tip, dthai($ev['date']) . $tipSub, 'ที่มา: ' . ($labels[$ev['src']] ?? $ev['src'])]);
            $work = ($ev['src'] === 'prod' && $links['prod'] !== null) ? ' data-ast-work="' . (int) $links['prod'] . '"' : '';
            $badge = isset($links['seg'][$i]) ? ast_ma_badge_html($links['seg'][$i], $c === 0 ? ' is-turn' : '') : '';
            if ($i === $n - 1 && $links['after']) {
                $badge .= ast_ma_badge_html($links['after'], ' is-after');
            }
            $out .= '<button type="button" class="ast-sstep ast-c-' . h($ev['src']) . ($i === $n - 1 ? ' is-cur' : '') . '"'
                . ' data-ast-i="' . $i . '"' . $work . ' data-ast-tip="' . h(implode("\n", $tipLines)) . '" style="--i:' . $i . '"'
                . ' aria-label="' . h($ev['step'] . ' ' . $date) . '">'
                . $badge
                . '<span class="ast-sico">' . ast_icon3d_html(ast_step_icon($ev['step']), $ev['src']) . '</span>'
                . '<b>' . h($ev['step']) . '</b><span class="ast-sdate">' . h($date) . '</span>'
                . ($ev['sub'] !== '' ? '<span class="ast-ssub">' . h($ev['sub']) . '</span>' : '')
                . '</button>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}
function ast_steps_script(): string
{
    return <<<'JS'
<script>
(function(){
  var snake = document.querySelector('.ast-stepwrap');
  if (!snake) { return; }
  var tip = document.createElement('div');
  tip.className = 'ast-tipbox';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);
  // ระหว่างเลื่อนหน้า (รวมเลื่อนอัตโนมัติหลังกด) ปุ่มวิ่งผ่านใต้เมาส์ — ถ้าโชว์ตอนนั้นกล่องจะค้างผิดที่
  var quietUntil = 0;
  function show(btn){
    if (Date.now() < quietUntil) { return; }
    var t = btn.getAttribute('data-ast-tip') || '';
    if (!t) { return; }
    var lines = t.split('\n');
    tip.innerHTML = '';
    lines.forEach(function(l, k){
      var el = document.createElement(k === 0 ? 'b' : 'div');
      el.textContent = l;
      tip.appendChild(el);
    });
    var r = (btn.querySelector('.ast-sico') || btn).getBoundingClientRect();
    tip.classList.add('is-on');
    var w = tip.offsetWidth, h = tip.offsetHeight;
    var x = Math.max(8, Math.min(window.innerWidth - w - 8, r.left + r.width / 2 - w / 2));
    var y = r.top - h - 8;
    if (y < 8) { y = r.bottom + 8; }
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
  }
  function hide(){ tip.classList.remove('is-on'); }
  function pick(t){ return t.closest('.ast-mab') || t.closest('.ast-sstep'); }
  snake.addEventListener('mouseover', function(e){ var b = pick(e.target); if (b) { show(b); } });
  snake.addEventListener('mouseout', function(e){ if (!e.relatedTarget || !snake.contains(e.relatedTarget)) { hide(); } });
  snake.addEventListener('focusin', function(e){ var b = e.target.closest('.ast-sstep'); if (b && b.matches(':focus-visible')) { show(b); } });
  snake.addEventListener('focusout', hide);
  window.addEventListener('scroll', function(){ hide(); quietUntil = Date.now() + 250; }, true);
  snake.addEventListener('click', function(e){
    var b = e.target.closest('.ast-sstep');
    if (!b) { return; }
    hide();
    quietUntil = Date.now() + 900;
    // ป้าย MA → งานในการ์ดงานกับตัวเครื่อง · ขั้นปกติ → แถวในรายการการ์ดนี้
    // ขั้นผลิตไม่มีแถวในรายการ (บันทึกผลิตอยู่ในงานกับตัวเครื่อง) → ไปที่บันทึกผลิต/QC
    var mab = e.target.closest('.ast-mab');
    var rows = [];
    if (!mab) {
      var row = document.querySelector('.ast-list [data-ast-row="' + b.getAttribute('data-ast-i') + '"]');
      if (row) { rows = [row]; }
    }
    if (!rows.length) {
      ((mab || b).getAttribute('data-ast-work') || '').split(',').forEach(function(k){
        var w = k !== '' ? document.querySelector('[data-work-row="' + k + '"]') : null;
        if (w) { rows.push(w); }
      });
    }
    if (!rows.length) { return; }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    rows[0].scrollIntoView({ block: mab ? 'center' : 'nearest', behavior: reduce ? 'auto' : 'smooth' });
    rows.forEach(function(r){
      r.classList.remove('is-hl');
      void r.offsetWidth;
      r.classList.add('is-hl');
      setTimeout(function(){ r.classList.remove('is-hl'); }, 1600);
    });
  });
})();
</script>
JS;
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

/**
 * แถวเหตุการณ์ในรายการด้านล่าง
 *
 * @param int|null $i ลำดับขั้นบนแถบ (เฉพาะแถวที่มีวันที่) — ให้กดขั้นแล้วเลื่อนมาที่แถวนี้
 */
function ast_row_html(array $ev, ?int $i = null): string
{
    $labels = ast_sources();
    $when = $ev['date'] !== '' ? h(dthai($ev['date'])) : '<span class="muted">—</span>';
    return '<div class="ast-row"' . ($i !== null ? ' data-ast-row="' . $i . '"' : '') . '>'
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
 * @param array               $work     asset_work_marks() — ป้าย MA บนแถบ + ขั้นผลิตชี้ไปบันทึกผลิต
 * @param array|null          $data     asset_sales_timeline_data() ที่คำนวณไว้แล้ว (ไม่ต้องอ่านซ้ำ)
 * @return string
 */
function asset_sales_timeline_card_html(array $asset, array $stock, array $lease, string $topHtml = '', string $footHtml = '', array $work = [], ?array $data = null): string
{
    $data = $data ?? asset_sales_timeline_data($asset, $stock, $lease);
    $dated = ast_dated_events($data);
    $undated = array_values(array_filter($data['events'], function ($e) { return $e['date'] === ''; }));

    $out = '<div class="asset-sales-card ast-card">';
    $prodBadge = status_badge((string) ($asset['status'] ?? ''));
    $out .= '<div class="asset-sales-card-head">' . ui_icon_html('history', 16)
        . '<b>เส้นทางเครื่อง · การขาย / เช่า</b>'
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
    $out .= ast_steps_html($dated, $work);

    if ($data['events']) {
        $out .= '<div class="ast-list">';
        foreach ($dated as $i => $ev) {
            // ผลิตเสร็จอยู่บนแถบแล้ว รายละเอียดอยู่ที่บันทึกผลิต/QC ในการ์ดงานกับตัวเครื่อง
            if ($ev['src'] === 'prod' && $work) {
                continue;
            }
            $out .= ast_row_html($ev, $i);
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
    return $out . '</div></div>' . ($dated ? ast_steps_script() : '');
}

/** เหตุการณ์ที่มีวันที่ เรียงเก่า → ใหม่ (ลำดับเดียวกับขั้นบนแถบ) */
function ast_dated_events(array $data): array
{
    return array_values(array_filter($data['events'], function ($e) { return $e['date'] !== ''; }));
}

/**
 * จุดอ้างอิงของงานกับตัวเครื่อง (เรียงเก่า → ใหม่ เท่ากับลำดับแถวที่แสดง) สำหรับผูกกับแถบขั้นตอน
 *
 * @param array<int,array<string,mixed>> $tlAsc ผลจาก timeline_sort_items_asc()
 * @return array<int,array{i:int,day:string,kind:string}>
 */
function asset_work_marks(array $tlAsc): array
{
    $out = [];
    foreach (array_values($tlAsc) as $i => $e) {
        $d = trim((string) ($e['d'] ?? ''));
        $out[] = ['i' => $i, 'day' => $d !== '' ? substr($d, 0, 10) : '', 'kind' => (string) ($e['kind'] ?? '')];
    }
    return $out;
}

/**
 * ตอนทำงานนี้ เครื่องอยู่ช่วงไหนของเส้นทาง — ดูจากเหตุการณ์ขาย/เช่าล่าสุดก่อนวันนั้น
 *
 * @param array<int,array<string,string>> $dated
 * @return string ข้อความดิบ (ยังไม่ escape) · ว่าง = ยังอยู่ช่วงผลิต/เข้าคลัง ไม่ต้องบอก
 */
function ast_work_context(array $dated, string $day): string
{
    return ast_work_context_info($dated, $day)['text'];
}

/**
 * บริบทของงาน + อาการที่ลูกค้าแจ้งของสัญญาเช่าช่วงนั้น
 *
 * @return array{text:string,claim:string}
 */
function ast_work_context_info(array $dated, string $day): array
{
    $none = ['text' => '', 'claim' => ''];
    if ($day === '') {
        return $none;
    }
    $last = null;
    $rent = null;
    // เฉพาะเหตุการณ์ก่อนวันนั้น — งานวันเดียวกับเหตุการณ์มักเป็นต้นเหตุ (เช่น MA เจอเสียแล้วปลดระวางวันเดียวกัน)
    foreach ($dated as $ev) {
        if ($ev['date'] >= $day) {
            break;
        }
        if ($ev['src'] === 'prod' || $ev['step'] === 'เข้า stock') {
            continue;
        }
        $last = $ev;
        if ($ev['step'] === 'เช่า') {
            $rent = $ev;
        }
    }
    if ($last === null) {
        return $none;
    }
    $step = $last['step'];
    if ($step === 'ปลดระวาง') {
        return ['text' => 'หลังปลดระวาง', 'claim' => ''];
    }
    if ($rent !== null && in_array($step, ['เช่า', 'แจ้งเคลม', 'รับคืน'], true)) {
        $site = trim((string) $rent['sub']) !== '' ? trim((string) $rent['sub']) : 'ลูกค้า';
        $until = (string) ($rent['until'] ?? '');
        $claim = (string) ($rent['claim'] ?? '');
        if ($step === 'รับคืน' || ($until !== '' && $day >= $until)) {
            return ['text' => 'หลังจาก ' . $site . ' ใช้งานแล้ว', 'claim' => $claim];
        }
        return ['text' => 'ระหว่างเช่าที่ ' . $site, 'claim' => $claim];
    }
    if ($step === 'เข้าระบบเช่า') {
        return ['text' => 'อยู่คลังเช่า', 'claim' => ''];
    }
    if (trim((string) $last['sub']) !== '') {
        return ['text' => 'หลังส่งมอบ ' . trim((string) $last['sub']), 'claim' => ''];
    }
    return $none;
}

/** ช่อง "สิ่งที่ทำ" ของงาน MA ระบบนี้ — ซ่อมก่อน เปลี่ยนรองลงมา ปกติเป็นจำนวน (เหมือนหน้า MA) */
function awk_ma_done_html(array $m): string
{
    $html = '';
    if ($m['repair'] || $m['replace']) {
        $html .= '<div class="ma-list-item-chips">';
        foreach ($m['repair'] as $it) {
            $html .= '<span class="ma-list-item-chip ma-list-item-chip-repair" title="ซ่อม">' . h($it) . '</span>';
        }
        foreach ($m['replace'] as $it) {
            $html .= '<span class="ma-list-item-chip ma-list-item-chip-replace" title="เปลี่ยน">' . h($it) . '</span>';
        }
        $html .= '</div>';
    }
    if ($m['ok']) {
        $html .= '<div class="ma-list-okn" title="' . h(implode(', ', $m['ok'])) . '">✓ ตรวจปกติ ' . count($m['ok']) . ' รายการ</div>';
    }
    if ($m['remark'] !== '') {
        $html .= '<div class="awk-remark">' . nl2br(h($m['remark'])) . '</div>';
    }
    return $html !== '' ? $html : '<span class="muted">—</span>';
}

/** ช่อง "ระบบเช่า" — ผลของงาน MA ต่อระบบเช่า (ป้ายเดียวกับหน้า MA) */
function awk_rent_close_html(string $kind, array $m): string
{
    $st = $kind === 'rent_ma' ? (string) ($m['status'] ?? '') : '';
    if ($st === 'Asset Retirement') {
        return '<span class="ma-pill ma-pill-urgent">เสื่อมสภาพ</span>';
    }
    if ($st === 'finished goods') {
        return '<span class="ma-pill ma-pill-done">ซ่อมแล้ว</span>';
    }
    if ($kind === 'ma' && !empty($m['spare'])) {
        return '<span class="muted">ไม่ปิดคิว</span>';
    }
    return '<span class="muted">—</span>';
}

/**
 * ส่วน "งานกับตัวเครื่อง" — ผลิต/QC · MA · อัปเดต · เบิกอะไหล่ เรียงเก่า → ใหม่
 *
 * หน้าตาเดียวกับส่วนประวัติการเช่า (หัวข้อ + คำอธิบาย + กรอบเดียว) และแถว MA
 * ใช้คอลัมน์เดียวกับตารางประวัติหน้า MA: วันเวลา · งาน · อาการที่แจ้ง · สิ่งที่ทำ · ระบบเช่า
 * แต่ละแถวบอกว่าตอนนั้นเครื่องอยู่ช่วงไหน และมี data-work-row ให้ป้าย MA บนแถบกดเลื่อนมาได้
 *
 * @param array<int,array<string,mixed>>  $tlAsc     ผลจาก timeline_sort_items_asc()
 * @param callable|null                   $actionsFn ปุ่มแก้ไข/ลบของแต่ละแถว
 * @param array<int,array<string,string>> $dated     ast_dated_events()
 * @param string                          $headLink  ลิงก์ท้ายบรรทัดคำอธิบาย (HTML)
 */
function asset_work_card_html(array $tlAsc, $actionsFn, int $assetId, array $dated, string $headLink = ''): string
{
    $out = '<section class="ma-repair-section awk-section" id="asset-work">'
        . '<h2 class="h-with-icon">' . ui_icon_html('repairs', 18) . 'งานกับตัวเครื่อง (' . count($tlAsc) . ')</h2>'
        . '<p class="ma-hint">ผลิต/QC · MA · อัปเดต · อะไหล่ — สิ่งที่ทำกับตัวเครื่อง เรียงเก่า → ใหม่'
        . ($headLink !== '' ? ' · ' . $headLink : '') . '</p>';
    if (!$tlAsc) {
        return $out . '<p class="muted">ยังไม่มีประวัติ</p></section>';
    }
    $out .= '<div class="ma-card ma-card-flat"><div class="ma-tblscroll"><table class="ma-table ma-list-table awk-table"><thead><tr>'
        . '<th class="ma-c-when">วันเวลา</th><th class="ma-c-asset">งาน</th>'
        . '<th class="ma-c-sym" title="อาการที่ลูกค้าแจ้งในสัญญาเช่าช่วงนั้น (ระบบเช่า)">อาการที่แจ้ง</th>'
        . '<th class="ma-c-done" title="ซ่อม · เปลี่ยน · จำนวนรายการที่ตรวจแล้วปกติ">สิ่งที่ทำ</th>'
        . '<th class="ma-c-rent" title="ผลต่อระบบเช่า: ซ่อมแล้ว หรือ เสื่อมสภาพ">ระบบเช่า</th>'
        . '<th class="ma-c-act"></th></tr></thead><tbody>';
    foreach (array_values($tlAsc) as $i => $e) {
        $kind = (string) ($e['kind'] ?? '');
        $m = is_array($e['ma'] ?? null) ? $e['ma'] : null;
        $isMa = ast_work_is_ma($kind) && $m !== null;
        $d = trim((string) ($e['d'] ?? ''));
        $day = $d !== '' ? substr($d, 0, 10) : '';
        $time = strlen($d) >= 16 ? substr($d, 11, 5) : '';
        $time = $time === '00:00' ? '' : $time;
        $ctx = $kind === 'production' ? ['text' => '', 'claim' => ''] : ast_work_context_info($dated, $day);
        $actions = ($actionsFn && is_callable($actionsFn)) ? (string) $actionsFn($e, $assetId) : '';

        $when = $day !== '' ? '<b>' . h(date('d/m/Y', strtotime($day))) . '</b>' : '<b class="muted">ไม่ระบุวันที่</b>';
        $small = array_filter([$time, $isMa ? (string) $m['by'] : '']);
        $when .= $small ? '<small>' . h(implode(' · ', $small)) . '</small>' : '';
        if ($kind === 'rent_ma') {
            $when .= '<span class="ma-src-tag">ระบบเช่า</span>';
        }

        $job = '<div class="awk-job">' . $e['type']
            . '</div>';
        $sub = [];
        if ($isMa && $kind === 'ma' && $m['round'] > 0) {
            $sub[] = 'รอบที่ ' . $m['round'];
        }
        if ($isMa && $kind === 'ma' && $m['fw'] !== '') {
            $sub[] = 'FW ' . $m['fw'];
        }
        $job .= $sub ? '<small>' . h(implode(' · ', $sub)) . '</small>' : '';
        $job .= $ctx['text'] !== '' ? '<span class="awk-ctx">' . h($ctx['text']) . '</span>' : '';

        $out .= '<tr data-work-row="' . $i . '" data-work-kind="' . h($kind) . '">'
            . '<td class="ma-c-when">' . $when . '</td>'
            . '<td class="ma-c-asset awk-c-job">' . $job . '</td>';
        if ($isMa) {
            $done = $kind === 'rent_ma'
                ? ($m['notes'] ? '<div class="ma-rent-note">' . implode('<br>', array_map('h', $m['notes'])) . '</div>' : '<span class="muted">ไม่มีรายละเอียด</span>')
                : awk_ma_done_html($m);
            $out .= '<td class="ma-list-symptom ma-c-sym">' . ($ctx['claim'] !== '' ? h($ctx['claim']) : '<span class="muted">—</span>') . '</td>'
                . '<td class="ma-c-done">' . $done . '</td>'
                . '<td class="ma-rent-close ma-c-rent">' . awk_rent_close_html($kind, $m) . '</td>';
        } else {
            // ผลิต/QC · อัปเดต · อะไหล่ ไม่มีอาการ/ผลต่อระบบเช่า — ใช้รายละเอียดเดิมเต็มช่อง
            $out .= '<td class="ma-c-done awk-c-body" colspan="3">' . $e['html'] . '</td>';
        }
        $out .= '<td class="ma-row-actions row-actions ma-c-act">' . $actions . '</td></tr>';
    }
    return $out . '</tbody></table></div></div></section>';
}
