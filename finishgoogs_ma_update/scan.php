<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();
page_header('สแกน QR / Barcode');
?>
<p class="muted">สแกนรหัสบนตัวเครื่องด้วยกล้อง หรือพิมพ์รหัสด้านล่าง (ใช้ได้กับเครื่องยิงบาร์โค้ด USB ด้วย — ยิงแล้วกด Enter)</p>

<form class="filter" method="get" action="<?= BASE_URL ?>/asset.php">
  <input type="text" name="code" id="manual-code" class="asset-search" data-nav="1" placeholder="พิมพ์รหัสเครื่องแล้วเลือก เช่น BP23021294" style="width:260px" autofocus>
  <button type="submit">เปิดหน้าเครื่อง</button>
</form>

<div id="reader" style="max-width:420px; background:#fff; border:1px solid #dfe4ec; border-radius:8px; padding:10px"></div>
<p class="muted" id="cam-status">กำลังโหลดตัวสแกน… (ต้องอนุญาตใช้กล้อง และต้องเปิดผ่าน localhost หรือ https)</p>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  if (typeof Html5Qrcode === 'undefined') {
    document.getElementById('cam-status').textContent = 'โหลดตัวสแกนไม่ได้ (ไม่มีอินเทอร์เน็ต) — ใช้ช่องพิมพ์รหัสด้านบนแทนได้';
    return;
  }
  var scanner = new Html5Qrcode('reader');
  scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 },
    function(text){
      scanner.stop();
      window.location = '<?= BASE_URL ?>/asset.php?code=' + encodeURIComponent(text.trim());
    }, function(){}
  ).then(function(){
    document.getElementById('cam-status').textContent = 'เล็งกล้องไปที่ QR/Barcode บนตัวเครื่อง';
  }).catch(function(e){
    document.getElementById('cam-status').textContent = 'เปิดกล้องไม่ได้: ' + e + ' — ใช้ช่องพิมพ์รหัสแทนได้';
  });
})();
</script>
<?php page_footer();
