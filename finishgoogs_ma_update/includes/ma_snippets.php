<?php
/**
 * ma_snippets.php — คำสั่งตั้งค่าหมายเลขสินค้า (คัดลอก Serial/MAC/สรุป MA)
 *
 * ใช้ในหน้า ma.php และ asset.php (popup จาก timeline)
 */

/**
 * ชื่อแผงคำสั่ง — ต่อท้ายด้วยงานเช่าเมื่อหน้านั้นแสดงข้อ 4 (สรุปส่งงานเช่า Office)
 *
 * ใช้ร่วมกันทุกจุดที่มีปุ่ม/หัวข้อของแผงนี้ เพื่อไม่ให้ชื่อหลุดไม่ตรงกันระหว่างหน้า
 *
 * @param bool $withRental true = หน้าที่มีข้อ 4 สรุปส่งงานเช่า Office (หน้า MA)
 * @return string
 */
function ma_snippets_title($withRental = false) {
    return $withRental
        ? 'คำสั่งตั้งค่าหมายเลขสินค้าและสรุปส่งงานเช่า Office'
        : 'คำสั่งตั้งค่าหมายเลขสินค้า';
}

/**
 * HTML บล็อกคำสั่งตั้งค่าหมายเลขสินค้า (คัดลอกได้ทีละข้อ)
 *
 * @param string $pfx คำนำหน้า id ของ element (เช่น ma-sn, asset-tl-sn)
 * @param array{title?:bool,lead?:bool,rental?:bool} $opts แสดงหัวข้อ/คำอธิบาย/สรุปงานเช่าหรือไม่
 * @return string
 */
function ma_snippets_inner_html($pfx, array $opts = []) {
    $pfx = preg_replace('/[^a-z0-9_-]/', '', $pfx);
    $showTitle = !isset($opts['title']) || $opts['title'];
    $showLead = !isset($opts['lead']) || $opts['lead'];
    $showRental = !isset($opts['rental']) || $opts['rental'];
    ob_start();
    if ($showTitle) { ?>
    <h3 class="ma-snippets-title h-with-icon"><?= ui_icon_html('clipboard', 15, 'h-svg') ?><span><?= h(ma_snippets_title($showRental)) ?></span></h3>
    <?php }
    if ($showLead) { ?>
    <p class="muted ma-snippets-lead">อัปเดตตามรหัสเครื่องและฟอร์ม · กดคัดลอกทีละข้อ</p>
    <?php } ?>
    <div class="ma-snippet">
      <div class="ma-snippet-hd">
        <span>1. ตั้งค่า Serial (MobaXterm)</span>
        <button type="button" class="btn-sm btn-line btn-with-icon ma-copy-btn" data-target="<?= h($pfx) ?>-serial"><?= ui_btn_label('copy', 'คัดลอก', 13) ?></button>
      </div>
      <textarea class="ma-snippet-txt" id="<?= h($pfx) ?>-serial" readonly rows="3" aria-label="คำสั่งตั้งค่า Serial"></textarea>
    </div>
    <div class="ma-snippet">
      <div class="ma-snippet-hd">
        <span>2. ตั้งค่า MAC — เปิดไฟล์ (MobaXterm)</span>
        <button type="button" class="btn-sm btn-line btn-with-icon ma-copy-btn" data-target="<?= h($pfx) ?>-mac"><?= ui_btn_label('copy', 'คัดลอก', 13) ?></button>
      </div>
      <textarea class="ma-snippet-txt" id="<?= h($pfx) ?>-mac" readonly rows="3" aria-label="คำสั่งเปิด cmdline.txt">sudo nano /boot/cmdline.txt</textarea>
    </div>
    <div class="ma-snippet">
      <div class="ma-snippet-hd">
        <span>3. MAC Address จากรหัสเครื่อง</span>
        <button type="button" class="btn-sm btn-line btn-with-icon ma-copy-btn" data-target="<?= h($pfx) ?>-macaddr"><?= ui_btn_label('copy', 'คัดลอก', 13) ?></button>
      </div>
      <textarea class="ma-snippet-txt" id="<?= h($pfx) ?>-macaddr" readonly rows="3" aria-label="MAC Address ที่คำนวณจากรหัสเครื่อง" placeholder="(กรอกรหัสเครื่องก่อน)"></textarea>
      <p class="muted ma-snippet-note">เช่น BS22120047 → 22:12:00:47</p>
    </div>
    <?php if ($showRental) { ?>
    <div class="ma-snippet">
      <div class="ma-snippet-hd">
        <span>4. สรุปส่งงานเช่า Office</span>
        <button type="button" class="btn-sm btn-line btn-with-icon ma-copy-btn" data-target="<?= h($pfx) ?>-rental"><?= ui_btn_label('copy', 'คัดลอก', 13) ?></button>
      </div>
      <textarea class="ma-snippet-txt ma-snippet-txt-tall" id="<?= h($pfx) ?>-rental" readonly rows="4" aria-label="ข้อความสรุป MA งานเช่า"></textarea>
      <p class="muted ma-snippet-note">บันทึกไป「รายการซ่อม」ในระบบเช่าอัตโนมัติเมื่อเลือก ซ่อมแล้ว/เสื่อมสภาพ — ข้อความตรงกับที่แสดงด้านบน</p>
    </div>
    <div class="ma-snippet">
      <a href="https://bit-online.net/rent/detail_product_waitma.php" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-line btn-with-icon ma-waitma-link">
        <?= ui_icon_html('external-link', 14, 'btn-svg') ?><span>เปิดฟอร์มรอ MA — bit-online</span>
      </a>
    </div>
    <?php } ?>
    <?php
    return ob_get_clean();
}

/**
 * สร้าง data-* attributes สำหรับเปิด popup ข้อความประจำสินค้า
 *
 * @param array{code?:string,replace?:string,repair?:string,fw?:string,remark?:string} $payload
 * @return string
 */
function ma_snippet_data_attrs(array $payload) {
    $keys = ['code', 'replace', 'repair', 'fw', 'remark'];
    $out = '';
    foreach ($keys as $k) {
        $out .= ' data-' . $k . '="' . h((string)($payload[$k] ?? '')) . '"';
    }
    return $out;
}

/**
 * ปุ่มเปิด popup คำสั่งตั้งค่าหมายเลขสินค้า
 *
 * @param array{code?:string,replace?:string,repair?:string,fw?:string,remark?:string} $payload
 * @param bool $withRental true = popup นั้นมีข้อ 4 สรุปส่งงานเช่า Office ด้วย
 * @return string
 */
function ma_snippet_open_button(array $payload, $withRental = false) {
    return '<button type="button" class="btn btn-sm btn-line btn-with-icon asset-snippet-open"'
        . ma_snippet_data_attrs($payload) . '>'
        . ui_btn_label('clipboard', ma_snippets_title($withRental)) . '</button>';
}
