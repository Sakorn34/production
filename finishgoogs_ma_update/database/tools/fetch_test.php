<?php
$url = $argv[1] ?? 'http://192.168.2.199/stockParts_tech/historyFG.php';
$html = @file_get_contents($url);
if ($html === false) {
    fwrite(STDERR, "fetch failed\n");
    exit(1);
}
echo substr($html, 0, 4000);
