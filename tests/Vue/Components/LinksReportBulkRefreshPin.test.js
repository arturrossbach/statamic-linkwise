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
 * would resurface. pre-merge review: "Schreib den Pin (10 min)
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

    // ─── reloadEntries truth-table (C-1, user-report 2026-05-16) ────────
    //
    // `localEntries[].content_hash` stays stale unless the watcher triggers
    // an Inertia partial reload of the `entries` prop after destructive
    // bulks complete. Stale hashes → next bulk-unlink sends OLD hashes →
    // SafeEntrySaver::verifyHashes rejects per-record with 'modified' →
    // grey toast "entry was modified by another editor" (real bug 2026-05-16,
    // documented in docs/ARCHITECTURE_REVIEW.md Klasse C C-1).
    //
    // Truth-table:
    //   - `inboundinsert`/`outboundinsert` → layout's pickTerminalReload
    //     returns 'partial' (LinkwiseLayout:818) — no need to reload here.
    //   - `bulkunlink`/`detailunlink`/`urlchanger`/`applyrule` →
    //     pickTerminalReload returns 'none' (bulkTerminalReload.js:88) —
    //     this watcher MUST reload, otherwise the bug surfaces.
    //   - `detailrelink` → existing pre-C-1 behaviour, preserved.

    const reloadKinds = ['bulkunlink', 'detailunlink', 'urlchanger', 'applyrule', 'detailrelink'];
    for (const kind of reloadKinds) {
        it(`triggers reloadEntries when ${kind} bulk reaches 'done' (C-1 fix)`, async () => {
            const wrapper = mountTab();
            const spy = vi.spyOn(wrapper.vm, 'reloadEntries').mockImplementation(() => {});
            // Suppress the parallel loadSuggestionCounts so the assertion
            // focuses on reloadEntries — the loadSuggestionCounts coverage
            // is the truth-table above.
            vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

            bulkState.lastCompletion = { kind, phase: 'done' };
            await nextTick();

            expect(spy).toHaveBeenCalledOnce();
        });
    }

    const noReloadKinds = ['inboundinsert', 'outboundinsert'];
    for (const kind of noReloadKinds) {
        it(`does NOT trigger reloadEntries for ${kind} (handled by layout's pickTerminalReload partial reload)`, async () => {
            const wrapper = mountTab();
            const spy = vi.spyOn(wrapper.vm, 'reloadEntries').mockImplementation(() => {});
            vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

            bulkState.lastCompletion = { kind, phase: 'done' };
            await nextTick();

            expect(spy).not.toHaveBeenCalled();
        });
    }

    it('does NOT trigger reloadEntries for unrelated kinds (scan)', async () => {
        const wrapper = mountTab();
        const spy = vi.spyOn(wrapper.vm, 'reloadEntries').mockImplementation(() => {});

        bulkState.lastCompletion = { kind: 'scan', phase: 'done' };
        await nextTick();

        expect(spy).not.toHaveBeenCalled();
    });

    it("does NOT trigger reloadEntries when phase is 'cancelled' or 'error'", async () => {
        const wrapper = mountTab();
        const spy = vi.spyOn(wrapper.vm, 'reloadEntries').mockImplementation(() => {});
        vi.spyOn(wrapper.vm, 'loadSuggestionCounts').mockResolvedValue();

        bulkState.lastCompletion = { kind: 'detailunlink', phase: 'cancelled' };
        await nextTick();
        bulkState.lastCompletion = { kind: 'bulkunlink', phase: 'error' };
        await nextTick();

        expect(spy).not.toHaveBeenCalled();
    });

    // ─── C-1 race-closure: showDetail async hash-refresh ────────────────
    //
    // The C-1 fix (PR #49) reload-after-completion closed the 100% repro
    // case but left a ~100-800ms race window: layout-poller clears
    // bulkState.active=null BEFORE recordCompletion() fires the
    // reloadEntries() partial reload. If the user opens a new DetailModal
    // via showDetail in that gap, the synchronous read of
    // localEntries[].content_hash returns the OLD hash and the next bulk
    // STILL ships stale state.
    //
    // The follow-up fix turns showDetail into async: fetch fresh hashes
    // from entryHashesUrl for all entries involved BEFORE populating
    // detailModal, merge into localEntries in place. By the time the
    // modal is interactive, hashes are guaranteed current. Race closed.
    //
    // Three contracts pinned:
    //   1. showDetail fetches the right ids from entryHashesUrl.
    //   2. fresh hashes get merged into localEntries[id].content_hash
    //      before detailModal is set (no leak of stale state into items).
    //   3. Token-pattern: rapid back-to-back showDetail calls only let
    //      the latest one set detailModal — older fetches that resolve
    //      after a newer call started are no-ops (prevents the
    //      "second click's modal got overwritten by first click's
    //      late-arrival" UX bug).

    const sampleEntries = [
        {
            id: 'e1',
            title: 'Source A',
            collection: 'pages',
            content_hash: 'OLD-hash-a',
            inbound_count: 2,
            outbound_count: 0,
            internal_links_detail: [
                { entry_id: 'target-1', href: 'statamic://entry::target-1', anchor_text: 'click', sentence_context: 'x' },
            ],
        },
        {
            id: 'target-1',
            title: 'Target',
            collection: 'pages',
            content_hash: 'OLD-hash-target',
            inbound_count: 1,
            outbound_count: 0,
            internal_links_detail: [],
        },
    ];

    it('showDetail fetches fresh hashes from entryHashesUrl for all involved entries', async () => {
        const wrapper = mountTab({
            entries: sampleEntries,
            entryHashesUrl: '/cp/linkwise/entry-hashes',
        });

        const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({
            ok: true,
            json: async () => ({ hashes: { 'e1': 'NEW-hash-a', 'target-1': 'NEW-hash-target' } }),
        });

        await wrapper.vm.showDetail('inbound', sampleEntries[1]);

        // Inbound flow: source entries (e1 here) + the target entry
        // (target-1) — DetailModal's unlink-flow can touch BOTH so both
        // need fresh hashes.
        expect(fetchSpy).toHaveBeenCalledOnce();
        const url = fetchSpy.mock.calls[0][0];
        expect(url).toContain('/cp/linkwise/entry-hashes');
        expect(url).toContain('ids');
        // The fetched ids must include the source AND the target.
        expect(url).toMatch(/e1/);
        expect(url).toMatch(/target-1/);

        fetchSpy.mockRestore();
    });

    it('merges fresh hashes into localEntries BEFORE detailModal becomes interactive', async () => {
        const wrapper = mountTab({
            entries: sampleEntries,
            entryHashesUrl: '/cp/linkwise/entry-hashes',
        });

        let resolveFetch;
        const fetchPromise = new Promise((resolve) => { resolveFetch = resolve; });
        vi.spyOn(global, 'fetch').mockReturnValue(fetchPromise);

        const showDetailPromise = wrapper.vm.showDetail('inbound', sampleEntries[1]);

        // Modal must NOT be set yet — fetch is in flight.
        expect(wrapper.vm.detailModal).toBeNull();

        resolveFetch({
            ok: true,
            json: async () => ({ hashes: { 'e1': 'NEW-hash-a', 'target-1': 'NEW-hash-target' } }),
        });
        await showDetailPromise;
        await nextTick();

        // Modal NOW set, AND localEntries hash mutated to fresh value.
        expect(wrapper.vm.detailModal).not.toBeNull();
        const sourceLocal = wrapper.vm.localEntries.find(e => e.id === 'e1');
        const targetLocal = wrapper.vm.localEntries.find(e => e.id === 'target-1');
        expect(sourceLocal.content_hash).toBe('NEW-hash-a');
        expect(targetLocal.content_hash).toBe('NEW-hash-target');
    });

    it('token-pattern: rapid back-to-back showDetail only lets the latest set detailModal', async () => {
        const wrapper = mountTab({
            entries: [
                ...sampleEntries,
                { id: 'e2', title: 'Source B', collection: 'pages', content_hash: 'OLD-b', inbound_count: 0, outbound_count: 0, internal_links_detail: [] },
                { id: 'target-2', title: 'Target B', collection: 'pages', content_hash: 'OLD-target-b', inbound_count: 1, outbound_count: 0, internal_links_detail: [] },
            ],
            entryHashesUrl: '/cp/linkwise/entry-hashes',
        });

        // Two fetches in flight, second resolves first (typical race shape).
        let resolveFirst;
        let resolveSecond;
        const firstFetch = new Promise((r) => { resolveFirst = r; });
        const secondFetch = new Promise((r) => { resolveSecond = r; });
        const fetchSpy = vi.spyOn(global, 'fetch')
            .mockReturnValueOnce(firstFetch)
            .mockReturnValueOnce(secondFetch);

        // Click 1 (target-1) starts first fetch.
        const p1 = wrapper.vm.showDetail('inbound', sampleEntries[1]);
        // Click 2 (target-2) starts second fetch BEFORE first resolves.
        const target2 = wrapper.vm.localEntries.find(e => e.id === 'target-2');
        const p2 = wrapper.vm.showDetail('inbound', target2);

        // Second fetch resolves first — modal should be set to the
        // target-2 modal.
        resolveSecond({
            ok: true,
            json: async () => ({ hashes: { 'target-2': 'NEW-target-b' } }),
        });
        await p2;
        await nextTick();
        expect(wrapper.vm.detailModal.entryId).toBe('target-2');

        // Now first fetch resolves LATE — must NOT overwrite the modal
        // (older request, user has already moved on).
        resolveFirst({
            ok: true,
            json: async () => ({ hashes: { 'e1': 'NEW-a', 'target-1': 'NEW-target-a' } }),
        });
        await p1;
        await nextTick();
        // Modal still target-2, not target-1.
        expect(wrapper.vm.detailModal.entryId).toBe('target-2');

        fetchSpy.mockRestore();
    });

    it('falls back gracefully when entryHashesUrl is empty (legacy / config-missing)', async () => {
        // Defensive: if the prop is not configured (broken page setup
        // or test env), showDetail must still open the modal — just
        // without the race-closure protection.
        const wrapper = mountTab({
            entries: sampleEntries,
            entryHashesUrl: '',
        });

        const fetchSpy = vi.spyOn(global, 'fetch');
        await wrapper.vm.showDetail('inbound', sampleEntries[1]);

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(wrapper.vm.detailModal).not.toBeNull();
        fetchSpy.mockRestore();
    });

    it('falls back gracefully when fetch fails (network error / 5xx)', async () => {
        const wrapper = mountTab({
            entries: sampleEntries,
            entryHashesUrl: '/cp/linkwise/entry-hashes',
        });

        vi.spyOn(global, 'fetch').mockRejectedValue(new Error('network error'));

        await wrapper.vm.showDetail('inbound', sampleEntries[1]);

        // Modal still opens — race-closure failed gracefully, no UX
        // hard-block. Hashes stay at their cached values (next bulk may
        // still surface 'modified' but that's the pre-fix baseline, not
        // worse).
        expect(wrapper.vm.detailModal).not.toBeNull();
    });
});
