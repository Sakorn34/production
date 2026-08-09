# Bug Report — ระบบ production (finishgoogs_ma_update + parts)

ตรวจรอบล่าสุด: 2026-08-09 · ตรวจจากโค้ดปัจจุบัน + ทดสอบจริงกับ MySQL `biton_tech_parts` (localhost)
ทุกข้อที่ระบุ **[พิสูจน์แล้ว]** คือรันทดสอบจริงหรืออ่านโค้ดยืนยันแล้ว ไม่ใช่การคาดเดา

> รายงานฉบับก่อนหน้าถูกเขียนทับ เพราะประเด็นส่วนใหญ่ถูกแก้ไปแล้ว (ดูหัวข้อ "ที่แก้ไปแล้ว" ท้ายเอกสาร)

---

## Critical

### BUG-01 — ลบใบเบิกแล้วสต็อกเพิ่มผี (คืนของที่ไม่เคยถูกตัด) **[พิสูจน์แล้ว]**

- **พบที่**: `parts/includes/StockService.php:998-1006` (`deleteStockOut()`)
- **เรียกใช้จาก**: `parts/pages/history.php:199` — ปุ่มลบบนหน้าประวัติ ผู้ใช้ทั่วไปกดได้
- **สาเหตุ**: ตาราง `stock_out` มีคอลัมน์ `stock_deducted` บอกว่าใบนั้นตัดสต็อกจริงหรือเป็นใบ backfill ที่ sync ย้อนหลังมา (ไม่ได้ตัดสต็อก) แต่ `deleteStockOut()` คืนสต็อกกลับ **ทุกใบโดยไม่เช็ค flag นี้**:
  ```php
  foreach ($detail['items'] as $item) {
      $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
          ->execute([(int) $item['quantity'], (int) $item['product_id']]);
  }
  ```
  ทั้งที่ `getStockOutDetail()` ใช้ `SELECT so.*` ซึ่งดึง `stock_deducted` มาให้อยู่แล้ว และระบบ**มีฟังก์ชันตรวจสำเร็จรูปอยู่แล้ว** คือ `production_stock_out_was_deducted_row()` ที่ `parts/includes/production_sync.php:91` — โค้ดส่วนอื่นก็ใช้ check นี้ (เช่น `production_asset_withdraw_sync.php` เขียนคอมเมนต์ไว้ชัดว่า "คืนสต็อกเมื่อ stock_deducted=1")
- **ขนาดผลกระทบจริงในฐานข้อมูลปัจจุบัน**: **145 จาก 179 ใบ (81%) มี `stock_deducted = 0`**
  ```
  stock_deducted=1 ->  34 ใบ
  stock_deducted=0 -> 145 ใบ
  ```
- **Steps to reproduce**: เปิดหน้าประวัติ (`parts/pages/history.php`) → เลือกใบเบิกที่ `stock_deducted=0` (ซึ่งเป็นใบส่วนใหญ่) → กดลบ
- **Expected**: ใบที่ไม่เคยตัดสต็อก เมื่อลบต้องไม่คืนสต็อก (ยอดคงเหลือคงเดิม)
- **Actual**: ยอด `products.quantity` เพิ่มขึ้นตามจำนวนในใบนั้น ทั้งที่ไม่เคยถูกหักออกไปตั้งแต่แรก
- **ผลกระทบผู้ใช้**: ยอดอะไหล่คงเหลือในระบบสูงกว่าของจริง ช่างเบิกของแล้วไม่มีของ, รายงานมูลค่าสต็อกสิ้นปี (`getStockValuationRows()`) ผิด, การแจ้งเตือนของใกล้หมด (`min_stock`) ไม่ทำงานเพราะยอดดูเหมือนยังเยอะ — และความเสียหายสะสมขึ้นทุกครั้งที่ลบ โดยไม่มีอะไรเตือน
- **Severity**: **Critical** (ข้อมูลสต็อกเพี้ยนถาวร กระทบใบส่วนใหญ่ในระบบ)

---

## High

### BUG-02 — แก้ไขจำนวนใบเบิกทำให้สต็อกติดลบได้ **[พิสูจน์แล้ว]**

- **พบที่**: `parts/includes/StockService.php:906-921` (`updateStockOutSingle()`)
- **เรียกใช้จาก**: `parts/pages/history.php:202` — ปุ่มแก้ไขบนหน้าประวัติ
- **สาเหตุ**: เมธอดนี้ตรวจของคงเหลือ **นอก transaction** และใช้ `getProduct()` ที่เป็น `SELECT` ธรรมดา ไม่มี `FOR UPDATE` แล้วค่อยหักสต็อกด้วย SQL ที่**ไม่มี guard**:
  ```php
  $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')
      ->execute([$diff, $productId]);
  ```
  ต่างจากเมธอดพี่น้องทุกตัวในไฟล์เดียวกัน (`stockOutItem()` บรรทัด 490-498, `stockOutBySet()` บรรทัด 432-437, `deleteStockIn()` บรรทัด 861-869) ที่ล็อกแถวด้วย `FOR UPDATE` และใช้ `... WHERE id = ? AND quantity >= ?` แล้วเช็ค `rowCount() === 0` เพื่อ throw error
- **ผลทดสอบจริง** (รันใน transaction แล้ว rollback ไม่แตะข้อมูลจริง):
  ```
  ก่อน: P00001 qty=92
  หลัง : qty=-50 (rowCount=1)          <- SQL แบบปัจจุบัน ยอมให้ติดลบ
  แบบมี guard: qty=92 (rowCount=0)      <- SQL แบบที่ควรเป็น ระบบจะ throw error
  ```
- **Steps to reproduce**: ผู้ใช้ 2 คนเปิดหน้าแก้ไขใบเบิกเดียวกัน/อะไหล่ตัวเดียวกันพร้อมกัน แล้วเพิ่มจำนวนเบิกทั้งคู่ — การตรวจของทั้งสองผ่าน (เพราะอ่านค่าก่อนใครหัก) แล้วต่างคนต่างหัก
- **Expected**: หักไม่สำเร็จแล้ว throw error เหมือนเมธอดอื่น
- **Actual**: หักผ่าน สต็อกติดลบ
- **หมายเหตุ**: กรณีใช้งานคนเดียวไม่พร้อมกัน การตรวจบรรทัด 906-911 ยังกันได้อยู่ — บั๊กนี้จะโผล่ตอนใช้งานพร้อมกัน (race condition) หรือถ้ามีอีก path มาแก้ยอดคั่นกลาง
- **เพิ่มเติม**: เมธอดนี้ไม่เช็ค `stock_deducted` เช่นกัน (ปัญหาชุดเดียวกับ BUG-01) — แก้จำนวนใบที่ `stock_deducted=0` ก็ไปขยับ `products.quantity` ทั้งที่ใบนั้นไม่เคยตัดสต็อก
- **Severity**: **High**

---

### BUG-03 — ไฟล์ cron เปิดให้เรียกจากเว็บได้โดยไม่ต้อง login **[พิสูจน์แล้ว]**

- **พบที่**: `finishgoogs_ma_update/cron/` — ไฟล์ทั้ง 10 ตัว
- **สาเหตุ**: โฟลเดอร์อยู่ใน webroot แต่ **ไม่มี `.htaccess`** · ไฟล์ทั้งหมด **ไม่มีตัวตรวจ CLI** (`php_sapi_name()`) และ **ไม่มีการเช็ค login** · ตัวกันเพียงอย่างเดียวคือ `if (!defined('LINE_PLESK_JOB'))` ใน `plesk_line_run_job.php:10` ซึ่ง **ผ่านอยู่แล้ว** เมื่อเรียกไฟล์ wrapper ตรงๆ เพราะ wrapper นิยาม constant นั้นให้เอง:
  ```php
  // cron/plesk_line_job_low_stock.php
  define('LINE_PLESK_JOB', 'low_stock_scan');   // <- นิยามให้ผ่าน guard เอง
  require __DIR__ . '/plesk_line_run_job.php'; // -> line_notify_run_job() + ส่ง outbox จริง
  ```
- **Steps to reproduce**: เปิด `https://<host>/production/finishgoogs_ma_update/cron/plesk_line_job_low_stock.php` จากเครื่องใดก็ได้ ไม่ต้อง login
- **Expected**: ต้องเรียกได้เฉพาะจาก Plesk Scheduled Task (CLI) เท่านั้น
- **Actual**: รันงานแจ้งเตือนและส่ง LINE ออกไปหาผู้ใช้จริงทันที แล้วคืน JSON รายละเอียดผลการทำงานกลับมาให้ผู้เรียก
- **ผลกระทบผู้ใช้**: ผู้ไม่หวังดีกดซ้ำๆ เพื่อสแปม LINE ให้พนักงาน · เปลืองโควตา LINE API · JSON ที่คืนกลับเผยข้อมูลภายในของงาน
- **แนวทางแก้**: เพิ่ม `.htaccess` ปิดทั้งโฟลเดอร์ `cron/` และ/หรือใส่ `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }` ใน `_bootstrap.php`
- **Severity**: **High**

---

## Medium

### BUG-04 — `appearance.php` ใครที่ login ก็แก้หน้าตาระบบทั้งบริษัทได้ **[พิสูจน์แล้ว]**

หน้าหลังบ้านอื่นบังคับ PIN ด้วย `require_settings_access()` ครบทุกหน้า แต่ `appearance.php:5` เรียกแค่ `require_login()`

| หน้า | ตัวกัน | สถานะ |
|---|---|---|
| `activity_logs.php:9` | `require_settings_access()` | ถูกต้อง |
| `settings_bulk.php:19` | `require_settings_access()` | ถูกต้อง |
| `server_config.php:11` | `require_settings_access()` | ถูกต้อง |
| `line_notify_settings.php:13` | `require_settings_access()` | ถูกต้อง |
| `appearance.php:5` | `require_login()` เท่านั้น | **ไม่สอดคล้อง** |

ผู้ใช้ทั่วไปที่ login ผ่าน SSO เข้าไปเปลี่ยนชื่อระบบ สีธีม โลโก้ และลำดับเมนูของทุกคนได้
(การตรวจค่าสีทำไว้ดีแล้ว — `preg_match('/^#[0-9a-fA-F]{6}$/')` ค่าที่ไม่ใช่ hex ถูกทิ้ง)

- **Severity**: **Medium**

### BUG-05 — อัปโหลดโลโก้เป็นไฟล์ SVG ที่ฝัง script ได้ (Stored XSS) **[พิสูจน์แล้ว]**

- **พบที่**: `appearance.php:47` อนุญาตนามสกุล `svg` และ `config.php:714` (`save_upload()`) ตรวจไฟล์ SVG เพียงว่า 4096 ไบต์แรกมีข้อความ `<svg` เท่านั้น:
  ```php
  if ($ext === 'svg') {
      if (stripos(file_get_contents(...,0,4096), '<svg') === false) return null;  // ตรวจแค่นี้
  }
  ```
- `uploads/.htaccess` ปิดการรัน PHP ไว้แล้ว (ทำได้ดี) แต่ **ไม่ได้กัน SVG** — เปิด URL ของไฟล์ตรงๆ เบราว์เซอร์จะรัน JavaScript ในนั้นภายใต้โดเมนของระบบ ขโมย session ของผู้ใช้คนอื่นได้
- ความเสี่ยงถูกขยายโดย BUG-04 เพราะผู้ใช้ทั่วไปก็เข้าหน้านี้ได้
- **แนวทางแก้**: ตัด `svg` ออกจากรายการที่อนุญาต (ใช้ PNG แทน) หรือ sanitize เนื้อไฟล์ก่อนบันทึก
- **Severity**: **Medium**

### BUG-06 — `parts/database/schema.sql` ล้าสมัย สร้าง DB ใหม่แล้วแอปพังทันที **[พิสูจน์แล้ว]**

เทียบ schema ในไฟล์กับฐานข้อมูลจริง พบว่าไฟล์ขาดคอลัมน์ที่โค้ดใช้งานอยู่:

| ตาราง | คอลัมน์ที่มีจริงแต่ไม่มีใน schema.sql |
|---|---|
| `products` | `price`, `is_active`, `supplier` |
| `stock_in` | `received_by`, `part_movement_id` |
| `stock_out` | `asset_code`, `part_movement_id`, `stock_deducted` |
| `stock_out_items` | `part_movement_id` |

- **ผลกระทบ**: ใครก็ตามที่ setup ระบบใหม่จาก `schema.sql` จะเจอ error ทันทีตั้งแต่หน้าแรก เพราะ `getDashboardStats()` (`StockService.php:18`) query `WHERE quantity <= min_stock AND is_active = 1` และหน้าอื่นใช้ `price`/`asset_code`/`stock_deducted` — ทำให้ไฟล์นี้ใช้เป็น reference ของ schema ไม่ได้เลย
- **Severity**: **Medium** (ไม่กระทบระบบที่รันอยู่ แต่ทำให้ setup ใหม่/กู้ระบบไม่ได้)

### BUG-07 — PIN หลังบ้านปลดล็อกแล้วไม่มีวันหมดอายุ

- **พบที่**: `finishgoogs_ma_update/includes/settings_gate.php:33` เก็บ `$_SESSION['settings_unlocked'] = time()` แต่ `config.php:616` ตรวจแค่ `!empty($_SESSION['settings_unlocked'])` — **เก็บเวลาไว้แต่ไม่เคยเอามาใช้**
- **ผลกระทบ**: ปลดล็อกครั้งเดียวแล้วเข้าหลังบ้านได้ตลอดอายุ session ถ้าเดินจากเครื่องโดยไม่ปิดเบราว์เซอร์ คนถัดไปเข้าหน้า settings/share_admin/activity_logs ได้ทันทีโดยไม่ต้องใส่ PIN
- **แนะนำ**: เทียบ `time() - $_SESSION['settings_unlocked'] < 1800` (ค่าที่เก็บไว้แล้วพร้อมใช้อยู่)
- **Severity**: **Medium**

### BUG-08 — `parts/sftp.json` เก็บรหัส FTP จริงไว้ใน webroot

- **พบที่**: `parts/sftp.json` — เก็บ host / username / password ของ FTP เซิร์ฟเวอร์จริงเป็น plaintext และตั้ง `"secure": false` (FTP ธรรมดา ไม่เข้ารหัส) — ค่าจริงไม่ระบุในเอกสารนี้ ดูได้จากไฟล์โดยตรง
- **สถานะการป้องกัน**: `parts/.htaccess` บล็อก `.json` ไว้แล้ว และ `.gitignore` กันไม่ให้ commit — **ความเสี่ยงถูกลดแล้วระดับหนึ่ง**
- **ที่ยังเหลือ**: การป้องกันพึ่ง `.htaccess` อย่างเดียว ถ้าวันหนึ่งย้ายไป nginx หรือ Apache ตั้ง `AllowOverride None` ไฟล์จะเปิดโล่งทันที และตัวรหัสก็ยังเป็นรหัสที่ใช้งานได้จริงอยู่
- **แนะนำ**: ย้ายออกนอก webroot ไปรวมกับ secrets อื่นที่ `D:\AppServ\secrets\production\` (ซึ่งระบบทำแล้วกับ DB credentials) และเปลี่ยนรหัส FTP
- **Severity**: **Medium**

---

## Low

### BUG-09 — ข้อความบนหน้า PIN บอกข้อมูลที่ไม่ตรงกับความจริงแล้ว

`finishgoogs_ma_update/includes/settings_gate.php:102` ยังแสดงข้อความ "ผู้ใช้ Tom เข้าได้โดยไม่ต้องใส่รหัส" แต่โค้ดจริง (`config.php:613`) จำกัดทางลัดนี้ไว้เฉพาะ `is_localhost_request()` แล้ว — บน production ข้อความนี้ไม่จริง ทำให้ผู้ใช้สับสน และเป็นการบอกใบ้ชื่อบัญชีที่มีสิทธิ์พิเศษให้คนอื่นรู้โดยไม่จำเป็น (คอมเมนต์หัวไฟล์บรรทัด 5 และ 13 ก็ยังเขียนแบบเดิม)

### BUG-10 — dead code

`StockService::getRecentMovements()` (บรรทัด 203) ไม่มีใครเรียกใช้ทั้งโปรเจค (grep แล้วเจอเฉพาะบรรทัดนิยาม) ·
`stockOutByWebhook()` (บรรทัด 715) มาร์ค `@deprecated` แล้วและไม่มีทางเรียกถึงเพราะ endpoint ปิดไปแล้ว — ควรลบทิ้งเพื่อลดภาระการดูแล

---

## ที่แก้ไปแล้วตั้งแต่รายงานรอบก่อน (ยืนยันแล้ว)

- **Webhook ไม่มี auth** → `parts/api/webhook-stockout.php` คืน HTTP 410 ปิดใช้งานแล้ว (เปลี่ยนไปเชื่อม DB ตรงแทน) · `stockOutByWebhook()` ยังอยู่ใน StockService แต่มาร์ค `@deprecated` และไม่มีทางเรียกถึงแล้ว
- **Tom backdoor** → ตอนนี้ถูกจำกัดด้วย `is_localhost_request()` ทั้ง `ss_dev_localhost_bootstrap()` และ `settings_admin_unlocked()` ใช้บน production ไม่ได้แล้ว
- **DB password / Cloudflare token ใน source** → ย้ายออกไป `D:\AppServ\secrets\production\` แล้ว (`db_secrets()`, `app_parts_secrets_path()`)
- **ไฟล์ Cloudflare Tunnel (`add.php`, `delete.php`, `del-dns.php`) และโฟลเดอร์ `parts/test/`** → ถูกลบออกจาก production แล้ว

---

## ส่วนที่ตรวจแล้วไม่พบปัญหา

- **CSRF ครบถ้วน 100%** — สแกนทุกไฟล์ที่รับ POST ในระบบหลัก ไม่พบไฟล์ไหนที่ขาด `csrf_check()`
- **ไม่พบช่องโหว่ SQL injection** — ทุก query ใช้ prepared statement · `ORDER BY` ที่รับค่าจากผู้ใช้ใช้ whitelist (`$sortSql[$sort]`) · `LIMIT` cast เป็น `(int)` ก่อนต่อสตริงทุกจุด
- **ทุกหน้าบังคับ login** — ไฟล์ที่ไม่เรียก `require_login()` ตรงๆ ล้วนเรียกผ่าน `require_can()` (ซึ่งเรียก `require_login()` ต่อ — `config.php:658`) หรือเป็นแค่หน้า redirect
- **`includes/part_stock_bridge.php` เขียนถูกต้องทั้งไฟล์** — ใช้ `AND quantity >= ?` + เช็ค `rowCount()` + ตั้ง `stock_deducted = 1` ตอนตัดสต็อกจริง (เป็นตัวอย่างที่ควรยึดเป็นแบบ)
- **`uploads/.htaccess` ปิดการรัน PHP** ในโฟลเดอร์อัปโหลดไว้เรียบร้อย
- ประเด็นที่เคยรายงานรอบก่อนถูกแก้แล้วทั้งหมด (ดูหัวข้อถัดไป)

## ขอบเขตการตรวจรอบนี้ (ตามตรง)

**ตรวจเชิงลึกครบ**: ฝั่ง `parts/` ทั้งหมด (StockService, production_sync, history, config, schema) และฝั่ง `finishgoogs_ma_update` เฉพาะระบบ auth / CSRF / SQL / upload / cron ซึ่งสแกนครบทุกไฟล์แล้ว

**ยังไม่ได้ตรวจเชิงลึก**: ตรรกะทางธุรกิจของ `finishgoogs_ma_update` — `ma.php` (1,487 บรรทัด: การคำนวณรอบ MA และ alert SD/RTC), `asset_new.php` (1,131 บรรทัด: การออกรหัสเครื่องและตัด BOM), `includes/asset_production_edit.php` (957 บรรทัด) และระบบ LINE notification · ส่วนเหล่านี้ต้องทดสอบกับข้อมูลจริงบนเซิร์ฟเวอร์ (ข้อมูลบนเครื่อง dev ไม่ครบ) จึงจะสรุปได้

| Severity | จำนวน |
|---|---|
| Critical | 1 |
| High | 2 |
| Medium | 5 |
| Low | 2 |
