<?php
/**
 * webhook-stockout.php — ปิดใช้งานแล้ว (เลิกใช้ webhook — เชื่อม DB โดยตรงจาก finishgoogs)
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'error'   => 'Webhook stockout ถูกปิดใช้งาน — ใช้การเชื่อม biton_tech_parts โดยตรงจาก finishgoogs_ma_update แทน',
], JSON_UNESCAPED_UNICODE);
