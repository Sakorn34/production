# build-patch.ps1 — สร้างชุด upload เฉพาะไฟล์ที่เปลี่ยน (patch)
#
# ใช้: deploy/build-patch.bat
#       deploy/build-patch.ps1 -IncludeFiles finishgoogs_ma_update/ma.php
# ผลลัพธ์:
#   deploy/release/production/           ← อัปแค่โฟลเดอร์นี้ (มีเฉพาะไฟล์ patch)
#   deploy/release/versions/YYYY-MM-DD_HHmmss/  ← เก็บประวัติแต่ละครั้ง

param(
    [switch]$OpenFolder,
    [switch]$MarkDeployed,
    [string]$Notes = '',
    [string[]]$IncludeFiles = @()
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
$VersionId = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$VersionDir = Join-Path $VersionsRoot $VersionId

$baseline = Read-DeployBaseline -BaselineFile $BaselineFile
$fromVersion = Get-PreviousVersionLabel -Baseline $baseline

$toCopy = New-Object System.Collections.Generic.List[string]
$toDelete = New-Object System.Collections.Generic.List[string]

if ($IncludeFiles.Count -gt 0) {
    foreach ($raw in $IncludeFiles) {
        $rel = ($raw -replace '\\', '/').Trim()
        if ($rel -eq '') { continue }
        if (-not (Test-IsDeployableRelativePath -RelativePath $rel)) {
            Write-Warning "Skip non-deployable path: $rel"
            continue
        }
        $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
        if (Test-Path -LiteralPath $full -PathType Leaf) {
            $toCopy.Add($rel)
        } else {
            Write-Warning "File not found: $rel"
        }
    }
} else {
    $candidates = Get-PatchCandidatePaths -Root $Root -Baseline $baseline -VersionsRoot $VersionsRoot

    foreach ($item in $candidates) {
        if ($item -like 'DELETE:*') {
            $rel = $item.Substring(7)
            if (Test-IsDeployableRelativePath -RelativePath $rel) {
                $toDelete.Add($rel)
            }
            continue
        }

        $rel = $item -replace '\\', '/'
        if (-not (Test-IsDeployableRelativePath -RelativePath $rel)) {
            continue
        }

        $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
        if (Test-Path -LiteralPath $full -PathType Leaf) {
            $toCopy.Add($rel)
        }
    }
}

if ($toCopy.Count -eq 0 -and $toDelete.Count -eq 0) {
    Write-Host ""
    Write-Host 'No changed files to upload' -ForegroundColor Yellow
    Write-Host ""
    Write-Host 'First time or full deploy: use build-release.bat'
    Write-Host 'Explicit files: build-patch.ps1 -IncludeFiles path/to/file.php'
    Write-Host 'After patch upload: run mark-deployed.bat'
    Write-Host ""
    exit 0
}

if (Test-Path -LiteralPath $Dest) {
    Remove-Item -LiteralPath $Dest -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $Dest | Out-Null
New-Item -ItemType Directory -Force -Path $VersionDir | Out-Null

foreach ($rel in $toCopy) {
    Copy-DeployFile -Root $Root -DestRoot $Dest -RelativePath $rel
}

$headCommit = Get-GitHeadCommit -Root $Root
$baselineLabel = if ($baseline -and $baseline.commit) { $baseline.commit } else { '(not marked yet)' }
$sortedCopy = @($toCopy | Sort-Object)
$sortedDelete = @($toDelete | Sort-Object)

$BeforeRoot = Join-Path $OutRoot 'before'
$beforeInfo = Write-DeployBeforeSnapshot -Root $Root -BeforeRoot $BeforeRoot -VersionsRoot $VersionsRoot `
    -Baseline $baseline -ChangedFiles $sortedCopy -DeletedFiles $sortedDelete

# ลบ compare/ รูปแบบเก่า (มี after ซ้ำ production)
$LegacyCompare = Join-Path $OutRoot 'compare'
if (Test-Path -LiteralPath $LegacyCompare) {
    Remove-Item -LiteralPath $LegacyCompare -Recurse -Force
}

$manifestLines = @(
    "# Patch v$VersionId",
    "# Commit: $headCommit",
    "# Baseline: $baselineLabel",
    '# Upload to httpdocs/production/ (keep folder structure)',
    ""
) + $sortedCopy

Set-Content -LiteralPath (Join-Path $Dest 'PATCH-MANIFEST.txt') -Value ($manifestLines -join [Environment]::NewLine) -Encoding UTF8

if ($sortedDelete.Count -gt 0) {
    $deleteLines = @(
        '# Deleted in project - remove on server manually if present',
        ""
    ) + $sortedDelete
    Set-Content -LiteralPath (Join-Path $Dest 'DELETE-ON-SERVER.txt') -Value ($deleteLines -join [Environment]::NewLine) -Encoding UTF8
}

Write-DeployReadme -DestRoot $Dest `
    -VersionId $VersionId `
    -ReleaseType 'Patch (changed files only)' `
    -FromVersion $fromVersion `
    -ToCommit $headCommit `
    -ChangedFiles $sortedCopy `
    -DeletedFiles $sortedDelete `
    -Notes $Notes `
    -FileCount $sortedCopy.Count | Out-Null

Append-DeployChangelog -ChangelogFile $ChangelogFile `
    -VersionId $VersionId `
    -ReleaseType 'Patch' `
    -FromVersion $fromVersion `
    -ToCommit $headCommit `
    -FileCount $sortedCopy.Count `
    -Notes $Notes `
    -ChangedFiles $sortedCopy

Copy-Item -LiteralPath $Dest -Destination (Join-Path $VersionDir 'production') -Recurse -Force
Copy-Item -LiteralPath (Join-Path $Dest 'README.html') -Destination (Join-Path $VersionDir 'README.html') -Force
if (Test-Path -LiteralPath $BeforeRoot) {
    Copy-Item -LiteralPath $BeforeRoot -Destination (Join-Path $VersionDir 'before') -Recurse -Force
}

Set-Content -LiteralPath (Join-Path $VersionDir 'info.txt') -Value @(
    "version=$VersionId",
    "type=patch",
    "created=$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')",
    "commit=$headCommit",
    "baseline=$baselineLabel",
    "from=$fromVersion",
    "files=$($sortedCopy.Count)",
    "deleted=$($sortedDelete.Count)"
) -Encoding UTF8

Write-LastBuildInfo -BuildInfoFile $BuildInfoFile `
    -VersionId $VersionId `
    -ReleaseType 'Patch' `
    -Commit $headCommit `
    -Notes $Notes `
    -FileCount $sortedCopy.Count `
    -FileHashes $(Build-PatchFileHashMap -Root $Root -RelativePaths $sortedCopy)

if ($MarkDeployed) {
    Invoke-MarkDeployed -DeployDir $DeployDir -Root $Root | Out-Null
}

Write-Host ""
Write-Host 'Patch upload ready' -ForegroundColor Green
Write-Host "  Version: v$VersionId"
Write-Host "  Folder:  $Dest"
Write-Host "  Files:   $($sortedCopy.Count)"
Write-Host "  Before:  $BeforeRoot  ($($beforeInfo.beforeFound) file(s))"
if ($sortedDelete.Count -gt 0) {
    Write-Host "  Delete on server: $($sortedDelete.Count) (see DELETE-ON-SERVER.txt)" -ForegroundColor Yellow
}
Write-Host "  Archive: $VersionDir"
Write-Host ""
Write-Host 'Next steps:' -ForegroundColor Cyan
Write-Host '  1. Open README.html in deploy/release/production/'
Write-Host '  2. FTP deploy/release/production/ (skip README.html on server)'
if (-not $MarkDeployed) {
    Write-Host '  3. After upload: build-patch.bat will auto-mark, or run mark-deployed.bat'
}
Write-Host ""

if ($OpenFolder) {
    Start-Process explorer.exe $Dest
}
