<?php
/**
 * shared/line_flex_work_summary.php — Flex สรุปงานรายคนต่อรอบเดือน
 *
 * ส่งเข้าไลน์ส่วนตัวของแต่ละคน ว่ารอบ 21 เดือนก่อน–20 เดือนนี้ เขาทำอะไรไปบ้าง
 * **แยกเป็นรายวัน** — เจ้าตัวอยากรู้ว่า "วันไหนทำอะไร" ไม่ใช่ยอดรวมทั้งรอบเฉย ๆ
 *
 * หนึ่งคนทำงานราว 16–20 วันต่อรอบ ใส่บับเบิลเดียวจะยาวเกินที่ LINE รับไหว จึงหั่นเป็น
 * carousel บับเบิลละ WORK_SUMMARY_FLEX_DAYS_PER_BUBBLE วัน
 */

/** จำนวนวันต่อบับเบิล — 6 วันคือความยาวที่ยังเลื่อนอ่านในแชทได้สบาย */
const WORK_SUMMARY_FLEX_DAYS_PER_BUBBLE = 6;

/** LINE รับ carousel ได้สูงสุด 12 บับเบิล */
const WORK_SUMMARY_FLEX_MAX_BUBBLES = 12;

/**
 * เพดานขนาด JSON ที่ยอมให้ข้อความหนึ่งใหญ่ได้ — LINE ตัดที่ 30KB
 * เผื่อไว้เพราะรอบที่ทำงานเกือบทุกวันและงานหลากหลายจะโตกว่าที่วัดไว้
 */
const WORK_SUMMARY_FLEX_BUDGET = 27000;

/**
 * แถวของหนึ่งวัน — หัวแถวเป็นวันที่ + ยอดรวมวันนั้น ใต้ลงมาเป็นงานที่ทำ
 *
 * @param  array<string,mixed> $day   ['label'=>..,'total'=>..,'items'=>[['label'=>..,'count'=>..]]]
 * @param  bool                $first แถวแรกของบับเบิลไม่ต้องมีเส้นคั่นข้างบน
 * @return array<int,array<string,mixed>>
 */
function line_flex_work_summary_day(array $day, bool $first): array
{
    $rows = [[
        'type' => 'box', 'layout' => 'horizontal',
        'contents' => [
            ['type' => 'text', 'text' => line_flex_text((string) ($day['label'] ?? '-'), 24),
             'size' => 'sm', 'weight' => 'bold', 'color' => '#0057b8', 'flex' => 0],
            ['type' => 'text', 'text' => number_format((int) ($day['total'] ?? 0)) . ' รายการ',
             'size' => 'xs', 'color' => '#999999', 'align' => 'end'],
        ],
    ]];
    // หมวดงาน + ของจริงที่ทำในหมวดนั้น (ฝั่ง PHP ย่อมาให้แล้วใน 'detail')
    // เคยลองยุบสองบรรทัดนี้เป็น text ก้อนเดียวแยกสีด้วย span แล้ว — JSON โตขึ้นราว 7%
    // เพราะ span ก็เป็น node ที่มี key ของตัวเอง อย่ายุบอีก
    foreach ((isset($day['cats']) && is_array($day['cats']) ? $day['cats'] : []) as $cat) {
        $rows[] = ['type' => 'text', 'margin' => 'sm', 'size' => 'xs', 'color' => '#555555',
                   'text' => line_flex_text((string) ($cat['label'] ?? '-'), 40)
                             . '  ' . number_format((int) ($cat['count'] ?? 0))];
        $detail = trim((string) ($cat['detail'] ?? ''));
        if ($detail !== '') {
            $rows[] = ['type' => 'text', 'size' => 'sm', 'color' => '#222222', 'wrap' => true,
                       'text' => line_flex_text($detail, 220)];
        }
    }

    $out = [];
    if (!$first) {
        $out[] = ['type' => 'separator', 'margin' => 'md', 'color' => '#eef2f7'];
    }
    $out[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'xs',
              'contents' => $rows];
    return $out;
}

/**
 * บับเบิลหนึ่งใบ (วันชุดหนึ่ง)
 *
 * @param  array<string,mixed>              $p     payload
 * @param  array<int,array<string,mixed>>   $days  วันของบับเบิลนี้
 * @param  int                              $page  หน้าที่เท่าไหร่ (เริ่มที่ 1)
 * @param  int                              $pages ทั้งหมดกี่หน้า
 * @return array<string,mixed>
 */
function line_flex_work_summary_bubble(array $p, array $days, int $page, int $pages, int $omitted = 0): array
{
    $body = [];
    if ($page === 1) {
        $body[] = ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60),
                   'size' => 'lg', 'weight' => 'bold', 'color' => '#111111'];
        $body[] = ['type' => 'text', 'text' => line_flex_text((string) ($p['cycle_label'] ?? ''), 60),
                   'size' => 'xs', 'color' => '#888888', 'margin' => 'xs'];
        $body[] = [
            'type' => 'box', 'layout' => 'baseline', 'margin' => 'lg',
            'contents' => [
                ['type' => 'text', 'text' => number_format((int) ($p['total'] ?? 0)),
                 'size' => 'xxl', 'weight' => 'bold', 'color' => '#0057b8', 'flex' => 0],
                ['type' => 'text', 'size' => 'sm', 'color' => '#888888',
                 'text' => '  รายการ · ' . number_format((int) ($p['day_count'] ?? count($days))) . ' วัน'],
            ],
        ];
        $body[] = ['type' => 'separator', 'margin' => 'lg'];
    } else {
        $body[] = ['type' => 'text', 'text' => line_flex_text((string) ($p['person_name'] ?? '-'), 60) . ' (ต่อ)',
                   'size' => 'sm', 'weight' => 'bold', 'color' => '#111111'];
        $body[] = ['type' => 'separator', 'margin' => 'md'];
    }

    if (!$days) {
        $body[] = ['type' => 'text', 'text' => 'ไม่มีงานที่บันทึกในรอบนี้',
                   'size' => 'sm', 'color' => '#888888', 'margin' => 'md'];
    }
    $first = true;
    foreach ($days as $day) {
        foreach (line_flex_work_summary_day($day, $first) as $node) {
            $body[] = $node;
        }
        $first = false;
    }
    if ($omitted > 0) {
        $body[] = ['type' => 'text', 'margin' => 'md', 'size' => 'xs', 'color' => '#888888', 'wrap' => true,
                   'text' => 'ยังมีอีก ' . number_format($omitted) . ' วัน — กดปุ่มด้านล่างดูทั้งรอบในระบบ'];
    }
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
    $days = isset($payload['days']) && is_array($payload['days']) ? array_values($payload['days']) : [];
    $chunks = $days ? array_chunk($days, WORK_SUMMARY_FLEX_DAYS_PER_BUBBLE) : [[]];
    if (count($chunks) > WORK_SUMMARY_FLEX_MAX_BUBBLES) {
        $chunks = array_slice($chunks, 0, WORK_SUMMARY_FLEX_MAX_BUBBLES);
    }
    // ลองสร้างดูก่อนว่ากี่บับเบิลถึงจะยังไม่เกินเพดาน — เกินแล้ว LINE ไม่ส่งให้ทั้งข้อความ
    // ตัดที่ท้ายรอบ (วันเก่าสุดอยู่ต้น) แล้วบอกไว้ว่าเหลืออีกกี่วัน
    $fit = 0;
    $used = 0;
    foreach ($chunks as $i => $chunk) {
        $len = strlen(json_encode(
            line_flex_work_summary_bubble($payload, $chunk, $i + 1, count($chunks)),
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
            $payload, $chunk, $i + 1, $pages, $i === $pages - 1 ? $omittedDays : 0
        );
    }

    $alt = 'สรุปงานรอบ ' . (string) ($payload['cycle_label'] ?? '') . ' — '
         . number_format((int) ($payload['total'] ?? 0)) . ' รายการ ใน '
         . number_format(count($days)) . ' วัน';

    return [[
        'type'     => 'flex',
        'altText'  => $alt,
        'contents' => $pages > 1
            ? ['type' => 'carousel', 'contents' => $bubbles]
            : $bubbles[0],
    ]];
}
