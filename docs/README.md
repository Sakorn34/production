# เอกสารระบบ — Production

ศูนย์รวมเอกสารของโฟลเดอร์ `production/` ซึ่งมี **2 ระบบ PHP แยกกัน** ที่เชื่อมโยงข้อมูลกัน:

| ระบบ | โฟลเดอร์ | หน้าที่ | ฐานข้อมูล |
|---|---|---|---|
| ระบบทะเบียนเครื่องและซ่อมบำรุง | [`finishgoogs_ma_update/`](../finishgoogs_ma_update) | บันทึกการผลิต ทะเบียนเครื่อง ซ่อม/MA อัปเดต FW-HW ลูกค้า | `biton_production` (+ อ่าน/เขียน `biton_stockparts`) |
| ระบบจัดการสต็อกอะไหล่ | [`parts/`](../parts) | รับเข้า-เบิกออกอะไหล่ จัดการ Set | `biton_tech_parts` |

## ความสัมพันธ์ระหว่าง 2 ระบบ

ทั้งสองระบบเชื่อมกันผ่าน **ฐานข้อมูล 2 จุด**:

1. **ทะเบียนเครื่องผลิตเสร็จ** — `biton_stockparts.stock` (ADR 0002)
   - ระบบทะเบียนเครื่อง (`finishgoogs_ma_update`) เขียนข้อมูลเครื่องที่ผลิตเสร็จเข้าไปโดยอัตโนมัติ ผ่านฟังก์ชัน `share_upsert_asset()` และมีหน้า `share.php` / `share_admin.php` ไว้เทียบข้อมูล/sync ย้อนหลังแบบแมนนวล — ดู [ADR 0002](adr/0002-single-source-stock-registry.md)

2. **สต็อกอะไหล่จริง** — `biton_tech_parts.products` (single source of truth สำหรับ quantity)
   - เมื่อบันทึกการผลิตหรือเบิกอะไหล่ใน `finishgoogs_ma_update` ระบบจะตัดสต็อกที่ `biton_tech_parts` โดยตรงผ่าน `includes/part_stock_bridge.php` (mapping: `parts.part_code` = `products.code`)
   - รับเข้าอะไหล่ทำที่แอป `parts/` เท่านั้น
   - **Webhook ถูกปิดใช้งานแล้ว** — ดู `database/tools/audit_part_codes.php` สำหรับตรวจรหัสที่ยังไม่ match

### Localhost dev

- SSO ปิดชั่วคราวบน localhost — auto-login เป็น `Tom` (ดู `config.php` → `ss_dev_localhost_bootstrap()`)
- Secrets อยู่ที่ `D:/AppServ/secrets/production/finishgoogs.secrets.php` (3 DB: production, stockparts, techparts)
- รัน `database/tools/setup_localhost_users.sql` ถ้ายังไม่มี MySQL user บนเครื่อง dev

ทั้งสองระบบใช้ **PHP ล้วน ไม่มี framework** (vanilla PHP + mysqli/PDO) แต่ละหน้าคือไฟล์ `.php` เดี่ยวๆ ไม่มี router กลาง

## สารบัญ

### คู่มือผู้ใช้งาน (User Guide)
- [ระบบทะเบียนเครื่องและซ่อมบำรุง](user-guide/finishgoogs-ma-update.md)
- [ระบบจัดการสต็อกอะไหล่](user-guide/parts-stock.md)

### เอกสารอ้างอิงสำหรับนักพัฒนา (API Reference)
- [Endpoint ของระบบทะเบียนเครื่อง](api-reference/finishgoogs-ma-update.md) (dashboard drill-down, sync)
- [Webhook API ของระบบสต็อกอะไหล่](api-reference/parts-webhook-api.md) — **deprecated (ปิดใช้งานแล้ว)**

### บันทึกการตัดสินใจเชิงสถาปัตยกรรม (ADR)
- [0001 — เปลี่ยนไปใช้ SSO ภายนอกแทนระบบ login เดิม](adr/0001-external-sso-authentication.md)
- [0002 — ทะเบียนสินค้ากลางอยู่ที่ `biton_stockparts.stock` จุดเดียว](adr/0002-single-source-stock-registry.md)
- [0003 — บังคับ autocommit และใช้ GET_LOCK กันข้อมูลหายแบบเงียบ](adr/0003-transaction-locking-strategy.md)

## เอกสารที่มีอยู่แล้วในโค้ด (ไม่ย้าย ใช้อ้างอิงต่อ)
- [`parts/README.md`](../parts/README.md) — วิธีติดตั้งระบบสต็อกอะไหล่ + โครงสร้างไฟล์/ตาราง
- `finishgoogs_ma_update/includes/part_stock_bridge.php` — เชื่อมเบิก/คืนอะไหล่กับ `biton_tech_parts` โดยตรง
- `finishgoogs_ma_update/database/tools/audit_part_codes.php` — ตรวจ `part_code` ว่าตรง `products.code` หรือไม่
- `finishgoogs_ma_update/system_doc.php` — หน้าเอกสารสรุปในตัวแอปเอง (เปิดจากเมนูระบบเมื่อ login แล้ว)
