import { describe, it, expect, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import AutoLinkingTab from '@/components/dashboard/AutoLinkingTab.vue';

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
