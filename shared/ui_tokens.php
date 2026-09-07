<?php
/**
 * shared/ui_tokens.php — ตัวแปรธีมชุดเดียวของทั้งสองแอป
 * ─────────────────────────────────────────────────────────────────────────────
 * ก่อนหน้านี้ token ชุดเดียวกันถูกประกาศไว้ 4 ที่:
 *   1. finishgoogs_ma_update/includes/layout.php  (inline #theme-vars)
 *   2. parts/includes/main_theme.php               (inline #theme-vars)
 *   3. parts/assets/style.css :root
 *   4. parts/assets/theme-v2.css :root
 * ค่าไม่ตรงกันหลายตัว และที่สำคัญกว่านั้นคือ ฝั่งอะไหล่ตั้งค่าไว้ตายตัว
 * (--border:#e7e0f5) ส่วนฝั่งทะเบียนเครื่องคำนวณจากสีธีม — ผู้ใช้เปลี่ยนสีพื้นหน้า
 * ที่ appearance.php แล้วเส้นขอบฝั่งทะเบียนเครื่องเปลี่ยนตาม แต่ฝั่งอะไหล่ไม่เปลี่ยน
 *
 * ไฟล์นี้เป็นที่เดียวที่ประกาศ token ทั้งหมด ทั้งสองแอปเรียกฟังก์ชันเดียวกัน
 * ค่าที่ได้จึงตรงกันเสมอโดยไม่ต้องไล่แก้สองที่ (UX Audit เฟส 2 ข้อ 1)
 *
 * ตัวที่คำนวณจากสีธีม (color-mix) ห้ามเปลี่ยนกลับเป็นค่าคงที่ — ทั้งชุดต้องขยับ
 * ตามสีที่ผู้ใช้ตั้งไว้ ไม่งั้นธีมที่เลือกจะไปชนกับสีที่ฝังไว้
 * ─────────────────────────────────────────────────────────────────────────────
 */

/**
 * บล็อก :root ของตัวแปรธีมทั้งหมด
 *
 * @param array $t คีย์ที่รับ:
 *     primary        สีหลัก
 *     primary_dark   สีหลักเข้ม
 *     sidebar        สีแถบเมนู
 *     sidebar_active สีเมนูที่เลือกอยู่
 *     page_bg        สีพื้นหน้า
 *     font           font stack (ค่า CSS ทั้งก้อน)
 *     font_scale     เปอร์เซ็นต์ขนาดตัวอักษร (90-140)
 * @return string  '<:root{...}>' พร้อมใส่ใน <style>
 */
function ui_tokens_css_block(array $t): string
{
    $primary     = (string) ($t['primary'] ?? '#e11d74');
    $primaryDark = (string) ($t['primary_dark'] ?? '#c01862');
    $sidebar     = (string) ($t['sidebar'] ?? '#4e2985');
    $pageBg      = (string) ($t['page_bg'] ?? '#f4f1fb');
    $font        = (string) ($t['font'] ?? 'system-ui, sans-serif');

    // สีเมนู active — ค่าขาวล้วน (ค่าเริ่มต้นเดิมของระบบ) จะมองไม่เห็นเพราะตัวอักษร
    // เมนูเป็นสีขาวอยู่แล้ว แปลงเป็นขาวโปร่งแทนเพื่อให้ยังเห็นว่าเมนูไหนถูกเลือก
    $sidebarActive = (string) ($t['sidebar_active'] ?? $primary);
    if (in_array(strtolower(trim($sidebarActive)), ['#fff', '#ffffff'], true)) {
        $sidebarActive = 'rgba(255,255,255,.16)';
    }

    $scale = (float) ($t['font_scale'] ?? 100) / 100;
    if ($scale <= 0) { $scale = 1.0; }
    $sf = rtrim(rtrim(sprintf('%.4f', $scale), '0'), '.');

    return ':root{
  /* ── สีที่ผู้ใช้ตั้งเองได้จาก appearance.php ── */
  --primary: ' . $primary . ';
  --primary-dark: ' . $primaryDark . ';
  --sidebar-bg: ' . $sidebar . ';
  --sidebar-active: ' . $sidebarActive . ';
  --page-bg: ' . $pageBg . ';
  --app-font: ' . $font . ';

  /* ── ที่คำนวณจากสีข้างบน ──
     เส้นขอบผสมพื้นหน้ากับสีเมนู ให้อยู่ตระกูลสีเดียวกับธีมทั้งระบบ
     (สูตรเดิมผสมกับ #64748b ซึ่งเป็นเทาอมฟ้า ขอบจึงคนละโทนกับสีอื่นในหน้า) */
  --border: color-mix(in srgb, var(--page-bg) 91%, var(--sidebar-bg) 9%);
  --border-strong: color-mix(in srgb, var(--page-bg) 78%, var(--sidebar-bg) 22%);
  --border-focus: color-mix(in srgb, var(--primary) 45%, #fff);
  --surface-muted: color-mix(in srgb, var(--page-bg) 88%, #fff);
  --surface-soft: color-mix(in srgb, var(--page-bg) 72%, #fff);
  --primary-soft: color-mix(in srgb, var(--primary) 12%, #fff);
  --focus-ring: color-mix(in srgb, var(--primary) 25%, transparent);

  /* เงาย้อมสีเมนูแทนเทาอมฟ้า — บนพื้นม่วงอ่อนเงาเทาดูขุ่น · เดิมสองแอปใช้คนละสูตร
     (ทะเบียนเครื่อง rgba(15,23,42) · อะไหล่ rgba(46,26,90)) ทั้งที่วางซ้อนหน้าเดียวกัน */
  --shadow-sm: 0 1px 2px color-mix(in srgb, var(--sidebar-bg) 8%, transparent);
  --shadow-md: 0 4px 16px color-mix(in srgb, var(--sidebar-bg) 12%, transparent);
  --shadow-lg: 0 8px 28px color-mix(in srgb, var(--sidebar-bg) 16%, transparent);

  /* ── พื้น/ตัวอักษร ── */
  --surface: #ffffff;
  --text: #2a2440;
  --text-muted: #6b6480;
  --text-faint: #9992ad;

  /* ── สีความหมาย (คู่ เข้ม/อ่อน) ──
     ป้ายสถานะเครื่องไม่ได้ใช้ชุดนี้ — อยู่ที่ shared/ui_status_palette.php ที่เดียว */
  --success:#16a34a; --success-soft:#dcfce7;
  --info:#1d4ed8;    --info-soft:#dbeafe;
  --warning:#a16207; --warning-soft:#fef9c3;
  --near:#eab308;    --near-soft:#fefce8;
  --danger:#b91c1c;  --danger-soft:#fee2e2;

  /* ── รูปทรง/จังหวะ ── */
  --radius:12px; --radius-sm:8px;
  --input-h:40px; --transition:.18s ease;

  /* ── ขนาดตัวอักษร ──
     ห้าระดับตามที่ audit เสนอ (F-10) · --fs-h1/table/badge เป็นชื่อเดิมที่โค้ดทั่วระบบ
     อ้างถึงอยู่ ค่าคงเดิมไม่ขยับ ส่วน xl/lg/sm/xs เพิ่มไว้ให้ของใหม่เลิกฝังตัวเลข */
  --font-scale:' . $sf . ';
  --fs-xl: calc(28px * var(--font-scale));
  --fs-h1: calc(22px * var(--font-scale));
  --fs-lg: calc(20px * var(--font-scale));
  --fs-body: calc(15px * var(--font-scale));
  --fs-table: calc(13.5px * var(--font-scale));
  --fs-sm: calc(13px * var(--font-scale));
  --fs-badge: calc(12px * var(--font-scale));
  --fs-xs: calc(11.5px * var(--font-scale));

  /* ── ชื่อพ้อง ──
     ฝั่งอะไหล่เขียน --bg/--card/--sidebar ไว้ทั่วไฟล์ ฝั่งทะเบียนเครื่องใช้
     --page-bg/--surface/--sidebar-bg · ยกให้เป็นชื่อเดียวกันต้องไล่แก้ CSS หลายร้อยที่
     จึงประกาศเป็นชื่อพ้องไว้ก่อน แล้วค่อยไล่รวมทีละไฟล์ */
  --bg: var(--page-bg);
  --card: var(--surface);
  --sidebar: var(--sidebar-bg);
}';
}
