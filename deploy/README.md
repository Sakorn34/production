# Deploy — อัปขึ้น server โดยไม่พลาด

## สองโหมด

| โหมด | ใช้เมื่อ | สคริปต์ | อัปกี่ไฟล์ |
|------|---------|---------|-----------|
| **Full** | ครั้งแรก / อัปทั้งระบบ | `build-release.bat` | ทั้งชุด (~97 ไฟล์) |
| **Patch** | แก้บางไฟล์แล้วอัปต่อ | `build-patch.bat` | **เฉพาะที่เปลี่ยน** |

```
แก้โค้ดใน production/
        │
        ├─ build-release.bat  ──► release/production/     (ครบชุด)
        │
        └─ build-patch.bat    ──► release/production/     (ใหม่ — อัป FTP)
                               release/before/         (เก่า — เทียบ diff)
                                      │
                                      │ FTP (เฉพาะ production/)
                                      ▼
                               httpdocs/production/
```

---

## โฟลเดอร์ before (เทียบกับ production)

เมื่อรัน **`build-patch.bat`**:

| โฟลเดอร์ | ความหมาย | อัป server? |
|----------|----------|-------------|
| `production/` | ไฟล์**ใหม่**หลังแก้ | **ใช่** |
| `before/` | ไฟล์**เก่า**ก่อนแก้ (จาก baseline archive หรือ git) | **ไม่** |

เทียบ diff: เปิด `before/...` กับ `production/...` ใน editor (WinMerge / VS Code Compare)

รายการไฟล์: `before/README.txt`

### ประหยัดพื้นที่

- ไม่ copy โฟลเดอร์ `after/` อีกต่อไป — **production = ของใหม่แล้ว**
- archive ใน `release/versions/` เก็บแค่ `production/` + `before/` (ไม่ซ้ำซ้อน)
- ลบเวอร์ชันเก่าใน `release/versions/` ได้เมื่อไม่ต้องการย้อนดู (เก็บ 5–10 ล่าสุดก็พอ)
- Full release ครั้งแรก (~97 ไฟล์) ใหญ่สุด — patch ถัดไปเล็กมาก

---

## ไฟล์สำคัญในโฟลเดอร์ upload

| ไฟล์ | อัปขึ้น server? | ความหมาย |
|------|-----------------|----------|
| `README.html` | **ไม่ต้อง** | เปิดใน browser — สรุปเวอร์ชัน + รายการไฟล์ + วิธี FTP |
| `before/` | **ไม่ต้อง** | ไฟล์เก่าก่อนแก้ — เทียบกับ production/ |
| `PATCH-MANIFEST.txt` | **ไม่ต้อง** | รายการไฟล์ patch (สำรอง) |
| `DELETE-ON-SERVER.txt` | **ไม่ต้อง** | ไฟล์ที่ต้องลบบน server |
| โค้ดใน `shared/`, `parts/`, `finishgoogs_ma_update/` | **ใช่** | อัปตาม path |

ประวัติทุกเวอร์ชัน: [`CHANGELOG.txt`](CHANGELOG.txt) และ `release/versions/`

---

## วิธีใช้ Full (ครั้งแรก)

1. ดับเบิลคลิก **`build-release.bat`**
2. เปิด **`deploy/release/production/README.html`** ใน browser
3. FTP อัปเนื้อหาใน `deploy/release/production/` → `httpdocs/production/`
   - **ข้าม** README.html, PATCH-MANIFEST.txt
4. อัป secrets แยกจาก `deploy/hostatom/` (ดูด้านล่าง)
5. ทดสอบ `http://103.30.127.11/production/finishgoogs_ma_update/`
6. กลับไปหน้าต่าง `build-release.bat` → พิมพ์ **OK** หลังอัป FTP

---

## วิธีใช้ Patch (หลังอัปครั้งแรก)

1. แก้โค้ดในโปรเจกต์
2. ดับเบิลคลิก **`build-patch.bat`**
3. เปิด **`deploy/release/production/README.html`** ใน browser
4. FTP อัปเฉพาะไฟล์/โฟลเดอร์ที่มี → `httpdocs/production/` (คง path)
5. กลับไปหน้าต่าง `build-patch.bat` → พิมพ์ **OK** หลังอัป FTP

> ไม่ต้องรัน `mark-deployed.bat` แยก — รวมใน `.bat` แล้ว  
> Preview อย่างเดียว: ใช้ `build-patch-preview.bat`

---

## Workflow อัตโนมัติ (หลังแก้โค้ด)

```
1. แก้โค้ดใน D:\AppServ\www\production\
2. AI รัน build-patch + สร้าง README.html
3. คุณ FTP อัปจาก deploy/release/production/
4. บอกในแชท "อัปเสร็จแล้ว" → AI รัน mark-deployed ให้
   (หรือใช้ build-patch.bat → พิมพ์ OK หลังอัป)
```

---

## ตั้งครั้งแรกบน server (แยกจาก upload)

โฟลเดอร์ **`deploy/hostatom/`** (ไม่ commit git):

| ไฟล์ | วางบน server |
|------|----------------|
| `finishgoogs.secrets.php` | `private/secrets/production/` |
| `parts.secrets.php` | `private/secrets/production/` |
| `config.paths.php` | `httpdocs/production/finishgoogs_ma_update/` |

ดูรายละเอียด: [`hostatom/UPLOAD.md`](hostatom/UPLOAD.md)

---

## LINE cron บน Plesk (trigger รายการละแจ้งเตือน)

หลัง patch `v2026-07-25_182834`: อัป FTP แล้วตั้ง Scheduled Task ตาม [`LINE-PLESK-MIGRATION.md`](LINE-PLESK-MIGRATION.md)

- **ไม่ต้องลบ** ไฟล์ PHP เก่าใน `cron/` — ทับ patch พอ
- **ควรปิด/ลบ** task Plesk เก่า (tick / worker / Run a command)
- ลบบน server ได้ (ไม่บังคับ): `setup_line_tasks.bat` — ดู `DELETE-ON-SERVER.txt` ใน release

---

## สิ่งที่สคริปต์ตัดออกให้อัตโนมัติ

| ไม่เอาขึ้น server | เหตุผล |
|-------------------|--------|
| `.git`, secrets, `config.paths.php` | ของ dev / รหัสลับ |
| `database/tools/`, logs, uploads | script dev / runtime |
| `README.html`, `PATCH-MANIFEST.txt`, `DELETE-ON-SERVER.txt` | เอกสาร dev |

---

## โครงสร้าง deploy/

| ไฟล์ | หน้าที่ |
|------|---------|
| `build-release.bat` | สร้าง full → อัป FTP → พิมพ์ OK บันทึก baseline |
| `build-patch.bat` | สร้าง patch → อัป FTP → พิมพ์ OK บันทึก baseline |
| `build-patch-preview.bat` | สร้าง patch อย่างเดียว (ไม่ mark) |
| `mark-deployed.bat` | บันทึก baseline เอง (กรณีพิเศษ) |
| `CHANGELOG.txt` | ประวัติเวอร์ชันสะสม |
| `release/production/` | **อัปโฟลเดอร์นี้** |
| `release/versions/` | archive แต่ละเวอร์ชัน + README.html |
| `.last-deploy.json` | baseline เปรียบเทียบ patch (local) |
| `.last-build.json` | ข้อมูล build ล่าสุด (local) |
| `hostatom/` | secrets + config สำหรับ server จริง |
| `LINE-PLESK-MIGRATION.md` | ย้าย LINE cron: อัป FTP, ปิด task เก่า, สร้าง task รายการ |
