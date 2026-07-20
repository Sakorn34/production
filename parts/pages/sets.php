<?php
 
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
        flash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
    redirect(url('/pages/sets.php'));
    
}

$sets = $stock->getAllSetsWithItems();
$products = $stock->getAllProducts();
?>

<div class="page-header">
    <?= ui_heading('sets', 'จัดการ Set', 'h1') ?>
    <p>สร้างและจัดการชุดเบิกอะไหล่</p>
</div>

<div class="grid-2">
    <div>
        <div class="card">
            <h2>สร้าง Set ใหม่</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_set">
                <!-- รหัส Set สร้างอัตโนมัติ -->
                <div class="form-group">
                    <label>ชื่อ Set</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>รายละเอียด</label>
                    <textarea name="description" rows="2"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">สร้าง Set</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>เพิ่มอะไหล่ใน Set</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_item">
                <div class="form-group">
                    <label>เลือก Set</label>
                    <select name="set_id" required>
                        <option value="">-- เลือก Set --</option>
                        <?php foreach ($sets as $s): ?>
                        <option value="<?= $s['id'] ?>">[<?= e($s['code']) ?>] <?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>เลือกอะไหล่</label>
                        <select name="product_id" required>
                            <option value="">-- เลือกอะไหล่ --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"> <?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>จำนวนต่อ 1 Set</label>
                        <input type="number" name="quantity" min="1" value="1" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">เพิ่มใน Set</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Set ทั้งหมด</h2>
        <?php foreach ($sets as $s): ?>
        <div class="set-card">
            <strong>[<?= e($s['code']) ?>] <?= e($s['name']) ?></strong>
            <?php if ($s['description']): ?>
                <p class="text-muted" style="margin: 0.25rem 0;"><?= e($s['description']) ?></p>
            <?php endif; ?>
            <?php if (empty($s['items'])): ?>
                <p class="text-muted">ยังไม่มีอะไหล่ใน Set</p>
            <?php else: ?>
            <table style="margin-top: 0.75rem;">
                <thead>
                    <tr>
                        <th>อะไหล่</th>
                        <th class="text-right">จำนวน/Set</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $fullSet = $stock->getSetWithItems((int) $s['id']);
                    foreach ($fullSet['items'] as $item):
                    ?>
                    <tr>
                        <td><?= e($item['name']) ?></td>
                        <td class="text-right"><?= formatNumber($item['quantity']) ?> <?= e($item['unit']) ?></td>
                        <td class="col-actions">
                            <form method="POST" onsubmit="return confirm('ลบรายการนี้ออกจาก Set?')">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                <?= actionIcon('delete', '', 'ลบ') ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
