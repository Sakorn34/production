<?php
/**
 * shared/line_notify_core.php — ระบบแจ้งเตือน LINE Messaging API (outbox + retry + dedup)
 *
 * วัตถุประสงค์: คิวข้อความ Flex, ส่ง push ไป LINE, บันทึก log และป้องกันซ้ำ
 * ใช้โดย finishgoogs_ma_update, parts และ cron worker
 *
 * Flow:
 *   line_notify_dispatch(event, payload) → outbox → line_notify_process_outbox() → LINE API
 */

require_once __DIR__ . '/app_paths.php';

// ─ Constants ─────────────────────────────────────────────────────────────────

/** @var int จำนวนครั้งส่งสูงสุดก่อน mark dead */
const LINE_NOTIFY_MAX_ATTEMPTS = 5;

/** @var array<int,int> วินาทีรอก่อน retry ตาม attempt (0-based) */
const LINE_NOTIFY_RETRY_DELAYS = [30, 120, 600, 3600];

/** @var array<string,int> TTL dedup ต่อ event (วินาที) */
const LINE_NOTIFY_DEDUP_TTL = [
    'production.problem_found'      => 86400,
    'stock.low_threshold'           => 86400,
    'ma.repair_required'            => 31536000,
    'stock.manual_withdraw'         => 31536000,
    'production.summary.daily'      => 86400,
    'production.summary.daily_update' => 86400,
    'production.summary.weekly'     => 604800,
    'production.summary.monthly'    => 2678400,
    'line.test'                     => 60,
];

/** @var array<string,string> ป้าย event สำหรับ UI */
const LINE_NOTIFY_EVENT_LABELS = [
    'production.problem_found'        => 'พบปัญหาตอนผลิต',
    'stock.low_threshold'               => 'อะไหล่ใกล้หมด',
    'ma.repair_required'                => 'MA ต้องซ่อม',
    'stock.manual_withdraw'             => 'เบิกอะไหล่ (manual)',
    'production.summary.daily'          => 'สรุปผลิตรายวัน',
    'production.summary.daily_update'   => 'อัปเดตผลิตหลัง 17:30',
    'production.summary.weekly'         => 'สรุปผลิตรายสัปดาห์',
    'production.summary.monthly'        => 'สรุปผลิตรายเดือน',
    'line.test'                         => 'ทดสอบการส่ง',
];

// ─ Config ────────────────────────────────────────────────────────────────────

/**
 * โหลด line.secrets.php (cache ต่อ request)
 *
 * @return array<string,mixed>
 */
function line_notify_config(bool $reload = false): array
{
    static $cfg = null;
    if ($reload) {
        $cfg = null;
    }
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        'channel_access_token'  => '',
        'channel_secret'        => '',
        'default_recipient_id'  => '',
        'enabled'               => false,
        'public_production_url' => '',
        'public_parts_url'      => '',
        'events'                => [],
    ];
    $path = !empty($GLOBALS['_line_notify_config_override_path'])
        ? (string)$GLOBALS['_line_notify_config_override_path']
        : app_line_secrets_path();
    if (!is_file($path)) {
        $cfg = $defaults;
        return $cfg;
    }
    if ($reload && function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        $cfg = $defaults;
        return $cfg;
    }
    $cfg = array_merge($defaults, $loaded);
    if (!is_array($cfg['events'])) {
        $cfg['events'] = [];
    }
    return $cfg;
}

/**
 * ตรวจว่าเปิดใช้งานแจ้งเตือน (ทั้งระบบหรือเฉพาะ event)
 *
 * @param string|null $eventKey
 * @return bool
 */
function line_notify_is_enabled(?string $eventKey = null): bool
{
    $cfg = line_notify_config();
    if (empty($cfg['enabled'])) {
        return false;
    }
    if ($eventKey === null || $eventKey === '') {
        return true;
    }
    $events = $cfg['events'] ?? [];
    if ($events === []) {
        return true;
    }
    if (!array_key_exists($eventKey, $events)) {
        return true;
    }
    return !empty($events[$eventKey]);
}

/**
 * Host สาธารณะสำหรับ deep link ใน Flex (LINE ต้องการ http/https)
 *
 * @return string เช่น http://localhost หรือ https://bit-online.net
 */
function line_notify_public_host(): string
{
    $cfg = line_notify_config();
    $host = trim((string)($cfg['public_site_host'] ?? ''));
    if ($host !== '') {
        return rtrim(str_replace('\\', '/', $host), '/');
    }
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return 'http://localhost';
}

/**
 * แปลง path/URL ให้เป็น absolute http(s) สำหรับ action.uri ของ LINE Flex
 *
 * @param string $raw            ค่าจาก secrets, BASE_URL หรือ path บน disk
 * @param string $webPathFallback path web เช่น /production/finishgoogs_ma_update
 * @return string
 */
function line_notify_normalize_public_url(string $raw, string $webPathFallback = ''): string
{
    $raw = trim(str_replace('\\', '/', $raw));
    if ($raw === '') {
        $raw = trim(str_replace('\\', '/', $webPathFallback));
    }
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw)) {
        return rtrim($raw, '/');
    }
    if (preg_match('#^[A-Za-z]:/#', $raw)) {
        $pos = stripos($raw, '/www/');
        if ($pos !== false) {
            $raw = substr($raw, $pos + 4);
        }
    }
    if ($raw !== '' && $raw[0] !== '/') {
        $raw = '/' . $raw;
    }
    return rtrim(line_notify_public_host(), '/') . $raw;
}

/**
 * URL ฐาน production สำหรับ deep link (CLI ใช้จาก secrets)
 *
 * @return string
 */
function line_notify_production_base_url(): string
{
    $cfg = line_notify_config();
    $custom = trim((string)($cfg['public_production_url'] ?? ''));
    $fallback = defined('BASE_URL') ? (string)BASE_URL : '/production/finishgoogs_ma_update';
    return line_notify_normalize_public_url($custom, $fallback);
}

/**
 * URL ฐาน parts สำหรับ deep link
 *
 * @return string
 */
function line_notify_parts_base_url(): string
{
    $cfg = line_notify_config();
    $custom = trim((string)($cfg['public_parts_url'] ?? ''));
    $fallback = defined('BASE_PATH') ? (string)BASE_PATH : '/production/parts';
    return line_notify_normalize_public_url($custom, $fallback);
}

/**
 * ทำความสะอาด URL สำหรับ action.uri ของ LINE Flex (ต้องเป็น https สาธารณะเท่านั้น)
 *
 * @param string $uri
 * @return string
 */
function line_notify_sanitize_https_uri(string $uri): string
{
    $uri = trim(str_replace('\\', '/', $uri));
    if ($uri === '') {
        return '';
    }

    // ตัด fragment จาก Excel/OneDrive ที่ติดมากับลิงก์สั่งซื้อ
    if (strpos($uri, '#') !== false) {
        $fragment = substr($uri, strpos($uri, '#'));
        if (preg_match('/#https?:\/\//i', $fragment) || preg_match('/Sheet\d+!/i', $fragment)) {
            $base = strstr($uri, '#', true);
            $uri = is_string($base) ? $base : $uri;
        }
    }

    if (!preg_match('#^https?://#i', $uri)) {
        if (isset($uri[0]) && $uri[0] === '/') {
            $uri = line_notify_normalize_public_url($uri, $uri);
        } else {
            return '';
        }
    }

    if (preg_match('#^http://#i', $uri)) {
        $uri = 'https://' . substr($uri, 7);
    }
    if (!preg_match('#^https://#i', $uri)) {
        return '';
    }

    $host = parse_url($uri, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }
    $hostLower = strtolower($host);
    if ($hostLower === 'localhost' || $hostLower === '127.0.0.1' || substr($hostLower, -6) === '.local') {
        return '';
    }
    if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $hostLower)) {
        return '';
    }

    return $uri;
}

// ─ DB ────────────────────────────────────────────────────────────────────────

/**
 * mysqli ไป DB production (ใช้ activity log connection)
 *
 * @return mysqli|null
 */
function line_notify_db()
{
    if (!function_exists('activity_log_db_connect')) {
        require_once __DIR__ . '/activity_log_core.php';
    }
    return activity_log_db_connect();
}

/**
 * สร้างตาราง notification_* ถ้ายังไม่มี
 *
 * @return void
 */
function line_notify_ensure_schema(): void
{
    $db = line_notify_db();
    if (!$db) {
        return;
    }
    $db->query("CREATE TABLE IF NOT EXISTS notification_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(64) NOT NULL,
        dedup_key VARCHAR(191) NOT NULL DEFAULT '',
        recipient_id VARCHAR(64) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        status ENUM('pending','sent','failed','dead') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        KEY idx_status_retry (status, next_retry_at),
        KEY idx_event_created (event_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS notification_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        outbox_id BIGINT UNSIGNED NULL,
        event_key VARCHAR(64) NOT NULL,
        recipient_id VARCHAR(64) NOT NULL,
        http_status SMALLINT NULL,
        line_request_id VARCHAR(64) NULL,
        error_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at),
        KEY idx_event (event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS notification_dedup (
        dedup_key VARCHAR(191) NOT NULL PRIMARY KEY,
        expires_at DATETIME NOT NULL,
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS notification_snapshots (
        snap_key VARCHAR(191) NOT NULL PRIMARY KEY,
        payload_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS notification_recipients (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(64) NOT NULL,
        recipient_id VARCHAR(64) NOT NULL,
        label VARCHAR(100) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_event_recipient (event_key, recipient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─ Dedup / Snapshot ──────────────────────────────────────────────────────────

/**
 * จอง dedup key — คืน false ถ้าซ้ำภายใน TTL
 *
 * @param string $dedupKey
 * @param int    $ttlSeconds
 * @return bool
 */
function line_notify_dedup_take(string $dedupKey, int $ttlSeconds): bool
{
    if ($dedupKey === '') {
        return true;
    }
    $db = line_notify_db();
    if (!$db) {
        return true;
    }
    line_notify_ensure_schema();
    $db->query("DELETE FROM notification_dedup WHERE expires_at < NOW()");
    $expires = date('Y-m-d H:i:s', time() + max(1, $ttlSeconds));
    $stmt = $db->prepare(
        'INSERT IGNORE INTO notification_dedup (dedup_key, expires_at) VALUES (?, ?)'
    );
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('ss', $dedupKey, $expires);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

/**
 * บันทึก snapshot (เช่น Round 1 รายวัน)
 *
 * @param string $snapKey
 * @param array<string,mixed> $data
 * @param int|null $ttlSeconds
 * @return void
 */
function line_notify_snapshot_save(string $snapKey, array $data, ?int $ttlSeconds = null): void
{
    $db = line_notify_db();
    if (!$db) {
        return;
    }
    line_notify_ensure_schema();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $expires = $ttlSeconds ? date('Y-m-d H:i:s', time() + $ttlSeconds) : null;
    $stmt = $db->prepare(
        'INSERT INTO notification_snapshots (snap_key, payload_json, expires_at)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json), created_at=NOW(), expires_at=VALUES(expires_at)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sss', $snapKey, $json, $expires);
    $stmt->execute();
    $stmt->close();
}

/**
 * อ่าน snapshot
 *
 * @param string $snapKey
 * @return array<string,mixed>|null
 */
function line_notify_snapshot_get(string $snapKey): ?array
{
    $db = line_notify_db();
    if (!$db) {
        return null;
    }
    line_notify_ensure_schema();
    $stmt = $db->prepare(
        'SELECT payload_json FROM notification_snapshots
         WHERE snap_key=? AND (expires_at IS NULL OR expires_at > NOW())'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $snapKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    $data = json_decode((string)$row['payload_json'], true);
    return is_array($data) ? $data : null;
}

/**
 * ลบ snapshot ที่หมดอายุ
 *
 * @return void
 */
function line_notify_snapshot_cleanup(): void
{
    $db = line_notify_db();
    if (!$db) {
        return;
    }
    $db->query('DELETE FROM notification_snapshots WHERE expires_at IS NOT NULL AND expires_at < NOW()');
}

// ─ Dispatch / Outbox ─────────────────────────────────────────────────────────

/**
 * คืน recipient ID สำหรับ event
 *
 * @param string $eventKey
 * @return string
 */
function line_notify_recipient(string $eventKey): string
{
    $db = line_notify_db();
    if ($db) {
        line_notify_ensure_schema();
        $stmt = $db->prepare(
            'SELECT recipient_id FROM notification_recipients
             WHERE event_key=? AND enabled=1 LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('s', $eventKey);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row && !empty($row['recipient_id'])) {
                return (string)$row['recipient_id'];
            }
        }
    }
    $cfg = line_notify_config();
    return trim((string)($cfg['default_recipient_id'] ?? ''));
}

/**
 * ใส่คิวแจ้งเตือน (ไม่ block — worker ส่งทีหลัง)
 *
 * @param string               $eventKey
 * @param array<string,mixed>  $payload
 * @param array<string,mixed>  $opts dedup_key, recipient_id, skip_dedup
 * @return int|null outbox id หรือ null ถ้าไม่ enqueue
 */
function line_notify_dispatch(string $eventKey, array $payload, array $opts = []): ?int
{
    if (!line_notify_is_enabled($eventKey)) {
        return null;
    }
    $recipient = trim((string)($opts['recipient_id'] ?? line_notify_recipient($eventKey)));
    if ($recipient === '') {
        error_log('[line_notify] skip ' . $eventKey . ': no recipient');
        return null;
    }

    $dedupKey = trim((string)($opts['dedup_key'] ?? ''));
    if ($dedupKey === '' && !empty($opts['auto_dedup'])) {
        $dedupKey = $eventKey . ':' . (string)($payload['entity_id'] ?? '') . ':' . date('Y-m-d');
    }
    if ($dedupKey !== '' && empty($opts['skip_dedup'])) {
        $ttl = (int)($opts['dedup_ttl'] ?? (LINE_NOTIFY_DEDUP_TTL[$eventKey] ?? 86400));
        if (!line_notify_dedup_take($dedupKey, $ttl)) {
            return null;
        }
    }

    $db = line_notify_db();
    if (!$db) {
        return null;
    }
    line_notify_ensure_schema();

    $payload['_event_key'] = $eventKey;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return null;
    }

    $stmt = $db->prepare(
        'INSERT INTO notification_outbox (event_key, dedup_key, recipient_id, payload_json, status, next_retry_at)
         VALUES (?, ?, ?, ?, \'pending\', NOW())'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ssss', $eventKey, $dedupKey, $recipient, $json);
    $ok = $stmt->execute();
    $id = $ok ? (int)$db->insert_id : null;
    $stmt->close();
    return $id;
}

/**
 * ประมวลผล outbox — ส่งข้อความที่ค้าง
 *
 * @param int $limit
 * @return array{sent:int,failed:int,dead:int,skipped:int}
 */
function line_notify_process_outbox(int $limit = 20): array
{
    $stats = ['sent' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0];
    $db = line_notify_db();
    if (!$db || !line_notify_is_enabled()) {
        return $stats;
    }
    line_notify_ensure_schema();

    if (!function_exists('line_flex_build_messages')) {
        require_once __DIR__ . '/line_flex_templates.php';
    }

    $limit = max(1, min(100, $limit));
    $sql = "SELECT id, event_key, recipient_id, payload_json, attempts
            FROM notification_outbox
            WHERE status IN ('pending','failed')
              AND next_retry_at <= NOW()
              AND attempts < " . LINE_NOTIFY_MAX_ATTEMPTS . "
            ORDER BY id ASC
            LIMIT {$limit}";
    $res = $db->query($sql);
    if (!$res) {
        return $stats;
    }

    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        $eventKey = (string)$row['event_key'];
        $recipient = (string)$row['recipient_id'];
        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload)) {
            line_notify_mark_outbox($id, 'dead', 'invalid payload_json');
            $stats['dead']++;
            continue;
        }

        try {
            $messages = line_flex_build_messages($eventKey, $payload);
        } catch (Throwable $e) {
            line_notify_mark_outbox($id, 'dead', 'build: ' . $e->getMessage());
            $stats['dead']++;
            continue;
        }
        if ($messages === []) {
            line_notify_mark_outbox($id, 'dead', 'empty messages');
            $stats['skipped']++;
            continue;
        }

        $allOk = true;
        foreach (array_chunk($messages, 5) as $chunk) {
            $push = line_notify_push($recipient, $chunk);
            line_notify_log_send($id, $eventKey, $recipient, $push);
            if (!$push['ok']) {
                $allOk = false;
                break;
            }
        }

        if ($allOk) {
            line_notify_mark_outbox($id, 'sent', null);
            $stats['sent']++;
        } else {
            $attempts = (int)$row['attempts'] + 1;
            if ($attempts >= LINE_NOTIFY_MAX_ATTEMPTS) {
                line_notify_mark_outbox($id, 'dead', $push['error'] ?? 'max attempts');
                $stats['dead']++;
            } else {
                $delay = LINE_NOTIFY_RETRY_DELAYS[min($attempts - 1, count(LINE_NOTIFY_RETRY_DELAYS) - 1)] ?? 3600;
                line_notify_mark_outbox($id, 'failed', $push['error'] ?? 'push failed', $attempts, $delay);
                $stats['failed']++;
            }
        }
    }
    return $stats;
}

/**
 * อัปเดตสถานะ outbox
 *
 * @param int         $id
 * @param string      $status
 * @param string|null $error
 * @param int|null    $attempts
 * @param int|null    $retryDelaySec
 * @return void
 */
function line_notify_mark_outbox(int $id, string $status, ?string $error, ?int $attempts = null, ?int $retryDelaySec = null): void
{
    $db = line_notify_db();
    if (!$db) {
        return;
    }
    $err = $error !== null ? mb_substr($error, 0, 500) : null;
    if ($status === 'sent') {
        $stmt = $db->prepare(
            'UPDATE notification_outbox SET status=?, sent_at=NOW(), last_error=NULL WHERE id=?'
        );
        if ($stmt) {
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $attemptsVal = $attempts ?? 0;
    $next = date('Y-m-d H:i:s', time() + ($retryDelaySec ?? 60));
    $stmt = $db->prepare(
        'UPDATE notification_outbox SET status=?, attempts=?, next_retry_at=?, last_error=? WHERE id=?'
    );
    if ($stmt) {
        $stmt->bind_param('sissi', $status, $attemptsVal, $next, $err, $id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * บันทึก log การส่ง
 *
 * @param int|null             $outboxId
 * @param string               $eventKey
 * @param string               $recipientId
 * @param array<string,mixed>  $pushResult
 * @return void
 */
function line_notify_log_send(?int $outboxId, string $eventKey, string $recipientId, array $pushResult): void
{
    $db = line_notify_db();
    if (!$db) {
        return;
    }
    $http = isset($pushResult['http_status']) ? (int)$pushResult['http_status'] : null;
    $reqId = isset($pushResult['request_id']) ? (string)$pushResult['request_id'] : null;
    $err = isset($pushResult['error']) ? mb_substr((string)$pushResult['error'], 0, 500) : null;
    $stmt = $db->prepare(
        'INSERT INTO notification_log (outbox_id, event_key, recipient_id, http_status, line_request_id, error_message)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $httpVal = $http !== null ? $http : 0;
    $outboxIdVal = $outboxId !== null ? $outboxId : 0;
    $reqId = $reqId ?? '';
    $err = $err ?? '';
    $stmt->bind_param('ississ', $outboxIdVal, $eventKey, $recipientId, $httpVal, $reqId, $err);
    $stmt->execute();
    $stmt->close();

    $level = !empty($pushResult['ok']) ? 'ok' : 'fail';
    error_log('[line_notify] ' . $level . ' event=' . $eventKey . ' http=' . ($http ?? '-'));
}

/**
 * หา path CA bundle สำหรับ curl บน Windows/AppServ
 *
 * @return string|null
 */
function line_notify_curl_ca_path(): ?string
{
    $cfg = line_notify_config();
    $custom = trim((string)($cfg['curl_ca_bundle'] ?? ''));
    if ($custom !== '' && is_file($custom)) {
        return $custom;
    }
    $iniCa = ini_get('curl.cainfo');
    if (is_string($iniCa) && $iniCa !== '' && is_file($iniCa)) {
        return $iniCa;
    }
    $candidates = [
        dirname(__DIR__, 2) . '/phpMyAdmin/libraries/certs/cacert.pem',
        'D:/AppServ/www/phpMyAdmin/libraries/certs/cacert.pem',
        'C:/AppServ/www/phpMyAdmin/libraries/certs/cacert.pem',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return str_replace('\\', '/', $path);
        }
    }
    return null;
}

/**
 * ตั้งค่า SSL ให้ curl (แก้ปัญหา unable to get local issuer certificate บน AppServ)
 *
 * @param resource $ch
 * @return void
 */
function line_notify_apply_curl_ssl($ch): void
{
    $cfg = line_notify_config();
    $verify = !array_key_exists('ssl_verify_peer', $cfg) || !empty($cfg['ssl_verify_peer']);
    if (!$verify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        return;
    }
    $ca = line_notify_curl_ca_path();
    if ($ca !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
}

/**
 * ส่ง push ไป LINE Messaging API
 *
 * @param string              $recipientId
 * @param array<int,array>    $messages
 * @return array{ok:bool,http_status?:int,request_id?:string,error?:string,body?:string}
 */
function line_notify_push(string $recipientId, array $messages): array
{
    $cfg = line_notify_config();
    $token = trim((string)($cfg['channel_access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'missing channel_access_token'];
    }
    if ($recipientId === '' || $messages === []) {
        return ['ok' => false, 'error' => 'missing recipient or messages'];
    }

    $body = json_encode(['to' => $recipientId, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return ['ok' => false, 'error' => 'json encode failed'];
    }

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
    ]);
    line_notify_apply_curl_ssl($ch);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    // AppServ/OpenSSL บางเครื่องยัง verify ไม่ผ่านแม้มี CAINFO — retry ครั้งเดียว (dev fallback)
    if ($resp === false && strpos($curlErr, 'SSL certificate problem') !== false) {
        curl_close($ch);
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
    }
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'http_status' => $http, 'error' => 'curl: ' . $curlErr];
    }

    $decoded = json_decode((string)$resp, true);
    $reqId = is_array($decoded) && isset($decoded['sentMessages'][0]['id'])
        ? (string)$decoded['sentMessages'][0]['id']
        : null;

    if ($http >= 200 && $http < 300) {
        return ['ok' => true, 'http_status' => $http, 'request_id' => $reqId, 'body' => (string)$resp];
    }
    $errMsg = is_array($decoded) && isset($decoded['message'])
        ? (string)$decoded['message']
        : mb_substr((string)$resp, 0, 200);
    return ['ok' => false, 'http_status' => $http, 'error' => $errMsg, 'body' => (string)$resp];
}

// ─ Helpers สำหรับ event hooks ────────────────────────────────────────────────

/**
 * แปลง icon_path จากตาราง parts เป็น public URL สำหรับ LINE Flex image
 *
 * @param string $iconPath path ใต้ uploads/ หรือ https ตรง
 * @return string
 */
function line_notify_part_icon_public_url(string $iconPath): string
{
    $iconPath = trim(str_replace('\\', '/', $iconPath));
    if ($iconPath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $iconPath)) {
        return line_notify_normalize_public_url($iconPath, '');
    }
    $base = rtrim(line_notify_production_base_url(), '/');
    $legacyFolders = [
        'Update_Images/', 'Parts_Images/', 'Menu Product_Images/', 'model appsheet_Images/',
        'model_Images/', 'NamePart_Images/', 'Sub Menu_Images/', 'Sub Product_Images/', 'Thumbnail_Images/',
    ];
    foreach ($legacyFolders as $lf) {
        if (strpos($iconPath, $lf) === 0) {
            $encoded = implode('/', array_map('rawurlencode', explode('/', $iconPath)));
            return line_notify_normalize_public_url($base . '/uploads/legacy/' . $encoded, '/uploads/legacy/' . $encoded);
        }
    }
    $rel = implode('/', array_map('rawurlencode', explode('/', ltrim($iconPath, '/'))));
    return line_notify_normalize_public_url($base . '/uploads/' . $rel, '/uploads/' . $rel);
}

/**
 * ดึง icon/link ของ parts จาก production DB (batch)
 *
 * @param array<int,string> $codes รหัสจาก biton_tech_parts.products.code
 * @param array<int,string> $names ชื่ออะไหล่ (fallback)
 * @return array{by_code: array<string,array{icon_url:string,link_url:string}>, by_name: array<string,array{icon_url:string,link_url:string}>}
 */
function line_notify_parts_media_lookup(array $codes, array $names): array
{
    $empty = ['by_code' => [], 'by_name' => []];
    if (!function_exists('qr')) {
        return $empty;
    }
    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
    $names = array_values(array_unique(array_filter(array_map('trim', $names))));
    if ($codes === [] && $names === []) {
        return $empty;
    }

    $conditions = [];
    $types = '';
    $params = [];
    if ($codes !== []) {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $conditions[] = "stock_code IN ({$ph})";
        $conditions[] = "part_code IN ({$ph})";
        $types .= str_repeat('s', count($codes) * 2);
        $params = array_merge($params, $codes, $codes);
    }
    if ($names !== []) {
        $ph = implode(',', array_fill(0, count($names), '?'));
        $conditions[] = "name IN ({$ph})";
        $types .= str_repeat('s', count($names));
        $params = array_merge($params, $names);
    }

    $sql = 'SELECT stock_code, part_code, name, icon_path, link FROM parts WHERE is_active=1 AND ('
        . implode(' OR ', $conditions) . ')';
    $res = qr($sql, $types, $params);
    if (!$res) {
        return $empty;
    }

    $out = ['by_code' => [], 'by_name' => []];
    while ($row = $res->fetch_assoc()) {
        $iconUrl = line_notify_part_icon_public_url((string)($row['icon_path'] ?? ''));
        $linkUrl = line_notify_sanitize_https_uri(trim((string)($row['link'] ?? '')));
        $entry = ['icon_url' => $iconUrl, 'link_url' => $linkUrl];
        $stockCode = trim((string)($row['stock_code'] ?? ''));
        $partCode = trim((string)($row['part_code'] ?? ''));
        $partName = trim((string)($row['name'] ?? ''));
        if ($stockCode !== '') {
            $out['by_code'][$stockCode] = $entry;
        }
        if ($partCode !== '' && !isset($out['by_code'][$partCode])) {
            $out['by_code'][$partCode] = $entry;
        }
        if ($partName !== '') {
            $out['by_name'][$partName] = $entry;
        }
    }
    return $out;
}

/**
 * เติม icon_url / link_url ให้รายการ low stock จากตาราง parts
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function line_notify_enrich_low_stock_items(array $items): array
{
    if ($items === []) {
        return [];
    }

    $codes = [];
    $names = [];
    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $iconPath = trim((string)($item['icon_path'] ?? ''));
        if ($iconPath !== '' && trim((string)($item['icon_url'] ?? '')) === '') {
            $items[$i]['icon_url'] = line_notify_part_icon_public_url($iconPath);
        }
        if (trim((string)($item['icon_url'] ?? '')) !== '') {
            continue;
        }
        $code = trim((string)($item['code'] ?? ''));
        $name = trim((string)($item['name'] ?? $item['name_part'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        } elseif ($name !== '') {
            $names[] = $name;
        }
    }

    $map = line_notify_parts_media_lookup($codes, $names);
    foreach ($items as $i => $item) {
        if (!is_array($item) || trim((string)($item['icon_url'] ?? '')) !== '') {
            continue;
        }
        $code = trim((string)($item['code'] ?? ''));
        $name = trim((string)($item['name'] ?? $item['name_part'] ?? ''));
        $media = null;
        if ($code !== '' && isset($map['by_code'][$code])) {
            $media = $map['by_code'][$code];
        } elseif ($name !== '' && isset($map['by_name'][$name])) {
            $media = $map['by_name'][$name];
        }
        if ($media === null) {
            continue;
        }
        if ($media['icon_url'] !== '') {
            $items[$i]['icon_url'] = $media['icon_url'];
        }
        if ($media['link_url'] !== '' && trim((string)($item['link_url'] ?? '')) === '') {
            $items[$i]['link_url'] = $media['link_url'];
        }
    }

    return $items;
}

/**
 * ตรวจสต็อกต่ำหลังเปลี่ยนจำนวน (Parts app)
 *
 * @param int $productId
 * @return void
 */
function line_notify_check_low_stock_product(int $productId): void
{
    if ($productId <= 0) {
        return;
    }
    if (!function_exists('line_notify_instant_enabled')) {
        require_once __DIR__ . '/line_notify_jobs.php';
    }
    if (!line_notify_instant_enabled('stock.low_threshold')) {
        return;
    }
    if (!isset($GLOBALS['line_notify_stock_db']) || !($GLOBALS['line_notify_stock_db'] instanceof PDO)) {
        return;
    }
    /** @var PDO $pdo */
    $pdo = $GLOBALS['line_notify_stock_db'];
    $stmt = $pdo->prepare('SELECT id, code, name, quantity, min_stock, unit FROM products WHERE id=?');
    $stmt->execute([$productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p || (int)$p['quantity'] > (int)$p['min_stock']) {
        return;
    }
    line_notify_dispatch('stock.low_threshold', [
        'product_id'   => (int)$p['id'],
        'code'         => (string)$p['code'],
        'name'         => (string)$p['name'],
        'quantity'     => (int)$p['quantity'],
        'min_stock'    => (int)$p['min_stock'],
        'unit'         => (string)$p['unit'],
        'entity_id'    => (int)$p['id'],
        'timestamp'    => date('d/m/Y H:i'),
    ], [
        'dedup_key' => 'stock.low_threshold:' . (int)$p['id'] . ':' . date('Y-m-d'),
    ]);
}

/**
 * ดึงรายการ outbox ล่าสุดสำหรับ admin
 *
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function line_notify_recent_outbox(int $limit = 15): array
{
    $db = line_notify_db();
    if (!$db) {
        return [];
    }
    line_notify_ensure_schema();
    $limit = max(1, min(50, $limit));
    $res = $db->query(
        "SELECT id, event_key, status, attempts, created_at, sent_at, last_error
         FROM notification_outbox ORDER BY id DESC LIMIT {$limit}"
    );
    if (!$res) {
        return [];
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}
