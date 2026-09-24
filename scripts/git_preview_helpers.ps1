function New-StocksPreviewBundle {
    param([string]$Branch, [string]$Commit)
    if (-not (Test-StocksRef "refs/remotes/origin/$Branch") -or
        (Invoke-StocksGit rev-parse "refs/remotes/origin/$Branch") -cne $Commit) {
        throw 'Save the exact feature commit on origin before building its preview bundle.'
    }
    $runJson = & gh run list --repo ITStudioAT/stocks --workflow CI --branch $Branch --commit $Commit --event push --limit 1 --json status,conclusion,headSha
    if ($LASTEXITCODE -ne 0) { throw 'Could not verify GitHub CI for this preview commit.' }
    $run = @($runJson | ConvertFrom-Json) | Select-Object -First 1
    if (-not $run -or $run.headSha -cne $Commit -or $run.status -cne 'completed' -or $run.conclusion -cne 'success') {
        throw 'The exact preview commit requires a successful completed GitHub CI run.'
    }
    if (Invoke-StocksGit ls-tree -r HEAD | Where-Object { $_ -match '^120000 ' }) {
        throw 'Preview source must not contain symbolic links.'
    }
    $projectRoot = (Get-Location).Path
    $previewDirectory = Invoke-StocksGit rev-parse --git-path stocks-preview
    $null = New-Item -ItemType Directory -Path $previewDirectory -Force
    $buildRoot = Join-Path $previewDirectory ('build-' + [guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $buildRoot
    $buildRoot = [IO.Path]::GetFullPath($buildRoot)
    $sourceArchive = Join-Path $buildRoot 'source.zip'
    $sourceDirectory = Join-Path $buildRoot 'source'
    Invoke-StocksGit archive --format=zip "--output=$sourceArchive" $Commit
    Expand-Archive -LiteralPath $sourceArchive -DestinationPath $sourceDirectory
    Push-Location -LiteralPath $sourceDirectory
    try {
        & composer install --no-dev --no-interaction --no-progress --no-plugins --no-scripts --prefer-dist
        if ($LASTEXITCODE -ne 0) { throw 'Preview Composer installation failed.' }
        $npm = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
        & $npm ci --ignore-scripts --no-fund
        if ($LASTEXITCODE -ne 0) { throw 'Preview frontend dependency installation failed.' }
        & $npm run build
        if ($LASTEXITCODE -ne 0) { throw 'Preview frontend build failed.' }
        $viteHot = Join-Path $sourceDirectory 'public/hot'
        if (Test-Path -LiteralPath $viteHot) {
            $hotItem = Get-Item -LiteralPath $viteHot
            if ($hotItem.PSIsContainer -or ($hotItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
                throw 'Invalid Vite hot marker in preview build.'
            }
            Remove-Item -LiteralPath $viteHot -Force
        }
        $nodeModules = [IO.Path]::GetFullPath((Join-Path $sourceDirectory 'node_modules'))
        if (-not $nodeModules.StartsWith($buildRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Refusing cleanup outside the owned preview build directory.'
        }
        Remove-Item -LiteralPath $nodeModules -Recurse -Force
        $bundlePath = Join-Path $buildRoot ("stocks-preview-$Commit.zip")
        $digest = & php scripts/preview-bundle.php $sourceDirectory $bundlePath $Commit
        if ($LASTEXITCODE -ne 0 -or $digest -cnotmatch '^[a-f0-9]{64}$') {
            throw 'Preview bundle validation failed.'
        }
        [IO.File]::WriteAllText($bundlePath + '.sha256', $digest + "`n", (New-Object System.Text.UTF8Encoding($false)))
        foreach ($service in @('PreviewInstallation.php', 'PreviewReleaseBundle.php', 'PreviewDatabaseGuard.php', 'PreviewFileSwap.php')) {
            Copy-Item -LiteralPath (Join-Path $sourceDirectory "app/Services/$service") -Destination (Join-Path $buildRoot $service)
        }
        foreach ($script in @('preview-install.php', 'stocks_preview_target.json')) {
            Copy-Item -LiteralPath (Join-Path $sourceDirectory "scripts/$script") -Destination (Join-Path $buildRoot $script)
        }
        Write-Host "Verified preview bundle: $bundlePath" -ForegroundColor Green
        Write-Host "SHA-256: $digest"
        Write-Host 'Installer helpers are alongside the bundle. No upload, server mutation or database operation was performed.'
    }
    finally { Pop-Location }
}
