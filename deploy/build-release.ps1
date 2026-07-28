# build-release.ps1 — สร้างโฟลเดอร์ upload ครบชุด (ครั้งแรก / อัปทั้งระบบ)
#
# ใช้: deploy/build-release.bat
# ผลลัพธ์: deploy/release/production/  ← อัปทั้งโฟลเดอร์นี้ขึ้น httpdocs/production

param(
    [switch]$OpenFolder,
    [switch]$MarkDeployed,
    [string]$Notes = ''
)

$ErrorActionPreference = 'Stop'
$DeployDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $DeployDir '..')).Path
$Common = Join-Path $DeployDir 'deploy-common.ps1'

if (-not (Test-Path -LiteralPath $Common)) {
    throw 'deploy-common.ps1 not found'
}

. $Common

$OutRoot = Join-Path $DeployDir 'release'
$Dest = Join-Path $OutRoot 'production'
$VersionsRoot = Join-Path $OutRoot 'versions'
$BaselineFile = Join-Path $DeployDir '.last-deploy.json'
$BuildInfoFile = Join-Path $DeployDir '.last-build.json'
$ChangelogFile = Join-Path $DeployDir 'CHANGELOG.txt'
$VersionId = (Get-Date -Format 'yyyy-MM-dd_HHmmss') + '-full'
$VersionDir = Join-Path $VersionsRoot $VersionId

$baseline = Read-DeployBaseline -BaselineFile $BaselineFile
$fromVersion = Get-PreviousVersionLabel -Baseline $baseline
$headCommit = Get-GitHeadCommit -Root $Root

if (Test-Path -LiteralPath $OutRoot) {
    Remove-Item -LiteralPath $OutRoot -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $Dest | Out-Null
New-Item -ItemType Directory -Force -Path $VersionDir | Out-Null

Invoke-FullReleaseCopy -Root $Root -Dest $Dest

$deployableFiles = @(Get-DeployableRelativePaths -Root $Root)
$fileCount = $deployableFiles.Count

Write-DeployReadme -DestRoot $Dest `
    -VersionId $VersionId `
    -ReleaseType 'Full (first deploy or full update)' `
    -FromVersion $fromVersion `
    -ToCommit $headCommit `
    -ChangedFiles $deployableFiles `
    -DeletedFiles @() `
    -Notes $Notes `
    -FileCount $fileCount | Out-Null

Append-DeployChangelog -ChangelogFile $ChangelogFile `
    -VersionId $VersionId `
    -ReleaseType 'Full' `
    -FromVersion $fromVersion `
    -ToCommit $headCommit `
    -FileCount $fileCount `
    -Notes $Notes `
    -ChangedFiles $deployableFiles

Copy-Item -LiteralPath $Dest -Destination (Join-Path $VersionDir 'production') -Recurse -Force
Copy-Item -LiteralPath (Join-Path $Dest 'README.html') -Destination (Join-Path $VersionDir 'README.html') -Force

Set-Content -LiteralPath (Join-Path $VersionDir 'info.txt') -Value @(
    "version=$VersionId",
    "type=full",
    "created=$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')",
    "commit=$headCommit",
    "from=$fromVersion",
    "files=$fileCount"
) -Encoding UTF8

Write-LastBuildInfo -BuildInfoFile $BuildInfoFile `
    -VersionId $VersionId `
    -ReleaseType 'Full' `
    -Commit $headCommit `
    -Notes $Notes `
    -FileCount $fileCount

if ($MarkDeployed) {
    Invoke-MarkDeployed -DeployDir $DeployDir -Root $Root | Out-Null
}

Write-Host ""
Write-Host 'Full release ready' -ForegroundColor Green
Write-Host "  Version: v$VersionId"
Write-Host "  Folder:  $Dest"
Write-Host "  Files:   $fileCount"
Write-Host "  Archive: $VersionDir"
Write-Host ""
Write-Host 'Next steps:' -ForegroundColor Cyan
Write-Host '  1. Open README.html in deploy/release/production/'
Write-Host '  2. FTP deploy/release/production/ (skip README.html on server)'
Write-Host '  3. Upload secrets from deploy/hostatom/ separately'
if (-not $MarkDeployed) {
    Write-Host '  4. After upload: build-release.bat will auto-mark, or run mark-deployed.bat'
}
Write-Host '  5. Use build-patch.bat for future updates'
Write-Host ""

if ($OpenFolder) {
    Start-Process explorer.exe $Dest
}
