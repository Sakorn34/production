<?php
/**
 * inv_pickups.php — ใบเบิกรอผลิต (แบบ ก: รวมตามรุ่น · 21 ก.ย. 2026)
 *
 * ของที่ระบบ inventory เบิกลงมาให้ผลิตแล้ว → รุ่นไหนต้องผลิตอีกกี่เครื่อง
 * ลงทะเบียนเครื่องผลิตใหม่แล้วตัดยอดให้เอง (asset_new.php → inv_pickup_allocate) · ตัด/ย้าย/ปิดเองได้ในหน้ารายละเอียด
 * ข้อมูลใบเบิกอ่านจาก inventory อย่างเดียว — ไม่แก้อะไรในระบบนั้น
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/inv_pickup.php';
require_login();
ensure_inv_pickup_schema();

$B = BASE_URL;
$preQ = trim((string) ($_GET['pre'] ?? ''));
// รายการ = (ใบ, กลุ่มรุ่น) · กลุ่มรุ่น "1-3" = อะไหล่/ชุดที่ใช้ได้หลายรุ่น
$grpQ = inv_pickup_grp((string) ($_GET['grp'] ?? ''));

// ── รายละเอียดรายการ: ตัดยอดเอง / เอาออก / ปิด-เปิด ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_asset'])) {
    // แท็บ "ไม่ผ่านใบเบิก": จับคู่เครื่องเข้าใบที่เลือก
    csrf_check();
    [$mPre, $mGrp] = array_pad(explode('|', (string) ($_POST['match_to'] ?? ''), 2), 2, '');
    $r = $mPre !== '' ? inv_pickup_manual_alloc($mPre, inv_pickup_grp($mGrp), (string) $_POST['match_asset'], actor_name())
                      : ['ok' => false, 'message' => 'ยังไม่ได้เลือกใบเบิก'];
    flash_set($r['message'], $r['ok'] ? 'ok' : 'err');
    header('Location: ' . $B . '/inv_pickups.php?tab=unmatched');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pre = trim((string) ($_POST['pre'] ?? ''));
    $grp = inv_pickup_grp((string) ($_POST['grp'] ?? ''));
    $back = $B . '/inv_pickups.php?pre=' . rawurlencode($pre) . '&grp=' . $grp;
    if (isset($_POST['alloc_code'])) {
        $n = 0;
        $msgs = [];
        // สแกน/วางได้หลายรหัส คั่นด้วยบรรทัดใหม่ ช่องว่าง หรือจุลภาค
        foreach (preg_split('/[\s,]+/', (string) $_POST['alloc_code'], -1, PREG_SPLIT_NO_EMPTY) as $code) {
            $r = inv_pickup_manual_alloc($pre, $grp, $code, actor_name());
            $n += $r['ok'] ? 1 : 0;
            $msgs[] = $r['message'];
        }
        flash_set(implode(' · ', array_slice($msgs, 0, 6)) . (count($msgs) > 6 ? ' …' : ''), $n ? 'ok' : 'err');
    } elseif (isset($_POST['unalloc'])) {
        inv_pickup_unalloc((int) $_POST['unalloc']);
        flash_set('เอาเครื่องออกจากใบเบิกแล้ว — ยอดคืนใบ');
    } elseif (isset($_POST['close'])) {
        inv_pickup_set_closed($pre, $grp, $_POST['close'] === '1', (string) ($_POST['reason'] ?? ''), actor_name());
        flash_set($_POST['close'] === '1' ? 'ปิดรายการแล้ว' : 'เปิดรายการใหม่แล้ว');
    }
    header('Location: ' . $back);
    exit;
}

$data = inv_pickup_load();
$canMap = function_exists('can_access_settings') ? can_access_settings() : true;
$mapLink = $canMap ? '<a class="btn btn-line" href="' . $B . '/inv_pickup_map.php" data-same-tab>' . ui_btn_label('settings', ' ผูกรุ่น') . '</a>' : '';
$fmtD = function ($d) { return $d !== '' ? date('d/m/y', strtotime($d)) : '—'; };

// ─────────────────────────── หน้ารายละเอียดรายการ (ใบ × รุ่น) ───────────────────────────
if ($preQ !== '' && $grpQ !== '') {
    $item = null;
    foreach ($data['items'] as $it) {
        if ($it['pre_id'] === $preQ && $it['grp'] === $grpQ) {
            $item = $it;
        }
    }
    $doc = $data['docs'][$preQ] ?? null;
    page_header('ใบเบิก ' . $preQ, true, $item ? $item['model'] : '', $B . '/inv_pickups.php', $mapLink);
    if (!$data['ok']) {
        echo '<div class="panel"><p class="err" style="margin:0">ต่อระบบ inventory ไม่ได้: ' . h($data['error']) . '</p></div>';
        page_footer();
        exit;
    }
    if (!$item || !$doc) {
        echo '<div class="panel"><p class="muted" style="margin:0">ไม่พบรายการนี้ (อาจเก่ากว่าวันเริ่มติดตาม หรือยังไม่ได้ผูกรุ่น)</p></div>';
        page_footer();
        exit;
    }
    $assets = inv_pickup_alloc_assets($preQ, $grpQ);
    $stLabel = ['waiting' => 'รอคลังจ่าย', 'ready' => 'รอผลิต', 'done' => 'ผลิตครบ'][$item['state']];
    ?>
<div class="panel ip-detail">
  <div class="ip-d-head">
    <div>
      <div class="ip-d-model"><?= h($item['model']) ?> <span class="ip-st is-<?= h($item['state']) ?>"><?= h($stLabel) ?><?= $item['closed'] ? ' · ปิดเอง' : '' ?></span></div>
      <div class="muted">เบิก <?= h($fmtD($doc['date'])) ?> โดย <?= h($doc['by']) ?> · ชุด "<?= h($doc['set']) ?>"
        <?= $doc['issued_at'] !== '' ? ' · คลังจ่าย ' . h($fmtD($doc['issued_at'])) . ($doc['issued_by'] !== '' ? ' (' . h($doc['issued_by']) . ')' : '') : '' ?></div>
      <?php if ($doc['remark'] !== '') { ?><div class="muted">หมายเหตุ: <?= h($doc['remark']) ?></div><?php } ?>
    </div>
    <div class="ip-d-nums"><b><?= (int) $item['done'] ?></b> / <?= (int) $item['qty'] ?> เครื่อง<div class="muted">เหลือ <?= (int) $item['left'] ?></div></div>
  </div>
  <?php if ($item['missing']) { ?><p class="ip-warn">คลังยังไม่ได้จ่าย: <?= h(implode(' · ', $item['missing'])) ?></p><?php } ?>
  <div class="ip-d-actions">
    <?= inv_pickups_register_links($item, 'btn') ?>
    <form method="post" class="ip-inline">
      <?= csrf_field() ?><input type="hidden" name="pre" value="<?= h($preQ) ?>"><input type="hidden" name="grp" value="<?= h($grpQ) ?>">
      <?php if ($item['closed']) { ?>
        <button type="submit" name="close" value="0" class="btn btn-line">เปิดรายการใหม่</button>
        <span class="muted">ปิดโดย <?= h($item['closed']['closed_by']) ?><?= $item['closed']['reason'] ? ' — ' . h($item['closed']['reason']) : '' ?></span>
      <?php } elseif ($item['left'] > 0) { ?>
        <input type="text" name="reason" placeholder="เหตุผลที่ปิด (ของเสีย ฯลฯ)" class="ip-reason">
        <button type="submit" name="close" value="1" class="btn btn-line" onclick="return confirm('ปิดรายการนี้ ทั้งที่ยังเหลือ <?= (int) $item['left'] ?> เครื่อง?')">ปิดรายการ</button>
      <?php } ?>
    </form>
  </div>
</div>

<div class="panel">
  <h3 style="margin:0 0 8px">เครื่องที่ตัดยอดแล้ว (<?= count($assets) ?>)</h3>
  <form method="post" class="ip-alloc">
    <?= csrf_field() ?><input type="hidden" name="pre" value="<?= h($preQ) ?>"><input type="hidden" name="grp" value="<?= h($grpQ) ?>">
    <input type="text" name="alloc_code" placeholder="พิมพ์หรือสแกน S/N เพื่อตัดยอดเข้าใบนี้" data-scan="submit" autocomplete="off">
    <button type="submit" class="btn btn-line">ตัดยอด</button>
  </form>
  <p class="muted" style="font-size:12.5px;margin:4px 0 10px">เครื่องที่ตัดจากใบอื่นอยู่แล้ว จะถูกย้ายมาใบนี้</p>
  <?php if ($assets) { ?>
  <div class="ip-sns">
    <?php foreach ($assets as $a) { ?>
    <span class="ip-sn"><a href="<?= $B ?>/asset.php?id=<?= (int) $a['asset_id'] ?>"><?= h($a['asset_code']) ?></a><?= count($item['products']) > 1 ? ' <small class="muted">' . h($a['pname']) . '</small>' : '' ?><?= $a['source'] === 'manual' ? ' <small class="muted">ตัดเอง</small>' : '' ?>
      <form method="post" class="ip-inline"><?= csrf_field() ?><input type="hidden" name="pre" value="<?= h($preQ) ?>"><input type="hidden" name="grp" value="<?= h($grpQ) ?>">
        <button type="submit" name="unalloc" value="<?= (int) $a['asset_id'] ?>" class="ip-x" title="เอาออกจากใบนี้" aria-label="เอา <?= h($a['asset_code']) ?> ออกจากใบนี้" onclick="return confirm('เอา <?= h($a['asset_code']) ?> ออกจากใบเบิกนี้?')">✕</button></form></span>
    <?php } ?>
  </div>
  <?php } else { ?><p class="muted" style="margin:0">ยังไม่มี</p><?php } ?>
</div>

<div class="panel">
  <h3 style="margin:0 0 8px">อะไหล่ในใบเบิก (ขอ / คลังจ่าย)</h3>
  <div class="table-wrap"><table class="list">
    <tr><th>อะไหล่</th><th class="num-col">ขอ</th><th class="num-col">จ่ายแล้ว</th></tr>
    <?php foreach ($doc['lines'] as $ln) { $short = $doc['issued'] && $ln['got'] + 0.001 < $ln['req']; ?>
    <tr><td><?= h($ln['name']) ?></td><td class="num-col"><?= 0 + $ln['req'] ?></td>
      <td class="num-col"><?= $doc['issued'] ? '<b' . ($short ? ' class="ip-short"' : '') . '>' . (0 + $ln['got']) . '</b>' : '<span class="muted">—</span>' ?></td></tr>
    <?php } ?>
  </table></div>
</div>
<?php
    inv_pickups_css();
    page_footer();
    exit;
}

// ─────────────────────────── หน้ารวม: การ์ดต่อรุ่น ───────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['ready', 'waiting', 'done', 'unmatched'], true) ? $_GET['tab'] : 'ready';
$unmatched = inv_pickup_unmatched_assets();
$byModel = ['ready' => [], 'waiting' => [], 'done' => []];
foreach ($data['items'] as $it) {
    $byModel[$it['state']][$it['grp']][] = $it;
}
$extraN = count(array_filter($data['docs'], function ($d) { return !empty($d['extra']); }));
$unmappedN = array_sum($data['unmapped']);
$sumLeft = 0;
foreach ($byModel['ready'] as $list) {
    foreach ($list as $it) {
        $sumLeft += $it['left'];
    }
}

page_header('ใบเบิกรอผลิต', true, $data['ok'] ? 'รอผลิต ' . number_format($sumLeft) . ' เครื่อง · ตั้งแต่ ' . $fmtD(inv_pickup_since()) : 'จากระบบ inventory', $B . '/assets.php', $mapLink);
if (!$data['ok']) { ?>
<div class="panel"><p class="err" style="margin:0">ต่อระบบ inventory ไม่ได้: <?= h($data['error']) ?></p></div>
<?php page_footer(); exit; } ?>

<?php if ($unmappedN) { ?>
<div class="panel ip-note">มี <?= number_format($unmappedN) ?> ใบที่ยังไม่ได้ผูกรุ่น จึงยังไม่ขึ้นในคิว<?= $canMap ? ' — <a href="' . $B . '/inv_pickup_map.php">ผูกรุ่น ›</a>' : '' ?></div>
<?php } ?>

<div class="ip-tabs" role="tablist">
  <?php foreach (['ready' => 'รอผลิต', 'waiting' => 'รอคลังจ่าย', 'done' => 'ผลิตครบ'] as $k => $label) { ?>
  <a class="ip-tab<?= $tab === $k ? ' is-on' : '' ?>" href="?tab=<?= $k ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $label ?> (<?= count($byModel[$k]) ?> รุ่น)</a>
  <?php } ?>
  <a class="ip-tab ip-tab-warn<?= $tab === 'unmatched' ? ' is-on' : '' ?>" href="?tab=unmatched" role="tab" aria-selected="<?= $tab === 'unmatched' ? 'true' : 'false' ?>">ไม่ผ่านใบเบิก (<?= count($unmatched) ?> เครื่อง)</a>
</div>

<?php if ($tab === 'unmatched') {
    // ใบที่รับรุ่นนั้นได้และยังเหลือ (รอผลิต) — ให้เลือกจับคู่
    $openFor = [];
    foreach ($byModel['ready'] as $list) {
        foreach ($list as $it) {
            foreach ($it['products'] as $p) {
                $openFor[$p][] = $it;
            }
        }
    } ?>
<div class="panel">
  <p class="muted" style="margin:0 0 10px;font-size:12.5px">เครื่องรุ่นที่ติดตามกับใบเบิก ที่ลงทะเบียนตั้งแต่ <?= h($fmtD(inv_pickup_since())) ?> แต่ไม่ได้ตัดยอดใบเบิกใด
    (ลงทะเบียนตอนไม่มีใบเบิกค้าง) — ถ้ามีใบเบิกของเครื่องนั้นแล้ว เลือกใบแล้วกดจับคู่</p>
  <?php if (!$unmatched) { ?><p class="muted" style="margin:0">ไม่มี — ทุกเครื่องผ่านใบเบิก</p><?php } else { ?>
  <div class="table-wrap"><table class="list">
    <tr><th data-pri="1">เครื่อง</th><th data-pri="2">ลงทะเบียน</th><th data-pri="1">จับคู่กับใบเบิก</th></tr>
    <?php foreach ($unmatched as $u) { $opts = $openFor[(int) $u['product_id']] ?? []; ?>
    <tr>
      <td data-pri="1"><a href="<?= $B ?>/asset.php?id=<?= (int) $u['id'] ?>"><b><?= h($u['asset_code']) ?></b></a><div class="muted" style="font-size:12px"><?= h($u['pname']) ?></div></td>
      <td data-pri="2"><?= h($fmtD($u['created_at'])) ?><?= $u['created_by'] ? ' · ' . h($u['created_by']) : '' ?></td>
      <td data-pri="1"><?php if ($opts) { ?>
        <form method="post" class="ip-inline"><?= csrf_field() ?><input type="hidden" name="match_asset" value="<?= h($u['asset_code']) ?>">
          <select name="match_to" class="ip-match"><?php foreach ($opts as $o) { ?>
            <option value="<?= h($o['pre_id'] . '|' . $o['grp']) ?>"><?= h($fmtD($o['date']) . ' · ' . ($o['kind'] === 'parts' ? implode(', ', array_unique($o['parts'])) : $o['set']) . ' · เหลือ ' . $o['left']) ?></option>
          <?php } ?></select>
          <button type="submit" class="btn btn-sm btn-line">จับคู่</button>
        </form>
      <?php } else { ?><span class="muted">ไม่มีใบเบิกค้างของรุ่นนี้</span><?php } ?></td>
    </tr>
    <?php } ?>
  </table></div>
  <?php } ?>
</div>
<?php
    inv_pickups_css();
    page_footer();
    exit;
} ?>

<?php
$models = $byModel[$tab];
// รอผลิต: เหลือมากสุดขึ้นก่อน · แท็บอื่นเรียงตามชื่อ
uasort($models, function ($a, $b) use ($tab) {
    $sa = array_sum(array_column($a, $tab === 'done' ? 'done' : ($tab === 'waiting' ? 'qty' : 'left')));
    $sb = array_sum(array_column($b, $tab === 'done' ? 'done' : ($tab === 'waiting' ? 'qty' : 'left')));
    return [$sb, $a[0]['model']] <=> [$sa, $b[0]['model']];
});
if (!$models) { ?>
<div class="panel"><p class="muted" style="margin:0"><?= $tab === 'ready' ? 'ไม่มีของที่เบิกลงมาแล้วรอผลิต' : ($tab === 'waiting' ? 'ไม่มีใบที่รอคลังจ่าย' : 'ยังไม่มีรายการที่ผลิตครบ') ?></p></div>
<?php } ?>
<div class="ip-grid">
<?php foreach ($models as $pid => $list) {
    $qty = array_sum(array_column($list, 'qty'));
    $done = array_sum(array_column($list, 'done'));
    $left = array_sum(array_column($list, 'left'));
    $big = $tab === 'done' ? $done : ($tab === 'waiting' ? $qty : $left);
    $pct = $qty > 0 ? min(100, round($done / $qty * 100)) : 0;
    $miss = [];
    foreach ($list as $it) {
        foreach ($it['missing'] as $m) {
            $miss[$m] = true;
        }
    } ?>
  <div class="ip-card">
    <div class="ip-c-head"><b class="ip-c-model"><?= h($list[0]['model']) ?></b><span><b class="ip-c-big"><?= number_format($big) ?></b> <span class="muted">เครื่อง</span></span></div>
    <div class="ip-bar"><i style="width:<?= $pct ?>%"></i></div>
    <div class="muted ip-c-sub">ผลิตแล้ว <?= number_format($done) ?> / <?= number_format($qty) ?> · <?= count($list) ?> ใบ
      <?= $miss ? '<span class="ip-warn-chip">' . h(implode(', ', array_slice(array_keys($miss), 0, 2))) . (count($miss) > 2 ? ' …' : '') . ' ยังไม่ได้จ่าย</span>' : '' ?></div>
    <?php foreach ($list as $it) { ?>
    <a class="ip-doc" href="?pre=<?= rawurlencode($it['pre_id']) ?>&amp;grp=<?= h($it['grp']) ?>">
      <span><?= h($fmtD($it['date'])) ?> · <?= h($it['kind'] === 'parts' ? implode(', ', array_unique($it['parts'])) : $it['set']) ?> · <?= h($it['by']) ?></span>
      <span class="ip-doc-n"><?= $tab === 'ready' ? 'เหลือ ' . (int) $it['left'] . '/' . (int) $it['qty'] : (int) $it['qty'] ?> ›</span>
    </a>
    <?php } ?>
    <?php if ($tab !== 'done') { ?><div class="ip-c-go"><?= inv_pickups_register_links($list[0], 'btn btn-sm btn-line') ?></div><?php } ?>
  </div>
<?php } ?>
</div>
<?php if ($extraN) { ?><p class="muted" style="font-size:12.5px">มีใบ "เบิกเสริม" <?= $extraN ?> ใบ (เบิกอะไหล่ไม่ถึงครึ่งชุด) — ไม่นับเป็นเครื่องที่ต้องผลิต</p><?php } ?>
<?php
inv_pickups_css();
page_footer();

/**
 * ปุ่มไปหน้าลงทะเบียน — กลุ่มหลายรุ่นได้ปุ่มต่อรุ่น (เลือกรุ่นให้เลย)
 *
 * @param array<string,mixed> $item
 * @param string              $cls
 * @return string
 */
function inv_pickups_register_links(array $item, string $cls): string
{
    $names = explode(' / ', (string) $item['model']);
    $html = '';
    foreach ($item['products'] as $i => $pid) {
        $label = count($item['products']) > 1 ? ' ลงทะเบียน ' . ($names[$i] ?? '#' . $pid) : ' ลงทะเบียนเครื่องรุ่นนี้';
        $html .= '<a class="' . h($cls) . '" href="' . BASE_URL . '/asset_new.php?product=' . (int) $pid . '">' . ui_btn_label('assets', $label) . '</a> ';
    }
    return $html;
}

/**
 * สไตล์ของหน้านี้
 *
 * @return void
 */
function inv_pickups_css(): void
{ ?>
<style>
.ip-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px; }
.ip-tab { padding: 6px 14px; border-radius: 999px; border: 1px solid var(--border, #e5e7eb); text-decoration: none; color: inherit; font-size: calc(13.5px * var(--font-scale, 1)); background: var(--surface, #fff); }
.ip-tab-warn { border-color: #fde68a; }
.ip-match { max-width: 100%; min-width: 0; }
.ip-tab.is-on { background: var(--accent-soft, #fdf2f8); border-color: var(--accent, #e0337f); color: var(--accent, #e0337f); font-weight: 700; }
.ip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(300px, 100%), 1fr)); gap: 12px; margin-bottom: 12px; }
.ip-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
.ip-c-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.ip-c-model { font-size: calc(15px * var(--font-scale, 1)); }
.ip-c-big { font-size: calc(22px * var(--font-scale, 1)); }
.ip-bar { height: 6px; border-radius: 3px; background: var(--surface-2, #f1f0f7); overflow: hidden; }
.ip-bar i { display: block; height: 100%; background: #6ee7b7; }
.ip-c-sub { font-size: calc(12.5px * var(--font-scale, 1)); }
.ip-doc { display: flex; justify-content: space-between; gap: 8px; padding: 6px 0; border-top: 1px dashed var(--border, #e5e7eb); font-size: calc(12.5px * var(--font-scale, 1)); color: inherit; text-decoration: none; }
.ip-doc > span:first-child { min-width: 0; overflow-wrap: anywhere; }
.ip-doc-n { white-space: nowrap; color: var(--muted, #6b7280); }
.ip-c-go { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.ip-warn-chip { display: inline-block; font-size: 11.5px; background: #fef3c7; color: #854d0e; border-radius: 6px; padding: 0 6px; }
.ip-warn { background: #fef3c7; color: #854d0e; border-radius: 8px; padding: 6px 10px; margin: 10px 0 0; }
.ip-note { background: #fffbeb; }
.ip-st { display: inline-block; font-size: 12px; border-radius: 6px; padding: 1px 8px; font-weight: 400; vertical-align: middle; }
.ip-st.is-waiting { background: #f1f5f9; color: #475569; }
.ip-st.is-ready { background: #dbeafe; color: #1e40af; }
.ip-st.is-done { background: #dcfce7; color: #166534; }
.ip-d-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ip-d-model { font-size: calc(18px * var(--font-scale, 1)); font-weight: 700; margin-bottom: 4px; }
.ip-d-nums { text-align: right; font-size: calc(15px * var(--font-scale, 1)); }
.ip-d-nums b { font-size: calc(24px * var(--font-scale, 1)); }
.ip-d-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
.ip-inline { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
.ip-reason { min-width: 0; width: 220px; max-width: 100%; }
.ip-alloc { display: flex; gap: 8px; flex-wrap: wrap; }
.ip-alloc input[type=text] { flex: 1 1 220px; min-width: 0; }
.ip-sns { display: flex; flex-wrap: wrap; gap: 6px; }
.ip-sn { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 2px 4px 2px 8px; font-size: calc(13px * var(--font-scale, 1)); }
.ip-x { border: 0; background: none; color: var(--muted, #6b7280); cursor: pointer; padding: 2px 6px; min-height: 0 !important; }
.ip-short { color: #b91c1c; }
</style>
<?php }
