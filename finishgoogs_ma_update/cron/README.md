# งานตั้งเวลา (Cron / Plesk Scheduled Task)

ไฟล์ในโฟลเดอร์ `cron/` ทั้งหมดรันได้จาก **บรรทัดคำสั่งเท่านั้น** เรียกผ่านเว็บจะถูกปฏิเสธ
(`cron/.htaccess` บล็อกไว้ชั้นหนึ่ง และ `cron/_bootstrap.php` เช็คซ้ำอีกชั้น ตอบ 403)

## เวลาส่ง ตั้งที่ Plesk ไม่ใช่ในหลังบ้าน

แบ่งหน้าที่กันชัดเจน จำไว้แค่นี้พอ

| ที่ | ตั้งอะไร |
|----|----------|
| **Plesk Scheduled Task** | **ส่งวันไหน กี่โมง** |
| **หลังบ้าน** (`line_notify_settings.php`) | เปิด/ปิดแต่ละประเภท · วิธีส่ง (ทันที/ตามเวลา/ทั้งสอง) · token · ผู้รับ |

เปิดในหลังบ้านแล้วแต่ไม่ได้ตั้ง task ใน Plesk = ไม่มีอะไรถูกส่ง

## ต้องมีก่อน

1. ตั้งค่า LINE ให้เรียบร้อยที่ `line_notify_settings.php`
2. ไฟล์ `line.secrets.php` วางไว้นอก web root
3. PHP CLI 8.2 (Plesk)

---

# ตารางงานทั้งหมด

สร้าง Scheduled Task แยก 1 ตัวต่อ 1 บรรทัด เฉพาะรายการที่เปิดใช้แบบ **ตามเวลา**

| งาน | Script ใน `cron/` | ตั้งเวลาที่แนะนำ |
|-----|-------------------|------------------|
| สรุปผลิตรายวัน | `plesk_line_job_daily.php` | Daily |
| อัปเดตผลิตหลังเลิกงาน | `plesk_line_job_daily_update.php` | Daily หลัง 17:30 |
| อะไหล่ใกล้หมด | `plesk_line_job_low_stock.php` | Daily |
| สินค้าที่ต้องผลิตเพิ่ม | `plesk_line_job_finishgood_shortage.php` | Daily *(ต้องตั้ง URL + token ก่อน — ดูด้านล่าง)* |
| สรุปผลิตรายสัปดาห์ | `plesk_line_job_weekly.php` | Cron ระบุวัน + เวลา |
| สรุปผลิตรายเดือน | `plesk_line_job_monthly.php` | `10 20 28-31 * *` *(ส่งเฉพาะวันสุดท้ายของเดือน)* |
| **สรุปงานรายคน** | `plesk_line_job_work_summary.php` | `0 9 21 * *` *(ต้องผูกไลน์รายคนก่อน — ดูด้านล่าง)* |
| **Sync สถานะเครื่อง** | `sync_asset_status.php` | Daily เช่น 06:00 *(ไม่เกี่ยวกับ LINE แต่ห้ามลืม)* |
| Worker สำรอง | `plesk_line_worker.php` | `*/2 * * * *` *(ไม่บังคับ)* |

path เต็มที่ต้องกรอกใน Plesk ดูได้ที่ **หลังบ้าน → ตั้งค่า LINE** คอลัมน์ *Plesk script*

**Worker สำรองไม่บังคับ** เพราะทุก job เรียกส่งคิวท้ายงานให้อยู่แล้ว
ตั้งเพิ่มเมื่ออยากให้ข้อความแบบ "ส่งทันที" ออกเร็วขึ้นเท่านั้น

---

# 3 งานที่ต้องตั้งค่าเพิ่มก่อนใช้

## 1. Sync สถานะเครื่อง — `sync_asset_status.php`

ไล่อัปเดต `assets.status` ทุกเครื่อง โดยดูจากระบบเช่า (`biton_leasing`) และการเบิกขาย
(`biton_stockparts`)

> **ถ้าลืมตั้ง task นี้ สถานะจะไม่ขยับเอง**
> หน้า Dashboard แค่นับจากคอลัมน์ `assets.status` ไม่ได้ไปคำนวณสดจากระบบเช่า
> ส่วน sync ที่ทำงานตอนเปิดหน้าเครื่อง แก้ให้เฉพาะเครื่องที่กดเข้าไปดูทีละตัว
> (Dashboard จงใจไม่รัน sync ทั้งหมด เพราะ 18,000+ เครื่องเสี่ยง timeout)
>
> **อาการเวลาลืม:** เพิ่มสถานะใหม่แล้วยอดขึ้น 0 ทั้งที่อัปไฟล์ครบแล้ว

เสร็จแล้วสคริปต์พิมพ์ `{"ok":true,"changed":N,"total":M}` ออกมาให้ดูว่าแก้ไปกี่รายการ
กด **Run Now** ใน Plesk เพื่อรันเองครั้งเดียวก็ได้

### มี 2 ไฟล์ชื่อคล้ายกัน อย่าสลับกัน

| ไฟล์ | ใช้ตอนไหน | พฤติกรรม |
|------|-----------|----------|
| `cron/sync_asset_status.php` | **ตั้งเป็น task** | เขียนจริงเสมอ ไม่มี option |
| `database/tools/sync_asset_status.php` | รันเองตอนตรวจสอบ | **ไม่ใส่ option = แค่ดูเฉย ๆ ไม่เขียน** |

```bash
# ดูก่อนว่าจะเปลี่ยนอะไรบ้าง ยังไม่เขียนลงฐาน
/opt/plesk/php/8.2/bin/php .../database/tools/sync_asset_status.php

# เขียนจริง
/opt/plesk/php/8.2/bin/php .../database/tools/sync_asset_status.php --apply

# ลองทีละเครื่อง
/opt/plesk/php/8.2/bin/php .../database/tools/sync_asset_status.php --apply --id=589
```

## 2. สรุปงานรายคน — `plesk_line_job_work_summary.php`

สรุปว่าแต่ละคนบันทึกอะไรไปบ้างในรอบ **21 เดือนก่อน – 20 เดือนนี้**
(รวมงานจาก production + ระบบซ่อม + ระบบเช่า) แล้วส่ง Flex เข้าไลน์ส่วนตัวของแต่ละคน

**ทำไมตั้งวันที่ 21:** รอบเพิ่งปิดไปเมื่อวันที่ 20 และสคริปต์ยึด "เมื่อวาน" เป็นตัวตั้งรอบ
รันวันที่ 21 จึงได้รอบที่เพิ่งปิด ไม่ใช่รอบใหม่ที่ยังไม่มีข้อมูล

### ต้องทำ 3 อย่างก่อนใช้ครั้งแรก

1. **ใส่ Channel Secret** ที่หน้าตั้งค่า LINE — webhook ใช้ตรวจลายเซ็น ถ้าไม่มีจะปฏิเสธทุก request
2. **ตั้ง Webhook URL** ที่ LINE Developers Console
   `https://<โดเมน>/production/finishgoogs_ma_update/line_webhook.php`
3. **ให้แต่ละคนผูกบัญชี** — ที่หน้า *สรุปงานรายคน* กด **ออกรหัส** ให้คนนั้น
   แล้วให้เจ้าตัวแอดบอตเป็นเพื่อนและทักรหัส 8 ตัวนั้นเข้าไป
   *(รหัสหมดอายุใน 60 นาที ใช้ได้ครั้งเดียว)*

คนที่ยังไม่ผูกไลน์จะถูกข้าม ไม่ทำให้ job ล้ม · เปิด/ปิดรายคนได้ที่สวิตช์ **ส่ง** ในหน้าเดียวกัน

```bash
# ดูว่าจะส่งถึงใครบ้าง ยังไม่ส่งจริง
/opt/plesk/php/8.2/bin/php .../cron/plesk_line_job_work_summary.php --dry-run

# ระบุรอบเอง (ใส่วันที่ไหนก็ได้ที่อยู่ในรอบนั้น)
/opt/plesk/php/8.2/bin/php .../cron/plesk_line_job_work_summary.php --date=2026-07-20 --dry-run
```

**2 เรื่องที่ออกแบบไว้ตั้งใจ**

- ส่งซ้ำรอบเดิมจะโดนกันไว้ ไม่ส่งซ้ำให้คนเดิม — รัน job ซ้ำได้ไม่ต้องกลัว
- ถ้าดึงข้อมูลระบบซ่อมหรือระบบเช่าไม่ได้ จะ **ไม่ส่งเลย** แล้ว exit 1
  เพราะสรุปที่ตัวเลขขาดไปแย่กว่าไม่ส่ง — ส่งไปแล้วตามแก้ไม่ได้

## 3. สินค้าที่ต้องผลิตเพิ่ม — `plesk_line_job_finishgood_shortage.php`

ดึงยอดสินค้าสำเร็จรูปจาก API ภายนอก แล้วแจ้งว่ารุ่นไหนควรผลิตเพิ่ม
ต้องกรอก **URL** และ **token** ของ API ที่หน้าตั้งค่า LINE ก่อน ไม่งั้น job จะไม่มีข้อมูลให้ส่ง

อยากดูหน้าตาการ์ดก่อนส่งจริง เปิดหน้า `finishgood_shortage_preview.php` ได้เลย ไม่ต้องยิงเข้าไลน์

---

# ทดสอบด้วยมือ

```bash
# รัน job ตรง ๆ
/opt/plesk/php/8.2/bin/php .../cron/plesk_line_job_daily.php

# เรียกแยกตามชื่องาน (daily, daily_update, weekly, monthly, low_stock_scan, test)
/opt/plesk/php/8.2/bin/php .../cron/line_notify_scheduled.php --job=test
```

อยากส่งเดี๋ยวนี้โดยไม่รอ cron ใช้ปุ่ม **ส่งทันที** ในหลังบ้านได้เลย
และก่อนส่งจริงควรเปิด **โหมดทดสอบ** ไว้ก่อน — ข้อความทุกฉบับจะวิ่งเข้าบัญชีทดสอบแทนของจริง

---

# เจอ error `unexpected '?'` — เป็นเรื่องรุ่น PHP ไม่ใช่โค้ดพัง

โค้ดชุดนี้ต้องใช้ **PHP 7.1 ขึ้นไป** (มี nullable type hint `?string`) ส่วนเซิร์ฟเวอร์รัน 8.2

Plesk มี php หลายรุ่นอยู่ในเครื่องเดียวกัน ถ้า Scheduled Task ไม่ได้ระบุว่าจะใช้รุ่นไหน
มันจะหยิบ php ของระบบซึ่งมักเก่ากว่า แล้วตายตั้งแต่อ่าน `config.php`

```
PHP Parse error: syntax error, unexpected '?', expecting variable (T_VARIABLE)
  in .../finishgoogs_ma_update/config.php on line 516
```

**วิธีแก้** ตั้ง task เป็น **Run a command** แล้วระบุตัวแปลภาษาให้ชัด

```
/opt/plesk/php/8.2/bin/php /var/www/vhosts/<domain>/httpdocs/production/finishgoogs_ma_update/cron/plesk_line_job_work_summary.php
```

หรือถ้าใช้ **Run a PHP script** ให้เลือกรุ่น PHP ในช่องของ task ให้ตรงกับ task ตัวอื่นที่รันผ่านอยู่แล้ว

---

# เครื่อง dev (Windows)

```powershell
$PhpExe = "D:\AppServ\php7\php.exe"
$CronDir = "D:\AppServ\www\production\finishgoogs_ma_update\cron"

schtasks /Create /TN "Production_LINE_Notify_Worker" /TR "`"$PhpExe`" `"$CronDir\line_notify_worker.php`"" /SC MINUTE /MO 2 /F
```
