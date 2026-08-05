<?php
/**
 * shared/datetime_helpers.php — มาตรฐานบันทึกและแสดงวันเวลา
 *
 * ใช้ร่วมกันระหว่าง finishgoogs_ma_update, parts และ shared modules
 * รูปแบบบันทึก: Y-m-d H:i:s · แสดงผล: d/m/Y หรือ d/m/Y H:i
 *
 * Flow:
 *   dt_from_input($_POST['visited_at'])  → บันทึก DB
 *   dt_for_input($row['visited_at'])     → ค่าใน datetime-local
 *   dt_display($row['created_at'])       → แสดงในตาราง
 */

// ─ Helpers ───────────────────────────────────────────────────────────────────

/**
 * คืนเวลาปัจจุบันในรูปแบบบันทึก DB
 *
 * @return string Y-m-d H:i:s
 */
function dt_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * แปลงค่าจากฟอร์ม (date / datetime-local) เป็นรูปแบบบันทึก DB
 *
 * @param string|null $value ค่าจาก input
 * @param bool $defaultNow ถ้าว่างให้ใช้เวลาปัจจุบัน
 * @return string Y-m-d H:i:s หรือค่าว่างถ้า $defaultNow=false
 */
function dt_from_input(?string $value, bool $defaultNow = true): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $defaultNow ? dt_now() : '';
    }

    $value = str_replace('T', ' ', $value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ' 00:00:00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        return $value . ':00';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $defaultNow ? dt_now() : '';
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * แปลงค่า DB เป็นค่าสำหรับ attribute ของ datetime-local
 *
 * @param string|null $value ค่าจาก DB
 * @return string Y-m-dTH:i
 */
function dt_for_input(?string $value): string
{
    if ($value === null || trim($value) === '' || strpos(trim($value), '0000-00-00') === 0) {
        return date('Y-m-d\TH:i');
    }

    $ts = strtotime(trim($value));
    if ($ts === false) {
        return date('Y-m-d\TH:i');
    }

    return date('Y-m-d\TH:i', $ts);
}

/**
 * แสดงวันเวลาแบบไทย — มีเวลาเมื่อข้อมูลมีส่วนเวลา (ไม่ใช่ 00:00:00 ล้วนๆ)
 *
 * @param string|null $value ค่าจาก DB
 * @return string d/m/Y หรือ d/m/Y H:i
 */
function dt_display(?string $value): string
{
    if ($value === null || $value === '' || $value === '0000-00-00' || strpos(trim($value), '0000-00-00') === 0) {
        return '-';
    }

    $raw = trim($value);
    $ts = strtotime($raw);
    if ($ts === false) {
        return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return date('d/m/Y', $ts);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]00:00(:00)?$/', $raw)) {
        return date('d/m/Y', $ts);
    }

    return date('d/m/Y H:i', $ts);
}

/**
 * แสดงวันเวลาแบบเต็มเสมอ (บังคับมี H:i)
 *
 * @param string|null $value ค่าจาก DB
 * @return string d/m/Y H:i
 */
function dt_display_full(?string $value): string
{
    if ($value === null || $value === '' || $value === '0000-00-00' || strpos(trim($value), '0000-00-00') === 0) {
        return '-';
    }

    $ts = strtotime(trim($value));
    if ($ts === false) {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    return date('d/m/Y H:i', $ts);
}

/**
 * แปลงเป็น DATE ล้วน Y-m-d (ใช้กับฟิลด์ปฏิทิน เช่น produced_at)
 *
 * @param string|null $value ค่าจาก input หรือ DB
 * @return string Y-m-d
 */
function dt_date_only(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return date('Y-m-d');
    }

    $ts = strtotime(str_replace('T', ' ', $value));
    if ($ts === false) {
        return date('Y-m-d');
    }

    return date('Y-m-d', $ts);
}

/**
 * อายุสินค้าแบบอ่านง่าย นับจากวันผลิตถึงวันนี้
 *
 * แสดงหน่วยใหญ่สุด 2 ระดับ เช่น "1 ปี 3 เดือน", "5 เดือน 12 วัน", "18 วัน"
 * ถ้าวันผลิตเป็นอนาคต (กรอกผิด) คืนค่าว่างเพื่อไม่ให้แสดงค่าติดลบ
 *
 * @param string|null $producedAt วันผลิต (Y-m-d หรือ Y-m-d H:i:s)
 * @param string|null $now        เวลาอ้างอิง (ไว้เทสต์) ว่าง = ตอนนี้
 * @return string ข้อความอายุ หรือค่าว่างถ้าคำนวณไม่ได้
 */
function dt_age_text(?string $producedAt, ?string $now = null): string
{
    $raw = trim((string) $producedAt);
    if ($raw === '' || $raw === '0000-00-00' || strpos($raw, '0000-00-00') === 0) {
        return '';
    }

    try {
        $start = new DateTimeImmutable(str_replace('T', ' ', $raw));
        $end = new DateTimeImmutable($now !== null && trim($now) !== '' ? $now : 'now');
    } catch (Exception $e) {
        return '';
    }

    if ($start > $end) {
        return '';
    }

    $diff = $start->diff($end);
    $years = (int) $diff->y;
    $months = (int) $diff->m;
    $days = (int) $diff->d;

    if ($years > 0) {
        return $months > 0
            ? $years . ' ปี ' . $months . ' เดือน'
            : $years . ' ปี';
    }
    if ($months > 0) {
        return $days > 0
            ? $months . ' เดือน ' . $days . ' วัน'
            : $months . ' เดือน';
    }
    if ($days > 0) {
        return $days . ' วัน';
    }

    return 'วันนี้';
}
