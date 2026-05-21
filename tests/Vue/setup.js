import { beforeEach } from 'vitest';
import { config } from '@vue/test-utils';
import { __resetRouterCalls } from './__stubs__/statamic-cms-inertia.js';

/**
 * Global setup for Vitest. Runs before each test.
 *
 * - Resets the router-call log so each test starts clean.
 * - Registers `v-tooltip` as a no-op directive globally so components
 *   that use it don't emit "Failed to resolve directive" warnings.
 *   Statamic's real `v-tooltip` is a tooltip plugin we don't need in
 *   unit tests — we just need it to mount silently.
 */
config.global.directives = {
    ...(config.global.directives || {}),
    tooltip: {
        mounted() {},
        updated() {},
        unmounted() {},
    },
};

/**
 * Statamic-CP global mocks — these helpers are added by the Statamic
 * Inertia adapter at runtime in production. Tests don't boot the
 * adapter, so we provide passthrough/stub implementations.
 *
 *   - `cp_url(path)` → builds a CP-relative URL. Tests don't care about
 *     the actual prefix, so we return the path verbatim.
 */
config.global.mocks = {
    ...(config.global.mocks || {}),
    cp_url: (path) => `/cp/${path}`,
};

beforeEach(() => {
    __resetRouterCalls();
});
