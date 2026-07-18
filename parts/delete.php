<?php
// 🔒 รหัสจริงอยู่ฝั่งเซิร์ฟเวอร์
require_once 'D:/AppServ/secrets/production/parts.secrets.php';
$DELETE_PASSWORD = $DELETE_PASSWORD_LEGACY;

// ใช้ isset() แทน ?? สำหรับ compatibility กับ PHP เก่า
$domain = isset($_GET['domain']) ? $_GET['domain'] : '';
$password = isset($_GET['pass']) ? $_GET['pass'] : '';

if ($password !== $DELETE_PASSWORD) {
    header("Location: index.php?error=wrong_password");
    exit;
}

// --- โค้ดลบ DNS ของคุณตรงนี้ ---

header("Location: index.php?success=deleted&site=" . urlencode($domain));
exit;
