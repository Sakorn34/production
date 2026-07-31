<?php
/**
 * product_edit.php — แก้ไขรายละเอียดอะไหล่ (modal + POST handler)
 *
 * ใช้ในหน้า product-detail.php, products.php และทุกหน้าที่แสดงข้อมูลต่อชิ้น
 *
 * Flow:
 *   parts_product_forms_handle_post() ก่อน header → redirect
 *   parts_product_edit_button() + parts_product_edit_modals() ใน HTML
 */

require_once __DIR__ . '/production_sync.php';

/**
 * ตรวจ URL ย้อนกลับ/redirect ว่าอยู่ในแอป parts
 *
 * @param string|null $url
 * @param string      $fallback
 * @return string
 */
function parts_safe_return_url(?string $url, string $fallback): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return $fallback;
    }
    if (strpos($url, 'javascript:') === 0 || strpos($url, 'data:') === 0) {
        return $fallback;
    }
    if ($url[0] === '/' && strpos($url, BASE_PATH) === 0) {
        return $url;
    }
    return $fallback;
}

/**
 * จัดการ POST แก้ไขอะไหล่ / อัปโหลดรูป — redirect เมื่อสำเร็จ
 *
 * @param PDO          $db
 * @param StockService $stock
 * @param string       $defaultReturn
 * @return bool true ถ้า handle แล้ว (redirect แล้ว)
 */
function parts_product_forms_handle_post(PDO $db, StockService $stock, string $defaultReturn): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }

    $action = $_POST['action'] ?? '';
    $returnTo = parts_safe_return_url($_POST['return_to'] ?? '', $defaultReturn);

    if ($action === 'update_details') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $prod = $productId ? $stock->getProduct($productId) : null;
        if (!$prod) {
            flash('error', 'ไม่พบอะไหล่');
            redirect($returnTo);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $partCode = trim((string) ($_POST['part_code'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $minStock = (int) ($_POST['min_stock'] ?? 0);
        $priceRaw = trim((string) ($_POST['price'] ?? ''));
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $purchaseLink = trim((string) ($_POST['purchase_link'] ?? ''));

        if ($name === '') {
            flash('error', 'กรุณากรอกชื่ออะไหล่');
            redirect($returnTo);
        }
        if ($unit === '') {
            flash('error', 'กรุณากรอกหน่วย');
            redirect($returnTo);
        }
        if ($minStock < 0) {
            flash('error', 'สต็อกขั้นต่ำต้องไม่ติดลบ');
            redirect($returnTo);
        }
        if ($priceRaw !== '' && !is_numeric($priceRaw)) {
            flash('error', 'ราคาต้องเป็นตัวเลข');
            redirect($returnTo);
        }
        if ($purchaseLink !== '' && !filter_var($purchaseLink, FILTER_VALIDATE_URL)) {
            flash('error', 'ลิงก์สั่งซื้อไม่ถูกต้อง');
            redirect($returnTo);
        }
        if (mb_strlen($partCode) > 255) {
            flash('error', 'รหัสอะไหล่ (Production) ยาวเกิน 255 ตัวอักษร');
            redirect($returnTo);
        }

        $price = $priceRaw === '' ? 0.0 : (float) $priceRaw;
        $stock->updateProductDetails($productId, [
            'name'          => $name,
            'unit'          => $unit,
            'min_stock'     => $minStock,
            'price'         => $price,
            'supplier'      => $supplier !== '' ? $supplier : null,
            'purchase_link' => $purchaseLink !== '' ? $purchaseLink : null,
        ]);

        try {
            $stockCode = (string) ($prod['code'] ?? '');
            if ($stockCode !== '') {
                if (!production_part_id_by_product_code($stockCode)) {
                    production_sync_create_part_from_product($stock->getProduct($productId) ?: $prod);
                }
                production_sync_part_code_by_code($stockCode, $partCode);
            }
        } catch (Throwable $syncEx) {
            error_log('[product_edit update_details part_code] ' . $syncEx->getMessage());
        }

        flash('success', 'บันทึกรายละเอียด — ' . parts_display_name(parts_enrich_product($stock->getProduct($productId) ?: $prod)));
        redirect($returnTo);
    }

    if ($action === 'upload_icon') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $prod = $productId ? $stock->getProduct($productId) : null;
        if (!$prod) {
            flash('error', 'ไม่พบอะไหล่');
            redirect($returnTo);
        }
        $iconPath = parts_save_product_image('icon');
        if (!$iconPath) {
            flash('error', 'ไฟล์รูปไม่ถูกต้อง (JPG, PNG, GIF, WEBP ไม่เกิน 5MB)');
            redirect($returnTo);
        }
        try {
            if (!production_part_id_by_product_code((string) $prod['code'])) {
                production_sync_create_part_from_product($prod);
            }
            production_sync_part_icon_by_code((string) $prod['code'], $iconPath);
        } catch (Throwable $syncEx) {
            error_log('[product_edit upload_icon] ' . $syncEx->getMessage());
            flash('error', 'บันทึกรูปไม่สำเร็จ');
            redirect($returnTo);
        }
        flash('success', 'บันทึกรูป — ' . parts_display_name(parts_enrich_product($prod)));
        redirect($returnTo);
    }

    return false;
}

/**
 * data-* attributes สำหรับเปิด modal แก้ไข
 *
 * @param array<string,mixed> $product
 * @param string              $returnTo
 * @return string
 */
function parts_product_edit_data_attrs(array $product, string $returnTo = ''): string
{
    $p = parts_enrich_product($product);
    $price = isset($p['price']) && $p['price'] !== '' && $p['price'] !== null
        ? number_format((float) $p['price'], 2, '.', '')
        : '';

    return ' data-open-modal="product-edit-modal"'
        . ' data-fill-modal="product-edit-modal"'
        . ' data-product-id="' . (int) ($p['id'] ?? 0) . '"'
        . ' data-product-code="' . e((string) ($p['code'] ?? '')) . '"'
        . ' data-product-name="' . e(parts_display_name($p)) . '"'
        . ' data-product-local-name="' . e((string) ($p['name'] ?? '')) . '"'
        . ' data-product-part-code="' . e(parts_product_part_code_raw($p)) . '"'
        . ' data-product-unit="' . e((string) ($p['unit'] ?? 'ชิ้น')) . '"'
        . ' data-product-min-stock="' . (int) ($p['min_stock'] ?? 0) . '"'
        . ' data-product-price="' . e($price) . '"'
        . ' data-product-supplier="' . e((string) ($p['supplier'] ?? '')) . '"'
        . ' data-product-purchase-link="' . e((string) ($p['purchase_link'] ?? '')) . '"'
        . ' data-product-return-to="' . e($returnTo) . '"';
}

/**
 * ปุ่มเปิด modal แก้ไขรายละเอียดอะไหล่
 *
 * @param array<string,mixed> $product
 * @param string              $returnTo
 * @param string              $class
 * @param string              $label
 * @return string HTML
 */
function parts_product_edit_button(array $product, string $returnTo = '', string $class = 'btn btn-sm btn-outline btn-with-icon', string $label = 'แก้ไข'): string
{
    return '<button type="button" class="' . e($class) . '"' . parts_product_edit_data_attrs($product, $returnTo) . '>'
        . ui_icon_html('edit', 14, 'btn-svg') . ' ' . e($label)
        . '</button>';
}

/**
 * Modal แก้ไขรายละเอียด + อัปโหลดรูป (ใส่ท้ายหน้าที่ใช้ปุ่มแก้ไขครั้งเดียว)
 *
 * @return void
 */
function parts_product_edit_modals(): void
{
    parts_modal_begin('product-edit-modal', 'แก้ไขรายละเอียดอะไหล่');
    ?>
    <form method="POST" id="product-edit-form">
        <input type="hidden" name="action" value="update_details">
        <input type="hidden" name="product_id" id="edit-modal-product-id" value="">
        <input type="hidden" name="return_to" id="edit-modal-return-to" value="">
        <p class="modal-product-label text-muted" style="margin:0 0 12px">
            รหัส: <strong id="edit-modal-product-code"></strong>
            · <span id="edit-modal-product-display-name"></span>
        </p>
        <div class="form-group">
            <label for="edit-modal-name">ชื่ออะไหล่ (ในระบบ Stock)</label>
            <input type="text" name="name" id="edit-modal-name" required maxlength="255" data-autofocus>
            <p class="form-hint">ถ้าอะไหล่นี้เชื่อมกับระบบทะเบียนเครื่องแล้ว ชื่อที่แสดงในตารางอาจใช้ชื่อจากฝั่งนั้นแทนชื่อนี้</p>
        </div>
        <div class="form-group">
            <label for="edit-modal-part-code">รหัสอะไหล่ (Production)</label>
            <input type="text" name="part_code" id="edit-modal-part-code" maxlength="255" placeholder="เช่น แผ่น PVC 35x20x5 mm.">
            <p class="form-hint">รหัส/รายละเอียดอะไหล่ในระบบทะเบียนเครื่อง</p>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit-modal-unit">หน่วย</label>
                <input type="text" name="unit" id="edit-modal-unit" required maxlength="50">
            </div>
            <div class="form-group">
                <label for="edit-modal-min-stock">สต็อกขั้นต่ำ</label>
                <input type="number" name="min_stock" id="edit-modal-min-stock" min="0" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit-modal-price">ราคา (บาท)</label>
                <input type="number" name="price" id="edit-modal-price" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
                <label for="edit-modal-supplier">ผู้จำหน่าย</label>
                <input type="text" name="supplier" id="edit-modal-supplier" maxlength="200" placeholder="เช่น Shopee, LCSC">
            </div>
        </div>
        <div class="form-group">
            <label for="edit-modal-purchase-link">ลิงก์สั่งซื้อ (URL)</label>
            <input type="url" name="purchase_link" id="edit-modal-purchase-link" placeholder="https://...">
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
            <button type="submit" class="btn btn-primary"><?= ui_icon_html('save', 16, 'btn-svg') ?> บันทึก</button>
        </div>
    </form>
    <?php
    parts_modal_end();

    parts_modal_begin('product-icon-modal', 'บันทึกรูปอะไหล่');
    ?>
    <form method="POST" enctype="multipart/form-data" id="product-icon-form">
        <input type="hidden" name="action" value="upload_icon">
        <input type="hidden" name="product_id" id="icon-modal-product-id" value="">
        <input type="hidden" name="return_to" id="icon-modal-return-to" value="">
        <p class="modal-product-label text-muted" style="margin:0 0 12px">
            <strong id="icon-modal-product-name"></strong>
        </p>
        <div class="form-group">
            <label>เลือกรูป</label>
            <input type="file" name="icon" accept="image/jpeg,image/png,image/gif,image/webp" required data-autofocus>
            <p class="form-hint">JPG, PNG, GIF, WEBP · ไม่เกิน 5MB · รูปนี้จะถูกซิงก์ไปแสดงในระบบทะเบียนเครื่องด้วย</p>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
            <button type="submit" class="btn btn-primary"><?= ui_icon_html('stock-in', 16, 'btn-svg') ?> บันทึกรูป</button>
        </div>
    </form>
    <?php
    parts_modal_end();
}
