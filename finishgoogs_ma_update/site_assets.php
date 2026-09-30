<?php
/**
 * site_assets.php — ทะเบียนเครื่องตามไซต์งาน (30 ก.ย. 2569)
 *
 * ค้นชื่อไซต์งานด้วยคำบางส่วน แล้วดูว่าไซต์นั้นมีเครื่องอะไรอยู่บ้าง
 * ชื่อไซต์เป็นข้อความอิสระและสะกดไม่ตรงกันข้ามระบบ จึงแยกผลตามแหล่งที่มา
 * ไม่รวมชื่อให้เอง — รายละเอียดอยู่ใน includes/site_assets.php
 *
 * อ่านอย่างเดียวทั้งหน้า ไม่เขียนอะไรกลับไปทั้งฝั่งเช่า ฝั่ง stock และฝั่ง setup
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/site_assets.php';
require_login();

$B = BASE_URL;
$q    = trim((string) ($_GET['q'] ?? ''));
$src  = trim((string) ($_GET['src'] ?? ''));
$site = trim((string) ($_GET['site'] ?? ''));
$srcs = site_assets_sources();
$detail = $src !== '' && $site !== '' && isset($srcs[$src]);

$rows = [];
if ($detail) {
    $rows = site_assets_attach_ours(site_assets_at($src, $site));
}
$hits = (!$detail && $q !== '') ? site_assets_search($q) : [];
$top  = (!$detail && $q === '') ? site_assets_top() : [];

page_header('ทะเบียนตามไซต์งาน', true,
    $detail ? $site : 'ไซต์งานนี้ใช้เครื่องอะไรบ้าง', $B . '/settings.php');
?>
<form method="get" class="sa-search">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="พิมพ์ชื่อไซต์งานบางส่วน เช่น ซิลลิค · บริดจสโตน · ชีวาทัย" autofocus>
  <button type="submit" class="btn btn-with-icon"><?= ui_btn_label('search', 'ค้นหา') ?></button>
  <?php if ($detail) { ?><a class="btn btn-line" href="<?= $B ?>/site_assets.php">เริ่มใหม่</a><?php } ?>
</form>

<p class="muted sa-lead">
  ชื่อไซต์งานถูกบันทึกไว้ 3 ที่ และเป็นข้อความอิสระทั้งหมด
  <b>ลูกค้าเจ้าเดียวกันมักสะกดคนละแบบในแต่ละระบบ</b> — ระบบจึงไม่รวมชื่อให้เอง
  ค้นด้วยคำสั้น ๆ เช่น “ซิลลิค” จะเจอทุกแบบที่เขียนไว้
</p>

<?php if ($detail) { ?>

<div class="sa-sitehead">
  <span class="asset-site-src"><?= h($srcs[$src]['th']) ?></span>
  <b><?= h($site) ?></b>
  <span class="muted">· <?= h($srcs[$src]['sub']) ?> · <?= number_format(count($rows)) ?> เครื่อง</span>
</div>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css) ?>
  <thead>
  <tr>
    <th data-pri="1">รหัสเครื่อง</th><th data-pri="1">รุ่น</th>
    <th data-pri="2">สถานะในทะเบียนเรา</th><th data-pri="1">รายละเอียด</th><th data-pri="2">วันที่</th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$rows) { ?>
  <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">ไม่พบเครื่องที่ไซต์งานนี้</td></tr>
  <?php } ?>
  <?php foreach ($rows as $r) { ?>
  <tr>
    <td data-pri="1"><?php if ($r['asset_id'] > 0) { ?><a href="<?= $B ?>/asset.php?id=<?= (int) $r['asset_id'] ?>"><b><?= h($r['sn']) ?></b></a><?php } else { ?><b><?= h($r['sn']) ?></b><?php } ?></td>
    <td data-pri="1"><?= h($r['pname'] !== '' ? $r['pname'] : '—') ?></td>
    <td data-pri="2"><?php if ($r['status'] !== '') { ?>
      <span class="badge" style="<?= h(status_badge_style($r['status'])) ?>"><?= h(status_palette_entry($r['status'])['th']) ?></span>
    <?php } else { ?><span class="muted">ไม่มีในทะเบียนเรา</span><?php } ?></td>
    <td data-pri="1"><?= h(trim(implode(' · ', array_filter([(string) $r['tag'], (string) $r['note']])))) ?></td>
    <td data-pri="2" class="muted"><?= h($r['date'] !== '' ? dthai($r['date']) : '—') ?></td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<p class="muted sa-foot">
  <b>ไม่มีในทะเบียนเรา</b> = เครื่องที่ระบบอื่นบันทึกไว้ที่ไซต์นี้ แต่ยังไม่เคยลงทะเบียนในระบบผลิต
  (อุปกรณ์ที่ซื้อมาขายต่อ หรือเครื่องเก่าก่อนเริ่มใช้ระบบ) · หน้านี้อ่านอย่างเดียว ไม่แก้ข้อมูลฝั่งไหน
</p>

<?php } else { ?>

<?php $list = $hits ?: $top; ?>
<?php if ($q !== '' && !$hits) { ?>
<div class="panel"><p class="muted" style="margin:0">ไม่พบไซต์งานที่มีคำว่า “<?= h($q) ?>” — ลองพิมพ์สั้นลง หรือใช้คำที่อยู่กลางชื่อ</p></div>
<?php } else { ?>

<div class="sa-listhead"><?= $hits ? 'พบ ' . number_format(count($hits)) . ' ไซต์งาน' : 'ไซต์งานที่มีเครื่องมากที่สุด' ?></div>

<div class="sa-grid">
  <?php foreach ($list as $x) { ?>
  <a class="sa-card" href="<?= $B ?>/site_assets.php?src=<?= rawurlencode($x['src']) ?>&amp;site=<?= rawurlencode($x['site']) ?>">
    <span class="asset-site-src"><?= h($srcs[$x['src']]['th']) ?></span>
    <span class="sa-card-name"><?= h($x['site']) ?></span>
    <span class="sa-card-n"><?= number_format($x['n']) ?> เครื่อง</span>
  </a>
  <?php } ?>
</div>

<?php } ?>
<?php } ?>

<style>
.sa-search { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 10px; }
.sa-search input[type=text] { flex: 1 1 320px; min-width: 0; }
.sa-lead { margin: 0 0 14px; line-height: 1.85; font-size: calc(12.5px * var(--font-scale, 1)); }
.sa-listhead { font-weight: 600; margin: 0 0 8px; }
.sa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; }
.sa-card { display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; text-decoration: none;
  background: var(--surface, #fff); border: 1px solid var(--border, #dde3ec); border-radius: 10px; color: inherit; }
.sa-card:hover { border-color: var(--primary); background: var(--surface-2, #faf9fd); }
.sa-card-name { font-weight: 600; line-height: 1.5; }
.sa-card-n { font-size: calc(11.5px * var(--font-scale, 1)); color: var(--text-muted, #6b6480); }
.sa-sitehead { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 10px;
  padding: 10px 14px; background: var(--surface-2, #faf9fd); border: 1px solid var(--border, #dde3ec);
  border-left: 3px solid var(--primary); border-radius: 8px; }
.sa-foot { margin-top: 12px; font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.8; }
</style>
<?php page_footer(); ?>
