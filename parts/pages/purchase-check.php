<?php
/**
 * pages/purchase-check.php — เช็คยอดเบิกผลิตก่อนสั่งซื้อ (แบบ B)
 *
 * สามแท็บใช้ตารางรูปแบบเดียวกัน: รูปอยู่หน้าชื่อ · เบิกผลิต · สูตรต่อเครื่อง · คงเหลือ · ขั้นต่ำ · สถานะ
 * ช่วงเวลาเลือกได้ 30 วัน · 90 วัน · เดือนนี้ — คงเหลือกับขั้นต่ำเป็นยอดวันนี้
 *
 * Flow: bootstrap → โหลดยอดเบิกผลิต (cache สั้น) → header → แถบช่วงเวลา/แท็บ → ตาราง
 */

$pageTitle = 'เช็คสั่งซื้อ';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/purchase_check.php';

$periodIn = (string) ($_GET['period'] ?? '90d');
if ($periodIn === '90') {
    $periodIn = '90d';
} elseif ($periodIn === '30') {
    $periodIn = '30d';
}
$tabIn = (string) ($_GET['tab'] ?? 'models');
$stIn = (string) ($_GET['st'] ?? 'all');
if (!isset(PURCHASE_CHECK_PERIODS[$periodIn])) {
    $periodIn = '90d';
}
if (!isset(PURCHASE_CHECK_TABS[$tabIn])) {
    $tabIn = 'models';
}
if (!isset(PURCHASE_CHECK_STATUS[$stIn])) {
    $stIn = 'all';
}

$bounds = purchase_check_period_bounds($periodIn);
$data = purchase_check_load_cached($bounds['from']);
$allParts = $data['parts'];
$partById = [];
foreach ($allParts as $p) {
    $partById[(int) $p['part_id']] = $p;
}
$shownParts = purchase_check_filter_parts($allParts, $stIn);
$models = $data['models'];
$suppliers = $stIn === 'all' ? $data['suppliers'] : purchase_check_group_suppliers($shownParts);
$counts = $data['counts'];
$searchPh = [
    'models'    => 'ค้นหารุ่น / อะไหล่',
    'suppliers' => 'ค้นหาผู้จำหน่าย / อะไหล่',
][$tabIn] ?? 'ค้นหาอะไหล่ / รุ่น / ผู้จำหน่าย';
$todayLabel = purchase_check_thai_date(date('Y-m-d'));

require_once __DIR__ . '/../includes/header.php';
parts_page_header(
    'basket',
    'เช็คสั่งซื้อ',
    'ยอดเบิกผลิตในช่วงที่เลือก · คงเหลือและขั้นต่ำเป็นของวันนี้'
);
?>

<div class="pc-page">
    <div class="pc-tools">
        <div class="pc-tool">
            <span class="pc-tool-label">ช่วงที่ดูยอดเบิก</span>
            <nav class="history-kind-legend history-tabs" aria-label="ช่วงที่ดูยอดเบิก">
                <?php foreach (PURCHASE_CHECK_PERIODS as $key => $label):
                    $key = (string) $key;
                    ?>
                <a class="history-kind-filter<?= $periodIn === $key ? ' is-active' : '' ?>"
                   href="<?= e(purchase_check_url(['period' => $key, 'tab' => $tabIn, 'st' => $stIn])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <p class="pc-range"><?= e($bounds['from_label']) ?> – <?= e($todayLabel) ?></p>
        </div>
        <div class="pc-tool">
            <span class="pc-tool-label">มุมมอง</span>
            <nav class="history-kind-legend history-tabs" aria-label="มุมมอง">
                <?php foreach (PURCHASE_CHECK_TABS as $key => $label): ?>
                <a class="history-kind-filter<?= $tabIn === $key ? ' is-active' : '' ?>"
                   href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => $key, 'st' => $stIn])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="pc-tool pc-tool-search">
            <label class="pc-tool-label" for="pc-search">ค้นหาในหน้านี้</label>
            <div class="products-search-bar pc-search-bar">
                <span class="pc-search-ico" aria-hidden="true"><?= ui_icon_html('search', 16) ?></span>
                <input type="search" id="pc-search" class="products-search-input"
                       placeholder="<?= e($searchPh) ?>"
                       autocomplete="off">
            </div>
        </div>
    </div>

    <div class="pc-stats" role="group" aria-label="สรุปช่วงที่เลือก">
        <a class="pc-stat<?= $tabIn === 'models' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'models', 'st' => 'all'])) ?>">
            <div class="pc-num"><?= formatNumber($counts['models']) ?></div>
            <div class="pc-lbl">รุ่นที่เบิกผลิต</div>
        </a>
        <a class="pc-stat<?= $tabIn === 'parts' && $stIn === 'all' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'parts', 'st' => 'all'])) ?>">
            <div class="pc-num"><?= formatNumber($counts['parts']) ?></div>
            <div class="pc-lbl">อะไหล่ที่ถูกใช้</div>
        </a>
        <a class="pc-stat<?= $tabIn === 'suppliers' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'suppliers', 'st' => $stIn])) ?>">
            <div class="pc-num"><?= formatNumber($counts['suppliers']) ?></div>
            <div class="pc-lbl">ผู้จำหน่าย</div>
        </a>
        <a class="pc-stat<?= $stIn === 'order' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'parts', 'st' => 'order'])) ?>">
            <div class="pc-num"><?= formatNumber($counts['order']) ?></div>
            <div class="pc-lbl">ควรสั่งแล้ว</div>
        </a>
        <a class="pc-stat<?= $stIn === 'near' ? ' is-on' : '' ?>"
           href="<?= e(purchase_check_url(['period' => $periodIn, 'tab' => 'parts', 'st' => 'near'])) ?>">
            <div class="pc-num"><?= formatNumber($counts['near']) ?></div>
            <div class="pc-lbl">ใกล้ถึงขั้นต่ำ</div>
        </a>
    </div>

    <?php if (!$data['ok']): ?>
    <p class="text-muted" style="color:var(--danger,#dc2626)"><?= e($data['error']) ?></p>
    <?php elseif ($tabIn === 'parts'): ?>
    <div class="card">
        <div class="pc-card-head">
            <span><?= e(PURCHASE_CHECK_STATUS[$stIn]) ?> · <?= formatNumber(count($shownParts)) ?> รายการ</span>
            <?php
            $hasFolds = false;
            foreach ($shownParts as $chk) {
                if (count($chk['models'] ?? []) > 0) {
                    $hasFolds = true;
                    break;
                }
            }
            if ($hasFolds): ?>
            <button type="button" class="pc-fold-all-btn" id="pc-fold-all" data-pc-next="1">ขยายทุกรุ่น</button>
            <?php endif; ?>
        </div>
        <div class="table-wrap table-wrap-fold">
            <table class="pc-table pc-table-fixed" id="pc-parts-table">
                <?= purchase_check_colgroup_html() ?>
                <?= purchase_check_thead_html() ?>
                <tbody>
                <?php if (!$shownParts): ?>
                    <tr id="pc-empty-row"><td colspan="6" class="text-center text-muted" style="padding:22px">ไม่มีการเบิกผลิตตามเงื่อนไขนี้</td></tr>
                <?php else: ?>
                    <?php foreach ($shownParts as $p):
                        $search = mb_strtolower(
                            $p['name'] . ' ' . $p['code'] . ' ' . $p['part_code'] . ' '
                            . ($p['supplier'] ?? '') . ' '
                            . implode(' ', array_column($p['models'], 'name')),
                            'UTF-8'
                        );
                        $mrows = $p['models'] ?: [];
                        $foldCount = count($mrows);
                        $foldId = 'p' . (int) $p['part_id'];
                        echo purchase_check_part_row_html(
                            $p,
                            $p['used'],
                            purchase_check_bom_display($mrows),
                            [
                                'search'     => $search,
                                'fold_id'    => $foldCount > 0 ? $foldId : '',
                                'fold_count' => $foldCount,
                            ]
                        );
                        foreach ($mrows as $m) {
                            echo purchase_check_part_row_html(
                                $p,
                                $m['qty'] ?? null,
                                $m['bom'] ?? null,
                                [
                                    'search'     => $search,
                                    'class'      => 'pc-more',
                                    'stock'      => false,
                                    'model_name' => (string) ($m['name'] ?? ''),
                                    'machines'   => (int) ($m['machines'] ?? 0),
                                    'models_of'  => $foldId,
                                    'hidden'     => true,
                                ]
                            );
                        }
                    endforeach; ?>
                    <tr id="pc-empty-row" hidden><td colspan="6" class="text-center text-muted" style="padding:22px">ไม่พบรายการที่ตรงกับคำค้น</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tabIn === 'suppliers'): ?>
    <?php if (!$suppliers): ?>
    <div class="card"><p class="text-muted" style="margin:0;padding:8px 0">ไม่มีการเบิกผลิตตามเงื่อนไขนี้</p></div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrap table-wrap-fold">
            <table class="pc-table pc-table-fixed">
                <?= purchase_check_colgroup_html() ?>
                <?= purchase_check_thead_html() ?>
                <tbody>
                <?php foreach ($suppliers as $si => $sup):
                    $search = mb_strtolower(
                        $sup['name'] . ' ' . implode(' ', array_column($sup['parts'], 'name')) . ' '
                        . implode(' ', array_column($sup['parts'], 'code')),
                        'UTF-8'
                    );
                    $needBits = [];
                    if ((int) $sup['order'] > 0) {
                        $needBits[] = formatNumber($sup['order']) . ' ควรสั่ง';
                    }
                    if ((int) $sup['near'] > 0) {
                        $needBits[] = formatNumber($sup['near']) . ' ใกล้ขั้นต่ำ';
                    }
                    $gid = 's' . $si;
                    echo purchase_check_group_row_html(
                        (string) $sup['name'],
                        formatNumber(count($sup['parts'])) . ' อะไหล่' . ($needBits ? ' · ' . implode(' · ', $needBits) : ''),
                        $search,
                        $gid
                    );
                    foreach ($sup['parts'] as $p) {
                        echo purchase_check_part_row_html(
                            $p,
                            $p['used'],
                            purchase_check_bom_display($p['models'] ?? []),
                            ['search' => $search, 'group' => $gid]
                        );
                    }
                endforeach; ?>
                <tr id="pc-empty-row" hidden><td colspan="6" class="text-center text-muted" style="padding:22px">ไม่พบรายการที่ตรงกับคำค้น</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <?php if (!$models): ?>
    <div class="card"><p class="text-muted" style="margin:0;padding:8px 0">ไม่มีการเบิกผลิตในช่วงนี้</p></div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrap table-wrap-fold">
            <table class="pc-table pc-table-fixed">
                <?= purchase_check_colgroup_html() ?>
                <?= purchase_check_thead_html() ?>
                <tbody>
                <?php foreach ($models as $mi => $mod):
                    $search = mb_strtolower(
                        $mod['name'] . ' ' . implode(' ', array_column($mod['parts'], 'name')) . ' '
                        . implode(' ', array_column($mod['parts'], 'code')),
                        'UTF-8'
                    );
                    $gid = 'm' . $mi;
                    echo purchase_check_group_row_html(
                        (string) $mod['name'],
                        formatNumber($mod['machines']) . ' เครื่อง · ' . formatNumber(count($mod['parts'])) . ' อะไหล่',
                        $search,
                        $gid
                    );
                    foreach ($mod['parts'] as $mp) {
                        $src = $partById[(int) $mp['part_id']] ?? [
                            'name'          => $mp['name'],
                            'code'          => $mp['code'],
                            'unit'          => $mp['unit'] ?? 'ชิ้น',
                            'stock'         => null,
                            'min'           => 0,
                            'status'        => 'ok',
                            'icon_path'     => '',
                            'purchase_link' => '',
                            'supplier'      => '',
                        ];
                        $src['name'] = (string) $mp['name'];
                        $src['code'] = (string) $mp['code'];
                        $src['unit'] = (string) ($mp['unit'] ?? $src['unit'] ?? 'ชิ้น');
                        echo purchase_check_part_row_html(
                            $src,
                            $mp['qty'],
                            $mp['bom'] ?? null,
                            ['search' => $search, 'group' => $gid]
                        );
                    }
                endforeach; ?>
                <tr id="pc-empty-row" hidden><td colspan="6" class="text-center text-muted" style="padding:22px">ไม่พบรายการที่ตรงกับคำค้น</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('pc-search');
    var items = document.querySelectorAll('[data-pc-search]');
    var empty = document.getElementById('pc-empty-row');
    var foldAll = document.getElementById('pc-fold-all');

    function query() {
        return input ? input.value.trim().toLowerCase() : '';
    }

    function parentOf(id) {
        return document.querySelector('[data-pc-models="' + id + '"]');
    }

    function isOpen(id) {
        var p = parentOf(id);
        return !!(p && p.getAttribute('data-pc-open') === '1');
    }

    function setOpen(id, open) {
        var p = parentOf(id);
        if (!p) return;
        p.setAttribute('data-pc-open', open ? '1' : '0');
        var btn = p.querySelector('.pc-fold');
        if (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', (open ? 'ซ่อนรุ่นเครื่อง ' : 'แสดงรุ่นเครื่อง ') + (btn.textContent || '').trim());
        }
    }

    function apply() {
        var q = query();
        var shown = 0;
        var groupShow = {};
        items.forEach(function (el) {
            if (el.classList.contains('pc-group')) return;
            var hit = !q || (el.getAttribute('data-pc-search') || '').indexOf(q) !== -1;
            var of = el.getAttribute('data-pc-models-of');
            var foldHide = !!(of && !q && !isOpen(of));
            el.hidden = !hit || foldHide;
            if (!el.hidden) {
                shown++;
                var g = el.getAttribute('data-pc-group');
                if (g) groupShow[g] = true;
            }
        });
        items.forEach(function (el) {
            if (!el.classList.contains('pc-group')) return;
            var g = el.getAttribute('data-pc-group') || '';
            var nameHit = !q || (el.getAttribute('data-pc-search') || '').indexOf(q) !== -1;
            var hit = !q || groupShow[g] || nameHit;
            el.hidden = !hit;
            if (q && nameHit) {
                items.forEach(function (row) {
                    if (row.getAttribute('data-pc-group') === g && !row.classList.contains('pc-group') && row.hidden) {
                        row.hidden = false;
                        shown++;
                    }
                });
            }
        });
        if (empty) empty.hidden = shown !== 0;
        if (foldAll) {
            var anyClosed = !!document.querySelector('[data-pc-models][data-pc-open="0"]');
            foldAll.textContent = anyClosed ? 'ขยายทุกรุ่น' : 'ย่อทุกรุ่น';
            foldAll.setAttribute('data-pc-next', anyClosed ? '1' : '0');
        }
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.pc-fold');
        if (btn) {
            ev.preventDefault();
            ev.stopPropagation();
            var id = btn.getAttribute('data-pc-fold') || '';
            setOpen(id, btn.getAttribute('aria-expanded') !== 'true');
            apply();
            return;
        }
        if (foldAll && ev.target === foldAll) {
            var next = foldAll.getAttribute('data-pc-next') !== '0';
            document.querySelectorAll('[data-pc-models]').forEach(function (p) {
                setOpen(p.getAttribute('data-pc-models') || '', next);
            });
            apply();
        }
    });

    if (input) input.addEventListener('input', apply);
})();
</script>

<?php parts_photo_modal_html(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
