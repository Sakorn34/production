<?php
/**
 * shared/line_notify_jobs.php — รันงานแจ้งเตือนตามประเภท (cron + ส่งทันทีจากหลังบ้าน)
 *
 * ใช้โดย line_notify_scheduled.php และ line_notify_settings.php
 */

require_once __DIR__ . '/line_notify_core.php';
require_once __DIR__ . '/line_flex_templates.php';

// ─ Catalog ───────────────────────────────────────────────────────────────────

/**
 * รายการประเภทแจ้งเตือนพร้อม metadata
 *
 * @return array<string,array<string,mixed>>
 */
function line_notify_type_catalog(): array
{
    return [
        'production.problem_found' => [
            'label'         => 'พบปัญหาตอนผลิต',
            'can_instant'   => true,
            'can_scheduled' => false,
            'job'           => null,
        ],
        'stock.low_threshold' => [
            'label'           => 'อะไหล่ใกล้หมด',
            'can_instant'     => true,
            'can_scheduled'   => true,
            'job'             => 'low_stock_scan',
            'schedule_type'   => 'daily',
            'default_delivery'=> 'both',
        ],
        'ma.repair_required' => [
            'label'         => 'MA ต้องซ่อม',
            'can_instant'   => true,
            'can_scheduled' => false,
            'job'           => null,
        ],
        'stock.manual_withdraw' => [
            'label'         => 'เบิกอะไหล่ (manual)',
            'can_instant'   => true,
            'can_scheduled' => false,
            'job'           => null,
        ],
        'production.summary.daily' => [
            'label'           => 'สรุปผลิตรายวัน',
            'can_instant'     => false,
            'can_scheduled'   => true,
            'job'             => 'daily',
            'schedule_type'   => 'daily',
            'default_delivery'=> 'scheduled',
        ],
        'production.summary.daily_update' => [
            'label'           => 'อัปเดตผลิตหลังเลิกงาน',
            'can_instant'     => false,
            'can_scheduled'   => true,
            'job'             => 'daily_update',
            'schedule_type'   => 'daily',
            'default_delivery'=> 'scheduled',
        ],
        'production.summary.weekly' => [
            'label'           => 'สรุปผลิตรายสัปดาห์',
            'can_instant'     => false,
            'can_scheduled'   => true,
            'job'             => 'weekly',
            'schedule_type'   => 'weekly',
            'default_delivery'=> 'scheduled',
        ],
        'production.summary.monthly' => [
            'label'           => 'สรุปผลิตรายเดือน',
            'can_instant'     => false,
            'can_scheduled'   => true,
            'job'             => 'monthly',
            'schedule_type'   => 'monthly_last_day',
            'default_delivery'=> 'scheduled',
        ],
    ];
}

/**
 *  normalize โหมดส่งต่อ event (instant / scheduled / both)
 *
 * @param string $eventKey
 * @param string $delivery
 * @return string
 */
function line_notify_normalize_delivery(string $eventKey, string $delivery): string
{
    $meta = line_notify_type_catalog()[$eventKey] ?? [];
    $canInstant = !empty($meta['can_instant']);
    $canScheduled = !empty($meta['can_scheduled']);
    $delivery = in_array($delivery, ['instant', 'scheduled', 'both'], true) ? $delivery : 'instant';

    if ($canInstant && $canScheduled) {
        return $delivery;
    }
    if ($canScheduled) {
        return 'scheduled';
    }
    return 'instant';
}

/**
 * เปิดส่งทันทีเมื่อเกิดเหตุการณ์หรือไม่
 *
 * @param string $eventKey
 * @return bool
 */
function line_notify_instant_enabled(string $eventKey): bool
{
    if (!line_notify_is_enabled($eventKey)) {
        return false;
    }
    $delivery = line_notify_event_delivery($eventKey);
    return $delivery === 'instant' || $delivery === 'both';
}

/**
 * เปิดส่งตามเวลา (cron) หรือไม่
 *
 * @param string $eventKey
 * @return bool
 */
function line_notify_scheduled_enabled(string $eventKey): bool
{
    if (!line_notify_is_enabled($eventKey)) {
        return false;
    }
    $delivery = line_notify_event_delivery($eventKey);
    return $delivery === 'scheduled' || $delivery === 'both';
}

/**
 * อ่านโหมดส่งจาก config
 *
 * @param string $eventKey
 * @return string instant|scheduled|both
 */
function line_notify_event_delivery(string $eventKey): string
{
    $schedules = line_notify_schedules();
    if (isset($schedules[$eventKey]['delivery'])) {
        return line_notify_normalize_delivery($eventKey, (string)$schedules[$eventKey]['delivery']);
    }
    $meta = line_notify_type_catalog()[$eventKey] ?? [];
    if (!empty($meta['default_delivery'])) {
        return line_notify_normalize_delivery($eventKey, (string)$meta['default_delivery']);
    }
    if (!empty($meta['can_scheduled']) && empty($meta['can_instant'])) {
        return 'scheduled';
    }
    return 'instant';
}

/**
 * ค่า default ตารางเวลาต่อ event
 *
 * @return array<string,array<string,mixed>>
 */
function line_notify_schedule_defaults(): array
{
    $out = [];
    foreach (line_notify_type_catalog() as $eventKey => $meta) {
        $delivery = line_notify_normalize_delivery(
            $eventKey,
            (string)($meta['default_delivery'] ?? 'instant')
        );
        $row = ['delivery' => $delivery];
        if (!empty($meta['can_scheduled'])) {
            $row['type'] = (string)($meta['schedule_type'] ?? 'daily');
        }
        $out[$eventKey] = $row;
    }
    return $out;
}

/**
 * อ่าน schedule จาก config (merge default) — เก็บแค่ delivery + type
 *
 * @return array<string,array<string,mixed>>
 */
function line_notify_schedules(): array
{
    $cfg = line_notify_config();
    $defaults = line_notify_schedule_defaults();
    $saved = isset($cfg['schedules']) && is_array($cfg['schedules']) ? $cfg['schedules'] : [];
    foreach ($defaults as $eventKey => $def) {
        $row = isset($saved[$eventKey]) && is_array($saved[$eventKey])
            ? array_merge($def, $saved[$eventKey])
            : $def;
        if (isset($def['type'])) {
            $row['type'] = (string)($row['type'] ?? $def['type']);
        }
        $row['delivery'] = line_notify_normalize_delivery(
            $eventKey,
            (string)($row['delivery'] ?? $def['delivery'] ?? 'instant')
        );
        unset($row['time'], $row['weekday']);
        $defaults[$eventKey] = $row;
    }
    return $defaults;
}

// ─ Production queries ────────────────────────────────────────────────────────

/**
 * ดึงรายการผลิตในช่วงวันที่
 *
 * @param string $from Y-m-d
 * @param string $to   Y-m-d
 * @return array<int,array<string,mixed>>
 */
function line_job_production_records(string $from, string $to): array
{
    if (!function_exists('qr')) {
        return [];
    }
    $rows = [];
    $sql = "SELECT a.asset_code AS serial_number, p.name AS model, pr.recorded_at AS timestamp,
                   COALESCE(pr.made_by, '') AS create_name, pr.problems_found, p.icon_path
            FROM production_records pr
            JOIN assets a ON a.id = pr.asset_id
            JOIN products p ON p.id = a.product_id
            WHERE DATE(pr.recorded_at) BETWEEN ? AND ?
            ORDER BY pr.recorded_at ASC";
    $res = qr($sql, 'ss', [$from, $to]);
    if (!$res) {
        return [];
    }
    $base = line_notify_production_base_url();
    while ($r = $res->fetch_assoc()) {
        $icon = (string)($r['icon_path'] ?? '');
        $imageUrl = '';
        if ($icon !== '' && function_exists('img_url')) {
            $imageUrl = img_url($icon);
        } elseif ($icon !== '') {
            $imageUrl = rtrim($base, '/') . '/uploads/' . ltrim(str_replace('\\', '/', $icon), '/');
        }
        $rows[] = [
            'serial_number'  => (string)$r['serial_number'],
            'model'          => (string)$r['model'],
            'timestamp'      => date('d/m/Y H:i', strtotime($r['timestamp'])),
            'create_name'    => (string)$r['create_name'],
            'problems_found' => (string)($r['problems_found'] ?? ''),
            'image_url'      => $imageUrl,
        ];
    }
    return $rows;
}

/**
 * @param array<int,array<string,mixed>> $records
 * @return array<string,array<int,array<string,mixed>>>
 */
function line_job_group_records(array $records): array
{
    $grouped = [];
    foreach ($records as $r) {
        $model = line_flex_text((string)($r['model'] ?? ''), 80);
        if ($model === '') {
            continue;
        }
        if (!isset($grouped[$model])) {
            $grouped[$model] = [];
        }
        $grouped[$model][] = $r;
    }
    return $grouped;
}

/**
 * ช่วงวันที่สำหรับ summary job
 *
 * @param string $job daily|weekly|monthly
 * @return array{from:string,to:string,date_range:string,dedup_suffix:string}
 */
function line_job_date_range(string $job): array
{
    $today = date('Y-m-d');
    if ($job === 'weekly') {
        $from = date('Y-m-d', strtotime('monday this week'));
        $to = $today;
        $range = date('d/m/Y', strtotime($from)) . ' – ' . date('d/m/Y', strtotime($to));
        return ['from' => $from, 'to' => $to, 'date_range' => $range, 'dedup_suffix' => 'weekly:' . date('o-\WW')];
    }
    if ($job === 'monthly') {
        $from = date('Y-m-01');
        $to = date('Y-m-t');
        return ['from' => $from, 'to' => $to, 'date_range' => 'เดือน ' . date('m/Y'), 'dedup_suffix' => 'monthly:' . date('Y-m')];
    }
    return [
        'from'         => $today,
        'to'           => $today,
        'date_range'   => date('d/m/Y'),
        'dedup_suffix' => 'daily:' . $today,
    ];
}

/**
 * รายการเบิกอะไหล่วันนี้ (รวมในสรุปผลิตรายวัน)
 *
 * @param string|null $date Y-m-d
 * @return array<int,array<string,mixed>>
 */
function line_job_today_withdraw_records(?string $date = null): array
{
    if (!function_exists('qr')) {
        return [];
    }
    $date = $date ?: date('Y-m-d');
    $rows = [];
    $res = qr(
        "SELECT pm.id AS movement_id, pt.name AS part_name, pm.qty, pm.mode, pm.remark, pm.made_by,
                a.asset_code
         FROM part_movements pm
         JOIN parts pt ON pt.id = pm.part_id
         LEFT JOIN assets a ON a.id = pm.ref_asset_id
         WHERE pm.direction = 'out' AND DATE(pm.moved_at) = ?
         ORDER BY pm.moved_at ASC",
        's',
        [$date]
    );
    if (!$res) {
        return [];
    }
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'movement_id' => (int)$r['movement_id'],
            'part_name'   => (string)$r['part_name'],
            'qty'         => (string)$r['qty'],
            'mode'        => (string)$r['mode'],
            'asset_code'  => (string)($r['asset_code'] ?? ''),
            'made_by'     => (string)($r['made_by'] ?? ''),
            'remark'      => (string)($r['remark'] ?? ''),
        ];
    }
    return $rows;
}

// ─ Run jobs ──────────────────────────────────────────────────────────────────

/**
 * รันงานตาม job key
 *
 * @param string               $job
 * @param array<string,mixed>  $opts skip_dedup, ignore_last_day_check
 * @return array{job:string,dispatched:int,skipped:string,event_key?:string}
 */
function line_notify_run_job(string $job, array $opts = []): array
{
    $skipDedup = !empty($opts['skip_dedup']);
    $dispatchOpts = $skipDedup ? ['skip_dedup' => true] : [];
    $result = ['job' => $job, 'dispatched' => 0, 'skipped' => ''];

    switch ($job) {
        case 'daily':
            $dr = line_job_date_range('daily');
            $records = line_job_production_records($dr['from'], $dr['to']);
            if ($records === []) {
                line_notify_dispatch('production.summary.daily', [
                    'no_data'   => true,
                    'timestamp' => date('d/m/Y H:i'),
                ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix'] . ':nodata']));
                $result['dispatched'] = 1;
                $result['event_key'] = 'production.summary.daily';
                break;
            }
            $grouped = line_job_group_records($records);
            line_notify_snapshot_save('daily_round1:' . date('Y-m-d'), $grouped, 86400 * 2);
            line_notify_dispatch('production.summary.daily', [
                'grouped' => $grouped,
                'records' => $records,
            ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix']]));
            $result['dispatched'] = 1;
            $result['event_key'] = 'production.summary.daily';
            break;

        case 'daily_update':
            $today = date('Y-m-d');
            $records = line_job_production_records($today, $today);
            if ($records === []) {
                $result['skipped'] = 'no records';
                break;
            }
            $newGrouped = line_job_group_records($records);
            $round1 = line_notify_snapshot_get('daily_round1:' . $today);
            if (!$round1) {
                line_notify_dispatch('production.summary.daily', [
                    'grouped' => $newGrouped,
                    'records' => $records,
                ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:daily_update_fallback:' . $today]));
                $result['dispatched'] = 1;
                $result['event_key'] = 'production.summary.daily';
                break;
            }
            $updates = [];
            foreach ($newGrouped as $model => $newItems) {
                $oldItems = (array)($round1[$model] ?? []);
                $oldSerials = array_map(static function ($r) {
                    return trim((string)($r['serial_number'] ?? ''));
                }, $oldItems);
                $added = [];
                foreach ($newItems as $item) {
                    $sn = trim((string)($item['serial_number'] ?? ''));
                    if ($sn !== '' && !in_array($sn, $oldSerials, true)) {
                        $added[] = $item;
                    }
                }
                if ($oldItems === [] && $newItems !== []) {
                    $updates[] = ['model' => $model, 'old' => [], 'added' => $newItems];
                } elseif ($added !== []) {
                    $updates[] = ['model' => $model, 'old' => $oldItems, 'added' => $added];
                }
            }
            if ($updates === []) {
                $result['skipped'] = 'no changes since round1';
                break;
            }
            line_notify_dispatch('production.summary.daily_update', [
                'updates' => $updates,
            ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:daily_update:' . $today . ':' . count($updates)]));
            $result['dispatched'] = 1;
            $result['event_key'] = 'production.summary.daily_update';
            break;

        case 'weekly':
            $dr = line_job_date_range('weekly');
            $records = line_job_production_records($dr['from'], $dr['to']);
            if ($records === []) {
                line_notify_dispatch('production.summary.weekly', [
                    'no_data'   => true,
                    'timestamp' => date('d/m/Y H:i'),
                ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix'] . ':nodata']));
                $result['dispatched'] = 1;
                $result['event_key'] = 'production.summary.weekly';
                break;
            }
            $problemCount = line_job_count_problems($records);
            line_notify_dispatch('production.summary.weekly', [
                'records'       => $records,
                'date_range'    => $dr['date_range'],
                'timestamp'     => date('d/m/Y H:i'),
                'problem_count' => $problemCount,
            ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix']]));
            $result['dispatched'] = 1;
            $result['event_key'] = 'production.summary.weekly';
            break;

        case 'monthly':
            if (empty($opts['ignore_last_day_check']) && !line_notify_is_last_day_of_month()) {
                $result['skipped'] = 'not last day of month';
                break;
            }
            $dr = line_job_date_range('monthly');
            $records = line_job_production_records($dr['from'], $dr['to']);
            if ($records === []) {
                line_notify_dispatch('production.summary.monthly', [
                    'no_data'   => true,
                    'timestamp' => date('d/m/Y H:i'),
                ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix'] . ':nodata']));
                $result['dispatched'] = 1;
                $result['event_key'] = 'production.summary.monthly';
                break;
            }
            $problemCount = line_job_count_problems($records);
            line_notify_dispatch('production.summary.monthly', [
                'records'       => $records,
                'date_range'    => $dr['date_range'],
                'timestamp'     => date('d/m/Y H:i'),
                'problem_count' => $problemCount,
            ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix']]));
            $result['dispatched'] = 1;
            $result['event_key'] = 'production.summary.monthly';
            break;

        case 'low_stock_scan':
            if (!function_exists('dbParts')) {
                $result['skipped'] = 'dbParts unavailable';
                break;
            }
            try {
                $pdo = dbParts();
                $GLOBALS['line_notify_stock_db'] = $pdo;
                $items = $pdo->query(
                    // ข้ามอะไหล่ที่ปิดการใช้งานแล้ว — ไม่ต้องเตือนให้สั่งซื้อของที่เลิกใช้
                    // เรียงตามผู้จำหน่ายก่อน เพื่อให้การ์ดที่แยกตาม dealer เรียงรายการสวยงาม
                    'SELECT id, code, name, quantity, min_stock, unit, supplier, purchase_link
                     FROM products WHERE quantity <= min_stock AND is_active = 1
                     ORDER BY supplier IS NULL, supplier, quantity ASC'
                )->fetchAll(PDO::FETCH_ASSOC);
                if ($items === []) {
                    $result['skipped'] = 'no low stock';
                    break;
                }
                line_notify_dispatch('stock.low_threshold', [
                    'batch'     => true,
                    'items'     => $items,
                    'timestamp' => date('d/m/Y H:i'),
                ], array_merge($dispatchOpts, ['dedup_key' => 'stock.low_threshold:scan:' . date('Y-m-d')]));
                $result['dispatched'] = 1;
                $result['event_key'] = 'stock.low_threshold';
            } catch (Throwable $e) {
                $result['skipped'] = $e->getMessage();
            }
            break;

        default:
            $result['skipped'] = 'unknown job';
    }
    return $result;
}

/**
 * นับรายการที่มีปัญหา
 *
 * @param array<int,array<string,mixed>> $records
 * @return int
 */
function line_job_count_problems(array $records): int
{
    $n = 0;
    foreach ($records as $r) {
        if (trim((string)($r['problems_found'] ?? '')) !== '') {
            $n++;
        }
    }
    return $n;
}

/**
 * ส่งทันทีตามประเภท (จากหลังบ้าน)
 *
 * @param string $eventKey
 * @return array{ok:bool,message:string,detail?:string,result?:array<string,mixed>}
 */
function line_notify_send_now(string $eventKey): array
{
    if (!line_notify_is_enabled($eventKey)) {
        return ['ok' => false, 'message' => 'ปิดใช้งานประเภทนี้อยู่'];
    }
    $catalog = line_notify_type_catalog();
    if (!isset($catalog[$eventKey])) {
        return ['ok' => false, 'message' => 'ไม่รู้จักประเภท: ' . $eventKey];
    }
    $meta = $catalog[$eventKey];
    $preview = null;
    $canInstant = !empty($meta['can_instant']) && line_notify_instant_enabled($eventKey);
    $canScheduled = !empty($meta['can_scheduled']);

    if ($canInstant) {
        $instantPreview = line_notify_send_now_instant($eventKey);
        if ($instantPreview['ok']) {
            $preview = $instantPreview;
        }
    }

    if ($preview === null && $canScheduled) {
        $job = (string)($meta['job'] ?? '');
        $jobOpts = ['skip_dedup' => true];
        if ($job === 'monthly') {
            $jobOpts['ignore_last_day_check'] = true;
        }
        $preview = line_notify_run_job($job, $jobOpts);
        if ($job === 'daily' && (int)($preview['dispatched'] ?? 0) === 0) {
            $dr = line_job_date_range('daily');
            $records = line_job_production_records($dr['from'], $dr['to']);
            if ($records !== []) {
                line_notify_dispatch('production.summary.daily', [
                    'grouped' => line_job_group_records($records),
                    'records' => $records,
                ], ['skip_dedup' => true, 'dedup_key' => 'manual:daily:' . microtime(true)]);
                $preview = ['dispatched' => 1, 'job' => 'daily'];
            }
        }
        if ((int)($preview['dispatched'] ?? 0) === 0) {
            return [
                'ok'      => false,
                'message' => 'ไม่มีข้อมูลที่จะส่ง',
                'detail'  => (string)($preview['skipped'] ?? ''),
                'result'  => $preview,
            ];
        }
    }

    if ($preview === null) {
        return ['ok' => false, 'message' => 'ไม่รองรับการส่งทันทีสำหรับโหมดที่ตั้งไว้'];
    }

    $stats = line_notify_process_outbox(15);
    if ($stats['sent'] > 0) {
        return [
            'ok'      => true,
            'message' => 'ส่งแล้ว — ตรวจข้อความใน LINE',
            'detail'  => 'sent=' . $stats['sent'],
            'result'  => $preview ?? [],
        ];
    }
    return [
        'ok'      => false,
        'message' => 'ใส่คิวแล้วแต่ส่งไม่สำเร็จ',
        'detail'  => json_encode($stats, JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * ส่งตัวอย่างจากข้อมูลล่าสุดใน DB (instant event)
 *
 * @param string $eventKey
 * @return array{ok:bool,message?:string,detail?:string,dispatched?:int}
 */
function line_notify_send_now_instant(string $eventKey): array
{
    if (!function_exists('qr')) {
        return ['ok' => false, 'message' => 'ไม่พร้อม query DB'];
    }

    switch ($eventKey) {
        case 'production.problem_found':
            $rows = [];
            $res = qr(
                "SELECT pr.asset_id, a.asset_code, p.name AS model, pr.problems_found AS problems,
                        pr.fix, pr.made_by, DATE(pr.recorded_at) AS produced_at
                 FROM production_records pr
                 JOIN assets a ON a.id = pr.asset_id
                 JOIN products p ON p.id = a.product_id
                 WHERE pr.problems_found IS NOT NULL AND TRIM(pr.problems_found) != ''
                   AND DATE(pr.recorded_at) = CURDATE()
                 ORDER BY pr.recorded_at DESC"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            if ($rows === []) {
                return ['ok' => false, 'message' => 'ไม่พบเครื่องผลิตใหม่วันนี้ที่มีปัญหา'];
            }
            foreach ($rows as $row) {
                line_notify_dispatch('production.problem_found', [
                    'asset_id'    => (int)$row['asset_id'],
                    'asset_code'  => (string)$row['asset_code'],
                    'model'       => (string)$row['model'],
                    'problems'    => (string)$row['problems'],
                    'fix'         => (string)($row['fix'] ?? ''),
                    'made_by'     => (string)($row['made_by'] ?? ''),
                    'produced_at' => (string)$row['produced_at'],
                ], ['skip_dedup' => true, 'dedup_key' => 'manual:problem:' . (int)$row['asset_id'] . ':' . microtime(true)]);
            }
            return ['ok' => true, 'dispatched' => count($rows)];

        case 'ma.repair_required':
            $rows = [];
            $res = qr(
                "SELECT m.id AS ma_id, m.asset_id, a.asset_code, m.ma_round, m.visited_at,
                        m.repair_items, m.remark, m.done_by
                 FROM ma_records m
                 JOIN assets a ON a.id = m.asset_id
                 WHERE m.result = 'repair' AND DATE(m.visited_at) = CURDATE()
                 ORDER BY m.visited_at DESC"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            if ($rows === []) {
                return ['ok' => false, 'message' => 'ไม่พบรายการ MA ใหม่วันนี้ที่ต้องซ่อม'];
            }
            foreach ($rows as $row) {
                line_notify_dispatch('ma.repair_required', [
                    'asset_id'     => (int)$row['asset_id'],
                    'asset_code'   => (string)$row['asset_code'],
                    'ma_round'     => (int)$row['ma_round'],
                    'visited_at'   => (string)$row['visited_at'],
                    'repair_items' => (string)($row['repair_items'] ?? ''),
                    'remark'       => (string)($row['remark'] ?? ''),
                    'done_by'      => (string)($row['done_by'] ?? ''),
                ], ['skip_dedup' => true, 'dedup_key' => 'manual:ma:' . (int)$row['ma_id'] . ':' . microtime(true)]);
            }
            return ['ok' => true, 'dispatched' => count($rows)];

        case 'stock.manual_withdraw':
            $withdraws = line_job_today_withdraw_records();
            if ($withdraws === []) {
                return ['ok' => false, 'message' => 'ไม่พบรายการเบิกอะไหล่วันนี้'];
            }
            line_notify_dispatch('stock.manual_withdraw', [
                'withdraw_items' => $withdraws,
            ], ['skip_dedup' => true, 'dedup_key' => 'manual:withdraw:' . microtime(true)]);
            return ['ok' => true, 'dispatched' => 1, 'message' => 'สรุปการเบิกอะไหล่วันนี้'];

        default:
            return ['ok' => false, 'message' => 'ประเภทนี้ไม่รองรับส่งทันที'];
    }
}

/**
 * เขียน log cron LINE ลง private/logs/line-cron.log
 *
 * @param array<string,mixed> $payload
 * @return void
 */
function line_notify_append_cron_log(array $payload): void
{
    if (!function_exists('app_error_log_path')) {
        return;
    }
    $logFile = dirname(app_error_log_path()) . '/line-cron.log';
    $line = date('Y-m-d H:i:s') . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * ชื่อไฟล์ Plesk script ต่อ event (trigger รายการละ 1 task)
 *
 * @param string $eventKey
 * @return string|null
 */
function line_notify_plesk_script_name(string $eventKey): ?string
{
    $map = [
        'stock.low_threshold'           => 'plesk_line_job_low_stock.php',
        'production.summary.daily'      => 'plesk_line_job_daily.php',
        'production.summary.daily_update'=> 'plesk_line_job_daily_update.php',
        'production.summary.weekly'     => 'plesk_line_job_weekly.php',
        'production.summary.monthly'    => 'plesk_line_job_monthly.php',
    ];
    return $map[$eventKey] ?? null;
}

/**
 * path สcript สำหรับแสดงใน Plesk (relative จาก httpdocs)
 *
 * @param string $eventKey
 * @return string
 */
function line_notify_plesk_script_path(string $eventKey): string
{
    $name = line_notify_plesk_script_name($eventKey);
    if ($name === null) {
        return '';
    }
    return 'production/finishgoogs_ma_update/cron/' . $name;
}

/**
 * คำแนะนำการตั้ง Plesk Scheduled Task ต่อ event (เวลาตั้งใน Plesk เท่านั้น)
 *
 * @param string $eventKey
 * @return string
 */
function line_notify_plesk_run_hint(string $eventKey): string
{
    $meta = line_notify_type_catalog()[$eventKey] ?? [];
    $type = (string)($meta['schedule_type'] ?? 'daily');
    if ($type === 'weekly') {
        return 'Cron — เลือกวัน+เวลาใน Plesk (เช่น ศ 17:30)';
    }
    if ($type === 'monthly_last_day') {
        return 'Cron 28–31 * * (เช่น 10 20 28-31 * *) — ส่งเฉพาะวันสุดท้ายเดือน';
    }
    return 'Daily — ตั้งเวลาใน Plesk';
}

/**
 * ตรวจว่าวันนี้เป็นวันสุดท้ายของเดือน (Asia/Bangkok)
 *
 * ใช้กับ job monthly จาก Plesk — ตั้ง cron รัน 28–31 แล้วข้ามวันที่ไม่ใช่วันสุดท้าย
 *
 * @return bool
 */
function line_notify_is_last_day_of_month(): bool
{
    $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    return $now->format('Y-m-d') === $now->format('Y-m-t');
}
