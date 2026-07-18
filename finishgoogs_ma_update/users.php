<?php
/**
 * users.php — เลิกใช้ระบบผู้ใช้งานภายในแล้ว ส่งกลับหน้าหลัก
 */
require __DIR__ . '/config.php';
require_login();
flash_set('ระบบผู้ใช้งานภายในถูกปิดแล้ว — ใช้สิทธิ์จาก session profile', 'err');
header('Location: ' . BASE_URL . '/index.php');
exit;
