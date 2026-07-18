# Test Case — ระบบ production (finishgoogs_ma_update + parts)

จัดทำโดย QA · อ้างอิงจากการอ่านโค้ดจริง (mysqli/PDO raw PHP, ไม่มี automated test framework — ทดสอบแบบ manual black-box ทั้งหมด)

สัญลักษณ์ Priority: **P1** = ต้องผ่านก่อนขึ้น production, **P2** = สำคัญแต่ไม่ block, **P3** = nice-to-have/cosmetic

---

## 1. ทะเบียนเครื่อง/ผลิต (`asset_new.php`, `assets.php`, `asset.php`)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| ASSET-01 | Login แล้ว, มีสินค้า (product) อย่างน้อย 1 รายการ | 1. เปิด `asset_new.php` 2. กรอกข้อมูลเครื่องใหม่ครบถ้วน (product, factory_serial, asset_code) 3. บันทึก | บันทึกสำเร็จ, เครื่องปรากฏใน `assets.php`, ค่าที่บันทึกตรงกับที่กรอกเมื่อดึงจาก DB โดยตรง (ไม่ใช่แค่ flash message) | P1 |
| ASSET-02 | มีเครื่อง asset_code ซ้ำอยู่แล้ว | บันทึกเครื่องใหม่ด้วย asset_code เดิม | ระบบแจ้ง error ชัดเจน ไม่สร้าง record ซ้ำ | P1 |
| ASSET-03 | — | เว้นฟิลด์บังคับ (เช่น factory_serial) ว่าง แล้วบันทึก | ระบบ reject พร้อมข้อความแจ้งเตือน ไม่ insert record ที่ข้อมูลไม่ครบ | P1 |
| ASSET-04 | เปิด 2 browser tab/session พร้อมกัน | บันทึกเครื่องใหม่พร้อมกันเกือบพร้อมเพรียงจาก 2 session (regression บั๊ก autocommit เดิมที่ config.php มี comment อธิบาย) | ทั้งสอง record ถูกบันทึกครบ ไม่มีข้อมูลหายเงียบ, ไม่มี id ชนกัน | **P1 (regression)** |
| ASSET-05 | มีเครื่องในระบบ | เปิด `scan.php` แล้วสแกน/กรอกรหัส asset_code ที่มีอยู่จริง | เปิดหน้ารายละเอียดเครื่องที่ถูกต้อง (`asset.php?code=`) | P2 |
| ASSET-06 | — | กรอก asset_code ที่ไม่มีในระบบผ่าน `asset.php?code=` | แสดง "ไม่พบข้อมูล" ไม่ error 500 / ไม่รั่ว SQL error | P1 |
| ASSET-07 | — | แก้ไขสถานะเครื่องระหว่าง new/rental/spare | สถานะเปลี่ยนถูกต้อง, ประวัติ (production_records) สอดคล้อง | P2 |

## 2. Update FW/HW (`update_new.php`, `updates.php`, `update_edit.php`, `updates_import.php`)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| UPD-01 | มีเครื่องอยู่แล้ว | เพิ่ม update log FW/HW ใหม่ผ่าน `update_new.php` | บันทึกสำเร็จ, ปรากฏใน `updates.php` | P1 |
| UPD-02 | มี update log อยู่แล้ว | แก้ไขผ่าน `update_edit.php` | ค่าที่แก้ไขถูกบันทึกจริง | P1 |
| UPD-03 | เตรียมไฟล์ CSV ที่มีวันที่รูปแบบ ISO (`2026-03-16 14:00:00`) | Import ผ่าน `updates_import.php` | แปลงวันที่ถูกต้อง 100% | P1 |
| UPD-04 | เตรียมไฟล์ CSV ที่มีวันที่แบบ slash ที่ **วันที่ >12 ในตำแหน่งแรกทุกแถว** เช่น `15/03/2024` | Import | ระบบ auto-detect เป็น `dmy` (วัน/เดือน) ถูกต้อง ตาม `share_detect_import_dt_fmt`/`share_detect_fmt` | P1 |
| UPD-05 | เตรียมไฟล์ CSV ที่มีวันที่แบบ slash ที่ **ทุกแถวมีวัน ≤12 (กำกวม)** เช่น `01/02/2026`, `03/04/2026` | Import | ระบบ default เป็น `mdy` (เดือน/วัน) — **ต้องตรวจสอบกับผู้ใช้จริงว่าไฟล์ต้นทาง (AppSheet) เป็น mdy จริงหรือไม่ เพราะไม่มีทางแยกแยะได้ 100% จากข้อมูลกำกวม** — เป็น known-limitation ให้บันทึกไว้ ไม่ใช่บั๊ก แต่ต้อง regression test ว่า default ยังคงเป็น mdy เหมือนเดิม | **P1 (edge case ยืนยันพฤติกรรม)** |
| UPD-06 | เตรียม CSV ที่มีเวลาแบบ `9:37:00 AM` และ `16:25 น.` | Import | แปลง AM/PM และ "น." (ภาษาไทย) เป็น 24-ชม. ถูกต้อง | P1 |
| UPD-07 | เตรียม CSV ที่มีแถววันที่ผิดรูปแบบ (เช่น `32/13/2026`) | Import | แถวนั้นถูกข้าม/ไม่ crash ทั้งไฟล์ (ฟังก์ชัน parse คืน null เมื่อ mo/d เกินช่วง) | P1 |
| UPD-08 | เตรียม CSV ที่ไม่มีคอลัมน์ `serial_number` | Import | แจ้ง error "ไฟล์ไม่ถูกต้อง" ไม่ import อะไรเลย | P1 |
| UPD-09 | เตรียม CSV มี BOM (`\xEF\xBB\xBF`) นำหน้า header | Import | ตัด BOM ถูกต้อง อ่าน header แถวแรกได้ปกติ | P2 |
| UPD-10 | อัปโหลดไฟล์ที่ไม่ใช่ .csv (เช่น .xlsx เปลี่ยนนามสกุลเป็น .csv) | Import | ระบบตรวจแค่ extension — ควรทดสอบว่าไฟล์ที่เนื้อหาไม่ใช่ CSV จริงจะพังหรือ handle graceful | P2 |

## 3. MA — บำรุงรักษาตามกำหนด (`ma.php`)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| MA-01 | เครื่องใกล้ครบกำหนดเปลี่ยน SD Card/Battery RTC | เปิดหน้า MA/dashboard | มี alert แจ้งเตือนถูกต้องตามอายุใช้งานจริง | P1 |
| MA-02 | — | บันทึก MA ใหม่ | ค่าบันทึกถูกต้อง, อายุใช้งาน/รอบถัดไปคำนวณใหม่ถูกต้อง | P1 |
| MA-03 | เครื่องยังไม่ถึงกำหนด | เปิดหน้า MA | ไม่มี alert หลอก (false positive) | P2 |

## 4. ซ่อม (`repairs.php`, `customers.php`)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| REP-01 | มีเครื่อง + ลูกค้า | สร้างรายการซ่อมใหม่ผูกกับลูกค้า | บันทึกสำเร็จ, เชื่อมโยงถูกต้อง | P1 |
| REP-02 | — | สร้าง/แก้ไขข้อมูลลูกค้า (`customers.php`) | บันทึกถูกต้อง, ไม่สร้างซ้ำโดยไม่ตั้งใจ | P2 |
| REP-03 | มีรายการซ่อมค้างหลายสถานะ | กรอง/ค้นหารายการซ่อมตามสถานะ | ผลลัพธ์ตรงกับ filter | P2 |

## 5. Sync stock (`share.php` / `share_admin.php` ↔ `biton_stockparts.stock`)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| SYNC-01 | login แล้ว, ไม่ใช่ผ่าน settings_admin_unlocked | เข้า `share.php` | ดูรายการได้ปกติ (read-only actions ไม่ต้องปลดล็อกหลังบ้าน) | P2 |
| SYNC-02 | ยังไม่ปลดล็อกหลังบ้าน | ยิง POST `act=sync` / `act=sync_all` / `act=refresh_meta` / `act=import` / `act=import_basic` ตรงๆ (ไม่ผ่าน settings_admin_unlocked) | ระบบ reject พร้อมข้อความ "ต้องเข้าหน้าหลังบ้านก่อน" ไม่ทำ sync (ตรวจสอบใน `share.php:54-59`) | **P1 (authorization)** |
| SYNC-03 | ปลดล็อกหลังบ้านแล้ว (PIN 9981) | กด "ดึงจากระบบ" (`act=sync`) | เพิ่มเฉพาะเครื่องที่ยังไม่มีใน `stock` (`INSERT IGNORE`), ไม่ซ้ำ | P1 |
| SYNC-04 | มี serial_number ซ้ำ | เพิ่มรายการใน stock ด้วย serial ที่มีอยู่แล้ว | ระบบแจ้ง "มีอยู่แล้ว" ไม่ insert ซ้ำ | P1 |
| SYNC-05 | เปิด 2 session พร้อมกัน กด sync พร้อมกัน | ทดสอบ `GET_LOCK`/race condition | ไม่มี id ชนกัน, ไม่มี deadlock ค้าง | **P1 (regression)** |
| SYNC-06 | เลือกหลายแถวใน list | กด "ลบที่เลือก" (`act=delete_bulk`) | ลบเฉพาะที่เลือกจริง, นับจำนวนลบถูกต้อง | P2 |
| SYNC-07 | — | Import CSV แบบ `act=import` ที่ generic AppSheet format | เพิ่ม/ข้ามตาม serial ซ้ำถูกต้อง, ตรวจ dmy/mdy อัตโนมัติเหมือน updates_import | P1 |

## 6. สต็อกอะไหล่ `parts/`

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| PARTS-01 | มีสินค้าใน `products` | รับเข้าสินค้า (`stock-in.php`) จำนวนหนึ่ง | `quantity` ใน products เพิ่มถูกต้อง, มี record ใน `stock_in` | P1 |
| PARTS-02 | มี Set ที่ผูกกับสินค้าหลายตัว (`set_items`) | เบิกออกเป็น Set (`stock-out.php`) | ตัด `quantity` ของทุกสินค้าใน set ถูกต้องตาม `quantity` ที่กำหนดใน `set_items`, สร้าง `stock_out`+`stock_out_items` | P1 |
| PARTS-03 | สินค้าเหลือน้อยกว่าที่จะเบิก | เบิกออกเกินจำนวนคงเหลือ | ระบบ reject ไม่ให้ quantity ติดลบ | **P1** |
| PARTS-04 | — | เบิกรายชิ้น (`stock-out-item.php`) | ตัดสต็อกเฉพาะรายการที่เลือกถูกต้อง | P1 |
| PARTS-05 | — | ยิง POST ไปยัง `api/webhook-stockout.php` ด้วย JSON `{product_code, quantity, purpose}` ที่ถูกต้อง แต่ **ไม่แนบ auth header ใดๆ** | ระบบ **ยอมรับและตัดสต็อกสำเร็จ** — ยืนยันว่าไม่มี authentication จริง (บันทึกเป็น bug/security finding ไม่ใช่ pass) | **P1 (security)** |
| PARTS-06 | เดียวกับ PARTS-05 | ยิง JSON ที่ไม่มี `product_code` หรือ `quantity<=0` | ได้ HTTP 400 พร้อม error message ที่ถูกต้อง ไม่ crash | P1 |
| PARTS-07 | — | ยิง `product_code` ที่ไม่มีในระบบ | ได้ error ที่เหมาะสม (ไม่ throw 500 ที่ไม่มีความหมาย) | P2 |
| PARTS-08 | มี `min_stock` ตั้งไว้ | สต็อกลดต่ำกว่า `min_stock` | มี indicator/alert แจ้งเตือนสินค้าใกล้หมด (ถ้าฟีเจอร์นี้มีจริงในหน้า products) | P2 |
| PARTS-09 | — | สร้าง/แก้ไข Set (`sets.php`) เพิ่ม/ลบ `set_items` | บันทึกถูกต้อง, unique key (set_id, product_id) ป้องกันสินค้าเดียวซ้ำในชุด | P2 |
| PARTS-10 | มีประวัติ stock_in/out | ดูหน้า `history.php` | แสดงประวัติครบ เรียงเวลาถูกต้อง | P3 |

## 7. Appearance/Theme (`appearance.php`)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| APP-01 | เข้าหน้า appearance | เปลี่ยนสีธีมด้วยค่า hex ที่ถูกต้อง (`#1a2b3c`) | บันทึกสำเร็จ, ธีมเปลี่ยนจริงทั้งระบบ | P1 |
| APP-02 | — | กรอกค่าสีที่ไม่ใช่ hex ที่ถูกต้อง (เช่น `red`, `<script>`, ค่าว่าง) | regex validate reject ค่าที่ไม่ถูกต้อง ไม่ถูกบันทึกดิบๆ ลง `site_settings` | **P1 (validation/XSS)** |
| APP-03 | — | อัปโหลดโลโก้เป็นไฟล์ SVG ที่มี `<script>` ฝังอยู่ | ต้องตรวจสอบว่าระบบ sanitize/strip script หรือปฏิเสธไฟล์ — ถ้าอนุญาตให้อัปโหลดตรงๆ และ serve กลับมาแสดงผล จะเป็นช่องโหว่ **Stored XSS** | **P1 (security)** |
| APP-04 | — | อัปโหลดไฟล์ที่เปลี่ยนนามสกุลเป็น .png/.jpg แต่เนื้อหาจริงเป็นไฟล์อื่น (เช่น .php เปลี่ยนนามสกุล) | `getimagesize()` ต้อง reject ไฟล์ที่ไม่ใช่รูปจริง | P1 |
| APP-05 | — | อัปโหลดฟอนต์ผ่าน URL Google Fonts ปลอม/ไม่ใช่โดเมน Google Fonts | ตรวจสอบว่ามี whitelist โดเมนหรือไม่ (SSRF risk ถ้าไม่ validate) | P2 |
| APP-06 | — | ลาก-วางเปลี่ยนลำดับเมนู แล้วบันทึก | ลำดับเมนูใหม่ถูกบันทึกและแสดงผลถูกต้องหลัง refresh | P2 |

## 8. Authorization / Security (ครอบคลุมทั้งระบบ)

| ID | Precondition | Steps | Expected Result | Priority |
|----|---|---|---|---|
| SEC-01 | Login ผ่าน SSO ด้วยบัญชีทั่วไป (ไม่ใช่ admin/Tom) | เข้า URL ตรงของ `settings.php`, `users.php`, `appearance.php` | **พฤติกรรมจริงตามโค้ด (`can()` ที่ config.php:279-281): เข้าได้ทั้งหมด** — นี่คือ known issue ที่ต้องยืนยันด้วยการทดสอบจริงแล้วรายงานเป็น bug ความรุนแรง High ไม่ใช่ผลลัพธ์ที่ยอมรับได้ | **P1 (security — คาดว่า FAIL)** |
| SEC-02 | ยังไม่ปลดล็อกหลังบ้าน | เข้า action ที่ต้อง `settings_admin_unlocked()` (เช่น sync ใน share.php) โดยตรง | ต้อง reject (ดู SYNC-02) — จุดนี้มี guard อยู่จริง | P1 |
| SEC-03 | ทราบ/เดา PIN `9981` | กรอก PIN เข้าหน้าหลังบ้าน | เข้าได้ทันที — ยืนยันความเสี่ยง PIN สั้น/ฮาร์ดโค้ด ไม่มี rate-limit/lockout | **P2 (security finding, ไม่ใช่บั๊กที่ต้องแก้ในเชิง function แต่ต้อง flag)** |
| SEC-04 | — | เปิดฟอร์ม POST ใดๆ (เช่น `share.php` add/edit/delete) แล้วยิง POST ตรงโดยไม่มี CSRF token หรือ token ผิด | ระบบ reject (มี `csrf_check()` ครบทุกไฟล์ที่รับ POST ในระบบหลัก — ยืนยันแล้วจากโค้ด) | P1 |
| SEC-05 | — | เข้า `parts/delete.php?domain=x&pass=a9981` ตรงๆ (ไม่ผ่าน UI) | ผ่านการเช็ครหัส (เพราะรหัสฮาร์ดโค้ดอยู่ในซอร์ส) — ยืนยันความเสี่ยง แม้โค้ดลบจริงยังไม่ implement (มีแค่ comment placeholder) ควร flag ให้ทีมลบไฟล์นี้ทิ้งถ้าไม่ได้ใช้งานจริง | **P1 (security)** |
| SEC-06 | — | ยิง POST ตรงไปยัง `parts/add.php` (สร้าง Cloudflare Tunnel) โดยไม่ login | ตรวจสอบว่ามีการเช็ค auth หรือไม่ ถ้าไม่มี = เข้าใช้ Cloudflare API token ที่ฝังในโค้ดได้โดยไม่ต้อง login | **P1 (security)** |
| SEC-07 | — | ยิง POST ไปยัง `api/webhook-stockout.php` จาก IP ภายนอกที่ไม่ใช่ระบบที่ควรเรียก | สำเร็จ (ไม่มี auth) — ดู PARTS-05 | P1 |

---

## หมายเหตุสำหรับผู้ทดสอบ

- ระบบไม่มี staging/test environment แยกจาก production ที่ชัดเจน (schema.sql มีแต่ของ `parts/`) — **ควรตั้ง environment ทดสอบแยกก่อนรัน test ที่มีผลกระทบต่อข้อมูลจริง** (โดยเฉพาะ ASSET-04, SYNC-05, PARTS-03 ที่ทดสอบ concurrency/destructive)
- Test case ที่ทำเครื่องหมาย "(คาดว่า FAIL)" คือจุดที่ยืนยันจากโค้ดแล้วว่าพฤติกรรมปัจจุบันไม่ปลอดภัย — ทดสอบเพื่อ **ยืนยันซ้ำ (confirm)** ก่อนรายงานเป็นบั๊กอย่างเป็นทางการ ไม่ใช่คาดหวังว่าจะผ่าน
