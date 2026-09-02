<?php
/**
 * finishgood_shortage_preview.php — ดูตัวอย่าง Flex "สินค้าที่ต้องผลิตเพิ่ม" ก่อนส่งจริง
 *
 * วัตถุประสงค์: ตรวจหน้าตา/ตัวเลขของการ์ดก่อนให้ cron ส่งเข้า LINE
 *              ข้อมูลดึงจาก API ของ setupsystem (แหล่งเดียวกับที่ cron ใช้)
 *
 * Flow: finishgood_shortage_fetch() → line_flex_finishgood_shortage_messages() → render + JSON
 */
require __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/shared/finishgood_shortage_client.php';
require_once dirname(__DIR__) . '/shared/line_flex_finishgood_shortage.php';

require_login();

$fetched = finishgood_shortage_fetch();
$messages = $fetched['ok']
    ? line_flex_finishgood_shortage_messages($fetched['items'], $fetched['timestamp_text'])
    : [];

// ─ Flex → HTML renderer (ประมาณการหน้าตาบน LINE) ─────────────────────────────

/**
 * แปลงขนาดตัวอักษรของ Flex เป็น px
 *
 * @param string $size
 * @return string
 */
function fg_preview_font_size(string $size): string
{
    $map = ['xxs' => '11px', 'xs' => '12px', 'sm' => '14px', 'md' => '16px', 'lg' => '19px', 'xl' => '22px', 'xxl' => '27px'];
    return $map[$size] ?? '14px';
}

/**
 * แปลงค่า margin/padding ของ Flex เป็น px
 *
 * @param string $value
 * @return string
 */
function fg_preview_spacing(string $value): string
{
    $map = ['none' => '0', 'xs' => '2px', 'sm' => '4px', 'md' => '8px', 'lg' => '12px', 'xl' => '16px', 'xxl' => '20px'];
    if (isset($map[$value])) {
        return $map[$value];
    }
    return preg_match('/^\d+px$/', $value) ? $value : '0';
}

/**
 * render node ของ Flex เป็น HTML (รองรับ box / text / image / filler / separator)
 *
 * @param array<string,mixed> $node
 * @param bool $isChild อยู่ใน box แนวนอนที่ต้องใช้ flex-grow หรือไม่
 * @return string
 */
function fg_preview_node(array $node, bool $isChild = false): string
{
    $type = (string)($node['type'] ?? '');

    if ($type === 'filler') {
        return '<div style="flex:1"></div>';
    }

    if ($type === 'separator') {
        $color = (string)($node['color'] ?? '#e0e0e0');
        $margin = isset($node['margin']) ? fg_preview_spacing((string)$node['margin']) : '0';
        return '<div style="border-top:1px solid ' . htmlspecialchars($color) . ';margin-top:' . $margin . '"></div>';
    }

    if ($type === 'image') {
        $style = 'width:100%;display:block;';
        if (($node['aspectMode'] ?? '') === 'fit') {
            $style .= 'object-fit:contain;';
        }
        if (($node['aspectRatio'] ?? '') === '1:1') {
            $style .= 'aspect-ratio:1/1;';
        }
        return '<img src="' . htmlspecialchars((string)$node['url'], ENT_QUOTES, 'UTF-8') . '" style="' . $style . '" alt=""'
            . ' onerror="this.replaceWith(Object.assign(document.createElement(\'div\'),{className:\'img-fallback\',textContent:\'IMG\'}))">';
    }

    if ($type === 'text') {
        $style = 'font-size:' . fg_preview_font_size((string)($node['size'] ?? 'md')) . ';';
        $style .= 'color:' . htmlspecialchars((string)($node['color'] ?? '#111111')) . ';';
        if (($node['weight'] ?? '') === 'bold') {
            $style .= 'font-weight:700;';
        }
        if (isset($node['align'])) {
            $style .= 'text-align:' . ($node['align'] === 'end' ? 'right' : ($node['align'] === 'center' ? 'center' : 'left')) . ';';
        }
        if (isset($node['margin'])) {
            $style .= 'margin-top:' . fg_preview_spacing((string)$node['margin']) . ';';
        }
        $style .= !empty($node['wrap'])
            ? 'white-space:normal;word-break:break-word;'
            : 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        if ($isChild && isset($node['flex'])) {
            $style .= (int)$node['flex'] === 0 ? 'flex:0 0 auto;' : 'flex:' . (int)$node['flex'] . ' 1 0;min-width:0;';
        }
        $style .= 'line-height:1.35;';

        $label = nl2br(htmlspecialchars((string)$node['text'], ENT_QUOTES, 'UTF-8'));

        if (($node['action']['type'] ?? '') === 'uri') {
            return '<a href="' . htmlspecialchars((string)$node['action']['uri'], ENT_QUOTES, 'UTF-8') . '" target="_blank"'
                . ' style="' . $style . 'text-decoration:none;display:block;">' . $label . '</a>';
        }
        return '<div style="' . $style . '">' . $label . '</div>';
    }

    if ($type !== 'box') {
        return '';
    }

    $horizontal = ($node['layout'] ?? '') === 'horizontal';
    $style = 'display:flex;flex-direction:' . ($horizontal ? 'row' : 'column') . ';';
    if ($horizontal) {
        // LINE ให้ box ลูกยืดเต็มความสูงเป็นค่าเริ่มต้น และไม่มีระยะห่างจนกว่าจะระบุ spacing
        $style .= 'align-items:stretch;';
        if (isset($node['spacing'])) {
            $style .= 'gap:' . fg_preview_spacing((string)$node['spacing']) . ';';
        }
    }
    foreach ([
        'backgroundColor' => 'background',
        'cornerRadius'    => 'border-radius',
        'height'          => 'height',
        'width'           => 'width',
        'justifyContent'  => 'justify-content',
        'alignItems'      => 'align-items',
    ] as $key => $css) {
        if (isset($node[$key])) {
            $style .= $css . ':' . htmlspecialchars((string)$node[$key]) . ';';
        }
    }
    if (isset($node['paddingAll'])) {
        $style .= 'padding:' . fg_preview_spacing((string)$node['paddingAll']) . ';';
    }
    if (isset($node['paddingStart'])) {
        $style .= 'padding-left:' . fg_preview_spacing((string)$node['paddingStart']) . ';';
    }
    if (isset($node['margin'])) {
        $style .= 'margin-top:' . fg_preview_spacing((string)$node['margin']) . ';';
    }
    if ($isChild) {
        if (isset($node['flex']) && (int)$node['flex'] === 0) {
            $style .= 'flex:0 0 auto;';
        } elseif (isset($node['flex'])) {
            $style .= 'flex:' . (int)$node['flex'] . ' 1 0;min-width:0;';
        } else {
            $style .= 'flex:1 1 0;min-width:0;';
        }
    }

    $inner = '';
    foreach (($node['contents'] ?? []) as $child) {
        if (is_array($child)) {
            $inner .= fg_preview_node($child, $horizontal);
        }
    }

    return '<div style="' . $style . '">' . $inner . '</div>';
}

/**
 * แตก message เป็นรายการ bubble
 *
 * @param array<string,mixed> $message
 * @return array<int,array<string,mixed>>
 */
function fg_preview_bubbles(array $message): array
{
    $contents = $message['contents'];
    return ($contents['type'] ?? '') === 'carousel' ? $contents['contents'] : [$contents];
}

$totalBubbles = 0;
$maxMessageBytes = 0;
foreach ($messages as $message) {
    $totalBubbles += count(fg_preview_bubbles($message));
    $bytes = strlen(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $maxMessageBytes = max($maxMessageBytes, $bytes);
}
$allJson = $messages !== []
    ? json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตัวอย่าง LINE Flex — สินค้าที่ต้องผลิตเพิ่ม</title>
    <style>
        body { background: #7494c0; font-family: -apple-system, "Segoe UI", "Sarabun", sans-serif; margin: 0; padding: 20px; }
        .panel { background: #fff; border-radius: 12px; padding: 18px; margin-bottom: 16px; }
        .stats { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
        .stat { flex: 1 1 150px; border: 1px solid #e2e2e2; border-radius: 8px; padding: 10px; text-align: center; }
        .stat .label { color: #777; font-size: 12px; }
        .stat .value { font-size: 24px; font-weight: 700; }
        .chat-area { padding: 16px 0; }
        .carousel-strip { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 12px; align-items: flex-start; }
        .bubble { width: 300px; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.25); flex: 0 0 auto; }
        pre.json { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 8px; max-height: 460px; overflow: auto; font-size: 12px; }
        .img-fallback { width: 100%; aspect-ratio: 1/1; display: flex; align-items: center; justify-content: center; background: #e4e4e4; color: #9a9a9a; font-size: 10px; font-weight: 700; border-radius: 4px; }
        .err { background: #fdecea; border: 1px solid #f5c6c2; color: #a3231a; border-radius: 8px; padding: 12px; }
        .ok { background: #eaf7ee; border: 1px solid #bfe3c9; color: #1e6b33; border-radius: 8px; padding: 12px; }
    </style>
</head>
<body>

<div class="panel">
    <strong>ตัวอย่าง LINE Flex — สินค้าที่ต้องผลิตเพิ่ม</strong>
    <div style="color:#777;font-size:12px;margin-top:4px;">
        ข้อมูลจาก <code><?= htmlspecialchars(finishgood_shortage_api_config()['url']) ?></code>
    </div>

    <?php if (!$fetched['ok']): ?>
        <div class="err" style="margin-top:12px;">ดึงข้อมูลไม่สำเร็จ: <?= htmlspecialchars($fetched['error']) ?></div>
    <?php elseif ($messages === []): ?>
        <div class="ok" style="margin-top:12px;">ไม่มีรุ่นที่ต้องผลิตเพิ่ม — ไม่ต้องส่งแจ้งเตือน</div>
    <?php else: ?>
        <div class="stats">
            <div class="stat">
                <div class="label">รุ่นที่ติดลบ</div>
                <div class="value" style="color:#c0392b;"><?= count($fetched['items']) ?></div>
            </div>
            <div class="stat">
                <div class="label">ขาดรวม (เครื่อง)</div>
                <div class="value" style="color:#c0392b;"><?= number_format(abs($fetched['total_shortage'])) ?></div>
            </div>
            <div class="stat">
                <div class="label">ข้อความ / การ์ด</div>
                <div class="value"><?= count($messages) ?> / <?= $totalBubbles ?></div>
            </div>
            <div class="stat">
                <div class="label">ข้อความที่ใหญ่สุด</div>
                <div class="value" style="color:<?= $maxMessageBytes > 50000 ? '#c0392b' : '#1e6b33' ?>;">
                    <?= number_format($maxMessageBytes / 1024, 1) ?> KB
                </div>
                <div class="label">ลิมิต LINE 50 KB</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php foreach ($messages as $index => $message): ?>
    <?php $bytes = strlen(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>
    <div class="panel" style="margin-bottom:8px;">
        <strong>ข้อความที่ <?= $index + 1 ?></strong>
        <span style="float:right;color:<?= $bytes > 50000 ? '#c0392b' : '#1e6b33' ?>;font-weight:700;">
            <?= number_format($bytes / 1024, 1) ?> KB
        </span>
        <div style="color:#777;font-size:12px;margin-top:4px;">altText: <?= htmlspecialchars($message['altText']) ?></div>
    </div>
    <div class="chat-area">
        <div class="carousel-strip">
            <?php foreach (fg_preview_bubbles($message) as $bubble): ?>
                <div class="bubble">
                    <?php foreach (['header', 'body', 'footer'] as $section): ?>
                        <?php if (isset($bubble[$section])) { echo fg_preview_node($bubble[$section]); } ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($allJson !== ''): ?>
    <div class="panel">
        <strong>Flex JSON — array ของ message</strong>
        <pre class="json"><?= htmlspecialchars($allJson) ?></pre>
    </div>
<?php endif; ?>

</body>
</html>
