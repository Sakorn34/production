<?php
/**
 * includes/stock_active_sync.php — ตั้งค่า active ในทะเบียนสินค้า (stock) ตามสถานะเครื่องในระบบผลิต
 *
 * กติกา (ตกลง 26 ก.ย. 2569):
 *   สถานะ "เครื่องใหม่" (new)                          → active = 1  นับเป็นสต๊อก
 *   เบิกใช้งานแล้ว: ขาย · เช่า · สำรอง · เสื่อมสภาพ ·
 *   สูญหาย · ไม่มีสถานะ · ไม่พบ serial ในระบบผลิต       → active = 0  ไม่นับ
 *
 * ตาราง stock เป็นของทีมสต๊อกอะไหล่ — ห้าม ALTER เพิ่มฟิลด์ เขียนได้เฉพาะค่าในคอลัมน์เดิม
 */

/**
 * ค่า active ที่ควรเป็น ตามสถานะเครื่อง
 *
 * @param string|null $status สถานะใน assets.status (null = ไม่พบเครื่องในระบบผลิต)
 * @return int 1 หรือ 0
 */
function stock_active_wanted($status): int
{
    return trim((string) $status) === 'new' ? 1 : 0;
}

/**
 * เทียบ active ในตาราง stock กับสถานะเครื่องทั้งระบบ — ไม่เขียนอะไรทั้งนั้น
 *
 * @return array{total:int,ok:int,to1:int,to0:int,no_asset:int,diff:int,a1_now:int,a1_after:int,new_assets:int}
 */
function stock_active_diff(): array
{
    $want = [];
    $newAssets = 0;
    $res = db()->query('SELECT asset_code, status FROM assets');
    while ($r = $res->fetch_assoc()) {
        $v = stock_active_wanted($r['status']);
        $want[trim((string) $r['asset_code'])] = $v;
        if ($v === 1) {
            $newAssets++;
        }
    }

    $out = ['total' => 0, 'ok' => 0, 'to1' => 0, 'to0' => 0, 'no_asset' => 0,
            'diff' => 0, 'a1_now' => 0, 'a1_after' => 0, 'new_assets' => $newAssets];
    $res = dbStock()->query('SELECT serial_number, active FROM stock');
    while ($r = $res->fetch_assoc()) {
        $out['total']++;
        $cur = (int) $r['active'];
        if ($cur === 1) {
            $out['a1_now']++;
        }
        $sn = trim((string) $r['serial_number']);
        $has = array_key_exists($sn, $want);
        if (!$has) {
            $out['no_asset']++;
        }
        $w = $has ? $want[$sn] : 0;
        if ($w === 1) {
            $out['a1_after']++;
        }
        if ($w === $cur) {
            $out['ok']++;
        } elseif ($w === 1) {
            $out['to1']++;
        } else {
            $out['to0']++;
        }
    }
    $out['diff'] = $out['to1'] + $out['to0'];
    return $out;
}

/**
 * ตั้ง active ของทั้งตาราง stock ให้ตรงกับสถานะเครื่อง
 *
 * เขียนเป็นชุด (ชุดละ 500 serial) ไม่ใช่ทีละแถว — 18,000 แถวจะได้ไม่กลายเป็น 18,000 round trip
 *
 * @return array{to1:int,to0:int,changed:int}
 */
function stock_active_apply(): array
{
    $newCodes = [];
    $res = db()->query("SELECT asset_code FROM assets WHERE status = 'new'");
    while ($r = $res->fetch_assoc()) {
        $newCodes[trim((string) $r['asset_code'])] = true;
    }

    $stock = dbStock();
    $to1 = [];
    $to0 = [];
    $res = $stock->query('SELECT serial_number, active FROM stock');
    while ($r = $res->fetch_assoc()) {
        $sn = trim((string) $r['serial_number']);
        $want = isset($newCodes[$sn]) ? 1 : 0;
        if ($want === (int) $r['active']) {
            continue;
        }
        if ($want === 1) {
            $to1[] = $r['serial_number'];
        } else {
            $to0[] = $r['serial_number'];
        }
    }

    $changed = stock_active_write_batch($to1, 1) + stock_active_write_batch($to0, 0);
    return ['to1' => count($to1), 'to0' => count($to0), 'changed' => $changed];
}

/**
 * เขียนค่า active ให้ serial ชุดหนึ่ง
 *
 * @param array<int,string> $serials
 * @param int               $active
 * @return int จำนวนแถวที่เปลี่ยนจริง
 */
function stock_active_write_batch(array $serials, int $active): int
{
    if (!$serials) {
        return 0;
    }
    $stock = dbStock();
    $active = $active === 1 ? 1 : 0;
    $done = 0;
    foreach (array_chunk($serials, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $stock->prepare("UPDATE stock SET active = $active WHERE serial_number IN ($ph)");
        if (!$st) {
            continue;
        }
        $st->bind_param(str_repeat('s', count($chunk)), ...$chunk);
        $st->execute();
        $done += $st->affected_rows;
    }
    return $done;
}

/**
 * ตั้ง active ให้เฉพาะเครื่องที่ระบุ — ใช้ตอนสถานะเครื่องเปลี่ยน
 *
 * เรียกได้ถี่ ๆ ไม่ต้องกลัว: ถ้าค่าตรงอยู่แล้ว MySQL จะไม่เขียนทับ
 * และถ้าตารางสต๊อกล่มก็เงียบไป ไม่ทำให้การบันทึกสถานะฝั่งเราพัง
 *
 * @param array<int,string> $assetCodes
 * @return int จำนวนแถวที่เปลี่ยนจริง
 */
function stock_active_sync_codes(array $assetCodes): int
{
    $codes = [];
    foreach ($assetCodes as $c) {
        $c = trim((string) $c);
        if ($c !== '') {
            $codes[$c] = true;
        }
    }
    if (!$codes) {
        return 0;
    }
    try {
        $list = array_keys($codes);
        $ph = implode(',', array_fill(0, count($list), '?'));
        $st = db()->prepare("SELECT asset_code, status FROM assets WHERE asset_code IN ($ph)");
        if (!$st) {
            return 0;
        }
        $st->bind_param(str_repeat('s', count($list)), ...$list);
        $st->execute();
        $res = $st->get_result();
        $to1 = [];
        $to0 = [];
        $seen = [];
        while ($r = $res->fetch_assoc()) {
            $seen[trim((string) $r['asset_code'])] = true;
            if (stock_active_wanted($r['status']) === 1) {
                $to1[] = $r['asset_code'];
            } else {
                $to0[] = $r['asset_code'];
            }
        }
        // serial ที่ไม่มีเครื่องในระบบผลิตแล้ว = ไม่นับเป็นสต๊อก
        foreach ($list as $c) {
            if (!isset($seen[$c])) {
                $to0[] = $c;
            }
        }
        return stock_active_write_batch($to1, 1) + stock_active_write_batch($to0, 0);
    } catch (Throwable $e) {
        error_log('[stock_active_sync_codes] ' . $e->getMessage());
        return 0;
    }
}
