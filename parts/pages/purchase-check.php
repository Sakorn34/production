<?php
/**
 * pages/purchase-check.php — เช็คสั่งซื้อ: อะไหล่ตามสูตรของแต่ละรุ่นที่ผลิต เทียบคงเหลือวันนี้
 *
 * ไม่มีช่วงเวลาและยอดเบิกแล้ว — การเบิกใช้ดูที่โปรไฟล์อะไหล่ (กดชื่ออะไหล่)
 *
 * Flow: bootstrap → โหลดสูตร + คงเหลือ → การ์ดสรุป (กรองสถานะ) → ตารางแยกตามรุ่น
 */

$pageTitle = 'เช็คสั่งซื้อ';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/purchase_check.php';

$stIn = (string) ($_GET['st'] ?? 'all');
if (!isset(PURCHASE_CHECK_STATUS[$stIn])) {
    $stIn = 'all';
}

$data = purchase_check_load();
$counts = $data['counts'];
$shownModels = [];
$shownParts = [];
foreach ($data['models'] as $m) {
    $parts = purchase_check_filter_parts($m['parts'], $stIn);
    if (!$parts) {
        continue;
    }
    foreach ($parts as $p) {
        $shownParts[(int) $p['part_id']] = true;
    }
    $shownModels[] = ['model' => $m, 'parts' => $parts];
}

require_once __DIR__ . '/../includes/header.php';
parts_page_header(
    'basket',
    'เช็คสั่งซื้อ',
    'อะไหล่ตามสูตรของแต่ละรุ่นที่ผลิต · คงเหลือและขั้นต่ำเป็นของวันนี้'
);
?>

<div class="pc-page">
    <div class="pc-stats" role="group" aria-label="สรุปสถานะอะไหล่">
        <a class="pc-stat<?= $stIn === 'all' ? ' is-on' : '' ?>" href="<?= e(purchase_check_url('all')) ?>">
            <span class="pc-lbl">รุ่นที่ผลิต</span>
            <span class="pc-num"><?= formatNumber($counts['models']) ?></span>
        </a>
        <a class="pc-stat" href="<?= e(purchase_check_url('all')) ?>">
            <span class="pc-lbl">อะไหล่ในสูตร</span>
            <span class="pc-num"><?= formatNumber($counts['parts']) ?></span>
        </a>
        <a class="pc-stat pc-stat-order<?= $stIn === 'order' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url($stIn === 'order' ? 'all' : 'order')) ?>">
            <span class="pc-lbl">ควรสั่งแล้ว</span>
            <span class="pc-num"><?= formatNumber($counts['order']) ?></span>
        </a>
        <a class="pc-stat pc-stat-near<?= $stIn === 'near' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url($stIn === 'near' ? 'all' : 'near')) ?>">
            <span class="pc-lbl">ใกล้ถึงขั้นต่ำ</span>
            <span class="pc-num"><?= formatNumber($counts['near']) ?></span>
        </a>
    </div>

    <?php if ($stIn !== 'all'): ?>
    <div class="pc-filter-note">
        <span>แสดงเฉพาะ <b><?= e(PURCHASE_CHECK_STATUS[$stIn]) ?></b> · <?= formatNumber(count($shownParts)) ?> อะไหล่ ใน <?= formatNumber(count($shownModels)) ?> รุ่น</span>
        <a href="<?= e(purchase_check_url('all')) ?>">ล้างตัวกรอง</a>
    </div>
    <?php endif; ?>

    <?php if (!$data['ok']): ?>
    <p class="text-muted" style="color:var(--danger,#dc2626)"><?= e($data['error']) ?></p>
    <?php elseif (!$shownModels): ?>
    <div class="card"><p class="text-muted" style="margin:0;padding:8px 0"><?= $stIn === 'all' ? 'ยังไม่มีรุ่นที่ตั้งสูตรผลิตไว้' : 'ไม่มีอะไหล่ในสถานะนี้' ?></p></div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrap table-wrap-fold">
            <table class="pc-table pc-table-fixed">
                <?= purchase_check_thead_html() ?>
                <tbody>
                <?php foreach ($shownModels as $row):
                    echo purchase_check_group_row_html($row['model'], count($row['parts']));
                    foreach ($row['parts'] as $p) {
                        echo purchase_check_part_row_html($p);
                    }
                endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="text-muted pc-foot-note">รายการตามสูตรผลิตของแต่ละรุ่น (ตั้งที่หน้ารุ่นสินค้าในระบบทะเบียนเครื่อง) · ดูประวัติการเบิกได้ที่โปรไฟล์อะไหล่ — กดชื่ออะไหล่</p>
    <?php endif; ?>
</div>

<?php parts_photo_modal_html(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
