<?php
/**
 * shared/app_paths.php — โหลด path สำหรับ secrets, error log และ URL ที่ใช้ร่วมกัน
 *
 * ลำดับการอ่านค่า: ค่า default → config.paths.php (local) → environment variable
 * ใช้โดย finishgoogs_ma_update, parts และ shared/activity_log_core.php
 *
 * Flow:
 *   require app_paths.php → app_finishgoogs_secrets_path() → require secrets
 */

// ─ Defaults ───────────────────────────────────────────────────────────────────

/**
 * คืนค่า path/URL เริ่มต้นสำหรับ dev (Windows AppServ)
 *
 * @return array<string,string>
 */
function app_paths_defaults(): array
{
    return [
        'finishgoogs_secrets'     => 'D:/AppServ/secrets/production/finishgoogs.secrets.php',
        'parts_secrets'           => 'D:/AppServ/secrets/production/parts.secrets.php',
        'error_log'               => 'D:/Ops/logs/php-error.log',
        'sso_production_login_url'=> 'https://bit-online.net/bitlogin/bitlink.php',
        'sso_parts_login_url'     => 'https://bit-online.net/bitlogin/index.php',
        'uploads_public_base'     => '',
        'line_secrets'            => 'D:/AppServ/secrets/production/line.secrets.php',
    ];
}

/**
 * ไฟล์ override local (gitignore) — อยู่ใต้ finishgoogs_ma_update/
 *
 * @return string
 */
function app_paths_config_file(): string
{
    return dirname(__DIR__) . '/finishgoogs_ma_update/config.paths.php';
}

/**
 * โหลดและ merge path config (cache ต่อ request)
 *
 * @return array<string,string>
 */
function app_paths(): array
{
    static $merged = null;
    if ($merged !== null) {
        return $merged;
    }

    $merged = app_paths_defaults();
    $file = app_paths_config_file();
    if (is_file($file)) {
        $local = require $file;
        if (is_array($local)) {
            foreach ($local as $key => $val) {
                if ($val !== null && $val !== '') {
                    $merged[$key] = (string)$val;
                }
            }
        }
    }

    $envMap = [
        'PRODUCTION_SECRETS_PATH' => 'finishgoogs_secrets',
        'PARTS_SECRETS_PATH'      => 'parts_secrets',
        'PHP_ERROR_LOG_PATH'      => 'error_log',
        'LINE_SECRETS_PATH'       => 'line_secrets',
    ];
    foreach ($envMap as $envKey => $cfgKey) {
        $envVal = getenv($envKey);
        if ($envVal !== false && $envVal !== '') {
            $merged[$cfgKey] = (string)$envVal;
        }
    }

    return $merged;
}

/**
 * อ่านค่า path เดียวจาก config
 *
 * @param string $key
 * @return string
 */
function app_path(string $key): string
{
    $paths = app_paths();
    return isset($paths[$key]) ? (string)$paths[$key] : '';
}

/**
 * path ไป finishgoogs.secrets.php
 *
 * @return string
 */
function app_finishgoogs_secrets_path(): string
{
    return app_path('finishgoogs_secrets');
}

/**
 * path ไป parts.secrets.php
 *
 * @return string
 */
function app_parts_secrets_path(): string
{
    return app_path('parts_secrets');
}

/**
 * path ไฟล์ error log ของ PHP
 *
 * @return string
 */
function app_error_log_path(): string
{
    return app_path('error_log');
}

/**
 * URL SSO สำหรับระบบ Production
 *
 * @return string
 */
function app_sso_production_login_url(): string
{
    $url = app_path('sso_production_login_url');
    return $url !== '' ? $url : 'https://bit-online.net/bitlogin/bitlink.php';
}

/**
 * URL SSO สำหรับแอป Parts
 *
 * @return string
 */
function app_sso_parts_login_url(): string
{
    $url = app_path('sso_parts_login_url');
    return $url !== '' ? $url : 'https://bit-online.net/bitlogin/index.php';
}

/**
 * URL สาธารณะของโฟลเดอร์ uploads (ลงท้ายด้วย /)
 *
 * @return string
 */
function app_uploads_public_base(): string
{
    $custom = trim(app_path('uploads_public_base'));
    if ($custom !== '') {
        return rtrim(str_replace('\\', '/', $custom), '/') . '/';
    }
    return '/production/finishgoogs_ma_update/uploads/';
}

/**
 * path ไป line.secrets.php (LINE Messaging API)
 *
 * @return string
 */
function app_line_secrets_path(): string
{
    return app_path('line_secrets');
}
