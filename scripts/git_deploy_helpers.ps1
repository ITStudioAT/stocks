function Invoke-StocksLiveSsh {
    param([string]$Destination, [string]$Command)
    $ssh = Get-Command ssh.exe -ErrorAction Stop
    & $ssh.Source -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 $Destination $Command
    if ($LASTEXITCODE -ne 0) {
        throw 'Live deployment or exact source verification failed. Inspect Cloudways before retrying.'
    }
}
