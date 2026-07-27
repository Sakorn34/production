# LINE Plesk — trigger รายการละแจ้งเตือน (ไม่มี tick / ไม่มีตั้งเวลาหลังบ้าน)

---

## 1. อัป FTP (ทับไฟล์)

อัปจาก `deploy/release/production/` → `httpdocs/production/` (คง path)

ไฟล์สำคัญ:

```
finishgoogs_ma_update/cron/line_notify_scheduled.php
finishgoogs_ma_update/cron/plesk_line_run_job.php
finishgoogs_ma_update/cron/plesk_line_job_*.php
finishgoogs_ma_update/cron/README.md
finishgoogs_ma_update/line_notify_settings.php
finishgoogs_ma_update/includes/line_notify_settings.php
shared/line_notify_jobs.php
```

**ห้ามลบ:** `line.secrets.php`, `config.paths.php`, secrets อื่น

---

## 2. Plesk — ปิด/ลบ Scheduled Task เก่า

| Task เก่า | การทำ |
|-----------|--------|
| **Run a command** → `line_notify_scheduled.php --job=tick` | **ลบ** |
| **Run a PHP script** → `plesk_line_tick.php` ทุก `* * * * *` | **ปิด Active** หรือลบ |
| **Run a PHP script** → `plesk_line_worker.php` ทุก `*/2 * * * *` | ปิดได้ (ไม่บังคับ) |

---

## 3. Plesk — สร้าง task ใหม่ (รายการละ 1 task)

**ทุก task:** Task type = **Run a PHP script** · PHP = **8.2** · Notify = Do not notify

| ประเภท | Script path (จาก httpdocs) | Run |
|--------|----------------------------|-----|
| สรุปผลิตรายวัน | `production/finishgoogs_ma_update/cron/plesk_line_job_daily.php` | **Daily** — ตั้งเวลาใน Plesk |
| อัปเดตหลังเลิกงาน | `.../plesk_line_job_daily_update.php` | **Daily** |
| อะไหล่ใกล้หมด | `.../plesk_line_job_low_stock.php` | **Daily** |
| สรุปรายสัปดาห์ | `.../plesk_line_job_weekly.php` | **Cron** ตามวัน+เวลา |
| สรุปรายเดือน | `.../plesk_line_job_monthly.php` | **Cron** `10 20 28-31 * *` (ส่งเฉพาะวันสุดท้ายเดือน) |

ดู path และคำแนะนำได้ที่ **หลังบ้าน → ตั้งค่า LINE** (คอลัมน์ Plesk script)

**ทดสอบ:** กด **Run Now** — ควรได้ JSON เช่น `{"job":"daily","result":{...},"worker":{"sent":1,...}}`

---

## 4. ลบบน server

| ไฟล์ | เหตุผล |
|------|--------|
| `finishgoogs_ma_update/cron/plesk_line_tick.php` | ลบโหมด tick แล้ว — ดู `DELETE-ON-SERVER.txt` ใน patch |
| `finishgoogs_ma_update/cron/setup_line_tasks.bat` | ใช้บน Windows dev เท่านั้น (ไม่บังคับ) |

`plesk_line_worker.php` เก็บไว้ได้ถ้าต้องการ worker สำรอง

---

## 5. หลังอัป FTP

1. Refresh หน้าตั้งค่า LINE — ไม่มีคอลัมน์เวลา/วันแล้ว
2. ตั้งเวลาใน Plesk ต่อ `plesk_line_job_*.php`
3. หลังบ้าน: เปิด/ปิด, วิธีส่ง, token → บันทึก
4. รัน mark-deployed บน dev หลังอัปเสร็จ (บอก OK ในแชท)
