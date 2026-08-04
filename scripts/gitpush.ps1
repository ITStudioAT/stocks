[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$message,

    [Parameter(Mandatory = $false)]
    [string]$version,

    [Parameter(Mandatory = $false)]
    [switch]$WaitForCI,

    [Parameter(Mandatory = $false)]
    [switch]$Full
)

. (Join-Path $PSScriptRoot 'git_helpers.ps1')

gitpush @PSBoundParameters
