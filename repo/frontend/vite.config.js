import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'src'),
        },
    },
    build: {
        outDir: path.resolve(__dirname, '..', 'backend', 'public', 'build'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                app: path.resolve(__dirname, 'src', 'app.js'),
                css: path.resolve(__dirname, 'src', 'css', 'app.css'),
            },
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['tests/unit_tests/**/*.spec.js'],
        exclude: ['tests/e2e/**'],
    },
});
