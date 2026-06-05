$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $repoRoot

function Write-ClaudeBlock {
    param (
        [string] $Reason
    )

    $maxLength = 12000

    if ($Reason.Length -gt $maxLength) {
        $Reason = $Reason.Substring(0, $maxLength) + "`n... output truncated ..."
    }

    @{
        decision = 'block'
        reason = $Reason
    } | ConvertTo-Json -Compress
}

function Invoke-VerifiedCommand {
    param (
        [string] $Label,
        [string] $Executable,
        [string[]] $Arguments
    )

    $script:commandsRun.Add("$Executable $($Arguments -join ' ')") | Out-Null
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $output = & $Executable @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $outputText = ($output | ForEach-Object { $_.ToString() }) -join "`n"

    if ($exitCode -ne 0) {
        throw @"
$Label failed with exit code $exitCode.

Command:
$Executable $($Arguments -join ' ')

Output:
$outputText
"@
    }
}

try {
    $changedFiles = @(
        & git diff --name-only HEAD
        & git ls-files --others --exclude-standard
    ) |
        Where-Object { $_ -and ($_ -notmatch '^public/build/') } |
        Sort-Object -Unique

    if ($changedFiles.Count -eq 0) {
        exit 0
    }

    $frontendChanged = $changedFiles | Where-Object {
        $_ -match '^(resources/js|resources/css|tests/js)/' -or
        $_ -match '\.(vue|js|ts|css|scss)$' -or
        $_ -in @('package.json', 'package-lock.json', 'vite.config.js')
    }

    $phpChanged = $changedFiles | Where-Object {
        $_ -match '\.php$' -or
        $_ -match '^(app|routes|database|tests/Feature|tests/Unit)/' -or
        $_ -in @('composer.json', 'composer.lock')
    }

    $migrationChanged = $changedFiles | Where-Object {
        $_ -match '^database/migrations/'
    }

    $script:commandsRun = [System.Collections.Generic.List[string]]::new()

    if ($migrationChanged) {
        Invoke-VerifiedCommand 'Applying database migrations' 'php' @('artisan', 'migrate', '--force')
    }

    if ($phpChanged) {
        Invoke-VerifiedCommand 'Formatting PHP with Pint' 'vendor\bin\pint.bat' @('--dirty', '--format', 'agent')
        Invoke-VerifiedCommand 'Running PHP tests' 'php' @('artisan', 'test', '--compact')
    }

    if ($frontendChanged) {
        Invoke-VerifiedCommand 'Running frontend tests' 'npm.cmd' @('run', 'test:frontend')
        Invoke-VerifiedCommand 'Building frontend assets' 'npm.cmd' @('run', 'build')
    }

    exit 0
} catch {
    $changedText = ($changedFiles | ForEach-Object { "- $_" }) -join "`n"
    $commandsText = ($script:commandsRun | ForEach-Object { "- $_" }) -join "`n"

    Write-ClaudeBlock @"
Claude Code cannot finish yet because project verification failed.

Changed files:
$changedText

Commands run:
$commandsText

Failure:
$($_.Exception.Message)

Fix the failure, then run the verification again before finalizing.
"@

    exit 0
}
