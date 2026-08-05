<?php
/**
 * pages/products.php — รายการอะไหล่ + งานสต็อกประจำวันทั้งหมด (2026-08-05)
 *
 * เดิมการรับเข้า/เบิกออกแยกอยู่คนละหน้า (stock-in.php, stock-out-item.php, stock-out.php)
 * ทั้งที่แต่ละหน้าเป็นแค่ "ปุ่มเปิด modal + ตารางประวัติ" ผู้ใช้ที่เห็นของใกล้หมดในหน้านี้
 * จึงต้องออกไปอีกหน้าแล้วค้นหาอะไหล่ตัวเดิมซ้ำ ตอนนี้ modal ทำงานย้ายมาอยู่ที่นี่หมด
 * ส่วนตารางประวัติไปรวมกันที่ history.php แบบแท็บ
 */

$pageTitle = 'อะไหล่';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/production_sync.php';
require_once __DIR__ . '/../includes/product_edit.php';

$sortState = parts_products_sort_state();
$productsReturnTo = parts_products_page_url($sortState);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (parts_product_forms_handle_post($db, $stock, $productsReturnTo)) {
        exit;
    }
    $action = $_POST['action'] ?? '';

    // งานสต็อกประจำวัน (รับเข้า / เบิกออก) — ย้ายมาจาก stock-in.php, stock-out-item.php, stock-out.php
    //
    // ทุก branch ต้องมีชื่อ action ชัดเจน ห้ามใช้ else ตกท้ายเด็ดขาด: ฟอร์มสวิตช์เปิด/ปิดอะไหล่
    // ถูก auto-submit จาก app.js ทุกครั้งที่คลิก ถ้ามี else ตกท้ายที่เรียกการเบิกออก
    // แค่คลิกสวิตช์ก็จะตัดสต็อกจริงทันที
    //
    // และต้องแยก try ออกจากก้อนล่าง เพราะก้อนล่าง catch เฉพาะ PDOException
    // แต่ StockService โยน Exception ธรรมดาเมื่อของไม่พอ — จะหลุดไปเป็น fatal
    if ($action === 'stock_in' || $action === 'stock_out_item' || $action === 'stock_out_set') {
        try {
            if ($action === 'stock_in') {
                ensureStockInColumns($db);
                $stock->stockIn(
                    (int) $_POST['product_id'],
                    (int) $_POST['quantity'],
                    trim($_POST['note'] ?? '') ?: null,
                    $line_name
                );
                flash('success', 'บันทึกรับเข้าเรียบร้อย');
                parts_log_stock_action('stock-in.php');
            } elseif ($action === 'stock_out_item') {
                $docNo = $stock->stockOutItem(
                    (int) $_POST['product_id'],
                    (int) $_POST['quantity'],
                    validateStockOutNote($_POST['note'] ?? null),
                    trim($line_name ?? '') ?: null,
                    trim($_POST['asset_code'] ?? '') ?: null
                );
                flash('success', "เบิกออกเรียบร้อย เลขที่: {$docNo}");
                parts_log_stock_action('stock-out-item.php');
            } else {
                // เดิมเป็น else ตกท้ายจึงไม่เคยตรวจค่า — ตอนนี้เรียกด้วยชื่อ action จึงต้องกันเอง
                $setId = (int) ($_POST['set_id'] ?? 0);
                $setCount = (int) ($_POST['set_count'] ?? 0);
                if ($setId <= 0 || $setCount <= 0) {
                    flash('error', 'กรุณาเลือก Set และระบุจำนวนชุดที่เบิก');
                    redirect($productsReturnTo);
                }
                $docNo = $stock->stockOutBySet(
                    $setId,
                    $setCount,
                    validateStockOutNote($_POST['note'] ?? null),
                    trim($line_name ?? '') ?: null,
                    trim($_POST['asset_code'] ?? '') ?: null
                );
                flash('success', "เบิกออกเรียบร้อย เลขที่: {$docNo}");
                parts_log_stock_action('stock-out.php');
            }
        } catch (Exception $e) {
            flash('error', safe_exception_message($e));
        }
        redirect($productsReturnTo);
    }

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
        if ($action === 'toggle_active') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                flash('error', 'รหัสอะไหล่ไม่ถูกต้อง');
                redirect($productsReturnTo);
            }
            $prod = $stock->getProduct($productId);
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่');
                redirect($productsReturnTo);
            }
            $nextActive = !product_is_active($prod);
            $stock->setProductActive($productId, $nextActive);
            try {
                production_sync_product_active_by_code((string) $prod['code'], $nextActive);
            } catch (Throwable $syncEx) {
                error_log('[products.php toggle sync] ' . $syncEx->getMessage());
            }
            flash('success', product_usage_label($nextActive) . ' — ' . parts_display_name(parts_enrich_product($prod)));
            redirect($productsReturnTo);
        }
        if ($action === 'delete') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $deletedBy = trim($line_name ?? '');
            $force = true;
            if ($productId <= 0 || $deletedBy === '') {
                flash('error', 'ข้อมูลไม่ครบสำหรับการลบ');
                redirect($productsReturnTo);
            }

            $pstmt = $db->prepare('SELECT id, code, name FROM products WHERE id = ?');
            $pstmt->execute([$productId]);
            $prod = $pstmt->fetch();
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่ที่จะลบ');
                redirect($productsReturnTo);
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
                        flash('error', 'ไม่สามารถลบอะไหล่ได้: ' . safe_exception_message($ex));
                        redirect($productsReturnTo);
                    }
                } else {
                    flash('error', 'ไม่สามารถลบอะไหล่ได้เนื่องจากมีข้อมูลอ้างอิง');
                    redirect($productsReturnTo);
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
            redirect($productsReturnTo);
        }
        redirect($productsReturnTo);
    } catch (PDOException $e) {
        flash('error', 'ไม่สามารถเพิ่มอะไหล่ได้ (รหัสซ้ำ?)');
        redirect($productsReturnTo);
    }
}

try {
    ensureProductColumns($db);
} catch (Exception $e) {
    // ignore migration errors
}

require_once __DIR__ . '/../includes/header.php';

$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
// เก็บลำดับตามชื่อไว้ก่อนเรียงตาราง — ช่องค้นหาใน modal ไม่ควรเรียงตาม sort ของตาราง
$pickerProducts = $products;
$products = parts_sort_products($products, $sortState['sort'], $sortState['dir']);
$sets = $stock->getAllSetsWithItems(); // ต้องใช้ตัวนี้ ไม่ใช่ getAllSets() เพราะต้องการ can_issue
$noteOptions = getStockOutNoteOptions();

// รายการอะไหล่ในแต่ละ Set สำหรับแสดงพรีวิวใน modal เบิกออก
// ใช้ชื่อจาก $products ที่ enrich มาแล้ว (ไม่ query ข้ามฐานข้อมูลซ้ำ) ชื่อจึงตรงกับในตาราง
$productNameById = [];
foreach ($pickerProducts as $p) {
    $productNameById[(int) $p['id']] = parts_display_name($p);
}
$setItemsData = [];
foreach ($sets as $s) {
    $list = [];
    foreach ($s['items'] as $it) {
        $code = trim((string) ($it['code'] ?? ''));
        $list[] = [
            'code'  => $code,
            'name'  => $productNameById[(int) $it['product_id']] ?? (string) ($it['name'] ?? ''),
            'qty'   => (int) $it['quantity'],
            'unit'  => (string) ($it['unit'] ?? 'ชิ้น'),
            'stock' => (int) ($it['stock_qty'] ?? 0),
            'icon'  => parts_upload_img_url($partIcons[$code] ?? '') ?? '',
        ];
    }
    $setItemsData[(int) $s['id']] = $list;
}
?>

<div class="page-header products-page-header">
    <div>
        <?= ui_heading('products', 'อะไหล่', 'h1') ?>
        <p>รายการอะไหล่และจำนวนคงเหลือ · แสดง <span id="products-shown-count"><?= number_format(count($products)) ?></span> จาก <?= number_format(count($products)) ?> รายการ</p>
    </div>
    <div class="parts-page-actions">
        <?= parts_btn_open_modal('product-add-modal', 'เพิ่มอะไหล่', 'plus', 'btn-outline btn-sm') ?>
        <span class="parts-actions-sep" aria-hidden="true"></span>
        <?= parts_btn_open_modal('stock-in-add-modal', 'รับเข้า', 'stock-in', 'btn-success') ?>
        <?= parts_btn_open_modal('stock-out-item-add-modal', 'เบิกรายชิ้น', 'stock-out-item', 'btn-danger') ?>
        <?php if (!empty($sets)): ?>
        <?= parts_btn_open_modal('stock-out-set-modal', 'เบิก Set', 'stock-out-set', 'btn-danger') ?>
        <?php endif; ?>
        <?php // ไม่มีปุ่ม "จัดการ Set" ที่นี่ — มีในเมนูด้านซ้ายอยู่แล้ว ?>
    </div>
</div>

<div class="card products-list-card">
    <?php
    $supplierList = [];
    foreach ($products as $sp) {
        $s = trim((string) ($sp['supplier'] ?? ''));
        if ($s !== '') {
            $supplierList[$s] = ($supplierList[$s] ?? 0) + 1;
        }
    }
    ksort($supplierList, SORT_FLAG_CASE | SORT_NATURAL);
    $noSupplierCount = count($products) - array_sum($supplierList);
    ?>
    <div class="products-search-bar">
        <label for="products-search" class="sr-only">ค้นหาอะไหล่</label>
        <input type="search" id="products-search" class="products-search-input"
               data-table-filter="products-table"
               data-filter-count="products-shown-count"
               data-filter-empty="products-empty-row"
               placeholder="ค้นหาอะไหล่ — ชื่อ / รหัส / ผู้จำหน่าย" autocomplete="off">
        <label for="products-supplier" class="sr-only">กรองตามผู้จำหน่าย</label>
        <select id="products-supplier" class="products-supplier-select" data-table-filter-select="products-table">
            <option value="">ผู้จำหน่ายทั้งหมด</option>
            <?php foreach ($supplierList as $sName => $sCount): ?>
            <option value="<?= e($sName) ?>"><?= e($sName) ?> (<?= number_format($sCount) ?>)</option>
            <?php endforeach; ?>
            <?php if ($noSupplierCount > 0): ?>
            <option value="__none__">ไม่ระบุผู้จำหน่าย (<?= number_format($noSupplierCount) ?>)</option>
            <?php endif; ?>
        </select>
    </div>
    <div class="table-wrap">
        <table class="products-table" id="products-table">
            <thead>
                <tr>
                    <th class="col-img">รูป</th>
                    <?= parts_products_sort_th('รหัส', 'code', $sortState) ?>
                    <?= parts_products_sort_th('ชื่อ', 'name', $sortState) ?>
                    <?= parts_products_sort_th('สถานะ', 'status', $sortState, 'col-status') ?>
                    <?= parts_products_sort_th('ราคา', 'price', $sortState) ?>
                    <?= parts_products_sort_th('ผู้จำหน่าย', 'link', $sortState) ?>
                    <?= parts_products_sort_th('คงเหลือ', 'quantity', $sortState, 'text-right') ?>
                    <?= parts_products_sort_th('หน่วย', 'unit', $sortState) ?>
                    <?= parts_products_sort_th('ขั้นต่ำ', 'min_stock', $sortState, 'text-right') ?>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p):
                    $isActive = product_is_active($p);
                    $icon = $partIcons[$p['code']] ?? '';
                    $searchKey = mb_strtolower(trim(implode(' ', array_filter([
                        (string) ($p['code'] ?? ''),
                        parts_display_name($p),
                        (string) ($p['name'] ?? ''),
                        (string) ($p['display_sub'] ?? ''),
                        (string) ($p['supplier'] ?? ''),
                        (string) ($p['unit'] ?? ''),
                    ]))), 'UTF-8');
                ?>
                <tr class="<?= $isActive ? '' : 'row-inactive' ?>" data-search="<?= e($searchKey) ?>" data-supplier="<?= e(trim((string) ($p["supplier"] ?? ""))) ?>">
                    <td class="col-img"><?= parts_product_img_cell($icon, $p, $productsReturnTo) ?></td>
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
                    <td class="col-price"><?= parts_product_price_cell($p, $productsReturnTo) ?></td>
                    <td>
                        <?php if (!empty($p['supplier'])): ?>
                            <div class="product-supplier-name"><?= e($p['supplier']) ?></div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                        <?php if (!empty($p['purchase_link'])): ?>
                            <a href="<?= e($p['purchase_link']) ?>" target="_blank" rel="noopener noreferrer" class="detail-link product-supplier-link">สั่งซื้อ</a>
                        <?php endif; ?>
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
                            <button type="button" class="btn btn-sm btn-icon btn-icon-edit" title="แก้ไขรายละเอียด"<?= parts_product_edit_data_attrs($p, $productsReturnTo) ?>><?= ui_icon_html('edit', 16, 'btn-svg') ?></button>
                            <form method="POST" onsubmit="return confirm('ยืนยันการลบอะไหล่ <?= e(parts_display_name($p)) ?>?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                <?= actionIcon('delete', '', 'ลบ') ?>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="products-empty-row" hidden>
                    <td colspan="10" class="text-center text-muted" style="padding:20px">ไม่พบอะไหล่ที่ตรงกับคำค้นหา</td>
                </tr>
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
                        <label>ราคา (บาท)</label>
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
                <p class="muted" style="font-size:12px;margin:0 0 12px">รหัสอะไหล่สร้างอัตโนมัติ · บันทึกแล้วจะซิงก์ไปยังระบบทะเบียนเครื่องอัตโนมัติ</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><?= ui_icon_html('plus', 16, 'btn-svg') ?> เพิ่มอะไหล่</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// ── งานสต็อกประจำวัน ────────────────────────────────────────────────────────
// ย้ายมาจาก stock-in.php / stock-out-item.php / stock-out.php เพื่อให้ทำงานได้จบในหน้าเดียว
// ทุกฟอร์มมี action ชัดเจน (ดูเหตุผลที่บล็อก POST ด้านบน)
?>
<?php parts_modal_begin('stock-in-add-modal', 'บันทึกรับเข้า'); ?>
<form method="POST">
    <input type="hidden" name="action" value="stock_in">
    <?= parts_product_picker_html('product_id', $pickerProducts, $partIcons, [
        'id'             => 'stock-in-product',
        'autofocus'      => true,
        'disableZeroQty' => false,
    ]) ?>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนรับเข้า</label>
            <input type="number" name="quantity" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>หมายเหตุ</label>
            <textarea name="note" rows="2" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-success"><?= ui_icon_html('stock-in', 16, 'btn-svg') ?> บันทึกรับเข้า</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php parts_modal_begin('stock-out-item-add-modal', 'บันทึกเบิกรายชิ้น'); ?>
<form method="POST">
    <input type="hidden" name="action" value="stock_out_item">
    <?= parts_product_picker_html('product_id', $pickerProducts, $partIcons, [
        'id' => 'stock-out-item-product',
        'autofocus' => true,
    ]) ?>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนเบิก</label>
            <input type="number" name="quantity" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>หมายเลขเครื่อง (S/N)</label>
            <input type="text" name="asset_code" placeholder="เช่น BP26072024">
        </div>
    </div>
    <p class="form-hint">ระบุ S/N ถ้าเบิกไปใช้กับเครื่อง</p>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <option value="">-- เลือกประเภทการเบิก --</option>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>"><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-danger"><?= ui_icon_html('stock-out-item', 16, 'btn-svg') ?> ยืนยันเบิกออก</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php if (!empty($sets)): ?>
<?php parts_modal_begin('stock-out-set-modal', 'เบิกออก Set'); ?>
<form method="POST" data-set-preview data-set-items="<?= e(json_encode($setItemsData, JSON_UNESCAPED_UNICODE)) ?>">
    <input type="hidden" name="action" value="stock_out_set">
    <div class="form-group">
        <label>เลือก Set</label>
        <select name="set_id" required data-autofocus>
            <option value="">-- เลือก Set --</option>
            <?php foreach ($sets as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= !$s['can_issue'] ? 'disabled' : '' ?>>
                [<?= e($s['code']) ?>] <?= e($s['name']) ?>
                <?= !$s['can_issue'] ? '(สต็อกไม่พอ)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="set-preview" data-set-preview-box hidden>
        <p class="set-preview-head">อะไหล่ที่จะถูกตัดออก <span data-set-preview-count></span></p>
        <ul class="set-preview-list" data-set-preview-list></ul>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนชุดที่เบิก</label>
            <input type="number" name="set_count" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>หมายเลขเครื่อง (S/N)</label>
            <input type="text" name="asset_code" placeholder="เช่น BP26072024">
        </div>
    </div>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <option value="">-- เลือกประเภทการเบิก --</option>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>"><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-danger"><?= ui_icon_html('stock-out-set', 16, 'btn-svg') ?> ยืนยันเบิกออก</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>

<?php parts_product_edit_modals(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
