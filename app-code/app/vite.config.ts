import tailwindcss      from '@tailwindcss/vite';
import laravel          from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

const isProduction: boolean = process.env.NODE_ENV === 'production';

export default defineConfig({
    build:   {
        sourcemap: isProduction,
    },
    plugins: [
        laravel({
            input:   [
                'resources/assets/filament/opa-chirik/css/theme.css'
            ],
            refresh: [
                'resources/views/**',
                'app/Filament/**'
            ],
            publicDirectory: '../httpdocs',
            buildDirectory:  'build',
        }),
        tailwindcss(),
    ],
});
