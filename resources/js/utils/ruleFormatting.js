/**
 * Rule-table formatting + computation helpers — pure functions.
 *
 * Extracted from AutoLinkingTab.vue during Sprint 5 PR 2d-prep as the
 * helper-utils Vorbau for the upcoming RuleListTable.vue split (analog
 * to PR #24's formDirty.js extract before PR 2c's RuleForm extract).
 *
 * These are all pure: no `this.*` access, no Vue reactivity coupling
 * (compare formatRelativeTime which reads `this.nowTick` and therefore
 * stays as a component method — moving it would break the minute-tick
 * recompute). Pure helpers can be imported by both AutoLinkingTab and
 * the future RuleListTable sub-component without a method-prop bridge.
 */

/**
 * Truncates a URL to ~50 chars for the rule table's "Link Target" column.
 * Plays defensively with browser tooltips: caller wraps in v-tooltip
 * with the full URL so the user can see the actual destination on hover.
 */
export function truncateUrl(url) {
    return url.length > 50 ? url.substring(0, 47) + '...' : url;
}

/**
 * Maps the auto-apply tri-state to a short human-readable label for
 * the Settings column. Falls back to 'Follow global' for any unknown
 * value (safest default — defers to the global toggle).
 */
export function formatAutoApply(value) {
    return {
        always: 'Always',
        never: 'Never',
        follow_global: 'Follow global',
    }[value] || 'Follow global';
}

/**
 * Coerces a backend value to the auto-apply tri-state. Backwards-compat
 * for old rules that stored a bool: true → 'follow_global' (the new
 * default — was the implicit "yes apply" before tri-state landed),
 * false → 'never'. Anything unrecognized → 'follow_global'.
 */
export function normalizeAutoApply(value) {
    if (value === true) return 'follow_global';
    if (value === false) return 'never';
    if (['follow_global', 'always', 'never'].includes(value)) return value;
    return 'follow_global';
}

/**
 * Formats an ISO timestamp as the local long-form date for the
 * "Last applied" tooltip. Empty string for null/undefined input.
 *
 * For unparseable input, modern V8 returns 'Invalid Date' from
 * `new Date('garbage').toLocaleString()` — no throw. The try/catch
 * is defensive belt-and-braces for engines that *do* throw (older
 * Safari with certain locale args, historical edge cases); on catch
 * we surface the raw input rather than crash the tooltip render.
 */
export function formatExactDate(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

/**
 * Entries where this rule would insert a NEW link right now.
 * = match_count − linked-to-target − linked-elsewhere − not-insertable.
 *
 * Used in three places: the rule-table "Will link" cell, the Apply
 * Confirmation summary (`selectedActive.reduce`), and the sorted-rules
 * comparator when the user sorts by Will-link. Stays in sync because
 * all three call this single function.
 */
export function wouldLinkForRule(rule) {
    const total = rule.match_count || 0;
    const toTarget = rule.linked_count || 0;
    const elsewhere = rule.linked_elsewhere_count || 0;
    const notInsertable = rule.not_insertable_count || 0;
    return Math.max(0, total - toTarget - elsewhere - notInsertable);
}
