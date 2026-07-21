<?php
/**
 * includes/settings_gate.php — ประตูรหัสผ่านหน้า settings.php
 *
 * ทุกคนต้องใส่ PIN 9981 ก่อนเข้า ยกเว้น login_name = Tom
 * รองรับปุ่มตัวเลขบนหน้าจอและพิมพ์จากคีย์บอร์ด
 */

/** @var string รหัส PIN ที่ถูกต้อง */
const SETTINGS_ACCESS_PIN = '9981';

/**
 * ตรวจว่าเข้าหน้าตั้งค่าได้แล้วหรือยัง (Tom ข้าม PIN อัตโนมัติ)
 *
 * @return bool
 */
function settings_access_granted() {
    return settings_admin_unlocked();
}

/**
 * บังคับใส่ PIN ก่อนเข้า settings — แสดงหน้า keypad แล้ว exit ถ้ายังไม่ผ่าน
 *
 * @return void
 */
function require_settings_access() {
    require_login();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_pin_unlock'])) {
        csrf_check();
        $pin = preg_replace('/\D/', '', (string)(isset($_POST['pin']) ? $_POST['pin'] : ''));
        if ($pin === SETTINGS_ACCESS_PIN) {
            $_SESSION['settings_unlocked'] = time();
            $dest = BASE_URL . '/settings.php';
            if (!empty($_GET['product'])) {
                $dest .= '?product=' . (int)$_GET['product'];
            }
            header('Location: ' . $dest);
            exit;
        }
        settings_gate_render(true);
        exit;
    }

    if (!settings_access_granted()) {
        settings_gate_render(false);
        exit;
    }
}

/**
 * แสดงหน้าใส่รหัส PIN แบบปุ่มตัวเลข
 *
 * @param bool $wrong แสดงข้อความ PIN ไม่ถูกต้อง
 * @return void
 */
function settings_gate_render($wrong = false) {
    require __DIR__ . '/layout.php';
    page_header('เข้าสู่ระบบหลังบ้าน', false);
    ?>
<style>
.pin-gate { max-width: 360px; margin: 24px auto; text-align: center; }
.pin-display {
  font-size: 28px; letter-spacing: 8px; font-weight: 700;
  padding: 14px 16px; border: 2px solid var(--border,#dde3ec); border-radius: 10px;
  background: var(--card,#fff); min-height: 52px; margin-bottom: 16px;
  color: #1f2430;
  font-variant-numeric: tabular-nums;
}
.pin-display .pin-slot { display: inline-block; min-width: 1.1em; text-align: center; }
.pin-display .pin-slot.is-empty { color: #c4cad4; font-weight: 500; }
.pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.pin-pad button {
  font-size: 22px; padding: 16px 0; border-radius: 10px; border: 1px solid var(--border,#dde3ec);
  background: var(--card,#fff); cursor: pointer; font-weight: 600;
  color: #1f2430; /* ต้อง override — global button เป็นตัวอักษรขาว จะกลืนกับพื้นขาวจนมองไม่เห็นเลข */
}
.pin-pad button:hover { background: #f4f1fb; }
.pin-pad button:active { transform: scale(0.97); }
.pin-pad .pin-wide { grid-column: span 2; }
.pin-err { color: #b91c1c; margin-bottom: 10px; font-weight: 600; }
</style>
<div class="panel pin-gate">
  <div style="font-size: 40px; margin-bottom: 8px">🔐</div>
  <h2 style="margin: 0 0 6px">ระบบหลังบ้าน</h2>
  <p class="muted" style="margin-bottom: 16px">กรุณาใส่รหัส 4 หลักเพื่อเข้าหน้าตั้งค่า<br>กดปุ่มบนหน้าจอหรือพิมพ์ตัวเลขจากคีย์บอร์ดได้</p>
  <?php if ($wrong) { ?><div class="pin-err">รหัสไม่ถูกต้อง กรุณาลองใหม่</div><?php } ?>
  <form method="post" id="pin-form">
    <?= csrf_field() ?>
    <input type="hidden" name="settings_pin_unlock" value="1">
    <input type="hidden" name="pin" id="pin-input" value="" maxlength="4" autocomplete="off">
    <div class="pin-display" id="pin-display" aria-live="polite" aria-label="รหัสที่กรอก"></div>
    <div class="pin-pad">
      <?php for ($d = 1; $d <= 9; $d++) { ?>
        <button type="button" data-digit="<?= $d ?>"><?= $d ?></button>
      <?php } ?>
      <button type="button" id="pin-clear" title="ล้าง">⌫</button>
      <button type="button" data-digit="0">0</button>
      <button type="submit" class="pin-wide" style="background:var(--primary); color:#fff; border-color:var(--primary)">เข้าใช้งาน</button>
    </div>
  </form>
  <p class="muted" style="margin-top: 14px; font-size: 12px">ผู้ใช้ Tom เข้าได้โดยไม่ต้องใส่รหัส</p>
</div>
<script>
(function(){
  var pin = '', max = 4;
  var input = document.getElementById('pin-input');
  var display = document.getElementById('pin-display');
  function render(){
    var html = '';
    for (var i = 0; i < max; i++) {
      var filled = i < pin.length;
      html += '<span class="pin-slot' + (filled ? '' : ' is-empty') + '">'
        + (filled ? pin.charAt(i) : '–') + '</span>';
    }
    display.innerHTML = html;
  }
  function addDigit(d){
    if (pin.length >= max) return;
    pin += d;
    input.value = pin;
    render();
    if (pin.length === max) document.getElementById('pin-form').requestSubmit();
  }
  document.querySelectorAll('[data-digit]').forEach(function(btn){
    btn.addEventListener('click', function(){ addDigit(btn.dataset.digit); });
  });
  document.getElementById('pin-clear').addEventListener('click', function(){
    pin = pin.slice(0, -1);
    input.value = pin;
    render();
  });
  document.addEventListener('keydown', function(e){
    if (e.key >= '0' && e.key <= '9') { e.preventDefault(); addDigit(e.key); return; }
    if (e.key === 'Backspace') { e.preventDefault(); pin = pin.slice(0, -1); input.value = pin; render(); return; }
    if (e.key === 'Enter' && pin.length) { e.preventDefault(); document.getElementById('pin-form').requestSubmit(); }
  });
  render();
})();
</script>
    <?php
    page_footer();
}
