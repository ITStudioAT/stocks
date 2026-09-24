[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('gitstart', 'gitwork', 'gitmain', 'gitsave', 'gitupdate', 'gitcheck', 'gitpreview', 'gitrelease', 'gitdeploy', 'gitdiscard')]
    [string]$Command,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$CommandArguments
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'git_branch_helpers.ps1')
$parameters = @{}
$arguments = @($CommandArguments | Where-Object { $null -ne $_ })
if ($Command -in @('gitstart', 'gitwork', 'gitmain', 'gitupdate')) {
    $flags = @($arguments | Where-Object { $_ -ceq '-NoPrepare' })
    if ($flags.Count -gt 1) { throw 'Specify -NoPrepare only once.' }
    if ($flags.Count -eq 1) { $parameters.NoPrepare = $true }
    $arguments = @($arguments | Where-Object { $_ -cne '-NoPrepare' })
}
switch ($Command) {
    'gitstart' {
        if ($arguments.Count -ne 1) { throw 'Usage: gitstart NAME [-NoPrepare]' }
        $parameters.Name = $arguments[0]
    }
    'gitdiscard' {
        if ($arguments.Count -ne 1) { throw 'Usage: gitdiscard NAME' }
        $parameters.Name = $arguments[0]
    }
    'gitwork' {
        if ($arguments.Count -gt 1) { throw 'Usage: gitwork [NAME] [-NoPrepare]' }
        if ($arguments.Count -eq 1) { $parameters.Name = $arguments[0] }
    }
    'gitsave' {
        $flags = @($arguments | Where-Object { $_ -cin @('-Full', '-WaitForCI') })
        if (@($flags | Select-Object -Unique).Count -ne $flags.Count) { throw 'Specify each gitsave flag only once.' }
        $positionals = @($arguments | Where-Object { $_ -cnotin @('-Full', '-WaitForCI') })
        if ($positionals.Count -lt 1 -or $positionals.Count -gt 2) { throw 'Usage: gitsave "DESCRIPTION" [VERSION] [-Full] [-WaitForCI]' }
        $parameters.Message = $positionals[0]
        if ($positionals.Count -eq 2) { $parameters.Version = $positionals[1] }
        if ($flags -ccontains '-Full') { $parameters.Full = $true }
        if ($flags -ccontains '-WaitForCI') { $parameters.WaitForCI = $true }
    }
    'gitrelease' {
        if ($arguments.Count -lt 1 -or $arguments.Count -gt 2) { throw 'Usage: gitrelease "DESCRIPTION" [VERSION]' }
        $parameters.Message = $arguments[0]
        if ($arguments.Count -eq 2) { $parameters.Version = $arguments[1] }
    }
    'gitpreview' {
        $expectFeature = $false
        $expectBundle = $false
        foreach ($argument in $arguments) {
            if ($expectFeature) {
                $parameters.FeatureName = $argument
                $expectFeature = $false
            }
            elseif ($expectBundle) {
                $parameters.BundleId = $argument
                $expectBundle = $false
            }
            elseif ($argument -ceq '-Feature' -and -not $parameters.ContainsKey('FeatureName')) {
                $expectFeature = $true
            }
            elseif ($argument -ceq '-Main' -and -not $parameters.ContainsKey('Main')) {
                $parameters.Main = $true
            }
            elseif ($argument -ceq '-RefreshData' -and -not $parameters.ContainsKey('RefreshData')) {
                $parameters.RefreshData = $true
            }
            elseif ($argument -cin @('deploy', 'prepare', 'resume') -and -not $parameters.ContainsKey('Mode')) {
                $parameters.Mode = $argument
                if ($argument -ceq 'resume') { $expectBundle = $true }
            }
            else { throw 'Usage: gitpreview [deploy|prepare|resume BUNDLE_ID] [-Feature NAME | -Main] [-RefreshData]' }
        }
        if ($expectFeature -or $expectBundle) { throw 'Usage: gitpreview [deploy|prepare|resume BUNDLE_ID] [-Feature NAME | -Main] [-RefreshData]' }
    }
    default {
        if ($arguments.Count -gt 0) { throw "Unexpected arguments for $Command." }
    }
}

Push-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
try { & $Command @parameters }
finally { Pop-Location }
