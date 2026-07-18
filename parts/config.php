<?php
require_once 'D:/AppServ/secrets/production/parts.secrets.php';
// ชี้ error_log ออกนอก web root กันไฟล์ log หลุดออกเว็บได้
ini_set('error_log', 'D:/Ops/logs/php-error.log');
