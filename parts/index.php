<?php

/**
 * index.php — Dashboard ของ parts ถูกแทนที่ด้วย Dashboard รวม 2 ระบบ
 * (ตกลง 2026-07-21) — redirect ไปที่ระบบทะเบียนเครื่องซึ่งแสดงข้อมูลสต็อกอะไหล่ครบแล้ว
 * ตารางสต็อกคงเหลือดูได้ที่ pages/products.php
 */

require_once __DIR__ . '/config/database.php';
require_once dirname(__DIR__) . '/shared/ui_icons.php';

header('Location: ' . ui_finishgoogs_app_url(), true, 302);
exit;
