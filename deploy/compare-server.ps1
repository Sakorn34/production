param(
    [string]$ServerRoot = 'D:\AppServ\www\production\deploy\release\form server\production_31072569_1021'
)

$ErrorActionPreference = 'Stop'
$DeployDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $DeployDir '..')).Path
. (Join-Path $DeployDir 'deploy-common.ps1')

$missing = New-Object System.Collections.Generic.List[string]
$diff = New-Object System.Collections.Generic.List[string]

foreach ($rel in (Get-DeployableRelativePaths -Root $Root)) {
    $local = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
    $srv = Join-Path $ServerRoot ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
    if (-not (Test-Path -LiteralPath $local -PathType Leaf)) { continue }
    if (-not (Test-Path -LiteralPath $srv -PathType Leaf)) {
        $missing.Add($rel)
        continue
    }
    $lh = (Get-FileHash -LiteralPath $local -Algorithm MD5).Hash
    $sh = (Get-FileHash -LiteralPath $srv -Algorithm MD5).Hash
    if ($lh -ne $sh) { $diff.Add($rel) }
}

$toUpload = @($missing + $diff | Sort-Object -Unique)
Write-Host ""
Write-Host "Server snapshot: $ServerRoot"
Write-Host "Local source:    $Root"
Write-Host ""
Write-Host "Missing on server: $($missing.Count)"
Write-Host "Different content: $($diff.Count)"
Write-Host "Total to upload: $($toUpload.Count)"
Write-Host ""

if ($missing.Count) {
    Write-Host '--- Missing ---'
    $missing | Sort-Object | ForEach-Object { Write-Host $_ }
    Write-Host ''
}

if ($diff.Count) {
    Write-Host '--- Different ---'
    $diff | Sort-Object | ForEach-Object { Write-Host $_ }
}

$outFile = Join-Path $DeployDir 'server-diff-files.txt'
$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($outFile, ($toUpload -join [Environment]::NewLine), $utf8NoBom)
Write-Host ""
Write-Host "Saved list: $outFile"
