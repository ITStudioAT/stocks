import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';

import { restoreViteHotIfDevServerRunning } from './restore-vite-hot-if-dev-running.mjs';

const require = createRequire(import.meta.url);
const vitestExecutablePath = resolve(dirname(require.resolve('vitest/package.json')), 'vitest.mjs');
const vitestArguments = process.argv.slice(2);

const exitCode = await new Promise(resolve => {
    const childProcess = spawn(process.execPath, [vitestExecutablePath, ...vitestArguments], {
        env: {
            ...process.env,
            LARAVEL_BYPASS_ENV_CHECK: '1',
            VITEST: 'true',
        },
        stdio: 'inherit',
    });

    childProcess.on('error', () => resolve(1));
    childProcess.on('close', code => resolve(code ?? 1));
});

await restoreViteHotIfDevServerRunning();

process.exit(exitCode);
