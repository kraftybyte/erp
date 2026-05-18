import { defineConfig } from 'vite'
import laravel, { refreshPaths } from 'laravel-vite-plugin'

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // WAJIB PERTAMA
                'resources/css/app.css',

                // Filament theme
                'resources/css/filament/admin/theme.css',

                // JS
                'resources/js/app.js',            ],
            refresh: [
                ...refreshPaths,
                'app/**',
            ],
        }),
    ],
})
