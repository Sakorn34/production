<?php
/**
 * database/tools/test_db_connections.php — ทดสอบเชื่อมต่อ DB ทั้ง 3 ตัว (ไม่ die ตอน fail)
 */
$secrets = require 'D:/AppServ/secrets/production/finishgoogs.secrets.php';

/**
 * @param array{host:string,user:string,pass:string,db:string} $cfg
 * @return array{ok:bool, error?:string, driver?:string}
 */
function test_mysqli_conn(array $cfg) {
    $mysqli = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
    if ($mysqli->connect_errno) {
        return ['ok' => false, 'driver' => 'mysqli', 'error' => $mysqli->connect_error];
    }
    $mysqli->query('SELECT 1');
    $mysqli->close();
    return ['ok' => true, 'driver' => 'mysqli'];
}

/**
 * @param array{host:string,user:string,pass:string,db:string} $cfg
 * @return array{ok:bool, error?:string, driver?:string}
 */
function test_pdo_conn(array $cfg) {
    try {
        $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['db'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
        return ['ok' => true, 'driver' => 'pdo'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'pdo', 'error' => $e->getMessage()];
    }
}

$map = [
    'production' => $secrets['production'],
    'stockparts' => $secrets['stockparts'],
    'techparts'  => $secrets['techparts'],
];

$allOk = true;
foreach ($map as $name => $cfg) {
    $my = test_mysqli_conn($cfg);
    $pd = ($name === 'techparts') ? test_pdo_conn($cfg) : ['ok' => null];
    $ok = $my['ok'];
    if (!$ok) {
        $allOk = false;
    }
    echo strtoupper($name) . ': ' . ($ok ? 'OK (mysqli)' : 'FAIL mysqli — ' . ($my['error'] ?? '')) . "\n";
    if ($name === 'techparts') {
        echo '  techparts PDO: ' . ($pd['ok'] ? 'OK' : 'FAIL — ' . ($pd['error'] ?? '')) . "\n";
    }
}

exit($allOk ? 0 : 1);
