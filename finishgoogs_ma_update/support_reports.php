<?php
/**
 * support_reports.php — เรื่องที่แจ้งเข้ามาจากปุ่ม "แจ้งปัญหา" (ระบบหลังบ้าน)
 *
 * รายการ: กรองตามสถานะ (ยังไม่แก้ · แก้แล้ว · ทั้งหมด)
 * ?id=N : รายละเอียดเรื่อง — ข้อความ · รูปขนาดเต็ม · หน้าที่แจ้ง · เวอร์ชัน · เปลี่ยนสถานะ + บันทึกของผู้ดูแล
 * ลิงก์ในข้อความ LINE ที่ส่งถึงผู้ดูแลพามาที่หน้ารายละเอียดนี้
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/support_report.php';
require_login();
ensure_support_reports_schema();

$B = BASE_URL;
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    csrf_check();
    $st = (string) ($_POST['status'] ?? '');
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    if (isset(SUPPORT_STATUSES[$st])) {
        qr('UPDATE support_reports SET status = ?, status_by = ?, status_at = NOW(), admin_note = ? WHERE id = ?',
           'sssi', [$st, actor_name(), $note !== '' ? $note : null, $id]);
        flash_set('เรื่อง #' . $id . ' — ' . SUPPORT_STATUSES[$st]);
    }
    header('Location: ' . $B . '/support_reports.php?id=' . $id);
    exit;
}

/** ป้ายสถานะเรื่อง (ไม่ใช่สถานะเครื่อง — ไม่เกี่ยวกับ palette สถานะเครื่อง) */
function support_status_badge(string $st): string
{
    return '<span class="sr-badge sr-' . h($st) . '">' . h(SUPPORT_STATUSES[$st] ?? $st) . '</span>';
}

if ($id > 0) {
    $r = qr('SELECT * FROM support_reports WHERE id = ?', 'i', [$id])->fetch_assoc();
    if (!$r) {
        http_response_code(404);
        page_header('ไม่พบเรื่อง', true, '', $B . '/support_reports.php');
        echo '<div class="panel"><p class="muted">ไม่พบเรื่อง #' . $id . '</p></div>';
        page_footer();
        exit;
    }
    $imgs = json_decode((string) $r['images_json'], true) ?: [];
    page_header('เรื่อง #' . $id, true, 'แจ้งโดย ' . $r['reporter'] . ' · ' . date('d/m/Y H:i', strtotime((string) $r['created_at'])), $B . '/support_reports.php');
    ?>
<div class="sr-detail">
  <div class="panel">
    <div class="sr-head"><?= support_status_badge((string) $r['status']) ?>
      <?php if ($r['status_by']) { ?><span class="muted">โดย <?= h($r['status_by']) ?> · <?= h(date('d/m/Y H:i', strtotime((string) $r['status_at']))) ?></span><?php } ?>
    </div>
    <p class="sr-msg"><?= nl2br(h((string) $r['message'] !== '' ? (string) $r['message'] : '(แนบรูปอย่างเดียว)')) ?></p>
    <?php if ($imgs) { ?>
    <div class="sr-imgs">
      <?php foreach ($imgs as $im) { $full = $B . '/uploads/' . $im['full']; $pv = $B . '/uploads/' . ($im['preview'] ?: $im['full']); ?>
      <a href="<?= h($full) ?>" target="_blank" rel="noopener"><img src="<?= h($pv) ?>" alt="รูปที่แนบ" loading="lazy"></a>
      <?php } ?>
    </div>
    <?php } ?>
    <dl class="sr-meta">
      <dt>หน้าที่แจ้ง</dt>
      <dd><?= h((string) $r['page_title'] ?: '-') ?>
        <?php if ($r['page_url'] !== '') { ?> · <a href="<?= h((string) $r['page_url']) ?>" target="_blank" rel="noopener">เปิดหน้านั้น ›</a><?php } ?></dd>
      <dt>เวอร์ชันระบบ</dt><dd><?= h((string) $r['app_version'] ?: '-') ?></dd>
      <dt>ซิงก์สถานะล่าสุด</dt><dd><?= h((string) $r['sync_at'] ?: '-') ?></dd>
      <dt>ส่ง LINE</dt><dd><?= h((string) ($r['line_result'] ?? '-')) ?></dd>
    </dl>
  </div>
  <form method="post" class="panel sr-form">
    <?= csrf_field() ?>
    <h3>เปลี่ยนสถานะ</h3>
    <div class="sr-status-btns">
      <?php foreach (SUPPORT_STATUSES as $k => $label) { ?>
      <label class="sr-choice"><input type="radio" name="status" value="<?= h($k) ?>"<?= $r['status'] === $k ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
      <?php } ?>
    </div>
    <label for="sr-note" class="muted">บันทึกของผู้ดูแล (ถ้ามี)</label>
    <textarea name="admin_note" id="sr-note" rows="3" placeholder="เช่น แก้ใน patch วันที่ ... / สาเหตุคือ ..."><?= h((string) ($r['admin_note'] ?? '')) ?></textarea>
    <button type="submit" class="btn">บันทึกสถานะ</button>
  </form>
</div>
<?php
} else {
    $f = (string) ($_GET['f'] ?? 'open');
    $where = $f === 'done' ? "WHERE status = 'done'" : ($f === 'all' ? '' : "WHERE status <> 'done'");
    $rows = [];
    $res = db()->query("SELECT id, created_at, reporter, page_title, message, images_json, status FROM support_reports $where ORDER BY id DESC LIMIT 300");
    while ($x = $res->fetch_assoc()) {
        $rows[] = $x;
    }
    $cnt = db()->query("SELECT SUM(status <> 'done') open_n, SUM(status = 'done') done_n, COUNT(*) all_n FROM support_reports")->fetch_assoc();
    page_header('เรื่องที่แจ้งเข้ามา', true, 'จากปุ่ม "แจ้งปัญหา" ในเมนู · ส่งถึง ' . SUPPORT_CONTACT_LABEL . ' ทาง LINE', $B . '/settings.php');
    ?>
<div class="sr-filters">
  <?php foreach (['open' => 'ยังไม่แก้', 'done' => 'แก้แล้ว', 'all' => 'ทั้งหมด'] as $k => $label) {
      $n = (int) ($cnt[$k . '_n'] ?? 0); ?>
  <a class="sr-chip<?= $f === $k ? ' is-on' : '' ?>" href="<?= h($B . '/support_reports.php?f=' . $k) ?>" data-same-tab><?= h($label) ?> (<?= number_format($n) ?>)</a>
  <?php } ?>
</div>
<?php if (!$rows) { ?>
<div class="panel"><p class="muted" style="margin:0">ไม่มีเรื่องในหมวดนี้</p></div>
<?php } else { ?>
<div class="sr-list">
  <?php foreach ($rows as $x) { $n = count(json_decode((string) $x['images_json'], true) ?: []); ?>
  <a class="sr-item" href="<?= h($B . '/support_reports.php?id=' . (int) $x['id']) ?>" data-same-tab>
    <span class="sr-item-top"><b>#<?= (int) $x['id'] ?></b> <?= support_status_badge((string) $x['status']) ?>
      <span class="muted"><?= h(date('d/m/Y H:i', strtotime((string) $x['created_at']))) ?> · <?= h((string) $x['reporter']) ?></span></span>
    <span class="sr-item-msg"><?= h(mb_strimwidth((string) $x['message'] !== '' ? (string) $x['message'] : '(แนบรูปอย่างเดียว)', 0, 140, '…')) ?></span>
    <span class="muted sr-item-meta">หน้า: <?= h((string) $x['page_title'] ?: '-') ?><?= $n ? ' · 📎 ' . $n . ' รูป' : '' ?></span>
  </a>
  <?php } ?>
</div>
<?php }
}
?>
<style>
.sr-badge { display: inline-block; padding: 1px 10px; border-radius: 999px; font-size: calc(12px * var(--font-scale, 1)); font-weight: 600; }
.sr-new { background: var(--warning-soft, #fef3c7); color: var(--warning, #92400e); }
.sr-doing { background: var(--info-soft, #dbeafe); color: var(--info, #1d4ed8); }
.sr-done { background: var(--success-soft, #dcfce7); color: var(--success, #166534); }
.sr-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.sr-chip { display: inline-flex; align-items: center; min-height: 36px; padding: 4px 14px; border-radius: 999px; border: 1px solid var(--border-strong, #d6cfe6); background: var(--surface, #fff); color: var(--text-muted); text-decoration: none; }
.sr-chip.is-on { background: var(--primary-soft, #fce7f3); border-color: var(--primary); color: var(--primary); font-weight: 600; }
.sr-list { display: flex; flex-direction: column; gap: 8px; }
.sr-item { display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius, 12px); color: inherit; text-decoration: none; }
.sr-item:hover { border-color: var(--primary); }
.sr-item-top { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sr-item-msg { line-height: 1.5; }
.sr-item-meta { font-size: calc(12px * var(--font-scale, 1)); }
.sr-detail { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
.sr-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
.sr-msg { font-size: calc(15px * var(--font-scale, 1)); line-height: 1.6; margin: 0 0 12px; white-space: normal; }
.sr-imgs { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.sr-imgs img { width: 160px; height: 160px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border); display: block; }
.sr-meta { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; margin: 0; font-size: calc(13px * var(--font-scale, 1)); }
.sr-meta dt { color: var(--text-muted); }
.sr-meta dd { margin: 0; overflow-wrap: anywhere; }
.sr-form { display: flex; flex-direction: column; gap: 10px; }
.sr-form h3 { margin: 0; }
.sr-status-btns { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.sr-choice { position: relative; }
.sr-choice input { position: absolute; opacity: 0; }
.sr-choice span { display: flex; align-items: center; justify-content: center; min-height: 44px; border: 1px solid var(--border-strong, #d6cfe6); border-radius: 10px; cursor: pointer; text-align: center; font-size: calc(13px * var(--font-scale, 1)); }
.sr-choice input:checked + span { background: var(--primary-soft, #fce7f3); border-color: var(--primary); color: var(--primary); font-weight: 600; }
.sr-choice input:focus-visible + span { outline: 2px solid var(--focus-ring, var(--primary)); outline-offset: 2px; }
.sr-form textarea { width: 100%; box-sizing: border-box; }
@media (max-width: 860px) {
  .sr-detail { grid-template-columns: minmax(0, 1fr); }
  .sr-imgs img { width: calc(50vw - 40px); height: calc(50vw - 40px); max-width: 180px; max-height: 180px; }
}
</style>
<?php page_footer();
