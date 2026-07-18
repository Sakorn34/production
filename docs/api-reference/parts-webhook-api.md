# API Reference — Webhook ระบบสต็อกอะไหล่

ระบบสต็อกอะไหล่ (`parts/`) เปิด endpoint เดียวสำหรับให้ระบบภายนอกเรียกมาตัดสต็อกอะไหล่โดยอัตโนมัติ ไม่ต้องผ่านหน้าเว็บ

**เอกสารฉบับเต็ม (source of truth):** [`parts/api/WEBHOOK_API.md`](../../parts/api/WEBHOOK_API.md) — มีตัวอย่างโค้ด cURL/PHP/Python ครบ อัปเดตเอกสารที่ไฟล์นั้นเมื่อพฤติกรรม API เปลี่ยน ไม่ต้อง sync มาที่นี่

## สรุปสั้น

```
POST /parts/api/webhook-stockout.php
Content-Type: application/json
```

```json
{ "product_code": "P001", "quantity": 5, "purpose": "ผลิต" }
```

- ไม่ต้อง authenticate — endpoint นี้เปิดสาธารณะ (ไม่มี API key/token) จำกัดการเข้าถึงด้วย network level เท่านั้น
- ตัดสต็อกทันทีถ้าอะไหล่พอ, validate `product_code`/`quantity`/`purpose` ก่อนเสมอ
- บันทึกลงประวัติเบิก (`stock_out`) ด้วย `issued_by = 'webhook'` และ `doc_no` รูปแบบ `WEBHOOK-YYYYMMDD-xxxxx`
- ตอบกลับเป็น JSON เสมอ ทั้งกรณีสำเร็จและ error (ดู error code ทั้งหมดในเอกสารฉบับเต็ม)

## ผู้เรียกจริง: `finishgoogs_ma_update`

ระบบทะเบียนเครื่องและซ่อมบำรุง (`finishgoogs_ma_update`) เป็นผู้เรียก endpoint นี้เองอัตโนมัติ ผ่านไฟล์ [`includes/part_webhook.php`](../../finishgoogs_ma_update/includes/part_webhook.php) — ยิง POST JSON ออกไปหลังบันทึก `part_movements` (ผลิตใหม่ หรือเบิกใช้อะไหล่ที่ผูกกับเครื่อง) โดย URL ปลายทางและ body template (รองรับ placeholder `{{product_code}}`, `{{quantity}}`, `{{purpose}}`, `{{device}}`, `{{serial_number}}`, `{{User}}`) ตั้งค่าแยกต่อรุ่นสินค้าได้ในหน้า "ตั้งค่า" ของระบบทะเบียนเครื่อง

ดู [ความสัมพันธ์ระหว่าง 2 ระบบ](../README.md#ความสัมพันธ์ระหว่าง-2-ระบบ)
