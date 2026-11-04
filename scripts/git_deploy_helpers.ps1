function Get-StocksLiveSshExecutable {
    $ssh = Get-Command ssh.exe -CommandType Application -ErrorAction SilentlyContinue
    if ($ssh) {
        $executable = $ssh.Source
    }
    else {
        $candidates = @()
        if ($env:WINDIR) {
            $candidates = @(
                (Join-Path $env:WINDIR 'Sysnative/OpenSSH/ssh.exe'),
                (Join-Path $env:WINDIR 'System32/OpenSSH/ssh.exe'),
                (Join-Path $env:SystemDrive 'Program Files/Git/usr/bin/ssh.exe')
            )
        }
        $executable = $candidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
        if (-not $executable) {
            $ssh = Get-Command ssh -CommandType Application -ErrorAction SilentlyContinue
            if (-not $ssh) { throw 'No SSH client was found. Install Windows OpenSSH Client or Git for Windows.' }
            $executable = $ssh.Source
        }
    }
    $executable
}

function Invoke-StocksLiveSsh {
    param([string]$Destination, [string]$Command, [string]$InputText)
    $executable = Get-StocksLiveSshExecutable
    if ($InputText) {
        $InputText | & $executable -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 $Destination $Command
    }
    else {
        & $executable -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 $Destination $Command
    }
    if ($LASTEXITCODE -ne 0) {
        throw 'Live deployment or exact source verification failed. Inspect Cloudways before retrying.'
    }
}

function Get-StocksLiveDeploymentInput {
    $payload = @'
set -Eeuo pipefail
deployment_umask="$(umask)"
umask 077
bootstrap_directory="$(mktemp -d storage/framework/.stocks-pull.XXXXXX)"
trap 'rm -rf -- "$bootstrap_directory"' EXIT
'@
    $files = [ordered]@{
        'CloudwaysApiClient.php' = 'app/Services/CloudwaysApiClient.php'
        'CloudwaysPullCommand.php' = 'app/Console/Commands/CloudwaysPullCommand.php'
        'cloudways-pull.php' = 'scripts/cloudways-pull.php'
        'pdeploy_cloudways.sh' = 'scripts/pdeploy_cloudways.sh'
    }
    foreach ($file in $files.GetEnumerator()) {
        $bytes = [IO.File]::ReadAllBytes((Join-Path (Get-Location).Path $file.Value))
        $encoded = [Convert]::ToBase64String($bytes)
        $hasher = [Security.Cryptography.SHA256]::Create()
        try {
            $digest = [BitConverter]::ToString($hasher.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
        }
        finally { $hasher.Dispose() }
        $name = $file.Key
        $payload += "`nprintf '%s' '$encoded' | base64 --decode > `"`$bootstrap_directory/$name`"`nprintf '%s  %s\n' '$digest' `"`$bootstrap_directory/$name`" | sha256sum --check --status`n"
    }
    $payload += @'

export STOCKS_DEPLOY_PROJECT_ROOT="$PWD"
export STOCKS_CLOUDWAYS_PULL_LAUNCHER="$PWD/$bootstrap_directory/cloudways-pull.php"
export STOCKS_EXPECTED_SOURCE_COMMIT="$1"
umask "$deployment_umask"
bash "$bootstrap_directory/pdeploy_cloudways.sh"
test "$(tr -d '\r\n' < deployment/source-commit)" = "$1"
'@
    $payload.Replace("`r`n", "`n")
}
