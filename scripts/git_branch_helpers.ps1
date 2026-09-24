function Invoke-StocksGit {
    $result = & git @args
    if ($LASTEXITCODE -ne 0) {
        throw "Git failed: git $($args -join ' '). Review git status; no automatic reset or stash was performed."
    }
    $result
}

function Assert-StocksRemote {
    $urls = @(Invoke-StocksGit remote get-url --all origin)
    $pushUrls = @(Invoke-StocksGit remote get-url --all --push origin)
    if ($urls.Count -ne 1 -or $pushUrls.Count -ne 1) {
        throw 'The workflow requires exactly one origin fetch URL and one push URL.'
    }
    foreach ($url in @($urls + $pushUrls)) {
        if ($url -notmatch '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)ITStudioAT/stocks(?:\.git)?/?$') {
            throw 'The Stocks workflow only trusts ITStudioAT/stocks on GitHub.'
        }
    }
}

function Assert-StocksRepository {
    $root = Invoke-StocksGit rev-parse --show-toplevel
    if ([IO.Path]::GetFullPath((Get-Location).Path) -ne [IO.Path]::GetFullPath($root)) {
        throw 'Run the workflow from the project root or use the installed profile commands.'
    }
    Assert-StocksRemote
    foreach ($state in @('MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'BISECT_LOG')) {
        $path = Invoke-StocksGit rev-parse --git-path $state
        if (Test-Path -LiteralPath $path) {
            throw 'A Git operation is unfinished. Resolve or abort it before continuing.'
        }
    }
    if (-not (Invoke-StocksGit branch --show-current)) {
        throw 'Detached HEAD: switch to a named branch first.'
    }
}

function Assert-StocksClean {
    if (Invoke-StocksGit status --porcelain --untracked-files=all) {
        throw 'Unsaved changes exist. Review git status and use gitsave before switching or updating.'
    }
}

function Test-StocksRef {
    param([string]$Ref)
    & git show-ref --verify --quiet $Ref
    if ($LASTEXITCODE -gt 1) { throw "Could not inspect $Ref." }
    $LASTEXITCODE -eq 0
}

function Test-StocksAncestor {
    param([string]$Ancestor, [string]$Descendant)
    & git merge-base --is-ancestor $Ancestor $Descendant
    if ($LASTEXITCODE -gt 1) { throw 'Could not inspect Git ancestry.' }
    $LASTEXITCODE -eq 0
}

function Update-StocksRemote {
    Invoke-StocksGit fetch --no-tags --prune origin '+refs/heads/*:refs/remotes/origin/*'
}

function Get-StocksFeatureBranch {
    param([string]$Name)
    $shortName = $Name -creplace '^codex/', ''
    if ($shortName -cnotmatch '^[a-z0-9]+(?:-[a-z0-9]+)*$') {
        throw 'Use a feature name such as depot-filter or codex/depot-filter (lowercase letters, numbers and hyphens).'
    }
    "codex/$shortName"
}

function Get-StocksOpenFeatures {
    @(Invoke-StocksGit for-each-ref '--format=%(refname:strip=3)' refs/remotes/origin/codex/) |
        Where-Object { $_ -cmatch '^codex/[a-z0-9]+(?:-[a-z0-9]+)*$' }
}

function Get-StocksFeatureReservationRef {
    param([string]$Branch)
    $name = (Get-StocksFeatureBranch $Branch).Substring('codex/'.Length)
    "refs/heads/codex/features/$name"
}

function New-StocksFeatureReservation {
    param([string]$Branch)
    $id = [guid]::NewGuid().ToString('N')
    $metadata = @{ branch = $Branch; id = $id } | ConvertTo-Json -Compress
    $metadataPath = Join-Path ([IO.Path]::GetTempPath()) "stocks-feature-$id.json"
    $indexPath = Join-Path ([IO.Path]::GetTempPath()) "stocks-feature-$id.index"
    $previousIndex = $env:GIT_INDEX_FILE
    try {
        [IO.File]::WriteAllText($metadataPath, "$metadata`n", (New-Object System.Text.UTF8Encoding($false)))
        $blob = Invoke-StocksGit hash-object -w -- $metadataPath
        $env:GIT_INDEX_FILE = $indexPath
        Invoke-StocksGit read-tree --empty
        Invoke-StocksGit update-index --add --cacheinfo "100644,$blob,feature.json"
        $tree = Invoke-StocksGit write-tree
        $commit = Invoke-StocksGit commit-tree $tree -m "Reserve $Branch ($id)"
    }
    finally {
        $env:GIT_INDEX_FILE = $previousIndex
        foreach ($path in @($metadataPath, $indexPath)) {
            if (Test-Path -LiteralPath $path) { [IO.File]::Delete($path) }
        }
    }
    [pscustomobject]@{ Branch = $Branch; Id = $id; Commit = $commit; Ref = (Get-StocksFeatureReservationRef $Branch) }
}

function Get-StocksActiveFeature {
    param([string]$Branch, [switch]$AllowMissing)
    if (-not $Branch) { $Branch = Assert-StocksFeature }
    $Branch = Get-StocksFeatureBranch $Branch
    $ref = Get-StocksFeatureReservationRef $Branch
    $tracking = $ref.Replace('refs/heads/', 'refs/remotes/origin/')
    if (-not (Test-StocksRef $tracking)) {
        if ($AllowMissing) { return $null }
        throw "Feature $Branch has no workflow reservation. Use gitwork NAME to register it."
    }
    $commit = Invoke-StocksGit rev-parse $tracking
    $parents = (Invoke-StocksGit rev-list --parents -n 1 $commit) -split ' '
    if ($parents.Count -ne 1) { throw 'The feature reservation is invalid.' }
    try { $metadata = (Invoke-StocksGit show "${commit}:feature.json") | ConvertFrom-Json -ErrorAction Stop }
    catch { throw 'The feature reservation cannot be read.' }
    if ($metadata.branch -isnot [string] -or $metadata.id -isnot [string] -or
        $metadata.branch -cne $Branch -or $metadata.id -cnotmatch '^[a-f0-9]{32}$' -or $metadata.id -ceq ('0' * 32) -or
        -not (Test-StocksRef "refs/remotes/origin/$Branch")) {
        throw 'The feature reservation and remote branch disagree.'
    }
    [pscustomobject]@{ Branch = $Branch; Id = [string]$metadata.id; Commit = $commit; Ref = $ref }
}

function Assert-StocksFeatureSnapshot {
    param($Feature, [string]$FeatureCommit, [string]$MainCommit)
    Update-StocksRemote
    $current = Get-StocksActiveFeature -Branch $Feature.Branch
    if ($current.Commit -cne $Feature.Commit -or $current.Id -cne $Feature.Id -or $current.Ref -cne $Feature.Ref -or
        (Invoke-StocksGit rev-parse "refs/remotes/origin/$($Feature.Branch)") -cne $FeatureCommit -or
        ($MainCommit -and (Invoke-StocksGit rev-parse refs/remotes/origin/main) -cne $MainCommit)) {
        throw 'The feature, its reservation, or main changed during preparation. Nothing was published.'
    }
}

function Assert-StocksFeature {
    $branch = Invoke-StocksGit branch --show-current
    if ($branch -cnotmatch '^codex/[a-z0-9]+(?:-[a-z0-9]+)*$') {
        throw 'This command requires a codex/ feature branch. Use gitstart NAME or gitwork NAME. main is published separately with gitpush.'
    }
    $branch
}

function Assert-StocksSaved {
    $branch = Invoke-StocksGit branch --show-current
    if ((Test-StocksRef "refs/remotes/origin/$branch") -and
        (Test-StocksAncestor HEAD "refs/remotes/origin/$branch")) {
        return
    }
    if ($branch -ne 'main' -and (Test-StocksAncestor HEAD refs/remotes/origin/main)) {
        return
    }
    throw "Branch $branch has unpublished or divergent commits. Save or resolve them before switching; main requires gitpush."
}

function Invoke-StocksLocalPreparation {
    if (-not (Test-Path -LiteralPath '.env' -PathType Leaf)) {
        throw 'Local .env is missing. Configure APP_ENV=local, then repeat gitmain or gitwork NAME.'
    }
    $localEnvironment = @(Get-Content -LiteralPath '.env' | Where-Object { $_ -match '^\s*APP_ENV\s*=' })
    if ($localEnvironment.Count -ne 1 -or $localEnvironment[0] -notmatch '^\s*APP_ENV\s*=\s*["'']?local["'']?\s*(?:#.*)?$' -or
        ($env:APP_ENV -and $env:APP_ENV -ne 'local')) {
        throw 'Local preparation requires APP_ENV=local in .env and no non-local APP_ENV override.'
    }
    & php scripts/update.php --target=local --prepare
    if ($LASTEXITCODE -ne 0) { throw 'Dependency preparation failed. Fix the error, then repeat gitmain or gitwork NAME.' }
    & php artisan config:clear --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Configuration cache clear failed. Repeat gitmain or gitwork NAME after fixing the error.' }
    & php artisan view:clear --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'View cache clear failed. Repeat gitmain or gitwork NAME after fixing the error.' }
    $npmExecutable = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
    & $npmExecutable run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed. Fix the error, then repeat gitmain or gitwork NAME.' }
    Write-Host 'Local files are ready. No migrations or seeders ran. Restart development servers and workers.' -ForegroundColor Green
}

function Switch-StocksBranch {
    param([string]$Branch, [switch]$NoPrepare)
    Assert-StocksClean
    Assert-StocksSaved
    if (-not (Test-StocksRef "refs/remotes/origin/$Branch")) {
        throw "Branch $Branch does not exist on origin. Save it on the other device first."
    }
    if (Test-StocksRef "refs/heads/$Branch") {
        if (-not (Test-StocksAncestor "refs/heads/$Branch" "refs/remotes/origin/$Branch")) {
            throw "Local $Branch has unpublished or divergent commits. No branch was switched."
        }
        Invoke-StocksGit switch $Branch
    }
    else {
        Invoke-StocksGit switch --track -c $Branch "refs/remotes/origin/$Branch"
    }
    Invoke-StocksGit -c merge.autostash=false merge --ff-only "refs/remotes/origin/$Branch"
    if (-not $NoPrepare) { Invoke-StocksLocalPreparation }
}

function gitstart {
    param([Parameter(Mandatory = $true)][string]$Name, [switch]$NoPrepare)
    $branch = Get-StocksFeatureBranch $Name
    Assert-StocksRepository
    Assert-StocksClean
    Update-StocksRemote
    Assert-StocksSaved
    if ((Test-StocksRef "refs/heads/$branch") -or (Test-StocksRef "refs/remotes/origin/$branch") -or
        (Test-StocksRef (Get-StocksFeatureReservationRef $branch).Replace('refs/heads/', 'refs/remotes/origin/'))) {
        throw "$branch already exists. Use gitwork NAME."
    }
    $main = Invoke-StocksGit rev-parse refs/remotes/origin/main
    $reservation = New-StocksFeatureReservation $branch
    Invoke-StocksGit push --atomic "--force-with-lease=$($reservation.Ref):" "--force-with-lease=refs/heads/${branch}:" origin `
        "$($reservation.Commit):$($reservation.Ref)" "${main}:refs/heads/$branch"
    Update-StocksRemote
    Switch-StocksBranch -Branch $branch -NoPrepare:$NoPrepare
    Write-Host "Feature $branch reserved on origin. Use gitsave DESCRIPTION to share work." -ForegroundColor Green
}

function gitwork {
    param([string]$Name, [switch]$NoPrepare)
    Assert-StocksRepository
    Assert-StocksClean
    Update-StocksRemote
    $features = @(Get-StocksOpenFeatures)
    if (-not $Name) {
        if ($features.Count -ne 1) {
            throw "Choose a feature with gitwork NAME. Available: $($features -join ', '). Use gitstart NAME for new work."
        }
        $Name = $features[0]
    }
    $branch = Get-StocksFeatureBranch $Name
    if ($branch -cnotin $features) { throw "Feature $branch does not exist on origin. Use gitstart NAME." }
    $active = Get-StocksActiveFeature -Branch $branch -AllowMissing
    if (-not $active) {
        $head = Invoke-StocksGit rev-parse "refs/remotes/origin/$branch"
        $reservation = New-StocksFeatureReservation $branch
        Invoke-StocksGit push --atomic "--force-with-lease=$($reservation.Ref):" "--force-with-lease=refs/heads/${branch}:$head" origin `
            "$($reservation.Commit):$($reservation.Ref)" "${head}:refs/heads/$branch"
        Update-StocksRemote
        $active = Get-StocksActiveFeature -Branch $branch
        Write-Host "Existing feature $branch registered for this workflow." -ForegroundColor Cyan
    }
    Switch-StocksBranch -Branch $branch -NoPrepare:$NoPrepare
}

function gitmain {
    param([switch]$NoPrepare)
    Assert-StocksRepository
    Assert-StocksClean
    Update-StocksRemote
    Switch-StocksBranch -Branch main -NoPrepare:$NoPrepare
}

function gitsave {
    param(
        [Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$Message,
        [string]$Version,
        [switch]$Full,
        [switch]$WaitForCI
    )
    if ([string]::IsNullOrWhiteSpace($Message)) { throw 'Supply a meaningful commit message.' }
    Assert-StocksRepository
    if ((Invoke-StocksGit branch --show-current) -ceq 'main') {
        . (Join-Path $PSScriptRoot 'git_helpers.ps1')
        gitpush -message $Message -version $Version -Full:$Full -WaitForCI:$WaitForCI
        return
    }
    $branch = Assert-StocksFeature
    if ($Version -or $Full -or $WaitForCI) {
        throw 'Feature saves accept only a description. Use gitrelease DESCRIPTION [VERSION] to publish a feature.'
    }
    Update-StocksRemote
    $remoteExists = Test-StocksRef "refs/remotes/origin/$branch"
    if ($remoteExists -and -not (Test-StocksAncestor "refs/remotes/origin/$branch" HEAD)) {
        throw 'The remote feature contains changes missing locally. Nothing was committed. Synchronize this feature before saving.'
    }
    $active = Get-StocksActiveFeature -Branch $branch -AllowMissing
    if (Invoke-StocksGit status --porcelain --untracked-files=all) {
        & php scripts/check-encoding.php
        if ($LASTEXITCODE -ne 0) { throw 'Source encoding check failed. Nothing was committed.' }
        Invoke-StocksGit add -A
        Invoke-StocksGit commit -m $Message
    }
    Assert-StocksClean
    $savedHead = Invoke-StocksGit rev-parse HEAD
    $remoteHead = if ($remoteExists) { Invoke-StocksGit rev-parse "refs/remotes/origin/$branch" } else { '' }
    try {
        if ($active) {
            Assert-StocksFeatureSnapshot -Feature $active -FeatureCommit $remoteHead
            $reservation = $active
        }
        else {
            $reservation = New-StocksFeatureReservation $branch
            Update-StocksRemote
            $actualRemote = if (Test-StocksRef "refs/remotes/origin/$branch") { Invoke-StocksGit rev-parse "refs/remotes/origin/$branch" } else { '' }
            if ($actualRemote -cne $remoteHead -or
                (Test-StocksRef $reservation.Ref.Replace('refs/heads/', 'refs/remotes/origin/'))) {
                throw 'The feature changed while registering its reservation. Nothing was pushed.'
            }
        }
        $push = @('-c', 'push.followTags=false', '-c', 'remote.origin.mirror=false', 'push', '--atomic',
            "--force-with-lease=refs/heads/${branch}:$remoteHead", 'origin', "${savedHead}:refs/heads/$branch")
        if (-not $active) {
            $push = @('-c', 'push.followTags=false', '-c', 'remote.origin.mirror=false', 'push', '--atomic',
                "--force-with-lease=refs/heads/${branch}:$remoteHead", "--force-with-lease=$($reservation.Ref):", 'origin',
                "${savedHead}:refs/heads/$branch", "$($reservation.Commit):$($reservation.Ref)")
        }
        Invoke-StocksGit @push
    }
    catch {
        Write-Host 'Changes remain committed locally; the GitHub save failed. Do not change devices yet.' -ForegroundColor Yellow
        throw
    }
    Write-Host "Saved $branch on origin. main and Cloudways are unchanged." -ForegroundColor Green
}

function gitupdate {
    param([switch]$NoPrepare)
    Assert-StocksRepository
    $branch = Assert-StocksFeature
    Assert-StocksClean
    Update-StocksRemote
    Assert-StocksSaved
    if (-not (Test-StocksRef "refs/remotes/origin/$branch")) {
        throw 'Save the feature with gitsave before updating it.'
    }
    Invoke-StocksGit -c merge.autostash=false merge --ff-only "refs/remotes/origin/$branch"
    Invoke-StocksGit -c merge.autostash=false merge --no-edit refs/remotes/origin/main
    if (-not $NoPrepare) { Invoke-StocksLocalPreparation }
    Write-Host 'main was incorporated locally. Test the feature, then use gitsave to share it.' -ForegroundColor Green
}

function gitcheck {
    Assert-StocksRepository
    Update-StocksRemote
    $branch = Invoke-StocksGit branch --show-current
    $features = @(Get-StocksOpenFeatures)
    Write-Host "Branch: $branch" -ForegroundColor Cyan
    Write-Host "Open features: $($features -join ', '). Select with gitwork NAME; preview with gitpreview -Feature NAME." -ForegroundColor Cyan
    Invoke-StocksGit status --short
    if (Test-StocksRef "refs/remotes/origin/$branch") {
        $counts = (Invoke-StocksGit rev-list --left-right --count "HEAD...refs/remotes/origin/$branch") -split '\s+'
        Write-Host "Only local / not yet available on other devices: $($counts[0]) commits"
        Write-Host "Only on origin / not yet downloaded: $($counts[1]) commits"
    }
    else { Write-Host 'This branch is not saved on origin.' -ForegroundColor Yellow }
    if ($branch -cmatch '^codex/[a-z0-9]+(?:-[a-z0-9]+)*$') {
        $missing = Invoke-StocksGit rev-list --count HEAD..refs/remotes/origin/main
        Write-Host "Commits missing from main: $missing (gitupdate incorporates them)"
        $schemaChanges = Invoke-StocksGit diff --name-only refs/remotes/origin/main...HEAD -- database/migrations config/database.php
        if ($schemaChanges) {
            Write-Host 'Database changes: use a separate local feature database before running migrations.' -ForegroundColor Yellow
            $schemaChanges
        }
    }
    Write-Host 'Live deploys main; the isolated preview deploys the feature. gitcheck does not inspect either server.' -ForegroundColor Cyan
}

function gitpreview {
    param(
        [ValidateSet('deploy', 'prepare', 'resume')][string]$Mode = 'deploy',
        [string]$BundleId,
        [string]$FeatureName,
        [switch]$Main,
        [switch]$RefreshData
    )
    Assert-StocksRepository
    Assert-StocksClean
    if ($Mode -ceq 'prepare' -and $RefreshData) { throw 'RefreshData requires an online preview deployment.' }
    $branch = Invoke-StocksGit branch --show-current
    if ($Main -and $FeatureName) { throw 'Choose either -Main or -Feature NAME.' }
    if ($Main) {
        if ($branch -cne 'main') { throw 'Use gitmain before gitpreview -Main.' }
    }
    else {
        $null = Assert-StocksFeature
        Update-StocksRemote
        $null = Get-StocksActiveFeature -Branch $branch
        if ($FeatureName -and (Get-StocksFeatureBranch $FeatureName) -cne $branch) {
            throw 'The selected preview feature differs from the checkout. Use gitwork NAME first.'
        }
        $features = @(Get-StocksOpenFeatures)
        if (-not $FeatureName -and $features.Count -gt 1) {
            throw 'Choose the shared preview explicitly with gitpreview -Feature NAME.'
        }
    }
    Update-StocksRemote
    $commit = Invoke-StocksGit rev-parse HEAD
    if ($commit -cne (Invoke-StocksGit rev-parse "refs/remotes/origin/$branch")) {
        throw 'Save the exact preview commit with gitsave before publishing it.'
    }
    if (-not $Main -and -not (Test-StocksAncestor refs/remotes/origin/main HEAD)) {
        throw 'Integrate current main with gitupdate, test and gitsave before preview.'
    }
    . (Join-Path $PSScriptRoot 'git_preview_helpers.ps1')
    $mainCommit = Invoke-StocksGit rev-parse refs/remotes/origin/main
    $bundle = if ($Mode -ceq 'resume') {
        Read-StocksPreviewReceipt -Id $BundleId -Branch $branch -Commit $commit -MainCommit $mainCommit
    }
    else {
        $prepared = New-StocksPreviewBundle -Branch $branch -Commit $commit
        Save-StocksPreviewReceipt -Bundle $prepared -Branch $branch -MainCommit $mainCommit
        $prepared
    }
    if ($Mode -ceq 'prepare') { return }
    $target = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'stocks_preview_target.json') -Raw | ConvertFrom-Json
    $destination = "$($target.sshUser)@$($target.serverAddress)"
    $root = $target.canonicalTargetRoot
    $markerJson = @(Invoke-StocksPreviewSsh -Destination $destination -Command "cat '$root/storage/framework/stocks-preview-instance'") -join "`n"
    $marker = $markerJson | ConvertFrom-Json
    $oldCommit = $marker.commit
    if ($marker.format -cne 'stocks-preview-instance-v1' -or $marker.state -cne 'active' -or
        $marker.root -cne $root -or $marker.source_app_id -cne $target.sourceAppId -or
        $marker.target_app_id -cne $target.targetAppId -or $oldCommit -cnotmatch '^[a-f0-9]{40}$') {
        throw 'The installed preview marker does not match the trusted target.'
    }
    if (-not $RefreshData -and (-not (Test-StocksAncestor $oldCommit HEAD) -or
        ($Main -and -not (Test-StocksAncestor $oldCommit refs/remotes/origin/main)) -or
        (-not $Main -and (Test-StocksAncestor $oldCommit refs/remotes/origin/main)))) {
        throw 'The selected preview would change its feature identity. A fresh data snapshot is required before switching.'
    }
    & git cat-file -e "$oldCommit^{commit}"
    if ($LASTEXITCODE -ne 0) { throw 'The installed preview commit is not available locally for a migration comparison.' }
    if (Invoke-StocksGit diff --name-only "$oldCommit..HEAD" '--' database/migrations) {
        throw 'The preview update contains database migrations. A migration-aware preview deploy is required.'
    }
    Update-StocksRemote
    if ((Invoke-StocksGit rev-parse "refs/remotes/origin/$branch") -cne $commit) {
        throw 'The preview branch changed during preparation. Review and retry.'
    }
    Send-StocksPreviewBundle -Bundle $bundle -OldCommit $oldCommit -RefreshData:$RefreshData
}

function gitrelease {
    param([Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$Message, [string]$Version)
    Assert-StocksRepository
    Assert-StocksClean
    $branch = Assert-StocksFeature
    Update-StocksRemote
    $active = Get-StocksActiveFeature -Branch $branch
    $featureCommit = Invoke-StocksGit rev-parse HEAD
    $remoteFeature = Invoke-StocksGit rev-parse "refs/remotes/origin/$branch"
    if ($featureCommit -cne $remoteFeature) {
        throw 'Save the exact feature commit with gitsave before releasing it.'
    }
    $mainCommit = Invoke-StocksGit rev-parse refs/remotes/origin/main
    Write-Host "Release candidate: $branch at $featureCommit into main at $mainCommit" -ForegroundColor Yellow
    if ((Read-Host 'Publish this feature to main? Type RELEASE') -cne 'RELEASE') {
        throw 'Release cancelled; no branch was switched.'
    }
    Update-StocksRemote
    Assert-StocksFeatureSnapshot -Feature $active -FeatureCommit $featureCommit -MainCommit $mainCommit
    gitmain -NoPrepare
    Invoke-StocksGit merge --squash $branch
    & git diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        throw 'The feature has no changes to release. The feature branch remains available.'
    }
    if ($LASTEXITCODE -ne 1) { throw 'Could not inspect the squashed release candidate.' }
    . (Join-Path $PSScriptRoot 'git_helpers.ps1')
    gitpush -message $Message -version $Version
    Assert-StocksFeatureSnapshot -Feature $active -FeatureCommit $featureCommit
    $rescue = "refs/stocks/released/$($active.Id)/$([guid]::NewGuid().ToString('N'))"
    Invoke-StocksGit update-ref "$rescue/feature" $featureCommit
    Invoke-StocksGit update-ref "$rescue/reservation" $active.Commit
    Invoke-StocksGit push --atomic "--force-with-lease=refs/heads/${branch}:$featureCommit" `
        "--force-with-lease=$($active.Ref):$($active.Commit)" origin ":refs/heads/$branch" ":$($active.Ref)"
    if (Test-StocksRef "refs/heads/$branch") { Invoke-StocksGit branch -D $branch }
    Write-Host "Released and closed $branch. Local recovery refs: $rescue/feature and $rescue/reservation" -ForegroundColor Green
}

function gitdeploy {
    Assert-StocksRepository
    Assert-StocksClean
    if ((Invoke-StocksGit branch --show-current) -cne 'main') {
        throw 'Switch to the clean main branch with gitmain before gitdeploy.'
    }
    Update-StocksRemote
    $releaseCommit = Invoke-StocksGit rev-parse HEAD
    if ($releaseCommit -cne (Invoke-StocksGit rev-parse refs/remotes/origin/main)) {
        throw 'Local main must match GitHub main exactly before gitdeploy.'
    }
    if (-not (Test-Path -LiteralPath 'deployment/source-commit' -PathType Leaf)) {
        throw 'The release source marker is missing. Publish a complete release first.'
    }
    $sourceCommit = ([IO.File]::ReadAllText((Join-Path (Get-Location).Path 'deployment/source-commit'))).Trim()
    if ($sourceCommit -cnotmatch '^[a-f0-9]{40,64}$') {
        throw 'The release source marker is invalid.'
    }
    & php scripts/frontend-release.php verify $sourceCommit
    if ($LASTEXITCODE -ne 0) { throw 'The local release package did not verify.' }
    $target = 'sftp_gkstocks_admin@165.227.156.99'
    $root = '/home/1486907.cloudwaysapps.com/cfbckymfgk/public_html'
    Write-Host "Live target: $target $root" -ForegroundColor Yellow
    Write-Host "GitHub main: $releaseCommit; source: $sourceCommit" -ForegroundColor Yellow
    Write-Host 'Package integrity verified. GitHub tests run in the background; deployment does not wait for their result.' -ForegroundColor Cyan
    if ((Read-Host 'Install this release on live? Type LIVE') -cne 'LIVE') {
        throw 'Live deployment cancelled.'
    }
    Update-StocksRemote
    if ((Invoke-StocksGit rev-parse refs/remotes/origin/main) -cne $releaseCommit -or
        (Invoke-StocksGit rev-parse HEAD) -cne $releaseCommit) {
        throw 'main changed during confirmation. Review and retry gitdeploy.'
    }
    . (Join-Path $PSScriptRoot 'git_deploy_helpers.ps1')
    $remoteCommand = "cd '$root' && test -f scripts/pdeploy_cloudways.sh && STOCKS_EXPECTED_SOURCE_COMMIT=$sourceCommit composer pdeploy --no-interaction && test `"`$(tr -d '\r\n' < deployment/source-commit)`" = '$sourceCommit'"
    Invoke-StocksLiveSsh -Destination $target -Command $remoteCommand
    Write-Host "Live deployment completed for source $sourceCommit." -ForegroundColor Green
}

function gitdiscard {
    param([Parameter(Mandatory = $true)][string]$Name)
    Assert-StocksRepository
    Assert-StocksClean
    if ((Invoke-StocksGit branch --show-current) -cne 'main') {
        throw 'Use gitmain before discarding a feature.'
    }
    $branch = Get-StocksFeatureBranch $Name
    Update-StocksRemote
    if ((Invoke-StocksGit rev-parse HEAD) -cne (Invoke-StocksGit rev-parse refs/remotes/origin/main)) {
        throw 'Local main must match GitHub main before discarding a feature.'
    }
    if (-not (Test-StocksRef "refs/remotes/origin/$branch")) {
        throw "Feature $branch does not exist on GitHub."
    }
    $featureCommit = Invoke-StocksGit rev-parse "refs/remotes/origin/$branch"
    $active = Get-StocksActiveFeature -Branch $branch
    if ((Test-StocksRef "refs/heads/$branch") -and
        (Invoke-StocksGit rev-parse "refs/heads/$branch") -cne $featureCommit) {
        throw 'Local and remote feature commits differ. Save or reconcile the feature before discarding it.'
    }
    $worktrees = @(Invoke-StocksGit worktree list --porcelain)
    if ($worktrees -ccontains "branch refs/heads/$branch") {
        throw "Feature $branch is checked out in a worktree. Switch it away before discarding."
    }
    . (Join-Path $PSScriptRoot 'git_preview_helpers.ps1')
    $target = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'stocks_preview_target.json') -Raw | ConvertFrom-Json
    $destination = "$($target.sshUser)@$($target.serverAddress)"
    $root = $target.canonicalTargetRoot
    $markerJson = @(Invoke-StocksPreviewSsh -Destination $destination -Command "cat '$root/storage/framework/stocks-preview-instance'") -join "`n"
    $marker = $markerJson | ConvertFrom-Json
    if ($marker.format -cne 'stocks-preview-instance-v1' -or $marker.state -cne 'active' -or
        $marker.root -cne $root -or $marker.target_app_id -cne '6690486' -or $marker.commit -cnotmatch '^[a-f0-9]{40}$') {
        throw 'Cannot verify the active preview identity. Feature was not discarded.'
    }
    if ((Test-StocksAncestor $marker.commit "refs/remotes/origin/$branch") -and
        -not (Test-StocksAncestor $marker.commit refs/remotes/origin/main)) {
        throw "Feature $branch is active on preview. Install another preview release first."
    }
    Write-Host "Discard $branch at $featureCommit without merging it into main." -ForegroundColor Yellow
    if ((Read-Host "Type DISCARD $branch") -cne "DISCARD $branch") {
        throw 'Feature discard cancelled.'
    }
    Assert-StocksFeatureSnapshot -Feature $active -FeatureCommit $featureCommit -MainCommit (Invoke-StocksGit rev-parse HEAD)
    $rescue = "refs/stocks/discarded/$($active.Id)/$([guid]::NewGuid().ToString('N'))"
    Invoke-StocksGit update-ref "$rescue/feature" $featureCommit
    Invoke-StocksGit update-ref "$rescue/reservation" $active.Commit
    Invoke-StocksGit push --atomic "--force-with-lease=refs/heads/${branch}:$featureCommit" `
        "--force-with-lease=$($active.Ref):$($active.Commit)" origin ":refs/heads/$branch" ":$($active.Ref)"
    if (Test-StocksRef "refs/heads/$branch") {
        Invoke-StocksGit branch -D $branch
    }
    Write-Host "Discarded $branch. Local recovery refs: $rescue/feature and $rescue/reservation" -ForegroundColor Green
}
