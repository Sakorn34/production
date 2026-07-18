# FTP Download Script
# วิธีใช้: ./sync-download.ps1

param(
    [string]$remotePath = "/",
    [switch]$recursive = $true
)

# อ่านค่า config จาก sftp.json
$config = Get-Content ".\sftp.json" | ConvertFrom-Json

$ftpHost = $config.host
$ftpUser = $config.username
$ftpPass = $config.password
$localPath = (Get-Location).Path

function Download-FtpDirectory {
    param(
        [string]$remotePath,
        [string]$localPath,
        [string]$ftpHost,
        [string]$ftpUser,
        [string]$ftpPass
    )
    
    $uri = "ftp://$ftpHost$remotePath"
    $credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
    
    try {
        $request = [System.Net.FtpWebRequest]::Create($uri)
        $request.Credentials = $credentials
        $request.UseBinary = $true
        $request.UsePassive = $true
        $request.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails
        
        $response = $request.GetResponse()
        $stream = $response.GetResponseStream()
        $reader = New-Object IO.StreamReader($stream)
        $listing = @()
        
        while (($line = $reader.ReadLine()) -ne $null) {
            $listing += $line
        }
        $reader.Close()
        $response.Close()
        
        foreach ($item in $listing) {
            $parts = $item -split '\s+', 9
            $type = $parts[0]
            $name = $parts[-1]
            
            if ([string]::IsNullOrWhiteSpace($name)) { continue }
            
            if ($type -like 'd*' -and $recursive) {
                # สร้าง folder ในเครื่อง
                $localDir = Join-Path $localPath $name
                if (!(Test-Path $localDir)) {
                    New-Item -ItemType Directory -Path $localDir -Force -ErrorAction SilentlyContinue | Out-Null
                    Write-Host "[📁] สร้าง: $name" -ForegroundColor Cyan
                }
                # ดาวน์โหลด recursive
                Download-FtpDirectory -remotePath "$remotePath$name/" -localPath $localDir -ftpHost $ftpHost -ftpUser $ftpUser -ftpPass $ftpPass
            } 
            elseif ($type -like '-*') {
                # ดาวน์โหลดไฟล์
                $remoteFile = "ftp://$ftpHost$remotePath$name"
                $localFile = Join-Path $localPath $name
                
                $fileRequest = [System.Net.FtpWebRequest]::Create($remoteFile)
                $fileRequest.Credentials = $credentials
                $fileRequest.UseBinary = $true
                $fileRequest.UsePassive = $true
                $fileRequest.Method = [System.Net.WebRequestMethods+Ftp]::DownloadFile
                
                $fileResponse = $fileRequest.GetResponse()
                $fileStream = $fileResponse.GetResponseStream()
                $localStream = [IO.File]::Create($localFile)
                $fileStream.CopyTo($localStream)
                $localStream.Close()
                $fileResponse.Close()
                
                Write-Host "[✓] ดาวน์โหลด: $name" -ForegroundColor Green
            }
        }
    }
    catch {
        Write-Host "[❌] Error: $_" -ForegroundColor Red
    }
}

Write-Host "🚀 เริ่มดาวน์โหลดจาก FTP Server..." -ForegroundColor Yellow
Write-Host "Host: $ftpHost | User: $ftpUser" -ForegroundColor Gray

Download-FtpDirectory -remotePath $remotePath -localPath $localPath -ftpHost $ftpHost -ftpUser $ftpUser -ftpPass $ftpPass

Write-Host "`n✅ ดาวน์โหลดเสร็จแล้ว!" -ForegroundColor Green
