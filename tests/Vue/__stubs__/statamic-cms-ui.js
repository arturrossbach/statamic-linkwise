import { defineComponent, h } from 'vue';

/**
 * Test-environment stubs for `@statamic/cms/ui`.
 *
 * Real Statamic-UI components (Modal/Stack/Tabs/Button/...) require the
 * Statamic CP runtime to mount. In Vitest unit-context we don't boot
 * that runtime — we test OUR component logic against these inert
 * wrappers that pass props + slots through.
 *
 * If a test ever needs to assert on Statamic-UI rendering details
 * (e.g. "Modal received `:open` prop"), the stub already records props
 * via `data-stub` + a queryable name. For full integration coverage,
 * Playwright tests run against the real CP.
 */

const passthrough = (name) => defineComponent({
    name,
    inheritAttrs: true,
    setup(_, { slots, attrs }) {
        return () => h('div', { 'data-stub': name, ...attrs }, slots.default?.());
    },
});

const namedSlot = (name, slotName = 'default') => defineComponent({
    name,
    inheritAttrs: true,
    setup(_, { slots, attrs }) {
        return () => h('div', { 'data-stub': name, ...attrs }, slots[slotName]?.());
    },
});

export const Tabs = passthrough('Tabs');
export const TabList = passthrough('TabList');
export const TabTrigger = passthrough('TabTrigger');
export const TabContent = passthrough('TabContent');

export const Dropdown = passthrough('Dropdown');
export const DropdownItem = passthrough('DropdownItem');
export const DropdownMenu = passthrough('DropdownMenu');
export const DropdownSeparator = passthrough('DropdownSeparator');

export const Modal = passthrough('Modal');
export const ModalClose = passthrough('ModalClose');
export const ModalTitle = passthrough('ModalTitle');

export const Stack = passthrough('Stack');
export const StackHeader = passthrough('StackHeader');
export const StackContent = passthrough('StackContent');
export const StackFooter = passthrough('StackFooter');

export const Header = passthrough('Header');
export const Card = passthrough('Card');
export const Panel = passthrough('Panel');
export const Button = passthrough('Button');
export const Badge = passthrough('Badge');
export const Popover = passthrough('Popover');
export const ConfirmationModal = passthrough('ConfirmationModal');
export const Alert = passthrough('Alert');
export const Icon = passthrough('Icon');

// `v-tooltip` directive — no-op in tests
export const vTooltip = {
    mounted() {},
    updated() {},
    unmounted() {},
};
