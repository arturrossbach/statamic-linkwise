import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import BardBadge from '@/components/shared/BardBadge.vue';

/**
 * Smoke + characterisation pin for the BardBadge dev-mode marker.
 *
 * Sprint 5 PR 1 — Vitest-Vorbau. This is the FIRST Vitest test in the
 * repo. Its primary purpose is to prove the test-stack is operational:
 * - alias-based `@statamic/cms/ui` stub resolves
 * - `$page.props.linkwise.*` globals can be injected via global mocks
 * - jsdom environment lets `mount()` actually render
 *
 * Secondary: pin the visibility contract of BardBadge. The component
 * is dev-mode-only and gated on `linkwise.dev_mode` + `bard_entry_ids`
 * inclusion — any regression here surfaces dev-mode noise to
 * production users.
 */
describe('BardBadge', () => {
    const mountWith = (linkwiseProps, entryId = 'entry-a') =>
        mount(BardBadge, {
            props: { entryId },
            global: {
                mocks: { $page: { props: { linkwise: linkwiseProps } } },
            },
        });

    it('renders nothing when dev_mode is off', () => {
        const wrapper = mountWith({ dev_mode: false, bard_entry_ids: ['entry-a'] });
        expect(wrapper.find('[data-stub="Badge"]').exists()).toBe(false);
    });

    it('renders nothing when bard_entry_ids is missing', () => {
        const wrapper = mountWith({ dev_mode: true });
        expect(wrapper.find('[data-stub="Badge"]').exists()).toBe(false);
    });

    it('renders nothing when entry-id is not in bard_entry_ids', () => {
        const wrapper = mountWith({ dev_mode: true, bard_entry_ids: ['entry-b', 'entry-c'] });
        expect(wrapper.find('[data-stub="Badge"]').exists()).toBe(false);
    });

    it('renders the badge when dev_mode is on AND entry-id is listed', () => {
        const wrapper = mountWith({ dev_mode: true, bard_entry_ids: ['entry-a'] });
        const badge = wrapper.find('[data-stub="Badge"]');
        expect(badge.exists()).toBe(true);
    });

    it('handles missing $page.props.linkwise gracefully (no throw)', () => {
        const wrapper = mount(BardBadge, {
            props: { entryId: 'entry-a' },
            global: {
                mocks: { $page: { props: {} } },
            },
        });
        expect(wrapper.find('[data-stub="Badge"]').exists()).toBe(false);
    });
});
