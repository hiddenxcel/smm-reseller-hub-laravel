import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),

            // Matches the path mapping in tsconfig.json, which until now was
            // the only place Ziggy was pointed at: the browser gets `route()`
            // as a global from Blade's @routes, so nothing had to resolve the
            // import. Node has no such global, and the SSR bundle needs the
            // real module to call.
            'ziggy-js': path.resolve(__dirname, 'vendor/tightenco/ziggy'),
        },
    },

    /*
     * The dev server runs on the Windows host while the app runs in a
     * container, so it has to listen on every interface rather than only on
     * loopback — bound to localhost it would be invisible to the browser
     * loading the page from http://localhost.
     *
     * `usePolling` is the price of the bind mount: Windows filesystem events
     * do not cross into WSL2, so without it a saved file never triggers a
     * reload. 300ms is a compromise — lower spins the CPU on this machine,
     * higher makes saving feel unanswered.
     */
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: true,
            interval: 300,
        },
    },
});
