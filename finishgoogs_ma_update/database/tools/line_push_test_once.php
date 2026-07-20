<?php
require dirname(__DIR__, 2) . '/config.php';
$ca = line_notify_curl_ca_path();
echo "CA path: " . ($ca ?? 'NULL') . "\n";
echo "CA exists: " . ($ca && is_file($ca) ? 'yes' : 'no') . "\n";

$cfg = line_notify_config();
$token = $cfg['channel_access_token'];
$recipient = $cfg['default_recipient_id'];

$ch = curl_init('https://api.line.me/v2/bot/message/push');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'to' => $recipient,
        'messages' => [['type' => 'text', 'text' => 'ทดสอบส่ง LINE — ' . date('d/m/Y H:i:s')]],
    ]),
    CURLOPT_TIMEOUT => 30,
]);
line_notify_apply_curl_ssl($ch);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP: {$http}\n";
echo "curl error: {$err}\n";
if ($resp !== false) {
    $d = json_decode($resp, true);
    echo "response message: " . ($d['message'] ?? 'OK') . "\n";
    echo "ok: " . ($http >= 200 && $http < 300 ? 'YES' : 'NO') . "\n";
}
