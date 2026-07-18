# Bug / Security Report — ระบบ production (finishgoogs_ma_update + parts)

จัดทำโดย QA จากการอ่านโค้ดจริง (static review) — รายการที่ทำเครื่องหมาย **[ยืนยันจากโค้ด]** คือดูโค้ดแล้วเห็นพฤติกรรมชัดเจน, รายการที่ทำเครื่องหมาย **[ต้อง verify]** คือจุดที่ควรทดสอบจริงเพื่อยืนยันก่อนถือว่าเป็นบั๊กที่ปิดงานได้

---

## Critical

### BUG-01 — Credential หลุดในซอร์สโค้ด (DB password, Cloudflare API token, FTP)
- **พบที่**: `finishgoogs_ma_update/config.php` (DB password ของ `biton_production` และ `biton_stockparts` เป็น plaintext), `parts/config/database.php` (DB password ของ `biton_tech_parts`), `parts/add.php` (Cloudflare API Token + Account ID ฝังในโค้ด), `.vscode/sftp.json` ใน `parts/` และ `parts/test/` (FTP credential ไปเซิร์ฟเวอร์จริง)
- **ผลกระทบ**: ถ้าซอร์สโค้ดหลุด (เช่น commit ขึ้น public repo, backup ไฟล์ถูกเข้าถึงได้, หรือมี LFI/RFI จุดใดจุดหนึ่ง) ผู้โจมตีได้ full access ไปยังฐานข้อมูลจริงทั้ง 3 ตัว + บัญชี Cloudflare + เซิร์ฟเวอร์ผ่าน FTP
- **Steps to reproduce**: เปิดไฟล์ที่ระบุด้วย text editor
- **Expected**: credential ควรอยู่ใน environment variable หรือไฟล์ config ที่ไม่ commit เข้า version control (`.gitignore`)
- **Actual**: credential เป็น plaintext ฝังตรงในไฟล์ที่อยู่ใน webroot
- **Severity**: **Critical** (data breach เต็มรูปแบบถ้าไฟล์หลุด)

---

## High

### BUG-02 — Authorization ไม่มีการเช็คสิทธิ์จริง (ทุก user เข้าถึงทุกฟีเจอร์ได้)
- **พบที่**: `finishgoogs_ma_update/config.php:279-281`
  ```php
  function can($perm) {
      return ss_session_has_profile();
  }
  ```
- **ผลกระทบ**: ผู้ใช้ทั่วไปที่ login ผ่าน SSO เข้าถึงหน้า `settings.php`, `users.php`, `appearance.php` (เปลี่ยนธีม/โลโก้/เมนูทั้งระบบ) ได้เหมือนกับ admin ทุกประการ — ไม่มีการแบ่งสิทธิ์ตาม role เลยทั้งที่ระบบมีฟังก์ชัน `can($perm)` ที่ดูเหมือนออกแบบมาให้เช็ค permission
- **Steps to reproduce**: [ต้อง verify] Login ด้วยบัญชีทั่วไป (ไม่ใช่ admin) → เปิด URL ตรงของ `appearance.php` หรือ `users.php`
- **Expected**: ผู้ใช้ที่ไม่มีสิทธิ์ admin ควรถูก redirect/reject
- **Actual (ตามโค้ด)**: เข้าได้ปกติทุกหน้า
- **Severity**: **High** — ผลกระทบต่อ data integrity และความน่าเชื่อถือของระบบทั้งองค์กร (ใครก็แก้ธีม/ลบ user/เปลี่ยน setting ได้)

### BUG-03 — Webhook API รับสต็อกออกโดยไม่มี Authentication
- **พบที่**: `parts/api/webhook-stockout.php` (ทั้งไฟล์ไม่มีการเช็ค API key/secret/token ใดๆ เลย)
- **ผลกระทบ**: ใครก็ตามที่รู้ URL endpoint สามารถยิง POST JSON `{product_code, quantity, purpose}` เพื่อ**ตัดสต็อกสินค้าจริง**ได้โดยไม่ต้อง authenticate — เสี่ยงข้อมูลสต็อกผิดเพี้ยน/ถูกโจมตีแบบ DoS เชิงข้อมูล (ยิงตัดสต็อกซ้ำๆ จนติดลบหรือหมด)
- **Steps to reproduce**: [ต้อง verify] `curl -X POST <host>/parts/api/webhook-stockout.php -H "Content-Type: application/json" -d '{"product_code":"P001","quantity":1,"purpose":"test"}'`
- **Expected**: ควรต้องมี API key/secret header หรือ IP whitelist ก่อนอนุญาตให้ตัดสต็อก
- **Actual**: ตัดสต็อกสำเร็จโดยไม่มีการยืนยันตัวตนใดๆ
- **Severity**: **High**

### BUG-04 — `parts/delete.php` รับรหัสผ่านผ่าน GET query string
- **พบที่**: `parts/delete.php:3,6-12`
  ```php
  $DELETE_PASSWORD = 'a9981';
  $password = isset($_GET['pass']) ? $_GET['pass'] : '';
  if ($password !== $DELETE_PASSWORD) { ... }
  ```
- **ผลกระทบ**: รหัสผ่านส่งผ่าน URL query string ถูกบันทึกใน browser history, server access log, proxy log, Referer header ได้ง่าย และรหัสเป็นสตริงสั้นที่ brute-force ได้ไม่ยาก ไม่มี rate-limit/lockout ปัจจุบันโค้ดส่วนลบจริง ("โค้ดลบ DNS ของคุณตรงนี้") ยังเป็นแค่ comment placeholder ที่ยังไม่ implement แต่ pattern ความปลอดภัยนี้เสี่ยงสูงหากมีการเติมโค้ดจริงในอนาคตโดยไม่แก้วิธี auth ก่อน
- **Steps to reproduce**: เข้า `parts/delete.php?domain=test.com&pass=a9981` ตรงๆ
- **Expected**: ควรใช้ POST + CSRF token + session-based auth แทนการเทียบรหัสผ่านผ่าน GET
- **Actual**: ยอมรับ authentication ผ่าน GET parameter
- **Severity**: **High** (แม้ยังไม่มี business logic จริงให้ถูกเรียกใช้ แต่ตัว auth pattern ต้องแก้ก่อนเติมโค้ด)

### BUG-05 — `parts/add.php` สร้าง Cloudflare Tunnel โดยไม่มี CSRF/Auth check และฝัง API Token
- **พบที่**: `parts/add.php`
- **ผลกระทบ**: ไฟล์นี้ไม่เกี่ยวข้องกับ business ของระบบสต็อกอะไหล่เลย (เป็นเครื่องมือจัดการ Cloudflare Tunnel/DNS) แต่อยู่ใน production webroot พร้อม API token จริงฝังอยู่ และไม่มี CSRF check — เสี่ยงถูกเรียกใช้สร้าง/ลบ tunnel โดยไม่ได้รับอนุญาต
- **Steps to reproduce**: [ต้อง verify] ตรวจสอบว่าไฟล์นี้ (และ `del-dns.php`, `in2.php`, `tunnel-detail.php`) ยังจำเป็นต้องอยู่ใน production หรือเป็นโค้ดทดลอง/dev tool ที่หลงเหลือ
- **Expected**: ไฟล์ที่ไม่เกี่ยวกับ business logic ไม่ควรอยู่ใน production webroot โดยเฉพาะที่มี credential ฝังอยู่
- **Severity**: **High** — แนะนำให้ทีมยืนยันและลบไฟล์ที่ไม่ใช้งานจริงออกจาก production

---

## Medium

### BUG-06 — หน้าหลังบ้านปลดล็อกด้วย PIN 4 หลักฮาร์ดโค้ด (`9981`)
- **พบที่**: `finishgoogs_ma_update/config.php:246` (`settings_admin_unlocked()`), เงื่อนไขอีกทางคือ `login_name === 'Tom'` (case-insensitive)
- **ผลกระทบ**: PIN สั้น ไม่มี rate-limit/lockout เดายากในทางทฤษฎีแต่ไม่ใช่มาตรฐานความปลอดภัยที่ยอมรับได้สำหรับฟีเจอร์ sync/import ข้อมูลจำนวนมาก (ที่ผูกกับ `settings_admin_unlocked()` ใน `share.php`)
- **Expected**: ใช้ระบบสิทธิ์ตาม role/user จริงแทน PIN แชร์ร่วมกัน
- **Severity**: **Medium**

### BUG-07 — ไฟล์ Cloudflare Tunnel/DNS ปนอยู่ใน `parts/` ที่ไม่เกี่ยวกับระบบสต็อกอะไหล่
- **พบที่**: `parts/add.php`, `parts/delete.php`, `parts/del-dns.php`, `parts/in2.php`, `parts/tunnel-detail.php`, `parts/db.db` (SQLite แยกต่างหาก), และสำเนาซ้ำทั้งชุดใน `parts/test/`
- **ผลกระทบ**: เพิ่มพื้นที่โจมตี (attack surface) โดยไม่จำเป็น, สร้างความสับสนเรื่อง scope ของระบบ, `parts/test/` ก็อปปี้ไฟล์ทั้งชุดรวม credential ซ้ำอีกชุด
- **Severity**: **Medium** — แนะนำให้ยืนยันกับทีมและย้าย/ลบออกจาก production webroot

---

## จุดที่ต้อง Verify เพิ่มเติมด้วยการทดสอบจริง (ยังไม่สรุปเป็นบั๊ก)

### CHECK-01 — บั๊ก autocommit/race condition เดิม (ตามที่ comment ใน config.php อ้างว่าแก้แล้ว)
`config.php` มี comment อธิบายบั๊กเดิมที่ `assets.php` เคยบันทึก "สำเร็จ" แต่ข้อมูลหายเงียบเพราะ autocommit=0 — ต้องทำ regression test จริง (ดู ASSET-04, SYNC-05 ใน test_cases.md) เพื่อยืนยันว่าแก้แล้วจริง ก่อนเซ็นชื่อ sign-off Definition of Done

### CHECK-02 — CSV date-parsing กรณีวันที่กำกวมทั้งไฟล์ (≤12 ทุกแถว)
`share_detect_fmt()`/`share_detect_import_dt_fmt()` ไม่มีทางแยกแยะ mdy/dmy ได้แน่นอนถ้าทุกแถวมีวัน≤12 — default เป็น mdy เป็น**พฤติกรรมที่ตั้งใจ** (ตาม comment "ค่า default ของ AppSheet") ไม่ใช่บั๊ก แต่ควรยืนยันกับผู้ใช้ธุรกิจว่าข้อมูลต้นทางเป็น mdy จริงเสมอ มิฉะนั้นข้อมูลวันที่จะผิดแบบเงียบๆ

### CHECK-03 — SVG upload ใน appearance.php
ยังไม่ได้ตรวจโค้ดส่วน validate ไฟล์ SVG ในรายละเอียด ควรอ่านเพิ่มและทดสอบอัปโหลด SVG ที่มี `<script>` ฝัง เพื่อยืนยันว่ามี sanitize หรือไม่ (ความเสี่ยง Stored XSS ถ้าไม่มี)

---

## สรุป

| Severity | จำนวน |
|---|---|
| Critical | 1 |
| High | 4 |
| Medium | 2 |
| ต้อง verify เพิ่มเติม | 3 |

**คำแนะนำก่อนขึ้น production**: BUG-01 ถึง BUG-05 ควรได้รับการแก้ไขหรืออย่างน้อยมี mitigation (เช่น ย้าย credential ออกจากซอร์ส, เพิ่ม auth ให้ webhook, ทำระบบ role จริง) ก่อนจะถือว่าผ่าน Definition of Done ด้าน security ส่วน CHECK-01/02/03 ต้องทดสอบจริงให้เสร็จก่อน sign-off
