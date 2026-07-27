# LINE Notification — Cron / Plesk Scheduled Task

## ตั้งเวลา

**เวลาและวันส่ง** ตั้งที่ **Plesk Scheduled Task** เท่านั้น

หลังบ้าน (`line_notify_settings.php`) ตั้งแค่ **เปิด/ปิด**, **วิธีส่ง** (ทันที/ตามเวลา/ทั้งสอง), token และ recipient

## Prerequisites

1. ตั้งค่า LINE ที่ `line_notify_settings.php`
2. ไฟล์ `line.secrets.php` อยู่นอก web root
3. PHP CLI หรือ Plesk Scheduled Task (PHP 8.2)

---

## Plesk — trigger รายการละ 1 task (แนะนำ)

สร้าง Scheduled Task แยกตามประเภทที่เปิด **ตามเวลา**:

| ประเภท | Script (Run a PHP script) | Plesk Run |
|--------|---------------------------|-----------|
| สรุปผลิตรายวัน | `production/.../cron/plesk_line_job_daily.php` | Daily — ตั้งเวลาใน Plesk |
| อัปเดตหลังเลิกงาน | `plesk_line_job_daily_update.php` | Daily |
| อะไหล่ใกล้หมด | `plesk_line_job_low_stock.php` | Daily |
| สรุปรายสัปดาห์ | `plesk_line_job_weekly.php` | Cron วัน+เวลา |
| สรุปรายเดือน | `plesk_line_job_monthly.php` | Cron วันสุดท้ายเดือน |

ดู path และคำแนะนำได้ที่ **หลังบ้าน → ตั้งค่า LINE** (คอลัมน์ Plesk script)

---

## Worker สำรอง (ไม่บังคับ)

| ช่อง | ค่า |
|------|-----|
| Script | `production/finishgoogs_ma_update/cron/plesk_line_worker.php` |
| Cron | `*/2 * * * *` |

ใช้เมื่อ instant notification ต้องการส่ง outbox เร็วขึ้น — job รายการเรียก `process_outbox` หลังรัน job อยู่แล้ว

---

## Windows (dev localhost)

```powershell
$PhpExe = "D:\AppServ\php7\php.exe"
$CronDir = "D:\AppServ\www\production\finishgoogs_ma_update\cron"

schtasks /Create /TN "Production_LINE_Notify_Worker" /TR "`"$PhpExe`" `"$CronDir\line_notify_worker.php`"" /SC MINUTE /MO 2 /F
```

---

## ทดสอบด้วยมือ (SSH)

```bash
/opt/plesk/php/8.2/bin/php .../cron/plesk_line_job_daily.php
/opt/plesk/php/8.2/bin/php .../cron/line_notify_scheduled.php --job=test
```

หรือใช้ปุ่ม **ส่งทันที** ในหลังบ้าน (ไม่ต้องรอ cron)

## งาน CLI แยกตาม job

ยังเรียก `--job=daily`, `weekly`, `monthly`, `low_stock_scan` ผ่าน `line_notify_scheduled.php` ได้ (manual/debug)
