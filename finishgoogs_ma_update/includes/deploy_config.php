<?php
/**
 * includes/deploy_config.php — บันทึก/ทดสอบ config ก่อน deploy ขึ้น server
 *
 * ใช้โดย server_config.php — เขียน secrets PHP, config.paths.php และตรวจสุขภาพระบบ
 */

require_once dirname(__DIR__, 2) . '/shared/app_paths.php';

/** @var string ค่า placeholder เมื่อไม่เปลี่ยนรหัสผ่าน */
const DEPLOY_PASSWORD_PLACEHOLDER = '••••••••';

/**
 * โครงสร้าง DB ว่างสำหรับฟอร์ม
 *
 * @return array{host:string,db:string,user:string,pass:string}
 */
function deploy_empty_db_block(): array
{
    return ['host' => '127.0.0.1', 'db' => '', 'user' => '', 'pass' => ''];
}

/**
 * อ่าน finishgoogs secrets เป็น array (ไม่ cache ถ้าไฟล์เปลี่ยน)
 *
 * @return array<string,mixed>
 */
function deploy_load_finishgoogs_secrets(): array
{
    $path = app_finishgoogs_secrets_path();
    if (!is_file($path)) {
        return [
            'production' => deploy_empty_db_block(),
            'stockparts' => deploy_empty_db_block(),
            'techparts'  => deploy_empty_db_block(),
            'leasing'    => deploy_empty_db_block(),
        ];
    }
    $data = require $path;
    if (!is_array($data)) {
        return [
            'production' => deploy_empty_db_block(),
            'stockparts' => deploy_empty_db_block(),
            'techparts'  => deploy_empty_db_block(),
            'leasing'    => deploy_empty_db_block(),
        ];
    }
    foreach (['production', 'stockparts', 'techparts', 'leasing'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = deploy_empty_db_block();
            continue;
        }
        $data[$key] = array_merge(deploy_empty_db_block(), $data[$key]);
    }
    return $data;
}

/**
 * อ่านค่า DB ของ Parts จาก parts.secrets.php
 *
 * @return array{host:string,db:string,user:string,pass:string,charset:string}
 */
function deploy_load_parts_secrets(): array
{
    $defaults = array_merge(deploy_empty_db_block(), ['charset' => 'utf8mb4']);
    $path = app_parts_secrets_path();
    if (!is_file($path)) {
        $tech = deploy_load_finishgoogs_secrets()['techparts'] ?? deploy_empty_db_block();
        return [
            'host'    => (string)($tech['host'] ?? '127.0.0.1'),
            'db'      => (string)($tech['db'] ?? ''),
            'user'    => (string)($tech['user'] ?? ''),
            'pass'    => (string)($tech['pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];
    }

    if (!defined('DB_HOST')) {
        require $path;
    }
    return [
        'host'    => defined('DB_HOST') ? (string)DB_HOST : $defaults['host'],
        'db'      => defined('DB_NAME') ? (string)DB_NAME : $defaults['db'],
        'user'    => defined('DB_USER') ? (string)DB_USER : $defaults['user'],
        'pass'    => defined('DB_PASS') ? (string)DB_PASS : $defaults['pass'],
        'charset' => defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4',
    ];
}

/**
 * รวมค่าจากฟอร์ม POST เป็นชุด config ที่พร้อมบันทึก
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function deploy_parse_form(array $post): array
{
    $existingFg = deploy_load_finishgoogs_secrets();
    $existingParts = deploy_load_parts_secrets();

    $paths = [
        'finishgoogs_secrets'      => trim((string)($post['path_finishgoogs_secrets'] ?? '')),
        'parts_secrets'            => trim((string)($post['path_parts_secrets'] ?? '')),
        'error_log'                => trim((string)($post['path_error_log'] ?? '')),
        'sso_production_login_url' => trim((string)($post['sso_production_login_url'] ?? '')),
        'sso_parts_login_url'      => trim((string)($post['sso_parts_login_url'] ?? '')),
        'uploads_public_base'      => trim((string)($post['uploads_public_base'] ?? '')),
    ];

    $fg = [];
    foreach (['production', 'stockparts', 'techparts', 'leasing'] as $block) {
        $fg[$block] = [
            'host' => trim((string)($post[$block . '_host'] ?? '')),
            'db'   => trim((string)($post[$block . '_db'] ?? '')),
            'user' => trim((string)($post[$block . '_user'] ?? '')),
            'pass' => deploy_resolve_password_field(
                (string)($post[$block . '_pass'] ?? ''),
                (string)($existingFg[$block]['pass'] ?? '')
            ),
        ];
    }

    $partsPass = deploy_resolve_password_field(
        (string)($post['parts_pass'] ?? ''),
        (string)$existingParts['pass']
    );

    $syncPartsFromTech = !empty($post['parts_sync_techparts']);
    $partsDb = [
        'host'    => $syncPartsFromTech ? $fg['techparts']['host'] : trim((string)($post['parts_host'] ?? '')),
        'db'      => $syncPartsFromTech ? $fg['techparts']['db'] : trim((string)($post['parts_db'] ?? '')),
        'user'    => $syncPartsFromTech ? $fg['techparts']['user'] : trim((string)($post['parts_user'] ?? '')),
        'pass'    => $syncPartsFromTech ? $fg['techparts']['pass'] : $partsPass,
        'charset' => trim((string)($post['parts_charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
    ];

    return [
        'paths'           => $paths,
        'finishgoogs'     => $fg,
        'parts'           => $partsDb,
        'parts_sync_tech' => $syncPartsFromTech,
    ];
}

/**
 * ถ้าผู้ใช้ไม่กรอกรหัสใหม่ ใช้ค่าเดิม
 *
 * @param string $posted
 * @param string $existing
 * @return string
 */
function deploy_resolve_password_field(string $posted, string $existing): string
{
    $posted = trim($posted);
    if ($posted === '' || $posted === DEPLOY_PASSWORD_PLACEHOLDER) {
        return $existing;
    }
    return $posted;
}

/**
 * validate ค่าก่อนบันทึก
 *
 * @param array<string,mixed> $cfg
 * @return array<int,string> รายการ error
 */
function deploy_validate_config(array $cfg): array
{
    $errors = [];
    $paths = $cfg['paths'] ?? [];
    foreach (['finishgoogs_secrets', 'parts_secrets', 'error_log'] as $pk) {
        if (empty($paths[$pk])) {
            $errors[] = 'กรุณาระบุ path: ' . $pk;
        }
    }
    foreach (['production', 'stockparts', 'techparts'] as $block) {
        $b = $cfg['finishgoogs'][$block] ?? [];
        if (empty($b['host']) || empty($b['db']) || empty($b['user'])) {
            $errors[] = 'กรุณากรอก host/db/user ของ ' . $block;
        }
    }
    $lease = $cfg['finishgoogs']['leasing'] ?? [];
    $leaseAny = trim((string)($lease['host'] ?? '')) !== ''
        || trim((string)($lease['db'] ?? '')) !== ''
        || trim((string)($lease['user'] ?? '')) !== '';
    if ($leaseAny && (empty($lease['host']) || empty($lease['db']) || empty($lease['user']))) {
        $errors[] = 'กรุณากรอก host/db/user ของ leasing ให้ครบ หรือเว้นว่างทั้งหมด (ไม่บังคับ)';
    }
    $parts = $cfg['parts'] ?? [];
    if (empty($parts['host']) || empty($parts['db']) || empty($parts['user'])) {
        $errors[] = 'กรุณากรอกการเชื่อมต่อ Parts (biton_tech_parts)';
    }
    return $errors;
}

/**
 * สร้างเนื้อหา finishgoogs.secrets.php
 *
 * @param array<string,array<string,string>> $fg
 * @return string
 */
function deploy_build_finishgoogs_secrets_php(array $fg): string
{
    $export = var_export($fg, true);
    return "<?php\n"
        . "/**\n * finishgoogs.secrets.php — สร้างโดย server_config.php\n * อย่า commit ไฟล์นี้\n */\n"
        . "return {$export};\n";
}

/**
 * สร้างเนื้อหา parts.secrets.php
 *
 * @param array{host:string,db:string,user:string,pass:string,charset:string} $parts
 * @return string
 */
function deploy_build_parts_secrets_php(array $parts): string
{
    $lines = [
        "<?php",
        "/** parts.secrets.php — สร้างโดย server_config.php · อย่า commit */",
        "define('DB_HOST', " . var_export($parts['host'], true) . ");",
        "define('DB_NAME', " . var_export($parts['db'], true) . ");",
        "define('DB_USER', " . var_export($parts['user'], true) . ");",
        "define('DB_PASS', " . var_export($parts['pass'], true) . ");",
        "define('DB_CHARSET', " . var_export($parts['charset'], true) . ");",
        "",
    ];
    return implode("\n", $lines);
}

/**
 * สร้างเนื้อหา config.paths.php
 *
 * @param array<string,string> $paths
 * @return string
 */
function deploy_build_paths_php(array $paths): string
{
    $export = var_export($paths, true);
    return "<?php\n/** config.paths.php — สร้างโดย server_config.php */\nreturn {$export};\n";
}

/**
 * ล้าง OPcache หลังเขียนไฟล์ config (ป้องกัน require ค่าเก่าหลังบันทึก)
 *
 * @param string $path
 * @return void
 */
function deploy_invalidate_opcache(string $path): void
{
    if (function_exists('opcache_invalidate') && is_file($path)) {
        @opcache_invalidate($path, true);
    }
}

/**
 * เขียนไฟล์อย่างปลอดภัย (สร้างโฟลเดอร์ถ้ายังไม่มี)
 *
 * @param string $path
 * @param string $content
 * @return array{ok:bool,message:string}
 */
function deploy_write_file(string $path, string $content): array
{
    $path = str_replace('\\', '/', $path);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'สร้างโฟลเดอร์ไม่ได้: ' . $dir];
        }
    }
    if (is_file($path) && !is_writable($path)) {
        return ['ok' => false, 'message' => 'ไฟล์ไม่สามารถเขียนได้: ' . $path];
    }
    if (!is_file($path) && !is_writable($dir)) {
        return ['ok' => false, 'message' => 'โฟลเดอร์ไม่สามารถเขียนได้: ' . $dir];
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'เขียนไฟล์ไม่สำเร็จ: ' . $path];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'ย้ายไฟล์ tmp ไม่สำเร็จ: ' . $path];
    }
    @chmod($path, 0640);
    deploy_invalidate_opcache($path);
    return ['ok' => true, 'message' => 'OK'];
}

/**
 * บันทึก config ทั้งหมดลง disk
 *
 * @param array<string,mixed> $cfg
 * @return array{ok:bool,messages:array<int,string>}
 */
function deploy_save_config(array $cfg): array
{
    $messages = [];
    $paths = $cfg['paths'];

    $fgPath = $paths['finishgoogs_secrets'];
    $partsPath = $paths['parts_secrets'];
    $pathsFile = app_paths_config_file();

    $writes = [
        [$fgPath, deploy_build_finishgoogs_secrets_php($cfg['finishgoogs'])],
        [$partsPath, deploy_build_parts_secrets_php($cfg['parts'])],
        [$pathsFile, deploy_build_paths_php($paths)],
    ];

    foreach ($writes as [$file, $content]) {
        $res = deploy_write_file($file, $content);
        $messages[] = ($res['ok'] ? '✓ ' : '✗ ') . basename($file) . ': ' . $res['message'];
        if (!$res['ok']) {
            return ['ok' => false, 'messages' => $messages];
        }
    }

    return ['ok' => true, 'messages' => $messages];
}

/**
 * ทดสอบเชื่อมต่อ MySQL
 *
 * @param array{host:string,db:string,user:string,pass:string} $cfg
 * @return array{ok:bool,message:string,detail:string}
 */
function deploy_test_mysqli(array $cfg): array
{
    $host = (string)($cfg['host'] ?? '');
    $db = (string)($cfg['db'] ?? '');
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');
    if ($host === '' || $db === '' || $user === '') {
        return ['ok' => false, 'message' => 'ข้อมูลไม่ครบ', 'detail' => ''];
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli($host, $user, $pass, $db);
    if ($mysqli->connect_errno) {
        return ['ok' => false, 'message' => 'เชื่อมต่อไม่ได้', 'detail' => $mysqli->connect_error];
    }
    $mysqli->set_charset('utf8mb4');
    $ver = '';
    if ($res = $mysqli->query('SELECT VERSION() AS v')) {
        $row = $res->fetch_assoc();
        $ver = (string)($row['v'] ?? '');
        $res->free();
    }
    $ac = 'unknown';
    if ($res = $mysqli->query('SELECT @@autocommit AS ac')) {
        $row = $res->fetch_assoc();
        $ac = (string)($row['ac'] ?? '');
        $res->free();
    }
    $mysqli->close();
    $detail = trim('MySQL ' . $ver . ($ac !== '' ? ' · autocommit=' . $ac : ''));
    return ['ok' => true, 'message' => 'เชื่อมต่อสำเร็จ', 'detail' => $detail];
}

/**
 * ทดสอบการเชื่อมต่อทุก DB จาก config ชุดหนึ่ง
 *
 * @param array<string,mixed> $cfg
 * @return array<string,array{ok:bool,message:string,detail:string}>
 */
function deploy_test_all_connections(array $cfg): array
{
    $out = [];
    foreach (['production', 'stockparts', 'techparts'] as $key) {
        $out[$key] = deploy_test_mysqli($cfg['finishgoogs'][$key] ?? deploy_empty_db_block());
    }
    // ฐานที่ไม่บังคับ — ยังไม่ตั้งค่าก็ข้ามไป ไม่ถือว่าพัง
    foreach (['leasing', 'maintenance'] as $key) {
        $optCfg = $cfg['finishgoogs'][$key] ?? deploy_empty_db_block();
        if (trim((string)($optCfg['host'] ?? '')) === ''
            || trim((string)($optCfg['db'] ?? '')) === ''
            || trim((string)($optCfg['user'] ?? '')) === '') {
            $out[$key] = ['ok' => true, 'message' => 'ข้าม (ไม่ได้ตั้งค่า)', 'detail' => ''];
        } else {
            $out[$key] = deploy_test_mysqli($optCfg);
        }
    }
    $out['parts'] = deploy_test_mysqli([
        'host' => $cfg['parts']['host'] ?? '',
        'db'   => $cfg['parts']['db'] ?? '',
        'user' => $cfg['parts']['user'] ?? '',
        'pass' => $cfg['parts']['pass'] ?? '',
    ]);
    return $out;
}

/**
 * ตรวจสุขภาพระบบก่อน deploy
 *
 * @return array<int,array{label:string,ok:bool,detail:string}>
 */
function deploy_health_checks(): array
{
    $checks = [];
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = [
        'label'  => 'PHP ' . PHP_VERSION . ' (≥ 7.4)',
        'ok'     => $phpOk,
        'detail' => $phpOk ? 'OK' : 'อัปเกรด PHP',
    ];

    foreach (['mysqli', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
        $ok = extension_loaded($ext);
        $checks[] = ['label' => 'extension ' . $ext, 'ok' => $ok, 'detail' => $ok ? 'OK' : 'ติดตั้ง/เปิดใช้'];
    }
    $gdOk = extension_loaded('gd');
    $checks[] = ['label' => 'extension gd (อัปโหลดรูป)', 'ok' => $gdOk, 'detail' => $gdOk ? 'OK' : 'แนะนำสำหรับ upload รูป'];

    $uploadsDir = dirname(__DIR__) . '/uploads';
    $uploadsWritable = is_dir($uploadsDir) && is_writable($uploadsDir);
    $checks[] = [
        'label'  => 'โฟลเดอร์ uploads/ เขียนได้',
        'ok'     => $uploadsWritable,
        'detail' => $uploadsDir,
    ];

    $fgPath = app_finishgoogs_secrets_path();
    $checks[] = [
        'label'  => 'finishgoogs.secrets.php มีอยู่',
        'ok'     => is_file($fgPath),
        'detail' => $fgPath,
    ];

    $partsPath = app_parts_secrets_path();
    $checks[] = [
        'label'  => 'parts.secrets.php มีอยู่',
        'ok'     => is_file($partsPath),
        'detail' => $partsPath,
    ];

    $pathsFile = app_paths_config_file();
    $checks[] = [
        'label'  => 'config.paths.php (local override)',
        'ok'     => is_file($pathsFile),
        'detail' => is_file($pathsFile) ? $pathsFile : 'ยังไม่มี — จะสร้างเมื่อบันทึกจากหน้านี้',
    ];

    $logPath = app_error_log_path();
    $logDir = dirname(str_replace('\\', '/', $logPath));
    $logOk = is_dir($logDir) ? is_writable($logDir) : @mkdir($logDir, 0750, true);
    $checks[] = [
        'label'  => 'โฟลเดอร์ error log เขียนได้',
        'ok'     => (bool)$logOk,
        'detail' => $logDir,
    ];

    $checks[] = [
        'label'  => 'Timezone Asia/Bangkok',
        'ok'     => date_default_timezone_get() === 'Asia/Bangkok',
        'detail' => date_default_timezone_get(),
    ];

    $checks[] = [
        'label'  => 'Production BASE_URL',
        'ok'     => defined('BASE_URL') && BASE_URL !== '',
        'detail' => defined('BASE_URL') ? (string)BASE_URL : '-',
    ];

    return $checks;
}

/**
 * คืนค่า config ปัจจุบันสำหรับแสดงในฟอร์ม
 *
 * @return array<string,mixed>
 */
function deploy_form_defaults(): array
{
    $paths = app_paths();
    $fg = deploy_load_finishgoogs_secrets();
    $parts = deploy_load_parts_secrets();
    $tech = $fg['techparts'];
    $partsSync = ($parts['host'] === $tech['host']
        && $parts['db'] === $tech['db']
        && $parts['user'] === $tech['user']
        && $parts['pass'] === $tech['pass']);

    return [
        'paths'           => $paths,
        'finishgoogs'     => $fg,
        'parts'           => $parts,
        'parts_sync_tech' => $partsSync,
    ];
}
