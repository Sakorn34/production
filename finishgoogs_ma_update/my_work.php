<?php
/**
 * my_work.php — สรุปงานของ "คนเดียว" แบบละเอียด
 *
 * เปิดได้ 2 ทาง:
 *   1. ลิงก์จากปุ่มในไลน์ — ?t=<โทเคนประจำตัว> ไม่ต้อง login เพราะคนทำงานส่วนใหญ่
 *      ไม่มีบัญชีในระบบ (30 ชื่อ มีบัญชีแค่ 9) โทเคนเปิดได้แค่สรุปงานของเจ้าของโทเคน
 *   2. ผู้ดูแลที่ login แล้ว — ?p=<person_id> กดมาจากหน้าสรุปงานรายคน
 *
 * แยกจาก work_report.php ตั้งใจ — หน้านั้นเป็นหน้าจัดการ (ทะเบียนคน, เปิด/ปิดการส่ง,
 * ออกรหัสผูกไลน์) ไม่ควรให้พนักงานที่กดมาจากไลน์เห็น
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/work_summary.php';

$B = BASE_URL;

$token = trim((string) (isset($_GET['t']) ? $_GET['t'] : ''));
$person = $token !== '' ? work_people_by_view_token($token) : null;
$viaToken = $person !== null;

if ($person === null) {
    // ไม่มีโทเคน = ต้องเป็นคนในระบบถึงจะเปิดของคนอื่นดูได้
    require_login();
    $pid = (int) (isset($_GET['p']) ? $_GET['p'] : 0);
    foreach (work_people_all() as $row) {
        if ((int) $row['id'] === $pid) {
            $person = $row;
            break;
        }
    }
}

$anchor = trim((string) (isset($_GET['c']) ? $_GET['c'] : ''));
$cycle = work_summary_cycle($anchor !== '' ? $anchor : null);
$prev = work_summary_cycle_shift($cycle, -1);
$next = work_summary_cycle_shift($cycle, +1);

/** ลิงก์ข้ามรอบต้องพกวิธีเข้าถึงเดิมไปด้วย ไม่งั้นกดรอบก่อนแล้วหลุดสิทธิ์ */
$selfUrl = function ($cycleTo) use ($B, $viaToken, $token, $person) {
    $q = $viaToken ? 't=' . rawurlencode($token) : 'p=' . (int) $person['id'];
    return $B . '/my_work.php?' . $q . '&c=' . rawurlencode((string) $cycleTo);
};

if ($person === null) {
    http_response_code(404);
    page_head_html('ไม่พบหน้านี้');
    echo '<body class="mw-body"><div class="mw-wrap"><div class="mw-card">'
       . '<h1 class="mw-h1">เปิดหน้านี้ไม่ได้</h1>'
       . '<p class="muted">ลิงก์อาจหมดอายุหรือถูกยกเลิกแล้ว — ขอลิงก์ใหม่จากผู้ดูแลระบบได้ครับ</p>'
       . '</div></div></body></html>';
    exit;
}

$pid = (int) $person['id'];
$detail = work_summary_person_items($pid, $cycle['from'], $cycle['to']);

page_head_html('สรุปงานของ ' . $person['display_name']);
?>
<body class="mw-body">
<div class="mw-wrap">

  <div class="mw-card mw-head">
    <div class="mw-head-top">
      <div>
        <h1 class="mw-h1"><?= h($person['display_name']) ?></h1>
        <p class="mw-sub">สรุปงานรอบ <?= h($cycle['label']) ?></p>
      </div>
      <?php if (!$viaToken) { ?>
      <a class="btn btn-sm btn-line" href="<?= $B ?>/work_report.php?c=<?= h($cycle['to']) ?>">‹ กลับหน้ารวม</a>
      <?php } ?>
    </div>

    <div class="mw-big">
      <b><?= number_format($detail['total']) ?></b>
      <span>รายการ · <?= number_format(count($detail['days'])) ?> วันที่มีงาน</span>
    </div>

    <div class="mw-nav">
      <a class="btn btn-sm btn-line" href="<?= h($selfUrl($prev['to'])) ?>">‹ รอบก่อน</a>
      <a class="btn btn-sm btn-line" href="<?= h($selfUrl($next['to'])) ?>">รอบถัดไป ›</a>
      <?php if ($detail['days']) { ?>
      <button type="button" class="btn btn-sm btn-line mw-expand" data-open="0">ขยายทุกวัน</button>
      <?php } ?>
    </div>
  </div>

  <?php if ($detail['errors']) { ?>
  <p class="muted mw-warn">ดึงข้อมูลบางส่วนไม่ได้: <?= h(implode(' · ', $detail['errors'])) ?>
    — ตัวเลขที่เห็นจึงยังไม่ครบทุกระบบ</p>
  <?php } ?>

  <?php if (!$detail['days']) { ?>
  <div class="mw-card"><p class="muted">ยังไม่มีงานที่บันทึกในรอบนี้</p></div>
  <?php } ?>

  <?php foreach ($detail['days'] as $day) { ?>
  <details class="mw-card mw-day">
    <summary>
      <span class="mw-date"><?= h($day['label']) ?></span>
      <span class="mw-daytotal"><?= number_format($day['total']) ?> รายการ</span>
      <span class="mw-chips">
        <?php foreach ($day['cats'] as $cat) { ?>
        <span class="wr-chip"><?= h($cat['label']) ?> <b><?= number_format($cat['count']) ?></b></span>
        <?php } ?>
      </span>
    </summary>
    <?php foreach ($day['cats'] as $cat) { ?>
    <div class="mw-cat">
      <h2 class="mw-cat-h"><?= h($cat['label']) ?> <span><?= number_format($cat['count']) ?></span></h2>
      <ul class="mw-items">
        <?php foreach ($cat['items'] as $it) { ?>
        <li>
          <b><?= h($it['name']) ?></b>
          <?php if ($it['ref'] !== '') { ?><code class="mw-ref"><?= h($it['ref']) ?></code><?php } ?>
          <?php if ($it['extra'] !== '') { ?><span class="muted"><?= h($it['extra']) ?></span><?php } ?>
        </li>
        <?php } ?>
      </ul>
    </div>
    <?php } ?>
  </details>
  <?php } ?>

  <p class="mw-foot muted">ระบบ <?= h(setting('app_name', APP_NAME)) ?> · สรุปรอบวันที่ 21 ถึง 20 ของเดือนถัดไป</p>
</div>
<script>
// ปุ่มเดียวสลับเปิด/ปิดทุกวัน — บนมือถือการไล่กดทีละวันช้าเกินไป
(function () {
  var btn = document.querySelector('.mw-expand');
  if (!btn) { return; }
  btn.addEventListener('click', function () {
    var open = btn.getAttribute('data-open') !== '1';
    var days = document.querySelectorAll('.mw-day');
    for (var i = 0; i < days.length; i++) { days[i].open = open; }
    btn.setAttribute('data-open', open ? '1' : '0');
    btn.textContent = open ? 'ย่อทุกวัน' : 'ขยายทุกวัน';
  });
})();
</script>
</body>
</html>
