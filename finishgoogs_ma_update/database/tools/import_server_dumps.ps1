# import_server_dumps.ps1 — นำเข้า SQL zip จาก server ลง local MySQL (AppServ)
# ใช้: วางไฟล์ *.sql.zip ใน D:\AppServ\www\production\ แล้วรัน script นี้
# คำเตือน: แทนที่ข้อมูลใน DB ทั้ง 3 ตัวด้วย dump จาก server

$ErrorActionPreference = 'Stop'
$root = 'D:\AppServ\www\production'
$mysql = 'D:\AppServ\mysql\bin\mysql.exe'
$temp = Join-Path $root '_db_import_temp'

# โหลดรหัสจาก finishgoogs.secrets.php ผ่าน PHP
$secretsJson = php -r "require '$root/shared/app_paths.php'; echo json_encode(require app_finishgoogs_secrets_path());"
$c = $secretsJson | ConvertFrom-Json

function Expand-LatestZip($pattern, $dest) {
    $zip = Get-ChildItem (Join-Path $root $pattern) | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $zip) { throw "ไม่พบไฟล์ $pattern" }
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    Expand-Archive -Path $zip.FullName -DestinationPath $dest -Force
    return (Get-ChildItem $dest -Filter *.sql | Select-Object -First 1).FullName
}

function Import-Sql($dbName, $user, $pass, $sqlFile) {
    Write-Host "Import $dbName ..."
    $env:MYSQL_PWD = $pass
    cmd /c "`"$mysql`" -h localhost -u $user --default-character-set=utf8mb4 $dbName < `"$sqlFile`""
    if ($LASTEXITCODE -ne 0) { throw "Import $dbName failed (exit $LASTEXITCODE)" }
}

# แตก zip + ตัด trigger (stockparts)
$prodSql = Expand-LatestZip 'biton_production_*.sql.zip' (Join-Path $temp 'production')
$stockZip = Get-ChildItem (Join-Path $root 'biton_stockparts_*.sql.zip') | Sort-Object LastWriteTime -Descending | Select-Object -First 1
$techSql = Expand-LatestZip 'biton_tech_parts_*.sql.zip' (Join-Path $temp 'techparts')

$stockRaw = (Get-ChildItem (Join-Path $temp 'stockparts') -Filter *.sql -ErrorAction SilentlyContinue | Select-Object -First 1)
if (-not $stockRaw) {
    Expand-Archive -Path $stockZip.FullName -DestinationPath (Join-Path $temp 'stockparts') -Force
    $stockRaw = Get-ChildItem (Join-Path $temp 'stockparts') -Filter *.sql | Select-Object -First 1
}
php (Join-Path $temp 'strip_triggers.php')
$stockSql = Join-Path $temp 'stockparts\biton_stockparts_import_no_triggers.sql'

Import-Sql $c.production.db $c.production.user $c.production.pass $prodSql
Import-Sql $c.stockparts.db $c.stockparts.user $c.stockparts.pass $stockSql
Import-Sql $c.techparts.db $c.techparts.user $c.techparts.pass $techSql

php (Join-Path $temp 'verify_counts.php')
Write-Host 'เสร็จสิ้น — ถ้า stockparts แจ้ง error VIEW ท้ายไฟล์ ให้ข้ามได้ (ข้อมูลตาราง stock import ครบแล้ว)'
