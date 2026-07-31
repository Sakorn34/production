<?php
/**
 * pages/sets.php — จัดการ Set (modal) + รายการเต็มจอพร้อมรูปอะไหล่
 */

$pageTitle = 'จัดการ Set';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_set') {
            $code = generateSetCode($db);
            $stmt = $db->prepare('INSERT INTO sets (code, name, description) VALUES (?, ?, ?)');
            $stmt->execute([
                $code,
                trim($_POST['name']),
                trim($_POST['description'] ?? '') ?: null,
            ]);
            flash('success', "เพิ่ม Set เรียบร้อย (รหัส: {$code})");
        } elseif ($action === 'add_item') {
            $stmt = $db->prepare(
                'INSERT INTO set_items (set_id, product_id, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            );
            $stmt->execute([
                (int) $_POST['set_id'],
                (int) $_POST['product_id'],
                (int) $_POST['quantity'],
            ]);
            flash('success', 'เพิ่มอะไหล่ใน Set เรียบร้อย');
        } elseif ($action === 'remove_item') {
            $stmt = $db->prepare('DELETE FROM set_items WHERE id = ?');
            $stmt->execute([(int) $_POST['item_id']]);
            flash('success', 'ลบรายการออกจาก Set แล้ว');
        }
    } catch (PDOException $e) {
        flash('error', safe_exception_message($e));
    }
    redirect(url('/pages/sets.php'));
}

$sets = $stock->getAllSetsWithItems();
$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
$partProdLabels = production_part_labels_by_stock_codes(array_column($products, 'code'));

$actions = parts_btn_open_modal('set-add-modal', 'สร้าง Set', 'plus', 'btn-primary')
    . parts_btn_open_modal('set-item-add-modal', 'เพิ่มอะไหล่ใน Set', 'stock-in', 'btn-outline');
parts_page_header('sets', 'จัดการ Set', 'สร้างและจัดการชุดเบิกอะไหล่ · ' . number_format(count($sets)) . ' Set', $actions);
?>

<div class="card parts-list-card">
    <?php if (empty($sets)): ?>
        <p class="empty-state">ยังไม่มี Set — กดปุ่ม <strong>สร้าง Set</strong> เพื่อเริ่มต้น</p>
    <?php else: ?>
        <?php foreach ($sets as $s): ?>
        <div class="set-card-full">
            <div class="set-card-head">
                <div>
                    <strong>[<?= e($s['code']) ?>] <?= e($s['name']) ?></strong>
                    <?php if ($s['description']): ?>
                        <p class="text-muted" style="margin:0.25rem 0 0"><?= e($s['description']) ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($s['can_issue']): ?>
                    <span class="badge badge-success">พร้อมเบิก</span>
                <?php else: ?>
                    <span class="badge badge-danger">สต็อกไม่พอ</span>
                <?php endif; ?>
            </div>
            <?php if (empty($s['items'])): ?>
                <p class="text-muted">ยังไม่มีอะไหล่ใน Set</p>
            <?php else: ?>
            <div class="set-items-panel">
                <div class="table-wrap">
                    <table class="parts-table">
                        <thead>
                            <tr>
                                <th class="col-img">รูป</th>
                                <th>อะไหล่</th>
                                <th class="text-right">จำนวน/Set</th>
                                <th class="text-right">คงเหลือ</th>
                                <th class="col-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $fullSet = $stock->getSetWithItems((int) $s['id']);
                            foreach ($fullSet['items'] as $item):
                                $icon = $partIcons[$item['code'] ?? ''] ?? '';
                            ?>
                            <tr>
                                <td class="col-img"><?= parts_img_tag($icon, parts_label_for_code((string) ($item['code'] ?? ''), (string) ($item['name'] ?? ''), $partProdLabels)) ?></td>
                                <td><?= e(parts_format_product_line((string) ($item['code'] ?? ''), (string) ($item['name'] ?? ''), $partProdLabels)) ?></td>
                                <td class="text-right"><?= formatNumber($item['quantity']) ?> <?= e($item['unit']) ?></td>
                                <td class="text-right"><?= formatNumber($item['stock_qty'] ?? 0) ?></td>
                                <td class="col-actions">
                                    <form method="POST" onsubmit="return confirm('ลบรายการนี้ออกจาก Set?')">
                                        <input type="hidden" name="action" value="remove_item">
                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                        <?= actionIcon('delete', '', 'ลบ') ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php parts_modal_begin('set-add-modal', 'สร้าง Set ใหม่'); ?>
<form method="POST">
    <input type="hidden" name="action" value="add_set">
    <div class="form-group">
        <label>ชื่อ Set</label>
        <input type="text" name="name" required data-autofocus>
    </div>
    <div class="form-group">
        <label>รายละเอียด</label>
        <textarea name="description" rows="2"></textarea>
    </div>
    <p class="muted" style="font-size:12px;margin:0 0 12px">รหัส Set สร้างอัตโนมัติ</p>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('plus', 16, 'btn-svg') ?> สร้าง Set</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php parts_modal_begin('set-item-add-modal', 'เพิ่มอะไหล่ใน Set'); ?>
<form method="POST">
    <input type="hidden" name="action" value="add_item">
    <div class="form-group">
        <label>เลือก Set</label>
        <select name="set_id" required data-autofocus>
            <option value="">-- เลือก Set --</option>
            <?php foreach ($sets as $s): ?>
            <option value="<?= (int) $s['id'] ?>">[<?= e($s['code']) ?>] <?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>ค้นหาอะไหล่</label>
        <input type="text" data-product-search="set-item-product" placeholder="พิมพ์ชื่อหรือรหัสอะไหล่" autocomplete="off">
    </div>
    <div class="form-group">
        <label>เลือกอะไหล่</label>
        <select name="product_id" id="set-item-product" required>
            <option value="">-- เลือกอะไหล่ --</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e(parts_format_product_option($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>จำนวนต่อ 1 Set</label>
        <input type="number" name="quantity" min="1" value="1" required>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('stock-in', 16, 'btn-svg') ?> เพิ่มใน Set</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
