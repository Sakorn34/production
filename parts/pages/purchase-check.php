<?php
/**
 * pages/purchase-check.php — เช็คยอดเบิกผลิตก่อนสั่งซื้อ (แบบ B)
 *
 * สามแท็บใช้ตารางรูปแบบเดียวกัน: รูปอยู่หน้าชื่อ · เบิกผลิต · สูตรต่อเครื่อง · คงเหลือ · ขั้นต่ำ · สถานะ
 * ช่วงเวลาเลือกได้ 30 วัน · 90 วัน · เดือนนี้ — คงเหลือกับขั้นต่ำเป็นยอดวันนี้
 *
 * Flow: bootstrap → โหลดยอดเบิกผลิต (cache สั้น) → header → แถบช่วงเวลา/แท็บ → ตาราง
 */

$pageTitle = 'เช็คสั่งซื้อ';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/purchase_check.php';

$periodIn = (string) ($_GET['period'] ?? '90d');
if ($periodIn === '90') {
    $periodIn = '90d';
} elseif ($periodIn === '30') {
    $periodIn = '30d';
}
$tabIn = (string) ($_GET['tab'] ?? 'models');
$stIn = (string) ($_GET['st'] ?? 'all');
if (!isset(PURCHASE_CHECK_PERIODS[$periodIn])) {
    $periodIn = '90d';
}
if (!isset(PURCHASE_CHECK_TABS[$tabIn])) {
    $tabIn = 'models';
}
if (!isset(PURCHASE_CHECK_STATUS[$stIn])) {
    $stIn = 'all';
}

$bounds = purchase_check_period_bounds($periodIn);
$data = purchase_check_load_cached($bounds['from']);
$allParts = $data['parts'];
$partById = [];
foreach ($allParts as $p) {
    $partById[(int) $p['part_id']] = $p;
}
$shownParts = purchase_check_filter_parts($allParts, $stIn);
$models = $data['models'];
if ($stIn !== 'all') {
    $keepIds = [];
    foreach ($shownParts as $p) {
        $keepIds[(int) $p['part_id']] = true;
    }
    foreach ($models as $mi => $mod) {
        $mod['parts'] = array_values(array_filter($mod['parts'], static function ($mp) use ($keepIds) {
            return isset($keepIds[(int) $mp['part_id']]);
        }));
        if ($mod['parts']) {
            $models[$mi] = $mod;
        } else {
            unset($models[$mi]);
        }
    }
}
$suppliers = $stIn === 'all' ? $data['suppliers'] : purchase_check_group_suppliers($shownParts);
if ($tabIn === 'models') {
    $shownIds = [];
    foreach ($models as $mod) {
        foreach ($mod['parts'] as $mp) {
            $shownIds[(int) $mp['part_id']] = true;
        }
    }
    $shownCount = count($shownIds);
} else {
    $shownCount = count($shownParts);
}
$counts = $data['counts'];
$todayLabel = purchase_check_thai_date(date('Y-m-d'));

require_once __DIR__ . '/../includes/header.php';
parts_page_header(
    'basket',
    'เช็คสั่งซื้อ',
    'ยอดเบิกผลิตในช่วงที่เลือก · คงเหลือและขั้นต่ำเป็นของวันนี้'
);
?>

<div class="pc-page">
    <div class="pc-tools">
        <div class="pc-tool">
            <span class="pc-tool-label">ช่วงที่ดูยอดเบิก</span>
            <nav class="pc-seg" aria-label="ช่วงที่ดูยอดเบิก">
                <?php foreach (PURCHASE_CHECK_PERIODS as $key => $label):
                    $key = (string) $key;
                    ?>
                <a class="pc-seg-btn<?= $periodIn === $key ? ' is-active' : '' ?>"
                   href="<?= e(purchase_check_url(['period' => $key, 'tab' => $tabIn, 'st' => $stIn])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="pc-tool">
            <span class="pc-tool-label">มุมมอง</span>
            <nav class="pc-seg" aria-label="มุมมอง">
                <?php foreach (PURCHASE_CHECK_TABS as $key => $label): ?>
                <a class="pc-seg-btn<?= $tabIn === $key ? ' is-active' : '' ?>"
                   href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => $key, 'st' => $stIn])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="pc-range">
            <span class="pc-tool-label">ช่วงข้อมูล</span>
            <span class="pc-range-val"><?= e($bounds['from_label']) ?> – <?= e($todayLabel) ?></span>
        </div>
    </div>

    <div class="pc-stats" role="group" aria-label="สรุปช่วงที่เลือก">
        <a class="pc-stat<?= $tabIn === 'models' && $stIn === 'all' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'models', 'st' => 'all'])) ?>">
            <span class="pc-lbl">รุ่นที่เบิกผลิต</span>
            <span class="pc-num"><?= formatNumber($counts['models']) ?></span>
        </a>
        <a class="pc-stat<?= $tabIn === 'suppliers' && $stIn === 'all' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'suppliers', 'st' => 'all'])) ?>">
            <span class="pc-lbl">ผู้จำหน่าย</span>
            <span class="pc-num"><?= formatNumber($counts['suppliers']) ?></span>
        </a>
        <a class="pc-stat pc-stat-order<?= $stIn === 'order' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => $tabIn, 'st' => $stIn === 'order' ? 'all' : 'order'])) ?>">
            <span class="pc-lbl">ควรสั่งแล้ว</span>
            <span class="pc-num"><?= formatNumber($counts['order']) ?></span>
        </a>
        <a class="pc-stat pc-stat-near<?= $stIn === 'near' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => $tabIn, 'st' => $stIn === 'near' ? 'all' : 'near'])) ?>">
            <span class="pc-lbl">ใกล้ถึงขั้นต่ำ</span>
            <span class="pc-num"><?= formatNumber($counts['near']) ?></span>
        </a>
    </div>

    <?php if ($stIn !== 'all'): ?>
    <div class="pc-filter-note">
        <span>แสดงเฉพาะ <b><?= e(PURCHASE_CHECK_STATUS[$stIn]) ?></b> · <?= formatNumber($shownCount) ?> อะไหล่</span>
        <a href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => $tabIn, 'st' => 'all'])) ?>">ล้างตัวกรอง</a>
    </div>
    <?php endif; ?>
    <?php if (!$data['ok']): ?>
    <p class="text-muted" style="color:var(--danger,#dc2626)"><?= e($data['error']) ?></p>
    <?php elseif ($tabIn === 'suppliers'): ?>
    <?php if (!$suppliers): ?>
    <div class="card"><p class="text-muted" style="margin:0;padding:8px 0">ไม่มีการเบิกผลิตตามเงื่อนไขนี้</p></div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrap table-wrap-fold">
            <table class="pc-table pc-table-fixed">
                <?= purchase_check_colgroup_html() ?>
                <?= purchase_check_thead_html() ?>
                <tbody>
                <?php foreach ($suppliers as $si => $sup):
                    $search = mb_strtolower(
                        $sup['name'] . ' ' . implode(' ', array_column($sup['parts'], 'name')) . ' '
                        . implode(' ', array_column($sup['parts'], 'code')),
                        'UTF-8'
                    );
                    $needBits = [];
                    if ((int) $sup['order'] > 0) {
                        $needBits[] = formatNumber($sup['order']) . ' ควรสั่ง';
                    }
                    if ((int) $sup['near'] > 0) {
                        $needBits[] = formatNumber($sup['near']) . ' ใกล้ขั้นต่ำ';
                    }
                    $gid = 's' . $si;
                    echo purchase_check_group_row_html(
                        (string) $sup['name'],
                        formatNumber(count($sup['parts'])) . ' อะไหล่' . ($needBits ? ' · ' . implode(' · ', $needBits) : ''),
                        $search,
                        $gid
                    );
                    foreach ($sup['parts'] as $p) {
                        echo purchase_check_part_row_html(
                            $p,
                            $p['used'],
                            purchase_check_bom_display($p['models'] ?? []),
                            ['search' => $search, 'group' => $gid]
                        );
                    }
                endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <?php if (!$models): ?>
    <div class="card"><p class="text-muted" style="margin:0;padding:8px 0">ไม่มีการเบิกผลิตในช่วงนี้</p></div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrap table-wrap-fold">
            <table class="pc-table pc-table-fixed">
                <?= purchase_check_colgroup_html() ?>
                <?= purchase_check_thead_html() ?>
                <tbody>
                <?php foreach ($models as $mi => $mod):
                    $search = mb_strtolower(
                        $mod['name'] . ' ' . implode(' ', array_column($mod['parts'], 'name')) . ' '
                        . implode(' ', array_column($mod['parts'], 'code')),
                        'UTF-8'
                    );
                    $gid = 'm' . $mi;
                    echo purchase_check_group_row_html(
                        (string) $mod['name'],
                        formatNumber($mod['machines']) . ' เครื่อง · ' . formatNumber(count($mod['parts'])) . ' อะไหล่',
                        $search,
                        $gid
                    );
                    foreach ($mod['parts'] as $mp) {
                        $src = $partById[(int) $mp['part_id']] ?? [
                            'name'          => $mp['name'],
                            'code'          => $mp['code'],
                            'unit'          => $mp['unit'] ?? 'ชิ้น',
                            'stock'         => null,
                            'min'           => 0,
                            'status'        => 'ok',
                            'icon_path'     => '',
                            'purchase_link' => '',
                            'supplier'      => '',
                        ];
                        $src['name'] = (string) $mp['name'];
                        $src['code'] = (string) $mp['code'];
                        $src['unit'] = (string) ($mp['unit'] ?? $src['unit'] ?? 'ชิ้น');
                        echo purchase_check_part_row_html(
                            $src,
                            $mp['qty'],
                            $mp['bom'] ?? null,
                            ['search' => $search, 'group' => $gid]
                        );
                    }
                endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php parts_photo_modal_html(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
