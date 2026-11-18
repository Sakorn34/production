<?php
/**
 * pages/history.php — ประวัติสต็อกทั้งหมด (2026-08-05)
 *
 * เดิมแยกเป็น 4 หน้า (history.php, stock-in.php, stock-out-item.php, stock-out.php)
 * ยุบมารวมที่นี่ ส่วนฟอร์มบันทึกย้ายไปเป็น modal ที่ products.php
 *
 * โครงหน้า (2026-08-09) — 2 แท็บ:
 *   ?tab=move[&kind=all|in|item|set]  รายการเคลื่อนไหว รับเข้า+เบิกออกในตารางเดียว
 *                                     kind เป็นปุ่มกรอง ไม่ใช่แท็บ เพราะทั้ง 4 ใช้ตารางหน้าตาเดียวกัน
 *   ?tab=sn                           1 แถวต่อ S/N (คนละ query คนละตาราง จึงยังเป็นแท็บ)
 *
 * ลิงก์เก่า ?tab=all|in|item|set แปลงเป็น tab=move&kind=… ให้อัตโนมัติ (bookmark ยังใช้ได้)
 * แต่ละแท็บโหลด query ของตัวเองเท่านั้น
 */

$pageTitle = 'ประวัติ';

/**
 * Popup basket แบบสองคอลัมน์ (D2) — ใบงานซ้าย · รายการอะไหล่ขวา
 *
 * @param array<int,array<string,mixed>> $basket
 * @param array<string,string> $partProdLabels
 * @param array<string,string> $partIcons
 * @param string $backQuery query ต่อท้ายลิงก์ดู/แก้ไข เช่น &tab=sn หรือ &tab=move&kind=set
 * @return string HTML
 */
function history_withdraw_basket_cards_html(array $basket, array $partProdLabels, array $partIcons, string $backQuery): string
{
    ob_start();
    ?>
    <div class="withdraw-basket-stack">
        <?php foreach ($basket as $doc):
            $items = $doc['items'] ?? [];
            $itemCount = count($items);
            $totalQty = 0;
            foreach ($items as $it) {
                $totalQty += (int) ($it['quantity'] ?? 0);
            }
            $isSet = !empty($doc['set_id']);
            $assetCode = trim((string) ($doc['asset_code'] ?? ''));
            $editUrl = $isSet
                ? url('/pages/history.php?tab=set&edit_out=' . (int) $doc['id'])
                : url('/pages/history.php?tab=item&edit_out=' . (int) $doc['id']);
            ?>
        <article class="withdraw-split">
            <aside class="withdraw-split-side">
                <?php if ($isSet): ?>
                <span class="move-tag move-set">เบิก Set</span>
                <?php elseif ($itemCount > 1): ?>
                <span class="badge badge-info withdraw-split-kind">เบิกชุด</span>
                <?php else: ?>
                <span class="move-tag move-item">เบิกรายชิ้น</span>
                <?php endif; ?>
                <p class="withdraw-split-doc"><?= e((string) $doc['doc_no']) ?></p>
                <?php if ($isSet && !empty($doc['set_name'])): ?>
                <p class="withdraw-split-set-name"><?= e((string) $doc['set_name']) ?></p>
                <?php endif; ?>
                <dl class="withdraw-split-meta">
                    <dt>ผู้เบิก</dt>
                    <dd><?= e($doc['issued_by'] ?: '-') ?></dd>
                    <dt>วันเวลา</dt>
                    <dd><?= formatDate($doc['created_at']) ?></dd>
                    <dt>สรุป</dt>
                    <dd><?= number_format($itemCount) ?> ชนิด · <?= formatNumber($totalQty) ?> ชิ้น</dd>
                    <?php if ($assetCode !== ''): ?>
                    <dt>S/N</dt>
                    <dd><strong><?= e($assetCode) ?></strong></dd>
                    <?php endif; ?>
                </dl>
                <div class="withdraw-split-actions table-actions">
                    <?= actionIcon('view', url('/pages/history.php?id=' . (int) $doc['id'] . $backQuery), 'ดูรายละเอียด') ?>
                    <?= actionIcon('edit', $editUrl, 'แก้ไข') ?>
                </div>
            </aside>
            <section class="withdraw-split-list">
                <div class="withdraw-split-list-head">
                    <span>รายการอะไหล่</span>
                    <span><?= number_format($itemCount) ?> ชนิด · <?= formatNumber($totalQty) ?> ชิ้น</span>
                </div>
                <div class="withdraw-split-scroll">
                    <?php foreach ($items as $item):
                        $label = parts_label_for_code((string) $item['code'], (string) $item['name'], $partProdLabels);
                        $unit = (string) ($item['unit'] ?? 'ชิ้น');
                        ?>
                    <div class="withdraw-split-row">
                        <div class="withdraw-split-row-img"><?= parts_img_link_tag($partIcons[$item['code']] ?? '', $label, (string) $item['code']) ?></div>
                        <div class="withdraw-split-row-name">
                            <?= parts_name_profile_link((string) $item['code'], $label) ?>
                        </div>
                        <div class="withdraw-split-row-qty text-danger">-<?= formatNumber($item['quantity']) ?> <?= e($unit) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="withdraw-split-list-foot">รวมเบิก <?= formatNumber($totalQty) ?> ชิ้น</p>
            </section>
        </article>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sn_basket') {
    ob_start();
    session_start();
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once dirname(__DIR__, 2) . '/shared/ui_icons.php';
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
    $codes = [];
    foreach ($basket as $doc) {
        foreach ($doc['items'] as $item) {
            if (!empty($item['code'])) {
                $codes[] = $item['code'];
            }
        }
    }
    $partProdLabels = production_part_labels_by_stock_codes(array_unique($codes));
    $iconProducts = array_map(function ($c) {
        return ['code' => $c];
    }, array_unique($codes));
    $partIcons = parts_product_icon_map($iconProducts);
    ?>
    <p class="withdraw-basket-sn-lead">S/N: <strong><?= e($sn) ?></strong> — <?= count($basket) ?> ใบเบิก</p>
    <?= history_withdraw_basket_cards_html($basket, $partProdLabels, $partIcons, '&tab=sn') ?>
    <?php
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'set_basket') {
    ob_start();
    session_start();
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once dirname(__DIR__, 2) . '/shared/ui_icons.php';
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
    $outId = (int) ($_GET['id'] ?? 0);
    if ($outId <= 0) {
        echo '<p class="text-muted">ไม่ระบุใบเบิก</p>';
        exit;
    }
    $detail = $stock->getStockOutDetail($outId);
    if (!$detail || empty($detail['set_id'])) {
        echo '<p class="text-muted">ไม่พบใบเบิก Set นี้</p>';
        exit;
    }
    $items = [];
    foreach ($detail['items'] as $row) {
        $items[] = [
            'code'     => $row['code'],
            'name'     => $row['name'],
            'quantity' => $row['quantity'],
            'unit'     => $row['unit'] ?: 'ชิ้น',
        ];
    }
    if ($items === []) {
        echo '<p class="text-muted">ไม่มีรายการอะไหล่ในใบเบิกนี้</p>';
        exit;
    }
    $basket = [[
        'id'         => (int) $detail['id'],
        'doc_no'     => $detail['doc_no'],
        'set_id'     => (int) $detail['set_id'],
        'set_code'   => $detail['set_code'],
        'set_name'   => $detail['set_name'],
        'issued_by'  => $detail['issued_by'],
        'created_at'  => $detail['created_at'],
        'asset_code'  => $detail['asset_code'] ?? '',
        'items'       => $items,
    ]];
    $codes = array_column($items, 'code');
    $partProdLabels = production_part_labels_by_stock_codes(array_unique(array_filter($codes)));
    $iconProducts = array_map(static function ($c) {
        return ['code' => $c];
    }, array_unique(array_filter($codes)));
    $partIcons = parts_product_icon_map($iconProducts);
    ?>
    <?= history_withdraw_basket_cards_html($basket, $partProdLabels, $partIcons, '&tab=move&kind=set') ?>
    <?php
    exit;
}

// ต้องเรียกเองก่อน header.php เพราะต้องใช้ url() และ $stock ตอนคำนวณ $tab/$partsBackUrl
// (header.php require ซ้ำได้ มี PARTS_BOOTSTRAP_LOADED กันไว้)
require_once __DIR__ . '/../includes/parts_bootstrap.php';

$historyTabs = [
    'move' => ['label' => 'รายการเคลื่อนไหว', 'icon' => 'history', 'sub' => 'รับเข้าและเบิกออกรวมกัน เรียงตามเวลาบันทึกล่าสุด'],
    'sn'   => ['label' => 'ตาม S/N',          'icon' => 'history', 'sub' => 'รายการเบิกออกทั้งหมด — 1 แถวต่อ S/N (คลิกแถวเพื่อดูรายละเอียด)'],
];

// ปุ่มกรองชนิดในแท็บรายการเคลื่อนไหว — เดิมเป็นแท็บแยก 4 อัน ซึ่ง 3 ใน 4 ใช้ตารางหน้าตาเดียวกัน
$moveKinds = [
    'all'  => ['label' => 'ทั้งหมด',     'sub' => 'รับเข้าและเบิกออกรวมกัน เรียงตามเวลาบันทึกล่าสุด'],
    'in'   => ['label' => 'รับเข้า',      'sub' => 'ประวัติการรับอะไหล่เข้าคลัง'],
    'item' => ['label' => 'เบิกรายชิ้น',  'sub' => 'ประวัติการเบิกอะไหล่ออกทีละรายการ'],
    'set'  => ['label' => 'เบิก Set',     'sub' => 'ประวัติการเบิกอะไหล่ออกเป็นชุด'],
];

$tab = (string) ($_GET['tab'] ?? 'move');
$moveKind = (string) ($_GET['kind'] ?? 'all');

// ลิงก์เดิม (?tab=all|in|item|set) รวมถึง redirect ของ stock-in.php ฯลฯ ยังต้องใช้ได้
// แปลงเป็นแท็บ move + ชนิด แทนที่จะปล่อยตกไปหน้าเริ่มต้นแล้วผู้ใช้งงว่าลิงก์พาไปผิดที่
$legacyTabToKind = ['all' => 'all', 'in' => 'in', 'item' => 'item', 'set' => 'set'];
if (isset($legacyTabToKind[$tab])) {
    $moveKind = $legacyTabToKind[$tab];
    $tab = 'move';
}
if (!isset($historyTabs[$tab])) {
    $tab = 'move';
}
if (!isset($moveKinds[$moveKind])) {
    $moveKind = 'all';
}

$editIn = isset($_GET['edit_in']) ? (int) $_GET['edit_in'] : 0;
$editOut = isset($_GET['edit_out']) ? (int) $_GET['edit_out'] : 0;
$editInRow = $editIn ? $stock->getStockInRow($editIn) : null;
$editOutRow = $editOut ? $stock->getStockOutDetail($editOut) : null;

// ลิงก์เก่าจากหน้าที่ยุบไปแล้ว (และ bookmark) ไม่มี ?tab= มาด้วย — เดาชนิดให้จากรายการที่ขอแก้
// ถ้าไม่ทำ modal จะไม่ถูก render เพราะอยู่คนละแท็บ แล้วกดแล้วเงียบโดยไม่มีอะไรเกิดขึ้น
if ($editInRow) {
    $tab = 'move';
    $moveKind = 'in';
} elseif ($editOutRow) {
    $tab = 'move';
    $moveKind = empty($editOutRow['set_id']) ? 'item' : 'set';
}

// URL ของแท็บ/ปุ่มกรอง — ใช้ซ้ำทั้งตอน render และตอน redirect หลังบันทึก
$moveUrl = function (string $kind) {
    return url('/pages/history.php?tab=move' . ($kind !== 'all' ? '&kind=' . $kind : ''));
};
$currentTabUrl = $tab === 'move' ? $moveUrl($moveKind) : url('/pages/history.php?tab=sn');

$detailId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$detail = $detailId ? $stock->getStockOutDetail($detailId) : null;

// ต้องตั้งก่อน require header.php — header อ่านค่านี้ตอน include ตั้งทีหลังไม่มีผล
if ($detail) {
    $partsBackUrl = $currentTabUrl;
}

$pageTitle = 'ประวัติ — ' . ($tab === 'move' ? $moveKinds[$moveKind]['label'] : $historyTabs[$tab]['label']);
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รวม handler ของ stock-in.php / stock-out-item.php / stock-out.php มาไว้ที่เดียว
    // ไม่มี else ที่สร้างรายการใหม่ — การสร้างทั้งหมดอยู่ที่ products.php ภายใต้ชื่อ action ชัดเจน
    try {
        if (isset($_POST['delete_stock_in'])) {
            $stock->deleteStockIn((int) $_POST['id']);
            flash('success', 'ลบรายการรับเข้าแล้ว');
        } elseif (isset($_POST['update_stock_in'])) {
            $stock->updateStockIn(
                (int) $_POST['id'],
                qty_post('quantity'),
                trim($_POST['note'] ?? '') ?: null
            );
            flash('success', 'แก้ไขรายการรับเข้าแล้ว');
        } elseif (isset($_POST['delete_stock_out'])) {
            $stock->deleteStockOut((int) $_POST['id']);
            flash('success', 'ลบรายการเบิกแล้ว');
        } elseif (isset($_POST['update_stock_out'])) {
            $stock->updateStockOutSingle(
                (int) $_POST['id'],
                qty_post('quantity'),
                validateStockOutNote($_POST['note'] ?? null),
                trim($_POST['asset_code'] ?? '') ?: null,
                $line_name
            );
            flash('success', 'แก้ไขรายการเบิกแล้ว');
        } elseif (isset($_POST['update_stock_out_meta'])) {
            $stock->updateStockOutMeta(
                (int) $_POST['id'],
                validateStockOutNote($_POST['note'] ?? null),
                trim($_POST['asset_code'] ?? '') ?: null,
                $line_name
            );
            flash('success', 'แก้ไขรายการเบิกแล้ว');
        } else {
            flash('error', 'คำสั่งไม่ถูกต้อง');
        }
    } catch (Exception $e) {
        flash('error', safe_exception_message($e));
    }
    // กลับไปแท็บเดิมแต่ตัด edit_in/edit_out ทิ้ง ไม่งั้น modal จะเด้งขึ้นมาใหม่ทันทีที่บันทึกเสร็จ
    redirect($currentTabUrl);
}

$allProducts = parts_enrich_products($stock->getAllProducts());
$partProdLabels = production_part_labels_by_stock_codes(array_column($allProducts, 'code'));
$partIcons = parts_product_icon_map($allProducts);

// โหลดเฉพาะ query ของแท็บที่กำลังดู
$grouped = [];
$history = [];
$noteOptions = [];
$moveCounts = [];
$allFull = false;
$allTotal = 0;
if ($detail) {
    // หน้ารายละเอียดไม่ต้องใช้ตารางไหนเลย
} elseif ($tab === 'move') {
    // ค่าเริ่มต้นตัดที่ 100 เพื่อให้หน้าแรกเปิดไว · กด "แสดงทั้งหมด" เพื่อดูย้อนหลังครบ
    $allFull = !empty($_GET['full']);
    $history = $stock->getAllMovementHistory($allFull ? null : 100, $moveKind);
    $moveCounts = $stock->getMovementCounts();
    $allTotal = $moveCounts[$moveKind] ?? 0;
    $noteOptions = getStockOutNoteOptions();
} else {
    $grouped = $stock->getStockOutHistoryGroupedBySn();
}

/**
 * สรุปรายการเบิกของ S/N (แถวยุบ)
 *
 * @param array<string,mixed> $g
 * @return string
 */
function history_sn_summary(array $g): string
{
    $docCount = (int) ($g['doc_count'] ?? $g['count']);
    if ($docCount <= 1) {
        $itemCount = count($g['items'] ?? []);
        if ($itemCount > 1) {
            return '<span class="history-sn-summary">' . $itemCount . ' รายการอะไหล่</span>';
        }
        return history_row_label($g['latest']);
    }

    $seen = [];
    $sets = 0;
    $singles = 0;
    foreach ($g['items'] as $h) {
        $id = (int) $h['id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        if (!empty($h['set_id'])) {
            $sets++;
        } else {
            $singles++;
        }
    }

    $label = $docCount . ' ใบเบิก';
    if ($sets && $singles) {
        $label .= ' (Set ' . $sets . ', รายชิ้น ' . $singles . ')';
    } elseif ($sets) {
        $label .= ' (Set)';
    } elseif ($singles) {
        $label .= ' (รายชิ้น)';
    }

    return '<span class="history-sn-summary">' . e($label)
        . ' <span class="text-muted">— คลิกดูรายละเอียด</span></span>';
}

/**
 * ใบเบิกล่าสุดในกลุ่ม S/N
 *
 * @param array<string,mixed> $g
 * @return array<string,mixed>
 */
function history_sn_latest(array $g): array
{
    $latest = $g['latest'];
    foreach ($g['items'] as $h) {
        if (strcmp((string) ($h['created_at'] ?? ''), (string) ($latest['created_at'] ?? '')) > 0) {
            $latest = $h;
        }
    }
    return $latest;
}

/**
 * ป้ายรายการเบิก (Set / รายชิ้น / เบิกชุด)
 *
 * @param array<string,mixed> $h
 * @return string
 */
function history_row_label(array $h): string
{
    global $partProdLabels;
    if (!empty($h['set_id'])) {
        return '[Set] [' . e($h['set_code']) . '] ' . e($h['set_name']);
    }
    $itemCount = (int) ($h['item_count'] ?? 1);
    if ($itemCount > 1) {
        return '<span class="badge badge-info">เบิกชุด</span> ' . $itemCount . ' รายการอะไหล่';
    }
    $code = (string) ($h['single_product_code'] ?? '');
    $name = parts_label_for_code($code, (string) ($h['single_product_name'] ?? ''), $partProdLabels ?? []);
    return '<span class="badge badge-info">รายชิ้น</span> ' . parts_name_profile_link($code, $name) . '<span class="sr-only"> ' . e($code) . '</span>';
}

/**
 * ปุ่มจัดการใบเบิกหนึ่งรายการ
 *
 * @param array<string,mixed> $h
 * @return string
 */
function history_row_actions(array $h): string
{
    // พา tab/kind ไปกับลิงก์ด้วย ปุ่ม "← กลับ" ในหน้ารายละเอียดจะได้พากลับมุมมองเดิม
    // ตอนนี้เรียกจากตารางในแท็บ S/N เท่านั้น แต่อ่าน $tab ไว้เผื่อถูกใช้ในตารางอื่นด้วย
    global $tab, $moveKind;
    $back = $tab === 'sn'
        ? '&tab=sn'
        : '&tab=move' . (($moveKind ?? 'all') !== 'all' ? '&kind=' . $moveKind : '');
    $view = actionIcon('view', url('/pages/history.php?id=' . (int) $h['id'] . $back), 'ดูรายละเอียด');
    if (empty($h['set_id'])) {
        $edit = actionIcon('edit', url('/pages/history.php?tab=item&edit_out=' . (int) $h['id']), 'แก้ไข');
    } else {
        $edit = actionIcon('edit', url('/pages/history.php?tab=set&edit_out=' . (int) $h['id']), 'แก้ไข');
    }
    $delete = '<form method="POST"' . parts_confirm_attrs('ลบใบเบิกนี้?', 'ลบใบเบิก') . '>'
        . '<input type="hidden" name="delete_stock_out" value="1">'
        . '<input type="hidden" name="id" value="' . (int) $h['id'] . '">'
        . actionIcon('delete', '', 'ลบ')
        . '</form>';
    return '<div class="table-actions">' . $view . $edit . $delete . '</div>';
}

/**
 * วัตถุประสงค์จากหมายเหตุที่ส่งมาจาก inventory pickup (ส่วนก่อน | )
 *
 * @param string $note หมายเหตุใน stock_out.note
 * @return string
 */
function history_inventory_purpose_head(string $note): string
{
    $note = trim($note);
    if ($note === '') {
        return '';
    }
    $pipe = strpos($note, '|');
    if ($pipe === false) {
        return $note;
    }

    return trim(substr($note, 0, $pipe));
}

/**
 * ข้อความหมายเหตุที่แสดง — ส่วนหลังวัตถุประสงค์ (เช่น ชื่อลูกค้า)
 *
 * @param string $note
 * @return string
 */
function history_inventory_note_body(string $note): string
{
    $note = trim($note);
    if ($note === '') {
        return '';
    }
    $parts = array_map('trim', explode('|', $note));
    $head = $parts[0] ?? '';
    if (history_inventory_purpose_is_known($head)) {
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
            $seg = $parts[$i];
            if ($seg === '' || preg_match('/^SN:/i', $seg)) {
                continue;
            }

            return $seg;
        }

        return '';
    }
    if (strpos($note, '|') === false) {
        return $note;
    }

    return trim(implode(' | ', array_slice($parts, 1)));
}

/**
 * ข้อความคอลัมน์หมายเหตุในแท็บความเคลื่อนไหว
 *
 * @param array<string,mixed> $h
 * @return string
 */
function history_move_note_cell(array $h): string
{
    $raw = trim((string) ($h['note'] ?? ''));
    if ($raw === '') {
        return '-';
    }
    $head = history_inventory_purpose_head($raw);
    $body = history_inventory_note_body($raw);
    if ($head === 'เบิกซ่อมลูกค้า') {
        return $body !== '' ? $body : '';
    }
    if ($body !== '') {
        return $body;
    }
    if (history_inventory_purpose_is_known($head)) {
        return '-';
    }

    return $raw;
}

/**
 * HTML คอลัมน์หมายเหตุแท็บ move — เบิกซ่อมจาก inventory แสดง「เบิกงานซ่อม」กับชื่อลูกค้าคนละบรรทัด
 *
 * @param array<string,mixed> $h
 * @return string HTML ที่ escape แล้ว (ยกเว้นแท็กโครงสร้างที่กำหนด)
 */
function history_move_note_cell_html(array $h): string
{
    $raw = trim((string) ($h['note'] ?? ''));
    if ($raw === '') {
        return e('-');
    }
    $head = history_inventory_purpose_head($raw);
    $body = history_inventory_note_body($raw);
    if ($head === 'เบิกซ่อมลูกค้า') {
        $html = '<span class="history-move-note-stack">';
        $html .= '<span class="history-move-note-purpose">' . e('เบิกงานซ่อม') . '</span>';
        if ($body !== '') {
            $html .= '<span class="history-move-note-company">' . e($body) . '</span>';
        }

        return $html . '</span>';
    }

    return e(history_move_note_cell($h));
}

/**
 * @param string $text
 * @return bool
 */
function history_inventory_purpose_is_known(string $text): bool
{
    static $known = [
        'เบิกผลิต',
        'เบิกตาม PO',
        'เบิกซ่อมลูกค้า',
        'เบิกเสียระหว่างผลิต',
        'เบิกเสียระหว่างขนส่ง',
        'เบิก MA',
        'เบิกอื่นๆ',
    ];

    return in_array(trim($text), $known, true);
}

/**
 * ป้ายประเภทในตาราง move — วัตถุประสงค์จาก inventory (ยกเว้นเบิกซ่อมลูกค้า ยังเป็นเบิกรายชิ้น)
 *
 * @param array<string,mixed> $h
 * @return array{label:string,class:string}
 */
function history_move_type_chip(array $h): array
{
    $kind = (string) ($h['kind'] ?? '');
    if ($kind === 'in') {
        return ['label' => 'รับเข้า', 'class' => 'move-in'];
    }
    if ($kind === 'set') {
        return ['label' => 'เบิก Set', 'class' => 'move-set'];
    }

    $purpose = history_inventory_purpose_head((string) ($h['note'] ?? ''));
    $map = [
        'เบิก MA' => ['label' => 'เบิก MA', 'class' => 'move-ma'],
        'เบิกผลิต' => ['label' => 'เบิกผลิต', 'class' => 'move-prod'],
        'เบิกตาม PO' => ['label' => 'เบิกตาม PO', 'class' => 'move-po'],
        'เบิกเสียระหว่างผลิต' => ['label' => 'เสียระหว่างผลิต', 'class' => 'move-scrap'],
        'เบิกเสียระหว่างขนส่ง' => ['label' => 'เสียระหว่างขนส่ง', 'class' => 'move-scrap'],
        'เบิกอื่นๆ' => ['label' => 'เบิกอื่นๆ', 'class' => 'move-other'],
    ];
    if ($purpose !== '' && isset($map[$purpose])) {
        return $map[$purpose];
    }

    return ['label' => 'เบิกรายชิ้น', 'class' => 'move-item'];
}

/**
 * ประเภทการเบิกจากหมายเหตุใบงาน — ใช้ chip ในคอลัมน์หมายเหตุ
 *
 * @param array<string,mixed> $h
 * @return string prod|ma|repair|other
 */
function history_withdraw_kind(array $h): string
{
    $note = trim((string) ($h['note'] ?? ''));
    if ($note === 'MA' || preg_match('/\bMA\b/u', $note)) {
        return 'ma';
    }
    if ($note === 'เบิกงานซ่อม' || $note === 'ซ่อม' || mb_strpos($note, 'ซ่อม') !== false) {
        return 'repair';
    }
    if ($note === 'เบิกผลิต' || mb_strpos($note, 'ผลิต') !== false || mb_strpos($note, 'BOM') !== false) {
        return 'prod';
    }
    return 'other';
}

/**
 * รายการประเภทการเบิกในกลุ่ม S/N (คั่นด้วย comma สำหรับ filter)
 *
 * @param array<string,mixed> $g
 * @return string
 */
function history_sn_withdraw_kinds_attr(array $g): string
{
    $set = [];
    foreach ($g['items'] as $h) {
        $set[history_withdraw_kind($h)] = true;
    }
    return implode(',', array_keys($set));
}

/**
 * chip หมายเหตุประจำประเภทการเบิก (ผลิต / MA / ซ่อม)
 *
 * @param array<string,mixed> $h
 * @return string HTML
 */
function history_note_badge(array $h): string
{
    $note = trim((string) ($h['note'] ?? ''));
    if ($note === '') {
        return '<span class="text-muted">-</span>';
    }
    $kind = history_withdraw_kind($h);
    switch ($kind) {
        case 'ma':
            return '<span class="badge history-note-badge history-note-ma">MA</span>';
        case 'repair':
            return '<span class="badge history-note-badge history-note-repair">เบิกซ่อม</span>';
        case 'prod':
            return '<span class="badge history-note-badge history-note-prod">เบิกผลิต</span>';
        default:
            return '<span class="badge badge-info">' . e($note) . '</span>';
    }
}

/**
 * รูปอะไหล่ — เฉพาะเบิกรายชิ้น 1 อะไหล่ต่อ 1 ใบงาน
 *
 * @param array<string,mixed> $h
 * @param array<string,string> $partIcons
 * @return string
 */
function history_row_thumb(array $h, array $partIcons): string
{
    global $partProdLabels;
    if (!empty($h['set_id'])) {
        return '';
    }
    if ((int) ($h['item_count'] ?? 1) !== 1) {
        return '';
    }
    $code = (string) ($h['single_product_code'] ?? '');
    $name = parts_label_for_code($code, (string) ($h['single_product_name'] ?? ''), $partProdLabels ?? []);
    return parts_img_link_tag($partIcons[$code] ?? '', $name, $code);
}

/**
 * ช่องค้นหาในรายการประวัติ (กรองฝั่งเบราว์เซอร์ด้วย app.js: [data-row-filter])
 *
 * ใช้หน้าตาเดียวกับช่องค้นหาของหน้ารายการอะไหล่ เพื่อให้ทั้งระบบใช้งานเหมือนกัน
 *
 * @param string $tbodyId     id ของ tbody ที่จะกรอง
 * @param string $placeholder ข้อความอธิบายในช่อง
 * @return string HTML
 */
function history_search_bar_html(string $tbodyId, string $placeholder, ?string $fullUrl = null): string
{
    // $fullUrl = ลิงก์โหลดรายการทั้งหมด (เมื่อหน้านี้โหลดแค่ 100 รายการล่าสุด) — ถ้าไม่เจอในที่โหลดมา
    // app.js จะเสนอลิงก์นี้พร้อมคำค้นเดิม (?q=) แล้วเติมคำค้นกลับเข้าช่องให้หลังโหลดใหม่
    $fullAttr = $fullUrl !== null ? ' data-full-url="' . e($fullUrl) . '"' : '';
    $moreLink = $fullUrl !== null
        ? ' <a id="history-search-full" href="' . e($fullUrl) . '">ค้นหาในรายการทั้งหมด</a>'
        : '';
    return '<div class="products-search-bar history-search-bar">'
        . '<label for="history-search" class="sr-only">ค้นหาในประวัติ</label>'
        . '<input type="search" id="history-search" class="products-search-input"'
        . ' data-row-filter="' . e($tbodyId) . '" data-filter-empty="history-search-empty"' . $fullAttr
        . ' placeholder="' . e($placeholder) . '" autocomplete="off">'
        . '</div>'
        . '<p id="history-search-empty" class="empty-state" hidden>ไม่พบรายการที่ตรงกับคำค้นหา' . $moreLink . '</p>';
}

/**
 * รูปสำหรับแถวสรุป S/N — แสดงเมื่อมี 1 ใบงานและ 1 อะไหล่
 *
 * @param array<string,mixed> $g
 * @param array<string,string> $partIcons
 * @return string
 */
function history_sn_row_thumb(array $g, array $partIcons): string
{
    if ((int) ($g['doc_count'] ?? $g['count'] ?? 0) > 1) {
        return '';
    }
    return history_row_thumb(history_sn_latest($g), $partIcons);
}
?>

<?php
$headSub = $tab === 'move' ? $moveKinds[$moveKind]['sub'] : $historyTabs[$tab]['sub'];
$headLabel = $tab === 'move' ? $moveKinds[$moveKind]['label'] : $historyTabs[$tab]['label'];
parts_page_header($historyTabs[$tab]['icon'], 'ประวัติ — ' . $headLabel, $headSub);
?>

<?php if (!$detail): ?>
<?php // แท็บเป็นลิงก์ (navigation ฝั่งเซิร์ฟเวอร์) — ห้ามใส่ data-history-filter เพราะ app.js จะดักคลิกไปทำ filter ?>
<div class="history-navbar">
<nav class="history-kind-legend history-tabs" aria-label="มุมมองประวัติ">
    <?php foreach ($historyTabs as $key => $def): ?>
    <a href="<?= $key === 'move' ? $moveUrl('all') : url('/pages/history.php?tab=sn') ?>"
       class="history-kind-filter<?= $tab === $key ? ' is-active' : '' ?>"
       <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($def['label']) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'move'): ?>
<?php // ปุ่มกรองชนิด — ระดับรองจากแท็บ ใช้ตารางเดียวกันทั้งหมด ต่างแค่เงื่อนไข ?>
<nav class="history-kind-legend move-kinds" aria-label="กรองชนิดการเคลื่อนไหว">
    <?php foreach ($moveKinds as $key => $def): ?>
    <a href="<?= $moveUrl($key) ?>"
       class="history-kind-filter<?= $moveKind === $key ? ' is-active' : '' ?>"
       <?= $moveKind === $key ? 'aria-current="page"' : '' ?>><?= e($def['label']) ?><?php
        if (!empty($moveCounts[$key])) { echo ' (' . number_format($moveCounts[$key]) . ')'; }
    ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($tab === 'sn'): ?>
<?php // กรองฝั่งเบราว์เซอร์ (app.js ผูกจาก [data-history-filter] ทั้งหน้า จึงย้ายมาอยู่
     // แถวเดียวกับแท็บได้) — เดิมอยู่ในการ์ดเหนือตาราง กินไปอีกหนึ่งบรรทัด ?>
<div class="history-kind-legend move-kinds" role="group" aria-label="กรองประเภทการเบิก">
    <button type="button" class="history-kind-filter is-active" data-history-filter="all">ทั้งหมด</button>
    <button type="button" class="history-kind-filter badge history-note-badge history-note-prod" data-history-filter="prod">เบิกผลิต</button>
    <button type="button" class="history-kind-filter badge history-note-badge history-note-ma" data-history-filter="ma">MA</button>
    <button type="button" class="history-kind-filter badge history-note-badge history-note-repair" data-history-filter="repair">เบิกซ่อม</button>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($detail): ?>
<div class="card parts-list-card">
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
            <dt>S/N เครื่อง</dt>
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
            <dt>วันเวลา</dt>
            <dd><?= formatDate($detail['created_at']) ?></dd>
        </div>
        <?php if (!empty($detail['part_movement_id'])): ?>
        <div>
            <dt>Production</dt>
            <dd><span class="sync-badge"><?= ui_icon_html('switch', 12, 'sync-svg') ?> movement #<?= (int) $detail['part_movement_id'] ?></span></dd>
        </div>
        <?php endif; ?>
    </dl>
    <div class="table-wrap table-wrap-fold">
    <table class="parts-table">
        <thead>
            <tr>
                <th class="col-img">รูป</th>
                <th>รหัส</th>
                <th>อะไหล่</th>
                <th class="text-right">จำนวนเบิก</th>
                <th>หน่วย</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detail['items'] as $item): ?>
            <tr>
                <td class="col-img"><?= parts_img_link_tag($partIcons[$item['code']] ?? '', parts_label_for_code((string) $item['code'], (string) $item['name'], $partProdLabels), (string) $item['code']) ?></td>
                <td><?= e($item['code']) ?></td>
                <td><?= parts_name_profile_link((string) $item['code'], parts_label_for_code((string) $item['code'], (string) $item['name'], $partProdLabels)) ?></td>
                <td class="text-right text-danger">-<?= formatNumber($item['quantity']) ?></td>
                <td><?= e($item['unit']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="form-actions">
        <a href="<?= e($currentTabUrl) ?>" class="btn btn-outline">← กลับ</a>
        <?php if (empty($detail['set_id'])): ?>
        <?= actionIcon('edit', url('/pages/history.php?tab=item&edit_out=' . (int) $detail['id']), 'แก้ไข', false) ?>
        <?php else: ?>
        <?= actionIcon('edit', url('/pages/history.php?tab=set&edit_out=' . (int) $detail['id']), 'แก้ไข', false) ?>
        <?php endif; ?>
        <?php $cf = parts_stock_out_delete_impact($detail); ?>
        <form method="POST"<?= parts_confirm_attrs(
            'ลบใบเบิก ' . ($detail['doc_no'] ?? '') . '?', 'ลบใบเบิกนี้', $cf['impact'], $cf['warn']) ?>>
            <input type="hidden" name="delete_stock_out" value="1">
            <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
            <?= actionIcon('delete', '', 'ลบ', false) ?>
        </form>
    </div>
</div>
<?php elseif ($tab === 'move'): ?>

<div class="card parts-list-card history-list-card">
    <?php if (empty($history)): ?>
        <p class="empty-state">ยังไม่มีรายการในชนิดที่เลือก</p>
    <?php else: ?>
    <?= history_search_bar_html('history-move-rows', 'ค้นหา — เลขที่ / อะไหล่ / S/N',
        (!$allFull && count($history) < $allTotal) ? $moveUrl($moveKind) . '&full=1' : null) ?>
    <div class="table-wrap table-wrap-fold table-wrap-fold-fill">
        <table class="parts-table history-move-table">
            <thead>
                <tr>
                    <th>วันเวลา</th>
                    <th>ประเภท</th>
                    <th class="col-img">รูป</th>
                    <th>รายการ</th>
                    <th class="text-right">จำนวน</th>
                    <th>S/N</th>
                    <th>หมายเหตุ</th>
                    <th>ผู้ทำรายการ</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody id="history-move-rows">
                <?php foreach ($history as $h):
                    $kind = (string) $h['kind'];
                    $isIn = $kind === 'in';
                    $moveChip = history_move_type_chip($h);
                    $code = (string) ($h['code'] ?? '');
                    $icon = $code !== '' ? ($partIcons[$code] ?? '') : '';
                    // เบิก Set แสดงเป็นชื่อชุด ส่วนอีกสองชนิดแสดงชื่ออะไหล่
                    $label = $kind === 'set'
                        ? (string) $h['set_name']
                        : parts_label_for_code($code, (string) ($h['name'] ?? ''), $partProdLabels);
                    $editUrl = $isIn
                        ? url('/pages/history.php?tab=in&edit_in=' . (int) $h['row_id'])
                        : url('/pages/history.php?tab=' . ($kind === 'set' ? 'set' : 'item') . '&edit_out=' . (int) $h['stock_out_id']);
                ?>
                <tr<?= $kind === 'set' ? ' class="history-set-row" data-set-basket-row="' . (int) $h['stock_out_id'] . '" title="คลิกดูรายการอะไหล่ใน Set นี้"' : '' ?>>
                    <td class="text-muted" style="white-space:nowrap"><?= formatDate($h['created_at']) ?></td>
                    <td><span class="move-tag <?= e($moveChip['class']) ?>"><?= e($moveChip['label']) ?></span></td>
                    <td class="col-img"><?= $kind === 'set' ? '' : parts_img_link_tag($icon, $label, $code) ?></td>
                    <?php // รหัสอะไหล่ไม่แสดงบนจอ แต่คงไว้ใน DOM (sr-only) เพื่อให้ช่องค้นหาพิมพ์รหัสแล้วยังเจอ ?>
                    <td><?php if ($kind === 'set'): ?><?= e($label) ?><?php if (!empty($h['set_code'])): ?><span class="sr-only"> <?= e((string) $h['set_code']) ?></span><?php endif; ?><?php if (!empty($h['doc_no'])): ?><br><span class="text-muted history-set-hint" style="font-size:11px"><?= e($h['doc_no']) ?> — คลิกดูรายละเอียด</span><?php endif; ?><?php else: ?><?= parts_name_profile_link($code, $label) ?><?= ($code !== '') ? '<span class="sr-only"> ' . e($code) . '</span>' : '' ?><?= !empty($h['doc_no']) ? '<br><span class="text-muted" style="font-size:11px">' . e($h['doc_no']) . '</span>' : '' ?><?php endif; ?></td>
                    <td class="text-right <?= $isIn ? 'text-success' : 'text-danger' ?>" style="white-space:nowrap">
                        <?= $isIn ? '+' : '-' ?><?= formatNumber($h['qty']) ?> <?= e($h['unit'] ?: 'ชิ้น') ?>
                    </td>
                    <td><?= e($h['asset_code'] ?: '-') ?></td>
                    <td class="text-muted"><?= history_move_note_cell_html($h) ?></td>
                    <td><?= e($h['actor'] ?: '-') ?></td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <?php if ($kind === 'set'): ?>
                            <?= actionIcon('set-basket', (string) (int) $h['stock_out_id'], 'ดูรายการอะไหล่ใน Set') ?>
                            <?php endif; ?>
                            <?= actionIcon('edit', $editUrl, 'แก้ไข') ?>
                            <form method="POST"<?= $isIn
                                ? parts_confirm_attrs('ลบรายการรับเข้านี้?', 'ลบรายการรับเข้า',
                                    [], 'ยอดคงเหลือจะลดลงตามจำนวนที่รับเข้าไว้')
                                : parts_confirm_attrs('ลบใบเบิกนี้?', 'ลบใบเบิก',
                                    [], 'รายการที่ผูกไว้ในระบบทะเบียนเครื่องจะถูกปรับตามด้วย') ?>>
                                <input type="hidden" name="<?= $isIn ? 'delete_stock_in' : 'delete_stock_out' ?>" value="1">
                                <input type="hidden" name="id" value="<?= (int) ($isIn ? $h['row_id'] : $h['stock_out_id']) ?>">
                                <?= actionIcon('delete', '', 'ลบ') ?>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted history-list-foot" style="margin:10px 0 0;font-size:12px">
        แสดง <?= number_format(count($history)) ?> จาก <?= number_format($allTotal) ?> รายการ
        <?php if (!$allFull && count($history) < $allTotal): ?>
            · <a href="<?= $moveUrl($moveKind) . '&full=1' ?>">แสดงทั้งหมด</a>
        <?php elseif ($allFull): ?>
            · <a href="<?= $moveUrl($moveKind) ?>">แสดงแค่ 100 รายการล่าสุด</a>
        <?php endif; ?>
    </p>
    <?php endif; ?>
</div>


<?php // modal แก้ไขทั้งสามชนิดอยู่ในแท็บนี้ เพราะทุกชนิดแสดงในตารางเดียวกันแล้ว
     // ยังเปิดจากลิงก์ ?edit_in= / ?edit_out= ได้เหมือนเดิม ?>
<?php if ($editInRow): ?>
<?php parts_modal_begin('stock-in-edit-modal', 'แก้ไขรายการรับเข้า', true, true); ?>
<form method="POST">
    <input type="hidden" name="update_stock_in" value="1">
    <input type="hidden" name="id" value="<?= (int) $editInRow['id'] ?>">
    <div class="form-group">
        <label>อะไหล่</label>
        <input type="text" value="<?= e(parts_label_for_code((string) ($editInRow['code'] ?? ''), (string) ($editInRow['name'] ?? ''), $partProdLabels)) ?>" readonly>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนรับเข้า</label>
            <input type="number" name="quantity" min="0.01" step="0.01" value="<?= e(qty_input_value($editInRow['quantity'])) ?>" required data-autofocus>
        </div>
        <div class="form-group">
            <label>หมายเหตุ</label>
            <textarea name="note" rows="2"><?= e($editInRow['note'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= $currentTabUrl ?>" class="btn btn-outline">ยกเลิก</a>
        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>

<?php // เดิม modal นี้กับ modal ของ set อยู่คนละแท็บจึงไม่ชนกัน ตอนนี้อยู่สาขาเดียวกันแล้ว
     // ต้องเช็ค set_id เอง ไม่งั้นการเบิก Set จะเปิด modal ทั้งสองอันซ้อนกัน ?>
<?php $ei = ($editOutRow && empty($editOutRow['set_id'])) ? ($editOutRow['items'][0] ?? null) : null; ?>
<?php if ($ei): ?>
<?php parts_modal_begin('stock-out-item-edit-modal', 'แก้ไขรายการเบิก', true, true); ?>
<form method="POST">
    <input type="hidden" name="update_stock_out" value="1">
    <input type="hidden" name="id" value="<?= (int) $editOutRow['id'] ?>">
    <div class="form-group">
        <label>อะไหล่</label>
        <input type="text" value="<?= e(parts_label_for_code((string) ($ei['code'] ?? ''), (string) ($ei['name'] ?? ''), $partProdLabels)) ?>" readonly>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนเบิก</label>
            <input type="number" name="quantity" min="0.01" step="0.01" value="<?= e(qty_input_value($ei['quantity'])) ?>" required data-autofocus>
        </div>
        <div class="form-group">
            <label>หมายเลขเครื่อง (S/N)</label>
            <input type="text" name="asset_code" value="<?= e($editOutRow['asset_code'] ?? '') ?>" placeholder="เช่น BP26072024">
        </div>
    </div>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>" <?= ($editOutRow['note'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!empty($editOutRow['part_movement_id'])): ?>
    <p class="sync-badge"><?= ui_icon_html('switch', 12, 'sync-svg') ?> เชื่อมกับระบบทะเบียนเครื่องแล้ว</p>
    <?php endif; ?>
    <div class="form-actions">
        <a href="<?= $currentTabUrl ?>" class="btn btn-outline">ยกเลิก</a>
        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>

<?php if ($editOutRow && !empty($editOutRow['set_id'])): ?>
<?php parts_modal_begin('stock-out-set-edit-modal', 'แก้ไขรายการเบิก Set — ' . $editOutRow['doc_no'], true, true); ?>
<form method="POST">
    <input type="hidden" name="update_stock_out_meta" value="1">
    <input type="hidden" name="id" value="<?= (int) $editOutRow['id'] ?>">
    <div class="form-group">
        <label>ชุดเบิก</label>
        <input type="text" value="[<?= e($editOutRow['set_code']) ?>] <?= e($editOutRow['set_name']) ?>" readonly>
    </div>
    <div class="form-group">
        <label>หมายเลขเครื่อง (S/N)</label>
        <input type="text" name="asset_code" value="<?= e($editOutRow['asset_code'] ?? '') ?>" placeholder="เช่น BP26072024" data-autofocus>
    </div>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>" <?= ($editOutRow['note'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <a href="<?= $currentTabUrl ?>" class="btn btn-outline">ยกเลิก</a>
        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>

<?php elseif ($tab === 'sn'): ?>

<div class="card parts-list-card history-list-card">
    <?= history_search_bar_html('history-list-body', 'ค้นหา — S/N / เลขที่ / อะไหล่') ?>
    <div class="table-wrap table-wrap-fold table-wrap-fold-fill">
    <table class="parts-table history-sn-table">
        <thead>
            <tr>
                <th class="col-img">รูป</th>
                <th>เลขที่</th>
                <th>รายการเบิก</th>
                <th>S/N</th>
                <th class="text-right">จำนวนรวม</th>
                <th>ผู้เบิก</th>
                <th>หมายเหตุ</th>
                <th>วันเวลา</th>
                <th class="col-actions">จัดการ</th>
            </tr>
        </thead>
        <tbody id="history-list-body">
            <?php if (empty($grouped)): ?>
            <tr><td colspan="9" class="text-center text-muted">ยังไม่มีประวัติการเบิก</td></tr>
            <?php else: ?>
            <tr id="history-filter-empty" hidden><td colspan="9" class="text-center text-muted">ไม่พบรายการในประเภทที่เลือก</td></tr>
            <?php foreach ($grouped as $g): ?>
            <?php if ($g['type'] === 'sn'): ?>
            <?php $latest = history_sn_latest($g); ?>
            <tr class="history-sn-row" data-sn-basket-row="<?= e($g['sn']) ?>" data-withdraw-kinds="<?= e(history_sn_withdraw_kinds_attr($g)) ?>" title="คลิกดูรายการเบิกทั้งหมดของ S/N นี้">
                <td class="col-img"><?= history_sn_row_thumb($g, $partIcons) ?></td>
                <td>
                    <?php if (($g['doc_count'] ?? $g['count']) > 1): ?>
                    <span class="text-muted"><?= (int) ($g['doc_count'] ?? $g['count']) ?> ใบเบิก</span>
                    <?php else: ?>
                    <strong><?= e($latest['doc_no']) ?></strong>
                    <?php endif; ?>
                </td>
                <td><?= history_sn_summary($g) ?></td>
                <td><strong><?= e($g['sn']) ?></strong></td>
                <td class="text-right"><?= formatNumber($g['total_qty']) ?></td>
                <td><?= e($latest['issued_by'] ?: '-') ?></td>
                <td><?= history_note_badge($latest) ?></td>
                <td class="text-muted"><?= formatDate($latest['created_at']) ?></td>
                <td class="col-actions">
                    <?= actionIcon('basket', $g['sn'], 'ดูรายการเบิกทั้งหมด') ?>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($g['items'] as $h): ?>
            <tr data-withdraw-kinds="<?= e(history_withdraw_kind($h)) ?>">
                <td class="col-img"><?= history_row_thumb($h, $partIcons) ?></td>
                <td><strong><?= e($h['doc_no']) ?></strong></td>
                <td><?= history_row_label($h) ?></td>
                <td title="ไม่ได้ระบุ S/N สำหรับรายการนี้">-</td>
                <td class="text-right"><?= formatNumber($h['display_qty'] ?? $h['total_qty'] ?? 0) ?></td>
                <td><?= e($h['issued_by'] ?: '-') ?></td>
                <td><?= history_note_badge($h) ?></td>
                <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                <td class="col-actions"><?= history_row_actions($h) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php endif; ?>

<?php if (!$detail && ($tab === 'sn' || $tab === 'move')): ?>
<div id="sn-basket-modal" class="modal-overlay withdraw-basket-modal" hidden>
    <div class="modal-box modal-box-withdraw-split">
        <div class="modal-header">
            <h3 class="h-with-icon"><?= ui_icon_html('basket', 18, 'h-svg') ?><span id="withdraw-basket-modal-title">อะไหล่ที่เบิกตาม S/N</span></h3>
            <button type="button" class="modal-close" aria-label="ปิด">&times;</button>
        </div>
        <div id="sn-basket-body" class="modal-body"></div>
    </div>
</div>
<?php endif; ?>

<?php parts_photo_modal_html(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
