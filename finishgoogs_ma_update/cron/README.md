# LINE Notification — Windows Task Scheduler

## ตั้งเวลาในระบบ

**เวลาส่งแต่ละประเภท** ตั้งที่ **ระบบหลังบ้าน → แจ้งเตือน LINE** แล้วกดบันทึก

Cron ใช้ `--job=tick` อ่านเวลาจาก `line.secrets.php` อัตโนมัติ (ไม่ต้องแก้ Task Scheduler เมื่อเปลี่ยนเวลา)

## Prerequisites

1. ตั้งค่า LINE ที่ `line_notify_settings.php`
2. ไฟล์ `line.secrets.php` อยู่นอก web root
3. PHP CLI เช่น `D:\AppServ\php7\php.exe`

## ติดตั้ง (แนะนำ) — tick + worker

```bat
cd D:\AppServ\www\production\finishgoogs_ma_update\cron
setup_line_tasks.bat
```

หรือ PowerShell:

```powershell
$PhpExe = "D:\AppServ\php7\php.exe"
$CronDir = "D:\AppServ\www\production\finishgoogs_ma_update\cron"

# Tick ทุก 1 นาที — ตรวจเวลาจากหลังบ้าน
schtasks /Create /TN "Production_LINE_Schedule_Tick" /TR "`"$PhpExe`" `"$CronDir\line_notify_scheduled.php`" --job=tick" /SC MINUTE /MO 1 /F

# Worker ทุก 2 นาที — ส่ง outbox
schtasks /Create /TN "Production_LINE_Notify_Worker" /TR "`"$PhpExe`" `"$CronDir\line_notify_worker.php`"" /SC MINUTE /MO 2 /F
```

## ทดสอบด้วยมือ

```bat
php line_notify_scheduled.php --job=tick
php line_notify_worker.php
```

หรือใช้ปุ่ม **ส่งทันที** ในหลังบ้าน (ไม่ต้องรอ cron)

## ตารางงาน

| Task | คำสั่ง | ความถี่ |
|------|--------|---------|
| Schedule tick | `--job=tick` | ทุก 1 นาที |
| Worker | `line_notify_worker.php` | ทุก 2 นาที |

## งานแยกตาม job (ทางเลือก)

ยังเรียก `--job=daily`, `weekly`, `monthly`, `low_stock_scan` ได้โดยตรง (ไม่ใช้เวลาจากหลังบ้าน)
