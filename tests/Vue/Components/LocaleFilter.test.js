import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import LocaleFilter from '@/components/LocaleFilter.vue';

/**
 * V1.2 Cross-Tab-A — emit-contract pin for the shared LocaleFilter.
 *
 * The component is intentionally dumb: takes `available` + `current` as
 * props, emits `update`. Parent owns navigation. This file pins the
 * emit contract — every tab that uses LocaleFilter relies on it.
 *
 * Component uses a native <select> (User-Smoke 2026-05-25 — the earlier
 * Dropdown-based trigger looked like static text). Tests reflect that.
 */

describe('LocaleFilter — emit contract', () => {
    it('does not render when available is empty', () => {
        const wrapper = mount(LocaleFilter, { props: { available: [], current: null } });
        // The whole widget is wrapped in v-if; rendered output is empty.
        expect(wrapper.text()).toBe('');
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it('renders one option per available locale plus an "All languages" entry', () => {
        const wrapper = mount(LocaleFilter, { props: { available: ['de', 'en', 'nl'], current: null } });
        const options = wrapper.findAll('option');
        // "All languages" + 3 locale entries
        expect(options).toHaveLength(4);
        expect(options[0].text()).toBe('All languages');
        expect(options[0].attributes('value')).toBe('');
        expect(options[1].text()).toBe('de');
    });

    it('emits update with the selected locale when an option is chosen', async () => {
        const wrapper = mount(LocaleFilter, { props: { available: ['de', 'en', 'nl'], current: null } });
        await wrapper.find('select').setValue('de');
        const events = wrapper.emitted('update');
        expect(events).toHaveLength(1);
        expect(events[0][0]).toBe('de');
    });

    it('emits update with null when "All languages" is chosen', async () => {
        const wrapper = mount(LocaleFilter, { props: { available: ['de', 'en'], current: 'de' } });
        await wrapper.find('select').setValue('');
        const events = wrapper.emitted('update');
        expect(events).toHaveLength(1);
        expect(events[0][0]).toBeNull();
    });

    it('preselects the current value', () => {
        const wrapper = mount(LocaleFilter, { props: { available: ['de', 'en'], current: 'de' } });
        // Verify the select's value matches current. Vue normalises
        // `:value="current ?? ''"` so the bound element should be "de".
        expect(wrapper.find('select').element.value).toBe('de');
    });
});
