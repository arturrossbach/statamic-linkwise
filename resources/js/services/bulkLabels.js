/**
 * Single source of truth for bulk-operation user-facing strings.
 *
 * Linkwise renders the same kind→label mapping in 4 different places —
 * the active-bulk banner, the persistent completion banner, the recovery
 * banner after a mid-operation reload, and the terminal toast. Before
 * this module they were 4 copy-pasted switch statements in LinkwiseLayout
 * (~300 LOC of duplicated string-building); a copy-edit in one rarely
 * propagated to the other three, and the per-place divergence (different
 * verb tense, slightly different field names) made label drift inevitable.
 *
 * Each export is a pure function — no Vue, no Statamic, no DOM. Trivially
 * inspectable, trivially testable once Vitest lands.
 *
 * Kinds mirror JobLock::JOBS plus the Activity-Log revert flows:
 *   scan, check, bulkunlink, applyrule, urlchanger,
 *   detailunlink, inboundinsert, outboundinsert
 */

/**
 * Pick the most-frequent error reason from an {message: count} map.
 * Surfaced in completion labels + toasts so users see WHY items were
 * skipped instead of guessing. Returns '' for empty input.
 */
export function topErrorReason(errors) {
    if (!errors || typeof errors !== 'object') return '';
    const entries = Object.entries(errors);
    if (entries.length === 0) return '';
    entries.sort((a, b) => (b[1] || 0) - (a[1] || 0));
    return entries[0][0] || '';
}

/**
 * Active-bulk banner label — what's running RIGHT NOW.
 * "Adding inbound links to "Cookie Recipe""
 *
 * @param {string} kind                 JobLock kind key
 * @param {object} ctx                  Per-kind context (entryTitle, ruleKeyword, mode, ...)
 * @returns {string}
 */
export function activeLabel(kind, ctx = {}) {
    const title = ctx.entryTitle ? `"${ctx.entryTitle}"` : '';

    switch (kind) {
        case 'inboundinsert':
            return title ? `Adding inbound links to ${title}` : 'Adding inbound links';
        case 'outboundinsert':
            return title ? `Adding outbound links from ${title}` : 'Adding outbound links';
        case 'detail-unlink':
            // Light client-side variant — light dispatch uses 'mode'.
            if (ctx.mode === 'inbound') return title ? `Removing inbound links to ${title}` : 'Removing inbound links';
            if (ctx.mode === 'outbound') return title ? `Removing outbound links from ${title}` : 'Removing outbound links';
            return 'Removing links';
        case 'detailunlink': {
            // Heavy server-side variant — uses sourceMode + always carries title.
            const tStr = ctx.entryTitle ? `"${ctx.entryTitle}"` : '';
            const verb = ctx.sourceMode === 'outbound'
                ? `Removing outbound links from ${tStr}`
                : `Removing inbound links to ${tStr}`;
            return ctx.startedBy ? `${verb.trim()} (by ${ctx.startedBy})` : verb.trim();
        }
        case 'scan':
            return 'Scanning content';
        case 'check':
            return 'Checking links';
        case 'bulkunlink':
            return 'Removing broken links';
        case 'applyrule': {
            if (ctx.totalRules && ctx.totalRules > 1) {
                const inner = ctx.ruleKeyword ? `"${ctx.ruleKeyword}"` : '...';
                return `Applying rule ${inner} (${ctx.ruleIndex || 1} of ${ctx.totalRules})`;
            }
            return ctx.ruleKeyword
                ? `Applying rule "${ctx.ruleKeyword}"`
                : 'Applying auto-link rule';
        }
        case 'urlchanger': {
            const action = ctx.action || 'apply';
            const verb = action === 'unlink' ? 'Unlinking' : 'Replacing';
            const base = ctx.search
                ? `${verb} URLs matching "${ctx.search}"`
                : `${verb} URLs`;
            return ctx.startedBy ? `${base} (by ${ctx.startedBy})` : base;
        }
        default:
            return 'Working';
    }
}

/**
 * Recovery-banner label — "what was running when the page reloaded".
 * Shorter than activeLabel because we don't have live context (kind
 * + ruleKeyword + search are all we persist), and the state is past-
 * tense by the time this renders.
 */
export function shortLabel(kind, ctx = {}) {
    switch (kind) {
        case 'inboundinsert': return 'Adding inbound links';
        case 'outboundinsert': return 'Adding outbound links';
        case 'detail-unlink': return 'Removing links';
        case 'scan': return 'Scanning content';
        case 'check': return 'Checking links';
        case 'bulkunlink': return 'Removing broken links';
        case 'applyrule': return ctx.ruleKeyword ? `Applying rule "${ctx.ruleKeyword}"` : 'Applying auto-link rule';
        case 'urlchanger': {
            const action = ctx.action || 'apply';
            const verb = action === 'unlink' ? 'Unlinking' : 'Replacing';
            return ctx.search ? `${verb} URLs matching "${ctx.search}"` : `${verb} URLs`;
        }
        default: return ctx.label || 'Bulk operation';
    }
}

/**
 * Persistent completion banner label — past-tense summary of what
 * happened. Includes counts + skip reason. Driven from `extra` payload
 * fields populated by each command's terminal status write.
 *
 * @param {string} kind
 * @param {string} phase   'done' | 'cancelled' | 'error'
 * @param {object} extra   command-specific terminal payload
 * @param {string} fallbackLabel  used for cancelled/error/default branches
 * @returns {string}
 */
export function completionLabel(kind, phase, extra = {}, fallbackLabel = 'Operation') {
    if (phase === 'cancelled') {
        return `${fallbackLabel} cancelled.`;
    }
    if (phase === 'error') {
        return extra.message || `${fallbackLabel} failed.`;
    }

    // phase === 'done'
    if (kind === 'scan') {
        return `Scanned ${extra.entries_count || 0} entries in ${extra.duration || 0}s.`;
    }
    if (kind === 'check') {
        return 'Broken-link check complete.';
    }
    if (kind === 'applyrule') {
        // `conflicts_skipped` is the USER-ACTIONABLE skip bucket: only
        // Hash-Conflict skips (entry was edited while Preview was open).
        // The other bucket — `entries_skipped` — covers engine-internal
        // skips (no-anchor / already-linked / self-referencing) over the
        // FULL index and would read "96 skipped" for a rule that only
        // touched 4 of 100 entries — meaningless to the user. See
        // [[architectural_health]] Klasse 9b. Field added 2026-05-17 by
        // ApplyRuleCommand single + multi terminal-status writes.
        const conflicts = extra.conflicts_skipped || 0;
        const conflictSuffix = conflicts > 0
            ? `, ${conflicts} skipped (entry was modified by another editor)`
            : '';
        if (extra.total_rules && extra.total_rules > 1) {
            const total = extra.total_links_added || 0;
            return total > 0
                ? `${total} link(s) added across ${extra.total_rules} rule(s)${conflictSuffix}.`
                : `No new links to add for ${extra.total_rules} rule(s)${conflictSuffix}.`;
        }
        const n = extra.links_added || 0;
        const kw = extra.rule_keyword ? ` for "${extra.rule_keyword}"` : '';
        return n > 0
            ? `${n} link(s) added${kw}${conflictSuffix}.`
            : `No new links to add${kw}${conflictSuffix}.`;
    }
    if (kind === 'bulkunlink') {
        const n = extra.succeeded || 0;
        const skipped = extra.skipped || 0;
        const reason = topErrorReason(extra.errors);
        if (n > 0 && skipped === 0) return `${n} link(s) removed.`;
        if (n > 0) return `${n} link(s) removed, ${skipped} skipped${reason ? ` (${reason})` : ''}.`;
        return `Could not remove any links — ${skipped} skipped${reason ? ` (${reason})` : ''}.`;
    }
    if (kind === 'urlchanger') {
        const n = extra.succeeded || 0;
        const skipped = extra.skipped || 0;
        const verb = extra.action === 'unlink' ? 'unlinked' : 'replaced';
        const reason = topErrorReason(extra.errors);
        if (n > 0 && skipped === 0) return `${n} URL(s) ${verb}.`;
        if (n > 0) return `${n} URL(s) ${verb}, ${skipped} skipped${reason ? ` (${reason})` : ''}.`;
        return `Could not ${extra.action || 'change'} any URLs — ${skipped} skipped${reason ? ` (${reason})` : ''}.`;
    }
    if (kind === 'detailunlink') {
        const n = extra.succeeded || 0;
        const skipped = extra.skipped || 0;
        const direction = extra.source_mode === 'outbound' ? 'outbound' : 'inbound';
        const t = extra.entry_title ? ` for "${extra.entry_title}"` : '';
        const reason = topErrorReason(extra.errors);
        if (n > 0 && skipped === 0) return `${n} ${direction} link(s) removed${t}.`;
        if (n > 0) return `${n} ${direction} link(s) removed${t}, ${skipped} skipped${reason ? ` (${reason})` : ''}.`;
        const topReason = reason || 'links not found in entry content';
        return `Could not remove any ${direction} links${t} — ${topReason}.`;
    }
    if (kind === 'inboundinsert' || kind === 'outboundinsert') {
        const direction = kind === 'inboundinsert' ? 'inbound' : 'outbound';
        const n = extra.succeeded || 0;
        const skipped = extra.skipped || 0;
        const t = extra.entry_title ? ` for "${extra.entry_title}"` : '';
        if (n > 0 && skipped === 0) return `${n} ${direction} link(s) added${t}.`;
        if (n > 0) return `${n} ${direction} link(s) added${t}, ${skipped} skipped (anchor not found — re-scan content if recently edited).`;
        const reason = topErrorReason(extra.errors) || 'anchor text not found';
        return `Could not add any ${direction} links${t} — ${reason}. Re-scan content and retry.`;
    }
    return `${fallbackLabel} complete.`;
}

/**
 * Terminal-toast variant signal — drives Statamic.$toast.success/info/error
 * AND the persistent banner's color (success / warning / error).
 *
 * @returns {'success' | 'info' | 'warning' | 'error'}
 */
export function completionVariant(kind, phase, extra = {}) {
    if (phase === 'error') return 'error';
    if (phase === 'cancelled') return 'warning';
    // phase === 'done'
    const skipped = extra.skipped || 0;
    const succeeded = extra.succeeded ?? extra.links_added ?? extra.total_links_added ?? 0;
    if (succeeded === 0 && skipped > 0) return 'warning';
    // applyrule "no new links to add" is informational, not failure.
    if (kind === 'applyrule' && succeeded === 0) return 'info';
    return 'success';
}
