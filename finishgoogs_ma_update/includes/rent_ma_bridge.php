<?php
/**
 * rent_ma_bridge.php
 * ────────────────────────────────────────────────────────────────────────────────
 * เชื่อมระบบเช่า (biton_leasing) กับหน้าบันทึก MA และโปรไฟล์เครื่อง — อ่านคิวรอ MA / สถานะเช่า
 * fail-soft: เช่าล่มแล้วหน้า MA ยังใช้ได้ ไม่ die
 * ────────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/rent_product_name_map.php';

/**
 * แปลง serial ให้เทียบกันได้
 *
 * @param string $sn หมายเลขสินค้า
 * @return string
 */
function rent_normalize_sn($sn)
{
    return strtoupper(trim((string)$sn));
}

/**
 * แปลง pro_date จากระบบเช่าเป็นวันที่ผลิต (produced_at) สำหรับลงทะเบียนผลิต
 *
 * @param mixed $proDate ค่า pro_date จาก tbl_product (วันที่บันทึกเข้าระบบเช่า)
 * @return string Y-m-d
 */
function rent_lease_produced_date($proDate)
{
    $proDate = trim((string)$proDate);
    if ($proDate === '' || $proDate === '0000-00-00') {
        return date('Y-m-d');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $proDate);
    if ($dt && $dt->format('Y-m-d') === $proDate) {
        return $proDate;
    }
    $ts = strtotime($proDate);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }
    return date('Y-m-d');
}

/**
 * ป้ายสถานะเครื่องในผลิต (สำหรับแสดงในคิวเช่า)
 *
 * @param string $status ค่า status จาก assets
 * @return string
 */
function rent_asset_status_label($status)
{
    static $map = [
        'new'    => 'ใหม่ (คลัง)',
        'rental' => 'เครื่องเช่า',
        'spare'  => 'เครื่องสำรอง',
    ];
    $status = (string)$status;
    return $map[$status] ?? $status;
}

/**
 * แยกหมายเลขสินค้าจากข้อความ (บรรทัด / ลูกน้ำ / ช่องว่าง)
 *
 * @param string $raw
 * @return array<int,string>
 */
function rent_parse_serial_list($raw)
{
    $parts = preg_split('/[\s,;]+/u', (string)$raw);
    $out = [];
    $seen = [];
    foreach ($parts as $p) {
        $sn = rent_normalize_sn($p);
        if ($sn === '' || isset($seen[$sn])) {
            continue;
        }
        $seen[$sn] = true;
        $out[] = $sn;
    }
    return $out;
}

/**
 * query ฐานเช่าแบบไม่ die
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return array{ok:bool, error?:string, result?:mysqli_result, insert_id?:int}
 */
function rent_q_try($sql, $types = '', $params = [])
{
    $conn = dbLeasing();
    if (!$conn) {
        return ['ok' => false, 'error' => dbLeasingError()];
    }
    $st = $conn->prepare($sql);
    if ($st === false) {
        return ['ok' => false, 'error' => $conn->error ?: 'prepare ระบบเช่าไม่สำเร็จ'];
    }
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    if (!$st->execute()) {
        $err = $st->error;
        $st->close();
        return ['ok' => false, 'error' => $err ?: 'execute ระบบเช่าไม่สำเร็จ'];
    }
    $res = $st->get_result();
    $insertId = (int)$conn->insert_id;
    if ($res === false) {
        $st->close();
        return ['ok' => true, 'insert_id' => $insertId];
    }
    return ['ok' => true, 'result' => $res, 'insert_id' => $insertId];
}

/**
 * หาเครื่องในทะเบียนผลิตจาก S/N
 *
 * @param string $sn
 * @return array<string,mixed>|null
 */
function rent_find_asset_by_sn($sn)
{
    $sn = rent_normalize_sn($sn);
    if ($sn === '') {
        return null;
    }
    try {
        return qr(
            'SELECT a.id, a.asset_code, a.factory_serial, a.product_id, a.status, p.name pname
             FROM assets a JOIN products p ON p.id = a.product_id
             WHERE a.asset_code = ? OR a.factory_serial = ?
             LIMIT 1',
            'ss',
            [$sn, $sn]
        )->fetch_assoc() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * อาการที่แจ้งของเครื่องที่ส่งเข้า MA — อ่านจากระบบเช่า
 *
 * ระบบเช่าไม่มีช่อง "อาการเสีย" ตรง ๆ ที่กรอกครบ:
 *   tbl_product.pro_remarks        มีแค่ 64/777 แถว และเก็บเลข IMEI ไม่ใช่อาการ — ไม่ใช้
 *   tbl_rent_product.p_remarks_claim  ครอบคลุม 495/777 (64%) และเป็นข้อความอาการจริง
 *                                     เช่น "ชาร์จแบต ไม่เข้า" "จอหลุด" "ไม่อ่าน smartcard"
 *   tbl_rent_product.p_remarks        ใช้เป็นตัวสำรองเมื่อไม่มีหมายเหตุเคลม
 * ระบบซ่อม (biton_maintenance) ไม่มีงานของเครื่องเช่าพวกนี้เลย (ตรวจแล้ว 0 จาก 777) จึงไม่ดึงจากที่นั่น
 *
 * หมายเหตุ: ข้อความบางรายการเป็นเรื่องสัญญา ไม่ใช่อาการ (เช่น "ต่อสัญญาปีที่ 2")
 * เพราะฝั่งระบบเช่าใช้ช่องเดียวกันบันทึกทั้งสองเรื่อง — แสดงตามที่เขาบันทึกไว้ ไม่ตีความเอง
 *
 * @param array<int,string> $sns S/N ที่ normalize แล้ว
 * @return array<string,string> sn => อาการที่แจ้ง
 */
function rent_wait_ma_symptoms(array $sns): array
{
    $sns = array_values(array_unique(array_filter(array_map('rent_normalize_sn', $sns))));
    if (!$sns) {
        return [];
    }
    $out = [];
    // แบ่งเป็นชุดละ 500 กัน IN(...) ยาวเกิน
    foreach (array_chunk($sns, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $q = rent_q_try(
            "SELECT p_sn, p_remarks_claim, p_remarks FROM tbl_rent_product
             WHERE p_sn IN ($ph)
               AND ((p_remarks_claim IS NOT NULL AND TRIM(p_remarks_claim) <> '')
                 OR (p_remarks IS NOT NULL AND TRIM(p_remarks) <> ''))
             ORDER BY p_id ASC",
            str_repeat('s', count($chunk)),
            $chunk
        );
        if (!$q['ok'] || empty($q['result'])) {
            continue;
        }
        // เรียง p_id จากน้อยไปมาก แถวหลังทับแถวหน้า → ได้สัญญาล่าสุดของ S/N นั้น
        while ($r = $q['result']->fetch_assoc()) {
            $sn = rent_normalize_sn($r['p_sn'] ?? '');
            if ($sn === '') {
                continue;
            }
            $v = trim((string) ($r['p_remarks_claim'] ?? ''));
            if ($v === '') {
                $v = trim((string) ($r['p_remarks'] ?? ''));
            }
            if ($v !== '') {
                $out[$sn] = $v;
            }
        }
    }
    return $out;
}

/**
 * ดึงคิวสินค้าสถานะ MA จากระบบเช่า พร้อมจับคู่ทะเบียนผลิต
 *
 * @param int $limit
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>}
 */
function rent_wait_ma_queue($limit = 2000)
{
    $limit = max(1, min(5000, (int)$limit));
    $q = rent_q_try(
        'SELECT pro_name, pro_sn, pro_status, pro_date FROM tbl_product WHERE pro_status = ? ORDER BY pro_name ASC, pro_sn ASC LIMIT ' . $limit,
        's',
        ['MA']
    );
    if (!$q['ok']) {
        return ['ok' => false, 'error' => (string)($q['error'] ?? dbLeasingError()), 'rows' => []];
    }
    $rows = [];
    $sns = [];
    if (!empty($q['result'])) {
        while ($r = $q['result']->fetch_assoc()) {
            $sn = rent_normalize_sn($r['pro_sn'] ?? '');
            if ($sn === '') {
                continue;
            }
            $rows[] = [
                'pro_name' => (string)$r['pro_name'],
                'pro_sn'   => $sn,
                'pro_date' => (string)($r['pro_date'] ?? ''),
                'asset'    => null,
            ];
            $sns[$sn] = true;
        }
    }
    $snList = array_keys($sns);
    $assetMap = [];
    if ($snList) {
        $ph = implode(',', array_fill(0, count($snList), '?'));
        $types = str_repeat('s', count($snList) * 2);
        $params = array_merge($snList, $snList);
        try {
            $res = qr(
                "SELECT a.id, a.asset_code, a.factory_serial, a.product_id, a.status, p.name pname
                 FROM assets a JOIN products p ON p.id = a.product_id
                 WHERE a.asset_code IN ($ph) OR a.factory_serial IN ($ph)",
                $types,
                $params
            );
            while ($a = $res->fetch_assoc()) {
                $code = rent_normalize_sn($a['asset_code'] ?? '');
                $fs = rent_normalize_sn($a['factory_serial'] ?? '');
                if ($code !== '') {
                    $assetMap[$code] = $a;
                }
                if ($fs !== '') {
                    $assetMap[$fs] = $a;
                }
            }
        } catch (Throwable $e) {
            // คิวเช่ายังแสดงได้ แม้จับคู่ผลิตพลาด
        }
    }
    $symptoms = [];
    if ($snList) {
        try {
            $symptoms = rent_wait_ma_symptoms($snList);
        } catch (Throwable $e) {
            $symptoms = [];   // คิวยังแสดงได้แม้อ่านอาการไม่สำเร็จ
        }
    }
    foreach ($rows as $i => $row) {
        $rows[$i]['asset'] = $assetMap[$row['pro_sn']] ?? null;
        $rows[$i]['symptom'] = $symptoms[$row['pro_sn']] ?? '';
    }
    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

/**
 * คิวรอ MA แบบ cache ต่อ request (ลด query ซ้ำ)
 *
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>}
 */
function rent_wait_ma_queue_cached($forceRefresh = false)
{
    static $cache = null;
    if ($forceRefresh) {
        $cache = null;
        rent_wait_ma_rent_name_product_map(true);
    }
    if ($cache === null) {
        $cache = rent_wait_ma_queue();
    }
    return $cache;
}

/**
 * แยกคิวรอ MA ของรุ่นหนึ่ง — ลงทะเบียนแล้ว / ยังไม่ลงทะเบียน
 *
 * @param int $productId
 * @return array{ok:bool, error:string, matched:array<int,array<string,mixed>>, unmatched:array<int,array<string,mixed>>}
 */
function rent_queue_rows_for_product($productId)
{
    $productId = (int)$productId;
    $empty = ['ok' => true, 'error' => '', 'matched' => [], 'unmatched' => []];
    if ($productId <= 0) {
        return $empty;
    }
    $product = qr('SELECT id, name FROM products WHERE id=? AND is_active=1', 'i', [$productId])->fetch_assoc();
    if (!$product) {
        return ['ok' => false, 'error' => 'ไม่พบรุ่นสินค้า', 'matched' => [], 'unmatched' => []];
    }
    $q = rent_wait_ma_queue_cached();
    if (!$q['ok']) {
        return ['ok' => false, 'error' => (string)$q['error'], 'matched' => [], 'unmatched' => []];
    }
    $matched = [];
    $unmatched = [];
    foreach ($q['rows'] as $row) {
        if (!empty($row['asset']) && (int)$row['asset']['product_id'] === $productId) {
            $matched[] = $row;
            continue;
        }
        if (empty($row['asset']) && rent_product_name_matches($row['pro_name'], $product['name'])) {
            $unmatched[] = $row;
        }
    }
    return ['ok' => true, 'error' => '', 'matched' => $matched, 'unmatched' => $unmatched];
}

/**
 * นับคิวรอ MA ต่อรุ่นสินค้า (ลงทะเบียนแล้ว + ชื่อตรงรุ่นแต่ยังไม่ลงทะเบียน)
 *
 * @return array{ok:bool, error:string, counts:array<int,int>, total_matched:int}
 */
function rent_wait_ma_counts_by_product()
{
    $q = rent_wait_ma_queue_cached();
    if (!$q['ok']) {
        return ['ok' => false, 'error' => $q['error'], 'counts' => [], 'total_matched' => 0];
    }
    $counts = [];
    foreach ($q['rows'] as $row) {
        if (!empty($row['asset'])) {
            $pid = (int)$row['asset']['product_id'];
        } else {
            $pid = rent_product_id_for_rent_name($row['pro_name']);
            if ($pid <= 0) {
                continue;
            }
        }
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
    }
    return [
        'ok'            => true,
        'error'         => '',
        'counts'        => $counts,
        'total_matched' => array_sum($counts),
    ];
}

/**
 * อ่านข้อมูล S/N ที่เสื่อมสภาพไม่สำเร็จ (ไม่ลบ session — ใช้ร่วมกับ consume หลัง render)
 *
 * @return array{serials:string, remark:string, errors:array<int,string>, failed_items:array<int,array{sn:string,reason:string}>, success:int, fail:int}|null
 */
function rent_bulk_retire_pending_peek()
{
    static $state = null;
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        if (isset($_SESSION['bulk_retire_pending']) && is_array($_SESSION['bulk_retire_pending'])) {
            $state = $_SESSION['bulk_retire_pending'];
        }
    }
    return $state;
}

/**
 * ลบข้อมูล pending หลังบันทึกสำเร็จครบทุกรายการ
 *
 * @return void
 */
function rent_bulk_retire_pending_clear()
{
    unset($_SESSION['bulk_retire_pending']);
}

/**
 * เก็บ S/N ที่เสื่อมสภาพไม่สำเร็จไว้ใน session สำหรับแสดงในฟอร์มรอบถัดไป
 *
 * @param array{serials:string, remark:string, errors?:array<int,string>, failed_items?:array<int,array{sn:string,reason:string}>, success:int, fail:int} $data
 * @return void
 */
function rent_bulk_retire_pending_save(array $data)
{
    $_SESSION['bulk_retire_pending'] = $data;
}

/**
 * รวมข้อความสาเหตุที่เสื่อมสภาพไม่สำเร็จ
 *
 * @param array<int,string> $parts ข้อความจากขั้นตอนก่อนหน้า
 * @param string $extraReason ข้อความจากขั้นตอนที่ล้มเหลว
 * @return string
 */
function rent_bulk_retire_fail_reason(array $parts, $extraReason = '')
{
    $reasonParts = $parts;
    $extraReason = trim((string)$extraReason);
    if ($extraReason !== '') {
        $reasonParts[] = $extraReason;
    }
    $reasonParts = array_values(array_filter($reasonParts, static function ($p) {
        return trim((string)$p) !== '';
    }));
    return $reasonParts ? implode(' · ', $reasonParts) : 'บันทึกไม่สำเร็จ';
}

/**
 * HTML ตารางสาเหตุที่เสื่อมสภาพไม่สำเร็จ
 *
 * @param array<int,array{sn:string,reason:string}> $items
 * @param int $maxHeight ความสูงสูงสุดของกล่อง scroll (px)
 * @return string
 */
function rent_bulk_retire_fail_table_html(array $items, $maxHeight = 260)
{
    if (!$items) {
        return '';
    }
    ob_start();
    ?>
    <div class="bulk-retire-fail-wrap" style="max-height:<?= (int)$maxHeight ?>px; overflow:auto; border:1px solid #f0bcbc; border-radius:6px; background:#fff8f8">
      <table class="tbl bulk-retire-fail-tbl" style="margin:0; font-size:13px">
        <thead>
          <tr>
            <th style="width:34%">หมายเลขสินค้า</th>
            <th>สาเหตุที่ไม่สำเร็จ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item) { ?>
          <tr>
            <td><code><?= h((string)($item['sn'] ?? '')) ?></code></td>
            <td><?= h((string)($item['reason'] ?? '')) ?></td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * แบนเนอร์สรุปรายการเสื่อมสภาพที่ไม่สำเร็จ (แสดงด้านบนหน้า MA)
 *
 * @return string
 */
function rent_bulk_retire_pending_alert_html()
{
    if (!function_exists('can') || !can('ma')) {
        return '';
    }
    $pending = rent_bulk_retire_pending_peek();
    if (!is_array($pending) || trim((string)($pending['serials'] ?? '')) === '') {
        return '';
    }
    $items = rent_bulk_retire_pending_items($pending);
    if (!$items) {
        return '';
    }
    $success = (int)($pending['success'] ?? 0);
    $fail = (int)($pending['fail'] ?? 0);
    return '<div class="flash flash-err" style="margin:0 0 14px">'
        . '<div style="font-weight:600; margin-bottom:6px">เสื่อมสภาพไม่สำเร็จ ' . number_format($fail) . ' รายการ'
        . ($success > 0 ? ' · สำเร็จแล้ว ' . number_format($success) . ' รายการ' : '')
        . '</div>'
        . '<p class="muted" style="margin:0 0 8px">หมายเลขที่ไม่สำเร็จยังอยู่ในช่องกรอกด้านล่าง — แก้ไขตามสาเหตุแล้วกดบันทึกอีกครั้ง</p>'
        . rent_bulk_retire_fail_table_html($items, 320)
        . '</div>';
}

/**
 * แปลงข้อมูล pending เป็นรายการ {sn, reason}
 *
 * @param array<string,mixed> $pending
 * @return array<int,array{sn:string,reason:string}>
 */
function rent_bulk_retire_pending_items(array $pending)
{
    if (!empty($pending['failed_items']) && is_array($pending['failed_items'])) {
        $items = [];
        foreach ($pending['failed_items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sn = rent_normalize_sn((string)($row['sn'] ?? ''));
            $reason = trim((string)($row['reason'] ?? ''));
            if ($sn === '') {
                continue;
            }
            $items[] = ['sn' => $sn, 'reason' => $reason !== '' ? $reason : 'บันทึกไม่สำเร็จ'];
        }
        if ($items) {
            return $items;
        }
    }
    $items = [];
    $errors = !empty($pending['errors']) && is_array($pending['errors']) ? $pending['errors'] : [];
    foreach ($errors as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(.+?)\s*—\s*(.+)$/u', $line, $m)) {
            $items[] = ['sn' => rent_normalize_sn($m[1]), 'reason' => trim($m[2])];
        } else {
            $items[] = ['sn' => '', 'reason' => $line];
        }
    }
    return $items;
}

/**
 * ปุ่มเปิด/ปิดฟอร์มเสื่อมสภาพหลาย S/N
 *
 * @return string
 */
function rent_bulk_retire_btn_html()
{
    if (!function_exists('can') || !can('ma')) {
        return '';
    }
    $pending = rent_bulk_retire_pending_peek();
    $hasPending = is_array($pending) && trim((string)($pending['serials'] ?? '')) !== '';
    $label = $hasPending ? '✕ ปิดฟอร์มเสื่อมสภาพ' : '➕ บันทึกเสื่อมสภาพหลาย S/N';
    return '<button type="button" class="btn btn-retire" id="retire-bulk-btn" aria-expanded="' . ($hasPending ? 'true' : 'false') . '"'
        . ' onclick="rentBulkRetireToggle()">'
        . h($label) . '</button>';
}

/**
 * JavaScript เปิด/ปิดฟอร์ม bulk retire (แสดงครั้งเดียวต่อหน้า)
 *
 * @return string
 */
function rent_bulk_retire_ui_script_html()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return <<<'HTML'
<script>
function rentBulkRetireToggle() {
  var wrap = document.getElementById('retire-bulk-wrap');
  var btn = document.getElementById('retire-bulk-btn');
  if (!wrap || !btn) return;
  var willOpen = wrap.hidden;
  wrap.hidden = !willOpen;
  btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  btn.textContent = willOpen ? '✕ ปิดฟอร์มเสื่อมสภาพ' : '➕ บันทึกเสื่อมสภาพหลาย S/N';
}
</script>
HTML;
}

/**
 * ฟอร์มเสื่อมสภาพหลาย S/N (ซ่อนจนกว่าจะกดปุ่ม)
 *
 * @param int $productId รหัสรุ่น (ใช้ redirect หลังเคลียร์รายการค้าง)
 * @return string
 */
function rent_bulk_retire_panel_html($productId = 0)
{
    if (!function_exists('can') || !can('ma')) {
        return '';
    }
    $pending = rent_bulk_retire_pending_peek();
    $hasPending = is_array($pending) && trim((string)($pending['serials'] ?? '')) !== '';
    $serialsVal = $hasPending ? (string)$pending['serials'] : '';
    $remarkVal = $hasPending ? (string)($pending['remark'] ?? '') : '';
    $failedItems = $hasPending ? rent_bulk_retire_pending_items($pending) : [];
    $pendingSuccess = $hasPending ? (int)($pending['success'] ?? 0) : 0;
    $pendingFail = $hasPending ? (int)($pending['fail'] ?? 0) : 0;
    ob_start();
    ?>
    <div id="retire-bulk-wrap"<?= $hasPending ? '' : ' hidden' ?>>
      <form method="post" class="panel" id="bulk-retire-form" style="margin-bottom:16px" action="<?= h(BASE_URL . '/ma.php' . ($productId > 0 ? '?product=' . (int)$productId : '')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="bulk_retire_ma" value="1">
        <?php if ($productId > 0) { ?>
        <input type="hidden" name="bulk_retire_product" value="<?= (int)$productId ?>">
        <?php } ?>
        <h3 style="margin:0 0 6px; font-size:15px">บันทึกเสื่อมสภาพหลาย S/N</h3>
        <p class="muted" style="margin:0 0 8px">บรรทัดละ 1 หมายเลขสินค้า · สถานะเครื่องในผลิต = เช่า · หมายเหตุเสื่อมสภาพ · ปิดงานเช่าเป็น Asset Retirement</p>
        <?php if ($hasPending) { ?>
        <div class="flash flash-err" style="margin:0 0 10px">
          บันทึกสำเร็จ <?= number_format($pendingSuccess) ?> รายการ · ไม่สำเร็จ <?= number_format($pendingFail) ?> รายการ
          — หมายเลขที่ไม่สำเร็จยังอยู่ในช่องด้านล่าง แก้ไขแล้วกดบันทึกอีกครั้ง
        </div>
        <?php if ($failedItems) { ?>
        <div style="margin:0 0 10px">
          <div style="font-weight:600; margin-bottom:6px; font-size:13px">รายการที่บันทึกไม่สำเร็จ</div>
          <?= rent_bulk_retire_fail_table_html($failedItems, 220) ?>
        </div>
        <?php } ?>
        <?php } ?>
        <label for="bulk_serials">หมายเลขสินค้า</label>
        <textarea name="bulk_serials" id="bulk_serials" rows="6" class="full" placeholder="BS22120047&#10;BS22120048" required><?= h($serialsVal) ?></textarea>
        <label for="bulk_remark" style="margin-top:8px; display:block">หมายเหตุรวม</label>
        <textarea name="bulk_remark" id="bulk_remark" rows="2" class="full" placeholder="เสื่อมสภาพ — รายละเอียดเพิ่มเติม"><?= h($remarkVal) ?></textarea>
        <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:8px; align-items:center">
          <button type="submit" class="btn btn-retire" onclick="return confirm('บันทึกเสื่อมสภาพตาม S/N ที่กรอก?');">บันทึกเสื่อมสภาพ</button>
          <button type="submit" class="btn" name="bulk_retire_clear_ma" value="1" formnovalidate
            onclick="return confirm('ล้างรายการที่ค้างและเริ่มกรอกใหม่?');">เคลียร์รายการ</button>
        </div>
      </form>
    </div>
    <?= rent_bulk_retire_ui_script_html() ?>
    <?php
    return ob_get_clean();
}

/**
 * HTML ปุ่ม + ฟอร์มเสื่อมสภาพหลาย S/N (ใช้หน้าเลือกรุ่น)
 *
 * @return string
 */
function rent_bulk_retire_form_html()
{
    if (!function_exists('can') || !can('ma')) {
        return '';
    }
    return '<div style="margin-bottom:10px">' . rent_bulk_retire_btn_html() . '</div>' . rent_bulk_retire_panel_html();
}
function rent_close_wait_ma($sn, $status, $remarks, $user, $dateYmd = '')
{
    $sn = rent_normalize_sn($sn);
    if ($sn === '') {
        return ['ok' => false, 'code' => 'empty', 'message' => 'ไม่มีหมายเลขสินค้า'];
    }
    if (!in_array($status, ['finished goods', 'Asset Retirement'], true)) {
        return ['ok' => false, 'code' => 'status', 'message' => 'สถานะปิดงานเช่าไม่ถูกต้อง'];
    }
    $conn = dbLeasing();
    if (!$conn) {
        return ['ok' => false, 'code' => 'db', 'message' => dbLeasingError()];
    }
    if ($dateYmd === '') {
        $dateYmd = date('Y-m-d');
    }
    $remarks = (string)$remarks;
    $user = (string)$user;
    $productName = '';
    $pId = 0;

    $found = rent_q_try(
        'SELECT pro_name, pro_status FROM tbl_product WHERE pro_sn = ? LIMIT 1',
        's',
        [$sn]
    );
    if (!$found['ok']) {
        return ['ok' => false, 'code' => 'sql', 'message' => (string)($found['error'] ?? 'อ่านสินค้าเช่าไม่สำเร็จ')];
    }
    $prod = (!empty($found['result'])) ? $found['result']->fetch_assoc() : null;
    if (!$prod) {
        return ['ok' => false, 'code' => 'not_found', 'message' => 'ไม่พบ S/N ' . $sn . ' ในระบบเช่า'];
    }
    // เสื่อมสภาพต้องสั่งได้แม้เครื่องจะออกจากคิวรอ MA ไปแล้ว — เดิมข้ามเงียบ ๆ ทำให้ระบบเช่ายังขึ้น
    // "คลังพร้อมเช่า" แล้ว cron sync ก็ตั้งสถานะฝั่งเรากลับเป็นเครื่องใหม่ทุกคืน กดกี่ครั้งก็ไม่ติด
    $currentStatus = trim((string)$prod['pro_status']);
    if ($currentStatus !== 'MA') {
        if ($status !== 'Asset Retirement') {
            // ปิดงานเป็น "คลังพร้อมเช่า" ยังทำได้เฉพาะเครื่องที่อยู่ในคิวรอ MA จริง ๆ
            return [
                'ok'      => true,
                'code'    => 'skip_not_ma',
                'message' => 'S/N ' . $sn . ' ไม่ได้อยู่ในคิวรอ MA (สถานะ ' . $currentStatus . ')',
            ];
        }
        if ($currentStatus === 'Asset Retirement') {
            return [
                'ok'      => true,
                'code'    => 'already',
                'message' => 'S/N ' . $sn . ' เป็นเสื่อมสภาพในระบบเช่าอยู่แล้ว',
            ];
        }
        if (!in_array($currentStatus, RENT_RETIRE_ALLOWED_FROM, true)) {
            // เครื่องยังอยู่กับลูกค้า/ติดเคลม — ตั้งเสื่อมสภาพตอนนี้จะทำให้สัญญาเช่ากับทะเบียนขัดกัน
            return [
                'ok'      => false,
                'code'    => 'not_retirable',
                'message' => 'S/N ' . $sn . ' ยังไม่ได้รับคืนเข้าคลัง (สถานะระบบเช่า: ' . $currentStatus
                           . ') — ต้องรับเครื่องคืนก่อนจึงจะตั้งเป็นเสื่อมสภาพได้',
            ];
        }
    }
    $productName = (string)$prod['pro_name'];

    $pidQ = rent_q_try(
        "SELECT p_id FROM tbl_rent_product WHERE p_sn = ? AND p_status = 'MA' ORDER BY p_id DESC LIMIT 1",
        's',
        [$sn]
    );
    if ($pidQ['ok'] && !empty($pidQ['result'])) {
        $pr = $pidQ['result']->fetch_assoc();
        if ($pr) {
            $pId = (int)$pr['p_id'];
        }
    }

    if (!$conn->begin_transaction()) {
        return ['ok' => false, 'code' => 'sql', 'message' => 'เริ่ม transaction ระบบเช่าไม่สำเร็จ'];
    }

    $ins = $conn->prepare(
        'INSERT INTO tbl_product_ma (ma_p_id, ma_date, ma_product, ma_sn, ma_remarks, ma_status, ma_user_add)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        $conn->rollback();
        return ['ok' => false, 'code' => 'sql', 'message' => $conn->error ?: 'เตรียมบันทึกประวัติซ่อมไม่สำเร็จ'];
    }
    $ins->bind_param('issssss', $pId, $dateYmd, $productName, $sn, $remarks, $status, $user);
    if (!$ins->execute()) {
        $err = $ins->error;
        $ins->close();
        $conn->rollback();
        return ['ok' => false, 'code' => 'sql', 'message' => $err ?: 'บันทึกประวัติซ่อมไม่สำเร็จ'];
    }
    $ins->close();

    if ($pId > 0) {
        $upRent = $conn->prepare("UPDATE tbl_rent_product SET p_status = 'received' WHERE p_id = ?");
        if (!$upRent) {
            $conn->rollback();
            return ['ok' => false, 'code' => 'sql', 'message' => $conn->error ?: 'อัปเดตสัญญาเช่าไม่สำเร็จ'];
        }
        $upRent->bind_param('i', $pId);
        if (!$upRent->execute()) {
            $err = $upRent->error;
            $upRent->close();
            $conn->rollback();
            return ['ok' => false, 'code' => 'sql', 'message' => $err ?: 'อัปเดตสัญญาเช่าไม่สำเร็จ'];
        }
        $upRent->close();
    }

    $upPro = $conn->prepare('UPDATE tbl_product SET pro_status = ? WHERE pro_sn = ? AND pro_status = ?');
    if (!$upPro) {
        $conn->rollback();
        return ['ok' => false, 'code' => 'sql', 'message' => $conn->error ?: 'อัปเดตสถานะสินค้าไม่สำเร็จ'];
    }
    // เทียบกับสถานะที่อ่านมาตอนต้น (ไม่ใช่ 'MA' ตายตัว) — ยังกันกรณีมีคนแก้สถานะแทรกระหว่างทางเหมือนเดิม
    $from = $currentStatus;
    $upPro->bind_param('sss', $status, $sn, $from);
    if (!$upPro->execute()) {
        $err = $upPro->error;
        $upPro->close();
        $conn->rollback();
        return ['ok' => false, 'code' => 'sql', 'message' => $err ?: 'อัปเดตสถานะสินค้าไม่สำเร็จ'];
    }
    if ($upPro->affected_rows < 1) {
        $upPro->close();
        $conn->rollback();
        return ['ok' => false, 'code' => 'skip_not_ma', 'message' => 'S/N ' . $sn . ' ไม่ได้อยู่ในคิวรอ MA แล้ว'];
    }
    $upPro->close();

    if (!$conn->commit()) {
        $conn->rollback();
        return ['ok' => false, 'code' => 'sql', 'message' => 'commit ระบบเช่าไม่สำเร็จ'];
    }

    $label = ($status === 'Asset Retirement') ? 'เสื่อมสภาพ' : 'finished goods';
    return [
        'ok'      => true,
        'code'    => 'closed',
        'message' => 'ปิดงานเช่าสำเร็จ — ' . $label . ' (S/N ' . $sn . ')',
    ];
}

/**
 * สถานะในระบบเช่าที่ตั้งเป็นเสื่อมสภาพได้ — ต้องเป็นเครื่องที่อยู่กับเราแล้วเท่านั้น
 *
 * @var array<int,string>
 */
const RENT_RETIRE_ALLOWED_FROM = ['MA', 'finished goods'];

/**
 * ตั้งสถานะเครื่องในทะเบียนเราเป็นเสื่อมสภาพ หลังระบบเช่ารับคำสั่งแล้ว
 *
 * ทำทันทีไม่ต้องรอ cron — ไม่งั้นกดเสื่อมสภาพแล้วสถานะยังขึ้นว่าเช่า/ใหม่ไปจนกว่าจะถึงรอบ sync
 * รอบถัดไป cron อ่านจากระบบเช่าได้ค่าเดียวกัน (Asset Retirement) จึงไม่ทับกลับ
 *
 * @param int $assetId
 * @return bool true = เปลี่ยนสถานะให้แล้ว
 */
function rent_mark_asset_retired($assetId)
{
    $assetId = (int)$assetId;
    if ($assetId <= 0) {
        return false;
    }
    $cur = qr('SELECT status FROM assets WHERE id = ? LIMIT 1', 'i', [$assetId])->fetch_assoc();
    if (!$cur || (string)$cur['status'] === 'retired') {
        return false;
    }
    q('UPDATE assets SET status = ? WHERE id = ?', 'si', ['retired', $assetId]);
    q(
        'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
        'isss',
        [$assetId, 'out', 'MA: เสื่อมสภาพ (' . (string)$cur['status'] . ' → retired)', actor_name() ?: 'system']
    );
    return true;
}

/**
 * ใส่คำว่าเสื่อมสภาพในหมายเหตุ ถ้ายังไม่มี
 *
 * @param string $remark
 * @return string
 */
function rent_ensure_retire_remark($remark)
{
    $remark = trim((string)$remark);
    if ($remark === '') {
        return 'เสื่อมสภาพ';
    }
    $remark = preg_replace('/\s+/u', ' ', $remark);
    if (mb_strpos($remark, 'เสื่อมสภาพ') === false) {
        return 'เสื่อมสภาพ · ' . preg_replace('/\s+/u', ' ', $remark);
    }
    return preg_replace('/\s+/u', ' ', $remark);
}

/**
 * ปิดงานเช่าหลังบันทึก MA เครื่องเดียว
 *
 * @param string $sn
 * @param string $action close_fg|retire|keep
 * @param string|null $replace
 * @param string|null $repair
 * @param string $fw
 * @param string $remark
 * @return array{ok:bool, code:string, message:string, action:string}
 */
function rent_apply_after_ma_save($sn, $action, $replace, $repair, $fw, $remark)
{
    $action = (string)$action;
    if (!in_array($action, ['close_fg', 'retire', 'keep'], true)) {
        $action = 'close_fg';
    }
    if ($action === 'keep') {
        return [
            'ok'      => true,
            'code'    => 'keep',
            'action'  => $action,
            'message' => 'ยังไม่ปิดคิวรอ MA ในระบบเช่า',
        ];
    }
    $status = ($action === 'retire') ? 'Asset Retirement' : 'finished goods';
    $remarks = ($action === 'retire')
        ? rent_ensure_retire_remark($remark)
        : ma_rental_office_summary($replace, $repair, $fw, $remark);
    $res = rent_close_wait_ma($sn, $status, $remarks, actor_name());
    $res['action'] = $action;
    return $res;
}

/**
 * ปิดงานเช่าหลังบันทึก MA — ลอง asset_code แล้ว fallback factory_serial
 *
 * @param array<string,mixed> $asset แถว assets
 * @param string $action close_fg|retire|keep
 * @param string|null $replace
 * @param string|null $repair
 * @param string $fw
 * @param string $remark
 * @return array{ok:bool, code:string, message:string, action:string}
 */
function rent_apply_after_ma_save_for_asset(array $asset, $action, $replace, $repair, $fw, $remark)
{
    $code = (string)($asset['asset_code'] ?? '');
    $fs = (string)($asset['factory_serial'] ?? '');
    $res = rent_apply_after_ma_save($code, $action, $replace, $repair, $fw, $remark);
    if (($res['code'] ?? '') === 'not_found'
        && rent_normalize_sn($fs) !== ''
        && rent_normalize_sn($fs) !== rent_normalize_sn($code)) {
        $res = rent_apply_after_ma_save($fs, $action, $replace, $repair, $fw, $remark);
    }
    return $res;
}

/**
 * บันทึก ma_records แบบย่อสำหรับเสื่อมสภาพ
 *
 * @param array<string,mixed> $asset
 * @param string $remark
 * @return array{ok:bool, message:string}
 */
function rent_insert_retire_ma_record(array $asset, $remark)
{
    $assetId = (int)($asset['id'] ?? 0);
    if ($assetId <= 0) {
        return ['ok' => false, 'message' => 'ไม่พบเครื่องในทะเบียนผลิต'];
    }
    $roundAlloc = ma_round_lock_acquire($assetId);
    if (!$roundAlloc['ok']) {
        return ['ok' => false, 'message' => (string)($roundAlloc['error'] ?? 'ล็อกรอบ MA ไม่สำเร็จ')];
    }
    $lockKey = (string)$roundAlloc['lock_key'];
    $round = (int)$roundAlloc['round'];
    $visited = date('Y-m-d H:i:s');
    $remark = rent_ensure_retire_remark($remark);
    $doneBy = actor_name();
    $result = 'ok';
    $machineStatus = 'rental';
    try {
        $ins = q_try(
            'INSERT INTO ma_records (asset_id,ma_round,visited_at,result,ok_items,replace_items,repair_items,fw_version,machine_status,remark,done_by)
             VALUES (?,?,?,?,NULL,NULL,NULL,NULL,?,?,?)',
            'iisssss',
            [$assetId, $round, $visited, $result, $machineStatus, $remark, $doneBy]
        );
        if (empty($ins['ok'])) {
            return ['ok' => false, 'message' => (string)($ins['error'] ?? 'บันทึก MA ผลิตไม่สำเร็จ')];
        }
        q('UPDATE assets SET status=? WHERE id=?', 'si', ['rental', $assetId]);
        return ['ok' => true, 'message' => 'บันทึก MA ผลิตแล้ว (เช่า + หมายเหตุเสื่อมสภาพ)'];
    } finally {
        ma_round_lock_release($lockKey);
    }
}

/**
 * เสื่อมสภาพหลาย S/N ในครั้งเดียว
 *
 * @param array<int,string> $serials
 * @param string $remark
 * @return array{ok:bool, success:int, fail:int, messages:array<int,string>, failed_serials:array<int,string>, failed_messages:array<int,string>, failed_items:array<int,array{sn:string,reason:string}>}
 */
function rent_bulk_retire(array $serials, $remark)
{
    $success = 0;
    $fail = 0;
    $messages = [];
    $failedSerials = [];
    $failedMessages = [];
    $failedItems = [];
    foreach ($serials as $sn) {
        $sn = rent_normalize_sn($sn);
        if ($sn === '') {
            continue;
        }
        $parts = [];
        $asset = rent_find_asset_by_sn($sn);
        if ($asset) {
            $prod = rent_insert_retire_ma_record($asset, $remark);
            $parts[] = $prod['ok'] ? $prod['message'] : ('ผลิต: ' . $prod['message']);
            if (!$prod['ok']) {
                $fail++;
                $reason = rent_bulk_retire_fail_reason($parts);
                $line = $sn . ' — ' . $reason;
                $messages[] = $line;
                $failedSerials[] = $sn;
                $failedMessages[] = $line;
                $failedItems[] = ['sn' => $sn, 'reason' => $reason];
                continue;
            }
        } else {
            $parts[] = 'ไม่พบในทะเบียนผลิต';
        }
        $rent = rent_close_wait_ma($sn, 'Asset Retirement', rent_ensure_retire_remark($remark), actor_name());
        if ($rent['ok'] && in_array((string)$rent['code'], ['closed', 'already'], true)) {
            if ($asset) {
                rent_mark_asset_retired((int)$asset['id']);
            }
            $success++;
            $parts[] = $rent['message'];
            $messages[] = $sn . ' — ' . implode(' · ', $parts);
        } elseif ($rent['ok'] && $rent['code'] === 'skip_not_ma') {
            $fail++;
            $reason = rent_bulk_retire_fail_reason($parts, (string)$rent['message']);
            $line = $sn . ' — ' . $reason;
            $messages[] = $line;
            $failedSerials[] = $sn;
            $failedMessages[] = $line;
            $failedItems[] = ['sn' => $sn, 'reason' => $reason];
        } else {
            $fail++;
            $reason = rent_bulk_retire_fail_reason($parts, (string)$rent['message']);
            $line = $sn . ' — ' . $reason;
            $messages[] = $line;
            $failedSerials[] = $sn;
            $failedMessages[] = $line;
            $failedItems[] = ['sn' => $sn, 'reason' => $reason];
        }
    }
    return [
        'ok'              => $fail === 0 && $success > 0,
        'success'         => $success,
        'fail'            => $fail,
        'messages'        => $messages,
        'failed_serials'  => $failedSerials,
        'failed_messages' => $failedMessages,
        'failed_items'    => $failedItems,
    ];
}

/**
 * ลงทะเบียน S/N จากคิวเช่าเข้าระบบผลิต (เครื่องเก่าที่ยังไม่มีใน assets)
 *
 * @param int    $productId
 * @param string $sn
 * @param string $note
 * @return array{ok:bool, asset_id?:int, message:string}
 */
function rent_register_single_asset($productId, $sn, $note = '')
{
    $productId = (int)$productId;
    $sn = rent_normalize_sn($sn);
    if ($sn === '') {
        return ['ok' => false, 'message' => 'ไม่มีหมายเลขสินค้า'];
    }
    $p = qr('SELECT id, name, code_mode FROM products WHERE id=? AND is_active=1', 'i', [$productId])->fetch_assoc();
    if (!$p) {
        return ['ok' => false, 'message' => 'ไม่พบรุ่นสินค้า'];
    }
    if (qr('SELECT id FROM assets WHERE asset_code=? OR factory_serial=?', 'ss', [$sn, $sn])->fetch_assoc()) {
        return ['ok' => false, 'message' => 'S/N ' . $sn . ' มีในทะเบียนผลิตแล้ว'];
    }
    $lease = rent_q_try('SELECT pro_name, pro_status, pro_date FROM tbl_product WHERE pro_sn = ? LIMIT 1', 's', [$sn]);
    if (!$lease['ok']) {
        return ['ok' => false, 'message' => (string)($lease['error'] ?? 'อ่านระบบเช่าไม่สำเร็จ')];
    }
    $row = (!empty($lease['result'])) ? $lease['result']->fetch_assoc() : null;
    if (!$row) {
        return ['ok' => false, 'message' => 'ไม่พบ S/N ' . $sn . ' ในระบบเช่า'];
    }
    if ((string)$row['pro_status'] !== 'MA') {
        return ['ok' => false, 'message' => 'S/N ' . $sn . ' ไม่ได้อยู่ในคิวรอ MA'];
    }
    if (!rent_product_name_matches($row['pro_name'], $p['name'])) {
        return ['ok' => false, 'message' => 'S/N ' . $sn . ' ไม่ใช่รุ่น ' . $p['name'] . ' ในระบบเช่า'];
    }
    $producedDate = rent_lease_produced_date($row['pro_date'] ?? '');
    $note = trim((string)$note);
    if ($note === '') {
        $note = 'ลงทะเบียนจากคิวรอ MA ระบบเช่า (S/N เก่า)';
        if ($producedDate !== date('Y-m-d')) {
            $note .= ' · วันที่บันทึกเช่า ' . $producedDate;
        }
    }
    if ($p['code_mode'] === 'factory_serial') {
        $r = create_produced_asset($productId, $producedDate, $sn, $note, null);
        if (isset($r['error'])) {
            return ['ok' => false, 'message' => (string)$r['error']];
        }
        $aid = (int)$r['asset_id'];
        q("UPDATE assets SET status='rental' WHERE id=?", 'i', [$aid]);
        share_upsert_asset($aid);
        return ['ok' => true, 'asset_id' => $aid, 'message' => 'ลงทะเบียน ' . $sn . ' แล้ว'];
    }
    $ins = q_try(
        "INSERT INTO assets (asset_code, factory_serial, product_id, produced_at, status, note) VALUES (?,?,?,?,?,?)",
        'ssisss',
        [$sn, $sn, $productId, $producedDate, 'rental', $note]
    );
    if (empty($ins['ok'])) {
        return ['ok' => false, 'message' => db_error_user_message((int)($ins['errno'] ?? 0), (string)($ins['error'] ?? 'บันทึกไม่สำเร็จ'))];
    }
    $aid = (int)($ins['insert_id'] ?? 0);
    if ($aid <= 0) {
        return ['ok' => false, 'message' => 'บันทึกเครื่องไม่สำเร็จ'];
    }
    share_upsert_asset($aid);
    return ['ok' => true, 'asset_id' => $aid, 'message' => 'ลงทะเบียน ' . $sn . ' แล้ว'];
}

/**
 * ลงทะเบียน S/N หลายรายการจากคิวเช่า
 *
 * @param int              $productId
 * @param array<int,string> $serials
 * @param string           $note
 * @return array{ok:bool, success:int, fail:int, messages:array<int,string>}
 */
function rent_register_assets_to_product($productId, array $serials, $note = '')
{
    $success = 0;
    $fail = 0;
    $messages = [];
    $seen = [];
    foreach ($serials as $sn) {
        $sn = rent_normalize_sn($sn);
        if ($sn === '' || isset($seen[$sn])) {
            continue;
        }
        $seen[$sn] = true;
        $res = rent_register_single_asset($productId, $sn, $note);
        if ($res['ok']) {
            $success++;
        } else {
            $fail++;
        }
        $messages[] = $res['message'];
        if ($success + $fail >= 150) {
            $messages[] = '…หยุดที่ 150 รายการต่อครั้ง';
            break;
        }
    }
    rent_wait_ma_queue_cached(true);
    return [
        'ok'       => $fail === 0 && $success > 0,
        'success'  => $success,
        'fail'     => $fail,
        'messages' => $messages,
    ];
}

/**
 * @var int จำนวนแถวที่เกินแล้วให้พับคิวไว้ก่อน — มากกว่านี้ฟอร์ม MA จะตกจอไปไกล
 */
const RENT_QUEUE_FOLD_FROM = 12;

/**
 * HTML แผงคิวรอ MA + ฟอร์มเสื่อมสภาพหลาย S/N
 *
 * @param int $productId กรองเฉพาะรุ่นนี้
 * @return string
 */
function rent_wait_ma_panel_html($productId)
{
    $productId = (int)$productId;
    if ($productId <= 0) {
        return '';
    }
    $split = rent_queue_rows_for_product($productId);
    if (!$split['ok']) {
        ob_start();
        ?>
        <div class="panel" style="margin-bottom:16px">
          <h3 class="h-with-icon" style="margin:0 0 8px"><?= function_exists('ui_icon_html') ? ui_icon_html('wrench', 15, 'h-svg') : '' ?><span>คิวรอ MA จากระบบเช่า — <?= h($split['error']) ?></span></h3>
          <p class="muted" style="margin:0">ยังเชื่อมระบบเช่าไม่ได้ · บันทึก MA ในผลิตใช้ได้ตามเดิม</p>
        </div>
        <?php
        return ob_get_clean();
    }
    $matched = $split['matched'];
    $unmatched = $split['unmatched'];
    if (!$matched && !$unmatched) {
        return '';
    }
    ob_start();
    ?>
    <div class="panel" style="margin-bottom:16px">
      <h3 class="h-with-icon" style="margin:0 0 8px"><?= function_exists('ui_icon_html') ? ui_icon_html('wrench', 15, 'h-svg') : '' ?><span>คิวรอ MA จากระบบเช่า (รุ่นนี้)</span></h3>
      <?php if ($matched) { ?>
        <?php // รุ่นที่มีเครื่องเช่าหลายร้อยเครื่องดันฟอร์ม MA ตกไปท้ายหน้ายาวมาก — ยาวเกินเกณฑ์ให้พับไว้ก่อน ?>
        <details class="rent-q" data-rent-q="matched"<?= count($matched) <= RENT_QUEUE_FOLD_FROM ? ' open' : '' ?>>
          <summary>
            <span class="rent-q-show">แสดงคิวรอ MA <?= number_format(count($matched)) ?> รายการ</span>
            <span class="rent-q-hide">ซ่อนคิวรอ MA (<?= number_format(count($matched)) ?> รายการ)</span>
          </summary>
        <p class="muted" style="margin:8px 0"><?= number_format(count($matched)) ?> รายการลงทะเบียนแล้ว · กดรหัสเครื่องเพื่อเปิดฟอร์ม MA</p>
        <div class="table-wrap" style="margin-bottom:12px">
          <table class="list" style="margin:0">
            <tr><th>S/N</th><th>สินค้า (เช่า)</th><th>ปัญหา / อาการที่แจ้ง</th><th>วันที่บันทึกเช่า</th><th>สถานะผลิต</th></tr>
            <?php foreach ($matched as $row) {
                $a = $row['asset'];
                $st = rent_asset_status_label($a['status'] ?? '');
                $sym = trim((string) ($row['symptom'] ?? ''));
                ?>
            <tr>
              <td><a href="<?= h(BASE_URL . '/ma.php?product=' . (int)$a['product_id'] . '&record=' . (int)$a['id']) ?>"><b><?= h($row['pro_sn']) ?></b></a></td>
              <td><?= h($row['pro_name']) ?></td>
              <td class="rent-q-sym"><?= $sym !== '' ? h($sym) : '<span class="muted">—</span>' ?></td>
              <td style="white-space:nowrap"><?= function_exists('dthai') ? dthai($row['pro_date'] ?? '') : h($row['pro_date'] ?? '') ?></td>
              <td class="muted">พบในทะเบียนผลิต<?= $st !== '' ? ' · ' . h($st) : '' ?></td>
            </tr>
            <?php } ?>
          </table>
        </div>
        </details>
      <?php } ?>

      <?php if ($unmatched && function_exists('can') && can('ma')) { ?>
        <h4 style="margin:12px 0 6px; font-size:14px">ยังไม่ลงทะเบียนผลิต (S/N เก่าจากระบบเช่า)</h4>
        <details class="rent-q" data-rent-q="unmatched"<?= count($unmatched) <= RENT_QUEUE_FOLD_FROM ? ' open' : '' ?>>
          <summary>
            <span class="rent-q-show">แสดงรายการที่ยังไม่ลงทะเบียน <?= number_format(count($unmatched)) ?> รายการ</span>
            <span class="rent-q-hide">ซ่อนรายการที่ยังไม่ลงทะเบียน (<?= number_format(count($unmatched)) ?> รายการ)</span>
          </summary>
        <p class="muted" style="margin:8px 0"><?= number_format(count($unmatched)) ?> รายการ · เลือกแล้วกดลงทะเบียน — วันที่ผลิตจะใช้ตามวันที่บันทึกเข้าระบบเช่า</p>
        <form method="post" onsubmit="return confirm('ลงทะเบียน S/N ที่เลือกเข้าระบบผลิต?');">
          <?= csrf_field() ?>
          <input type="hidden" name="register_rent_assets" value="1">
          <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
          <div class="table-wrap">
            <table class="list" style="margin:0">
              <tr>
                <th style="width:36px"><input type="checkbox" id="rent-reg-all" checked title="เลือกทั้งหมด"></th>
                <th>S/N</th><th>สินค้า (เช่า)</th><th>ปัญหา / อาการที่แจ้ง</th><th>วันที่บันทึกเช่า</th>
              </tr>
              <?php foreach ($unmatched as $row) { $sym = trim((string) ($row['symptom'] ?? '')); ?>
              <tr>
                <td><input type="checkbox" class="rent-reg-sn" name="register_sns[]" value="<?= h($row['pro_sn']) ?>" checked></td>
                <td><b><?= h($row['pro_sn']) ?></b></td>
                <td><?= h($row['pro_name']) ?></td>
                <td class="rent-q-sym"><?= $sym !== '' ? h($sym) : '<span class="muted">—</span>' ?></td>
                <td style="white-space:nowrap"><?= function_exists('dthai') ? dthai($row['pro_date'] ?? '') : h($row['pro_date'] ?? '') ?></td>
              </tr>
              <?php } ?>
            </table>
          </div>
          <div style="margin-top:10px">
            <button type="submit" class="btn btn-line">ลงทะเบียนทะเบียนผลิตที่เลือก</button>
          </div>
        </form>
        <script>
        (function(){
          var all = document.getElementById('rent-reg-all');
          if (!all) return;
          all.addEventListener('change', function(){
            document.querySelectorAll('.rent-reg-sn').forEach(function(el){ el.checked = all.checked; });
          });
        })();
        </script>
        </details>
      <?php } elseif ($unmatched) { ?>
        <p class="muted" style="margin:12px 0 0"><?= number_format(count($unmatched)) ?> รายการยังไม่ลงทะเบียนผลิต — ต้องมีสิทธิ์ MA</p>
      <?php } ?>
      <script>
      // จำว่าคนนี้เปิดหรือปิดไว้ — คนที่ใช้คิวนี้ทุกวันจะได้ไม่ต้องกดกางใหม่ทุกครั้ง
      (function(){
        document.querySelectorAll('details[data-rent-q]').forEach(function(d){
          var key = 'rentQueueOpen:' + d.getAttribute('data-rent-q');
          try {
            var saved = localStorage.getItem(key);
            if (saved === '1') { d.open = true; }
            else if (saved === '0') { d.open = false; }
          } catch (e) {}
          d.addEventListener('toggle', function(){
            try { localStorage.setItem(key, d.open ? '1' : '0'); } catch (e) {}
          });
        });
      })();
      </script>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * ตรวจว่าวันที่จากระบบเช่าใช้แสดงผลได้หรือไม่
 *
 * @param mixed $date
 * @return bool
 */
function rent_leasing_valid_date($date)
{
    $d = trim((string) $date);
    return $d !== '' && $d !== '0000-00-00' && strpos($d, '0000-00-00') !== 0;
}

/**
 * ข้อความนี้บอก "อาการเสียของเครื่อง" หรือเป็นแค่งานเอกสาร
 *
 * ระบบเช่าไม่มีช่องเก็บเหตุผลที่ปลดระวาง/สูญหายเลย (pro_remarks ว่างทั้งหมด) ที่พอใช้ได้
 * คือหมายเหตุการเคลมในบรรทัดสัญญา แต่ในนั้นปนสองเรื่องอยู่ — อาการเสียจริง เช่น
 * "ชาร์จไม่เข้า" "เฟืองหัก" กับงานสัญญา เช่น "ต่อสัญญาเช่า" (ซึ่งมีถึง 201 ครั้ง
 * มากที่สุดในตาราง) ถ้าเอามาโชว์ดิบ ๆ เครื่องส่วนใหญ่จะขึ้นว่า "ต่อสัญญาเช่า"
 * แล้วคนอ่านจะเข้าใจว่านั่นคือสาเหตุที่เครื่องเสีย
 *
 * ใช้วิธี deny-list ไม่ใช่ allow-list เพราะข้อความอาการเสียเป็นคำที่คนพิมพ์เองอิสระ
 * มี 53 แบบและจะมีแบบใหม่เรื่อย ๆ ส่วนคำงานเอกสารมีไม่กี่แบบและซ้ำเดิม
 *
 * @param string $text
 * @return bool
 */
function rent_leasing_is_fault_note($text)
{
    $t = trim((string) $text);
    if ($t === '' || $t === '0') {
        return false;
    }
    // บอกแค่ว่าเคลม หรือบอกแค่ชื่อสินค้า ไม่ได้บอกอาการ — เทียบทั้งสตริง ไม่ใช่ substring
    // เพราะคำว่า "เคลม" ไปโผล่กลางข้อความที่บอกอาการจริงได้
    $exact = ['-', '--', 'เคลม', 'เคลม printer', 'เคลม smc s', 'เคลม smartcard s'];
    if (in_array(mb_strtolower($t), $exact, true)) {
        return false;
    }
    $deny = [
        'ต่อสัญญา', 'รับคืน', 'มาบันทึกระบบภายหลัง', 'ไม่รายการนี้มาในชุด',
        'ซื้อต่อ', 'ซื้อใหม่ทดแทน', 'ยกเลิกใช้งาน', 'ย้ายหน่วยงาน', 'ส่งผิดที่อยู่',
        'ไม่รู้หมายเลขเครื่อง', 'เคลมไปพร้อมมือถือ',
    ];
    foreach ($deny as $d) {
        if (mb_strpos($t, $d) !== false) {
            return false;
        }
    }
    return true;
}

/**
 * อาการเสียที่เคยแจ้งไว้ — คัดจากบรรทัดสัญญาที่ผู้เรียกอ่านมาแล้ว
 *
 * รับ rows แทนที่จะ query เอง เพราะ tbl_rent_product ไม่มี index บน p_sn (วัดได้ 51 ms
 * ต่อครั้ง) และ asset_leasing_info() ก็ scan ตารางนี้ด้วยเงื่อนไขเดียวกันอยู่แล้ว
 * ยิงซ้ำอีกรอบคือจ่ายค่าเดิมสองเท่าโดยไม่ได้อะไรเพิ่ม — schema ฝั่งระบบเช่าเราไม่แก้
 *
 * @param array<int,array<string,mixed>> $rows แถวจาก tbl_rent_product (ต้องมี p_id, p_remarks, p_remarks_claim)
 * @return array<int,string>
 */
function rent_leasing_fault_notes(array $rows)
{
    $rows = is_array($rows) ? $rows : [];
    // เรียงใหม่สุดก่อน — ผู้เรียกเรียงบรรทัด active ขึ้นหน้าเพื่อเลือกสัญญาที่จะโชว์
    // ซึ่งคนละเกณฑ์กับอาการเสียที่ควรไล่ตามเวลา
    usort($rows, function ($a, $b) {
        return (int) ($b['p_id'] ?? 0) <=> (int) ($a['p_id'] ?? 0);
    });
    $notes = [];
    foreach ($rows as $row) {
        foreach ([$row['p_remarks_claim'] ?? '', $row['p_remarks'] ?? ''] as $raw) {
            $t = trim((string) $raw);
            if (rent_leasing_is_fault_note($t) && !in_array($t, $notes, true)) {
                $notes[] = $t;
            }
        }
    }
    return $notes;
}

/**
 * ประวัติงาน MA ของเครื่องจากระบบเช่า (tbl_product_ma) — ใหม่สุดขึ้นก่อน
 *
 * ตารางนี้คือสมุดบันทึกงาน MA ฝั่งระบบเช่า คนละเล่มกับระบบซ่อม (biton_maintenance)
 * ma_remarks กรอกไว้ครบทุกแถว จึงเป็นแหล่งเดียวที่บอกได้ว่าเครื่องถูกปลดระวางเพราะอะไร
 * — ช่อง pro_remarks ที่ควรเก็บเรื่องนี้ว่างทั้งตาราง
 *
 * @param string $serial S/N ที่ normalize แล้ว
 * @return array<int,array{date:string,status:string,remarks:string}>
 */
function rent_leasing_ma_history($serial)
{
    $serial = trim((string) $serial);
    if ($serial === '') {
        return [];
    }
    $q = rent_q_try(
        'SELECT ma_id, ma_date, ma_status, ma_remarks, ma_user_add FROM tbl_product_ma
         WHERE ma_sn = ? ORDER BY ma_date DESC, ma_id DESC',
        's',
        [$serial]
    );
    if (!$q['ok'] || empty($q['result'])) {
        return [];
    }
    $rows = [];
    while ($row = $q['result']->fetch_assoc()) {
        $remarks = trim((string) ($row['ma_remarks'] ?? ''));
        if ($remarks === '') {
            continue;
        }
        $rows[] = [
            'id' => (int) ($row['ma_id'] ?? 0),   // เลขอ้างอิงในระบบเช่า — ใช้ชี้ตำแหน่งตอนไปแก้ที่ต้นทาง
            'date' => trim((string) ($row['ma_date'] ?? '')),
            'status' => trim((string) ($row['ma_status'] ?? '')),
            'remarks' => $remarks,
            'by' => trim((string) ($row['ma_user_add'] ?? '')),
        ];
    }
    return $rows;
}

/**
 * แปลงประวัติ MA ระบบเช่าเป็นรายการ timeline — ไปรวมกับ board ของหน้าเครื่อง
 *
 * รับ $info ที่โหลดมาแล้ว ไม่ query ซ้ำ เพราะ asset.php เรียก asset_leasing_info()
 * ไว้ตั้งแต่ต้นหน้าอยู่แล้ว
 *
 * @param array<string,mixed> $info จาก asset_leasing_info()
 * @return array<int,array<string,mixed>>
 */
function rent_leasing_ma_timeline_items(array $info)
{
    $rows = is_array($info['ma_history'] ?? null) ? $info['ma_history'] : [];
    if (!$rows) {
        return [];
    }
    $sn = trim((string) ($info['serial'] ?? ($info['sn'] ?? '')));
    $items = [];
    foreach ($rows as $r) {
        $isRetire = trim((string) ($r['status'] ?? '')) === 'Asset Retirement';
        $body = [];
        $body[] = $isRetire
            ? '<b class="tl-rent-ma-retire">ปลดระวาง (เสื่อมสภาพ)</b>'
            : '<b>กลับเข้าคลัง</b>';
        $parts = rent_leasing_ma_note_parts((string) ($r['remarks'] ?? ''));
        if (count($parts) > 1) {
            $body[] = '<ul class="tl-checklist"><li>' . implode('</li><li>', array_map('h', $parts)) . '</li></ul>';
        } elseif ($parts) {
            $body[] = h($parts[0]);
        }
        $by = trim((string) ($r['by'] ?? ''));
        if ($by !== '') {
            $body[] = 'โดย: ' . h($by);
        }
        $items[] = [
            'd' => function_exists('timeline_dt') ? timeline_dt($r['date'] ?? '') : (string) ($r['date'] ?? ''),
            'type_key' => 'rent_ma',
            'type' => function_exists('ui_timeline_type_html') ? ui_timeline_type_html('rent_ma') : '',
            'html' => implode('<br>', $body),
            'kind' => 'rent_ma',
            'rid' => 0,
            'rent_ma_id' => (int) ($r['id'] ?? 0),
            'rent_ma_sn' => $sn,
        ];
    }
    return $items;
}

/**
 * รูปแบบ URL ระบบเช่าที่ตั้งไว้ (ว่าง = ยังไม่ได้ตั้ง / ไม่ใช่ http)
 *
 * @return string
 */
function rent_leasing_app_url(): string
{
    $u = trim((string) setting('leasing_app_url', ''));
    if ($u === '' || !preg_match('~^https?://~i', $u)) {
        return '';
    }
    return rtrim($u, '/');
}

/** ทางลัดไปหน้าประวัติเครื่องในระบบเช่า ต่อท้ายให้เองเมื่อผู้ใช้ใส่มาแค่ URL หลัก */
const RENT_LEASING_SN_PATH = '/product_history_usage.php?serial_number={sn}';

/**
 * URL เปิดเครื่องเครื่องนั้นในระบบเช่าโดยตรง
 *
 * ค่าที่ตั้งไว้ใส่ตัวแทนได้: {sn} = S/N · {ma_id} = เลขอ้างอิงแถวประวัติ MA
 * ถ้าใส่มาแค่ URL หลัก (ไม่มีตัวแทนเลย) จะต่อ path หน้าประวัติเครื่องให้เอง
 *
 * @param string $sn
 * @param int    $maId
 * @return string ว่าง = ยังตั้ง URL ไม่ได้
 */
function rent_leasing_record_url(string $sn, int $maId = 0): string
{
    $tpl = rent_leasing_app_url();
    if ($tpl === '') {
        return '';
    }
    $sn = trim($sn);
    if (strpos($tpl, '{sn}') === false && strpos($tpl, '{ma_id}') === false) {
        if ($sn === '') {
            return $tpl;   // ไม่รู้ S/N ก็ได้แค่หน้าแรก
        }
        $tpl .= RENT_LEASING_SN_PATH;
    }
    return strtr($tpl, [
        '{sn}'    => rawurlencode($sn),
        '{ma_id}' => rawurlencode((string) $maId),
    ]);
}

/**
 * ปุ่มท้ายรายการ MA ระบบเช่าใน timeline
 *
 * ระบบเช่าเป็นของทีมอื่น เราอ่านอย่างเดียว — ปุ่มนี้จึงพาไปแก้ที่ต้นทาง
 * พร้อมบอกเลขอ้างอิง (ma_id) กับ S/N ไว้ให้ค้นเจอง่าย และมีปุ่มคัดลอกให้
 *
 * @param array<string,mixed> $e รายการ timeline
 * @return string
 */
function rent_ma_source_actions_html(array $e): string
{
    $maId = (int) ($e['rent_ma_id'] ?? 0);
    $sn   = trim((string) ($e['rent_ma_sn'] ?? ''));
    $url  = rent_leasing_record_url($sn, $maId);

    $out = '<div class="tl-actions rent-ma-actions">';
    if ($url !== '') {
        $label = $sn !== '' ? 'เปิดเครื่องนี้ในระบบเช่า' : 'เปิดระบบเช่า';
        $out .= '<a class="btn btn-sm btn-line btn-with-icon" target="_blank" rel="noopener" href="'
              . h($url) . '" title="เปิดหน้าประวัติของ S/N นี้ในระบบเช่า">' . ui_btn_label('edit', $label) . ' ↗</a>';
    }
    if ($maId > 0) {
        $out .= '<button type="button" class="btn btn-sm btn-line rent-ma-copy" data-copy="' . h((string) $maId)
              . '" title="คัดลอกเลขอ้างอิงไปค้นในระบบเช่า">คัดลอกเลขอ้างอิง #' . (int) $maId . '</button>';
    }
    if ($sn !== '') {
        $out .= '<button type="button" class="btn btn-sm btn-line rent-ma-copy" data-copy="' . h($sn)
              . '" title="คัดลอก S/N ไปค้นในระบบเช่า">คัดลอก S/N</button>';
    }
    if ($url === '') {
        $out .= '<span class="muted rent-ma-hint">ตั้ง URL ระบบเช่าได้ที่ ตั้งค่าระบบ → ปรับแต่งหน้าตา</span>';
    }
    return $out . '</div>';
}

/**
 * เหตุผลที่ปลดระวาง — แถว Asset Retirement ล่าสุด
 *
 * @param array<int,array<string,mixed>> $history จาก rent_leasing_ma_history()
 * @return string
 */
function rent_leasing_ma_retire_reason(array $history)
{
    foreach ($history as $row) {
        if (trim((string) ($row['status'] ?? '')) === 'Asset Retirement') {
            return trim((string) ($row['remarks'] ?? ''));
        }
    }
    return '';
}

/**
 * คนบันทึกและวันที่ของแถวปลดระวาง — ใช้ต่อท้ายเหตุผลในการ์ด
 *
 * @param array<int,array<string,mixed>> $history จาก rent_leasing_ma_history()
 * @return array{by:string,date:string}
 */
function rent_leasing_ma_retire_meta(array $history)
{
    foreach ($history as $row) {
        if (trim((string) ($row['status'] ?? '')) === 'Asset Retirement') {
            return [
                'by' => trim((string) ($row['by'] ?? '')),
                'date' => trim((string) ($row['date'] ?? '')),
            ];
        }
    }
    return ['by' => '', 'date' => ''];
}

/**
 * แยกหมายเหตุ MA เป็นข้อ ๆ เท่าที่แยกได้อย่างปลอดภัย
 *
 * ช่างกรอกหลายข้อในช่องเดียว บางแถวคั่นด้วยช่องว่างหลายตัวหรือขึ้นบรรทัดใหม่ (59 แถว
 * แยกได้) แต่อีก 64 แถวเขียนติดกันไม่มีตัวคั่นเลย เช่น "พิมพ์ใบผ่าน OKPort type-c
 * กล่องเป็นรอยเยอะ" — พวกนั้นปล่อยเป็นก้อนเดียว ไม่เดาแยกเพราะเสี่ยงตัดผิดกลางคำ
 *
 * @param string $text
 * @return array<int,string>
 */
function rent_leasing_ma_note_parts($text)
{
    $t = trim((string) $text);
    if ($t === '') {
        return [];
    }
    $parts = preg_split('/\s{2,}|[\r\n]+/u', $t);
    $out = [];
    foreach ((array) $parts as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', (string) $p));
        // ตัวคั่นที่ค้างหัวท้ายหลังแยก — "ok  / fw 6.8" ไม่ควรได้ข้อว่า "/ fw 6.8"
        $p = trim($p, " /·,-");
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out ?: [preg_replace('/\s+/u', ' ', $t)];
}

/**
 * ป้ายสถานะเช่า (รวม pro_status + p_status) เป็นภาษาไทย
 *
 * @param string $proStatus tbl_product.pro_status
 * @param string $pStatus   tbl_rent_product.p_status ล่าสุด
 * @return string
 */
function rent_leasing_status_label($proStatus, $pStatus)
{
    $pro = trim((string) $proStatus);
    $p = trim((string) $pStatus);
    if ($p === 'active') {
        return 'ใช้งานอยู่กับลูกค้า';
    }
    if ($pro === 'MA' || $p === 'MA') {
        return 'รอซ่อม MA';
    }
    if ($pro === 'Asset Retirement') {
        return 'เสื่อมสภาพ';
    }
    if ($pro === 'claim' || $p === 'claim') {
        return 'เคลม';
    }
    if ($pro === 'Awaiting Return') {
        return 'รอส่งคืน';
    }
    if ($pro === 'finished goods') {
        return 'คลังพร้อมเช่า';
    }
    if ($pro === 'rent') {
        return 'ปล่อยเช่า (ไม่ active)';
    }
    if ($pro === 'sale') {
        return 'ขายขาด';
    }
    if ($pro === 'Lost') {
        return 'สูญหาย';
    }
    if ($p === 'received') {
        return 'รับคืนแล้ว';
    }
    if ($pro !== '') {
        return $pro;
    }
    return $p !== '' ? $p : '—';
}

/**
 * class CSS ของ badge สถานะเช่า
 *
 * @param string $proStatus
 * @param string $pStatus
 * @return string
 */
function rent_leasing_status_badge_class($proStatus, $pStatus)
{
    $pro = trim((string) $proStatus);
    $p = trim((string) $pStatus);
    if ($p === 'active') {
        return 'asset-rent-badge-active';
    }
    if ($pro === 'MA' || $p === 'MA') {
        return 'asset-rent-badge-ma';
    }
    if ($pro === 'Asset Retirement') {
        return 'asset-rent-badge-retire';
    }
    if ($pro === 'claim' || $p === 'claim') {
        return 'asset-rent-badge-claim';
    }
    if ($pro === 'Awaiting Return') {
        return 'asset-rent-badge-await';
    }
    if ($pro === 'finished goods') {
        return 'asset-rent-badge-fg';
    }
    return 'asset-rent-badge-default';
}

/**
 * สร้าง HTML badge สถานะเช่า
 *
 * @param string $proStatus
 * @param string $pStatus
 * @return string
 */
function rent_leasing_status_badge_html($proStatus, $pStatus)
{
    $cls = rent_leasing_status_badge_class($proStatus, $pStatus);
    return '<span class="badge asset-rent-status ' . h($cls) . '">'
        . h(rent_leasing_status_label($proStatus, $pStatus)) . '</span>';
}

/**
 * อ่านโปรไฟล์เช่าจาก biton_leasing สำหรับหน้า asset.php (read-only)
 *
 * @param string $assetCode     รหัสเครื่อง production
 * @param string $factorySerial factory_serial (fallback)
 * @return array<string,mixed>
 */
function asset_leasing_info($assetCode, $factorySerial = '')
{
    $out = [
        'ok' => false,
        'found' => false,
        'message' => '',
        'serial' => '',
        'product_name' => '',
        'pro_status' => '',
        'pro_date' => '',
        'pro_remarks' => '',
        'p_status' => '',
        'customer_name' => '',
        'site_id' => '',
        'site_name' => '',
        'r_startdate' => '',
        'r_enddate' => '',
        'r_po' => '',
        'r_code' => '',
        'p_date_received' => '',
        'p_remarks' => '',
        'fault_notes' => [],
        'ma_history' => [],
        'ma_retire_reason' => '',
        'status_label' => '',
        'rent_history' => [],
    ];

    $candidates = [];
    foreach ([$assetCode, $factorySerial] as $raw) {
        $sn = rent_normalize_sn($raw);
        if ($sn !== '' && !in_array($sn, $candidates, true)) {
            $candidates[] = $sn;
        }
    }
    if (!$candidates) {
        $out['message'] = 'ไม่มี S/N';
        return $out;
    }

    if (!dbLeasing()) {
        $out['message'] = dbLeasingError();
        return $out;
    }
    $out['ok'] = true;

    $product = null;
    foreach ($candidates as $sn) {
        $q = rent_q_try(
            'SELECT pro_name, pro_sn, pro_status, pro_date, pro_remarks
             FROM tbl_product WHERE pro_sn = ? LIMIT 1',
            's',
            [$sn]
        );
        if (!$q['ok']) {
            $out['ok'] = false;
            $out['message'] = (string) ($q['error'] ?? dbLeasingError());
            return $out;
        }
        if (!empty($q['result']) && ($row = $q['result']->fetch_assoc())) {
            $product = $row;
            break;
        }
    }

    if (!$product) {
        $out['message'] = 'ไม่พบ S/N ในระบบเช่า';
        return $out;
    }

    $out['found'] = true;
    $out['serial'] = trim((string) ($product['pro_sn'] ?? ''));
    $out['product_name'] = trim((string) ($product['pro_name'] ?? ''));
    $out['pro_status'] = trim((string) ($product['pro_status'] ?? ''));
    $out['pro_date'] = trim((string) ($product['pro_date'] ?? ''));
    $out['pro_remarks'] = trim((string) ($product['pro_remarks'] ?? ''));

    // ดึงทุกบรรทัดสัญญาของ S/N นี้ในนัดเดียว — แถวแรกคือบรรทัดที่เอามาแสดง (active ก่อน
    // แล้วใหม่สุด) ส่วนที่เหลือใช้ไล่อาการเสีย เครื่องหนึ่งเคลมได้หลายรอบข้ามสัญญา และรอบ
    // ที่ทำให้เครื่องถูกปลดมักไม่ใช่บรรทัดล่าสุด · ตารางนี้ไม่มี index บน p_sn จึงต้องยิงรอบเดียว
    $line = null;
    $rentRows = [];
    $lq = rent_q_try(
        'SELECT rp.p_id, rp.p_cus_id, rp.p_status, rp.p_siteid, rp.p_sitename, rp.p_remarks,
                rp.p_remarks_claim, rp.p_date_received, r.r_startdate, r.r_enddate, r.r_po, r.r_code
         FROM tbl_rent_product rp
         LEFT JOIN tbl_rent r ON rp.p_r_id = r.r_id
         WHERE rp.p_sn = ?
         ORDER BY CASE WHEN rp.p_status = ? THEN 0 ELSE 1 END, rp.p_id DESC',
        'ss',
        [$out['serial'], 'active']
    );
    if (!$lq['ok']) {
        $out['ok'] = false;
        $out['message'] = (string) ($lq['error'] ?? dbLeasingError());
        return $out;
    }
    if (!empty($lq['result'])) {
        while ($row = $lq['result']->fetch_assoc()) {
            $rentRows[] = $row;
        }
        $line = $rentRows ? $rentRows[0] : null;
    }

    if ($line) {
        $out['p_status'] = trim((string) ($line['p_status'] ?? ''));
        $out['site_id'] = trim((string) ($line['p_siteid'] ?? ''));
        $out['site_name'] = trim((string) ($line['p_sitename'] ?? ''));
        $out['p_date_received'] = trim((string) ($line['p_date_received'] ?? ''));
        $out['p_remarks'] = trim((string) ($line['p_remarks'] ?? ''));
        $out['r_startdate'] = trim((string) ($line['r_startdate'] ?? ''));
        $out['r_enddate'] = trim((string) ($line['r_enddate'] ?? ''));
        $out['r_po'] = trim((string) ($line['r_po'] ?? ''));
        $out['r_code'] = trim((string) ($line['r_code'] ?? ''));

        $cusId = trim((string) ($line['p_cus_id'] ?? ''));
        if ($cusId !== '') {
            $cq = rent_q_try('SELECT cus_name FROM tbl_customer WHERE cus_id = ? LIMIT 1', 's', [$cusId]);
            if ($cq['ok'] && !empty($cq['result']) && ($cr = $cq['result']->fetch_assoc())) {
                $out['customer_name'] = trim((string) ($cr['cus_name'] ?? ''));
            }
        }
    }

    $out['rent_history'] = rent_leasing_rent_history($rentRows);
    $out['fault_notes'] = rent_leasing_fault_notes($rentRows);
    $out['ma_history'] = rent_leasing_ma_history($out['serial']);
    $out['ma_retire_reason'] = rent_leasing_ma_retire_reason($out['ma_history']);
    $out['status_label'] = rent_leasing_status_label($out['pro_status'], $out['p_status']);
    return $out;
}

/**
 * ประวัติการเช่าของเครื่อง — ทุกบรรทัดสัญญา (tbl_rent_product) ที่ S/N นี้เคยไปอยู่ ใหม่สุดก่อน
 * ใช้แถวที่ asset_leasing_info() ดึงมาแล้ว ไม่ query ตารางใหญ่ซ้ำ · ชื่อลูกค้าดึงรอบเดียวทั้งชุด
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,string>>
 */
function rent_leasing_rent_history(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $names = [];
    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return trim((string) ($r['p_cus_id'] ?? '')); }, $rows))));
    if ($ids && dbLeasing()) {
        $in = implode(',', array_map(function ($v) { return "'" . dbLeasing()->real_escape_string($v) . "'"; }, $ids));
        $res = @dbLeasing()->query("SELECT cus_id, cus_name FROM tbl_customer WHERE cus_id IN ($in)");
        while ($res && ($x = $res->fetch_row())) {
            $names[(string) $x[0]] = trim((string) $x[1]);
        }
    }
    $d = function ($v) {
        $v = trim((string) $v);
        return ($v === '' || strpos($v, '0000-00-00') === 0) ? '' : substr($v, 0, 10);
    };
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        // บรรทัดสัญญาซ้ำเป๊ะ (สัญญา/วันที่/ไซต์/สถานะเดียวกัน) มีอยู่จริงในระบบเช่า — แสดงครั้งเดียว
        $key = implode('|', [$r['r_code'] ?? '', $r['r_startdate'] ?? '', $r['r_enddate'] ?? '', $r['p_sitename'] ?? '', $r['p_status'] ?? '']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'code'     => trim((string) ($r['r_code'] ?? '')) !== '' ? trim((string) $r['r_code']) : trim((string) ($r['r_po'] ?? '')),
            'start'    => $d($r['r_startdate'] ?? ''),
            'end'      => $d($r['r_enddate'] ?? ''),
            'returned' => $d($r['p_date_received'] ?? ''),
            'status'   => trim((string) ($r['p_status'] ?? '')),
            'customer' => $names[trim((string) ($r['p_cus_id'] ?? ''))] ?? '',
            'site'     => trim((string) ($r['p_sitename'] ?? '')),
        ];
    }
    // เช่าอยู่ตอนนี้ขึ้นก่อน แล้วเรียงตามวันเริ่มสัญญาใหม่สุด
    usort($out, function ($a, $b) {
        return [$a['status'] === 'active' ? 0 : 1, $b['start']] <=> [$b['status'] === 'active' ? 0 : 1, $a['start']];
    });
    return $out;
}

/**
 * ตารางประวัติการเช่าบนโปรไฟล์เครื่อง (หน้าตาเดียวกับ "เคยถูกยืมเป็นเครื่องสำรอง") — ไม่มีประวัติ = ''
 *
 * @param array<string,mixed> $info ผลจาก asset_leasing_info()
 * @return string
 */
function asset_leasing_rent_history_html(array $info): string
{
    $rows = $info['rent_history'] ?? [];
    if (empty($info['found']) || !$rows) {
        return '';
    }
    $labels = [
        'active' => ['กำลังเช่า', 'ma-pill-out'], 'received' => ['รับคืนแล้ว', 'ma-pill-done'], 'claim' => ['เคลม', 'ma-pill-open'],
        'Awaiting Return' => ['รอรับคืน', 'ma-pill-open'], 'MA' => ['ส่ง MA', 'ma-pill-open'], 'Lost' => ['สูญหาย', 'ma-pill-urgent'],
        'Asset Retirement' => ['ปลดระวาง', 'ma-pill-urgent'],
    ];
    $be = function ($iso) {
        return $iso !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '/' . $m[2] . '/' . ((int) $m[1] + 543) : '';
    };
    $now = $rows[0]['status'] === 'active' ? $rows[0] : null;
    $total = count($rows);
    $customers = count(array_unique(array_filter(array_column($rows, 'customer'))));

    $out = '<section class="ma-repair-section">';
    $out .= '<h2 class="h-with-icon">' . ui_icon_html('history', 18) . 'ประวัติการเช่า (ระบบเช่า)'
        . ($now ? ' <span class="ma-pill ma-pill-out">ตอนนี้เช่าอยู่</span>' : '') . '</h2>';
    $out .= '<p class="ma-hint">' . number_format($total) . ' สัญญา' . ($customers ? ' · ' . number_format($customers) . ' ลูกค้า' : '');
    if ($now) {
        $out .= ' · ตอนนี้อยู่ที่ ' . h($now['site'] !== '' ? $now['site'] : $now['customer'])
            . ($now['end'] !== '' ? ' ถึง ' . h($be($now['end'])) : '');
    }
    $out .= '</p>';

    $render = function (array $slice) use ($labels, $be) {
        $s = '';
        foreach ($slice as $r) {
            $lb = $labels[$r['status']] ?? [$r['status'] !== '' ? $r['status'] : '—', 'ma-pill-open'];
            $s .= '<tr>'
                . '<td class="ma-mono">' . h($r['code'] !== '' ? $r['code'] : '—') . '</td>'
                . '<td class="ma-mono">' . h($be($r['start']) ?: '—') . '</td>'
                . '<td class="ma-mono">' . h($be($r['end']) ?: '—') . '</td>'
                . '<td>' . ($r['returned'] !== '' ? '<span class="ma-mono">' . h($be($r['returned'])) . '</span>' : ($r['status'] === 'active' ? '<span class="ma-muted">—</span>' : '<span class="ma-muted">—</span>')) . '</td>'
                . '<td>' . h($r['customer'] !== '' ? $r['customer'] : '—') . ($r['site'] !== '' ? '<div class="ma-muted">' . h($r['site']) . '</div>' : '') . '</td>'
                . '<td><span class="ma-pill ' . $lb[1] . '">' . h($lb[0]) . '</span></td>'
                . '</tr>';
        }
        return $s;
    };
    $head = '<table class="ma-table"><thead><tr><th>สัญญา</th><th>เริ่ม</th><th>สิ้นสุด</th><th>รับคืน</th><th>ลูกค้า / ไซต์งาน</th><th>สถานะ</th></tr></thead><tbody>';
    $show = 5;
    $out .= '<div class="ma-card ma-card-flat"><div class="ma-tblscroll">' . $head . $render(array_slice($rows, 0, $show)) . '</tbody></table></div>';
    if ($total > $show) {
        $rest = array_slice($rows, $show);
        $out .= '<details class="ma-more"><summary>ดูอีก ' . number_format(count($rest)) . ' สัญญา</summary>'
            . '<div class="ma-tblscroll">' . $head . $render($rest) . '</tbody></table></div></details>';
    }
    return $out . '</div></section>';
}

/**
 * สถานะเช่าแบบ batch สำหรับตาราง assets.php (read-only)
 *
 * @param array<int,array{asset_code:string,factory_serial?:string}> $assets
 * @return array<string,array{found:bool,pro_status:string,p_status:string,status_label:string,badge_class:string,customer_name:string}>
 */
function asset_leasing_status_by_assets(array $assets): array
{
    $out = [];
    if (!$assets || !dbLeasing()) {
        return $out;
    }

    $lookup = [];
    $allSns = [];
    foreach ($assets as $a) {
        $assetCode = trim((string) ($a['asset_code'] ?? ''));
        if ($assetCode === '') {
            continue;
        }
        foreach ([$assetCode, trim((string) ($a['factory_serial'] ?? ''))] as $raw) {
            $sn = rent_normalize_sn($raw);
            if ($sn === '') {
                continue;
            }
            $allSns[$sn] = true;
            if (!isset($lookup[$assetCode])) {
                $lookup[$assetCode] = [];
            }
            if (!in_array($sn, $lookup[$assetCode], true)) {
                $lookup[$assetCode][] = $sn;
            }
        }
    }

    $sns = array_keys($allSns);
    if (!$sns) {
        return $out;
    }

    $products = [];
    foreach (array_chunk($sns, 100) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $q = rent_q_try(
            "SELECT pro_sn, pro_status FROM tbl_product WHERE pro_sn IN ($ph)",
            str_repeat('s', count($chunk)),
            $chunk
        );
        if (!$q['ok'] || empty($q['result'])) {
            continue;
        }
        while ($row = $q['result']->fetch_assoc()) {
            $proSn = rent_normalize_sn($row['pro_sn'] ?? '');
            if ($proSn !== '') {
                $products[$proSn] = $row;
            }
        }
    }
    if (!$products) {
        return $out;
    }

    $foundSns = array_keys($products);
    $lines = [];
    foreach (array_chunk($foundSns, 100) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $lq = rent_q_try(
            "SELECT rp.p_sn, rp.p_status, rp.p_cus_id
             FROM tbl_rent_product rp
             WHERE rp.p_sn IN ($ph)
             ORDER BY CASE WHEN rp.p_status = 'active' THEN 0 ELSE 1 END, rp.p_id DESC",
            str_repeat('s', count($chunk)),
            $chunk
        );
        if (!$lq['ok'] || empty($lq['result'])) {
            continue;
        }
        while ($row = $lq['result']->fetch_assoc()) {
            $pSn = rent_normalize_sn($row['p_sn'] ?? '');
            if ($pSn !== '' && !isset($lines[$pSn])) {
                $lines[$pSn] = $row;
            }
        }
    }

    $cusIds = [];
    foreach ($lines as $row) {
        $cid = trim((string) ($row['p_cus_id'] ?? ''));
        if ($cid !== '') {
            $cusIds[$cid] = true;
        }
    }
    $cusNames = [];
    if ($cusIds) {
        $ids = array_keys($cusIds);
        foreach (array_chunk($ids, 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $cq = rent_q_try(
                "SELECT cus_id, cus_name FROM tbl_customer WHERE cus_id IN ($ph)",
                str_repeat('s', count($chunk)),
                $chunk
            );
            if (!$cq['ok'] || empty($cq['result'])) {
                continue;
            }
            while ($cr = $cq['result']->fetch_assoc()) {
                $cusNames[trim((string) ($cr['cus_id'] ?? ''))] = trim((string) ($cr['cus_name'] ?? ''));
            }
        }
    }

    foreach ($lookup as $assetCode => $candidates) {
        $prod = null;
        $matchedSn = '';
        foreach ($candidates as $sn) {
            if (isset($products[$sn])) {
                $prod = $products[$sn];
                $matchedSn = $sn;
                break;
            }
        }
        if (!$prod) {
            continue;
        }
        $pro = trim((string) ($prod['pro_status'] ?? ''));
        $line = $lines[$matchedSn] ?? null;
        $pSt = $line ? trim((string) ($line['p_status'] ?? '')) : '';
        $cusId = $line ? trim((string) ($line['p_cus_id'] ?? '')) : '';
        $out[$assetCode] = [
            'found' => true,
            'pro_status' => $pro,
            'p_status' => $pSt,
            'status_label' => rent_leasing_status_label($pro, $pSt),
            'badge_class' => rent_leasing_status_badge_class($pro, $pSt),
            'customer_name' => $cusId !== '' ? ($cusNames[$cusId] ?? '') : '',
        ];
    }

    return $out;
}

/**
 * ป้ายสถานะเช่าสำหรับตารางรายการเครื่อง
 *
 * @param array<string,mixed> $status จาก asset_leasing_status_by_assets()
 * @return string
 */
function rent_leasing_list_status_html(array $status): string
{
    $title = 'ระบบเช่า';
    $cus = trim((string) ($status['customer_name'] ?? ''));
    if ($cus !== '') {
        $title .= ' · ' . $cus;
    }
    return '<span class="sale-tag asset-rent-status ' . h((string) ($status['badge_class'] ?? 'asset-rent-badge-default')) . '" title="' . h($title) . '">'
        . h((string) ($status['status_label'] ?? '')) . '</span>';
}

/**
 * แถว dl ใน card เช่า — ใช้ stockparts_withdraw_dl_row ถ้ามี
 *
 * @param string $label
 * @param string $value HTML
 * @return string
 */
function rent_leasing_dl_row($label, $value)
{
    if ($value === '') {
        return '';
    }
    if (function_exists('stockparts_withdraw_dl_row')) {
        return stockparts_withdraw_dl_row($label, $value);
    }
    return '<dt>' . h($label) . '</dt><dd>' . $value . '</dd>';
}

/**
 * สร้าง HTML card「การเบิกเช่า」บนโปรไฟล์เครื่อง production
 *
 * @param array<string,mixed> $info จาก asset_leasing_info()
 * @return string
 */
function asset_leasing_card_html(array $info, $extraHtml = '')
{
    $icon = function_exists('ui_icon_html') ? ui_icon_html('customers', 16) : '';
    $out = '<div class="asset-sales-card asset-rent-card">';
    $out .= '<div class="asset-sales-card-head">' . $icon . '<b>การเบิกเช่า</b></div>';
    $out .= '<div class="asset-sales-card-body">';

    if (empty($info['found'])) {
        $msg = trim((string) ($info['message'] ?? 'ไม่พบข้อมูลเช่า'));
        $out .= '<p class="muted asset-sales-empty">' . h($msg !== '' ? $msg : 'ไม่พบ S/N ในระบบเช่า') . '</p>';
        $out .= '</div></div>';
        return $out;
    }

    $pro = (string) ($info['pro_status'] ?? '');
    $pSt = (string) ($info['p_status'] ?? '');

    $out .= '<div class="asset-rent-status-row">';
    $out .= rent_leasing_status_badge_html($pro, $pSt);
    $out .= '</div>';
    // กล่องผลเทียบสถานะเสื่อมสภาพ (ถ้ามี) — วางใต้ป้ายสถานะให้เห็นทันที ไม่ต้องเลื่อนหาท้ายการ์ด
    $out .= (string) $extraHtml;

    $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">';
    $out .= rent_leasing_dl_row('S/N', '<b>' . h((string) ($info['serial'] ?? '')) . '</b>');
    if (!empty($info['product_name'])) {
        $out .= rent_leasing_dl_row('รุ่น (เช่า)', h((string) $info['product_name']));
    }
    if (!empty($info['customer_name'])) {
        $out .= rent_leasing_dl_row('ลูกค้า', '<b>' . h((string) $info['customer_name']) . '</b>');
    }
    if (!empty($info['site_id'])) {
        $out .= rent_leasing_dl_row('Site ID', h((string) $info['site_id']));
    }
    if (!empty($info['site_name'])) {
        $out .= rent_leasing_dl_row('Site Name', h((string) $info['site_name']));
    }
    if (rent_leasing_valid_date($info['r_startdate'] ?? '')) {
        $out .= rent_leasing_dl_row('เริ่มสัญญา', h(dthai($info['r_startdate'])));
    }
    if ($pSt === 'active' && rent_leasing_valid_date($info['r_enddate'] ?? '')) {
        $out .= rent_leasing_dl_row('หมดสัญญา', h(dthai($info['r_enddate'])));
    }
    if (rent_leasing_valid_date($info['p_date_received'] ?? '')) {
        $out .= rent_leasing_dl_row('วันที่รับคืน', h(dthai($info['p_date_received'])));
    }
    if (!empty($info['r_po'])) {
        $out .= rent_leasing_dl_row('PO', h((string) $info['r_po']));
    }
    if (!empty($info['r_code'])) {
        $out .= rent_leasing_dl_row('รหัสสัญญา', h((string) $info['r_code']));
    }
    if (rent_leasing_valid_date($info['pro_date'] ?? '')) {
        $out .= rent_leasing_dl_row('ลงทะเบียนเช่า', h(dthai($info['pro_date'])));
    }
    $retire = trim((string) ($info['ma_retire_reason'] ?? ''));
    // เครื่องที่เคยปลดแล้วถูกเอากลับมาปล่อยเช่าใหม่ ยังมีแถว Asset Retirement ค้างในประวัติ
    // ถ้าโชว์เหตุผลปลดระวางคู่กับป้าย "เครื่องเช่า" จะอ่านแล้วขัดกันเอง — ประวัติยังเห็นได้ในตาราง
    if (trim((string) ($info['pro_status'] ?? '')) !== 'Asset Retirement') {
        $retire = '';
    }
    if ($retire !== '') {
        $parts = rent_leasing_ma_note_parts($retire);
        $val = count($parts) > 1
            ? '<ul class="rent-ma-note-list"><li>' . implode('</li><li>', array_map('h', $parts)) . '</li></ul>'
            : h($parts ? $parts[0] : '');
        $meta = rent_leasing_ma_retire_meta(
            is_array($info['ma_history'] ?? null) ? $info['ma_history'] : []
        );
        $tail = [];
        if (rent_leasing_valid_date($meta['date'])) {
            $tail[] = dthai($meta['date']);
        }
        if ($meta['by'] !== '') {
            $tail[] = 'โดย ' . $meta['by'];
        }
        if ($tail) {
            $val .= '<span class="rent-retire-meta muted">' . h(implode(' · ', $tail)) . '</span>';
        }
        $out .= rent_leasing_dl_row('เหตุผลที่ปลดระวาง', '<span class="rent-retire-reason">' . $val . '</span>');
    }
    $faults = is_array($info['fault_notes'] ?? null) ? $info['fault_notes'] : [];
    if ($faults) {
        $bits = [];
        foreach ($faults as $fx) {
            $bits[] = '<span class="rent-fault-note">' . h($fx) . '</span>';
        }
        $out .= rent_leasing_dl_row('อาการที่แจ้งเคลม', implode(' ', $bits));
    }
    $remarks = trim((string) ($info['p_remarks'] ?? ''));
    if ($remarks === '' && !empty($info['pro_remarks'])) {
        $remarks = trim((string) $info['pro_remarks']);
    }
    if ($remarks !== '') {
        $out .= rent_leasing_dl_row('หมายเหตุ', h($remarks));
    }
    $out .= '</dl>';

    $out .= '</div></div>';
    return $out;
}
