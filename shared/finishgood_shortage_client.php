<?php
/**
 * shared/finishgood_shortage_client.php — ดึงรายการสินค้าที่ต้องผลิตเพิ่มจากระบบ Setup
 *
 * วัตถุประสงค์: ให้ production ได้ตัวเลขจากแหล่งเดียว (setupsystem) แทนการมีสูตรคำนวณซ้ำ
 *              สูตร (คงเหลือ + ระบบเช่า) − (ขั้นต่ำ + PO) อยู่ที่ setupsystem
 *              ยกเว้นรุ่นที่เลือกให้นับยอดคงเหลือจากทะเบียนเครื่องของเรา — ดู finishgood_shortage_registry.php
 *
 * ตั้งค่าใน line.secrets.php:
 *   'finishgood_shortage_api_url'   => 'https://bit-online.net/setupsystem/api/finishgood_shortage.php',
 *   'finishgood_shortage_api_token' => 'xxxx',
 *
 * Flow: finishgood_shortage_fetch() → ['ok'=>bool, 'items'=>[], 'timestamp_text'=>string, 'error'=>string]
 */

require_once __DIR__ . '/line_notify_core.php';

/** @var string URL ปลายทางเริ่มต้นเมื่อไม่ได้ตั้งใน secrets */
const FINISHGOOD_SHORTAGE_API_DEFAULT_URL = 'https://bit-online.net/setupsystem/api/finishgood_shortage.php';

/** @var int timeout การเรียก API (วินาที) — คำนวณ stock ทั้งหมดใช้เวลาพอสมควร */
const FINISHGOOD_SHORTAGE_API_TIMEOUT = 60;

/**
 * ค่า config ของ API (url + token)
 *
 * @return array{url:string,token:string}
 */
function finishgood_shortage_api_config(): array
{
    $cfg = line_notify_config();

    $url = trim((string)($cfg['finishgood_shortage_api_url'] ?? ''));
    if ($url === '') {
        $url = FINISHGOOD_SHORTAGE_API_DEFAULT_URL;
    }

    return [
        'url'   => $url,
        'token' => trim((string)($cfg['finishgood_shortage_api_token'] ?? '')),
    ];
}

/**
 * ดึงรายการสินค้าที่ต้องผลิตเพิ่ม
 *
 * ส่ง token ผ่าน header ไม่ใช่ query string — กัน token ติดใน access log ของ web server
 *
 * @return array{ok:bool,items:array<int,array<string,mixed>>,timestamp_text:string,total_shortage:int,error:string}
 */
function finishgood_shortage_fetch(): array
{
    $fail = static function (string $error): array {
        return ['ok' => false, 'items' => [], 'timestamp_text' => '', 'total_shortage' => 0, 'error' => $error];
    };

    $cfg = finishgood_shortage_api_config();
    if ($cfg['token'] === '') {
        return $fail('ยังไม่ได้ตั้ง finishgood_shortage_api_token ใน line.secrets.php');
    }
    if (!function_exists('curl_init')) {
        return $fail('ไม่มี ext-curl');
    }

    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => FINISHGOOD_SHORTAGE_API_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['X-Api-Token: ' . $cfg['token'], 'Accept: application/json'],
    ]);
    // ใบรับรอง CA บน AppServ ใช้ตัวช่วยเดียวกับตอนส่ง LINE — ไม่งั้นเครื่อง dev เรียก API ไม่ผ่าน
    line_notify_apply_curl_ssl($ch);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return $fail('เรียก API ไม่สำเร็จ: ' . $curlError);
    }
    if ($status !== 200) {
        return $fail('API ตอบ HTTP ' . $status);
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return $fail('JSON ที่ได้อ่านไม่ออก');
    }
    if (empty($data['ok'])) {
        return $fail((string)($data['error'] ?? 'API ตอบ ok=false'));
    }

    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

    return [
        'ok'             => true,
        'items'          => array_values($items),
        'timestamp_text' => (string)($data['timestamp_text'] ?? (date('d/m/Y H:i') . ' น.')),
        'total_shortage' => (int)($data['total_shortage'] ?? 0),
        'error'          => '',
    ];
}
