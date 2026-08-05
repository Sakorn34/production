<?php
/**
 * pages/sets.php — ย้ายไปรวมกับหน้าเบิกออก Set แล้ว (2026-07-31)
 *
 * เดิมหน้านี้กับ stock-out.php แสดงรายการ Set ซ้ำกันทั้งคู่ จึงรวมเป็นหน้าเดียว
 * คงไฟล์นี้ไว้เพื่อ redirect ลิงก์/บุ๊กมาร์กเดิม
 */
require_once __DIR__ . '/../includes/parts_bootstrap.php';
redirect(url('/pages/stock-out.php'));
