<?php
/** layout.php — โครงหน้าเว็บ: header + sidebar + popup แจ้งเตือน/บันทึกสำเร็จ + modal เจาะลึก */

/**
 * ตรวจว่า URL ย้อนกลับอยู่ในแอปนี้และปลอดภัย
 *
 * @param string $url
 * @return bool
 */
function page_back_url_is_allowed($url) {
    $url = trim((string)$url);
    if ($url === '' || preg_match('#^(javascript|data):#i', $url)) {
        return false;
    }
    if ($url[0] === '/' && strpos($url, BASE_URL) === 0) {
        return true;
    }
    if (strpos($url, BASE_URL) === 0) {
        return true;
    }
    $refHost = parse_url($url, PHP_URL_HOST);
    $reqHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($refHost !== '' && $reqHost !== '' && strcasecmp($refHost, $reqHost) === 0) {
        $refPath = parse_url($url, PHP_URL_PATH) ?: '';
        $basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: BASE_URL;
        return $refPath !== '' && strpos($refPath, $basePath) === 0;
    }
    return false;
}

/**
 * ตรวจว่าเป็นหน้าเมนูงานหลักใน sidebar (ไม่ต้องแสดงปุ่มย้อนกลับ)
 *
 * @param string|null $cur basename ของสคริปต์ปัจจุบัน
 * @return bool
 */
function page_is_menu_page($cur = null) {
    $cur = $cur ?: basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    if ($cur === 'updates.php' && (int)(isset($_GET['product']) ? $_GET['product'] : 0) > 0) {
        return false;
    }
    if ($cur === 'ma.php' && (int)(isset($_GET['product']) ? $_GET['product'] : 0) > 0) {
        return false;
    }
    static $menus = [
        'index.php', 'assets.php', 'updates.php', 'ma.php', 'parts.php',
        'repairs.php', 'scan.php', 'settings.php',
    ];
    return in_array($cur, $menus, true);
}

/**
 * คำนวณ URL ปลายทางเมื่อกดย้อนกลับ — ใช้ referer ในแอปก่อน แล้วค่อย fallback หน้าเมนู
 *
 * @param string $override URL ที่หน้าเรียกส่งมา (เช่น รายการอัปเดตของรุ่น)
 * @return string
 */
function page_back_url($override = '') {
    if ($override !== '' && page_back_url_is_allowed($override)) {
        return $override;
    }
    $fromRef = page_back_url_from_referer();
    if ($fromRef !== null) {
        return $fromRef;
    }
    return page_back_url_default();
}

/**
 * อ่าน HTTP Referer ถ้าชี้ไปหน้าอื่นในแอป (ไม่ใช่หน้าเดิม)
 *
 * @return string|null
 */
function page_back_url_from_referer(): ?string
{
    $ref = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref === '' || !page_back_url_is_allowed($ref)) {
        return null;
    }

    $refPath = (string) (parse_url($ref, PHP_URL_PATH) ?: '');
    $refQuery = (string) (parse_url($ref, PHP_URL_QUERY) ?: '');
    $curUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $curPath = (string) (parse_url($curUri, PHP_URL_PATH) ?: '');
    $curQuery = (string) (parse_url($curUri, PHP_URL_QUERY) ?: '');

    if ($refPath === $curPath && $refQuery === $curQuery) {
        return null;
    }

    return $ref;
}

/**
 * HTML ปุ่มย้อนกลับ — กดแล้ว history.back() เหมือนเบราว์เซอร์ ถ้าไม่มีประวัติใช้ fallback
 *
 * @param string $fallbackHref จาก page_back_url()
 * @return string
 */
function page_back_button_html(string $fallbackHref): string
{
    $href = h($fallbackHref);
    $jsFallback = json_encode($fallbackHref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return '<a href="' . $href . '" class="btn btn-line btn-sm backbtn" onclick="return fgPageBack(event, ' . $jsFallback . ')">← ย้อนกลับ</a>';
}

/**
 * URL หน้าเมนูงานตามโมดูลของหน้าปัจจุบัน
 *
 * @return string
 */
function page_back_url_default() {
    $cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $b = BASE_URL;
    $productId = (int)(isset($_GET['product']) ? $_GET['product'] : 0);

    switch ($cur) {
        case 'asset.php':
        case 'asset_new.php':
            return $b . '/assets.php';
        case 'update_edit.php':
        case 'update_new.php':
            return $productId > 0 ? $b . '/updates.php?product=' . $productId : $b . '/updates.php';
        case 'updates_import.php':
            return $b . '/updates.php';
        case 'updates.php':
            return $b . '/updates.php';
        case 'ma.php':
            return $b . '/ma.php';
        case 'appearance.php':
        case 'server_config.php':
        case 'line_notify_settings.php':
        case 'activity_logs.php':
        case 'share_admin.php':
        case 'system_doc.php':
        case 'work_report.php':
            return $b . '/settings.php';
        case 'share.php':
            return $b . '/settings.php';
        case 'profile.php':
            return $b . '/index.php';
        default:
            return $b . '/index.php';
    }
}

function page_header($title, $showBack = true, $subtitle = '', $backUrl = '', $actionsHtml = '') {
    $u = user();
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $nav = nav_effective();
    $flash = flash_get();
    $backHref = page_back_url($backUrl);
    page_head_html($title);
    ?>
<body>
<?php
$side = setting('sidebar_side', 'left');
    page_header_body($title, $showBack, $subtitle, $backHref, $actionsHtml, $u, $cur, $nav, $flash, $side);
}

/**
 * <head> ของหน้าเว็บ — แยกออกมาให้หน้าที่ไม่ใช้ sidebar (เช่น my_work.php ที่พนักงาน
 * เปิดจากลิงก์ในไลน์โดยไม่ต้อง login) ใช้ธีม ฟอนต์ และตัวแปรสีชุดเดียวกันได้
 * ถ้าปล่อยให้แต่ละหน้าก๊อป <head> ไปเอง ธีมจะเพี้ยนกันทันทีที่แก้ที่เดียว
 *
 * @param  string $title
 * @return void
 */
function page_head_html($title) {
    $fontCfg = theme_font_config();
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h(setting('app_name', APP_NAME)) ?></title>
<?php $fav = setting('favicon'); if ($fav) { ?><link rel="icon" href="<?= h(img_url($fav)) ?>"><?php } ?>
<?php if (!empty($fontCfg['google'])) { ?><link rel="stylesheet" href="<?= h($fontCfg['google']) ?>"><?php } ?>
<?php if (!empty($fontCfg['custom_file'])) {
    $fontUrl = img_url($fontCfg['custom_file']);
    $ext = strtolower(pathinfo($fontCfg['custom_file'], PATHINFO_EXTENSION));
    $fmt = $ext === 'woff2' ? 'woff2' : ($ext === 'woff' ? 'woff' : ($ext === 'otf' ? 'opentype' : 'truetype'));
    ?><style>@font-face{font-family:'AppCustomFont';src:url('<?= h($fontUrl) ?>') format('<?= h($fmt) ?>');font-display:swap;font-weight:400;font-style:normal;}</style><?php
} ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<style id="theme-vars">:root{
  --primary: <?= theme_color('color_primary', '#e11d74') ?>;
  --primary-dark: <?= theme_color('color_primary_dark', '#c01862') ?>;
  --sidebar-bg: <?= theme_color('color_sidebar', '#4e2985') ?>;
  --sidebar-active: <?php
    // สีเมนู active — ค่าขาวล้วน (legacy default) จะมองไม่เห็นเพราะตัวอักษรเมนูเป็นสีขาว
    $sbAct = theme_color('color_sidebar_active', '#e11d74');
    echo in_array(strtolower($sbAct), ['#fff', '#ffffff'], true) ? 'rgba(255,255,255,.16)' : $sbAct;
  ?>;
  --page-bg: <?= theme_color('color_page_bg', '#f4f1fb') ?>;
  /* เส้นขอบผสมจากพื้นหน้ากับสีเมนู ให้อยู่ตระกูลม่วงเดียวกับธีมและตรงกับฝั่งอะไหล่
     สูตรเดิมผสมกับ #64748b ซึ่งเป็นเทาอมฟ้า ขอบจึงคนละโทนกับสีอื่นในหน้า */
  --border: color-mix(in srgb, var(--page-bg) 91%, var(--sidebar-bg) 9%);
  --border-strong: color-mix(in srgb, var(--page-bg) 78%, var(--sidebar-bg) 22%);
  --surface-muted: color-mix(in srgb, var(--page-bg) 88%, #fff);
  --surface-soft: color-mix(in srgb, var(--page-bg) 72%, #fff);
  --primary-soft: color-mix(in srgb, var(--primary) 12%, #fff);
  --focus-ring: color-mix(in srgb, var(--primary) 25%, transparent);
  --app-font: <?= $fontCfg['font'] ?>;
  --success:#16a34a; --success-soft:#dcfce7;
  --info:#1d4ed8; --info-soft:#dbeafe;
  --warning:#a16207; --warning-soft:#fef9c3;
  --near:#eab308; --near-soft:#fefce8;
  --danger:#b91c1c; --danger-soft:#fee2e2;
  --radius:12px; --radius-sm:8px;
  --shadow-sm:0 1px 2px rgba(15,23,42,.06);
  --shadow-md:0 4px 16px rgba(15,23,42,.08);
  --text:#2a2440; --text-muted:#6b6480; --text-faint:#9992ad;
  --surface:#ffffff;
  --input-h:40px; --transition:.18s ease;
  <?= theme_font_css_sizes() ?>
}</style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/theme-v2.css?v=<?= @filemtime(__DIR__ . '/../assets/theme-v2.css') ?: time() ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/sidebar.css?v=<?= @filemtime(__DIR__ . '/../assets/sidebar.css') ?: time() ?>">
</head>
<?php
}

/**
 * ส่วน <body> ของหน้าที่มี sidebar — เนื้อเดิมของ page_header() ทั้งดุ้น
 *
 * @return void
 */
function page_header_body($title, $showBack, $subtitle, $backHref, $actionsHtml, $u, $cur, $nav, $flash, $side) {
    $sideClass = $side === 'right' ? ' sidebar-right' : ($side === 'top' ? ' sidebar-top' : '');
$dockClass = ($side === 'top') ? '' : ' nav-collapsed';
$ubUser = ui_userbox_identity();
$showName = $ubUser['name'] !== 'ผู้ใช้งาน' ? $ubUser['name'] : ($u ? $u['display_name'] : '-');
$settingsActive = in_array($cur, ['settings.php', 'appearance.php', 'server_config.php', 'line_notify_settings.php', 'activity_logs.php', 'share_admin.php', 'system_doc.php', 'work_report.php', 'my_work.php'], true);
?>
<div class="app<?= $sideClass . $dockClass ?>">
<?php // ตั้ง class ให้ตรงกับที่ผู้ใช้เลือกไว้ ก่อนเบราว์เซอร์วาดเฟรมแรก
     // ไม่งั้นหน้าจะวาดแบบหุบก่อน แล้ว sidebar.js (โหลดท้ายหน้า) ค่อยสั่งขยาย
     // ทำให้เห็นอนิเมชัน 56->248px ซ้ำทุกครั้งที่เปลี่ยนหน้า ?>
<script>(function(){try{var a=document.currentScript.parentElement;
if(a&&a.classList.contains('nav-collapsed')&&localStorage.getItem('fg-sidebar-expanded')==='1'){
a.classList.remove('nav-collapsed');a.classList.add('nav-expanded');}}catch(e){}})();</script>
  <aside class="sidebar" id="fg-app-sidebar">
    <?php // หัวแถบเหลือแค่ปุ่มหุบ/ขยาย ชื่อแอปกับโลโก้ถูกเอาออกตามที่ตกลง ?>
    <div class="sidebar-head">
      <button type="button" class="sidebar-pin-btn" aria-label="ขยายเมนู" aria-expanded="false">
        <?php // ไอคอนเดียวหมุน 180 องศาเมื่อขยาย — เดิมเป็นตัวอักษร › ‹ สองอันสลับ display กัน ?>
        <?= ui_icon_html('chevron-right', 16, 'pin-chevron') ?>
      </button>
    </div>
    <div class="sidebar-search" data-smart-search="<?= BASE_URL ?>/smart_search.php">
      <div class="sidebar-search-inner">
        <span class="sidebar-search-icon"><?= ui_icon_html('search', 16) ?></span>
        <input type="search" class="sidebar-search-input" placeholder="ค้นหา S/N, MA, อัปเดต..." autocomplete="off" aria-label="ค้นหาอัจฉริยะ S/N MA อัปเดต">
      </div>
      <?php // ตอนหุบเมนู ช่องค้นหาเต็มไม่มีที่ยืน — ปุ่มนี้แทนที่ กดแล้วขยายเมนูพร้อมโฟกัสช่องค้นหา ?>
      <button type="button" class="sidebar-search-mini" aria-label="ค้นหา" title="ค้นหา"><?= ui_icon_html('search', 16) ?></button>
    </div>
    <div class="sidebar-nav">
      <nav>
        <?= ui_nav_group_label('ทะเบียนเครื่อง') ?>
        <?php foreach ($nav as $n) { ?>
        <a href="<?= BASE_URL . '/' . $n['file'] ?>" class="<?= $cur === $n['file'] ? 'active' : '' ?>">
          <span class="nav-ico"><?= ui_nav_icon_html($n['icon']) ?></span>
          <span class="nav-text"><?= h($n['label']) ?></span>
        </a>
        <?php } ?>
        <?= ui_sidebar_cross_group('สต็อกอะไหล่', ui_nav_items_parts(), ui_parts_base_url()) ?>
      </nav>
    </div>
    <div class="sidebar-foot">
      <button type="button" class="userbox-trigger" aria-expanded="false" aria-haspopup="true">
        <div class="ub-avatar"><?= h(mb_substr(trim($showName), 0, 1)) ?></div>
        <div class="ub-info">
          <div class="ub-name"><?= h($showName) ?></div>
          <div class="muted"><?= h($ubUser['sub']) ?></div>
        </div>
        <span class="userbox-chevron" aria-hidden="true">▾</span>
      </button>
      <div class="userbox-popover" hidden>
        <div class="userbox-popover-head">
          <div class="ub-avatar"><?= h(mb_substr(trim($showName), 0, 1)) ?></div>
          <div class="ub-info">
            <div class="ub-name"><?= h($showName) ?></div>
            <div class="muted"><?= h($ubUser['sub']) ?></div>
          </div>
        </div>
        <a href="<?= BASE_URL ?>/profile.php"><?= ui_icon_html('user', 16) ?> โปรไฟล์</a>
        <a href="<?= h(ui_settings_admin_url()) ?>"<?= $settingsActive ? ' class="is-active"' : '' ?>><?= ui_icon_html('settings', 16) ?> ตั้งค่าระบบ</a>
        <div class="userbox-popover-divider"></div>
        <a href="<?= BASE_URL ?>/logout.php" class="userbox-pop-danger"><?= ui_icon_html('logout', 16) ?> ออกจากระบบ</a>
      </div>
      <div class="userbox">
        <div class="ub-row">
          <div class="ub-avatar"><?= h(mb_substr(trim($showName), 0, 1)) ?></div>
          <div class="ub-info">
            <div class="ub-name"><?= h($showName) ?></div>
            <div class="muted"><?= h($ubUser['sub']) ?></div>
          </div>
          <?= ui_userbox_settings_link($settingsActive ? 'is-active' : '') ?>
        </div>
        <div class="ub-links">
          <a href="<?= BASE_URL ?>/profile.php">โปรไฟล์</a> ·
          <a href="<?= BASE_URL ?>/logout.php">ออกจากระบบ</a>
        </div>
      </div>
    </div>
  </aside>
  <div class="nav-backdrop" id="fg-nav-backdrop" hidden aria-hidden="true"></div>
  <main class="content">
    <div class="pagehead">
      <?php if ($showBack && !page_is_menu_page($cur)) { ?>
        <?= page_back_button_html($backHref) ?>
      <?php } ?>
      <div class="pagehead-titles">
        <h1><?= h($title) ?></h1>
        <?php if ($subtitle !== '') { ?><p class="pagehead-sub"><?= h($subtitle) ?></p><?php } ?>
      </div>
      <?php // ช่องปุ่มการทำงานของหน้า — เดิมไม่มี ปุ่มหลักจึงต้องไปแขวนในแถบกรอง ?>
      <?php if ($actionsHtml !== '') { ?><div class="pagehead-actions"><?= $actionsHtml ?></div><?php } ?>
    </div>
    <?php if ($flash) { ?>
    <script>window.__flash = <?= json_encode(['msg' => $flash[0], 'type' => $flash[1]], JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php } ?>
<?php
}

function page_footer() {
    ?>
  </main>
</div>

<!-- popup แจ้งเตือน -->
<div id="notif-overlay" class="notif-overlay" hidden>
  <div class="notif-box" role="dialog" aria-modal="true" aria-labelledby="notif-overlay-title">
    <h2 id="notif-overlay-title" class="h-with-icon"><?= ui_icon_html('bell', 20, 'h-svg') ?><span>แจ้งเตือน</span></h2>
    <ul id="notif-list"></ul>
    <button onclick="closeOverlay('notif-overlay')">รับทราบ</button>
  </div>
</div>

<!-- toast แจ้งเตือนสถานะ (จางหายอัตโนมัติ) -->
<div id="flash-toast-host" class="flash-toast-host" aria-live="polite" aria-atomic="true"></div>
<?php if (defined('APP_RELEASE_VERSION') && APP_RELEASE_VERSION !== '') { ?>
<p class="app-release-ver" title="รหัสชุด deploy — ใช้เทียบว่าเซิร์ฟเวอร์ได้ไฟล์ล่าสุดแล้วหรือยัง">Version <?= h(APP_RELEASE_VERSION) ?></p>
<?php } ?>

<!-- modal รายการเจาะลึก (dashboard ฯลฯ) — เจาะได้หลายชั้น มีปุ่มย้อนกลับ -->
<div id="list-overlay" class="notif-overlay" hidden>
  <div class="notif-box list-modal-box" role="dialog" aria-modal="true" aria-labelledby="list-title">
    <div class="list-modal-head">
      <button id="list-back" class="btn-sm btn-line" onclick="modalBack()" hidden>← ย้อน</button>
      <h2 id="list-title"></h2>
      <button class="btn-sm btn-line btn-icon-only" onclick="closeOverlay('list-overlay')" aria-label="ปิด"><?= ui_icon_html('close', 16, 'btn-svg') ?></button>
    </div>
    <div id="list-body" class="list-modal-body">กำลังโหลด…</div>
    <div id="list-more" class="list-modal-foot"></div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/sidebar.js?v=<?= @filemtime(__DIR__ . '/../assets/sidebar.js') ?: time() ?>"></script>
<script>
/* ── overlay: ปิดด้วย Esc / คลิกพื้นหลัง · ล็อกการเลื่อนหน้า · คืนโฟกัสให้ที่เดิม ──
   แอปอะไหล่ทำสามอย่างนี้อยู่แล้ว ฝั่งนี้เดิมปิดได้ทางเดียวคือกดปุ่มปิด */
var __overlayReturnFocus = null;

function openOverlay(id){
  var ov = document.getElementById(id);
  if (!ov || !ov.hidden) return;
  __overlayReturnFocus = document.activeElement;
  ov.hidden = false;
  document.body.classList.add('modal-open');
  // ย้ายโฟกัสเข้ากล่อง ไม่งั้นคีย์บอร์ดยังอยู่หลัง overlay
  var box = ov.querySelector('[role="dialog"]');
  if (box) {
    if (!box.hasAttribute('tabindex')) box.setAttribute('tabindex', '-1');
    try { box.focus({ preventScroll: true }); } catch (e) { box.focus(); }
  }
}

function closeOverlay(id){
  var ov = document.getElementById(id);
  if (!ov) return;
  ov.hidden = true;
  if (!document.querySelector('.notif-overlay:not([hidden])')) {
    document.body.classList.remove('modal-open');
  }
  if (__overlayReturnFocus && document.contains(__overlayReturnFocus)) {
    try { __overlayReturnFocus.focus({ preventScroll: true }); } catch (e) {}
  }
  __overlayReturnFocus = null;
}

/** ปิด overlay ที่เปิดอยู่บนสุด */
function closeTopOverlay(){
  var open = document.querySelectorAll('.notif-overlay:not([hidden])');
  if (!open.length) return false;
  closeOverlay(open[open.length - 1].id);
  return true;
}

document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' || e.key === 'Esc') { if (closeTopOverlay()) e.preventDefault(); }
});

/* คลิกที่พื้นหลัง (ตัว overlay เอง) เท่านั้น — คลิกในกล่องต้องไม่ปิด */
document.addEventListener('click', function(e){
  if (e.target.classList && e.target.classList.contains('notif-overlay')) closeOverlay(e.target.id);
});

/** ปุ่มย้อนกลับ — ใช้ประวัติเบราว์เซอร์ก่อน ไม่มีประวัติค่อยไป fallback */
function fgPageBack(e, fallbackHref) {
  if (e && typeof e.preventDefault === 'function' && window.history.length > 1) {
    e.preventDefault();
    window.history.back();
    return false;
  }
  if (fallbackHref) {
    window.location.href = fallbackHref;
    return false;
  }
  return true;
}

/**
 * ค้นหาอะไหล่จากหลายชื่อ/รหัสในคำเดียว — ใช้ร่วมกันในตัวเลือกอะไหล่ของ asset_new.php และ ma.php
 *
 * เทียบกับคีย์ search ที่ backend รวมไว้ให้แล้ว (ชื่อ + Code Part + รหัสสต็อก + หน่วย)
 * พิมพ์หลายคำคั่นช่องว่างได้ ต้องเจอครบทุกคำ (AND) เช่น "hub 2 port"
 */
function partMatchesQuery(p, query){
  query = (query || '').trim().toLowerCase();
  if (!query) return true;
  var hay = p.search || (p.name || '').toLowerCase();
  var words = query.split(/\s+/);
  for (var i = 0; i < words.length; i++) {
    if (hay.indexOf(words[i]) === -1) return false;
  }
  return true;
}

/** บรรทัดรหัสอะไหล่ใต้ชื่อในตัวเลือก (Code Part / รหัสสต็อก) */
function partCodeLineHtml(p){
  var parts = [];
  if (p.part_code) parts.push(p.part_code);
  if (p.stock_code) parts.push(p.stock_code);
  if (!parts.length) return '';
  var d = document.createElement('div');
  d.textContent = parts.join(' · ');
  return '<span class="ma-part-opt-code">' + d.innerHTML + '</span>';
}
(function(){
  if (typeof initAppSidebar === 'function') {
    initAppSidebar({
      appEl: document.querySelector('.app'),
      sidebarEl: document.getElementById('fg-app-sidebar'),
      backdropEl: document.getElementById('fg-nav-backdrop')
    });
  }
})();

// ฟิลเตอร์ค้นหา: เลือก dropdown แล้วค้นหาทันที ไม่ต้องกดปุ่ม
document.querySelectorAll('form.filter select').forEach(function(s){
  if (!s.hasAttribute('onchange')) s.addEventListener('change', function(){ if (s.form) s.form.submit(); });
});

// ค้นหาในตารางรายการแบบพิมพ์ไปกรองไป (ใส่ class list-live-filter ที่ input)
document.querySelectorAll('input.list-live-filter').forEach(function(input){
  var tbl = input.closest('main') ? input.closest('main').querySelector('table.list') : null;
  if (!tbl || !tbl.tBodies.length) return;
  input.addEventListener('input', function(){
    var q = input.value.trim().toLowerCase();
    tbl.tBodies[0].querySelectorAll('tr').forEach(function(tr){
      if (tr.querySelector('th')) return;
      tr.style.display = (!q || tr.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
    });
  });
});

// ค้นหารหัสเครื่องแบบพิมพ์ไปค้นไป — ใส่ class="asset-search" ที่ input ใดก็ได้
// (data-nav="1" = คลิกแล้วเปิดหน้าเครื่อง, ไม่ใส่ = เติมรหัสลงช่อง + ยิง event input/change)
(function(){
  var B = '<?= BASE_URL ?>';
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
  document.querySelectorAll('input.asset-search').forEach(function(input){
    if (input.dataset.acReady) return; input.dataset.acReady='1';
    input.setAttribute('autocomplete','off');
    var wrap=document.createElement('span'); wrap.className='ac-wrap';
    input.parentNode.insertBefore(wrap,input); wrap.appendChild(input);
    var box=document.createElement('div'); box.className='combo-list ac-list'; box.hidden=true; wrap.appendChild(box);
    var timer=null;
    input.addEventListener('input',function(){
      clearTimeout(timer); var q=input.value.trim();
      if(q.length<1){ box.hidden=true; return; }
      timer=setTimeout(function(){
        var url=B+'/assets.php?ajax=suggest&q='+encodeURIComponent(q);
        if(input.dataset.product) url+='&product='+encodeURIComponent(input.dataset.product);
        fetch(url).then(function(r){return r.json();}).then(function(items){
          if(!items.length){ box.innerHTML='<div class="muted" style="padding:6px 10px">ไม่พบเครื่องที่ตรง</div>'; box.hidden=false; return; }
          box.innerHTML=items.map(function(it){
            var age = it.age ? ' · อายุ '+esc(it.age) : '';
            return '<div data-code="'+esc(it.code)+'" data-id="'+it.id+'" data-age="'+esc(it.age||'')+'" data-produced="'+esc(it.produced||'')+'">'
              + '<b>'+esc(it.code)+'</b> <span class="muted">'+esc(it.pname)+' · '+esc(it.status)+age+'</span></div>';
          }).join('');
          box.hidden=false;
        }).catch(function(){ box.hidden=true; });
      },300);
    });
    box.addEventListener('click',function(e){
      var d=e.target.closest('div[data-code]'); if(!d)return;
      if(input.dataset.nav==='1'){ location.href=B+'/asset.php?id='+d.dataset.id; return; }
      input.value=d.dataset.code; box.hidden=true;
      // ส่งข้อมูลเครื่องที่เลือกไปให้หน้าที่ใช้งานต่อ (เช่น แสดงอายุเครื่องใต้ช่อง)
      input.dataset.pickedAge = d.dataset.age || '';
      input.dataset.pickedProduced = d.dataset.produced || '';
      input.dataset.pickedId = d.dataset.id || '';
      input.dispatchEvent(new Event('change',{bubbles:true}));
      input.dispatchEvent(new CustomEvent('asset-picked',{bubbles:true,detail:{
        code: d.dataset.code, id: d.dataset.id, age: d.dataset.age || '', produced: d.dataset.produced || ''
      }}));
      input.focus();
    });
    document.addEventListener('click',function(e){ if(!wrap.contains(e.target)) box.hidden=true; });
  });
})();

// toast แจ้งเตือนสถานะ — แสดงแล้วจางหาย (ไม่ต้องกด OK)
window.__flashToast = function(msg, type, duration){
  type = type || 'ok';
  duration = duration || (type === 'err' ? 5200 : 3600);
  var host = document.getElementById('flash-toast-host');
  if (!host || !msg) return;
  var el = document.createElement('div');
  el.className = 'flash-toast flash-toast-' + type;
  el.setAttribute('role', 'status');
  el.textContent = msg;
  host.appendChild(el);
  requestAnimationFrame(function(){
    requestAnimationFrame(function(){ el.classList.add('flash-toast-show'); });
  });
  setTimeout(function(){
    el.classList.remove('flash-toast-show');
    el.classList.add('flash-toast-hide');
    setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 420);
  }, duration);
};
(function(){
  if (!window.__flash) return;
  __flashToast(window.__flash.msg, window.__flash.type);
})();

// popup แจ้งเตือนครั้งแรกของ session
(function(){
  if (sessionStorage.getItem('notifShown')) return;
  fetch('<?= BASE_URL ?>/notifications.php').then(function(r){ return r.json(); }).then(function(items){
    sessionStorage.setItem('notifShown', '1');
    if (!items.length) return;
    var ul = document.getElementById('notif-list');
    items.forEach(function(it){
      var li = document.createElement('li');
      li.className = 'notif-' + it.level;
      if (it.url) { var a = document.createElement('a'); a.href = it.url; a.textContent = it.text; li.appendChild(a); }
      else { li.textContent = it.text; }
      ul.appendChild(li);
    });
    openOverlay('notif-overlay');
  }).catch(function(){});
})();

// modal รายการเจาะลึก — โหลด HTML จาก endpoint แล้วแสดง (เจาะหลายชั้นได้ มี stack ย้อนกลับ)
var __modalStack = [], __modalCur = null;
function showListModal(title, url, moreUrl, isBack){
  var overlay = document.getElementById('list-overlay');
  if (!isBack) {
    if (!overlay.hidden && __modalCur) __modalStack.push(__modalCur); // เปิดชั้นถัดไปจาก modal เดิม
    else __modalStack = [];                                          // เปิดใหม่จากหน้า
  }
  __modalCur = { t: title, u: url, m: moreUrl };
  document.getElementById('list-back').hidden = __modalStack.length === 0;
  document.getElementById('list-title').textContent = title;
  document.getElementById('list-body').innerHTML = 'กำลังโหลด…';
  document.getElementById('list-more').innerHTML = moreUrl
    ? '<a class="btn btn-sm btn-line" href="' + moreUrl + '">ดูทั้งหมดแบบเต็มหน้า →</a>' : '';
  openOverlay('list-overlay');
  fetch(url).then(function(r){ return r.text(); }).then(function(html){
    if (__modalCur && __modalCur.u === url) document.getElementById('list-body').innerHTML = html;
  }).catch(function(){
    document.getElementById('list-body').innerHTML = 'โหลดข้อมูลไม่สำเร็จ';
  });
}
function modalBack(){
  var p = __modalStack.pop();
  if (p) showListModal(p.t, p.u, p.m, true);
}

// ── dropdown chip — chip ในกรอบช่องกรอก เลือกได้หลายค่า ───────────────────
(function(){
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML.replace(/"/g,'&quot;'); }
  function parseChipVals(s){
    if (!s) return [];
    return String(s).split(',').map(function(x){ return x.trim(); }).filter(function(x){ return x !== ''; });
  }
  function chipDdIsMulti(mode){ return (mode || '').indexOf('multi') !== -1; }
  function chipDdAllowFree(mode){ return (mode || '').indexOf('_free') !== -1; }
  function chipDdMode(dd){ return dd.dataset.mode || 'chip_multi_free'; }
  function chipDdGetSelected(dd){
    var hid = dd.querySelector('input[type=hidden][data-chip-val]');
    return hid ? parseChipVals(hid.value) : [];
  }
  function chipDdSync(dd, vals){
    var mode = chipDdMode(dd);
    if (!chipDdIsMulti(mode) && vals.length > 1) vals = [vals[vals.length - 1]];
    var hid = dd.querySelector('input[type=hidden][data-chip-val]');
    if (hid) hid.value = vals.join(', ');
    var box = dd.querySelector('.chip-dd-chips');
    if (!box) return;
    box.innerHTML = vals.map(function(v){
      return '<span class="chip chip-pick" data-v="' + esc(v) + '">' + esc(v) + ' <b title="ลบ">×</b></span>';
    }).join('');
    var filt = dd.querySelector('.chip-dd-filter');
    if (filt) {
      var ph = chipDdIsMulti(mode) ? 'พิมพ์หรือเลือก ▾' : 'พิมพ์หรือเลือกค่าเดียว ▾';
      if (!chipDdAllowFree(mode)) ph = 'เลือกจากรายการ ▾';
      filt.placeholder = vals.length ? '' : ph;
    }
  }
  function chipDdAdd(dd, val){
    val = (val || '').trim();
    if (!val) return;
    var mode = chipDdMode(dd);
    var sel = chipDdGetSelected(dd);
    if (!chipDdIsMulti(mode)) {
      chipDdSync(dd, [val]);
    } else {
      if (sel.indexOf(val) !== -1) return;
      sel.push(val);
      chipDdSync(dd, sel);
    }
    var filt = dd.querySelector('.chip-dd-filter');
    if (filt) { filt.value = ''; filt.focus(); }
  }
  function chipDdRemove(dd, val){
    var mode = chipDdMode(dd);
    if (!chipDdIsMulti(mode)) {
      chipDdSync(dd, []);
      return;
    }
    chipDdSync(dd, chipDdGetSelected(dd).filter(function(v){ return v !== val; }));
  }
  /** ย้ายข้อควที่พิมพ์ใน filter ไป hidden input ก่อน submit (chip_*_free) */
  function chipDdFlushFreeText(dd){
    if (!dd) return;
    if (!chipDdAllowFree(chipDdMode(dd))) return;
    var filt = dd.querySelector('.chip-dd-filter');
    if (!filt) return;
    var pending = filt.value.trim();
    if (!pending) return;
    chipDdAdd(dd, pending);
  }
  window.chipDdFlushFreeText = chipDdFlushFreeText;
  function showChipList(dd, filter, focusSearch){
    var opts = JSON.parse(dd.dataset.opts || '[]');
    var sel = chipDdGetSelected(dd);
    var box = dd.querySelector('.chip-dd-opts');
    var mode = chipDdMode(dd);
    var isMulti = chipDdIsMulti(mode);
    filter = (filter || '').toLowerCase();
    var shown = opts.filter(function(o){
      return (!filter || o.toLowerCase().indexOf(filter) !== -1);
    });
    if (!opts.length) {
      box.innerHTML = '<div class="dd-panel-empty muted">ไม่มีค่าเก่าของรุ่นนี้</div>';
    } else if (!shown.length) {
      box.innerHTML = '<div class="dd-panel-empty muted">'
        + (chipDdAllowFree(mode) ? 'ไม่ตรงคำค้น — พิมพ์แล้วกด Enter' : 'ไม่ตรงคำค้น — เลือกจากรายการเท่านั้น')
        + '</div>';
    } else {
      var rows = shown.map(function(o){
        var picked = sel.indexOf(o) !== -1;
        if (isMulti) {
          return '<label class="dd-panel-row dd-panel-check' + (picked ? ' is-checked' : '') + '" data-v="' + esc(o) + '">'
            + '<input type="checkbox"' + (picked ? ' checked disabled' : '') + ' tabindex="-1">'
            + '<span>' + esc(o) + '</span></label>';
        }
        return '<div class="dd-panel-row' + (picked ? ' is-selected' : '') + '" data-v="' + esc(o) + '"><span>' + esc(o) + '</span></div>';
      }).join('');
      box.innerHTML = '<div class="dd-panel-items">' + rows + '</div>';
    }
    dd.querySelector('.chip-dd-list').hidden = false;
    dd.classList.add('chip-dd-open');
    var filt = dd.querySelector('.chip-dd-filter');
    if (focusSearch && filt) setTimeout(function(){ filt.focus(); }, 0);
  }
  window.chipDdHtml = function(inputName, value, options, hidden, inputMode){
    inputMode = inputMode || 'chip_multi_free';
    if (inputMode === 'text') {
      return (hidden || '') + '<input type="text" name="' + inputName + '" value="' + esc(value) + '" style="width:100%; max-width:320px">';
    }
    var opts = options || [];
    var vals = parseChipVals(value);
    if (!chipDdIsMulti(inputMode) && vals.length > 1) vals = [vals[0]];
    var cls = 'chip-dd ' + (chipDdIsMulti(inputMode) ? 'chip-dd-multi' : 'chip-dd-single');
    return '<div class="' + cls + '" data-mode="' + esc(inputMode) + '" data-opts="' + esc(JSON.stringify(opts)) + '">'
      + (hidden || '')
      + '<input type="hidden" name="' + inputName + '" data-chip-val="1" value="' + esc(vals.join(', ')) + '">'
      + '<div class="chip-dd-box">'
      + '<div class="chip-dd-chips">' + vals.map(function(v){
          return '<span class="chip chip-pick" data-v="' + esc(v) + '">' + esc(v) + ' <b title="ลบ">×</b></span>';
        }).join('') + '</div>'
      + '<input type="text" class="chip-dd-filter" autocomplete="off" placeholder="">'
      + '<button type="button" class="chip-dd-btn" tabindex="-1">▾</button>'
      + '</div>'
      + '<div class="chip-dd-list" hidden><div class="chip-dd-opts"></div></div>'
      + '</div>';
  };
  window.initChipDd = function(root){
    (root || document).querySelectorAll('.chip-dd').forEach(function(dd){
      if (dd.dataset.ready) return;
      // ฟอร์ม MA ใช้ addChip/showList ของ ma.php — ห้าม chipDdSync ทับ chips ที่โหลดจากประวัติ
      if (dd.classList.contains('ma-chip-dd')) return;
      dd.dataset.ready = '1';
      chipDdSync(dd, chipDdGetSelected(dd));
      var filt = dd.querySelector('.chip-dd-filter');
      if (!filt) return;
      filt.addEventListener('input', function(){
        showChipList(dd, filt.value.trim().toLowerCase(), false);
      });
      filt.addEventListener('focus', function(){
        showChipList(dd, filt.value.trim().toLowerCase(), true);
      });
      filt.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
          e.preventDefault();
          if (!chipDdAllowFree(chipDdMode(dd))) return;
          chipDdAdd(dd, filt.value);
          dd.querySelector('.chip-dd-list').hidden = true;
          dd.classList.remove('chip-dd-open');
        } else if (e.key === 'Backspace' && !filt.value && chipDdGetSelected(dd).length) {
          if (chipDdIsMulti(chipDdMode(dd))) {
            var sel = chipDdGetSelected(dd);
            chipDdRemove(dd, sel[sel.length - 1]);
          } else {
            chipDdRemove(dd, chipDdGetSelected(dd)[0]);
          }
        }
      });
      filt.addEventListener('blur', function(){
        setTimeout(function(){
          var list = dd.querySelector('.chip-dd-list');
          if (list && !list.hidden) return;
          chipDdFlushFreeText(dd);
        }, 120);
      });
      dd.querySelector('.chip-dd-box').addEventListener('click', function(e){
        if (!e.target.closest('.chip-dd-btn')) filt.focus();
      });
    });
  };
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.chip-dd-btn');
    if (btn){
      var dd = btn.closest('.chip-dd');
      // ปล่อยให้ asset_new / ma จัดการ dropdown อะไหล่เอง (มี UI แยกจาก chip-dd ทั่วไป)
      if (dd && (dd.classList.contains('bom-part-dd') || dd.classList.contains('ma-part-dd'))) return;
      var list = dd.querySelector('.chip-dd-list');
      var filt = dd.querySelector('.chip-dd-filter');
      if (list.hidden) showChipList(dd, filt ? filt.value.trim().toLowerCase() : '', true);
      else { list.hidden = true; dd.classList.remove('chip-dd-open'); }
      e.preventDefault(); return;
    }
    var opt = e.target.closest('.chip-dd-opts .chip[data-v], .chip-dd-opts .dd-panel-row[data-v]');
    if (opt){
      var dd = opt.closest('.chip-dd');
      var mode = chipDdMode(dd);
      if (opt.classList.contains('sel') && chipDdIsMulti(mode)) return;
      if (opt.classList.contains('is-checked')) return;
      if (!chipDdIsMulti(mode)) chipDdSync(dd, [opt.dataset.v]);
      else chipDdAdd(dd, opt.dataset.v);
      dd.querySelector('.chip-dd-list').hidden = true;
      dd.classList.remove('chip-dd-open');
      return;
    }
    var rm = e.target.closest('.chip-dd-chips .chip b');
    if (rm){
      var chip = rm.closest('.chip[data-v]');
      chipDdRemove(chip.closest('.chip-dd'), chip.dataset.v);
      return;
    }
    document.querySelectorAll('.chip-dd-list').forEach(function(l){
      if (!l.parentNode.contains(e.target)) {
        l.hidden = true;
        var dd = l.closest('.chip-dd');
        if (dd) dd.classList.remove('chip-dd-open');
      }
    });
  });
  document.addEventListener('DOMContentLoaded', function(){ initChipDd(); });
  document.addEventListener('submit', function(e){
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    form.querySelectorAll('.chip-dd').forEach(function(dd){ chipDdFlushFreeText(dd); });
  }, true);
})();

// ── stepper จำนวน (+/- ทีละ step) ─────────────────────────────────────
window.qtyStepHtml = function(name, value, step, min){
  step = step || 0.5; min = min || step;
  var v = value != null ? value : min;
  return '<div class="qty-stepper">'
    + '<button type="button" class="qty-btn" data-delta="-' + step + '" onclick="qtyStepClick(this)">−</button>'
    + '<input type="number" name="' + name + '" value="' + v + '" step="' + step + '" min="' + min + '">'
    + '<button type="button" class="qty-btn" data-delta="' + step + '" onclick="qtyStepClick(this)">+</button></div>';
};
window.qtyStepClick = function(btn){
  var wrap = btn.closest('.qty-stepper');
  var inp = wrap.querySelector('input[type=number]');
  var step = parseFloat(btn.dataset.delta) || 0.5;
  var min = parseFloat(inp.min) || 0.5;
  var v = (parseFloat(inp.value) || 0) + step;
  if (v < min) v = min;
  v = Math.round(v * 2) / 2;
  inp.value = (v % 1 === 0) ? String(v) : v.toFixed(1);
};
</script>
</body>
</html>
<?php
}
