import { defineComponent, h } from 'vue';

/**
 * Test-environment stubs for `@statamic/cms/inertia`.
 *
 * Real Inertia bindings need a router instance + page-data via a
 * server-rendered first request. In Vitest unit-context we substitute
 * `Link` with a plain `<a>` and `Head` with a no-op; `router.visit`
 * / `router.post` / `router.get` are jest-style spies that record
 * calls — tests can assert call-shape without booting Inertia itself.
 */

export const Link = defineComponent({
    name: 'Link',
    props: ['href', 'method', 'as', 'preserveScroll', 'preserveState'],
    inheritAttrs: true,
    setup(props, { slots, attrs }) {
        return () => h('a', { href: props.href, ...attrs }, slots.default?.());
    },
});

export const Head = defineComponent({
    name: 'Head',
    setup(_, { slots }) {
        return () => slots.default?.();
    },
});

// Track router-method calls so tests can assert on them.
export const __routerCalls = [];

const recordCall = (method) => (...args) => {
    __routerCalls.push({ method, args });
    return Promise.resolve();
};

export const router = {
    visit: recordCall('visit'),
    get: recordCall('get'),
    post: recordCall('post'),
    put: recordCall('put'),
    patch: recordCall('patch'),
    delete: recordCall('delete'),
    reload: recordCall('reload'),
    on: () => () => {},
};

/**
 * Reset call-log between tests. Called from `tests/Vue/setup.js`
 * `beforeEach` so each test starts with a clean slate.
 */
export function __resetRouterCalls() {
    __routerCalls.length = 0;
}
