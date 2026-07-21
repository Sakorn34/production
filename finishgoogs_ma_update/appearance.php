<?php
/** appearance.php — ปรับแต่งหน้าตาระบบ (admin): ข้อความ · โลโก้ · สีธีม · เมนู(icon/ตำแหน่ง) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$COLORS = [
    'color_primary'        => ['สีหลัก (ปุ่ม/ลิงก์)', '#e11d74'],
    'color_primary_dark'   => ['สีหลักเข้ม (hover)', '#c01862'],
    'color_sidebar'        => ['พื้นแถบเมนู', '#4e2985'],
    'color_sidebar_active' => ['เมนูที่เลือกอยู่', '#e11d74'],
    'color_page_bg'        => ['พื้นหลังหน้า', '#f4f1fb'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // ข้อความ
    set_setting('app_name', trim($_POST['app_name']));
    set_setting('login_subtitle', trim($_POST['login_subtitle']));
    // สีธีม (เก็บเฉพาะที่เป็น hex ถูกต้อง)
    foreach ($COLORS as $k => $meta) {
        $v = trim(isset($_POST[$k]) ? $_POST[$k] : '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) set_setting($k, $v);
    }
    // ตำแหน่งเมนู
    $side = $_POST['sidebar_side'] ?? 'left';
    set_setting('sidebar_side', in_array($side, ['left', 'right', 'top'], true) ? $side : 'left');
    // ความสูงโลโก้ (px)
    $lh = (int)($_POST['brand_logo_h'] ?? 0);
    if ($lh >= 20 && $lh <= 160) set_setting('brand_logo_h', $lh);
    // ขนาดตัวอักษร (เปอร์เซ็นต์)
    $fontScale = (int)($_POST['font_scale_percent'] ?? 100);
    if ($fontScale >= 90 && $fontScale <= 140) set_setting('font_scale_percent', $fontScale);
    // โลโก้ + ไอคอนแท็บเบราว์เซอร์ (favicon) — แจ้ง error ถ้าเลือกไฟล์แล้วอัปโหลดไม่ผ่าน
    $upErrors = [];
    $brandUpload = function ($field, $label) use (&$upErrors) {
        if (empty($_FILES[$field]['name'])) return null; // ไม่ได้เลือกไฟล์
        $err = $_FILES[$field]['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $upErrors[] = "$label: ไฟล์ใหญ่เกินขีดจำกัด (สูงสุด " . ini_get('upload_max_filesize') . ')';
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $upErrors[] = "$label: อัปโหลดไม่สำเร็จ (รหัส $err) ลองใหม่อีกครั้ง";
            return null;
        }
        $p = save_upload($field, 'brand', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'svg']);
        if (!$p) $upErrors[] = "$label: ไฟล์ไม่ใช่รูปที่รองรับ (PNG / JPG / GIF / WebP / ICO / SVG)";
        return $p;
    };
    $logo = $brandUpload('brand_logo', 'โลโก้');
    if ($logo) set_setting('brand_logo', $logo);
    elseif (!empty($_POST['remove_logo'])) set_setting('brand_logo', '');
    $fav = $brandUpload('favicon', 'ไอคอน (Favicon)');
    if ($fav) set_setting('favicon', $fav);
    elseif (!empty($_POST['remove_favicon'])) set_setting('favicon', '');
    // ฟอนต์ภาษาไทย
    $fontPreset = trim((string)($_POST['font_preset'] ?? 'system'));
    $allowedFonts = ['system', 'saraban', 'prompt', 'kanit', 'noto', 'custom'];
    if (!in_array($fontPreset, $allowedFonts, true)) $fontPreset = 'system';
    set_setting('font_preset', $fontPreset);
    $fontUp = save_font_upload('font_file');
    if ($fontUp) {
        set_setting('font_file', $fontUp);
        set_setting('font_preset', 'custom');
    } elseif (!empty($_POST['remove_font_file'])) {
        set_setting('font_file', '');
        if ($fontPreset === 'custom') set_setting('font_preset', 'system');
    }
    // เมนู (icon/label/ซ่อน/ลำดับ) — เรียงตามลำดับแถวที่ส่งมา
    $files  = (array)($_POST['nav_file'] ?? []);
    $icons  = (array)($_POST['nav_icon'] ?? []);
    $labels = (array)($_POST['nav_label'] ?? []);
    $shows  = (array)($_POST['nav_show'] ?? []);
    $navItems = [];
    foreach ($files as $i => $f) {
        if ($f === '') continue;
        $navItems[] = [
            'file'   => $f,
            'icon'   => trim(isset($icons[$i]) ? $icons[$i] : ''),
            'label'  => trim(isset($labels[$i]) ? $labels[$i] : ''),
            'hidden' => (isset($shows[$i]) ? $shows[$i] : '1') !== '1',
            'order'  => $i,
        ];
    }
    set_setting('nav_items', json_encode($navItems, JSON_UNESCAPED_UNICODE));

    if ($upErrors) flash_set('บันทึกแล้ว แต่รูปไม่ถูกอัปโหลด — ' . implode(' · ', $upErrors), 'err');
    else flash_set('บันทึกการปรับแต่งหน้าตาแล้ว');
    header('Location: ' . BASE_URL . '/appearance.php'); exit;
}

// สร้างรายการเมนูปัจจุบัน (default + override) เรียงตามลำดับที่ตั้งไว้
$ovr = json_decode((string)setting('nav_items', '[]'), true);
if (!is_array($ovr)) $ovr = [];
$ovrByFile = [];
foreach ($ovr as $o) if (isset($o['file'])) $ovrByFile[$o['file']] = $o;
$navRows = [];
foreach (nav_default() as $i => $n) {
    if ($n[0] === 'settings.php') continue;
    $o = isset($ovrByFile[$n[0]]) ? $ovrByFile[$n[0]] : [];
    $navRows[] = [
        'file'   => $n[0],
        'icon'   => (isset($o['icon']) && $o['icon'] !== '') ? $o['icon'] : $n[1],
        'label'  => (isset($o['label']) && $o['label'] !== '') ? $o['label'] : $n[2],
        'hidden' => !empty($o['hidden']),
        'order'  => isset($o['order']) ? (int)$o['order'] : $i,
    ];
}
usort($navRows, function ($a, $b) { return $a['order'] - $b['order']; });

$logo = setting('brand_logo');
page_header('ปรับแต่งหน้าตาระบบ');
?>
<p class="muted" style="margin-bottom:14px">ปรับข้อความ โลโก้ สีธีม และเมนู (ไอคอน/ลำดับ/ตำแหน่ง) ของทั้งระบบ · มีผลกับทุกคนหลังบันทึก</p>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="produce-cols">
    <!-- ซ้าย: ข้อความ + โลโก้ + สี -->
    <div class="panel">
      <?= ui_heading('edit', 'ข้อความ & โลโก้', 'h3') ?>
      <div class="field"><label>ชื่อระบบ (แสดงบนแถบเมนู/แท็บ)</label>
        <input type="text" name="app_name" value="<?= h(setting('app_name', APP_NAME)) ?>" style="width:100%"></div>
      <div class="field"><label>ข้อความรองหน้า Login</label>
        <input type="text" name="login_subtitle" value="<?= h(setting('login_subtitle', 'บันทึกผลิตใหม่ · อัปเดต FW/HW · ซ่อมบำรุง · MA เครื่องเช่า/สำรอง')) ?>" style="width:100%"></div>
      <div class="field"><label>โลโก้ (แทนชื่อระบบบนแถบเมนู)</label>
        <?php if ($logo) { ?><div style="margin-bottom:6px; background:var(--sidebar-bg); padding:8px; border-radius:8px; display:inline-block"><img src="<?= h(img_url($logo)) ?>" class="brand-logo"></div>
          <label style="display:block; font-weight:400"><input type="checkbox" name="remove_logo" value="1"> ลบโลโก้ (กลับไปใช้ชื่อระบบ)</label><?php } ?>
        <input type="file" name="brand_logo" accept="image/*,.svg">
        <div style="display:flex; align-items:center; gap:8px; margin-top:8px">
          <label style="font-weight:400; font-size:13px; margin:0">ความสูงโลโก้:</label>
          <input type="range" name="brand_logo_h" min="20" max="160" step="2" value="<?= (int)setting('brand_logo_h', 56) ?>" oninput="document.getElementById('logo-h-val').textContent=this.value" style="flex:1">
          <span style="font-size:13px; min-width:44px"><b id="logo-h-val"><?= (int)setting('brand_logo_h', 56) ?></b> px</span>
        </div></div>
      <div class="field"><label>ไอคอนบนแท็บเบราว์เซอร์ (Favicon)</label>
        <?php $favCur = setting('favicon'); if ($favCur) { ?>
          <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px">
            <img src="<?= h(img_url($favCur)) ?>" style="width:24px; height:24px; object-fit:contain; border:1px solid #e4e8ef; border-radius:4px; background:#fff">
            <label style="font-weight:400"><input type="checkbox" name="remove_favicon" value="1"> ลบไอคอน</label>
          </div>
        <?php } ?>
        <input type="file" name="favicon" accept="image/*,.ico,.svg">
        <div class="muted" style="font-size:12px; margin-top:3px">แนะนำรูปสี่เหลี่ยมจัตุรัส PNG ขนาด 64×64 ขึ้นไป · รองรับ PNG / JPG / GIF / WebP / ICO / SVG · ขนาดไม่เกิน <?= h(ini_get('upload_max_filesize')) ?></div>      </div>

      <h3 class="h-with-icon" style="margin:18px 0 10px"><?= ui_icon_html('clipboard', 18, 'h-svg') ?><span>ขนาดตัวอักษร</span></h3>
      <?php $fontScaleCur = theme_font_scale_percent(); ?>
      <div class="field">
        <label>ขยายขนาดข้อความทั้งระบบ</label>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">
          <input type="range" name="font_scale_percent" min="90" max="140" step="5"
            value="<?= (int) $fontScaleCur ?>"
            oninput="document.getElementById('font-scale-val').textContent=this.value + '%'"
            style="flex:1; min-width:160px">
          <span style="font-size:13px; min-width:52px"><b id="font-scale-val"><?= (int) $fontScaleCur ?>%</b></span>
        </div>
        <div class="muted" style="font-size:12px; margin-top:4px">100% = ค่าเริ่มต้น · มีผลกับเนื้อหา หัวข้อ ตาราง และป้ายสถานะ</div>
      </div>

      <?= ui_heading('font', 'ฟอนต์ภาษาไทย', 'h3') ?>
      <?php
      $fontPreset = setting('font_preset', 'system');
      $fontFile = setting('font_file', '');
      ?>
      <div class="field"><label>ชุดฟอนต์</label>
        <select name="font_preset" style="width:100%">
          <option value="system" <?= $fontPreset === 'system' ? 'selected' : '' ?>>Noto Sans Thai / ระบบ (ค่าเริ่มต้น)</option>
          <option value="saraban" <?= $fontPreset === 'saraban' ? 'selected' : '' ?>>Sarabun (Google Fonts)</option>
          <option value="prompt" <?= $fontPreset === 'prompt' ? 'selected' : '' ?>>Prompt (Google Fonts)</option>
          <option value="kanit" <?= $fontPreset === 'kanit' ? 'selected' : '' ?>>Kanit (Google Fonts)</option>
          <option value="noto" <?= $fontPreset === 'noto' ? 'selected' : '' ?>>Noto Sans Thai (Google Fonts)</option>
          <option value="custom" <?= $fontPreset === 'custom' || $fontFile ? 'selected' : '' ?>>อัปโหลดไฟล์ฟอนต์เอง</option>
        </select>
        <div class="muted" style="font-size:12px; margin-top:4px">มีผลทั้งระบบหลังบันทึก · เลือก "อัปโหลดเอง" แล้วแนบไฟล์ด้านล่าง</div>
      </div>
      <div class="field"><label>ไฟล์ฟอนต์ (WOFF / WOFF2 / TTF / OTF)</label>
        <?php if ($fontFile) { ?>
          <div class="muted" style="margin-bottom:6px">ใช้งาน: <?= h(basename($fontFile)) ?></div>
          <label style="display:block; font-weight:400"><input type="checkbox" name="remove_font_file" value="1"> ลบไฟล์ฟอนต์ที่อัปโหลด</label>
        <?php } ?>
        <input type="file" name="font_file" accept=".woff,.woff2,.ttf,.otf,font/woff,font/woff2,font/ttf,font/otf">
      </div>

      <?= ui_heading('palette', 'สีธีม', 'h3') ?>
      <div class="field">
        <div style="display:flex; gap:8px; margin-bottom:10px">
          <button type="button" class="btn btn-line btn-sm" onclick="applyPreset('v2')">v2 ชมพู–ม่วง (ค่าเริ่มต้น)</button>
          <button type="button" class="btn btn-line btn-sm" onclick="applyPreset('navy')">โทนน้ำเงิน</button>
          <button type="button" class="btn btn-line btn-sm" onclick="applyPreset('teal')">โทนเขียวเทา</button>
          <button type="button" class="btn btn-line btn-sm" onclick="applyPreset('vibrant')">โทนสดใส (ชมพู/ม่วง)</button>
        </div>
        <?php foreach ($COLORS as $k => $meta) { ?>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:7px" class="color-row" data-color-key="<?= h($k) ?>">
          <input type="color" id="c_<?= $k ?>" name="<?= $k ?>" value="<?= h(theme_color($k, $meta[1])) ?>" style="width:46px; height:32px; padding:2px; border:1px solid #c9d2e0; border-radius:6px; cursor:pointer">
          <span style="font-size:13px; flex:1"><?= h($meta[0]) ?></span>
          <span id="contrast_<?= $k ?>" class="contrast-badge" style="font-size:11px; padding:2px 8px; border-radius:999px; font-weight:600"></span>
        </div>
        <?php } ?>
        <div id="contrast-hint" class="muted" style="font-size:12px; margin-top:6px"></div>
      </div>
    </div>

    <!-- ขวา: เมนู + ตำแหน่ง -->
    <div class="panel">
      <?= ui_heading('clipboard', 'เมนู (ไอคอน · ชื่อ · ลำดับ · แสดง)', 'h3') ?>
      <p class="muted" style="margin-bottom:8px">ลาก ≡ จัดลำดับ · ใส่ icon key (เช่น dashboard, parts, scan) หรือ emoji · ติ๊กออกเพื่อซ่อนเมนู · <b>ระบบหลังบ้าน</b> แสดงเป็นไอคón ⚙️ ข้างชื่อผู้ใช้เสมอ</p>
      <table class="list" id="tbl-nav">
        <tr><th style="width:26px"></th><th style="width:56px">ไอคอน</th><th>ชื่อเมนู</th><th style="width:44px; text-align:center">แสดง</th></tr>
        <?php foreach ($navRows as $r) { ?>
        <tr>
          <td class="drag muted" style="cursor:grab; text-align:center">≡<input type="hidden" name="nav_file[]" value="<?= h($r['file']) ?>"></td>
          <td><input type="text" name="nav_icon[]" value="<?= h($r['icon']) ?>" style="width:72px; text-align:center" placeholder="dashboard"></td>
          <td><input type="text" name="nav_label[]" value="<?= h($r['label']) ?>" style="width:100%"><div class="muted" style="font-size:11px"><?= h($r['file']) ?></div></td>
          <td style="text-align:center">
            <input type="hidden" name="nav_show[]" value="<?= $r['hidden'] ? '0' : '1' ?>">
            <input type="checkbox" <?= $r['hidden'] ? '' : 'checked' ?> onchange="this.previousElementSibling.value=this.checked?'1':'0'">
          </td>
        </tr>
        <?php } ?>
      </table>

      <?= ui_heading('tag', 'ตำแหน่งแถบเมนู', 'h3') ?>
      <div class="field">
        <?php $curSide = setting('sidebar_side', 'left'); ?>
        <label style="display:inline-block; margin-right:16px; font-weight:400"><input type="radio" name="sidebar_side" value="left" <?= !in_array($curSide, ['right','top'], true) ? 'checked' : '' ?>> ซ้าย</label>
        <label style="display:inline-block; margin-right:16px; font-weight:400"><input type="radio" name="sidebar_side" value="right" <?= $curSide === 'right' ? 'checked' : '' ?>> ขวา</label>
        <label style="display:inline-block; font-weight:400"><input type="radio" name="sidebar_side" value="top" <?= $curSide === 'top' ? 'checked' : '' ?>> ด้านบน (แถบแนวนอน)</label>
      </div>
    </div>
  </div>

  <div style="margin-top:16px"><button type="submit" class="btn-with-icon"><?= ui_btn_label('save', 'บันทึกการปรับแต่ง') ?></button></div>
</form>

<script>
var PRESETS = {
  v2: { color_primary:'#e11d74', color_primary_dark:'#c01862', color_sidebar:'#4e2985', color_sidebar_active:'#e11d74', color_page_bg:'#f4f1fb' },
  navy: { color_primary:'#2c4a7c', color_primary_dark:'#1d3a68', color_sidebar:'#17233a', color_sidebar_active:'#2c4a7c', color_page_bg:'#f2f4f8' },
  teal: { color_primary:'#0f766e', color_primary_dark:'#115e59', color_sidebar:'#12312e', color_sidebar_active:'#0f766e', color_page_bg:'#f1f5f4' },
  vibrant: { color_primary:'#e11d74', color_primary_dark:'#c01862', color_sidebar:'#4e2985', color_sidebar_active:'#e11d74', color_page_bg:'#f4f1fb' }
};
function applyPreset(name){
  var p = PRESETS[name]; if (!p) return;
  for (var k in p) { var el = document.getElementById('c_' + k); if (el) el.value = p[k]; }
  updateContrastChecks();
}

function hexToRgb(hex){
  hex = (hex || '').replace('#','');
  if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
  if (hex.length !== 6) return null;
  return { r: parseInt(hex.slice(0,2),16), g: parseInt(hex.slice(2,4),16), b: parseInt(hex.slice(4,6),16) };
}
function relLuminance(rgb){
  var c = [rgb.r, rgb.g, rgb.b].map(function(v){
    v = v / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}
function contrastRatio(fg, bg){
  var L1 = relLuminance(fg), L2 = relLuminance(bg);
  var lighter = Math.max(L1, L2), darker = Math.min(L1, L2);
  return (lighter + 0.05) / (darker + 0.05);
}
function bestTextOn(bgHex){
  var bg = hexToRgb(bgHex);
  if (!bg) return { color: '#ffffff', ratio: 0 };
  var white = { r:255, g:255, b:255 }, black = { r:0, g:0, b:0 };
  var rw = contrastRatio(white, bg), rb = contrastRatio(black, bg);
  return rw >= rb ? { color: '#ffffff', ratio: rw } : { color: '#000000', ratio: rb };
}
function updateContrastChecks(){
  var pairs = [
    ['color_primary', '#ffffff'],
    ['color_sidebar_active', '#ffffff'],
    ['color_sidebar', null]
  ];
  var issues = 0;
  pairs.forEach(function(pair){
    var key = pair[0], fgHex = pair[1];
    var bgEl = document.getElementById('c_' + key);
    var badge = document.getElementById('contrast_' + key);
    if (!bgEl || !badge) return;
    var bg = hexToRgb(bgEl.value);
    if (!bg) return;
    var fg = fgHex ? hexToRgb(fgHex) : hexToRgb(bestTextOn(bgEl.value).color);
    var ratio = contrastRatio(fg, bg);
    var passAA = ratio >= 4.5;
    if (!passAA) issues++;
    badge.textContent = 'AA ' + ratio.toFixed(1) + ':1';
    badge.style.background = passAA ? '#dcfce7' : '#fee2e2';
    badge.style.color = passAA ? '#15803d' : '#b91c1c';
  });
  var sidebarBg = document.getElementById('c_color_sidebar');
  var sidebarBadge = document.getElementById('contrast_color_sidebar');
  if (sidebarBg && sidebarBadge) {
    var navText = hexToRgb('#b9c6dc');
    var ratioNav = contrastRatio(navText, hexToRgb(sidebarBg.value));
    var passNav = ratioNav >= 4.5;
    if (!passNav) issues++;
    sidebarBadge.textContent = 'เมนู ' + ratioNav.toFixed(1) + ':1';
    sidebarBadge.style.background = passNav ? '#dcfce7' : '#fee2e2';
    sidebarBadge.style.color = passNav ? '#15803d' : '#b91c1c';
  }
  var hint = document.getElementById('contrast-hint');
  if (hint) {
    hint.textContent = issues
      ? 'มี ' + issues + ' จุดที่ contrast ต่ำกว่า WCAG AA (4.5:1) — ปรับสีให้ badge เป็นสีเขียว'
      : 'Contrast ผ่าน WCAG AA ทุกจุดที่ตรวจ';
  }
}
document.querySelectorAll('input[type=color]').forEach(function(el){
  el.addEventListener('input', updateContrastChecks);
});
updateContrastChecks();

// ลากจัดลำดับเมนู
(function(){
  var tbl = document.getElementById('tbl-nav');
  var dragged = null;
  tbl.addEventListener('mousedown', function(e){ var h = e.target.closest('.drag'); if (h){ h.closest('tr').setAttribute('draggable','true'); } });
  tbl.addEventListener('dragstart', function(e){ dragged = e.target.closest('tr'); if(!dragged)return; e.dataTransfer.effectAllowed='move'; try{e.dataTransfer.setData('text/plain','');}catch(_){} dragged.style.opacity='0.4'; });
  tbl.addEventListener('dragover', function(e){ if(!dragged)return; e.preventDefault(); var over=e.target.closest('tr'); if(!over||over===dragged||over.parentNode!==dragged.parentNode||over.querySelector('th'))return; var rc=over.getBoundingClientRect(); over.parentNode.insertBefore(dragged, (e.clientY-rc.top)>rc.height/2 ? over.nextSibling : over); });
  tbl.addEventListener('drop', function(e){ e.preventDefault(); });
  tbl.addEventListener('dragend', function(){ if(dragged){ dragged.style.opacity=''; dragged.removeAttribute('draggable'); dragged=null; } });
})();
</script>
<?php page_footer();
