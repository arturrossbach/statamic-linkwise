import { describe, it, expect, beforeEach, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import LinksReportTab from '@/components/dashboard/LinksReportTab.vue';
import { bulkState } from '@/services/bulkOperationService.js';

/**
 * Regression pin for the user-reported 2026-05-16 bug:
 *
 *   "in der haupttabelle sehe ich immernoch den alten suggestion count
 *    beim fall des inbound-insert (kein seitenreload gemacht). ich hab
 *    das modal nochmal neu aufgemacht (also auf den falschen count
 *    geklickt) und dann nochmal geschlossen. erst DANN hat sich der
 *    count auf 0 aktualisiert"
 *
 * Root cause: `closeSuggestModal` calls `refreshSuggestionCountForEntry`
 * BEFORE the async bulk-insert completes. Fix: watch
 * `bulkState.lastCompletion` and call `loadSuggestionCounts()` when a
 * content-mutating bulk reaches 'done' phase.
 *
 * Without this pin, a future refactor (e.g. the deferred PR 4
 * LinksReportTab split) could silently drop the watcher and the bug
 * would resurface. Advisor pre-merge-flag: "Schreib den Pin (10 min)
 * vor Merge — das gibt dir auch Munition für deine Antwort".
 */
describe('LinksReportTab — bulkState.lastCompletion watcher', () => {
    const mountTab = (overrides = {}) => shallowMount(LinksReportTab, {
        props: {
            entries: [],
            collections: [],
            suggestionCountsUrl: '/cp/linkwise/suggestion-counts',
            inboundSuggestionsBaseUrl: '/cp/linkwise/inbound/__ID__',
            outboundSuggestionsBaseUrl: '/cp/linkwise/outbound/__ID__',
            ...overrides,
        },
        global: {
            mocks: { $page: { props: { linkwise: {} } } },
        },
    });

    beforeEach(() => {
        // Reset shared reactive store so each test starts clean.
        bulkState.active = null;
        bulkState.lastCompletion = null;
    });

    const completionKinds = [
        'inboundinsert',
        'outboundinsert',
        'bulkunlink',
        'detailunlink',
        'urlchanger',
        'applyrule',
        // DetailModal sync re-link emits a synthetic completion event via
        // recordCompletion() so the same watcher fires (added 2026-05-16
        // after user-reported stale anchor-text on modal re-open).
        'detailrelink',
    ];

    for (const kind of completionKinds) {
        it(`triggers loadSuggestionCounts when ${kind} bulk reaches 'done'`, async () => {
            const wrapper = mountTab();
            const spy = vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

            // Mutate the shared reactive store — the watcher should fire.
            bulkState.lastCompletion = { kind, phase: 'done' };
            await nextTick();

            expect(spy).toHaveBeenCalledOnce();
        });
    }

    it('does NOT trigger loadSuggestionCounts for non-content-mutating kinds', async () => {
        // scan + check are GLOBAL bulks but don't directly modify suggestion
        // counts in the same way (they trigger a full index rebuild which
        // refreshes everything via the existing scan-done window-reload).
        const wrapper = mountTab();
        const spy = vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

        bulkState.lastCompletion = { kind: 'scan', phase: 'done' };
        await nextTick();

        expect(spy).not.toHaveBeenCalled();
    });

    it("does NOT trigger when phase is not 'done'", async () => {
        // Cancelled / error / starting / running — only the terminal-success
        // window is the safe point to refetch counts.
        const wrapper = mountTab();
        const spy = vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

        bulkState.lastCompletion = { kind: 'inboundinsert', phase: 'cancelled' };
        await nextTick();
        bulkState.lastCompletion = { kind: 'inboundinsert', phase: 'error' };
        await nextTick();

        expect(spy).not.toHaveBeenCalled();
    });

    it('does not crash on null completion', async () => {
        // Defensive: bulkState.lastCompletion starts null and can be reset
        // to null by dismissCompletion. The watcher must early-return.
        const wrapper = mountTab();
        const spy = vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

        bulkState.lastCompletion = null;
        await nextTick();

        expect(spy).not.toHaveBeenCalled();
    });

    it('skips when suggestionCountsUrl is not configured', async () => {
        // Defensive: if the route prop is missing (broken page config),
        // we must not fire a fetch to undefined.
        const wrapper = mountTab({ suggestionCountsUrl: '' });
        const spy = vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

        bulkState.lastCompletion = { kind: 'inboundinsert', phase: 'done' };
        await nextTick();

        expect(spy).not.toHaveBeenCalled();
    });
});
