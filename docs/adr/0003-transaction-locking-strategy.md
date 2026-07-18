# ADR 0003 — บังคับ `autocommit(true)` และใช้ `GET_LOCK` กันข้อมูลหายแบบเงียบ

- **สถานะ:** Accepted (แก้ไขหลังพบปัญหาจริงในการใช้งาน)
- **ขอบเขต:** `finishgoogs_ma_update/config.php` — ทั้งสอง connection (`db()` และ `dbStock()`)

## บริบท

เคยเกิดปัญหาจริง (postmortem บันทึกไว้เป็นคอมเมนต์ในโค้ด) ที่หน้า `assets.php` แสดงข้อความ "บันทึกสำเร็จ" ทุกครั้ง แต่**เครื่องที่เพิ่งผลิตไม่ปรากฏในทะเบียน** สาเหตุคือ:

1. **Autocommit ปิดอยู่ที่ระดับ MySQL user account** (`biton_production`) โดยที่โค้ด PHP ทั้งระบบไม่เคยเรียก `commit()` เลย — ทุก `INSERT` จึงอยู่ในธุรกรรมที่ไม่เคย commit และถูก rollback ทิ้งเงียบๆ ตอน connection ปิด โดยที่ PHP มองไม่เห็น error ใดๆ (`config.php:51-54`)
2. **Race condition ตอนออกเลข id แบบคำนวณเอง** — เมื่อมีการเขียนพร้อมกันหลายเครื่อง id ถัดไปที่คำนวณได้ (เช่น `MAX(id)+1`) อาจชนกับแถวที่เพิ่งถูกเขียนไปหมาดๆ ทำให้ `ON DUPLICATE KEY UPDATE` ไปแก้ทับแถวอื่นแทนที่จะ insert แถวใหม่ — ก็หายเงียบเช่นกัน ไม่มี error (`config.php:486-488`)

## การตัดสินใจ

1. **บังคับ `$db->autocommit(true)` ทันทีหลังเปิด connection** ทั้งสองจุด (`db()` ที่ `config.php:55` และ `dbStock()` ที่ `config.php:31`) เพื่อไม่ให้พึ่งพา default setting ของ MySQL user account ที่อาจถูกตั้งไว้คนละแบบโดยไม่รู้ตัว
2. **ครอบ critical section ที่ต้อง "อ่าน id ถัดไป + insert" ด้วย `GET_LOCK`** ให้เป็น atomic operation:
   - `share_upsert_asset()` ใช้ lock key `biton_stockparts_stock_id_seq` (timeout 10 วินาที) ก่อนคำนวณ id ถัดไปของตาราง `stock` (`config.php:490-501`)
   - การออกรหัสเครื่องใหม่ (`create_produced_asset`) ใช้ lock key ต่อ prefix รุ่นสินค้า (`gencode_<prefix>` หรือ `gencode_pid_<id>`) ก่อนคำนวณเลขรันนิ่ง (`config.php:1560-1564`)
   - ถ้า `GET_LOCK` ไม่สำเร็จภายใน timeout → **fail-soft พร้อม log** (`error_log`) ไม่ throw exception ทำให้ผู้ใช้เห็น error ชัดเจนกว่าการหายเงียบแบบเดิม แต่การ sync รอบนั้นจะไม่เกิดขึ้น ต้องตรวจสอบย้อนหลังจาก log
3. **ยืนยันข้อมูลจริงใน DB หลัง insert สำคัญ** — เช่นหลังบันทึกเครื่องใหม่ จะ `SELECT` กลับมาเช็คว่าแถวมีอยู่จริงก่อนถือว่าสำเร็จ (`config.php:1547-1550`) เผื่อกรณี autocommit/rollback เงียบๆ ที่ยังหลุดรอดมาได้

## ผลกระทบ

- แก้ปัญหาข้อมูลหายแบบเงียบที่เคยเกิดขึ้นจริง แลกกับ overhead เล็กน้อยจาก `GET_LOCK` ทุกครั้งที่ออกรหัสเครื่อง/sync stock (timeout 10 วินาทีต่อครั้งในกรณีเลวร้ายที่สุด)
- ทุก connection ใหม่ที่เพิ่มเข้าระบบในอนาคต**ต้องบังคับ `autocommit(true)` ด้วยเช่นกัน** — ไม่ใช่ default ที่รับประกันจาก server ฝั่งนี้
- Pattern `GET_LOCK` + fail-soft + log ควรใช้เป็นแนวทางเดียวกันทุกจุดที่มีการคำนวณ id/ลำดับเองแทนการพึ่ง `AUTO_INCREMENT` (เช่นถ้ามีจุดคำนวณ sequence แบบ manual อื่นเพิ่มในอนาคต)
