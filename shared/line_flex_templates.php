<?php
/**
 * shared/line_flex_templates.php — สร้าง Flex Message ต่อ event แจ้งเตือน LINE
 *
 * port จาก Appscript.txt (AppSheet webhook) + event Phase 1 ใหม่
 * เรียกผ่าน line_flex_build_messages($eventKey, $payload)
 */

require_once __DIR__ . '/line_notify_core.php';

// ─ Helpers ───────────────────────────────────────────────────────────────────

/**
 * ตัดและ sanitize ข้อความสำหรับ Flex
 *
 * @param string $text
 * @param int    $max
 * @return string
 */
function line_flex_text(string $text, int $max = 200): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    if (mb_strlen($text) > $max) {
        return mb_substr($text, 0, $max - 1) . '…';
    }
    return $text;
}

/**
 * แถว key-value ใน body
 *
 * @param string $label
 * @param string $value
 * @return array<string,mixed>
 */
function line_flex_kv(string $label, string $value): array
{
    $label = line_flex_text($label, 40);
    $value = line_flex_text($value, 120);
    if ($label === '') {
        $label = '-';
    }
    if ($value === '') {
        $value = '-';
    }
    return [
        'type'   => 'box',
        'layout' => 'horizontal',
        'margin' => 'sm',
        'contents' => [
            ['type' => 'text', 'text' => $label, 'size' => 'xs', 'color' => '#888888', 'flex' => 4],
            ['type' => 'text', 'text' => line_flex_text($value, 120), 'size' => 'xs', 'color' => '#333333', 'flex' => 6, 'align' => 'end', 'wrap' => true, 'weight' => 'bold'],
        ],
    ];
}

/**
 * ปุ่ม footer
 *
 * @param string $label
 * @param string $uri
 * @return array<string,mixed>
 */
function line_flex_footer_btn(string $label, string $uri): array
{
    return [
        'type'   => 'box',
        'layout' => 'vertical',
        'paddingAll' => '12px',
        'contents' => [[
            'type'   => 'button',
            'style'  => 'primary',
            'height' => 'sm',
            'color'  => '#1a5fa8',
            'action' => ['type' => 'uri', 'label' => line_flex_text($label, 40), 'uri' => $uri],
        ]],
    ];
}

/**
 * ไฟล์รูปแทน เมื่อรุ่นสินค้ายังไม่มีรูปในระบบ — อยู่บนเซิร์ฟเวอร์เรา
 *
 * @var string
 */
const LINE_FLEX_FALLBACK_IMAGE = '/production/finishgoogs_ma_update/assets/line-production-icon.png';

/**
 * map ชื่อรุ่น → products.icon_path (รูปที่อัปในหน้าตั้งค่า → รุ่นสินค้า)
 *
 * โหลดครั้งเดียวต่อ request แล้ว cache ไว้ ไม่ query ต่อแถว
 *
 * @return array<string,string> ชื่อรุ่นตัวพิมพ์เล็ก => icon_path
 */
function line_flex_product_icon_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!function_exists('db')) {
        return $map;
    }
    try {
        $res = db()->query("SELECT name, icon_path FROM products WHERE icon_path IS NOT NULL AND icon_path <> ''");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $name = trim((string) $row['name']);
                if ($name !== '') {
                    $map[mb_strtolower($name, 'UTF-8')] = (string) $row['icon_path'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('line_flex_product_icon_map failed: ' . $e->getMessage());
    }
    return $map;
}

/**
 * ชื่ออะไหล่ → ไฟล์รูป (ตาราง parts คนละตารางกับรุ่นสินค้า)
 *
 * รายการในหมวด "เบิกอะไหล่" เป็นชื่ออะไหล่ ไม่ใช่ชื่อรุ่นเครื่อง ถ้าเอาไปหาใน
 * products จะไม่เจอแล้วตกไปใช้รูปแทนทั้งหมด — คนอ่านเห็นรูปไม่ตรงของ
 *
 * @return array<string,string> key = ชื่อตัวพิมพ์เล็ก
 */
function line_flex_part_icon_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!function_exists('db')) {
        return $map;
    }
    try {
        $res = db()->query("SELECT name, icon_path FROM parts WHERE icon_path IS NOT NULL AND icon_path <> ''");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $name = trim((string) $row['name']);
                if ($name !== '') {
                    $map[mb_strtolower($name, 'UTF-8')] = (string) $row['icon_path'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('line_flex_part_icon_map failed: ' . $e->getMessage());
    }
    return $map;
}

/**
 * URL รูปของอะไหล่สำหรับ LINE Flex — ไม่เจอก็คืนว่าง ให้ผู้เรียกตัดสินใจเอง
 *
 * @param  string $partName
 * @return string
 */
function line_flex_part_image(string $partName): string
{
    $key = mb_strtolower(trim($partName), 'UTF-8');
    $map = line_flex_part_icon_map();
    if ($key === '' || !isset($map[$key]) || !function_exists('img_url')) {
        return '';
    }
    $url = line_notify_normalize_public_url((string) img_url($map[$key]), '');
    return preg_match('#^https://#i', $url) ? $url : '';
}

/**
 * URL รูปของรุ่นสินค้าสำหรับ LINE Flex — ใช้รูปบนเซิร์ฟเวอร์เราเท่านั้น
 *
 * ลำดับ: รูปที่อัปไว้กับรุ่นสินค้า → รูปแทนบนเซิร์ฟเวอร์
 *
 * เดิมมี map รูป Google Drive ฝังไว้ในโค้ดเป็นชั้นกลาง ทำให้เปลี่ยนรูปจากหลังบ้าน
 * ไม่ได้และผูกกับบัญชี Drive ภายนอก — เอาออกแล้ว
 *
 * LINE ต้องโหลดรูปเองจากภายนอก URL จึงต้องเป็น https สาธารณะ ค่าที่ใช้ประกอบ
 * โดเมนคือ public_site_host ในหน้าตั้งค่าแจ้งเตือน LINE (cron รันแบบ CLI
 * ไม่มี HTTP_HOST ให้เดา ถ้าไม่ตั้งค่านั้นรูปจะโหลดไม่ขึ้น)
 *
 * @param string $model      ชื่อรุ่น
 * @param string $imageUrl   URL ที่ส่งมากับข้อมูล (ยอมรับเฉพาะ https ของเราเอง)
 * @return string
 */
function line_flex_product_image(string $model, string $imageUrl = ''): string
{
    // 1) รูปที่อัปไว้กับรุ่นสินค้า
    $key = mb_strtolower(trim($model), 'UTF-8');
    $map = line_flex_product_icon_map();
    if ($key !== '' && isset($map[$key]) && function_exists('img_url')) {
        $url = line_notify_normalize_public_url((string) img_url($map[$key]), '');
        if (preg_match('#^https://#i', $url)) {
            return $url;
        }
    }

    // 2) URL ที่ส่งมากับข้อมูล — รับเฉพาะที่เป็น https และไม่ใช่ของนอกบ้าน
    if ($imageUrl !== '' && stripos($imageUrl, 'drive.google.com') === false) {
        $url = line_notify_normalize_public_url($imageUrl, '');
        if (preg_match('#^https://#i', $url)) {
            return $url;
        }
    }

    // 3) รูปแทนบนเซิร์ฟเวอร์เรา
    return line_notify_normalize_public_url(LINE_FLEX_FALLBACK_IMAGE, '');
}

/** @var int จำนวนรายการอะไหล่ต่ำสูงสุดในการ์ดเดียว (ไม่แบ่ง carousel) */
const LINE_FLEX_LOW_STOCK_SINGLE_CARD_MAX = 10;

/**
 * แบ่งรายการอะไหล่ต่ำเป็นกลุ่มต่อการ์ด — ≤10 การ์ดเดียว, มากกว่านั้นแบ่งเฉลี่ย 2–4 การ์ด
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<int,array<string,mixed>>>
 */
/** ป้ายกลุ่มสำหรับอะไหล่ที่ยังไม่ได้ระบุผู้จำหน่าย */
const LINE_FLEX_LOW_STOCK_NO_SUPPLIER = 'ไม่ระบุผู้จำหน่าย';

/** จำนวนการ์ดสูงสุดที่ยอมให้ส่งต่อรอบ — กันกรณีผู้จำหน่ายเยอะจนยิงข้อความรัว */
const LINE_FLEX_LOW_STOCK_MAX_CARDS = 20;

/**
 * จัดกลุ่มอะไหล่ต่ำตามผู้จำหน่าย — ผู้จำหน่ายที่ระบุชื่อขึ้นก่อน (สั่งซื้อได้ทันที)
 * กลุ่ม "ไม่ระบุผู้จำหน่าย" ไปท้ายสุดเสมอ
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<string,array<int,array<string,mixed>>> ชื่อผู้จำหน่าย => รายการ
 */
function line_flex_low_stock_group_by_supplier(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sup = trim((string)($item['supplier'] ?? ''));
        if ($sup === '' || $sup === '-') {
            $sup = LINE_FLEX_LOW_STOCK_NO_SUPPLIER;
        }
        $groups[$sup][] = $item;
    }

    // เรียงของน้อยสุดขึ้นก่อนในแต่ละกลุ่ม (เร่งด่วนสุดอยู่บน)
    foreach ($groups as $sup => $rows) {
        usort($rows, function ($a, $b) {
            return ((int)($a['quantity'] ?? 0)) <=> ((int)($b['quantity'] ?? 0));
        });
        $groups[$sup] = $rows;
    }

    $noSup = null;
    if (isset($groups[LINE_FLEX_LOW_STOCK_NO_SUPPLIER])) {
        $noSup = $groups[LINE_FLEX_LOW_STOCK_NO_SUPPLIER];
        unset($groups[LINE_FLEX_LOW_STOCK_NO_SUPPLIER]);
    }

    // ผู้จำหน่ายที่มีรายการมากสุดขึ้นก่อน
    uasort($groups, function ($a, $b) {
        return count($b) <=> count($a);
    });

    if ($noSup !== null) {
        $groups[LINE_FLEX_LOW_STOCK_NO_SUPPLIER] = $noSup;
    }

    return $groups;
}

function line_flex_low_stock_card_chunks(array $items): array
{
    $total = count($items);
    if ($total <= LINE_FLEX_LOW_STOCK_SINGLE_CARD_MAX) {
        return [$items];
    }
    if ($total <= 20) {
        $numCards = 2;
    } elseif ($total <= 30) {
        $numCards = 3;
    } else {
        $numCards = 4;
    }
    $base = intdiv($total, $numCards);
    $extra = $total % $numCards;
    $chunks = [];
    $offset = 0;
    for ($i = 0; $i < $numCards; $i++) {
        $size = $base + ($i < $extra ? 1 : 0);
        if ($size <= 0) {
            continue;
        }
        $chunks[] = array_slice($items, $offset, $size);
        $offset += $size;
    }
    return $chunks === [] ? [$items] : $chunks;
}

/**
 * URL ไอคอน Production System สำหรับ footer link (ต้องเป็น https)
 *
 * @return string
 */
function line_flex_system_link_icon_url(): string
{
    // ไฟล์อยู่ที่ finishgoogs_ma_update/assets/ ไม่ใช่ราก /production/
    // เดิมประกอบจาก base url ของแอปแล้วได้ path ผิด ตอบ 404 — แต่ถูกบังไว้ด้วย
    // รูปสำรองบน Google Drive จึงไม่มีใครเห็นว่าพัง
    return line_notify_normalize_public_url(LINE_FLEX_FALLBACK_IMAGE, '');
}

/**
 * นับจำนวนต่อผู้บันทึก
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<string,int>
 */
function line_flex_count_creators(array $items): array
{
    $creatorCount = [];
    foreach ($items as $item) {
        $raw = trim((string)($item['create_name'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $n = line_flex_text($raw, 40);
        $creatorCount[$n] = ($creatorCount[$n] ?? 0) + 1;
    }
    return $creatorCount;
}

/**
 * แถวสรุปผู้บันทึก (แบบ AppScript creatorSummaryRows)
 *
 * @param array<string,int> $creatorCount
 * @param string            $countColor
 * @return array<int,array<string,mixed>>
 */
function line_flex_creator_summary_rows(array $creatorCount, string $countColor = '#0057b8'): array
{
    $rows = [];
    foreach ($creatorCount as $name => $cnt) {
        $rows[] = [
            'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '4px',
            'contents' => [
                ['type' => 'text', 'text' => line_flex_required_text($name, 40), 'size' => 'sm', 'color' => '#444444', 'flex' => 5],
                ['type' => 'text', 'text' => $cnt . ' รายการ', 'size' => 'sm', 'color' => $countColor, 'flex' => 3, 'align' => 'end', 'weight' => 'bold'],
            ],
        ];
    }
    return $rows;
}

/**
 * แถวลิงก์ footer (logo + Production System + ›)
 *
 * @param string $uri
 * @param string $label
 * @param string $bgColor
 * @return array<string,mixed>
 */
function line_flex_system_link_row(string $uri, string $label = 'Production System', string $bgColor = '#eef4ff'): array
{
    $actionUri = line_flex_action_uri($uri);
    $row = [
        'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => $bgColor, 'cornerRadius' => '8px',
        'paddingAll' => '10px', 'margin' => 'md', 'alignItems' => 'center',
        'contents' => [
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'justifyContent' => 'center',
                'contents' => [['type' => 'image', 'url' => line_flex_system_link_icon_url(), 'size' => 'xxs', 'align' => 'center']],
            ],
            ['type' => 'text', 'text' => line_flex_text($label, 40), 'size' => 'sm', 'color' => '#1a5fa8', 'weight' => 'bold', 'flex' => 4, 'align' => 'center'],
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'justifyContent' => 'center',
                'contents' => [['type' => 'text', 'text' => '›', 'size' => 'xl', 'color' => '#1a5fa8', 'align' => 'center']],
            ],
        ],
    ];
    if ($actionUri !== '') {
        $row['action'] = ['type' => 'uri', 'uri' => $actionUri];
    }
    return $row;
}

/**
 * URI สำหรับ action ใน Flex — normalize จาก config/path ที่ส่งมา
 *
 * @param string $uri
 * @return string
 */
function line_flex_action_uri(string $uri): string
{
    return line_notify_sanitize_https_uri($uri);
}

/**
 * URL ลิงก์ production จาก payload (หรือ index เป็นค่าเริ่มต้น)
 *
 * @param array<string,mixed> $payload
 * @return string
 */
function line_flex_production_link_from_payload(array $payload): string
{
    if (!empty($payload['link_url'])) {
        return line_flex_action_uri((string)$payload['link_url']);
    }
    $base = line_notify_production_base_url();
    if (!empty($payload['asset_id'])) {
        return line_flex_action_uri($base . '/asset.php?id=' . (int)$payload['asset_id']);
    }
    return line_flex_action_uri($base . '/index.php');
}

/**
 * URL ลิงก์ parts จาก payload
 *
 * @param array<string,mixed> $payload
 * @return string
 */
function line_flex_parts_link_from_payload(array $payload): string
{
    if (!empty($payload['link_url'])) {
        return line_flex_action_uri((string)$payload['link_url']);
    }
    $base = line_notify_parts_base_url();
    if (!empty($payload['product_id'])) {
        return line_flex_action_uri($base . '/pages/product-detail.php?id=' . (int)$payload['product_id']);
    }
    return line_flex_action_uri($base . '/index.php');
}

/**
 * ข้อความ branding ท้ายการ์ด
 *
 * @return array<string,mixed>
 */
function line_flex_system_branding(): array
{
    return ['type' => 'text', 'text' => '✅ Production System', 'size' => 'xxs', 'color' => '#bbbbbb', 'align' => 'center', 'margin' => 'md'];
}

/**
 * Header ตาราง serial ใน body
 *
 * @return array<string,mixed>
 */
function line_flex_serial_table_header(): array
{
    return [
        'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => '#f7f7f7', 'cornerRadius' => '6px', 'paddingAll' => '6px', 'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => '#', 'size' => 'xxs', 'color' => '#999999', 'flex' => 1, 'align' => 'center', 'weight' => 'bold'],
            ['type' => 'text', 'text' => 'Serial Number', 'size' => 'xxs', 'color' => '#999999', 'flex' => 6, 'align' => 'center', 'weight' => 'bold'],
            ['type' => 'text', 'text' => 'ผู้บันทึก', 'size' => 'xxs', 'color' => '#999999', 'flex' => 3, 'align' => 'center', 'weight' => 'bold'],
        ],
    ];
}

/**
 * แถว serial ใน body
 *
 * @param int                 $index
 * @param array<string,mixed> $item
 * @param bool                $isNew
 * @return array<string,mixed>
 */
function line_flex_serial_row(int $index, array $item, bool $isNew = false): array
{
    $numColor = $isNew ? '#1a8a4a' : '#aaaaaa';
    $serialColor = $isNew ? '#1a8a4a' : '#333333';
    return [
        'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '6px', 'paddingStart' => '10px', 'paddingEnd' => '10px',
        'contents' => [
            ['type' => 'text', 'text' => $index . '.', 'size' => 'sm', 'color' => $numColor, 'flex' => 1, 'align' => 'center'] + ($isNew ? ['weight' => 'bold'] : []),
            ['type' => 'text', 'text' => line_flex_required_text((string)($item['serial_number'] ?? ''), 40), 'size' => 'sm', 'color' => $serialColor, 'flex' => 6, 'align' => 'center', 'weight' => 'bold', 'wrap' => true],
            ['type' => 'text', 'text' => line_flex_required_text((string)($item['create_name'] ?? ''), 30), 'size' => 'sm', 'color' => '#555555', 'flex' => 3, 'align' => 'end', 'wrap' => true],
        ],
    ];
}

/**
 * แถว model พร้อม progress bar (summary รายสัปดาห์/เดือน)
 *
 * @param string $model
 * @param int    $count
 * @param int    $totalAll
 * @param string $accentTxt
 * @return array<string,mixed>
 */
function line_flex_model_progress_row(string $model, int $count, int $totalAll, string $accentTxt): array
{
    $pct = $totalAll > 0 ? (int)round($count / $totalAll * 100) : 0;
    $barFlex = max($pct, 1);
    $bgFlex = max(100 - $pct, 1);
    return [
        'type' => 'box', 'layout' => 'vertical', 'paddingTop' => '8px',
        'contents' => [
            [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => line_flex_required_text($model, 80), 'size' => 'xs', 'color' => '#333333', 'flex' => 6, 'wrap' => true],
                    ['type' => 'text', 'text' => $count . ' รายการ', 'size' => 'xs', 'color' => $accentTxt, 'flex' => 3, 'align' => 'end', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => $pct . '%', 'size' => 'xs', 'color' => '#aaaaaa', 'flex' => 2, 'align' => 'end'],
                ],
            ],
            [
                'type' => 'box', 'layout' => 'horizontal', 'height' => '4px', 'margin' => 'xs',
                'contents' => [
                    ['type' => 'box', 'layout' => 'vertical', 'flex' => $barFlex, 'backgroundColor' => $accentTxt, 'cornerRadius' => '2px', 'contents' => [['type' => 'filler']]],
                    ['type' => 'box', 'layout' => 'vertical', 'flex' => $bgFlex, 'backgroundColor' => '#eeeeee', 'cornerRadius' => '2px', 'contents' => [['type' => 'filler']]],
                ],
            ],
        ],
    ];
}

/**
 * ข้อความที่ Flex ต้องไม่ว่าง
 *
 * @param string $text
 * @param int    $max
 * @return string
 */
function line_flex_required_text(string $text, int $max = 200): string
{
    $t = line_flex_text($text, $max);
    return $t === '' ? '-' : $t;
}

/**
 * สร้าง array ข้อความ LINE จาก event + payload
 *
 * @param string              $eventKey
 * @param array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_build_messages(string $eventKey, array $payload): array
{
    switch ($eventKey) {
        case 'production.problem_found':
            return [line_flex_problem_found($payload)];
        case 'stock.low_threshold':
            if (!empty($payload['batch'])) {
                return line_flex_low_stock_alert_messages($payload);
            }
            return [line_flex_low_stock_alert_single($payload)];
        case 'ma.repair_required':
            return [line_flex_ma_repair($payload)];
        case 'stock.manual_withdraw':
            if (!empty($payload['withdraw_items']) && is_array($payload['withdraw_items'])) {
                $flex = line_flex_withdraw_daily_summary($payload['withdraw_items']);
                return $flex !== null ? [$flex] : [];
            }
            return [line_flex_manual_withdraw($payload)];
        case 'production.summary.daily':
            if (!empty($payload['no_data'])) {
                return [line_flex_production_no_data_daily((string)($payload['timestamp'] ?? date('d/m/Y H:i')))];
            }
            return line_flex_production_daily_bundle($payload);
        case 'production.summary.daily_update':
            return line_flex_production_update_carousel($payload);
        case 'production.summary.weekly':
            if (!empty($payload['no_data'])) {
                return [line_flex_production_no_data_summary('week', (string)($payload['timestamp'] ?? date('d/m/Y H:i')))];
            }
            return [line_flex_production_summary_overview($eventKey, $payload)];
        case 'production.summary.monthly':
            if (!empty($payload['no_data'])) {
                return [line_flex_production_no_data_summary('month', (string)($payload['timestamp'] ?? date('d/m/Y H:i')))];
            }
            return [line_flex_production_summary_overview($eventKey, $payload)];
        case 'finishgood.shortage':
            require_once __DIR__ . '/line_flex_finishgood_shortage.php';
            $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
            return line_flex_finishgood_shortage_messages($items, (string)($payload['timestamp'] ?? date('d/m/Y H:i') . ' น.'));
        case 'work.summary.monthly':
            require_once __DIR__ . '/line_flex_work_summary.php';
            return line_flex_work_summary_messages($payload);
        case 'line.test':
            return [['type' => 'text', 'text' => line_flex_text((string)($payload['message'] ?? 'ทดสอบ LINE ✅'), 500)]];
        default:
            return [];
    }
}

// ─ Event: production.problem_found ───────────────────────────────────────────

/**
 * Flex เมื่อพบปัญหาตอนผลิต
 *
 * @param array<string,mixed> $p
 * @return array<string,mixed>
 */
function line_flex_problem_found(array $p): array
{
    $code = line_flex_text((string)($p['asset_code'] ?? '-'), 80);
    $model = line_flex_text((string)($p['model'] ?? '-'), 80);
    $problems = line_flex_text((string)($p['problems'] ?? ''), 200);
    $fix = line_flex_text((string)($p['fix'] ?? ''), 120);
    $madeBy = line_flex_text((string)($p['made_by'] ?? '-'), 60);
    $producedAt = line_flex_text((string)($p['produced_at'] ?? ''), 40);
    $linkUrl = line_flex_production_link_from_payload($p);

    $body = [
        line_flex_kv('รุ่น', $model),
        line_flex_kv('หมายเลข', $code),
        line_flex_kv('วันที่ผลิต', $producedAt),
        line_flex_kv('ผู้บันทึก', $madeBy),
        line_flex_kv('ปัญหา', $problems),
    ];
    if ($fix !== '') {
        $body[] = line_flex_kv('วิธีแก้', $fix);
    }

    return [
        'type'    => 'flex',
        'altText' => '⚠️ พบปัญหาตอนผลิต ' . $code,
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'kilo',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#c0392b', 'paddingAll' => '14px',
                'contents' => [
                    ['type' => 'text', 'text' => '⚠️ พบปัญหาตอนผลิต', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'sm'],
                ],
            ],
            'body'   => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '14px', 'contents' => $body],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px',
                'contents' => [
                    line_flex_system_link_row($linkUrl),
                    line_flex_system_branding(),
                ],
            ],
        ],
    ];
}

// ─ Event: stock.low_threshold (Appscript2) ───────────────────────────────────

/**
 * แถว part ใน Low Stock alert
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function line_flex_low_stock_part_row(array $item): array
{
    $namePart = line_flex_required_text((string)($item['name'] ?? $item['name_part'] ?? ''), 80);
    $stock = (int)($item['quantity'] ?? $item['stock'] ?? 0);
    $stockMin = (int)($item['min_stock'] ?? $item['stock_min'] ?? 0);
    $isLow = $stock < $stockMin;
    $stockColor = $isLow ? '#c0392b' : '#27ae60';
    $iconRaw = trim((string)($item['icon_url'] ?? ''));
    if ($iconRaw === '') {
        $iconPath = trim((string)($item['icon_path'] ?? ''));
        if ($iconPath !== '') {
            $iconRaw = line_notify_part_icon_public_url($iconPath);
        }
    }
    $iconUrl = line_flex_text($iconRaw, 500);
    if ($iconUrl !== '' && preg_match('#^https://#i', line_notify_normalize_public_url($iconUrl, ''))) {
        $iconUrl = line_notify_normalize_public_url($iconUrl, '');
    } else {
        $iconUrl = '';
    }
    $linkUrl = line_flex_action_uri((string)($item['link_url'] ?? line_flex_parts_link_from_payload([
        'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
    ])));

    $thumbContents = [];
    if ($iconUrl !== '') {
        $imageComponent = [
            'type' => 'image', 'url' => $iconUrl, 'size' => 'xs',
            'aspectMode' => 'cover', 'aspectRatio' => '1:1', 'flex' => 0, 'gravity' => 'center',
        ];
        if ($linkUrl !== '') {
            $imageComponent['action'] = ['type' => 'uri', 'uri' => $linkUrl];
        }
        $thumbContents[] = $imageComponent;
    }

    return [
        'type' => 'box', 'layout' => 'horizontal',
        'paddingTop' => '8px', 'paddingStart' => '4px', 'paddingEnd' => '4px', 'alignItems' => 'center',
        'contents' => [
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 0,
                'width' => '40px', 'height' => '40px', 'cornerRadius' => '6px', 'backgroundColor' => '#f0f0f0',
                'contents' => $thumbContents,
            ],
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'paddingStart' => '10px',
                'contents' => [
                    ['type' => 'text', 'text' => $namePart, 'size' => 'sm', 'color' => '#333333', 'weight' => 'bold', 'wrap' => true],
                ],
            ],
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 0, 'alignItems' => 'flex-end',
                'contents' => [
                    ['type' => 'text', 'text' => (string)$stock, 'size' => 'lg', 'color' => $stockColor, 'weight' => 'bold', 'align' => 'end'],
                    ['type' => 'text', 'text' => 'ขั้นต่ำ ' . $stockMin, 'size' => 'xxs', 'color' => '#e67e22', 'align' => 'end', 'margin' => 'xs'],
                ],
            ],
        ],
    ];
}

/**
 * Bubble Low Stock alert (Appscript2 handleLowStock)
 *
 * @param array<int,array<string,mixed>> $items
 * @param int                            $totalCount
 * @param string                         $timestamp
 * @param int                            $page
 * @param int                            $totalPages
 * @return array<string,mixed>
 */
function line_flex_low_stock_alert_bubble(array $items, int $totalCount, string $timestamp, int $page = 1, int $totalPages = 1, string $supplier = ''): array
{
    $partRows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $partRows[] = line_flex_low_stock_part_row($item);
        $partRows[] = ['type' => 'separator', 'color' => '#f0f0f0', 'margin' => 'xs'];
    }
    $supplier = trim($supplier);
    if ($supplier !== '') {
        // การ์ดแยกตามผู้จำหน่าย — บอกจำนวนของเจ้านั้น และหน้าที่เท่าไรถ้าเจ้าเดียวมีหลายการ์ด
        $cardTitle = '📦 ' . count($items) . ' รายการ'
            . ($totalPages > 1 ? ' (' . $page . '/' . $totalPages . ')' : '');
    } else {
        $cardTitle = $totalPages > 1
            ? '📋 การ์ด ' . $page . '/' . $totalPages . ' (' . count($items) . ' รายการ)'
            : '📦 ' . $totalCount . ' รายการ';
    }

    return [
        'type'   => 'bubble',
        'size'   => 'mega',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#c0392b', 'paddingAll' => 'lg',
            'contents' => [
                ['type' => 'text', 'text' => '⚠️ STOCK ALERT', 'size' => 'xxs', 'color' => '#ffcccc', 'weight' => 'bold'],
                ['type' => 'text', 'text' => $supplier !== '' ? line_flex_required_text($supplier, 60) : 'อะไหล่ต่ำกว่ากำหนด',
                 'size' => 'md', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'xs', 'wrap' => true],
                $supplier !== ''
                    ? ['type' => 'text', 'text' => 'อะไหล่ต่ำกว่ากำหนด · ผู้จำหน่าย', 'size' => 'xxs', 'color' => '#ffcccc', 'margin' => 'xs']
                    : ['type' => 'filler'],
                ['type' => 'text', 'text' => $cardTitle . ' | 🕐 ' . $timestamp, 'size' => 'xs', 'color' => '#ffdddd', 'margin' => 'sm', 'wrap' => true],
                ($totalPages > 1 && $page < $totalPages)
                    ? ['type' => 'text', 'text' => 'เลื่อนดูการ์ดถัดไป →', 'size' => 'xxs', 'color' => '#ffcccc', 'margin' => 'xs', 'align' => 'end']
                    : ['type' => 'filler'],
            ],
        ],
        'body' => [
            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px',
            'contents' => array_merge(
                [[
                    'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => '#f7f7f7', 'cornerRadius' => '6px', 'paddingAll' => '6px',
                    'contents' => [
                        ['type' => 'text', 'text' => 'Part', 'size' => 'xxs', 'color' => '#999999', 'flex' => 1, 'weight' => 'bold'],
                        ['type' => 'text', 'text' => 'คงเหลือ', 'size' => 'xxs', 'color' => '#999999', 'flex' => 0, 'weight' => 'bold', 'align' => 'end'],
                    ],
                ]],
                $partRows
            ),
        ],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical', 'cornerRadius' => '12px', 'paddingAll' => '12px', 'backgroundColor' => '#fff0f0',
            'contents' => [
                ['type' => 'text', 'text' => 'กรุณาสั่งซื้ออะไหล่เพิ่มโดยด่วน', 'size' => 'xs', 'color' => '#c0392b', 'align' => 'center', 'weight' => 'bold'],
                ['type' => 'separator', 'margin' => 'sm', 'color' => '#ffcccc'],
                ['type' => 'text', 'text' => '✅ Stock Part System', 'size' => 'xxs', 'color' => '#bbbbbb', 'align' => 'center', 'margin' => 'sm'],
            ],
        ],
    ];
}

/**
 * Flex อะไหล่ต่ำ (รายการเดียว)
 *
 * @param array<string,mixed> $p
 * @return array<string,mixed>
 */
function line_flex_low_stock_alert_single(array $p): array
{
    $timestamp = line_flex_required_text((string)($p['timestamp'] ?? date('d/m/Y H:i')), 30);
    $enriched = line_notify_enrich_low_stock_items([$p]);
    $p = $enriched[0] ?? $p;
    $supplier = trim((string)($p['supplier'] ?? ''));
    if ($supplier === '' || $supplier === '-') {
        $supplier = LINE_FLEX_LOW_STOCK_NO_SUPPLIER;
    }
    return [
        'type'    => 'flex',
        'altText' => '⚠️ อะไหล่ต่ำกว่ากำหนด: ' . line_flex_text((string)($p['name'] ?? ''), 40),
        'contents' => line_flex_low_stock_alert_bubble([$p], 1, $timestamp, 1, 1, $supplier),
    ];
}

/**
 * Flex Low Stock หลายรายการ — ≤20 รายการ = การ์ดเดียว, >20 = carousel เรียงข้างกัน
 *
 * @param array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_low_stock_alert_messages(array $payload): array
{
    $items = array_values(array_filter((array)($payload['items'] ?? []), 'is_array'));
    if ($items === []) {
        return [];
    }
    $items = line_notify_enrich_low_stock_items($items);
    $timestamp = line_flex_required_text((string)($payload['timestamp'] ?? date('d/m/Y H:i')), 30);
    $total = count($items);

    // แยกการ์ดตามผู้จำหน่าย — เจ้าละการ์ด สั่งซื้อทีเดียวจบต่อเจ้า
    $groups = line_flex_low_stock_group_by_supplier($items);
    $altText = '⚠️ อะไหล่ต่ำกว่ากำหนด ' . $total . ' รายการ · ' . count($groups) . ' ผู้จำหน่าย';

    $bubbles = [];
    $truncated = 0;
    foreach ($groups as $supplier => $rows) {
        // ผู้จำหน่ายเจ้าเดียวที่มีของเยอะ แบ่งเป็นหลายการ์ดของเจ้านั้น
        $pages = array_chunk($rows, LINE_FLEX_LOW_STOCK_SINGLE_CARD_MAX);
        $totalPages = count($pages);
        foreach ($pages as $i => $page) {
            if (count($bubbles) >= LINE_FLEX_LOW_STOCK_MAX_CARDS) {
                $truncated += count($page);
                continue;
            }
            $bubbles[] = line_flex_low_stock_alert_bubble(
                $page, $total, $timestamp, $i + 1, $totalPages, (string)$supplier
            );
        }
    }

    if ($bubbles === []) {
        return [];
    }
    if ($truncated > 0) {
        $altText .= ' (แสดง ' . LINE_FLEX_LOW_STOCK_MAX_CARDS . ' การ์ดแรก · อีก ' . $truncated . ' รายการดูในระบบ)';
    }
    if (count($bubbles) === 1) {
        return [[
            'type'     => 'flex',
            'altText'  => $altText,
            'contents' => $bubbles[0],
        ]];
    }

    return line_flex_carousel_messages($bubbles, $altText);
}

// ─ Event: ma.repair_required ─────────────────────────────────────────────────

/**
 * Flex MA ต้องซ่อม
 *
 * @param array<string,mixed> $p
 * @return array<string,mixed>
 */
function line_flex_ma_repair(array $p): array
{
    $code = line_flex_text((string)($p['asset_code'] ?? '-'), 80);
    $round = (int)($p['ma_round'] ?? 0);
    $visitedRaw = (string) ($p['visited_at'] ?? '');
    $visited = line_flex_text(function_exists('dt_display_full') ? dt_display_full($visitedRaw) : $visitedRaw, 40);
    $repair = line_flex_text((string)($p['repair_items'] ?? ''), 200);
    $remark = line_flex_text((string)($p['remark'] ?? ''), 120);
    $doneBy = line_flex_text((string)($p['done_by'] ?? ''), 60);
    $linkUrl = line_flex_production_link_from_payload($p);

    $body = [
        line_flex_kv('เครื่อง', $code),
        line_flex_kv('รอบ MA', (string)$round),
        line_flex_kv('วันที่เข้า MA', $visited),
        line_flex_kv('รายการซ่อม', $repair),
        line_flex_kv('ผู้บันทึก', $doneBy),
    ];
    if ($remark !== '') {
        $body[] = line_flex_kv('หมายเหตุ', $remark);
    }

    return [
        'type'    => 'flex',
        'altText' => '🔧 MA ต้องซ่อม ' . $code,
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'kilo',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#8e44ad', 'paddingAll' => '14px',
                'contents' => [
                    ['type' => 'text', 'text' => '🔧 MA — ต้องซ่อม', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'sm'],
                ],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '14px', 'contents' => $body],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px',
                'contents' => [
                    line_flex_system_link_row($linkUrl),
                    line_flex_system_branding(),
                ],
            ],
        ],
    ];
}

// ─ Event: stock.manual_withdraw ──────────────────────────────────────────────

/**
 * Flex เบิกอะไหล่ manual
 *
 * @param array<string,mixed> $p
 * @return array<string,mixed>
 */
function line_flex_manual_withdraw(array $p): array
{
    $partName = line_flex_text((string)($p['part_name'] ?? '-'), 80);
    $qty = line_flex_text((string)($p['qty'] ?? ''), 20);
    $unit = line_flex_text((string)($p['unit'] ?? ''), 20);
    $assetCode = line_flex_text((string)($p['asset_code'] ?? ''), 60);
    $madeBy = line_flex_text((string)($p['made_by'] ?? ''), 60);
    $remark = line_flex_text((string)($p['remark'] ?? ''), 100);
    $remaining = line_flex_text((string)($p['remaining'] ?? ''), 30);

    $body = [
        line_flex_kv('อะไหล่', $partName),
        line_flex_kv('จำนวน', $qty . ($unit !== '' ? ' ' . $unit : '')),
        line_flex_kv('ผู้เบิก', $madeBy),
    ];
    if ($assetCode !== '') {
        $body[] = line_flex_kv('อ้างอิงเครื่อง', $assetCode);
    }
    if ($remaining !== '') {
        $body[] = line_flex_kv('คงเหลือ', $remaining);
    }
    if ($remark !== '') {
        $body[] = line_flex_kv('หมายเหตุ', $remark);
    }

    return [
        'type'    => 'flex',
        'altText' => '📤 เบิกอะไหล่: ' . $partName,
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'kilo',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#2980b9', 'paddingAll' => '14px',
                'contents' => [
                    ['type' => 'text', 'text' => '📤 เบิกอะไหล่', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'sm'],
                ],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '14px', 'contents' => $body],
        ],
    ];
}

// ─ Production summary (port AppScript) ─────────────────────────────────────────

/**
 * จัดกลุ่ม records ตาม model
 *
 * @param array<int,array<string,mixed>> $records
 * @return array<string,array<int,array<string,mixed>>>
 */
function line_flex_group_by_model(array $records): array
{
    $grouped = [];
    foreach ($records as $r) {
        $model = line_flex_text((string)($r['model'] ?? ''), 80);
        if ($model === '') {
            continue;
        }
        if (!isset($grouped[$model])) {
            $grouped[$model] = [];
        }
        $grouped[$model][] = $r;
    }
    return $grouped;
}

/**
 * Flex สรุปการเบิกอะไหล่วันนี้ (รวมในชุดสรุปผลิตรายวัน)
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<string,mixed>|null
 */
function line_flex_withdraw_daily_summary(array $items): ?array
{
    if ($items === []) {
        return null;
    }
    $rows = [[
        'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => '#f7f7f7', 'cornerRadius' => '6px', 'paddingAll' => '6px',
        'contents' => [
            ['type' => 'text', 'text' => 'อะไหล่', 'size' => 'xxs', 'color' => '#999999', 'flex' => 5, 'weight' => 'bold'],
            ['type' => 'text', 'text' => 'จำนวน', 'size' => 'xxs', 'color' => '#999999', 'flex' => 2, 'align' => 'end', 'weight' => 'bold'],
            ['type' => 'text', 'text' => 'เครื่อง/โหมด', 'size' => 'xxs', 'color' => '#999999', 'flex' => 4, 'align' => 'end', 'weight' => 'bold'],
        ],
    ]];
    $shown = 0;
    foreach ($items as $item) {
        if ($shown >= 30) {
            break;
        }
        if (!is_array($item)) {
            continue;
        }
        $ref = line_flex_required_text((string)($item['asset_code'] ?? ''), 20);
        if ($ref === '-') {
            $ref = line_flex_required_text((string)($item['mode'] ?? ''), 20);
        }
        $rows[] = [
            'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '6px',
            'contents' => [
                ['type' => 'text', 'text' => line_flex_required_text((string)($item['part_name'] ?? ''), 40), 'size' => 'xs', 'color' => '#333333', 'flex' => 5, 'wrap' => true],
                ['type' => 'text', 'text' => line_flex_required_text((string)($item['qty'] ?? ''), 10), 'size' => 'xs', 'color' => '#2980b9', 'flex' => 2, 'align' => 'end', 'weight' => 'bold'],
                ['type' => 'text', 'text' => $ref, 'size' => 'xxs', 'color' => '#888888', 'flex' => 4, 'align' => 'end', 'wrap' => true],
            ],
        ];
        $shown++;
    }
    if (count($items) > $shown) {
        $rows[] = ['type' => 'text', 'text' => 'และอีก ' . (count($items) - $shown) . ' รายการ', 'size' => 'xs', 'color' => '#888888', 'margin' => 'md'];
    }
    $linkUrl = line_notify_parts_base_url() . '/index.php';

    return [
        'type'    => 'flex',
        'altText' => '📤 สรุปการเบิกอะไหล่วันนี้ ' . count($items) . ' รายการ',
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'mega',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#2980b9', 'paddingAll' => 'lg',
                'contents' => [
                    ['type' => 'text', 'text' => '📤 WITHDRAW SUMMARY', 'size' => 'xxs', 'color' => '#cce5ff', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => 'สรุปการเบิกอะไหล่วันนี้', 'size' => 'md', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'xs'],
                    ['type' => 'text', 'text' => count($items) . ' รายการ', 'size' => 'xs', 'color' => '#cce5ff', 'margin' => 'sm'],
                ],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px', 'contents' => $rows],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px',
                'contents' => [
                    line_flex_system_link_row($linkUrl, 'Production System', '#eef4ff'),
                    line_flex_system_branding(),
                ],
            ],
        ],
    ];
}

/**
 * รวม carousel สรุปผลิตรายวัน (ไม่รวมเบิกอะไหล่ — ใช้ stock.manual_withdraw แยก)
 *
 * @param array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_production_daily_bundle(array $payload): array
{
    return line_flex_production_daily_carousel($payload);
}

/**
 * Carousel รายงานผลิตรายวัน (Round 1)
 *
 * @param array<string,mixed> $payload grouped + records
 * @return array<int,array<string,mixed>>
 */
function line_flex_production_daily_carousel(array $payload): array
{
    $grouped = (array)($payload['grouped'] ?? line_flex_group_by_model((array)($payload['records'] ?? [])));
    if ($grouped === []) {
        return [];
    }
    $bubbles = [];
    foreach ($grouped as $model => $items) {
        $bubbles[] = line_flex_production_model_card($model, $items, false);
    }
    return line_flex_carousel_messages($bubbles, '📦 รายงานการผลิตสินค้า');
}

/**
 * Carousel อัปเดตผลิต (Round 2)
 *
 * @param array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_production_update_carousel(array $payload): array
{
    $updates = (array)($payload['updates'] ?? []);
    if ($updates === []) {
        return [];
    }
    $bubbles = [];
    foreach ($updates as $u) {
        if (!is_array($u)) {
            continue;
        }
        $model = (string)($u['model'] ?? '');
        $old = (array)($u['old'] ?? []);
        $added = (array)($u['added'] ?? []);
        if ($added === []) {
            continue;
        }
        if ($old === []) {
            $bubbles[] = line_flex_production_model_card($model, $added, false);
            continue;
        }
        $bubbles[] = line_flex_production_model_card($model, array_merge($old, $added), true, $old, $added);
    }
    return line_flex_carousel_messages($bubbles, '🔔 อัปเดตรายงานการผลิตสินค้า');
}

/**
 * แบ่ง bubbles เป็น carousel messages (สูงสุด 5 ต่อ message)
 *
 * @param array<int,array<string,mixed>> $bubbles
 * @param string                         $altText
 * @return array<int,array<string,mixed>>
 */
function line_flex_carousel_messages(array $bubbles, string $altText): array
{
    $messages = [];
    for ($i = 0; $i < count($bubbles); $i += 5) {
        $slice = array_slice($bubbles, $i, 5);
        $messages[] = [
            'type'    => 'flex',
            'altText' => $altText,
            'contents' => ['type' => 'carousel', 'contents' => $slice],
        ];
    }
    return $messages;
}

/**
 * Flex card ต่อ model (จาก AppScript buildFlexCard)
 *
 * @param string                             $model
 * @param array<int,array<string,mixed>>     $items
 * @param bool                               $isUpdate
 * @param array<int,array<string,mixed>>     $oldItems
 * @param array<int,array<string,mixed>>     $addedItems
 * @return array<string,mixed>
 */
function line_flex_production_model_card(
    string $model,
    array $items,
    bool $isUpdate = false,
    array $oldItems = [],
    array $addedItems = []
): array {
    $totalSerial = count($items);
    $imageUrl = line_flex_product_image($model, (string)($items[0]['image_url'] ?? ''));
    $firstTs = line_flex_required_text((string)($items[0]['timestamp'] ?? date('d/m/Y H:i')), 30);
    $headerBg = $isUpdate ? '#0d6e3a' : '#1a8a4a';
    $badge = $isUpdate ? '🔔 UPDATE REPORT' : 'PRODUCTION REPORT';
    $dashUrl = line_notify_production_base_url() . '/index.php';

    $bodyContents = [
        [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#f0f7ff', 'cornerRadius' => '10px', 'paddingAll' => '10px',
            'contents' => [['type' => 'text', 'text' => line_flex_required_text($model, 80), 'size' => 'sm', 'color' => '#1a5fa8', 'weight' => 'bold', 'align' => 'center', 'wrap' => true]],
        ],
        line_flex_serial_table_header(),
    ];

    if ($isUpdate && $oldItems !== []) {
        foreach ($oldItems as $idx => $item) {
            $bodyContents[] = line_flex_serial_row($idx + 1, $item, false);
        }
        $bodyContents[] = [
            'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => '#e8f5e9', 'cornerRadius' => '4px', 'paddingAll' => '4px', 'margin' => 'sm',
            'contents' => [['type' => 'text', 'text' => '🆕 เพิ่มใหม่ ' . count($addedItems) . ' รายการ', 'size' => 'xxs', 'color' => '#1a8a4a', 'align' => 'center', 'flex' => 1, 'weight' => 'bold']],
        ];
        foreach ($addedItems as $idx => $item) {
            $bodyContents[] = line_flex_serial_row(count($oldItems) + $idx + 1, $item, true);
        }
    } else {
        foreach ($items as $idx => $item) {
            $bodyContents[] = line_flex_serial_row($idx + 1, $item, false);
        }
    }

    $creatorCount = line_flex_count_creators($items);
    $footerContents = [
        ['type' => 'text', 'text' => '📋 สรุปประจำ Model', 'size' => 'sm', 'align' => 'center', 'weight' => 'bold', 'color' => '#1a8a4a'],
        ['type' => 'separator', 'margin' => 'sm', 'color' => '#c8ecd5'],
        [
            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => '📊 จำนวนรวม', 'size' => 'sm', 'color' => '#444444', 'flex' => 5],
                ['type' => 'text', 'text' => $totalSerial . ' รายการ', 'size' => 'sm', 'color' => '#1a8a4a', 'flex' => 3, 'align' => 'end', 'weight' => 'bold'],
            ],
        ],
        [
            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => '🕐 เริ่มบันทึก', 'size' => 'xs', 'color' => '#888888', 'flex' => 4],
                ['type' => 'text', 'text' => $firstTs, 'size' => 'xs', 'color' => '#888888', 'flex' => 5, 'align' => 'end', 'wrap' => true],
            ],
        ],
    ];
    if ($isUpdate && $addedItems !== []) {
        $footerContents[] = [
            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => '🆕 เพิ่มใหม่', 'size' => 'xs', 'color' => '#1a8a4a', 'flex' => 4],
                ['type' => 'text', 'text' => count($addedItems) . ' รายการ', 'size' => 'xs', 'color' => '#1a8a4a', 'flex' => 5, 'align' => 'end', 'weight' => 'bold'],
            ],
        ];
    }
    $footerContents[] = ['type' => 'separator', 'margin' => 'sm', 'color' => '#c8ecd5'];
    $footerContents[] = ['type' => 'text', 'text' => '✍️ บันทึกโดย', 'size' => 'xs', 'color' => '#888888', 'margin' => 'sm'];
    $footerContents = array_merge($footerContents, line_flex_creator_summary_rows($creatorCount));
    $footerContents[] = ['type' => 'separator', 'margin' => 'md', 'color' => '#c8ecd5'];
    $footerContents[] = line_flex_system_link_row($dashUrl);
    $footerContents[] = line_flex_system_branding();

    return [
        'type'   => 'bubble',
        'size'   => 'mega',
        'header' => [
            'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => $headerBg, 'paddingAll' => 'lg',
            'contents' => [
                [
                    'type' => 'box', 'layout' => 'vertical', 'flex' => 3,
                    'contents' => [
                        ['type' => 'text', 'text' => $badge, 'size' => 'xxs', 'color' => '#aaffcc', 'weight' => 'bold'],
                        ['type' => 'text', 'text' => '📦 รายงานการผลิตสินค้า', 'size' => 'sm', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'xs', 'wrap' => true],
                        ['type' => 'text', 'text' => '🕐 ' . $firstTs, 'size' => 'xs', 'color' => '#ddffdd', 'margin' => 'sm', 'wrap' => true],
                    ],
                ],
                [
                    'type' => 'box', 'layout' => 'vertical', 'flex' => 2,
                    'contents' => [['type' => 'image', 'url' => $imageUrl, 'size' => 'full', 'aspectMode' => 'cover', 'aspectRatio' => '1:1', 'gravity' => 'center', 'margin' => 'xs']],
                ],
            ],
        ],
        'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '14px', 'contents' => $bodyContents],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical', 'cornerRadius' => '12px', 'paddingAll' => '12px', 'backgroundColor' => '#f8fdf9',
            'contents' => $footerContents,
        ],
    ];
}

/**
 * Flex สรุปรายสัปดาห์/เดือน (Overview card — port buildSummaryOverviewCard)
 *
 * @param string              $eventKey
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function line_flex_production_summary_overview(string $eventKey, array $payload): array
{
    $isWeek = ($eventKey === 'production.summary.weekly');
    $records = (array)($payload['records'] ?? []);
    $grouped = line_flex_group_by_model($records);
    $totalAll = count($records);
    $dateRange = line_flex_required_text((string)($payload['date_range'] ?? ''), 60);
    $timestamp = line_flex_required_text((string)($payload['timestamp'] ?? date('d/m/Y H:i')), 30);
    $linkUrl = line_flex_production_link_from_payload($payload);

    $headerBg = $isWeek ? '#1a5fa8' : '#6d3bbf';
    $accentCol = $isWeek ? '#aad4ff' : '#d8b4fe';
    $accentBg = $isWeek ? '#f0f7ff' : '#f5f0ff';
    $accentTxt = $isWeek ? '#1a5fa8' : '#6d3bbf';
    $sepCol = $isWeek ? '#bee3f8' : '#d8b4fe';
    $badgeText = $isWeek ? '📅 WEEKLY SUMMARY' : '🗓️ MONTHLY SUMMARY';
    $titleText = $isWeek ? 'สรุปยอดผลิตรายสัปดาห์' : 'สรุปยอดผลิตรายเดือน';

    $creatorCount = line_flex_count_creators($records);
    arsort($creatorCount);
    $topCreator = array_key_first($creatorCount) ?: '-';

    $modelRows = [];
    foreach ($grouped as $model => $items) {
        $modelRows[] = line_flex_model_progress_row($model, count($items), $totalAll, $accentTxt);
    }

    $creatorRows = [];
    foreach ($creatorCount as $name => $cnt) {
        $creatorRows[] = [
            'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '4px',
            'contents' => [
                ['type' => 'text', 'text' => line_flex_required_text($name, 40), 'size' => 'xs', 'color' => '#444444', 'flex' => 5],
                ['type' => 'text', 'text' => $cnt . ' รายการ', 'size' => 'xs', 'color' => $accentTxt, 'flex' => 3, 'align' => 'end', 'weight' => 'bold'],
            ],
        ];
    }

    return [
        'type'    => 'flex',
        'altText' => ($isWeek ? '📅' : '🗓️') . ' ' . $titleText,
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'mega',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => $headerBg, 'paddingAll' => 'lg',
                'contents' => [
                    ['type' => 'text', 'text' => $badgeText, 'size' => 'xxs', 'color' => $accentCol, 'weight' => 'bold'],
                    ['type' => 'text', 'text' => '📦 ' . $titleText, 'size' => 'sm', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'xs', 'wrap' => true],
                    ['type' => 'text', 'text' => '📆 ' . $dateRange, 'size' => 'xs', 'color' => $accentCol, 'margin' => 'sm', 'wrap' => true],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '14px',
                'contents' => array_merge(
                    [[
                        'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => $accentBg, 'cornerRadius' => '10px', 'paddingAll' => '12px',
                        'contents' => [
                            [
                                'type' => 'box', 'layout' => 'vertical', 'flex' => 1,
                                'contents' => [
                                    ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'size' => 'xs', 'color' => '#888888'],
                                    ['type' => 'text', 'text' => (string)$totalAll, 'size' => 'xxl', 'color' => $accentTxt, 'weight' => 'bold'],
                                    ['type' => 'text', 'text' => 'รายการ', 'size' => 'xs', 'color' => '#888888'],
                                ],
                            ],
                            [
                                'type' => 'box', 'layout' => 'vertical', 'flex' => 1,
                                'contents' => [
                                    ['type' => 'text', 'text' => 'จำนวน Model', 'size' => 'xs', 'color' => '#888888'],
                                    ['type' => 'text', 'text' => (string)count($grouped), 'size' => 'xxl', 'color' => $accentTxt, 'weight' => 'bold'],
                                    ['type' => 'text', 'text' => 'รุ่น', 'size' => 'xs', 'color' => '#888888'],
                                ],
                            ],
                        ],
                    ]],
                    [[
                        'type' => 'box', 'layout' => 'horizontal', 'backgroundColor' => '#f7f7f7',
                        'cornerRadius' => '6px', 'paddingAll' => '6px', 'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => 'Model', 'size' => 'xxs', 'color' => '#999999', 'flex' => 6, 'weight' => 'bold'],
                            ['type' => 'text', 'text' => 'จำนวน', 'size' => 'xxs', 'color' => '#999999', 'flex' => 3, 'align' => 'end', 'weight' => 'bold'],
                            ['type' => 'text', 'text' => '%', 'size' => 'xxs', 'color' => '#999999', 'flex' => 2, 'align' => 'end', 'weight' => 'bold'],
                        ],
                    ]],
                    $modelRows
                ),
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'cornerRadius' => '12px', 'paddingAll' => '12px', 'backgroundColor' => $accentBg,
                'contents' => array_merge(
                    [
                        ['type' => 'text', 'text' => '✍️ สรุปผู้บันทึกทั้งหมด', 'size' => 'sm', 'align' => 'center', 'weight' => 'bold', 'color' => $accentTxt],
                        ['type' => 'separator', 'margin' => 'sm', 'color' => $sepCol],
                    ],
                    $creatorRows,
                    [
                        ['type' => 'separator', 'margin' => 'md', 'color' => $sepCol],
                        [
                            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '🏆 บันทึกมากสุด', 'size' => 'xs', 'color' => '#888888', 'flex' => 4],
                                ['type' => 'text', 'text' => line_flex_required_text($topCreator, 40), 'size' => 'xs', 'color' => $accentTxt, 'flex' => 5, 'align' => 'end', 'weight' => 'bold', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'xs',
                            'contents' => [
                                ['type' => 'text', 'text' => '🕐 สร้างเมื่อ', 'size' => 'xs', 'color' => '#888888', 'flex' => 4],
                                ['type' => 'text', 'text' => $timestamp, 'size' => 'xs', 'color' => '#888888', 'flex' => 5, 'align' => 'end', 'wrap' => true],
                            ],
                        ],
                        ['type' => 'separator', 'margin' => 'md', 'color' => $sepCol],
                        line_flex_system_link_row($linkUrl, 'Production System', '#ffffff'),
                        line_flex_system_branding(),
                    ]
                ),
            ],
        ],
    ];
}

/**
 * Flex ไม่มีข้อมูลรายวัน (Round 1 — sendNoDataMessage)
 *
 * @param string $timestamp
 * @return array<string,mixed>
 */
function line_flex_production_no_data_daily(string $timestamp): array
{
    $parts = preg_split('/\s+/', trim($timestamp), 2);
    $dateOnly = $parts[0] ?? $timestamp;
    $timeOnly = $parts[1] ?? '';

    return [
        'type'    => 'flex',
        'altText' => '⚠️ ไม่พบข้อมูลการผลิตสินค้าประจำวัน',
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'mega',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#c0392b', 'paddingAll' => '16px',
                'contents' => [
                    ['type' => 'text', 'text' => 'PRODUCTION REPORT', 'size' => 'xxs', 'color' => '#ffcccc'],
                    ['type' => 'text', 'text' => '📋 รายงานการผลิตสินค้า', 'size' => 'md', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'sm'],
                    ['type' => 'text', 'text' => trim($dateOnly . ' ' . $timeOnly), 'size' => 'xs', 'color' => '#ffdddd', 'margin' => 'sm'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '24px',
                'contents' => [
                    ['type' => 'text', 'text' => '⚠️', 'size' => 'xxl', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'ไม่พบข้อมูลการผลิตสินค้า', 'size' => 'md', 'align' => 'center', 'weight' => 'bold', 'color' => '#c0392b', 'margin' => 'md'],
                    ['type' => 'text', 'text' => 'ไม่มีรายการบันทึกในวันนี้', 'size' => 'sm', 'align' => 'center', 'color' => '#aaaaaa', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#eeeeee'],
                    [
                        'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'backgroundColor' => '#fff8f7', 'cornerRadius' => '8px', 'paddingAll' => '12px',
                        'contents' => [
                            [
                                'type' => 'box', 'layout' => 'horizontal',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'วันที่ตรวจสอบ', 'size' => 'xs', 'color' => '#aaaaaa', 'flex' => 4],
                                    ['type' => 'text', 'text' => $dateOnly, 'size' => 'xs', 'color' => '#c0392b', 'flex' => 5, 'align' => 'end', 'weight' => 'bold'],
                                ],
                            ],
                            [
                                'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '6px',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'เวลา', 'size' => 'xs', 'color' => '#aaaaaa', 'flex' => 4],
                                    ['type' => 'text', 'text' => line_flex_required_text($timeOnly, 20), 'size' => 'xs', 'color' => '#c0392b', 'flex' => 5, 'align' => 'end', 'weight' => 'bold'],
                                ],
                            ],
                            ['type' => 'separator', 'margin' => 'md', 'color' => '#ffcccc'],
                            [
                                'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '6px',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'สถานะ', 'size' => 'xs', 'color' => '#aaaaaa', 'flex' => 4],
                                    ['type' => 'text', 'text' => 'ไม่มีข้อมูล', 'size' => 'xs', 'color' => '#c0392b', 'flex' => 5, 'align' => 'end', 'weight' => 'bold'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#f0f0f0', 'paddingAll' => '10px',
                'contents' => [['type' => 'text', 'text' => '✅ Production System', 'size' => 'xxs', 'color' => '#aaaaaa', 'align' => 'center']],
            ],
        ],
    ];
}

/**
 * Flex ไม่มีข้อมูลรายสัปดาห์/เดือน (sendNoDataSummaryMessage)
 *
 * @param string $period week|month
 * @param string $timestamp
 * @return array<string,mixed>
 */
function line_flex_production_no_data_summary(string $period, string $timestamp): array
{
    $isWeek = ($period === 'week');
    $headerBg = $isWeek ? '#1a5fa8' : '#6d3bbf';
    $titleTxt = $isWeek ? '📅 สรุปยอดผลิตรายสัปดาห์' : '🗓️ สรุปยอดผลิตรายเดือน';
    $bodyTxt = $isWeek ? 'ไม่พบข้อมูลการผลิตสัปดาห์นี้' : 'ไม่พบข้อมูลการผลิตเดือนนี้';
    $parts = preg_split('/\s+/', trim($timestamp), 2);
    $dateOnly = $parts[0] ?? $timestamp;
    $timeOnly = $parts[1] ?? '';

    return [
        'type'    => 'flex',
        'altText' => '⚠️ ' . $titleTxt . ' — ไม่มีข้อมูล',
        'contents' => [
            'type'   => 'bubble',
            'size'   => 'mega',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => $headerBg, 'paddingAll' => '16px',
                'contents' => [
                    ['type' => 'text', 'text' => 'PRODUCTION REPORT', 'size' => 'xxs', 'color' => '#ccddff'],
                    ['type' => 'text', 'text' => $titleTxt, 'size' => 'md', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'sm'],
                    ['type' => 'text', 'text' => trim($dateOnly . ' ' . $timeOnly), 'size' => 'xs', 'color' => '#ccddff', 'margin' => 'sm'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '24px',
                'contents' => [
                    ['type' => 'text', 'text' => '⚠️', 'size' => 'xxl', 'align' => 'center'],
                    ['type' => 'text', 'text' => $bodyTxt, 'size' => 'md', 'align' => 'center', 'weight' => 'bold', 'color' => '#c0392b', 'margin' => 'md'],
                    ['type' => 'text', 'text' => 'กรุณาตรวจสอบข้อมูลในระบบ', 'size' => 'sm', 'align' => 'center', 'color' => '#aaaaaa', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#eeeeee'],
                    [
                        'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'backgroundColor' => '#f8f8f8', 'cornerRadius' => '8px', 'paddingAll' => '12px',
                        'contents' => [
                            [
                                'type' => 'box', 'layout' => 'horizontal',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'วันที่ตรวจสอบ', 'size' => 'xs', 'color' => '#aaaaaa', 'flex' => 4],
                                    ['type' => 'text', 'text' => $dateOnly, 'size' => 'xs', 'color' => '#555555', 'flex' => 5, 'align' => 'end', 'weight' => 'bold'],
                                ],
                            ],
                            [
                                'type' => 'box', 'layout' => 'horizontal', 'paddingTop' => '6px',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'สถานะ', 'size' => 'xs', 'color' => '#aaaaaa', 'flex' => 4],
                                    ['type' => 'text', 'text' => 'ไม่มีข้อมูล', 'size' => 'xs', 'color' => '#c0392b', 'flex' => 5, 'align' => 'end', 'weight' => 'bold'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#f0f0f0', 'paddingAll' => '10px',
                'contents' => [['type' => 'text', 'text' => '✅ Production System', 'size' => 'xxs', 'color' => '#aaaaaa', 'align' => 'center']],
            ],
        ],
    ];
}
