<?php
/**
 * settings_bulk.php — ตั้งค่า 2 เรื่องนี้ให้ทุกรุ่นในหน้าเดียว
 *
 *   1. แผงคำสั่งตั้งค่าหมายเลขสินค้า (Serial / MAC)
 *   2. แจ้งเตือนอะไหล่ที่ควรเปลี่ยน (SD Card / Battery Backup RTC)
 *
 * เดิมสองเรื่องนี้ตั้งได้จาก settings.php?product=<id> ทีละรุ่น ซึ่งมี 40+ รุ่น
 * ต้องกดเข้าออกทีละอัน หน้านี้แสดงเป็นตารางติ๊กช่อง บันทึกทีเดียวจบ
 *
 * สำคัญ: หน้านี้แตะเฉพาะ field_kind 4 ตัวที่เกี่ยวข้องเท่านั้น
 * (product_snippets, product_snippets_off, watch_alert, watch_alert_cfg)
 * ฟิลด์อื่นของรุ่น เช่น component / Firmware / Lot / Checklist ต้องไม่ถูกแตะ
 * — ต่างจาก settings.php ที่ลบ config ของทั้ง context แล้วเขียนใหม่ทั้งชุด
 */

require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();
require __DIR__ . '/includes/layout.php';

ensure_field_input_mode_schema();

const BULK_SNIPPET_FIELD = 'ข้อความประจำสินค้า';
const BULK_WATCH_CFG_FIELD = 'แจ้งเตือนอะไหล่';

/**
 * sort_order ถัดไปของรุ่น — ต่อท้ายของเดิม ไม่ไปแทรกกลางชุดฟิลด์ที่ตั้งไว้แล้ว
 *
 * @param int $pid
 * @return int
 */
function bulk_next_sort(int $pid): int
{
    $r = qr(
        "SELECT COALESCE(MAX(sort_order), -1) + 1 AS s FROM product_field_config
         WHERE product_id=? AND context='production'",
        'i',
        [$pid]
    )->fetch_assoc();
    return (int) ($r['s'] ?? 0);
}

$watchCatalog = part_watch_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $snipPosted = array_map('intval', (array) ($_POST['snippets'] ?? []));
    $watchPosted = (array) ($_POST['watch'] ?? []); // [pid => [ชื่ออะไหล่ => 1]]
    $ids = array_map('intval', (array) ($_POST['pids'] ?? []));

    $changed = 0;
    foreach ($ids as $pid) {
        if ($pid <= 0) {
            continue;
        }

        // เขียนเฉพาะเรื่องที่ค่าต่างจากของเดิมจริง ๆ และแยกสองเรื่องออกจากกัน
        //
        // รุ่นที่ยังไม่เคยตั้งค่าแจ้งเตือนถือว่า "เปิดทุกรายการ" โดยปริยาย ถ้าเขียนทับทุกครั้ง
        // ที่กดบันทึก รุ่นพวกนั้นจะกลายเป็น "ตั้งค่าแล้ว" ทั้งยวง ซึ่งเปลี่ยนชะตากรรมตอนเพิ่ม
        // ชนิดแจ้งเตือนใหม่ในอนาคต (ตั้งค่าแล้ว = ค่าเริ่มปิด · ยังไม่ตั้ง = ค่าเริ่มเปิด)
        // — และการแก้แผงคำสั่งอย่างเดียวก็ต้องไม่ไปทำให้ฝั่งแจ้งเตือนถูกตรึงค่าตามไปด้วย
        $wantSnip = in_array($pid, $snipPosted, true);
        $mine = (array) ($watchPosted[$pid] ?? []);
        $wantWatch = [];
        foreach (array_keys($watchCatalog) as $alertName) {
            $wantWatch[$alertName] = !empty($mine[$alertName]);
        }
        $touched = false;

        // ── แผงคำสั่งตั้งค่าหมายเลขสินค้า ──
        if ($wantSnip !== product_show_snippets($pid)) {
            q("DELETE FROM product_field_config WHERE product_id=? AND context='production'
               AND field_kind IN ('product_snippets','product_snippets_off')", 'i', [$pid]);
            $snipKind = $wantSnip ? 'product_snippets' : 'product_snippets_off';
            q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
               VALUES (?,'production',?,?,'','',?)", 'issi', [$pid, BULK_SNIPPET_FIELD, $snipKind, bulk_next_sort($pid)]);
            $touched = true;
        }

        // ── แจ้งเตือนอะไหล่ที่ควรเปลี่ยน ──
        if ($wantWatch != product_watch_alerts_enabled($pid)) {
            q("DELETE FROM product_field_config WHERE product_id=? AND context='production'
               AND field_kind IN ('watch_alert','watch_alert_cfg')", 'i', [$pid]);
            q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
               VALUES (?,'production',?,'watch_alert_cfg','','',?)", 'isi', [$pid, BULK_WATCH_CFG_FIELD, bulk_next_sort($pid)]);
            foreach ($wantWatch as $alertName => $on) {
                if ($on) {
                    q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                       VALUES (?,'production',?,'watch_alert','','',?)", 'isi', [$pid, $alertName, bulk_next_sort($pid)]);
                }
            }
            $touched = true;
        }

        if ($touched) {
            $changed++;
        }
    }
    flash_set($changed > 0
        ? 'บันทึกแล้ว — เปลี่ยนแปลง ' . number_format($changed) . ' รุ่น'
        : 'บันทึกแล้ว — ไม่มีรุ่นไหนเปลี่ยนแปลง');
    header('Location: ' . BASE_URL . '/settings_bulk.php');
    exit;
}

$rows = qr("SELECT id, name, product_code, icon_path FROM products WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$state = [];
foreach ($rows as $r) {
    $pid = (int) $r['id'];
    $state[$pid] = [
        'snippets'  => product_show_snippets($pid),
        'watch'     => product_watch_alerts_enabled($pid),
        'decided'   => product_watch_alerts_configured($pid),
    ];
}
$snipOnCount = count(array_filter($state, function ($s) { return $s['snippets']; }));
$notDecided = count(array_filter($state, function ($s) { return !$s['decided']; }));

page_header('ตั้งค่ารวมทุกรุ่น', true, '', BASE_URL . '/settings.php');
?>

<p class="muted" style="margin-top:-6px">
  ตั้งค่า 2 เรื่องนี้ให้ทุกรุ่นในหน้าเดียว ไม่ต้องกดเข้าออกทีละรุ่น ·
  <?= number_format(count($rows)) ?> รุ่น · เปิดแผงคำสั่งอยู่ <?= number_format($snipOnCount) ?> รุ่น
</p>

<?php if ($notDecided > 0) { ?>
<div class="panel" style="margin-bottom:14px; padding:10px 14px; border-left:3px solid var(--warning)">
  <b><?= number_format($notDecided) ?> รุ่นยังไม่เคยตั้งค่าแจ้งเตือนอะไหล่</b> —
  รุ่นที่ยังไม่ตั้ง ระบบถือว่า<b>เปิดแจ้งเตือนทุกรายการ</b>อยู่แล้ว ช่องด้านล่างจึงติ๊กไว้ตามสถานะที่ใช้งานจริง
  กดบันทึกโดยไม่แก้อะไร ผลลัพธ์จะเหมือนเดิมทุกประการ
</div>
<?php } ?>

<form method="post" id="bulk-form">
  <?= csrf_field() ?>
  <div style="margin-bottom:10px">
    <input type="text" id="bulk-search" placeholder="ค้นหาชื่อรุ่น / รหัสสินค้า" style="width:min(360px,100%)">
  </div>

  <div class="table-wrap">
  <table class="list" id="tbl-bulk">
    <thead>
      <tr>
        <th style="width:44px"></th>
        <th>รุ่นสินค้า</th>
        <th style="width:210px" class="bulk-col">
          คำสั่งตั้งค่าหมายเลขสินค้า<br>
          <label class="bulk-all"><input type="checkbox" data-toggle-col="snip"> เลือกทั้งหมด</label>
        </th>
        <?php foreach (array_keys($watchCatalog) as $i => $alertName) { ?>
        <th style="width:190px" class="bulk-col">
          <?= h($alertName) ?><br>
          <label class="bulk-all"><input type="checkbox" data-toggle-col="w<?= $i ?>"> เลือกทั้งหมด</label>
        </th>
        <?php } ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r) {
        $pid = (int) $r['id'];
        $st = $state[$pid];
    ?>
      <tr data-search="<?= h(mb_strtolower($r['name'] . ' ' . $r['product_code'])) ?>">
        <td><?= img_tag($r['icon_path'], $r['name'], 'thumb') ?></td>
        <td>
          <input type="hidden" name="pids[]" value="<?= $pid ?>">
          <a href="<?= BASE_URL ?>/settings.php?product=<?= $pid ?>"><?= h($r['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= h($r['product_code']) ?></div>
        </td>
        <td class="bulk-cell">
          <label class="bulk-chk">
            <input type="checkbox" name="snippets[]" value="<?= $pid ?>" data-col="snip" <?= $st['snippets'] ? 'checked' : '' ?>>
            <span>เปิดใช้</span>
          </label>
        </td>
        <?php foreach (array_keys($watchCatalog) as $i => $alertName) { ?>
        <td class="bulk-cell">
          <label class="bulk-chk">
            <input type="checkbox" name="watch[<?= $pid ?>][<?= h($alertName) ?>]" value="1" data-col="w<?= $i ?>" <?= !empty($st['watch'][$alertName]) ? 'checked' : '' ?>>
            <span>แจ้งเตือน</span>
          </label>
        </td>
        <?php } ?>
      </tr>
    <?php } ?>
    </tbody>
  </table>
  </div>
  <p class="muted" id="bulk-empty" hidden style="padding:10px 0">ไม่พบรุ่นที่ค้นหา</p>

  <div class="form-actions" style="margin-top:16px; position:sticky; bottom:0; background:var(--surface,#fff); padding:12px 0; border-top:1px solid var(--border)">
    <button type="submit" class="btn btn-primary"><?= ui_btn_label('check', 'บันทึกทุกรุ่น', 16) ?></button>
    <a href="<?= BASE_URL ?>/settings.php" class="btn btn-line">ยกเลิก</a>
  </div>
</form>

<style>
#tbl-bulk .bulk-col { text-align: center; }
#tbl-bulk .bulk-cell { text-align: center; }
.bulk-all { display: inline-flex; align-items: center; gap: 5px; font-weight: 400; font-size: 12px; color: var(--muted, #6b6580); cursor: pointer; }
.bulk-chk { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.bulk-chk input { width: 17px; height: 17px; }
.bulk-chk span { font-size: 13px; }
</style>

<script>
(function () {
  var form = document.getElementById('bulk-form');
  if (!form) return;

  // เลือกทั้งหมดต่อคอลัมน์ — นับเฉพาะแถวที่ยังไม่ถูกซ่อนด้วยการค้นหา
  form.querySelectorAll('[data-toggle-col]').forEach(function (master) {
    master.addEventListener('change', function () {
      var col = master.getAttribute('data-toggle-col');
      form.querySelectorAll('tbody tr:not([hidden]) input[data-col="' + col + '"]').forEach(function (cb) {
        cb.checked = master.checked;
      });
    });
  });

  var search = document.getElementById('bulk-search');
  var empty = document.getElementById('bulk-empty');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      var shown = 0;
      form.querySelectorAll('tbody tr').forEach(function (tr) {
        var hit = q === '' || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
        tr.hidden = !hit;
        if (hit) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
    });
  }
})();
</script>

<?php page_footer(); ?>
