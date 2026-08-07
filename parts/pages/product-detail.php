<?php
/**
 * pages/product-detail.php — รายละเอียดอะไหล่ + แก้ไขข้อมูลพื้นฐาน
 */

$pageTitle = 'รายละเอียดอะไหล่';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/product_edit.php';

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$returnTo = url('/pages/product-detail.php' . ($productId ? '?id=' . $productId : ''));

if (parts_product_forms_handle_post($db, $stock, $returnTo)) {
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$rawProduct = $productId ? $stock->getProduct($productId) : null;
$product = $rawProduct ? parts_enrich_product($rawProduct) : null;
$history = $product ? $stock->getProductStockOutHistory($productId, 50) : [];
$icon = '';
if ($product) {
    $icons = parts_product_icon_map([$product]);
    $icon = $icons[$product['code']] ?? '';
}

parts_page_header('products', 'รายละเอียดอะไหล่', 'ข้อมูลอะไหล่ ราคา ลิงก์สั่งซื้อ และประวัติการเบิกของชิ้นนี้');
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
        <?php else: ?>
        <div class="table-wrap">
            <table class="parts-table">
                <thead>
                    <tr>
                        <th class="col-img">รูป</th>
                        <th>เลขที่</th>
                        <th>ประเภท</th>
                        <th class="text-right">จำนวน</th>
                        <th>ผู้เบิก</th>
                        <th>หมายเหตุ</th>
                        <th>วันเวลา</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                    <tr>
                        <td class="col-img"><?= parts_img_tag($icon, parts_display_name($product)) ?></td>
                        <td><a href="<?= url('/pages/history.php?id=' . (int) $row['id']) ?>" class="detail-link"><?= e($row['doc_no']) ?></a></td>
                        <td>
                            <?php if ($row['set_id']): ?>
                                <span class="badge badge-info">ชุดเบิก</span>
                                [<?= e($row['set_code']) ?>] <?= e($row['set_name']) ?>
                            <?php else: ?>
                                <span class="badge badge-info">เบิกรายชิ้น</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-danger">-<?= formatNumber($row['quantity']) ?></td>
                        <td><?= e($row['issued_by'] ?: '-') ?></td>
                        <td class="text-muted"><?= e($row['note'] ?: '-') ?></td>
                        <td class="text-muted"><?= formatDate($row['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <p style="margin-top: 1rem;"><a href="<?= url('/pages/products.php') ?>" class="btn btn-outline">← กลับไปหน้าอะไหล่</a></p>
    <?php endif; ?>
</div>

<?php parts_product_edit_modals(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
