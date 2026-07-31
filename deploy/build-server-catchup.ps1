$ErrorActionPreference = 'Stop'
$DeployDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$files = Get-Content -LiteralPath (Join-Path $DeployDir 'server-diff-files.txt') |
    Where-Object { $_.Trim() -ne '' }

. (Join-Path $DeployDir 'build-patch.ps1') `
    -IncludeFiles $files `
    -Notes 'Catch-up vs server production_31072569_1021: 62 files (5 missing + 57 diff)'
