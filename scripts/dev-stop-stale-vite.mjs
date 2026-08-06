import { execFileSync } from 'node:child_process';

const isStrict = process.argv.includes('--strict');

if (process.platform !== 'win32') {
    process.exit(0);
}

const workspace = process.cwd().replaceAll("'", "''");
const currentProcessId = process.pid;
const port = Number(process.env.VITE_DEV_SERVER_PORT ?? 5173);
const command = `
$workspace = '${workspace}'.ToLowerInvariant()
$currentProcessId = ${currentProcessId}
$port = ${port}
$targets = @{}

function Add-TargetProcess {
    param([int] $processId)

    if (-not $processId -or $processId -eq 0 -or $processId -eq $currentProcessId) {
        return
    }

    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$processId" -ErrorAction SilentlyContinue

    if (-not $process) {
        return
    }

    $commandLine = ([string] $process.CommandLine).ToLowerInvariant()
    $processName = ([string] $process.Name).ToLowerInvariant()
    $isViteEntryPoint =
        $commandLine.Contains('\\node_modules\\vite\\bin\\vite.js') -or
        $commandLine.Contains('/node_modules/vite/bin/vite.js')

    if ($processName -eq 'node.exe' -and $commandLine.Contains($workspace) -and $isViteEntryPoint) {
        $targets[$process.ProcessId] = $process
    }
}

Get-CimInstance Win32_Process |
    Where-Object {
        $_.Name -eq 'node.exe' -and
        $_.ProcessId -ne $currentProcessId -and
        (([string] $_.CommandLine).ToLowerInvariant()).Contains($workspace)
    } |
    ForEach-Object { Add-TargetProcess -processId $_.ProcessId }

Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
    ForEach-Object { Add-TargetProcess -processId $_.OwningProcess }

$targets.Values |
    ForEach-Object {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
        Write-Output "Stopped stale Vite process $($_.ProcessId)"
    }

if ($targets.Count -gt 0) {
    $deadline = (Get-Date).AddSeconds(5)

    while ((Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.OwningProcess -ne 0 }) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 200
    }

    if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.OwningProcess -ne 0 }) {
        throw "Port $port is still in use after stopping stale Vite processes."
    }
}
`;

try {
    execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', command], {
        stdio: 'inherit',
    });
} catch (error) {
    console.warn('Could not check for stale Vite processes before starting dev server.');

    if (isStrict) {
        process.exit(1);
    }
}
