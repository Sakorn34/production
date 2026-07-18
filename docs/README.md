# เอกสารระบบ — Production

ศูนย์รวมเอกสารของโฟลเดอร์ `production/` ซึ่งมี **2 ระบบ PHP แยกกัน** ที่เชื่อมโยงข้อมูลกัน:

| ระบบ | โฟลเดอร์ | หน้าที่ | ฐานข้อมูล |
|---|---|---|---|
| ระบบทะเบียนเครื่องและซ่อมบำรุง | [`finishgoogs_ma_update/`](../finishgoogs_ma_update) | บันทึกการผลิต ทะเบียนเครื่อง ซ่อม/MA อัปเดต FW-HW ลูกค้า | `biton_production` (+ อ่าน/เขียน `biton_stockparts`) |
| ระบบจัดการสต็อกอะไหล่ | [`parts/`](../parts) | รับเข้า-เบิกออกอะไหล่ จัดการ Set | `biton_tech_parts` |

## ความสัมพันธ์ระหว่าง 2 ระบบ

ทั้งสองระบบ**ไม่ได้เชื่อม database กันโดยตรง** แต่เชื่อมผ่านทะเบียนสินค้ากลางที่อยู่ในฐานข้อมูล **`biton_stockparts`** (ตาราง `stock`) ซึ่งเป็นของทีมระบบสต็อกอะไหล่ ไม่ใช่ของระบบใดระบบหนึ่ง:

- ระบบทะเบียนเครื่อง (`finishgoogs_ma_update`) เขียนข้อมูลเครื่องที่ผลิตเสร็จเข้าไปที่ `biton_stockparts.stock` โดยอัตโนมัติ ผ่านฟังก์ชัน `share_upsert_asset()` และมีหน้า `share.php` / `share_admin.php` ไว้เทียบข้อมูล/sync ย้อนหลังแบบแมนนวล — ดู [ADR 0002](adr/0002-single-source-stock-registry.md)
- ระบบสต็อกอะไหล่ (`parts/`) เป็นระบบอิสระที่ไม่เกี่ยวกับตาราง `stock` นี้ — เชื่อมกับ `finishgoogs_ma_update` ทางเดียว: ฝั่ง `finishgoogs_ma_update` เป็นผู้**ยิง webhook ออกไปเอง** ทุกครั้งที่บันทึกการผลิต/เบิกใช้อะไหล่ที่ผูกกับเครื่อง (`includes/part_webhook.php`, ตั้งค่า URL/body ต่อรุ่นสินค้าได้) ไปตัดสต็อกที่ `parts/api/webhook-stockout.php` ดู [API Reference: Parts Webhook](api-reference/parts-webhook-api.md)

ทั้งสองระบบใช้ **PHP ล้วน ไม่มี framework** (vanilla PHP + mysqli/PDO) แต่ละหน้าคือไฟล์ `.php` เดี่ยวๆ ไม่มี router กลาง

## สารบัญ

### คู่มือผู้ใช้งาน (User Guide)
- [ระบบทะเบียนเครื่องและซ่อมบำรุง](user-guide/finishgoogs-ma-update.md)
- [ระบบจัดการสต็อกอะไหล่](user-guide/parts-stock.md)

### เอกสารอ้างอิงสำหรับนักพัฒนา (API Reference)
- [Endpoint ของระบบทะเบียนเครื่อง](api-reference/finishgoogs-ma-update.md) (dashboard drill-down, sync)
- [Webhook API ของระบบสต็อกอะไหล่](api-reference/parts-webhook-api.md)

### บันทึกการตัดสินใจเชิงสถาปัตยกรรม (ADR)
- [0001 — เปลี่ยนไปใช้ SSO ภายนอกแทนระบบ login เดิม](adr/0001-external-sso-authentication.md)
- [0002 — ทะเบียนสินค้ากลางอยู่ที่ `biton_stockparts.stock` จุดเดียว](adr/0002-single-source-stock-registry.md)
- [0003 — บังคับ autocommit และใช้ GET_LOCK กันข้อมูลหายแบบเงียบ](adr/0003-transaction-locking-strategy.md)

## เอกสารที่มีอยู่แล้วในโค้ด (ไม่ย้าย ใช้อ้างอิงต่อ)
- [`parts/README.md`](../parts/README.md) — วิธีติดตั้งระบบสต็อกอะไหล่บน XAMPP + โครงสร้างไฟล์/ตาราง
- [`parts/api/WEBHOOK_API.md`](../parts/api/WEBHOOK_API.md) — สเปกละเอียดของ webhook stockout API
- `finishgoogs_ma_update/system_doc.php` — หน้าเอกสารสรุปในตัวแอปเอง (เปิดจากเมนูระบบเมื่อ login แล้ว)
