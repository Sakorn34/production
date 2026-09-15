<?php
/**
 * shared/finishgood_shortage_dashboard.php — รายการสินค้าที่ต้องผลิตเพิ่ม สำหรับหน้า Dashboard
 *
 * ใช้ตัวเลขชุดเดียวกับการแจ้งเตือน LINE ทุกขั้น (API setupsystem → แทนรุ่นที่นับจากทะเบียนเรา
 * → ตัดรุ่นที่ปิดแจ้งเตือน) คนที่เห็นการ์ดใน LINE กับบน Dashboard จะได้เห็นเลขเดียวกัน
 *
 * ทำไมต้อง cache: API ของ setupsystem คำนวณ stock ทั้งระบบทุกครั้ง ใช้เวลาราว 5 วินาที
 *   Dashboard เปิดกันทั้งวัน ถ้ายิงทุกครั้งจะช้าทั้งหน้าและกดภาระให้ setupsystem
 *   จึงเก็บผลไว้ FG_SHORTAGE_DASH_CACHE_TTL วินาที และหน้า Dashboard โหลดแยกทีหลัง (ไม่บล็อกหน้า)
 *
 * ถ้ารอบนี้ดึงไม่ได้ แต่มีผลเก่าอยู่ → คืนผลเก่าพร้อมบอกว่าเป็นข้อมูลเมื่อไหร่ ดีกว่าการ์ดว่าง
 */

require_once __DIR__ . '/finishgood_shortage_client.php';
require_once __DIR__ . '/finishgood_shortage_filter.php';
require_once __DIR__ . '/finishgood_shortage_registry.php';

/** @var int อายุ cache (วินาที) */
const FG_SHORTAGE_DASH_CACHE_TTL = 600;

/**
 * ไฟล์ cache — อยู่โฟลเดอร์เดียวกับ error log ซึ่งอยู่นอก web root และเขียนได้แน่นอน
 *
 * @return string '' = หาที่เก็บไม่ได้ (ทำงานต่อได้โดยไม่มี cache)
 */
function fg_shortage_dash_cache_file(): string
{
    if (!function_exists('app_error_log_path')) {
        return '';
    }
    $dir = dirname(app_error_log_path());
    return is_dir($dir) && is_writable($dir) ? $dir . '/fg-shortage-dashboard.json' : '';
}

/**
 * อ่าน cache
 *
 * @return array<string,mixed>|null
 */
function fg_shortage_dash_cache_read(): ?array
{
    $file = fg_shortage_dash_cache_file();
    if ($file === '' || !is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) && isset($data['saved_at'], $data['items']) ? $data : null;
}

/**
 * บันทึก cache — การตั้งค่ารายรุ่นเปลี่ยนเมื่อไหร่ ต้องล้างด้วย fg_shortage_dash_cache_clear()
 *
 * @param array<string,mixed> $data
 * @return void
 */
function fg_shortage_dash_cache_write(array $data): void
{
    $file = fg_shortage_dash_cache_file();
    if ($file === '') {
        return;
    }
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * ล้าง cache — เรียกหลังเปลี่ยนรุ่นที่นับจากทะเบียน/รุ่นที่ปิดแจ้งเตือน ให้ Dashboard เห็นผลทันที
 *
 * @return void
 */
function fg_shortage_dash_cache_clear(): void
{
    $file = fg_shortage_dash_cache_file();
    if ($file !== '' && is_file($file)) {
        @unlink($file);
    }
}

/**
 * รายการสินค้าที่ต้องผลิตเพิ่ม (ผ่าน cache)
 *
 * @param bool $force true = ไม่ใช้ cache
 * @return array{ok:bool,error:string,items:array<int,array<string,mixed>>,total_shortage:int,skipped:int,registry_error:string,saved_at:int,timestamp_text:string,stale:bool}
 */
function fg_shortage_dashboard_data(bool $force = false): array
{
    $cached = fg_shortage_dash_cache_read();
    if (!$force && $cached !== null && (time() - (int) $cached['saved_at']) < FG_SHORTAGE_DASH_CACHE_TTL) {
        $cached['stale'] = false;
        return $cached;
    }

    $fetched = finishgood_shortage_fetch();
    if (empty($fetched['ok'])) {
        if ($cached !== null) {
            $cached['stale'] = true;
            $cached['error'] = (string) $fetched['error'];
            return $cached;
        }
        return [
            'ok' => false, 'error' => (string) $fetched['error'], 'items' => [], 'total_shortage' => 0,
            'skipped' => 0, 'registry_error' => '', 'saved_at' => 0, 'timestamp_text' => '', 'stale' => false,
        ];
    }

    $registry = fg_shortage_apply_registry($fetched['items']);
    $filtered = fg_shortage_filter_items($registry['items']);

    $total = 0;
    foreach ($filtered['items'] as $it) {
        $total += (int) ($it['need'] ?? 0);
    }

    $data = [
        'ok'             => true,
        'error'          => '',
        'items'          => $filtered['items'],
        'total_shortage' => $total,
        'skipped'        => (int) $filtered['skipped'],
        'registry_error' => (string) $registry['error'],
        'saved_at'       => time(),
        'timestamp_text' => (string) $fetched['timestamp_text'],
        'stale'          => false,
    ];
    fg_shortage_dash_cache_write($data);
    return $data;
}
