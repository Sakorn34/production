<?php
/**
 * includes/inventory_db.php — เชื่อมต่อระบบ inventory (biton_inventory) แบบอ่านอย่างเดียว
 *
 * ใช้ credentials ชุดเดียวกับ finishgoogs (finishgoogs.secrets.php key inventory)
 * fail-soft คืน null แทน die — หน้า parts ต้องทำงานต่อได้แม้ต่อ inventory ไม่ได้
 */

/**
 * คืน mysqli ไป biton_inventory หรือ null
 *
 * @return mysqli|null
 */
function parts_inventory_db(): ?mysqli
{
    static $db = false;
    static $error = '';
    if ($db !== false) {
        $GLOBALS['_partsInventoryDbError'] = $error;
        return $db;
    }
    $db = null;
    $error = '';
    try {
        if (!function_exists('app_finishgoogs_secrets_path')) {
            require_once dirname(__DIR__, 2) . '/shared/app_paths.php';
        }
        $c = require app_finishgoogs_secrets_path();
        $cfg = is_array($c) ? ($c['inventory'] ?? null) : null;
        if (!is_array($cfg)) {
            $error = 'ยังไม่ได้ตั้งค่า inventory ใน finishgoogs.secrets.php';
            $GLOBALS['_partsInventoryDbError'] = $error;
            return null;
        }
        $host = trim((string) ($cfg['host'] ?? ''));
        $user = trim((string) ($cfg['user'] ?? ''));
        $pass = (string) ($cfg['pass'] ?? '');
        $name = trim((string) ($cfg['db'] ?? ''));
        if ($host === '' || $name === '' || $user === '') {
            $error = 'ค่าเชื่อมต่อ inventory ไม่ครบ (host / db / user)';
            $GLOBALS['_partsInventoryDbError'] = $error;
            return null;
        }
        $mysqli = mysqli_init();
        if (!$mysqli) {
            $error = 'สร้างการเชื่อมต่อ inventory ไม่สำเร็จ';
            $GLOBALS['_partsInventoryDbError'] = $error;
            return null;
        }
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        if (!@$mysqli->real_connect($host, $user, $pass, $name)) {
            $error = 'เชื่อมต่อฐาน inventory ไม่ได้';
            $GLOBALS['_partsInventoryDbError'] = $error;
            return null;
        }
        $mysqli->set_charset('utf8mb4');
        $db = $mysqli;
    } catch (Throwable $e) {
        error_log('[parts_inventory_db] ' . $e->getMessage());
        $error = 'เชื่อมต่อ inventory ไม่สำเร็จ';
        $db = null;
    }
    $GLOBALS['_partsInventoryDbError'] = $error;
    return $db;
}

/**
 * ข้อความ error ล่าสุดของ parts_inventory_db()
 *
 * @return string
 */
function parts_inventory_db_error(): string
{
    parts_inventory_db();
    return (string) ($GLOBALS['_partsInventoryDbError'] ?? '');
}
