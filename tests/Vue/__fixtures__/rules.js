/**
 * Shared rule fixture for Vitest pin / characterisation tests.
 *
 * Extracted in Sprint 5 PR 2e-prep (flagged in PR 2d) — the same
 * `mockRule` shape gets reused across PR 2e (RulePreviewModal pins) and the
 * upcoming LinksReport / BrokenLinks / Activity tab splits. Inlining the
 * 18-field literal per test file drifts; one canonical fixture doesn't.
 *
 * Numbers are chosen so wouldLinkForRule(mockRule) === 6
 * (match_count 10 − linked_count 2 − linked_elsewhere_count 1
 *  − not_insertable_count 1). Several pins assert on the `6` rendered in
 * the row's "Will link" column, so don't drift these without updating tests.
 *
 * Usage:
 *   import { mockRule, makeRule } from '../__fixtures__/rules.js';
 *
 * Tests that need variants should pass overrides to `makeRule({...})`
 * rather than mutating the shared `mockRule` constant.
 */

// Computed at module load — always 5 seconds before "now" so
// formatRelativeTime() (defined in RuleListTable.vue) consistently returns
// "just now" (its < 10s branch). Without this the original hard-coded
// '2026-05-15T12:00:00Z' aged into "2 hours ago" by the time the test
// suite ran later in the same day — a real flake observed 2026-05-15.
const RECENT_ISO = new Date(Date.now() - 5000).toISOString();

export const mockRule = Object.freeze({
    id: 'r1',
    keyword: 'laravel',
    url: 'https://laravel.com',
    target_entry_id: null,
    match_count: 10,
    linked_count: 2,
    linked_elsewhere_count: 1,
    not_insertable_count: 1,
    last_applied_at: RECENT_ISO,
    last_applied_links_added: 4,
    case_sensitive: false,
    skip_if_exists: false,
    once_per_post: true,
    auto_apply_on_save: 'follow_global',
    collections: [],
    active: true,
});

/**
 * Builder for rule variants. Spreads the canonical fixture, then applies
 * overrides — callers can flip a single field (`active: false`) without
 * re-declaring the whole 18-property literal.
 */
export function makeRule(overrides = {}) {
    return { ...mockRule, ...overrides };
}
