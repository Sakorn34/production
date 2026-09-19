<?php
/**
 * includes/line_bot.php — ไลน์สำรองแบบถาม-ตอบ (ริชเมนู · ค้นหา · ประวัติเครื่อง · สต็อก · สแกนผ่าน LIFF)
 *
 * เรียกจาก line_webhook.php หลังตรวจลายเซ็นแล้ว ตอบด้วย reply token เสมอ = คำตอบเข้าแชทส่วนตัว
 * ของคนที่ทักมาเท่านั้น ไม่มีใครอื่นเห็น
 *
 * ตอบเฉพาะคนที่ผูกไลน์ไว้ในหน้า "สรุปงานรายคน" แล้ว (work_people.line_user_id) — ผลค้นหามีชื่อลูกค้า
 * ไซต์งาน และสัญญาเช่า คนนอกที่แอดไลน์มาจึงได้แค่ข้อความบอกวิธีผูกบัญชี
 *
 * ค้นหาใช้ smart_search_query() ตัวเดียวกับช่องค้นหาบน sidebar — ผลตรงกับเว็บเสมอ
 */

require_once __DIR__ . '/smart_search.php';

/** @var string postback data ของปุ่มริชเมนู / ปุ่มในข้อความ */
const LINE_BOT_PB_SEARCH  = 'm=search';
const LINE_BOT_PB_HISTORY = 'm=history';
const LINE_BOT_PB_STOCK   = 'm=stock';
const LINE_BOT_PB_HELP    = 'm=help';
const LINE_BOT_PB_SCAN    = 'm=scan';
/** @var int ผลค้นหาสูงสุดต่อข้อความ — Flex ยาวกว่านี้อ่านยากบนมือถือ */
const LINE_BOT_MAX_RESULTS = 10;
/** @var int รายการประวัติเครื่องที่แสดงในการ์ด */
const LINE_BOT_MAX_HISTORY = 8;

/**
 * คนทำงานที่ผูก LINE นี้ไว้กับ bot สำรอง
 *
 * @param string $lineUserId
 * @param string $bot
 * @return array<string,mixed>|null
 */
function line_bot_person(string $lineUserId, string $bot): ?array
{
    if ($lineUserId === '') {
        return null;
    }
    work_people_ensure_schema();
    try {
        $row = qr(
            'SELECT id, display_name FROM work_people
             WHERE line_user_id = ? AND is_active = 1 AND COALESCE(line_user_bot, "main") = ? LIMIT 1',
            'ss',
            [$lineUserId, $bot]
        )->fetch_assoc();
        return $row ?: null;
    } catch (\Throwable $e) {
        error_log('[line_bot_person] ' . $e->getMessage());
        return null;
    }
}

/**
 * ข้อความนี้เป็นรหัสผูกบัญชีที่ยังใช้ได้หรือไม่ — S/N บางรุ่นยาว 8 ตัวเท่ารหัส จึงต้องเช็คกับฐานจริง
 * ไม่ใช่ดูแค่รูปแบบ ไม่งั้นคนที่ผูกแล้วพิมพ์ S/N 8 ตัวจะโดนตอบว่า "ไม่พบรหัสนี้"
 *
 * @param string $text
 * @return string รหัส (ตัวใหญ่) หรือ ''
 */
function line_bot_link_code_in(string $text): string
{
    $t = strtoupper(trim($text));
    if (!preg_match('/^[A-Z0-9]{8}$/', $t)) {
        return '';
    }
    try {
        $row = qr('SELECT id FROM work_people WHERE link_code = ? LIMIT 1', 's', [$t])->fetch_assoc();
        return $row ? $t : '';
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * URL หน้าเว็บ production แบบ https สำหรับปุ่มในข้อความ ('' = ใช้ไม่ได้ ไม่ต้องใส่ปุ่ม)
 *
 * @param string $path path ต่อจาก BASE_URL หรือ URL เต็ม
 * @return string
 */
function line_bot_web_url(string $path): string
{
    if ($path === '') {
        return '';
    }
    $url = preg_match('#^https?://#i', $path) ? $path : rtrim(line_notify_production_base_url(), '/') . '/' . ltrim($path, '/');
    return line_notify_sanitize_https_uri($url);
}

/**
 * URL เปิดหน้าสแกน (LIFF) — '' ถ้ายังไม่ได้ตั้ง LIFF ID
 *
 * @return string
 */
function line_bot_liff_url(): string
{
    $id = trim((string) (line_notify_config()['liff_scan_id'] ?? ''));
    return $id !== '' ? 'https://liff.line.me/' . rawurlencode($id) : '';
}

// ─ ข้อความตอบ ─────────────────────────────────────────────────────────────────

/**
 * ข้อความธรรมดา — ไม่ใส่ปุ่มลัด (quick reply) เพราะซ้ำกับริชเมนูที่อยู่ใต้แชทตลอดอยู่แล้ว
 *
 * @param string $text
 * @return array<string,mixed>
 */
function line_bot_text(string $text): array
{
    return ['type' => 'text', 'text' => mb_substr($text, 0, 4900)];
}

/**
 * หัวข้อวิธีใช้ — ใช้ทั้งการ์ด (ปุ่ม "วิธีใช้") และข้อความธรรมดา (ตอนผูกบัญชี/แอดไลน์)
 *
 * @return array<int,array{0:string,1:string,2:string}> [ไอคอน, หัวข้อ, คำอธิบาย]
 */
function line_bot_help_items(): array
{
    return [
        ['⌨️', 'พิมพ์ค้นหา', "พิมพ์ S/N ชื่อรุ่น หรือชื่อลูกค้าในแชทได้เลย\nS/N เต็ม = ได้ประวัติเครื่องทันที"],
        ['📷', 'สแกนด้วยกล้อง', "ส่องบาร์โค้ดหรือ QR บนเครื่อง\nไม่ต้องพิมพ์ ได้ประวัติเครื่องทันที"],
        ['📦', 'เช็คสต็อก', "ใหม่ · พร้อมเช่า · ขั้นต่ำ · PO · ขาด/เกิน\nแยกตามรุ่น ตัวเลขเดียวกับ Dashboard"],
    ];
}

/**
 * ข้อความวิธีใช้แบบข้อความธรรมดา
 *
 * @return string
 */
function line_bot_help_text(): string
{
    $out = "วิธีใช้ไลน์ Production\n";
    foreach (line_bot_help_items() as [$ic, $title, $desc]) {
        $out .= "\n" . $ic . ' ' . $title . "\n" . $desc . "\n";
    }
    return $out . "\n🔒 คำตอบเห็นเฉพาะคุณในแชทนี้";
}

/**
 * การ์ดวิธีใช้ (ปุ่ม "วิธีใช้" ในริชเมนู)
 *
 * @return array<string,mixed>
 */
function line_bot_help_flex(): array
{
    $body = [
        ['type' => 'text', 'text' => 'วิธีใช้ไลน์ Production', 'weight' => 'bold', 'size' => 'md'],
        ['type' => 'text', 'text' => 'กดเมนูด้านล่าง หรือพิมพ์ในแชทได้เลย', 'size' => 'xs', 'color' => '#888888'],
    ];
    foreach (line_bot_help_items() as [$ic, $title, $desc]) {
        $body[] = ['type' => 'separator', 'margin' => 'lg'];
        $body[] = ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'md', 'margin' => 'lg', 'contents' => [
            ['type' => 'text', 'text' => $ic, 'size' => 'lg', 'flex' => 0],
            ['type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'spacing' => 'xs', 'contents' => [
                ['type' => 'text', 'text' => $title, 'size' => 'sm', 'weight' => 'bold', 'color' => '#4b2682'],
                ['type' => 'text', 'text' => $desc, 'size' => 'xs', 'color' => '#555555', 'wrap' => true],
            ]],
        ]];
    }
    $body[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'xl', 'paddingAll' => '10px',
               'backgroundColor' => '#f4f1fb', 'cornerRadius' => '8px', 'contents' => [
        ['type' => 'text', 'text' => '🔒 คำตอบเห็นเฉพาะคุณในแชทนี้', 'size' => 'xs', 'color' => '#4b2682', 'wrap' => true],
    ]];
    return [
        'type' => 'flex', 'altText' => 'วิธีใช้ไลน์ Production',
        'contents' => line_bot_bubble($body),
    ];
}

/**
 * แบดจ์สถานะเครื่อง (สีจาก ui_status_palette.php เท่านั้น)
 *
 * @param string $status
 * @return array<string,mixed>
 */
function line_bot_status_badge(string $status): array
{
    $p = status_palette_entry($status);
    return [
        'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
        'backgroundColor' => $p['bg'], 'cornerRadius' => '6px',
        'paddingStart' => '8px', 'paddingEnd' => '8px', 'paddingTop' => '2px', 'paddingBottom' => '2px',
        'contents' => [['type' => 'text', 'text' => $p['chip'], 'size' => 'xxs', 'color' => $p['fg'], 'weight' => 'bold']],
    ];
}

/**
 * Flex bubble ห่อมาตรฐาน
 *
 * @param array<int,array<string,mixed>> $body
 * @param array<int,array<string,mixed>> $footer
 * @return array<string,mixed>
 */
function line_bot_bubble(array $body, array $footer = []): array
{
    $b = [
        'type' => 'bubble', 'size' => 'mega',
        'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '16px', 'contents' => $body],
    ];
    if ($footer) {
        $b['footer'] = ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '12px', 'contents' => $footer];
    }
    return $b;
}

/**
 * ปุ่มเปิดเว็บ (ใส่เฉพาะเมื่อได้ URL https)
 *
 * @param string $label
 * @param string $path
 * @return array<int,array<string,mixed>>
 */
function line_bot_web_button(string $label, string $path): array
{
    $url = line_bot_web_url($path);
    if ($url === '') {
        return [];
    }
    return [['type' => 'button', 'style' => 'secondary', 'height' => 'sm',
             'action' => ['type' => 'uri', 'label' => mb_substr($label, 0, 20), 'uri' => $url]]];
}

// ─ ค้นหา ─────────────────────────────────────────────────────────────────────

/**
 * เครื่องที่รหัสตรงเป๊ะ (รหัสเครื่องหรือ S/N โรงงาน) — พิมพ์/สแกน S/N เต็มแล้วได้ประวัติเลย
 *
 * @param string $q
 * @return int asset id หรือ 0
 */
function line_bot_exact_asset(string $q): int
{
    $q = trim($q);
    if ($q === '' || mb_strlen($q) > 60) {
        return 0;
    }
    $row = qr('SELECT id FROM assets WHERE asset_code = ? OR factory_serial = ? ORDER BY (asset_code = ?) DESC, id DESC LIMIT 1',
              'sss', [$q, $q, $q])->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

/**
 * ตอบคำค้นหา — S/N ตรงเป๊ะ = การ์ดประวัติ · ไม่งั้นรายการผลค้นหาแบบช่องค้นหาในเว็บ
 *
 * @param string $q
 * @return array<int,array<string,mixed>> messages
 */
function line_bot_answer_query(string $q): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? $q);
    if (mb_strlen($q) < 2) {
        return [line_bot_text('พิมพ์อย่างน้อย 2 ตัวอักษรครับ')];
    }
    $aid = line_bot_exact_asset($q);
    if ($aid > 0) {
        return line_bot_history_messages($aid);
    }
    try {
        $items = smart_search_merge_assets(smart_search_link_assets(smart_search_query($q, 5), $q));
    } catch (\Throwable $e) {
        error_log('[line_bot_answer_query] ' . $e->getMessage());
        return [line_bot_text('ค้นหาไม่สำเร็จ ลองใหม่อีกครั้งครับ')];
    }
    if (!$items) {
        return [line_bot_text('ไม่พบ "' . mb_substr($q, 0, 40) . '"' . "\nลองพิมพ์ให้สั้นลง หรือพิมพ์ S/N บางส่วนครับ")];
    }
    return [line_bot_search_flex($q, $items)];
}

/**
 * การ์ดผลค้นหา
 *
 * @param string                           $q
 * @param array<int,array<string,mixed>>   $items ผลจาก smart_search (รวมเครื่องแล้ว)
 * @return array<string,mixed>
 */
function line_bot_search_flex(string $q, array $items): array
{
    $total = count($items);
    $shown = array_slice($items, 0, LINE_BOT_MAX_RESULTS);
    $body = [
        ['type' => 'text', 'text' => 'ค้นหา "' . line_flex_text($q, 30) . '"', 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
        ['type' => 'text', 'text' => 'พบ ' . $total . ' รายการ' . ($total > count($shown) ? ' · แสดง ' . count($shown) . ' รายการแรก' : '')
            . ' · แตะเครื่องเพื่อดูประวัติ', 'size' => 'xs', 'color' => '#888888', 'wrap' => true],
        ['type' => 'separator', 'margin' => 'md'],
    ];
    foreach ($shown as $it) {
        $isAsset = (int) ($it['asset_id'] ?? 0) > 0;
        $title = line_flex_text((string) ($it['title'] ?? ''), 40);
        $sub = $isAsset ? (string) ($it['model'] ?? '') : (string) ($it['subtitle'] ?? '');
        if ($isAsset && !empty($it['sources'])) {
            $labels = array_filter(array_map(function ($s) { return $s['kind'] === 'asset' ? '' : (string) $s['label']; }, $it['sources']));
            if ($labels) {
                $sub .= ' · เจอใน ' . implode(', ', $labels);
            }
        }
        $left = [
            ['type' => 'text', 'text' => $title !== '' ? $title : '-', 'size' => 'sm', 'weight' => 'bold', 'color' => '#222222', 'wrap' => true],
        ];
        if (trim($sub) !== '') {
            $left[] = ['type' => 'text', 'text' => line_flex_text($sub, 90), 'size' => 'xxs', 'color' => '#777777', 'wrap' => true];
        }
        $right = $isAsset && !empty($it['status'])
            ? line_bot_status_badge((string) $it['status'])
            : ['type' => 'text', 'text' => line_flex_text((string) ($it['kind_label'] ?? ''), 14) ?: '-', 'size' => 'xxs', 'color' => '#666666', 'flex' => 0];
        $row = [
            'type' => 'box', 'layout' => 'horizontal', 'spacing' => 'md', 'paddingTop' => '8px', 'paddingBottom' => '8px',
            'contents' => [['type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'contents' => $left], $right],
        ];
        if ($isAsset) {
            $row['action'] = ['type' => 'postback', 'label' => 'ประวัติ', 'data' => 'h=' . (int) $it['asset_id'],
                              'displayText' => 'ประวัติ ' . mb_substr($title, 0, 40)];
        } else {
            $url = line_bot_web_url((string) ($it['href'] ?? ''));
            if ($url !== '') {
                $row['action'] = ['type' => 'uri', 'label' => 'เปิด', 'uri' => $url];
            }
        }
        $body[] = $row;
        $body[] = ['type' => 'separator'];
    }
    array_pop($body);
    return [
        'type' => 'flex', 'altText' => 'ผลค้นหา ' . mb_substr($q, 0, 40) . ' (' . $total . ')',
        'contents' => line_bot_bubble($body, line_bot_web_button('ดูทั้งหมดในเว็บ', 'assets.php?q=' . rawurlencode($q))),
    ];
}

// ─ ประวัติเครื่อง ─────────────────────────────────────────────────────────────

/**
 * การ์ดประวัติเครื่อง
 *
 * @param int $assetId
 * @return array<int,array<string,mixed>> messages
 */
function line_bot_history_messages(int $assetId): array
{
    $a = qr('SELECT a.id, a.asset_code, a.factory_serial, a.status, a.current_fw_version, a.produced_at, p.name pname
             FROM assets a JOIN products p ON p.id = a.product_id WHERE a.id = ?', 'i', [$assetId])->fetch_assoc();
    if (!$a) {
        return [line_bot_text('ไม่พบเครื่องนี้ในระบบแล้วครับ')];
    }
    require_once __DIR__ . '/timeline.php';
    require_once __DIR__ . '/rent_ma_bridge.php';
    $tl = [];
    try {
        $tl = asset_timeline_items($assetId)['tl'];
        if (function_exists('asset_leasing_info')) {
            $lease = asset_leasing_info((string) $a['asset_code'], (string) ($a['factory_serial'] ?? ''));
            $tl = array_merge($tl, rent_leasing_ma_timeline_items($lease));
        }
    } catch (\Throwable $e) {
        error_log('[line_bot_history] ' . $e->getMessage());
    }
    usort($tl, function ($x, $y) {
        return ($y['d'] !== '' ? strtotime($y['d']) : 0) <=> ($x['d'] !== '' ? strtotime($x['d']) : 0);
    });

    $body = [
        ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'md', 'contents' => [
            ['type' => 'text', 'text' => line_flex_text((string) $a['asset_code'], 40), 'weight' => 'bold', 'size' => 'lg', 'wrap' => true, 'flex' => 1],
            line_bot_status_badge((string) $a['status']),
        ]],
        ['type' => 'text', 'text' => line_flex_text(implode(' · ', array_filter([
            (string) $a['pname'],
            $a['current_fw_version'] ? 'FW ' . $a['current_fw_version'] : '',
            $a['produced_at'] ? 'ผลิต ' . date('d/m/Y', strtotime((string) $a['produced_at'])) : '',
        ])), 120), 'size' => 'xs', 'color' => '#777777', 'wrap' => true],
        ['type' => 'separator', 'margin' => 'md'],
    ];
    if (!$tl) {
        $body[] = ['type' => 'text', 'text' => 'ยังไม่มีประวัติ', 'size' => 'sm', 'color' => '#888888', 'margin' => 'md'];
    }
    foreach (array_slice($tl, 0, LINE_BOT_MAX_HISTORY) as $e) {
        $label = trim(html_entity_decode(strip_tags((string) ($e['type'] ?? '')), ENT_QUOTES, 'UTF-8'));
        // เนื้อหาใน timeline เป็น HTML (ชิป · รายการ checklist) — ขึ้นบรรทัด/ปิดกล่องเมื่อไหร่ใส่ตัวคั่นแทน
        // ไม่งั้นข้อความติดกันเป็นพืด
        // รายการ checklist ทั้งชุด (บางรุ่น 25 ข้อ) ยาวเกินการ์ด — เหลือแค่ "checklist N รายการ"
        $txt = preg_replace('#<ul class="tl-checklist">.*?</ul>#s', '', (string) ($e['html'] ?? '')) ?? '';
        $txt = preg_replace('/<br\s*\/?>|<\/(div|li|summary|span|p)>/i', ' · ', $txt) ?? '';
        $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($txt), ENT_QUOTES, 'UTF-8')) ?? '');
        $txt = trim(preg_replace('/(\s*·\s*)+/u', ' · ', $txt) ?? $txt, " ·");
        $body[] = ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'md', 'margin' => 'md', 'contents' => [
            ['type' => 'text', 'text' => $e['d'] !== '' ? date('d/m/y', strtotime($e['d'])) : '-', 'size' => 'xxs', 'color' => '#888888', 'flex' => 0],
            ['type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'contents' => array_values(array_filter([
                ['type' => 'text', 'text' => $label !== '' ? line_flex_text($label, 40) : '-', 'size' => 'xs', 'weight' => 'bold', 'color' => '#333333', 'wrap' => true],
                $txt !== '' ? ['type' => 'text', 'text' => line_flex_text($txt, 140), 'size' => 'xxs', 'color' => '#666666', 'wrap' => true] : null,
            ]))],
        ]];
    }
    if (count($tl) > LINE_BOT_MAX_HISTORY) {
        $body[] = ['type' => 'text', 'text' => 'แสดง ' . LINE_BOT_MAX_HISTORY . ' จาก ' . count($tl) . ' รายการล่าสุด', 'size' => 'xxs', 'color' => '#888888', 'margin' => 'md'];
    }
    return [[
        'type' => 'flex', 'altText' => 'ประวัติ ' . (string) $a['asset_code'],
        'contents' => line_bot_bubble($body, line_bot_web_button('ดูประวัติเต็มในเว็บ', 'asset.php?id=' . $assetId)),
    ]];
}

// ─ สต็อก ────────────────────────────────────────────────────────────────────

/**
 * การ์ดสต็อกคงเหลือ — ชุดตัวเลขเดียวกับตาราง "จำนวนเครื่องแยกตามรุ่น" บน Dashboard
 * (fg_model_stock_map: เครื่องใหม่ · คลังพร้อมเช่า · ขั้นต่ำ · PO ค้าง · ขาด/เกิน) ไม่คิดเอง
 * ตัวเลขในไลน์กับหน้าเว็บจะได้ไม่ขัดกัน
 *
 * @return array<int,array<string,mixed>> messages
 */
function line_bot_stock_messages(): array
{
    require_once dirname(__DIR__, 2) . '/shared/finishgood_shortage_dashboard.php';
    try {
        $map = fg_model_stock_map();
    } catch (\Throwable $e) {
        error_log('[line_bot_stock] ' . $e->getMessage());
        return [line_bot_text('ดึงยอดสต็อกไม่สำเร็จ ลองใหม่อีกครั้งครับ')];
    }
    $names = [];
    $res = qr("SELECT UPPER(TRIM(product_code)) code, name FROM products WHERE product_code IS NOT NULL AND TRIM(product_code) <> ''");
    while ($r = $res->fetch_assoc()) {
        $names[(string) $r['code']] = (string) $r['name'];
    }
    $rows = [];
    foreach ($map['codes'] as $code => $c) {
        $new = (int) ($c['new'] ?? 0);
        $rent = max(0, (int) ($c['rent'] ?? 0));
        $min = (int) ($c['min'] ?? 0);
        $po = (int) ($c['po'] ?? 0);
        // รุ่นที่ไม่มีของและไม่มีเป้าอะไรเลย ไม่ต้องขึ้นให้รก
        if ($new + $rent + $min + $po === 0) {
            continue;
        }
        $rows[] = ['name' => $names[$code] ?? (string) $code, 'new' => $new, 'rent' => $rent, 'min' => $min, 'po' => $po,
                   'state' => (string) ($c['state'] ?? ''), 'gap' => (int) ($c['gap'] ?? 0), 'over' => (int) ($c['over'] ?? 0)];
    }
    if (!$rows) {
        return [line_bot_text($map['error'] !== '' ? 'ดึงยอดสต็อกไม่สำเร็จ: ' . $map['error'] : 'ยังไม่มีข้อมูลสต็อกครับ')];
    }
    // ขาดขึ้นก่อน (ขาดมากก่อน) → ปิดแจ้งเตือน → ที่เหลือเรียงตามเครื่องใหม่
    $rank = ['short' => 0, 'muted' => 1];
    usort($rows, function ($a, $b) use ($rank) {
        return [$rank[$a['state']] ?? 2, -$a['gap'], -$a['new'], $a['name']] <=> [$rank[$b['state']] ?? 2, -$b['gap'], -$b['new'], $b['name']];
    });
    $shortN = count(array_filter($rows, function ($r) { return $r['state'] === 'short'; }));

    $pNew = status_palette_entry('new');
    $pRent = status_palette_entry('rental');
    $num = function (int $n, array $extra = []) {
        return ['type' => 'text', 'text' => number_format($n), 'size' => 'xxs', 'align' => 'end', 'flex' => 2,
                'color' => $n ? '#333333' : '#bbbbbb'] + $extra;
    };
    $hd = function (string $t, string $color = '#888888') {
        return ['type' => 'text', 'text' => $t, 'size' => 'xxs', 'color' => $color, 'weight' => 'bold', 'align' => 'end', 'flex' => 2, 'wrap' => true];
    };
    $body = [
        ['type' => 'text', 'text' => 'สต็อกคงเหลือ', 'weight' => 'bold', 'size' => 'md'],
        ['type' => 'text', 'text' => ($shortN ? 'ต้องผลิตเพิ่ม ' . $shortN . ' รุ่น · ' : 'ไม่มีรุ่นที่ขาด · ')
            . 'ข้อมูล ณ ' . ($map['updated'] !== '' ? $map['updated'] : date('d/m/Y H:i')) . ($map['stale'] ? ' (ข้อมูลเก่า)' : ''),
            'size' => 'xxs', 'color' => '#888888', 'wrap' => true],
        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'spacing' => 'xs', 'contents' => [
            ['type' => 'text', 'text' => 'รุ่น', 'size' => 'xxs', 'color' => '#888888', 'flex' => 6],
            $hd('ใหม่', $pNew['fg']), $hd('พร้อมเช่า', $pRent['fg']), $hd('ขั้นต่ำ'), $hd('PO'), $hd('ผล'),
        ]],
        ['type' => 'separator'],
    ];
    $max = 30;
    foreach (array_slice($rows, 0, $max) as $r) {
        if ($r['state'] === 'short') {
            $res = ['text' => 'ขาด ' . number_format($r['gap']), 'color' => '#991b1b', 'weight' => 'bold'];
        } elseif ($r['state'] === 'muted') {
            $res = ['text' => 'ขาด ' . number_format($r['gap']), 'color' => '#94a3b8'];
        } else {
            $res = $r['over'] > 0 ? ['text' => 'เกิน ' . number_format($r['over']), 'color' => '#166534'] : ['text' => 'พอดี', 'color' => '#475569'];
        }
        $body[] = ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'spacing' => 'xs', 'contents' => [
            ['type' => 'text', 'text' => line_flex_text($r['name'], 40), 'size' => 'xxs', 'color' => '#333333', 'wrap' => true, 'flex' => 6],
            $num($r['new'], ['weight' => 'bold']), $num($r['rent']), $num($r['min']), $num($r['po']),
            ['type' => 'text', 'size' => 'xxs', 'align' => 'end', 'flex' => 2, 'wrap' => true] + $res,
        ]];
    }
    if (count($rows) > $max) {
        $body[] = ['type' => 'text', 'text' => 'และอีก ' . (count($rows) - $max) . ' รุ่น — ดูทั้งหมดใน Dashboard', 'size' => 'xxs', 'color' => '#888888', 'margin' => 'sm'];
    }
    $body[] = ['type' => 'text', 'text' => 'ผล = (ใหม่ + พร้อมเช่า) − (ขั้นต่ำ + PO) · สีจาง = ปิดแจ้งเตือนรุ่นนั้นไว้',
               'size' => 'xxs', 'color' => '#999999', 'wrap' => true, 'margin' => 'md'];
    $bubble = line_bot_bubble($body, line_bot_web_button('เปิด Dashboard', 'index.php'));
    $bubble['size'] = 'giga';   // 6 คอลัมน์ — mega แคบจนตัวเลขตกบรรทัด
    return [[
        'type' => 'flex', 'altText' => 'สต็อกคงเหลือ' . ($shortN ? ' · ต้องผลิตเพิ่ม ' . $shortN . ' รุ่น' : ''),
        'contents' => $bubble,
    ]];
}

// ─ ตัวจัดการ event ────────────────────────────────────────────────────────────

/**
 * ตอบ event จากคนที่ผูกไลน์แล้ว (ข้อความ / postback)
 *
 * @param array<string,mixed> $ev
 * @return array<int,array<string,mixed>> messages ('' = ไม่ต้องตอบ)
 */
function line_bot_handle(array $ev): array
{
    $type = (string) ($ev['type'] ?? '');
    if ($type === 'postback') {
        $data = (string) ($ev['postback']['data'] ?? '');
        if (preg_match('/^h=(\d+)$/', $data, $m)) {
            return line_bot_history_messages((int) $m[1]);
        }
        switch ($data) {
            case LINE_BOT_PB_SEARCH:
                return [line_bot_text("พิมพ์สิ่งที่อยากค้นหาในช่องแชทได้เลยครับ\nเช่น A4N69080374 · bitScan · ชื่อลูกค้า\n\nพิมพ์ S/N เต็ม = ได้ประวัติเครื่องทันที")];
            case LINE_BOT_PB_HISTORY:
                return [line_bot_text("พิมพ์ S/N เต็มของเครื่อง หรือกด \"สแกน S/N\" ด้านล่างครับ")];
            case LINE_BOT_PB_STOCK:
                return line_bot_stock_messages();
            case LINE_BOT_PB_SCAN:
                return [line_bot_text("ยังไม่ได้ตั้งค่าหน้าสแกนในระบบหลังบ้าน (LIFF ID)\nระหว่างนี้พิมพ์ S/N แทนได้ครับ")];
            case LINE_BOT_PB_HELP:
            default:
                return [line_bot_help_flex()];
        }
    }
    if ($type === 'message' && (string) ($ev['message']['type'] ?? '') === 'text') {
        return line_bot_answer_query((string) ($ev['message']['text'] ?? ''));
    }
    if ($type === 'message') {
        return [line_bot_text('ตอนนี้ตอบได้เฉพาะข้อความครับ พิมพ์ S/N หรือคำที่ต้องการค้นหาได้เลย')];
    }
    return [];
}

// ─ LINE API ─────────────────────────────────────────────────────────────────

/**
 * เรียก LINE Messaging API
 *
 * @param string               $method GET|POST|DELETE
 * @param string               $url
 * @param string               $token
 * @param string|null          $body
 * @param string               $contentType
 * @return array{code:int, body:string}
 */
function line_bot_api(string $method, string $url, string $token, ?string $body = null, string $contentType = 'application/json'): array
{
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token];
    if ($body !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    } elseif ($method === 'POST') {
        // POST ไม่มีเนื้อหา (เช่น ตั้งริชเมนูหลัก) — curl ไม่ใส่ Content-Length ให้เอง
        // หน้าบ้านของ LINE (Akamai) ตอบ 411 Length Required
        $body = '';
        $headers[] = 'Content-Length: 0';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (function_exists('line_notify_apply_curl_ssl')) {
        line_notify_apply_curl_ssl($ch);
    }
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($out) ? $out : $err];
}

/**
 * ตอบกลับด้วย reply token (ส่งได้สูงสุด 5 ข้อความ · token ใช้ได้ครั้งเดียว)
 *
 * @param string                           $replyToken
 * @param array<int,array<string,mixed>>   $messages
 * @param string                           $bot
 * @return bool
 */
function line_bot_reply(string $replyToken, array $messages, string $bot): bool
{
    $token = line_notify_bot_token($bot);
    if ($replyToken === '' || $token === '' || !$messages) {
        return false;
    }
    $r = line_bot_api('POST', 'https://api.line.me/v2/bot/message/reply', $token, json_encode([
        'replyToken' => $replyToken,
        'messages'   => array_slice(array_values($messages), 0, 5),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($r['code'] !== 200) {
        error_log('[line_bot_reply] HTTP ' . $r['code'] . ' ' . mb_substr($r['body'], 0, 500));
        // Flex ผิดรูปแบบ = LINE ไม่ส่งอะไรเลย — ส่งข้อความธรรมดาแทน คนถามจะได้ไม่เงียบหาย
        // (reply token ยังไม่ถูกใช้เพราะคำขอแรกถูกปฏิเสธ)
        if ($r['code'] === 400 && ($messages[0]['type'] ?? '') === 'flex') {
            line_bot_api('POST', 'https://api.line.me/v2/bot/message/reply', $token, json_encode([
                'replyToken' => $replyToken,
                'messages'   => [['type' => 'text', 'text' => 'แสดงผลไม่สำเร็จ ลองเปิดในเว็บแทนครับ']],
            ], JSON_UNESCAPED_UNICODE));
        }
        return false;
    }
    return true;
}

// ─ ริชเมนู ──────────────────────────────────────────────────────────────────

/** @var string path รูปริชเมนู (2500×1686 · 6 ช่อง 3×2) */
const LINE_BOT_RICHMENU_IMAGE = __DIR__ . '/../assets/line/richmenu.png';

/**
 * โครงริชเมนู 5 ปุ่ม (บน 2 · ล่าง 3) — ตำแหน่งต้องตรงกับรูป assets/line/richmenu.png
 *
 * @return array<string,mixed>
 */
function line_bot_richmenu_def(): array
{
    $liff = line_bot_liff_url();
    $web = line_bot_web_url('index.php');
    $pb = function (string $data, string $text) {
        return ['type' => 'postback', 'data' => $data, 'displayText' => $text];
    };
    // แถวบน 2 ปุ่มใหญ่ (ครึ่งจอ) · แถวล่าง 3 ปุ่ม — "ค้นหา" กับ "ประวัติ" รวมเป็นปุ่มเดียว
    // เพราะบอตแยกให้เองอยู่แล้ว (S/N เต็ม = การ์ดประวัติ · ไม่เต็ม = รายการผลค้นหา)
    $areas = [
        [0, 0, 1250, $pb(LINE_BOT_PB_SEARCH, 'พิมพ์ค้นหา')],
        // ยังไม่ตั้ง LIFF = ปุ่มตอบว่ายังไม่พร้อม แทนที่จะกดแล้วไม่เกิดอะไร
        [1250, 0, 1250, $liff !== '' ? ['type' => 'uri', 'uri' => $liff] : $pb(LINE_BOT_PB_SCAN, 'สแกนด้วยกล้อง')],
        [0, 843, 833, $pb(LINE_BOT_PB_STOCK, 'เช็คสต็อก')],
        [833, 843, 834, $web !== '' ? ['type' => 'uri', 'uri' => $web] : $pb(LINE_BOT_PB_HELP, 'วิธีใช้')],
        [1667, 843, 833, $pb(LINE_BOT_PB_HELP, 'วิธีใช้')],
    ];
    return [
        'size'        => ['width' => 2500, 'height' => 1686],
        'selected'    => true,
        'name'        => 'production-menu',
        'chatBarText' => 'เมนู Production',
        'areas'       => array_map(function ($a) {
            return ['bounds' => ['x' => $a[0], 'y' => $a[1], 'width' => $a[2], 'height' => 843], 'action' => $a[3]];
        }, $areas),
    ];
}

/**
 * ติดตั้งริชเมนูเป็นค่าเริ่มต้นของ bot — สร้างใหม่ → อัปรูป → ตั้งเป็น default → ลบของเก่าที่เราเคยสร้าง
 *
 * @param string $bot
 * @return array{ok:bool, message:string}
 */
function line_bot_install_richmenu(string $bot): array
{
    $token = line_notify_bot_token($bot);
    if ($token === '') {
        return ['ok' => false, 'message' => 'ยังไม่ได้ใส่ Channel Access Token ของ bot สำรอง'];
    }
    if (!is_file(LINE_BOT_RICHMENU_IMAGE)) {
        return ['ok' => false, 'message' => 'ไม่พบรูปริชเมนู assets/line/richmenu.png'];
    }
    $old = [];
    $r = line_bot_api('GET', 'https://api.line.me/v2/bot/richmenu/list', $token);
    if ($r['code'] === 200) {
        foreach ((json_decode($r['body'], true)['richmenus'] ?? []) as $m) {
            if (($m['name'] ?? '') === 'production-menu') {
                $old[] = (string) $m['richMenuId'];
            }
        }
    }
    $r = line_bot_api('POST', 'https://api.line.me/v2/bot/richmenu', $token, json_encode(line_bot_richmenu_def(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $id = $r['code'] === 200 ? (string) (json_decode($r['body'], true)['richMenuId'] ?? '') : '';
    if ($id === '') {
        return ['ok' => false, 'message' => 'สร้างริชเมนูไม่สำเร็จ (HTTP ' . $r['code'] . '): ' . mb_substr($r['body'], 0, 300)];
    }
    $r = line_bot_api('POST', 'https://api-data.line.me/v2/bot/richmenu/' . rawurlencode($id) . '/content', $token,
                      (string) file_get_contents(LINE_BOT_RICHMENU_IMAGE), 'image/png');
    if ($r['code'] !== 200) {
        line_bot_api('DELETE', 'https://api.line.me/v2/bot/richmenu/' . rawurlencode($id), $token);
        return ['ok' => false, 'message' => 'อัปรูปริชเมนูไม่สำเร็จ (HTTP ' . $r['code'] . '): ' . mb_substr($r['body'], 0, 300)];
    }
    $r = line_bot_api('POST', 'https://api.line.me/v2/bot/user/all/richmenu/' . rawurlencode($id), $token);
    if ($r['code'] !== 200) {
        return ['ok' => false, 'message' => 'ตั้งเป็นเมนูหลักไม่สำเร็จ (HTTP ' . $r['code'] . '): ' . mb_substr($r['body'], 0, 300)];
    }
    foreach ($old as $oid) {
        line_bot_api('DELETE', 'https://api.line.me/v2/bot/richmenu/' . rawurlencode($oid), $token);
    }
    return ['ok' => true, 'message' => 'ติดตั้งริชเมนูแล้ว' . (line_bot_liff_url() === '' ? ' (ปุ่มสแกนยังไม่ทำงาน — ใส่ LIFF ID แล้วกดติดตั้งใหม่)' : '')];
}

/**
 * สถานะริชเมนูปัจจุบันของ bot
 *
 * @param string $bot
 * @return string ข้อความสั้น
 */
function line_bot_richmenu_status(string $bot): string
{
    $token = line_notify_bot_token($bot);
    if ($token === '') {
        return 'ยังไม่ได้ใส่ Token ของ bot สำรอง';
    }
    $r = line_bot_api('GET', 'https://api.line.me/v2/bot/user/all/richmenu', $token);
    if ($r['code'] === 404) {
        return 'ยังไม่ได้ติดตั้ง';
    }
    if ($r['code'] !== 200) {
        return 'ตรวจสอบไม่ได้ (HTTP ' . $r['code'] . ')';
    }
    $id = (string) (json_decode($r['body'], true)['richMenuId'] ?? '');
    $m = line_bot_api('GET', 'https://api.line.me/v2/bot/richmenu/' . rawurlencode($id), $token);
    $name = $m['code'] === 200 ? (string) (json_decode($m['body'], true)['name'] ?? '') : '';
    return $name === 'production-menu' ? 'ติดตั้งแล้ว' : 'มีริชเมนูอื่นอยู่ (' . ($name !== '' ? $name : $id) . ') — กดติดตั้งเพื่อแทนที่';
}
