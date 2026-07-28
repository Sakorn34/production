# mark-deployed.ps1 — บันทึกว่าอัป patch ล่าสุดแล้ว (ใช้เป็นฐานครั้งถัดไป)

$ErrorActionPreference = 'Stop'
$DeployDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $DeployDir '..')).Path
$Common = Join-Path $DeployDir 'deploy-common.ps1'

. $Common

$result = Invoke-MarkDeployed -DeployDir $DeployDir -Root $Root

Write-Host ""
Write-Host 'Deploy baseline saved' -ForegroundColor Green
Write-Host "  version: $($result.version)"
Write-Host "  commit:  $($result.commit)"
Write-Host "  files:   $($result.fileCount)"
Write-Host "  file:    $($result.baselineFile)"
Write-Host ""
Write-Host 'Next build-patch will include only files changed after this point'
Write-Host ""
