import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import vuetify from 'vite-plugin-vuetify';

export default defineConfig({
    cacheDir: process.env.VITEST ? 'node_modules/.vitest' : 'node_modules/.vite',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/homepage.js'],
            refresh: true,
        }),
        vue(),
        vuetify({ autoImport: true }),
        tailwindcss(),
    ],
    server: {
        host: 'localhost',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        cors: {
            origin: [
                /^http:\/\/localhost(:\d+)?$/,
                /^http:\/\/127\.0\.0\.1(:\d+)?$/,
                /^http:\/\/\[::1\](:\d+)?$/,
            ],
        },
        hmr: {
            host: 'localhost',
            port: 5173,
            protocol: 'ws',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
            usePolling: true,
            interval: 100,
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['tests/js/setup.js'],
        server: {
            deps: {
                inline: ['vuetify'],
            },
        },
    },
});
