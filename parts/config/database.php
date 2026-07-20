<?php
/**
 * config/database.php — เชื่อมต่อ biton_tech_parts และกำหนด BASE_PATH ของแอป
 *
 * BASE_PATH อ่านจาก SCRIPT_NAME อัตโนมัติ รองรับทั้ง
 * - monorepo: /production/parts
 * - standalone XAMPP: /parts
 */

require_once dirname(__DIR__, 2) . '/shared/app_paths.php';
require_once app_parts_secrets_path();
ini_set('error_log', app_error_log_path());

/**
 * คืน web path ของแอป parts จาก request ปัจจุบัน
 *
 * @return string เช่น /production/parts หรือ /parts
 */
function parts_app_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/parts');
    if ($pos !== false) {
        $cached = substr($script, 0, $pos + 6);
        return $cached;
    }
    $cached = '/production/parts';
    return $cached;
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', parts_app_base_path());
}

/**
 * ตรวจว่า request มาจาก localhost dev หรือไม่
 *
 * @return bool
 */
function parts_is_localhost_request(): bool
{
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

/**
 * บootstrap session สำหรับ localhost — ไม่บังคับ SSO ภายนอก
 *
 * @return void
 */
function parts_localhost_bootstrap_session(): void
{
    if (!parts_is_localhost_request() || isset($_SESSION['profile'])) {
        return;
    }
    $_SESSION['profile'] = (object)[
        'login_name'  => 'Tom',
        'display_name'=> 'Tom',
    ];
}

/**
 * คืน PDO connection ไป biton_tech_parts (singleton ต่อ request)
 *
 * @return PDO
 */
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
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('SET CHARACTER SET utf8mb4');
    }
    return $pdo;
}
