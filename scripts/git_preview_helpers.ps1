function Assert-StocksPreviewCi {
    param([string]$Branch, [string]$Commit)
    if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
        throw 'GitHub CLI is required to verify CI for the exact preview commit.'
    }
    $runJson = & gh run list --repo ITStudioAT/stocks --workflow CI --branch $Branch --commit $Commit --event push --limit 1 --json status,conclusion,headSha
    if ($LASTEXITCODE -ne 0) { throw 'Could not verify GitHub CI for this preview commit.' }
    $run = @($runJson | ConvertFrom-Json) | Select-Object -First 1
    if (-not $run -or $run.headSha -cne $Commit -or $run.status -cne 'completed' -or $run.conclusion -cne 'success') {
        throw 'The exact preview commit requires a successful completed GitHub CI run.'
    }
}

function New-StocksPreviewBundle {
    param([string]$Branch, [string]$Commit)
    if (-not (Test-StocksRef "refs/remotes/origin/$Branch") -or
        (Invoke-StocksGit rev-parse "refs/remotes/origin/$Branch") -cne $Commit) {
        throw 'Save the exact feature commit on origin before building its preview bundle.'
    }
    Assert-StocksPreviewCi -Branch $Branch -Commit $Commit
    if (Invoke-StocksGit ls-tree -r HEAD | Where-Object { $_ -match '^120000 ' }) {
        throw 'Preview source must not contain symbolic links.'
    }
    $projectRoot = (Get-Location).Path
    $previewDirectory = Invoke-StocksGit rev-parse --git-path stocks-preview
    $null = New-Item -ItemType Directory -Path $previewDirectory -Force
    $id = [guid]::NewGuid().ToString('N')
    $buildRoot = Join-Path $previewDirectory ('build-' + $id)
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
        foreach ($service in @('PreviewInstallation.php', 'PreviewReleaseBundle.php', 'PreviewReleaseUpdate.php', 'PreviewDatabaseGuard.php', 'PreviewFileSwap.php')) {
            Copy-Item -LiteralPath (Join-Path $sourceDirectory "app/Services/$service") -Destination (Join-Path $buildRoot $service)
        }
        foreach ($script in @('preview-install.php', 'preview-update.php', 'stocks_preview_target.json')) {
            Copy-Item -LiteralPath (Join-Path $sourceDirectory "scripts/$script") -Destination (Join-Path $buildRoot $script)
        }
        Write-Host "Verified preview bundle: $bundlePath" -ForegroundColor Green
        Write-Host "SHA-256: $digest"
        Write-Host 'Installer helpers are alongside the bundle. No upload, server mutation or database operation was performed.'
        [pscustomobject]@{ Id = $id; Directory = $buildRoot; BundlePath = $bundlePath; Digest = $digest; Commit = $Commit }
    }
    finally { Pop-Location }
}

function Save-StocksPreviewReceipt {
    param([object]$Bundle, [string]$Branch, [string]$MainCommit)
    $directory = Invoke-StocksGit rev-parse --git-path stocks-preview
    $files = @([IO.Path]::GetFileName($Bundle.BundlePath), 'PreviewReleaseUpdate.php', 'PreviewReleaseBundle.php', 'PreviewFileSwap.php', 'preview-update.php', 'stocks_preview_target.json')
    $hashes = [ordered]@{}
    foreach ($name in $files) {
        $hashes[$name] = (Get-FileHash -LiteralPath (Join-Path $Bundle.Directory $name) -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $receipt = [ordered]@{
        format = 'stocks-preview-receipt-v1'
        id = $Bundle.Id
        branch = $Branch
        commit = $Bundle.Commit
        main_commit = $MainCommit
        digest = $Bundle.Digest
        files = $hashes
    }
    $path = Join-Path $directory "receipt-$($Bundle.Id).json"
    [IO.File]::WriteAllText([IO.Path]::GetFullPath($path), ($receipt | ConvertTo-Json -Depth 4), (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "Prepared preview bundle $($Bundle.Id). Resume with gitpreview resume $($Bundle.Id)." -ForegroundColor Green
}

function Read-StocksPreviewReceipt {
    param([string]$Id, [string]$Branch, [string]$Commit, [string]$MainCommit)
    if ($Id -cnotmatch '^[a-f0-9]{32}$') { throw 'Use the 32-character bundle ID shown by gitpreview prepare.' }
    $directory = [IO.Path]::GetFullPath((Invoke-StocksGit rev-parse --git-path stocks-preview))
    $receiptPath = Join-Path $directory "receipt-$Id.json"
    if (-not (Test-Path -LiteralPath $receiptPath -PathType Leaf) -or (Get-Item -LiteralPath $receiptPath).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw 'The prepared preview receipt is missing or invalid.'
    }
    $receipt = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
    if ($receipt.format -cne 'stocks-preview-receipt-v1' -or $receipt.id -cne $Id -or
        $receipt.branch -cne $Branch -or $receipt.commit -cne $Commit -or
        $receipt.main_commit -cne $MainCommit -or $receipt.digest -cnotmatch '^[a-f0-9]{64}$') {
        throw 'The prepared preview no longer matches this exact branch, commit and main.'
    }
    $buildRoot = Join-Path $directory "build-$Id"
    if (-not (Test-Path -LiteralPath $buildRoot -PathType Container) -or (Get-Item -LiteralPath $buildRoot).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw 'The prepared preview bundle directory is missing or invalid.'
    }
    $bundleName = "stocks-preview-$Commit.zip"
    $files = @($bundleName, 'PreviewReleaseUpdate.php', 'PreviewReleaseBundle.php', 'PreviewFileSwap.php', 'preview-update.php', 'stocks_preview_target.json')
    if (@($receipt.files.PSObject.Properties.Name).Count -ne $files.Count) {
        throw 'The prepared preview receipt has an unexpected file list.'
    }
    foreach ($name in $files) {
        $path = Join-Path $buildRoot $name
        if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or (Get-Item -LiteralPath $path).Attributes -band [IO.FileAttributes]::ReparsePoint -or
            $receipt.files.$name -cnotmatch '^[a-f0-9]{64}$' -or
            (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $receipt.files.$name) {
            throw "Prepared preview file changed: $name"
        }
    }
    if ($receipt.files.$bundleName -cne $receipt.digest) { throw 'Prepared preview bundle digest mismatch.' }
    Assert-StocksPreviewCi -Branch $Branch -Commit $Commit
    [pscustomobject]@{ Id = $Id; Directory = $buildRoot; BundlePath = (Join-Path $buildRoot $bundleName); Digest = $receipt.digest; Commit = $Commit }
}

function Invoke-StocksPreviewSsh {
    param([string]$Destination, [string]$Command)
    $ssh = Get-Command ssh.exe -ErrorAction Stop
    $output = & $ssh.Source -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 $Destination $Command
    if ($LASTEXITCODE -ne 0) { throw 'Preview SSH command failed. Inspect the target before retrying.' }
    $output
}

function Send-StocksPreviewBundle {
    param([object]$Bundle, [string]$OldCommit)
    $target = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'stocks_preview_target.json') -Raw | ConvertFrom-Json
    if ($target.repository -cne 'ITStudioAT/stocks' -or $target.serverId -cne '1486907' -or
        $target.sourceAppId -cne '6468818' -or $target.targetAppId -cne '6690486' -or
        $target.canonicalTargetRoot -cne '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html' -or
        $target.targetOwnerUid -cne '1013' -or $target.sshUser -cne 'sftp_for_gkstocks_feature' -or
        $target.serverAddress -cne '165.227.156.99') {
        throw 'The preview target identity changed. No upload was performed.'
    }
    if ($OldCommit -cnotmatch '^[a-f0-9]{40}$' -or $Bundle.Commit -cnotmatch '^[a-f0-9]{40}$' -or
        $Bundle.Digest -cnotmatch '^[a-f0-9]{64}$') {
        throw 'Invalid preview update identity.'
    }
    $destination = "$($target.sshUser)@$($target.serverAddress)"
    $root = $target.canonicalTargetRoot
    $transfer = "$root/.stocks-preview-private/updates/$([guid]::NewGuid().ToString('N'))"
    $private = "$root/.stocks-preview-private"
    $prepare = "umask 077 && test `"`$(id -u)`" = 1013 && test `"`$(realpath '$root')`" = '$root' && test ! -L '$private' && test `"`$(stat -c '%u:%a' '$private')`" = '1013:700' && test ! -e '$root/storage/framework/down' && test ! -L '$private/updates' && mkdir -m 700 -p '$private/updates' && mkdir -m 700 '$transfer'"
    Invoke-StocksPreviewSsh -Destination $destination -Command $prepare | Out-Null
    $scp = Get-Command scp.exe -ErrorAction Stop
    $files = @([IO.Path]::GetFileName($Bundle.BundlePath), 'PreviewReleaseUpdate.php', 'PreviewReleaseBundle.php', 'PreviewFileSwap.php', 'preview-update.php', 'stocks_preview_target.json')
    Push-Location -LiteralPath $Bundle.Directory
    try {
        & $scp.Source -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 -O @files "${destination}:$transfer/"
        if ($LASTEXITCODE -ne 0) {
            throw "Preview upload failed. Preserve and inspect private transfer $transfer before retrying."
        }
    }
    finally { Pop-Location }
    $archive = "$transfer/$([IO.Path]::GetFileName($Bundle.BundlePath))"
    $script = "$transfer/preview-update.php"
    $targetJson = "$transfer/stocks_preview_target.json"
    $arguments = "'$root' $($target.targetOwnerUid) '$archive' $($Bundle.Digest) '$targetJson' $OldCommit"
    Invoke-StocksPreviewSsh -Destination $destination -Command "php '$script' inspect $arguments" | Out-Host
    Write-Host "Preview target: $destination $root" -ForegroundColor Yellow
    Write-Host "Installed: $OldCommit; candidate: $($Bundle.Commit)" -ForegroundColor Yellow
    if ((Read-Host 'Install this preview release? Type PREVIEW') -cne 'PREVIEW') {
        throw "Preview installation cancelled. Private transfer retained at $transfer."
    }
    Invoke-StocksPreviewSsh -Destination $destination -Command "php '$script' apply $arguments" | Out-Host
    Write-Host "Preview release $($Bundle.Commit) installed. Private transfer retained at $transfer." -ForegroundColor Green
}
