<?php
$url = $argv[1] ?? 'http://192.168.2.199/stockParts_tech/historyFG.php';
$out = $argv[2] ?? dirname(__DIR__) . '/import/historyFG.html';
$html = @file_get_contents($url);
if ($html === false) {
    fwrite(STDERR, "fetch failed: $url\n");
    exit(1);
}
file_put_contents($out, $html);
echo "saved " . strlen($html) . " bytes to $out\n";
