# verify-kpo-page.ps1 — smoke test หน้า KPO หลังแก้โค้ด (php -l + render คร่าว ๆ)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$phpFiles = @(
    "$root\parts\includes\key_parts_order.php",
    "$root\parts\pages\key-parts-order.php"
)
foreach ($f in $phpFiles) {
    & php -l $f
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
$smoke = @"
<?php
chdir('$($root -replace "\\", "/")/parts');
`$_SERVER['REQUEST_METHOD'] = 'GET';
`$_GET = ['year' => (int) date('Y'), 'era' => 'be'];
require 'includes/parts_bootstrap.php';
require 'includes/key_parts_order.php';
`$y = (int) date('Y');
`$data = key_parts_order_load_cached(`$y);
if (!is_array(`$data)) { fwrite(STDERR, "load not array\n"); exit(1); }
`$mos = key_parts_order_parse_avg_months('1,2,3', `$y);
if (count(`$mos) !== 3) { fwrite(STDERR, "parse avg_mos fail\n"); exit(1); }
`$sub = key_parts_order_year_out_subline_extras_html('all', [1 => key_parts_order_empty_buckets()], [], [1, 2]);
echo "kpo-smoke-ok\n";
"@
$tmp = Join-Path $env:TEMP "kpo-smoke-$(Get-Random).php"
[System.IO.File]::WriteAllText($tmp, $smoke, (New-Object System.Text.UTF8Encoding $false))
& php $tmp
$code = $LASTEXITCODE
Remove-Item -Force $tmp -ErrorAction SilentlyContinue
if ($code -ne 0) { exit $code }
Write-Host "KPO verify OK"
