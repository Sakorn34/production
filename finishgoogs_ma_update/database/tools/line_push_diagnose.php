<?php
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/config.php';

$db = line_notify_db();
$res = $db->query("SELECT id, event_key, status, attempts, last_error, created_at FROM notification_outbox ORDER BY id DESC LIMIT 5");
echo "=== Recent Outbox ===\n";
while ($r = $res->fetch_assoc()) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
$res2 = $db->query("SELECT id, event_key, http_status, error_message, created_at FROM notification_log ORDER BY id DESC LIMIT 5");
echo "=== Recent Log ===\n";
while ($r = $res2->fetch_assoc()) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

// Direct push test
$messages = line_flex_build_messages('line.test', ['message' => 'ทดสอบส่ง LINE — ' . date('d/m/Y H:i:s')]);
$recipient = line_notify_recipient('line.test');
$push = line_notify_push($recipient, $messages);
echo "=== Direct Push ===\n";
echo json_encode(['ok' => $push['ok'], 'http_status' => $push['http_status'] ?? null, 'error' => $push['error'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";
