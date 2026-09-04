<?php
/**
 * shared/line_flex_work_summary.php — Flex สรุปงานรายคนต่อรอบเดือน
 *
 * ส่งเข้าไลน์ส่วนตัวของแต่ละคน ว่ารอบ 21 เดือนก่อน–20 เดือนนี้ เขาทำอะไรไปบ้าง
 * แยกเป็นรายวัน · ไม่บอกจำนวนครั้ง (ตัวเลขดูที่หน้าเว็บ) · มีรูปสินค้าประกอบทุกรายการ
 *
 * ชั้นข้อมูล 3 ชั้นต้องแยกออกจากกันให้เห็นตั้งแต่เหลือบแรก ไม่งั้นอ่านเร็ว ๆ แล้วปนกัน:
 *   วัน      = แถบสีทึบเต็มความกว้าง
 *   หัวข้องาน = ตัวหนาเล็ก มีขีดสีนำหน้า
 *   ของที่ทำ  = รูป + ชื่อรุ่น เยื้องเข้ามา
 */

/** จำนวนวันต่อบับเบิล (ค่าตั้งต้น) — ตัวจริงเฉลี่ยใหม่ให้ทุกใบสูงพอ ๆ กัน */
const WORK_SUMMARY_FLEX_DAYS_PER_BUBBLE = 5;

/** LINE รับ carousel ได้สูงสุด 12 บับเบิล */
const WORK_SUMMARY_FLEX_MAX_BUBBLES = 12;

/**
 * เพดานขนาด JSON ที่ยอมให้ข้อความหนึ่งใหญ่ได้ — LINE ตัดที่ 30KB
 * เผื่อไว้เพราะรอบที่ทำงานเกือบทุกวันและงานหลากหลายจะโตกว่าที่วัดไว้
 */
const WORK_SUMMARY_FLEX_BUDGET = 27000;

/** ความสูงรูปสินค้าในรายการ */
const WORK_SUMMARY_FLEX_THUMB = '38px';

/**
 * ระดับความละเอียด ไล่จากเต็มที่สุดไปประหยัดที่สุด
 *
 * URL รูปยาวเส้นละ ~125 ตัวอักษร แถวที่มีรูปจึงกินราว 3 เท่าของแถวข้อความเปล่า
 * คนที่ทำงานหลายด้านทุกวัน (เช่น 20 วัน × 4 หัวข้อ) ใส่รูปทุกแถวแล้วทะลุเพดาน 30KB
 * ของ LINE แน่นอน — ลดความละเอียดทีละขั้นดีกว่าตัดวันทิ้ง เพราะ "ทำงานวันไหนบ้าง"
 * คือแก่นของสรุปนี้ ส่วนรูปเป็นของแถม
 *
 * @return array<int,array{names:int,images:string}>
 */
function line_flex_work_summary_levels(): array
{
    return [
        ['names' => 2, 'images' => 'all'],
        ['names' => 1, 'images' => 'all'],
        ['names' => 2, 'images' => 'first'],   // รูปเฉพาะหัวข้อแรกของวัน
        ['names' => 1, 'images' => 'first'],
        ['names' => 2, 'images' => 'none'],
        ['names' => 1, 'images' => 'none'],
    ];
}

/**
 * หนึ่งรายการงาน — รูปสินค้า + ชื่อรุ่น (หรือชื่อเปล่าเมื่อประหยัดที่)
 *
 * @param  string $name    ชื่อรุ่น
 * @param  bool   $withImg ใส่รูปไหม
 * @return array<string,mixed>
 */
function line_flex_work_summary_item(string $name, bool $withImg): array
{
    $url = $withImg && function_exists('line_flex_product_image') ? line_flex_product_image($name) : '';
    if ($url === '') {
        // ไม่มีรูปก็ต้องยังเยื้องเข้ามาและมีจุดนำ ไม่งั้นอ่านปนกับหัวข้อ
        return ['type' => 'text', 'text' => '·  ' . line_flex_text($name, 60), 'size' => 'sm',
                'color' => '#1a1a1a', 'margin' => 'sm', 'wrap' => true, 'offsetStart' => '10px'];
    }
    return [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
        'contents' => [
            ['type' => 'image', 'url' => $url, 'size' => WORK_SUMMARY_FLEX_THUMB,
             'aspectRatio' => '1:1', 'flex' => 0],
            ['type' => 'text', 'text' => line_flex_text($name, 60), 'size' => 'sm',
             'color' => '#1a1a1a', 'gravity' => 'center', 'wrap' => true, 'margin' => 'md'],
        ],
    ];
}

/**
 * บล็อกของหนึ่งวัน — แถบวัน แล้วไล่หัวข้องานกับของที่ทำ
 *
 * @param  array<string,mixed>          $day ['label'=>..,'cats'=>[['label'=>..,'names'=>[..],'more'=>int]]]
 * @param  array{names:int,images:string} $opt ระดับความละเอียด
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_day(array $day, array $opt): array
{
    $out = [[
        // แถบวันเป็นพื้นทึบเต็มความกว้าง ทำหน้าที่เป็นตัวคั่นในตัว ไม่ต้องมี separator อีก
        'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg',
        'backgroundColor' => '#0057b8', 'cornerRadius' => '4px',
        'paddingAll' => '5px', 'paddingStart' => '10px',
        'contents' => [[
            'type' => 'text', 'text' => line_flex_text((string) ($day['label'] ?? '-'), 24),
            'size' => 'sm', 'weight' => 'bold', 'color' => '#ffffff',
        ]],
    ]];

    $first = true;
    foreach ((isset($day['cats']) && is_array($day['cats']) ? $day['cats'] : []) as $cat) {
        // หัวข้องาน: ขีดนำหน้า + ตัวหนาสีน้ำเงินตัวเล็ก — ต่างจากชื่อรุ่นทั้งสี ขนาด และน้ำหนัก
        $out[] = ['type' => 'text', 'margin' => 'md', 'size' => 'xs', 'weight' => 'bold',
                  'color' => '#0057b8', 'wrap' => true,
                  'text' => '▌ ' . line_flex_text((string) ($cat['label'] ?? '-'), 40)];

        $withImg = $opt['images'] === 'all' || ($opt['images'] === 'first' && $first);
        $names = isset($cat['names']) && is_array($cat['names']) ? $cat['names'] : [];
        $shown = array_slice($names, 0, max(1, (int) $opt['names']));
        foreach ($shown as $nm) {
            $out[] = line_flex_work_summary_item((string) $nm, $withImg);
        }
        if ((int) ($cat['more'] ?? 0) > 0 || count($names) > count($shown)) {
            $out[] = ['type' => 'text', 'text' => 'และอื่น ๆ', 'size' => 'xs',
                      'color' => '#9aa4b2', 'margin' => 'sm', 'offsetStart' => '10px'];
        }
        $first = false;
    }
    return $out;
}

/**
 * บับเบิลหนึ่งใบ (วันชุดหนึ่ง)
 *
 * @param  array<string,mixed>            $p       payload
 * @param  array<int,array<string,mixed>> $days    วันของบับเบิลนี้
 * @param  int                            $page    หน้าที่เท่าไหร่ (เริ่มที่ 1)
 * @param  int                            $pages   ทั้งหมดกี่หน้า
 * @param  int                            $omitted วันที่ใส่ไม่ลง
 * @return array<string,mixed>
 */
function line_flex_work_summary_bubble(array $p, array $days, int $page, int $pages, int $omitted = 0, array $opt = ['names' => 2, 'images' => 'all']): array
{
    $body = [];
    if ($page === 1) {
        $body[] = ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60),
                   'size' => 'lg', 'weight' => 'bold', 'color' => '#111111'];
        $body[] = ['type' => 'text', 'text' => line_flex_text((string) ($p['cycle_label'] ?? ''), 60),
                   'size' => 'xs', 'color' => '#888888', 'margin' => 'xs'];
    } else {
        $body[] = ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60),
                   'size' => 'sm', 'weight' => 'bold', 'color' => '#888888'];
    }

    if (!$days) {
        $body[] = ['type' => 'text', 'text' => 'ไม่มีงานที่บันทึกในรอบนี้',
                   'size' => 'sm', 'color' => '#888888', 'margin' => 'lg'];
    }
    foreach ($days as $day) {
        foreach (line_flex_work_summary_day($day, $opt) as $node) {
            $body[] = $node;
        }
    }
    if ($omitted > 0) {
        $body[] = ['type' => 'text', 'margin' => 'lg', 'size' => 'xs', 'color' => '#888888', 'wrap' => true,
                   'text' => 'ยังมีอีก ' . number_format($omitted) . ' วัน — กดปุ่มด้านล่างดูทั้งรอบในระบบ'];
    }
    // ดันเนื้อขึ้นบน ที่ว่างไปกองอยู่ก้อนเดียวข้างล่าง บับเบิลที่เนื้อสั้นกว่าจะได้ไม่
    // ถ่างระยะห่างของตัวเองจนดูคนละแบบกับใบอื่น (carousel ยืดทุกใบสูงเท่าใบที่สูงสุด)
    $body[] = ['type' => 'filler'];
    $body[] = line_flex_system_branding();

    $title = '📋 สรุปงานของคุณรอบนี้';
    if ($pages > 1) {
        $title .= '  (' . $page . '/' . $pages . ')';
    }
    $bubble = [
        'type' => 'bubble',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#0057b8', 'paddingAll' => '12px',
            'contents' => [[
                'type' => 'text', 'text' => $title,
                'color' => '#ffffff', 'size' => 'sm', 'weight' => 'bold',
            ]],
        ],
        'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '14px', 'contents' => $body],
    ];

    $url = trim((string) ($p['report_url'] ?? ''));
    if ($url !== '' && function_exists('line_flex_footer_btn')) {
        $safe = line_flex_sanitize_uri_or_empty($url);
        if ($safe !== '') {
            $bubble['footer'] = [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '10px',
                'contents' => [line_flex_footer_btn('ดูรายละเอียดในระบบ', $safe)],
            ];
        }
    }
    return $bubble;
}

/**
 * LINE รับเฉพาะ https — ลิงก์ที่ไม่ผ่านเกณฑ์ต้องตัดปุ่มทิ้ง ไม่ใช่ส่งไปให้ error
 *
 * @param  string $uri
 * @return string '' ถ้าใช้ไม่ได้
 */
function line_flex_sanitize_uri_or_empty(string $uri): string
{
    if (function_exists('line_notify_sanitize_https_uri')) {
        return (string) line_notify_sanitize_https_uri($uri);
    }
    return stripos($uri, 'https://') === 0 ? $uri : '';
}

/**
 * แบ่งวันลงบับเบิลให้แต่ละใบได้จำนวนใกล้เคียงกัน
 *
 * array_chunk() แบบตรง ๆ จะเหลือเศษไปกองที่ใบสุดท้าย (16 วัน → 5,5,5,1) ใบท้ายเลย
 * โล่งผิดสังเกต — หารจำนวนใบก่อนแล้วค่อยเฉลี่ยจะได้ 4,4,4,4
 *
 * @param  array<int,array<string,mixed>> $days
 * @param  int                            $per
 * @return array<int,array<int,array<string,mixed>>>
 */
function line_flex_work_summary_chunks(array $days, int $per): array
{
    if (!$days) {
        return [[]];
    }
    $n = count($days);
    $bubbles = max(1, (int) ceil($n / max(1, $per)));
    // array_chunk() ปัดขึ้นทุกก้อนแล้วเศษไปกองใบท้าย (21 วัน/5 ใบ → 5,5,5,5,1)
    // กระจายเศษไปใบแรก ๆ แทนจะได้ 5,4,4,4,4 ทุกใบสูงพอ ๆ กัน
    $base = intdiv($n, $bubbles);
    $rem = $n % $bubbles;
    $out = [];
    $i = 0;
    for ($b = 0; $b < $bubbles; $b++) {
        $take = $base + ($b < $rem ? 1 : 0);
        $out[] = array_slice($days, $i, $take);
        $i += $take;
    }
    return $out;
}

/**
 * ข้อความทั้งชุดของ event work.summary.monthly
 *
 * @param  array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_messages(array $payload): array
{
    $days = isset($payload['days']) && is_array($payload['days']) ? array_values($payload['days']) : [];
    $allChunks = line_flex_work_summary_chunks($days, WORK_SUMMARY_FLEX_DAYS_PER_BUBBLE);
    if (count($allChunks) > WORK_SUMMARY_FLEX_MAX_BUBBLES) {
        $allChunks = array_slice($allChunks, 0, WORK_SUMMARY_FLEX_MAX_BUBBLES);
    }

    // ไล่ลดความละเอียดจนกว่าทั้งรอบจะใส่ลงในเพดานได้ — เลือกระดับที่ละเอียดที่สุดที่ยังพอดี
    $levels = line_flex_work_summary_levels();
    $opt = end($levels);
    foreach ($levels as $lv) {
        $size = 0;
        foreach ($allChunks as $i => $chunk) {
            $size += strlen(json_encode(
                line_flex_work_summary_bubble($payload, $chunk, $i + 1, count($allChunks), 0, $lv),
                JSON_UNESCAPED_UNICODE
            ));
        }
        if ($size <= WORK_SUMMARY_FLEX_BUDGET) {
            $opt = $lv;
            break;
        }
    }

    // ประหยัดสุดแล้วยังไม่พอ (รอบที่ทำงานหนักมากจริง ๆ) ค่อยตัดวันท้าย ๆ ทิ้ง
    $chunks = $allChunks;
    $fit = 0;
    $used = 0;
    foreach ($chunks as $i => $chunk) {
        $len = strlen(json_encode(
            line_flex_work_summary_bubble($payload, $chunk, $i + 1, count($chunks), 0, $opt),
            JSON_UNESCAPED_UNICODE
        ));
        if ($fit > 0 && $used + $len > WORK_SUMMARY_FLEX_BUDGET) {
            break;
        }
        $used += $len;
        $fit++;
    }
    $omittedDays = 0;
    if ($fit < count($chunks)) {
        foreach (array_slice($chunks, $fit) as $chunk) {
            $omittedDays += count($chunk);
        }
        $chunks = array_slice($chunks, 0, $fit);
    }
    $pages = count($chunks);

    $bubbles = [];
    foreach ($chunks as $i => $chunk) {
        $bubbles[] = line_flex_work_summary_bubble(
            $payload, $chunk, $i + 1, $pages, $i === $pages - 1 ? $omittedDays : 0, $opt
        );
    }

    return [[
        'type'     => 'flex',
        'altText'  => 'สรุปงานของคุณ รอบ ' . (string) ($payload['cycle_label'] ?? ''),
        'contents' => $pages > 1
            ? ['type' => 'carousel', 'contents' => $bubbles]
            : $bubbles[0],
    ]];
}
