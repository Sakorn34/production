<?php
/**
 * shared/line_flex_work_summary.php — Flex สรุปงานรายคนต่อรอบเดือน
 *
 * ส่งเข้าไลน์ส่วนตัวของแต่ละคน ว่ารอบ 21 เดือนก่อน–20 เดือนนี้ เขาทำอะไรไปบ้าง
 * ไม่บอกจำนวนครั้ง (ตัวเลขดูที่หน้าเว็บ) · มีรูปสินค้าประกอบรายการ
 *
 * **การ์ดใบเดียวเท่านั้น** — แจ้งเตือนมีหน้าที่บอกให้พอเห็นภาพแล้วชวนไปดูของจริง
 * carousel หลายใบทำให้ต้องปัดอ่านในแชทซึ่งไม่มีใครทำ ที่เหลือกดปุ่มไปดูที่เว็บ
 *
 * ชั้นข้อมูล 3 ชั้นต้องแยกออกจากกันให้เห็นตั้งแต่เหลือบแรก ไม่งั้นอ่านเร็ว ๆ แล้วปนกัน:
 *   วัน      = แถบสีทึบเต็มความกว้าง
 *   หัวข้องาน = ตัวหนาเล็ก มีขีดสีนำหน้า
 *   ของที่ทำ  = รูป + ชื่อรุ่น เยื้องเข้ามา
 */

/** จำนวนวันที่ยกมาโชว์ในการ์ด — เอาวันท้าย ๆ ของรอบ เพราะเป็นงานที่เพิ่งทำ */
const WORK_SUMMARY_FLEX_MAX_DAYS = 4;

/**
 * เพดานขนาด JSON ของการ์ด — LINE ตัดข้อความที่เกิน 30KB ทิ้งทั้งฉบับ แต่ที่นี่
 * ตั้งต่ำกว่ามากเพราะการ์ดยาวเกินก็ไม่มีใครอ่าน ตัวจำกัดจริงคือความยาวที่พออ่านจบ
 */
const WORK_SUMMARY_FLEX_BUDGET = 13000;

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
 * หาไฟล์รูปของรายการหนึ่ง — หมวดเบิกอะไหล่ต้องหาในตาราง parts ไม่ใช่ products
 *
 * ถ้าใช้ map เดียวกันหมด ชื่ออะไหล่จะหาไม่เจอใน products แล้วได้รูปแทนของระบบมา
 * ซึ่งดูเหมือนรูปผิดของ
 *
 * @param  string $name ชื่อรุ่น หรือชื่ออะไหล่
 * @param  string $cat  key ของหมวด
 * @return string '' ถ้าไม่มีรูปที่ใช้ได้
 */
function line_flex_work_summary_image(string $name, string $cat): string
{
    if ($cat === 'part_out') {
        $url = function_exists('line_flex_part_image') ? line_flex_part_image($name) : '';
        // อะไหล่ที่ยังไม่มีรูปในระบบ (67 จาก 146 รายการ) ปล่อยว่างดีกว่าเอารูปเครื่องมาแปะ
        return $url;
    }
    return function_exists('line_flex_product_image') ? line_flex_product_image($name) : '';
}

/**
 * หนึ่งรายการงาน — รูป + ชื่อ (หรือชื่อเปล่าเมื่อประหยัดที่ / ไม่มีรูป)
 *
 * @param  string $name    ชื่อรุ่น หรือชื่ออะไหล่
 * @param  bool   $withImg ใส่รูปไหม
 * @param  string $cat     key ของหมวด
 * @return array<string,mixed>
 */
function line_flex_work_summary_item(string $name, bool $withImg, string $cat = ''): array
{
    $url = $withImg ? line_flex_work_summary_image($name, $cat) : '';
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
            $out[] = line_flex_work_summary_item((string) $nm, $withImg, (string) ($cat['key'] ?? ''));
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
 * ปฏิทินของรอบ — ช่องทึบ = วันที่มีงาน
 *
 * รอบนี้คร่อมสองเดือน (21 ถึง 20) ปฏิทินเดือนเดียวจึงใช้ไม่ได้ — วางเป็นสัปดาห์
 * เริ่มอาทิตย์ ตั้งแต่สัปดาห์ที่มีวันเปิดรอบ ถึงสัปดาห์ที่มีวันปิดรอบ วันนอกรอบเว้นว่าง
 *
 * @param  string            $from Y-m-d
 * @param  string            $to   Y-m-d
 * @param  array<string,bool> $worked key = Y-m-d ที่มีงาน
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_calendar(string $from, string $to, array $worked): array
{
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
        return [];
    }
    // ถอยไปวันอาทิตย์ของสัปดาห์แรก แล้วเดินทีละวันจนพ้นวันปิดรอบ
    $cur = strtotime('-' . (int) date('w', $fromTs) . ' day', $fromTs);
    $rows = [];
    $week = [];
    $guard = 0;
    while ($cur <= $toTs && $guard++ < 70) {
        $ymd = date('Y-m-d', $cur);
        $inCycle = $ymd >= $from && $ymd <= $to;
        $hit = $inCycle && isset($worked[$ymd]);
        $num = (string) ((int) date('j', $cur));
        if (!$inCycle) {
            $week[] = ['type' => 'filler'];
        } elseif ($hit) {
            // วันที่มีงานเป็นช่องทึบ — ตัวที่ต้องเห็นก่อนเพื่อน
            $week[] = [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#0057b8',
                'cornerRadius' => '4px', 'paddingAll' => '4px',
                'contents' => [['type' => 'text', 'text' => $num, 'size' => 'xxs',
                                'align' => 'center', 'weight' => 'bold', 'color' => '#ffffff']],
            ];
        } else {
            // วันว่างเป็นตัวเลขจาง ๆ ไม่ต้องมีกล่อง — ประหยัดที่ไปกว่าครึ่งต่อช่อง
            $week[] = ['type' => 'text', 'text' => $num, 'size' => 'xxs', 'align' => 'center',
                       'gravity' => 'center', 'color' => '#c8cfd8'];
        }
        if (count($week) === 7) {
            $rows[] = ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'xs',
                       'margin' => 'xs', 'contents' => $week];
            $week = [];
        }
        $cur = strtotime('+1 day', $cur);
    }
    if ($week) {
        while (count($week) < 7) {
            $week[] = ['type' => 'filler'];
        }
        $rows[] = ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'xs',
                   'margin' => 'xs', 'contents' => $week];
    }
    if (!$rows) {
        return [];
    }

    $head = [];
    foreach (['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'] as $d) {
        $head[] = ['type' => 'text', 'text' => $d, 'size' => 'xxs', 'align' => 'center', 'color' => '#9aa4b2'];
    }
    array_unshift($rows, ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'xs', 'contents' => $head]);

    return [['type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'contents' => $rows]];
}

/**
 * การ์ดสรุป — ใบเดียวจบ
 *
 * @param  array<string,mixed>            $p       payload
 * @param  array<int,array<string,mixed>> $days    วันที่ยกมาโชว์ (เรียงเก่า→ใหม่)
 * @param  int                            $omitted วันก่อนหน้าที่ไม่ได้ใส่มา
 * @param  array{names:int,images:string} $opt     ระดับความละเอียด
 * @return array<string,mixed>
 */
function line_flex_work_summary_bubble(array $p, array $days, int $omitted = 0, array $opt = ['names' => 2, 'images' => 'all']): array
{
    $body = [
        ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60),
         'size' => 'lg', 'weight' => 'bold', 'color' => '#111111'],
        ['type' => 'text', 'text' => line_flex_text((string) ($p['cycle_label'] ?? ''), 60),
         'size' => 'xs', 'color' => '#888888', 'margin' => 'xs'],
    ];

    // ปฏิทินเห็นทั้งรอบในภาพเดียว — การ์ดใบเดียวใส่รายละเอียดทุกวันไม่ไหว แต่ "ทำงาน
    // วันไหนบ้าง" ยัดลงตารางเล็ก ๆ ได้หมด ส่วนรายละเอียดค่อยกดไปดูที่เว็บ
    $worked = [];
    foreach (isset($p['all_dates']) && is_array($p['all_dates']) ? $p['all_dates'] : [] as $d) {
        $worked[(string) $d] = true;
    }
    foreach (line_flex_work_summary_calendar(
        (string) ($p['cycle_from'] ?? ''), (string) ($p['cycle_to'] ?? ''), $worked
    ) as $node) {
        $body[] = $node;
    }

    if (!$days) {
        $body[] = ['type' => 'text', 'text' => 'ไม่มีงานที่บันทึกในรอบนี้',
                   'size' => 'sm', 'color' => '#888888', 'margin' => 'lg'];
    } elseif ($omitted > 0) {
        // บอกให้ชัดว่านี่คือช่วงท้ายของรอบ ไม่ใช่งานทั้งหมดที่ทำ
        $body[] = ['type' => 'text', 'text' => 'งานล่าสุดในรอบนี้', 'size' => 'xs',
                   'color' => '#9aa4b2', 'margin' => 'lg'];
    }
    foreach ($days as $day) {
        foreach (line_flex_work_summary_day($day, $opt) as $node) {
            $body[] = $node;
        }
    }
    if ($omitted > 0) {
        $body[] = ['type' => 'text', 'margin' => 'lg', 'size' => 'xs', 'color' => '#888888', 'wrap' => true,
                   'text' => 'ก่อนหน้านี้ยังมีอีก ' . number_format($omitted) . ' วัน — กดดูทั้งรอบได้ที่ปุ่มด้านล่าง'];
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
 * ข้อความทั้งชุดของ event work.summary.monthly — การ์ดใบเดียวเสมอ
 *
 * @param  array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_messages(array $payload): array
{
    $days = isset($payload['days']) && is_array($payload['days']) ? array_values($payload['days']) : [];

    // ปฏิทินต้องรู้ทุกวันที่มีงาน ถึงแม้รายละเอียดจะยกมาแค่ไม่กี่วัน
    $payload['all_dates'] = [];
    foreach ($days as $d) {
        if (!empty($d['date'])) {
            $payload['all_dates'][] = (string) $d['date'];
        }
    }

    // รายละเอียดยกมาเฉพาะวันท้าย ๆ ของรอบ (งานที่เพิ่งทำ จำได้ง่ายกว่า)
    // แล้วไล่ลดจนกว่าการ์ดจะสั้นพอ — ปฏิทินยังบอกภาพรวมทั้งรอบอยู่แล้ว
    $levels = line_flex_work_summary_levels();
    $best = null;
    foreach ([WORK_SUMMARY_FLEX_MAX_DAYS, 3, 2, 1] as $take) {
        $show = array_slice($days, -$take);
        $omitted = count($days) - count($show);
        foreach ($levels as $lv) {
            $bubble = line_flex_work_summary_bubble($payload, $show, $omitted, $lv);
            if ($best === null) {
                $best = $bubble;   // เผื่อไม่มีชุดไหนพอดีเลย อย่างน้อยได้ใบที่ละเอียดสุด
            }
            if (strlen(json_encode($bubble, JSON_UNESCAPED_UNICODE)) <= WORK_SUMMARY_FLEX_BUDGET) {
                return line_flex_work_summary_wrap($payload, $bubble);
            }
        }
    }
    // เหลือวันเดียวระดับประหยัดสุดแล้วยังยาว — ตัดรายละเอียดทิ้ง เหลือปฏิทินกับปุ่ม
    $bubble = line_flex_work_summary_bubble($payload, [], count($days), end($levels));
    return line_flex_work_summary_wrap($payload, $bubble);
}

/**
 * ห่อบับเบิลเป็นข้อความ Flex หนึ่งฉบับ
 *
 * @param  array<string,mixed> $payload
 * @param  array<string,mixed> $bubble
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_wrap(array $payload, array $bubble): array
{
    return [[
        'type'     => 'flex',
        'altText'  => 'สรุปงานของคุณ รอบ ' . (string) ($payload['cycle_label'] ?? ''),
        'contents' => $bubble,
    ]];
}
