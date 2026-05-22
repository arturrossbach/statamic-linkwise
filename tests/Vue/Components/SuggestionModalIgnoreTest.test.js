import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SuggestionModal from '@/components/dashboard/SuggestionModal.vue';

/**
 * Klasse-10 guarantee-stack pin — Vitest side.
 *
 * The user gave one explicit guarantee on 2026-05-22 launch eve:
 *   "Ich will nicht wieder die gleiche Count-Hölle mit dir durchgehen.
 *    Der count muss sich weiterhin stabil aktualisieren."
 *
 * The fragile contract is: when the user clicks ✕ or ↩ on a
 * suggestion row, the Links Report badge count MUST refresh. The
 * single mechanism that drives that refresh is the `@ignored` event
 * emitted by SuggestionModal, which the LinksReportTab parent binds
 * to `loadSuggestionCounts` (the StatsApiController endpoint —
 * single source of truth for the badge number).
 *
 * If any future refactor accidentally:
 *   - drops the @ignored emit
 *   - silently swallows the POST/DELETE failure path
 *   - emits @ignored on failure (would refresh against stale data)
 *
 * → the user-visible count goes stale and the guarantee is broken.
 * These pins guard each branch.
 */

describe('SuggestionModal ignore/un-ignore @ignored emit (Klasse-10 guarantee)', () => {
    // Toast + fetch globals.
    beforeEach(() => {
        window.Statamic = {
            $toast: { error: vi.fn(), success: vi.fn() },
            $config: { get: (k) => (k === 'csrfToken' ? 'test-token' : '') },
        };
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete window.Statamic;
    });

    const inboundModal = (overrides = {}) => ({
        mode: 'inbound',
        entryId: 'entry-target',
        title: 'inbound',
        suggestions: [
            {
                source_entry_id: 'entry-source',
                source_title: 'Source',
                source_collection: 'posts',
                _anchor: 'foo',
                _originalAnchor: 'foo',
                _status: 'pending',
                score: 0.5,
                match_type: 'title',
                is_ignored: false,
            },
        ],
        totalAvailable: 1,
        ...overrides,
    });

    const mountModal = (modal) =>
        mount(SuggestionModal, {
            props: {
                modal,
                ignoreSuggestionUrl: '/cp/linkwise/ignored-suggestions',
                unignoreSuggestionUrl: '/cp/linkwise/ignored-suggestions',
            },
        });

    it('emits @ignored after a successful POST so parent re-fetches counts', async () => {
        const fetchSpy = vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ success: true, ignored: true }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );

        const wrapper = mountModal(inboundModal());

        // Call the method directly — DOM clicking would require the
        // full Stack-rendered table; the method IS the contract.
        const suggestion = wrapper.props('modal').suggestions[0];
        await wrapper.vm.ignoreItem(suggestion, /* isOutboundTarget */ false);
        await flushPromises();

        expect(fetchSpy).toHaveBeenCalledOnce();
        expect(fetchSpy.mock.calls[0][1].method).toBe('POST');
        expect(wrapper.emitted('ignored')).toBeTruthy();
        expect(wrapper.emitted('ignored').length).toBe(1);
        expect(suggestion.is_ignored).toBe(true);
    });

    it('emits @ignored after a successful DELETE so parent re-fetches counts', async () => {
        const fetchSpy = vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ success: true, ignored: false }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );

        const wrapper = mountModal(inboundModal({
            suggestions: [{
                source_entry_id: 'entry-source',
                source_title: 'Source',
                source_collection: 'posts',
                _anchor: 'foo',
                _originalAnchor: 'foo',
                _status: 'pending',
                score: 0.5,
                match_type: 'title',
                is_ignored: true, // start ignored
            }],
        }));

        const suggestion = wrapper.props('modal').suggestions[0];
        await wrapper.vm.unignoreItem(suggestion, false);
        await flushPromises();

        expect(fetchSpy).toHaveBeenCalledOnce();
        expect(fetchSpy.mock.calls[0][1].method).toBe('DELETE');
        expect(wrapper.emitted('ignored')).toBeTruthy();
        expect(suggestion.is_ignored).toBe(false);
    });

    it('does NOT emit @ignored when POST fails (5xx) and rolls back is_ignored', async () => {
        // Server error: optimistic flag must revert AND no @ignored
        // event fires (otherwise parent refreshes against unchanged
        // state — UI shows "ignored" but DB never recorded).
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ message: 'server boom' }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' },
            }),
        );

        const wrapper = mountModal(inboundModal());
        const suggestion = wrapper.props('modal').suggestions[0];
        await wrapper.vm.ignoreItem(suggestion, false);
        await flushPromises();

        expect(wrapper.emitted('ignored')).toBeFalsy();
        expect(suggestion.is_ignored).toBe(false); // rolled back
        expect(window.Statamic.$toast.error).toHaveBeenCalled();
    });

    it('does NOT emit @ignored when network fails and rolls back is_ignored', async () => {
        vi.spyOn(window, 'fetch').mockRejectedValue(new Error('network down'));

        const wrapper = mountModal(inboundModal());
        const suggestion = wrapper.props('modal').suggestions[0];
        await wrapper.vm.ignoreItem(suggestion, false);
        await flushPromises();

        expect(wrapper.emitted('ignored')).toBeFalsy();
        expect(suggestion.is_ignored).toBe(false);
        expect(window.Statamic.$toast.error).toHaveBeenCalled();
    });

    it('busy-guard prevents double-fire on rapid double-click', async () => {
        let resolveFetch;
        const fetchSpy = vi.spyOn(window, 'fetch').mockImplementation(
            () => new Promise((resolve) => { resolveFetch = resolve; }),
        );

        const wrapper = mountModal(inboundModal());
        const suggestion = wrapper.props('modal').suggestions[0];

        // Two synchronous calls before the first resolves.
        const p1 = wrapper.vm.ignoreItem(suggestion, false);
        const p2 = wrapper.vm.ignoreItem(suggestion, false);

        resolveFetch(new Response('{"success":true}', { status: 200 }));
        await Promise.all([p1, p2]);
        await flushPromises();

        expect(fetchSpy).toHaveBeenCalledOnce(); // second call short-circuited
        expect(wrapper.emitted('ignored').length).toBe(1);
    });

    it('Bug A: ignoredCount counts individual ignored targets, not whole groups (outbound)', () => {
        // 2-target group, 1 target ignored → count must be 1, NOT 0.
        // Pre-fix this returned 0 because the filter required EVERY
        // target to be ignored → the "Show ignored (N)" chip was
        // hidden and the user couldn't navigate to the ignored row.
        const modal = {
            mode: 'outbound',
            entryId: 'src',
            title: 'x',
            groups: [{
                key: 'g1',
                _anchor: 'a',
                _originalAnchor: 'a',
                _status: 'pending',
                _selectedTarget: 't1',
                _expanded: false,
                targets: [
                    { target_entry_id: 't1', target_title: 'T1', target_collection: 'p', match_type: 'title', score: 0.7, is_ignored: true },
                    { target_entry_id: 't2', target_title: 'T2', target_collection: 'p', match_type: 'title', score: 0.5, is_ignored: false },
                ],
                sentence_context: 'sentence',
            }],
        };
        const wrapper = mountModal(modal);
        expect(wrapper.vm.ignoredCount).toBe(1);
    });

    it('Bug B: auto-reveals ignored row after ignore so ↩ button is reachable', async () => {
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response('{"success":true,"ignored":true}', { status: 200 }),
        );

        const wrapper = mountModal(inboundModal());
        // Default state: showIgnored=false (modal opens with only
        // actionable rows visible).
        expect(wrapper.vm.showIgnored).toBe(false);

        const suggestion = wrapper.props('modal').suggestions[0];
        await wrapper.vm.ignoreItem(suggestion, false);
        await flushPromises();

        // After explicit ignore action, the toggle must auto-flip so
        // the just-ignored row stays visible with ↩ in the same frame.
        // Without this the row disappears via the sortedInbound filter
        // and the user has to hunt for "Show ignored" to undo.
        expect(wrapper.vm.showIgnored).toBe(true);
        expect(suggestion.is_ignored).toBe(true);
    });

    it('Select-All excludes ignored rows (inbound, user-bug 2026-05-22)', async () => {
        // 3 ignored + 3 actionable inbound suggestions. Master-checkbox
        // clicked → only the 3 actionable should land in `selected`.
        // Pre-fix: master-check selected all 6 → "Add 6 links" button
        // showed even though 3 were hidden + uninsertable.
        const buildSuggestion = (id, ignored) => ({
            source_entry_id: id,
            source_title: 'Source ' + id,
            source_collection: 'posts',
            _anchor: 'a',
            _originalAnchor: 'a',
            _status: 'pending',
            score: 0.5,
            match_type: 'title',
            is_ignored: ignored,
        });
        const wrapper = mountModal({
            mode: 'inbound',
            entryId: 'target',
            title: 'x',
            suggestions: [
                buildSuggestion('s1', false),
                buildSuggestion('s2', true),
                buildSuggestion('s3', false),
                buildSuggestion('s4', true),
                buildSuggestion('s5', false),
                buildSuggestion('s6', true),
            ],
            totalAvailable: 6,
        });

        // Trigger select-all
        wrapper.vm.toggleSelectAll();

        expect(wrapper.vm.selected.length).toBe(3);
        // Verify only non-ignored suggestions made it in
        const selectedIds = wrapper.vm.selected.map(s => s.source_entry_id).sort();
        expect(selectedIds).toEqual(['s1', 's3', 's5']);
    });

    it('Select-All excludes ignored groups (outbound)', async () => {
        // Outbound: a group is "ignored" when its currently-selected
        // target carries is_ignored=true. Master-check must skip
        // those groups (sister-branch to the inbound fix).
        const buildGroup = (key, targetId, ignored) => ({
            key,
            _anchor: 'a',
            _originalAnchor: 'a',
            _status: 'pending',
            _selectedTarget: targetId,
            _expanded: false,
            targets: [
                { target_entry_id: targetId, target_title: 'T', target_collection: 'p', match_type: 'title', score: 0.7, is_ignored: ignored },
            ],
            sentence_context: 'sentence',
        });
        const wrapper = mountModal({
            mode: 'outbound',
            entryId: 'src',
            title: 'x',
            groups: [
                buildGroup('g1', 't1', false),
                buildGroup('g2', 't2', true),
                buildGroup('g3', 't3', false),
            ],
        });

        wrapper.vm.toggleSelectAll();

        expect(wrapper.vm.selected.length).toBe(2);
        expect(wrapper.vm.selected.sort()).toEqual(['g1', 'g3']);
    });

    it('removes the item from `selected` when it gets ignored (inbound)', async () => {
        // User ticks the checkbox, then changes their mind and
        // clicks ✕. The "Add N links" counter must NOT carry the
        // now-ignored item — otherwise the next "Add" tries to
        // insert something the user just told it to forget.
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response('{"success":true,"ignored":true}', { status: 200 }),
        );

        const wrapper = mountModal(inboundModal());
        const suggestion = wrapper.props('modal').suggestions[0];

        // Simulate the user ticking the checkbox first.
        wrapper.vm.selected.push(suggestion);
        expect(wrapper.vm.selected.length).toBe(1);

        await wrapper.vm.ignoreItem(suggestion, false);
        await flushPromises();

        expect(wrapper.vm.selected.length).toBe(0);
        expect(suggestion.is_ignored).toBe(true);
    });

    it('removes the group from `selected` when its picked target gets ignored (outbound)', async () => {
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response('{"success":true,"ignored":true}', { status: 200 }),
        );

        const outboundModal = {
            mode: 'outbound',
            entryId: 'src',
            title: 'x',
            groups: [{
                key: 'g1',
                _anchor: 'a',
                _originalAnchor: 'a',
                _status: 'pending',
                _selectedTarget: 't1',
                _expanded: false,
                targets: [
                    { target_entry_id: 't1', target_title: 'T1', target_collection: 'p', match_type: 'title', score: 0.7, is_ignored: false },
                ],
                sentence_context: 'sentence',
            }],
        };
        const wrapper = mountModal(outboundModal);

        // User had ticked the group's checkbox.
        wrapper.vm.selected.push('g1');
        expect(wrapper.vm.selected.length).toBe(1);

        const target = outboundModal.groups[0].targets[0];
        await wrapper.vm.ignoreItem(target, /* isOutboundTarget */ true);
        await flushPromises();

        expect(wrapper.vm.selected.length).toBe(0);
        expect(target.is_ignored).toBe(true);
    });

    it('emits @ignored on outbound-target ignore (sister-branch parity)', async () => {
        // The outbound branch calls ignoreItem(target, isOutboundTarget=true).
        // Same emit contract — both branches must keep the count guarantee.
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response('{"success":true,"ignored":true}', { status: 200 }),
        );

        const outboundModal = {
            mode: 'outbound',
            entryId: 'entry-source',
            title: 'outbound',
            groups: [{
                key: 'g1',
                _anchor: 'phrase',
                _originalAnchor: 'phrase',
                _status: 'pending',
                _selectedTarget: 'entry-target-1',
                _expanded: false,
                targets: [{
                    target_entry_id: 'entry-target-1',
                    target_title: 'T1',
                    target_collection: 'posts',
                    match_type: 'title',
                    score: 0.7,
                    is_ignored: false,
                }],
                sentence_context: 'a sentence',
            }],
        };

        const wrapper = mountModal(outboundModal);
        const target = outboundModal.groups[0].targets[0];
        await wrapper.vm.ignoreItem(target, /* isOutboundTarget */ true);
        await flushPromises();

        expect(wrapper.emitted('ignored')).toBeTruthy();
        expect(target.is_ignored).toBe(true);
    });

    it('skips the POST entirely when ignoreSuggestionUrl prop is missing', async () => {
        const fetchSpy = vi.spyOn(window, 'fetch');

        const wrapper = mount(SuggestionModal, {
            props: {
                modal: inboundModal(),
                // ignoreSuggestionUrl deliberately omitted
            },
        });

        const suggestion = wrapper.props('modal').suggestions[0];
        await wrapper.vm.ignoreItem(suggestion, false);
        await flushPromises();

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(wrapper.emitted('ignored')).toBeFalsy();
        expect(suggestion.is_ignored).toBe(false);
    });

    it('insertSuggestions belt-and-suspenders: filters ignored from emitted payload (inbound)', async () => {
        // Defense-in-depth pin (user-bug 2026-05-22). The Select-All filter
        // already drops ignored suggestions, but if a future refactor (or a
        // direct test of `selected`) lands an ignored item in the array, the
        // emit must STILL filter — server gates with the same rule, and
        // emitting ignored items would burn a UX round-trip the server then
        // rejects with skipped='ignored' in the Activity Log.
        const wrapper = mount(SuggestionModal, {
            props: {
                modal: {
                    mode: 'inbound',
                    entryId: 'target',
                    title: 'x',
                    suggestions: [
                        { source_entry_id: 's1', source_title: 'S1', source_collection: 'posts', _anchor: 'a', _originalAnchor: 'a', _status: 'pending', score: 0.5, match_type: 'title', is_ignored: false },
                        { source_entry_id: 's2', source_title: 'S2', source_collection: 'posts', _anchor: 'b', _originalAnchor: 'b', _status: 'pending', score: 0.5, match_type: 'title', is_ignored: true },
                    ],
                    totalAvailable: 2,
                },
            },
        });

        // Bypass toggleSelectAll's filter on purpose — we want to prove the
        // emit filter would catch a leak from any other source.
        wrapper.vm.selected = [...wrapper.props('modal').suggestions];
        wrapper.vm.insertSuggestions();
        await flushPromises();

        const insertEmit = wrapper.emitted('insert');
        expect(insertEmit).toBeTruthy();
        const payload = insertEmit[0][0];
        expect(payload.length).toBe(1);
        expect(payload[0].source_entry_id).toBe('s1');
    });

    it('insertSuggestions belt-and-suspenders: filters ignored groups from emitted payload (outbound)', async () => {
        // Outbound: ignored is on the currently-selected target. The emit
        // filter resolves the group → selected-target → is_ignored chain
        // the same way toggleSelectAll does, so the server never sees
        // ignored pairs even if a test bypassed the master-checkbox path.
        const wrapper = mount(SuggestionModal, {
            props: {
                modal: {
                    mode: 'outbound',
                    entryId: 'src',
                    title: 'x',
                    groups: [
                        { key: 'g1', _anchor: 'a', _originalAnchor: 'a', _status: 'pending', _selectedTarget: 't1', _expanded: false, targets: [{ target_entry_id: 't1', target_title: 'T1', target_collection: 'p', match_type: 'title', score: 0.7, is_ignored: false }], sentence_context: 's' },
                        { key: 'g2', _anchor: 'b', _originalAnchor: 'b', _status: 'pending', _selectedTarget: 't2', _expanded: false, targets: [{ target_entry_id: 't2', target_title: 'T2', target_collection: 'p', match_type: 'title', score: 0.7, is_ignored: true }], sentence_context: 's' },
                    ],
                },
            },
        });

        wrapper.vm.selected = ['g1', 'g2'];
        wrapper.vm.insertSuggestions();
        await flushPromises();

        const insertEmit = wrapper.emitted('insert');
        expect(insertEmit).toBeTruthy();
        expect(insertEmit[0][0]).toEqual(['g1']);
    });
});
