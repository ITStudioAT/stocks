[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('gitstart', 'gitwork', 'gitmain', 'gitsave', 'gitupdate', 'gitprepare', 'gitcheck', 'gitpreview')]
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
    'gitwork' {
        if ($arguments.Count -gt 1) { throw 'Usage: gitwork [NAME] [-NoPrepare]' }
        if ($arguments.Count -eq 1) { $parameters.Name = $arguments[0] }
    }
    'gitsave' {
        if ($arguments.Count -ne 1) { throw 'Usage: gitsave "DESCRIPTION" (no version or release flags)' }
        $parameters.Message = $arguments[0]
    }
    'gitpreview' {
        if ($arguments.Count -gt 1 -or ($arguments.Count -eq 1 -and $arguments[0] -cnotin @('prepare', 'bundle'))) {
            throw 'Only gitpreview prepare or bundle is available until the initial Cloudways installation and restore gates are verified.'
        }
        if ($arguments.Count -eq 1) { $parameters.Mode = $arguments[0] }
    }
    default {
        if ($arguments.Count -gt 0) { throw "Unexpected arguments for $Command." }
    }
}

Push-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
try { & $Command @parameters }
finally { Pop-Location }
