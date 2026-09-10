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
.doc-section h3 { font-size: 14px; font-weight: 700; margin: 14px 0 6px; color: var(--text-muted, #45506a); }
.doc-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.doc-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
.tag-db1 { background: #dbeafe; color: #1d4ed8; }
.tag-db2 { background: #fce7f3; color: #9d174d; }
.tag-table { background: #f3f4f6; color: var(--text, #374151); border: 1px solid #d1d5db; }
.tag-fn { background: #fef3c7; color: #92400e; }
.db-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; }
@media (max-width: 900px) { .db-grid { grid-template-columns: minmax(0, 1fr); } }
.db-box { border-radius: 10px; overflow: hidden; }
.db-box-head { padding: 12px 16px; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.db-box-head.primary { background: #1d4ed8; color: #fff; }
.db-box-head.secondary { background: #9d174d; color: #fff; }
.db-box-body { border: 1px solid var(--border, #e5e7eb); border-top: 0; border-radius: 0 0 10px 10px; padding: 10px 0; }
.tbl-item { display: flex; align-items: flex-start; gap: 10px; padding: 7px 14px; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
.tbl-item:last-child { border-bottom: 0; }
.tbl-name { font-family: monospace; font-weight: 700; color: var(--text, #374151); min-width: 165px; flex-shrink: 0; }
.tbl-desc { color: #6b7280; line-height: 1.45; }
.tbl-rows { font-size: 11px; color: #9ca3af; margin-top: 2px; }
.flow-steps { counter-reset: step; display: flex; flex-direction: column; gap: 0; }
.flow-step { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border-bottom: 1px dashed var(--border, #e5e7eb); }
.flow-step:last-child { border-bottom: 0; }
.flow-num { counter-increment: step; content: counter(step); width: 26px; height: 26px; border-radius: 50%; background: var(--primary); color: #fff; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
.flow-body { flex: 1; font-size: 13px; line-height: 1.55; }
.flow-body b { color: #1f2937; }
.flow-writes { display: inline-flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.flow-write { font-size: 11px; font-family: monospace; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 4px; }
.flow-write.del { background: #fee2e2; color: #991b1b; }
.rel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 10px; }
.rel-box { background: #f9fafb; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 10px 14px; font-size: 12.5px; }
.rel-box b { font-size: 13px; font-family: monospace; color: #1d4ed8; overflow-wrap: anywhere; }
.rel-box ul { margin: 6px 0 0 16px; color: var(--text-muted, #4b5563); line-height: 1.7; }
.schema-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.schema-table th { background: #f3f4f6; padding: 6px 10px; text-align: left; border: 1px solid var(--border, #e5e7eb); font-weight: 600; color: var(--text, #374151); }
.schema-table td { padding: 5px 10px; border: 1px solid var(--border, #e5e7eb); color: var(--text, #374151); }
.schema-table tr:hover td { background: #fafafa; }
.col-pk { background: #fef9c3; }
.col-fk { background: #dbeafe; }
.col-key { font-family: monospace; font-weight: 600; }
.page-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 10px; margin-top: 8px; }
.page-card { background: #fff; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 10px 14px; font-size: 12.5px; }
.page-card .pfile { font-family: monospace; font-weight: 700; color: var(--primary); font-size: 13px; }
.page-card .pdesc { color: #6b7280; margin-top: 3px; line-height: 1.45; }
.page-card .pread { font-size: 11px; color: #9ca3af; margin-top: 4px; font-family: monospace; }
.section-note { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 8px 12px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: #1e40af; margin-bottom: 10px; }
.arrow { color: #9ca3af; font-size: 12px; }
.perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 8px; }
.perm-box { border-radius: 8px; overflow: hidden; font-size: 12.5px; }
.perm-head { padding: 7px 12px; font-weight: 700; color: #fff; }
.perm-list { padding: 7px 12px; border: 1px solid var(--border, #e5e7eb); border-top: 0; border-radius: 0 0 8px 8px; }
.perm-list li { color: var(--text-muted, #4b5563); line-height: 1.7; }
.inline-code { font-family: monospace; font-size: 12px; background: #f3f4f6; padding: 1px 6px; border-radius: 4px; color: var(--text, #374151); }
.inline-code { overflow-wrap: anywhere; }
/* บนจอมือถือ: กล่องรายชื่อตารางกับหัวข้อยาว ๆ ต้องหดตามจอได้ ไม่ใช่ดันหน้าให้เลื่อนข้าง */
@media (max-width: 640px) {
  .tbl-item { flex-direction: column; gap: 3px; padding: 8px 12px; }
  .tbl-name { min-width: 0; }
  .db-box-head { flex-wrap: wrap; padding: 10px 12px; }
  .schema-table { font-size: 12px; }
  .schema-table th, .schema-table td { padding: 5px 7px; }
}
</style>

<!-- TOC ด่วน -->
<div class="panel doc-section" style="padding: 14px 18px">
  <b style="font-size:13px">เนื้อหา</b>
  <div style="display:flex; flex-wrap:wrap; gap:6px 16px; margin-top:8px; font-size:13px">
    <a href="#overview">① ภาพรวม</a>
    <a href="#status">② สถานะเครื่อง</a>
    <a href="#databases">③ ฐานข้อมูล</a>
    <a href="#pages">④ หน้าระบบ</a>
    <a href="#lifecycle">⑤ วงจรชีวิตเครื่อง</a>
    <a href="#tables">⑥ รายละเอียดตาราง</a>
    <a href="#relations">⑦ ความสัมพันธ์ตาราง</a>
    <a href="#helpers">⑧ ฟังก์ชันหลัก</a>
    <a href="#permissions">⑨ สิทธิ์ / Login</a>
    <a href="#import">⑩ การนำเข้าข้อมูล</a>
    <a href="#stockstatus">⑪ สถานะสต็อกอะไหล่</a>
    <a href="#search">⑫ ค้นหาอัจฉริยะ</a>
    <a href="#workreport">⑬ สรุปงานรายคน</a>
    <a href="#linenotify">⑭ แจ้งเตือน LINE</a>
    <a href="#mobile">⑮ โหมดมือถือ</a>
    <a href="#cron">⑯ งานอัตโนมัติ (cron)</a>
  </div>
</div>

<!-- ① ภาพรวม -->
<div class="panel doc-section" id="overview">
  <h2>① ภาพรวมระบบ</h2>
  <p style="font-size:13px; color:var(--text-muted, #4b5563); margin-bottom:12px">
    ระบบบันทึกข้อมูลการผลิตและการจัดการสินค้า (Production &amp; Asset Management) พัฒนาด้วย <b>PHP</b> บน <b>AppServ (Windows)</b> ใช้ <b>MySQL</b> ผ่าน <b>mysqli / PDO</b> (prepared statements) · Login ผ่าน <b>SSO bit-online</b> (localhost dev = Tom อัตโนมัติ)
  </p>
  <div class="section-note" style="margin-bottom:12px">
    <b>2 ระบบงานใน monorepo นี้</b><br>
    • <span class="inline-code">finishgoogs_ma_update/</span> — ทะเบียนเครื่อง, บันทึกผลิต, MA, อัปเดต FW/HW, เบิกอะไหล่ต่อเครื่อง<br>
    • <span class="inline-code">parts/</span> — สต็อกอะไหล่ช่าง (รับเข้า / เบิก Set / เบิกรายชิ้น) ใช้ DB <b>biton_tech_parts</b> โดยตรง<br>
    สต็อกจริง single source of truth = <span class="inline-code">biton_tech_parts.products.quantity</span> · Production map ผ่าน <span class="inline-code">parts.stock_code</span><br>
    <b>ต่อฐานข้อมูลรวม 6 ตัว</b> — 3 ตัวที่เราเขียนเองได้ (biton_production, biton_stockparts, biton_tech_parts)
    และอีก 3 ตัวที่ <b>อ่านอย่างเดียว</b> ของทีมอื่น (biton_maintenance งานซ่อม, biton_setup ขาย/เคลม, biton_leasing งานเช่า)
    — 3 ตัวหลัง<b>ต่อไม่ติดได้โดยไม่ทำให้หน้าเว็บล้ม</b> (timeout 3 วินาที คืน null แล้วซ่อนเฉพาะส่วนนั้น)<br>
    <b>เมนู/sidebar ของทั้ง 2 แอปตอนนี้ใช้แหล่งเดียวกัน</b>: อ่าน/เขียนลำดับและการซ่อนเมนูจาก <span class="inline-code">site_settings.nav_items</span> ร่วมกัน ผ่าน <span class="inline-code">shared/ui_icons.php::ui_nav_apply_override()</span> (ปรับที่ appearance.php ฝั่งเดียว มีผลทั้ง 2 แอป)
  </div>
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
      <div style="font-weight:700; margin-top:4px">อะไหล่ / เบิกใช้</div>
      <div class="pdesc">BOM ต่อรุ่น, เบิกอัตโนมัติตอนผลิต/MA, sync กับ biton_tech_parts</div>
    </div>
    <div class="page-card" style="border-left:3px solid #f59e0b">
      <div style="font-size:20px">🛠️</div>
      <div style="font-weight:700; margin-top:4px">ประวัติซ่อม</div>
      <div class="pdesc">ข้อมูล legacy จาก AppSheet — ดูได้ที่ repairs.php</div>
    </div>
    <div class="page-card" style="border-left:3px solid #ec4899">
      <div style="font-size:20px">📊</div>
      <div style="font-weight:700; margin-top:4px">Dashboard</div>
      <div class="pdesc">กราฟผลิตรายเดือน/ปี (เดือนย่อไทย), สถิติรายรุ่น, Drill-down Modal</div>
    </div>
    <div class="page-card" style="border-left:3px solid #6366f1">
      <div style="font-size:20px">📦</div>
      <div style="font-weight:700; margin-top:4px">ทะเบียน S/N (stock)</div>
      <div class="pdesc">sync อัตโนมัติไป biton_stockparts.stock + หน้า share/share_admin</div>
    </div>
    <div class="page-card" style="border-left:3px solid #14b8a6">
      <div style="font-size:20px">📜</div>
      <div style="font-weight:700; margin-top:4px">Activity Log</div>
      <div class="pdesc">บันทึกการใช้งาน Production + Parts, ดู/Export CSV ที่หลังบ้าน</div>
    </div>
    <div class="page-card" style="border-left:3px solid #0ea5e9">
      <div style="font-size:20px">🔍</div>
      <div style="font-weight:700; margin-top:4px">ค้นหาอัจฉริยะ</div>
      <div class="pdesc">ช่องเดียวที่ sidebar ค้นข้าม 14 แหล่งใน 5 ฐาน — ดู <a href="#search">หัวข้อ ⑫</a></div>
    </div>
    <div class="page-card" style="border-left:3px solid #a855f7">
      <div style="font-size:20px">🧑‍🔧</div>
      <div style="font-weight:700; margin-top:4px">สรุปงานรายคน</div>
      <div class="pdesc">รอบ 21–20 ของเดือน รวมงานจาก production + ซ่อม + เช่า — ดู <a href="#workreport">หัวข้อ ⑬</a></div>
    </div>
    <div class="page-card" style="border-left:3px solid #22c55e">
      <div style="font-size:20px">💬</div>
      <div style="font-weight:700; margin-top:4px">แจ้งเตือน LINE</div>
      <div class="pdesc">Flex Message ทั้งแบบทันทีและตามรอบ + ผูกไลน์รายคนผ่าน webhook — ดู <a href="#linenotify">หัวข้อ ⑭</a></div>
    </div>
    <div class="page-card" style="border-left:3px solid #f97316">
      <div style="font-size:20px">📱</div>
      <div style="font-weight:700; margin-top:4px">โหมดมือถือ</div>
      <div class="pdesc">แถบลัดล่างจอ + เป้าสัมผัส 48px เมื่อจอกว้าง ≤640px — ดู <a href="#mobile">หัวข้อ ⑮</a></div>
    </div>
  </div>
</div>

<!-- ② สถานะเครื่อง -->
<div class="panel doc-section" id="status">
  <h2>② สถานะเครื่อง (ผลิตใหม่ / เช่า / สำรอง)</h2>
  <div class="section-note">
    เก็บที่คอลัมน์ <span class="inline-code">assets.status</span> ในฐานข้อมูลหลัก (production DB) · ไม่มีตารางแยก
  </div>
  <div style="overflow-x:auto">
  <table class="schema-table" style="min-width:520px; margin-bottom:12px">
    <tr><th>ค่าใน DB</th><th>แสดงผล (ไทย)</th><th>ความหมาย</th><th>CSS badge</th></tr>
    <tr><td class="col-key">new</td><td>เครื่องใหม่</td><td>อยู่ในคลัง / ผลิตใหม่ยังไม่ออกไปเช่า</td><td><span class="inline-code">st-new</span></td></tr>
    <tr><td class="col-key">rental</td><td>เครื่องเช่า</td><td>ออกไปเช่าลูกค้า</td><td><span class="inline-code">st-rental</span></td></tr>
    <tr><td class="col-key">spare</td><td>เครื่องสำรอง</td><td>เครื่องสำรอง / ยืมทดแทน</td><td><span class="inline-code">st-spare</span></td></tr>
    <tr><td class="col-key">sold</td><td>ขายแล้ว</td><td>เบิกขายออกไปแล้ว — มาจากการเบิกขายใน biton_stockparts</td><td><span class="inline-code">st-sold</span></td></tr>
    <tr><td class="col-key">retired</td><td>เสื่อมสภาพ</td><td>ปลดระวาง ไม่ใช้งานต่อ</td><td><span class="inline-code">st-retired</span></td></tr>
    <tr><td class="col-key">lost</td><td>สูญหาย</td><td>หาไม่เจอ / ลูกค้าทำหาย</td><td><span class="inline-code">st-lost</span></td></tr>
  </table>
  </div>
  <div class="section-note" style="background:#fff7ed; border-color:#f59e0b; color:#92400e; margin-bottom:10px">
    <b>3 สถานะท้ายไม่ได้กดเปลี่ยนเอง — ระบบ sync ให้</b><br>
    <span class="inline-code">includes/asset_status_sync.php</span> อ่านจาก<b>ระบบเช่า</b> (biton_leasing) และ<b>การเบิกขาย</b> (biton_stockparts)
    แล้วตัดสินตามลำดับความสำคัญ <span class="inline-code">sold &gt; rental (เช่าอยู่/MA) &gt; new (รับคืนแล้ว)</span>
    · <b>ไม่ทับ spare</b> ยกเว้นกรณีขายแล้ว<br>
    รันเป็นรอบด้วย <span class="inline-code">cron/sync_asset_status.php</span> (CLI · แนะนำวันละครั้ง เช่น 06:00) — ดู <a href="#cron">หัวข้อ ⑯</a>
  </div>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 0 18px; line-height:1.75">
    <li><b>ประเภท DB:</b> <span class="inline-code">ENUM('new','rental','spare','sold','retired','lost')</span> DEFAULT 'new'</li>
    <li><b>ตอนผลิตใหม่:</b> <span class="inline-code">create_produced_asset()</span> INSERT ด้วย <span class="inline-code">status='new'</span> เสมอ</li>
    <li><b>เปลี่ยนสถานะ:</b> หน้า <span class="inline-code">asset.php</span> (dropdown + บันทึก) หรือ <span class="inline-code">ma.php</span> (เลือกสถานะหลังบันทึก MA)</li>
    <li><b>Dashboard / assets.php:</b> นับ GROUP BY status · filter <span class="inline-code">?status=new|rental|spare</span></li>
    <li><b>Helper:</b> <span class="inline-code">status_th()</span>, <span class="inline-code">status_badge()</span>, <span class="inline-code">status_list()</span> ใน config.php</li>
    <li><b>ทุกครั้งที่สถานะเปลี่ยน จะมีแถวบันทึกไว้เสมอ</b> ใน <span class="inline-code">stock_movements</span>
        (ไม่ใช่ "บางครั้ง") — เก็บว่าใครเปลี่ยน เปลี่ยนจากอะไรเป็นอะไร และเข้าคลัง (<span class="inline-code">in</span>)
        หรือออกจากคลัง (<span class="inline-code">out</span>) · แถวที่ cron sync เขียนเองจะขึ้นต้นเหตุผลว่า
        <span class="inline-code">Sync สถานะ:</span> ทำให้แยกออกจากที่คนกดเองได้</li>
  </ul>
</div>

<!-- ③ ฐานข้อมูล -->
<div class="panel doc-section" id="databases">
  <h2>③ ฐานข้อมูล</h2>
  <p class="section-note">
    ระบบต่อ <b>6 database</b> แบ่งเป็น 2 กลุ่ม<br>
    <b>เขียนได้ (ของเรา):</b> <span class="inline-code">biton_production</span> ฐานหลัก ·
    <span class="inline-code">biton_stockparts</span> ทะเบียน S/N ของทีม stock ·
    <span class="inline-code">biton_tech_parts</span> สต็อกอะไหล่ช่าง (single source of truth ของจำนวนคงเหลือ)<br>
    <b>อ่านอย่างเดียว (ของทีมอื่น — ห้ามแก้ schema หรือเขียนลงไป):</b>
    <span class="inline-code">biton_maintenance</span> งานซ่อม ·
    <span class="inline-code">biton_setup</span> ประวัติขาย/เคลม/ใบส่งมอบ ·
    <span class="inline-code">biton_leasing</span> งานเช่า
  </p>
  <div class="section-note" style="background:#fff7ed; border-color:#f59e0b; color:#92400e">
    <b>3 ฐานอ่านอย่างเดียวต่อไม่ติดได้ — และต้องไม่ทำให้หน้าเว็บล้ม</b><br>
    <span class="inline-code">dbMaintenance()</span> / <span class="inline-code">dbSetup()</span> / <span class="inline-code">dbLeasing()</span>
    ตั้ง connect timeout <b>3 วินาที</b> และ<b>คืน <span class="inline-code">null</span></b> ถ้าต่อไม่ได้ (ไม่ throw)
    ทุกจุดที่เรียกจึงต้องเช็ค null แล้วซ่อนเฉพาะส่วนนั้น
    · เหตุผลที่อ่านค่าความผิดพลาดได้: <span class="inline-code">dbLeasingError()</span> ฯลฯ
  </div>
  <div class="db-grid">
    <!-- biton_production -->
    <div class="db-box">
      <div class="db-box-head primary">🗄️ biton_production &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ฐานหลัก — ระบบผลิต)</span></div>
      <div class="db-box-body">
        <div class="tbl-item"><span class="tbl-name">assets</span><div><div class="tbl-desc">ตารางกลาง: 1 แถว = เครื่อง 1 เครื่อง — รหัส, รุ่น, <b>status</b> (new/rental/spare), FW ล่าสุด, วันผลิต</div></div></div>
        <div class="tbl-item"><span class="tbl-name">products</span><div><div class="tbl-desc">รุ่นสินค้า: รหัส, prefix, โหมดสร้างรหัส (generated/factory_serial), รูปสินค้า</div></div></div>
        <div class="tbl-item"><span class="tbl-name">production_records</span><div><div class="tbl-desc">ประวัติการผลิต: ผู้ประกอบ, FW ณ ผลิต, checklist, ฟิลด์พิเศษ (JSON), QC ผ่าน/ไม่ผ่าน</div></div></div>
        <div class="tbl-item"><span class="tbl-name">asset_components</span><div><div class="tbl-desc">ชิ้นส่วนปัจจุบันของเครื่อง: Display, HUB, Main Board ฯลฯ (1 แถว/ชิ้นส่วน/เครื่อง)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">update_logs</span><div><div class="tbl-desc">ประวัติอัปเดต FW/HW: เวอร์ชันก่อน-หลัง, รูปถ่าย, ช่าง</div></div></div>
        <div class="tbl-item"><span class="tbl-name">ma_records</span><div><div class="tbl-desc">บันทึก MA รายรอบ: อุปกรณ์ OK/เปลี่ยน/ซ่อม (comma-separated), FW, ช่าง, รอบที่</div></div></div>
        <div class="tbl-item"><span class="tbl-name">repairs</span><div><div class="tbl-desc">ประวัติซ่อม: อาการ, การวินิจฉัย, สถานะซ่อม (received/in_progress/done/returned)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">deployments</span><div><div class="tbl-desc">ประวัติส่งเครื่องให้ลูกค้า: วันเริ่ม-สิ้นสุด, ประเภท (rental/sale/install) — legacy</div></div></div>
        <div class="tbl-item"><span class="tbl-name">parts</span><div><div class="tbl-desc">ทะเบียนอะไหล่ใน Production: รหัส, ชื่อ, stock_code (map → tech_parts), icon — จำนวนคงเหลืออ่านจาก biton_tech_parts</div></div></div>
        <div class="tbl-item"><span class="tbl-name">part_movements</span><div><div class="tbl-desc">ประวัติเบิก/คืนอะไหล่ต่อเครื่อง: ref_asset_id, ma_record_id, qty, mode</div></div></div>
        <div class="tbl-item"><span class="tbl-name">bom_items</span><div><div class="tbl-desc">Bill of Materials: อะไหล่ต่อรุ่น (product → parts) จำนวน/เครื่อง</div></div></div>
        <div class="tbl-item"><span class="tbl-name">stock_movements</span><div><div class="tbl-desc">ประวัติเปลี่ยนสถานะเครื่อง in/out (เก็บไว้เพื่อ audit)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">spare_loans</span><div><div class="tbl-desc">บันทึกยืม-คืนเครื่องสำรอง (เชื่อมกับ repairs ถ้ายืมแทนเครื่องเสีย)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">product_field_config</span><div><div class="tbl-desc">config ฟิลด์ต่อรุ่น: production/ma/update + checklist + watch_alert (SD Card/RTC) + fw/lot/made_by</div></div></div>
        <div class="tbl-item"><span class="tbl-name">activity_logs</span><div><div class="tbl-desc">Activity log ร่วม Production + Parts: ผู้ใช้, action, สรุป, รายละเอียด POST (ดูที่ activity_logs.php)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">site_settings</span><div><div class="tbl-desc">key-value: สีธีม, โลโก้, ชื่อแอป, เมนู, ฟอนต์ (ใช้ร่วม parts ผ่าน main_theme)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">customers</span><div><div class="tbl-desc">ข้อมูล legacy (AppSheet) — ยังมี FK ใน assets/repairs แต่<strong>ไม่มีหน้ UI จัดการแล้ว</strong></div></div></div>
        <div class="tbl-item"><span class="tbl-name">users</span><div><div class="tbl-desc">legacy — ปัจจุบัน login ใช้ SSO session profile ไม่ได้ auth จากตารางนี้</div></div></div>
        <div class="tbl-item"><span class="tbl-name">work_people</span><div><div class="tbl-desc">ทะเบียน<b>คนทำงาน</b> สำหรับสรุปงานรายคน: ชื่อที่แสดง, LINE user id + bot ที่ผูก, รหัสผูกบัญชี 8 หลัก + วันหมดอายุ, <span class="inline-code">view_token</span> (เปิดหน้าสรุปของตัวเองได้โดยไม่ต้อง login), เปิด/ปิดรับแจ้งเตือน</div><div class="tbl-rows">สร้างอัตโนมัติด้วย work_people_ensure_schema() — ไม่ต้องรัน SQL มือ</div></div></div>
        <div class="tbl-item"><span class="tbl-name">work_person_aliases</span><div><div class="tbl-desc">ชื่อที่สะกดต่างกันแต่เป็นคนเดียวกัน (เช่น <span class="inline-code">AUI</span> = <span class="inline-code">Aui</span>) — alias เป็น PK เพื่อกันชื่อเดียวไปผูกสองคน</div></div></div>
        <div class="tbl-item"><span class="tbl-name">notification_outbox</span><div><div class="tbl-desc">คิวข้อความ LINE ที่รอส่ง (เก็บ recipient_id รายแถว จึงส่งรายคนได้)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">notification_log</span><div><div class="tbl-desc">ประวัติการส่งจริง — สำเร็จ/ล้มเหลว/ข้อความที่ LINE ตอบกลับ</div></div></div>
        <div class="tbl-item"><span class="tbl-name">notification_dedup</span><div><div class="tbl-desc">กันส่งซ้ำ: เก็บ dedup key + TTL ต่อประเภทเหตุการณ์ (cron รันซ้ำก็ไม่ส่งซ้ำ)</div></div></div>
        <div class="tbl-item"><span class="tbl-name">notification_snapshots</span><div><div class="tbl-desc">ค่าที่ส่งไปครั้งก่อน ใช้เทียบว่ามีอะไรเปลี่ยนพอที่จะส่งใหม่ไหม</div></div></div>
        <div class="tbl-item"><span class="tbl-name">notification_recipients</span><div><div class="tbl-desc">ทะเบียนปลายทาง (กลุ่ม/ห้อง/รายคน) ที่เลือกได้ในหน้าตั้งค่า LINE</div></div></div>
      </div>
    </div>
    <!-- biton_stockparts + tech_parts -->
    <div>
      <div class="db-box" style="margin-bottom:12px">
        <div class="db-box-head secondary">📦 biton_stockparts &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ทะเบียน S/N)</span></div>
        <div class="db-box-body">
          <div class="tbl-item"><span class="tbl-name">stock</span><div><div class="tbl-desc">ทะเบียน serial number สินค้าทุกเครื่อง: serial_number (PK), model, timestamp (วันผลิต), create_name (ผู้บันทึก), id (รหัสชุด), active (1/0)</div><div class="tbl-rows">⚠️ เค้าโครงของทีมอะไหล่ — ห้าม ALTER เพิ่มฟิลด์</div></div></div>
        </div>
      </div>
      <div class="db-box" style="margin-bottom:12px">
        <div class="db-box-head secondary" style="background:#047857">🔩 biton_tech_parts &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(สต็อกอะไหล่ช่าง — Parts app)</span></div>
        <div class="db-box-body">
          <div class="tbl-item"><span class="tbl-name">products</span><div><div class="tbl-desc">อะไหล่: code, name, quantity (คงเหลือจริง), รูป, ราคา</div></div></div>
          <div class="tbl-item"><span class="tbl-name">stock_in / stock_out</span><div><div class="tbl-desc">รับเข้า / เบิกออก (Set หรือรายชิ้น) — จัดการที่ <span class="inline-code">/production/parts/</span></div></div></div>
          <div class="tbl-item"><span class="tbl-name">sets</span><div><div class="tbl-desc">ชุดเบิก (BOM ช่าง) สำหรับเบิกหลายชิ้นพร้อมกัน</div></div></div>
        </div>
      </div>
      <div class="db-box" style="margin-bottom:12px">
        <div class="db-box-head secondary" style="background:#b45309">🛠️ biton_maintenance &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ระบบซ่อม — อ่านอย่างเดียว)</span></div>
        <div class="db-box-body">
          <div class="tbl-item"><span class="tbl-name">transac_repair</span><div><div class="tbl-desc">งานซ่อม 1 แถว = 1 งาน มี<b>ชื่อคน 4 บทบาท</b>คู่กับวันที่คนละช่อง: รับเครื่อง (<span class="inline-code">trp_user_recive_ma</span>), ประเมินราคา (<span class="inline-code">trp_user_rate</span>), ซ่อมเสร็จ (<span class="inline-code">trp_user_ma</span>), ส่งคืน (<span class="inline-code">trp_sendby</span>)</div><div class="tbl-rows">⚠️ ห้ามแก้ code/schema ของระบบซ่อม — เชื่อมผ่าน includes/maintenance_repair_bridge.php เท่านั้น</div></div></div>
        </div>
      </div>
      <div class="db-box" style="margin-bottom:12px">
        <div class="db-box-head secondary" style="background:#4338ca">🧾 biton_setup &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ขาย / เคลม / ส่งมอบ — อ่านอย่างเดียว)</span></div>
        <div class="db-box-body">
          <div class="tbl-item"><span class="tbl-name">ประวัติขาย / เคลม</span><div><div class="tbl-desc">เลขที่เคลม, S/N เดิม-ใหม่, ชื่อลูกค้า/ไซต์, PO, เลขที่สัญญาเช่า — เชื่อมผ่าน <span class="inline-code">includes/setup_sale_history.php</span></div></div></div>
          <div class="tbl-item"><span class="tbl-name">ใบส่งมอบ (Order)</span><div><div class="tbl-desc">S/N ที่ส่งมอบ, PO, บริษัท/แผนก/ผู้ติดต่อ — กดจากผลค้นหาแล้วเปิดที่ระบบต้นทาง</div></div></div>
        </div>
      </div>
      <div class="db-box" style="margin-bottom:12px">
        <div class="db-box-head secondary" style="background:#0f766e">📄 biton_leasing &nbsp;<span style="font-weight:400; font-size:11px; opacity:.85">(ระบบเช่า — อ่านอย่างเดียว)</span></div>
        <div class="db-box-body">
          <div class="tbl-item"><span class="tbl-name">tbl_product</span><div><div class="tbl-desc">ทะเบียนเครื่องเช่า: <span class="inline-code">pro_sn</span>, ชื่อเครื่อง, ผู้ลงทะเบียน + วันที่ (<span class="inline-code">pro_user_add</span>/<span class="inline-code">pro_date</span>)</div></div></div>
          <div class="tbl-item"><span class="tbl-name">tbl_product_ma</span><div><div class="tbl-desc">งาน MA ของเครื่องเช่า: <span class="inline-code">ma_user_add</span> / <span class="inline-code">ma_date</span></div></div></div>
          <div class="tbl-item"><span class="tbl-name">tbl_rent / tbl_rent_product</span><div><div class="tbl-desc">สัญญาเช่า + รายการเครื่องในสัญญา: เลขสัญญา, PO, ไซต์, ผู้ติดต่อ 3 คน/เบอร์ 3 เบอร์</div><div class="tbl-rows">⚠️ tbl_rent_product ไม่มี index บนคอลัมน์ชื่อ — ต้องกรองด้วย cus_id ที่มี index ก่อนเสมอ ไม่งั้นช้า 10 เท่า</div></div></div>
          <div class="tbl-item"><span class="tbl-name">tbl_customer</span><div><div class="tbl-desc">ชื่อลูกค้าฝั่งเช่า (<span class="inline-code">cus_name</span>, <span class="inline-code">cus_sname</span>, <span class="inline-code">ecus_name</span>) — เชื่อมผ่าน includes/rent_ma_bridge.php, rent_product_name_map.php</div></div></div>
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
        <b>ยอด ณ วันสำรวจ 2026-07-13</b> <span style="font-weight:400">(ตัวเลขอ้างอิงเก่า ไม่ได้อัปเดตอัตโนมัติ — ดูของจริงที่ share_admin.php)</span><br>
        — 18,356 แถว ใน biton_stockparts.stock<br>
        — active=1: 466 เครื่อง (เครื่องผลิตจากระบบนี้)<br>
        — active=0: 17,890 เครื่อง (นำเข้าจาก legacy / AppSheet)<br>
        — 0 ค่าว่างทั้ง create_name และ timestamp
      </div>
    </div>
  </div>
</div>

<!-- ④ หน้าระบบ -->
<div class="panel doc-section" id="pages">
  <h2>④ หน้าระบบ — แต่ละหน้าทำอะไร อ่านข้อมูลจากไหน</h2>
  <div class="page-grid">
    <div class="page-card" style="border-top:3px solid #ec4899">
      <div class="pfile">index.php</div>
      <div class="pdesc">Dashboard หลัก: stat tiles (เครื่องทั้งหมด/ใหม่/เช่า/สำรอง), กราฟรายเดือน/ปี, โดนัท, รายรุ่น</div>
      <div class="pread">อ่าน: assets JOIN products (AJAX→dashboard_data.php)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #3b82f6">
      <div class="pfile">assets.php</div>
      <div class="pdesc">รายการเครื่องทั้งหมด: ค้นหา, filter สถานะ/รุ่น, paginate 50/หน้า, AJAX suggest</div>
      <div class="pread">อ่าน: assets JOIN products</div>
    </div>
    <div class="page-card" style="border-top:3px solid #3b82f6">
      <div class="pfile">asset.php</div>
      <div class="pdesc">รายละเอียด 1 เครื่อง: ข้อมูล, ชิ้นส่วน, timeline, แจ้งเตือน SD/Battery; แก้ไข/ลบ/เปลี่ยนสถานะ</div>
      <div class="pread">อ่าน: assets, production_records, update_logs, ma_records, part_movements, repairs, asset_components</div>
    </div>
    <div class="page-card" style="border-top:3px solid #10b981">
      <div class="pfile">asset_new.php</div>
      <div class="pdesc">บันทึกผลิตใหม่: เลือกรุ่น, BOM picker, checklist/watch alerts ตาม settings, รองรับหลายเครื่อง (generated-code)</div>
      <div class="pread">อ่าน: products, parts, product_field_config / เขียน: assets, production_records, asset_components, part_movements + tech_parts เบิก BOM</div>
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
      <div class="pdesc">ประวัติซ่อมรูปแบบเดิม (ตาราง Repair Display ที่นำเข้าจาก AppSheet) — <b>อ่านอย่างเดียว</b> กรองตามลูกค้า/ค้นหาได้ · งานซ่อมปัจจุบันอยู่ที่ระบบซ่อม (biton_maintenance) ซึ่งเราอ่านผ่าน includes/maintenance_repair_bridge.php</div>
      <div class="pread">อ่าน: repairs JOIN assets JOIN products LEFT JOIN customers (legacy)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #10b981">
      <div class="pfile">parts.php</div>
      <div class="pdesc">ทะเบียนอะไหล่ Production + ประวัติเบิกต่อเครื่อง; จำนวนคงเหลืออ่านจาก biton_tech_parts; เบิก/ปรับผ่าน part_stock_bridge</div>
      <div class="pread">อ่าน: parts, part_movements, bom_items, tech_parts (qty) / เขียน: parts, part_movements, biton_tech_parts</div>
    </div>
    <div class="page-card" style="border-top:3px solid #047857">
      <div class="pfile">/production/parts/</div>
      <div class="pdesc">แอปสต็อกอะไหล่ช่างแยก — ใช้ DB biton_tech_parts โดยตรง มี 6 หน้า: products (รายการ+เพิ่มอะไหล่), product-detail (รายอะไหล่), stock-in (รับเข้า), stock-out (เบิก Set), stock-out-item (เบิกรายชิ้น), sets (จัดการ Set)</div>
      <div class="pread">อ่าน+เขียน: biton_tech_parts (products, stock_in, stock_out, sets)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #047857">
      <div class="pfile">parts/pages/history.php</div>
      <div class="pdesc">ประวัติเคลื่อนไหวอะไหล่ — ยุบรวมหน้าที่เคยแยกกัน (รับเข้า/เบิก Set/เบิกรายชิ้น/ประวัติ) เหลือ 2 แท็บ: <span class="inline-code">tab=move</span> (ความเคลื่อนไหวรวม เข้า+ออก) และ <span class="inline-code">tab=sn</span> (ค้นตามเลขอะไหล่/S/N)</div>
      <div class="pread">อ่าน: biton_tech_parts (stock_in, stock_out, stock_out_items)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #047857">
      <div class="pfile">parts/pages/year-end-summary.php</div>
      <div class="pdesc">สรุปยอดสต็อกอะไหล่ปิดปี</div>
      <div class="pread">อ่าน: biton_tech_parts</div>
    </div>
    <div class="page-card" style="border-top:3px solid #6366f1">
      <div class="pfile">products.php</div>
      <div class="pdesc">รายการรุ่นสินค้า: แก้ไข prefix/era/icon/โหมด; จำนวนเครื่องต่อรุ่น; เพิ่มรุ่นใหม่</div>
      <div class="pread">อ่าน: products JOIN assets (COUNT) / เขียน: products</div>
    </div>
    <div class="page-card" style="border-top:3px solid #6366f1">
      <div class="pfile">settings.php</div>
      <div class="pdesc">หลังบ้าน (PIN 9981): config ฟิลด์รายรุ่น, checklist ผลิต, watch alerts SD/RTC, input_mode, reset อัตโนมัติ</div>
      <div class="pread">อ่าน: product_field_config, products / เขียน: product_field_config</div>
    </div>
    <div class="page-card" style="border-top:3px solid #ec4899">
      <div class="pfile">appearance.php</div>
      <div class="pdesc">ปรับแต่งหน้าตา: ชื่อแอป, โลโก้, 5 สีธีม, ตำแหน่ง sidebar, เมนู (ไอคอน/ลำดับ/ซ่อน)</div>
      <div class="pread">อ่าน+เขียน: site_settings</div>
    </div>
    <div class="page-card" style="border-top:3px solid #9d174d">
      <div class="pfile">share_admin.php</div>
      <div class="pdesc">หลังบ้านทะเบียน S/N: เปรียบเทียบ assets ↔ stock, Import CSV, Sync, แก้ไข/ลบ (PIN 9981)</div>
      <div class="pread">อ่าน+เขียน: biton_stockparts.stock + อ่าน assets/products</div>
    </div>
    <div class="page-card" style="border-top:3px solid #14b8a6">
      <div class="pfile">activity_logs.php</div>
      <div class="pdesc">Activity Log ร่วม Production + Parts: filter ผู้ใช้/ระบบ/วันที่, Export CSV (PIN 9981)</div>
      <div class="pread">อ่าน: activity_logs (biton_production)</div>
    </div>
    <div class="page-card" style="border-top:3px solid var(--border-strong, #64748b)">
      <div class="pfile">scan.php</div>
      <div class="pdesc">สแกน QR/บาร์โค้ดด้วย camera → redirect ไปหน้าเครื่อง</div>
      <div class="pread">ไม่อ่าน DB (client-side scan ด้วย html5-qrcode)</div>
    </div>
    <div class="page-card" style="border-top:3px solid var(--border-strong, #64748b)">
      <div class="pfile">share.php</div>
      <div class="pdesc">ทะเบียนสินค้า (stock) แบบอ่าน/ค้นหา — จัดการเต็มรูปแบบอยู่ที่ share_admin.php</div>
      <div class="pread">อ่าน: biton_stockparts.stock</div>
    </div>
    <div class="page-card" style="border-top:3px solid var(--border-strong, #64748b)">
      <div class="pfile">system_doc.php</div>
      <div class="pdesc">เอกสารหลักการทำงานของระบบ (หน้านี้)</div>
      <div class="pread">ไม่อ่าน DB</div>
    </div>
    <div class="page-card" style="border-top:3px solid #0ea5e9">
      <div class="pfile">smart_search.php</div>
      <div class="pdesc">AJAX เบื้องหลังช่องค้นหาที่ sidebar — <span class="inline-code">?ajax=1&amp;q=…</span> คืน JSON ผลจาก 14 แหล่งใน 5 ฐาน (ตรรกะอยู่ที่ includes/smart_search.php)</div>
      <div class="pread">อ่าน: ทั้ง 6 ฐาน (ฐานนอกที่ต่อไม่ติดจะข้ามไป)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #a855f7">
      <div class="pfile">work_report.php</div>
      <div class="pdesc">สรุปงานรายคนต่อรอบเดือน (21 เดือนก่อน – 20 เดือนนี้) ตาราง คน × หมวดงาน · กดตัวเลขดูรายการจริง · ปุ่มส่ง LINE รายคน · จัดการทะเบียนคน/ผูกไลน์</div>
      <div class="pread">อ่าน: production (5 ตาราง) + biton_maintenance + biton_leasing / เขียน: work_people, work_person_aliases, notification_outbox</div>
    </div>
    <div class="page-card" style="border-top:3px solid #a855f7">
      <div class="pfile">my_work.php</div>
      <div class="pdesc">สรุปงานของ “คนเดียว” แบบละเอียด — เปิดจากหน้ารายงาน หรือจากลิงก์ในไลน์ด้วย <span class="inline-code">view_token</span> (ไม่ต้อง login)</div>
      <div class="pread">อ่าน: เหมือน work_report.php แต่กรองเฉพาะคนเดียว</div>
    </div>
    <div class="page-card" style="border-top:3px solid #22c55e">
      <div class="pfile">line_notify_settings.php</div>
      <div class="pdesc">หลังบ้านตั้งค่า LINE (PIN 9981): เปิด/ปิดรายเหตุการณ์, วิธีส่ง (ทันที/ตามเวลา/ทั้งสอง), token, ผู้รับ, โหมดทดสอบ, ปุ่มส่งทดสอบรายแถว, ประวัติการส่ง</div>
      <div class="pread">อ่าน+เขียน: site_settings, notification_recipients, notification_log</div>
    </div>
    <div class="page-card" style="border-top:3px solid #22c55e">
      <div class="pfile">line_webhook.php</div>
      <div class="pdesc"><b>Endpoint สาธารณะ</b> ให้ LINE เรียกเข้ามา — คนทักรหัสผูกบัญชี 8 หลักมา แล้วระบบเก็บ LINE user id ลง work_people · <b>ตรวจลายเซ็น X-Line-Signature ก่อนเสมอ</b> และตอบ HTTP 200 ทุกกรณี (ไม่งั้น LINE ยิงซ้ำรัว)</div>
      <div class="pread">เขียน: work_people.line_user_id / ไม่มี session</div>
    </div>
    <div class="page-card" style="border-top:3px solid #22c55e">
      <div class="pfile">finishgood_shortage_preview.php</div>
      <div class="pdesc">ดูตัวอย่างการ์ด Flex “สินค้าที่ต้องผลิตเพิ่ม” ก่อนปล่อยให้ cron ส่งจริง — ตรวจหน้าตาและตัวเลขได้ก่อน</div>
      <div class="pread">อ่าน: assets, products, biton_stockparts</div>
    </div>
    <div class="page-card" style="border-top:3px solid #6366f1">
      <div class="pfile">settings_bulk.php</div>
      <div class="pdesc">ตั้งค่า<b>ทุกรุ่นในหน้าเดียว</b> (PIN 9981): แผงคำสั่งตั้งค่าหมายเลขสินค้า (Serial/MAC) และค่าที่ต้องเซ็ตซ้ำ ๆ หลายรุ่น</div>
      <div class="pread">อ่าน+เขียน: product_field_config, products</div>
    </div>
    <div class="page-card" style="border-top:3px solid #06b6d4">
      <div class="pfile">update_edit.php</div>
      <div class="pdesc">แก้ไขรายการอัปเดต FW/HW ที่บันทึกไปแล้ว (<span class="inline-code">?id=</span> ของ update_logs)</div>
      <div class="pread">อ่าน+เขียน: update_logs (+ assets.current_fw_version)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #06b6d4">
      <div class="pfile">updates_import.php</div>
      <div class="pdesc">นำเข้าประวัติอัปเดต FW/HW จาก CSV</div>
      <div class="pread">เขียน: update_logs</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">profile.php</div>
      <div class="pdesc">แสดงข้อมูลจาก SSO session profile — ชื่อที่ระบบใช้บันทึกรายการคือ login_name</div>
      <div class="pread">ไม่อ่าน DB</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">server_config.php</div>
      <div class="pdesc">หลังบ้านดูค่าตั้งเซิร์ฟเวอร์/เส้นทางไฟล์ (PIN 9981)</div>
      <div class="pread">อ่าน: ค่า config + สถานะการต่อฐาน</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">healthz.php</div>
      <div class="pdesc">ตรวจสุขภาพระบบ — ใช้เช็คว่าต่อฐานข้อมูลได้ไหม</div>
      <div class="pread">ping ฐานข้อมูล</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">dashboard_data.php · notifications.php · sync_data.php</div>
      <div class="pdesc">AJAX endpoint: modal เจาะลึกจาก dashboard · รายการแจ้งเตือนสำหรับ popup · ซิงก์ stock จากทะเบียนเครื่อง</div>
      <div class="pread">อ่าน: assets/products · เขียน: biton_stockparts.stock (sync_data)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #64748b">
      <div class="pfile">login.php · logout.php</div>
      <div class="pdesc">localhost dev → login เป็น Tom อัตโนมัติ · production → ส่งต่อ SSO bit-online</div>
      <div class="pread">ไม่อ่าน DB</div>
    </div>
    <div class="page-card" style="border-top:3px solid #9ca3af; opacity:.75">
      <div class="pfile">report.php · stock_compare.php · spares.php</div>
      <div class="pdesc"><s>ยกเลิกแล้ว</s> — report ถูกรวมเข้า Dashboard · stock_compare ถูกรวมเข้า share.php · โมดูลยืม-คืนเครื่องสำรองยกเลิก 2026-07-11 (ใช้ระบบ MA แทน) · ทั้ง 3 ไฟล์คง URL ไว้ให้ redirect เท่านั้น</div>
      <div class="pread">—</div>
    </div>    <div class="page-card" style="border-top:3px solid #9ca3af; opacity:.75">
      <div class="pfile">users.php</div>
      <div class="pdesc"><s>legacy</s> — เหลือเป็น stub redirect กลับ index.php พร้อมข้อความ "ระบบผู้ใช้งานภายในถูกปิดแล้ว" ไม่มีลิงก์จากเมนูใดๆ · login ใช้ SSO profile ทั้งหมด</div>
      <div class="pread">(ไม่มี query DB)</div>
    </div>
    <div class="page-card" style="border-top:3px solid #9ca3af; opacity:.75">
      <div class="pfile"><s>customers.php</s></div>
      <div class="pdesc">ลบออกจาก repo แล้ว — ตาราง customers ยังมีใน DB (FK legacy จาก assets/repairs) แต่ไม่มีหน้า UI จัดการอีกต่อไป</div>
      <div class="pread">—</div>
    </div>
  </div>
</div>

<!-- ⑤ วงจรชีวิตเครื่อง -->
<div class="panel doc-section" id="lifecycle">
  <h2>⑤ วงจรชีวิตของเครื่อง — บันทึกอย่างไร เก็บที่ไหน</h2>

  <h3>🏭 การสร้างเครื่องใหม่ (asset_new.php → create_produced_asset)</h3>
  <div class="section-note" style="margin-bottom:10px">
    <b>โหมดสร้างรหัส 2 แบบ:</b><br>
    • <b>generated</b>: ระบบตั้งรหัสให้เอง รูปแบบ <span class="inline-code">{prefix}{YY}{MM}{NNNN}</span><br>
    &nbsp;&nbsp;&nbsp;&nbsp;<b>หาเลขวิ่งตัวถัดไปยังไง</b> — ไล่<b>อ่านตัวเลขท้ายรหัสของเครื่องที่มีอยู่จริง</b>
    (<span class="inline-code">asset_running_scan_for_product()</span>)
    ไม่ได้ถาม <span class="inline-code">MAX(running_no)</span> ตรง ๆ
    เพราะคอลัมน์ <span class="inline-code">running_no</span> เคยมีค่าที่ไม่ตรงกับรหัสจริง ถ้าเชื่อคอลัมน์นั้นจะได้เลขซ้ำ
    · อ่านตัวเลขท้ายรหัสไม่ออกเมื่อไหร่ค่อยหันไปใช้คอลัมน์ <span class="inline-code">running_no</span> แทน<br>
    &nbsp;&nbsp;&nbsp;&nbsp;<b>กันเลขชนกันยังไง</b> — ล็อกด้วย <span class="inline-code">GET_LOCK()</span> ของ MySQL
    ก่อนคำนวณ ถ้ามีคนกดบันทึกพร้อมกันหรือบันทึกทีเดียวหลายเครื่อง จะต่อคิวกันไม่แย่งเลขเดียวกัน<br>
    • <b>factory_serial</b>: ใช้ S/N จากโรงงานเป็นรหัสเครื่อง (ต้อง unique), 1 form = 1 เครื่อง
  </div>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body"><b>เลือกรุ่น + วันที่ผลิต</b> → ระบบโหลดฟิลด์ตาม product_field_config (หรือ auto-derive จากประวัติถ้ายังไม่ config)<div class="flow-writes"><span class="flow-write">อ่าน: products, product_field_config, parts (BOM)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><b>บันทึก BOM template</b> (ถ้าแก้ไข) — ลบ bom_items เก่าแล้ว INSERT ใหม่<div class="flow-writes"><span class="flow-write">เขียน: bom_items</span></div></div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body"><b>create_produced_asset()</b> — INSERT เข้า assets (asset_code, product_id, produced_at, status='new', created_by) แล้วเรียก share_upsert_asset()<div class="flow-writes"><span class="flow-write">เขียน: assets</span><span class="flow-write">sync: biton_stockparts.stock (active=1)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body"><b>บันทึก production_records</b> — ผู้ประกอบ, FW ณ เวลาผลิต, checklist, ฟิลด์พิเศษ (JSON)<div class="flow-writes"><span class="flow-write">เขียน: production_records (extra_json)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">5</div><div class="flow-body"><b>บันทึกชิ้นส่วน</b> — ฟิลด์ชนิด 'component' (Display, HUB ฯลฯ) → upsert ทีละชิ้น<div class="flow-writes"><span class="flow-write">เขียน: asset_components (ON DUPLICATE KEY UPDATE)</span></div></div></div>
    <div class="flow-step"><div class="flow-num">6</div><div class="flow-body"><b>เบิกอะไหล่ตาม BOM</b> — <span class="inline-code">tech_parts_stock_out_by_part_id()</span> ลด quantity ใน biton_tech_parts + INSERT part_movements (mode='ผลิต', ref_asset_id)<div class="flow-writes"><span class="flow-write">เขียน: part_movements</span><span class="flow-write">sync: biton_tech_parts.quantity−</span></div></div></div>
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
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body"><b>เบิกอะไหล่ (ถ้าเลือก)</b> — tech_parts_stock_out + INSERT part_movements mode=MA, ma_record_id<div class="flow-writes"><span class="flow-write">เขียน: part_movements, biton_tech_parts.quantity−</span></div></div></div>
    <div class="flow-step"><div class="flow-num">5</div><div class="flow-body">ถ้ามี FW ใหม่ → UPDATE assets.current_fw_version<div class="flow-writes"><span class="flow-write">เขียน: assets.current_fw_version (ถ้ามีการเปลี่ยน)</span></div></div></div>
  </div>

  <h3>⬆️ การบันทึก FW/HW Update (update_new.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body">INSERT update_logs: update_type, component_name, old/new value, รูปถ่าย (สูงสุด 2 ไฟล์ → saves ใน uploads/)<div class="flow-writes"><span class="flow-write">เขียน: update_logs</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body">ถ้า FW → UPDATE assets.current_fw_version; ถ้า HW → Upsert asset_components (ชิ้นส่วนปัจจุบัน)<div class="flow-writes"><span class="flow-write">เขียน: assets.current_fw_version และ/หรือ asset_components</span></div></div></div>
  </div>

  <h3>🔩 การเบิก/ปรับอะไหล่ (parts.php / part_stock_bridge.php)</h3>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body">INSERT part_movements: direction out, qty, mode, ref_asset_id / ma_record_id (ถ้ามี)<div class="flow-writes"><span class="flow-write">เขียน: part_movements</span></div></div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><span class="inline-code">tech_parts_stock_out_by_part_id()</span> หรือ <span class="inline-code">tech_parts_stock_in_by_part_id()</span> — map ผ่าน <span class="inline-code">parts.stock_code</span> → <span class="inline-code">biton_tech_parts.products.code</span><div class="flow-writes"><span class="flow-write">sync: biton_tech_parts.products.quantity</span></div></div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body">รับเข้าสต็อกจริงทำที่แอป <span class="inline-code">/production/parts/</span> (products.php) — ไม่ใช่ parts.php<div class="flow-writes"><span class="flow-write">เขียน: biton_tech_parts.stock_in</span></div></div></div>
  </div>
</div>

<!-- ⑥ รายละเอียดตาราง -->
<div class="panel doc-section" id="tables">
  <h2>⑥ รายละเอียดคอลัมน์ตารางสำคัญ</h2>

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
    <tr><td>status</td><td>ENUM('new','rental','spare','sold','retired','lost')</td><td>new=คลัง, rental=เช่า, spare=สำรอง, sold=ขายแล้ว, retired=เสื่อมสภาพ, lost=สูญหาย (4 ตัวหลัง sync จากระบบเช่า/stock — ดู includes/asset_status_sync.php)</td></tr>
    <tr><td class="col-fk">current_customer_id</td><td>BIGINT UNSIGNED NULL</td><td>FK → customers(id) — legacy, ไม่มี UI จัดการแล้ว</td></tr>
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
    <tr><td>visited_at</td><td>DATETIME</td><td>วันเวลาเข้า MA</td></tr>
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
    <tr><td>field_kind</td><td>VARCHAR(30)</td><td><b>ฟิลด์นี้เป็นชนิดไหน — เป็นข้อความอิสระ ผู้ใช้พิมพ์เองได้จากหน้า settings</b><br>
      มีบางค่าที่ระบบ<b>จองไว้</b> คือใส่ค่านี้แล้วพฤติกรรมจะเปลี่ยนจริง ๆ ส่วนค่าอื่นที่พิมพ์เองเป็นแค่ป้ายจัดกลุ่ม
      ไม่มีผลอะไรกับระบบ (ในฐานตอนนี้มีทั้ง <span class="inline-code">ประเภทการใช้งาน</span> และ
      <span class="inline-code">ชิ้นส่วนฮาร์ดแวร์</span> ที่พิมพ์เข้ามาเอง)<br><br>
      <b>ค่าที่ระบบจองไว้ — ฟอร์มบันทึกผลิต</b><br>
      <span class="inline-code">component</span> ชิ้นส่วนของเครื่อง (บันทึกลง asset_components) ·
      <span class="inline-code">extra</span> ฟิลด์เสริม (ลง extra_json) ·
      <span class="inline-code">text</span> ข้อความทั่วไป ·
      <span class="inline-code">ผู้ผลิต</span> ตอบเป็นชื่อคนแบบปุ่มกด <b>เลือกได้เฉพาะชื่อที่ตั้งไว้หลังบ้าน พิมพ์เพิ่มเองไม่ได้</b> ·
      <span class="inline-code">checklist</span> · <span class="inline-code">fw</span> ·
      <span class="inline-code">lot</span> · <span class="inline-code">made_by</span><br><br>
      <b>ค่าที่ระบบจองไว้ — ฟอร์ม MA</b><br>
      <span class="inline-code">ma_ok</span> · <span class="inline-code">ma_replace</span> ·
      <span class="inline-code">ma_repair</span> · <span class="inline-code">ma_fw</span> ·
      <span class="inline-code">ma_status</span> · <span class="inline-code">ma_remark</span> ·
      <span class="inline-code">ma_item</span> (แบบเก่า รวมทุกช่องไว้ด้วยกัน)<br><br>
      <b>ค่าที่ระบบจองไว้ — แจ้งเตือนอายุอุปกรณ์</b><br>
      <span class="inline-code">watch_alert</span> เปิดใช้ · <span class="inline-code">watch_alert_cfg</span> ค่าที่ตั้งไว้<br><br>
      <b>ค่าที่ลงท้ายด้วย <span class="inline-code">_off</span> = ตั้งใจปิด ไม่ใช่ยังไม่เคยตั้ง</b><br>
      <span class="inline-code">fw_off</span> · <span class="inline-code">lot_off</span> ·
      <span class="inline-code">made_by_off</span> · <span class="inline-code">product_snippets_off</span> —
      ถ้าไม่มีแถวนี้เลย ระบบจะถือว่ายังไม่เคยตั้งค่าแล้วไปเดาให้เอง ซึ่งคนละความหมายกับ "ปิด"</td></tr>
    <tr><td>input_mode</td><td>VARCHAR(40) NULL</td><td>chip_single_free, chip_multi, text, … — ควบคุม UI ฟิลด์ (settings.php)</td></tr>
    <tr><td>options_text</td><td>TEXT NULL</td><td>ตัวเลือก dropdown (1 ตัวเลือก/บรรทัด) หรือรายการ checklist / รหัส watch alert</td></tr>
    <tr><td>sort_order</td><td>INT</td><td>ลำดับแสดง (drag-reorder ได้)</td></tr>
  </table>
  </div>
  <div class="section-note" style="margin-top:8px">
    <b>รุ่นที่ยังไม่ได้ตั้งค่า ระบบเดาฟิลด์ให้เอง</b><br>
    ถ้ารุ่นไหนไม่มีแถวใน <span class="inline-code">product_field_config</span> เลย ระบบจะไปดู<b>ประวัติที่เคยบันทึกจริง</b>
    ของรุ่นนั้นแล้วสร้างฟิลด์ให้ตามที่เคยใช้ — <b>เรียงตัวที่ใช้บ่อยขึ้นก่อน</b><br>
    • ชิ้นส่วน ← ชื่อชิ้นส่วนที่เคยบันทึกใน <span class="inline-code">asset_components</span><br>
    • ฟิลด์เสริม ← ชื่อคีย์ที่เคยมีใน <span class="inline-code">production_records.extra_json</span><br>
    • รายการ MA ← อุปกรณ์ที่เคยถูกบันทึกว่า OK / เปลี่ยน / ซ่อม ใน <span class="inline-code">ma_records</span><br>
    พอไปตั้งค่าที่หน้า settings แล้ว ค่าที่ตั้งจะแทนที่ตัวที่เดาไว้ทั้งหมด
  </div>
</div>

<!-- ⑦ ความสัมพันธ์ตาราง -->
<div class="panel doc-section" id="relations">
  <h2>⑦ ความสัมพันธ์ของตาราง (Foreign Keys &amp; JOINs)</h2>
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
      <b>parts</b> ↔ BOM / tech_parts
      <ul>
        <li>← <b>bom_items</b>.part_id (CASCADE)</li>
        <li>← <b>part_movements</b>.part_id</li>
        <li>stock_code → <b>biton_tech_parts.products</b>.code (logical, PHP bridge)</li>
        <li>bom_items: product_id × part_id (UNIQUE)</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>activity_logs</b>
      <ul>
        <li>standalone — ไม่ FK ไปตารางอื่น</li>
        <li>system_key = 'production' | 'parts'</li>
        <li>เขียนอัตโนมัติทุก POST (และ flash_set ที่ส่ง logSummary)</li>
      </ul>
    </div>
    <div class="rel-box">
      <b>users</b> ← legacy FK
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

<!-- ⑧ ฟังก์ชันหลัก -->
<div class="panel doc-section" id="helpers">
  <h2>⑧ ฟังก์ชันหลักใน config.php (+ shared)</h2>
  <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:10px">
    <?php
    $fns = [
      ['db()', 'Singleton: คืน mysqli connection ไปยัง biton_production (lazy init)'],
      ['dbStock() / dbParts()', 'ต่อ biton_stockparts (mysqli) และ biton_tech_parts (PDO) — 2 ฐานที่เราเขียนได้'],
      ['dbLeasing() / dbMaintenance() / dbSetup()', 'ต่อฐานอ่านอย่างเดียวของทีมอื่น — timeout 3 วิ, คืน null ถ้าต่อไม่ได้ (ไม่ throw) ทุกจุดที่เรียกต้องเช็ค null'],
      ['dbLeasingError() / dbMaintenanceError() / dbSetupError()', 'ข้อความผิดพลาดล่าสุดของฐานนั้น สำหรับแสดงว่าทำไมส่วนนี้ถึงว่าง'],
      ['q($sql, $types, $params)', 'สั่ง query แบบ prepared statement (ค่าที่ส่งเข้าไปถูกแยกจากตัวคำสั่ง จึงกัน SQL injection) คืน mysqli_stmt · หยุดทำงานทันทีถ้า prepare ไม่ผ่าน'],
      ['qr($sql, $types, $params)', 'เหมือน q() แต่คืนผลลัพธ์มาให้วนอ่านต่อได้เลยด้วย fetch_assoc() / fetch_row()'],
      ['h($s)', 'htmlspecialchars() ป้องกัน XSS ใช้ทุกที่ที่ echo ข้อมูลจาก DB หรือ User'],
      ['require_login()', 'บังคับ SSO session profile (localhost dev → Tom อัตโนมัติ)'],
      ['can($perm) / require_can($perm)', 'ตอนนี้คืน true ให้ทุกคนที่ล็อกอินได้ — ระบบไม่ได้แบ่งสิทธิ์ตามบทบาทแล้ว ที่ยังรับพารามิเตอร์ $perm อยู่ก็เพื่อไม่ต้องไล่แก้จุดที่เรียกทั้งระบบ'],
      ['actor_name()', 'ชื่อผู้ใช้จาก $_SESSION profile สำหรับบันทึก/เบิก/activity log'],
      ['status_th($s) / status_badge($s) / status_list()', 'แปลง/แสดงสถานะเครื่อง new/rental/spare'],
      ['thai_month_short($ym) / thai_month_period_label($ym)', 'ป้ายเดือนย่อไทย + พ.ศ. สำหรับ Dashboard'],
      ['create_produced_asset($pid,$date,$serial,$note,$uid)', 'สร้างเครื่องใหม่ให้สถานะ new เสมอ · ถ้ารุ่นนั้นให้ระบบตั้งรหัสเอง จะล็อกด้วย GET_LOCK ก่อนหาเลขวิ่ง กันเลขชนกัน · เสร็จแล้วส่งต่อให้ share_upsert_asset() ไปลงทะเบียน stock ให้อัตโนมัติ'],
      ['share_upsert_asset($assetId, $oldCode)', 'Sync → biton_stockparts.stock (fail-soft)'],
      ['share_delete_asset($code)', 'DELETE จาก biton_stockparts.stock (fail-soft)'],
      ['tech_parts_stock_out_by_part_id(...)', 'ตัดยอดอะไหล่ในคลังช่าง (includes/part_stock_bridge.php) — เขียนเข้า biton_tech_parts โดยตรง ครอบด้วย transaction และล็อกแถวไว้ กันสองคนเบิกพร้อมกันแล้วยอดเพี้ยน'],
      ['effective_fields($pid, $ctx)', 'คืนรายการฟิลด์ที่ต้องแสดงในฟอร์มของรุ่นนั้น — ใช้ค่าที่ตั้งไว้หลังบ้านก่อน ถ้ายังไม่เคยตั้งจึงเดาจากประวัติที่เคยบันทึกจริง'],
      ['effective_production_checklist($pid)', 'รายการที่ต้องติ๊กตรวจก่อนส่งมอบของรุ่นนั้น — ถ้าไม่ได้ตั้งไว้ จะดึงจากเครื่องล่าสุดของรุ่นเดียวกันมาให้'],
      ['product_watch_alerts_enabled($pid)', 'เปิด/ปิด watch alert SD Card / Battery RTC ต่อรุ่น'],
      ['part_watch_alerts($assetId, $producedAt)', 'คำนวณว่า SD Card / ถ่าน RTC ถึงเวลาเปลี่ยนหรือยัง — หาวันเปลี่ยนล่าสุดจาก 3 ที่แล้วเลือกอันที่ใหม่สุด: บันทึก MA, งานซ่อมในระบบซ่อม, และวันผลิต (ใช้เมื่อไม่เคยเปลี่ยนเลย) · ครบ 22 เดือนเตือนสีส้ม ครบ 24 เดือนเตือนสีแดง · ต้องเปิดใช้ต่อรุ่นก่อนถึงจะเตือน'],
      ['activity_log_write([...])', 'shared/activity_log_core.php — บันทึก activity_logs'],
      ['img_url($path)', 'แปลงที่อยู่ไฟล์รูปที่เก็บใน DB ให้เป็น URL ที่เปิดได้ — รองรับทั้งรูปที่อัปผ่านระบบนี้และรูปเก่าที่ย้ายมาจาก AppSheet ซึ่งเก็บคนละรูปแบบ'],
      ['save_upload($field,$subdir,$exts)', 'รับ upload ภาพ → uploads/{subdir}/'],
      ['dthai($d)', 'วันที่ d/m/Y'],
      ['setting($key) / set_setting($key,$val)', 'อ่าน/เขียน site_settings'],
      ['nav_effective()', 'Build sidebar ฝั่ง production จาก site_settings.nav_items (parts ใช้ shared/ui_icons.php::ui_nav_apply_override() อ่าน settings ชุดเดียวกัน — ทั้ง 2 แอปจึงเห็นเมนูตรงกัน)'],
      ['ui_mobile_bar_html(...)', 'shared/ui_icons.php — แถบลัดล่างจอสำหรับมือถือ (สแกน / เครื่อง / บันทึก MA / อะไหล่) ใช้ร่วมกันทั้ง 2 แอป'],
      ['smart_search_query($q, $limitPerKind=5)', 'includes/smart_search.php — ค้น 14 แหล่งข้าม 5 ฐานในครั้งเดียว คืน array ผลลัพธ์พร้อม kind/href (ฐานที่ต่อไม่ติดถูกข้าม ไม่ทำให้ทั้งช่องค้นหาพัง)'],
      ['asset_status_sync_*()', 'includes/asset_status_sync.php — คิดสถานะเครื่องใหม่จากระบบเช่า + การเบิกขาย ตามลำดับ sold > rental > new (ไม่ทับ spare)'],
      ['work_people_ensure_schema() / work_people_resolve($raw)', 'includes/work_people.php — สร้างตารางทะเบียนคนอัตโนมัติ · แปลงชื่อดิบเป็นรหัสคน (แยก comma + เทียบไม่สนตัวพิมพ์ + ตาม alias)'],
      ['work_summary_cycle($today) / work_summary_for_cycle($from,$to)', 'includes/work_summary.php — คำนวณรอบ 21–20 · รวมยอดงานรายคนจาก 3 ระบบ ยิง query ทีเดียวต่อแหล่งแล้ว group ใน PHP'],
      ['line_notify_dispatch($eventKey, $payload, $opts)', 'shared/line_notify_core.php — เข้าคิวข้อความ (รับ recipient_id + dedup_key จึงส่งรายคนและกันส่งซ้ำได้)'],
      ['line_notify_process_outbox()', 'ส่งคิวที่ค้าง — cron job ทุกตัวเรียกท้ายงาน'],
      ['maintenance_repair_* / rent_ma_* / setup_sale_history_*', 'includes/ — สะพานอ่านข้อมูลจากระบบซ่อม/เช่า/ขาย โดยไม่แตะโค้ดหรือ schema ของระบบเหล่านั้น'],
    ];
    foreach ($fns as $f) {
        echo '<div class="rel-box"><b style="color:#92400e">' . h($f[0]) . '</b><ul><li style="list-style:none; margin-left:0; color:var(--text, #374151)">' . h($f[1]) . '</li></ul></div>';
    }
    ?>
  </div>
</div>

<!-- ⑨ สิทธิ์ผู้ใช้ -->
<div class="panel doc-section" id="permissions">
  <h2>⑨ การ Login และสิทธิ์</h2>
  <div class="section-note" style="margin-bottom:12px">
    <b>Login:</b> SSO bit-online → session <span class="inline-code">$_SESSION['profile']</span> (display name, employee id ฯลฯ)<br>
    <b>Localhost dev:</b> bootstrap เป็น Tom อัตโนมัติถ้ายังไม่มี profile<br>
    <b>Role เก่า (admin/qc/technician):</b> ตาราง users ยังมีใน DB แต่<strong>ไม่ใช้ตัดสินใจสิทธิ์แล้ว</strong> — ทุกคนที่ login ได้ใช้ฟีเจอร์หลักได้<br>
    <b>หลังบ้าน (settings, appearance, share_admin, activity_logs):</b> ต้องปลดล็อก PIN <span class="inline-code">9981</span> หรือชื่อ Tom (ดู <span class="inline-code">includes/settings_gate.php</span>) — <b>appearance.php ย้ายมาอยู่กลุ่มนี้แล้ว</b> (เดิมแค่ require_login) และการปลดล็อกจะ<b>หมดอายุใน 30 นาที</b> (<span class="inline-code">SETTINGS_UNLOCK_TTL</span>, config.php) ต้องใส่ PIN ใหม่หลังจากนั้น<br>
    <b>หน้าที่เปิดได้โดยไม่ต้อง login 2 หน้า:</b>
    <span class="inline-code">line_webhook.php</span> (LINE เรียกเข้ามา — ป้องกันด้วยการตรวจลายเซ็น HMAC-SHA256 ไม่ใช่ session) และ
    <span class="inline-code">my_work.php</span> เมื่อเปิดด้วย <span class="inline-code">view_token</span> ประจำตัว (คนทำงาน 30 ชื่อมีบัญชีในระบบแค่ 9 — กดปุ่มในไลน์แล้วเจอหน้า login ก็เท่ากับดูไม่ได้) · โทเคนเปิดได้เฉพาะสรุปงานของคนคนนั้น และเพิกถอนรายคนได้<br>
    <b>Cron jobs:</b> ไฟล์ใน <span class="inline-code">cron/</span> ถูกล็อกเป็น CLI-only 2 ชั้น — <span class="inline-code">cron/.htaccess</span> บล็อก HTTP access ทั้งหมด และ <span class="inline-code">cron/_bootstrap.php</span> เช็ค <span class="inline-code">PHP_SAPI !== 'cli'</span> → ตอบ 403 ถ้าพยายามเรียกผ่านเว็บ
  </div>
  <div class="perm-grid">
    <div class="perm-box">
      <div class="perm-head" style="background:#1d4ed8">🔐 SSO profile</div>
      <div class="perm-list"><ul>
        <li>Dashboard, ทะเบียนเครื่อง, ผลิต, MA, Update</li>
        <li>อะไหล่ Production, ซ่อม (อ่าน), สแกน QR</li>
        <li>Activity log เขียนอัตโนมัติทุก POST</li>
      </ul></div>
    </div>
    <div class="perm-box">
      <div class="perm-head" style="background:#92400e">🔑 PIN 9981 / Tom</div>
      <div class="perm-list"><ul>
        <li>settings.php — config รุ่น/checklist/watch alerts</li>
        <li>settings_bulk.php — ตั้งค่าทุกรุ่นในหน้าเดียว</li>
        <li>share_admin.php — Sync stock, Import CSV</li>
        <li>activity_logs.php — ดู log + Export CSV</li>
        <li>appearance.php — ธีม/เมนู</li>
        <li>line_notify_settings.php — ตั้งค่าแจ้งเตือน LINE</li>
        <li>server_config.php — ค่าตั้งเซิร์ฟเวอร์</li>
      </ul></div>
    </div>
    <div class="perm-box">
      <div class="perm-head" style="background:#047857">🔩 Parts app</div>
      <div class="perm-list"><ul>
        <li>แอป <span class="inline-code">/production/parts/</span> แยก login SSO เดียวกัน</li>
        <li>จัดการสต็อกจริง biton_tech_parts</li>
        <li>Activity log system_key = 'parts'</li>
      </ul></div>
    </div>
  </div>
</div>

<!-- ⑩ การนำเข้าข้อมูล -->
<div class="panel doc-section" id="import">
  <h2>⑩ การนำเข้าข้อมูล</h2>
  <h3>🗂️ import_legacy.php (CLI — นำเข้าจาก AppSheet ครั้งแรก)</h3>
  <div class="section-note">รันผ่าน Command Line เท่านั้น (<span class="inline-code">php database/import_legacy.php</span>) — idempotent: ล้างตารางแล้ว reload ใหม่ได้เสมอ</div>
  <div class="flow-steps">
    <div class="flow-step"><div class="flow-num">1</div><div class="flow-body"><b>อ่านไฟล์ CSV จาก database/staging/</b> — Finish Goods (ผลิต), Update (FW/HW), MA, Repair Display, StockmasterDB</div></div>
    <div class="flow-step"><div class="flow-num">2</div><div class="flow-body"><b>Auto-detect รูปแบบวันที่</b> ต่อไฟล์ — โหวตว่าตำแหน่งแรกคือ DD หรือ MM โดยนับค่า &gt;12 ในแต่ละตำแหน่ง แก้ปัญหาที่ AppSheet export วันที่ปนกัน DD/MM และ MM/DD</div></div>
    <div class="flow-step"><div class="flow-num">3</div><div class="flow-body"><b>บันทึกลง DB ทีละตาราง</b>: users → products → customers → assets+production_records → update_logs → ma_records → repairs → parts+part_movements</div></div>
    <div class="flow-step"><div class="flow-num">4</div><div class="flow-body"><b>บันทึก log</b> แถวที่ข้ามหรือมีปัญหา ไว้ที่ database/import_log/*.csv เพื่อตรวจสอบ</div></div>
  </div>

  <h3 style="margin-top:16px">📥 share_admin.php → Import CSV (Web UI)</h3>
  <div class="section-note">Admin กด Import บนหน้า share_admin (PIN 9981) — นำเข้าเข้า biton_stockparts.stock โดยตรง</div>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin: 6px 0 0 20px; line-height:1.8">
    <li>คอลัมน์ที่คาดหวัง: timestamp, serial_number, model, id, create_name, setup_id, active</li>
    <li>active Y/y → 1, N/n → 0</li>
    <li>Auto-detect รูปแบบวันที่ per-file เช่นกัน (<span class="inline-code">share_detect_fmt()</span>)</li>
    <li>serial_number ซ้ำ → ข้าม (SKIP) และรายงาน</li>
  </ul>

  <h3 style="margin-top:16px">🔄 share_admin.php → Sync จากระบบ (Web UI)</h3>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin: 6px 0 0 20px; line-height:1.8">
    <li>ดึงเครื่องทุกเครื่องจาก assets ที่ยังไม่มีใน stock</li>
    <li>ใช้ <span class="inline-code">INSERT IGNORE</span> ป้องกัน duplicate</li>
    <li>ใช้ <span class="inline-code">DENSE_RANK()</span> จัด batch id ตาม produced_at</li>
    <li>active = 1 สำหรับทุกเครื่องที่ดึงมาจากระบบ</li>
  </ul>

  <h3 style="margin-top:16px">🛠️ fix_stock_data.php (CLI — เติมข้อมูล stock ที่ขาด)</h3>
  <div class="section-note" style="background:#f0fdf4; border-color:#10b981; color:#065f46">รันครั้งเดียว 2026-07-13 — เติม create_name 7,338 แถว + timestamp 20 แถว จนครบ 100% (0 ค่าว่าง)</div>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin: 6px 0 0 20px; line-height:1.8">
    <li><b>Source 1</b>: production_records.made_by / assembly_by (7,243 แถว)</li>
    <li><b>Source 2</b>: StockmasterDB.csv คอลัมน์ "ผู้บันทึก" (1 แถว)</li>
    <li><b>Source 3</b>: เพื่อนบ้านรุ่นเดียวกัน หมายเลขใกล้เคียง — เทียบตัวเลขที่ฝังใน serial (91 แถว)</li>
    <li><b>Source 4</b>: ชื่อยอดนิยม fallback (3 แถว)</li>
    <li><b>Timestamp</b>: เพื่อนบ้านรุ่นเดียวกัน serial ใกล้เคียง เช่น B0904001 → 2009-04 (20 แถว)</li>
  </ul>
</div>

<!-- ⑪ สถานะสต็อกอะไหล่ -->
<div class="panel doc-section" id="stockstatus">
  <h2>⑪ สถานะสต็อกอะไหล่ (5 ระดับ)</h2>
  <div class="section-note">
    คนละเรื่องกับสถานะ<b>เครื่อง</b> ใน <a href="#status">หัวข้อ ②</a> (new/rental/spare) — นี่คือสถานะของ<b>ยอดคงเหลืออะไหล่</b> คำนวณจากฟังก์ชันกลาง <span class="inline-code">shared/stock_status.php::stock_status_key()</span> ใช้ร่วมกันทั้งฝั่ง production และ parts (แทนที่โค้ดคำนวณซ้ำที่เคยกระจายอยู่ 5 จุด)
  </div>
  <div style="overflow-x:auto">
  <table class="schema-table" style="min-width:420px">
    <tr><th>ระดับ</th><th>ความหมาย</th></tr>
    <tr><td class="col-key">out</td><td>หมด (0 ชิ้น)</td></tr>
    <tr><td class="col-key">critical</td><td>วิกฤต — เหลือน้อยมาก</td></tr>
    <tr><td class="col-key">low</td><td>ต่ำกว่าขั้นต่ำที่ตั้งไว้</td></tr>
    <tr><td class="col-key">near</td><td>ใกล้ขั้นต่ำ</td></tr>
    <tr><td class="col-key">ok</td><td>ปกติ</td></tr>
  </table>
  </div>
</div>

<!-- ⑫ ค้นหาอัจฉริยะ -->
<div class="panel doc-section" id="search">
  <h2>⑫ ค้นหาอัจฉริยะ (ช่องค้นหาที่ sidebar)</h2>
  <div class="section-note">
    ช่องเดียวค้น <b>14 แหล่งข้าม 5 ฐานข้อมูล</b> พร้อมกัน · ตรรกะอยู่ที่
    <span class="inline-code">includes/smart_search.php</span> · หน้า AJAX คือ
    <span class="inline-code">smart_search.php?ajax=1&amp;q=…</span> คืน JSON
  </div>
  <h3>กติกา</h3>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 12px 18px; line-height:1.8">
    <li>ต้องพิมพ์อย่างน้อย <b>2 ตัวอักษร</b> (สั้นกว่านั้นคืน array ว่าง)</li>
    <li>ค้นแบบ <span class="inline-code">LIKE %q%</span> — พิมพ์ท่อนกลางก็เจอ ไม่ต้องรู้ว่าขึ้นต้นด้วยอะไร</li>
    <li><b>ประเภทละ 5 รายการ</b> (<span class="inline-code">$limitPerKind</span> ปรับได้ 1–8)</li>
    <li>บรรทัดคำอธิบายยกข้อความ<b>ตรงที่ตรงกับคำค้น</b>ขึ้นมาโชว์ (<span class="inline-code">smart_search_pick_match()</span>) ไม่ใช่ยกฟิลด์แรกที่มีค่า — ผู้ใช้จะได้รู้ว่าทำไมรายการนี้ขึ้นมา</li>
    <li>ทุกก้อนที่ยิงไปฐานนอกห่อ <span class="inline-code">try</span> ไว้ — <b>ฐานเดียวล่มไม่ทำให้ทั้งช่องค้นหาพัง</b> ผลจากส่วนนั้นหายไปเฉย ๆ</li>
    <li>ผลที่ต้องเปิดที่ระบบต้นทางมีเครื่องหมาย <b>↗</b> ต่อท้ายป้ายประเภท</li>
  </ul>
  <h3>แหล่งข้อมูลทั้งหมด</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>ฐาน</th><th>ป้ายผลลัพธ์</th><th>ค้นจากคอลัมน์</th><th>กดแล้วไป</th></tr>
    <tr><td rowspan="8" class="col-key">biton_production</td><td>เครื่อง</td><td>asset_code, factory_serial, ชื่อรุ่น, current_fw_version, note, lot_label</td><td>asset.php</td></tr>
    <tr><td>เครื่อง (ผ่านลูกค้า)</td><td>ชื่อลูกค้า / site_label ของลูกค้าและของ deployment</td><td>asset.php</td></tr>
    <tr><td>ลูกค้า</td><td>name, site_label, contact_name, phone, security_company</td><td>repairs.php?customer=</td></tr>
    <tr><td>คน</td><td>work_people.display_name, work_person_aliases.alias</td><td>work_report.php?p=</td></tr>
    <tr><td>รุ่น</td><td>products: name, product_code, category</td><td>assets.php?product=</td></tr>
    <tr><td>MA</td><td>รหัสเครื่อง, S/N, remark, ok/replace/repair_items, fw_version, <b>done_by</b></td><td>asset.php</td></tr>
    <tr><td>FW / HW</td><td>รหัสเครื่อง, S/N, detail, component_name, old_value, new_value, <b>made_by</b></td><td>asset.php</td></tr>
    <tr><td>อะไหล่</td><td>parts: name, part_code, stock_code, category, dealer</td><td>parts.php?q=</td></tr>
    <tr><td class="col-key">biton_tech_parts</td><td>ใบเบิก</td><td>doc_no, note, <b>issued_by</b>, asset_code</td><td>parts/pages/history.php?q=</td></tr>
    <tr><td class="col-key">biton_stockparts</td><td>ทะเบียน stock</td><td>serial_number, model, <b>create_name</b>, setup_id</td><td>share.php?q=</td></tr>
    <tr><td class="col-key">biton_maintenance</td><td>ซ่อม</td><td>trp_sn, trp_product, <b>trp_repair_inform (อาการที่ลูกค้าแจ้ง)</b>, trp_repair_remarks</td><td>repairs.php?q=</td></tr>
    <tr><td rowspan="2" class="col-key">biton_setup</td><td>ขาย · เคลม ↗</td><td>claim_number, product_name, S/N เดิม-ใหม่, customer_name, site_name, po_number, lease_number</td><td>↗ ระบบ setup</td></tr>
    <tr><td>ส่งมอบ ↗</td><td>serial_number, S/N เดิม, po_number, issue_ref, company_name, department, customer_name, contact_person</td><td>↗ ระบบ setup</td></tr>
    <tr><td rowspan="3" class="col-key">biton_leasing</td><td>เครื่องเช่า</td><td>pro_sn, pro_name, pro_remarks, pro_bundle + ชื่อลูกค้า/ไซต์</td><td>asset.php (ถ้าเป็นเครื่องเรา)</td></tr>
    <tr><td>สัญญาเช่า ↗</td><td>r_code, r_po, r_po_renew, r_sitename, r_siteid, r_addrjob, <b>ผู้ติดต่อ 3 คน + เบอร์ 3 เบอร์</b>, r_remarks, r_product</td><td>↗ ระบบเช่า</td></tr>
    <tr><td>MA เช่า</td><td>งาน MA ของเครื่องเช่า — ค้นจาก S/N หรือชื่อไซต์เดียวกับด้านบน</td><td>ประวัติ MA เครื่องเช่า</td></tr>
  </table>
  </div>
  <div class="section-note" style="margin-top:10px; background:#fff7ed; border-color:#f59e0b; color:#92400e">
    <b>บทเรียนเรื่องความเร็วฝั่งระบบเช่า</b> — เดิมยิง LIKE บน <span class="inline-code">cus_name</span> ที่ join เข้ามา
    ใช้ index ไม่ได้ ใช้เวลา ~100 ms · แก้เป็นหาชื่อลูกค้าให้ได้ <span class="inline-code">cus_id</span> ก่อน
    แล้วค่อยกรองด้วยคอลัมน์ที่มี index เหลือ ~10 ms
  </div>
</div>

<!-- ⑬ สรุปงานรายคน -->
<div class="panel doc-section" id="workreport">
  <h2>⑬ รายงานสรุปงานรายคน</h2>
  <div class="section-note">
    หน้า <span class="inline-code">work_report.php</span> (ภาพรวมทุกคน) และ <span class="inline-code">my_work.php</span> (รายคนแบบละเอียด)
    · ตรรกะอยู่ที่ <span class="inline-code">includes/work_summary.php</span> + <span class="inline-code">includes/work_people.php</span>
  </div>
  <h3>รอบเวลา</h3>
  <p style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 12px">
    <b>วันที่ 21 ของเดือนก่อน ถึงวันที่ 20 ของเดือนนี้</b> — <span class="inline-code">work_summary_cycle()</span> เลื่อนรอบก่อน/ถัดไปได้ รองรับข้ามปี ธ.ค.→ม.ค.
  </p>
  <h3>หมวดงานที่นับ (11 หมวด จาก 3 ระบบ)</h3>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>ระบบ</th><th>หมวด</th><th>ตาราง</th><th>ช่องชื่อคน</th><th>ช่องวันที่</th></tr>
    <tr><td rowspan="5" class="col-key">production</td><td>บันทึกผลิต / QC</td><td>production_records</td><td>made_by</td><td>recorded_at</td></tr>
    <tr><td>บันทึก MA</td><td>ma_records</td><td>done_by</td><td>visited_at</td></tr>
    <tr><td>อัปเดต FW/HW</td><td>update_logs</td><td>made_by</td><td>updated_at</td></tr>
    <tr><td>เบิกอะไหล่ (นอกงานผลิต)</td><td>part_movements</td><td>made_by</td><td>moved_at</td></tr>
    <tr><td>เคลื่อนไหวคลัง</td><td>stock_movements</td><td>made_by</td><td>moved_at</td></tr>
    <tr><td rowspan="4" class="col-key">ระบบซ่อม</td><td>รับเครื่องเข้าซ่อม</td><td rowspan="4">transac_repair</td><td>trp_user_recive_ma</td><td>trp_receive_date</td></tr>
    <tr><td>ประเมิน / เสนอราคา</td><td>trp_user_rate</td><td>trp_rate_date</td></tr>
    <tr><td>ซ่อมเสร็จ</td><td>trp_user_ma</td><td>trp_success_date</td></tr>
    <tr><td>ส่งคืนลูกค้า</td><td>trp_sendby</td><td>trp_send_date</td></tr>
    <tr><td rowspan="2" class="col-key">ระบบเช่า</td><td>ลงทะเบียนเครื่องเช่า</td><td>tbl_product</td><td>pro_user_add</td><td>pro_date</td></tr>
    <tr><td>MA เครื่องเช่า</td><td>tbl_product_ma</td><td>ma_user_add</td><td>ma_date</td></tr>
  </table>
  </div>
  <h3 style="margin-top:14px">กับดักที่โค้ดจัดการไว้แล้ว — ห้ามถอดออก</h3>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 12px 18px; line-height:1.85">
    <li><b>ชื่อหลายคนในช่องเดียว</b> — <span class="inline-code">production_records.made_by</span> เก็บได้แบบ <span class="inline-code">Ice,Tom</span> ต้อง split ก่อนนับ ไม่งั้นคนหายจากสรุป</li>
    <li><b>แถวที่ระบบเขียนเอง</b> — <span class="inline-code">stock_movements</span> ที่ <span class="inline-code">reason LIKE 'Sync สถานะ:%'</span> เป็นของ cron ต้องตัดออก ไม่งั้นยอดบวมด้วยงานที่ไม่มีคนทำ · ตัด <span class="inline-code">system</span> / <span class="inline-code">Admin</span> ออกจากรายชื่อคนด้วย</li>
    <li><b>ชื่อซ้ำต่างตัวพิมพ์</b> — <span class="inline-code">AUI</span> กับ <span class="inline-code">Aui</span> คือคนเดียวกัน เทียบแบบไม่สนตัวพิมพ์ผ่าน work_person_aliases</li>
    <li><b>คนทำงานส่วนใหญ่ไม่มีบัญชีในระบบ</b> — สรุปให้<b>ทุกชื่อที่พบในข้อมูลงาน</b> ไม่ยึดตามตาราง users</li>
    <li><b>ระบบซ่อม/เช่าต่อไม่ได้</b> — คืนเฉพาะส่วน production พร้อมข้อความบอก ไม่ใช่หน้า error</li>
  </ul>
  <h3>ส่งเข้าไลน์รายคน</h3>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 0 18px; line-height:1.8">
    <li>ผูกบัญชี: หน้ารายงานสร้าง<b>รหัส 8 หลัก</b> ให้ → พนักงานทักรหัสเข้าไลน์บอท → <span class="inline-code">line_webhook.php</span> เก็บ LINE user id ลง <span class="inline-code">work_people</span> · รหัสใช้ครั้งเดียว มีวันหมดอายุ</li>
    <li>LINE user id ผูกกับ <b>bot ตัวที่ได้มา</b> (คอลัมน์ <span class="inline-code">line_user_bot</span>) — ย้าย bot แล้ว id เดิมใช้ส่งไม่ได้ ต้องผูกใหม่</li>
    <li>ส่ง 1 ข้อความต่อคน ผ่าน <span class="inline-code">line_notify_dispatch('work.summary.monthly', …)</span> พร้อม dedup key รายคน+รายรอบ → cron รันซ้ำก็ไม่ส่งซ้ำ</li>
    <li>คนที่ยังไม่ผูกไลน์ = <b>ข้าม</b> และรายงานว่าข้ามกี่คน ไม่นับเป็น error</li>
  </ul>
</div>

<!-- ⑭ LINE -->
<div class="panel doc-section" id="linenotify">
  <h2>⑭ ระบบแจ้งเตือนผ่าน LINE</h2>
  <div class="section-note">
    อยู่ใน <span class="inline-code">shared/</span> ใช้ร่วมกันทั้ง 2 แอป — ไม่ใช่ระบบแยกต่างหาก ·
    ตั้งค่าที่ <span class="inline-code">line_notify_settings.php</span> (PIN 9981) ·
    <b>เวลาและวันส่งตั้งที่ Plesk Scheduled Task เท่านั้น</b> หลังบ้านตั้งแค่เปิด/ปิด · วิธีส่ง · token · ผู้รับ
  </div>
  <h3>ไฟล์หลัก</h3>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 12px 18px; line-height:1.8">
    <li><span class="inline-code">shared/line_notify_core.php</span> — คิว/ส่ง/dedup/retry ผ่าน LINE Messaging API + สร้าง 5 ตาราง notification_* อัตโนมัติ</li>
    <li><span class="inline-code">shared/line_flex_templates.php</span> — เลือกแบบการ์ด Flex ตามประเภทเหตุการณ์</li>
    <li><span class="inline-code">shared/line_flex_finishgood_shortage.php</span> · <span class="inline-code">shared/line_flex_work_summary.php</span> — builder แยกไฟล์สำหรับการ์ดที่ซับซ้อน</li>
    <li><span class="inline-code">shared/line_notify_jobs.php</span> — runner ที่ cron เรียก</li>
    <li><span class="inline-code">includes/line_notify_settings.php</span> · <span class="inline-code">includes/work_summary_send.php</span> — ตรรกะหน้าตั้งค่า และตัวส่งสรุปงานรายคน</li>
  </ul>
  <h3>ประเภทเหตุการณ์ทั้งหมด</h3>
  <div style="overflow-x:auto">
  <table class="schema-table" style="max-width:820px">
    <tr><th>event key</th><th>ชื่อไทย</th><th>ยิงจากไหน</th></tr>
    <tr><td class="col-key">production.problem_found</td><td>พบปัญหาตอนผลิต</td><td>asset_new.php (ทันที)</td></tr>
    <tr><td class="col-key">ma.repair_required</td><td>MA ต้องซ่อม</td><td>ma.php (ทันที)</td></tr>
    <tr><td class="col-key">stock.low_threshold</td><td>อะไหล่ควรสั่งเพิ่ม</td><td>parts/includes/StockService.php + cron รายวัน</td></tr>
    <tr><td class="col-key">stock.manual_withdraw</td><td>เบิกอะไหล่ (manual)</td><td>หน้าเบิกอะไหล่</td></tr>
    <tr><td class="col-key">production.summary.daily</td><td>สรุปผลิตรายวัน</td><td>cron/plesk_line_job_daily.php</td></tr>
    <tr><td class="col-key">production.summary.daily_update</td><td>อัปเดตผลิตหลัง 17:30</td><td>cron/plesk_line_job_daily_update.php</td></tr>
    <tr><td class="col-key">production.summary.weekly</td><td>สรุปผลิตรายสัปดาห์</td><td>cron/plesk_line_job_weekly.php</td></tr>
    <tr><td class="col-key">production.summary.monthly</td><td>สรุปผลิตรายเดือน</td><td>cron/plesk_line_job_monthly.php</td></tr>
    <tr><td class="col-key">work.summary.monthly</td><td>สรุปงานรายคน (รายเดือน)</td><td>cron/plesk_line_job_work_summary.php — ส่งรายคน</td></tr>
    <tr><td class="col-key">line.test</td><td>ทดสอบการส่ง</td><td>ปุ่มในหน้าตั้งค่า (รายแถว)</td></tr>
  </table>
  </div>
  <div class="section-note" style="margin-top:10px; background:#fff7ed; border-color:#f59e0b; color:#92400e">
    <b>ข้อจำกัดของ LINE Flex ที่ต้องคิดเผื่อเสมอ</b><br>
    1 ข้อความไม่เกิน <b>50 KB</b> (โค้ดกันไว้ที่ 45 KB) และ carousel ไม่เกิน <b>12 bubble</b>
    — การ์ดที่มีรายการยาว (เช่นสรุปรุ่นทั้งหมด) ต้อง<b>แบ่งเป็นหลายข้อความให้จำนวนใกล้เคียงกัน</b> ไม่ใช่ยัดข้อความเดียว
  </div>
  <h3>โหมดทดสอบ</h3>
  <p style="font-size:13px; color:var(--text-muted, #4b5563); margin:0">
    <span class="inline-code">line_notify_test_mode()</span> เปลี่ยนปลายทาง<b>ทุกข้อความ</b>ไปบัญชีทดสอบ —
    เปิดก่อนตรวจหน้าตาการ์ด แล้วค่อยปิดเมื่อจะส่งจริง ·
    การ์ด “สินค้าที่ต้องผลิตเพิ่ม” ดูตัวอย่างได้ที่ <span class="inline-code">finishgood_shortage_preview.php</span> โดยไม่ต้องส่ง
  </p>
</div>

<!-- ⑮ โหมดมือถือ -->
<div class="panel doc-section" id="mobile">
  <h2>⑮ โหมดมือถือ</h2>
  <div class="section-note">
    เข้าโหมดมือถือด้วย <span class="inline-code">@media (max-width: 640px)</span> เท่านั้น —
    <b>ดูความกว้างหน้าจอ ไม่ได้ดูว่าเป็นเครื่องอะไร</b> (ไม่มี UA sniffing)
    จึงต้องมี <span class="inline-code">&lt;meta name="viewport" content="width=device-width, initial-scale=1"&gt;</span> ในทุกหน้า
  </div>
  <ul style="font-size:13px; color:var(--text-muted, #4b5563); margin:0 0 12px 18px; line-height:1.85">
    <li><b>แถบลัดล่างจอ</b> (<span class="inline-code">.mbar</span>): สแกน · เครื่อง · บันทึก MA · อะไหล่ — สร้างจาก <span class="inline-code">ui_mobile_bar_html()</span> ใช้ร่วมกัน 2 แอป</li>
    <li><b>เป้าสัมผัส 48px</b>: <span class="inline-code">:root { --input-h: 48px }</span> บนจอเล็ก</li>
    <li>กฎมือถือทั้งหมดอยู่ใน <span class="inline-code">assets/sidebar.css</span> <b>ไม่ใช่ style.css</b> — เพราะแอป parts ไม่ได้โหลด style.css ของ production</li>
    <li>เนื้อหาท้ายหน้าเว้นที่ให้แถบลอย: <span class="inline-code">padding-bottom: calc(64px + env(safe-area-inset-bottom))</span></li>
  </ul>
  <div class="section-note" style="background:#fff7ed; border-color:#f59e0b; color:#92400e">
    <b>เวลาตรวจหน้าจอมือถือ</b><br>
    • วัดการล้นแนวนอนด้วย <span class="inline-code">documentElement.clientWidth</span> <b>ไม่ใช่</b> <span class="inline-code">innerWidth</span>
    — บน viewport มือถือ innerWidth โตตามความกว้างที่เลื่อนได้ ทำให้ตรวจไม่เจอ<br>
    • ใช้ <span class="inline-code">minmax(0, 1fr)</span> แทน <span class="inline-code">1fr</span> ใน grid ที่ต้องหดได้
    — <span class="inline-code">1fr</span> หดต่ำกว่า min-content ไม่ได้ แล้วดันคอลัมน์ล้นกรอบเงียบ ๆ<br>
    • ต้องตรวจ<b>สถานะที่ต้องกดถึงจะเห็น</b>ด้วย (เช่นการ์ดที่สลับด้วยปุ่ม) ไม่ใช่ตรวจแค่ตอนโหลดหน้า<br>
    • ถ้าเปิดในมือถือแล้วยังเห็นหน้าจอแบบคอม ให้เช็คก่อนว่าเบราว์เซอร์เปิด “Request Desktop Site” อยู่หรือเปล่า
  </div>
</div>

<!-- ⑯ Cron -->
<div class="panel doc-section" id="cron">
  <h2>⑯ งานอัตโนมัติ (cron / Plesk Scheduled Task)</h2>
  <div class="section-note">
    ไฟล์ทั้งหมดอยู่ใน <span class="inline-code">cron/</span> · ล็อกเป็น <b>CLI-only 2 ชั้น</b>:
    <span class="inline-code">cron/.htaccess</span> บล็อก HTTP ทั้งหมด และ <span class="inline-code">cron/_bootstrap.php</span>
    เช็ค <span class="inline-code">PHP_SAPI !== 'cli'</span> → ตอบ 403 · เซิร์ฟเวอร์รันด้วย <b>PHP 8.2</b> (เว็บรัน 7.3)
    · คู่มือตั้ง task อยู่ที่ <span class="inline-code">cron/README.md</span>
  </div>
  <div style="overflow-x:auto">
  <table class="schema-table">
    <tr><th>ไฟล์</th><th>ทำอะไร</th><th>ความถี่ที่แนะนำ</th></tr>
    <tr><td class="col-key">sync_asset_status.php</td><td>sync <span class="inline-code">assets.status</span> จากระบบเช่า + การเบิกขาย (sold / rental / new)</td><td>วันละครั้ง เช่น 06:00</td></tr>
    <tr><td class="col-key">plesk_line_job_daily.php</td><td>สรุปผลิตรายวัน</td><td>Daily</td></tr>
    <tr><td class="col-key">plesk_line_job_daily_update.php</td><td>อัปเดตผลิตหลังเลิกงาน</td><td>Daily หลัง 17:30</td></tr>
    <tr><td class="col-key">plesk_line_job_low_stock.php</td><td>อะไหล่ใกล้หมด</td><td>Daily</td></tr>
    <tr><td class="col-key">plesk_line_job_finishgood_shortage.php</td><td>สินค้าที่ต้องผลิตเพิ่ม</td><td>ตามที่ตกลง</td></tr>
    <tr><td class="col-key">plesk_line_job_weekly.php</td><td>สรุปผลิตรายสัปดาห์</td><td>รายสัปดาห์</td></tr>
    <tr><td class="col-key">plesk_line_job_monthly.php</td><td>สรุปผลิตรายเดือน</td><td><span class="inline-code">10 20 28-31 * *</span> (ส่งเฉพาะวันสุดท้ายของเดือน)</td></tr>
    <tr><td class="col-key">plesk_line_job_work_summary.php</td><td><b>สรุปงานรายคน</b> — คิดรอบที่เพิ่งปิด แล้ว dispatch รายคน</td><td><span class="inline-code">0 9 21 * *</span></td></tr>
    <tr><td class="col-key">plesk_line_worker.php</td><td>ส่ง outbox ที่ค้าง (worker สำรอง)</td><td><span class="inline-code">*/2 * * * *</span> ถ้าต้องการให้ instant เร็วขึ้น</td></tr>
    <tr><td class="col-key">line_notify_worker.php · line_notify_scheduled.php</td><td>เวอร์ชันเรียกตรงสำหรับ dev / ทดสอบด้วยมือ</td><td>—</td></tr>
  </table>
  </div>
  <p style="font-size:12.5px; color:var(--text-muted, #4b5563); margin:10px 0 0">
    job รายการเรียก <span class="inline-code">line_notify_process_outbox()</span> ท้ายงานอยู่แล้ว — worker สำรองจึงไม่บังคับ
  </p>
</div>

<p class="muted" style="font-size:12px; text-align:center; margin-top:8px">เอกสารนี้สร้างอัตโนมัติจากโครงสร้างระบบ · อัปเดตล่าสุด: <?= date('d/m/Y') ?></p>

<?php page_footer(); ?>
