<?php
/**
 * database/tools/line_debug_outbox.php — replay outbox row และแสดง error จาก LINE API
 */
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 3) . '/shared/line_flex_templates.php';

$id = isset($argv[1]) ? (int)$argv[1] : 0;
$db = line_notify_db();
$res = $db->query('SELECT * FROM notification_outbox ORDER BY id DESC LIMIT 1');
$row = $res ? $res->fetch_assoc() : null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM notification_outbox WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$row) {
    echo "no row\n";
    exit(1);
}

$payload = json_decode((string)$row['payload_json'], true);
$messages = line_flex_build_messages((string)$row['event_key'], is_array($payload) ? $payload : []);
echo 'outbox #' . $row['id'] . ' event=' . $row['event_key'] . ' messages=' . count($messages) . "\n";

$json = json_encode(['to' => (string)$row['recipient_id'], 'messages' => $messages], JSON_UNESCAPED_UNICODE);
echo 'payload bytes: ' . strlen((string)$json) . "\n";

$push = line_notify_push((string)$row['recipient_id'], array_slice($messages, 0, 1));
echo json_encode($push, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
