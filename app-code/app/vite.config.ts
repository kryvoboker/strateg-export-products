import { defineConfig } from 'vite';
import laravel          from 'laravel-vite-plugin';
import tailwindcss      from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const isFilamentThemeOnlyMode = mode === 'filament-theme';

    return {
        plugins: [
            laravel({
                input:           isFilamentThemeOnlyMode
                                     ? ['resources/assets/filament/opa-chirik/theme.css']
                                     : [
                        'resources/css/app.css',
                        'resources/js/app.js',
                        'resources/assets/filament/opa-chirik/theme.css',
                    ],
                refresh:         true,
                publicDirectory: '../httpdocs',
                buildDirectory:  'build',
            }),
            tailwindcss(),
        ],
    };
});
