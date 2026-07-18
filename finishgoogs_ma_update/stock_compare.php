<?php
/**
 * stock_compare.php — หน้านี้ถูกรวมเข้ากับ share.php แล้ว (ตกลง 2026-07-13)
 * ข้อมูลรายการสินค้าเก็บที่ biton_stockparts.stock ที่เดียว — จัดการ/ฟิลเตอร์ข้อมูลไม่ครบได้ที่ share.php
 */
require __DIR__ . '/config.php';
header('Location: ' . BASE_URL . '/share.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
