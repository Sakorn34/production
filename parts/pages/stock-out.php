<?php
/**
 * pages/stock-out.php — ย้ายไปรวมที่อื่นแล้ว (2026-08-05)
 *
 * ฟอร์มเบิกออก Set → modal ที่ products.php
 * ประวัติเบิก Set   → history.php?tab=set
 * จัดการ Set        → sets.php (กลับไปใช้ชื่อเดิมของตัวเอง)
 *
 * ไฟล์นี้เคยรวม "เบิกออก Set + จัดการ Set" ไว้ด้วยกันเมื่อ 2026-07-31 เพราะตอนนั้น
 * stock-out.php กับ sets.php render รายการ Set ซ้ำกัน พอการเบิกออกย้ายไป products.php
 * เหตุผลนั้นก็หมดไป และชื่อ stock-out.php ก็ไม่ตรงกับเนื้อหาอีกต่อไป จึงสลับกลับ
 *
 * เก็บไว้เป็น redirect ให้ bookmark เดิมใช้ได้ (ดูเหตุผลใน stock-in.php)
 */

require_once __DIR__ . '/../includes/parts_bootstrap.php';

$editOut = isset($_GET['edit_out']) ? (int) $_GET['edit_out'] : 0;
if ($editOut > 0) {
    redirect(url('/pages/history.php?tab=set&edit_out=' . $editOut));
}
redirect(url('/pages/sets.php'));
