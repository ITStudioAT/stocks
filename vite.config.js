import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import vuetify from 'vite-plugin-vuetify';

export default defineConfig({
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
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rolldownOptions: {
            output: {
                codeSplitting: {
                    groups: [
                        {
                            name: 'vuetify',
                            test: /node_modules[\\/]vuetify/,
                            maxSize: 250000,
                            priority: 30,
                        },
                        {
                            name: 'vue',
                            test: /node_modules[\\/](vue|@vue|pinia)/,
                            priority: 20,
                        },
                        {
                            name: 'vendor',
                            test: /node_modules/,
                            priority: 10,
                        },
                    ],
                },
            },
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
