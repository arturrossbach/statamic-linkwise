import { describe, it, expect } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import ActivityPage from '@/components/pages/ActivityPage.vue';

/**
 * Pin-set for `formatSkipReason` render contract.
 *
 * Backstory: User-Smoke 2026-05-16 reported that skipped bulk-items in
 * the Activity-Log Drawer showed only `<reason>` with no clue WHICH
 * anchor or which target was skipped. Klasse-7 follow-up extended
 * `BulkSnapshotStore::buildSkipRecord` to carry anchor + target
 * context; the renderer now prefixes the reason with "Anchor 'X' →
 * 'Target': <reason>" when context is available.
 *
 * Backward-compat is the key invariant pinned here:
 *   - legacy snapshots on disk lack the new fields → render gracefully
 *     with just the reason text (today-behaviour preserved).
 *   - new snapshots get the enriched prefix.
 *   - missing-half cases (anchor without target, target without anchor)
 *     fall back to the partial prefix.
 *
 * Three render-branches × 2 reason-types × legacy vs. enriched = the
 * truth-table below. All use the same fake-row shape that
 * `BulkSnapshotStore::buildSkipRecord` produces.
 */
describe('ActivityPage.formatSkipReason — anchor + target render', () => {
    // Shallow-mount minimal — the method is pure, no rendering needed.
    // The full ActivityPage component has heavy Inertia + bulk-state
    // dependencies. ShallowMount with empty page props is enough to
    // get the methods bag attached.
    const buildVm = () => {
        const wrapper = shallowMount(ActivityPage, {
            props: {
                rebuildUrl: '',
                rebuildStatusUrl: '',
                rebuildCancelUrl: '',
                snapshots: [],
            },
            global: {
                mocks: { $page: { props: { linkwise: {} } } },
            },
        });
        return wrapper.vm;
    };

    // ── Legacy / backward-compat ────────────────────────────────────────

    it('renders just the reason text when row has no anchor and no target (legacy snapshot)', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'anchor_not_found',
            // no anchor_text, target_entry_title, target_href — legacy shape.
        });
        expect(out).toBe('Anchor text was not found in entry content — run a scan or edit the entry');
        expect(out).not.toContain('Anchor "');
        expect(out).not.toContain('→');
    });

    it('renders just the reason for legacy modified-row', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'modified',
            modified_by: 'Artur',
            // no anchor / target fields.
        });
        expect(out).toBe('Modified by <strong>Artur</strong> since this bulk ran');
        expect(out).not.toContain('Anchor "');
    });

    // ── Enriched: anchor + target both present ──────────────────────────

    it('renders Anchor + Target prefix when both fields are present', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'anchor_not_found',
            anchor_text: 'foo bar',
            target_entry_id: 'target-1',
            target_entry_title: 'Target Page',
        });
        expect(out).toBe(
            'Anchor "<strong>foo bar</strong>" → <em>Target Page</em>: '
            + 'Anchor text was not found in entry content — run a scan or edit the entry',
        );
    });

    it('prefers target_entry_title over target_href when both present', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'modified',
            modified_by: 'Artur',
            anchor_text: 'foo',
            target_entry_id: 'target-1',
            target_entry_title: 'Internal Title',
            target_href: 'statamic://entry::target-1',
        });
        expect(out).toContain('→ <em>Internal Title</em>');
        expect(out).not.toContain('statamic://');
    });

    it('falls back to target_href when title is missing (external target)', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'anchor_not_found',
            anchor_text: 'click here',
            target_href: 'https://example.com/old-page',
            // no target_entry_title — external URL.
        });
        expect(out).toContain('→ <em>https://example.com/old-page</em>');
    });

    // ── Enriched: anchor only ───────────────────────────────────────────

    it('renders Anchor-only prefix when target is null (missing_link_target case)', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'missing_link_target',
            anchor_text: 'orphan anchor',
            // target_entry_id / target_href both null — by definition of the reason.
        });
        expect(out).toBe(
            'Anchor "<strong>orphan anchor</strong>": '
            + 'Insertion payload was missing both target entry and href',
        );
        expect(out).not.toContain('→');
    });

    // ── XSS / escape ────────────────────────────────────────────────────

    it('escapes HTML special chars in anchor text to prevent v-html injection', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'error',
            anchor_text: '<script>alert("xss")</script>',
            target_entry_title: 'Safe',
        });
        // The literal '<script>' must NOT survive into the rendered HTML;
        // only the escaped form &lt;script&gt; should appear.
        expect(out).not.toContain('<script>');
        expect(out).toContain('&lt;script&gt;');
        // The wrapping <strong>/<em> remain intact (those are our own markup).
        expect(out).toContain('<strong>');
        expect(out).toContain('<em>');
    });

    it('escapes HTML special chars in target label', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'error',
            anchor_text: 'safe',
            target_entry_title: 'Title with "quotes" & <tag>',
        });
        expect(out).toContain('&quot;quotes&quot;');
        expect(out).toContain('&amp;');
        expect(out).toContain('&lt;tag&gt;');
        expect(out).not.toMatch(/<tag>/);
    });

    // ── Edge cases ──────────────────────────────────────────────────────

    it('treats empty-string anchor_text as missing (not rendered as Anchor "")', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'error',
            anchor_text: '',  // empty = missing
            target_entry_title: 'Target',
        });
        // hasAnchor=false → target-only branch.
        expect(out).toBe('Target <em>Target</em>: Unexpected error during write — see log for details');
    });

    it('falls back to target_entry_id when title and href both missing', () => {
        const vm = buildVm();
        const out = vm.formatSkipReason({
            entry_id: 'e1',
            reason: 'modified',
            modified_by: 'Artur',
            anchor_text: 'foo',
            target_entry_id: 'bare-id-fallback',
        });
        expect(out).toContain('→ <em>bare-id-fallback</em>');
    });

    it('does not crash on null row', () => {
        const vm = buildVm();
        expect(vm.formatSkipReason(null)).toBe('');
        expect(vm.formatSkipReason(undefined)).toBe('');
    });
});
