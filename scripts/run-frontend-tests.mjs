import { spawn } from 'node:child_process';

import { restoreViteHotIfDevServerRunning } from './restore-vite-hot-if-dev-running.mjs';

const vitestArguments = process.argv.slice(2);

const exitCode = await new Promise(resolve => {
    const childProcess = spawn('vitest', vitestArguments, {
        shell: process.platform === 'win32',
        stdio: 'inherit',
    });

    childProcess.on('error', () => resolve(1));
    childProcess.on('close', code => resolve(code ?? 1));
});

await restoreViteHotIfDevServerRunning();

process.exit(exitCode);
