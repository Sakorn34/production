<?php
/**
 * guide.php — คู่มือการใช้งานสำหรับผู้ใช้ใหม่ (แบบ ข · 18 ก.ย. 2026)
 *
 * หน้าแรกเป็นการ์ดหัวข้อ "อยากทำอะไร" กดเข้าไปอ่านทีละเรื่อง (?t=หัวข้อ) — ใช้บนมือถือเป็นหลัก
 * จึงไม่ทำเป็นเล่มยาวหน้าเดียว · แต่ละเรื่องเป็นขั้นตอนเป็นข้อ + ปุ่ม "ไปทำเลย" พาไปหน้านั้นจริง
 *
 * เอกสารเทคนิค (ฐานข้อมูล · ตาราง · data flow) อยู่ที่ system_doc.php ในระบบหลังบ้าน — คนละกลุ่มผู้อ่าน
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$B = BASE_URL;
$P = ui_parts_base_url();

/*
 * แต่ละหัวข้อ: title · icon · intro · steps (ข้อความ HTML ที่เราเขียนเอง) · tips · go (ปุ่มไปหน้านั้น)
 * <b> ในขั้นตอน = ชื่อปุ่ม/เมนูที่เห็นบนจอจริง ให้คนอ่านมองหาได้ตรง ๆ
 */
$topics = [
    'start' => [
        'title' => 'เริ่มต้นใช้งาน',
        'icon'  => 'dashboard',
        'intro' => 'รู้จักหน้าจอและเมนูหลักของระบบ',
        'steps' => [
            'เข้าสู่ระบบด้วยบัญชี bit-online ของตัวเอง — ชื่อที่ล็อกอินจะถูกบันทึกเป็นผู้ทำรายการทุกครั้ง',
            '<b>บนมือถือ</b> ใช้แถบด้านล่าง: <b>Dashboard</b> · <b>สแกน</b> · <b>เครื่อง</b> · <b>อะไหล่</b> · <b>เมนู</b> (ปุ่มเมนูเปิดรายการทั้งหมด เช่น บันทึก MA · อัปเดต FW/HW)',
            '<b>บนคอม</b> ใช้เมนูด้านซ้าย — กดลูกศรบนสุดเพื่อกาง/หุบเมนู',
            'ช่อง <b>ค้นหา</b> บนเมนูพิมพ์ S/N, MA หรือเรื่องอัปเดตได้ หรือกดไอคอนสแกนในช่องเพื่อสแกนรหัสเครื่อง',
            'กดเข้าดูเครื่องจากรายการหรือ popup — หน้าเครื่องจะเปิดในแท็บใหม่ รายการเดิมไม่หาย',
        ],
        'tips'  => ['ปุ่ม <b>← ย้อนกลับ</b> มุมซ้ายบนพากลับไปหน้าก่อนหน้าจริง ข้ามหน้าฟอร์มที่บันทึกแล้วให้เอง'],
        'go'    => [['Dashboard', $B . '/index.php']],
    ],
    'scan' => [
        'title' => 'สแกน / ค้นหาเครื่อง',
        'icon'  => 'scan',
        'intro' => 'หาเครื่องจาก QR หรือบาร์โค้ดบนตัวเครื่อง แล้วเปิดหน้าข้อมูลเครื่อง',
        'steps' => [
            'กด <b>สแกน</b> ที่แถบล่าง แล้วอนุญาตให้ใช้กล้อง',
            'เล็งกล้องไปที่ QR หรือบาร์โค้ด ห่างประมาณ 10–20 ซม.',
            'เจอเครื่อง = มีเสียงติ๊ด กรอบเขียว แล้วเปิดหน้าเครื่องให้เอง · ไม่เจอในทะเบียน = กรอบแดง สแกนป้ายอื่นต่อได้เลย',
            'ที่มืดกด <b>ไฟฉาย</b> · สลับกล้องหน้า-หลัง ปิดเสียง และซูมได้จากปุ่มใต้ภาพกล้อง',
            'ช่องค้นหาที่มีไอคอนสแกนอยู่ในช่อง กดสแกนได้ทุกหน้า — สแกนแล้วระบบค้นหาให้ทันที',
            'หน้าเครื่องมีข้อมูลเครื่อง ประวัติทั้งหมด และปุ่ม <b>บันทึก MA</b> · ปุ่ม <b>อื่นๆ</b> มีคำสั่งที่เหลือ (อัปเดต FW/HW · เพิ่มรายการเบิก · แก้ไขเครื่อง)',
        ],
        'tips'  => ['สแกนไม่ติด ให้เช็ดสติกเกอร์ หรือพิมพ์รหัสเองในช่องค้นหาได้เสมอ'],
        'go'    => [['เปิดหน้าสแกน', $B . '/scan.php']],
    ],
    'produce' => [
        'title' => 'ลงทะเบียนเครื่องผลิตใหม่',
        'icon'  => 'assets',
        'intro' => 'บันทึกเครื่องที่ผลิตเสร็จเข้าทะเบียน ทีละหลายเครื่องในชุดเดียว',
        'steps' => [
            'กด <b>เครื่อง</b> ที่แถบล่าง → <b>ลงทะเบียนเครื่องผลิตใหม่</b>',
            'เลือกรุ่นสินค้า (พิมพ์ชื่อรุ่นในช่องกรองให้หาเร็วขึ้น)',
            'ตรวจ <b>วันที่ผลิต</b> — ค่าเริ่มต้นเป็นวันนี้',
            'ใส่รหัสเครื่อง: กด <b>สแกนต่อเนื่อง</b> แล้วสแกนทีละเครื่อง ระบบเพิ่มแถวให้เอง · รหัสซ้ำขึ้นเตือนสีเหลืองและไม่เพิ่มซ้ำ · บางรุ่นระบบออกเลขให้เอง แค่กด <b>เพิ่มเครื่อง</b>',
            'เลือก <b>ข้อมูลประจำรุ่น</b> ตามจริง — ค่าเริ่มต้นดึงจากเครื่องล่าสุดของรุ่นนี้',
            'ตรวจ <b>ชุดอะไหล่ที่จะเบิก</b> และจำนวน — ระบบเบิกอะไหล่ให้อัตโนมัติทุกเครื่องตอนบันทึก',
            'ติ๊ก <b>Checklist</b> ข้อที่ตรวจแล้ว และกรอกปัญหา/หมายเหตุ (ถ้ามี)',
            'กด <b>บันทึกทั้งชุด</b>',
        ],
        'tips'  => ['ต้องการเพิ่มฟิลด์หรือข้อ checklist ของรุ่น แจ้งผู้ดูแลระบบ (ตั้งค่าที่ระบบหลังบ้าน)'],
        'go'    => [['ไปลงทะเบียน', $B . '/asset_new.php']],
    ],
    'ma' => [
        'title' => 'บันทึก MA',
        'icon'  => 'ma',
        'intro' => 'บันทึกการตรวจเช็ค/ซ่อมบำรุงเครื่อง',
        'steps' => [
            'สแกนหรือค้นหาเครื่อง แล้วกด <b>บันทึก MA</b> ที่หน้าเครื่อง',
            'หรือเปิด <b>เมนู</b> → <b>บันทึก MA</b> → เลือกรุ่น → กด <b>เพิ่มรายการ MA</b> แล้วใส่รหัสเครื่อง (มีปุ่มสแกนในช่อง)',
            'ตรวจ <b>วันเวลาเข้า MA</b> แล้วเลือก <b>สถานะเครื่อง</b> หลังตรวจ',
            'ติ๊กผลที่ทำ: <b>ใช้งานได้ปกติ</b> · <b>เปลี่ยนอะไหล่</b> · <b>ซ่อม</b> — ถ้าใช้อะไหล่ เพิ่มใน <b>อะไหล่ที่เบิกในรอบ MA</b> พร้อมจำนวน',
            'ใส่ <b>Firmware หลังตรวจ</b> และ <b>หมายเหตุ</b> (ถ้ามี)',
            'กด <b>บันทึก MA</b>',
        ],
        'tips'  => ['เครื่องเสื่อมสภาพหลายเครื่องพร้อมกัน ใช้ปุ่ม <b>บันทึกเสื่อมสภาพหลาย S/N</b> ในหน้าบันทึก MA'],
        'go'    => [['เปิดหน้าบันทึก MA', $B . '/ma.php']],
    ],
    'update' => [
        'title' => 'บันทึกอัปเดต FW/HW',
        'icon'  => 'updates',
        'intro' => 'บันทึกการเปลี่ยนเฟิร์มแวร์หรือชิ้นส่วนของเครื่อง',
        'steps' => [
            'ที่หน้าเครื่อง กด <b>อื่นๆ</b> → <b>บันทึกอัปเดต FW/HW</b>',
            'หรือเปิด <b>เมนู</b> → <b>อัปเดต FW/HW</b> → เลือกรุ่น → ใส่รหัสเครื่องในช่อง <b>รหัสเครื่องที่จะบันทึกอัปเดต</b>',
            'เลือก <b>ประเภทการอัปเดต</b> (Firmware · Hardware · อื่นๆ) — ถ้าเป็น Hardware ใส่ชื่อ <b>ชิ้นส่วน</b> ด้วย',
            'ตรวจ <b>ค่าเดิม</b> (ระบบเติมให้) แล้วใส่ <b>ค่าใหม่</b> เช่น 2.6.0 · เขียน <b>รายละเอียด</b> และแนบรูปประกอบได้',
            'กด <b>บันทึกการอัปเดต</b> — หน้าเครื่องจะแสดงค่าปัจจุบันเป็นค่าใหม่ทันที',
        ],
        'tips'  => [],
        'go'    => [['เปิดหน้าอัปเดต FW/HW', $B . '/updates.php']],
    ],
    'parts' => [
        'title' => 'เบิกอะไหล่',
        'icon'  => 'products',
        'intro' => 'ดูของคงเหลือ รับเข้า และเบิกอะไหล่',
        'steps' => [
            'กด <b>อะไหล่</b> ที่แถบล่าง — เห็นรายการอะไหล่และจำนวนคงเหลือ',
            'รับของเข้า กด <b>รับเข้า</b> · เบิกทีละรายการ กด <b>เบิกรายชิ้น</b> · เบิกเป็นชุด กด <b>เบิก Set</b>',
            'เบิกอะไหล่ให้เครื่องใดเครื่องหนึ่ง: ไปที่หน้าเครื่อง → <b>อื่นๆ</b> → <b>เพิ่มรายการเบิก</b> — ประวัติจะผูกกับเครื่องนั้น',
            'ดูย้อนหลังได้ที่ <b>ประวัติ</b> ในเมนูอะไหล่',
        ],
        'tips'  => ['ตอนลงทะเบียนเครื่องผลิตใหม่ ระบบเบิกอะไหล่ตามชุดของรุ่นให้เอง ไม่ต้องเบิกซ้ำ'],
        'go'    => [['เปิดหน้าอะไหล่', $P . '/pages/products.php']],
    ],
    'count' => [
        'title' => 'นับสต็อกด้วยการสแกน',
        'icon'  => 'box',
        'intro' => 'นับเครื่องที่อยู่ในคลังจริง แล้วให้ระบบปรับสถานะให้ตรง (งานของผู้รับผิดชอบคลัง)',
        'steps' => [
            'เปิด <b>ระบบหลังบ้าน</b> (ไอคอนเฟืองข้างชื่อผู้ใช้ท้ายเมนู) → <b>นับสต็อกด้วยการสแกน</b>',
            'เลือกรุ่นที่จะนับ (ปุ่ม <b>เลือกรุ่นที่ถึงรอบนับ</b> ช่วยเลือกให้) แล้วเริ่มรอบนับ',
            'สแกนทุกเครื่องที่อยู่ในคลัง — เครื่องที่เจอจะถูกนับทันที กล้องเปิดค้างไว้สแกนต่อได้',
            'สแกนครบแล้วไปขั้นตัดสถานะ — ระบบแบ่งกลุ่มเครื่องที่ไม่เจอพร้อมเหตุผล ตรวจกลุ่มสีเหลืองก่อน',
            'ติ๊กเฉพาะกลุ่มที่ต้องการ แล้วกด <b>ตัดสถานะตามที่ติ๊ก</b>',
        ],
        'tips'  => ['นับไม่ครบในวันเดียวได้ — เครื่องที่สแกนเจอในรอบอื่นภายใน 30 วันถือว่าอยู่ในคลัง', 'ทุกการเปลี่ยนสถานะย้อนกลับได้จากหน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ'],
        'go'    => [['เปิดหน้านับสต็อก', $B . '/stock_scan.php']],
    ],
    'dashboard' => [
        'title' => 'อ่าน Dashboard',
        'icon'  => 'chart',
        'intro' => 'ดูยอดเครื่อง รุ่นที่ต้องผลิตเพิ่ม และสต็อกอะไหล่',
        'steps' => [
            'แท็บ <b>สต็อกเครื่อง</b>: เครื่องใหม่ในคลัง · คลังพร้อมเช่า · จำนวนรุ่นที่ต้องผลิตเพิ่ม · ขาดรวมกี่เครื่อง',
            '"ขาด N" ของแต่ละรุ่น = (เครื่องใหม่ + คลังพร้อมเช่า) − (สต็อกขั้นต่ำ + PO ค้าง)',
            'กดที่รุ่นเพื่อดูหมายเลขเครื่องที่นับอยู่ และใบ PO ที่ค้าง',
            'แท็บ <b>การผลิต</b>: จำนวนเครื่องทั้งหมดแยกสถานะ และกราฟการผลิตรายปี — กดแท่งปีเพื่อดูรายเดือน',
            'แท็บ <b>อะไหล่</b>: อะไหล่ที่ควรสั่งเพิ่ม และความเคลื่อนไหวรับเข้า/เบิกออกวันนี้',
        ],
        'tips'  => ['ป้าย "ถึงรอบนับ" ใต้ชื่อรุ่น = ไม่ได้นับสต็อกรุ่นนั้นนานเกินรอบ กดเพื่อเริ่มนับได้เลย'],
        'go'    => [['เปิด Dashboard', $B . '/index.php']],
    ],
    'report' => [
        'title' => 'แจ้งปัญหา',
        'icon'  => 'alert',
        'intro' => 'เจอระบบทำงานผิดปกติ ส่งเรื่องถึงผู้ดูแลระบบทาง LINE ได้จากทุกหน้า',
        'steps' => [
            'เปิดหน้าที่เจอปัญหาค้างไว้',
            'กด <b>แจ้งปัญหา</b> ท้ายเมนู (มือถือ: กด <b>เมนู</b> ที่แถบล่างก่อน)',
            'เล่าสั้น ๆ ว่ากดอะไรแล้วเกิดอะไรขึ้น',
            'กด <b>+ แนบรูป</b> เพื่อแนบภาพหน้าจอ (สูงสุด 4 รูป) — บนคอมจับภาพหน้าจอแล้วกด Ctrl+V ในช่องข้อความได้เลย',
            'กด <b>ส่งเข้า LINE</b> — ผู้ดูแลได้รับทันที พร้อมลิงก์หน้าที่แจ้ง',
        ],
        'tips'  => [],
        'go'    => [],
    ],
];

$t = (string) ($_GET['t'] ?? '');
$topic = $topics[$t] ?? null;
page_header($topic ? $topic['title'] : 'คู่มือการใช้งาน', $topic !== null, $topic ? 'คู่มือการใช้งาน' : 'อยากทำอะไร เลือกหัวข้อได้เลย', $topic ? $B . '/guide.php' : '');

if (!$topic) { ?>
<div class="guide-grid">
  <?php foreach ($topics as $key => $tp) { ?>
  <a class="guide-tile" href="<?= h($B . '/guide.php?t=' . $key) ?>" data-same-tab>
    <span class="guide-tile-ic"><?= ui_icon_html($tp['icon'], 22) ?></span>
    <span class="guide-tile-text"><b><?= h($tp['title']) ?></b><span><?= h($tp['intro']) ?></span></span>
  </a>
  <?php } ?>
</div>
<?php } else {
    $keys = array_keys($topics);
    $i = array_search($t, $keys, true);
    $prev = $i > 0 ? $keys[$i - 1] : null;
    $next = $i < count($keys) - 1 ? $keys[$i + 1] : null;
?>
<div class="guide-topic">
  <p class="guide-intro"><span class="guide-tile-ic"><?= ui_icon_html($topic['icon'], 22) ?></span><?= h($topic['intro']) ?></p>
  <ol class="guide-steps">
    <?php foreach ($topic['steps'] as $s) { ?><li><?= $s ?></li><?php } ?>
  </ol>
  <?php if ($topic['tips']) { ?>
  <div class="guide-tips">
    <b>เคล็ดลับ</b>
    <ul><?php foreach ($topic['tips'] as $tip) { ?><li><?= $tip ?></li><?php } ?></ul>
  </div>
  <?php } ?>
  <?php if ($topic['go']) { ?>
  <div class="guide-go">
    <?php foreach ($topic['go'] as $g) { ?><a class="btn" href="<?= h($g[1]) ?>" data-same-tab><?= h($g[0]) ?> ›</a><?php } ?>
  </div>
  <?php } elseif ($t === 'report') { ?>
  <div class="guide-go"><button type="button" class="btn" data-support-open>แจ้งปัญหาเลย ›</button></div>
  <?php } ?>
  <nav class="guide-nav">
    <?php if ($prev) { ?><a href="<?= h($B . '/guide.php?t=' . $prev) ?>" data-same-tab>‹ <?= h($topics[$prev]['title']) ?></a><?php } else { ?><span></span><?php } ?>
    <?php if ($next) { ?><a href="<?= h($B . '/guide.php?t=' . $next) ?>" data-same-tab><?= h($topics[$next]['title']) ?> ›</a><?php } ?>
  </nav>
</div>
<?php } ?>

<style>
.guide-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.guide-tile {
  display: flex; align-items: center; gap: 12px; padding: 14px; min-height: 76px;
  background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius, 12px);
  color: inherit; text-decoration: none; transition: border-color .15s, transform .12s;
}
.guide-tile:hover { border-color: var(--primary); transform: translateY(-1px); }
.guide-tile-ic { flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px; display: inline-grid; place-items: center; background: var(--primary-soft, #fce7f3); color: var(--primary); }
.guide-tile-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.guide-tile-text b { font-size: calc(15px * var(--font-scale, 1)); }
.guide-tile-text span { font-size: calc(12.5px * var(--font-scale, 1)); color: var(--text-muted); line-height: 1.4; }
.guide-topic { max-width: 720px; }
.guide-intro { display: flex; align-items: center; gap: 12px; margin: 0 0 14px; color: var(--text-muted); }
.guide-steps { list-style: none; counter-reset: gs; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.guide-steps li {
  counter-increment: gs; position: relative; padding: 12px 14px 12px 52px; line-height: 1.6;
  background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius, 12px);
}
.guide-steps li::before {
  content: counter(gs); position: absolute; left: 14px; top: 12px; width: 26px; height: 26px; border-radius: 50%;
  display: grid; place-items: center; background: var(--primary); color: #fff; font-weight: 700; font-size: 13px;
}
.guide-tips { margin-top: 14px; padding: 12px 14px; border-radius: var(--radius, 12px); background: var(--surface-soft, #f4f1fa); }
.guide-tips ul { margin: 6px 0 0; padding-left: 18px; line-height: 1.6; }
.guide-go { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
.guide-nav { display: flex; justify-content: space-between; gap: 10px; margin-top: 22px; padding-top: 12px; border-top: 1px solid var(--border); }
.guide-nav a { text-decoration: none; min-height: 40px; display: inline-flex; align-items: center; }
@media (max-width: 640px) {
  .guide-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .guide-tile { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px; }
  .guide-tile-text span { display: none; }
  .guide-go .btn { flex: 1 1 100%; justify-content: center; }
}
</style>
<?php page_footer();
