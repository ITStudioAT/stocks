$profilePath = $PROFILE.CurrentUserCurrentHost
$profileDirectory = Split-Path -Parent $profilePath
$legacyStartMarker = '# >>> schooltool managed helpers >>>'
$legacyEndMarker = '# <<< schooltool managed helpers <<<'
$startMarker = '# >>> project git dispatcher >>>'
$endMarker = '# <<< project git dispatcher <<<'
$managedBlock = @"
$startMarker
function gitpush {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = `$true)]
        [string]`$message,

        [Parameter(Mandatory = `$false)]
        [string]`$version,

        [Parameter(Mandatory = `$false)]
        [switch]`$WaitForCI,

        [Parameter(Mandatory = `$false)]
        [switch]`$Full
    )

    `$repositoryRoot = git rev-parse --show-toplevel 2>`$null

    if (`$LASTEXITCODE -ne 0 -or -not `$repositoryRoot) {
        throw 'gitpush must be run inside a Git repository.'
    }

    `$repositoryRoot = `$repositoryRoot.Trim()
    `$remoteUrl = git -C `$repositoryRoot remote get-url origin 2>`$null

    if (`$LASTEXITCODE -ne 0 -or -not `$remoteUrl) {
        throw 'gitpush requires an origin remote.'
    }

    `$remoteUrl = `$remoteUrl.Trim()
    `$trustedRemotePattern = '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)(?<repository>ITStudioAT/(?:schooltool|stocks))(?:\.git)?/?$'
    `$remoteMatch = [regex]::Match(`$remoteUrl, `$trustedRemotePattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    if (-not `$remoteMatch.Success) {
        throw "gitpush does not trust the origin remote: `$remoteUrl"
    }

    `$pushUrls = @(git -C `$repositoryRoot remote get-url --all --push origin 2>`$null)

    if (`$LASTEXITCODE -ne 0 -or `$pushUrls.Count -eq 0) {
        throw 'gitpush requires an origin push URL.'
    }

    foreach (`$pushUrl in `$pushUrls) {
        `$pushUrl = `$pushUrl.Trim()
        `$pushMatch = [regex]::Match(`$pushUrl, `$trustedRemotePattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

        if (-not `$pushMatch.Success) {
            throw "gitpush does not trust the origin push URL: `$pushUrl"
        }
    }

    `$projectGitPush = Join-Path `$repositoryRoot 'scripts/gitpush.ps1'

    if (-not (Test-Path -LiteralPath `$projectGitPush -PathType Leaf)) {
        throw "The trusted repository does not provide scripts/gitpush.ps1: `$repositoryRoot"
    }

    Push-Location -LiteralPath `$repositoryRoot

    try {
        & `$projectGitPush @PSBoundParameters
    }
    finally {
        Pop-Location
    }
}
$endMarker
"@

if (-not (Test-Path -LiteralPath $profileDirectory)) {
    [System.IO.Directory]::CreateDirectory($profileDirectory) | Out-Null
}

$profileContent = if (Test-Path -LiteralPath $profilePath) {
    [System.IO.File]::ReadAllText($profilePath)
}
else {
    ''
}

foreach ($markers in @(
    @($legacyStartMarker, $legacyEndMarker),
    @($startMarker, $endMarker)
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

$profileContent += $managedBlock + [Environment]::NewLine
$windowsPowerShellUtf8 = New-Object System.Text.UTF8Encoding($true)
[System.IO.File]::WriteAllText($profilePath, $profileContent, $windowsPowerShellUtf8)

git config core.hooksPath .githooks
if ($LASTEXITCODE -ne 0) {
    throw 'Could not configure the repository hooks path.'
}

Write-Host "Project-aware Git helpers installed in $profilePath" -ForegroundColor Green
Write-Host 'Open a new PowerShell terminal before using gitpush.' -ForegroundColor Cyan
