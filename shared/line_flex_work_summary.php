<?php
/**
 * shared/line_flex_work_summary.php — Flex สรุปงานรายคนต่อรอบเดือน
 *
 * ส่งเข้าไลน์ส่วนตัวของแต่ละคน ว่ารอบ 21 เดือนก่อน–20 เดือนนี้ เขาบันทึกอะไรไปบ้าง
 * ทุกระบบรวมกัน · วัดจากข้อมูลจริงแล้วคนหนึ่งมีงานหลักร้อยรายการต่อรอบ จึงสรุปเป็น
 * ตัวเลขต่อหมวด ไม่ลิสต์ทีละงาน
 */

/**
 * แถวหมวดงาน — ชื่อหมวดซ้าย จำนวนขวา พร้อมแถบสัดส่วนบาง ๆ ให้เห็นว่าหมวดไหนหนัก
 *
 * @param  array<string,mixed> $item  ['label'=>..,'count'=>..]
 * @param  int                 $max   จำนวนของหมวดที่เยอะสุด (ไว้คิดความยาวแถบ)
 * @return array<string,mixed>
 */
function line_flex_work_summary_row(array $item, int $max): array
{
    $count = (int) ($item['count'] ?? 0);
    $pct = $max > 0 ? max(4, (int) round($count / $max * 100)) : 4;
    return [
        'type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'xs',
        'contents' => [
            [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => line_flex_text((string) ($item['label'] ?? '-'), 40),
                     'size' => 'sm', 'color' => '#333333', 'flex' => 7, 'wrap' => true],
                    ['type' => 'text', 'text' => number_format($count), 'size' => 'sm', 'weight' => 'bold',
                     'color' => '#0057b8', 'flex' => 3, 'align' => 'end'],
                ],
            ],
            [
                'type' => 'box', 'layout' => 'vertical', 'height' => '5px',
                'backgroundColor' => '#eef2f7', 'cornerRadius' => '3px',
                'contents' => [[
                    'type' => 'box', 'layout' => 'vertical', 'height' => '5px',
                    'width' => $pct . '%', 'backgroundColor' => '#4a90d9', 'cornerRadius' => '3px',
                    'contents' => [['type' => 'filler']],
                ]],
            ],
        ],
    ];
}

/**
 * บับเบิลสรุปของคนหนึ่ง
 *
 * @param  array<string,mixed> $p payload จาก work_summary_send_cycle()
 * @return array<string,mixed>
 */
function line_flex_work_summary_bubble(array $p): array
{
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : [];
    $max = 0;
    foreach ($items as $it) {
        $max = max($max, (int) ($it['count'] ?? 0));
    }
    // จำกัดจำนวนแถวกัน bubble ยาวเกินที่ LINE รับไหว — ที่เหลือยุบเป็นบรรทัดเดียว
    $showLimit = 8;
    $rows = [];
    foreach (array_slice($items, 0, $showLimit) as $it) {
        $rows[] = line_flex_work_summary_row($it, $max);
    }
    $rest = count($items) - $showLimit;
    if ($rest > 0) {
        $restSum = 0;
        foreach (array_slice($items, $showLimit) as $it) {
            $restSum += (int) ($it['count'] ?? 0);
        }
        $rows[] = ['type' => 'text', 'margin' => 'md', 'size' => 'xs', 'color' => '#888888',
                   'text' => 'และอีก ' . $rest . ' หมวด รวม ' . number_format($restSum) . ' รายการ'];
    }
    if (!$rows) {
        $rows[] = ['type' => 'text', 'text' => 'ไม่มีงานที่บันทึกในรอบนี้', 'size' => 'sm', 'color' => '#888888', 'margin' => 'md'];
    }

    $body = array_merge([
        ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60),
         'size' => 'lg', 'weight' => 'bold', 'color' => '#111111'],
        ['type' => 'text', 'text' => line_flex_text((string) ($p['cycle_label'] ?? ''), 60),
         'size' => 'xs', 'color' => '#888888', 'margin' => 'xs'],
        [
            'type' => 'box', 'layout' => 'baseline', 'margin' => 'lg',
            'contents' => [
                ['type' => 'text', 'text' => number_format((int) ($p['total'] ?? 0)),
                 'size' => 'xxl', 'weight' => 'bold', 'color' => '#0057b8', 'flex' => 0],
                ['type' => 'text', 'text' => '  รายการ', 'size' => 'sm', 'color' => '#888888'],
            ],
        ],
        ['type' => 'separator', 'margin' => 'lg'],
    ], $rows, [line_flex_system_branding()]);

    $bubble = [
        'type' => 'bubble',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#0057b8', 'paddingAll' => '12px',
            'contents' => [[
                'type' => 'text', 'text' => '📋 สรุปงานของคุณรอบนี้',
                'color' => '#ffffff', 'size' => 'sm', 'weight' => 'bold',
            ]],
        ],
        'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'contents' => $body],
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
 * ข้อความทั้งชุดของ event work.summary.monthly
 *
 * @param  array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_messages(array $payload): array
{
    return [[
        'type' => 'flex',
        'altText' => 'สรุปงานรอบ ' . (string) ($payload['cycle_label'] ?? '') . ' — '
                     . number_format((int) ($payload['total'] ?? 0)) . ' รายการ',
        'contents' => line_flex_work_summary_bubble($payload),
    ]];
}
