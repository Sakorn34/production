<?php
/** layout.php — โครงหน้าเว็บ: header + sidebar + popup แจ้งเตือน/บันทึกสำเร็จ + modal เจาะลึก */

function page_header($title, $showBack = true) {
    $u = user();
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $nav = nav_effective();
    $flash = flash_get();
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
  --primary: <?= theme_color('color_primary', '#2c4a7c') ?>;
  --primary-dark: <?= theme_color('color_primary_dark', '#1d3a68') ?>;
  --sidebar-bg: <?= theme_color('color_sidebar', '#17233a') ?>;
  --sidebar-active: <?= theme_color('color_sidebar_active', '#2c4a7c') ?>;
  --page-bg: <?= theme_color('color_page_bg', '#f2f4f8') ?>;
  --logo-h: <?= max(20, min(160, (int)setting('brand_logo_h', 56))) ?>px;
  --app-font: <?= $fontCfg['font'] ?>;
}</style>
</head>
<body>
<?php
$brandLogo = setting('brand_logo');
$side = setting('sidebar_side', 'left');
$sideClass = $side === 'right' ? ' sidebar-right' : ($side === 'top' ? ' sidebar-top' : '');
?>
<div class="app<?= $sideClass ?>">
  <script>try{ if(localStorage.getItem('navHidden')==='1') document.currentScript.parentNode.classList.add('nav-hidden'); }catch(e){}</script>
  <aside class="sidebar">
    <div class="brand">
      <?php if ($brandLogo) { ?><img src="<?= h(img_url($brandLogo)) ?>" alt="โลโก้" class="brand-logo"><?php } else { ?><?= h(setting('app_name', APP_NAME)) ?><?php } ?>
    </div>
    <nav>
      <?php foreach ($nav as $n) { ?>
        <a href="<?= BASE_URL . '/' . $n['file'] ?>" class="<?= $cur === $n['file'] ? 'active' : '' ?>"><span class="nav-ico"><?= h($n['icon']) ?></span> <?= h($n['label']) ?></a>
      <?php } ?>
    </nav>
    <div class="userbox">
      <?php
        $actor = $u ? actor_name() : '';
        $showName = $actor !== '' ? $actor : ($u ? $u['display_name'] : '-');
      ?>
      <div class="ub-row">
        <div class="ub-avatar"><?= h(mb_substr(trim($showName), 0, 1)) ?></div>
        <div class="ub-info">
          <div class="ub-name"><?= h($showName) ?></div>
          <div class="muted"><?= h($u && $u['display_name'] !== '' && $u['display_name'] !== $showName ? $u['display_name'] : 'SSO') ?></div>
        </div>
      </div>
      <div class="ub-links">
        <a href="<?= BASE_URL ?>/profile.php">โปรไฟล์</a> ·
        <a href="<?= BASE_URL ?>/logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </aside>
  <div class="nav-backdrop" onclick="document.querySelector('.app').classList.remove('nav-open')"></div>
  <main class="content">
    <div class="pagehead">
      <button type="button" class="nav-toggle" onclick="toggleNav()" title="ซ่อน/แสดงเมนู">☰</button>
      <?php if ($showBack && $cur !== 'index.php') { ?>
        <a href="javascript:history.back()" class="btn btn-line btn-sm backbtn">← ย้อนกลับ</a>
      <?php } ?>
      <h1><?= h($title) ?></h1>
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
  <div class="notif-box">
    <h2>🔔 แจ้งเตือน</h2>
    <ul id="notif-list"></ul>
    <button onclick="closeOverlay('notif-overlay')">รับทราบ</button>
  </div>
</div>

<!-- popup บันทึกสำเร็จ / ข้อผิดพลาด -->
<div id="flash-overlay" class="notif-overlay" hidden>
  <div class="notif-box" style="text-align:center">
    <div id="flash-icon" style="font-size:44px; margin-bottom:8px">✅</div>
    <div id="flash-msg" style="font-size:16px; margin-bottom:16px; word-break:break-word"></div>
    <button onclick="closeOverlay('flash-overlay')">ตกลง</button>
  </div>
</div>

<!-- modal รายการเจาะลึก (dashboard ฯลฯ) — เจาะได้หลายชั้น มีปุ่มย้อนกลับ -->
<div id="list-overlay" class="notif-overlay" hidden>
  <div class="notif-box" style="width:min(820px,94vw); max-height:84vh">
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px">
      <button id="list-back" class="btn-sm btn-line" onclick="modalBack()" hidden>← ย้อน</button>
      <h2 id="list-title" style="margin:0; flex:1; font-size:16px"></h2>
      <button class="btn-sm btn-line" onclick="closeOverlay('list-overlay')">✕ ปิด</button>
    </div>
    <div id="list-body" style="overflow:auto; max-height:64vh">กำลังโหลด…</div>
    <div id="list-more" style="margin-top:10px"></div>
  </div>
</div>

<script>
function closeOverlay(id){ document.getElementById(id).hidden = true; }
function toggleNav(){
  var app = document.querySelector('.app');
  if (window.matchMedia('(max-width: 800px)').matches) { app.classList.toggle('nav-open'); return; } // จอแคบ: เปิด/ปิดลิ้นชักเมนู
  app.classList.toggle('nav-hidden');
  try { localStorage.setItem('navHidden', app.classList.contains('nav-hidden') ? '1' : '0'); } catch(e){}
}
// ปิดแถบเมนูเมื่อกดเลือกเมนู (เปิดใหม่ด้วยปุ่ม ☰)
document.querySelectorAll('.sidebar nav a').forEach(function(a){
  a.addEventListener('click', function(){
    document.querySelector('.app').classList.remove('nav-open');
    if (!window.matchMedia('(max-width: 800px)').matches) { try { localStorage.setItem('navHidden', '1'); } catch(e){} }
  });
});

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
          box.innerHTML=items.map(function(it){ return '<div data-code="'+esc(it.code)+'" data-id="'+it.id+'"><b>'+esc(it.code)+'</b> <span class="muted">'+esc(it.pname)+' · '+esc(it.status)+'</span></div>'; }).join('');
          box.hidden=false;
        }).catch(function(){ box.hidden=true; });
      },300);
    });
    box.addEventListener('click',function(e){
      var d=e.target.closest('div[data-code]'); if(!d)return;
      if(input.dataset.nav==='1'){ location.href=B+'/asset.php?id='+d.dataset.id; return; }
      input.value=d.dataset.code; box.hidden=true;
      input.dispatchEvent(new Event('change',{bubbles:true}));
      input.focus();
    });
    document.addEventListener('click',function(e){ if(!wrap.contains(e.target)) box.hidden=true; });
  });
})();

// popup บันทึกสำเร็จ
(function(){
  if (!window.__flash) return;
  document.getElementById('flash-icon').textContent = window.__flash.type === 'err' ? '⚠️' : '✅';
  document.getElementById('flash-msg').textContent = window.__flash.msg;
  document.getElementById('flash-overlay').hidden = false;
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
    document.getElementById('notif-overlay').hidden = false;
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
  overlay.hidden = false;
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
      dd.querySelector('.chip-dd-box').addEventListener('click', function(e){
        if (!e.target.closest('.chip-dd-btn')) filt.focus();
      });
    });
  };
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.chip-dd-btn');
    if (btn){
      var dd = btn.closest('.chip-dd');
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
