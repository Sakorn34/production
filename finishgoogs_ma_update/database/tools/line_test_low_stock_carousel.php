<?php
/**
 * database/tools/line_test_low_stock_carousel.php — ทดสอบ carousel low stock >20 รายการ
 */
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 3) . '/shared/line_flex_templates.php';

$items = [];
for ($i = 1; $i <= 25; $i++) {
    $items[] = ['name' => 'Part ' . $i, 'quantity' => 1, 'min_stock' => 10];
}

$msgs = line_flex_low_stock_alert_messages(['items' => $items, 'timestamp' => date('d/m/Y H:i')]);
echo '25 items: ' . count($msgs) . " message(s)\n";
echo '  type: ' . ($msgs[0]['contents']['type'] ?? '?') . "\n";
echo '  bubbles: ' . count($msgs[0]['contents']['contents'] ?? []) . "\n";

$msgs15 = line_flex_low_stock_alert_messages(['items' => array_slice($items, 0, 15), 'timestamp' => date('d/m/Y H:i')]);
echo '15 items: type=' . ($msgs15[0]['contents']['type'] ?? '?') . "\n";

if (($msgs[0]['contents']['type'] ?? '') !== 'carousel' || count($msgs[0]['contents']['contents'] ?? []) !== 2) {
    fwrite(STDERR, "FAIL: expected 1 carousel with 2 bubbles\n");
    exit(1);
}
if (($msgs15[0]['contents']['type'] ?? '') !== 'bubble') {
    fwrite(STDERR, "FAIL: expected single bubble for 15 items\n");
    exit(1);
}

$lastBubble = $msgs[0]['contents']['contents'][1] ?? [];
$headerTexts = [];
foreach (($lastBubble['header']['contents'] ?? []) as $c) {
    if (($c['type'] ?? '') === 'text') {
        $headerTexts[] = (string)($c['text'] ?? '');
    }
}
if (in_array('เลื่อนดูการ์ดถัดไป →', $headerTexts, true)) {
    fwrite(STDERR, "FAIL: last carousel card must not show swipe hint\n");
    exit(1);
}
$firstBubble = $msgs[0]['contents']['contents'][0] ?? [];
$firstTexts = [];
foreach (($firstBubble['header']['contents'] ?? []) as $c) {
    if (($c['type'] ?? '') === 'text') {
        $firstTexts[] = (string)($c['text'] ?? '');
    }
}
if (!in_array('เลื่อนดูการ์ดถัดไป →', $firstTexts, true)) {
    fwrite(STDERR, "FAIL: first carousel card should show swipe hint\n");
    exit(1);
}

echo "OK\n";
