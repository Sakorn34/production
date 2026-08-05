<?php
/**
 * pages/stock-in.php — ย้ายไปรวมที่อื่นแล้ว (2026-08-05)
 *
 * ฟอร์มบันทึกรับเข้า → modal ที่ products.php
 * ประวัติรับเข้า      → history.php?tab=in
 *
 * เก็บไฟล์นี้ไว้เป็น redirect เพราะระบบไม่มี front controller — ทุกไฟล์ใน pages/
 * ถูกเรียกตรงจาก URL ลบทิ้งแล้ว bookmark และ history ของเบราว์เซอร์จะ 404
 */

require_once __DIR__ . '/../includes/parts_bootstrap.php';

$editIn = isset($_GET['edit_in']) ? (int) $_GET['edit_in'] : 0;
redirect(url('/pages/history.php?tab=in' . ($editIn > 0 ? '&edit_in=' . $editIn : '')));
