<?php
/**
 * pages/products.php — รายการอะไหล่ทั้งหมด + เพิ่มอะไหล่ (modal) + sync production
 */

$pageTitle = 'อะไหล่';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/production_sync.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $code = generateProductCode($db);
            $stmt = $db->prepare(
                'INSERT INTO products (code, name, unit, quantity, min_stock, price, purchase_link, supplier, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $code,
                trim($_POST['name']),
                trim($_POST['unit']),
                (int) $_POST['quantity'],
                (int) $_POST['min_stock'],
                ($_POST['price'] !== '' ? (float) $_POST['price'] : 0.00),
                trim($_POST['purchase_link'] ?? '') ?: null,
                trim($_POST['supplier'] ?? '') ?: null,
            ]);
            $newId = (int) $db->lastInsertId();
            $newProduct = $newId ? $stock->getProduct($newId) : null;
            $syncMsg = '';
            if ($newProduct) {
                try {
                    $partId = production_sync_create_part_from_product($newProduct);
                    if ($partId) {
                        $syncMsg = ' · sync production #' . $partId;
                    }
                    $iconPath = parts_save_product_image('icon');
                    if ($iconPath) {
                        production_sync_part_icon_by_code((string) $newProduct['code'], $iconPath);
                        $syncMsg .= ' · บันทึกรูปแล้ว';
                    }
                } catch (Throwable $syncEx) {
                    error_log('[products.php add sync] ' . $syncEx->getMessage());
                    $syncMsg = ' · sync production ไม่สำเร็จ';
                }
            }
            flash('success', "เพิ่มอะไหล่เรียบร้อย (รหัส: {$code}){$syncMsg}");
        }
        if ($action === 'upload_icon') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $prod = $productId ? $stock->getProduct($productId) : null;
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่');
                redirect(url('/pages/products.php'));
            }
            $iconPath = parts_save_product_image('icon');
            if (!$iconPath) {
                flash('error', 'ไฟล์รูปไม่ถูกต้อง (รองรับ JPG, PNG, GIF, WEBP ไม่เกิน 5MB)');
                redirect(url('/pages/products.php'));
            }
            try {
                if (!production_part_id_by_product_code((string) $prod['code'])) {
                    production_sync_create_part_from_product($prod);
                }
                production_sync_part_icon_by_code((string) $prod['code'], $iconPath);
            } catch (Throwable $syncEx) {
                error_log('[products.php upload_icon] ' . $syncEx->getMessage());
                flash('error', 'บันทึกรูปไม่สำเร็จ');
                redirect(url('/pages/products.php'));
            }
            flash('success', 'บันทึกรูป — ' . parts_display_name(parts_enrich_product($prod)));
            redirect(url('/pages/products.php'));
        }
        if ($action === 'update_price') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $prod = $productId ? $stock->getProduct($productId) : null;
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่');
                redirect(url('/pages/products.php'));
            }
            $priceRaw = trim((string) ($_POST['price'] ?? ''));
            if ($priceRaw === '' || !is_numeric($priceRaw)) {
                flash('error', 'กรุณากรอกราคาเป็นตัวเลข');
                redirect(url('/pages/products.php'));
            }
            $stock->updateProductPrice($productId, (float) $priceRaw);
            flash('success', 'บันทึกราคา — ' . parts_display_name(parts_enrich_product($prod)));
            redirect(url('/pages/products.php'));
        }
        if ($action === 'update_vendor') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $prod = $productId ? $stock->getProduct($productId) : null;
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่');
                redirect(url('/pages/products.php'));
            }
            $supplier = trim((string) ($_POST['supplier'] ?? ''));
            $purchaseLink = trim((string) ($_POST['purchase_link'] ?? ''));
            if ($purchaseLink !== '' && !filter_var($purchaseLink, FILTER_VALIDATE_URL)) {
                flash('error', 'ลิงก์สั่งซื้อไม่ถูกต้อง');
                redirect(url('/pages/products.php'));
            }
            $stmt = $db->prepare('UPDATE products SET supplier = ?, purchase_link = ? WHERE id = ?');
            $stmt->execute([
                $supplier !== '' ? $supplier : null,
                $purchaseLink !== '' ? $purchaseLink : null,
                $productId,
            ]);
            flash('success', 'บันทึกผู้จำหน่าย/ลิงก์ — ' . parts_display_name(parts_enrich_product($prod)));
            redirect(url('/pages/products.php'));
        }
        if ($action === 'toggle_active') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                flash('error', 'รหัสอะไหล่ไม่ถูกต้อง');
                redirect(url('/pages/products.php'));
            }
            $prod = $stock->getProduct($productId);
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่');
                redirect(url('/pages/products.php'));
            }
            $nextActive = !product_is_active($prod);
            $stock->setProductActive($productId, $nextActive);
            try {
                production_sync_product_active_by_code((string) $prod['code'], $nextActive);
            } catch (Throwable $syncEx) {
                error_log('[products.php toggle sync] ' . $syncEx->getMessage());
            }
            flash('success', product_usage_label($nextActive) . ' — ' . parts_display_name(parts_enrich_product($prod)));
            redirect(url('/pages/products.php'));
        }
        if ($action === 'delete') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $deletedBy = trim($line_name ?? '');
            $force = true;
            if ($productId <= 0 || $deletedBy === '') {
                flash('error', 'ข้อมูลไม่ครบสำหรับการลบ');
                redirect(url('/pages/products.php'));
            }

            $pstmt = $db->prepare('SELECT id, code, name FROM products WHERE id = ?');
            $pstmt->execute([$productId]);
            $prod = $pstmt->fetch();
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่ที่จะลบ');
                redirect(url('/pages/products.php'));
            }

            try {
                $del = $db->prepare('DELETE FROM products WHERE id = ?');
                $del->execute([$productId]);
                $deleted = true;
            } catch (PDOException $e) {
                $deleted = false;
                if ($force) {
                    try {
                        $db->beginTransaction();
                        $db->prepare('DELETE FROM stock_out_items WHERE product_id = ?')->execute([$productId]);
                        $db->prepare('DELETE FROM stock_in WHERE product_id = ?')->execute([$productId]);
                        $db->prepare('DELETE FROM set_items WHERE product_id = ?')->execute([$productId]);
                        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
                        $db->commit();
                        $deleted = true;
                    } catch (Exception $ex) {
                        $db->rollBack();
                        flash('error', 'ไม่สามารถลบอะไหล่ได้: ' . $ex->getMessage());
                        redirect(url('/pages/products.php'));
                    }
                } else {
                    flash('error', 'ไม่สามารถลบอะไหล่ได้เนื่องจากมีข้อมูลอ้างอิง');
                    redirect(url('/pages/products.php'));
                }
            }

            if ($deleted) {
                $logDir = __DIR__ . '/../logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0777, true);
                }
                $logFile = $logDir . '/deletions.log';
                $now = date('Y-m-d H:i:s');
                $line = sprintf(
                    "%s | deleted_by=%s | force=1 | id=%s | code=%s | name=%s\n",
                    $now,
                    $deletedBy,
                    $prod['id'],
                    $prod['code'],
                    $prod['name']
                );
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                flash('success', 'ลบอะไหล่สำเร็จ');
            }
            redirect(url('/pages/products.php'));
        }
        redirect(url('/pages/products.php'));
    } catch (PDOException $e) {
        flash('error', 'ไม่สามารถเพิ่มอะไหล่ได้ (รหัสซ้ำ?)');
        redirect(url('/pages/products.php'));
    }
}

try {
    ensureProductColumns($db);
} catch (Exception $e) {
    // ignore migration errors
}

$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
?>

<div class="page-header products-page-header">
    <div>
        <?= ui_heading('products', 'อะไหล่', 'h1') ?>
        <p>รายการอะไหล่และจำนวนคงเหลือ · <?= number_format(count($products)) ?> รายการ</p>
    </div>
    <div class="parts-page-actions">
        <a href="<?= url('/pages/vendor-import.php') ?>" class="btn btn-outline btn-sm">นำเข้าผู้จำหน่าย</a>
        <?= parts_btn_open_modal('product-add-modal', 'เพิ่มอะไหล่', 'stock-in', 'btn-primary') ?>
    </div>
</div>

<div class="card products-list-card">
    <div class="table-wrap">
        <table class="products-table">
            <thead>
                <tr>
                    <th class="col-img">รูป</th>
                    <th>รหัส</th>
                    <th>ชื่อ</th>
                    <th class="col-status">สถานะ</th>
                    <th>ราคา</th>
                    <th>ลิงก์</th>
                    <th class="text-right">คงเหลือ</th>
                    <th>หน่วย</th>
                    <th class="text-right">ขั้นต่ำ</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p):
                    $isActive = product_is_active($p);
                    $icon = $partIcons[$p['code']] ?? '';
                ?>
                <tr class="<?= $isActive ? '' : 'row-inactive' ?>">
                    <td class="col-img"><?= parts_product_img_cell($icon, $p) ?></td>
                    <td><?= e($p['code']) ?></td>
                    <td><a href="<?= url('/pages/product-detail.php?id=' . (int) $p['id']) ?>" class="detail-link"><?= parts_product_name_html($p) ?></a></td>
                    <td class="col-status">
                        <form method="POST" class="product-active-form">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                            <label class="switch-toggle" title="<?= e(product_usage_label($isActive)) ?>">
                                <input type="checkbox" class="product-active-switch" <?= $isActive ? 'checked' : '' ?>>
                                <span class="switch-slider" aria-hidden="true"></span>
                                <span class="switch-label"><?= e(product_usage_label($isActive)) ?></span>
                            </label>
                        </form>
                    </td>
                    <td class="col-price"><?= parts_product_price_cell($p) ?></td>
                    <td>
                        <?php if (!empty($p['purchase_link'])): ?>
                            <a href="<?= e($p['purchase_link']) ?>" target="_blank" rel="noopener noreferrer" class="detail-link">สั่งซื้อ</a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                        <?php if (!empty($p['supplier'])): ?>
                            <div class="muted" style="font-size:11px;margin-top:2px"><?= e($p['supplier']) ?></div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline btn-cell-action" style="margin-top:4px"
                            data-open-modal="product-vendor-modal"
                            data-fill-modal="product-vendor-modal"
                            data-product-id="<?= (int) $p['id'] ?>"
                            data-product-name="<?= e(parts_display_name($p)) ?>"
                            data-product-supplier="<?= e((string) ($p['supplier'] ?? '')) ?>"
                            data-product-purchase-link="<?= e((string) ($p['purchase_link'] ?? '')) ?>"><?= ui_icon_html('edit', 14, 'btn-svg') ?> ผู้จำหน่าย/ลิงก์</button>
                    </td>
                    <td class="text-right">
                        <span class="<?= $p['quantity'] <= $p['min_stock'] ? 'qty-low' : 'qty-ok' ?>">
                            <?= formatNumber($p['quantity']) ?>
                        </span>
                    </td>
                    <td><?= e($p['unit']) ?></td>
                    <td class="text-right"><?= formatNumber($p['min_stock']) ?></td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <?= actionIcon('view', url('/pages/product-detail.php?id=' . (int) $p['id']), 'ดูรายละเอียด') ?>
                            <form method="POST" onsubmit="return confirm('ยืนยันการลบอะไหล่ <?= e(parts_display_name($p)) ?>?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                <?= actionIcon('delete', '', 'ลบ') ?>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="product-add-modal" class="modal-overlay" hidden>
    <div class="modal-box" role="dialog" aria-labelledby="product-add-title" aria-modal="true">
        <div class="modal-header">
            <h3 id="product-add-title">เพิ่มอะไหล่ใหม่</h3>
            <button type="button" class="modal-close" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="product-add-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>ชื่ออะไหล่</label>
                    <input type="text" name="name" required data-autofocus>
                </div>
                <div class="form-group">
                    <label>รูปอะไหล่</label>
                    <input type="file" name="icon" accept="image/jpeg,image/png,image/gif,image/webp">
                    <p class="form-hint">JPG, PNG, GIF, WEBP · ไม่เกิน 5MB</p>
                </div>
                <div class="form-group">
                    <label>หน่วย</label>
                    <input type="text" name="unit" value="ชิ้น" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>จำนวนเริ่มต้น</label>
                        <input type="number" name="quantity" value="0" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>สต็อกขั้นต่ำ (แจ้งเตือน)</label>
                        <input type="number" name="min_stock" value="0" min="0" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ราคา</label>
                        <input type="number" name="price" value="0.00" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label>ลิงก์สั่งซื้อ (URL)</label>
                        <input type="url" name="purchase_link" placeholder="https://...">
                    </div>
                </div>
                <div class="form-group">
                    <label>ผู้จำหน่าย / บริษัทที่สั่งซื้อ</label>
                    <input type="text" name="supplier" maxlength="200" placeholder="เช่น Shopee, LCSC, ชื่อร้าน">
                </div>
                <p class="muted" style="font-size:12px;margin:0 0 12px">รหัสอะไหล่สร้างอัตโนมัติ · บันทึกแล้ว sync ไป production (parts.stock_code)</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><?= ui_icon_html('plus', 16, 'btn-svg') ?> เพิ่มอะไหล่</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php parts_modal_begin('product-icon-modal', 'บันทึกรูปอะไหล่'); ?>
<form method="POST" enctype="multipart/form-data" id="product-icon-form">
    <input type="hidden" name="action" value="upload_icon">
    <input type="hidden" name="product_id" id="icon-modal-product-id" value="">
    <p class="modal-product-label text-muted" style="margin:0 0 12px">
        <strong id="icon-modal-product-name"></strong>
    </p>
    <div class="form-group">
        <label>เลือกรูป</label>
        <input type="file" name="icon" accept="image/jpeg,image/png,image/gif,image/webp" required data-autofocus>
        <p class="form-hint">JPG, PNG, GIF, WEBP · ไม่เกิน 5MB · sync ไป production.parts.icon_path</p>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('stock-in', 16, 'btn-svg') ?> บันทึกรูป</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php parts_modal_begin('product-price-modal', 'กรอกราคาอะไหล่'); ?>
<form method="POST" id="product-price-form">
    <input type="hidden" name="action" value="update_price">
    <input type="hidden" name="product_id" id="price-modal-product-id" value="">
    <p class="modal-product-label text-muted" style="margin:0 0 12px">
        <strong id="price-modal-product-name"></strong>
    </p>
    <div class="form-group">
        <label>ราคาต่อหน่วย (บาท)</label>
        <input type="number" name="price" id="price-modal-input" min="0" step="0.01" required data-autofocus>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('plus', 16, 'btn-svg') ?> บันทึกราคา</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php parts_modal_begin('product-vendor-modal', 'ผู้จำหน่าย / ลิงก์สั่งซื้อ'); ?>
<form method="POST" id="product-vendor-form">
    <input type="hidden" name="action" value="update_vendor">
    <input type="hidden" name="product_id" id="vendor-modal-product-id" value="">
    <p class="modal-product-label text-muted" style="margin:0 0 12px">
        <strong id="vendor-modal-product-name"></strong>
    </p>
    <div class="form-group">
        <label>ผู้จำหน่าย / บริษัทที่สั่งซื้อ</label>
        <input type="text" name="supplier" id="vendor-modal-supplier" maxlength="200" placeholder="เช่น Shopee, LCSC, ชื่อร้าน" data-autofocus>
    </div>
    <div class="form-group">
        <label>ลิงก์สั่งซื้อ (URL)</label>
        <input type="url" name="purchase_link" id="vendor-modal-purchase-link" placeholder="https://...">
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('save', 16, 'btn-svg') ?> บันทึก</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
