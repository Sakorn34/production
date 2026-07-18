<?php
/** report.php — รายงานการผลิตถูกรวมเข้าหน้า Dashboard แล้ว (คง URL เดิมไว้ให้ redirect) */
require __DIR__ . '/config.php';
header('Location: ' . BASE_URL . '/index.php');
exit;
