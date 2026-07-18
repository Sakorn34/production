# สร้าง MySQL users สำหรับ localhost dev
# รัน: .\setup_localhost.ps1
# ต้องมีสิทธิ root MySQL บนเครื่องนี้

$ErrorActionPreference = 'Stop'
$mysql = 'D:\AppServ\MySQL\bin\mysql.exe'
$sqlFile = Join-Path $PSScriptRoot 'setup_localhost_users.sql'

if (-not (Test-Path $mysql)) {
    Write-Error "ไม่พบ mysql.exe ที่ $mysql"
}
if (-not (Test-Path $sqlFile)) {
    Write-Error "ไม่พบ $sqlFile"
}

$rootPass = Read-Host 'MySQL root password (Enter ถ้าไม่มี)'
$args = @('-u', 'root')
if ($rootPass) { $args += @("-p$rootPass") }

Write-Host 'กำลังรัน setup_localhost_users.sql ...'
Get-Content $sqlFile -Raw | & $mysql @args 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error 'setup ล้มเหลว — ตรวจ root password และว่า import DB ทั้ง 3 ตัวแล้ว'
}

Write-Host 'ทดสอบ connection ...'
php (Join-Path (Split-Path $PSScriptRoot -Parent) 'test_db_connections.php')
exit $LASTEXITCODE
