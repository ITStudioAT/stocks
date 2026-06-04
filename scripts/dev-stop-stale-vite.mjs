import { execFileSync } from 'node:child_process';

if (process.platform !== 'win32') {
    process.exit(0);
}

const workspace = process.cwd().replaceAll("'", "''");
const currentProcessId = process.pid;
const command = `
$workspace = '${workspace}'
$currentProcessId = ${currentProcessId}
$viteCommandPattern = 'vite[\\\\/]bin[\\\\/]vite\\.js|node_modules[\\\\/]\\.bin[\\\\/]vite'
Get-CimInstance Win32_Process |
    Where-Object {
        $_.Name -eq 'node.exe' -and
        $_.ProcessId -ne $currentProcessId -and
        $_.CommandLine -match [regex]::Escape($workspace) -and
        $_.CommandLine -match $viteCommandPattern
    } |
    ForEach-Object {
        Stop-Process -Id $_.ProcessId -Force
        Write-Output "Stopped stale Vite process $($_.ProcessId)"
    }
`;

try {
    execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', command], {
        stdio: 'inherit',
    });
} catch (error) {
    console.warn('Could not check for stale Vite processes before starting dev server.');
}
