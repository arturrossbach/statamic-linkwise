import { describe, it, expect, beforeEach, vi } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';
import AutoLinkingTab from '@/components/dashboard/AutoLinkingTab.vue';
import SortableHeader from '@/components/shared/SortableHeader.vue';
import { mockRule } from '../__fixtures__/rules.js';

/**
 * Characterisation pins for AutoLinkingTab.vue (1759 LOC god-component).
 *
 * Sprint 5 PR 2 Phase A — pre-refactor net. Per advisor pre-PR-review:
 * "Pin-Tests pro Interaktions-Paar, dann erst splitten."
 *
 * Memory `sprint5_frontend_pickup.md` flags three state-drift surfaces
 * that the upcoming Composable extraction must NOT break:
 *
 *   1. `customUrlValid` — URL validation rules (Custom Url linkMode)
 *   2. `canCreate` — keyword + linkMode-Entry/selectedEntry vs Custom-Url + customUrlValid
 *   3. `formDirty` — edit-mode snapshot vs live newRule state
 *
 * Plus the bulk-action helper triplet (`selectedActiveCount` /
 * `selectedInactiveCount` / `selectedHasActive` / `selectedHasInactive`)
 * which the toolbar consumes — verified for symmetry under empty + mixed
 * selection.
 *
 * All tests use shallowMount + props-only construction; no Inertia
 * router calls, no real CP runtime, no real bulkOperationService.
 *
 * Refactor target (PR 2 Phase B): extract `useUrlValidation` +
 * `useFormDirty` composables. THESE TESTS MUST STILL PASS AFTER
 * EXTRACTION — they are the contract.
 */
describe('AutoLinkingTab (Phase A characterisation)', () => {
    let wrapper;

    /** Minimal mount: data prop with empty rules + collections, no entries. */
    const mountTab = (entries = []) =>
        shallowMount(AutoLinkingTab, {
            props: {
                data: {
                    rules: [],
                    collections: [],
                    auto_apply_on_save_enabled: false,
                    urls: {},
                },
                entries,
            },
            global: {
                mocks: {
                    $page: { props: { linkwise: {} } },
                },
            },
        });

    beforeEach(() => {
        wrapper = mountTab();
    });

    // ── customUrlValid — URL truth table ───────────────────────────────

    describe('customUrlValid', () => {
        const setUrl = (v) => { wrapper.vm.newRule.url = v; };

        it('returns false for empty string', () => {
            setUrl('');
            expect(wrapper.vm.customUrlValid).toBe(false);
        });

        it('returns false for whitespace-containing input', () => {
            setUrl('https://example .com');
            expect(wrapper.vm.customUrlValid).toBe(false);
        });

        it('returns false for un-parseable strings', () => {
            setUrl('not a url');
            expect(wrapper.vm.customUrlValid).toBe(false);
        });

        it('returns false for disallowed protocols', () => {
            setUrl('ftp://example.com');
            expect(wrapper.vm.customUrlValid).toBe(false);
        });

        it('returns false for hostname without a dot (http)', () => {
            setUrl('http://localhost');
            expect(wrapper.vm.customUrlValid).toBe(false);
        });

        it('returns false for hostnames with unsafe chars', () => {
            setUrl('https://ex_ample.com');
            expect(wrapper.vm.customUrlValid).toBe(false);
        });

        it('returns true for a well-formed https URL', () => {
            setUrl('https://example.com/path');
            expect(wrapper.vm.customUrlValid).toBe(true);
        });

        it('returns true for mailto: without dot-rule', () => {
            setUrl('mailto:hello@example.com');
            expect(wrapper.vm.customUrlValid).toBe(true);
        });

        it('returns true for tel: protocol', () => {
            setUrl('tel:+491234567');
            expect(wrapper.vm.customUrlValid).toBe(true);
        });
    });

    // ── canCreate — interaction of keyword + linkMode + selectedEntry + customUrlValid ──

    describe('canCreate', () => {
        it('returns false when keyword is empty', async () => {
            wrapper.vm.newRule.keyword = '';
            wrapper.vm.linkMode = 'entry';
            wrapper.vm.selectedEntry = { id: 'x' };
            expect(wrapper.vm.canCreate).toBe(false);
        });

        it('returns false when keyword is whitespace only', () => {
            wrapper.vm.newRule.keyword = '   ';
            wrapper.vm.linkMode = 'entry';
            wrapper.vm.selectedEntry = { id: 'x' };
            expect(wrapper.vm.canCreate).toBe(false);
        });

        it('returns false in entry-mode when no entry is selected', () => {
            wrapper.vm.newRule.keyword = 'kw';
            wrapper.vm.linkMode = 'entry';
            wrapper.vm.selectedEntry = null;
            expect(wrapper.vm.canCreate).toBe(false);
        });

        it('returns true in entry-mode when keyword + selectedEntry present', () => {
            wrapper.vm.newRule.keyword = 'kw';
            wrapper.vm.linkMode = 'entry';
            wrapper.vm.selectedEntry = { id: 'x' };
            expect(wrapper.vm.canCreate).toBe(true);
        });

        it('returns false in custom-url-mode when URL is invalid', () => {
            wrapper.vm.newRule.keyword = 'kw';
            wrapper.vm.linkMode = 'custom';
            wrapper.vm.newRule.url = 'not-a-url';
            expect(wrapper.vm.canCreate).toBe(false);
        });

        it('returns true in custom-url-mode when URL is valid', () => {
            wrapper.vm.newRule.keyword = 'kw';
            wrapper.vm.linkMode = 'custom';
            wrapper.vm.newRule.url = 'https://example.com';
            expect(wrapper.vm.canCreate).toBe(true);
        });

        it('ignores selectedEntry when in custom-url-mode', () => {
            // Even with a selectedEntry, custom-mode must consult customUrlValid.
            wrapper.vm.newRule.keyword = 'kw';
            wrapper.vm.linkMode = 'custom';
            wrapper.vm.newRule.url = '';
            wrapper.vm.selectedEntry = { id: 'x' };
            expect(wrapper.vm.canCreate).toBe(false);
        });
    });

    // ── formDirty — edit-mode snapshot vs live state ───────────────────

    describe('formDirty', () => {
        it('returns false when not in edit mode', () => {
            wrapper.vm.editingRule = null;
            wrapper.vm.editingRuleSnapshot = null;
            expect(wrapper.vm.formDirty).toBe(false);
        });

        it('returns false when snapshot equals live state', () => {
            wrapper.vm.editingRule = { id: 'r1' };
            wrapper.vm.editingRuleSnapshot = { keyword: 'a', url: 'https://x.com' };
            wrapper.vm.newRule = { keyword: 'a', url: 'https://x.com' };
            wrapper.vm.linkMode = 'entry';
            wrapper.vm.editingLinkModeSnapshot = 'entry';
            expect(wrapper.vm.formDirty).toBe(false);
        });

        it('returns true when any tracked field differs', () => {
            wrapper.vm.editingRule = { id: 'r1' };
            wrapper.vm.editingRuleSnapshot = { keyword: 'a', url: 'https://x.com' };
            wrapper.vm.newRule = { keyword: 'a-edited', url: 'https://x.com' };
            wrapper.vm.linkMode = 'entry';
            wrapper.vm.editingLinkModeSnapshot = 'entry';
            expect(wrapper.vm.formDirty).toBe(true);
        });

        it('returns true when linkMode flipped vs snapshot', () => {
            wrapper.vm.editingRule = { id: 'r1' };
            wrapper.vm.editingRuleSnapshot = { keyword: 'a', url: 'https://x.com' };
            wrapper.vm.newRule = { keyword: 'a', url: 'https://x.com' };
            wrapper.vm.linkMode = 'custom';
            wrapper.vm.editingLinkModeSnapshot = 'entry';
            expect(wrapper.vm.formDirty).toBe(true);
        });
    });

    // ── selected*Count toolbar helpers — symmetry contract ─────────────

    describe('selected*Count toolbar helpers', () => {
        const setRules = (rules) => { wrapper.vm.rules = rules; };

        it('zeros when no rules selected', () => {
            setRules([{ id: 'r1', active: true }]);
            wrapper.vm.selectedRules = [];
            expect(wrapper.vm.selectedActiveCount).toBe(0);
            expect(wrapper.vm.selectedInactiveCount).toBe(0);
            expect(wrapper.vm.selectedHasActive).toBe(false);
            expect(wrapper.vm.selectedHasInactive).toBe(false);
        });

        it('counts active vs inactive separately', () => {
            setRules([
                { id: 'r1', active: true },
                { id: 'r2', active: false },
                { id: 'r3', active: true },
            ]);
            wrapper.vm.selectedRules = ['r1', 'r2', 'r3'];
            expect(wrapper.vm.selectedActiveCount).toBe(2);
            expect(wrapper.vm.selectedInactiveCount).toBe(1);
            expect(wrapper.vm.selectedHasActive).toBe(true);
            expect(wrapper.vm.selectedHasInactive).toBe(true);
        });

        it('selectedInactiveCount = selectedRules.length − selectedActiveCount (invariant)', () => {
            setRules([
                { id: 'r1', active: true },
                { id: 'r2', active: false },
            ]);
            wrapper.vm.selectedRules = ['r1', 'r2'];
            expect(wrapper.vm.selectedActiveCount + wrapper.vm.selectedInactiveCount)
                .toBe(wrapper.vm.selectedRules.length);
        });
    });

    // ── Render bridges — DOM markers stable across Sub-Component-Split ──
    //
    // These pin output that MUST persist when Z. 20-167 / Z. 215-353 / Z. 355-468
    // get extracted to RuleForm / RuleListTable / RulePreviewModal sub-components
    // in PR 2c–2e. Tests use text-content matching so they're agnostic to
    // whether the markup comes from inline template or a child component.

    describe('Render bridges', () => {
        // Full mount() (not shallowMount) so Statamic-UI-stubs render their
        // slot content. Render-pins must see the actual heading text inside
        // the wrapper Cards / Modals.
        const fullMount = () => mount(AutoLinkingTab, {
            props: {
                data: { rules: [], collections: [], auto_apply_on_save_enabled: false, urls: {} },
                entries: [],
            },
            global: {
                mocks: { $page: { props: { linkwise: {} } } },
            },
        });

        it('renders "Create Rule" heading when not in edit mode', () => {
            const w = fullMount();
            expect(w.text()).toContain('Create Rule');
        });

        it('renders "Edit Rule" heading when editingRule is set', async () => {
            const w = fullMount();
            w.vm.editingRule = { id: 'r1', keyword: 'kw', url: 'https://x.com' };
            w.vm.editingRuleSnapshot = { keyword: 'kw', url: 'https://x.com' };
            w.vm.newRule = { keyword: 'kw', url: 'https://x.com' };
            await w.vm.$nextTick();
            expect(w.text()).toContain('Edit Rule');
        });

        it('renders "Auto-Linking Rules" intro card heading', () => {
            const w = fullMount();
            expect(w.text()).toContain('Auto-Linking Rules');
        });
    });

    // ── Sub-Component event-bridge pins (Sprint 5 PR 2c) ────────────────
    //
    // RuleForm.vue now owns the form template; the parent listens for
    // `submit` / `cancel` / `entry-picked` events. These pins verify the
    // wiring — Render-Bridge text-content alone wouldn't catch a renamed
    // event or a typo'd handler binding.

    describe('RuleForm event bridges', () => {
        const fullMount = () => mount(AutoLinkingTab, {
            props: {
                data: { rules: [], collections: [], auto_apply_on_save_enabled: false, urls: {} },
                entries: [],
            },
            global: {
                mocks: { $page: { props: { linkwise: {} } } },
            },
        });

        it('@submit emit on RuleForm calls saveRule which posts via fetch', async () => {
            const w = fullMount();
            // Spy on `fetch` (the wrapper's internal HTTP helper, not window.fetch).
            // saveRule short-circuits when !canCreate, so we set up the
            // minimal canCreate=true preconditions before the emit.
            w.vm.newRule.keyword = 'laravel';
            w.vm.linkMode = 'url';
            w.vm.newRule.url = 'https://laravel.com';
            await w.vm.$nextTick();
            expect(w.vm.canCreate).toBe(true);

            const fetchSpy = vi.spyOn(w.vm, 'fetch').mockResolvedValue({ ok: true, rule: { id: 'r1' } });
            w.findComponent({ name: 'RuleForm' }).vm.$emit('submit');
            await w.vm.$nextTick();
            // saveRule kicks off the keyword-loop iteration; first iteration
            // synchronously calls fetch(...). If the template binding were
            // broken (renamed event, typo'd handler) fetchSpy would not be hit.
            expect(fetchSpy).toHaveBeenCalled();
            fetchSpy.mockRestore();
        });

        it('@cancel emit on RuleForm resets editingRule via cancelEdit', async () => {
            const w = fullMount();
            w.vm.editingRule = { id: 'r1', keyword: 'kw' };
            w.vm.editingRuleSnapshot = { keyword: 'kw' };
            await w.vm.$nextTick();
            w.findComponent({ name: 'RuleForm' }).vm.$emit('cancel');
            await w.vm.$nextTick();
            expect(w.vm.editingRule).toBeNull();
            expect(w.vm.editingRuleSnapshot).toBeNull();
        });

        it('@entry-picked emit on RuleForm forwards to parent onEntryPicked', async () => {
            const w = fullMount();
            const payload = [{ id: 'e42', title: 'Foo Bar', collection: { handle: 'pages' } }];
            w.findComponent({ name: 'RuleForm' }).vm.$emit('entry-picked', payload);
            await w.vm.$nextTick();
            expect(w.vm.selectedEntry).toEqual({
                id: 'e42',
                title: 'Foo Bar',
                collection: 'pages',
            });
            expect(w.vm.newRule.url).toBe('statamic://entry::e42');
        });

        it('parent openEntrySelector bridges to RuleForm ref', () => {
            const w = fullMount();
            const ruleForm = w.findComponent({ name: 'RuleForm' });
            const spy = vi.spyOn(ruleForm.vm, 'openEntrySelector').mockImplementation(() => {});
            w.vm.openEntrySelector();
            expect(spy).toHaveBeenCalledOnce();
            spy.mockRestore();
        });
    });

    // ── RuleListTable characterisation pins (Sprint 5 PR 2d-prep, pre-extract) ───
    //
    // Lock the rule-table surface BEFORE Z. 96-229 (<Panel><table>) gets
    // extracted into RuleListTable.vue. These pins must stay byte-stable
    // through the extract — they assert column headers + per-row data
    // marker + toggle-sort wiring + row-action dropdown wiring.
    //
    // The advisor's "Charakterisierungs-Pins VOR Code-Touch" pflicht: pin
    // what is, then refactor, then verify pins still green. Same pattern
    // as PR 2c's Render-Bridge prep (PR #25).

    describe('Rule table characterisation', () => {
        // Single rule with all stat fields populated so the row renders
        // every column. wouldLinkForRule(mockRule) === 6 (10−2−1−1).
        // Fixture lives in tests/Vue/__fixtures__/rules.js — extracted
        // in PR 2e-prep for reuse across PR 2e + PR 4/5/6 splits.

        const mountWithRule = () => mount(AutoLinkingTab, {
            props: {
                data: { rules: [mockRule], collections: [], auto_apply_on_save_enabled: false, urls: {} },
                entries: [],
            },
            global: {
                mocks: { $page: { props: { linkwise: {} } } },
            },
        });

        it('renders all 8 sortable column headers via SortableHeader props', () => {
            const w = mountWithRule();
            // Note: SortableHeader passes `label` to a Button (sortable=true) or
            // a <span> (sortable=false). Under the passthrough Button-stub the
            // sortable case's label is a prop-attribute, not text content. So
            // we assert via the SortableHeader component's `label` prop —
            // refactor-orthogonal across the upcoming RuleListTable extract.
            const headers = w.findAllComponents(SortableHeader);
            const labels = headers.map(h => h.props('label'));
            expect(labels).toEqual(expect.arrayContaining([
                'Keyword', 'Link Target', 'Matches', 'Already linked',
                'Will link', 'Last applied', 'Settings', 'Actions',
            ]));
            // Exactly 8 SortableHeader instances — guards against accidental
            // column addition/removal during the extract.
            expect(headers.length).toBe(8);
        });

        it('renders per-row data: keyword + link + will-link count + relative time', () => {
            const w = mountWithRule();
            const text = w.text();
            expect(text).toContain('laravel');
            expect(text).toContain('https://laravel.com');
            // wouldLinkForRule(mockRule) === 6 → appears in the row text.
            // (Match-count 10 and will-link 6 both render as plain numbers.)
            expect(text).toContain('10');
            expect(text).toContain('6');
            // formatRelativeTime turns 'just now' for very recent ISO timestamps.
            expect(text).toContain('just now');
            // Settings dl labels prove the column rendered all fields.
            expect(text).toContain('Case:');
            expect(text).toContain('Collections:');
        });

        it('toggle-sort: clicking SortableHeader cycles sortField + sortDirection', async () => {
            const w = mountWithRule();
            // Initial state from data(): sortField='created_at', direction='desc'.
            expect(w.vm.sortField).toBe('created_at');
            expect(w.vm.sortDirection).toBe('desc');

            // Find the first SortableHeader (Keyword column) and emit 'sort'.
            const headers = w.findAllComponents(SortableHeader);
            expect(headers.length).toBeGreaterThan(0);
            // The Keyword header is the first sortable one (after the
            // checkbox-only <th>). Emit sort directly — Vue resolves to
            // the @sort listener bound in the template.
            headers[0].vm.$emit('sort');
            await w.vm.$nextTick();
            // After extract: this still flows through to parent's toggleSort
            // (from sortableMixin). Pin the state transition.
            expect(w.vm.sortField).toBe('keyword');
        });

        it('row-action dropdown: Edit + Delete DropdownItems are present per row', () => {
            const w = mountWithRule();
            // DropdownItem-stub is a passthrough that renders attrs onto the
            // wrapper div — `text` is an HTML attribute, not a declared Vue
            // prop. Read via the rendered DOM-attribute on the stub div.
            const items = w.findAll('[data-stub="DropdownItem"]');
            const texts = items.map(i => i.attributes('text'));
            expect(texts).toContain('Edit');
            expect(texts).toContain('Delete');
            // Active rule (mockRule.active=true) → "Ignore (skip during Apply)"
            // is the toggle-active label. Pinning the literal label.
            expect(texts).toContain('Ignore (skip during Apply)');
        });

        // ── RuleListTable wiring pins (Sprint 5 PR 2d, post-extract) ────────
        //
        // Sub-Component extracted. These pins lock the wiring contract:
        // parent ↔ child event-bridges + scrollToRule ref-bridge (Option B).

        it('scrollToRule bridges to RuleListTable.getRowRef via $refs', async () => {
            const w = mountWithRule();
            const table = w.findComponent({ name: 'RuleListTable' });
            expect(table.exists()).toBe(true);
            const spy = vi.spyOn(table.vm, 'getRowRef').mockReturnValue(undefined);
            w.vm.scrollToRule('r1');
            await w.vm.$nextTick();
            // getRowRef called by the parent's scrollToRule via the ref bridge.
            expect(spy).toHaveBeenCalledWith('r1');
            spy.mockRestore();
        });

        it('@edit-rule emit on RuleListTable triggers parent editRule', async () => {
            const w = mountWithRule();
            const table = w.findComponent({ name: 'RuleListTable' });
            table.vm.$emit('edit-rule', mockRule);
            await w.vm.$nextTick();
            // editRule sets editingRule + snapshots — pin via state.
            // Vue 3 wraps assigned objects in reactive proxies — Object.is
            // identity fails but structural equality holds via toEqual.
            expect(w.vm.editingRule).toEqual(mockRule);
            expect(w.vm.editingRuleSnapshot).not.toBeNull();
        });

        it('@toggle-select-all emit clears or fills selection', async () => {
            const w = mountWithRule();
            const table = w.findComponent({ name: 'RuleListTable' });
            expect(w.vm.selectedRules.length).toBe(0);
            // toggleSelectAll fills with filteredRules ids when nothing
            // is selected; clears when allSelected.
            table.vm.$emit('toggle-select-all');
            await w.vm.$nextTick();
            expect(w.vm.selectedRules).toEqual(['r1']);
            // Second call clears since allSelected is now true.
            table.vm.$emit('toggle-select-all');
            await w.vm.$nextTick();
            expect(w.vm.selectedRules).toEqual([]);
        });

        // Remaining 4 action-event bridges — pin via downstream state-effect
        // (spy-after-mount can't intercept template listeners, so we observe
        // the side-effect the handler synchronously mutates).

        it('@confirm-delete emit sets deleteConfirm to the rule', async () => {
            const w = mountWithRule();
            const table = w.findComponent({ name: 'RuleListTable' });
            expect(w.vm.deleteConfirm).toBeNull();
            table.vm.$emit('confirm-delete', mockRule);
            await w.vm.$nextTick();
            expect(w.vm.deleteConfirm).toEqual(mockRule);
        });

        it('@preview-rule emit opens the previewModal with the rule', async () => {
            const w = mountWithRule();
            // previewRule async chain: needs data.urls.store (used in
            // `url.replace()`) + a fetch stub. Without urls.store the chain
            // throws synchronously and the catch resets previewModal to null.
            w.vm.data.urls = { store: '/x/rules' };
            vi.spyOn(w.vm, 'fetch').mockResolvedValue({ ok: true, affected_entries: [] });
            const table = w.findComponent({ name: 'RuleListTable' });
            expect(w.vm.previewModal).toBeNull();
            table.vm.$emit('preview-rule', mockRule);
            await w.vm.$nextTick();
            expect(w.vm.previewModal).not.toBeNull();
            expect(w.vm.previewModal.keyword).toBe('laravel');
            expect(w.vm.previewModal.ruleId).toBe('r1');
        });

        it('@apply-rule emit delegates to dispatchApplyAsync', async () => {
            const w = mountWithRule();
            const spy = vi.spyOn(w.vm, 'dispatchApplyAsync').mockResolvedValue(undefined);
            const table = w.findComponent({ name: 'RuleListTable' });
            table.vm.$emit('apply-rule', mockRule);
            await w.vm.$nextTick();
            // dispatchApplyAsync is invoked synchronously from applyRule —
            // spy-after-mount works for method-internal calls (vs template).
            expect(spy).toHaveBeenCalledOnce();
            expect(spy.mock.calls[0][0]).toEqual(mockRule);
            spy.mockRestore();
        });

        it('@toggle-active emit on RuleListTable triggers PUT via fetch', async () => {
            const w = mountWithRule();
            // toggleActive uses this.data.urls.store; mockRule fixture has no
            // urls — set it minimally so the URL replace() doesn't throw.
            w.vm.data.urls = { store: '/x/rules' };
            const fetchSpy = vi.spyOn(w.vm, 'fetch').mockResolvedValue({ success: true, rule: { active: false } });
            const table = w.findComponent({ name: 'RuleListTable' });
            table.vm.$emit('toggle-active', mockRule);
            await w.vm.$nextTick();
            // toggleActive calls fetch synchronously with the rule's PUT URL.
            expect(fetchSpy).toHaveBeenCalled();
            const [url, method] = fetchSpy.mock.calls[0];
            expect(url).toContain('r1');
            expect(method).toBe('PUT');
            fetchSpy.mockRestore();
        });
    });

    // ── RulePreviewModal wiring pins (Sprint 5 PR 2e, post-extract) ─────
    //
    // Sub-Component extracted. These pins lock the wiring contract:
    // parent ↔ child v-model bridges + the three action emits
    // (`@close`, `@apply`, `@unlink`). The pre-extract behaviour for
    // `previewModal` open/close is already covered by the
    // "@preview-rule emit opens the previewModal with the rule" pin
    // above — that flow is parent-owned and survives the extract.

    describe('RulePreviewModal wiring', () => {
        // Set up a mount that has a live previewModal so the child renders
        // and its event handlers are reachable.
        const mountWithOpenPreview = async (modalOverrides = {}) => {
            const w = mount(AutoLinkingTab, {
                props: {
                    data: { rules: [mockRule], collections: [], auto_apply_on_save_enabled: false, urls: { store: '/x/rules' } },
                    entries: [],
                },
                global: {
                    mocks: { $page: { props: { linkwise: {} } } },
                },
            });
            // Seed the modal directly — bypasses fetch/network. Async paths
            // read these state fields, so this matches the post-previewRule
            // shape exactly.
            w.vm.previewModal = {
                title: 'Preview: "laravel"',
                keyword: 'laravel',
                ruleId: 'r1',
                loading: false,
                items: [
                    { id: 'e1', title: 'Entry 1', link_status: 'would_link', edit_url: '/e1', sentence_context: 'foo laravel bar' },
                    { id: 'e2', title: 'Entry 2', link_status: 'linked_to_target', edit_url: '/e2', sentence_context: 'baz laravel qux' },
                ],
                ...modalOverrides,
            };
            await w.vm.$nextTick();
            return w;
        };

        it('@close emit on RulePreviewModal nulls previewModal via closePreviewModal', async () => {
            const w = await mountWithOpenPreview();
            const modal = w.findComponent({ name: 'RulePreviewModal' });
            expect(modal.exists()).toBe(true);
            expect(w.vm.previewModal).not.toBeNull();
            modal.vm.$emit('close');
            await w.vm.$nextTick();
            // closePreviewModal sets previewModal=null AND clears the two
            // selection pools (resetPreviewModalState).
            expect(w.vm.previewModal).toBeNull();
            expect(w.vm.excludedEntryIds).toEqual([]);
            expect(w.vm.selectedUnlinkIds).toEqual([]);
        });

        it('@apply emit on RulePreviewModal delegates to dispatchApplyAsync with excluded ids', async () => {
            const w = await mountWithOpenPreview();
            const spy = vi.spyOn(w.vm, 'dispatchApplyAsync').mockResolvedValue(undefined);
            // Pre-set an exclusion so we can verify it's forwarded.
            w.vm.excludedEntryIds = ['e1-excluded'];
            const modal = w.findComponent({ name: 'RulePreviewModal' });
            modal.vm.$emit('apply');
            // applyFromPreview is async — flush microtasks.
            await w.vm.$nextTick();
            await new Promise(r => setTimeout(r, 0));
            expect(spy).toHaveBeenCalledOnce();
            const [rule, opts] = spy.mock.calls[0];
            expect(rule.id).toBe('r1');
            expect(opts.excluded_entry_ids).toEqual(['e1-excluded']);
            spy.mockRestore();
        });

        it('@unlink emit on RulePreviewModal POSTs to detail-unlink-async', async () => {
            const w = await mountWithOpenPreview();
            // Need at least one selected id for the bail-early guard to pass.
            w.vm.selectedUnlinkIds = ['e2'];
            // Statamic globals used by unlinkSelectedFromPreview.
            global.Statamic = {
                $config: { get: vi.fn(() => 'csrf-token') },
                $toast: { error: vi.fn(), success: vi.fn() },
            };
            global.fetch = vi.fn().mockResolvedValue({ ok: true, json: vi.fn().mockResolvedValue({}) });
            const modal = w.findComponent({ name: 'RulePreviewModal' });
            modal.vm.$emit('unlink');
            await w.vm.$nextTick();
            await new Promise(r => setTimeout(r, 0));
            expect(global.fetch).toHaveBeenCalled();
            const [url] = global.fetch.mock.calls[0];
            expect(url).toBe('/cp/linkwise/detail-unlink-async');
            delete global.fetch;
            delete global.Statamic;
        });

        it('v-model:excluded-entry-ids: child update event mutates parent state', async () => {
            const w = await mountWithOpenPreview();
            const modal = w.findComponent({ name: 'RulePreviewModal' });
            expect(w.vm.excludedEntryIds).toEqual([]);
            modal.vm.$emit('update:excludedEntryIds', ['e1']);
            await w.vm.$nextTick();
            expect(w.vm.excludedEntryIds).toEqual(['e1']);
        });

        it('v-model:selected-unlink-ids: child update event mutates parent state', async () => {
            const w = await mountWithOpenPreview();
            const modal = w.findComponent({ name: 'RulePreviewModal' });
            expect(w.vm.selectedUnlinkIds).toEqual([]);
            modal.vm.$emit('update:selectedUnlinkIds', ['e2']);
            await w.vm.$nextTick();
            expect(w.vm.selectedUnlinkIds).toEqual(['e2']);
        });
    });

    // ── teardownApplyAsync — Klasse-9a button-stuck pin (2026-05-17) ───
    //
    // User-Smoke 2026-05-17: After Apply via Preview Modal, the row's
    // Apply button stayed in loading state and ALL other rules' Apply
    // buttons stayed greyed out via RuleListTable's gate
    // (`applyAsyncRuleId !== rule.id`, RuleListTable.vue:116). Root:
    // `pollApplyAsyncStatusOnce` had two exit branches (idle/unknown
    // and `wasActive=false` early-return) that cleared
    // `applyAsyncProgress` but forgot `applyAsyncRuleId`.
    //
    // These pins assert that for EVERY terminal-or-unknown phase the
    // poller can see, the cleanup is complete. New helper
    // `teardownApplyAsync()` is the single source of truth — if a
    // future branch is added that bypasses it, these tests fail.

    describe('teardownApplyAsync (Klasse 9a button-stuck pin)', () => {
        const seedApplyState = (w, ruleId = 'rule-x') => {
            w.vm.applyAsyncRuleId = ruleId;
            w.vm.applyAsyncProgress = { rule_keyword: 'kw', current: 0, total: 0 };
            // Pretend the poll-interval is live so we can assert it
            // gets cleared too. We don't actually run any timers — the
            // ID is what matters for the cleanup contract.
            w.vm.applyAsyncPollTimer = 42;
        };

        it('clears all three async-apply state fields in one call', () => {
            seedApplyState(wrapper);
            // Stub away the real clearInterval — jsdom would yell about
            // the fake 42 timer ID. We only care about the state mutation.
            const originalClearInterval = globalThis.clearInterval;
            globalThis.clearInterval = vi.fn();

            wrapper.vm.teardownApplyAsync();

            expect(wrapper.vm.applyAsyncRuleId).toBeNull();
            expect(wrapper.vm.applyAsyncProgress).toBeNull();
            expect(wrapper.vm.applyAsyncPollTimer).toBeNull();
            expect(globalThis.clearInterval).toHaveBeenCalledWith(42);

            globalThis.clearInterval = originalClearInterval;
        });

        it('is idempotent (calling twice on already-clean state is safe)', () => {
            const originalClearInterval = globalThis.clearInterval;
            globalThis.clearInterval = vi.fn();

            wrapper.vm.teardownApplyAsync();
            expect(() => wrapper.vm.teardownApplyAsync()).not.toThrow();
            expect(wrapper.vm.applyAsyncRuleId).toBeNull();
            expect(wrapper.vm.applyAsyncProgress).toBeNull();

            globalThis.clearInterval = originalClearInterval;
        });

        // ── Per-phase Pin (every terminal-or-soft-terminal phase
        // returned by the apply-async-status endpoint must end in a
        // teardown). The poller's else-branch catches `idle` and any
        // unknown phase string — both must teardown like a terminal.

        const terminalPhases = ['done', 'cancelled', 'error', 'idle', 'unknown-phase-string'];

        terminalPhases.forEach((phase) => {
            it(`pollApplyAsyncStatusOnce clears applyAsyncRuleId on phase="${phase}"`, async () => {
                // Wire a fetch stub returning the phase under test. We
                // only need the cleanup contract; the rule-row update
                // and applyAsyncResult side-effects are not pinned here
                // (those are toast / table-render details).
                seedApplyState(wrapper, 'rule-x');
                const originalFetch = globalThis.fetch;
                globalThis.fetch = vi.fn().mockResolvedValue({
                    ok: true,
                    json: () => Promise.resolve({
                        phase,
                        rule_id: 'rule-x',
                        rule_keyword: 'kw',
                        links_added: 0,
                    }),
                });
                const originalClearInterval = globalThis.clearInterval;
                globalThis.clearInterval = vi.fn();

                // status_url has to be non-empty or the method returns
                // before the fetch — see line 833-834 of AutoLinkingTab.
                wrapper.vm.data.urls.apply_async_status = '/cp/linkwise/autolink/apply-async/status';

                await wrapper.vm.pollApplyAsyncStatusOnce();

                expect(wrapper.vm.applyAsyncRuleId).toBeNull();
                expect(wrapper.vm.applyAsyncProgress).toBeNull();

                globalThis.fetch = originalFetch;
                globalThis.clearInterval = originalClearInterval;
            });
        });

        // ── wasActive=false early-return: was the second of the two
        // hangs found in User-Smoke 2026-05-17. Before the fix, the
        // `if (!wasActive) return;` short-circuited before the
        // `applyAsyncRuleId = null` write. Now `teardownApplyAsync()`
        // runs FIRST, so the early-return doesn't matter.

        it('done-phase with wasActive=false still clears applyAsyncRuleId (stale-done path)', async () => {
            // Seed the ruleId but NOT the progress — simulates the
            // race where polling fires before dispatchApplyAsync's
            // progress-set lands, OR a leftover cache 'done' from a
            // prior session.
            wrapper.vm.applyAsyncRuleId = 'rule-x';
            wrapper.vm.applyAsyncProgress = null;
            wrapper.vm.applyAsyncPollTimer = 42;

            const originalFetch = globalThis.fetch;
            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    phase: 'done',
                    rule_id: 'rule-x',
                    rule_keyword: 'kw',
                    links_added: 5,
                }),
            });
            const originalClearInterval = globalThis.clearInterval;
            globalThis.clearInterval = vi.fn();
            wrapper.vm.data.urls.apply_async_status = '/cp/linkwise/autolink/apply-async/status';

            await wrapper.vm.pollApplyAsyncStatusOnce();

            // Pre-fix: this assertion failed — early-return bypassed cleanup.
            expect(wrapper.vm.applyAsyncRuleId).toBeNull();

            globalThis.fetch = originalFetch;
            globalThis.clearInterval = originalClearInterval;
        });
    });

    // ── entryHashes — derives hash map from prop ───────────────────────

    describe('entryHashes computed', () => {
        it('returns empty map for entries with no content_hash', () => {
            wrapper = mountTab([{ id: 'e1' }]);
            expect(wrapper.vm.entryHashes).toEqual({});
        });

        it('maps entry-id to content_hash for hashed entries', () => {
            wrapper = mountTab([
                { id: 'e1', content_hash: 'abc' },
                { id: 'e2', content_hash: 'def' },
            ]);
            expect(wrapper.vm.entryHashes).toEqual({ e1: 'abc', e2: 'def' });
        });

        it('skips entries without content_hash even when others have one', () => {
            wrapper = mountTab([
                { id: 'e1', content_hash: 'abc' },
                { id: 'e2' },
            ]);
            expect(wrapper.vm.entryHashes).toEqual({ e1: 'abc' });
        });
    });
});
