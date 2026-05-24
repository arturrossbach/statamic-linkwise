import { describe, it, expect, vi } from 'vitest';
import { mount, config as vtuConfig } from '@vue/test-utils';
import LocaleFilter from '@/components/LocaleFilter.vue';

/**
 * V1.2 Cross-Tab-A — emit-contract pin for the shared LocaleFilter.
 *
 * The component is intentionally dumb: takes `available` + `current` as
 * props, emits `update`. Parent owns navigation. This file pins the
 * emit contract — every tab that uses LocaleFilter relies on it.
 *
 * Statamic UI dropdown internals (`<Dropdown>`, `<DropdownItem>`) are
 * stubbed out — we're testing emit behavior, not Statamic's UI.
 */

// Stub out Statamic UI imports so the test doesn't need the real CP.
const stubs = {
    Dropdown: { template: '<div><slot name="trigger" /><slot /></div>' },
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownItem: {
        props: ['text'],
        emits: ['click'],
        template: '<button :data-text="text" @click="$emit(\'click\')">{{ text }}</button>',
    },
    DropdownSeparator: { template: '<hr />' },
    Button: { template: '<button><slot /></button>' },
};

describe('LocaleFilter — emit contract', () => {
    it('does not render when available is empty', () => {
        const wrapper = mount(LocaleFilter, {
            props: { available: [], current: null },
            global: { stubs },
        });
        // The whole widget is wrapped in v-if; rendered output is an
        // empty comment node. text() should be empty.
        expect(wrapper.text()).toBe('');
    });

    it('renders one option per available locale plus an "All languages" entry', () => {
        const wrapper = mount(LocaleFilter, {
            props: { available: ['de', 'en', 'nl'], current: null },
            global: { stubs },
        });
        const items = wrapper.findAll('button[data-text]');
        // "All languages" + 3 locale entries
        expect(items).toHaveLength(4);
        expect(items[0].attributes('data-text')).toBe('All languages');
        expect(items[1].attributes('data-text')).toBe('de');
    });

    it('emits update with the selected locale when an item is clicked', async () => {
        const wrapper = mount(LocaleFilter, {
            props: { available: ['de', 'en', 'nl'], current: null },
            global: { stubs },
        });
        const items = wrapper.findAll('button[data-text]');
        await items[1].trigger('click'); // "de"
        const events = wrapper.emitted('update');
        expect(events).toHaveLength(1);
        expect(events[0][0]).toBe('de');
    });

    it('emits update with null when "All languages" is clicked', async () => {
        const wrapper = mount(LocaleFilter, {
            props: { available: ['de', 'en'], current: 'de' },
            global: { stubs },
        });
        const items = wrapper.findAll('button[data-text]');
        await items[0].trigger('click'); // "All languages"
        const events = wrapper.emitted('update');
        expect(events).toHaveLength(1);
        expect(events[0][0]).toBeNull();
    });

    it('does not emit update when clicking the already-active option', async () => {
        // Re-selecting the current value would trigger a no-op
        // navigation in the parent — costly + jarring. Component
        // short-circuits.
        const wrapper = mount(LocaleFilter, {
            props: { available: ['de', 'en'], current: 'de' },
            global: { stubs },
        });
        const items = wrapper.findAll('button[data-text]');
        await items[1].trigger('click'); // "de" (active)
        expect(wrapper.emitted('update')).toBeUndefined();
    });
});
