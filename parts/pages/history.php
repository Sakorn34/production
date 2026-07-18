<?php

$pageTitle = 'ประวัติเบิก';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sn_basket') {
    ob_start();
    session_start();
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/helpers.php';
    parts_localhost_bootstrap_session();
    if (!isset($_SESSION['profile'])) {
        http_response_code(403);
        exit('Unauthorized');
    }
    require_once __DIR__ . '/../includes/StockService.php';
    require_once __DIR__ . '/../includes/production_sync.php';
    $db = getDB();
    ensure_stock_production_sync_schema($db);
    $stock = new StockService($db);
    ob_end_clean();

    header('Content-Type: text/html; charset=utf-8');
    $sn = trim($_GET['sn'] ?? '');
    if ($sn === '') {
        echo '<p class="text-muted">ไม่ระบุ S/N</p>';
        exit;
    }
    $basket = $stock->getBasketDetailsByAssetCode($sn);
    if (empty($basket)) {
        echo '<p class="text-muted">ไม่พบรายการเบิกสำหรับ S/N นี้</p>';
        exit;
    }
    ?>
    <p class="text-muted" style="margin-bottom:0.75rem">S/N: <strong><?= e($sn) ?></strong> — <?= count($basket) ?> ใบเบิก</p>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>เลขที่</th>
                <th>อะไหล่</th>
                <th class="text-right">จำนวน</th>
                <th>ผู้เบิก</th>
                <th>วันที่</th>
                <th class="col-actions">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($basket as $doc): ?>
                <?php foreach ($doc['items'] as $idx => $item): ?>
                <tr>
                    <?php if ($idx === 0): ?>
                    <td rowspan="<?= count($doc['items']) ?>"><strong><?= e($doc['doc_no']) ?></strong>
                        <?php if ($doc['set_id']): ?>
                        <br><span class="badge badge-info">Set</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php if ($doc['set_id'] && $idx === 0): ?>
                        <span class="text-muted">[<?= e($doc['set_code']) ?>] </span>
                        <?php endif; ?>
                        [<?= e($item['code']) ?>] <?= e($item['name']) ?>
                    </td>
                    <td class="text-right text-danger">-<?= formatNumber($item['quantity']) ?> <?= e($item['unit']) ?></td>
                    <?php if ($idx === 0): ?>
                    <td rowspan="<?= count($doc['items']) ?>"><?= e($doc['issued_by'] ?: '-') ?></td>
                    <td rowspan="<?= count($doc['items']) ?>" class="text-muted"><?= formatDate($doc['created_at']) ?></td>
                    <td rowspan="<?= count($doc['items']) ?>" class="col-actions">
                        <div class="table-actions">
                            <?= actionIcon('view', url('/pages/history.php?id=' . (int) $doc['id']), 'ดูรายละเอียด') ?>
                            <?php if (empty($doc['set_id'])): ?>
                            <?= actionIcon('edit', url('/pages/stock-out-item.php?edit_out=' . (int) $doc['id']), 'แก้ไข') ?>
                            <?php else: ?>
                            <?= actionIcon('edit', url('/pages/stock-out.php?edit_out=' . (int) $doc['id']), 'แก้ไข') ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
    exit;
}

require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_stock_out'])) {
    try {
        $stock->deleteStockOut((int) $_POST['id']);
        flash('success', 'ลบรายการเบิกแล้ว');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/history.php'));
}

$detailId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$detail = $detailId ? $stock->getStockOutDetail($detailId) : null;
$grouped = $stock->getStockOutHistoryGrouped();

/**
 * สรุปชื่อรายการเบิกสำหรับแถวกลุ่ม S/N
 *
 * @param array<int,array<string,mixed>> $items
 * @return string
 */
function history_group_label(array $items): string
{
    $count = count($items);
    if ($count === 1) {
        $h = $items[0];
        if ($h['set_id']) {
            return '[Set] [' . e($h['set_code']) . '] ' . e($h['set_name']);
        }
        return '<span class="badge badge-info">รายชิ้น</span> [' . e($h['single_product_code']) . '] ' . e($h['single_product_name']);
    }
    return '<strong>' . $count . ' ใบเบิก</strong> <span class="text-muted">(S/N เดียวกัน)</span>';
}
?>

<div class="page-header">
    <h1>📜 ประวัติการเบิก</h1>
    <p>รายการเบิกออกทั้งหมด — จัดกลุ่มตาม S/N เครื่อง</p>
</div>

<?php if ($detail): ?>
<div class="card">
    <h2>รายละเอียด <?= e($detail['doc_no']) ?></h2>
    <dl class="detail-meta">
        <div>
            <dt>ประเภท</dt>
            <dd>
                <?php if ($detail['set_id']): ?>
                    ชุดเบิก — [<?= e($detail['set_code']) ?>] <?= e($detail['set_name']) ?>
                <?php else: ?>
                    <span class="badge badge-info">เบิกรายชิ้น</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>S/N สินค้า</dt>
            <dd><?= e($detail['asset_code'] ?: '-') ?></dd>
        </div>
        <div>
            <dt>ผู้เบิก</dt>
            <dd><?= e($detail['issued_by'] ?: '-') ?></dd>
        </div>
        <div>
            <dt>หมายเหตุ</dt>
            <dd><?= e($detail['note'] ?: '-') ?></dd>
        </div>
        <div>
            <dt>วันที่</dt>
            <dd><?= formatDate($detail['created_at']) ?></dd>
        </div>
        <?php if (!empty($detail['part_movement_id'])): ?>
        <div>
            <dt>Production</dt>
            <dd><span class="sync-badge">🔗 movement #<?= (int) $detail['part_movement_id'] ?></span></dd>
        </div>
        <?php endif; ?>
    </dl>
    <div class="table-wrap">
    <table>
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
    </div>
    <div class="form-actions">
        <a href="<?= url('/pages/history.php') ?>" class="btn btn-outline">← กลับ</a>
        <?php if (empty($detail['set_id'])): ?>
        <?= actionIcon('edit', url('/pages/stock-out-item.php?edit_out=' . (int) $detail['id']), 'แก้ไข', false) ?>
        <?php else: ?>
        <?= actionIcon('edit', url('/pages/stock-out.php?edit_out=' . (int) $detail['id']), 'แก้ไข', false) ?>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('ลบรายการเบิกนี้?')">
            <input type="hidden" name="delete_stock_out" value="1">
            <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
            <?= actionIcon('delete', '', 'ลบ', false) ?>
        </form>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>เลขที่</th>
                <th>รายการเบิก</th>
                <th>S/N</th>
                <th class="text-right">จำนวนรวม</th>
                <th>ผู้เบิก</th>
                <th>หมายเหตุ</th>
                <th>วันที่</th>
                <th class="col-actions">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($grouped)): ?>
            <tr><td colspan="8" class="text-center text-muted">ยังไม่มีประวัติการเบิก</td></tr>
            <?php else: ?>
            <?php foreach ($grouped as $g): ?>
            <?php $h = $g['latest']; ?>
            <tr>
                <td>
                    <?php if ($g['type'] === 'sn' && $g['count'] > 1): ?>
                        <span class="text-muted"><?= $g['count'] ?> ใบ</span><br>
                        <span style="font-size:0.82rem"><?= e($h['doc_no']) ?> …</span>
                    <?php else: ?>
                        <strong><?= e($h['doc_no']) ?></strong>
                    <?php endif; ?>
                </td>
                <td>
                    <span><?= history_group_label($g['items']) ?></span>
                </td>
                <td><?= e($g['sn'] ?: '-') ?></td>
                <td class="text-right"><?= formatNumber($g['total_qty']) ?></td>
                <td><?= e($h['issued_by'] ?: '-') ?></td>
                <td class="text-muted"><?= e($h['note'] ?: '-') ?></td>
                <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                <td class="col-actions">
                    <?php if ($g['type'] === 'single'): ?>
                    <div class="table-actions">
                        <?= actionIcon('view', url('/pages/history.php?id=' . $h['id']), 'ดูรายละเอียด') ?>
                        <?php if (empty($h['set_id'])): ?>
                        <?= actionIcon('edit', url('/pages/stock-out-item.php?edit_out=' . (int) $h['id']), 'แก้ไข') ?>
                        <?php else: ?>
                        <?= actionIcon('edit', url('/pages/stock-out.php?edit_out=' . (int) $h['id']), 'แก้ไข') ?>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('ลบรายการเบิกนี้?')">
                            <input type="hidden" name="delete_stock_out" value="1">
                            <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                            <?= actionIcon('delete', '', 'ลบ') ?>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="table-actions">
                        <?= actionIcon('basket', $g['sn'], 'ดูรายละเอียดทั้งหมด', false) ?>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div id="sn-basket-modal" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h3>🧺 อะไหล่ที่เบิกตาม S/N</h3>
            <button type="button" class="modal-close" aria-label="ปิด">&times;</button>
        </div>
        <div id="sn-basket-body" class="modal-body"></div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
