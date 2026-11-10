import { paraglideVitePlugin } from '@inlang/paraglide-js';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        paraglideVitePlugin({
            project: './project.inlang',
            outdir: './resources/js/paraglide',
            // Saved choice, then the browser's languages (custom-navigator maps zh-TW/HK to zh-Hant).
            strategy: ['localStorage', 'custom-navigator', 'baseLocale'],
            emitTsDeclarations: true,
        }),
        inertia(),
        tailwindcss(),
        svelte(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
