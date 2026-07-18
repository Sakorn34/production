<?php
/** system_doc.php — อธิบายหลักการทำงานของระบบ (admin เท่านั้น) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

page_header('หลักการทำงานของระบบ');
$B = BASE_URL;
?>
<style>
.doc-section { margin-bottom: 28px; }
.doc-section h2 { font-size: 17px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--primary); color: var(--primary); }
.doc-section h3 { font-size: 14px; font-weight: 700; margin: 14px 0 6px; color: #45506a; }
.doc-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.doc-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
.tag-db1 { background: #dbeafe; color: #1d4ed8; }
.tag-db2 { background: #fce7f3; color: #9d174d; }
.tag-table { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.tag-fn { background: #fef3c7; color: #92400e; }
.db-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 900px) { .db-grid { grid-template-columns: 1fr; } }
.db-box { border-radius: 10px; overflow: hidden; }
.db-box-head { padding: 12px 16px; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.db-box-head.primary { background: #1d4ed8; color: #fff; }
.db-box-head.secondary { background: #9d174d; color: #fff; }
.db-box-body { border: 1px solid #e5e7eb; border-top: 0; border-radius: 0 0 10px 10px; padding: 10px 0; }
.tbl-item { display: flex; align-items: flex-start; gap: 10px; padding: 7px 14px; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
.tbl-item:last-child { border-bottom: 0; }
.tbl-name { font-family: monospace; font-weight: 700; color: #374151; min-width: 165px; flex-shrink: 0; }
.tbl-desc { color: #6b7280; line-height: 1.45; }
.tbl-rows { font-size: 11px; color: #9ca3af; margin-top: 2px; }
.flow-steps { counter-reset: step; display: flex; flex-direction: column; gap: 0; }
.flow-step { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border-bottom: 1px dashed #e5e7eb; }
.flow-step:last-child { border-bottom: 0; }
.flow-num { counter-increment: step; content: counter(step); width: 26px; height: 26px; border-radius: 50%; background: var(--primary); color: #fff; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
.flow-body { flex: 1; font-size: 13px; line-height: 1.55; }
.flow-body b { color: #1f2937; }
.flow-writes { display: inline-flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.flow-write { font-size: 11px; font-family: monospace; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 4px; }
.flow-write.del { background: #fee2e2; color: #991b1b; }
.rel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 10px; }
.rel-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; }
.rel-box b { font-size: 13px; font-family: monospace; color: #1d4ed8; }
.rel-box ul { margin: 6px 0 0 16px; color: #4b5563; line-height: 1.7; }
.schema-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.schema-table th { background: #f3f4f6; padding: 6px 10px; text-align: left; border: 1px solid #e5e7eb; font-weight: 600; color: #374151; }
.schema-table td { padding: 5px 10px; border: 1px solid #e5e7eb; color: #374151; }
.schema-table tr:hover td { background: #fafafa; }
.col-pk { background: #fef9c3; }
.col-fk { background: #dbeafe; }
.col-key { font-family: monospace; font-weight: 600; }
.page-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 10px; margin-top: 8px; }
.page-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; }
.page-card .pfile { font-family: monospace; font-weight: 700; color: var(--primary); font-size: 13px; }
.page-card .pdesc { color: #6b7280; margin-top: 3px; line-height: 1.45; }
.page-card .pread { font-size: 11px; color: #9ca3af; margin-top: 4px; font-family: monospace; }
.section-note { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 8px 12px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: #1e40af; margin-bottom: 10px; }
.arrow { color: #9ca3af; font-size: 12px; }
.perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 8px; }
.perm-box { border-radius: 8px; overflow: hidden; font-size: 12.5px; }
.perm-head { padding: 7px 12px; font-weight: 700; color: #fff; }
.perm-list { padding: 7px 12px; border: 1px solid #e5e7eb; border-top: 0; border-radius: 0 0 8px 8px; }
.perm-list li { color: #4b5563; line-height: 1.7; }
.inline-code { font-family: monospace; font-size: 12px; background: #f3f4f6; padding: 1px 6px; border-radius: 4px; color: #374151; }
</style>

<!-- TOC ด่วน -->
<div class="panel doc-section" style="padding: 14px 18px">
  <b style="font-size:13px">เนื้อหา</b>
  <div style="display:flex; flex-wrap:wrap; gap:6px 16px; margin-top:8px; font-size:13px">
    <a href="#overview">① ภาพรวม</a>
    <a href="#databases">② ฐานข้อมูล</a>
    <a href="#pages">③ หน้าระบบ</a>
    <a href="#lifecycle">④ วงจรชีวิตเครื่อง</a>
    <a href="#tables">⑤ รายละเอียดตาราง</a>
    <a href="#relations">⑥ ความสัมพันธ์ตาราง</a>
    <a href="#helpers">⑦ ฟังก์ชันหลัก</a>
    <a href="#permissions">⑧ สิทธิ์ผู้ใช้</a>
    <a href="#import">⑨ การนำเข้าข้อมูล</a>
  </div>
</div>

<!-- ① ภาพรวม -->
<div class="panel doc-section" id="overview">
  <h2>① ภาพรวมระบบ</h2>
  <p style="font-size:13px; color:#4b5563; margin-bottom:12px">
    ระบบบันทึกข้อมูลการผลิตและการจัดการสินค้า (Production &amp; Asset Management) พัฒนาด้วย <b>PHP 7.3</b> บน <b>AppServ (Windows)</b> ใช้ <b>MySQL</b> เป็น database ผ่าน <b>mysqli</b> (prepared statements ทุกจุด)
  </p>
  <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px">
    <div class="page-card" style="border-left:3px solid #3b82f6">
      <div style="font-size:20px">🖥️</div>
      <div style="font-weight:700; margin-top:4px">ทะเบียนเครื่องผลิต</div>
      <div class="pdesc">บันทึก เครื่องใหม่, รหัสเครื่อง, สถานะ (ใหม่/เช่า/สำรอง), ชิ้นส่วน, FW ปัจจุบัน</div>
    </div>
    <div class="page-card" style="border-left:3px solid #8b5cf6">
      <div style="font-size:20px">🔧</div>
      <div style="font-weight:700; margin-top:4px">Maintenance (MA)</div>
      <div class="pdesc">บันทึกการ MA รายรอบ: อุปกรณ์ OK/เปลี่ยน/ซ่อม, FW ณ ตอนนั้น, ช่าง</div>
    </div>
    <div class="page-card" style="border-left:3px solid #06b6d4">
      <div style="font-size:20px">⬆️</div>
      <div style="font-weight:700; margin-top:4px">อัปเดต FW / HW</div>
      <div class="pdesc">บันทึก Firmware / Hardware เปลี่ยนแปลง พร้อมรูปถ่าย</div>
    </div>
    <div class="page-card" style="border-left:3px solid #10b981">
      <div style="font-size:20px">🔩</div>
      <div style="font-weight:700; margin-top:4px">อะไหล่ใช้ผลิต</div>
      <div class="pdesc">stock อะไหล่, BOM/เบิกใช้, แจ้งเตือน stock ต่ำ</div>
    </div>
    <div class="page-card" style="border-left:3px solid #f59e0b">
      <div style="font-size:20px">🛠️</div>
      <div style="font-weight:700; margin-top:4px">ประวัติซ่อม</div>
      <div class="pdesc">บันทึกการซ่อมแต่ละรอบ: อาการ, ผลวินิจฉัย, ช่าง, ลูกค้า</div>
    </div>
    <div class="page-card" style="border-left:3px solid #ec4899">
      <div style="font-size:20px">📊</div>
      <div style="font-weight:700; margin-top:4px">Dashboard</div>
      <div class="pdesc">กราฟผลิตรายเดือน/ปี, สถิติรายรุ่น, Drill-down Modal</div>
    </div>
    <div class="page-card" style="border-left:3px solid #6366f1">
      <div style="font-size:20px">📦</div>
      <div style="font-weight:700; margin-top:4px">ทะเบียนสินค้า (stock)</div>
      <div class="pdesc">sync อัตโนมัติไปยัง biton_stockparts.stock ทีมอะไหล่</div>
    </div>
    <div class="page-card" style="border-left:3px solid #14b8a6">
      <div style="font-size:20px">👥</div>
      <div style="font-weight:700; margin-top:4px">ลูกค้า / ผู้ใช้</div>
      <div class="pdesc">ทะเบียนลูกค้า, ผู้ใช้งาน, สิทธิ์แยกตามบทบาท</div>
    </div>
  </div>
</div>

<!-- ② ฐานข้อมูล -->
<div class="panel doc-section" id="databases">
  <h2>② ฐานข้อมูล</h2>
  <p class="section-note">ระบบใช้ 2 database ต่างกัน: <b>bit_production</b> (ฐานหลักของระบบนี้) และ <b>biton_stockparts</b> (ของทีมอะไหล่ — เชื่อมต่อข้ามฐาน)</p>
  <div class="db-grid">
    <!-- bit_production -->
    <div class="db-box">
      <div class="db-box-head primary">🗄️ bit_production &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ฐานหลัก — ระบบผลิต)</span></div>
      <div class="db-box-body">
        <div class="tbl-item"><span class="tbl-name">assets</span><div><div class="tbl-desc">ตารางกลาง: 1 แถว = เครื่อง 1 เครื่อง รหัสเครื่อง, รุ่น, สถานะ, ลูกค้าปัจจุบัน, FW ล่าสุด</div></div></div>
        <div class="tbl-item"><span class="tbl-name">products</span><div><div class="tbl-desc">รุ่นสินค้า: รหัส, prefix, โหมดสร้างรหัส (generated/factory_serial), รูปสินค้า</div></div></div>
        <div class="tbl-item"><span class="tbl-name">production_records</span><div><div class="tbl-desc">ประวัติการผลิต: ผู้ประกอบ, FW ณ ผลิต, checklist, ฟิลด์พิเศษ (JSON), QC ผ่าน/ไม่ผ่าน</div></div></div>
        <div class="tbl-item"><span class="tbl-name">asset_components</span><div><div class="tbl-desc">ชิ้นส่วนปัจจุบันของเครื่อง: Display, HUB, Main Board ฯลฯ (1 แถว/ชิ้นส่วน/เครื่อง)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">update_logs</span><div><div class="tbl-desc">ประวัติอัปเดต FW/HW: เวอร์ชันก่อน-หลัง, รูปถ่าย, ช่าง</div></div></div>
        <div class="tbl-item"><span class="tbl-name">ma_records</span><div><div class="tbl-desc">บันทึก MA รายรอบ: อุปกรณ์ OK/เปลี่ยน/ซ่อม (comma-separated), FW, ช่าง, รอบที่</div></div></div>
        <div class="tbl-item"><span class="tbl-name">repairs</span><div><div class="tbl-desc">ประวัติซ่อม: อาการ, การวินิจฉัย, สถานะซ่อม (received/in_progress/done/returned)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">deployments</span><div><div class="tbl-desc">ประวัติส่งเครื่องให้ลูกค้า: วันเริ่ม-สิ้นสุด, ประเภท (rental/sale/install)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">customers</span><div><div class="tbl-desc">ทะเบียนลูกค้า: ชื่อ, สาขา, บริษัทรักษาความปลอดภัย, เบอร์โทร</div></div></div>
        <div class="tbl-item"><span class="tbl-name">parts</span><div><div class="tbl-desc">อะไหล่: รหัส, ชื่อ, จำนวน stock (คำนวณสะสม), แจ้งเตือนต่ำ, ผู้จำหน่าย</div></div></div>
        <div class="tbl-item"><span class="tbl-name">part_movements</span><div><div class="tbl-desc">การเคลื่อนไหว stock อะไหล่: รับ/เบิก, เชื่อมกับเครื่องที่ใช้ หรือใบซ่อม</div></div></div>
        <div class="tbl-item"><span class="tbl-name">bom_items</span><div><div class="tbl-desc">Bill of Materials: อะไหล่ต่อรุ่น (product → parts) จำนวน/เครื่อง</div></div></div>
        <div class="tbl-item"><span class="tbl-name">stock_movements</span><div><div class="tbl-desc">ประวัติเปลี่ยนสถานะเครื่อง in/out (เก็บไว้เพื่อ audit)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">spare_loans</span><div><div class="tbl-desc">บันทึกยืม-คืนเครื่องสำรอง (เชื่อมกับ repairs ถ้ายืมแทนเครื่องเสีย)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">product_field_config</span><div><div class="tbl-desc">config ฟิลด์บันทึกแต่ละรุ่น: ชื่อฟิลด์, ชนิด (component/extra/text), dropdown options, context (production/ma/update)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">users</span><div><div class="tbl-desc">ผู้ใช้งาน: username, password_hash (bcrypt), display_name, role, LINE UID</div></div></div>
        <div class="tbl-item"><span class="tbl-name">notifications</span><div><div class="tbl-desc">แจ้งเตือน: MA ครบกำหนด, เครื่องเช่าเกินกำหนด, อะไหล่ต่ำ</div></div></div>
        <div class="tbl-item"><span class="tbl-name">site_settings</span><div><div class="tbl-desc">key-value store: สีธีม, โลโก้, ชื่อแอป, เมนูหลังบ้าน, config ย้าย online</div></div></div>
      </div>
    </div>
    <!-- biton_stockparts -->
    <div>
      <div class="db-box" style="margin-bottom:12px">
        <div class="db-box-head secondary">📦 biton_stockparts &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ฐานทีมอะไหล่ — เข้าถึง 1 ตาราง)</span></div>
        <div class="db-box-body">
          <div class="tbl-item"><span class="tbl-name">stock</span><div><div class="tbl-desc">ทะเบียน serial number สินค้าทุกเครื่อง: serial_number (PK), model, timestamp (วันผลิต), create_name (ผู้บันทึก), id (รหัสชุด), active (1/0)</div><div class="tbl-rows">⚠️ เค้าโครงของทีมอะไหล่ — ห้าม ALTER เพิ่มฟิลด์</div></div></div>
        </div>
      </div>
      <div class="section-note">
        <b>กติกาการเชื่อม Cross-Database</b><br>
        — JOIN ข้าม DB ผ่าน PHP mysqli <b>ไม่ work</b> เสมอไป → แก้ด้วยการ query แยก 2 ครั้งแล้วรวมผลใน PHP<br>
        — การเขียนเข้า <span class="inline-code">biton_stockparts.stock</span> ทำผ่าน <span class="inline-code">share_upsert_asset()</span> / <span class="inline-code">share_delete_asset()</span> ซึ่ง fail-soft (ใช้ <span class="inline-code">@</span> suppression) เพื่อไม่ให้ crash ระบบหลัก
      </div>
      <div class="section-note" style="background:#fff7ed; border-color:#f59e0b; color:#92400e">
        <b>Sync อัตโนมัติ</b><br>
        ทุกครั้งที่สร้าง/แก้ไข/ลบ asset ในระบบ → <span class="inline-code">share_upsert_asset()</span> / <span class="inline-code">share_delete_asset()</span> ถูกเรียกอัตโนมัติ ทำให้ <span class="inline-code">biton_stockparts.stock</span> อัปเดตตลอด
      </div>
      <div class="section-note" style="background:#f0fdf4; border-color:#10b981; color:#065f46">
        <b>สถานะข้อมูล (ณ 2026-07-13)</b><br>
        — 18,356 แถว ใน biton_stockparts.stock<br>
        — active=1: 466 เครื่อง (เครื่องผลิตจากระบบนี้)<br>
        — active=0: 17,890 เครื่อง (นำเข้าจาก legacy / AppSheet)<br>
        — 0 ค่าว่างทั้ง create_name และ timestamp
      </div>
    </div>
  </div>
</div>

<!-- ③ หน้าระบบ -->
<div class="panel doc-section" id="pages">
  <h2>③ หน้าระบบ — แต่ละหน้าทำอะไร อ่านข้อมูลจากไหน</h2>
  <div class="page-grid">
    <div class="page-card" style="border-top:3px solid #ec4899">
      <div class="pfile">index.php</div>
      <div class="pdesc">Dashboard หลัก: stat tiles (เครื่องทั้งหมด/ใหม่/เช่า/สำรอง), กราฟรายเดือน/ปี, โดนัท, รายรุ่น</div>
      <div class="pread">อ่าน: assets, products, (AJAX→dashboard_data.php)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #3b82f6">
      <div class="pfile">assets.php</div>
      <div class="pdesc">รายการเครื่องทั้งหมด: ค้นหา, filter สถานะ/รุ่น, paginate 50/หน้า, AJAX suggest</div>
      <div class="pread">อ่าน: assets JOIN products LEFT JOIN customers</div>
    </div>
    <div class="page-card" style="border-top:3px solid #3b82f6">
      <div class="pfile">asset.php</div>
      <div class="pdesc">รายละเอียด 1 เครื่อง: ข้อมูล, ชิ้นส่วน, timeline, แจ้งเตือน SD/Battery; แก้ไข/ลบ/เปลี่ยนสถานะ</div>
      <div class="pread">อ่าน: assets, production_records, update_logs, ma_records, part_movements, repairs, asset_components</div>
    </div>
    <div class="page-card" style="border-top:3px solid #10b981">
      <div class="pfile">asset_new.php</div>
      <div class="pdesc">บันทึกผลิตใหม่: เลือกรุ่น, วันที่, ชิ้นส่วน, FW, ผู้ประกอบ; รองรับหลายเครื่อง (ถ้า generated-code)</div>
      <div class="pread">อ่าน: products, parts (BOM), product_field_config / เขียน: assets, production_records, asset_components, part_movements</div>
    </div>
    <div class="page-card" style="border-top:3px solid #8b5cf6">
      <div class="pfile">ma.php</div>
      <div class="pdesc">บันทึก MA: ค้นหาเครื่องด้วยรหัส/S/N, เลือกอุปกรณ์ OK/เปลี่ยน/ซ่อม, FW, ช่าง</div>
      <div class="pread">อ่าน: assets, products, product_field_config (MA pool), ma_records / เขียน: ma_records, (update assets.status, current_fw_version)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #06b6d4">
      <div class="pfile">update_new.php</div>
      <div class="pdesc">บันทึกอัปเดต FW/HW: เลือกชิ้นส่วน, เวอร์ชันก่อน-หลัง, รูปถ่าย (สูงสุด 2 รูป)</div>
      <div class="pread">อ่าน: assets, product_field_config (update) / เขียน: update_logs, (assets.current_fw_version), (asset_components)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #06b6d4">
      <div class="pfile">updates.php</div>
      <div class="pdesc">รายการอัปเดต FW/HW ทั้งหมด: paginate, ค้นหา, filter</div>
      <div class="pread">อ่าน: update_logs JOIN assets JOIN products</div>
    </div>
    <div class="page-card" style="border-top:3px solid #f59e0b">
      <div class="pfile">repairs.php</div>
      <div class="pdesc">รายการซ่อมทั้งหมด: filter ลูกค้า/ค้นหา (นำเข้าจาก AppSheet legacy)</div>
      <div class="pread">อ่าน: repairs JOIN assets JOIN products LEFT JOIN customers (ROW_NUMBER() นับครั้งซ่อมต่อเครื่อง)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #10b981">
      <div class="pfile">parts.php</div>
      <div class="pdesc">คลัง stock อะไหล่: รับเข้า/เบิกออก/เบิกซ่อม, BOM, แจ้งเตือน stock ต่ำ; กดดูว่าเครื่องไหนใช้อะไหล่นี้</div>
      <div class="pread">อ่าน: parts, part_movements, bom_items / เขียน: parts (stock_qty±), part_movements</div>
    </div>
    <div class="page-card" style="border-top:3px solid #14b8a6">
      <div class="pfile">customers.php / customer.php</div>
      <div class="pdesc">ทะเบียนลูกค้า: จำนวนเครื่อง/deployment/ซ่อม; รายละเอียดลูกค้า 1 ราย</div>
      <div class="pread">อ่าน: customers, assets, deployments, repairs</div>
    </div>
    <div class="page-card" style="border-top:3px solid #6366f1">
      <div class="pfile">products.php</div>
      <div class="pdesc">รายการรุ่นสินค้า: แก้ไข prefix/era/icon/โหมด; จำนวนเครื่องต่อรุ่น; เพิ่มรุ่นใหม่</div>
      <div class="pread">อ่าน: products JOIN assets (COUNT) / เขียน: products</div>
    </div>
    <div class="page-card" style="border-top:3px solid #6366f1">
      <div class="pfile">settings.php</div>
      <div class="pdesc">หลังบ้าน: config ฟิลด์รายรุ่น (production/MA/update), reset กลับอัตโนมัติ</div>
      <div class="pread">อ่าน: product_field_config, products / เขียน: product_field_config</div>
    </div>
    <div class="page-card" style="border-top:3px solid #ec4899">
      <div class="pfile">appearance.php</div>
      <div class="pdesc">ปรับแต่งหน้าตา: ชื่อแอป, โลโก้, 5 สีธีม, ตำแหน่ง sidebar, เมนู (ไอคอน/ลำดับ/ซ่อน)</div>
      <div class="pread">อ่าน+เขียน: site_settings</div>
    </div>
    <div class="page-card" style="border-top:3px solid #9d174d">
      <div class="pfile">share.php</div>
      <div class="pdesc">ทะเบียนสินค้า (stock): ดู/เพิ่ม/แก้ไข/ลบ ใน biton_stockparts.stock, Import CSV, Sync จากระบบ</div>
      <div class="pread">อ่าน+เขียน: biton_stockparts.stock (query แยกจาก bit_production)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">scan.php</div>
      <div class="pdesc">สแกน QR/บาร์โค้ดด้วย camera → redirect ไปหน้าเครื่อง</div>
      <div class="pread">ไม่อ่าน DB (client-side scan ด้วย html5-qrcode)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">users.php / profile.php</div>
      <div class="pdesc">จัดการผู้ใช้ (admin): เพิ่ม/แก้ role/รีเซ็ต password, เปลี่ยน password ตัวเอง</div>
      <div class="pread">อ่าน+เขียน: users</div>
    </div>
  </div>
</div>

<!-- ④ วงจรชีวิตเครื่อง -->
<div class="panel doc-section" id="lifecycle">
  <h2>④ วงจรชีวิตของเครื่อง — บันทึกอย่างไร เก็บที่ไหน</h2>

  <h3>🏭 การสร้างเครื่องใหม่ (asset_new.php → create_produced_asset)</h3>
  <div class="section-note" style="margin-bottom:10px">
    <b>โหมดสร้างรหัส 2 แบบ:</b><br>
    • <b>generated</b>: ระบบสร้างรหัสอัตโนมัติ <span class="inline-code">{prefix}{YY}{MM}{NNNN}</span> — ใช้ MySQL <span class="inline-code">GET_LOCK()</span> ล็อกก่อน query MAX(running_no) เพื่อป้องกัน race condition (รองรับ batch หลายเครื่องพร้อมกัน)<br>
    • <b>factory_serial</b>: ใช้ S/N จากโรงงานเป็นรหัสเครื่อง (ต้อง unique), 1 form = 1 เครื่อง
  </div>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body"><b>เลือกรุ่น + วันที่ผลิต</b> → ระบบโหลดฟิลด์ตาม product_field_config (หรือ auto-derive จากประวัติถ้ายังไม่ config)<div class="flow-writes"><span class="flow-write">อ่าน: products, product_field_config, parts (BOM)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><b>บันทึก BOM template</b> (ถ้าแก้ไข) — ลบ bom_items เก่าแล้ว INSERT ใหม่<div class="flow-writes"><span class="flow-write">เขียน: bom_items</span></div></div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body"><b>create_produced_asset()</b> — INSERT เข้า assets (asset_code, product_id, produced_at, status='new', created_by) แล้วเรียก share_upsert_asset()<div class="flow-writes"><span class="flow-write">เขียน: assets</span><span class="flow-write">sync: biton_stockparts.stock (active=1)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body"><b>บันทึก production_records</b> — ผู้ประกอบ, FW ณ เวลาผลิต, checklist, ฟิลด์พิเศษ (JSON)<div class="flow-writes"><span class="flow-write">เขียน: production_records (extra_json)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">5</div><div class="flow-body"><b>บันทึกชิ้นส่วน</b> — ฟิลด์ชนิด 'component' (Display, HUB ฯลฯ) → upsert ทีละชิ้น<div class="flow-writes"><span class="flow-write">เขียน: asset_components (ON DUPLICATE KEY UPDATE)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">6</div><div class="flow-body"><b>เบิกอะไหล่ตาม BOM</b> — ลด parts.stock_qty + บันทึก part_movements (mode='ผลิต', ref_asset_id)<div class="flow-writes"><span class="flow-write">เขียน: part_movements, parts.stock_qty−</span></div></div></div>
  </div>

  <h3>✏️ การแก้ไขเครื่อง (asset.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body"><b>UPDATE assets</b>: asset_code, product_id, produced_at, lot_label, note<div class="flow-writes"><span class="flow-write">เขียน: assets</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><b>share_upsert_asset(id, oldCode)</b>: ถ้ารหัสเปลี่ยน → ลบแถวเก่าออกจาก stock แล้ว INSERT แถวใหม่<div class="flow-writes"><span class="flow-write">sync: biton_stockparts.stock</span></div></div></div>
  </div>

  <h3>🗑️ การลบเครื่อง (asset.php — admin เท่านั้น)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body"><b>NULL ref_asset_id</b> ใน part_movements ก่อน เพื่อ FK ไม่ติด<div class="flow-writes"><span class="flow-write">เขียน: part_movements.ref_asset_id=NULL</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><b>ลบ spare_loans</b> ที่เชื่อมกับเครื่องนี้<div class="flow-writes"><span class="flow-write del">ลบ: spare_loans</span></div></div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body"><b>DELETE assets</b> → CASCADE ลบทุกตารางที่เชื่อมกัน อัตโนมัติ<div class="flow-writes"><span class="flow-write del">Cascade ลบ: production_records, update_logs, ma_records, asset_components, deployments, stock_movements</span></div></div></div>
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body"><b>share_delete_asset(code)</b>: ลบออกจาก stock ด้วย<div class="flow-writes"><span class="flow-write del">sync: biton_stockparts.stock ลบแถวออก</span></div></div></div>
  </div>

  <h3>🔄 การเปลี่ยนสถานะ (asset.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body">UPDATE <span class="inline-code">assets.status</span> → 'new' / 'rental' / 'spare'<div class="flow-writes"><span class="flow-write">เขียน: assets.status</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body">INSERT <span class="inline-code">stock_movements</span>: direction='in' ถ้าคืนคลัง, 'out' ถ้าออกไปลูกค้า<div class="flow-writes"><span class="flow-write">เขียน: stock_movements</span></div></div></div>
  </div>

  <h3>🔧 การบันทึก MA (ma.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body">ค้นหาเครื่องด้วย รหัส หรือ factory_serial → ดึง asset_id<div class="flow-writes"><span class="flow-write">อ่าน: assets</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body">คำนวณ ma_round = MAX(ma_round)+1 ของเครื่องนั้น<div class="flow-writes"><span class="flow-write">อ่าน: ma_records</span></div></div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body">INSERT ma_records: ok_items, replace_items, repair_items เก็บเป็น comma-separated string<div class="flow-writes"><span class="flow-write">เขียน: ma_records</span></div></div></div>
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body">ถ้ามี FW ใหม่ → UPDATE assets.current_fw_version<div class="flow-writes"><span class="flow-write">เขียน: assets.current_fw_version (ถ้ามีการเปลี่ยน)</span></div></div></div>
  </div>

  <h3>⬆️ การบันทึก FW/HW Update (update_new.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body">INSERT update_logs: update_type, component_name, old/new value, รูปถ่าย (สูงสุด 2 ไฟล์ → saves ใน uploads/)<div class="flow-writes"><span class="flow-write">เขียน: update_logs</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body">ถ้า FW → UPDATE assets.current_fw_version; ถ้า HW → Upsert asset_components (ชิ้นส่วนปัจจุบัน)<div class="flow-writes"><span class="flow-write">เขียน: assets.current_fw_version และ/หรือ asset_components</span></div></div></div>
  </div>

  <h3>🔩 การรับ/เบิกอะไหล่ (parts.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body">INSERT part_movements: direction in/out, qty, mode (ผลิต/ซ่อม/สั่งซื้อ), ref_asset_id (ถ้าเบิกผลิต)<div class="flow-writes"><span class="flow-write">เขียน: part_movements</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body">UPDATE parts.stock_qty ± qty → เป็นจำนวนสะสมปัจจุบัน<div class="flow-writes"><span class="flow-write">เขียน: parts.stock_qty</span></div></div></div>
  </div>
</div>

<!-- ⑤ รายละเอียดตาราง -->
<div class="panel doc-section" id="tables">
  <h2>⑤ รายละเอียดคอลัมน์ตารางสำคัญ</h2>

  <h3>🗃️ assets (ตารางกลาง)</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>คอลัมน์</th><th>ประเภท</th><th>หมายเหตุ</th></tr>
    <tr><td class="col-pk col-key">id</td><td>BIGINT UNSIGNED PK AUTO</td><td>รหัสเครื่องภายใน</td></tr>
    <tr><td class="col-key">asset_code</td><td>VARCHAR(50) UNIQUE</td><td>รหัสเครื่อง (generated หรือ factory S/N)</td></tr>
    <tr><td>factory_serial</td><td>VARCHAR(100) NULL</td><td>S/N จากโรงงาน (ถ้าต่างจาก asset_code)</td></tr>
    <tr><td class="col-fk col-key">product_id</td><td>BIGINT UNSIGNED NOT NULL</td><td>FK → products(id)</td></tr>
    <tr><td>running_no</td><td>INT UNSIGNED NULL</td><td>ตัวเลขวิ่งใน รหัส (ใช้ generated mode)</td></tr>
    <tr><td>produced_at</td><td>DATE NULL</td><td>วันที่ผลิต (ขึ้น YY/MM ในรหัส)</td></tr>
    <tr><td>status</td><td>ENUM('new','rental','spare')</td><td>new=คลัง, rental=เช่า, spare=สำรอง</td></tr>
    <tr><td class="col-fk">current_customer_id</td><td>BIGINT UNSIGNED NULL</td><td>FK → customers(id)</td></tr>
    <tr><td>current_fw_version</td><td>VARCHAR(50) NULL</td><td>FW ล่าสุดที่ทราบ (อัปเดตตอน update/MA)</td></tr>
    <tr><td>ma_interval_months</td><td>TINYINT UNSIGNED NULL</td><td>ระยะ MA เป็นเดือน</td></tr>
    <tr><td>next_ma_date</td><td>DATE NULL</td><td>วัน MA ครั้งถัดไป</td></tr>
    <tr><td class="col-fk">created_by</td><td>BIGINT UNSIGNED NULL</td><td>FK → users(id)</td></tr>
  </table>
  </div>

  <h3 style="margin-top:18px">📝 production_records</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>คอลัมน์</th><th>ประเภท</th><th>หมายเหตุ</th></tr>
    <tr><td class="col-pk col-key">id</td><td>BIGINT UNSIGNED PK AUTO</td><td></td></tr>
    <tr><td class="col-fk col-key">asset_id</td><td>BIGINT UNSIGNED</td><td>FK → assets(id) CASCADE DELETE</td></tr>
    <tr><td>recorded_at</td><td>DATETIME</td><td>วันเวลาที่บันทึก</td></tr>
    <tr><td>made_by / assembly_by</td><td>VARCHAR(100) NULL</td><td>ชื่อผู้ประกอบ (text-field)</td></tr>
    <tr><td>fw_version</td><td>VARCHAR(50) NULL</td><td>FW ณ เวลาผลิต</td></tr>
    <tr><td>problems_found / fix</td><td>TEXT NULL</td><td>ปัญหาที่พบ / วิธีแก้ไข</td></tr>
    <tr><td>checklist</td><td>TEXT NULL</td><td>รายการ checklist ที่ตรวจ</td></tr>
    <tr><td>qc_passed</td><td>TINYINT(1) NULL</td><td>NULL=ยังไม่ผ่าน QC, 1=ผ่าน</td></tr>
    <tr><td>extra_json</td><td>JSON NULL</td><td>ฟิลด์พิเศษตามรุ่น เช่น Type Reader, Battery By (key-value JSON)</td></tr>
    <tr><td class="col-fk">user_id</td><td>BIGINT UNSIGNED NULL</td><td>FK → users(id)</td></tr>
  </table>
  </div>

  <h3 style="margin-top:18px">🔧 ma_records</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>คอลัมน์</th><th>ประเภท</th><th>หมายเหตุ</th></tr>
    <tr><td class="col-pk col-key">id</td><td>BIGINT UNSIGNED PK AUTO</td><td></td></tr>
    <tr><td class="col-fk col-key">asset_id</td><td>BIGINT UNSIGNED</td><td>FK → assets(id) CASCADE</td></tr>
    <tr><td>ma_round</td><td>INT UNSIGNED NULL</td><td>รอบที่ (นับต่อเนื่องต่อเครื่อง)</td></tr>
    <tr><td>visited_at</td><td>DATE</td><td>วันที่ทำ MA</td></tr>
    <tr><td>result</td><td>ENUM('ok','replace','repair') NULL</td><td>ผลโดยรวม</td></tr>
    <tr><td>ok_items</td><td>TEXT NULL</td><td>อุปกรณ์ที่ตรวจ OK (comma-separated)</td></tr>
    <tr><td>replace_items</td><td>TEXT NULL</td><td>อุปกรณ์ที่เปลี่ยน (comma-separated)</td></tr>
    <tr><td>repair_items</td><td>TEXT NULL</td><td>อุปกรณ์ที่ซ่อม (comma-separated)</td></tr>
    <tr><td>versions_json</td><td>JSON NULL</td><td>Snapshot legacy จาก AppSheet (OK/Replace/Repair keys)</td></tr>
    <tr><td>fw_version</td><td>VARCHAR(50) NULL</td><td>FW ณ ทำ MA</td></tr>
    <tr><td>done_by</td><td>VARCHAR(100) NULL</td><td>ชื่อช่าง</td></tr>
  </table>
  </div>

  <h3 style="margin-top:18px">📦 biton_stockparts.stock</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>คอลัมน์</th><th>ประเภท</th><th>ที่มาของข้อมูล</th></tr>
    <tr><td class="col-pk col-key">serial_number</td><td>VARCHAR UNIQUE PK</td><td>= assets.asset_code</td></tr>
    <tr><td>model</td><td>VARCHAR</td><td>= products.name</td></tr>
    <tr><td>timestamp</td><td>DATETIME</td><td>= production_records.recorded_at (แรกสุด) หรือ assets.produced_at</td></tr>
    <tr><td>create_name</td><td>VARCHAR NOT NULL</td><td>= production_records.made_by (แรกสุด)</td></tr>
    <tr><td>id</td><td>INT (ไม่ใช่ PK)</td><td>รหัสชุดบันทึก (DENSE_RANK ตาม produced_at)</td></tr>
    <tr><td>setup_id</td><td>INT NULL</td><td>ไม่ได้ใช้จากระบบนี้</td></tr>
    <tr><td>active</td><td>TINYINT</td><td>1 = เครื่องผลิตจากระบบนี้; 0 = legacy/import</td></tr>
  </table>
  </div>

  <h3 style="margin-top:18px">⚙️ product_field_config (ควบคุมฟิลด์ฟอร์มบันทึก)</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>คอลัมน์</th><th>ประเภท</th><th>หมายเหตุ</th></tr>
    <tr><td class="col-fk col-key">product_id</td><td>BIGINT UNSIGNED</td><td>FK → products(id)</td></tr>
    <tr><td>context</td><td>ENUM('production','ma','update')</td><td>ฟิลด์นี้ใช้ในฟอร์มไหน</td></tr>
    <tr><td>field_name</td><td>VARCHAR(150)</td><td>ชื่อฟิลด์ เช่น "Display", "Battery By"</td></tr>
    <tr><td>field_kind</td><td>VARCHAR(30)</td><td>component = ชิ้นส่วน HW / extra = เก็บใน JSON / text / ma_item</td></tr>
    <tr><td>options_text</td><td>TEXT NULL</td><td>ตัวเลือก dropdown (1 ตัวเลือก/บรรทัด)</td></tr>
    <tr><td>sort_order</td><td>INT</td><td>ลำดับแสดง (drag-reorder ได้)</td></tr>
  </table>
  </div>
  <div class="section-note" style="margin-top:8px">
    <b>Auto-derive:</b> ถ้าไม่มี config ใน product_field_config → ระบบ derive จากประวัติจริง: component จาก asset_components.component_name, extra จาก production_records.extra_json keys, MA pool จาก ma_records.ok/replace/repair_items โดยจัดลำดับตามความถี่
  </div>
</div>

<!-- ⑥ ความสัมพันธ์ตาราง -->
<div class="panel doc-section" id="relations">
  <h2>⑥ ความสัมพันธ์ของตาราง (Foreign Keys &amp; JOINs)</h2>
  <div class="rel-grid">
    <div class="rel-box">
      <b>assets</b> → เชื่อมออกไป
      <ul>
        <li>product_id → <b>products</b>.id</li>
        <li>current_customer_id → <b>customers</b>.id</li>
        <li>created_by → <b>users</b>.id</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>assets</b> ← ถูกอ้างอิง (CASCADE)
      <ul>
        <li>← <b>production_records</b>.asset_id</li>
        <li>← <b>update_logs</b>.asset_id</li>
        <li>← <b>ma_records</b>.asset_id</li>
        <li>← <b>repairs</b>.asset_id</li>
        <li>← <b>deployments</b>.asset_id</li>
        <li>← <b>asset_components</b>.asset_id</li>
        <li>← <b>stock_movements</b>.asset_id</li>
        <li>← <b>spare_loans</b>.spare_asset_id</li>
        <li>← <b>part_movements</b>.ref_asset_id (NULL = ไม่ CASCADE)</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>products</b> ← ถูกอ้างอิง
      <ul>
        <li>← <b>assets</b>.product_id</li>
        <li>← <b>bom_items</b>.product_id (CASCADE)</li>
        <li>← <b>product_field_config</b>.product_id (CASCADE)</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>customers</b> ← ถูกอ้างอิง
      <ul>
        <li>← <b>assets</b>.current_customer_id</li>
        <li>← <b>deployments</b>.customer_id</li>
        <li>← <b>repairs</b>.customer_id</li>
        <li>← <b>spare_loans</b>.customer_id</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>deployments</b>
      <ul>
        <li>asset_id → <b>assets</b>.id (CASCADE)</li>
        <li>customer_id → <b>customers</b>.id</li>
        <li>← <b>ma_records</b>.deployment_id</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>repairs</b>
      <ul>
        <li>asset_id → <b>assets</b>.id (CASCADE)</li>
        <li>customer_id → <b>customers</b>.id</li>
        <li>← <b>spare_loans</b>.repair_id</li>
        <li>← <b>part_movements</b>.repair_id</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>parts</b> ↔ BOM
      <ul>
        <li>← <b>bom_items</b>.part_id (CASCADE)</li>
        <li>← <b>part_movements</b>.part_id</li>
        <li>bom_items: product_id × part_id (UNIQUE)</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>users</b> ← ถูกอ้างอิง
      <ul>
        <li>← production_records.user_id</li>
        <li>← update_logs.user_id</li>
        <li>← ma_records.user_id</li>
        <li>← repairs.technician_id</li>
        <li>← stock_movements.user_id</li>
        <li>← part_movements.user_id</li>
        <li>← notifications.dismissed_by</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>biton_stockparts.stock</b>
      <ul>
        <li>serial_number = <b>assets</b>.asset_code (logical, ไม่ใช่ FK)</li>
        <li>sync ผ่าน PHP: share_upsert/delete_asset()</li>
        <li>ไม่มี JOIN ข้าม DB ใน query → query แยกแล้วรวมใน PHP</li>
      </ul>
    </div>
  </div>
</div>

<!-- ⑦ ฟังก์ชันหลัก -->
<div class="panel doc-section" id="helpers">
  <h2>⑦ ฟังก์ชันหลักใน config.php</h2>
  <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:10px">
    <?php
    $fns = [
      ['db()', 'Singleton: คืน mysqli connection ไปยัง bit_production (lazy init)'],
      ['q($sql, $types, $params)', 'Prepared statement: execute แล้วคืน mysqli_stmt. Die ถ้า prepare ล้มเหลว'],
      ['qr($sql, $types, $params)', 'เหมือน q() แต่คืน result set (mysqli_result) ใช้ fetch_assoc/fetch_row'],
      ['h($s)', 'htmlspecialchars() ป้องกัน XSS ใช้ทุกที่ที่ echo ข้อมูลจาก DB หรือ User'],
      ['create_produced_asset($pid,$date,$serial,$note,$uid)', 'สร้างเครื่องใหม่: generated code (GET_LOCK) หรือ factory_serial; เรียก share_upsert_asset(); คืน [\'code\'=>...] หรือ [\'error\'=>...]'],
      ['share_upsert_asset($assetId, $oldCode)', 'Sync 1 เครื่องไป biton_stockparts.stock (INSERT … ON DUPLICATE KEY UPDATE); ถ้ารหัสเปลี่ยน→ลบแถวเก่าก่อน; fail-soft'],
      ['share_delete_asset($code)', 'DELETE จาก biton_stockparts.stock WHERE serial_number=?; fail-soft'],
      ['can($perm)', 'ตรวจสิทธิ์: admin=ทุกอย่าง; qc=production; technician=update/repair/ma/spare/parts'],
      ['effective_fields($pid, $ctx)', 'ดึง config ฟิลด์ฟอร์ม: ถ้ามี product_field_config ใช้นั้น, ไม่มี → auto-derive จากประวัติ'],
      ['derive_production_fields($pid)', 'Auto-derive ชิ้นส่วนจาก asset_components + extra keys จาก production_records.extra_json'],
      ['derive_ma_pool($pid)', 'Auto-derive รายการ MA จาก ma_records ok/replace/repair_items จัดลำดับตามความถี่'],
      ['part_watch_alerts($assetId, $producedAt)', 'ตรวจ SD Card / Battery Backup RTC: เตือน 22 เดือน, แจ้งเตือน 24 เดือน (ดูจาก ma_records.replace_items)'],
      ['img_url($path)', 'แปลง path ที่เก็บใน DB เป็น URL สาธารณะ (รองรับ legacy AppSheet paths 9 แบบ)'],
      ['save_upload($field,$subdir,$exts)', 'รับ upload ไฟล์ภาพ, ตรวจ type/getimagesize, บันทึกเป็น uploads/{subdir}/{Y/m}/{timestamp_hex}.ext'],
      ['dthai($d)', 'แปลงวันที่เป็น d/m/Y (คืน "-" ถ้า null)'],
      ['setting($key,$default)', 'อ่านค่าจาก site_settings (cached static)'],
      ['set_setting($key,$val)', 'เขียน site_settings (INSERT … ON DUPLICATE KEY UPDATE)'],
      ['nav_effective()', 'Build sidebar nav รวม overrides จาก site_settings.nav_items JSON'],
    ];
    foreach ($fns as $f) {
        echo '<div class="rel-box"><b style="color:#92400e">' . h($f[0]) . '</b><ul><li style="list-style:none; margin-left:0; color:#374151">' . h($f[1]) . '</li></ul></div>';
    }
    ?>
  </div>
</div>

<!-- ⑧ สิทธิ์ผู้ใช้ -->
<div class="panel doc-section" id="permissions">
  <h2>⑧ สิทธิ์ผู้ใช้งาน (Role-Based Access)</h2>
  <div class="perm-grid">
    <div class="perm-box">
      <div class="perm-head" style="background:#1d4ed8">👑 admin</div>
      <div class="perm-list"><ul>
        <li>ทุกฟีเจอร์ทั้งหมด</li>
        <li>ลบเครื่อง / จัดการผู้ใช้</li>
        <li>หลังบ้าน / ทะเบียนสินค้า</li>
        <li>ปรับแต่งธีม / Config ย้าย</li>
      </ul></div>
    </div>
    <div class="perm-box">
      <div class="perm-head" style="background:#065f46">🔬 qc</div>
      <div class="perm-list"><ul>
        <li>บันทึกผลิตใหม่ (production)</li>
        <li>ดู Dashboard, รายการเครื่อง</li>
      </ul></div>
    </div>
    <div class="perm-box">
      <div class="perm-head" style="background:#6d28d9">🔧 technician</div>
      <div class="perm-list"><ul>
        <li>บันทึก MA, FW/HW update</li>
        <li>บันทึกซ่อม</li>
        <li>จัดการอะไหล่ (เบิก/รับ)</li>
        <li>เครื่องสำรอง</li>
      </ul></div>
    </div>
    <div class="perm-box">
      <div class="perm-head" style="background:#92400e">📊 executive</div>
      <div class="perm-list"><ul>
        <li>ดู Dashboard, รายการเครื่อง</li>
        <li>ดู-อย่างเดียว (read-only)</li>
        <li>ไม่มีสิทธิ์บันทึกอะไร</li>
      </ul></div>
    </div>
  </div>
</div>

<!-- ⑨ การนำเข้าข้อมูล -->
<div class="panel doc-section" id="import">
  <h2>⑨ การนำเข้าข้อมูล</h2>
  <h3>🗂️ import_legacy.php (CLI — นำเข้าจาก AppSheet ครั้งแรก)</h3>
  <div class="section-note">รันผ่าน Command Line เท่านั้น (<span class="inline-code">php database/import_legacy.php</span>) — idempotent: ล้างตารางแล้ว reload ใหม่ได้เสมอ</div>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body"><b>อ่านไฟล์ CSV จาก database/staging/</b> — Finish Goods (ผลิต), Update (FW/HW), MA, Repair Display, StockmasterDB</div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><b>Auto-detect รูปแบบวันที่</b> ต่อไฟล์ — โหวตว่าตำแหน่งแรกคือ DD หรือ MM โดยนับค่า &gt;12 ในแต่ละตำแหน่ง แก้ปัญหาที่ AppSheet export วันที่ปนกัน DD/MM และ MM/DD</div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body"><b>บันทึกลง DB ทีละตาราง</b>: users → products → customers → assets+production_records → update_logs → ma_records → repairs → parts+part_movements</div></div>
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body"><b>บันทึก log</b> แถวที่ข้ามหรือมีปัญหา ไว้ที่ database/import_log/*.csv เพื่อตรวจสอบ</div></div>
  </div>

  <h3 style="margin-top:16px">📥 share.php → Import CSV (Web UI)</h3>
  <div class="section-note">Admin กด "Import CSV" บนหน้าทะเบียนสินค้า — นำเข้าเข้า biton_stockparts.stock โดยตรง</div>
  <ul style="font-size:13px; color:#4b5563; margin: 6px 0 0 20px; line-height:1.8">
    <li>คอลัมน์ที่คาดหวัง: timestamp, serial_number, model, id, create_name, setup_id, active</li>
    <li>active Y/y → 1, N/n → 0</li>
    <li>Auto-detect รูปแบบวันที่ per-file เช่นกัน (<span class="inline-code">share_detect_fmt()</span>)</li>
    <li>serial_number ซ้ำ → ข้าม (SKIP) และรายงาน</li>
  </ul>

  <h3 style="margin-top:16px">🔄 share.php → Sync จากระบบ (Web UI)</h3>
  <ul style="font-size:13px; color:#4b5563; margin: 6px 0 0 20px; line-height:1.8">
    <li>ดึงเครื่องทุกเครื่องจาก assets ที่ยังไม่มีใน stock</li>
    <li>ใช้ <span class="inline-code">INSERT IGNORE</span> ป้องกัน duplicate</li>
    <li>ใช้ <span class="inline-code">DENSE_RANK()</span> จัด batch id ตาม produced_at</li>
    <li>active = 1 สำหรับทุกเครื่องที่ดึงมาจากระบบ</li>
  </ul>

  <h3 style="margin-top:16px">🛠️ fix_stock_data.php (CLI — เติมข้อมูล stock ที่ขาด)</h3>
  <div class="section-note" style="background:#f0fdf4; border-color:#10b981; color:#065f46">รันครั้งเดียว 2026-07-13 — เติม create_name 7,338 แถว + timestamp 20 แถว จนครบ 100% (0 ค่าว่าง)</div>
  <ul style="font-size:13px; color:#4b5563; margin: 6px 0 0 20px; line-height:1.8">
    <li><b>Source 1</b>: production_records.made_by / assembly_by (7,243 แถว)</li>
    <li><b>Source 2</b>: StockmasterDB.csv คอลัมน์ "ผู้บันทึก" (1 แถว)</li>
    <li><b>Source 3</b>: เพื่อนบ้านรุ่นเดียวกัน หมายเลขใกล้เคียง — เทียบตัวเลขที่ฝังใน serial (91 แถว)</li>
    <li><b>Source 4</b>: ชื่อยอดนิยม fallback (3 แถว)</li>
    <li><b>Timestamp</b>: เพื่อนบ้านรุ่นเดียวกัน serial ใกล้เคียง เช่น B0904001 → 2009-04 (20 แถว)</li>
  </ul>
</div>

<p class="muted" style="font-size:12px; text-align:center; margin-top:8px">เอกสารนี้สร้างอัตโนมัติจากโครงสร้างระบบ · อัปเดตล่าสุด: <?= date('d/m/Y') ?></p>

<?php page_footer(); ?>
