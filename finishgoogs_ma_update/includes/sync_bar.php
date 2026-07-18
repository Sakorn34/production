<?php
/**
 * sync_bar.php — แถบปุ่มซิงก์/import stock (ใช้เฉพาะใน share_admin.php)
 */
$syncStats = share_sync_stats();
?>
<div class="sync-bar" id="share-sync-bar">
  <?php if ($syncStats['needs_sync']) { ?>
  <span class="sync-hint muted">
    stock ไม่ครบ <?= number_format($syncStats['incomplete']) ?> รายการ
    <?php if ($syncStats['missing_in_stock'] > 0) { ?>
    · ขาด <?= number_format($syncStats['missing_in_stock']) ?> เครื่อง
    <?php } ?>
  </span>
  <?php } ?>
  <button type="button" class="btn btn-sm btn-line" id="btn-share-sync" title="ซิงก์ผู้ผลิต วันที่ผลิต และรุ่นจากทะเบียนเครื่องผลิต">
    🔄 Sync ข้อมูลที่ยังไม่ครบ
  </button>
  <a href="<?= BASE_URL ?>/share_admin.php#import-basic" class="btn btn-sm btn-line" title="Import CSV: timestamp, serial_number, ผู้ผลิต">📥 Import stock</a>
  <span id="sync-status" class="muted" style="font-size:12px"></span>
</div>
<script>
(function(){
  var btn = document.getElementById('btn-share-sync');
  if (!btn) return;
  btn.addEventListener('click', function(){
    if (btn.disabled) return;
    if (!confirm('ซิงก์ข้อมูล stock จากทะเบียนเครื่องผลิต?\n(เพิ่มรายการที่ขาด + อัปเดตผู้ผลิต/วันที่/รุ่นที่ยังว่าง)')) return;
    btn.disabled = true;
    var st = document.getElementById('sync-status');
    st.textContent = 'กำลังซิงก์…';
    var fd = new FormData();
    fd.append('csrf', <?= json_encode(csrf()) ?>);
    fetch(<?= json_encode(BASE_URL . '/sync_data.php') ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        btn.disabled = false;
        if (!d.ok) { st.textContent = d.error || 'ซิงก์ไม่สำเร็จ'; return; }
        var r = d.result || {};
        st.textContent = 'เพิ่ม ' + (r.added || 0) + ' · อัปเดต ' + (r.updated_meta || 0)
          + ' · เหลือไม่ครบ ' + (r.incomplete_remaining || 0);
        if (window.__flashToast) window.__flashToast('ซิงก์ข้อมูล stock แล้ว', 'ok');
      })
      .catch(function(){
        btn.disabled = false;
        st.textContent = 'เชื่อมต่อไม่สำเร็จ';
      });
  });
})();
</script>
