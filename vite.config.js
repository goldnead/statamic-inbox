import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import statamic from '@statamic/cms/vite-plugin';

/*
 * One config, two jobs, as in statamic-automations.
 *
 * In the Control Panel build the Statamic plugin rewrites `vue` to
 * `window.Vue`, so the bundle never ships a second Vue and provide/inject
 * keeps working across the seam. Under Vitest there is no `window.Vue`, so the
 * SFCs are compiled with the plain Vue plugin and `@statamic/cms/*` reads its
 * components off the `__STATAMIC__` stub that tests/js/setup.js installs.
 *
 * `@vitejs/plugin-vue` is a declared dependency of `@statamic/cms`, so it is
 * always installed and always the version the CP itself compiles with.
 */
const isTest = !!process.env.VITEST;

export default defineConfig({
    plugins: isTest
        ? [vue()]
        : [
            // Externalises `vue` to the Control Panel runtime.
            statamic(),
            tailwindcss(),
            laravel({
                // Must byte-match $vite in ServiceProvider.
                input: ['resources/js/cp.js', 'resources/css/cp.css'],
                publicDirectory: 'dist',
                hotFile: 'dist/hot',
            }),
        ],

    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
    },
});
