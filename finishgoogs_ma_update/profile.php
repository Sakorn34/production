<?php
/**
 * profile.php — แสดงข้อมูลจาก session profile (ไม่มีระบบรหัสผ่านภายใน)
 * ชื่อที่ใช้บันทึกรายการ = login_name (เช่น Tom)
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$u = user();
page_header('โปรไฟล์');
?>
<div class="panel" style="max-width:480px">
  <dl>
    <dt>ชื่อที่ใช้บันทึกในระบบ</dt><dd><b><?= h(actor_name()) ?></b> <span class="muted">(login name)</span></dd>
    <dt>ชื่อแสดง</dt><dd><?= h($u['display_name']) ?></dd>
    <dt>Login name</dt><dd><?= h($u['username'] !== '' ? $u['username'] : '-') ?></dd>
    <dt>แหล่งยืนยันตัวตน</dt><dd>SSO (session profile)</dd>
  </dl>
  <p class="muted" style="margin-top:12px; font-size:13px">รายการที่บันทึกจะใช้ชื่อ <b><?= h(actor_name()) ?></b> ตาม login name จากบัญชีองค์กร</p>
</div>
<?php page_footer();
