<?php
 
$pageTitle = 'รายละเอียดอะไหล่';
require_once __DIR__ . '/../includes/header.php';

$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$product = $productId ? $stock->getProduct($productId) : null;
$history = $product ? $stock->getProductStockOutHistory($productId, 50) : [];
?>

<div class="page-header">
    <h1>🧩 รายละเอียดอะไหล่</h1>
    <p>ข้อมูลสินค้า ราคา ลิงก์สั่งซื้อ และประวัติการเบิกของชิ้นนี้</p>
</div>

<div class="card">
    <?php if (!$product): ?>
        <p class="text-muted">ไม่พบอะไหล่ หรือไม่มีรหัสสินค้าใน URL</p>
        <p><a href="<?= url('/pages/products.php') ?>" class="btn btn-outline">← กลับไปหน้าอะไหล่</a></p>
    <?php else: ?>
        <div class="grid-2">
            <div>
                <h2>ข้อมูลพื้นฐาน</h2>
                <table>
                    <tbody>
                        <tr>
                            <th>รหัส</th>
                            <td><?= e($product['code']) ?></td>
                        </tr>
                        <tr>
                            <th>ชื่ออะไหล่</th>
                            <td><?= e($product['name']) ?></td>
                        </tr>
                        <tr>
                            <th>จำนวนคงเหลือ</th>
                            <td><?= formatNumber($product['quantity']) ?> <?= e($product['unit']) ?></td>
                        </tr>
                        <tr>
                            <th>สต็อกขั้นต่ำ</th>
                            <td><?= formatNumber($product['min_stock']) ?> <?= e($product['unit']) ?></td>
                        </tr>
                        <tr>
                            <th>ราคา</th>
                            <td><?= formatCurrency($product['price'] ?? null) ?></td>
                        </tr>
                        <tr>
                            <th>ลิงก์สั่งซื้อ</th>
                            <td>
                                <?php if (!empty($product['purchase_link'])): ?>
                                    <a href="<?= e($product['purchase_link']) ?>" target="_blank">สั่งซื้อทันที</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <h2>สรุป</h2>
                <p><strong>ชื่อ:</strong> <?= e($product['name']) ?></p>
                <p><strong>หน่วย:</strong> <?= e($product['unit']) ?></p>
                <p><strong>สถานะสต็อก:</strong>
                    <?php if ($product['quantity'] <= $product['min_stock']): ?>
                        <span class="badge badge-danger">ใกล้หมด</span>
                    <?php else: ?>
                        <span class="badge badge-success">ปกติ</span>
                    <?php endif; ?>
                </p>
                <p><strong>อัพเดตล่าสุด:</strong> <?= formatDate($product['updated_at'] ?? $product['created_at']) ?></p>
            </div>
        </div>

        <div style="margin-top: 1.5rem;">
            <h2>ประวัติการเบิกของอะไหล่ชิ้นนี้</h2>
            <?php if (empty($history)): ?>
                <p class="text-muted">ยังไม่มีการเบิกออกสำหรับอะไหล่ชิ้นนี้</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>ประเภท</th>
                            <th class="text-right">จำนวน</th>
                            <th>ผู้เบิก</th>
                            <th>หมายเหตุ</th>
                            <th>วันที่</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                        <tr>
                            <td><a href="<?= url('/pages/history.php?id=' . (int) $row['id']) ?>"><?= e($row['doc_no']) ?></a></td>
                            <td>
                                <?php if ($row['set_id']): ?>
                                    <span class="badge badge-info">ชุดเบิก</span>
                                    [<?= e($row['set_code']) ?>] <?= e($row['set_name']) ?>
                                <?php else: ?>
                                    <span class="badge badge-secondary">เบิกรายชิ้น</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">-<?= formatNumber($row['quantity']) ?></td>
                            <td><?= e($row['issued_by'] ?: '-') ?></td>
                            <td class="text-muted"><?= e($row['note'] ?: '-') ?></td>
                            <td class="text-muted"><?= formatDate($row['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <p style="margin-top: 1rem;"><a href="<?= url('/pages/products.php') ?>" class="btn btn-outline">← กลับไปหน้าอะไหล่</a></p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
