<?php
/**
 * installation_history.php — นำเข้าประวัติติดตั้งจากระบบ installation เดิม (หน้าหลังบ้าน)
 *
 * server เปิดไฟล์ Access บน LAN ไม่ได้ จึงให้ export เป็น CSV บนเครื่อง LAN ก่อน
 * (database/tools/export_installation_history.ps1) แล้วอัปโหลดไฟล์ที่นี่
 * หน้านี้บอกด้วยว่าจับคู่กับทะเบียนได้กี่เครื่อง และเครื่องไหนจะเปลี่ยนเป็น "ขายแล้ว"
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/asset_status_sync.php';
require_once __DIR__ . '/includes/installation_history.php';
require_login();

$B = BASE_URL;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['import'])) {
        $f = $_FILES['csv'] ?? null;
        if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $err = $f ? (int) $f['error'] : -1;
            flash_set($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                ? 'ไฟล์ใหญ่เกินที่ server รับได้ — ใช้ไฟล์ .csv.gz ที่สคริปต์ export สร้างให้'
                : 'อัปโหลดไฟล์ไม่สำเร็จ', 'err');
            header('Location: ' . $B . '/installation_history.php'); exit;
        }
        @set_time_limit(300);
        $r = installation_history_import_csv($f['tmp_name']);
        flash_set($r['ok']
            ? 'นำเข้าแล้ว ' . number_format($r['rows_saved']) . ' serial จาก ' . number_format($r['jobs']) . ' งาน · ข้าม ' . number_format($r['skipped']) . ' แถวที่ไม่มี serial ที่ใช้ได้'
            : 'นำเข้าไม่สำเร็จ: ' . $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . $B . '/installation_history.php'); exit;
    }
    if (isset($_POST['apply']) && installation_history_available()) {
        @set_time_limit(300);
        $d = installation_history_apply();
        flash_set('เปลี่ยนเป็นขายแล้ว ' . number_format($d['new'] + $d['unknown']) . ' เครื่อง (จากใหม่ ' . number_format($d['new'])
            . ' · จากไม่มีสถานะ ' . number_format($d['unknown']) . ') — ย้อนกลับได้ที่หน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ');
        header('Location: ' . $B . '/installation_history.php'); exit;
    }
}

$has = installation_history_available();
$meta = json_decode((string) setting(INSTALLATION_HISTORY_META_KEY, ''), true);
$stat = null;
$candidates = [];
if ($has) {
    $stat = ['rows' => 0, 'serials' => 0, 'jobs' => 0, 'multi' => 0, 'by_status' => [], 'matched' => 0];
    $x = qr('SELECT COUNT(*), COUNT(DISTINCT serial), COUNT(DISTINCT install_id), MIN(COALESCE(sale_date, install_date)), MAX(COALESCE(sale_date, install_date)) FROM installation_history')->fetch_row();
    list($stat['rows'], $stat['serials'], $stat['jobs'], $stat['from'], $stat['to']) = $x;
    $stat['multi'] = (int) qr('SELECT COUNT(*) FROM (SELECT serial FROM installation_history GROUP BY serial HAVING COUNT(DISTINCT install_id) > 1) t')->fetch_row()[0];
    $matchedIds = array_keys(installation_history_matched_ids());
    if ($matchedIds) {
        $res = qr('SELECT status, COUNT(*) n FROM assets WHERE id IN (' . implode(',', $matchedIds) . ') GROUP BY status ORDER BY n DESC');
        while ($r = $res->fetch_assoc()) {
            $stat['by_status'][$r['status']] = (int) $r['n'];
            $stat['matched'] += (int) $r['n'];
        }
    }

    // เครื่องใหม่ / ไม่มีสถานะ ที่มีประวัติติดตั้ง — แสดงว่าแต่ละเครื่องจะเปลี่ยนหรือไม่ เพราะอะไร
    @set_time_limit(120);
    $candidates = installation_history_candidates();
}
$willCount = count(array_filter($candidates, function ($c) { return $c['will']; }));

page_header('ประวัติติดตั้งระบบเดิม', true, 'นำเข้าข้อมูลงานติดตั้งจากระบบ installation (MS Access · ปี 2010–2023) มาแสดงบนหน้าเครื่องและใช้ยืนยันการขาย', $B . '/settings.php');
?>
<div class="panel ih-intro">
  <b>ขั้นตอน</b>
  <ol>
    <li>บนเครื่องที่เปิดไฟล์ <code>installation.mdb</code> ได้ (LAN 192.168.2.199) รัน
      <code>database\tools\export_installation_history.ps1</code> — ได้ไฟล์ <code>installation_history.csv</code> และ <code>.csv.gz</code></li>
    <li>อัปโหลดไฟล์ <b>.csv.gz</b> ด้านล่าง (ข้อมูลเดิมในตารางจะถูกแทนที่ทั้งหมด)</li>
    <li>ตรวจรายการด้านล่าง แล้วกดปุ่ม <b>"เปลี่ยนเป็นขายแล้ว N เครื่อง"</b> ที่หัวรายการ (ปุ่มขึ้นเมื่อมีเครื่องที่เข้าเงื่อนไข) — เครื่องสถานะใหม่ sync ทุกคืนทำให้เองด้วย</li>
  </ol>
  <form method="post" enctype="multipart/form-data" class="ih-upload">
    <?= csrf_field() ?>
    <input type="file" id="ih-csv" name="csv" accept=".csv,.gz,text/csv" required>
    <button type="submit" name="import" value="1" class="btn btn-primary btn-sm">นำเข้า</button>
  </form>
  <?php if (is_array($meta)) { ?>
  <p class="muted" style="margin:6px 0 0;font-size:13px">นำเข้าล่าสุด <?= h(dthai((string) ($meta['imported_at'] ?? ''))) ?> · อ่าน <?= number_format((int) ($meta['rows_read'] ?? 0)) ?> แถว · ข้าม <?= number_format((int) ($meta['skipped'] ?? 0)) ?> แถวที่ไม่มี serial ที่ใช้ได้</p>
  <?php } ?>
</div>

<?php if (!$has || !$stat) { ?>
<div class="panel"><p class="muted" style="margin:0">ยังไม่มีข้อมูลนำเข้า</p></div>
<?php } else { ?>
<div class="ih-stats">
  <div class="panel"><span class="muted">serial ที่ใช้ได้</span><b><?= number_format((int) $stat['serials']) ?></b><span class="muted"><?= number_format((int) $stat['jobs']) ?> งาน · <?= h(dthai((string) $stat['from'])) ?> – <?= h(dthai((string) $stat['to'])) ?></span></div>
  <div class="panel"><span class="muted">จับคู่กับทะเบียนเราได้</span><b><?= number_format($stat['matched']) ?> เครื่อง</b>
    <span><?php foreach ($stat['by_status'] as $st => $n) { echo status_badge($st) . ' ' . number_format($n) . ' '; } ?></span></div>
  <div class="panel"><span class="muted">S/N ที่พบหลายงาน</span><b><?= number_format((int) $stat['multi']) ?></b><span class="muted">แสดงบนหน้าเครื่อง แต่ไม่ใช้ตัดสถานะ</span></div>
</div>

<div class="panel">
  <div class="ih-head">
    <div><b>เครื่องใหม่ / ไม่มีสถานะ ที่มีประวัติติดตั้ง</b> <span class="muted">(<?= number_format(count($candidates)) ?> เครื่อง · จะเปลี่ยนเป็นขายแล้ว <?= number_format($willCount) ?>)</span></div>
    <?php if ($willCount > 0) { ?>
    <form method="post" onsubmit="return confirm('เปลี่ยน <?= (int) $willCount ?> เครื่องเป็น &quot;ขายแล้ว&quot; ตามประวัติติดตั้ง?\n\nย้อนกลับได้ที่หน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ');">
      <?= csrf_field() ?>
      <button type="submit" name="apply" value="1" class="btn btn-primary btn-sm">เปลี่ยนเป็นขายแล้ว <?= number_format($willCount) ?> เครื่อง</button>
    </form>
    <?php } ?>
  </div>
  <?php if (!$candidates) { ?>
  <p class="muted" style="margin:8px 0 0">ไม่มีเครื่องสถานะใหม่หรือไม่มีสถานะ ที่มีประวัติติดตั้ง — ไม่มีอะไรต้องอัปเดต</p>
  <?php } else { ?>
  <div class="table-wrap ih-scroll">
  <table class="list" style="margin:0">
    <thead><tr><th data-pri="1">S/N</th><th data-pri="2">รุ่น</th><th data-pri="2">ตอนนี้</th><th data-pri="3">ผลิต</th><th data-pri="1">ผล</th></tr></thead>
    <tbody>
    <?php foreach ($candidates as $c) { ?>
      <tr>
        <td data-pri="1"><a href="<?= h($B . '/asset.php?id=' . (int) $c['id']) ?>" target="_blank" rel="noopener"><b><?= h((string) $c['asset_code']) ?></b></a>
          <div class="cell-sub"><?= h((string) $c['pname']) ?> · ผลิต <?= h(dthai(substr((string) $c['produced_at'], 0, 10))) ?></div></td>
        <td data-pri="2"><?= h((string) $c['pname']) ?></td>
        <td data-pri="2"><?= status_badge((string) $c['status']) ?></td>
        <td data-pri="3" data-nowrap><?= h(dthai(substr((string) $c['produced_at'], 0, 10))) ?></td>
        <td data-pri="1"><?= $c['will'] ? '→ ' . status_badge('sold') : '<span class="muted">คงเดิม</span>' ?>
          <div class="muted" style="font-size:12px"><?= h($c['why']) ?></div></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
  </div>
  <?php } ?>
</div>
<?php } ?>

<style>
.ih-intro ol { margin: 6px 0 12px; padding-left: 20px; line-height: 1.8; }
.ih-upload { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ih-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 12px; }
.ih-stats .panel { display: flex; flex-direction: column; gap: 4px; margin: 0; }
.ih-stats b { font-size: 22px; }
.ih-head { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.ih-scroll { max-height: min(640px, calc(100vh - 160px)); overflow: auto; border: 1px solid var(--border); border-radius: var(--radius, 12px); }
.ih-scroll table th { position: sticky; top: 0; z-index: 2; }
@media (max-width: 760px) { .ih-scroll { max-height: none; overflow: visible; border: 0; } }
</style>
<?php page_footer();
