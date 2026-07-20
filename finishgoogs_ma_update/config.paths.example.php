<?php
/**
 * config.paths.example.php — ตัวอย่าง path สำหรับ server (คัดลอกเป็น config.paths.php)
 *
 * หรือตั้งค่าผ่านหน้า server_config.php ในระบบหลังบ้าน
 */
return [
    // path ไฟล์ secrets (ควรอยู่นอก web root)
    'finishgoogs_secrets'      => 'D:/AppServ/secrets/production/finishgoogs.secrets.php',
    'parts_secrets'            => 'D:/AppServ/secrets/production/parts.secrets.php',
    'line_secrets'             => 'D:/AppServ/secrets/production/line.secrets.php',
    'error_log'                => 'D:/Ops/logs/php-error.log',

    // SSO
    'sso_production_login_url' => 'https://bit-online.net/bitlogin/bitlink.php',
    'sso_parts_login_url'      => 'https://bit-online.net/bitlogin/index.php',

    // URL สาธารณะของ uploads (ว่าง = ใช้ /production/finishgoogs_ma_update/uploads/)
    'uploads_public_base'      => '',
];
