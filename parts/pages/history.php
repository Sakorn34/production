<?php

$pageTitle = 'ประวัติเบิก';
require_once __DIR__ . '/../includes/header.php';

$detailId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$detail = $detailId ? $stock->getStockOutDetail($detailId) : null;
$history = $stock->getStockOutHistory();
?>

<div class="page-header">
    <h1>📜 ประวัติการเบิก</h1>
    <p>รายการเบิกออกทั้งหมด</p>
</div>

<?php if ($detail): ?>
<div class="card">
    <h2>รายละเอียด <?= e($detail['doc_no']) ?></h2>
    <p><strong>ประเภท:</strong>
        <?php if ($detail['set_id']): ?>
            ชุดเบิก — [<?= e($detail['set_code']) ?>] <?= e($detail['set_name']) ?>
        <?php else: ?>
            <span class="badge badge-info">เบิกรายชิ้น</span>
        <?php endif; ?>  
    </p>
    <p><strong>ผู้เบิก:</strong> <?= e($detail['issued_by'] ?: '-') ?></p>
    <p><strong>หมายเหตุ:</strong> <?= e($detail['note'] ?: '-') ?></p>
    <p><strong>วันที่:</strong> <?= formatDate($detail['created_at']) ?></p>

    <table style="margin-top: 1rem;">
        <thead>
                <tr>
                <th>รหัส</th>
                <th>อะไหล่</th>
                <th class="text-right">จำนวนเบิก</th>
                <th>หน่วย</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detail['items'] as $item): ?>
            <tr>
                <td><?= e($item['code']) ?></td>
                <td><?= e($item['name']) ?></td>
                <td class="text-right text-danger">-<?= formatNumber($item['quantity']) ?></td>
                <td><?= e($item['unit']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top: 1rem;"><a href="<?= url('/pages/history.php') ?>" class="btn btn-outline">← กลับ</a></p>
</div>
<?php else: ?>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>เลขที่</th>
                <th>รายการเบิก</th>
                <th class="text-right">จำนวนรวม</th>
                <th>ผู้เบิก</th>
                <th>หมายเหตุ</th>
                <th>วันที่</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($history)): ?>
            <tr><td colspan="7" class="text-center text-muted">ยังไม่มีประวัติการเบิก</td></tr>
            <?php else: ?>
            <?php foreach ($history as $h): ?>
            <tr>
                <td><strong><?= e($h['doc_no']) ?></strong></td>
                <td>
                    <?php if ($h['set_id']): ?>
                        [<?= e($h['set_code']) ?>] <?= e($h['set_name']) ?>
                    <?php else: ?>
                        <span class="badge badge-info">รายชิ้น</span>
                        [<?= e($h['single_product_code']) ?>] <?= e($h['single_product_name']) ?>
                    <?php endif; ?>
                </td>
                <td class="text-right"><?= formatNumber($h['total_qty']) ?></td>
                <td><?= e($h['issued_by'] ?: '-') ?></td>
                <td class="text-muted"><?= e($h['note'] ?: '-') ?></td>
                <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                <td><a href="<?= url('/pages/history.php?id=' . $h['id']) ?>" class="detail-link">ดูรายละเอียด</a></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
