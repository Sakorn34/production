# API Reference — ระบบทะเบียนเครื่องและซ่อมบำรุง

ระบบนี้**ไม่มี REST API สาธารณะ** endpoint ทั้งหมดด้านล่างเป็น internal endpoint ที่หน้าเว็บของระบบเองเรียกผ่าน AJAX/fetch เท่านั้น ไม่ได้ออกแบบมาให้ระบบภายนอกเรียกตรงๆ (ถ้าต้องการเชื่อมจากระบบภายนอก ให้ใช้ [Webhook API ของระบบสต็อกอะไหล่](parts-webhook-api.md) แทน)

ทุก endpoint ต้องมี **session ที่ login แล้ว** (ผ่าน SSO) เท่านั้น — เรียกโดยไม่มี session ที่ถูกต้องจะถูก redirect ไปหน้า SSO login (`SSO_LOGIN_URL`) ไม่ใช่ error response

Base URL: `BASE_URL` = `/production` (กำหนดใน [`finishgoogs_ma_update/config.php:10`](../../finishgoogs_ma_update/config.php))

---

## GET `/dashboard_data.php`

คืน **HTML fragment** (ไม่ใช่ JSON) สำหรับ inject เข้า modal บนหน้าแดชบอร์ด ใช้ทำ drill-down หลายชั้น: ปี → เดือน → รุ่นสินค้า → รายการเครื่อง → timeline

ไฟล์: [`finishgoogs_ma_update/dashboard_data.php`](../../finishgoogs_ma_update/dashboard_data.php)

### Query Parameters

| พารามิเตอร์ | จำเป็น | คำอธิบาย |
|---|---|---|
| `type` | ใช่ | ประเภทข้อมูลที่ต้องการ (ดูตารางค่าด้านล่าง) |
| `v` | ตามชนิด | ค่าอ้างอิงหลัก (ปี/เดือน/สถานะ/asset id ฯลฯ แล้วแต่ `type`) |
| `p` | บางชนิด | ค่าอ้างอิงรอง (เช่นชื่อรุ่นสินค้า หรือ product id) |

### ค่าที่รองรับของ `type`

| `type` | ต้องการ `v` | ต้องการ `p` | คืนอะไร |
|---|---|---|---|
| `status` | สถานะเครื่อง (ต้องอยู่ใน `status_list()`) | — | ตารางเครื่องตามสถานะ |
| `all` | — | — | ตารางเครื่องทั้งหมด (จำกัด 150 แถวล่าสุด) |
| `month` | `YYYY-MM` | — | ตารางเครื่องที่ผลิตในเดือนนั้น (คลิกแถวเปิด timeline ได้) |
| `year` | `YYYY` | — | ตารางเครื่องที่ผลิตในปีนั้น |
| `year_months` | `YYYY` | — | กราฟแท่งรายเดือนของปีนั้น |
| `month_models` | `YYYY-MM` | — | การ์ดรุ่นสินค้าที่ผลิตในเดือนนั้น (รูป+จำนวน) |
| `month_product` | `YYYY-MM` | ชื่อรุ่น | ตารางเครื่องของรุ่นนั้นในเดือนนั้น |
| `timeline` | asset id (int) | — | Timeline ประวัติทั้งหมดของเครื่องนั้น |
| `category` | ชื่อหมวดสินค้า | — | ตารางเครื่องตามหมวด |
| `product` | ชื่อรุ่น | — | ตารางเครื่องตามรุ่น |
| `product_years` | product id (int) | — | กราฟแท่งรายปีของรุ่นนั้น |
| `product_year_months` | `YYYY` | product id | กราฟแท่งรายเดือนของรุ่น+ปีนั้น |
| `product_month` | `YYYY-MM` | product id | ตารางเครื่องของรุ่น+เดือนนั้น |
| `repairs_open` | — | — | ตารางงานซ่อมที่ยังไม่ปิด (สถานะ `received`/`in_progress`) |

ค่า `type` ที่ไม่รู้จัก → คืนข้อความ `ไม่รู้จักประเภทข้อมูล` (HTTP 200) พารามิเตอร์รูปแบบผิด (เช่น `year` ไม่ใช่ 4 หลัก) → `exit()` พร้อมข้อความ error สั้นๆ เป็น HTML ธรรมดา ไม่ใช่ JSON

จำกัดผลลัพธ์สูงสุด **150 แถว** ต่อการเรียกหนึ่งครั้ง (ตัวแปร `$LIMIT`)

---

## POST `/sync_data.php`

Trigger การซิงก์ข้อมูลเครื่องทั้งหมดจากทะเบียนเครื่อง (`assets`) เข้า `biton_stockparts.stock` — เรียกจากปุ่ม "Sync ทั้งหมด" ในหน้า `share_admin.php`

ไฟล์: [`finishgoogs_ma_update/sync_data.php`](../../finishgoogs_ma_update/sync_data.php)

### เงื่อนไข
- ต้อง login แล้ว (`require_login()`)
- ต้องปลดล็อกระบบหลังบ้านแล้ว (`settings_admin_unlocked()`) — ไม่งั้นได้ `{"ok": false, "error": "ต้องเข้าหน้าหลังบ้านก่อน"}`
- ต้องเป็น `POST` เท่านั้น
- ต้องแนบ CSRF token ที่ถูกต้อง (field `csrf`) — ไม่งั้น `exit()` ทันที ไม่คืน JSON

### Response

```json
// สำเร็จ
{ "ok": true, "result": { /* ผลจาก share_sync_all_from_production() */ } }

// ล้มเหลว
{ "ok": false, "error": "ซิงก์ไม่สำเร็จ" }
```

---

## GET `/notifications.php`

คืน JSON รายการแจ้งเตือนล่าสุด สำหรับ popup กระดิ่งแจ้งเตือนที่แถบบน

ไฟล์: [`finishgoogs_ma_update/notifications.php`](../../finishgoogs_ma_update/notifications.php)

---

## GET `/assets.php?ajax=suggest`

Live-search แนะนำเครื่องขณะพิมพ์ในช่องค้นหา (autocomplete) คืน HTML fragment รายการที่ตรงกับคำค้น

ไฟล์: [`finishgoogs_ma_update/assets.php`](../../finishgoogs_ma_update/assets.php)

---

## กลไก Sync กับทะเบียนสินค้ากลาง

ฟังก์ชัน sync หลักทั้งหมดอยู่ใน [`finishgoogs_ma_update/config.php`](../../finishgoogs_ma_update/config.php) (บรรทัด 474 เป็นต้นไป) ไม่ใช่ endpoint ที่เรียกจากภายนอกได้โดยตรง แต่เป็น business logic ที่หน้าเว็บ (`share.php`, `share_admin.php`, `sync_data.php`) เรียกใช้ภายใน:

| ฟังก์ชัน | หน้าที่ |
|---|---|
| `share_upsert_asset($assetId, $oldCode)` | Sync เครื่อง 1 ตัวเข้า `biton_stockparts.stock` (เรียกอัตโนมัติทุกครั้งที่บันทึก/แก้ไขเครื่อง) — ใช้ `GET_LOCK` ป้องกัน race condition ตอนออกเลข id ดู [ADR 0003](../adr/0003-transaction-locking-strategy.md) |
| `share_sync_all_from_production()` | Sync เครื่องทั้งหมดจากทะเบียนเครื่องเข้า stock (ใช้โดย `sync_data.php`) |
| `share_reconcile_index()` / `share_reconcile_list()` | หารายการที่ไม่ตรงกันระหว่าง `assets` กับ `stock` (ใช้แสดงในหน้า `share_admin.php`) |
| `share_import_basic_csv($filePath)` | นำเข้าทะเบียนสินค้าเก่าจากไฟล์ CSV |

ดูกฎการห้ามแก้ schema ตาราง `stock` และเหตุผลที่ทะเบียนกลางต้องมีจุดเดียวที่ [ADR 0002](../adr/0002-single-source-stock-registry.md)
