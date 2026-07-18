<?php
require __DIR__ . '/../../config.php';
$map = [
    23 => ['bitVisitor S', 'bitVisitor_S__MA.csv'],
];
$staging = 'd:/AppServ/www/cluade/production/_archive/database/staging/staging/';
foreach ($map as $pid => [$name, $file]) {
    $c = qr('SELECT COUNT(*) c FROM ma_records m JOIN assets a ON a.id=m.asset_id WHERE a.product_id=?', 'i', [$pid])->fetch_assoc()['c'];
    $csv = 0;
    $path = $staging . $file;
    if (is_file($path)) {
        $fp = fopen($path, 'r');
        fgetcsv($fp);
        while (fgetcsv($fp) !== false) $csv++;
        fclose($fp);
    }
    echo "$name (id=$pid): DB=$c CSV=$csv\n";
}
