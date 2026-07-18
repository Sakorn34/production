<?php
/**
 * database/tools/qc_localhost.php — QC ชุด localhost integration (รัน: php database/tools/qc_localhost.php)
 */
ob_start();
$root = dirname(__DIR__, 2);
$repoRoot = dirname($root, 1);

// จำลอง localhost ก่อน load config (ให้ bootstrap ทำงานใน CLI)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';

$fail = 0;
$pass = 0;

function qc($name, $ok, $detail = '') {
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "[PASS] $name" . ($detail ? " — $detail" : '') . "\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail ? " — $detail" : '') . "\n";
    }
}

echo "=== QC Localhost Integration ===\n\n";

// 1) Files
qc('part_stock_bridge.php exists', is_file($root . '/includes/part_stock_bridge.php'));
qc('part_webhook.php removed', !is_file($root . '/includes/part_webhook.php'));
qc('setup_localhost_users.sql exists', is_file($root . '/database/tools/setup_localhost_users.sql'));

$whPath = $repoRoot . '/parts/api/webhook-stockout.php';
$wh = is_file($whPath) ? file_get_contents($whPath) : '';
qc('webhook-stockout returns 410', is_file($whPath) && (strpos($wh, '410') !== false || strpos($wh, 'ปิดใช้งาน') !== false));

$secretsPath = 'D:/AppServ/secrets/production/finishgoogs.secrets.php';
qc('finishgoogs.secrets.php exists', is_file($secretsPath));
$secrets = is_file($secretsPath) ? require $secretsPath : [];
qc('secrets has techparts', !empty($secrets['techparts']['db']));
foreach (['production', 'stockparts', 'techparts'] as $k) {
    $host = $secrets[$k]['host'] ?? '';
    qc("secrets.$k host is localhost", $host === 'localhost' || $host === '127.0.0.1', "host=$host");
}

// 2) PHP syntax
$files = [
    $root . '/config.php',
    $root . '/includes/part_stock_bridge.php',
    $root . '/parts.php',
    $root . '/asset_new.php',
    $root . '/asset.php',
    $root . '/healthz.php',
    $root . '/login.php',
];
foreach ($files as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    qc('syntax ' . basename($f), $code === 0, $code ? implode(' ', $out) : '');
}

// 3) Load config helpers (may die on DB if called too early — test helpers only)
require $root . '/config.php';

qc('is_localhost_request() exists', function_exists('is_localhost_request'));
qc('dbParts() exists', function_exists('dbParts'));
qc('tech_parts_stock_out_by_part_id() exists', function_exists('tech_parts_stock_out_by_part_id'));
qc('tech_parts_qty_to_int(1.4)=1', tech_parts_qty_to_int(1.4) === 1);
qc('tech_parts_qty_to_int(1.5)=2', tech_parts_qty_to_int(1.5) === 2);
qc('tech_parts_qty_to_int(0)=0', tech_parts_qty_to_int(0) === 0);

qc('is_localhost_request true on 127.0.0.1', is_localhost_request() === true);
$_SESSION = [];
ss_dev_localhost_bootstrap();
qc('Tom profile after bootstrap', ss_session_has_profile() && maintenance_new_profile_login_name() === 'Tom');

// 4) DB connections
echo "\n--- DB connections ---\n";
$dbFail = 0;
foreach (['production' => $secrets['production'], 'stockparts' => $secrets['stockparts'], 'techparts' => $secrets['techparts']] as $name => $cfg) {
    $mysqli = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
    if ($mysqli->connect_errno) {
        echo "[FAIL] DB $name — " . $mysqli->connect_error . "\n";
        $dbFail++;
        $fail++;
    } else {
        $mysqli->query('SELECT 1');
        $mysqli->close();
        echo "[PASS] DB $name\n";
        $pass++;
    }
}

echo "\n=== Summary: $pass passed, $fail failed ===\n";
ob_end_flush();
if ($dbFail > 0) {
    echo "\nAction: run as MySQL root:\n";
    echo "  mysql -u root -p < database/tools/setup_localhost_users.sql\n";
}
exit($fail > 0 ? 1 : 0);
