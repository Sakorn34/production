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
 *
 * วางเป็นปฏิทินทั้งรอบก่อน แล้วค่อยกดวันที่สนใจดูรายการ — รอบหนึ่งมีงานหลายร้อย
 * รายการ ไล่เป็นลิสต์ยาว ๆ หาวันที่ต้องการไม่เจอ
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
$weeks = work_summary_calendar_weeks($cycle['from'], $cycle['to']);

// วันไหนมีงานบ้าง — ใช้ทั้งวาดปฏิทินและหยิบรายการมาใส่ popup
$byDate = [];
foreach ($detail['days'] as $d) {
    $byDate[(string) $d['date']] = $d;
}

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
    </div>
  </div>

  <?php if ($detail['errors']) { ?>
  <p class="muted mw-warn">ดึงข้อมูลบางส่วนไม่ได้: <?= h(implode(' · ', $detail['errors'])) ?>
    — ตัวเลขที่เห็นจึงยังไม่ครบทุกระบบ</p>
  <?php } ?>

  <div class="mw-card">
    <div class="mw-legend">
      <span><i class="mw-sw mw-sw-work"></i> วันที่มีรายการ — กดดูได้</span>
      <span><i class="mw-sw mw-sw-off"></i> วันที่ไม่มีรายการ</span>
    </div>

    <div class="mw-cal">
      <div class="mw-cal-row mw-cal-head">
        <?php foreach (['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'] as $dw) { ?><span><?= h($dw) ?></span><?php } ?>
      </div>
      <?php foreach ($weeks as $week) { ?>
      <div class="mw-cal-row">
        <?php foreach ($week as $ymd) {
            if ($ymd === null) { ?><span class="mw-cal-cell is-out"></span><?php continue; }
            $day = isset($byDate[$ymd]) ? $byDate[$ymd] : null;
            $num = (int) substr($ymd, 8, 2);
            if ($day === null) { ?>
        <span class="mw-cal-cell is-off" title="ไม่มีรายการ"><b><?= $num ?></b></span>
            <?php } else { ?>
        <button type="button" class="mw-cal-cell is-work" data-day="<?= h($ymd) ?>"
          title="<?= h($day['label']) ?> — <?= number_format($day['total']) ?> รายการ">
          <b><?= $num ?></b><small><?= number_format($day['total']) ?></small>
        </button>
            <?php }
        } ?>
      </div>
      <?php } ?>
    </div>

    <?php if (!$detail['days']) { ?>
    <p class="muted mw-hint">ยังไม่มีงานที่บันทึกในรอบนี้</p>
    <?php } elseif ($viaToken) { ?>
    <p class="muted mw-hint">กดวันที่ต้องการเพื่อดูรายการ · แตะรายการเพื่อเปิดหน้าเครื่องในแท็บใหม่
      (ต้องมีบัญชีผู้ใช้ของระบบ)</p>
    <?php } else { ?>
    <p class="muted mw-hint">กดวันที่ต้องการเพื่อดูรายการงานของวันนั้น</p>
    <?php } ?>
  </div>

  <p class="mw-foot muted">ระบบ <?= h(setting('app_name', APP_NAME)) ?> · สรุปรอบวันที่ 21 ถึง 20 ของเดือนถัดไป</p>
</div>

<?php // รายการของแต่ละวันซ่อนไว้ในหน้า แล้วโคลนเข้า popup ตอนกด — ไม่ต้องยิง ajax
      // เพิ่ม เพราะข้อมูลทั้งรอบก็ดึงมาครบแล้วตั้งแต่แรก ?>
<div id="mw-days" hidden>
  <?php foreach ($detail['days'] as $day) { ?>
  <div id="mw-d-<?= h($day['date']) ?>" data-title="<?= h($day['label']) ?>"
       data-sub="<?= number_format($day['total']) ?> รายการ">
    <?php foreach ($day['cats'] as $cat) { ?>
    <div class="mw-cat">
      <h2 class="mw-cat-h"><?= h($cat['label']) ?> <span><?= number_format($cat['count']) ?></span></h2>
      <ul class="mw-items">
        <?php foreach ($cat['items'] as $it) {
            $aid = (int) $it['asset_id'];
            $tag = $aid > 0 ? 'a' : 'span'; ?>
        <li>
          <<?= $tag ?> class="mw-item<?= $aid > 0 ? ' is-link' : '' ?>"
            <?php if ($aid > 0) { ?>href="<?= $B ?>/asset.php?id=<?= $aid ?>" target="_blank" rel="noopener"
            title="เปิดหน้าเครื่องในแท็บใหม่"<?php } ?>>
            <b><?= h($it['name']) ?></b>
            <?php if ($it['ref'] !== '') { ?><code class="mw-ref"><?= h($it['ref']) ?></code><?php } ?>
            <?php if ($it['extra'] !== '') { ?><span class="muted"><?= h($it['extra']) ?></span><?php } ?>
          </<?= $tag ?>>
        </li>
        <?php } ?>
      </ul>
    </div>
    <?php } ?>
  </div>
  <?php } ?>
</div>

<div class="mw-modal" id="mw-modal" hidden>
  <div class="mw-modal-bg" data-close="1"></div>
  <div class="mw-modal-box" role="dialog" aria-modal="true" aria-labelledby="mw-modal-title">
    <div class="mw-modal-head">
      <div>
        <b id="mw-modal-title"></b>
        <span class="muted" id="mw-modal-sub"></span>
      </div>
      <button type="button" class="mw-modal-x" data-close="1" aria-label="ปิด">&times;</button>
    </div>
    <div class="mw-modal-body" id="mw-modal-body"></div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('mw-modal');
  if (!modal) { return; }
  var title = document.getElementById('mw-modal-title');
  var sub = document.getElementById('mw-modal-sub');
  var body = document.getElementById('mw-modal-body');
  var last = null;

  function open(ymd, btn) {
    var src = document.getElementById('mw-d-' + ymd);
    if (!src) { return; }
    title.textContent = src.getAttribute('data-title') || '';
    sub.textContent = src.getAttribute('data-sub') || '';
    body.innerHTML = src.innerHTML;
    body.scrollTop = 0;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    last = btn;
  }
  function close() {
    modal.hidden = true;
    document.body.style.overflow = '';
    if (last) { last.focus(); last = null; }
  }

  document.addEventListener('click', function (e) {
    var day = e.target.closest ? e.target.closest('.mw-cal-cell.is-work') : null;
    if (day) { open(day.getAttribute('data-day'), day); return; }
    if (e.target.closest && e.target.closest('[data-close]')) { close(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) { close(); }
  });
})();
</script>
</body>
</html>
