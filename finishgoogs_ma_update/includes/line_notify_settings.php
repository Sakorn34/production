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
        if (($meta['mode'] ?? '') !== 'scheduled') {
            continue;
        }
        $fk = line_settings_field_key($eventKey);
        $time = line_notify_normalize_time((string)($post['sched_time_' . $fk] ?? '08:00'));
        $weekday = (int)($post['sched_weekday_' . $fk] ?? 0);
        $schedules[$eventKey] = [
            'time'    => $time,
            'weekday' => max(0, min(6, $weekday)),
            'type'    => (string)($meta['schedule_type'] ?? 'daily'),
        ];
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
