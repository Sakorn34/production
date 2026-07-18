<?php
/** notifications.php — คืน JSON รายการแจ้งเตือนสำหรับ popup */
require __DIR__ . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$items = [];

// (เดิมมีแจ้งเตือน MA ถัดไป + อะไหล่ต่ำกว่าขั้นต่ำ — ยกเลิกแล้ว
//  การแจ้งเตือนอะไหล่ครบกำหนดเปลี่ยนแสดงที่หน้าเครื่องและฟอร์มบันทึก MA แทน)

echo json_encode($items, JSON_UNESCAPED_UNICODE);
