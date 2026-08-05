<?php
/**
 * shared/activity_log_core.php — เก็บ Activity Log ร่วมกันใน DB production
 *
 * ใช้โดย finishgoogs_ma_update (ทะเบียน/MA) และ parts (สต็อกช่าง)
 * ตาราง activity_logs อยู่ใน biton_production
 */

require_once __DIR__ . '/app_paths.php';

// ─ Constants ─────────────────────────────────────────────────────────────────

/** @var array<int,string> */
const ACTIVITY_LOG_SKIP_POST_KEYS = [
    'csrf', 'csrf_token', 'pin', 'password', 'settings_pin_unlock',
    'new_password', 'confirm_password',
];

/** @var array<string,string> */
const ACTIVITY_LOG_SYSTEM_LABELS = [
    'production' => 'ทะเบียนเครื่อง / MA',
    'parts'      => 'สต็อกอะไหล่ (Parts)',
];

// ─ DB ────────────────────────────────────────────────────────────────────────

/**
 * เชื่อมต่อ mysqli ไป DB production สำหรับ activity log
 *
 * @return mysqli|null
 */
function activity_log_db_connect() {
    static $db = null;
    static $failed = false;
    if ($failed) {
        return null;
    }
    if ($db instanceof mysqli) {
        return $db;
    }
    $secretsPath = app_finishgoogs_secrets_path();
    if (!is_file($secretsPath)) {
        $failed = true;
        return null;
    }
    $c = require $secretsPath;
    $p = isset($c['production']) ? $c['production'] : null;
    if (!$p || empty($p['host']) || empty($p['db'])) {
        $failed = true;
        return null;
    }
    $db = @new mysqli($p['host'], $p['user'], $p['pass'], $p['db']);
    if ($db->connect_errno) {
        $failed = true;
        $db = null;
        return null;
    }
    $db->set_charset('utf8mb4');
    $db->autocommit(true);
    return $db;
}

/**
 * สร้างตาราง activity_logs ถ้ายังไม่มี
 *
 * @param mysqli|null $db
 * @return void
 */
function activity_log_ensure_schema($db = null) {
    $db = $db ?: activity_log_db_connect();
    if (!$db) {
        return;
    }
    $db->query("CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        system_key VARCHAR(32) NOT NULL,
        actor_name VARCHAR(100) NOT NULL DEFAULT '',
        action_key VARCHAR(64) NOT NULL,
        summary VARCHAR(500) NOT NULL,
        detail TEXT NULL,
        entity_type VARCHAR(64) NULL,
        entity_id VARCHAR(64) NULL,
        ip_address VARCHAR(45) NULL,
        request_uri VARCHAR(500) NULL,
        KEY idx_created (created_at),
        KEY idx_system (system_key),
        KEY idx_actor (actor_name(50)),
        KEY idx_action (action_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ─ Write ─────────────────────────────────────────────────────────────────────

/**
 * บันทึก log 1 รายการ
 *
 * @param array<string,mixed> $row system_key, actor_name, action_key, summary, detail?, entity_type?, entity_id?
 * @param mysqli|null         $db
 * @return bool
 */
function activity_log_write(array $row, $db = null) {
    $db = $db ?: activity_log_db_connect();
    if (!$db) {
        return false;
    }
    activity_log_ensure_schema($db);

    $system = mb_substr(trim((string)(isset($row['system_key']) ? $row['system_key'] : 'production')), 0, 32);
    $actor = mb_substr(trim((string)(isset($row['actor_name']) ? $row['actor_name'] : '')), 0, 100);
    $action = mb_substr(trim((string)(isset($row['action_key']) ? $row['action_key'] : 'action')), 0, 64);
    $summary = mb_substr(trim((string)(isset($row['summary']) ? $row['summary'] : '')), 0, 500);
    if ($summary === '') {
        $summary = $action;
    }
    $detail = isset($row['detail']) ? (string)$row['detail'] : '';
    if (mb_strlen($detail) > 8000) {
        $detail = mb_substr($detail, 0, 8000) . '…';
    }
    $entityType = isset($row['entity_type']) ? mb_substr((string)$row['entity_type'], 0, 64) : null;
    $entityId = isset($row['entity_id']) ? mb_substr((string)$row['entity_id'], 0, 64) : null;
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $uri = mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500);

    $st = $db->prepare(
        'INSERT INTO activity_logs (system_key, actor_name, action_key, summary, detail, entity_type, entity_id, ip_address, request_uri)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    if (!$st) {
        return false;
    }
    $st->bind_param('sssssssss', $system, $actor, $action, $summary, $detail, $entityType, $entityId, $ip, $uri);
    $ok = $st->execute();
    $st->close();
    if ($ok) {
        $GLOBALS['activity_log_written'] = true;
    }
    return (bool)$ok;
}

/**
 * ตรวจว่าควรข้ามการบันทึก page view หรือไม่
 *
 * @param string $script basename ของสคริปต์
 * @return bool
 */
function activity_log_should_skip_page_view(string $script): bool {
    static $skipExact = [
        'healthz.php',
        'login.php',
        'logout.php',
        'dashboard_data.php',
        'sync_data.php',
        'notifications.php',
    ];
    if (in_array($script, $skipExact, true)) {
        return true;
    }
    if (!empty($_GET['ajax'])) {
        return true;
    }
    $scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($scriptPath, '/cron/') !== false
        || strpos($scriptPath, '/database/tools/') !== false
        || strpos($scriptPath, '/api/') !== false) {
        return true;
    }
    return false;
}

/**
 * สรุป query string GET ที่ปลอดภัยสำหรับ detail page view
 *
 * @return string
 */
function activity_log_sanitize_get_detail(): string {
    if (!is_array($_GET) || $_GET === []) {
        return '';
    }
    $skipKeys = ['csrf', 'csrf_token', 'export'];
    $parts = [];
    foreach ($_GET as $k => $v) {
        $k = (string)$k;
        if (in_array($k, $skipKeys, true)) {
            continue;
        }
        if (is_array($v)) {
            continue;
        }
        $v = mb_substr(trim((string)$v), 0, 80);
        if ($v === '') {
            continue;
        }
        $parts[] = $k . '=' . $v;
        if (count($parts) >= 8) {
            break;
        }
    }
    return implode('; ', $parts);
}

/**
 * ลงทะเบียนบันทึก page view เมื่อเปิดหน้า (GET) — ทุกหน้าที่ login แล้ว
 *
 * @param string $systemKey production|parts
 * @param string $actorName ชื่อผู้ใช้
 * @return void
 */
function activity_log_register_page_view_shutdown(string $systemKey, string $actorName): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        return;
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '' || activity_log_should_skip_page_view($script)) {
        return;
    }
    register_shutdown_function(function () use ($systemKey, $actorName, $script) {
        if (!empty($GLOBALS['activity_log_skip_auto']) || !empty($GLOBALS['activity_log_skip_page_view'])) {
            return;
        }
        $action = 'page_view:' . $script;
        $summary = 'เปิดดู — ' . activity_log_script_label($script);
        if ($script === 'activity_logs.php' && (string)($_GET['export'] ?? '') === 'csv') {
            $action = 'page_export:' . $script;
            $summary = 'Export CSV — Activity Log';
        }
        activity_log_write([
            'system_key'  => $systemKey,
            'actor_name'  => $actorName,
            'action_key'  => $action,
            'summary'     => $summary,
            'detail'      => activity_log_sanitize_get_detail(),
            'entity_type' => 'page',
            'entity_id'   => $script,
        ]);
    });
}

/**
 * ดึงประวัติการเปิดดูหน้าเว็บล่าสุด (ทุกหน้า)
 *
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function activity_log_recent_page_views($limit = 15) {
    $db = activity_log_db_connect();
    if (!$db) {
        return [];
    }
    activity_log_ensure_schema($db);
    $limit = max(1, min(50, (int)$limit));
    $sql = "SELECT * FROM activity_logs
            WHERE action_key LIKE 'page_view:%' OR action_key LIKE 'page_export:%'
            ORDER BY id DESC LIMIT $limit";
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}

/**
 * สรุป POST ที่ปลอดภัยสำหรับ detail (ไม่เก็บ PIN/รหัสผ่าน)
 *
 * @return string
 */
function activity_log_sanitize_post_detail() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($_POST)) {
        return '';
    }
    $parts = [];
    foreach ($_POST as $k => $v) {
        $k = (string)$k;
        if (in_array($k, ACTIVITY_LOG_SKIP_POST_KEYS, true)) {
            continue;
        }
        if (stripos($k, 'password') !== false || stripos($k, 'csrf') !== false) {
            continue;
        }
        if (is_array($v)) {
            $v = '[array:' . count($v) . ']';
        } else {
            $v = mb_substr(trim((string)$v), 0, 120);
        }
        if ($v === '') {
            continue;
        }
        $parts[] = $k . '=' . $v;
        if (count($parts) >= 24) {
            break;
        }
    }
    return implode('; ', $parts);
}

/**
 * ป้าย action จากชื่อไฟล์ PHP
 *
 * @param string $script basename เช่น asset_new.php
 * @return string
 */
function activity_log_script_label($script) {
    static $map = [
        'index.php'          => 'หน้าแรก / Dashboard',
        'assets.php'         => 'รายการเครื่อง',
        'asset.php'          => 'รายละเอียดเครื่อง',
        'asset_new.php'      => 'บันทึกผลิตใหม่',
        'ma.php'             => 'บันทึก MA',
        'updates.php'        => 'อัปเดต FW/HW',
        'repairs.php'        => 'งานซ่อม',
        'scan.php'           => 'สแกน QR',
        'parts.php'          => 'เบิก/คืนอะไหล่ (Production)',
        'report.php'         => 'รายงาน',
        'settings.php'       => 'ตั้งค่ารุ่น/ฟิลด์',
        'appearance.php'     => 'ปรับแต่งหน้าตา',
        'server_config.php'  => 'ตั้งค่า server',
        'line_notify_settings.php' => 'ตั้งค่า LINE',
        'activity_logs.php'  => 'Activity Log',
        'system_doc.php'     => 'เอกสารระบบ',
        'share_admin.php'    => 'จัดการ sync stock',
        'share.php'          => 'ทะเบียน stock',
        'profile.php'        => 'โปรไฟล์ผู้ใช้',
        'users.php'          => 'จัดการผู้ใช้',
        'update_new.php'     => 'อัปเดต FW/HW',
        'update_edit.php'    => 'แก้ไขอัปเดต',
        'stock-in.php'       => 'รับเข้าอะไหล่',
        'stock-out.php'      => 'เบิกออก (Set)',
        'stock-out-item.php' => 'เบิกรายชิ้น',
        'products.php'       => 'จัดการอะไหล่',
        'sets.php'           => 'จัดการ Set',
        'product-detail.php' => 'รายละเอียดอะไหล่',
        'history.php'        => 'ประวัติสต็อก',
        // เลิกใช้แล้ว 2026-08-05 (ลบไฟล์ทิ้ง) — คง label ไว้ให้ log เก่าที่บันทึกไปแล้วยังอ่านออก
        'vendor-import.php'  => 'นำเข้าจาก vendor',
        'year-end-summary.php' => 'สรุปปลายปี',
        'webhook-stockout.php' => 'Webhook เบิกอะไหล่',
    ];
    $script = basename((string)$script);
    if (isset($map[$script])) {
        return $map[$script];
    }
    return preg_replace('/\.php$/', '', $script);
}

/**
 * ลงทะเบียน auto-log เมื่อจบ POST request (ถ้ายังไม่มี log เฉพาะ)
 *
 * @param string $systemKey production|parts
 * @param string $actorName ชื่อผู้ใช้
 * @return void
 */
function activity_log_register_post_shutdown($systemKey, $actorName) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $skip = ['activity_logs.php', 'login.php', 'healthz.php'];
    if (in_array($script, $skip, true)) {
        return;
    }
    register_shutdown_function(function () use ($systemKey, $actorName, $script) {
        if (!empty($GLOBALS['activity_log_skip_auto'])) {
            return;
        }
        if (!empty($GLOBALS['activity_log_written'])) {
            return;
        }
        activity_log_write([
            'system_key' => $systemKey,
            'actor_name' => $actorName,
            'action_key' => 'post:' . preg_replace('/\.php$/', '', $script),
            'summary'    => activity_log_script_label($script),
            'detail'     => activity_log_sanitize_post_detail(),
        ]);
    });
}

// ─ Query / export ────────────────────────────────────────────────────────────

/**
 * ค้นหา log ตาม filter
 *
 * @param array<string,mixed> $filters system, q, actor, action, date_from, date_to
 * @param int                 $limit
 * @param int                 $offset
 * @return array{rows:array<int,array<string,mixed>>, total:int}
 */
function activity_log_search(array $filters, $limit = 100, $offset = 0) {
    $db = activity_log_db_connect();
    if (!$db) {
        return ['rows' => [], 'total' => 0];
    }
    activity_log_ensure_schema($db);

    $where = ['1=1'];
    $types = '';
    $params = [];

    if (!empty($filters['system']) && isset(ACTIVITY_LOG_SYSTEM_LABELS[$filters['system']])) {
        $where[] = 'system_key=?';
        $types .= 's';
        $params[] = $filters['system'];
    }
    if (!empty($filters['actor'])) {
        $where[] = 'actor_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $filters['actor'] . '%';
    }
    if (!empty($filters['action'])) {
        $where[] = 'action_key LIKE ?';
        $types .= 's';
        $params[] = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['q'])) {
        $where[] = '(summary LIKE ? OR detail LIKE ? OR entity_id LIKE ?)';
        $types .= 'sss';
        $q = '%' . $filters['q'] . '%';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }
    if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $where[] = 'created_at>=?';
        $types .= 's';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $where[] = 'created_at<=?';
        $types .= 's';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    $wSql = implode(' AND ', $where);
    $limit = max(1, min(500, (int)$limit));
    $offset = max(0, (int)$offset);

    $cntSql = "SELECT COUNT(*) c FROM activity_logs WHERE $wSql";
    $st = $db->prepare($cntSql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $total = (int)$st->get_result()->fetch_assoc()['c'];
    $st->close();

    $sql = "SELECT * FROM activity_logs WHERE $wSql ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return ['rows' => $rows, 'total' => $total];
}

/**
 * ส่ง HTTP headers + body CSV จากผลค้นหา
 *
 * @param array<int,array<string,mixed>> $rows
 * @return void
 */
function activity_log_csv_output(array $rows) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['เวลา', 'ระบบ', 'ผู้ใช้', 'action', 'สรุป', 'รายละเอียด', 'entity_type', 'entity_id', 'IP', 'URI']);
    foreach ($rows as $r) {
        $sys = isset(ACTIVITY_LOG_SYSTEM_LABELS[$r['system_key']]) ? ACTIVITY_LOG_SYSTEM_LABELS[$r['system_key']] : $r['system_key'];
        fputcsv($out, [
            $r['created_at'],
            $sys,
            $r['actor_name'],
            $r['action_key'],
            $r['summary'],
            $r['detail'],
            $r['entity_type'],
            $r['entity_id'],
            $r['ip_address'],
            $r['request_uri'],
        ]);
    }
    fclose($out);
}
