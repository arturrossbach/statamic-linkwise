import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

/**
 * Vitest setup for Linkwise Vue-component characterisation tests.
 *
 * Sprint 5 PR 1 (REV-DR-01 follow-up):
 * - Test-env `jsdom` (broader DOM surface than happy-dom; required for
 *   Statamic-UI components that touch ResizeObserver / MutationObserver).
 * - Aliases stub `@statamic/cms/ui` and `@statamic/cms/inertia` so tests
 *   don't need to boot the real CP container. Stubs are plain Vue shells
 *   that pass props + slots through — enough to verify our own
 *   component logic without dragging vendor surface into unit tests.
 * - `tests/Vue/setup.js` registers globals + any cross-cutting mocks.
 *
 * Production build (`vite.config.js`) is entry-points-based (only
 * `resources/js/addon.js`), so test code never leaks into the bundle.
 */
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: false,
        include: ['tests/Vue/**/*.test.js'],
        setupFiles: ['tests/Vue/setup.js'],
        reporters: ['default'],
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@statamic/cms/ui': path.resolve(__dirname, 'tests/Vue/__stubs__/statamic-cms-ui.js'),
            '@statamic/cms/inertia': path.resolve(__dirname, 'tests/Vue/__stubs__/statamic-cms-inertia.js'),
        },
    },
});
