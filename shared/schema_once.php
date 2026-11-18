<?php
/**
 * shared/schema_once.php — จำว่าตรวจ schema แล้ว ไม่ต้อง SHOW COLUMNS ทุกหน้า
 *
 * ทุกหน้าของระบบ production เคยยิง SHOW COLUMNS / CREATE TABLE IF NOT EXISTS ตอน bootstrap
 * ถ้าตาราง part_movements ถูกล็อก (เช่น กำลังสร้าง index) ทั้งระบบจะค้างตาม
 *
 * Flow: schema_once_skip('key') ถ้ายังไม่เคย → ตรวจจริง → schema_once_mark('key')
 */

/**
 * path ไฟล์จำผลตรวจ (อยู่นอก web root)
 *
 * @param string $key
 * @return string
 */
function schema_once_file(string $key): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
    if ($safe === '') {
        $safe = 'unknown';
    }
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'fg_schema_' . $safe . '.ok';
}

/**
 * ตรวจ schema นี้ไปแล้วในช่วง TTL หรือยัง
 *
 * @param string $key
 * @param int    $ttl วินาที (ค่าเริ่ม 1 วัน)
 * @return bool true = ข้ามได้
 */
function schema_once_skip(string $key, int $ttl = 86400): bool
{
    $file = schema_once_file($key);
    return is_file($file) && (time() - (int) filemtime($file)) < $ttl;
}

/**
 * จำว่าตรวจผ่านแล้ว
 *
 * @param string $key
 * @return void
 */
function schema_once_mark(string $key): void
{
    @file_put_contents(schema_once_file($key), (string) time(), LOCK_EX);
}
