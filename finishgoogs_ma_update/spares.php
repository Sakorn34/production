<?php
require __DIR__ . '/config.php';
require_login();
// โมดูลยืม-คืนเครื่องสำรองถูกยกเลิก (2026-07-11) — ใช้ระบบ MA จัดการเครื่องสำรองแทน
flash_set('การจัดการเครื่องสำรองย้ายไปอยู่ในระบบ MA แล้ว — ดูรายการเครื่องสำรองได้ที่หน้าบันทึก MA', 'err');
header('Location: ' . BASE_URL . '/ma.php?st=spare');
exit;
