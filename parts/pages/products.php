<?php
 
$pageTitle = 'อะไหล่';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $code = generateProductCode($db);
            $stmt = $db->prepare(
                'INSERT INTO products (code, name, unit, quantity, min_stock, price, purchase_link) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $code,
                trim($_POST['name']),
                trim($_POST['unit']),
                (int) $_POST['quantity'],
                (int) $_POST['min_stock'],
                ($_POST['price'] !== '' ? (float) $_POST['price'] : 0.00),
                trim($_POST['purchase_link'] ?? '') ?: null,
            ]);
            flash('success', "เพิ่มอะไหล่เรียบร้อย (รหัส: {$code})");
        }
        if ($action === 'delete') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            // deleted_by is now taken from session user name ($line_name)
            $deletedBy = trim($line_name ?? '');
            $force = true;
            if ($productId <= 0 || $deletedBy === '') {
                flash('error', 'ข้อมูลไม่ครบสำหรับการลบ');
                redirect(url('/pages/products.php'));
            }

            // fetch product for log
            $pstmt = $db->prepare('SELECT id, code, name FROM products WHERE id = ?');
            $pstmt->execute([$productId]);
            $prod = $pstmt->fetch();
            if (!$prod) {
                flash('error', 'ไม่พบอะไหล่ที่จะลบ');
                redirect(url('/pages/products.php'));
            }

            try {
                // try normal delete first
                $del = $db->prepare('DELETE FROM products WHERE id = ?');
                $del->execute([$productId]);
                $deleted = true;
            } catch (PDOException $e) {
                // constraint violation -> try force if requested
                $deleted = false;
                if ($force) {
                    try {
                        $db->beginTransaction();
                        // remove dependent rows referencing this product
                        $db->prepare('DELETE FROM stock_out_items WHERE product_id = ?')->execute([$productId]);
                        $db->prepare('DELETE FROM stock_in WHERE product_id = ?')->execute([$productId]);
                        $db->prepare('DELETE FROM set_items WHERE product_id = ?')->execute([$productId]);
                        // now delete product
                        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
                        $db->commit();
                        $deleted = true;
                    } catch (Exception $ex) {
                        $db->rollBack();
                        flash('error', 'ไม่สามารถลบอะไหล่ได้: ' . $ex->getMessage());
                        redirect(url('/pages/products.php'));
                    }
                } else {
                    flash('error', 'ไม่สามารถลบอะไหล่ได้เนื่องจากมีข้อมูลอ้างอิง (ลองเลือก "ลบข้อมูลที่เกี่ยวข้อง" เพื่อบังคับลบ)');
                    redirect(url('/pages/products.php'));
                }
            }

            if ($deleted) {
                // ensure log dir
                $logDir = __DIR__ . '/../logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0777, true);
                }
                $logFile = $logDir . '/deletions.log';
                $now = date('Y-m-d H:i:s');
                $line = sprintf("%s | deleted_by=%s | force=%s | id=%s | code=%s | name=%s\n",
                    $now, $deletedBy, $force ? '1' : '0', $prod['id'], $prod['code'], $prod['name']
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

// ensure database has purchase_link and price columns (simple migration)
try {
    ensureProductColumns($db);
} catch (Exception $e) {
    // ignore migration errors
}

$products = $stock->getAllProducts();
?>

<div class="page-header">
    <h1>📋 อะไหล่</h1>
    <p>รายการอะไหล่และจำนวนคงเหลือ</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2>เพิ่มอะไหล่ใหม่</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <!-- รหัสอะไหล่สร้างอัตโนมัติ -->
            <div class="form-group">
                <label>ชื่ออะไหล่</label>
                <input type="text" name="name" required>
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
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">เพิ่มอะไหล่</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>รายการอะไหล่ทั้งหมด</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>รหัส</th>
                    <th>ชื่อ</th>
                    <th>ราคา</th>
                    <th>ลิงก์</th>
                    <th class="text-right">คงเหลือ</th>
                    <th>หน่วย</th>
                    <th class="text-right">ขั้นต่ำ</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e($p['code']) ?></td>
                    <td><a href="<?= url('/pages/product-detail.php?id=' . (int) $p['id']) ?>" class="detail-link"><?= e($p['name']) ?></a></td>
                    <td><?= formatCurrency($p['price'] ?? null) ?></td>
                    <td>
                        <?php if (!empty($p['purchase_link'])): ?>
                            <a href="<?= e($p['purchase_link']) ?>" target="_blank" class="detail-link">สั่งซื้อ</a>
                        <?php else: ?>
                            -
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
                            <form method="POST" onsubmit="return confirm('ยืนยันการลบอะไหล่ <?= e($p['name']) ?>?');">
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
