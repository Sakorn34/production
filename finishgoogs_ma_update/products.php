<?php
/**
 * products.php — redirect ไประบบหลังบ้าน (รวมการจัดการรุ่นแล้ว)
 */
require __DIR__ . '/config.php';
header('Location: ' . BASE_URL . '/settings.php', true, 302);
exit;
