<?php
/**
 * healthz.php — ตรวจว่าระบบยังทำงานได้อยู่ไหม
 *
 * แบ่งฐานข้อมูลเป็น 2 กลุ่ม เพราะความร้ายแรงไม่เท่ากัน
 *
 *   จำเป็น (3 ฐาน)   production, stockparts, tech_parts — ล่มแล้วระบบใช้งานไม่ได้ → ตอบ 500
 *   ไม่จำเป็น (3 ฐาน) maintenance, setup, leasing — ของทีมอื่น ล่มแล้วแค่บางส่วนของหน้าหายไป
 *                     ระบบยังใช้งานได้ตามปกติ → ยังตอบ 200 แต่บอกไว้ในบรรทัดที่สอง
 *
 * ถ้าตอบ 500 เพราะฐานที่ไม่จำเป็น จะกลายเป็นปลุกคนขึ้นมาแก้ทั้งที่ระบบไม่ได้พัง
 *
 * รูปแบบคำตอบ (บรรทัดแรกคงรูปเดิมไว้ ไม่ให้ตัวมอนิเตอร์ที่ตั้งไว้แล้วพัง)
 *   ok                        ปกติทั้งหมด
 *   ok\ndegraded:leasing      ใช้งานได้ แต่ฐานเสริมบางตัวต่อไม่ติด
 *   db_error:stockparts       ฐานที่จำเป็นล่ม (HTTP 500)
 */
require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

/** ฐานที่ขาดไม่ได้ — ต่อไม่ได้เมื่อไหร่ถือว่าระบบล่ม */
$required = [
    'production' => 'db',
    'stockparts' => 'dbStock',
    'techparts'  => 'dbParts',
];

/** ฐานอ่านอย่างเดียวของทีมอื่น — ตัวเชื่อมคืน null แทนที่จะ throw เมื่อต่อไม่ได้ */
$optional = [
    'maintenance' => 'dbMaintenance',
    'setup'       => 'dbSetup',
    'leasing'     => 'dbLeasing',
];

$down     = [];
$degraded = [];

foreach ($required as $name => $fn) {
    try {
        $conn = $fn();
        if (!$conn) {
            throw new RuntimeException('ตัวเชื่อมคืนค่าว่าง');
        }
        $conn->query('SELECT 1');
    } catch (Throwable $e) {
        $down[] = $name;
        error_log('[healthz] ' . $name . ' DB failed: ' . $e->getMessage());
    }
}

foreach ($optional as $name => $fn) {
    try {
        $conn = $fn();
        if (!$conn) {
            throw new RuntimeException('ตัวเชื่อมคืนค่าว่าง');
        }
        $conn->query('SELECT 1');
    } catch (Throwable $e) {
        $degraded[] = $name;
        error_log('[healthz] ' . $name . ' DB unavailable (ไม่กระทบการใช้งานหลัก): ' . $e->getMessage());
    }
}

if ($down) {
    http_response_code(500);
    echo 'db_error:' . implode(',', $down);
    if ($degraded) {
        echo "\ndegraded:" . implode(',', $degraded);
    }
    exit;
}

http_response_code(200);
echo 'ok';
if ($degraded) {
    echo "\ndegraded:" . implode(',', $degraded);
}
