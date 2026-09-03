<?php
/**
 * shared/line_flex_finishgood_shortage.php — Flex แจ้งเตือนสินค้าสำเร็จรูปที่ต้องผลิตเพิ่ม
 *
 * วัตถุประสงค์: ประกอบ Flex carousel จากรายการที่ "Stock ที่ต้องผลิต" ติดลบ
 *              (คงเหลือ + ระบบเช่า) − (ขั้นต่ำ + PO) < 0
 *
 * ไฟล์นี้ไม่แตะฐานข้อมูล — รับ array ที่คำนวณมาแล้วเท่านั้น จึงเทสต์ได้โดยไม่ต้องต่อ DB
 *
 * รูปแบบ 1 รายการที่รับเข้ามา:
 *   [
 *     'product_name'  => 'Scanner',
 *     'product_code'  => 'ACC028',
 *     'stock_qty'     => 2,
 *     'leasing_qty'   => 11,
 *     'minimum_stock' => 20,
 *     'po_qty'        => 14,
 *     'available'     => 13,
 *     'required'      => 34,
 *     'need'          => -21,
 *     'image_url'     => 'https://...',
 *     'detail_url'    => 'https://.../finishgood_serial_list.php?product_id=33',
 *   ]
 *
 * Flow: line_flex_finishgood_shortage_messages($items) → array ของ message object
 */

// ─ Config ─────────────────────────────────────────────────────────────────────

/** @var int จำนวนรุ่นสูงสุดต่อการ์ด 1 ใบ */
const LINE_FLEX_FG_ITEMS_PER_CARD = 5;

/** @var string ความสูงคงที่ของแถว 1 รุ่น — ทำให้ทุกการ์ดสูงเท่ากัน */
const LINE_FLEX_FG_ROW_HEIGHT = '108px';

/** @var int จำนวนการ์ดสูงสุดต่อ 1 ข้อความ (LINE จำกัด carousel 12 bubble) */
const LINE_FLEX_FG_MAX_CARDS = 12;

/**
 * @var int งบขนาด payload ต่อ 1 ข้อความ (byte)
 *
 * LINE จำกัด Flex message ละ 50 KB — เผื่อไว้ที่ 45 KB กัน payload บวมเมื่อข้อมูลเพิ่ม
 */
const LINE_FLEX_FG_MESSAGE_BYTE_BUDGET = 45000;

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * ข้อความสำหรับ Flex — ห้ามว่าง และตัดความยาวกัน payload เกินลิมิต
 *
 * @param string $text
 * @param int $max
 * @return string
 */
function line_flex_fg_text(string $text, int $max = 200): string
{
    $text = trim($text);
    if ($text === '') {
        return '-';
    }
    if (mb_strlen($text, 'UTF-8') > $max) {
        $text = mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
    return $text;
}

/**
 * สีตามความรุนแรงของการขาด (สัดส่วนที่มี เทียบกับที่ต้องมี)
 *
 * @param int $available จำนวนที่มี (คงเหลือ + เช่า)
 * @param int $required จำนวนที่ต้องมี (ขั้นต่ำ + PO)
 * @return string hex color
 */
function line_flex_fg_color(int $available, int $required): string
{
    if ($required <= 0) {
        return '#e67e22';
    }
    $ratio = $available / $required;
    if ($ratio <= 0.25) {
        return '#c0392b';
    }
    if ($ratio <= 0.6) {
        return '#e67e22';
    }
    return '#d4a017';
}

/**
 * แถบเส้นแนวนอน: จำนวนที่มี เทียบกับจำนวนที่ต้องมี
 *
 * ใช้ flex ratio แทน width % เพราะ LINE Flex ไม่รองรับ % ใน box ซ้อน
 *
 * @param int $available
 * @param int $required
 * @param string $color
 * @return array<string,mixed>
 */
function line_flex_fg_bar(int $available, int $required, string $color): array
{
    $pct = $required > 0 ? (int)round($available / $required * 100) : 100;
    $pct = max(0, min(100, $pct));

    return [
        'type' => 'box', 'layout' => 'horizontal', 'height' => '8px', 'margin' => 'sm',
        'backgroundColor' => '#ececec', 'cornerRadius' => '4px',
        'contents' => [
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => max($pct, 1),
                'backgroundColor' => $color, 'cornerRadius' => '4px',
                'contents' => [['type' => 'filler']],
            ],
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => max(100 - $pct, 1),
                'contents' => [['type' => 'filler']],
            ],
        ],
    ];
}

/**
 * กรอบสีบอกตัวเลข 1 ชุด — ป้ายกำกับ · ที่มา · ยอดรวม
 *
 * แยกสีเพื่อให้อ่านออกทันทีว่าอันไหน "ที่มี" อันไหน "ที่ต้องมี"
 *
 * @param string $label ป้ายกำกับ เช่น คงเหลือ
 * @param string $breakdown ที่มาของตัวเลข เช่น 2 (+11 เช่า)
 * @param int $total ยอดรวม
 * @param string $bgColor สีพื้นกรอบ
 * @param string $labelColor สีป้ายกำกับ
 * @param string $totalColor สีตัวเลขรวม
 * @return array<string,mixed>
 */
function line_flex_fg_metric_pill(string $label, string $breakdown, int $total, string $bgColor, string $labelColor, string $totalColor): array
{
    return [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'xs',
        'backgroundColor' => $bgColor, 'cornerRadius' => '4px', 'paddingAll' => '4px',
        'contents' => [
            ['type' => 'text', 'text' => $label, 'size' => 'xxs', 'color' => $labelColor, 'weight' => 'bold', 'flex' => 4, 'wrap' => false],
            ['type' => 'text', 'text' => $breakdown, 'size' => 'xxs', 'color' => '#8a8a8a', 'flex' => 5, 'align' => 'end', 'wrap' => false],
            ['type' => 'text', 'text' => '= ' . $total, 'size' => 'xxs', 'color' => $totalColor, 'weight' => 'bold', 'flex' => 3, 'align' => 'end', 'wrap' => false],
        ],
    ];
}

/**
 * URL รูปของรุ่นสินค้า — ใช้ตัวกลางใน line_flex_templates.php
 *
 * เดิมไฟล์นี้มีตัวค้น icon_path กับรูป default ของตัวเอง ซึ่งซ้ำกับตัวกลาง
 * ย้ายไปรวมที่เดียวแล้ว การ์ดรายงานผลิตจะได้ใช้รูปบนเซิร์ฟเวอร์เหมือนกัน
 *
 * @param string $productName ชื่อรุ่น
 * @param string $fallbackUrl URL ที่ส่งมากับข้อมูล
 * @return string
 */
function line_flex_fg_image_url(string $productName, string $fallbackUrl = ''): string
{
    return line_flex_product_image($productName, $fallbackUrl);
}

/**
 * แถว 1 รุ่น: รูป (กึ่งกลาง) · ชื่อที่กดไปหน้า serial list · ยอดขาด · แถบเส้น · กรอบสี 2 ชุด
 *
 * ล็อกความสูงคงที่ทุกแถว เพื่อให้ทุกการ์ดใน carousel สูงเท่ากัน
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function line_flex_fg_row(array $item): array
{
    $available = (int)$item['available'];
    $required  = (int)$item['required'];
    $color     = line_flex_fg_color($available, $required);

    $leasingQty = (int)($item['leasing_qty'] ?? 0);
    $leasingSuffix = $leasingQty > 0 ? ' (+' . $leasingQty . ' เช่า)' : '';

    $nameText = [
        'type' => 'text',
        'text' => line_flex_fg_text((string)$item['product_name'], 60),
        'size' => 'xs', 'weight' => 'bold', 'color' => '#1a5fb4', 'flex' => 7, 'wrap' => true,
    ];
    if (!empty($item['detail_url'])) {
        $nameText['action'] = [
            'type'  => 'uri',
            'label' => line_flex_fg_text((string)$item['product_name'], 40),
            'uri'   => (string)$item['detail_url'],
        ];
    }

    return [
        'type' => 'box', 'layout' => 'horizontal', 'height' => LINE_FLEX_FG_ROW_HEIGHT, 'margin' => 'lg',
        'contents' => [
            // รูปสินค้า — จัดกึ่งกลางช่องทั้งแนวตั้งและแนวนอน
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 0, 'width' => '58px',
                'backgroundColor' => '#f5f5f5', 'cornerRadius' => '6px', 'paddingAll' => '3px',
                'justifyContent' => 'center', 'alignItems' => 'center',
                'contents' => [
                    [
                        'type' => 'image',
                        'url' => line_flex_fg_image_url((string)$item['product_name'], (string)($item['image_url'] ?? '')),
                        'size' => 'full',
                        'aspectMode' => 'fit',
                        'aspectRatio' => '1:1',
                    ],
                ],
            ],

            // รายละเอียด
            [
                'type' => 'box', 'layout' => 'vertical', 'flex' => 1, 'paddingStart' => '8px',
                'contents' => [
                    [
                        'type' => 'box', 'layout' => 'horizontal',
                        'contents' => [
                            $nameText,
                            [
                                'type' => 'text', 'text' => (string)(int)$item['need'],
                                'size' => 'lg', 'weight' => 'bold', 'color' => $color, 'flex' => 3, 'align' => 'end',
                            ],
                        ],
                    ],
                    [
                        'type' => 'box', 'layout' => 'horizontal',
                        'contents' => [
                            ['type' => 'text', 'text' => line_flex_fg_text((string)$item['product_code'], 30), 'size' => 'xxs', 'color' => '#999999', 'flex' => 7],
                            ['type' => 'text', 'text' => 'ต้องผลิต', 'size' => 'xxs', 'color' => '#999999', 'flex' => 3, 'align' => 'end'],
                        ],
                    ],

                    line_flex_fg_bar($available, $required, $color),

                    line_flex_fg_metric_pill(
                        'คงเหลือ',
                        (int)$item['stock_qty'] . $leasingSuffix,
                        $available,
                        '#eaf2fc', '#4a7ab8', '#1a5fb4'
                    ),
                    line_flex_fg_metric_pill(
                        'ขั้นต่ำ+PO',
                        (int)$item['minimum_stock'] . ' + ' . (int)$item['po_qty'],
                        $required,
                        '#fdeeea', '#b5705a', '#b02a1e'
                    ),
                ],
            ],
        ],
    ];
}

/**
 * แถวเปล่าความสูงเท่าแถวจริง — เติมการ์ดที่มีรายการน้อยกว่าให้สูงเท่ากัน
 *
 * @return array<string,mixed>
 */
function line_flex_fg_placeholder_row(): array
{
    return [
        'type' => 'box', 'layout' => 'vertical', 'height' => LINE_FLEX_FG_ROW_HEIGHT, 'margin' => 'lg',
        'contents' => [['type' => 'filler']],
    ];
}

/**
 * แบ่งรายการลงการ์ดให้จำนวนต่อใบใกล้เคียงกันที่สุด
 *
 * array_chunk() ธรรมดาจะทิ้งเศษไว้ใบสุดท้าย (27 รุ่น → 5,5,5,5,5,2)
 * วิธีนี้เฉลี่ยให้สมดุล (27 รุ่น → 5,5,5,4,4,4) แนวเดียวกับ line_flex_low_stock_card_chunks()
 *
 * @param array<int,array<string,mixed>> $items
 * @param int $maxPerCard
 * @return array<int,array<int,array<string,mixed>>>
 */
function line_flex_fg_card_chunks(array $items, int $maxPerCard): array
{
    $total = count($items);
    $maxPerCard = max(1, $maxPerCard);

    if ($total === 0) {
        return [];
    }
    if ($total <= $maxPerCard) {
        return [$items];
    }

    $numCards = (int)ceil($total / $maxPerCard);
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
 * การ์ด 1 ใบ (bubble)
 *
 * @param array<int,array<string,mixed>> $items รุ่นในการ์ดนี้
 * @param int $page ลำดับการ์ด
 * @param int $totalPages จำนวนการ์ดทั้งหมด
 * @param int $totalItems จำนวนรุ่นติดลบทั้งหมด
 * @param int $totalShortage ยอดขาดรวม (ค่าติดลบ)
 * @param string $timestamp เวลาที่สรุป
 * @param int $rowsPerCard จำนวนแถวที่ทุกการ์ดต้องมีเท่ากัน
 * @return array<string,mixed>
 */
function line_flex_fg_bubble(array $items, int $page, int $totalPages, int $totalItems, int $totalShortage, string $timestamp, int $rowsPerCard = 0): array
{
    $rows = [];
    foreach ($items as $index => $item) {
        if ($index > 0) {
            $rows[] = ['type' => 'separator', 'color' => '#f0f0f0', 'margin' => 'lg'];
        }
        $rows[] = line_flex_fg_row($item);
    }

    $rowsPerCard = max($rowsPerCard, count($items));
    for ($i = count($items); $i < $rowsPerCard; $i++) {
        $rows[] = ['type' => 'separator', 'color' => '#f0f0f0', 'margin' => 'lg'];
        $rows[] = line_flex_fg_placeholder_row();
    }

    return [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#b02a1e', 'paddingAll' => 'lg',
            'contents' => [
                ['type' => 'text', 'text' => '🏭 FINISH GOOD ALERT', 'size' => 'xxs', 'color' => '#ffd0cb', 'weight' => 'bold'],
                ['type' => 'text', 'text' => 'สินค้าที่ต้องผลิตเพิ่ม', 'size' => 'md', 'color' => '#ffffff', 'weight' => 'bold', 'margin' => 'xs'],
                [
                    'type' => 'text',
                    'text' => $totalItems . ' รุ่น · ขาดรวม ' . number_format(abs($totalShortage)) . ' เครื่อง',
                    'size' => 'xs', 'color' => '#ffdedb', 'margin' => 'sm', 'wrap' => true,
                ],
                [
                    'type' => 'text',
                    'text' => ($totalPages > 1 ? 'การ์ด ' . $page . '/' . $totalPages . ' | ' : '') . '🕐 ' . $timestamp,
                    'size' => 'xxs', 'color' => '#ffc9c4', 'margin' => 'xs', 'wrap' => true,
                ],
            ],
        ],
        'body' => [
            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px',
            'contents' => array_merge(
                [[
                    'type' => 'box', 'layout' => 'horizontal',
                    'backgroundColor' => '#f7f7f7', 'cornerRadius' => '6px', 'paddingAll' => '6px',
                    'contents' => [
                        ['type' => 'text', 'text' => 'รุ่นสินค้า', 'size' => 'xxs', 'color' => '#999999', 'weight' => 'bold', 'flex' => 7],
                        ['type' => 'text', 'text' => 'ต้องผลิต', 'size' => 'xxs', 'color' => '#999999', 'weight' => 'bold', 'flex' => 3, 'align' => 'end'],
                    ],
                ]],
                $rows
            ),
        ],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px', 'backgroundColor' => '#fff4f2', 'cornerRadius' => '12px',
            'contents' => [
                ['type' => 'text', 'text' => 'แถบเส้น = Stock คงเหลือ(+เช่า) ต่อ ขั้นต่ำ+PO', 'size' => 'xxs', 'color' => '#b02a1e', 'align' => 'center', 'wrap' => true],
                ['type' => 'separator', 'margin' => 'sm', 'color' => '#ffd9d3'],
                ['type' => 'text', 'text' => '✅ Setup System · เช็ค Stock สินค้าสำเร็จรูป', 'size' => 'xxs', 'color' => '#bbbbbb', 'align' => 'center', 'margin' => 'sm'],
            ],
        ],
    ];
}

/**
 * ประกอบ Flex ทั้งชุด แล้วแตกเป็นหลายข้อความให้แต่ละข้อความไม่เกินลิมิต 50 KB ของ LINE
 *
 * @param array<int,array<string,mixed>> $items รายการที่ต้องผลิตเพิ่ม (เรียงจากขาดมากไปน้อย)
 * @param string|null $timestamp เวลาที่แสดง (null = เวลาปัจจุบัน)
 * @return array<int,array<string,mixed>> รายการ message object (ว่าง = ไม่มีรุ่นติดลบ)
 */
function line_flex_finishgood_shortage_messages(array $items, ?string $timestamp = null): array
{
    if ($items === []) {
        return [];
    }

    if ($timestamp === null) {
        $timestamp = date('d/m/Y H:i') . ' น.';
    }

    $totalItems = count($items);
    $totalShortage = 0;
    foreach ($items as $item) {
        $totalShortage += (int)$item['need'];
    }

    $chunks = line_flex_fg_card_chunks($items, LINE_FLEX_FG_ITEMS_PER_CARD);
    $totalPages = count($chunks);

    $rowsPerCard = 0;
    foreach ($chunks as $chunk) {
        $rowsPerCard = max($rowsPerCard, count($chunk));
    }

    $bubbles = [];
    foreach ($chunks as $index => $chunk) {
        $bubbles[] = line_flex_fg_bubble($chunk, $index + 1, $totalPages, $totalItems, $totalShortage, $timestamp, $rowsPerCard);
    }

    // จัด bubble ลงข้อความแบบ greedy ตามงบ byte และจำนวน bubble สูงสุด
    $groups = [];
    $current = [];
    $currentBytes = 0;
    foreach ($bubbles as $bubble) {
        $size = strlen(json_encode($bubble, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $wouldOverflow = ($currentBytes + $size) > LINE_FLEX_FG_MESSAGE_BYTE_BUDGET;
        if ($current !== [] && ($wouldOverflow || count($current) >= LINE_FLEX_FG_MAX_CARDS)) {
            $groups[] = $current;
            $current = [];
            $currentBytes = 0;
        }
        $current[] = $bubble;
        $currentBytes += $size;
    }
    if ($current !== []) {
        $groups[] = $current;
    }

    $totalGroups = count($groups);
    $messages = [];
    foreach ($groups as $index => $group) {
        $contents = count($group) === 1
            ? $group[0]
            : ['type' => 'carousel', 'contents' => $group];

        $altText = '🏭 สินค้าที่ต้องผลิตเพิ่ม ' . $totalItems . ' รุ่น (ขาดรวม ' . number_format(abs($totalShortage)) . ' เครื่อง)';
        if ($totalGroups > 1) {
            $altText .= ' [' . ($index + 1) . '/' . $totalGroups . ']';
        }

        $messages[] = [
            'type' => 'flex',
            'altText' => $altText,
            'contents' => $contents,
        ];
    }

    return $messages;
}
