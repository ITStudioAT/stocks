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
    param([string]$Destination, [string]$Command)
    $executable = Get-StocksLiveSshExecutable
    & $executable -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 $Destination $Command
    if ($LASTEXITCODE -ne 0) {
        throw 'Live deployment or exact source verification failed. Inspect Cloudways before retrying.'
    }
}
