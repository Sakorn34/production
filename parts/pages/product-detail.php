<?php
/**
 * pages/product-detail.php — รายละเอียดอะไหล่ + แก้ไขข้อมูลพื้นฐาน
 */

$pageTitle = 'รายละเอียดอะไหล่';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/product_edit.php';

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$returnTo = url('/pages/product-detail.php' . ($productId ? '?id=' . $productId : ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && parts_stock_forms_handle_post($db, $stock, $returnTo, $line_name ?? null)) {
    exit;
}
if (parts_product_forms_handle_post($db, $stock, $returnTo)) {
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$rawProduct = $productId ? $stock->getProduct($productId) : null;
$product = $rawProduct ? parts_enrich_product($rawProduct) : null;
// ดึงมากพอให้สรุปรายรุ่นเป็นยอดจริง ไม่ใช่ยอดของ 50 รายการล่าสุด
$HIST_MAX = 400;
$history = $product ? $stock->getProductStockOutHistory($productId, $HIST_MAX) : [];
$icon = '';
if ($product) {
    $icons = parts_product_icon_map([$product]);
    $icon = $icons[$product['code']] ?? '';
}

// ปุ่มบนหัวหน้า: ย้อนกลับ + งานที่ทำต่อกับอะไหล่ชิ้นนี้บ่อยที่สุด
// รับเข้า/เบิกออก เปิด modal บนหน้านี้เลย — เดิมเด้งไปหน้าอะไหล่รวม คนที่กำลังอ่าน
// ประวัติของชิ้นนี้อยู่จึงหลุดออกจากหน้าไปทุกครั้ง ตัวจัดการ POST ใช้ตัวเดียวกับหน้านั้น
$detailActions = '<a href="' . e(url('/pages/products.php')) . '" class="btn btn-outline btn-with-icon">← กลับไปหน้าอะไหล่รวม</a>';
if ($product) {
    $detailActions .= '<span class="parts-actions-sep" aria-hidden="true"></span>'
        . '<button type="button" class="btn btn-success btn-with-icon" data-open-modal="stock-in-add-modal">'
        . ui_icon_html('stock-in', 16, 'btn-svg') . ' รับเข้า</button>'
        . '<button type="button" class="btn btn-danger btn-with-icon" data-open-modal="stock-out-item-add-modal">'
        . ui_icon_html('stock-out-item', 16, 'btn-svg') . ' เบิกออก</button>';
}
parts_page_header('products', 'รายละเอียดอะไหล่', 'ข้อมูลอะไหล่ ราคา ลิงก์สั่งซื้อ และประวัติการเบิกของชิ้นนี้', $detailActions);
?>

<div class="card parts-list-card">
    <?php if (!$product): ?>
        <p class="text-muted">ไม่พบอะไหล่ หรือไม่มีรหัสสินค้าใน URL</p>
        <p><a href="<?= url('/pages/products.php') ?>" class="btn btn-outline">← กลับไปหน้าอะไหล่</a></p>
    <?php else: ?>
        <div class="product-detail-hero">
            <div class="product-detail-hero-media">
                <?php if ($icon): ?>
                    <?= parts_img_tag($icon, parts_display_name($product), 'parts-thumb-lg') ?>
                <?php else: ?>
                    <div class="parts-thumb-lg-placeholder" aria-hidden="true"></div>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline btn-with-icon" style="margin-top:0.5rem"
                    data-open-modal="product-icon-modal"
                    data-fill-modal="product-icon-modal"
                    data-product-id="<?= (int) $product['id'] ?>"
                    data-product-name="<?= e(parts_display_name($product)) ?>"
                    data-product-return-to="<?= e($returnTo) ?>">
                    <?= ui_icon_html('edit', 14, 'btn-svg') ?> เปลี่ยนรูป
                </button>
            </div>
            <div>
                <h2 style="margin:0 0 0.35rem;font-size:1.2rem"><?= e(parts_display_name($product)) ?></h2>
                <?php if (!empty($product['display_sub']) && $product['display_sub'] !== $product['code']): ?>
                <p class="text-muted" style="margin:0;font-size:12px">รหัสอะไหล่ (Production): <?= e($product['display_sub']) ?></p>
                <?php endif; ?>
                <p class="text-muted" style="margin:0.15rem 0 0;font-size:12px">รหัสอะไหล่ (สต็อก): <?= e($product['code']) ?></p>
                <p style="margin:0.5rem 0 0">
                    <strong><?= formatNumber($product['quantity']) ?></strong> <?= e($product['unit']) ?> คงเหลือ
                    <?php
                    // สถานะเดียวกับแดชบอร์ด มาจาก shared/stock_status.php
                    $pdStatus = stock_status_key((int) $product['quantity'], (int) $product['min_stock']);
                    $pdBadge = ['out' => 'badge-danger', 'critical' => 'badge-danger', 'low' => 'badge-warning', 'near' => 'badge-near', 'ok' => 'badge-success'];
                    ?>
                    <span class="badge <?= e($pdBadge[$pdStatus] ?? 'badge-success') ?>"><?= e(stock_status_meta($pdStatus)['label']) ?></span>
                </p>
            </div>
        </div>

        <div class="grid-2" style="margin-bottom:1.25rem">
            <div>
                <div class="section-hd-row">
                    <h3 style="margin:0;font-size:1rem">ข้อมูลพื้นฐาน</h3>
                    <?= parts_product_edit_button($product, $returnTo) ?>
                </div>
                <table class="parts-table" style="margin-top:0.75rem">
                    <tbody>
                        <tr><th>สต็อกขั้นต่ำ</th><td><?= formatNumber($product['min_stock']) ?> <?= e($product['unit']) ?></td></tr>
                        <tr><th>ผู้จำหน่าย</th><td><?= !empty($product['supplier']) ? e($product['supplier']) : '-' ?></td></tr>
                        <tr><th>ราคา</th><td><?= formatCurrency($product['price'] ?? null) ?></td></tr>
                        <tr>
                            <th>ลิงก์สั่งซื้อ</th>
                            <td>
                                <?php if (!empty($product['purchase_link'])): ?>
                                    <a href="<?= e($product['purchase_link']) ?>" target="_blank" rel="noopener noreferrer" class="detail-link">สั่งซื้อทันที</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>อัปเดตล่าสุด</th><td><?= formatDate($product['updated_at'] ?? $product['created_at']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h3 style="margin-bottom:0.75rem;font-size:1rem">ประวัติการเบิกของอะไหล่ชิ้นนี้</h3>
        <?php if (empty($history)): ?>
            <p class="text-muted">ยังไม่มีการเบิกออกสำหรับอะไหล่ชิ้นนี้</p>
        <?php else:
            // จัดเป็น รุ่นสินค้า → หมายเลขเครื่อง → ใบงาน — เดิมเป็นตารางเรียงตามเวลาล้วน
            // อ่านแล้วไม่รู้ว่าอะไหล่ชิ้นนี้ลงไปกับรุ่นไหนบ้าง และคอลัมน์รูปก็เป็นรูปเดียวกันทุกแถว
            $histGroups = parts_history_group_by_model($history);
            $prodBase = production_app_base_url();
        ?>
        <p class="text-muted" style="margin:0 0 0.6rem;font-size:0.82rem">
            <?= formatNumber(count($history)) ?> รายการ<?= count($history) >= $HIST_MAX ? " ล่าสุด (มีมากกว่านี้)" : "" ?> · กดชื่อรุ่นเพื่อย่อ/ขยาย
        </p>
        <?php foreach ($histGroups as $gi => $g): ?>
        <details class="part-hist-grp"<?= $gi === 0 ? ' open' : '' ?>>
            <summary class="part-hist-sum">
                <b<?= $g['known'] ? '' : ' class="text-muted"' ?>><?= e($g['model']) ?></b>
                <span class="text-muted part-hist-sum-meta">
                    <?php if ($g['known']): ?><?= formatNumber(count($g['sns'])) ?> เครื่อง · <?php endif; ?>
                    <?= formatNumber($g['docs']) ?> ใบ · <?= formatNumber($g['qty']) ?> ชิ้น
                </span>
            </summary>
            <div class="part-hist-body">
                <?php foreach ($g['sns'] as $sn): ?>
                <div class="part-hist-sn">
                    <?php if ($sn['sn'] !== ''): ?>
                    <div class="part-hist-sn-head">
                        <?php if ($sn['asset_id'] > 0): ?>
                        <a href="<?= e($prodBase . '/asset.php?id=' . (int) $sn['asset_id']) ?>" target="_blank" rel="noopener"
                           class="detail-link" title="เปิดหน้าเครื่องนี้ในระบบทะเบียนเครื่อง"><?= e($sn['sn']) ?></a>
                        <?php else: ?>
                        <b><?= e($sn['sn']) ?></b>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($sn['rows'] as $row): ?>
                    <div class="part-hist-doc">
                        <a href="<?= url('/pages/history.php?id=' . (int) $row['id']) ?>" class="detail-link part-hist-doc-no"><?= e($row['doc_no']) ?></a>
                        <?php if ($row['set_id']): ?>
                        <span class="badge badge-info">ชุดเบิก</span>
                        <span class="text-muted">[<?= e($row['set_code']) ?>] <?= e($row['set_name']) ?></span>
                        <?php else: ?>
                        <span class="badge badge-info">เบิกรายชิ้น</span>
                        <?php endif; ?>
                        <span class="text-muted part-hist-who"><?= e($row['issued_by'] ?: '-') ?><?= $row['note'] ? ' · ' . e($row['note']) : '' ?></span>
                        <span class="text-danger part-hist-qty">-<?= formatNumber($row['quantity']) ?></span>
                        <span class="text-muted part-hist-when"><?= formatDate($row['created_at']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php parts_product_edit_modals(); ?>
<?php if ($product) { parts_stock_modals_for_product($product); } ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
