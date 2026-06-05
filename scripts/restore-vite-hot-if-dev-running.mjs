import { mkdirSync, writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export async function restoreViteHotIfDevServerRunning() {
    const devServerUrls = process.env.VITE_DEV_SERVER_URL
        ? [process.env.VITE_DEV_SERVER_URL]
        : ['http://localhost:5173', 'http://127.0.0.1:5173'];

    for (const devServerUrl of devServerUrls) {
        try {
            const response = await fetch(`${devServerUrl}/resources/js/homepage.js`, {
                signal: AbortSignal.timeout(1500),
            });

            if (!response.ok) {
                continue;
            }

            mkdirSync('public', { recursive: true });
            writeFileSync('public/hot', devServerUrl);
            console.log(`Restored public/hot for running Vite dev server: ${devServerUrl}`);

            return true;
        } catch {
            // This candidate is not running. Try the next common local dev URL.
        }
    }

    return false;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    await restoreViteHotIfDevServerRunning();
}
