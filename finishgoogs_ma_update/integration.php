<?php
/**
 * integration.php — การเชื่อมต่อกับระบบอื่น (26 ก.ย. 2026)
 *
 * เขียนให้ผู้ดูแลระบบอื่นอ่านแล้วเข้าใจว่า "ระบบ production เข้าไปทำอะไรกับของเขาบ้าง"
 * ไม่มีพารามิเตอร์ = หน้ารวมทุกระบบ · ?s=<key> = รายละเอียดระบบเดียว
 * เนื้อหาอยู่ที่ includes/release_notes.php → release_integrations()
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/release_notes.php';
require_login();

$B = BASE_URL;
$all = release_integrations();
$key = isset($_GET['s']) ? (string) $_GET['s'] : '';
$one = $key !== '' ? release_integration($key) : null;

/**
 * แผนภาพ "ระบบเขา ↔ ระบบเรา" — บอกทิศทางข้อมูลด้วยภาพ
 *
 * ทำด้วย HTML/CSS ไม่ใช่ SVG เพราะข้อความไทยยาวไม่เท่ากันและต้องตัดบรรทัดเองได้
 *
 * @param array<string,mixed> $g
 * @param bool                $mini ย่อสำหรับการ์ดหน้ารวม (ไม่มีป้ายรายการ)
 * @return string
 */
function integ_flow_html(array $g, bool $mini = false): string
{
    $ro = $g['mode'] === 'read';
    $chips = function (array $items, string $cls) {
        if (!$items) {
            return '';
        }
        $o = '<div class="ig-chips ' . $cls . '">';
        foreach ($items as $t) {
            $o .= '<span class="ig-chip">' . h($t) . '</span>';
        }
        return $o . '</div>';
    };

    $o = '<div class="ig-flow' . ($mini ? ' is-mini' : '') . '">';
    $o .= '<div class="ig-node is-them">'
        . '<div class="ig-node-t">' . h($g['name']) . '</div>'
        . '<div class="ig-node-s">' . h($g['app']) . '</div></div>';

    $o .= '<div class="ig-mid">';
    // ขาเข้า: ระบบเขา → ระบบเรา
    $o .= '<div class="ig-lane is-in"><span class="ig-lane-l">เราอ่าน</span>'
        . '<span class="ig-arrow" aria-hidden="true"></span></div>';
    if (!$mini) {
        $o .= $chips((array) $g['in'], 'is-in');
    }
    // ขาออก: ระบบเรา → ระบบเขา
    if ($ro) {
        $o .= '<div class="ig-lane is-none"><span class="ig-arrow is-dead" aria-hidden="true"></span>'
            . '<span class="ig-lane-l">ไม่เขียนกลับ</span></div>';
    } else {
        $o .= '<div class="ig-lane is-out"><span class="ig-arrow is-back" aria-hidden="true"></span>'
            . '<span class="ig-lane-l">เราเขียน</span></div>';
        if (!$mini) {
            $o .= $chips((array) $g['out'], 'is-out');
        }
    }
    $o .= '</div>';

    $o .= '<div class="ig-node is-us">'
        . '<div class="ig-node-t">ระบบ production</div>'
        . '<div class="ig-node-s">ระบบของเรา</div></div>';
    return $o . '</div>';
}

if ($one) {
    $ro = $one['mode'] === 'read';
    page_header('เชื่อมต่อกับ' . $one['name'], true, $one['app'] . ' · ' . $one['db'], $B . '/integration.php');
    ?>
    <div class="ig-detail">
      <div class="ig-top">
        <span class="ig-mode <?= $ro ? 'is-ro' : 'is-rw' ?>"><?= $ro ? 'เราอ่านอย่างเดียว' : 'เราอ่านและเขียน' ?></span>
        <span class="muted ig-short"><?= h((string) $one['short']) ?></span>
      </div>

      <?= integ_flow_html($one) ?>

      <section class="ig-why">
        <h3 class="ig-h3"><?= ui_icon_html('alert', 16, 'h-svg') ?> ทำไมต้องเชื่อมต่อ</h3>
        <p class="ig-why-p"><?= h((string) $one['why']) ?></p>
      </section>

      <div class="ig-cols">
        <section class="ig-box">
          <h3 class="ig-h3"><?= ui_icon_html('download', 16, 'h-svg') ?> เราดึงอะไรจากระบบนี้</h3>
          <ul class="ig-ul is-in"><?php foreach ((array) $one['read'] as $t) { ?><li><?= h($t) ?></li><?php } ?></ul>
        </section>
        <section class="ig-box">
          <h3 class="ig-h3"><?= ui_icon_html('upload', 16, 'h-svg') ?> เราเขียนอะไรกลับไป</h3>
          <?php if (!empty($one['write'])) { ?>
          <ul class="ig-ul is-out"><?php foreach ((array) $one['write'] as $t) { ?><li><?= h($t) ?></li><?php } ?></ul>
          <?php } else { ?>
          <p class="ig-none"><?= ui_icon_html('check-circle', 15, 'h-svg') ?> ไม่เขียนอะไรกลับเลย ข้อมูลฝั่งคุณไม่เปลี่ยน</p>
          <?php } ?>
        </section>
      </div>

      <?php if (!empty($one['uses'])) { ?>
      <section class="ig-box ig-box-wide ig-useblk">
        <h3 class="ig-h3"><?= ui_icon_html('chart', 16, 'h-svg') ?> เอาไปใช้ตรงไหน ทำอะไรได้</h3>
        <div class="ig-use-head" aria-hidden="true">
          <span>ข้อมูล</span><span></span><span>ใช้ที่ไหนในระบบเรา</span><span></span><span>ได้อะไร</span>
        </div>
        <?php foreach ((array) $one['uses'] as $u) { $out = ($u['kind'] ?? 'in') === 'out'; ?>
        <div class="ig-use <?= $out ? 'is-out' : 'is-in' ?>">
          <span class="ig-use-d"><?= h((string) $u['d']) ?></span>
          <span class="ig-use-ar" aria-hidden="true"></span>
          <a class="ig-use-at" href="<?= h((string) $u['u']) ?>"><?= h((string) $u['at']) ?></a>
          <span class="ig-use-ar" aria-hidden="true"></span>
          <span class="ig-use-g"><?= h((string) $u['g']) ?></span>
        </div>
        <?php } ?>
        <p class="muted ig-use-note">ป้ายสีน้ำเงิน = ข้อมูลที่เราดึงมาใช้ · ป้ายสีเหลือง = สิ่งที่เราเขียนกลับไปให้ระบบนั้น</p>
      </section>
      <?php } ?>

      <section class="ig-box ig-box-wide">
        <h3 class="ig-h3"><?= ui_icon_html('check', 16, 'h-svg') ?> ข้อดีที่ได้</h3>
        <ul class="ig-ul"><?php foreach ((array) $one['did'] as $t) { ?><li><?= h($t) ?></li><?php } ?></ul>
      </section>

      <section class="ig-box ig-box-wide ig-guard">
        <h3 class="ig-h3"><?= ui_icon_html('check-circle', 16, 'h-svg') ?> กันข้อมูลฝั่งคุณเสียหายอย่างไร</h3>
        <p class="muted ig-guard-lead"><?= h((string) $one['safe']) ?></p>
        <ul class="ig-ul is-guard"><?php foreach ((array) $one['guard'] as $t) { ?><li><?= h($t) ?></li><?php } ?></ul>
      </section>

      <?php if (!empty($one['where'])) { ?>
      <p class="muted ig-where">เห็นผลได้ที่
        <?php $i = 0; foreach ((array) $one['where'] as $w) { echo $i++ ? ' · ' : ''; ?><a href="<?= h($w['u']) ?>"><?= h($w['t']) ?></a><?php } ?>
      </p>
      <?php } ?>
    </div>
    <?php
} else {
    $nRo = 0;
    foreach ($all as $g) {
        if ($g['mode'] === 'read') {
            $nRo++;
        }
    }
    page_header('การเชื่อมต่อกับระบบอื่น', true,
        count($all) . ' ระบบ · อ่านอย่างเดียว ' . $nRo . ' · อ่านและเขียน ' . (count($all) - $nRo),
        $B . '/release_notes.php');
    ?>
    <p class="muted ig-intro">ระบบ production ทำงานร่วมกับระบบอื่นในบริษัท หน้านี้สรุปว่าเราเข้าไปยุ่งกับระบบไหนอย่างไร
      — กดที่ระบบเพื่อดูรายละเอียดว่าดึงอะไรมาและเขียนอะไรกลับบ้าง</p>

    <div class="ig-grid">
      <?php foreach ($all as $g) { $ro = $g['mode'] === 'read'; ?>
      <a class="ig-card" href="<?= $B ?>/integration.php?s=<?= h($g['key']) ?>">
        <div class="ig-card-top">
          <span class="ig-ico"><?= ui_icon_html($g['icon'], 18) ?></span>
          <b class="ig-card-name"><?= h($g['name']) ?></b>
          <span class="ig-mode <?= $ro ? 'is-ro' : 'is-rw' ?>"><?= $ro ? 'อ่านอย่างเดียว' : 'อ่าน + เขียน' ?></span>
        </div>
        <p class="ig-card-short"><?= h((string) $g['short']) ?></p>
        <?= integ_flow_html($g, true) ?>
        <span class="ig-more">ดูรายละเอียด <?= ui_icon_html('chevron-right', 14) ?></span>
      </a>
      <?php } ?>
    </div>
    <?php
}
?>
<style>
.ig-intro { margin-bottom: 14px; line-height: 1.8; }
/* ── การ์ดหน้ารวม ── */
.ig-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 12px; }
.ig-card { display: block; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
  border-radius: 12px; padding: 13px 15px; color: inherit; text-decoration: none; }
.ig-card:hover { border-color: var(--primary); text-decoration: none; }
.ig-card-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ig-ico { display: inline-flex; color: var(--primary); }
.ig-card-name { font-size: calc(15px * var(--font-scale, 1)); }
.ig-card-short { font-size: calc(13px * var(--font-scale, 1)); line-height: 1.65;
  color: var(--text-muted, #6b6480); margin: 7px 0 0; }
.ig-more { display: inline-flex; align-items: center; gap: 3px; margin-top: 10px;
  font-size: calc(12.5px * var(--font-scale, 1)); color: var(--primary); font-weight: 600; }
/* ── ป้ายบอกสิทธิ์ ── */
.ig-mode { flex: 0 0 auto; margin-left: auto; font-size: calc(11.5px * var(--font-scale, 1));
  border-radius: 999px; padding: 2px 10px; white-space: nowrap; }
.ig-mode.is-ro { background: var(--success-soft, #dcfce7); color: var(--success, #16a34a); }
.ig-mode.is-rw { background: var(--warning-soft, #fef9c3); color: var(--warning, #a16207); }
/* ── แผนภาพทิศทางข้อมูล ── */
.ig-flow { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr) minmax(0, 1fr);
  align-items: center; gap: 8px; margin: 14px 0; }
.ig-flow.is-mini { margin: 11px 0 0; gap: 6px; }
.ig-node { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 9px 10px; text-align: center; }
.ig-node.is-them { background: var(--surface-2, #f6f4fb); }
.ig-node.is-us { background: var(--primary-soft, #fce7f3); border-color: var(--primary); }
.ig-node-t { font-size: calc(13px * var(--font-scale, 1)); font-weight: 700; line-height: 1.35; }
.ig-node-s { font-size: calc(11px * var(--font-scale, 1)); color: var(--text-muted, #6b6480); margin-top: 1px; }
.is-mini .ig-node { padding: 7px 8px; }
.is-mini .ig-node-t { font-size: calc(12px * var(--font-scale, 1)); }
.ig-mid { display: flex; flex-direction: column; gap: 4px; }
.ig-lane { display: flex; align-items: center; gap: 5px; }
.ig-lane-l { font-size: calc(11.5px * var(--font-scale, 1)); white-space: nowrap; font-weight: 600; }
.ig-lane.is-in .ig-lane-l { color: var(--info, #1d4ed8); }
.ig-lane.is-out .ig-lane-l { color: var(--warning, #a16207); }
.ig-lane.is-none .ig-lane-l { color: var(--text-muted, #6b6480); font-weight: 400; }
/* ลูกศร: ขาเข้าชี้ขวา (เข้าหาเรา) · ขาออกชี้ซ้าย (กลับไปหาเขา) */
.ig-arrow { flex: 1 1 auto; position: relative; height: 2px; background: var(--info, #1d4ed8); border-radius: 2px; }
.ig-arrow::after { content: ''; position: absolute; right: -1px; top: -4px;
  border: 5px solid transparent; border-left-color: var(--info, #1d4ed8); border-right: 0; }
.ig-arrow.is-back { background: var(--warning, #a16207); }
.ig-arrow.is-back::after { right: auto; left: -1px; border-left: 0;
  border-right: 5px solid var(--warning, #a16207); }
.ig-arrow.is-dead { background: none; border-top: 2px dashed var(--border-strong, #cfc8e0); height: 0; }
.ig-arrow.is-dead::after { display: none; }
.ig-chips { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; margin: 1px 0 4px; }
.ig-chip { font-size: calc(11px * var(--font-scale, 1)); border-radius: 999px; padding: 1px 8px;
  background: var(--surface-2, #f6f4fb); color: var(--text-muted, #5f5e5a); }
.ig-chips.is-in .ig-chip { background: var(--info-soft, #dbeafe); color: var(--info, #1d4ed8); }
.ig-chips.is-out .ig-chip { background: var(--warning-soft, #fef9c3); color: var(--warning, #a16207); }
/* ── หน้ารายละเอียด ── */
.ig-top { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.ig-top .ig-mode { margin-left: 0; }
.ig-short { font-size: calc(13.5px * var(--font-scale, 1)); line-height: 1.7; }
.ig-why { margin: 0 0 14px; padding: 11px 14px; border-radius: 10px;
  background: var(--surface-2, #f6f4fb); border-left: 3px solid var(--primary); }
.ig-why .ig-h3 { margin-bottom: 5px; }
.ig-why-p { margin: 0; font-size: calc(13.5px * var(--font-scale, 1)); line-height: 1.75; }
/* ── สายโซ่: ข้อมูล → ใช้ที่ไหน → ได้อะไร ── */
.ig-useblk { margin-bottom: 12px; }
.ig-use-head, .ig-use { display: grid; grid-template-columns: minmax(0, 0.9fr) 18px minmax(0, 1.1fr) 18px minmax(0, 1.5fr);
  align-items: center; gap: 8px; }
.ig-use-head { font-size: calc(11.5px * var(--font-scale, 1)); color: var(--text-muted, #6b6480);
  font-weight: 700; padding: 0 2px 4px; border-bottom: 1px solid var(--border, #ece7f6); margin-bottom: 4px; }
.ig-use { padding: 7px 2px; border-bottom: 1px solid var(--border, #ece7f6); }
.ig-use:last-of-type { border-bottom: 0; }
.ig-use-d { justify-self: start; font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.5;
  border-radius: 8px; padding: 3px 9px; }
.ig-use.is-in .ig-use-d { background: var(--info-soft, #dbeafe); color: var(--info, #1d4ed8); }
.ig-use.is-out .ig-use-d { background: var(--warning-soft, #fef9c3); color: var(--warning, #a16207); }
.ig-use-ar { position: relative; height: 2px; background: var(--border-strong, #cfc8e0); }
.ig-use-ar::after { content: ''; position: absolute; right: -1px; top: -4px;
  border: 5px solid transparent; border-left-color: var(--border-strong, #cfc8e0); border-right: 0; }
.ig-use-at { font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.5; font-weight: 600; }
.ig-use-g { font-size: calc(12.5px * var(--font-scale, 1)); line-height: 1.6; color: var(--text-muted, #5f5e5a); }
.ig-use-note { font-size: calc(11.5px * var(--font-scale, 1)); margin: 9px 0 0; line-height: 1.6; }
@media (max-width: 700px) {
  /* จอแคบ: เรียงลงมาเป็นขั้น ๆ แทนสามคอลัมน์ */
  .ig-use-head { display: none; }
  .ig-use { grid-template-columns: minmax(0, 1fr); gap: 3px; padding: 9px 0; }
  .ig-use-ar { height: 0; border-left: 2px solid var(--border-strong, #cfc8e0); width: 0;
    height: 12px; margin-left: 11px; background: none; }
  .ig-use-ar::after { top: auto; bottom: -1px; right: auto; left: -4px;
    border: 5px solid transparent; border-top-color: var(--border-strong, #cfc8e0); border-bottom: 0; border-left-color: transparent; }
  .ig-use-at, .ig-use-g { padding-left: 3px; }
}
.ig-guard { border-color: var(--success, #16a34a); }
.ig-guard .ig-h3 { color: var(--success, #16a34a); }
.ig-guard-lead { font-size: calc(13px * var(--font-scale, 1)); line-height: 1.7; margin: 0 0 8px; }
.ig-ul.is-guard li::before { background: var(--success, #16a34a); }
.ig-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
.ig-box { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
  border-radius: 12px; padding: 12px 15px; }
.ig-box-wide { margin-top: 12px; }
.ig-h3 { display: flex; align-items: center; gap: 6px; font-size: calc(14px * var(--font-scale, 1)); margin: 0 0 8px; }
.ig-ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.ig-ul li { position: relative; padding-left: 16px; font-size: calc(13.5px * var(--font-scale, 1)); line-height: 1.65; }
.ig-ul li::before { content: ''; position: absolute; left: 2px; top: 9px;
  width: 6px; height: 6px; border-radius: 999px; background: var(--primary); }
.ig-ul.is-in li::before { background: var(--info, #1d4ed8); }
.ig-ul.is-out li::before { background: var(--warning, #a16207); }
.ig-none { display: flex; align-items: center; gap: 6px; margin: 0;
  font-size: calc(13.5px * var(--font-scale, 1)); color: var(--success, #16a34a); }
.ig-where { margin-top: 14px; font-size: calc(13px * var(--font-scale, 1)); }
@media (max-width: 560px) {
  /* จอแคบ: วางกล่องซ้อนกันแล้วให้ลูกศรชี้ลง/ขึ้นแทน */
  .ig-flow { grid-template-columns: minmax(0, 1fr); }
  .ig-mid { padding: 2px 0; }
  .ig-lane { justify-content: center; }
  .ig-arrow { flex: 0 0 46px; transform: rotate(90deg); }
  .ig-arrow.is-back { transform: rotate(-90deg); }
}
</style>
<?php page_footer(); ?>
