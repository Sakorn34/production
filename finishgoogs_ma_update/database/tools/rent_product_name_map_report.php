<?php
/**
 * database/tools/rent_product_name_map_report.php — รายงานแมปชื่อรุ่น production ↔ leasing
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__, 2) . '/includes/rent_ma_bridge.php';

function rent_strip_product_suffix($name)
{
    $n = trim((string) $name);
    $n = preg_replace('/\s+STK\d+\s*$/iu', '', $n);
    $n = preg_replace('/\s+ST-\d+\s*$/iu', '', $n);
    return trim($n);
}

function rent_normalize_map_name($name)
{
    $n = rent_product_name_key($name);
    return rent_strip_product_suffix($n);
}

/**
 * แมปชื่อเช่า → รุ่นผลิต (longest-prefix ก่อน เพื่อไม่ให้ Router กลืน Router 4G)
 *
 * @param array<int,array{id:int,name:string,key:string,base:string}> $products
 * @return array<string,array{id:int,name:string,method:string}>
 */
function rent_build_name_alias_map(array $products)
{
    $map = [];
    foreach ($products as $p) {
        foreach ([$p['key'], $p['base']] as $k) {
            if ($k !== '' && !isset($map[$k])) {
                $map[$k] = ['id' => $p['id'], 'name' => $p['name'], 'method' => 'exact'];
            }
        }
    }
    return $map;
}

/**
 * @param string $rentName
 * @param array<int,array{id:int,name:string,key:string,base:string}> $products
 * @param array<string,array{id:int,name:string,method:string}> $exactMap
 * @return array{id:int,name:string,method:string}|null
 */
function rent_match_rent_name_to_product($rentName, array $products, array $exactMap)
{
    $rentKey = rent_product_name_key($rentName);
    if ($rentKey === '') {
        return null;
    }
    if (isset($exactMap[$rentKey])) {
        return $exactMap[$rentKey];
    }

    $rentBase = rent_normalize_map_name($rentName);
    if ($rentBase !== '' && isset($exactMap[$rentBase])) {
        $hit = $exactMap[$rentBase];
        $hit['method'] = 'base-exact';
        return $hit;
    }

    $candidates = [];
    foreach ($products as $p) {
        foreach ([$p['key'], $p['base']] as $prodKey) {
            if ($prodKey === '') {
                continue;
            }
            if ($prodKey === $rentKey || $prodKey === $rentBase) {
                $candidates[] = ['id' => $p['id'], 'name' => $p['name'], 'method' => 'normalized', 'len' => mb_strlen($prodKey)];
            } elseif (mb_strpos($prodKey, $rentKey) === 0 && ($rentKey === $prodKey || mb_substr($prodKey, mb_strlen($rentKey), 1) === ' ')) {
                $candidates[] = ['id' => $p['id'], 'name' => $p['name'], 'method' => 'prod-starts-with-rent', 'len' => mb_strlen($rentKey)];
            } elseif (mb_strpos($rentKey, $prodKey) === 0 && ($prodKey === $rentKey || mb_substr($rentKey, mb_strlen($prodKey), 1) === ' ')) {
                $candidates[] = ['id' => $p['id'], 'name' => $p['name'], 'method' => 'rent-starts-with-prod', 'len' => mb_strlen($prodKey)];
            }
        }
    }
    if (!$candidates) {
        return null;
    }
    usort($candidates, static function ($a, $b) {
        return ($b['len'] <=> $a['len']) ?: ($a['id'] <=> $b['id']);
    });
    $best = $candidates[0];
    return ['id' => $best['id'], 'name' => $best['name'], 'method' => $best['method']];
}

$products = [];
$res = qr('SELECT id, name, product_code FROM products WHERE is_active=1 ORDER BY name');
while ($p = $res->fetch_assoc()) {
    $products[] = [
        'id' => (int) $p['id'],
        'name' => (string) $p['name'],
        'code' => (string) ($p['product_code'] ?? ''),
        'key' => rent_product_name_key($p['name']),
        'base' => rent_normalize_map_name($p['name']),
    ];
}
$exactMap = rent_build_name_alias_map($products);

if (!dbLeasing()) {
    fwrite(STDERR, 'dbLeasing fail: ' . dbLeasingError() . "\n");
    exit(1);
}

$rentNames = [];
$q = rent_q_try(
    'SELECT pro_name, COUNT(*) c FROM tbl_product GROUP BY pro_name ORDER BY pro_name',
    '',
    []
);
if (!$q['ok'] || empty($q['result'])) {
    fwrite(STDERR, "query leasing names fail\n");
    exit(1);
}
while ($r = $q['result']->fetch_assoc()) {
    $rentNames[] = ['name' => (string) $r['pro_name'], 'count' => (int) $r['c']];
}

$mapped = [];
$unmapped = [];
$ambiguous = [];

foreach ($rentNames as $rn) {
    $rentName = $rn['name'];
    $hit = rent_match_rent_name_to_product($rentName, $products, $exactMap);
    if (!$hit) {
        $unmapped[] = $rn;
        continue;
    }
    $mapped[] = [
        'rent_name' => $rentName,
        'rent_count' => $rn['count'],
        'prod_id' => $hit['id'],
        'prod_name' => $hit['name'],
        'method' => $hit['method'],
    ];
}

// รอบ 2: prefix match — ไม่ให้ชื่อสั้น (Router) แย่งรุ่นที่มีชื่อเช่าเฉพาะ (Router 4G) แล้ว
$claimedProd = [];
foreach ($mapped as $m) {
    if (in_array($m['method'], ['exact', 'base-exact'], true)) {
        $claimedProd[$m['prod_id']] = $m['rent_name'];
    }
}
$mapped2 = [];
$unmapped2 = [];
foreach ($rentNames as $rn) {
    $rentName = $rn['name'];
    $exactHit = rent_match_rent_name_to_product($rentName, $products, $exactMap);
    if ($exactHit && in_array($exactHit['method'], ['exact', 'base-exact'], true)) {
        $mapped2[] = [
            'rent_name' => $rentName,
            'rent_count' => $rn['count'],
            'prod_id' => $exactHit['id'],
            'prod_name' => $exactHit['name'],
            'method' => $exactHit['method'],
        ];
        continue;
    }
    $rentKey = rent_product_name_key($rentName);
    $candidates = [];
    foreach ($products as $p) {
        if (isset($claimedProd[$p['id']])) {
            continue;
        }
        foreach ([$p['key'], $p['base']] as $prodKey) {
            if ($prodKey === '') {
                continue;
            }
            if (mb_strpos($prodKey, $rentKey) === 0 && ($rentKey === $prodKey || mb_substr($prodKey, mb_strlen($rentKey), 1) === ' ')) {
                $candidates[] = ['id' => $p['id'], 'name' => $p['name'], 'method' => 'prod-starts-with-rent', 'len' => mb_strlen($rentKey)];
            } elseif (mb_strpos($rentKey, $prodKey) === 0 && ($prodKey === $rentKey || mb_substr($rentKey, mb_strlen($prodKey), 1) === ' ')) {
                $candidates[] = ['id' => $p['id'], 'name' => $p['name'], 'method' => 'rent-starts-with-prod', 'len' => mb_strlen($prodKey)];
            }
        }
    }
    if (!$candidates) {
        $unmapped2[] = $rn;
        continue;
    }
    usort($candidates, static function ($a, $b) {
        return ($b['len'] <=> $a['len']) ?: ($a['id'] <=> $b['id']);
    });
    $best = $candidates[0];
    $mapped2[] = [
        'rent_name' => $rentName,
        'rent_count' => $rn['count'],
        'prod_id' => $best['id'],
        'prod_name' => $best['name'],
        'method' => $best['method'],
    ];
    $claimedProd[$best['id']] = $rentName;
}
$mapped = $mapped2;
$unmapped = $unmapped2;

// ชื่อเช่าที่แมปไม่ได้ — เสนอ candidate ใกล้เคียง
$suggestions = [
    'Card Cam' => 'Card Camera',
    'FaceCam Indoor' => 'Indoor Camera',
    'Printer 80 mm.' => 'Printer 80mm.',
    'Smart Card S' => 'Smart Card Reader S',
    'bitCamera' => 'Card Camera / Outdoor Camera',
    'Portable Client' => '(ไม่มีรุ่นตรงใน production)',
    'bitSupply 1224' => '(ไม่มีรุ่น 1224 ใน production)',
];

// รุ่นผลิตที่ไม่มีชื่อเช่าแมป
$mappedProdIds = array_unique(array_column($mapped, 'prod_id'));
$unmappedProd = [];
foreach ($products as $p) {
    if (!in_array($p['id'], $mappedProdIds, true)) {
        $unmappedProd[] = $p;
    }
}

echo "=== แมปชื่อรุ่น production ↔ leasing ===\n";
echo 'รุ่นผลิต active: ' . count($products) . "\n";
echo 'ชื่อในระบบเช่า (distinct): ' . count($rentNames) . "\n";
echo 'แมปได้: ' . count($mapped) . "\n";
echo 'แมปไม่ได้ (เช่า): ' . count($unmapped) . "\n";
echo 'รุ่นผลิตไม่มีคู่เช่า: ' . count($unmappedProd) . "\n\n";

echo "--- ตัวอย่างที่ user ระบุ ---\n";
foreach (['Router 4G', 'Router'] as $sample) {
    $hit = rent_match_rent_name_to_product($sample, $products, $exactMap);
    echo $sample . ' => ' . ($hit ? ($hit['name'] . ' (id ' . $hit['id'] . ', ' . $hit['method'] . ')') : 'ไม่แมป') . "\n";
}
echo "\n";

echo "--- แมปได้ (" . count($mapped) . ") ---\n";
foreach ($mapped as $m) {
    echo sprintf(
        "  [%s] %s (%d เครื่อง) => id %d %s (%s)\n",
        $m['method'],
        $m['rent_name'],
        $m['rent_count'],
        $m['prod_id'],
        $m['prod_name'],
        $m['method']
    );
}

if ($unmapped) {
    echo "\n--- แมปไม่ได้ ชื่อในระบบเช่า (" . count($unmapped) . ") ---\n";
    foreach ($unmapped as $u) {
        $hint = $suggestions[$u['name']] ?? '';
        echo sprintf("  %s (%d เครื่อง)", $u['name'], $u['count']);
        if ($hint !== '') {
            echo ' → แนะนำ: ' . $hint;
        }
        echo "\n";
    }
}

if ($unmappedProd) {
    echo "\n--- รุ่นผลิตที่ยังไม่มีชื่อเช่าแมป (" . count($unmappedProd) . ") ---\n";
    foreach ($unmappedProd as $p) {
        echo sprintf("  id %d | %s | %s\n", $p['id'], $p['code'], $p['name']);
    }
}
