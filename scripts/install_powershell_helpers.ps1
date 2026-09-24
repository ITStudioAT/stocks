param(
    [string[]]$ProfilePaths = @(
        $PROFILE.CurrentUserCurrentHost
        (Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'PowerShell\Microsoft.PowerShell_profile.ps1')
        (Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'WindowsPowerShell\Microsoft.PowerShell_profile.ps1')
    )
)

$ErrorActionPreference = 'Stop'
$profilePaths = $ProfilePaths | Select-Object -Unique
$legacyStartMarker = '# >>> schooltool managed helpers >>>'
$legacyEndMarker = '# <<< schooltool managed helpers <<<'
$startMarker = '# >>> project git dispatcher >>>'
$endMarker = '# <<< project git dispatcher <<<'
$sshStartMarker = '# >>> project SSH dispatcher >>>'
$sshEndMarker = '# <<< project SSH dispatcher <<<'
$managedBlock = @"
$startMarker
function mu {
    [CmdletBinding()]
    param()

    if (-not (Test-Path -LiteralPath (Join-Path (Get-Location) 'composer.json') -PathType Leaf) -or
        -not (Test-Path -LiteralPath (Join-Path (Get-Location) 'package.json') -PathType Leaf)) {
        throw 'mu must be run from a project containing composer.json and package.json.'
    }

    `$npmExecutable = if (`$env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }

    Write-Host 'Checking Composer packages...' -ForegroundColor Cyan
    & composer outdated --direct

    if (`$LASTEXITCODE -ne 0) {
        throw 'Could not check Composer package updates.'
    }

    Write-Host 'Checking npm packages...' -ForegroundColor Cyan
    & `$npmExecutable outdated

    Write-Host 'Updating Composer packages...' -ForegroundColor Cyan
    & composer update

    if (`$LASTEXITCODE -ne 0) {
        throw 'Composer package update failed.'
    }

    Write-Host 'Updating npm packages...' -ForegroundColor Cyan
    & `$npmExecutable update

    if (`$LASTEXITCODE -ne 0) {
        throw 'npm package update failed.'
    }

    Write-Host 'Composer and npm packages are up to date.' -ForegroundColor Green
}

function gitpull {
    git pull @args
    if (`$LASTEXITCODE -ne 0) {
        throw "git pull failed with exit code `$LASTEXITCODE."
    }
    `$viennaTimeZone = [TimeZoneInfo]::FindSystemTimeZoneById('W. Europe Standard Time')
    `$finishedAt = [TimeZoneInfo]::ConvertTime([DateTimeOffset]::UtcNow, `$viennaTimeZone)
    Write-Host ("Abgeschlossen: {0} (Europe/Vienna)" -f `$finishedAt.ToString('dd.MM.yyyy HH:mm:ss zzz')) -ForegroundColor Green
}

function Invoke-ProjectGitWorkflow {
    param([string]`$Command, [string[]]`$CommandArguments)
    `$repositoryRoot = git rev-parse --show-toplevel 2>`$null
    if (`$LASTEXITCODE -ne 0 -or -not `$repositoryRoot) {
        throw 'Run this command inside the Stocks or Schooltool repository.'
    }
    `$repositoryRoot = `$repositoryRoot.Trim()
    `$urls = @(git -C `$repositoryRoot remote get-url --all origin 2>`$null)
    if (`$LASTEXITCODE -ne 0 -or `$urls.Count -ne 1) {
        throw 'The workflow requires exactly one origin fetch URL.'
    }
    `$pushUrls = @(git -C `$repositoryRoot remote get-url --all --push origin 2>`$null)
    if (`$LASTEXITCODE -ne 0 -or `$pushUrls.Count -ne 1) {
        throw 'The workflow requires exactly one origin push URL.'
    }
    `$repository = `$null
    foreach (`$url in @(`$urls + `$pushUrls)) {
        `$match = [regex]::Match(`$url.Trim(), '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)(?<repository>ITStudioAT/(?:schooltool|stocks))(?:\.git)?/?$', 'IgnoreCase')
        if (-not `$match.Success -or (`$repository -and `$repository -ine `$match.Groups['repository'].Value)) {
            throw 'The workflow requires matching trusted fetch and push repositories.'
        }
        `$repository = `$match.Groups['repository'].Value
    }
    `$workflow = Join-Path `$repositoryRoot 'scripts/git_workflow.ps1'
    if (-not (Test-Path -LiteralPath `$workflow -PathType Leaf)) {
        throw 'This branch does not contain the workflow helpers yet. Incorporate the workflow setup before using these commands.'
    }
    Push-Location -LiteralPath `$repositoryRoot
    try { & `$workflow -Command `$Command -CommandArguments `$CommandArguments }
    finally { Pop-Location }
}
if (-not (Get-Variable -Name ProjectLegacyGitPush -Scope Global -ErrorAction SilentlyContinue)) {
    `$existingGitPush = Get-Item Function:\gitpush -ErrorAction SilentlyContinue
    `$global:ProjectLegacyGitPush = if (`$existingGitPush) { `$existingGitPush.ScriptBlock } else { `$null }
}
function gitpush {
    `$repositoryRoot = git rev-parse --show-toplevel 2>`$null
    if (`$LASTEXITCODE -eq 0 -and `$repositoryRoot) {
        `$remoteUrl = git -C `$repositoryRoot.Trim() remote get-url origin 2>`$null
        if (`$LASTEXITCODE -eq 0 -and `$remoteUrl -match '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)ITStudioAT/stocks(?:\.git)?/?`$') {
            Invoke-ProjectGitWorkflow 'gitpush' `$args
            return
        }
    }
    if (`$global:ProjectLegacyGitPush) {
        & `$global:ProjectLegacyGitPush @args
        return
    }
    throw 'No gitpush helper was defined for this repository.'
}
function gitstart { Invoke-ProjectGitWorkflow 'gitstart' `$args }
function gitwork { Invoke-ProjectGitWorkflow 'gitwork' `$args }
function gitmain { Invoke-ProjectGitWorkflow 'gitmain' `$args }
function gitsave { Invoke-ProjectGitWorkflow 'gitsave' `$args }
function gitupdate { Invoke-ProjectGitWorkflow 'gitupdate' `$args }
function gitcheck { Invoke-ProjectGitWorkflow 'gitcheck' `$args }
function gitrelease { Invoke-ProjectGitWorkflow 'gitrelease' `$args }
function gitdiscard { Invoke-ProjectGitWorkflow 'gitdiscard' `$args }
function gitpreview { Invoke-ProjectGitWorkflow 'gitpreview' `$args }
function gitdeploy { Invoke-ProjectGitWorkflow 'gitdeploy' `$args }
$endMarker
"@
$sshManagedBlock = @"
$sshStartMarker
function sshx {
    [CmdletBinding()]
    param()

    `$repositoryRoot = git rev-parse --show-toplevel 2>`$null

    if (`$LASTEXITCODE -ne 0 -or -not `$repositoryRoot) {
        throw 'sshx must be run inside a Git repository.'
    }

    `$repositoryRoot = `$repositoryRoot.Trim()
    `$remoteUrl = git -C `$repositoryRoot remote get-url origin 2>`$null

    if (`$LASTEXITCODE -ne 0 -or -not `$remoteUrl) {
        throw 'sshx requires an origin remote.'
    }

    `$remoteUrl = `$remoteUrl.Trim()
    `$trustedRemotePattern = '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)ITStudioAT/(?<project>schooltool|stocks)(?:\.git)?/?$'
    `$remoteMatch = [regex]::Match(`$remoteUrl, `$trustedRemotePattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    if (-not `$remoteMatch.Success) {
        throw "sshx does not trust the origin remote: `$remoteUrl"
    }

    `$project = `$remoteMatch.Groups['project'].Value.ToLowerInvariant()
    `$projectDestinations = @{
        schooltool = 'sftp_schooltool_at@165.227.156.99'
        stocks = 'sftp_gkstocks_admin@165.227.156.99'
    }
    `$destination = `$projectDestinations[`$project]
    `$sshCommand = Get-Command ssh.exe -ErrorAction SilentlyContinue
    `$sshExecutable = if (`$sshCommand) {
        `$sshCommand.Source
    }
    elseif (Test-Path -LiteralPath 'C:\Program Files\Git\usr\bin\ssh.exe' -PathType Leaf) {
        'C:\Program Files\Git\usr\bin\ssh.exe'
    }
    else {
        throw 'No SSH client was found. Install Windows OpenSSH Client or Git for Windows.'
    }

    Write-Host "Connecting `$project to `$destination..." -ForegroundColor Cyan
    & `$sshExecutable `$destination
}
$sshEndMarker
"@

$windowsPowerShellUtf8 = New-Object System.Text.UTF8Encoding($true)

foreach ($profilePath in $profilePaths) {
    $profileDirectory = Split-Path -Parent $profilePath

    if (-not (Test-Path -LiteralPath $profileDirectory)) {
        [System.IO.Directory]::CreateDirectory($profileDirectory) | Out-Null
    }

    $profileContent = if (Test-Path -LiteralPath $profilePath) {
        [System.IO.File]::ReadAllText($profilePath)
    }
    else {
        ''
    }
    $originalContent = $profileContent

    foreach ($markers in @(
        @($legacyStartMarker, $legacyEndMarker),
        @($startMarker, $endMarker),
        @($sshStartMarker, $sshEndMarker)
    )) {
        $pattern = [regex]::Escape($markers[0]) + '.*?' + [regex]::Escape($markers[1])
        $profileContent = [regex]::Replace(
            $profileContent,
            $pattern,
            '',
            [System.Text.RegularExpressions.RegexOptions]::Singleline
        ).TrimEnd()
    }

    if ($profileContent) {
        $profileContent += [Environment]::NewLine + [Environment]::NewLine
    }

    $profileContent += $managedBlock + [Environment]::NewLine + [Environment]::NewLine
    $profileContent += $sshManagedBlock + [Environment]::NewLine
    if ($profileContent -ne $originalContent) {
        if (Test-Path -LiteralPath $profilePath) {
            $backupPath = $profilePath + '.stocks-backup-' + [guid]::NewGuid().ToString('N')
            [System.IO.File]::Copy($profilePath, $backupPath)
        }
        [System.IO.File]::WriteAllText($profilePath, $profileContent, $windowsPowerShellUtf8)
    }

    Write-Host "Project-aware helpers installed in $profilePath" -ForegroundColor Green
}

$repositoryRoot = Split-Path -Parent $PSScriptRoot
git -C $repositoryRoot config --local core.hooksPath .githooks
if ($LASTEXITCODE -ne 0) {
    throw 'Could not configure the repository hooks path.'
}

Write-Host 'Open a new PowerShell terminal or run: . $PROFILE' -ForegroundColor Cyan
Write-Host 'Stocks Git: gitstart, gitwork, gitmain, gitsave, gitpush, gitupdate, gitcheck, gitpreview, gitrelease, gitdeploy, gitdiscard.' -ForegroundColor Cyan
