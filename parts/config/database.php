<?php

// XAMPP: โปรเจกตอยู่ที่ http://localhost/parts
define('BASE_PATH', '/parts');

require_once 'D:/AppServ/secrets/production/parts.secrets.php';
// ชี้ error_log ออกนอก web root กันไฟล์ log หลุดออกเว็บได้ (บางจุด require ไฟล์นี้ตรงๆ ไม่ผ่าน config.php)
ini_set('error_log', 'D:/Ops/logs/php-error.log');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
    }
    return $pdo;
}
