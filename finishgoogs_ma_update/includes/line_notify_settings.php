<?php
/**
 * includes/line_notify_settings.php — โหลด/บันทึก/ทดสอบการตั้งค่า LINE Notification
 *
 * ใช้โดย line_notify_settings.php ในระบบหลังบ้าน
 */

require_once dirname(__DIR__, 2) . '/shared/line_notify_core.php';
require_once dirname(__DIR__, 2) . '/shared/line_flex_templates.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_jobs.php';
require_once __DIR__ . '/deploy_config.php';

/** @var string placeholder token ในฟอร์ม */
const LINE_SETTINGS_TOKEN_PLACEHOLDER = '••••••••••••••••';

/**
 * แปลง event_key เป็น field name
 *
 * @param string $eventKey
 * @return string
 */
function line_settings_field_key(string $eventKey): string
{
    return str_replace('.', '_', $eventKey);
}

/**
 * แปลง POST field กลับเป็น event_key
 *
 * @param string $field
 * @return string
 */
function line_settings_event_from_field(string $field): string
{
    return str_replace('_', '.', $field);
}

/**
 * รายการ event ที่ตั้งค่าเปิด/ปิดได้
 *
 * @return array<string,string>
 */
function line_settings_event_options(): array
{
    $catalog = line_notify_type_catalog();
    $labels = [];
    foreach ($catalog as $key => $meta) {
        $labels[$key] = (string)($meta['label'] ?? $key);
    }
    return $labels;
}

/**
 * ค่า default สำหรับฟอร์ม
 *
 * @return array<string,mixed>
 */
function line_settings_form_defaults(): array
{
    $cfg = line_notify_config();
    $events = [];
    foreach (array_keys(line_notify_type_catalog()) as $key) {
        $ev = $cfg['events'] ?? [];
        $events[$key] = ($ev === [] || !array_key_exists($key, $ev)) ? true : !empty($ev[$key]);
    }
    return [
        'line_secrets_path'     => app_line_secrets_path(),
        'enabled'               => !empty($cfg['enabled']),
        'channel_access_token'  => '',
        'channel_secret'        => '',
        'default_recipient_id'  => (string)($cfg['default_recipient_id'] ?? ''),
        'public_production_url' => (string)($cfg['public_production_url'] ?? ''),
        'public_parts_url'      => (string)($cfg['public_parts_url'] ?? ''),
        'events'                => $events,
        'schedules'             => line_notify_schedules(),
        'has_token'             => trim((string)($cfg['channel_access_token'] ?? '')) !== '',
        'has_secret'            => trim((string)($cfg['channel_secret'] ?? '')) !== '',
    ];
}

/**
 * แปลง POST เป็นชุด config
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function line_settings_parse_post(array $post): array
{
    $existing = line_notify_config();
    $events = [];
    foreach (array_keys(line_notify_type_catalog()) as $key) {
        $events[$key] = !empty($post['event_' . line_settings_field_key($key)]);
    }

    $schedules = [];
    foreach (line_notify_type_catalog() as $eventKey => $meta) {
        $fk = line_settings_field_key($eventKey);
        $delivery = line_notify_normalize_delivery(
            $eventKey,
            (string)($post['sched_delivery_' . $fk] ?? 'instant')
        );
        $row = ['delivery' => $delivery];
        if (!empty($meta['can_scheduled'])) {
            $row['type'] = (string)($meta['schedule_type'] ?? 'daily');
        }
        $schedules[$eventKey] = $row;
    }

    $token = trim((string)($post['channel_access_token'] ?? ''));
    if ($token === '' || $token === LINE_SETTINGS_TOKEN_PLACEHOLDER) {
        $token = (string)($existing['channel_access_token'] ?? '');
    }
    $secret = trim((string)($post['channel_secret'] ?? ''));
    if ($secret === '' || $secret === LINE_SETTINGS_TOKEN_PLACEHOLDER) {
        $secret = (string)($existing['channel_secret'] ?? '');
    }

    return [
        'line_secrets_path'     => trim((string)($post['line_secrets_path'] ?? app_line_secrets_path())),
        'enabled'               => !empty($post['line_enabled']),
        'channel_access_token'  => $token,
        'channel_secret'        => $secret,
        'default_recipient_id'  => trim((string)($post['default_recipient_id'] ?? '')),
        'public_production_url' => trim((string)($post['public_production_url'] ?? '')),
        'public_parts_url'      => trim((string)($post['public_parts_url'] ?? '')),
        'events'                => $events,
        'schedules'             => $schedules,
    ];
}

/**
 * validate ก่อนบันทึก
 *
 * @param array<string,mixed> $cfg
 * @return array<int,string>
 */
function line_settings_validate(array $cfg): array
{
    $errors = [];
    if (empty($cfg['line_secrets_path'])) {
        $errors[] = 'กรุณาระบุ path ไฟล์ line.secrets.php';
    }
    if (!empty($cfg['enabled'])) {
        if (trim((string)($cfg['channel_access_token'] ?? '')) === '') {
            $errors[] = 'กรุณาระบุ Channel Access Token';
        }
        if (trim((string)($cfg['default_recipient_id'] ?? '')) === '') {
            $errors[] = 'กรุณาระบุ Group/User ID ผู้รับ';
        }
    }
    return $errors;
}

/**
 * สร้างเนื้อหา line.secrets.php
 *
 * @param array<string,mixed> $cfg
 * @return string
 */
function line_settings_build_secrets_php(array $cfg): string
{
    $export = var_export([
        'channel_access_token'  => (string)($cfg['channel_access_token'] ?? ''),
        'channel_secret'        => (string)($cfg['channel_secret'] ?? ''),
        'default_recipient_id'  => (string)($cfg['default_recipient_id'] ?? ''),
        'enabled'               => !empty($cfg['enabled']),
        'public_production_url' => (string)($cfg['public_production_url'] ?? ''),
        'public_parts_url'      => (string)($cfg['public_parts_url'] ?? ''),
        'events'                => (array)($cfg['events'] ?? []),
        'schedules'             => (array)($cfg['schedules'] ?? []),
    ], true);
    return "<?php\n/** line.secrets.php — สร้างโดย line_notify_settings.php · อย่า commit */\nreturn {$export};\n";
}

/**
 * บันทึก secrets + อัปเดต path ใน config.paths.php
 *
 * @param array<string,mixed> $cfg
 * @return array{ok:bool,messages:array<int,string>}
 */
function line_settings_save(array $cfg): array
{
    $messages = [];
    $secretsPath = str_replace('\\', '/', (string)$cfg['line_secrets_path']);
    $write = deploy_write_file($secretsPath, line_settings_build_secrets_php($cfg));
    $messages[] = ($write['ok'] ? '✓ ' : '✗ ') . 'line.secrets.php: ' . $write['message'];
    if (!$write['ok']) {
        return ['ok' => false, 'messages' => $messages];
    }

    $pathsFile = app_paths_config_file();
    $paths = app_paths();
    $paths['line_secrets'] = $secretsPath;
    $pathWrite = deploy_write_file($pathsFile, deploy_build_paths_php($paths));
    $messages[] = ($pathWrite['ok'] ? '✓ ' : '✗ ') . 'config.paths.php: ' . $pathWrite['message'];
    if (!$pathWrite['ok']) {
        return ['ok' => false, 'messages' => $messages];
    }

    if (function_exists('app_paths_clear_cache')) {
        app_paths_clear_cache();
    }

    return ['ok' => true, 'messages' => $messages];
}

/**
 * ทดสอบส่งข้อความ LINE (enqueue + process ทันที)
 *
 * @param array<string,mixed> $cfg
 * @return array{ok:bool,message:string,detail?:string}
 */
function line_settings_test_push(array $cfg): array
{
    $token = trim((string)($cfg['channel_access_token'] ?? ''));
    $recipient = trim((string)($cfg['default_recipient_id'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'message' => 'ไม่มี Channel Access Token'];
    }
    if ($recipient === '') {
        return ['ok' => false, 'message' => 'ไม่มี Recipient ID'];
    }

    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'line_secrets_test_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmpFile, line_settings_build_secrets_php(array_merge($cfg, ['enabled' => true])));
    $GLOBALS['_line_notify_config_override_path'] = $tmpFile;
    line_notify_config(true);

    line_notify_ensure_schema();
    line_notify_dispatch('line.test', [
        'message' => 'ทดสอบ LINE จากระบบหลังบ้าน — ' . date('d/m/Y H:i:s'),
    ], ['dedup_key' => 'line.test:' . microtime(true)]);

    $stats = line_notify_process_outbox(5);

    unset($GLOBALS['_line_notify_config_override_path']);
    line_notify_config(true);
    @unlink($tmpFile);

    if ($stats['sent'] > 0) {
        return ['ok' => true, 'message' => 'ส่งทดสอบสำเร็จ — ตรวจข้อความใน LINE'];
    }
    return [
        'ok'      => false,
        'message' => 'ส่งไม่สำเร็จ',
        'detail'  => 'sent=' . $stats['sent'] . ' failed=' . $stats['failed'] . ' dead=' . $stats['dead'],
    ];
}

// ─ Outbox display (หลังบ้าน) ─────────────────────────────────────────────────

/**
 * ป้ายประเภทแจ้งเตือนสำหรับแสดงใน Outbox
 *
 * @param string               $eventKey
 * @param array<string,mixed>  $catalog จาก line_notify_type_catalog()
 * @return string
 */
function line_settings_outbox_event_label(string $eventKey, array $catalog): string
{
    return LINE_NOTIFY_EVENT_LABELS[$eventKey]
        ?? (string)(($catalog[$eventKey]['label'] ?? '') ?: $eventKey);
}

/**
 * แปลง status outbox เป็นข้อความภาษาไทย
 *
 * @param string $status pending|sent|failed|dead
 * @return string
 */
function line_settings_outbox_status_label(string $status): string
{
    $map = [
        'pending' => 'รอส่ง',
        'sent'    => 'ส่งแล้ว',
        'failed'  => 'ล้มเหลว (จะลองใหม่)',
        'dead'    => 'ส่งไม่ได้',
    ];
    return $map[$status] ?? $status;
}

/**
 * CSS class สำหรับ badge สถานะ outbox
 *
 * @param string $status
 * @return string
 */
function line_settings_outbox_status_class(string $status): string
{
    $map = [
        'pending' => 'ln-ob-pending',
        'sent'    => 'ln-ob-sent',
        'failed'  => 'ln-ob-failed',
        'dead'    => 'ln-ob-dead',
    ];
    return $map[$status] ?? 'ln-ob-pending';
}

/**
 * จัดรูปแบบวันที่ outbox ให้อ่านง่าย (d/m/Y H:i)
 *
 * @param string|null $datetime
 * @return string
 */
function line_settings_outbox_format_time(?string $datetime): string
{
    $datetime = trim((string)$datetime);
    if ($datetime === '' || $datetime === '-') {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    return date('d/m/Y H:i', $ts);
}

/**
 * สรุปจำนวนตาม status จากรายการ outbox ล่าสุด
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{pending:int,sent:int,failed:int,dead:int,total:int}
 */
function line_settings_outbox_summary(array $rows): array
{
    $summary = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'dead' => 0, 'total' => count($rows)];
    foreach ($rows as $row) {
        $st = (string)($row['status'] ?? '');
        if (isset($summary[$st])) {
            $summary[$st]++;
        }
    }
    return $summary;
}

// ─ Plesk Scheduled Task diagnostics ─────────────────────────────────────────

/**
 * path ไฟล์ log cron LINE (private/logs/line-cron.log)
 *
 * @return string
 */
function line_plesk_cron_log_path(): string
{
    if (!function_exists('app_error_log_path')) {
        return '';
    }
    return dirname(app_error_log_path()) . '/line-cron.log';
}

/**
 * อ่าน schedule แนะนำสำหรับตั้ง Plesk ต่อ event
 *
 * @param string $eventKey
 * @return array{mode:string,time:string,detail:string,recipe:string}
 */
function line_plesk_recommended_schedule(string $eventKey): array
{
    $defaults = [
        'stock.low_threshold'             => ['mode' => 'daily', 'time' => '08:00', 'detail' => 'Daily 08:00'],
        'production.summary.daily'        => ['mode' => 'daily', 'time' => '17:30', 'detail' => 'Daily 17:30'],
        'production.summary.daily_update' => ['mode' => 'daily', 'time' => '18:00', 'detail' => 'Daily 18:00'],
        'production.summary.weekly'       => ['mode' => 'cron', 'time' => '17:30', 'detail' => 'Cron — วันศุกร์ 17:30'],
        'production.summary.monthly'        => ['mode' => 'cron', 'time' => '17:35', 'detail' => 'Cron — วันสุดท้ายเดือน 17:35'],
    ];
    $row = $defaults[$eventKey] ?? ['mode' => 'daily', 'time' => '', 'detail' => 'Daily — ตั้งเวลาใน Plesk'];
    $row['recipe'] = 'Run a PHP script · PHP 8.2 · Notify = Do not notify · ' . $row['detail'];
    return $row;
}

/**
 * threshold วินาทีสำหรับตัดสินว่า log ล่าสุด stale หรือไม่
 *
 * @param string $scheduleType daily|weekly|monthly_last_day
 * @return int
 */
function line_plesk_stale_threshold_seconds(string $scheduleType): int
{
    $map = [
        'daily'            => 26 * 3600,
        'weekly'           => 8 * 86400,
        'monthly_last_day' => 35 * 86400,
    ];
    return $map[$scheduleType] ?? 26 * 3600;
}

/**
 * อ่าน tail log cron แล้วคืน entry ล่าสุดต่อ job key
 *
 * @param int $maxLines จำนวนบรรทัดท้ายสุดที่อ่าน
 * @return array<string,array{at:string,ts:int,result:array<string,mixed>,worker:array<string,mixed>}>
 */
function line_plesk_parse_cron_log(int $maxLines = 200): array
{
    $path = line_plesk_cron_log_path();
    if ($path === '' || !is_readable($path)) {
        return [];
    }

    $lines = [];
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return [];
    }

    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
        if (count($lines) > $maxLines) {
            array_shift($lines);
        }
    }
    fclose($fp);

    $byJob = [];
    foreach ($lines as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\{.*\})$/u', $line, $m)) {
            continue;
        }
        $payload = json_decode($m[2], true);
        if (!is_array($payload)) {
            continue;
        }
        $job = (string)($payload['job'] ?? '');
        if ($job === '') {
            continue;
        }
        $ts = strtotime($m[1]);
        if ($ts === false) {
            continue;
        }
        if (!isset($byJob[$job]) || $ts >= $byJob[$job]['ts']) {
            $byJob[$job] = [
                'at'     => $m[1],
                'ts'     => $ts,
                'result' => is_array($payload['result'] ?? null) ? $payload['result'] : [],
                'worker' => is_array($payload['worker'] ?? null) ? $payload['worker'] : [],
            ];
        }
    }

    return $byJob;
}

/**
 * นับ outbox ตาม status ทั้งหมด (ไม่จำกัดจำนวนแถว)
 *
 * @return array{pending:int,sent:int,failed:int,dead:int}
 */
function line_plesk_outbox_counts(): array
{
    $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'dead' => 0];
    $db = line_notify_db();
    if (!$db) {
        return $counts;
    }
    line_notify_ensure_schema();
    $res = $db->query('SELECT status, COUNT(*) AS c FROM notification_outbox GROUP BY status');
    if (!$res) {
        return $counts;
    }
    while ($r = $res->fetch_assoc()) {
        $st = (string)($r['status'] ?? '');
        if (isset($counts[$st])) {
            $counts[$st] = (int)$r['c'];
        }
    }
    return $counts;
}

/**
 * ตัดสินสถานะ Plesk task ต่อ event จาก config + log
 *
 * @param string               $eventKey
 * @param array<string,mixed>  $meta       จาก catalog
 * @param array<string,mixed>|null $lastRun จาก line_plesk_parse_cron_log
 * @return array{status:string,message:string}
 */
function line_plesk_task_status(string $eventKey, array $meta, ?array $lastRun): array
{
    if (!line_notify_scheduled_enabled($eventKey)) {
        return ['status' => 'not_required', 'message' => 'ไม่ต้องตั้ง task'];
    }

    $job = (string)($meta['job'] ?? '');
    if ($job === '' || $lastRun === null) {
        return ['status' => 'needs_setup', 'message' => 'ยังไม่เคยรัน — ตั้ง task ใน Plesk'];
    }

    $resultOk = !array_key_exists('ok', $lastRun['result']) || !empty($lastRun['result']['ok']);
    if (!$resultOk) {
        $msg = (string)($lastRun['result']['message'] ?? 'job ล้มเหลว');
        return ['status' => 'failed', 'message' => $msg];
    }

    $scheduleType = (string)($meta['schedule_type'] ?? 'daily');
    $threshold = line_plesk_stale_threshold_seconds($scheduleType);
    $age = time() - (int)$lastRun['ts'];
    if ($age > $threshold) {
        return ['status' => 'stale', 'message' => 'รันล่าสุดเกิน threshold — ตรวจ task ใน Plesk'];
    }

    return ['status' => 'ok', 'message' => 'รันล่าสุดปกติ'];
}

/**
 * รวมผลตรวจสอบ Plesk Scheduled Task สำหรับ UI/CLI
 *
 * @return array<string,mixed>
 */
function line_plesk_task_diagnostics(): array
{
    $catalog = line_notify_type_catalog();
    $lastRuns = line_plesk_parse_cron_log();
    $logPath = line_plesk_cron_log_path();
    $logExists = $logPath !== '' && is_file($logPath);
    $outboxCounts = line_plesk_outbox_counts();
    $tasks = [];
    $required = 0;
    $okCount = 0;
    $needsInstantWorker = false;

    foreach ($catalog as $eventKey => $meta) {
        if (line_notify_instant_enabled($eventKey)) {
            $needsInstantWorker = true;
        }
        $scriptName = line_notify_plesk_script_name($eventKey);
        if ($scriptName === null) {
            continue;
        }

        $job = (string)($meta['job'] ?? '');
        $lastRun = $job !== '' ? ($lastRuns[$job] ?? null) : null;
        $statusInfo = line_plesk_task_status($eventKey, $meta, $lastRun);
        $requiredFlag = line_notify_scheduled_enabled($eventKey);
        if ($requiredFlag) {
            $required++;
            if ($statusInfo['status'] === 'ok') {
                $okCount++;
            }
        }

        $rec = line_plesk_recommended_schedule($eventKey);
        $tasks[] = [
            'event_key'      => $eventKey,
            'label'          => (string)($meta['label'] ?? $eventKey),
            'job'            => $job,
            'required'       => $requiredFlag,
            'script_path'    => line_notify_plesk_script_path($eventKey),
            'script_name'    => $scriptName,
            'schedule_type'  => (string)($meta['schedule_type'] ?? 'daily'),
            'recommended'    => $rec,
            'plesk_hint'     => line_notify_plesk_run_hint($eventKey),
            'last_run_at'    => $lastRun['at'] ?? null,
            'last_run_ts'    => $lastRun['ts'] ?? null,
            'last_result'    => $lastRun['result'] ?? null,
            'status'         => $statusInfo['status'],
            'status_message' => $statusInfo['message'],
        ];
    }

    return [
        'now'                  => date('Y-m-d H:i:s'),
        'timezone'             => 'Asia/Bangkok',
        'system_enabled'       => line_notify_is_enabled(),
        'log_path'             => $logPath,
        'log_exists'           => $logExists,
        'outbox'               => $outboxCounts,
        'tasks_required'       => $required,
        'tasks_ok'             => $okCount,
        'needs_instant_worker' => $needsInstantWorker,
        'tasks'                => $tasks,
    ];
}

/**
 * ป้ายสถานะ Plesk task ภาษาไทย
 *
 * @param string $status not_required|needs_setup|ok|stale|failed
 * @return string
 */
function line_plesk_status_label(string $status): string
{
    $map = [
        'not_required' => 'ไม่ต้องตั้ง',
        'needs_setup'  => 'ต้องตั้ง task',
        'ok'           => 'ปกติ',
        'stale'        => 'ค้าง/เก่า',
        'failed'       => 'ล้มเหลว',
    ];
    return $map[$status] ?? $status;
}

/**
 * CSS class สำหรับ badge สถานะ Plesk task
 *
 * @param string $status
 * @return string
 */
function line_plesk_status_class(string $status): string
{
    $map = [
        'not_required' => 'ln-plesk-status--skip',
        'needs_setup'  => 'ln-plesk-status--needs',
        'ok'           => 'ln-plesk-status--ok',
        'stale'        => 'ln-plesk-status--stale',
        'failed'       => 'ln-plesk-status--failed',
    ];
    return $map[$status] ?? 'ln-plesk-status--needs';
}

/**
 * ทดสอบ worker (process outbox) จากหลังบ้าน
 *
 * @param int $limit จำนวนรายการ outbox ที่ประมวลผล
 * @return array{ok:bool,message:string,detail?:string,stats:array<string,mixed>}
 */
function line_plesk_test_worker(int $limit = 10): array
{
    if (!line_notify_is_enabled()) {
        return [
            'ok'      => false,
            'message' => 'ระบบ LINE ปิดอยู่',
            'stats'   => ['skipped' => 'disabled'],
        ];
    }
    $stats = line_notify_process_outbox(max(1, min(50, $limit)));
    $sent = (int)($stats['sent'] ?? 0);
    $failed = (int)($stats['failed'] ?? 0);
    $dead = (int)($stats['dead'] ?? 0);
    $detail = 'sent=' . $sent . ' failed=' . $failed . ' dead=' . $dead;
    if (isset($stats['skipped'])) {
        $detail = (string)$stats['skipped'];
    }
    return [
        'ok'      => $sent > 0 || ($failed === 0 && $dead === 0),
        'message' => $sent > 0 ? 'worker ส่งสำเร็จ ' . $sent . ' รายการ' : 'worker รันแล้ว (ไม่มีรายการใหม่ส่ง)',
        'detail'  => $detail,
        'stats'   => $stats,
    ];
}
