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
        throw 'Local .env is missing. Follow docs/windows-git-workflow.md, then run gitprepare.'
    }
    $localEnvironment = @(Get-Content -LiteralPath '.env' | Where-Object { $_ -match '^\s*APP_ENV\s*=' })
    if ($localEnvironment.Count -ne 1 -or $localEnvironment[0] -notmatch '^\s*APP_ENV\s*=\s*["'']?local["'']?\s*(?:#.*)?$' -or
        ($env:APP_ENV -and $env:APP_ENV -ne 'local')) {
        throw 'Local preparation requires APP_ENV=local in .env and no non-local APP_ENV override.'
    }
    & php scripts/update.php --target=local --prepare
    if ($LASTEXITCODE -ne 0) { throw 'Dependency preparation failed. Fix the error, then retry gitprepare.' }
    & php artisan config:clear --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Configuration cache clear failed. Retry gitprepare after fixing the error.' }
    & php artisan view:clear --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'View cache clear failed. Retry gitprepare after fixing the error.' }
    $npmExecutable = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
    & $npmExecutable run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed. Fix the error, then retry gitprepare.' }
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
    if ((Test-StocksRef "refs/heads/$branch") -or (Test-StocksRef "refs/remotes/origin/$branch")) {
        throw "$branch already exists. Use gitwork NAME."
    }
    Invoke-StocksGit switch --no-track -c $branch refs/remotes/origin/main
    if (-not $NoPrepare) { Invoke-StocksLocalPreparation }
    Write-Host 'Feature created locally. Use gitsave DESCRIPTION to share it with the other devices.' -ForegroundColor Green
}

function gitwork {
    param([string]$Name, [switch]$NoPrepare)
    Assert-StocksRepository
    Assert-StocksClean
    Update-StocksRemote
    if (-not $Name) {
        $features = @(Invoke-StocksGit for-each-ref '--format=%(refname:strip=3)' refs/remotes/origin/codex/)
        if ($features.Count -ne 1) {
            throw "Choose a feature with gitwork NAME. Available: $($features -join ', '). Use gitstart NAME for new work."
        }
        $Name = $features[0]
    }
    Switch-StocksBranch -Branch (Get-StocksFeatureBranch $Name) -NoPrepare:$NoPrepare
}

function gitmain {
    param([switch]$NoPrepare)
    Assert-StocksRepository
    Assert-StocksClean
    Update-StocksRemote
    Switch-StocksBranch -Branch main -NoPrepare:$NoPrepare
}

function gitsave {
    param([Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$Message)
    if ([string]::IsNullOrWhiteSpace($Message)) { throw 'Supply a meaningful commit message.' }
    Assert-StocksRepository
    $branch = Assert-StocksFeature
    Update-StocksRemote
    if ((Test-StocksRef "refs/remotes/origin/$branch") -and
        -not (Test-StocksAncestor "refs/remotes/origin/$branch" HEAD)) {
        throw 'The remote feature contains changes missing locally. Nothing was committed. Synchronize this feature before saving.'
    }
    if (Invoke-StocksGit status --porcelain --untracked-files=all) {
        Invoke-StocksGit add -A
        Invoke-StocksGit commit -m $Message
    }
    Assert-StocksClean
    try {
        Invoke-StocksGit -c push.followTags=false -c remote.origin.mirror=false push --set-upstream origin "HEAD:refs/heads/$branch"
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

function gitprepare {
    Assert-StocksRepository
    Invoke-StocksLocalPreparation
}

function gitcheck {
    Assert-StocksRepository
    Update-StocksRemote
    Invoke-StocksGit status --short --branch
    Invoke-StocksGit branch -vv
    Write-Host 'Remote features:' -ForegroundColor Cyan
    Invoke-StocksGit for-each-ref '--format=%(refname:strip=3)' refs/remotes/origin/codex/
}

function gitpreview {
    Assert-StocksRepository
    Assert-StocksClean
    $branch = Invoke-StocksGit branch --show-current
    if ($branch -cne 'main') { $null = Assert-StocksFeature }
    $commit = Invoke-StocksGit rev-parse HEAD
    $targetPath = Join-Path $PSScriptRoot 'stocks_preview_target.json'
    $target = Get-Content -LiteralPath $targetPath -Raw | ConvertFrom-Json
    if ($target.repository -cne 'ITStudioAT/stocks' -or $target.sourceAppId -eq $target.targetAppId -or
        $target.sourceDatabase -ceq $target.targetDatabase -or $target.sourceDatabaseUser -ceq $target.targetDatabaseUser) {
        throw 'The preview target identity is invalid. No plan was created.'
    }
    $pending = @('app-ssh-access', 'canonical-target-root', 'unix-owner', 'web-php-patch', 'cli-php-version',
        'schema-only-database-grants', 'independent-app-key', 'private-preview-access', 'snapshot-export',
        'backup-and-restore-drill', 'initial-installation', 'exact-commit-ci', 'preview-smoke-test')
    $plan = [ordered]@{
        format = 'stocks-preview-plan-v1'
        branch = $branch
        sourceCommit = $commit
        target = $target
        pendingGates = $pending
        canDeploy = $false
    }
    $planDirectory = Invoke-StocksGit rev-parse --git-path stocks-preview
    $null = New-Item -ItemType Directory -Path $planDirectory -Force
    $planPath = Join-Path $planDirectory ("plan-$commit.json")
    $json = $plan | ConvertTo-Json -Depth 5
    [IO.File]::WriteAllText([IO.Path]::GetFullPath($planPath), $json, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "Prepared review plan: $planPath" -ForegroundColor Green
    Write-Host "Source: $branch at $commit"
    Write-Host "Target: $($target.targetAppId) at $($target.targetUrl)"
    Write-Host "Pending gates: $($pending -join ', ')" -ForegroundColor Yellow
    Write-Host 'No archive, database copy, GitHub push or deployment was performed. See docs/preview-cloudways.md.'
}
