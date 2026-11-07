import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny, local } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                'resources/js/miniapp/main.tsx',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // The Farsi face (OFL-licensed files live in resources/fonts,
                // fetched from Bunny at build time is not an option on this
                // network). Not preloaded: an English session never downloads
                // it — the font-face only resolves when Persian glyphs render.
                local('Vazirmatn', {
                    variants: [
                        { src: 'resources/fonts/Vazirmatn-Regular.woff2', weight: 400 },
                        { src: 'resources/fonts/Vazirmatn-Medium.woff2', weight: 500 },
                        { src: 'resources/fonts/Vazirmatn-SemiBold.woff2', weight: 600 },
                        { src: 'resources/fonts/Vazirmatn-Bold.woff2', weight: 700 },
                    ],
                    preload: false,
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
});
