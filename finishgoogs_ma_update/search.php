<?php
/**
 * search.php — ผลการค้นหาแบบเต็มหน้า (24 ก.ย. 2026)
 *
 * ช่องค้นหาบนเมนูโชว์ได้แค่ไม่กี่รายการต่อแหล่ง (กันดรอปดาวน์ยาว) พอค้นด้วยชื่อลูกค้า
 * ที่มีเครื่องหลายสิบเครื่องจึงเห็นไม่ครบ · หน้านี้ใช้ตัวค้นหาเดียวกันแต่ดึงเต็มจำนวน
 * แล้วจัดกลุ่มตามชนิดผล — เครื่องขึ้นก่อนเสมอ เพราะเป็นสิ่งที่คนค้นหามองหา
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/smart_search.php';
require_login();

/** @var int จำนวนสูงสุดต่อแหล่ง — มากพอสำหรับลูกค้ารายใหญ่ แต่ไม่ดึงทั้งฐาน */
const SEARCH_PER_KIND = 40;

$B = BASE_URL;
$q = trim((string) ($_GET['q'] ?? ''));
$items = mb_strlen($q) >= 2 ? smart_search_merge_assets(smart_search_link_assets(smart_search_query($q, SEARCH_PER_KIND), $q)) : [];

// แยกเครื่อง (รวมจากทุกแหล่งแล้ว) ออกจากผลชนิดอื่น แล้วจัดกลุ่มที่เหลือตามชนิด
$assets = [];
$rest = [];
foreach ($items as $it) {
    if ((int) ($it['asset_id'] ?? 0) > 0) {
        $assets[] = $it;
    } else {
        $rest[(string) ($it['kind_label'] ?? 'อื่น ๆ')][] = $it;
    }
}
$total = count($items);

page_header('ผลการค้นหา', true, $q !== '' ? 'คำค้น "' . $q . '" · ' . number_format($total) . ' รายการ' : 'พิมพ์คำค้นอย่างน้อย 2 ตัวอักษร', $B . '/index.php');
?>
<form method="get" class="filter">
  <input type="search" name="q" value="<?= h($q) ?>" data-scan="submit" autocomplete="off" placeholder="S/N · ชื่อลูกค้า · รุ่น · PO · ชื่อคน" style="width:min(380px,100%)" autofocus>
  <button type="submit" class="btn">ค้นหา</button>
</form>

<?php if (mb_strlen($q) < 2) { ?>
<div class="panel"><p class="muted" style="margin:0">ค้นได้ทั้งรหัสเครื่อง S/N โรงงาน ชื่อรุ่น ชื่อลูกค้า/ไซต์งาน เลข PO ใบส่งมอบ ชื่อช่าง และอะไหล่</p></div>
<?php } elseif (!$total) { ?>
<div class="panel"><p class="muted" style="margin:0">ไม่พบอะไรที่ตรงกับ "<?= h($q) ?>"</p></div>
<?php } else { ?>

<?php if ($assets) { ?>
<div class="panel">
  <h3 style="margin:0 0 8px">เครื่อง (<?= count($assets) ?>)<?= count($assets) >= SEARCH_PER_KIND ? ' <span class="muted" style="font-weight:400;font-size:13px">— แสดงสูงสุด ' . SEARCH_PER_KIND . ' ต่อแหล่ง</span>' : '' ?></h3>
  <div class="table-wrap"><table class="list">
    <tr><th data-pri="1">รหัสเครื่อง</th><th data-pri="2">รุ่น</th><th data-pri="1">สถานะ</th><th data-pri="2">เจอจาก</th></tr>
    <?php foreach ($assets as $a) { ?>
    <tr>
      <td data-pri="1" data-nowrap><a href="<?= $B ?>/asset.php?id=<?= (int) $a['asset_id'] ?>"><b><?= h((string) ($a['title'] ?? $a['code'] ?? '')) ?></b></a>
        <div class="cell-sub muted"><?= h((string) ($a['model'] ?? '')) ?></div></td>
      <td data-pri="2"><?= h((string) ($a['model'] ?? '')) ?></td>
      <td data-pri="1"><?= !empty($a['status']) ? status_badge((string) $a['status']) : '<span class="muted">—</span>' ?></td>
      <td data-pri="2"><?php foreach (($a['sources'] ?? []) as $s) { ?><span class="sr-src"><?= h((string) $s['label']) ?></span><?php } ?></td>
    </tr>
    <?php } ?>
  </table></div>
</div>
<?php } ?>

<?php foreach ($rest as $label => $rows) { ?>
<div class="panel">
  <h3 style="margin:0 0 8px"><?= h($label) ?> (<?= count($rows) ?>)</h3>
  <div class="table-wrap"><table class="list">
    <tr><th data-pri="1">รายการ</th><th data-pri="2">รายละเอียด</th></tr>
    <?php foreach ($rows as $r) { $href = (string) ($r['href'] ?? ''); $ext = strpos($href, 'http') === 0; ?>
    <tr>
      <td data-pri="1"><a href="<?= h($ext ? $href : $B . '/' . $href) ?>"<?= $ext ? ' target="_blank" rel="noopener"' : '' ?>><b><?= h((string) ($r['title'] ?? $r['code'] ?? '')) ?></b><?= $ext ? ' ↗' : '' ?></a>
        <div class="cell-sub muted"><?= h((string) ($r['subtitle'] ?? '')) ?></div></td>
      <td data-pri="2" class="muted"><?= h((string) ($r['subtitle'] ?? '')) ?></td>
    </tr>
    <?php } ?>
  </table></div>
</div>
<?php } ?>

<?php } ?>

<style>
.sr-src { display: inline-block; font-size: calc(11.5px * var(--font-scale, 1)); background: var(--surface-soft, #f6f4fb); color: var(--text-muted, #6b6480); border-radius: 999px; padding: 1px 9px; margin: 0 4px 3px 0; }
</style>
<?php page_footer(); ?>
