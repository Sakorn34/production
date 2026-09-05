<?php
/**
 * shared/line_flex_work_summary.php — Flex สรุปงานรายคนต่อรอบเดือน
 *
 * การ์ดใบเดียว ตอบคำถามเดียว: "เดือนนี้ทำงานวันไหน เวลาไหน งานอะไร"
 * ปลายทางจริงของข้อความนี้คือเอาไปกรอกใบบันทึกงานล่วงเวลา คนอ่านจึงต้องการ
 * วันที่กับเวลาเป็นหลัก ส่วนรายละเอียดว่าเครื่องไหนบ้างอยู่ที่หน้าเว็บ
 *
 * โครงการ์ด: รายการรายวัน — วันที่ · ช่วงเวลาที่บันทึก · หัวข้องาน (+ รูปของที่ทำ)
 * ปฏิทินอยู่ที่หน้าเว็บอย่างเดียว ในแชทมันซ้ำกับรายการรายวันที่บอกครบอยู่แล้ว
 *
 * เวลาที่แสดงคือ "เวลาที่บันทึกข้อมูล" ไม่ใช่เวลาเข้า–ออกงาน และมีเฉพาะงานฝั่ง
 * production — ระบบซ่อม/เช่าเก็บแค่วันที่ วันที่ไม่มีเวลาจึงเว้นว่างไว้ ไม่เดาให้
 */

/**
 * เพดานขนาด JSON ของการ์ด — LINE ตัดข้อความที่เกิน 30KB ทิ้งทั้งฉบับ ตั้งต่ำกว่านั้นมาก
 * เพราะการ์ดยาวเกินก็ไม่มีใครอ่าน
 */
const WORK_SUMMARY_FLEX_BUDGET = 20000;

/** ความสูงรูปของที่ทำในแถววัน */
const WORK_SUMMARY_FLEX_THUMB = '32px';

/**
 * ระดับความละเอียด ไล่จากเต็มที่สุดไปประหยัดที่สุด
 *
 * URL รูปยาวเส้นละ ~125 ตัวอักษร แถวที่มีรูปจึงกินที่ราว 3 เท่าของแถวข้อความเปล่า
 * คนที่ทำงานเกือบทุกวันจะยาวเกินเพดาน — ตัดรูปก่อน แล้วค่อยตัดวัน เพราะ "ทำงาน
 * วันไหนบ้าง" คือแก่นของสรุปนี้ ส่วนรูปเป็นของแถม
 *
 * @return array<int,array{images:bool}>
 */
function line_flex_work_summary_levels(): array
{
    return [['images' => true], ['images' => false]];
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
    if ($name === '') {
        return '';
    }
    if ($cat === 'part_out') {
        // อะไหล่ที่ยังไม่มีรูปในระบบ ปล่อยว่างดีกว่าเอารูปเครื่องมาแปะ
        return function_exists('line_flex_part_image') ? line_flex_part_image($name) : '';
    }
    return function_exists('line_flex_product_image') ? line_flex_product_image($name) : '';
}

/**
 * แถวของหนึ่งวัน — [รูป] วันที่ · เวลา / หัวข้องาน
 *
 * @param  array<string,mixed>  $day ['label'=>..,'time'=>..,'work'=>..,'lead'=>['name'=>..,'key'=>..]]
 * @param  array{images:bool}   $opt
 * @return array<string,mixed>
 */
function line_flex_work_summary_day(array $day, array $opt): array
{
    $lead = isset($day['lead']) && is_array($day['lead']) ? $day['lead'] : ['name' => '', 'key' => ''];
    $url = !empty($opt['images'])
        ? line_flex_work_summary_image((string) ($lead['name'] ?? ''), (string) ($lead['key'] ?? ''))
        : '';

    $head = [
        ['type' => 'text', 'text' => line_flex_text((string) ($day['label'] ?? '-'), 24),
         'size' => 'sm', 'weight' => 'bold', 'color' => '#0057b8', 'flex' => 0],
    ];
    $time = trim((string) ($day['time'] ?? ''));
    if ($time !== '') {
        $head[] = ['type' => 'text', 'text' => $time, 'size' => 'xs',
                   'color' => '#111111', 'align' => 'end'];
    }

    $col = [
        ['type' => 'box', 'layout' => 'horizontal', 'contents' => $head],
        ['type' => 'text', 'text' => line_flex_text((string) ($day['work'] ?? ''), 120),
         'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'xs'],
    ];

    $row = [];
    if ($url !== '') {
        $row[] = ['type' => 'image', 'url' => $url, 'size' => WORK_SUMMARY_FLEX_THUMB,
                  'aspectRatio' => '1:1', 'flex' => 0];
        $row[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'contents' => $col];
    } else {
        $row = $col;
    }

    return ['type' => 'box', 'layout' => $url !== '' ? 'horizontal' : 'vertical',
            'margin' => 'lg', 'contents' => $row];
}

/**
 * การ์ดสรุป — ใบเดียวจบ
 *
 * @param  array<string,mixed>            $p       payload
 * @param  array<int,array<string,mixed>> $days    วันที่ยกมาโชว์ (เรียงเก่า→ใหม่)
 * @param  int                            $omitted วันที่ใส่ไม่ลง
 * @param  array{images:bool}             $opt
 * @return array<string,mixed>
 */
function line_flex_work_summary_bubble(array $p, array $days, int $omitted = 0, array $opt = ['images' => true]): array
{
    $all = isset($p['days']) && is_array($p['days']) ? $p['days'] : [];
    $body = [
        ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60),
         'size' => 'lg', 'weight' => 'bold', 'color' => '#111111'],
        ['type' => 'text', 'text' => line_flex_text((string) ($p['cycle_label'] ?? ''), 60)
                                     . '  ·  ทำงาน ' . number_format(count($all)) . ' วัน',
         'size' => 'xs', 'color' => '#888888', 'margin' => 'xs'],
    ];

    if (!$days) {
        $body[] = ['type' => 'text', 'text' => 'ไม่มีงานที่บันทึกในรอบนี้',
                   'size' => 'sm', 'color' => '#888888', 'margin' => 'lg'];
    } else {
        $body[] = ['type' => 'separator', 'margin' => 'lg', 'color' => '#eef2f7'];
    }
    foreach ($days as $day) {
        $body[] = line_flex_work_summary_day($day, $opt);
    }
    if ($omitted > 0) {
        $body[] = ['type' => 'text', 'margin' => 'lg', 'size' => 'xs', 'color' => '#888888', 'wrap' => true,
                   'text' => 'ยังมีอีก ' . number_format($omitted) . ' วันก่อนหน้า — กดดูทั้งรอบได้ที่ปุ่มด้านล่าง'];
    }
    $body[] = ['type' => 'text', 'margin' => 'lg', 'size' => 'xxs', 'color' => '#aab2bd', 'wrap' => true,
               'text' => 'เวลาที่แสดงคือเวลาที่บันทึกข้อมูล งานจากระบบซ่อม/เช่าไม่มีเวลากำกับ'];
    $body[] = line_flex_system_branding();

    $bubble = [
        'type' => 'bubble',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#0057b8', 'paddingAll' => '12px',
            'contents' => [[
                'type' => 'text', 'text' => '📋 สรุปงานของคุณรอบนี้',
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

    $fits = function ($bubble) {
        return strlen(json_encode($bubble, JSON_UNESCAPED_UNICODE)) <= WORK_SUMMARY_FLEX_BUDGET;
    };
    $levels = line_flex_work_summary_levels();

    // ครบทุกวันสำคัญกว่ารูป — ข้อความนี้เอาไปกรอกใบ OT วันที่หายไปคือข้อมูลหาย
    // ส่วนรูปเป็นของแถม จึงลดระดับรูปให้หมดก่อนค่อยยอมตัดวัน
    foreach ($levels as $opt) {
        $bubble = line_flex_work_summary_bubble($payload, $days, 0, $opt);
        if ($fits($bubble)) {
            return line_flex_work_summary_wrap($payload, $bubble);
        }
    }
    $cheapest = end($levels);
    for ($take = count($days) - 1; $take >= 1; $take--) {
        $show = array_slice($days, -$take);
        $bubble = line_flex_work_summary_bubble($payload, $show, count($days) - $take, $cheapest);
        if ($fits($bubble)) {
            return line_flex_work_summary_wrap($payload, $bubble);
        }
    }
    // ยาวเกินแม้เหลือวันเดียว — เหลือปฏิทินกับปุ่มพอ
    return line_flex_work_summary_wrap(
        $payload,
        line_flex_work_summary_bubble($payload, [], count($days), $cheapest)
    );
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
