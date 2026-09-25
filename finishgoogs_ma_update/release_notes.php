<?php
/**
 * release_notes.php — ประวัติการอัปเดตระบบ (25 ก.ย. 2026)
 *
 * ไล่ดูว่าระบบพัฒนาอะไรไปบ้างในแต่ละรอบ · เนื้อหาอยู่ที่ includes/release_notes.php
 * ตัวเลขเวอร์ชันที่ใช้อยู่มาจาก APP_RELEASE_VERSION ที่ประทับตอน build patch
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/release_notes.php';
require_login();

$B = BASE_URL;
$notes = release_notes();
$kinds = release_note_kinds();

$total = 0;
$byKind = ['new' => 0, 'imp' => 0, 'fix' => 0];
foreach ($notes as $n) {
    foreach ($n['items'] as $it) {
        $total++;
        if (isset($byKind[$it['t']])) {
            $byKind[$it['t']]++;
        }
    }
}
// เวอร์ชันที่ใช้อยู่ = 2026-09-25_101203 → เทียบกับวันที่ของบล็อกเพื่อติดป้าย "ใช้อยู่ตอนนี้"
$liveDate = defined('APP_RELEASE_VERSION') ? substr((string) APP_RELEASE_VERSION, 0, 10) : '';
$thMonth = ['01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน', '05' => 'พฤษภาคม', '06' => 'มิถุนายน',
            '07' => 'กรกฎาคม', '08' => 'สิงหาคม', '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'];
$fmtDay = function (string $d) use ($thMonth) {
    [$y, $m, $dd] = array_pad(explode('-', $d), 3, '');
    return (int) $dd . ' ' . ($thMonth[$m] ?? $m) . ' ' . ((int) $y + 543);
};
$monthLabel = function (string $d) use ($thMonth) {
    [$y, $m] = array_pad(explode('-', $d), 3, '');
    return ($thMonth[$m] ?? $m) . ' ' . ((int) $y + 543);
};

$firstDate = $notes ? (string) end($notes)['date'] : '';
reset($notes);
page_header('ประวัติการอัปเดตระบบ', true, count($notes) . ' รอบ · ' . number_format($total) . ' รายการ'
    . ($firstDate !== '' ? ' · ตั้งแต่ ' . $fmtDay($firstDate) : ''), $B . '/settings.php');
?>
<div class="rn-filters" role="tablist" aria-label="กรองชนิดรายการ">
  <button type="button" class="rn-chip is-on" data-rn="all" role="tab" aria-selected="true">ทั้งหมด (<?= number_format($total) ?>)</button>
  <?php foreach ($kinds as $k => $info) { ?>
  <button type="button" class="rn-chip <?= h($info['cls']) ?>" data-rn="<?= h($k) ?>" role="tab" aria-selected="false"><?= h($info['th']) ?> (<?= number_format($byKind[$k]) ?>)</button>
  <?php } ?>
</div>

<div class="rn-list">
  <?php $lastMonth = ''; foreach ($notes as $n) {
      $mk = $monthLabel((string) $n['date']);
      if ($mk !== $lastMonth) { $lastMonth = $mk; ?>
  <div class="rn-month"><?= h($mk) ?></div>
  <?php } ?>
  <article class="rn-item">
    <div class="rn-dot" aria-hidden="true"></div>
    <div class="rn-card">
      <div class="rn-head">
        <div>
          <b class="rn-title"><?= h((string) $n['title']) ?></b>
          <?php if ($liveDate !== '' && $liveDate === (string) $n['date']) { ?><span class="rn-live">ใช้อยู่ตอนนี้</span><?php } ?>
        </div>
        <span class="rn-date"><?= h($fmtDay((string) $n['date'])) ?></span>
      </div>
      <ul class="rn-items">
        <?php foreach ($n['items'] as $it) { $ki = $kinds[$it['t']] ?? ['th' => $it['t'], 'cls' => '']; ?>
        <li data-rn-kind="<?= h((string) $it['t']) ?>"><span class="rn-tag <?= h($ki['cls']) ?>"><?= h($ki['th']) ?></span><span><?= h((string) $it['s']) ?></span></li>
        <?php } ?>
      </ul>
    </div>
  </article>
  <?php } ?>
</div>

<p class="muted rn-foot">เนื้อหาสรุปจากประวัติการแก้ไขจริงของระบบ · เวอร์ชันที่ใช้อยู่ตอนนี้คือ <b><?= h(defined('APP_RELEASE_VERSION') ? (string) APP_RELEASE_VERSION : '-') ?></b></p>

<style>
.rn-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.rn-chip { font-size: calc(13px * var(--font-scale, 1)); border: 1px solid var(--border, #e5e7eb); background: var(--surface, #fff);
  color: var(--text, #2a2440); border-radius: 999px; padding: 5px 14px; cursor: pointer; font-family: inherit; }
.rn-chip:hover { border-color: var(--primary); }
.rn-chip.is-on { border-color: var(--primary); color: var(--primary); font-weight: 600; background: var(--primary-soft, #fce7f3); }
.rn-month { font-size: calc(13px * var(--font-scale, 1)); font-weight: 700; color: var(--muted, #6b7280);
  margin: 18px 0 8px; padding-left: 26px; }
.rn-month:first-child { margin-top: 0; }
.rn-list { position: relative; }
.rn-item { position: relative; padding-left: 26px; margin-bottom: 10px; }
.rn-item::before { content: ''; position: absolute; left: 7px; top: 0; bottom: -10px; width: 2px; background: var(--border, #ece7f6); }
.rn-item:last-child::before { bottom: 12px; }
.rn-dot { position: absolute; left: 2px; top: 16px; width: 12px; height: 12px; border-radius: 999px;
  background: var(--surface, #fff); border: 2px solid var(--primary); }
.rn-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 12px 14px; }
.rn-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.rn-title { font-size: calc(15px * var(--font-scale, 1)); }
.rn-date { font-size: calc(12.5px * var(--font-scale, 1)); color: var(--muted, #6b7280); white-space: nowrap; }
.rn-live { display: inline-block; margin-left: 8px; font-size: calc(11.5px * var(--font-scale, 1)); font-weight: 400;
  background: #d1fae5; color: #065f46; border-radius: 999px; padding: 1px 9px; vertical-align: 1px; }
.rn-items { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.rn-items li { display: flex; gap: 8px; align-items: flex-start; font-size: calc(13.5px * var(--font-scale, 1)); line-height: 1.55; }
.rn-items li[hidden] { display: none; }
.rn-tag { flex: 0 0 auto; font-size: calc(11.5px * var(--font-scale, 1)); border-radius: 999px; padding: 1px 9px; margin-top: 2px; }
.rn-tag.is-new { background: #ede9fe; color: #5b21b6; }
.rn-tag.is-imp { background: #dbeafe; color: #1e40af; }
.rn-tag.is-fix { background: #fef3c7; color: #92400e; }
.rn-chip.is-new.is-on { background: #ede9fe; border-color: #7c3aed; color: #5b21b6; }
.rn-chip.is-imp.is-on { background: #dbeafe; border-color: #2563eb; color: #1e40af; }
.rn-chip.is-fix.is-on { background: #fef3c7; border-color: #d97706; color: #92400e; }
.rn-foot { margin-top: 16px; font-size: calc(12.5px * var(--font-scale, 1)); }
@media (max-width: 480px) { .rn-item, .rn-month { padding-left: 20px; } .rn-dot { left: 0; } .rn-item::before { left: 5px; } }
</style>
<script>
/* กรองตามชนิด — ซ่อนรายการที่ไม่ตรง แล้วซ่อนการ์ด/หัวเดือนที่ไม่เหลืออะไรเลย */
(function () {
  var chips = [].slice.call(document.querySelectorAll('.rn-chip'));
  var items = [].slice.call(document.querySelectorAll('.rn-items li'));
  var cards = [].slice.call(document.querySelectorAll('.rn-item'));
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      var kind = c.getAttribute('data-rn');
      chips.forEach(function (o) {
        var on = o === c;
        o.classList.toggle('is-on', on);
        o.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      items.forEach(function (li) {
        li.hidden = kind !== 'all' && li.getAttribute('data-rn-kind') !== kind;
      });
      cards.forEach(function (card) {
        card.hidden = !card.querySelector('.rn-items li:not([hidden])');
      });
      // หัวเดือนไหนไม่เหลือการ์ดแล้วก็ซ่อนไปด้วย
      [].slice.call(document.querySelectorAll('.rn-month')).forEach(function (m) {
        var any = false;
        for (var el = m.nextElementSibling; el && !el.classList.contains('rn-month'); el = el.nextElementSibling) {
          if (el.classList.contains('rn-item') && !el.hidden) { any = true; break; }
        }
        m.hidden = !any;
      });
    });
  });
})();
</script>
<?php page_footer(); ?>
