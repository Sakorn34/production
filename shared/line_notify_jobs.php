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
            'label'  => 'พบปัญหาตอนผลิต',
            'mode'   => 'instant',
            'job'    => null,
        ],
        'stock.low_threshold' => [
            'label'  => 'อะไหล่ใกล้หมด',
            'mode'   => 'scheduled',
            'job'    => 'low_stock_scan',
            'default_time' => '08:00',
        ],
        'ma.repair_required' => [
            'label'  => 'MA ต้องซ่อม',
            'mode'   => 'instant',
            'job'    => null,
        ],
        'stock.manual_withdraw' => [
            'label'  => 'เบิกอะไหล่ (manual)',
            'mode'   => 'instant',
            'job'    => null,
        ],
        'production.summary.daily' => [
            'label'  => 'สรุปผลิตรายวัน',
            'mode'   => 'scheduled',
            'job'    => 'daily',
            'default_time' => '17:30',
            'schedule_type' => 'daily',
        ],
        'production.summary.daily_update' => [
            'label'  => 'อัปเดตผลิตหลังเลิกงาน',
            'mode'   => 'scheduled',
            'job'    => 'daily_update',
            'default_time' => '18:00',
            'schedule_type' => 'daily',
        ],
        'production.summary.weekly' => [
            'label'  => 'สรุปผลิตรายสัปดาห์',
            'mode'   => 'scheduled',
            'job'    => 'weekly',
            'default_time' => '17:30',
            'schedule_type' => 'weekly',
            'default_weekday' => 0,
        ],
        'production.summary.monthly' => [
            'label'  => 'สรุปผลิตรายเดือน',
            'mode'   => 'scheduled',
            'job'    => 'monthly',
            'default_time' => '17:35',
            'schedule_type' => 'monthly_last_day',
        ],
    ];
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
        if (($meta['mode'] ?? '') !== 'scheduled') {
            continue;
        }
        $out[$eventKey] = [
            'time'    => (string)($meta['default_time'] ?? '08:00'),
            'weekday' => (int)($meta['default_weekday'] ?? 0),
            'type'    => (string)($meta['schedule_type'] ?? 'daily'),
        ];
    }
    return $out;
}

/**
 * อ่านตารางเวลาจาก config (merge default)
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
        $row['time'] = line_notify_normalize_time((string)($row['time'] ?? $def['time']));
        $row['weekday'] = (int)($row['weekday'] ?? $def['weekday']);
        $row['type'] = (string)($row['type'] ?? $def['type']);
        $defaults[$eventKey] = $row;
    }
    return $defaults;
}

/**
 *  normalize เวลา HH:MM
 *
 * @param string $time
 * @return string
 */
function line_notify_normalize_time(string $time): string
{
    $time = trim($time);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    return '08:00';
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
                $withdraws = line_job_today_withdraw_records($dr['from']);
                if ($withdraws === []) {
                    line_notify_dispatch('production.summary.daily', [
                        'no_data'   => true,
                        'timestamp' => date('d/m/Y H:i'),
                    ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix'] . ':nodata']));
                } else {
                    line_notify_dispatch('production.summary.daily', [
                        'grouped'        => [],
                        'records'        => [],
                        'withdraw_items' => $withdraws,
                    ], array_merge($dispatchOpts, ['dedup_key' => 'production.summary:' . $dr['dedup_suffix'] . ':withdraw_only']));
                }
                $result['dispatched'] = 1;
                $result['event_key'] = 'production.summary.daily';
                break;
            }
            $grouped = line_job_group_records($records);
            line_notify_snapshot_save('daily_round1:' . date('Y-m-d'), $grouped, 86400 * 2);
            line_notify_dispatch('production.summary.daily', [
                'grouped'         => $grouped,
                'records'         => $records,
                'withdraw_items'  => line_job_today_withdraw_records($dr['from']),
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
                    'grouped'        => $newGrouped,
                    'records'        => $records,
                    'withdraw_items' => line_job_today_withdraw_records($today),
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
            if (empty($opts['ignore_last_day_check']) && date('Y-m-d') !== date('Y-m-t')) {
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
                    'SELECT id, code, name, quantity, min_stock, unit FROM products WHERE quantity <= min_stock ORDER BY quantity ASC'
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
 * รันงานที่ถึงเวลาตาม config (cron --job=tick)
 *
 * @return array<int,array<string,mixed>>
 */
function line_notify_run_due_schedules(): array
{
    $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $hhmm = $now->format('H:i');
    $dow = (int)$now->format('w');
    $isLastDay = $now->format('Y-m-d') === $now->format('Y-m-t');
    $catalog = line_notify_type_catalog();
    $schedules = line_notify_schedules();
    $ran = [];

    foreach ($schedules as $eventKey => $sched) {
        if (!line_notify_is_enabled($eventKey)) {
            continue;
        }
        $meta = $catalog[$eventKey] ?? null;
        if (!$meta || ($meta['mode'] ?? '') !== 'scheduled') {
            continue;
        }
        $time = line_notify_normalize_time((string)($sched['time'] ?? ''));
        if ($time !== $hhmm) {
            continue;
        }
        $type = (string)($sched['type'] ?? 'daily');
        if ($type === 'weekly' && (int)($sched['weekday'] ?? 0) !== $dow) {
            continue;
        }
        if ($type === 'monthly_last_day' && !$isLastDay) {
            continue;
        }

        $dedupKey = 'schedule_tick:' . $eventKey . ':' . date('Y-m-d') . ':' . $time;
        if (!line_notify_dedup_take($dedupKey, 900)) {
            continue;
        }

        $job = (string)($meta['job'] ?? '');
        if ($job === '') {
            continue;
        }
        $jobOpts = [];
        if ($job === 'monthly') {
            $jobOpts['ignore_last_day_check'] = true;
        }
        $ran[] = line_notify_run_job($job, $jobOpts);
    }
    return $ran;
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

    if (($meta['mode'] ?? '') === 'instant') {
        $preview = line_notify_send_now_instant($eventKey);
        if (!$preview['ok']) {
            return $preview;
        }
    } else {
        $job = (string)($meta['job'] ?? '');
        $jobOpts = ['skip_dedup' => true];
        if ($job === 'monthly') {
            $jobOpts['ignore_last_day_check'] = true;
        }
        $preview = line_notify_run_job($job, $jobOpts);
        if ($job === 'daily' && (int)($preview['dispatched'] ?? 0) === 0) {
            // สำหรับทดสอบส่งทันที — ใส่ withdraw ในชุด daily ด้วย
            $dr = line_job_date_range('daily');
            $records = line_job_production_records($dr['from'], $dr['to']);
            $withdraws = line_job_today_withdraw_records($dr['from']);
            if ($records !== [] || $withdraws !== []) {
                line_notify_dispatch('production.summary.daily', [
                    'grouped'        => line_job_group_records($records),
                    'records'        => $records,
                    'withdraw_items' => $withdraws,
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
            $records = line_job_production_records(date('Y-m-d'), date('Y-m-d'));
            if ($records === []) {
                line_notify_dispatch('production.summary.daily', [
                    'no_data'        => true,
                    'timestamp'      => date('d/m/Y H:i'),
                    'withdraw_items' => $withdraws,
                ], ['skip_dedup' => true, 'dedup_key' => 'manual:withdraw_bundle:' . microtime(true)]);
            } else {
                line_notify_dispatch('production.summary.daily', [
                    'grouped'        => line_job_group_records($records),
                    'records'        => $records,
                    'withdraw_items' => $withdraws,
                ], ['skip_dedup' => true, 'dedup_key' => 'manual:withdraw_bundle:' . microtime(true)]);
            }
            return ['ok' => true, 'dispatched' => 1, 'message' => 'รวมเบิกอะไหล่ + สรุปผลิตในชุดเดียว'];

        default:
            return ['ok' => false, 'message' => 'ประเภทนี้ไม่รองรับส่งทันที'];
    }
}

/** @return array<int,string> วันในสัปดาห์สำหรับ select */
function line_notify_weekday_options(): array
{
    return [
        0 => 'อาทิตย์',
        1 => 'จันทร์',
        2 => 'อังคาร',
        3 => 'พุธ',
        4 => 'พฤหัส',
        5 => 'ศุกร์',
        6 => 'เสาร์',
    ];
}
