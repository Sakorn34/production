<?php
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 3) . '/shared/line_flex_templates.php';

$db = line_notify_db();
$res = $db->query('SELECT payload_json FROM notification_outbox WHERE id=58');
$payload = json_decode((string)$res->fetch_assoc()['payload_json'], true);
$messages = line_flex_build_messages('stock.low_threshold', $payload);

function walk_uris($node, $path, &$bad) {
    if (!is_array($node)) return;
    if (($node['type'] ?? '') === 'image' && isset($node['action']['uri'])) {
        $u = (string)$node['action']['uri'];
        if (!preg_match('#^https://#i', $u)) {
            $bad[] = ['path' => $path, 'uri' => $u];
        }
    }
    foreach ($node as $k => $v) {
        if (is_array($v)) {
            walk_uris($v, $path . '/' . $k, $bad);
        }
    }
}

$bad = [];
foreach ($messages as $mi => $msg) {
    walk_uris($msg, 'messages[' . $mi . ']', $bad);
}
echo count($bad) . " bad image action uris\n";
foreach (array_slice($bad, 0, 15) as $b) {
    echo $b['path'] . ' => ' . $b['uri'] . "\n";
}

$items = line_notify_enrich_low_stock_items($payload['items']);
echo "\nSample links from DB:\n";
foreach ($items as $item) {
    $l = trim((string)($item['link_url'] ?? ''));
    if ($l !== '') {
        echo '- ' . $l . ' => action: ' . line_flex_action_uri($l) . "\n";
    }
}
