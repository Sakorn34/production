<?php
/**
 * includes/maintenance_db.php — เชื่อมต่อระบบงานซ่อม (biton_maintenance) แบบอ่านอย่างเดียว
 *
 * ใช้ credentials ชุดเดียวกับ finishgoogs (finishgoogs.secrets.php key maintenance)
 * fail-soft — หน้า KPO ต้องทำงานต่อได้แม้ต่อ maintenance ไม่ได้
 */

/**
 * คืน mysqli ไป biton_maintenance หรือ null
 *
 * @return mysqli|null
 */
function parts_maintenance_db(): ?mysqli
{
    static $db = false;
    static $error = '';
    if ($db !== false) {
        $GLOBALS['_partsMaintenanceDbError'] = $error;
        return $db;
    }
    $db = null;
    $error = '';
    try {
        if (!function_exists('app_finishgoogs_secrets_path')) {
            require_once dirname(__DIR__, 2) . '/shared/app_paths.php';
        }
        $c = require app_finishgoogs_secrets_path();
        $cfg = is_array($c) ? ($c['maintenance'] ?? null) : null;
        if (!is_array($cfg)) {
            $error = 'ยังไม่ได้ตั้งค่า maintenance ใน finishgoogs.secrets.php';
            $GLOBALS['_partsMaintenanceDbError'] = $error;
            return null;
        }
        $host = trim((string) ($cfg['host'] ?? ''));
        $user = trim((string) ($cfg['user'] ?? ''));
        $pass = (string) ($cfg['pass'] ?? '');
        $name = trim((string) ($cfg['db'] ?? ''));
        if ($host === '' || $name === '' || $user === '') {
            $error = 'ค่าเชื่อมต่อ maintenance ไม่ครบ (host / db / user)';
            $GLOBALS['_partsMaintenanceDbError'] = $error;
            return null;
        }
        $mysqli = mysqli_init();
        if (!$mysqli) {
            $error = 'สร้างการเชื่อมต่อ maintenance ไม่สำเร็จ';
            $GLOBALS['_partsMaintenanceDbError'] = $error;
            return null;
        }
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        if (!@$mysqli->real_connect($host, $user, $pass, $name)) {
            $error = 'เชื่อมต่อฐาน maintenance ไม่ได้';
            $GLOBALS['_partsMaintenanceDbError'] = $error;
            return null;
        }
        $mysqli->set_charset('utf8mb4');
        $db = $mysqli;
    } catch (Throwable $e) {
        error_log('[parts_maintenance_db] ' . $e->getMessage());
        $error = 'เชื่อมต่อ maintenance ไม่สำเร็จ';
        $db = null;
    }
    $GLOBALS['_partsMaintenanceDbError'] = $error;
    return $db;
}
