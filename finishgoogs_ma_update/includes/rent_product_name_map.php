<?php
/**
 * rent_product_name_map.php
 * ────────────────────────────────────────────────────────────────────────────────
 * แมปชื่อรุ่นจากระบบเช่า (คิวรอ MA) → products ในระบบผลิต
 * รองรับ exact / alias / prefix และตาราง legacy รุ่นเก่า
 *
 * ตัวอย่าง: Portable Client → โทรศัพท์มือถือ, Router → Router WIFI (ไม่ชน Router 4G)
 * ────────────────────────────────────────────────────────────────────────────────
 */

/**
 * ตัด suffix STK/ST ออกจากชื่อรุ่นผลิต
 *
 * @param string $name
 * @return string
 */
function rent_product_strip_suffix($name)
{
    $n = trim((string) $name);
    $n = preg_replace('/\s+STK\d+\s*$/iu', '', $n);
    $n = preg_replace('/\s+ST-\d+\s*$/iu', '', $n);
    return trim($n);
}

/**
 * คีย์ normalize ชื่อสำหรับเทียบแมป
 *
 * @param string $name
 * @return string
 */
function rent_product_name_key($name)
{
    $name = mb_strtolower(trim((string) $name));
    $name = str_replace(['adepter', 'adapter'], 'adapter', $name);
    $name = preg_replace('/\s*mm\.?\s*/iu', 'mm', $name);
    return preg_replace('/\s+/u', ' ', $name);
}

/**
 * Alias มาตรฐาน — ชื่อในระบบเช่า → ชื่อรุ่นใน products (active)
 * ใช้หลัง exact match ไม่เจอ
 *
 * @return array<string,string> rent_key => production product name
 */
function rent_product_standard_aliases()
{
    return [
        'portable client'      => 'โทรศัพท์มือถือ',
        'card cam'             => 'Card Camera',
        'facecam indoor'       => 'Indoor Camera',
        'printer 80 mm'        => 'Printer 80mm.',
        'printer 80mm'         => 'Printer 80mm.',
        'smart card s'         => 'Smart Card Reader S',
        'bitscan online'       => 'bitScan',
        'bitstamp'             => 'bitStamp Factory Single',
        'bitsupply 1206'       => 'bitSupply 1206 Paperless',
        'bitsupply 1212'       => 'bitSupply 1212 Paperless',
        'magnetic'             => 'Magnetic Reader',
        'router'               => 'Router WIFI',
        'smart card'           => 'Smart Card Reader',
        'bitcamera'            => 'Outdoor Camera',
    ];
}

/**
 * ตารางรุ่นเก่าในระบบเช่า — ไม่มีรุ่นตรงใน products ปัจจุบัน
 * แมปไปรุ่นที่ใกล้เคียงสำหรับคิว MA / ลงทะเบียน
 *
 * @return array<string,string> rent_key => production product name
 */
function rent_product_legacy_aliases()
{
    return [
        'bitsupply 1224' => 'bitSupply 1203',
    ];
}

/**
 * โหลดรายการ products active พร้อม key สำหรับแมป
 *
 * @return array<int,array{id:int,name:string,key:string,base:string}>
 */
function rent_product_catalog()
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $catalog = [];
    $res = qr('SELECT id, name FROM products WHERE is_active=1 ORDER BY id');
    while ($p = $res->fetch_assoc()) {
        $name = (string) $p['name'];
        $catalog[] = [
            'id'   => (int) $p['id'],
            'name' => $name,
            'key'  => rent_product_name_key($name),
            'base' => rent_product_name_key(rent_product_strip_suffix($name)),
        ];
    }
    return $catalog;
}

/**
 * แปลงชื่อรุ่นผลิต → product_id
 *
 * @param string $productName
 * @return int
 */
function rent_product_id_by_name($productName)
{
    $key = rent_product_name_key($productName);
    if ($key === '') {
        return 0;
    }
    foreach (rent_product_catalog() as $p) {
        if ($p['key'] === $key || $p['base'] === $key) {
            return $p['id'];
        }
    }
    return 0;
}

/**
 * แผนที่ alias รวม (legacy ทับ standard ไม่ได้ — legacy ตรวจก่อน)
 *
 * @return array<string,int> rent_key => product_id
 */
function rent_product_alias_id_map()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (rent_product_legacy_aliases() as $rentKey => $prodName) {
        $pid = rent_product_id_by_name($prodName);
        if ($pid > 0) {
            $map[rent_product_name_key($rentKey)] = $pid;
        }
    }
    foreach (rent_product_standard_aliases() as $rentKey => $prodName) {
        $key = rent_product_name_key($rentKey);
        if ($key === '' || isset($map[$key])) {
            continue;
        }
        $pid = rent_product_id_by_name($prodName);
        if ($pid > 0) {
            $map[$key] = $pid;
        }
    }
    return $map;
}

/**
 * แมป exact ชื่อรุ่นผลิต → product_id (รวม base หลังตัด STK)
 *
 * @return array<string,int>
 */
function rent_product_exact_name_id_map()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (rent_product_catalog() as $p) {
        foreach ([$p['key'], $p['base']] as $k) {
            if ($k !== '' && !isset($map[$k])) {
                $map[$k] = $p['id'];
            }
        }
    }
    return $map;
}

/**
 * หา product_id จาก prefix match (ยาวสุดชนะ)
 *
 * @param string $rentKey
 * @param array<int,int> $excludeProductIds
 * @return int
 */
function rent_product_prefix_match_id($rentKey, array $excludeProductIds = [])
{
    if ($rentKey === '') {
        return 0;
    }
    $candidates = [];
    foreach (rent_product_catalog() as $p) {
        if (in_array($p['id'], $excludeProductIds, true)) {
            continue;
        }
        foreach ([$p['key'], $p['base']] as $prodKey) {
            if ($prodKey === '') {
                continue;
            }
            if ($prodKey === $rentKey) {
                $candidates[] = ['id' => $p['id'], 'len' => mb_strlen($prodKey)];
            } elseif (mb_strpos($prodKey, $rentKey) === 0
                && ($rentKey === $prodKey || mb_substr($prodKey, mb_strlen($rentKey), 1) === ' ')
            ) {
                $candidates[] = ['id' => $p['id'], 'len' => mb_strlen($rentKey)];
            } elseif (mb_strpos($rentKey, $prodKey) === 0
                && ($prodKey === $rentKey || mb_substr($rentKey, mb_strlen($prodKey), 1) === ' ')
            ) {
                $candidates[] = ['id' => $p['id'], 'len' => mb_strlen($prodKey)];
            }
        }
    }
    if (!$candidates) {
        return 0;
    }
    usort($candidates, static function ($a, $b) {
        return ($b['len'] <=> $a['len']) ?: ($a['id'] <=> $b['id']);
    });
    return (int) $candidates[0]['id'];
}

/**
 * แปลงชื่อในระบบเช่า → product_id (รายชื่อเดียว)
 *
 * @param string $rentName
 * @param array<int,int> $excludeProductIds รุ่นที่จองแล้ว (กัน Router ชน Router 4G)
 * @return int
 */
function rent_resolve_rent_name_to_product_id($rentName, array $excludeProductIds = [])
{
    $rentKey = rent_product_name_key($rentName);
    if ($rentKey === '') {
        return 0;
    }

    $aliasMap = rent_product_alias_id_map();
    $legacyKeys = array_map('rent_product_name_key', array_keys(rent_product_legacy_aliases()));
    if (in_array($rentKey, $legacyKeys, true)) {
        $pid = (int) ($aliasMap[$rentKey] ?? 0);
        if ($pid > 0 && !in_array($pid, $excludeProductIds, true)) {
            return $pid;
        }
    }

    $exact = rent_product_exact_name_id_map();
    if (isset($exact[$rentKey]) && !in_array((int) $exact[$rentKey], $excludeProductIds, true)) {
        return (int) $exact[$rentKey];
    }

    if (isset($aliasMap[$rentKey]) && !in_array((int) $aliasMap[$rentKey], $excludeProductIds, true)) {
        return (int) $aliasMap[$rentKey];
    }

    return rent_product_prefix_match_id($rentKey, $excludeProductIds);
}

/**
 * สร้างแผนที่ชื่อเช่า → product_id จากรายชื่อในคิว (สองรอบ — exact ก่อน prefix/alias)
 *
 * @param array<int,string> $rentNames
 * @return array<string,int> rent_key => product_id
 */
function rent_build_rent_name_product_map(array $rentNames)
{
    $unique = [];
    foreach ($rentNames as $name) {
        $key = rent_product_name_key($name);
        if ($key !== '') {
            $unique[$key] = (string) $name;
        }
    }

    $map = [];

    // รอบ 1: exact ชื่อตรง products
    $exact = rent_product_exact_name_id_map();
    foreach ($unique as $rentKey => $rentName) {
        if (isset($exact[$rentKey])) {
            $map[$rentKey] = (int) $exact[$rentKey];
        }
    }

    // รอบ 2: legacy + alias + prefix (หลายชื่อเช่าแมปรุ่นเดียวกันได้ เช่น 1203/1224)
    foreach ($unique as $rentKey => $rentName) {
        if (isset($map[$rentKey])) {
            continue;
        }
        $pid = rent_resolve_rent_name_to_product_id($rentName);
        if ($pid > 0) {
            $map[$rentKey] = $pid;
        }
    }

    return $map;
}

/**
 * แผนที่ชื่อคิวรอ MA ปัจจุบัน → product_id (cache ต่อ request)
 *
 * @param bool $forceRefresh
 * @return array<string,int>
 */
function rent_wait_ma_rent_name_product_map($forceRefresh = false)
{
    static $map = null;
    static $built = false;
    if ($forceRefresh) {
        $map = null;
        $built = false;
    }
    if ($built) {
        return $map ?? [];
    }
    $built = true;
    if (!function_exists('rent_wait_ma_queue_cached')) {
        return [];
    }
    $q = rent_wait_ma_queue_cached();
    if (!$q['ok']) {
        $map = [];
        return $map;
    }
    $names = [];
    foreach ($q['rows'] as $row) {
        $names[] = (string) ($row['pro_name'] ?? '');
    }
    $map = rent_build_rent_name_product_map($names);
    return $map;
}

/**
 * ชื่อเช่าตรงกับรุ่นผลิตหรือไม่ (รองรับ alias / prefix)
 *
 * @param string $rentName
 * @param string $productName
 * @return bool
 */
function rent_product_name_matches($rentName, $productName)
{
    $pid = rent_product_id_by_name($productName);
    if ($pid <= 0) {
        return false;
    }
    $rentKey = rent_product_name_key($rentName);
    if ($rentKey === '') {
        return false;
    }
    $queueMap = rent_wait_ma_rent_name_product_map();
    if (isset($queueMap[$rentKey])) {
        return (int) $queueMap[$rentKey] === $pid;
    }
    return rent_resolve_rent_name_to_product_id($rentName) === $pid;
}

/**
 * product_id จากชื่อในระบบเช่า (ใช้แทน exact-only map เดิม)
 *
 * @param string $rentName
 * @return int
 */
function rent_product_id_for_rent_name($rentName)
{
    $rentKey = rent_product_name_key($rentName);
    if ($rentKey === '') {
        return 0;
    }
    $queueMap = rent_wait_ma_rent_name_product_map();
    if (isset($queueMap[$rentKey])) {
        return (int) $queueMap[$rentKey];
    }
    return rent_resolve_rent_name_to_product_id($rentName);
}

/**
 * แผนที่ชื่อรุ่น → product_id (backward compat — รวม alias)
 *
 * @return array<string,int>
 */
function rent_product_ids_by_name_map()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = rent_product_exact_name_id_map();
    foreach (rent_product_alias_id_map() as $rentKey => $pid) {
        if (!isset($map[$rentKey])) {
            $map[$rentKey] = $pid;
        }
    }
    return $map;
}
