/**
 * Bulk-operation completion-signature + stale-detection helpers.
 *
 * Extracted from `LinkwiseLayout.vue::pollBulkStatusOnce` in Sprint 5 PR 3a.
 *
 * # Why this exists
 *
 * Five+ documented production bugs in the last month all rooted in the same
 * spot: the kind-specific signature string used to dedup completion toasts.
 * When two consecutive runs of the same bulk produce identical signatures,
 * sessionStorage suppresses the second toast — the user sees the progress
 * banner blink, then nothing.
 *
 * Recent commit history (see `git log` against `LinkwiseLayout.vue`):
 *
 *   8f30b2d  JobLock::snapshot: pick latest terminal by heartbeat
 *   5b7099c  suppress toast for completions older than 60s
 *   ccb4822  persistent banner reliable across all bulk completions
 *   37fc989  toast + completion banner survive after add-link
 *   00692c2  Outbound/Inbound toast dedup: include heartbeat in signature
 *
 * Lifting the truth-table into a pure function makes the regression cases
 * directly testable (`tests/Vue/services/bulkSignature.test.js` pins the
 * six kind-branches with a fixture-per-bug-case) — vs the inline form
 * which only got caught when a real user hit it in production.
 *
 * # Contract
 *
 * Pure: no `bulkState`, no DOM, no fetch. Inputs in, string out. The caller
 * (`pollBulkStatusOnce`) handles `sessionStorage` dedup + `setHeavyState` +
 * `recordCompletion`.
 */

/**
 * Build a content-based dedup signature for a terminal bulk-status record.
 *
 * The signature is what the layout writes to sessionStorage as
 * `linkwise:bulkToastShown`. Two consecutive identical signatures =>
 * second toast/banner suppressed.
 *
 * Per-kind branches encode "what makes one run distinct from another":
 *
 *   - applyrule:           multi-rule total + total_links_added
 *   - urlchanger:          action + succeeded/skipped
 *   - detailunlink:        source_mode + succeeded/skipped + heartbeat
 *   - bulkunlink:          succeeded/skipped + heartbeat
 *   - outboundinsert:      succeeded/skipped + heartbeat
 *   - inboundinsert:       succeeded/skipped + heartbeat
 *
 * Heartbeat is the critical piece for the three insert/unlink-like kinds —
 * without it, two back-to-back runs with identical succeeded/skipped numbers
 * (very common: skipped:1 from anchor-not-found over and over) produce the
 * SAME signature and the second toast gets suppressed. Real bug 2026-05-10.
 *
 * Unknown kinds fall through to the base signature only — that's intentional,
 * we'd rather show a duplicate toast for an unknown kind than swallow a real
 * one. The branch is a safety property: known kinds must add their distinct
 * extras; new kinds must opt in by adding a branch.
 *
 * @param {object} status - The terminal bulk-status payload from the server.
 * @param {string} status.kind - 'applyrule' | 'urlchanger' | 'detailunlink'
 *                               | 'bulkunlink' | 'outboundinsert'
 *                               | 'inboundinsert' | 'scan' | 'check' | ...
 * @param {string} [status.phase='idle'] - 'done' | 'cancelled' | 'error' | ...
 * @param {number} [status.current=0]
 * @param {number} [status.total=0]
 * @param {string} [status.message='']
 * @param {object} [status.extra={}] - kind-specific payload (total_rules,
 *                                     succeeded, skipped, heartbeat, ...)
 * @returns {string} Signature string suitable for sessionStorage dedup.
 */
export function buildCompletionSignature(status) {
    const phase = status.phase || 'idle';
    const tExtra = status.extra || {};

    let kindSig = '';
    if (status.kind === 'applyrule') {
        // Multi-rule applies always end with current=total=0 (because the
        // top-level counters are reset between rules) — without these
        // kind-specific extras every multi-rule run gets the SAME base
        // signature `applyrule:done:0:0:` and dedup blocks every run after
        // the first.
        kindSig = `:r${tExtra.total_rules || 0}:la${tExtra.total_links_added || tExtra.links_added || 0}`;
    } else if (status.kind === 'urlchanger') {
        kindSig = `:a${tExtra.action || ''}:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}`;
    } else if (status.kind === 'detailunlink') {
        // heartbeat makes repeated identical-outcome runs unique — without it
        // two consecutive "5 removed / 0 skipped" bulks produced the same
        // signature and the second recordCompletion was dedup-suppressed,
        // so the user saw NO persistent success banner after the second
        // remove. Real bug 2026-05-11.
        kindSig = `:m${tExtra.source_mode || ''}:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}:hb${tExtra.heartbeat || tExtra.started_by_id || ''}`;
    } else if (status.kind === 'bulkunlink') {
        // Same uniqueness fix as detailunlink — heartbeat in the signature
        // so back-to-back identical-outcome bulks each fire their own
        // toast + banner.
        kindSig = `:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}:hb${tExtra.heartbeat || tExtra.started_by_id || ''}`;
    } else if (status.kind === 'outboundinsert' || status.kind === 'inboundinsert') {
        // Without this branch, repeated outbound/inbound inserts with
        // identical succeeded/skipped numbers (very common case:
        // skipped:1 from anchor-not-found over and over) produced the
        // SAME signature and the second + every subsequent toast got
        // dedup-suppressed. User saw the banner blink and nothing else.
        // Real bug 2026-05-10.
        // started_by_id makes the signature unique per session/user;
        // combined with heartbeat (a per-run timestamp) every actual run
        // gets a fresh signature.
        kindSig = `:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}:hb${tExtra.heartbeat || tExtra.started_by_id || ''}`;
    }

    return `${status.kind}:${phase}:${status.current}:${status.total}:${status.message || ''}${kindSig}`;
}

/**
 * Stale-completion guard. True when the bulk's done-status was written more
 * than 60s before the client polled it — meaning the user tabbed away mid-
 * bulk and just came back. The persistent banner still hydrates so they
 * have a recap; only the transient toast + auto-open of the notifications
 * disclosure get suppressed (stale "success!" toast for a 10-minute-old
 * action surprised users in testing).
 *
 * Missing heartbeat (legacy status from before heartbeat was added
 * everywhere) → treat as STALE. Otherwise an old pre-heartbeat 'done' cache
 * entry sitting around could fire a fresh-looking toast on first poll.
 * Real bug observed 2026-05-11 with a legacy `scan` terminal cache.
 *
 * Small clock drift between server (which sets heartbeat) and client
 * (which reads `nowSec`) is absorbed by the 60s threshold.
 *
 * @param {number|null|undefined} heartbeat - Server-side Unix-seconds
 *                                            timestamp written with the
 *                                            terminal status.
 * @param {number} nowSec - Client's current Unix-seconds (Date.now()/1000).
 *                          Parameterised so tests don't need to mock Date.
 * @returns {boolean} True when the completion should be treated as stale.
 */
export function isCompletionStale(heartbeat, nowSec) {
    if (!heartbeat) return true;
    const ageSec = Math.max(0, nowSec - heartbeat);
    return ageSec > 60;
}
