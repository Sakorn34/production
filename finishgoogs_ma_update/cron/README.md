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
| สรุปรายเดือน | `plesk_line_job_monthly.php` | Cron `10 20 28-31 * *` (ส่งเฉพาะวันสุดท้ายเดือน) |

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

---

# Sync สถานะเครื่อง (ไม่เกี่ยวกับ LINE)

`cron/sync_asset_status.php` — ไล่อัปเดต `assets.status` ทุกเครื่องจากระบบเช่า
(`biton_leasing`) และการเบิกขาย (`biton_stockparts`)

| ช่อง | ค่า |
|------|-----|
| Script | `production/finishgoogs_ma_update/cron/sync_asset_status.php` |
| Plesk Run | Daily |

**ต้องตั้ง task นี้ ไม่งั้นสถานะจะไม่ขยับเอง** — หน้า Dashboard แค่นับจากคอลัมน์
`assets.status` ไม่ได้คำนวณสดจากระบบเช่า ส่วน sync ที่ทำงานตอนเปิดหน้าเครื่อง
แก้ให้เฉพาะเครื่องที่เปิดดูทีละตัวเท่านั้น (หน้า Dashboard จงใจไม่รัน full sync
เพราะ 18,000+ เครื่องเสี่ยง timeout)

อาการเวลาลืมตั้ง: เพิ่มสถานะใหม่แล้วยอดขึ้น 0 ทั้งที่อัปไฟล์ครบ

รันเองครั้งเดียวหลังเพิ่มสถานะใหม่ได้ที่ปุ่ม **Run Now** ใน Plesk — สคริปต์พิมพ์
`{"ok":true,"changed":N,"total":M}` ออกมาให้ยืนยันว่าแก้ไปกี่รายการ

## ทดสอบ / dry-run

```bash
# ดูว่าจะเปลี่ยนอะไรบ้าง ยังไม่เขียน
/opt/plesk/php/8.2/bin/php .../database/tools/sync_asset_status.php

# เขียนจริง
/opt/plesk/php/8.2/bin/php .../database/tools/sync_asset_status.php --apply

# เครื่องเดียว
/opt/plesk/php/8.2/bin/php .../database/tools/sync_asset_status.php --apply --id=589
```

---

# สรุปงานรายคน ส่งเข้าไลน์ส่วนตัว

`cron/plesk_line_job_work_summary.php` — สรุปว่าแต่ละคนบันทึกอะไรไปบ้างในรอบ
**21 เดือนก่อน – 20 เดือนนี้** (รวมงานจาก production + ระบบซ่อม + ระบบเช่า)
แล้วส่ง Flex เข้าไลน์ส่วนตัวของแต่ละคน

| ช่อง | ค่า |
|------|-----|
| Script | `production/finishgoogs_ma_update/cron/plesk_line_job_work_summary.php` |
| Cron | `0 9 21 * *` (9 โมงเช้า ทุกวันที่ 21) |

รันวันที่ 21 เพราะรอบเพิ่งปิดเมื่อวันที่ 20 — สคริปต์ยึด "เมื่อวาน" เป็นตัวตั้งรอบ
จึงได้รอบที่เพิ่งปิด ไม่ใช่รอบใหม่ที่ยังไม่มีข้อมูล

## ก่อนใช้ครั้งแรก ต้องทำ 3 อย่าง

1. **ตั้ง Channel Secret** ที่หน้า ตั้งค่า LINE — webhook ใช้ตรวจลายเซ็น ถ้าไม่มีจะปฏิเสธทุก request
2. **ตั้ง Webhook URL** ที่ LINE Developers Console:
   `https://<โดเมน>/production/finishgoogs_ma_update/line_webhook.php`
3. **ให้แต่ละคนผูกบัญชี** — หน้า "สรุปงานรายคน" กด *ออกรหัส* ให้คนนั้น แล้วให้เจ้าตัว
   แอดบอตเป็นเพื่อนและทักรหัส 8 ตัวนั้นไป (รหัสหมดอายุใน 60 นาที ใช้ได้ครั้งเดียว)

คนที่ยังไม่ผูก LINE จะถูกข้าม ไม่ทำให้ job ล้ม · เปิด/ปิดรายคนได้ที่สวิตช์ **ส่ง** ในหน้าเดียวกัน

## ทดสอบ

```bash
# ดูว่าจะส่งให้ใครบ้าง ไม่ส่งจริง
php cron/plesk_line_job_work_summary.php --dry-run

# ระบุรอบเอง (ใส่วันที่ในรอบที่ต้องการ)
php cron/plesk_line_job_work_summary.php --date=2026-07-20 --dry-run
```

ส่งซ้ำรอบเดิมจะโดน dedup กันไว้ ไม่ส่งซ้ำให้คนเดิม · ถ้าดึงข้อมูลระบบซ่อม/เช่าไม่ได้
สคริปต์จะ **ไม่ส่งเลย** แล้ว exit 1 เพราะตัวเลขไม่ครบ = สรุปผิด ส่งไปแล้วแก้ไม่ได้
