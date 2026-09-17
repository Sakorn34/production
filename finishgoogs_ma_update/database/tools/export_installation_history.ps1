# export_installation_history.ps1 — อ่านไฟล์ Access ของระบบ installation เดิม แล้วเขียน CSV (UTF-8)
#
# ใช้บนเครื่องที่เปิดไฟล์ .mdb ได้ (มี Microsoft Access Database Engine / ACE OLEDB)
# อ่านอย่างเดียว (Mode=Read) ไม่แก้ไฟล์ต้นทาง
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File export_installation_history.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File export_installation_history.ps1 -Mdb "\\192.168.2.199\...\installation.mdb" -Out "C:\temp\installation_history.csv"
#
# ได้ไฟล์แล้วนำเข้าที่หลังบ้าน Production → "ประวัติติดตั้งระบบเดิม" (หรือ import_installation_history.php บน dev)
# การแยก serial จริงออกจากช่องที่พิมพ์อิสระทำฝั่ง PHP ตอนนำเข้า ไฟล์นี้ส่งค่าดิบไปทั้งหมด

param(
    [string]$Mdb = 'D:\AppServ\www\installation\installation.mdb',
    [string]$Out = (Join-Path $PSScriptRoot 'installation_history.csv')
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path $Mdb)) { throw "ไม่พบไฟล์ $Mdb" }

$providers = @('Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0')
$cn = $null
foreach ($p in $providers) {
    try {
        $cn = New-Object System.Data.OleDb.OleDbConnection("Provider=$p;Data Source=$Mdb;Mode=Read;")
        $cn.Open()
        break
    } catch { $cn = $null }
}
if (-not $cn) { throw 'เปิดไฟล์ Access ไม่ได้ — ต้องติดตั้ง Microsoft Access Database Engine (ACE OLEDB) แบบ 64-bit' }

# serial_number.sn_IDinstallation เป็นข้อความ ส่วน installation.id เป็นตัวเลข จึงต้อง CStr ตอน join
$sql = @"
SELECT i.id AS install_id, i.in_date, s.sn_serial, s.sn_product, s.sn_invoice,
       i.in_invoice, i.in_namesend, i.in_security, i.in_company_sec, i.in_end_user,
       i.in_area, i.in_status, i.in_product, i.in_model
FROM serial_number s INNER JOIN installation i ON CStr(i.id) = s.sn_IDinstallation
ORDER BY i.id, s.sn_id
"@
$cmd = $cn.CreateCommand()
$cmd.CommandText = $sql
$r = $cmd.ExecuteReader()

$rows = New-Object System.Collections.Generic.List[object]
while ($r.Read()) {
    $date = ''
    if (-not $r.IsDBNull(1)) { $date = ([datetime]$r.GetValue(1)).ToString('yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture) }
    $rows.Add([pscustomobject]@{
        install_id     = [string]$r.GetValue(0)
        install_date   = $date
        serial_raw     = [string]$r.GetValue(2)
        sn_product     = [string]$r.GetValue(3)
        sn_invoice     = [string]$r.GetValue(4)
        in_invoice     = [string]$r.GetValue(5)
        in_namesend    = [string]$r.GetValue(6)
        in_security    = [string]$r.GetValue(7)
        in_company_sec = [string]$r.GetValue(8)
        in_end_user    = [string]$r.GetValue(9)
        in_area        = [string]$r.GetValue(10)
        in_status      = [string]$r.GetValue(11)
        in_product     = [string]$r.GetValue(12)
        in_model       = [string]$r.GetValue(13)
    })
}
$r.Close()
$cn.Close()

$rows | Export-Csv -Path $Out -NoTypeInformation -Encoding UTF8
Write-Host ("เขียน {0:N0} แถว → {1}" -f $rows.Count, $Out)

# ไฟล์บีบอัด (.gz) สำหรับอัปโหลดขึ้น server — CSV เต็มราว 4 MB เกินขนาดอัปโหลดของ PHP ที่ตั้งไว้ทั่วไป
$gz = "$Out.gz"
$src = [IO.File]::OpenRead($Out)
$dst = [IO.File]::Create($gz)
$zip = New-Object IO.Compression.GZipStream($dst, [IO.Compression.CompressionMode]::Compress)
$src.CopyTo($zip)
$zip.Dispose(); $dst.Dispose(); $src.Dispose()
Write-Host ("บีบอัดสำหรับอัปโหลด → {0} ({1:N0} KB)" -f $gz, ((Get-Item $gz).Length / 1KB))
