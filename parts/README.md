# ระบบจัดการสต็อก (Stock Management System)

ระบบ PHP + MySQL สำหรับจัดการสต็อกอะไหล่ รองรับการรับเข้า เบิกออกเป็น Set และ Dashboard ภาพรวม

**ติดตั้งที่:** `C:\xampp\htdocs\parts`  
**URL:** http://localhost/parts

## ฟีเจอร์

- **Dashboard** — ภาพรวมสต็อก, อะไหล่ใกล้หมด, ความเคลื่อนไหวล่าสุด
- **อะไหล่** — เพิ่มอะไหล่, ดูจำนวนคงเหลือ
- **รับเข้า** — บันทึกรับอะไหล่เข้าคลัง
- **เบิกออก (Set)** — เบิกอะไหล่ออกเป็นชุด (Set) พร้อมตรวจสอบสต็อก
- **จัดการ Set** — สร้างชุดเบิกและกำหนดอะไหล่ในชุด
- **ประวัติเบิก** — ดูประวัติการเบิกออกทั้งหมด

## ติดตั้งด้วย XAMPP

### 1. เปิด XAMPP Control Panel

- Start **Apache**
- Start **MySQL**

### 2. สร้างฐานข้อมูล

**วิธี A — phpMyAdmin (แนะนำ)**

1. เปิด http://localhost/phpmyadmin
2. คลิก **Import**
3. เลือกไฟล์ `C:\xampp\htdocs\parts\database\schema.sql`
4. กด **Go**

**วิธี B — Command line**

```bash
C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < C:\xampp\htdocs\parts\database\schema.sql
```

> สำคัญ: ต้องใช้ `--default-character-set=utf8mb4` เพื่อให้ภาษาไทยแสดงถูกต้อง

**ถ้าภาษาเพี้ยน (แสดงเป็น ?)** — รันไฟล์แก้ไข:

```bash
C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < C:\xampp\htdocs\parts\database\fix_encoding.sql
```

### 3. ตั้งค่า Database (ถ้าจำเป็น)

แก้ไข `config/database.php` — ค่า default ของ XAMPP:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'biton_tech_parts');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP default ไม่มีรหัสผ่าน
define('BASE_PATH', '/parts');
```

### 4. เปิดใช้งาน

เปิดเบราว์เซอร์: **http://localhost/parts**

## โครงสร้าง

```
C:\xampp\htdocs\parts\
├── config/database.php      # การเชื่อมต่อ DB + BASE_PATH
├── database/schema.sql      # SQL schema + ข้อมูลตัวอย่าง
├── includes/
│   ├── header.php           # Layout
│   ├── footer.php
│   ├── helpers.php          # ฟังก์ชันช่วย
│   └── StockService.php     # Business logic
├── pages/
│   ├── products.php         # จัดการอะไหล่
│   ├── stock-in.php         # รับเข้า
│   ├── stock-out.php        # เบิกออก Set
│   ├── sets.php             # จัดการ Set
│   └── history.php          # ประวัติเบิก
├── assets/style.css
└── index.php                # Dashboard
```

## ตารางฐานข้อมูล

| ตาราง | คำอธิบาย |
|-------|----------|
| `products` | อะไหล่ + จำนวนคงเหลือ |
| `sets` | ชุดเบิก |
| `set_items` | อะไหล่ในแต่ละ Set |
| `stock_in` | ประวัติรับเข้า |
| `stock_out` | หัวเอกสารเบิกออก |
| `stock_out_items` | รายละเอียดอะไหล่ที่เบิก |
