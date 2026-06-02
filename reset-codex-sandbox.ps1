# Reset Codex Windows sandbox state so the extension can rebuild it cleanly.
# RUN THIS AS ADMINISTRATOR (right-click > Run with PowerShell as admin,
# or from an elevated PowerShell:  powershell -ExecutionPolicy Bypass -File .\reset-codex-sandbox.ps1)

$ErrorActionPreference = 'Continue'

Write-Host "== Step 0: make sure VS Code / Codex are closed ==" -ForegroundColor Cyan
Get-Process Code, codex, 'codex-command-runner', 'codex-windows-sandbox-setup' -ErrorAction SilentlyContinue |
    ForEach-Object { Write-Host "  stopping $($_.ProcessName) (pid $($_.Id))"; Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 1

Write-Host "== Step 1: remove the stale sandbox local accounts ==" -ForegroundColor Cyan
foreach ($u in 'CodexSandboxOffline','CodexSandboxOnline') {
    if (Get-LocalUser -Name $u -ErrorAction SilentlyContinue) {
        Remove-LocalUser -Name $u; Write-Host "  removed user $u"
    } else { Write-Host "  user $u not present" }
}

Write-Host "== Step 2: remove the CodexSandboxUsers group ==" -ForegroundColor Cyan
if (Get-LocalGroup -Name 'CodexSandboxUsers' -ErrorAction SilentlyContinue) {
    Remove-LocalGroup -Name 'CodexSandboxUsers'; Write-Host "  removed group CodexSandboxUsers"
} else { Write-Host "  group not present" }

Write-Host "== Step 3: remove leftover profile folders ==" -ForegroundColor Cyan
foreach ($p in 'C:\Users\CodexSandboxOffline','C:\Users\CodexSandboxOnline') {
    if (Test-Path $p) { Remove-Item $p -Recurse -Force -ErrorAction SilentlyContinue; Write-Host "  removed $p" }
}

Write-Host "== Step 4: clear stale Codex sandbox state ==" -ForegroundColor Cyan
$home = "C:\Users\kron\.codex"
foreach ($item in '.sandbox','.sandbox-secrets','.sandbox-bin','cap_sid','sandbox.log') {
    $full = Join-Path $home $item
    if (Test-Path $full) { Remove-Item $full -Recurse -Force -ErrorAction SilentlyContinue; Write-Host "  removed $full" }
}

Write-Host ""
Write-Host "DONE. Now:" -ForegroundColor Green
Write-Host "  1. Right-click VS Code -> Run as administrator" -ForegroundColor Green
Write-Host "  2. Open the Codex panel; it will rebuild the sandbox (accept any UAC prompt)" -ForegroundColor Green
Write-Host "  3. After it succeeds once, you can go back to launching VS Code normally" -ForegroundColor Green
