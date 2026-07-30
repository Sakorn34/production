<?php
/**
 * ma_snippets.php — ข้อความประจำสินค้า (คัดลอก Serial/MAC/สรุป MA)
 *
 * ใช้ในหน้า ma.php และ asset.php (popup จาก timeline)
 */

/**
 * HTML บล็อกข้อความประจำสินค้า (4 รายการคัดลอกได้)
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
    <h3 class="ma-snippets-title h-with-icon"><?= ui_icon_html('clipboard', 15, 'h-svg') ?><span>ข้อความประจำสินค้า</span></h3>
    <?php }
    if ($showLead) { ?>
    <p class="muted ma-snippets-lead">อัปเดตตามหมายเลขสินค้าและฟอร์ม · กดคัดลอกทีละข้อ</p>
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
        <span>3. MAC Address จากหมายเลขสินค้า</span>
        <button type="button" class="btn-sm btn-line btn-with-icon ma-copy-btn" data-target="<?= h($pfx) ?>-macaddr"><?= ui_btn_label('copy', 'คัดลอก', 13) ?></button>
      </div>
      <textarea class="ma-snippet-txt" id="<?= h($pfx) ?>-macaddr" readonly rows="3" aria-label="MAC Address ที่คำนวณจากหมายเลขสินค้า" placeholder="(กรอกหมายเลขสินค้าก่อน)"></textarea>
      <p class="muted ma-snippet-note">เช่น BS22120047 → 22:12:00:47</p>
    </div>
    <?php if ($showRental) { ?>
    <div class="ma-snippet">
      <div class="ma-snippet-hd">
        <span>4. สรุปส่งงานเช่า Office</span>
        <button type="button" class="btn-sm btn-line btn-with-icon ma-copy-btn" data-target="<?= h($pfx) ?>-rental"><?= ui_btn_label('copy', 'คัดลอก', 13) ?></button>
      </div>
      <textarea class="ma-snippet-txt ma-snippet-txt-tall" id="<?= h($pfx) ?>-rental" readonly rows="8" aria-label="ข้อความสรุป MA งานเช่า"></textarea>
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
 * ปุ่มเปิด popup ข้อความประจำสินค้า
 *
 * @param array{code?:string,replace?:string,repair?:string,fw?:string,remark?:string} $payload
 * @return string
 */
function ma_snippet_open_button(array $payload) {
    return '<button type="button" class="btn btn-sm btn-line btn-with-icon asset-snippet-open"'
        . ma_snippet_data_attrs($payload) . '>'
        . ui_btn_label('clipboard', 'ข้อความประจำสินค้า') . '</button>';
}
