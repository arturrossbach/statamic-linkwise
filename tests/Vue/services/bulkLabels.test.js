import { describe, it, expect } from 'vitest';
import { completionLabel, completionVariant } from '@/services/bulkLabels.js';

/**
 * Characterisation tests for `bulkLabels.completionLabel` + `completionVariant`,
 * with a specific focus on the **applyrule Skip-Branch** added 2026-05-17
 * for [[architectural_health]] Klasse 9b.
 *
 * ## Background
 *
 * User-Smoke 2026-05-17 on the Auto-Linking Tab: user opened Preview for
 * a rule covering 4 entries, then edited one of those entries in another
 * tab. On Apply, the backend's per-record `verifyHashes` skipped the
 * modified entry and the toast showed "3 link(s) added" — no skip
 * mention. Same pattern as bulkunlink/urlchanger/detailunlink which
 * DO append a "X skipped (reason)" suffix; applyrule was the outlier.
 *
 * ## What's pinned
 *
 * - `completionLabel('applyrule', 'done', extra)` for the four corners:
 *   `(n>0 | n=0) × (conflicts>0 | conflicts=0)`. Plus the same matrix
 *   for the multi-rule branch (`extra.total_rules > 1`).
 * - `completionVariant('applyrule', ...)` stays `'success'` when at
 *   least one link was added even with conflicts — same convention as
 *   bulkunlink/urlchanger (user chose Empfehlung A in the consultation).
 *
 * ## Why `conflicts_skipped` not `entries_skipped`
 *
 * `entries_skipped` (engine-internal counter) covers no-anchor, already-
 * linked, self-referencing skips across the FULL index. For a rule that
 * touches 4 of 100 entries it reads "96 skipped" — meaningless to the
 * user. `conflicts_skipped` is the user-actionable bucket (Hash-Conflict
 * only) added to ApplyRuleCommand's terminal status writes in the same
 * PR. See ApplyRuleCommand.php:256 + :513.
 */
describe('completionLabel("applyrule", "done", …)', () => {
    // ── Single-rule branch ─────────────────────────────────────────────

    it('single-rule: links added, no conflicts → plain success copy', () => {
        const msg = completionLabel('applyrule', 'done', {
            links_added: 3,
            rule_keyword: 'Datenbank-Indexstrategie',
            conflicts_skipped: 0,
        });
        expect(msg).toBe('3 link(s) added for "Datenbank-Indexstrategie".');
    });

    it('single-rule: links added + conflicts → suffix carries the reason', () => {
        // The User-Smoke 2026-05-17 scenario: 4 entries previewed,
        // 1 modified mid-preview, Apply → 3 linked + 1 skipped.
        const msg = completionLabel('applyrule', 'done', {
            links_added: 3,
            rule_keyword: 'Datenbank-Indexstrategie',
            conflicts_skipped: 1,
        });
        expect(msg).toBe(
            '3 link(s) added for "Datenbank-Indexstrategie", '
            + '1 skipped (entry was modified by another editor).',
        );
    });

    it('single-rule: 0 links added, conflicts present → "No new links" + suffix', () => {
        // Edge case: every previewed entry got skipped because every
        // one was edited mid-preview.
        const msg = completionLabel('applyrule', 'done', {
            links_added: 0,
            rule_keyword: 'Datenbank-Indexstrategie',
            conflicts_skipped: 2,
        });
        expect(msg).toBe(
            'No new links to add for "Datenbank-Indexstrategie", '
            + '2 skipped (entry was modified by another editor).',
        );
    });

    it('single-rule: 0 links + 0 conflicts → unchanged from pre-fix copy', () => {
        // Backwards-compat: existing "Nothing to link" UX preserved.
        const msg = completionLabel('applyrule', 'done', {
            links_added: 0,
            rule_keyword: 'Datenbank-Indexstrategie',
            conflicts_skipped: 0,
        });
        expect(msg).toBe('No new links to add for "Datenbank-Indexstrategie".');
    });

    it('single-rule: missing rule_keyword falls back to keyword-less copy', () => {
        const msg = completionLabel('applyrule', 'done', {
            links_added: 5,
            conflicts_skipped: 0,
        });
        expect(msg).toBe('5 link(s) added.');
    });

    it('single-rule: backwards-compat when status pre-dates the field', () => {
        // Old cache snapshots written by the previous ApplyRuleCommand
        // (no conflicts_skipped field). `|| 0` → no suffix, identical
        // to old behaviour.
        const msg = completionLabel('applyrule', 'done', {
            links_added: 3,
            rule_keyword: 'Soba',
            // conflicts_skipped intentionally absent
        });
        expect(msg).toBe('3 link(s) added for "Soba".');
    });

    // ── Multi-rule branch (Apply Selected) ─────────────────────────────

    it('multi-rule: links added, no conflicts → plain success copy', () => {
        const msg = completionLabel('applyrule', 'done', {
            total_rules: 3,
            total_links_added: 8,
            conflicts_skipped: 0,
        });
        expect(msg).toBe('8 link(s) added across 3 rule(s).');
    });

    it('multi-rule: links added + conflicts → suffix carries the reason', () => {
        const msg = completionLabel('applyrule', 'done', {
            total_rules: 3,
            total_links_added: 8,
            conflicts_skipped: 2,
        });
        expect(msg).toBe(
            '8 link(s) added across 3 rule(s), '
            + '2 skipped (entry was modified by another editor).',
        );
    });

    it('multi-rule: 0 links + conflicts → "No new links" + suffix', () => {
        const msg = completionLabel('applyrule', 'done', {
            total_rules: 3,
            total_links_added: 0,
            conflicts_skipped: 4,
        });
        expect(msg).toBe(
            'No new links to add for 3 rule(s), '
            + '4 skipped (entry was modified by another editor).',
        );
    });
});

describe('completionVariant("applyrule", "done", …)', () => {
    // Empfehlung A from the consultation: stay green when at least one
    // link was added, even with conflicts. Same convention as bulkunlink
    // / urlchanger / detailunlink — keeps UX symmetric across kinds.

    it('partial-success (n>0 + conflicts>0) → "success" (green)', () => {
        const v = completionVariant('applyrule', 'done', {
            links_added: 3,
            conflicts_skipped: 1,
        });
        expect(v).toBe('success');
    });

    it('all-success (n>0 + conflicts=0) → "success"', () => {
        const v = completionVariant('applyrule', 'done', {
            links_added: 3,
            conflicts_skipped: 0,
        });
        expect(v).toBe('success');
    });

    it('all-skipped (n=0 + conflicts>0) → "info" (current behaviour, gap documented)', () => {
        // Inherits the cross-kind `succeeded===0 && skipped>0` branch.
        // Note: completionVariant maps `links_added → succeeded` fallback
        // already (line ~215 in bulkLabels.js). conflicts_skipped is NOT
        // in the `skipped` fallback chain by design — keeping the variant
        // logic Hash-Conflict-agnostic. Currently this case maps via the
        // applyrule-specific `succeeded === 0 → info` branch.
        const v = completionVariant('applyrule', 'done', {
            links_added: 0,
            conflicts_skipped: 2,
        });
        // Documents current behaviour: applyrule's `succeeded===0`
        // branch wins → 'info'. If a future change wants to promote
        // all-skipped-due-to-conflicts to 'warning', this test fails
        // loudly and the author re-evaluates.
        expect(v).toBe('info');
    });

    it('nothing-to-link (n=0 + conflicts=0) → "info"', () => {
        const v = completionVariant('applyrule', 'done', {
            links_added: 0,
            conflicts_skipped: 0,
        });
        expect(v).toBe('info');
    });
});
