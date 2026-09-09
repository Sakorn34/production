<?php
/**
 * config.php — แกนกลางของระบบ: DB, session, สิทธิ์, helper
 */
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
require_once dirname(__DIR__) . '/shared/app_paths.php';
ini_set('error_log', app_error_log_path());

/**
 * คืน BASE_URL จาก SCRIPT_NAME — รองรับ localhost, LAN IP, และ production
 *
 * @return string
 */
function app_base_url() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/finishgoogs_ma_update';
    $pos = strpos($script, $marker);
    if ($pos !== false) {
        $cached = substr($script, 0, $pos + strlen($marker));
        return $cached;
    }
    $cached = '/production';
    return $cached;
}

define('BASE_URL', app_base_url());
define('APP_NAME', 'ระบบทะเบียนเครื่องและซ่อมบำรุง');
/** รหัสชุด deploy — อัปเมื่อ build patch แล้วเทียบกับ server ว่าอัปครบหรือยัง */
define('APP_RELEASE_VERSION', '2026-09-09_171532');

/**
 * โหลด secrets แบบ cache ต่อ request
 *
 * @return array<string,mixed>
 */
function db_secrets() {
    static $c = null;
    if ($c === null) {
        $path = app_finishgoogs_secrets_path();
        if (!is_file($path)) {
            die('ไม่พบไฟล์ secrets: ' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8')
                . ' — ตั้งค่าที่หลังบ้าน → ตั้งค่า Server / Deploy');
        }
        $c = require $path;
        if (!is_array($c)) {
            die('ไฟล์ secrets รูปแบบไม่ถูกต้อง: ' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
        }
    }
    return $c;
}

/**
 * ตรวจว่า request มาจาก localhost dev หรือไม่
 *
 * @return bool
 */
function is_localhost_request() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $localIp = in_array($ip, ['127.0.0.1', '::1'], true);
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $localHost = (bool)preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    $cached = $localIp && $localHost;
    return $cached;
}

function dbStock()
{
    static $db;

    if (!$db) {

        $c = db_secrets();

        $db = new mysqli(
            $c['stockparts']['host'],
            $c['stockparts']['user'],
            $c['stockparts']['pass'],
            $c['stockparts']['db']
        );
        if ($db->connect_errno) die('เชื่อมต่อฐานข้อมูล stockparts ไม่ได้: ' . $db->connect_error);
        $db->set_charset('utf8mb4');
        // บังคับ autocommit ให้เปิดเสมอ — กันเคสที่ฝั่ง MySQL/user account ถูกตั้ง autocommit=0 ไว้
        // ซึ่งจะทำให้ query ดูเหมือนสำเร็จ (ไม่ error) แต่ข้อมูลไม่ถูก commit จริง แล้วหายไปเงียบๆ ตอน connection ปิด
        $db->autocommit(true);
    }

    return $db;
}

/**
 * PDO ไปยัง biton_tech_parts — สต็อกอะไหล่จริง (single source of truth)
 *
 * @return PDO
 */
function dbParts() {
    static $pdo = null;
    if ($pdo === null) {
        $c = db_secrets();
        if (empty($c['techparts'])) {
            die('ไม่พบการตั้งค่า techparts ใน finishgoogs.secrets.php');
        }
        $tp = $c['techparts'];
        $host = (string)$tp['host'];
        $dbName = (string)$tp['db'];
        $dsn = 'mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)$tp['user'], (string)$tp['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    return $pdo;
}

/**
 * เชื่อมต่อฐานระบบเช่า (biton_leasing) — fail-soft ไม่ die
 *
 * @return mysqli|null
 */
function dbLeasing()
{
    static $db = false;
    static $error = '';
    if ($db !== false) {
        $GLOBALS['_dbLeasingError'] = $error;
        return $db;
    }
    $db = null;
    $c = db_secrets();
    if (empty($c['leasing']) || !is_array($c['leasing'])) {
        $error = 'ยังไม่ได้ตั้งค่า leasing ใน finishgoogs.secrets.php';
        $GLOBALS['_dbLeasingError'] = $error;
        return null;
    }
    $cfg = $c['leasing'];
    $host = trim((string)($cfg['host'] ?? ''));
    $user = trim((string)($cfg['user'] ?? ''));
    $pass = (string)($cfg['pass'] ?? '');
    $name = trim((string)($cfg['db'] ?? ''));
    if ($host === '' || $name === '' || $user === '') {
        $error = 'ค่าเชื่อมต่อระบบเช่าไม่ครบ (host / db / user)';
        $GLOBALS['_dbLeasingError'] = $error;
        return null;
    }
    $mysqli = mysqli_init();
    if (!$mysqli) {
        $error = 'สร้างการเชื่อมต่อระบบเช่าไม่สำเร็จ';
        $GLOBALS['_dbLeasingError'] = $error;
        return null;
    }
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (!@$mysqli->real_connect($host, $user, $pass, $name)) {
        $error = 'เชื่อมต่อฐานระบบเช่าไม่ได้';
        $GLOBALS['_dbLeasingError'] = $error;
        return null;
    }
    $mysqli->set_charset('utf8mb4');
    $mysqli->autocommit(true);
    $db = $mysqli;
    $error = '';
    $GLOBALS['_dbLeasingError'] = '';
    return $db;
}

/**
 * ข้อความ error ล่าสุดของ dbLeasing()
 *
 * @return string
 */
function dbLeasingError()
{
    if (dbLeasing() !== null) {
        return '';
    }
    return (string)($GLOBALS['_dbLeasingError'] ?? 'เชื่อมต่อฐานระบบเช่าไม่ได้');
}
/**
 * เชื่อมต่อฐานระบบซ่อม (biton_maintenance) — อ่านอย่างเดียว fail-soft ไม่ die
 *
 * ระบบซ่อมเป็นคนละแอปคนละ MySQL user เราแตะโค้ดฝั่งนั้นไม่ได้ตามที่ตกลงไว้
 * จึงอ่านจากฐานของมันตรง ๆ ถ้าต่อไม่ได้ต้องคืน null เฉย ๆ ไม่ใช่ล้มทั้งหน้า
 * เพราะหน้าโปรไฟล์เครื่องมีเรื่องอื่นให้แสดงอีกมาก
 *
 * @return mysqli|null
 */
function dbMaintenance()
{
    static $db = false;
    static $error = '';
    if ($db !== false) {
        $GLOBALS['_dbMaintenanceError'] = $error;
        return $db;
    }
    $db = null;
    $c = db_secrets();
    if (empty($c['maintenance']) || !is_array($c['maintenance'])) {
        $error = 'ยังไม่ได้ตั้งค่า maintenance ใน finishgoogs.secrets.php';
        $GLOBALS['_dbMaintenanceError'] = $error;
        return null;
    }
    $cfg = $c['maintenance'];
    $host = trim((string)($cfg['host'] ?? ''));
    $user = trim((string)($cfg['user'] ?? ''));
    $pass = (string)($cfg['pass'] ?? '');
    $name = trim((string)($cfg['db'] ?? ''));
    if ($host === '' || $name === '' || $user === '') {
        $error = 'ค่าเชื่อมต่อระบบซ่อมไม่ครบ (host / db / user)';
        $GLOBALS['_dbMaintenanceError'] = $error;
        return null;
    }
    $mysqli = mysqli_init();
    if (!$mysqli) {
        $error = 'สร้างการเชื่อมต่อระบบซ่อมไม่สำเร็จ';
        $GLOBALS['_dbMaintenanceError'] = $error;
        return null;
    }
    // 3 วินาทีคือเพดานที่กันหน้าค้างถ้าเครื่องปลายทางไม่ตอบ
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (!@$mysqli->real_connect($host, $user, $pass, $name)) {
        $error = 'เชื่อมต่อฐานระบบซ่อมไม่ได้';
        $GLOBALS['_dbMaintenanceError'] = $error;
        return null;
    }
    $mysqli->set_charset('utf8mb4');
    $db = $mysqli;
    $error = '';
    $GLOBALS['_dbMaintenanceError'] = '';
    return $db;
}

/**
 * ข้อความ error ล่าสุดของ dbMaintenance()
 *
 * @return string
 */
function dbMaintenanceError()
{
    if (dbMaintenance() !== null) {
        return '';
    }
    return (string)($GLOBALS['_dbMaintenanceError'] ?? 'เชื่อมต่อฐานระบบซ่อมไม่ได้');
}

/**
 * เชื่อมต่อฐานระบบ setup/ขาย (biton_setup) — อ่านอย่างเดียว fail-soft ไม่ die
 *
 * เป็นฐานของระบบ setupsystem (bit-online.net/setupsystem) ที่เก็บประวัติเคลม/ขาย
 * แยกชิ้นและการส่งมอบ Order เราไม่ได้เป็นเจ้าของและไม่แก้อะไรในนั้น ใช้เพื่อให้
 * ช่องค้นหาหาเจอเท่านั้น · ต่อไม่ได้ต้องคืน null เฉย ๆ ไม่ใช่ทำให้ช่องค้นหาพังทั้งอัน
 *
 * @return mysqli|null
 */
function dbSetup()
{
    static $db = false;
    static $error = '';
    if ($db !== false) {
        $GLOBALS['_dbSetupError'] = $error;
        return $db;
    }
    $db = null;
    $c = db_secrets();
    if (empty($c['setup']) || !is_array($c['setup'])) {
        $error = 'ยังไม่ได้ตั้งค่า setup ใน finishgoogs.secrets.php';
        $GLOBALS['_dbSetupError'] = $error;
        return null;
    }
    $cfg = $c['setup'];
    $host = trim((string)($cfg['host'] ?? ''));
    $user = trim((string)($cfg['user'] ?? ''));
    $pass = (string)($cfg['pass'] ?? '');
    $name = trim((string)($cfg['db'] ?? ''));
    if ($host === '' || $name === '' || $user === '') {
        $error = 'ค่าเชื่อมต่อฐาน setup ไม่ครบ (host / db / user)';
        $GLOBALS['_dbSetupError'] = $error;
        return null;
    }
    $mysqli = mysqli_init();
    if (!$mysqli) {
        $error = 'สร้างการเชื่อมต่อฐาน setup ไม่สำเร็จ';
        $GLOBALS['_dbSetupError'] = $error;
        return null;
    }
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    if (!@$mysqli->real_connect($host, $user, $pass, $name)) {
        $error = 'เชื่อมต่อฐาน setup ไม่ได้';
        $GLOBALS['_dbSetupError'] = $error;
        return null;
    }
    $mysqli->set_charset('utf8mb4');
    $db = $mysqli;
    $error = '';
    $GLOBALS['_dbSetupError'] = '';
    return $db;
}

/**
 * ข้อความ error ล่าสุดของ dbSetup()
 *
 * @return string
 */
function dbSetupError()
{
    if (dbSetup() !== null) {
        return '';
    }
    return (string)($GLOBALS['_dbSetupError'] ?? 'เชื่อมต่อฐาน setup ไม่ได้');
}

function db() {
    static $db = null;
    if ($db === null) {
        $c = db_secrets();

        $db = new mysqli(
            $c['production']['host'],
            $c['production']['user'],
            $c['production']['pass'],
            $c['production']['db']
        );
        
        if ($db->connect_errno) die('เชื่อมต่อฐานข้อมูล production ไม่ได้: ' . $db->connect_error);
        $db->set_charset('utf8mb4');
        // เดิมไม่มีการบังคับ autocommit เลย — ถ้า user account (biton_production) ถูกตั้ง autocommit=0
        // ไว้ที่ฝั่ง server (และในโค้ดทั้งระบบไม่มีการเรียก commit() ที่ไหนเลย) การ insert ทุกอย่างจะ
        // "สำเร็จ" ในสายตา PHP แต่จริงๆ อยู่ในธุรกรรมที่ไม่เคย commit แล้วถูก rollback ทิ้งเงียบๆ ตอนจบ request
        // — ตรงกับอาการที่ assets.php ไม่มีข้อมูลใหม่เพิ่มเข้ามาทั้งที่ขึ้น "บันทึกสำเร็จ" ทุกครั้ง
        $db->autocommit(true);
    }
    return $db;
}

/** prepared query — คืน mysqli_stmt */
function q($sql, $types = '', $params = [], $conn = null)
{
    if ($conn === null) {
        $conn = db();
    }

    $st = $conn->prepare($sql);

    if ($st === false) {
        die('SQL error: ' . $conn->error);
    }

    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }

    if (!$st->execute()) {
        // เดิมไม่ตรวจผล execute — บางเคส (เช่น FK / duplicate) ดู "ผ่าน" จากฝั่ง PHP แล้ว flash สำเร็จ
        // ทั้งที่แถวไม่ถูกเขียนจริง
        die('SQL execute error: ' . $st->error . ' | SQL: ' . $sql);
    }

    return $st;
}

/**
 * execute SQL แบบไม่ die — คืน ['ok'=>bool, 'error'?, 'errno'?, 'insert_id'?]
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return array{ok:bool, error?:string, errno?:int, insert_id?:int, stmt?:mysqli_stmt}
 */
function q_try($sql, $types = '', $params = []) {
    $conn = db();
    $st = $conn->prepare($sql);
    if ($st === false) {
        return ['ok' => false, 'error' => $conn->error, 'errno' => (int)$conn->errno];
    }
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    if (!$st->execute()) {
        return ['ok' => false, 'error' => $st->error, 'errno' => (int)$st->errno];
    }
    return ['ok' => true, 'stmt' => $st, 'insert_id' => (int)$conn->insert_id];
}

/**
 * คำนวณสถานะเครื่องจากประวัติ MA ที่เหลือ — ถ้าไม่มี MA เหลือ กลับเป็น new
 *
 * ใช้เฉพาะ MA ที่ระบุสถานะเครื่องไว้จริงเท่านั้น เรคคอร์ดเก่าที่ machine_status
 * ยังว่าง (บันทึกก่อนมีคอลัมน์นี้) ถือว่า "ไม่มีข้อมูล" จึงคงสถานะเดิมของเครื่องไว้
 * ไม่เดาแทนผู้ใช้ — ก่อนหน้านี้ค่าว่างถูก fallback เป็น rental ทำให้เครื่องสำรอง
 * ถูกเปลี่ยนเป็นเครื่องเช่าเองเมื่อมีการลบ MA
 *
 * @param int $assetId
 * @return void
 */
function recompute_asset_status_from_ma(int $assetId): void
{
    $assetId = (int) $assetId;
    if ($assetId <= 0) {
        return;
    }
    // ไม่เหลือ MA เลย = ถอยกลับไปก่อนเคยเข้า MA จึงกลับเป็น new
    // ยืนยันกับเจ้าของระบบแล้ว (2026-08-05) ว่าต้องการแบบนี้ แม้เครื่องจะเคยถูก
    // ตั้งเป็น spare ด้วยมือแล้วสถานะนั้นหายไปก็ตาม — อย่าเปลี่ยนโดยไม่ถามก่อน
    $any = qr('SELECT id FROM ma_records WHERE asset_id=? LIMIT 1', 'i', [$assetId])->fetch_assoc();
    if (!$any) {
        q("UPDATE assets SET status='new' WHERE id=?", 'i', [$assetId]);
        asset_status_sync_one($assetId, false);
        return;
    }
    $row = qr(
        "SELECT machine_status FROM ma_records
         WHERE asset_id=? AND machine_status IN ('rental','spare')
         ORDER BY visited_at DESC, id DESC LIMIT 1",
        'i',
        [$assetId]
    )->fetch_assoc();
    if (!$row) {
        return; // ยังมี MA อยู่แต่ไม่มีรอบไหนระบุสถานะ — ไม่แตะสถานะเดิม
    }
    q('UPDATE assets SET status=? WHERE id=?', 'si', [(string) $row['machine_status'], $assetId]);
    asset_status_sync_one($assetId, false);
}

/**
 * เติมค่าที่ยังไม่มีใน ENUM assets.status (ครั้งเดียวต่อ request)
 *
 * ต้องรันก่อนเขียนสถานะเสมอ — ค่าที่ไม่อยู่ใน ENUM จะถูก MySQL ปัดเป็นค่าว่าง
 * (หรือ error ถ้าเปิด strict mode) เงียบ ๆ ไม่มีอะไรฟ้อง
 *
 * @return void
 */
function ensure_asset_status_sold_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $res = db()->query("SHOW COLUMNS FROM assets LIKE 'status'");
        if ($res && ($row = $res->fetch_assoc())) {
            $type = (string) ($row['Type'] ?? '');
            $missing = false;
            foreach (['sold', 'retired', 'lost'] as $val) {
                if (stripos($type, "'" . $val . "'") === false) {
                    $missing = true;
                    break;
                }
            }
            if ($missing) {
                db()->query(
                    "ALTER TABLE assets MODIFY status"
                    . " ENUM('new','rental','spare','sold','retired','lost') NOT NULL DEFAULT 'new'"
                );
            }
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('[ensure_asset_status_sold_schema] ' . $e->getMessage());
    }
}

/**
 * คำนำหน้า reason ของแถว stock_movements ที่มาจากการ sync สถานะ
 *
 * การ sync ไม่ได้ขยับเครื่องเข้า/ออกคลังจริง แค่คำนวณป้ายสถานะใหม่ แต่มันเขียนลง
 * ตารางเดียวกับการเคลื่อนไหวจริง (11,022 จาก 13,142 แถวเป็นของ sync) timeline จึงต้องมี
 * ทางแยกแถวสองชนิดนี้ออกจากกัน — อยู่ใน config.php เพราะทั้งฝั่งเขียน (asset_status_sync.php)
 * และฝั่งอ่าน (timeline.php) ต้องใช้ค่าเดียวกัน และ config โหลดก่อนทั้งคู่เสมอ
 *
 * @return string
 */
function asset_status_sync_log_prefix(): string
{
    return 'Sync สถานะ: ';
}

/**
 * sync สถานะเครื่องเดียวจากระบบเช่า/stock (โหลด helper อัตโนมัติ)
 *
 * @param int  $assetId
 * @param bool $write   false = dry-run
 * @return array{changed:bool,from:string,to:string,reason:string}
 */
function asset_status_sync_one(int $assetId, bool $write = true): array
{
    ensure_asset_status_sold_schema();
    $assetId = (int) $assetId;
    if ($assetId <= 0) {
        return ['changed' => false, 'from' => '', 'to' => '', 'reason' => 'invalid id'];
    }
    require_once __DIR__ . '/includes/asset_status_sync.php';
    return asset_status_sync_by_id($assetId, $write);
}

/**
 * คำนวณ current_fw_version จาก production_records, update_logs และ ma_records
 * — ใช้แหล่งที่ใหม่กว่า
 *
 * ไล่ตรวจจากแหล่งที่มีน้ำหนักน้อยสุดไปมากสุด (ผลิต → อัปเดต → MA) เพราะเงื่อนไข
 * เทียบเวลาใช้ >= แหล่งที่ตรวจทีหลังจึงชนะเมื่อเวลาเท่ากัน
 *
 * @param int $assetId
 * @return void
 */
function recompute_asset_fw(int $assetId): void
{
    $assetId = (int) $assetId;
    if ($assetId <= 0) {
        return;
    }
    $bestFw = null;
    $bestTs = 0;

    // FW ที่บันทึกตอนผลิต — เป็นค่าตั้งต้นของเครื่องที่ยังไม่เคยอัปเดตหรือเข้า MA
    $pr = qr(
        "SELECT fw_version, recorded_at FROM production_records
         WHERE asset_id=? AND fw_version IS NOT NULL AND TRIM(fw_version)<>''
         ORDER BY recorded_at DESC, id DESC LIMIT 1",
        'i',
        [$assetId]
    )->fetch_assoc();
    if ($pr) {
        $ts = strtotime((string) $pr['recorded_at']) ?: 0;
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $bestFw = (string) $pr['fw_version'];
        }
    }

    $ul = qr(
        "SELECT new_value, updated_at FROM update_logs
         WHERE asset_id=? AND update_type='firmware' AND new_value IS NOT NULL AND TRIM(new_value)<>''
         ORDER BY updated_at DESC, id DESC LIMIT 1",
        'i',
        [$assetId]
    )->fetch_assoc();
    if ($ul) {
        $ts = strtotime((string) $ul['updated_at']) ?: 0;
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $bestFw = (string) $ul['new_value'];
        }
    }

    $ma = qr(
        "SELECT fw_version, visited_at FROM ma_records
         WHERE asset_id=? AND fw_version IS NOT NULL AND TRIM(fw_version)<>''
         ORDER BY visited_at DESC, id DESC LIMIT 1",
        'i',
        [$assetId]
    )->fetch_assoc();
    if ($ma) {
        $ts = strtotime((string) $ma['visited_at']) ?: 0;
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $bestFw = (string) $ma['fw_version'];
        }
    }

    q('UPDATE assets SET current_fw_version=? WHERE id=?', 'si', [$bestFw, $assetId]);
}

/**
 * เช็คว่า update_logs เป็นรายการล่าสุดของประเภทนั้นหรือไม่
 *
 * @param int         $logId
 * @param int         $assetId
 * @param string      $type
 * @param string|null $componentName
 * @return bool
 */
function is_latest_update_log(int $logId, int $assetId, string $type, ?string $componentName = null): bool
{
    if ($type === 'hardware' && $componentName !== null && $componentName !== '') {
        $latest = qr(
            "SELECT id FROM update_logs WHERE asset_id=? AND update_type='hardware' AND component_name=?
             ORDER BY updated_at DESC, id DESC LIMIT 1",
            'is',
            [$assetId, $componentName]
        )->fetch_assoc();
    } else {
        $latest = qr(
            'SELECT id FROM update_logs WHERE asset_id=? AND update_type=? ORDER BY updated_at DESC, id DESC LIMIT 1',
            'is',
            [$assetId, $type]
        )->fetch_assoc();
    }
    return $latest && (int) $latest['id'] === $logId;
}

/**
 * ล็อกการจัดสรรเลขรอบ MA ต่อเครื่อง (GET_LOCK) — คืน round ถัดไปเมื่อได้ lock
 *
 * @param int $assetId
 * @return array{ok:bool, round?:int, lock_key?:string, error?:string}
 */
function ma_round_lock_acquire(int $assetId): array
{
    $assetId = (int) $assetId;
    if ($assetId <= 0) {
        return ['ok' => false, 'error' => 'ไม่พบเครื่อง'];
    }
    $lockKey = 'ma_round_' . $assetId;
    $lock = qr('SELECT GET_LOCK(?,10) l', 's', [$lockKey])->fetch_assoc();
    if (!$lock || (int) $lock['l'] !== 1) {
        return ['ok' => false, 'error' => 'ระบบกำลังบันทึก MA ให้เครื่องนี้อยู่ กรุณาลองใหม่'];
    }
    $r = qr('SELECT COALESCE(MAX(ma_round),0)+1 r FROM ma_records WHERE asset_id=?', 'i', [$assetId])->fetch_assoc();
    return ['ok' => true, 'round' => (int) ($r['r'] ?? 1), 'lock_key' => $lockKey];
}

/**
 * ปล่อย lock การจัดสรรเลขรอบ MA
 *
 * @param string $lockKey
 * @return void
 */
function ma_round_lock_release(string $lockKey): void
{
    if ($lockKey === '') {
        return;
    }
    qr('SELECT RELEASE_LOCK(?)', 's', [$lockKey]);
}

/**
 * แปลง errno MySQL เป็นข้อความที่ผู้ใช้เข้าใจ
 *
 * @param int    $errno
 * @param string $error
 * @return string
 */
function db_error_user_message($errno, $error) {
    if ((int)$errno === 1062) {
        if (preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/", $error, $m)) {
            $val = $m[1];
            if (strpos($m[2], 'asset_code') !== false) {
                return 'รหัสเครื่อง "' . $val . '" มีในระบบแล้ว — ไม่สามารถบันทึกซ้ำได้';
            }
            return 'ข้อมูล "' . $val . '" ซ้ำในระบบ';
        }
        return 'ข้อมูลซ้ำในระบบ — กรุณาตรวจสอบรหัสเครื่อง';
    }
    return 'บันทึกไม่สำเร็จ — กรุณาลองใหม่';
}

function qr($sql, $types = '', $params = [], $conn = null)
{
    return q($sql, $types, $params, $conn)->get_result();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- ตั้งค่าระบบ / ปรับแต่งหน้าตา ----------
function settings() {
    static $s = null;
    if ($s === null) {
        $s = [];
        $res = @db()->query("SELECT skey, sval FROM site_settings");
        if ($res) while ($r = $res->fetch_assoc()) $s[$r['skey']] = $r['sval'];
    }
    return $s;
}
function setting($k, $default = null) {
    $s = settings();
    return (isset($s[$k]) && $s[$k] !== '') ? $s[$k] : $default;
}
function set_setting($k, $v) {
    q("INSERT INTO site_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)", 'ss', [$k, (string)$v]);
}
/** สีธีม (ตรวจ hex) — คืนค่าปลอดภัยสำหรับใส่ใน CSS */
function theme_color($k, $default) {
    $v = (string)setting($k, $default);
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $default;
}
/** เมนูเริ่มต้น: [file, icon(emoji), label, ผู้มีสิทธิ์เห็น] — ไม่จำกัด role แล้ว (ใช้แค่ session profile) */
function nav_default() {
    return [
        ['index.php',      'dashboard', 'หน้าหลัก',        true],
        ['assets.php',     'assets',    'ทะเบียนเครื่องผลิตใหม่', true],
        ['updates.php',    'updates',   'อัปเดต FW/HW',      true],
        ['ma.php',         'ma',        'บันทึก MA',         true],
        ['parts.php',      'parts',     'อะไหล่',            true],
        ['repairs.php',    'repairs',   'ประวัติซ่อม',        true],
        ['scan.php',       'scan',      'สแกน QR',           true],
        ['settings.php',   'settings',  'ระบบหลังบ้าน',      true],
    ];
}
/** คืน icon key มาตรฐานตามไฟล์เมนู */
function nav_icon_for_file(string $file): string {
    foreach (nav_default() as $n) {
        if ($n[0] === $file) {
            return $n[1];
        }
    }
    return 'dashboard';
}

/** คืน label มาตรฐานตามไฟล์เมนู (ตาม mockup v2) */
function nav_label_for_file(string $file): string {
    foreach (nav_default() as $n) {
        if ($n[0] === $file) {
            return $n[2];
        }
    }
    return $file;
}

/** รวมเมนูเริ่มต้น + override (order/hidden) → เมนูที่ใช้จริง — icon/label มาตรฐาน v2 */
function nav_effective() {
    $ovr = json_decode((string)setting('nav_items', '[]'), true);
    if (!is_array($ovr)) $ovr = [];
    $byFile = [];
    foreach ($ovr as $o) if (isset($o['file'])) $byFile[$o['file']] = $o;
    $out = [];
    foreach (nav_default() as $i => $n) {
        if (!$n[3]) continue;
        // ระบบหลังบ้าน — แสดงเป็นไอคónข้างชื่อผู้ใช้ใน userbox ไม่ใส่ในรายการเมนู
        if ($n[0] === 'settings.php') continue;
        $o = isset($byFile[$n[0]]) ? $byFile[$n[0]] : [];
        if (!empty($o['hidden'])) continue;
        $out[] = [
            'file'  => $n[0],
            'icon'  => nav_icon_for_file($n[0]),
            'label' => nav_label_for_file($n[0]),
            'order' => isset($o['order']) ? (int)$o['order'] : $i,
        ];
    }
    usort($out, function ($a, $b) { return $a['order'] - $b['order']; });
    return $out;
}

/**
 * เปอร์เซ็นต์ขยายขนาดตัวอักษร (90–140) จาก appearance.php
 *
 * @return int
 */
function theme_font_scale_percent(): int {
    $p = (int) setting('font_scale_percent', 100);
    return max(90, min(140, $p));
}

/**
 * CSS variables ขนาดตัวอักษรตาม font_scale_percent
 *
 * @return string
 */
function theme_font_css_sizes(): string {
    $s = theme_font_scale_percent() / 100;
    $sf = rtrim(rtrim(sprintf('%.4f', $s), '0'), '.');
    return sprintf(
        '--font-scale:%s; --fs-body:calc(15px * var(--font-scale)); --fs-h1:calc(22px * var(--font-scale)); --fs-table:calc(13.5px * var(--font-scale)); --fs-badge:calc(12px * var(--font-scale));',
        $sf
    );
}

/**
 * ค่า font-size สำหรับ inline style — คูณตาม --font-scale จาก appearance.php
 *
 * @param float $px ขนาดฐานเมื่อ scale = 100%
 * @return string เช่น calc(13px * var(--font-scale, 1))
 */
function theme_fs_css(float $px): string {
    $px = max(1, $px);
    return 'calc(' . $px . 'px * var(--font-scale, 1))';
}

/** ชื่อเดือนย่อภาษาไทย จาก Y-m */
function thai_month_short(string $ym): string {
    static $names = [
        '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
        '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
        '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.',
    ];
    $parts = explode('-', $ym);
    if (count($parts) !== 2) {
        return $ym;
    }
    return ($names[$parts[1]] ?? $parts[1]);
}

/**
 * ป้ายช่วงเดือนสำหรับหัวข้อ modal / รายงาน — เช่น "ก.ค. 2568"
 *
 * @param string $ym รูปแบบ Y-m
 * @return string
 */
function thai_month_period_label(string $ym): string {
    $parts = explode('-', $ym);
    if (count($parts) !== 2) {
        return $ym;
    }
    return thai_month_short($ym) . ' ' . ((int)$parts[0] + 543);
}

/** แปลงปี ค.ศ. เป็น พ.ศ. สำหรับแสดงผล */
function thai_buddhist_year(int $year): int {
    return $year + 543;
}

// ---------- auth (session profile เท่านั้น — ไม่ใช้ตาราง users / role / login ภายใน) ----------
define('SSO_LOGIN_URL', app_sso_production_login_url());

require_once __DIR__ . '/includes/session_profile.php';
require_once dirname(__DIR__) . '/shared/ui_icons.php';

/**
 * Dev localhost — auto-login เป็น Tom แทน SSO (ห้ามใช้บน production)
 *
 * @return void
 */
function ss_dev_localhost_bootstrap() {
    if (!is_localhost_request() || ss_session_has_profile()) {
        return;
    }
    $_SESSION['profile'] = (object)[
        'login_name'   => 'Tom',
        'display_name' => 'Tom',
    ];
}
ss_dev_localhost_bootstrap();

/**
 * คืนข้อมูลผู้ใช้จาก `$_SESSION['profile']` (ไม่พึ่งตาราง users)
 *
 * @return array{id:null,username:string,display_name:string,role:string}|null
 */
function user() {
    if (!ss_session_has_profile()) {
        return null;
    }
    ss_sync_session_employee_from_profile();
    $uname = (string)(maintenance_new_profile_login_name() ?: '');
    $dname = maintenance_new_profile_display_name();
    if ($dname === '') {
        $dname = $uname !== '' ? $uname : 'ผู้ใช้งาน';
    }
    // id = null เพราะเลิกผูก FK กับตาราง users — คอลัมน์ user_id/created_by ที่เป็น NULL ได้จะเก็บ null
    return [
        'id'           => null,
        'username'     => $uname,
        'display_name' => $dname,
        'role'         => 'user',
    ];
}

/**
 * ชื่อที่ใช้บันทึกรายการในระบบ = login_name จาก SSO (เช่น Tom)
 * ถ้าไม่มี login_name ค่อยใช้ display_name
 *
 * @return string
 */
function actor_name() {
    $u = user();
    if (!$u) {
        return '';
    }
    if ($u['username'] !== '') {
        return $u['username'];
    }
    return (string)$u['display_name'];
}

/** @var int อายุการปลดล็อกหน้าหลังบ้าน (วินาที) — ครบแล้วต้องใส่ PIN ใหม่ */
if (!defined('SETTINGS_UNLOCK_TTL')) {
    define('SETTINGS_UNLOCK_TTL', 1800);
}

/**
 * ตรวจว่าเข้าหน้าหลังบ้านได้แล้วหรือยัง (PIN 9981 · หมดอายุตาม SETTINGS_UNLOCK_TTL)
 *
 * @return bool
 */
function settings_admin_unlocked() {
    if (!user()) {
        return false;
    }
    if (is_localhost_request() && strcasecmp(maintenance_new_profile_login_name() ?: '', 'Tom') === 0) {
        return true;
    }
    $since = (int)($_SESSION['settings_unlocked'] ?? 0);
    if ($since <= 0) {
        return false;
    }
    // หมดอายุใน 30 นาที — กันเคสเดินจากเครื่องโดยไม่ปิดเบราว์เซอร์แล้วคนถัดไปเข้าหลังบ้านต่อ
    if (time() - $since > SETTINGS_UNLOCK_TTL) {
        unset($_SESSION['settings_unlocked']);
        return false;
    }
    return true;
}

/**
 * บังคับให้มี session profile จาก SSO ก่อนใช้งานระบบ
 *
 * @return void
 */
function require_login() {
    if (!ss_session_has_profile()) {
        if (is_localhost_request()) {
            ss_dev_localhost_bootstrap();
        }
        if (!ss_session_has_profile()) {
            header('Location: ' . (is_localhost_request() ? BASE_URL . '/login.php' : SSO_LOGIN_URL));
            exit;
        }
    }
    ss_sync_session_employee_from_profile();
    ensure_field_input_mode_schema();
    ensure_asset_serial_identity();
    ensure_ma_datetime_schema();
    // ล้าง cache ของระบบ role/users เก่าใน session ถ้ายังค้างอยู่
    unset($_SESSION['user']);
}

/**
 * ตรวจสิทธิ์ — เปิดให้ทุกคนที่มี profile ใช้ได้ทุกส่วน (ไม่จำกัด role)
 *
 * @param string $perm ชื่อสิทธิ์เดิม (เก็บพารามิเตอร์ไว้ให้เรียกเดิมได้)
 * @return bool
 */
function can($perm) {
    return ss_session_has_profile();
}

/**
 * บังคับล็อกอินด้วย session profile (ไม่เช็ค role)
 *
 * @param string $perm ชื่อสิทธิ์เดิม (ไม่ใช้ตัดสินใจแล้ว)
 * @return void
 */
function require_can($perm) {
    require_login();
}

// ---------- csrf ----------
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_check() {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== csrf()) exit('คำขอไม่ถูกต้อง (CSRF)');
}

// ---------- flash message ----------
function flash_set($msg, $type = 'ok', $logSummary = null) {
    $_SESSION['flash'] = [$msg, $type];
    if ($logSummary !== null) {
        if (!function_exists('activity_log_write')) {
            require_once dirname(__DIR__) . '/shared/activity_log_core.php';
        }
        activity_log_write([
            'system_key' => 'production',
            'actor_name' => actor_name(),
            'action_key' => 'save:' . preg_replace('/\.php$/', '', basename((string)($_SERVER['SCRIPT_NAME'] ?? 'app'))),
            'summary'    => mb_substr((string)$logSummary, 0, 500) ?: mb_substr((string)$msg, 0, 500),
            'detail'     => activity_log_sanitize_post_detail(),
        ]);
    }
}
function flash_get() { $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : null; unset($_SESSION['flash']); return $f; }

// ---------- รูปภาพ ----------
function img_url($p) {
    if (!$p) return null;
    if (preg_match('#^https?://#', $p)) return $p; // ลิงก์ AppSheet เดิม
    // ใช้ app_uploads_public_base() ไม่ใช่ BASE_URL — BASE_URL เดามาจาก SCRIPT_NAME
    // ตอนรันผ่านเว็บได้ค่าถูก แต่ตอน cron รันแบบ CLI เดาไม่ได้แล้วตกไปที่ '/production'
    // ซึ่งขาดโฟลเดอร์แอป รูปในแจ้งเตือน LINE จึงกลายเป็น 404 ทั้งหมด
    $base = function_exists('app_uploads_public_base') ? app_uploads_public_base() : BASE_URL . '/uploads/';
    $legacyFolders = ['Update_Images/', 'Parts_Images/', 'Menu Product_Images/', 'model appsheet_Images/',
                      'model_Images/', 'NamePart_Images/', 'Sub Menu_Images/', 'Sub Product_Images/', 'Thumbnail_Images/'];
    foreach ($legacyFolders as $lf) {
        if (strpos($p, $lf) === 0) return $base . 'legacy/' . implode('/', array_map('rawurlencode', explode('/', $p)));
    }
    return $base . implode('/', array_map('rawurlencode', explode('/', $p)));
}
/** แสดงรูปหรือกล่อง placeholder */
function img_tag($path, $alt = '', $class = 'thumb') {
    $u = img_url($path);
    if ($u) return '<img src="' . h($u) . '" alt="' . h($alt) . '" class="' . $class . '" loading="lazy" onerror="this.classList.add(\'broken\')">';
    return '<span class="' . $class . ' noimg">ไม่มีรูป</span>';
}

/** รับไฟล์อัปโหลดรูป → คืน path relative ใต้ uploads/ หรือ null */
function save_upload($field, $subdir, $exts = null) {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    if ($exts === null) $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) return null;
    if ($ext === 'svg') {
        // อ่านทั้งไฟล์ ไม่ใช่แค่ 4096 ไบต์แรก — สคริปต์ซ่อนท้ายไฟล์ได้
        $svg = (string)@file_get_contents($_FILES[$field]['tmp_name']);
        if (stripos($svg, '<svg') === false) return null;
        // SVG รัน JavaScript ได้ใต้โดเมนของระบบ → ปฏิเสธไฟล์ที่มีสคริปต์/event handler/ลิงก์ภายนอก
        if (preg_match('/<\s*script|<\s*foreignObject|javascript\s*:|\son\w+\s*=|<\s*!ENTITY/i', $svg)) return null;
    } elseif ($ext === 'ico') {
        if (@file_get_contents($_FILES[$field]['tmp_name'], false, null, 0, 4) !== "\x00\x00\x01\x00") return null;
    } else {
        if (!@getimagesize($_FILES[$field]['tmp_name'])) return null;
    }
    $dir = __DIR__ . '/uploads/' . $subdir . '/' . date('Y/m');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$name")) return null;
    return $subdir . '/' . date('Y/m') . '/' . $name;
}

/**
 * รับไฟล์ font อัปโหลด → คืน path relative ใต้ uploads/fonts/
 *
 * @param string $field ชื่อ field ใน $_FILES
 * @return string|null
 */
function save_font_upload($field) {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $exts = ['woff', 'woff2', 'ttf', 'otf'];
    if (!in_array($ext, $exts, true)) {
        return null;
    }
    $dir = __DIR__ . '/uploads/fonts/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = date('His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$name")) {
        return null;
    }
    return 'fonts/' . date('Y/m') . '/' . $name;
}

/**
 * คืนค่า font-family CSS ตามการตั้งค่าหน้าตาระบบ
 *
 * @return array{font:string,google:string,custom_file:string}
 */
function theme_font_config() {
    $preset = setting('font_preset', 'noto');
    $custom = trim((string)setting('font_file', ''));
    $google = '';
    $family = "'Noto Sans Thai', 'Segoe UI', Tahoma, sans-serif";
    if ($custom !== '') {
        return ['font' => "'AppCustomFont', 'Noto Sans Thai', 'Segoe UI', sans-serif", 'google' => '', 'custom_file' => $custom];
    }
    $presets = [
        'system'  => ['font' => "'Noto Sans Thai', 'Segoe UI', Tahoma, sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap'],
        'saraban' => ['font' => "'Sarabun', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap'],
        'prompt'  => ['font' => "'Prompt', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap'],
        'kanit'   => ['font' => "'Kanit', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap'],
        'noto'    => ['font' => "'Noto Sans Thai', 'Segoe UI', sans-serif", 'google' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;600;700&display=swap'],
    ];
    if (isset($presets[$preset])) {
        return ['font' => $presets[$preset]['font'], 'google' => $presets[$preset]['google'], 'custom_file' => ''];
    }
    return ['font' => $family, 'google' => $google, 'custom_file' => ''];
}

// ---------- สถานะ ----------
// ชื่อและสีสถานะอยู่ที่ shared/ui_status_palette.php ที่เดียว — ทั้ง badge กราฟ
// และแถบสัดส่วนอ่านจากที่นั่น เพื่อไม่ให้สถานะเดียวมีคนละสีในหน้าเดียวกันอีก
require_once dirname(__DIR__) . '/shared/ui_status_palette.php';

function status_th($s) {
    $e = status_palette_entry((string) $s);
    return $e['th'];
}
function status_list() { return array_keys(status_palette()); }
function status_badge($s) {
    // คง class st-<key> ไว้ให้ของเดิมที่ hook สีจากคลาสนี้ยังทำงาน — สีจริงมาจาก
    // inline ซึ่งชนะคลาสอยู่แล้ว จึงไม่ต้องไล่แก้ทุกที่ที่เคยพิมพ์คลาสเอง
    return '<span class="badge st-' . h($s) . '" style="' . h(status_badge_style((string) $s)) . '">'
         . h(status_th($s)) . '</span>';
}
function role_th($r) {
    $m = ['admin' => 'ผู้ดูแลระบบ', 'qc' => 'ทีมผลิต/QC', 'technician' => 'ช่างเทคนิค', 'executive' => 'ผู้บริหาร'];
    return isset($m[$r]) ? $m[$r] : $r;
}
function dthai($d) { // แสดงวันที่ — มีเวลาเมื่อข้อมูลมีส่วนเวลา
    if (!function_exists('dt_display')) {
        require_once dirname(__DIR__) . '/shared/datetime_helpers.php';
    }
    return dt_display($d);
}
function dthai_full($d) {
    if (!function_exists('dt_display_full')) {
        require_once dirname(__DIR__) . '/shared/datetime_helpers.php';
    }
    return dt_display_full($d);
}

// ---------- แจ้งเตือนอะไหล่ครบกำหนดเปลี่ยน (SD Card / Battery Backup RTC) ----------

/**
 * รายการแจ้งเตือนที่ระบบรองรับ — key = ชื่อแสดง, value = alias สำหรับค้นใน MA
 *
 * @return array<string,array<int,string>>
 */
function part_watch_catalog() {
    return [
        'SD Card'            => ['SD Card', 'SD-card', 'SDcard'],
        'Battery Backup RTC' => ['Battery Backup RTC', 'Battery RTC', 'RTC Battery', 'CR2032'],
    ];
}

/**
 * ตรวจว่ารุ่นนี้ตั้งค่าแจ้งเตือนแล้วหรือยัง
 *
 * @param int $productId
 * @return bool
 */
function product_watch_alerts_configured($productId) {
    $productId = (int)$productId;
    if ($productId <= 0) {
        return false;
    }
    return (bool)qr(
        "SELECT 1 FROM product_field_config
         WHERE product_id=? AND context='production' AND field_kind='watch_alert_cfg' AND is_active=1 LIMIT 1",
        'i',
        [$productId]
    )->fetch_assoc();
}

/**
 * อ่านว่ารุ่นนี้เปิดแจ้งเตือนอะไรบ้าง — ยังไม่ตั้งค่า = เปิดทั้งหมด (backward compatible)
 *
 * @param int $productId
 * @return array<string,bool>
 */
function product_watch_alerts_enabled($productId) {
    $productId = (int)$productId;
    $catalog = part_watch_catalog();
    $enabled = [];
    if ($productId <= 0) {
        foreach (array_keys($catalog) as $k) {
            $enabled[$k] = true;
        }
        return $enabled;
    }
    if (!product_watch_alerts_configured($productId)) {
        foreach (array_keys($catalog) as $k) {
            $enabled[$k] = true;
        }
        return $enabled;
    }
    foreach (array_keys($catalog) as $k) {
        $enabled[$k] = false;
    }
    $res = qr(
        "SELECT field_name FROM product_field_config
         WHERE product_id=? AND context='production' AND field_kind='watch_alert' AND is_active=1",
        'i',
        [$productId]
    );
    while ($r = $res->fetch_assoc()) {
        $enabled[(string)$r['field_name']] = true;
    }
    return $enabled;
}

/**
 * รายการ Checklist หน้าบันทึกผลิต — จากหลังบ้าน หรือดึงจากประวัติล่าสุด
 *
 * @param int $productId
 * @return array<int,string>
 */
function effective_production_checklist($productId) {
    $productId = (int)$productId;
    if ($productId <= 0) {
        return [];
    }
    foreach (product_config_fields($productId, 'production') as $f) {
        if ($f['kind'] === 'checklist' && $f['options']) {
            return $f['options'];
        }
    }
    $last = qr(
        "SELECT pr.checklist FROM production_records pr
         JOIN assets a ON a.id=pr.asset_id
         WHERE a.product_id=? AND pr.checklist IS NOT NULL AND TRIM(pr.checklist)<>'' AND pr.checklist<>'-'
         ORDER BY pr.recorded_at DESC, pr.id DESC LIMIT 1",
        'i',
        [$productId]
    )->fetch_assoc();
    if (!$last || trim((string)$last['checklist']) === '') {
        return [];
    }
    $items = [];
    foreach (preg_split('/\s*,\s*/', (string)$last['checklist']) as $it) {
        $it = trim($it);
        if ($it !== '' && $it !== '-') {
            $items[] = $it;
        }
    }
    return $items;
}

/**
 * เกณฑ์: อายุใช้งาน ≥ 2 ปี = ถึงกำหนด, ≥ 1 ปี 10 เดือน = ใกล้ถึงกำหนด
 * เคยเปลี่ยนอะไหล่นั้น (จาก MA) → นับจากครั้งล่าสุด · ไม่เคย → นับจากวันผลิต
 * เช็คทั้งคอลัมน์ replace_items (ระบบใหม่) และ versions_json.Replace (ข้อมูลเก่า AppSheet)
 *
 * @param int         $assetId
 * @param string|null $producedAt
 * @param int|null    $productId ถ้ารู้ product_id จะกรองตามการตั้งค่ารุ่น
 * @return array<int,array{level:string,html:string}>
 */
function part_watch_alerts($assetId, $producedAt, $productId = null) {
    $assetId = (int)$assetId;
    if ($productId === null && $assetId > 0) {
        $row = qr('SELECT product_id FROM assets WHERE id=? LIMIT 1', 'i', [$assetId])->fetch_assoc();
        $productId = $row ? (int)$row['product_id'] : 0;
    }
    $enabled = product_watch_alerts_enabled((int)$productId);
    $watch = part_watch_catalog();
    $out = [];

    // วันเปลี่ยนอะไหล่จากงานซ่อมของระบบ MA — เครื่องที่เข้าศูนย์ซ่อมแล้วเปลี่ยนไป
    // ไม่ได้ถูกบันทึกใน ma_records ถ้าไม่นับด้วยจะเตือนซ้ำทั้งที่เพิ่งเปลี่ยน
    // fail-soft: ต่อฐานระบบซ่อมไม่ได้ก็ยังคำนวณจาก ma_records ได้ตามเดิม
    $repairDates = [];
    if ($assetId > 0) {
        $ac = qr('SELECT asset_code FROM assets WHERE id=? LIMIT 1', 'i', [$assetId])->fetch_assoc();
        $code = $ac ? trim((string)$ac['asset_code']) : '';
        if ($code !== '') {
            require_once __DIR__ . '/includes/maintenance_repair_bridge.php';
            try {
                $repairDates = asset_maintenance_part_replacements($code);
            } catch (Throwable $e) {
                error_log('[part_watch_alerts] repair lookup: ' . $e->getMessage());
            }
        }
    }
    foreach ($watch as $name => $pats) {
        if (empty($enabled[$name])) {
            continue;
        }
        $base = ($producedAt && $producedAt !== '0000-00-00') ? $producedAt : null;
        $conds = []; $types = 'i'; $params = [$assetId];
        foreach ($pats as $p) {
            $conds[] = 'replace_items LIKE ?';
            $conds[] = 'JSON_UNQUOTE(JSON_EXTRACT(versions_json, \'$.Replace\')) LIKE ?';
            $types .= 'ss'; $params[] = "%$p%"; $params[] = "%$p%";
        }
        $r = qr("SELECT MAX(visited_at) d FROM ma_records WHERE asset_id=? AND (" . implode(' OR ', $conds) . ")",
                $types, $params)->fetch_assoc();
        if ($r && $r['d']) $base = $r['d'];

        // งานซ่อมใหม่กว่าบันทึก MA ก็ให้ยึดวันซ่อม
        $fromRepair = false;
        if (!empty($repairDates[$name])) {
            $rd = (string)$repairDates[$name];
            if (!$base || strcmp(substr($rd, 0, 10), substr((string)$base, 0, 10)) > 0) {
                $base = $rd;
                $fromRepair = true;
            }
        }
        if (!$base) continue;
        try { $d1 = new DateTime($base); } catch (Exception $e) { continue; }
        $diff = $d1->diff(new DateTime('today'));
        $months = $diff->y * 12 + $diff->m;
        if ($months < 22) continue;
        $ageTxt = ($diff->y ? $diff->y . ' ปี ' : '') . $diff->m . ' เดือน';
        if ($fromRepair) {
            $fromTxt = 'นับจากงานซ่อม ' . dthai($base);
        } else {
            $fromTxt = ($base !== $producedAt) ? 'นับจากเปลี่ยนล่าสุด ' . dthai($base) : 'นับจากวันผลิต ' . dthai($base);
        }
        if ($months >= 24) $out[] = ['level' => 'danger', 'html' => "🔴 <b>ถึงกำหนดเปลี่ยน $name แล้ว</b> — ใช้งาน $ageTxt ($fromTxt)"];
        else               $out[] = ['level' => 'warn',   'html' => "🟠 <b>ใกล้ถึงกำหนดเปลี่ยน $name</b> — ใช้งาน $ageTxt ($fromTxt)"];
    }
    return $out;
}
/** แปลงผล part_watch_alerts เป็นกล่องแจ้งเตือน HTML */
function part_alerts_html($assetId, $producedAt) {
    $out = '';
    foreach (part_watch_alerts($assetId, $producedAt) as $al) {
        $st = $al['level'] === 'danger'
            ? 'background:#fbe4e4;color:#90312c;border:1px solid #f0bcbc'
            : 'background:#fdf3dd;color:#8a5f0b;border:1px solid #f0d9a0';
        $out .= '<div class="flash" style="' . $st . '">' . $al['html'] . '</div>';
    }
    return $out;
}

// ---------- sync ทะเบียนสินค้า biton_stockparts.stock ----------
// ข้อมูลรายการสินค้าเก็บที่ biton_stockparts.stock ที่เดียว (ตกลง 2026-07-13 — เลิกใช้ shared_assets แล้ว)
// เพิ่ม/แก้ไข/ลบเครื่องในระบบหลักแล้วสะท้อนไปตาราง stock อัตโนมัติ (ดู share.php)
// ตั้งใจให้ fail-soft: ถ้าตารางปลายทางมีปัญหา งานผลิตหลักต้องไม่ล้ม

/** upsert เครื่องลงตาราง stock ตามข้อมูลปัจจุบัน — เรียกหลังเพิ่มหรือแก้ไขเครื่อง ($oldCode = รหัสเดิมถ้ามีการเปลี่ยนรหัส) */
function share_upsert_asset($assetId, $oldCode = null) {
    $a = qr("SELECT a.asset_code, a.produced_at, p.name model,
                    (SELECT pr.made_by FROM production_records pr WHERE pr.asset_id=a.id AND pr.made_by IS NOT NULL AND pr.made_by<>''
                     ORDER BY pr.recorded_at DESC, pr.id DESC LIMIT 1) made_by,
                    COALESCE((SELECT MAX(pr2.recorded_at) FROM production_records pr2 WHERE pr2.asset_id=a.id), a.produced_at) last_dt
             FROM assets a JOIN products p ON p.id=a.product_id WHERE a.id=?", 'i', [$assetId])->fetch_assoc();
    if (!$a) return;
    if ($oldCode !== null && $oldCode !== $a['asset_code']) share_delete_asset($oldCode); // เปลี่ยนรหัส: เอาแถว serial เดิมออกก่อน
    $ts = $a['last_dt'] ?: $a['produced_at'];
    $conn = dbStock();

    // เดิม: คำนวณ id ใหม่ด้วย COALESCE(MAX(s.id),0)+1 ในคำสั่งเดียวกับ INSERT โดยไม่มีล็อกกันชน
    // ถ้าเขียนพร้อมกันหลายเครื่อง id ที่คำนวณได้อาจชนกับแถวอื่นที่เพิ่งถูกเขียนไปหมาดๆ
    // ผลคือ ON DUPLICATE KEY UPDATE จะไป "แก้แถวอื่น" แทนที่จะ insert แถวใหม่จริงๆ — แบบไม่มี error ใดๆ เลย
    // (เครื่องที่เพิ่งผลิตหายไปเงียบๆ ทั้งที่ฝั่งระบบหลักบันทึกสำเร็จจริง)
    // แก้ไข: ครอบด้วย GET_LOCK ให้ "อ่าน id ถัดไป + insert" เป็น atomic เหมือนตอนออกรหัสเครื่อง (create_produced_asset)
    $lockKey = 'biton_stockparts_stock_id_seq';
    try {
        $lockRow = $conn->query("SELECT GET_LOCK('$lockKey', 10) l");
        $lockRow = $lockRow ? $lockRow->fetch_assoc() : null;
    } catch (\mysqli_sql_exception $e) {
        error_log("[share_upsert_asset] GET_LOCK query error: " . $e->getMessage() . " asset_id=$assetId code={$a['asset_code']}");
        return;
    }
    if (!$lockRow || (int)$lockRow['l'] !== 1) {
        error_log("[share_upsert_asset] GET_LOCK ไม่สำเร็จ asset_id=$assetId code={$a['asset_code']} — ข้อมูลจะไม่ถูก sync ไป stock รอบนี้");
        return; // fail-soft ตามเจตนาเดิม แต่ log ไว้ให้ตรวจสอบย้อนหลังได้ (เดิมไม่ log อะไรเลย)
    }
    try {
        $madeBy = trim((string)($a['made_by'] ?? ''));
        $st = $conn->prepare("INSERT INTO stock (`timestamp`, serial_number, model, id, create_name, setup_id, active)
            SELECT ?, ?, ?, COALESCE(MAX(s.id),0)+1, ?, NULL, 1 FROM stock s
            ON DUPLICATE KEY UPDATE `timestamp`=VALUES(`timestamp`), model=VALUES(model),
                                    create_name=IF(VALUES(create_name)='', create_name, VALUES(create_name))");
        if ($st === false) {
            error_log("[share_upsert_asset] prepare ล้มเหลว: " . $conn->error . " asset_id=$assetId code={$a['asset_code']}");
            return;
        }
        $st->bind_param('ssss', $ts, $a['asset_code'], $a['model'], $a['made_by']);
        try {
            $st->execute();
        } catch (\mysqli_sql_exception $e) {
            // @ ไม่ได้กัน exception จริงบน PHP 8.1+ (ค่า default ของ mysqli เปลี่ยนไปโยน exception) — ครอบ try/catch จริงแทน
            error_log("[share_upsert_asset] execute ล้มเหลว: " . $e->getMessage() . " asset_id=$assetId code={$a['asset_code']}");
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('$lockKey')");
    }
}
/** อัปเดต model + timestamp + ชื่อผู้ผลิต ใน stock จากข้อมูล production ตาม serial ที่มีในระบบ */
function share_refresh_meta_from_production() {
    $prod = db();
    $stock = dbStock();
    $res = $prod->query("SELECT a.asset_code, p.name model,
                                COALESCE((SELECT MAX(pr.recorded_at) FROM production_records pr WHERE pr.asset_id=a.id), a.produced_at) ts,
                                (SELECT pr.made_by FROM production_records pr WHERE pr.asset_id=a.id
                                 AND pr.made_by IS NOT NULL AND TRIM(pr.made_by)<>'' 
                                 ORDER BY pr.recorded_at DESC, pr.id DESC LIMIT 1) made_by
                         FROM assets a JOIN products p ON p.id=a.product_id");
    if (!$res) return 0;
    $st = $stock->prepare("UPDATE stock SET model=?, `timestamp`=?, create_name=IF(?='', create_name, ?) WHERE serial_number=?");
    if (!$st) return 0;
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        $madeBy = trim((string)($r['made_by'] ?? ''));
        $st->bind_param('sssss', $r['model'], $r['ts'], $madeBy, $madeBy, $r['asset_code']);
        $st->execute();
        if ($st->affected_rows > 0) $n++;
    }
    return $n;
}

/**
 * สถิติความครบถ้วนของ stock เทียบกับทะเบียนเครื่องผลิต
 *
 * @return array{assets:int,in_stock:int,missing_in_stock:int,incomplete:int,needs_sync:bool}
 */
function share_sync_stats() {
    $assetCodes = [];
    $res = qr("SELECT asset_code FROM assets");
    while ($r = $res->fetch_assoc()) {
        $assetCodes[$r['asset_code']] = true;
    }
    $inStock = [];
    $incomplete = 0;
    $stock = dbStock();
    $res2 = qr("SELECT serial_number, `timestamp`, model, create_name FROM stock", '', [], $stock);
    while ($r = $res2->fetch_assoc()) {
        $inStock[$r['serial_number']] = true;
        if ($r['timestamp'] === null || trim((string)$r['model']) === '' || trim((string)$r['create_name']) === '') {
            $incomplete++;
        }
    }
    $missing = 0;
    foreach ($assetCodes as $code => $_) {
        if (!isset($inStock[$code])) {
            $missing++;
        }
    }
    return [
        'assets' => count($assetCodes),
        'in_stock' => count($inStock),
        'missing_in_stock' => $missing,
        'incomplete' => $incomplete,
        'needs_sync' => $missing > 0 || $incomplete > 0,
    ];
}

/**
 * ซิงก์ข้อมูล stock ทั้งหมดจากทะเบียนเครื่องผลิต — เพิ่มรายการที่ขาด + อัปเดต meta ที่ยังไม่ครบ
 *
 * @return array{assets:int,added:int,updated_meta:int,incomplete_remaining:int,missing_remaining:int}
 */
function share_sync_all_from_production() {
    $stock = dbStock();
    $existing = [];
    $res0 = qr("SELECT serial_number FROM stock", '', [], $stock);
    while ($x = $res0->fetch_assoc()) {
        $existing[$x['serial_number']] = true;
    }
    $added = 0;
    $res = qr("SELECT id FROM assets ORDER BY id");
    while ($r = $res->fetch_assoc()) {
        $aid = (int)$r['id'];
        $code = qr("SELECT asset_code FROM assets WHERE id=?", 'i', [$aid])->fetch_assoc();
        if (!$code) {
            continue;
        }
        $wasThere = isset($existing[$code['asset_code']]);
        share_upsert_asset($aid);
        if (!$wasThere) {
            $added++;
            $existing[$code['asset_code']] = true;
        }
    }
    $updatedMeta = share_refresh_meta_from_production();
    $stats = share_sync_stats();
    return [
        'assets' => $stats['assets'],
        'added' => $added,
        'updated_meta' => $updatedMeta,
        'incomplete_remaining' => $stats['incomplete'],
        'missing_remaining' => $stats['missing_in_stock'],
    ];
}

/**
 * ปรับ timestamp ให้เทียบกันได้ (ตัดวินาที/รูปแบบต่างกัน)
 *
 * @param mixed $v
 * @return string
 */
function share_norm_ts($v) {
    if ($v === null || $v === '') {
        return '';
    }
    $ts = strtotime((string)$v);
    return $ts ? date('Y-m-d H:i:s', $ts) : trim((string)$v);
}

/**
 * เปรียบเทียบ meta ระหว่าง assets กับ stock — คืนรายการฟิลด์ที่ต่างกัน
 *
 * @param array $asset แถวจาก assets (model, ts, made_by)
 * @param array $stock แถวจาก stock (model, timestamp, create_name)
 * @return string[] ชื่อฟิลด์ที่ต่าง เช่น model, timestamp, create_name
 */
function share_meta_diff_fields(array $asset, array $stock) {
    $diff = [];
    $aModel = trim((string)($asset['model'] ?? ''));
    $sModel = trim((string)($stock['model'] ?? ''));
    if ($aModel !== $sModel && ($aModel !== '' || $sModel !== '')) {
        $diff[] = 'model';
    }
    if (share_norm_ts($asset['ts'] ?? null) !== share_norm_ts($stock['timestamp'] ?? null)) {
        $diff[] = 'timestamp';
    }
    $aName = trim((string)($asset['made_by'] ?? ''));
    $sName = trim((string)($stock['create_name'] ?? ''));
    if ($aName !== $sName && ($aName !== '' || $sName !== '')) {
        $diff[] = 'create_name';
    }
    return $diff;
}

/**
 * โหลดดัชนีเปรียบเทียบ assets ↔ stock (cache ต่อ request)
 *
 * @return array{assets_by_code:array,stock_by_sn:array,rows:array,counts:array}
 */
function share_reconcile_index() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stockBySn = [];
    $resS = qr("SELECT serial_number, model, `timestamp`, create_name, active, id batch_id FROM stock", '', [], dbStock());
    while ($r = $resS->fetch_assoc()) {
        $stockBySn[$r['serial_number']] = $r;
    }

    $assetsByCode = [];
    $resA = qr("SELECT a.id asset_id, a.asset_code, p.name model, a.produced_at,
                       COALESCE((SELECT MAX(pr.recorded_at) FROM production_records pr WHERE pr.asset_id=a.id), a.produced_at) ts,
                       (SELECT pr.made_by FROM production_records pr WHERE pr.asset_id=a.id
                        AND pr.made_by IS NOT NULL AND TRIM(pr.made_by)<>'' 
                        ORDER BY pr.recorded_at DESC, pr.id DESC LIMIT 1) made_by
                FROM assets a JOIN products p ON p.id=a.product_id");
    while ($r = $resA->fetch_assoc()) {
        $assetsByCode[$r['asset_code']] = $r;
    }

    $rows = [];
    $counts = ['assets_only' => 0, 'stock_only' => 0, 'meta_diff' => 0, 'total_diff' => 0];

    foreach ($assetsByCode as $code => $a) {
        if (!isset($stockBySn[$code])) {
            $counts['assets_only']++;
            $rows[] = [
                'type' => 'assets_only',
                'serial' => $code,
                'asset_id' => (int)$a['asset_id'],
                'a_model' => $a['model'],
                'a_ts' => $a['ts'],
                'a_name' => $a['made_by'] ?? '',
                's_model' => '',
                's_ts' => null,
                's_name' => '',
                's_active' => null,
                's_batch' => null,
                'diffs' => [],
            ];
        }
    }

    foreach ($stockBySn as $sn => $s) {
        if (!isset($assetsByCode[$sn])) {
            $counts['stock_only']++;
            $rows[] = [
                'type' => 'stock_only',
                'serial' => $sn,
                'asset_id' => null,
                'a_model' => '',
                'a_ts' => null,
                'a_name' => '',
                's_model' => $s['model'] ?? '',
                's_ts' => $s['timestamp'],
                's_name' => $s['create_name'] ?? '',
                's_active' => (int)$s['active'],
                's_batch' => $s['batch_id'],
                'diffs' => [],
            ];
        }
    }

    foreach ($assetsByCode as $code => $a) {
        if (!isset($stockBySn[$code])) {
            continue;
        }
        $s = $stockBySn[$code];
        $diffs = share_meta_diff_fields($a, $s);
        if (!$diffs) {
            continue;
        }
        $counts['meta_diff']++;
        $rows[] = [
            'type' => 'meta_diff',
            'serial' => $code,
            'asset_id' => (int)$a['asset_id'],
            'a_model' => $a['model'],
            'a_ts' => $a['ts'],
            'a_name' => $a['made_by'] ?? '',
            's_model' => $s['model'] ?? '',
            's_ts' => $s['timestamp'],
            's_name' => $s['create_name'] ?? '',
            's_active' => (int)$s['active'],
            's_batch' => $s['batch_id'],
            'diffs' => $diffs,
        ];
    }

    usort($rows, function ($x, $y) {
        $ord = ['assets_only' => 0, 'meta_diff' => 1, 'stock_only' => 2];
        $dx = $ord[$x['type']] ?? 9;
        $dy = $ord[$y['type']] ?? 9;
        if ($dx !== $dy) {
            return $dx - $dy;
        }
        return strcmp($x['serial'], $y['serial']);
    });

    $counts['total_diff'] = count($rows);
    $cache = [
        'assets_by_code' => $assetsByCode,
        'stock_by_sn' => $stockBySn,
        'rows' => $rows,
        'counts' => $counts,
    ];
    return $cache;
}

/**
 * ดึงรายการที่ไม่ตรงกันแบบแบ่งหน้า
 *
 * @param string $filter assets_only|stock_only|meta_diff|'' (ทุกประเภทที่ต่าง)
 * @param string $search ค้นหา serial / รุ่น / ชื่อ
 * @param int    $page
 * @param int    $per
 * @return array{rows:array,total:int,page:int,pages:int}
 */
function share_reconcile_list($filter, $search, $page, $per) {
    $idx = share_reconcile_index();
    $rows = $idx['rows'];
    if ($filter !== '' && in_array($filter, ['assets_only', 'stock_only', 'meta_diff'], true)) {
        $rows = array_values(array_filter($rows, function ($r) use ($filter) {
            return $r['type'] === $filter;
        }));
    }
    $search = trim((string)$search);
    if ($search !== '') {
        $q = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($q) {
            $blob = mb_strtolower($r['serial'] . ' ' . ($r['a_model'] ?? '') . ' ' . ($r['s_model'] ?? '')
                . ' ' . ($r['a_name'] ?? '') . ' ' . ($r['s_name'] ?? ''));
            return mb_strpos($blob, $q) !== false;
        }));
    }
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    $off = ($page - 1) * $per;
    return [
        'rows' => array_slice($rows, $off, $per),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
    ];
}

/**
 * ซิงก์เครื่องเดียวจากระบบหลักไป stock ตาม serial
 *
 * @param string $serial
 * @return bool สำเร็จถ้ามีใน assets
 */
function share_sync_one_serial($serial) {
    $serial = trim($serial);
    if ($serial === '' || mb_strlen($serial) > 80) {
        return false;
    }
    $row = qr("SELECT id FROM assets WHERE asset_code=?", 's', [$serial])->fetch_assoc();
    if (!$row) {
        return false;
    }
    share_upsert_asset((int)$row['id']);
    return true;
}

/**
 * ซิงก์เครื่องเดียวจาก stock ไปสร้างในระบบหลัก (assets) ตาม serial
 *
 * ใช้ข้อมูล model / timestamp / create_name จาก stock จับคู่ products.name
 * แล้ว INSERT assets + production_records (ถ้ามีผู้ผลิต/เวลา)
 *
 * @param string $serial  serial_number ใน stock (= asset_code ที่ต้องการ)
 * @return array{ok:bool, asset_id?:int, error?:string}
 */
function share_sync_stock_one_serial($serial) {
    $serial = trim($serial);
    if ($serial === '' || mb_strlen($serial) > 80) {
        return ['ok' => false, 'error' => 'serial ไม่ถูกต้อง'];
    }
    if (qr("SELECT id FROM assets WHERE asset_code=?", 's', [$serial])->fetch_assoc()) {
        return ['ok' => false, 'error' => 'มีในระบบหลักแล้ว'];
    }

    $stockDb = dbStock();
    $s = qr("SELECT serial_number, model, `timestamp`, create_name FROM stock WHERE serial_number=?", 's', [$serial], $stockDb)->fetch_assoc();
    if (!$s) {
        return ['ok' => false, 'error' => 'ไม่พบใน stock'];
    }

    $model = trim((string)($s['model'] ?? ''));
    if ($model === '') {
        return ['ok' => false, 'error' => 'ไม่มีรุ่นใน stock'];
    }

    $p = qr("SELECT id, code_mode FROM products WHERE name=? AND is_active=1 ORDER BY id LIMIT 1", 's', [$model])->fetch_assoc();
    if (!$p) {
        $p = qr("SELECT id, code_mode FROM products WHERE name=? ORDER BY id LIMIT 1", 's', [$model])->fetch_assoc();
    }
    if (!$p) {
        return ['ok' => false, 'error' => "ไม่พบรุ่น \"$model\" ในระบบ"];
    }

    $ts = $s['timestamp'];
    $producedDate = $ts ? date('Y-m-d', strtotime((string)$ts)) : date('Y-m-d');
    $madeBy = trim((string)($s['create_name'] ?? ''));
    $note = 'sync จาก stock';
    $productId = (int)$p['id'];

    if ($p['code_mode'] === 'factory_serial') {
        $r = create_produced_asset($productId, $producedDate, $serial, $note, null);
        if (isset($r['error'])) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $aid = (int)$r['asset_id'];
    } else {
        $ins = q_try("INSERT INTO assets (asset_code, factory_serial, product_id, produced_at, status, note) VALUES (?,?,?,?,'new',?)",
            'ssiss', [$serial, $serial, $productId, $producedDate, $note]);
        if (!$ins['ok']) {
            return ['ok' => false, 'error' => db_error_user_message($ins['errno'], $ins['error'])];
        }
        $aid = (int)$ins['insert_id'];
        if ($aid <= 0) {
            return ['ok' => false, 'error' => 'บันทึกเครื่องไม่สำเร็จ'];
        }
        share_upsert_asset($aid);
    }

    if ($madeBy !== '' || $ts) {
        $recordedAt = $ts ? date('Y-m-d H:i:s', strtotime((string)$ts)) : date('Y-m-d H:i:s');
        q("INSERT INTO production_records (asset_id, recorded_at, made_by) VALUES (?,?,?)",
          'iss', [$aid, $recordedAt, $madeBy !== '' ? $madeBy : null]);
        share_upsert_asset($aid);
    }

    return ['ok' => true, 'asset_id' => $aid];
}

/**
 * เติม production_records.made_by ในระบบหลักจาก stock.create_name
 *
 * ใช้เมื่อเครื่องมีใน assets แล้วแต่ยังไม่มีชื่อผู้ผลิต ขณะที่ stock มี create_name
 *
 * @param string     $serial   serial_number / asset_code
 * @param array|null $stockRow แถว stock ที่มี create_name, timestamp (ถ้ามีแล้วจะไม่ query ซ้ำ)
 * @return array{ok:bool, skip?:bool, asset_id?:int, error?:string}
 */
function share_fill_asset_made_by_from_stock($serial, ?array $stockRow = null) {
    $serial = trim($serial);
    if ($serial === '' || mb_strlen($serial) > 80) {
        return ['ok' => false, 'error' => 'serial ไม่ถูกต้อง'];
    }

    $asset = qr("SELECT a.id, a.produced_at FROM assets a WHERE a.asset_code=?", 's', [$serial])->fetch_assoc();
    if (!$asset) {
        return ['ok' => false, 'skip' => true, 'error' => 'ไม่พบในระบบหลัก'];
    }
    $aid = (int)$asset['id'];

    if (qr("SELECT id FROM production_records WHERE asset_id=? AND made_by IS NOT NULL AND TRIM(made_by)<>'' LIMIT 1", 'i', [$aid])->fetch_assoc()) {
        return ['ok' => false, 'skip' => true, 'error' => 'มีผู้ผลิตในระบบหลักแล้ว'];
    }

    if ($stockRow === null) {
        $stockRow = qr("SELECT create_name, `timestamp` FROM stock WHERE serial_number=?", 's', [$serial], dbStock())->fetch_assoc();
    }
    if (!$stockRow) {
        return ['ok' => false, 'error' => 'ไม่พบใน stock'];
    }

    $name = trim((string)($stockRow['create_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'skip' => true, 'error' => 'ไม่มีชื่อใน stock'];
    }
    if (mb_strlen($name) > 100) {
        $name = mb_substr($name, 0, 100);
    }

    $recordedAt = !empty($stockRow['timestamp'])
        ? date('Y-m-d H:i:s', strtotime((string)$stockRow['timestamp']))
        : ($asset['produced_at'] ? date('Y-m-d H:i:s', strtotime((string)$asset['produced_at'])) : date('Y-m-d H:i:s'));

    q("INSERT INTO production_records (asset_id, recorded_at, made_by) VALUES (?,?,?)",
      'iss', [$aid, $recordedAt, $name]);

    return ['ok' => true, 'asset_id' => $aid];
}

/**
 * เติม made_by ในระบบหลักจาก stock ทุกรายการที่ยังว่าง
 *
 * @return array{ok:int, skip:int, fail:int}
 */
function share_fill_all_asset_made_by_from_stock() {
    $stock = dbStock();
    $res = qr("SELECT serial_number, create_name, `timestamp` FROM stock WHERE TRIM(COALESCE(create_name,''))<>''", '', [], $stock);
    $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0];
    while ($row = $res->fetch_assoc()) {
        $r = share_fill_asset_made_by_from_stock($row['serial_number'], $row);
        if (!empty($r['ok'])) {
            $stats['ok']++;
        } elseif (!empty($r['skip'])) {
            $stats['skip']++;
        } else {
            $stats['fail']++;
        }
    }
    return $stats;
}

/**
 * ลบเครื่องจากระบบหลักพร้อมประวัติ (ใช้ในหน้าหลังบ้านเท่านั้น)
 *
 * @param int $assetId
 * @return bool
 */
function asset_delete_full($assetId) {
    $assetId = (int)$assetId;
    if ($assetId <= 0) {
        return false;
    }
    $a = qr("SELECT asset_code FROM assets WHERE id=?", 'i', [$assetId])->fetch_assoc();
    if (!$a) {
        return false;
    }
    if (function_exists('production_sync_delete_all_withdrawals_for_asset')) {
        production_sync_delete_all_withdrawals_for_asset($assetId, $a['asset_code']);
    } else {
        q("UPDATE part_movements SET ref_asset_id=NULL WHERE ref_asset_id=?", 'i', [$assetId]);
    }
    q("DELETE FROM spare_loans WHERE spare_asset_id=? OR replaces_asset_id=?", 'ii', [$assetId, $assetId]);
    q("DELETE FROM assets WHERE id=?", 'i', [$assetId]);
    share_delete_asset($a['asset_code']);
    return true;
}

/**
 * พาร์สวันที่จากหลายรูปแบบสำหรับ import CSV (ISO หรือ AppSheet)
 *
 * @param string      $s
 * @param string|null $slashFmt 'mdy'|'dmy' สำหรับรูปแบบ วัน/เดือน/ปี
 * @return string|null 'Y-m-d H:i:s'
 */
function share_parse_import_dt($s, $slashFmt = 'mdy') {
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('#^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?#', $s)) {
        $norm = str_replace('T', ' ', $s);
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $norm)) {
            $norm .= ' 00:00:00';
        } elseif (strlen($norm) === 16) {
            $norm .= ':00';
        }
        $ts = strtotime($norm);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM|น\.)?)?#iu', $s, $m)) {
        return null;
    }
    list(, $a, $b, $y) = $m;
    $mo = $slashFmt === 'dmy' ? (int)$b : (int)$a;
    $d  = $slashFmt === 'dmy' ? (int)$a : (int)$b;
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
        return null;
    }
    $h = isset($m[4]) ? (int)$m[4] : 0;
    $i = isset($m[5]) ? (int)$m[5] : 0;
    $sec = isset($m[6]) ? (int)$m[6] : 0;
    $ap = strtoupper($m[7] ?? '');
    if ($ap === 'PM' && $h < 12) {
        $h += 12;
    }
    if ($ap === 'AM' && $h === 12) {
        $h = 0;
    }
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $sec);
}

/**
 * ตรวจรูปแบบวันที่แบบ slash ในไฟล์ import
 *
 * @param array<int, string> $values
 * @return string 'mdy'|'dmy'
 */
function share_detect_import_dt_fmt(array $values) {
    foreach ($values as $v) {
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/\d{4}#', trim((string)$v), $m)) {
            continue;
        }
        if ((int)$m[1] > 12) {
            return 'dmy';
        }
        if ((int)$m[2] > 12) {
            return 'mdy';
        }
    }
    return 'mdy';
}

/**
 * ดึง model / วันที่ / ผู้ผลิต จากทะเบียนเครื่องผลิตตามหมายเลขสินค้า
 *
 * @param string $assetCode
 * @return array{model:string,ts:?string,made_by:string}
 */
function share_lookup_production_meta($assetCode) {
    $r = qr("SELECT p.name model,
                    COALESCE((SELECT MAX(pr.recorded_at) FROM production_records pr WHERE pr.asset_id=a.id), a.produced_at) ts,
                    (SELECT pr.made_by FROM production_records pr WHERE pr.asset_id=a.id
                     AND pr.made_by IS NOT NULL AND TRIM(pr.made_by)<>'' 
                     ORDER BY pr.recorded_at DESC, pr.id DESC LIMIT 1) made_by
             FROM assets a JOIN products p ON p.id=a.product_id WHERE a.asset_code=?", 's', [$assetCode])->fetch_assoc();
    if (!$r) {
        return ['model' => '', 'ts' => null, 'made_by' => ''];
    }
    return [
        'model' => trim((string)$r['model']),
        'ts' => $r['ts'] ?: null,
        'made_by' => trim((string)($r['made_by'] ?? '')),
    ];
}

/**
 * แมปหัวคอลัมน์ CSV → ฟิลด์มาตรฐาน (serial / timestamp / user / model)
 *
 * @param array<int, string> $headerRow
 * @return array<string, int>
 */
function share_normalize_import_header(array $headerRow) {
    $aliases = [
        'serial' => ['serial_number', 'serialnumber', 'serial', 'serial_no', 'serialno', 'asset_code', 'code', 'หมายเลขสินค้า', 'เลขเครื่อง'],
        'timestamp' => ['timestamp', 'date', 'datetime', 'time', 'วันที่', 'วันที่ผลิต', 'เวลา'],
        'user' => ['create_name', 'user', 'username', 'name', 'ผู้ผลิต', 'ผู้บันทึก', 'ชื่อผู้บันทึก'],
        'model' => ['model', 'รุ่น', 'product', 'product_name'],
    ];
    $col = [];
    foreach ($headerRow as $i => $raw) {
        $key = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw)));
        $key = preg_replace('/[\s\-]+/', '_', $key);
        foreach ($aliases as $field => $names) {
            if (in_array($key, $names, true) || $key === $field) {
                $col[$field] = $i;
            }
        }
    }
    return $col;
}

/**
 * เติมเฉพาะฟิลด์ที่ยังว่างใน stock จากทะเบียนเครื่องผลิต
 *
 * @param array<int, string> $serials รายการ serial_number
 * @return int จำนวนแถวที่ถูกอัปเดต
 */
function share_fill_stock_gaps(array $serials) {
    if (!$serials) {
        return 0;
    }
    $stock = dbStock();
    $st = $stock->prepare("UPDATE stock SET
        model = IF(TRIM(COALESCE(model,''))='', ?, model),
        `timestamp` = IF(`timestamp` IS NULL, ?, `timestamp`),
        create_name = IF(TRIM(COALESCE(create_name,''))='', ?, create_name)
        WHERE serial_number=? AND (TRIM(COALESCE(model,''))='' OR `timestamp` IS NULL OR TRIM(COALESCE(create_name,''))='')");
    if (!$st) {
        return 0;
    }
    $n = 0;
    foreach ($serials as $sn) {
        $sn = trim((string)$sn);
        if ($sn === '') {
            continue;
        }
        $meta = share_lookup_production_meta($sn);
        if ($meta['model'] === '' && $meta['ts'] === null && $meta['made_by'] === '') {
            continue;
        }
        $st->bind_param('ssss', $meta['model'], $meta['ts'], $meta['made_by'], $sn);
        $st->execute();
        if ($st->affected_rows > 0) {
            $n++;
        }
    }
    return $n;
}

/**
 * Import CSV พื้นฐาน: timestamp + serial_number + ผู้ผลิต/ผู้บันทึก
 * ค่าที่ยังว่างหลัง import จะถูกเติมจากทะเบียนเครื่องผลิตอัตโนมัติ
 *
 * @param string $filePath path ไฟล์ CSV ชั่วคราว
 * @return array<string, mixed>
 */
function share_import_basic_csv($filePath) {
    $fh = fopen($filePath, 'r');
    if (!$fh) {
        return ['error' => 'เปิดไฟล์ไม่ได้'];
    }
    $head = fgetcsv($fh);
    if (!$head) {
        fclose($fh);
        return ['error' => 'ไฟล์ว่าง'];
    }
    $col = share_normalize_import_header($head);
    if (!isset($col['serial'])) {
        fclose($fh);
        return ['error' => 'ต้องมีคอลัมน์ serial_number (หรือ serialnumber / หมายเลขสินค้า)'];
    }
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        $rows[] = $r;
    }
    fclose($fh);

    $tsIdx = $col['timestamp'] ?? null;
    $tsSamples = [];
    if ($tsIdx !== null) {
        foreach ($rows as $r) {
            $tsSamples[] = $r[$tsIdx] ?? '';
        }
    }
    $fmt = share_detect_import_dt_fmt($tsSamples);

    $stock = dbStock();
    $batch = (int)qr("SELECT COALESCE(MAX(id),0)+1 m FROM stock", '', [], $stock)->fetch_assoc()['m'];

    $stIns = $stock->prepare("INSERT INTO stock (`timestamp`, serial_number, model, id, create_name, setup_id, active) VALUES (?,?,?,?,?,NULL,1)");
    $stUpd = $stock->prepare("UPDATE stock SET `timestamp`=?, model=?, create_name=? WHERE serial_number=?");
    $stSel = $stock->prepare("SELECT `timestamp`, model, create_name FROM stock WHERE serial_number=? LIMIT 1");
    if (!$stIns || !$stUpd || !$stSel) {
        return ['error' => 'เตรียมคำสั่งฐานข้อมูลไม่สำเร็จ'];
    }

    $added = 0;
    $updated = 0;
    $skipped = 0;
    $noProd = 0;
    $importedSerials = [];

    foreach ($rows as $r) {
        $sn = trim((string)($r[$col['serial']] ?? ''));
        if ($sn === '') {
            $skipped++;
            continue;
        }
        $importedSerials[] = $sn;

        $importTs = null;
        if ($tsIdx !== null) {
            $importTs = share_parse_import_dt($r[$tsIdx] ?? '', $fmt);
        }
        $importUser = isset($col['user']) ? trim((string)($r[$col['user']] ?? '')) : '';
        $importModel = isset($col['model']) ? trim((string)($r[$col['model']] ?? '')) : '';

        $prod = share_lookup_production_meta($sn);
        if ($prod['model'] === '' && $prod['ts'] === null && $prod['made_by'] === '') {
            $noProd++;
        }

        $stSel->bind_param('s', $sn);
        $stSel->execute();
        $existing = $stSel->get_result()->fetch_assoc();

        if ($existing) {
            $ts = $importTs ?: ($existing['timestamp'] ?: $prod['ts']);
            $name = $importUser !== '' ? $importUser
                : (trim((string)$existing['create_name']) !== '' ? $existing['create_name'] : $prod['made_by']);
            $model = $importModel !== '' ? $importModel
                : (trim((string)$existing['model']) !== '' ? $existing['model'] : $prod['model']);
            $stUpd->bind_param('ssss', $ts, $model, $name, $sn);
            $stUpd->execute();
            if ($stUpd->affected_rows > 0) {
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            $ts = $importTs ?: $prod['ts'];
            $name = $importUser !== '' ? $importUser : $prod['made_by'];
            $model = $importModel !== '' ? $importModel : $prod['model'];
            $stIns->bind_param('sssis', $ts, $sn, $model, $batch, $name);
            $stIns->execute();
            if ($stIns->affected_rows > 0) {
                $added++;
            } else {
                $skipped++;
            }
        }
    }

    $filled = share_fill_stock_gaps($importedSerials);

    return [
        'added' => $added,
        'updated' => $updated,
        'filled_from_production' => $filled,
        'skipped' => $skipped,
        'not_in_production' => $noProd,
        'total_rows' => count($rows),
        'date_fmt' => $fmt,
    ];
}

/** ลบเครื่องออกจากตาราง stock ตามรหัส — เรียกตอนลบเครื่องออกจากระบบ */
function share_delete_asset($code) {
    $conn = dbStock();
    try {
        $st = $conn->prepare("DELETE FROM stock WHERE serial_number=?");
        if ($st === false) { error_log("[share_delete_asset] prepare ล้มเหลว: " . $conn->error . " code=$code"); return; }
        $st->bind_param('s', $code);
        try {
            $st->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log("[share_delete_asset] execute ล้มเหลว: " . $e->getMessage() . " code=$code");
        }
    } catch (\mysqli_sql_exception $e) {
        error_log("[share_delete_asset] prepare ล้มเหลว (exception): " . $e->getMessage() . " code=$code");
    }
}

// ---------- สร้างรหัสเครื่องใหม่ ----------

/**
 * ตรวจและเพิ่มคอลัมน์ตั้งค่ารูปแบบรหัสสินค้า (รันครั้งเดียวต่อ request)
 *
 * @return void
 */
function ensure_product_code_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    $have = [];
    $res = db()->query("SHOW COLUMNS FROM products");
    if (!$res) return;
    while ($c = $res->fetch_assoc()) $have[$c['Field']] = true;
    $add = [];
    if (!isset($have['code_use_prefix'])) $add[] = "ADD COLUMN code_use_prefix TINYINT(1) NOT NULL DEFAULT 1 AFTER code_year_era";
    if (!isset($have['code_use_year']))   $add[] = "ADD COLUMN code_use_year TINYINT(1) NOT NULL DEFAULT 1 AFTER code_use_prefix";
    if (!isset($have['code_use_month']))  $add[] = "ADD COLUMN code_use_month TINYINT(1) NOT NULL DEFAULT 1 AFTER code_use_year";
    if (!$add) return;
    foreach ($add as $sql) {
        try {
            db()->query("ALTER TABLE products $sql");
        } catch (\mysqli_sql_exception $e) {
            error_log('[ensure_product_code_schema] ' . $e->getMessage());
        }
    }
}

/**
 * ซิงก์ factory_serial ให้ตรงกับ asset_code — หมายเลขสินค้าคือตัวเดียวกัน ต่างแค่วิธีออกเลข
 *
 * @return void
 */
function ensure_asset_serial_identity() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query("UPDATE assets SET factory_serial = asset_code
                      WHERE factory_serial IS NULL OR TRIM(factory_serial) = '' OR factory_serial <> asset_code");
    } catch (\mysqli_sql_exception $e) {
        error_log('[ensure_asset_serial_identity] ' . $e->getMessage());
    }
}

/**
 * อัปเกรดคอลัมน์วันเวลาที่ควรเก็บทั้งวันและเวลา (เช่น ma_records.visited_at)
 *
 * @return void
 */
function ensure_ma_datetime_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $res = db()->query("SHOW COLUMNS FROM ma_records LIKE 'visited_at'");
        if (!$res) {
            return;
        }
        $col = $res->fetch_assoc();
        if (!$col) {
            return;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        if (strpos($type, 'datetime') === false) {
            db()->query('ALTER TABLE ma_records MODIFY visited_at DATETIME NOT NULL');
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('[ensure_ma_datetime_schema] ' . $e->getMessage());
    }
}

/**
 * เพิ่มคอลัมน์ machine_status ใน ma_records สำหรับ recompute สถานะหลังลบ MA
 *
 * @return void
 */
function ensure_ma_status_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $res = db()->query("SHOW COLUMNS FROM ma_records LIKE 'machine_status'");
        if ($res && $res->num_rows === 0) {
            db()->query(
                "ALTER TABLE ma_records ADD COLUMN machine_status VARCHAR(16) NULL DEFAULT NULL AFTER fw_version"
            );
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('[ensure_ma_status_schema] ' . $e->getMessage());
    }
}

/**
 * ค่าเริ่มต้นส่วนประกอบรหัสจากแถว products
 *
 * @param array<string, mixed> $p
 * @return array{code_use_prefix:int,code_use_year:int,code_use_month:int,running_digits:int,code_year_era:string}
 */
function product_code_normalize(array $p) {
    return [
        'code_use_prefix' => (int)(isset($p['code_use_prefix']) ? $p['code_use_prefix'] : 1),
        'code_use_year'   => (int)(isset($p['code_use_year']) ? $p['code_use_year'] : 1),
        'code_use_month'  => (int)(isset($p['code_use_month']) ? $p['code_use_month'] : 1),
        'running_digits'  => max(1, min(8, (int)(isset($p['running_digits']) ? $p['running_digits'] : 4) ?: 4)),
        'code_year_era'   => (isset($p['code_year_era']) && $p['code_year_era'] === 'be') ? 'be' : 'ce',
        'code_prefix'     => isset($p['code_prefix']) ? (string)$p['code_prefix'] : '',
    ];
}

/**
 * สร้างรหัสเครื่องแบบ generated จากส่วนประกอบที่ตั้งค่า
 *
 * @param array<string, mixed> $p           แถว/ค่า products (code_prefix, code_year_era, running_digits, code_use_*)
 * @param string             $producedDate วันที่ผลิต Y-m-d
 * @param int                $runNo        เลข running
 * @return string
 */
function build_generated_asset_code(array $p, $producedDate, $runNo) {
    $cfg = product_code_normalize($p);
    $ts = strtotime($producedDate ?: date('Y-m-d'));
    $out = '';
    if ($cfg['code_use_prefix'] && $cfg['code_prefix'] !== '') {
        $out .= $cfg['code_prefix'];
    }
    if ($cfg['code_use_year']) {
        $y = (int)date('Y', $ts);
        if ($cfg['code_year_era'] === 'be') $y += 543;
        $out .= substr((string)$y, -2);
    }
    if ($cfg['code_use_month']) {
        $out .= date('m', $ts);
    }
    $digits = (int)$cfg['running_digits'];
    $out .= str_pad((string)$runNo, $digits, '0', STR_PAD_LEFT);
    return $out;
}

/**
 * คำอธิบายรูปแบบรหัสสำหรับแสดงใน UI
 *
 * @param array<string, mixed> $p
 * @return string
 */
function product_code_format_label(array $p) {
    if (($p['code_mode'] ?? '') !== 'generated') {
        return 'กรอกรหัสเครื่องเอง / สแกน QR';
    }
    $cfg = product_code_normalize($p);
    $parts = [];
    if ($cfg['code_use_prefix'] && $cfg['code_prefix'] !== '') $parts[] = $cfg['code_prefix'];
    if ($cfg['code_use_year']) $parts[] = 'ปี' . ($cfg['code_year_era'] === 'be' ? 'พ.ศ.' : 'ค.ศ.') . '(2)';
    if ($cfg['code_use_month']) $parts[] = 'เดือน(2)';
    $parts[] = 'รันนิ่ง(' . $cfg['running_digits'] . ')';
    return implode(' + ', $parts);
}

/**
 * ดึงเลข running จากท้ายรหัสเครื่อง (ใช้ตอนแก้ไขรหัส manual / คำนวณเลขถัดไป)
 *
 * รูปแบบ generated = … + running ความยาว running_digits ท้ายสุดเสมอ
 *
 * @param array<string, mixed> $p
 * @param string             $assetCode
 * @return int|null
 */
function parse_running_from_asset_code(array $p, $assetCode) {
    $cfg = product_code_normalize($p);
    $digits = (int)$cfg['running_digits'];
    $assetCode = trim((string)$assetCode);
    if ($digits <= 0 || $assetCode === '') {
        return null;
    }

    // ต้องตรงรูปแบบปัจจุบันเป๊ะ ๆ ก่อนถึงจะอ่านหางเป็น running ได้
    //
    // รหัสรุ่นเก่าใช้ running สั้นกว่า เช่น CC6309680 = CC + 63(ปี) + 09(เดือน) + 680
    // ถ้าตัด 4 ตัวท้ายตาม running_digits ปัจจุบัน จะได้ "9680" คือขโมยเลข 9 จากเดือนมาเป็นหลักพัน
    // ทำให้เลขถัดไปพุ่งจาก 1477 ไปเป็น 9681 — เครื่องที่ผลิตเดือน 09 จะดันเลขสูงสุดเสมอ
    //
    // รหัสที่ไม่เข้ารูปแบบให้คืน null แล้วปล่อยให้ผู้เรียกไปใช้ running_no ในฐานข้อมูลแทน
    // (ค่านั้นเก็บไว้ถูกต้องอยู่แล้ว)
    $prefix = ($cfg['code_use_prefix'] && $cfg['code_prefix'] !== '') ? (string)$cfg['code_prefix'] : '';
    $expectedLen = strlen($prefix)
        + ($cfg['code_use_year'] ? 2 : 0)
        + ($cfg['code_use_month'] ? 2 : 0)
        + $digits;

    if (strlen($assetCode) !== $expectedLen) {
        return null;
    }
    if ($prefix !== '' && strncmp($assetCode, $prefix, strlen($prefix)) !== 0) {
        return null;
    }
    $middle = substr($assetCode, strlen($prefix), $expectedLen - strlen($prefix) - $digits);
    if ($middle !== '' && !ctype_digit($middle)) {
        return null;
    }
    $tail = substr($assetCode, -$digits);
    if (!ctype_digit($tail)) {
        return null;
    }
    return (int)$tail;
}

/**
 * หาเลข running สูงสุดของรุ่น — อ่านจากท้าย asset_code เป็นหลัก (กัน running_no ในฐานข้อมูลผิดรูป)
 *
 * @param array<string, mixed> $p         แถว products
 * @param int                  $productId
 * @return int
 */
function max_running_no_for_product(array $p, int $productId): int {
    return asset_running_scan_for_product($p, $productId)['max'];
}

/**
 * สแกนเครื่องของรุ่น คืนเลข running สูงสุดพร้อมรหัสของเครื่องนั้น
 *
 * แยกออกมาเพื่อให้ "เลขถัดไป" กับ "เครื่องล่าสุดในระบบ" ที่โชว์ในฟอร์มมาจากการนับชุดเดียวกัน
 * เดิม latest_asset_code_for_product() เรียงด้วยคอลัมน์ running_no ในฐานข้อมูล ส่วนการหาเลข
 * ถัดไปอ่านจากตัวรหัส สองทางนี้ให้คำตอบคนละตัวเมื่อข้อมูลเก่ามี running_no ผิดรูป
 *
 * @param array<string, mixed> $p
 * @param int                  $productId
 * @return array{max:int, code:string}
 */
function asset_running_scan_for_product(array $p, int $productId): array {
    $productId = (int)$productId;
    if ($productId <= 0 || ($p['code_mode'] ?? '') !== 'generated') {
        return ['max' => 0, 'code' => ''];
    }
    $maxRun = 0;
    $maxCode = '';
    $prefix = trim((string)($p['code_prefix'] ?? ''));
    if ($prefix !== '') {
        $res = qr(
            "SELECT a.asset_code, a.running_no FROM assets a
             INNER JOIN products pr ON pr.id = a.product_id
             WHERE pr.code_prefix = ?",
            's',
            [$prefix]
        );
    } else {
        $res = qr(
            "SELECT asset_code, running_no FROM assets WHERE product_id = ?",
            'i',
            [$productId]
        );
    }
    $cfgDigits = product_code_normalize($p)['running_digits'];
    $rnCap = (int)str_repeat('9', min(8, $cfgDigits + 1));
    while ($row = $res->fetch_assoc()) {
        $parsed = parse_running_from_asset_code($p, $row['asset_code']);
        if ($parsed === null) {
            // รหัสรูปแบบเก่า/กรอกเอง — ใช้ running_no ในฐานข้อมูลแทน (ตัดค่าที่ผิดรูปทิ้งด้วย cap)
            $rn = (int)($row['running_no'] ?? 0);
            $parsed = ($rn > 0 && $rn <= $rnCap) ? $rn : null;
        }
        if ($parsed !== null && $parsed > $maxRun) {
            $maxRun = $parsed;
            $maxCode = (string)$row['asset_code'];
        }
    }
    return ['max' => $maxRun, 'code' => $maxCode];
}

/**
 * เลข running ถัดไปสำหรับออกรหัส generated
 *
 * @param array<string, mixed> $p
 * @param int                  $productId
 * @return int
 */
function next_running_no_for_product(array $p, int $productId): int {
    return max_running_no_for_product($p, $productId) + 1;
}

/**
 * รหัสเครื่องล่าสุดของรุ่น — generated ใช้ running_no สูงสุด (ให้ตรงกับเลข running ถัดไป)
 *
 * @param array<string, mixed> $p         แถว products
 * @param int                  $productId
 * @return string
 */
function latest_asset_code_for_product(array $p, int $productId): string {
    $productId = (int)$productId;
    if ($productId <= 0) {
        return '';
    }

    if (($p['code_mode'] ?? '') === 'generated') {
        // ใช้ผลสแกนชุดเดียวกับที่คำนวณเลขถัดไป เพื่อให้ "เครื่องล่าสุด" กับ "เลขถัดไป" สอดคล้องกันเสมอ
        $scan = asset_running_scan_for_product($p, $productId);
        if ($scan['code'] !== '') {
            return $scan['code'];
        }
    }

    $row = qr(
        "SELECT asset_code FROM assets WHERE product_id = ? ORDER BY id DESC LIMIT 1",
        'i',
        [$productId]
    )->fetch_assoc();
    return $row ? (string)$row['asset_code'] : '';
}

/**
 * คำนวณรหัส generated ที่จะออกสำหรับ batch บันทึกผลิต (ยังไม่ INSERT)
 *
 * @param int    $productId
 * @param string $producedDate Y-m-d
 * @param int    $count        จำนวนเครื่อง
 * @return array{codes?:array<int,string>, start_run?:int, error?:string, conflicts?:array<int,string>}
 */
function preview_generated_asset_codes($productId, $producedDate, $count) {
    ensure_product_code_schema();
    $count = min(200, max(1, (int)$count));
    $p = qr("SELECT id, code_mode, code_prefix, code_year_era, running_digits, code_use_prefix, code_use_year, code_use_month FROM products WHERE id=?", 'i', [$productId])->fetch_assoc();
    if (!$p) return ['error' => 'ไม่พบรุ่นสินค้า'];
    if ($p['code_mode'] !== 'generated') return ['error' => 'รุ่นนี้ไม่ได้ใช้ระบบออกรหัสอัตโนมัติ'];

    $cfg = product_code_normalize($p);
    if ($cfg['code_use_prefix'] && trim((string)$p['code_prefix']) === '') {
        return ['error' => 'รุ่นนี้ยังไม่ได้ตั้งชื่อย่อ (prefix) ในหลังบ้าน'];
    }

    $startRun = next_running_no_for_product($p, (int)$productId);
    $run = $startRun;
    $codes = [];
    $conflicts = [];

    for ($i = 0; $i < $count; $i++) {
        $found = false;
        for ($try = 0; $try < 200; $try++) {
            $code = build_generated_asset_code($p, $producedDate, $run);
            $dup = qr("SELECT id FROM assets WHERE asset_code=?", 's', [$code])->fetch_assoc();
            if (!$dup && !in_array($code, $codes, true)) {
                $codes[] = $code;
                $run++;
                $found = true;
                break;
            }
            if ($dup && !in_array($code, $conflicts, true)) $conflicts[] = $code;
            $run++;
        }
        if (!$found) {
            return ['error' => 'หาเลข running ว่างไม่ได้ — รหัสในช่วงนี้ถูกใช้ครบแล้ว กรุณาติดต่อ admin'];
        }
    }

    $out = ['codes' => $codes, 'start_run' => $startRun];
    if ($conflicts) $out['conflicts'] = $conflicts;
    return $out;
}

/**
 * ตรวจรหัสก่อนบันทึกผลิต — ใช้รหัสตามที่แสดงในฟอร์ม (ยังไม่ข้ามเลข)
 *
 * @param int                   $productId
 * @param string                $producedDate
 * @param int                   $count
 * @param array<int, string>    $serials  รายการ S/N (โหมด factory_serial)
 * @return array{ok:bool, message?:string, codes?:array<int,string>, dupes?:array<int,string>}
 */
function precheck_production_save($productId, $producedDate, $count, array $serials = []) {
    $p = qr("SELECT id, code_mode, code_prefix, code_year_era, running_digits, code_use_prefix, code_use_year, code_use_month FROM products WHERE id=?", 'i', [$productId])->fetch_assoc();
    if (!$p) return ['ok' => false, 'message' => 'ยังไม่ได้เลือกรุ่นสินค้า'];

    if ($p['code_mode'] === 'generated') {
        $count = min(200, max(1, (int)$count));
        if (trim((string)$p['code_prefix']) === '' && product_code_normalize($p)['code_use_prefix']) {
            return ['ok' => false, 'message' => 'รุ่นนี้ยังไม่ได้ตั้งชื่อย่อ (prefix) ในหลังบ้าน'];
        }
        $startRun = next_running_no_for_product($p, (int)$productId);
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = build_generated_asset_code($p, $producedDate, $startRun + $i);
        }
    } else {
        $codes = array_values(array_unique(array_filter(array_map('trim', $serials))));
        if (!$codes) return ['ok' => false, 'message' => 'ยังไม่ได้กรอกรหัสเครื่อง'];
    }

    $dupes = [];
    foreach ($codes as $code) {
        if (qr("SELECT id FROM assets WHERE asset_code=?", 's', [$code])->fetch_assoc()) {
            $dupes[] = $code;
        }
    }
    if ($dupes) {
        $list = implode(', ', $dupes);
        $msg = count($dupes) === 1
            ? 'รหัสเครื่อง "' . $dupes[0] . '" มีในระบบแล้ว — ไม่สามารถบันทึกซ้ำได้'
            : 'รหัสเครื่องเหล่านี้มีในระบบแล้ว: ' . $list;
        $msg .= "\n\nสาเหตุที่เป็นไปได้: เคยบันทึกหรือนำเข้าแล้ว · เลข running ไม่ตรงกับรหัสที่มีอยู่\n";
        $msg .= 'แนวทางแก้: ตรวจใน「ทะเบียนเครื่อง」หรือให้ admin ปรับเลข running ในระบบหลังบ้าน';
        return ['ok' => false, 'message' => $msg, 'codes' => $codes, 'dupes' => $dupes];
    }

    return ['ok' => true, 'codes' => $codes];
}

/**
 * สร้างเครื่องผลิตใหม่พร้อม gen รหัส (ล็อกกันเลขชนเมื่อบันทึกพร้อมกัน)
 * running นับต่อเนื่องทั้ง "ตระกูล prefix" (ทุกรุ่นที่ใช้ prefix เดียวกัน)
 *
 * @param int         $productId     รุ่นสินค้า
 * @param string      $producedDate  วันที่ผลิต Y-m-d
 * @param string|null $factorySerial หมายเลขสินค้า (กรอกเอง/สแกน เมื่อ code_mode=factory_serial)
 * @param string|null $note          หมายเหตุ
 * @param mixed       $userId        ไม่ใช้แล้ว (เก็บพารามิเตอร์ไว้ไม่ให้ call site พัง) — ไม่เขียน created_by
 * @return array{code?:string,asset_id?:int,error?:string}
 */
function create_produced_asset($productId, $producedDate, $factorySerial, $note, $userId = null) {
    ensure_product_code_schema();
    $d = db();
    $p = qr("SELECT id, code_mode, code_prefix, code_year_era, running_digits, code_use_prefix, code_use_year, code_use_month FROM products WHERE id=?", 'i', [$productId])->fetch_assoc();
    if (!$p) return ['error' => 'ไม่พบรุ่นสินค้า'];

    if ($p['code_mode'] !== 'generated') {
        // กรอกหมายเลขสินค้าเอง / สแกน QR — ใช้เป็นรหัสเครื่องและ S/N เดียวกัน
        if (trim((string)$factorySerial) === '') return ['error' => 'รุ่นนี้ต้องกรอกรหัสเครื่อง (กรอกเองหรือสแกน QR)'];
        $code = trim($factorySerial);
        $dup = qr("SELECT id FROM assets WHERE asset_code=?", 's', [$code])->fetch_assoc();
        if ($dup) return ['error' => db_error_user_message(1062, "Duplicate entry '$code' for key 'asset_code'")];
        $ins = q_try("INSERT INTO assets (asset_code,factory_serial,product_id,produced_at,status,note) VALUES (?,?,?,?,'new',?)",
          'ssiss', [$code, $code, $productId, $producedDate, $note]);
        if (!$ins['ok']) {
            return ['error' => db_error_user_message($ins['errno'], $ins['error'])];
        }
        $aid = (int)$ins['insert_id'];
        if ($aid <= 0) {
            return ['error' => 'บันทึกเครื่องไม่สำเร็จ (ไม่ได้รับ asset id)'];
        }
        // ยืนยันว่าแถวจริงอยู่ใน DB (กันเคส autocommit/rollback เงียบๆ)
        $chk = qr("SELECT id FROM assets WHERE id=?", 'i', [$aid])->fetch_assoc();
        if (!$chk) {
            return ['error' => 'บันทึกเครื่องไม่สำเร็จ (ข้อมูลไม่ถูก commit)'];
        }
        share_upsert_asset($aid); // sync ตารางแชร์ข้อมูลสินค้า
        return ['code' => $code, 'asset_id' => $aid];
    }

    $cfg = product_code_normalize($p);
    if ($cfg['code_use_prefix'] && trim((string)$p['code_prefix']) === '') {
        return ['error' => 'ติ๊กใช้ชื่อย่อแล้ว กรุณากรอกค่าชื่อย่อ (prefix)'];
    }
    $lockKey = trim((string)$p['code_prefix']) !== ''
        ? 'gencode_' . $p['code_prefix']
        : 'gencode_pid_' . $productId;
    $lock = qr("SELECT GET_LOCK(?,10) l", 's', [$lockKey])->fetch_assoc();
    if (!$lock || (int)$lock['l'] !== 1) return ['error' => 'ระบบกำลังออกเลขให้ผู้อื่น กรุณาลองใหม่'];
    try {
        $run = next_running_no_for_product($p, (int)$productId);
        $code = null;
        for ($try = 0; $try < 200; $try++) {
            $candidate = build_generated_asset_code($p, $producedDate, $run);
            if (!qr("SELECT id FROM assets WHERE asset_code=?", 's', [$candidate])->fetch_assoc()) {
                $code = $candidate;
                break;
            }
            $run++;
        }
        if ($code === null) {
            return ['error' => 'หาเลข running ว่างไม่ได้ — รหัสในช่วงนี้ถูกใช้ครบแล้ว กรุณาติดต่อ admin'];
        }
        $fs = $code;
        $ins = q_try("INSERT INTO assets (asset_code,factory_serial,product_id,running_no,produced_at,status,note) VALUES (?,?,?,?,?,'new',?)",
          'ssiiss', [$code, $fs, $productId, $run, $producedDate, $note]);
        if (!$ins['ok']) {
            return ['error' => db_error_user_message($ins['errno'], $ins['error'])];
        }
        $aid = (int)$ins['insert_id'];
        if ($aid <= 0) {
            return ['error' => 'บันทึกเครื่องไม่สำเร็จ (ไม่ได้รับ asset id)'];
        }
        $chk = qr("SELECT id FROM assets WHERE id=?", 'i', [$aid])->fetch_assoc();
        if (!$chk) {
            return ['error' => 'บันทึกเครื่องไม่สำเร็จ (ข้อมูลไม่ถูก commit)'];
        }
        share_upsert_asset($aid); // sync ตารางแชร์ข้อมูลสินค้า
        return ['code' => $code, 'asset_id' => $aid];
    } finally {
        qr("SELECT RELEASE_LOCK(?)", 's', [$lockKey]);
    }
}

// ============================================================
//  ระบบตั้งค่าฟิลด์รายรุ่น (admin หลังบ้าน)
//  context: 'production' (บันทึกผลิต), 'ma' (การ MA), 'update' (อัปเดต FW/HW)
// ============================================================

/** รูปแบบช่องกรอกที่รองรับใน product_field_config.input_mode */
const FIELD_INPUT_MODES = [
    'chip_multi_free'  => 'เลือกหลายค่า + พิมพ์เองได้',
    'chip_single_free' => 'เลือกค่าเดียว + พิมพ์เองได้',
    'chip_multi'       => 'เลือกหลายค่า (รายการเท่านั้น)',
    'chip_single'      => 'เลือกค่าเดียว (รายการเท่านั้น)',
    'text'             => 'ช่องข้อความ (ไม่มี dropdown)',
];

/**
 * ตรวจและเพิ่มคอลัมน์ input_mode ใน product_field_config
 *
 * @return void
 */
function ensure_field_input_mode_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $have = [];
    $res = db()->query("SHOW COLUMNS FROM product_field_config");
    if (!$res) {
        return;
    }
    while ($c = $res->fetch_assoc()) {
        $have[$c['Field']] = true;
    }
    if (isset($have['input_mode'])) {
        return;
    }
    try {
        db()->query("ALTER TABLE product_field_config ADD COLUMN input_mode VARCHAR(24) NOT NULL DEFAULT 'chip_multi_free' AFTER options_text");
    } catch (\mysqli_sql_exception $e) {
        error_log('[ensure_field_input_mode_schema] ' . $e->getMessage());
    }
}

/**
 * คืนค่า input_mode ที่ถูกต้อง — ค่าไม่รู้จักใช้ chip_multi_free
 *
 * @param string|null $mode
 * @return string
 */
function normalize_input_mode($mode) {
    $mode = trim((string)$mode);
    return isset(FIELD_INPUT_MODES[$mode]) ? $mode : 'chip_multi_free';
}

/**
 * ป้ายไทยของ input_mode สำหรับหน้าหลังบ้าน
 *
 * @param string|null $mode
 * @return string
 */
function input_mode_label($mode) {
    $mode = normalize_input_mode($mode);
    return FIELD_INPUT_MODES[$mode];
}

/** แตกข้อความหลายบรรทัด → array (ตัดว่าง/ซ้ำ) */
function split_lines($text) {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
        $line = trim($line);
        if ($line !== '' && !in_array($line, $out, true)) $out[] = $line;
    }
    return $out;
}

/** อ่าน config ที่ admin ตั้งไว้ของรุ่น — คืน array ['name','kind','options'=>[]] เรียงตาม sort_order */
function product_config_fields($productId, $context) {
    ensure_field_input_mode_schema();
    $res = qr("SELECT field_name, field_kind, options_text, input_mode FROM product_field_config
               WHERE product_id=? AND context=? AND is_active=1 ORDER BY sort_order, id", 'is', [$productId, $context]);
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'name' => $r['field_name'],
            'kind' => $r['field_kind'],
            'options' => split_lines($r['options_text']),
            'input_mode' => normalize_input_mode($r['input_mode'] ?? ''),
        ];
    }
    return $out;
}

/** มี config ที่ตั้งเองไหม */
function has_product_config($productId, $context) {
    $r = qr("SELECT COUNT(*) c FROM product_field_config WHERE product_id=? AND context=? AND is_active=1", 'is', [$productId, $context])->fetch_assoc();
    return (int)$r['c'] > 0;
}

/** ดึงฟิลด์ "บันทึกผลิต" จากประวัติจริงของรุ่น (component + extra) */
function derive_production_fields($productId) {
    $fields = [];
    // ชิ้นส่วนฮาร์ดแวร์
    $res = qr("SELECT ac.component_name FROM asset_components ac JOIN assets a ON a.id=ac.asset_id
               WHERE a.product_id=? GROUP BY ac.component_name ORDER BY MAX(ac.id) DESC LIMIT 30", 'i', [$productId]);
    $names = [];
    while ($r = $res->fetch_assoc()) $names[] = $r['component_name'];
    foreach ($names as $cn) {
        $opts = [];
        $r2 = qr("SELECT ac.component_value v FROM asset_components ac JOIN assets a ON a.id=ac.asset_id
                  WHERE a.product_id=? AND ac.component_name=? AND ac.component_value IS NOT NULL AND ac.component_value<>''
                  GROUP BY ac.component_value ORDER BY MAX(ac.id) DESC LIMIT 20", 'is', [$productId, $cn]);
        while ($x = $r2->fetch_assoc()) $opts[] = $x['v'];
        $fields[] = ['name' => $cn, 'kind' => 'component', 'options' => $opts, 'input_mode' => 'chip_multi_free'];
    }
    // ข้อมูลเฉพาะรุ่น (extra_json)
    $extraVals = [];
    $res = qr("SELECT pr.extra_json FROM production_records pr JOIN assets a ON a.id=pr.asset_id
               WHERE a.product_id=? AND pr.extra_json IS NOT NULL ORDER BY pr.id DESC LIMIT 400", 'i', [$productId]);
    while ($r = $res->fetch_assoc()) {
        $j = json_decode($r['extra_json'], true);
        if (!is_array($j)) continue;
        foreach ($j as $k => $v) {
            if ($k === 'ประเภทบันทึก' || $v === '' || $v === null) continue;
            if (!isset($extraVals[$k])) $extraVals[$k] = [];
            if (!in_array((string)$v, $extraVals[$k], true) && count($extraVals[$k]) < 20) $extraVals[$k][] = (string)$v;
        }
    }
    foreach ($extraVals as $k => $opts) {
        $fields[] = ['name' => $k, 'kind' => 'extra', 'options' => $opts, 'input_mode' => 'chip_multi_free'];
    }
    return $fields;
}

/** ดึงรายการตรวจ/อะไหล่ MA จากประวัติจริงของรุ่น (เรียงตามความถี่) */
function derive_ma_pool($productId) {
    $freq = [];
    $res = qr("SELECT m.ok_items, m.replace_items, m.repair_items FROM ma_records m
               JOIN assets a ON a.id=m.asset_id WHERE a.product_id=? ORDER BY m.id DESC LIMIT 500", 'i', [$productId]);
    while ($r = $res->fetch_assoc()) {
        foreach (['ok_items', 'replace_items', 'repair_items'] as $col) {
            foreach (split_lines(str_replace(',', "\n", (string)$r[$col])) as $it) {
                if ($it === '-') continue;
                $freq[$it] = (isset($freq[$it]) ? $freq[$it] : 0) + 1;
            }
        }
    }
    arsort($freq);
    return array_slice(array_keys($freq), 0, 60);
}

/** ดึงชื่อชิ้นส่วนที่เคยอัปเดต (สำหรับหน้าอัปเดต FW/HW) พร้อมตัวเลือกค่า */
function derive_update_fields($productId) {
    // ใช้ชิ้นส่วนฮาร์ดแวร์ชุดเดียวกับบันทึกผลิต (component) เป็นตัวเลือกช่อง "ชิ้นส่วน"
    $fields = [];
    foreach (derive_production_fields($productId) as $f) {
        if ($f['kind'] === 'component') $fields[] = $f;
    }
    return $fields;
}

/**
 * อ่านค่ามาตรฐาน FW / Lot ที่ตั้งต่อรุ่นในหลังบ้าน
 *
 * field_kind: fw|lot = เปิดใช้, fw_off|lot_off = ตั้งใจปิด
 *
 * @param int $productId
 * @return array{fw:?array,lot:?array,decided:bool}
 */
function product_std_fields($productId) {
    ensure_field_input_mode_schema();
    $out = ['fw' => null, 'lot' => null, 'made_by' => null, 'decided' => false];
    // อ่านรวมถึงแถวปิดด้วย — query ตรง ๆ ไม่ผ่าน product_config_fields (กรอง is_active อย่างเดียว แต่ยังได้ทุก kind)
    $res = qr("SELECT field_name, field_kind, options_text, input_mode FROM product_field_config
               WHERE product_id=? AND context='production' AND field_kind IN ('fw','lot','fw_off','lot_off','made_by','made_by_off') AND is_active=1
               ORDER BY sort_order, id", 'i', [$productId]);
    while ($r = $res->fetch_assoc()) {
        $out['decided'] = true;
        $opts = split_lines($r['options_text']);
        $mode = normalize_input_mode($r['input_mode'] ?? '');
        if ($r['field_kind'] === 'fw') {
            $out['fw'] = ['name' => $r['field_name'], 'kind' => 'fw', 'options' => $opts, 'input_mode' => $mode];
        } elseif ($r['field_kind'] === 'lot') {
            $out['lot'] = ['name' => $r['field_name'], 'kind' => 'lot', 'options' => $opts, 'input_mode' => $mode];
        } elseif ($r['field_kind'] === 'made_by') {
            $out['made_by'] = ['name' => $r['field_name'], 'kind' => 'made_by', 'options' => $opts, 'input_mode' => $mode];
        }
    }
    return $out;
}

/**
 * รุ่นนี้เปิดใช้แผง "ข้อความประจำสินค้า" (Serial / MAC) หรือไม่
 *
 * ตั้งจากระบบหลังบ้าน → ฟิลด์หน้า "บันทึกผลิตใหม่" → สวิตช์ข้อความประจำสินค้า
 *
 * @param int $productId
 * @return bool
 */
function product_show_snippets($productId) {
    ensure_field_input_mode_schema();
    $r = qr("SELECT field_kind FROM product_field_config
             WHERE product_id=? AND context='production'
               AND field_kind IN ('product_snippets','product_snippets_off') AND is_active=1
             LIMIT 1", 'i', [(int)$productId])->fetch_assoc();
    return $r && $r['field_kind'] === 'product_snippets';
}

/**
 * รวม config จากหลังบ้าน + ประวัติจริง → ชุดฟิลด์ที่ใช้จริงในฟอร์ม
 *
 * - ถ้าตั้งค่าหลังบ้านไว้แล้ว (production): ใช้เฉพาะฟิลด์จาก config ตามลำดับที่ตั้ง
 *   แล้วเติม options จากประวัติเข้าไปในแต่ละฟิลด์ (ยังพิมพ์ค่าใหม่ได้อิสระที่ฟอร์ม)
 * - ข้ามชนิด fw/lot/made_by (เป็นช่องมาตรฐานแยกแสดงตามสวิตช์หลังบ้าน)
 * - ข้าม checklist / watch_alert / watch_alert_cfg (ไม่ใช่ช่องกรอกในฟอร์มผลิต)
 * - context อื่น: พฤติกรรมเดิม (config ก่อน แล้วต่อด้วยฟิลด์ประวัติที่ไม่มีใน config)
 * - ถ้าไม่ได้ตั้ง: ใช้ประวัติล้วน
 *
 * @param int    $productId รุ่นสินค้า
 * @param string $context   production|ma|update
 * @return array<int, array{name:string,kind:string,options:array}>
 */
function effective_fields($productId, $context) {
    $derived = $context === 'ma' ? [] : ($context === 'update' ? derive_update_fields($productId) : derive_production_fields($productId));
    $derivedByName = [];
    foreach ($derived as $f) {
        $derivedByName[$f['name']] = $f;
    }

    $cfg = product_config_fields($productId, $context);
    if (!$cfg) {
        return $derived;
    }

    $out = [];
    $used = [];
    foreach ($cfg as $c) {
        // ข้ามชนิดที่ไม่ได้เป็นช่องกรอกในฟอร์ม:
        // - fw/lot/made_by (+ สถานะปิด) จัดการแยกด้วยสวิตช์หลังบ้าน
        // - checklist โหลดผ่าน effective_production_checklist()
        // - watch_alert / watch_alert_cfg เป็นแค่สวิตช์แจ้งเตือน MA (SD Card / RTC)
        //   ไม่ใช่ฟิลด์กรอก — เก็บใต้ context=production ใน settings แต่ห้ามโผล่ในฟอร์มผลิต
        if (in_array($c['kind'], [
            'fw', 'lot', 'fw_off', 'lot_off', 'made_by', 'made_by_off',
            'checklist', 'watch_alert', 'watch_alert_cfg',
            'product_snippets', 'product_snippets_off',
        ], true)) {
            continue;
        }
        $histOpts = isset($derivedByName[$c['name']]) ? $derivedByName[$c['name']]['options'] : [];
        $merged = $c['options'];
        foreach ($histOpts as $o) {
            if (!in_array($o, $merged, true)) {
                $merged[] = $o;
            }
        }
        $out[] = [
            'name' => $c['name'],
            'kind' => $c['kind'],
            'options' => $merged,
            'input_mode' => normalize_input_mode($c['input_mode'] ?? ''),
        ];
        $used[$c['name']] = true;
    }
    // หน้าบันทึกผลิต: ยึดฟิลด์จากหลังบ้านเป็นหลัก ไม่ปะปนฟิลด์ประวัติเกิน config
    if ($context === 'production') {
        return $out;
    }
    foreach ($derived as $f) {
        if (empty($used[$f['name']])) {
            if (!isset($f['input_mode'])) {
                $f['input_mode'] = 'chip_multi_free';
            }
            $out[] = $f;
        }
    }
    return $out;
}

/**
 * จับคู่ฟิลด์ config MA → ช่อง ok/replace/repair (รองรับชนิดเก่า "รายการตรวจ")
 *
 * @return 'ok'|'replace'|'repair'|'all'|null
 */
function ma_config_field_group($kind, $fieldName) {
    $kind = trim((string)$kind);
    $name = trim((string)$fieldName);
    if ($kind === 'ma_ok' || mb_strpos($name, 'ปกติ') !== false) {
        return 'ok';
    }
    if ($kind === 'ma_replace' || mb_strpos($name, 'เปลี่ยน') !== false) {
        return 'replace';
    }
    if ($kind === 'ma_repair' || (mb_strpos($name, 'ซ่อม') !== false && mb_strpos($name, 'เปลี่ยน') === false)) {
        return 'repair';
    }
    if ($kind === 'ma_item' || $kind === 'รายการตรวจ' || mb_strpos($kind, 'รายการ') === 0) {
        return 'all';
    }
    return null;
}

/** pool รายการ MA สำหรับ dropdown — ใช้เฉพาะ config หลังบ้าน (ไม่ปะประวัติเก่า) */
function effective_ma_pool($productId, $group = null) {
    if (!has_product_config($productId, 'ma')) {
        return derive_ma_pool($productId);
    }
    $cfg = [];
    foreach (product_config_fields($productId, 'ma') as $c) {
        $fg = ma_config_field_group($c['kind'], $c['name']);
        if ($fg === null) {
            continue;
        }
        if ($group !== null && $fg !== 'all' && $fg !== $group) {
            continue;
        }
        $cfg = array_merge($cfg, $c['options']);
    }
    return array_values(array_filter(array_unique($cfg), function ($x) {
        return trim($x) !== '' && $x !== 'items';
    }));
}

/**
 * รุ่นนี้เปิดใช้ฟิลด์ Firmware หรือไม่ — ยึดสวิตช์หน้า "บันทึกผลิต" (fw / fw_off)
 *
 * @param int $productId
 * @return bool
 */
function product_show_fw($productId) {
    $std = product_std_fields($productId);
    return $std['decided'] ? ($std['fw'] !== null) : true;
}

/**
 * รูปแบบช่อง Firmware — จาก config หลังบ้านส่วนผลิต
 *
 * @param int $productId
 * @return string
 */
function effective_fw_input_mode($productId) {
    $std = product_std_fields($productId);
    return $std['fw']
        ? normalize_input_mode($std['fw']['input_mode'])
        : 'chip_single_free';
}

/**
 * ตัวเลือก Firmware ร่วมกันทุกหน้า (ผลิต / MA / อัปเดต)
 *
 * รวม config หลังบ้าน + ประวัติ fw_version จาก production / update / MA ของรุ่น
 *
 * @param int $productId
 * @return array<int, string>
 */
function effective_fw_options($productId) {
    $opts = [];
    $std = product_std_fields($productId);
    if (!empty($std['fw']['options'])) {
        foreach ($std['fw']['options'] as $o) {
            $o = trim((string) $o);
            if ($o !== '' && !in_array($o, $opts, true)) {
                $opts[] = $o;
            }
        }
    }
    $pid = (int) $productId;
    $queries = [
        "SELECT pr.fw_version v FROM production_records pr JOIN assets a ON a.id=pr.asset_id
         WHERE a.product_id=? AND pr.fw_version IS NOT NULL AND TRIM(pr.fw_version)<>'' AND pr.fw_version<>'-'
         GROUP BY pr.fw_version ORDER BY MAX(pr.id) DESC LIMIT 12",
        "SELECT u.new_value v FROM update_logs u JOIN assets ax ON ax.id=u.asset_id
         WHERE ax.product_id=? AND u.update_type='firmware' AND u.new_value IS NOT NULL AND TRIM(u.new_value)<>''
         GROUP BY u.new_value ORDER BY MAX(u.id) DESC LIMIT 12",
        "SELECT m.fw_version v FROM ma_records m JOIN assets a ON a.id=m.asset_id
         WHERE a.product_id=? AND m.fw_version IS NOT NULL AND TRIM(m.fw_version)<>''
         GROUP BY m.fw_version ORDER BY MAX(m.id) DESC LIMIT 12",
    ];
    foreach ($queries as $sql) {
        $res = qr($sql, 'i', [$pid]);
        while ($r = $res->fetch_assoc()) {
            $v = trim((string) ($r['v'] ?? ''));
            if ($v !== '' && !in_array($v, $opts, true)) {
                $opts[] = $v;
            }
        }
    }
    return $opts;
}

function effective_ma_fw_options($productId) {
    return product_show_fw($productId) ? effective_fw_options($productId) : [];
}

/**
 * อ่านค่า FW จาก POST — ถ้าปิดฟิลด์ใน config ให้คงค่าเดิม (ตอนแก้ไข) หรือว่าง (ตอนใหม่)
 *
 * @param int $productId
 * @param string $preserveExisting ค่า fw เดิมจาก record (ตอนแก้ไข)
 * @return string
 */
function resolve_fw_post_value($productId, $preserveExisting = '') {
    if (product_show_fw($productId)) {
        return trim((string) ($_POST['fw_version'] ?? ''));
    }
    return trim((string) $preserveExisting);
}

/** ฟิลด์ฟอร์ม MA สำหรับแสดงในหลังบ้าน (preview / default) */
function derive_ma_form_fields($productId) {
    $pool = derive_ma_pool($productId);
    return [
        ['name' => 'รายการ ✅ ปกติ', 'kind' => 'ma_ok', 'options' => $pool, 'input_mode' => 'chip_multi_free'],
        ['name' => 'รายการ 🔄 เปลี่ยนอะไหล่', 'kind' => 'ma_replace', 'options' => $pool, 'input_mode' => 'chip_multi_free'],
        ['name' => 'รายการ 🔧 ซ่อม', 'kind' => 'ma_repair', 'options' => $pool, 'input_mode' => 'chip_multi_free'],
        ['name' => 'สถานะเครื่อง', 'kind' => 'ma_status', 'options' => ['เครื่องเช่า', 'เครื่องสำรอง'], 'input_mode' => 'chip_single'],
        ['name' => 'หมายเหตุ', 'kind' => 'ma_remark', 'options' => [], 'input_mode' => 'text'],
    ];
}

/** อ่าน config ฟิลด์ MA ที่ใช้จริงในฟอร์ม */
function effective_ma_form_fields($productId) {
    if (!has_product_config($productId, 'ma')) {
        return derive_ma_form_fields($productId);
    }
    $cfg = product_config_fields($productId, 'ma');
    return $cfg ?: derive_ma_form_fields($productId);
}

require_once __DIR__ . '/includes/part_stock_bridge.php';

require_once dirname(__DIR__) . '/shared/activity_log_core.php';
require_once dirname(__DIR__) . '/shared/datetime_helpers.php';
require_once dirname(__DIR__) . '/shared/error_messages.php';
require_once dirname(__DIR__) . '/shared/stock_status.php';
require_once dirname(__DIR__) . '/shared/line_notify_core.php';
require_once dirname(__DIR__) . '/shared/line_flex_templates.php';
require_once dirname(__DIR__) . '/shared/line_notify_jobs.php';
ensure_ma_datetime_schema();
ensure_ma_status_schema();
ensure_asset_status_sold_schema();
activity_log_ensure_schema();
line_notify_ensure_schema();
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['profile'])) {
    activity_log_register_post_shutdown('production', actor_name());
    activity_log_register_page_view_shutdown('production', actor_name());
}
