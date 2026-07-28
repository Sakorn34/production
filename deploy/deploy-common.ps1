# deploy-common.ps1 — กฎร่วมสำหรับ build release / patch
#
# dot-source จาก build-release.ps1 และ build-patch.ps1

$script:DeployAppDirs = @('shared', 'finishgoogs_ma_update', 'parts')
$script:DeployRootFiles = @('.htaccess')

$script:DeployExcludeDirNames = @(
    '.git', '.cursor', '.vscode', 'secrets', 'deploy', 'release',
    '_archive', '_db_import_temp', 'node_modules', 'Delete',
    'tools', 'import_log', 'import', 'logs', 'uploads'
)

$script:DeployExcludeFilePatterns = @(
    '*.secrets.php', 'config.paths.php', 'config.local.php', '.env', 'sftp.json',
    '*.log', '*.sql', '*.sql.zip', '*.bat', 'cnf_*.tmp', 'Appscript*.txt',
    '.gitignore', '*.tmp', 'Thumbs.db', 'desktop.ini',
    'README.html', 'README.txt', 'PATCH-MANIFEST.txt', 'DELETE-ON-SERVER.txt', 'UPLOAD-GUIDE.txt'
)

$script:DeployStripAfterCopy = @(
    'finishgoogs_ma_update\database\tools',
    'finishgoogs_ma_update\database\import_log',
    'finishgoogs_ma_update\database\import',
    'finishgoogs_ma_update\logs',
    'parts\database\tools',
    'parts\database\import_log',
    'parts\database\import',
    'parts\logs'
)

$script:DeployGuardFiles = @(
    'finishgoogs_ma_update\uploads\.htaccess',
    'parts\logs\.htaccess',
    'finishgoogs_ma_update\database\.htaccess',
    'parts\database\.htaccess'
)

function Test-DeployExcludeFileName {
    param(
        [string]$FileName
    )

    foreach ($pattern in $script:DeployExcludeFilePatterns) {
        if ($FileName -like $pattern) {
            return $true
        }
    }
    return $false
}

function Test-IsDeployableRelativePath {
    param(
        [string]$RelativePath
    )

    if ([string]::IsNullOrWhiteSpace($RelativePath)) {
        return $false
    }

    $normalized = ($RelativePath -replace '\\', '/').TrimStart('./')
    $segments = $normalized -split '/'

    foreach ($seg in $segments) {
        if ($script:DeployExcludeDirNames -contains $seg) {
            return $false
        }
    }

    $fileName = Split-Path -Leaf $normalized
    if (Test-DeployExcludeFileName -FileName $fileName) {
        return $false
    }

    if ($normalized -eq '.htaccess') {
        return $true
    }

    $top = $segments[0]
    return ($script:DeployAppDirs -contains $top)
}

function Get-DeployableRelativePaths {
    param(
        [string]$Root
    )

    $results = New-Object System.Collections.Generic.List[string]

    foreach ($name in $script:DeployRootFiles) {
        $full = Join-Path $Root $name
        if (Test-Path -LiteralPath $full -PathType Leaf) {
            $results.Add($name)
        }
    }

    foreach ($dirName in $script:DeployAppDirs) {
        $base = Join-Path $Root $dirName
        if (-not (Test-Path -LiteralPath $base)) {
            continue
        }

        Get-ChildItem -LiteralPath $base -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($Root.Length).TrimStart('\', '/')
            if (Test-IsDeployableRelativePath -RelativePath $rel) {
                $results.Add(($rel -replace '\\', '/'))
            }
        }
    }

    return $results | Sort-Object -Unique
}

function Get-FileHashShort {
    param(
        [string]$FilePath
    )

    if (-not (Test-Path -LiteralPath $FilePath -PathType Leaf)) {
        return $null
    }

    $hash = (Get-FileHash -LiteralPath $FilePath -Algorithm SHA256).Hash
    return $hash.Substring(0, 12)
}

function Read-DeployBaseline {
    param(
        [string]$BaselineFile
    )

    if (-not (Test-Path -LiteralPath $BaselineFile)) {
        return $null
    }

    try {
        return Get-Content -LiteralPath $BaselineFile -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Write-Warning 'Cannot read baseline; using git compare mode'
        return $null
    }
}

function Write-DeployBaseline {
    param(
        [string]$BaselineFile,
        [string]$Root,
        [string]$Commit,
        [hashtable]$FileHashes,
        [string]$Version = '',
        [string]$ReleaseType = ''
    )

    $payload = [ordered]@{
        commit      = $Commit
        builtAt     = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        version     = $Version
        releaseType = $ReleaseType
        files       = $FileHashes
    }

    $json = $payload | ConvertTo-Json -Depth 5
    Set-Content -LiteralPath $BaselineFile -Value $json -Encoding UTF8
}

function Write-LastBuildInfo {
    param(
        [string]$BuildInfoFile,
        [string]$VersionId,
        [string]$ReleaseType,
        [string]$Commit,
        [string]$Notes,
        [int]$FileCount,
        [hashtable]$FileHashes = @{}
    )

    $filesObj = [ordered]@{}
    foreach ($key in ($FileHashes.Keys | Sort-Object)) {
        $filesObj[$key] = $FileHashes[$key]
    }

    $payload = [ordered]@{
        version     = $VersionId
        releaseType = $ReleaseType
        commit      = $Commit
        builtAt     = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        fileCount   = $FileCount
        notes       = $Notes
        files       = $filesObj
    }

    $payload | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $BuildInfoFile -Encoding UTF8
}

function Get-GitHeadCommit {
    param(
        [string]$Root
    )

    Push-Location $Root
    try {
        $hash = Invoke-GitNames { git rev-parse --short HEAD } | Select-Object -First 1
        if (-not $hash) {
            return 'unknown'
        }
        return $hash.Trim()
    } finally {
        Pop-Location
    }
}

function Invoke-GitNames {
    param(
        [scriptblock]$Command
    )

    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    try {
        $out = & $Command 2>$null
        if ($LASTEXITCODE -ne 0) {
            return @()
        }
        if (-not $out) {
            return @()
        }
        if ($out -is [string]) {
            return @($out)
        }
        return @($out)
    } finally {
        $ErrorActionPreference = $prev
    }
}

function Get-GitChangedRelativePaths {
    param(
        [string]$Root,
        [string]$SinceCommit
    )

    Push-Location $Root
    try {
        $paths = New-Object System.Collections.Generic.List[string]

        if ($SinceCommit) {
            $fromGit = Invoke-GitNames { git diff --name-only "$SinceCommit" HEAD }
            if ($fromGit.Count -gt 0) {
                $paths.AddRange([string[]]$fromGit)
            }
        }

        foreach ($cmd in @(
            { git diff --name-only HEAD },
            { git diff --name-only --cached HEAD },
            { git ls-files --others --exclude-standard }
        )) {
            $out = Invoke-GitNames $cmd
            if ($out.Count -gt 0) {
                $paths.AddRange([string[]]$out)
            }
        }

        return $paths | ForEach-Object { $_ -replace '\\', '/' } | Sort-Object -Unique
    } finally {
        Pop-Location
    }
}

function Test-DeployFileChangedSinceBaseline {
    param(
        [string]$Root,
        [object]$Baseline,
        [string]$RelativePath
    )

    if (-not ($Baseline -and $Baseline.files)) {
        return $true
    }

    $rel = ($RelativePath -replace '\\', '/')
    $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)

    if (-not (Test-Path -LiteralPath $full)) {
        return $Baseline.files.PSObject.Properties.Name -contains $rel
    }

    $hash = Get-FileHashShort -FilePath $full
    $oldHash = $Baseline.files.$rel

    if ($null -eq $oldHash -or $oldHash -eq '') {
        return $true
    }

    return ($hash -ne $oldHash)
}

function Get-VersionProductionFilesMap {
    param(
        [string]$ProductionRoot
    )

    $map = @{}
    if (-not (Test-Path -LiteralPath $ProductionRoot)) {
        return $map
    }

    Get-ChildItem -LiteralPath $ProductionRoot -Recurse -File | ForEach-Object {
        $name = $_.Name
        if ($name -in @('README.html', 'README.txt', 'PATCH-MANIFEST.txt', 'DELETE-ON-SERVER.txt', 'UPLOAD-GUIDE.txt')) {
            return
        }
        $rel = $_.FullName.Substring($ProductionRoot.Length).TrimStart('\', '/').Replace('\', '/')
        if (Test-IsDeployableRelativePath -RelativePath $rel) {
            $map[$rel] = Get-FileHashShort -FilePath $_.FullName
        }
    }

    return $map
}

function Get-PendingUploadFilesMap {
    param(
        [string]$VersionsRoot,
        [object]$Baseline
    )

    if (-not (Test-Path -LiteralPath $VersionsRoot)) {
        return @{}
    }

    $since = $null
    if ($Baseline -and $Baseline.version) {
        $since = [string]$Baseline.version
    }

    $versionDirs = Get-ChildItem -LiteralPath $VersionsRoot -Directory | Sort-Object Name
    $merged = @{}

    foreach ($dir in $versionDirs) {
        if ($since -and $dir.Name -le $since) {
            continue
        }
        $prod = Join-Path $dir.FullName 'production'
        $files = Get-VersionProductionFilesMap -ProductionRoot $prod
        foreach ($rel in $files.Keys) {
            $merged[$rel] = $files[$rel]
        }
    }

    return $merged
}

function Add-PendingUploadReconciliationCandidates {
    param(
        [System.Collections.Generic.List[string]]$Candidates,
        [string]$Root,
        [string]$VersionsRoot,
        [object]$Baseline
    )

    $pending = Get-PendingUploadFilesMap -VersionsRoot $VersionsRoot -Baseline $Baseline
    if ($pending.Count -eq 0) {
        return
    }

    $existing = @{}
    foreach ($item in $Candidates) {
        if ($item -like 'DELETE:*') {
            $existing[$item.Substring(7)] = $true
        } else {
            $existing[$item] = $true
        }
    }

    foreach ($rel in ($pending.Keys | Sort-Object)) {
        $uploadedHash = $pending[$rel]
        $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)

        if (-not (Test-Path -LiteralPath $full)) {
            $del = "DELETE:$rel"
            if (-not $existing.ContainsKey($rel) -and -not ($Candidates -contains $del)) {
                $Candidates.Add($del)
            }
            continue
        }

        $currentHash = Get-FileHashShort -FilePath $full
        if ($currentHash -ne $uploadedHash -and -not $existing.ContainsKey($rel)) {
            $Candidates.Add($rel)
        }
    }
}

function Build-PatchFileHashMap {
    param(
        [string]$Root,
        [string[]]$RelativePaths
    )

    $map = @{}
    foreach ($rel in $RelativePaths) {
        $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
        if (Test-Path -LiteralPath $full -PathType Leaf) {
            $map[$rel] = Get-FileHashShort -FilePath $full
        }
    }
    return $map
}

function Get-PatchCandidatePaths {
    param(
        [string]$Root,
        [object]$Baseline,
        [string]$VersionsRoot = ''
    )

    $candidates = New-Object System.Collections.Generic.List[string]

    # มี baseline hash แล้ว — ใช้เทียบ hash เท่านั้น (ไม่ใช้ git diff ที่ยังเห็นไฟล์ uncommitted ซ้ำ)
    if ($Baseline -and $Baseline.files) {
        foreach ($rel in (Get-DeployableRelativePaths -Root $Root)) {
            if (Test-DeployFileChangedSinceBaseline -Root $Root -Baseline $Baseline -RelativePath $rel) {
                $candidates.Add($rel)
            }
        }

        foreach ($rel in $Baseline.files.PSObject.Properties.Name) {
            $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
            if (-not (Test-Path -LiteralPath $full)) {
                $candidates.Add("DELETE:$rel")
            }
        }

        if ($VersionsRoot -eq '') {
            $VersionsRoot = Join-Path $Root 'deploy\release\versions'
        }
        Add-PendingUploadReconciliationCandidates -Candidates $candidates -Root $Root `
            -VersionsRoot $VersionsRoot -Baseline $Baseline

        return $candidates | Sort-Object -Unique
    }

    if ($Baseline -and $Baseline.commit) {
        $gitChanged = Get-GitChangedRelativePaths -Root $Root -SinceCommit $Baseline.commit
        $candidates.AddRange([string[]]$gitChanged)
    } else {
        Push-Location $Root
        try {
            $lastCommit = Invoke-GitNames { git rev-parse --short HEAD~1 } | Select-Object -First 1
            if ($lastCommit) {
                $gitChanged = Get-GitChangedRelativePaths -Root $Root -SinceCommit $lastCommit.Trim()
                $candidates.AddRange([string[]]$gitChanged)
            } else {
                $gitChanged = Get-GitChangedRelativePaths -Root $Root -SinceCommit $null
                $candidates.AddRange([string[]]$gitChanged)
            }
        } finally {
            Pop-Location
        }
    }

    return $candidates | Sort-Object -Unique
}

function Copy-DeployFile {
    param(
        [string]$Root,
        [string]$DestRoot,
        [string]$RelativePath
    )

    $src = Join-Path $Root ($RelativePath -replace '/', [IO.Path]::DirectorySeparatorChar)
    $dst = Join-Path $DestRoot ($RelativePath -replace '/', [IO.Path]::DirectorySeparatorChar)
    $dstDir = Split-Path -Parent $dst

    if (-not (Test-Path -LiteralPath $dstDir)) {
        New-Item -ItemType Directory -Force -Path $dstDir | Out-Null
    }

    Copy-Item -LiteralPath $src -Destination $dst -Force
}

function Get-BaselineArchiveProductionRoot {
    param(
        [string]$VersionsRoot,
        [object]$Baseline
    )

    if (-not ($Baseline -and $Baseline.version)) {
        return $null
    }

    $version = [string]$Baseline.version
    $candidates = @(
        (Join-Path (Join-Path $VersionsRoot $version) 'production'),
        (Join-Path (Join-Path $VersionsRoot ("v$version")) 'production')
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Container) {
            return $candidate
        }
    }

    return $null
}

function Export-GitBlobToFile {
    param(
        [string]$Root,
        [string]$Commit,
        [string]$RelativePath,
        [string]$DestPath
    )

    if ([string]::IsNullOrWhiteSpace($Commit) -or $Commit -eq 'unknown') {
        return $false
    }

    $rel = ($RelativePath -replace '\\', '/')
    $destDir = Split-Path -Parent $DestPath
    if ($destDir -and -not (Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Force -Path $destDir | Out-Null
    }

    Push-Location $Root
    try {
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = 'git'
        $psi.Arguments = "show `"${Commit}:${rel}`""
        $psi.WorkingDirectory = $Root
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.UseShellExecute = $false
        $psi.CreateNoWindow = $true
        $proc = [System.Diagnostics.Process]::Start($psi)
        $fs = [System.IO.File]::Create($DestPath)
        try {
            $proc.StandardOutput.BaseStream.CopyTo($fs)
        } finally {
            $fs.Close()
        }
        $proc.WaitForExit()
        if ($proc.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $DestPath -PathType Leaf)) {
            if (Test-Path -LiteralPath $DestPath) {
                Remove-Item -LiteralPath $DestPath -Force -ErrorAction SilentlyContinue
            }
            return $false
        }
        return $true
    } catch {
        return $false
    } finally {
        Pop-Location
    }
}

function Resolve-BaselineSourceFile {
    param(
        [string]$Root,
        [string]$VersionsRoot,
        [object]$Baseline,
        [string]$RelativePath,
        [string]$BeforeDestPath
    )

    $rel = ($RelativePath -replace '\\', '/')
    $archiveRoot = Get-BaselineArchiveProductionRoot -VersionsRoot $VersionsRoot -Baseline $Baseline
    if ($archiveRoot) {
        $archFile = Join-Path $archiveRoot ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
        if (Test-Path -LiteralPath $archFile -PathType Leaf) {
            return $archFile
        }
    }

    if ($Baseline -and $Baseline.commit) {
        if (Export-GitBlobToFile -Root $Root -Commit $Baseline.commit -RelativePath $rel -DestPath $BeforeDestPath) {
            return $BeforeDestPath
        }
    }

    return $null
}

function Copy-DeployFileToDestRoot {
    param(
        [string]$SourceFile,
        [string]$DestRoot,
        [string]$RelativePath
    )

    $rel = ($RelativePath -replace '\\', '/')
    $dst = Join-Path $DestRoot ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
    $dstDir = Split-Path -Parent $dst
    if ($dstDir -and -not (Test-Path -LiteralPath $dstDir)) {
        New-Item -ItemType Directory -Force -Path $dstDir | Out-Null
    }
    Copy-Item -LiteralPath $SourceFile -Destination $dst -Force
}

function Write-DeployBeforeSnapshot {
    param(
        [string]$Root,
        [string]$BeforeRoot,
        [string]$VersionsRoot,
        [object]$Baseline,
        [string[]]$ChangedFiles,
        [string[]]$DeletedFiles = @()
    )

    if (Test-Path -LiteralPath $BeforeRoot) {
        Remove-Item -LiteralPath $BeforeRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Force -Path $BeforeRoot | Out-Null

    $manifest = New-Object System.Collections.Generic.List[string]
    $manifest.Add('# before = files before edit (baseline)')
    $manifest.Add('# production/ = files after edit (upload to server)')
    $manifest.Add('# Compare before/ vs production/ in editor - do NOT upload before/ to server')
    $manifest.Add('')

    $beforeOk = 0
    $beforeMissing = 0
    $archiveRoot = Get-BaselineArchiveProductionRoot -VersionsRoot $VersionsRoot -Baseline $Baseline
    if ($archiveRoot) {
        $manifest.Add("# source: archive $($Baseline.version)")
    } elseif ($Baseline -and $Baseline.commit) {
        $manifest.Add("# source: git commit $($Baseline.commit)")
    } else {
        $manifest.Add('# source: none (new files have no before copy)')
    }
    $manifest.Add('')

    foreach ($rel in ($ChangedFiles | Sort-Object -Unique)) {
        $relNorm = $rel -replace '\\', '/'
        $beforeDest = Join-Path $BeforeRoot ($relNorm -replace '/', [IO.Path]::DirectorySeparatorChar)
        $beforeSrc = Resolve-BaselineSourceFile -Root $Root -VersionsRoot $VersionsRoot -Baseline $Baseline `
            -RelativePath $relNorm -BeforeDestPath $beforeDest

        if ($beforeSrc) {
            if ($beforeSrc -ne $beforeDest) {
                Copy-DeployFileToDestRoot -SourceFile $beforeSrc -DestRoot $BeforeRoot -RelativePath $relNorm
            }
            $manifest.Add("OK   $relNorm")
            $beforeOk++
        } else {
            $manifest.Add("NEW  $relNorm  (new file - no before)")
            $beforeMissing++
        }
    }

    foreach ($rel in ($DeletedFiles | Sort-Object -Unique)) {
        $relNorm = $rel -replace '\\', '/'
        $beforeDest = Join-Path $BeforeRoot ($relNorm -replace '/', [IO.Path]::DirectorySeparatorChar)
        $beforeSrc = Resolve-BaselineSourceFile -Root $Root -VersionsRoot $VersionsRoot -Baseline $Baseline `
            -RelativePath $relNorm -BeforeDestPath $beforeDest

        if ($beforeSrc) {
            if ($beforeSrc -ne $beforeDest) {
                Copy-DeployFileToDestRoot -SourceFile $beforeSrc -DestRoot $BeforeRoot -RelativePath $relNorm
            }
            $manifest.Add("DEL  $relNorm  (deleted in project - before only)")
            $beforeOk++
        } else {
            $manifest.Add("DEL? $relNorm  (deleted - before not found)")
            $beforeMissing++
        }
    }

    $manifest.Add('')
    $manifest.Add("before found: $beforeOk | before missing: $beforeMissing | production: $($ChangedFiles.Count)")

    Set-Content -LiteralPath (Join-Path $BeforeRoot 'README.txt') -Value ($manifest -join [Environment]::NewLine) -Encoding UTF8

    return [ordered]@{
        beforeFound     = $beforeOk
        beforeMissing   = $beforeMissing
        productionCount = $ChangedFiles.Count
        archiveRoot     = $archiveRoot
    }
}

function Remove-ReleasePath {
    param(
        [string]$DestRoot,
        [string]$RelativePath
    )

    $target = Join-Path $DestRoot ($RelativePath -replace '\\', [IO.Path]::DirectorySeparatorChar)
    if (Test-Path -LiteralPath $target) {
        Remove-Item -LiteralPath $target -Recurse -Force
    }
}

function Invoke-FullReleaseCopy {
    param(
        [string]$Root,
        [string]$Dest
    )

    foreach ($name in $script:DeployRootFiles) {
        $src = Join-Path $Root $name
        if (Test-Path -LiteralPath $src) {
            Copy-Item -LiteralPath $src -Destination $Dest -Force
        }
    }

    foreach ($dir in $script:DeployAppDirs) {
        $src = Join-Path $Root $dir
        $dst = Join-Path $Dest $dir
        if (-not (Test-Path -LiteralPath $src)) {
            Write-Warning "Skip (not found): $dir"
            continue
        }

        $robocopyArgs = @(
            $src, $dst,
            '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NC', '/NS', '/NP'
        )
        foreach ($excludeDir in $script:DeployExcludeDirNames) {
            $robocopyArgs += '/XD'
            $robocopyArgs += $excludeDir
        }
        foreach ($excludeFile in $script:DeployExcludeFilePatterns) {
            $robocopyArgs += '/XF'
            $robocopyArgs += $excludeFile
        }

        & robocopy.exe @robocopyArgs | Out-Null
    }

    foreach ($rel in $script:DeployStripAfterCopy) {
        Remove-ReleasePath -DestRoot $Dest -RelativePath $rel
    }

    foreach ($rel in $script:DeployGuardFiles) {
        $src = Join-Path $Root ($rel -replace '\\', [IO.Path]::DirectorySeparatorChar)
        if (-not (Test-Path -LiteralPath $src)) {
            continue
        }
        $target = Join-Path $Dest ($rel -replace '\\', [IO.Path]::DirectorySeparatorChar)
        $targetDir = Split-Path -Parent $target
        if (-not (Test-Path -LiteralPath $targetDir)) {
            New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
        }
        Copy-Item -LiteralPath $src -Destination $target -Force
    }
}

function Get-PreviousVersionLabel {
    param(
        [object]$Baseline
    )

    if (-not $Baseline) {
        return '(none)'
    }

    if ($Baseline.version) {
        return $Baseline.version
    }

    if ($Baseline.builtAt -and $Baseline.commit) {
        return "$($Baseline.builtAt) / $($Baseline.commit)"
    }

    if ($Baseline.commit) {
        return $Baseline.commit
    }

    return '(none)'
}

function Escape-Html {
    param([string]$Text)
    if ($null -eq $Text) { return '' }
    return [System.Net.WebUtility]::HtmlEncode($Text)
}

function Write-DeployReadme {
    param(
        [string]$DestRoot,
        [string]$VersionId,
        [string]$ReleaseType,
        [string]$FromVersion,
        [string]$ToCommit,
        [string[]]$ChangedFiles,
        [string[]]$DeletedFiles,
        [string]$Notes,
        [int]$FileCount
    )

    $created = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $isPatch = ($ReleaseType -like '*Patch*')

    $notesHtml = New-Object System.Collections.Generic.List[string]
    if ($Notes) {
        foreach ($noteLine in ($Notes -split "`r?`n")) {
            $trimmed = $noteLine.Trim()
            if ($trimmed) {
                $notesHtml.Add("<li>$(Escape-Html $trimmed)</li>")
            }
        }
    }
    if ($notesHtml.Count -eq 0) {
        $notesHtml.Add('<li class="muted">(no notes provided)</li>')
    }

    $filesHtml = New-Object System.Collections.Generic.List[string]
    if ($ChangedFiles -and $ChangedFiles.Count -gt 0) {
        foreach ($rel in ($ChangedFiles | Sort-Object)) {
            $filesHtml.Add("<li><code>$(Escape-Html $rel)</code></li>")
        }
    } else {
        $filesHtml.Add('<li class="muted">full release — see folder contents</li>')
    }

    $deleteSection = ''
    if ($DeletedFiles -and $DeletedFiles.Count -gt 0) {
        $delItems = ($DeletedFiles | Sort-Object | ForEach-Object { "<li><code>$(Escape-Html $_)</code></li>" }) -join "`n"
        $deleteSection = @"
<section class="warn">
  <h2>Delete on server ($($DeletedFiles.Count) file(s))</h2>
  <p>Remove these files on the server manually if they exist:</p>
  <ul>$delItems</ul>
</section>
"@
    }

    $ftpSteps = if ($isPatch) {
        @(
            'Upload <strong>only</strong> the files/folders listed below from <code>production/</code>',
            'Target path: <code>httpdocs/production/</code> (keep folder structure)',
            'Do <strong>not</strong> upload <code>before/</code>, README.html, or PATCH-MANIFEST.txt'
        )
    } else {
        @(
            'Upload all contents of the <code>production</code> folder',
            'Target path: <code>httpdocs/production/</code>',
            'Do <strong>not</strong> upload this README.html to the server',
            'Do <strong>not</strong> overwrite <code>finishgoogs_ma_update/uploads/</code> if images exist'
        )
    }
    $ftpStepsHtml = ($ftpSteps | ForEach-Object { "<li>$_</li>" }) -join "`n"

    $html = @"
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deploy v$VersionId</title>
<style>
  :root { --bg:#f4f6f9; --card:#fff; --text:#1a1a2e; --muted:#64748b; --accent:#2563eb; --warn:#fef3c7; --warn-b:#f59e0b; }
  * { box-sizing:border-box; }
  body { font-family:Segoe UI, Tahoma, sans-serif; background:var(--bg); color:var(--text); margin:0; padding:24px; line-height:1.5; }
  .wrap { max-width:860px; margin:0 auto; }
  h1 { font-size:1.4rem; margin:0 0 4px; }
  .badge { display:inline-block; background:var(--accent); color:#fff; font-size:.75rem; padding:2px 10px; border-radius:999px; margin-bottom:16px; }
  section { background:var(--card); border-radius:10px; padding:18px 22px; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
  h2 { font-size:1rem; margin:0 0 10px; color:var(--accent); }
  dl { display:grid; grid-template-columns:120px 1fr; gap:4px 12px; margin:0; }
  dt { color:var(--muted); font-size:.85rem; }
  dd { margin:0; }
  ul { margin:6px 0 0; padding-left:20px; }
  ul.files { columns:2; column-gap:24px; font-size:.85rem; }
  code { background:#eef2ff; padding:1px 5px; border-radius:4px; font-size:.85em; word-break:break-all; }
  .muted { color:var(--muted); }
  section.warn { background:var(--warn); border-left:4px solid var(--warn-b); }
  ol.steps { padding-left:20px; }
  ol.steps li { margin-bottom:4px; }
  footer { text-align:center; color:var(--muted); font-size:.8rem; margin-top:20px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Production Deploy</h1>
  <span class="badge">$(Escape-Html $ReleaseType)</span>

  <section>
    <h2>Version info</h2>
    <dl>
      <dt>Version</dt><dd><strong>v$(Escape-Html $VersionId)</strong></dd>
      <dt>From</dt><dd>$(Escape-Html $FromVersion)</dd>
      <dt>To</dt><dd><code>$(Escape-Html $ToCommit)</code></dd>
      <dt>Created</dt><dd>$created</dd>
      <dt>Files</dt><dd>$FileCount file(s) to upload</dd>
    </dl>
  </section>

  <section>
    <h2>Changes</h2>
    <ul>$($notesHtml -join "`n")</ul>
  </section>

  <section>
    <h2>FTP instructions</h2>
    <ol class="steps">$ftpStepsHtml</ol>
    <p class="muted" style="margin-top:12px">Secrets upload separately: <code>deploy/hostatom/</code></p>
    <p class="muted">After FTP upload: type <strong>OK</strong> in build-patch.bat to save baseline</p>
  </section>

  $deleteSection

  $(if ($isPatch) {
@'
  <section>
    <h2>Compare before edit (local only)</h2>
    <p>Compare <code>deploy/release/before/</code> (old) vs <code>production/</code> (new) in your editor before FTP.</p>
    <p class="muted">Do not upload <code>before/</code> to the server. See <code>before/README.txt</code> for file list.</p>
  </section>
'@
} else { '' })

  <section>
    <h2>Files to upload ($FileCount)</h2>
    <ul class="files">$($filesHtml -join "`n")</ul>
  </section>

  <footer>Generated by deploy/build-$(if ($isPatch) { 'patch' } else { 'release' }).ps1</footer>
</div>
</body>
</html>
"@

    Set-Content -LiteralPath (Join-Path $DestRoot 'README.html') -Value $html -Encoding UTF8
    return $html
}

function Append-DeployChangelog {
    param(
        [string]$ChangelogFile,
        [string]$VersionId,
        [string]$ReleaseType,
        [string]$FromVersion,
        [string]$ToCommit,
        [int]$FileCount,
        [string]$Notes,
        [string[]]$ChangedFiles
    )

    $entry = New-Object System.Collections.Generic.List[string]
    $entry.Add('')
    $entry.Add('================================================================')
    $entry.Add("v$VersionId | $ReleaseType | $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')")
    $entry.Add("From: $FromVersion  ->  To: $ToCommit  |  Files: $FileCount")
    $entry.Add('----------------------------------------------------------------')

    if ($Notes) {
        foreach ($noteLine in ($Notes -split "`r?`n")) {
            $trimmed = $noteLine.Trim()
            if ($trimmed) {
                $entry.Add("- $trimmed")
            }
        }
    }

    if ($ChangedFiles -and $ChangedFiles.Count -gt 0 -and $ChangedFiles.Count -le 30) {
        $entry.Add('')
        $entry.Add('Files:')
        foreach ($rel in ($ChangedFiles | Sort-Object)) {
            $entry.Add("  $rel")
        }
    } elseif ($ChangedFiles -and $ChangedFiles.Count -gt 30) {
        $entry.Add('')
        $entry.Add("Files: $($ChangedFiles.Count) (see README.html in version archive)")
    }

    $block = ($entry -join [Environment]::NewLine) + [Environment]::NewLine

    if (-not (Test-Path -LiteralPath $ChangelogFile)) {
        $header = @(
            '# Production Deploy Changelog',
            '# Append-only log of each release/patch',
            ''
        ) -join [Environment]::NewLine
        Set-Content -LiteralPath $ChangelogFile -Value ($header + $block) -Encoding UTF8
        return
    }

    Add-Content -LiteralPath $ChangelogFile -Value $block -Encoding UTF8
}

function Invoke-MarkDeployed {
    param(
        [string]$DeployDir,
        [string]$Root
    )

    $BaselineFile = Join-Path $DeployDir '.last-deploy.json'
    $BuildInfoFile = Join-Path $DeployDir '.last-build.json'
    $headCommit = Get-GitHeadCommit -Root $Root

    $version = ''
    $releaseType = ''
    if (Test-Path -LiteralPath $BuildInfoFile) {
        try {
            $buildInfo = Get-Content -LiteralPath $BuildInfoFile -Raw -Encoding UTF8 | ConvertFrom-Json
            $version = [string]$buildInfo.version
            $releaseType = [string]$buildInfo.releaseType
        } catch {
            Write-Warning 'Cannot read .last-build.json'
        }
    }

    $hashMap = @{}
    foreach ($rel in (Get-DeployableRelativePaths -Root $Root)) {
        $full = Join-Path $Root ($rel -replace '/', [IO.Path]::DirectorySeparatorChar)
        $hashMap[$rel] = Get-FileHashShort -FilePath $full
    }

    Write-DeployBaseline -BaselineFile $BaselineFile -Root $Root -Commit $headCommit `
        -FileHashes $hashMap -Version $version -ReleaseType $releaseType

    return [ordered]@{
        version     = $version
        commit      = $headCommit
        fileCount   = $hashMap.Count
        baselineFile = $BaselineFile
    }
}
