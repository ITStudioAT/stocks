param(
    [Parameter(Mandatory = $true)][string]$BundleDirectory,
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-f0-9]{64}$')][string]$ExpectedManifestSha256,
    [switch]$VerifyOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# This helper never reads a password. OpenSSH prompts directly in the user's terminal.
$bundle = (Resolve-Path -LiteralPath $BundleDirectory).Path
if ((Get-Item -LiteralPath $bundle).Attributes -band [IO.FileAttributes]::ReparsePoint) {
    throw 'A snapshot bundle must not be a link.'
}
$manifestPath = Join-Path $bundle 'transfer-files.sha256'
if ((Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $ExpectedManifestSha256) {
    throw 'Transfer manifest authentication failed.'
}
$required = @(
    'PreviewOriginalSchema.php', 'PreviewOriginalPolicy.php', 'PreviewSnapshotPolicy.php',
    'PreviewSnapshotArchive.php', 'PreviewSnapshotStream.php', 'PreviewSnapshotDatabase.php',
    'PreviewSnapshotTransfer.php', 'PreviewReleaseBundle.php', 'preview-snapshot.php',
    'incoming-request.json', 'incoming-recipient.key', 'original.snapshot', 'export-receipt.json'
)
$seen = @{}
foreach ($line in [IO.File]::ReadAllLines($manifestPath)) {
    if ($line -cnotmatch '^([a-f0-9]{64})  ([A-Za-z0-9.-]+)$') {
        throw 'Invalid transfer manifest entry.'
    }
    $digest = $Matches[1]
    $name = $Matches[2]
    if ($required -cnotcontains $name -or $seen.ContainsKey($name)) {
        throw 'Unexpected or duplicate transfer file.'
    }
    $path = Join-Path $bundle $name
    $item = Get-Item -LiteralPath $path
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
        (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $digest) {
        throw "Transfer file verification failed: $name"
    }
    $seen[$name] = $digest
}
if ($seen.Count -ne $required.Count) { throw 'Transfer files are missing.' }
$request = Get-Content -LiteralPath (Join-Path $bundle 'incoming-request.json') -Raw | ConvertFrom-Json
$receipt = Get-Content -LiteralPath (Join-Path $bundle 'export-receipt.json') -Raw | ConvertFrom-Json
$context = $request.context
if ($context.source_app_id -cne '6468818' -or $context.target_app_id -cne '6690486' -or
    $context.target_commit -cne '75e531e6b022a09c424d6c73db4bff6923493b53' -or
    $context.nonce -cnotmatch '^[a-f0-9]{64}$' -or
    $receipt.sha256 -cne $seen['original.snapshot']) {
    throw 'Transfer source, target or snapshot identity mismatch.'
}
foreach ($field in @('source_app_id', 'target_app_id', 'source_commit', 'target_commit', 'nonce')) {
    if ($context.$field -cne $receipt.context.$field) { throw 'Export receipt context mismatch.' }
}
Write-Output 'All 13 transfer files and the original-data context are verified.'
if ($VerifyOnly) { return }

$sshDirectory = Join-Path $env:WINDIR 'Sysnative/OpenSSH'
if (-not (Test-Path -LiteralPath (Join-Path $sshDirectory 'ssh.exe'))) {
    $sshDirectory = Join-Path $env:WINDIR 'System32/OpenSSH'
}
$ssh = Join-Path $sshDirectory 'ssh.exe'
$scp = Join-Path $sshDirectory 'scp.exe'
if (-not (Test-Path -LiteralPath $ssh) -or -not (Test-Path -LiteralPath $scp)) {
    throw 'Windows OpenSSH client is unavailable.'
}
$remote = 'sftp_for_gkstocks_feature@165.227.156.99'
$root = '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html'
$private = "$root/.stocks-preview-private"
$destination = "$private/original-$($context.nonce)"
$options = @('-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=30')
$prepare = "umask 077 && test `"`$(id -u)`" = 1013 && test `"`$(realpath '$root')`" = '$root' && test ! -L '$private' && test `"`$(stat -c '%u:%a' '$private')`" = '1013:700' && mkdir -m 700 '$destination'"
& $ssh @options $remote $prepare
if ($LASTEXITCODE -ne 0) { throw 'Preview private upload preparation failed; nothing was imported.' }
Push-Location -LiteralPath $bundle
try {
    # Relative sources avoid Windows-drive parsing differences in SCP.
    & $scp @options -O @required 'transfer-files.sha256' "${remote}:$destination/"
    if ($LASTEXITCODE -ne 0) { throw 'Preview upload failed; nothing was imported.' }
} finally {
    Pop-Location
}
$digest = $receipt.sha256
$command = "php '$destination/preview-snapshot.php'"
$arguments = "'$root' '$destination'"
$run = "umask 077 && cd '$destination' && chmod 600 -- * && test `"`$(sha256sum transfer-files.sha256 | cut -d ' ' -f 1)`" = '$ExpectedManifestSha256' && sha256sum -c transfer-files.sha256 && $command adopt $arguments && $command inspect $arguments '$destination/original.snapshot' '$digest' && $command import $arguments '$destination/original.snapshot' '$digest' && $command finish $arguments"
& $ssh @options $remote $run
if ($LASTEXITCODE -ne 0) {
    Write-Output "If import began, maintenance remains active. Recovery in the same authenticated SSH session: $command restore $arguments && $command finish $arguments"
    throw 'Preview import/release did not complete. Preserve the private transfer directory for recovery.'
}
Write-Output 'Preview original-data import and verified release completed. Sign in with the original user after Basic Auth.'
