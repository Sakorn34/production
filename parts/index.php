<?php

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$stats = $stock->getDashboardStats();
$products = $stock->getAllProducts();
$lowStock = $stock->getLowStockProducts();
$recent = $stock->getRecentMovements(8);
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <p>ภาพรวมระบบจัดการสต็อก</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="label">อะไหล่ทั้งหมด</div>
        <div class="value"><?= formatNumber($stats['total_products']) ?></div>
    </div>
    <div class="stat-card success">
        <div class="label">จำนวนคงเหลือรวม</div>
        <div class="value"><?= formatNumber($stats['total_quantity']) ?></div>
    </div>
    <div class="stat-card warning">
        <div class="label">อะไหล่ใกล้หมด</div>
        <div class="value"><?= formatNumber($stats['low_stock']) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">ชุดเบิก (Set)</div>
        <div class="value"><?= formatNumber($stats['total_sets']) ?></div>
    </div>
    <div class="stat-card success">
        <div class="label">รับเข้าวันนี้</div>
        <div class="value"><?= formatNumber($stats['today_in']) ?></div>
    </div>
    <div class="stat-card danger">
        <div class="label">เบิกออกวันนี้</div>
        <div class="value"><?= formatNumber($stats['today_out']) ?></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2>📋 อะไหล่คงเหลือ</h2>
        <table>
            <thead>
                <tr>
                    <th>รหัส</th>
                    <th>ชื่ออะไหล่</th>
                    <th class="text-right">คงเหลือ</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e($p['code']) ?></td>
                    <td><a href="<?= url('/pages/product-detail.php?id=' . (int) $p['id']) ?>"><?= e($p['name']) ?></a></td>
                    <td class="text-right">
                        <span class="<?= $p['quantity'] <= $p['min_stock'] ? 'qty-low' : 'qty-ok' ?>">
                            <?= formatNumber($p['quantity']) ?> <?= e($p['unit']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($p['quantity'] <= $p['min_stock']): ?>
                            <span class="badge badge-danger">ใกล้หมด</span>
                        <?php else: ?>
                            <span class="badge badge-success">ปกติ</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <?php if (!empty($lowStock)): ?>
        <div class="card">
            <h2>⚠️ แจ้งเตือนอะไหล่ใกล้หมด</h2>
            <table>
                <thead>
                    <tr>
                        <th>อะไหล่</th>
                        <th class="text-right">คงเหลือ</th>
                        <th class="text-right">ขั้นต่ำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStock as $p): ?>
                    <tr>
                        <td><?= e($p['name']) ?></td>
                        <td class="text-right qty-low"><?= formatNumber($p['quantity']) ?></td>
                        <td class="text-right"><?= formatNumber($p['min_stock']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>  
        <?php endif; ?>

        <div class="card">
            <h2>🔄 ความเคลื่อนไหวล่าสุด</h2>
            <?php if (empty($recent)): ?>
                <p class="empty-state">ยังไม่มีข้อมูล</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th>รายการ</th>
                        <th class="text-right">จำนวน</th>
                        <th>วันที่</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $m): ?>
                    <tr>
                        <td>
                            <?php if ($m['type'] === 'in'): ?>
                                <span class="badge badge-success">รับเข้า</span>
                            <?php else: ?>
                                <span class="badge badge-danger">เบิกออก</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($m['product_name']) ?></td>
                        <td class="text-right"><?= formatNumber($m['quantity']) ?></td>
                        <td class="text-muted"><?= formatDate($m['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; 


?>
