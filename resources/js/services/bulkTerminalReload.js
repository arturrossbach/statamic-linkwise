/**
 * Terminal-bulk reload decision helper.
 *
 * Extracted from `LinkwiseLayout.vue::pollBulkStatusOnce` in Sprint 5 PR 3b.
 *
 * # Why this exists
 *
 * Three independent `if (...) reload()` branches sit at the bottom of
 * pollBulkStatusOnce, all guarded by `seenRunning[kind]` to prevent
 * infinite reload loops when a stale 'done' status from a previous
 * session (cache TTL 300s) gets observed. The truth-table is small but
 * the side-effect mix (window vs Inertia) and the guard rationale make
 * it worth lifting into a pure decision function with tests.
 *
 * Recent commits that touched this truth-table:
 *
 *   37fc989  toast + completion banner survive after add-link
 *            (switched outbound/inbound from window.location.reload
 *            to inertiaRouter.reload — hard-reload was killing the
 *            success toast mid-render)
 *
 * # Contract
 *
 * Pure: no DOM, no Inertia, no mutation. Inputs in, action string out.
 * The caller (`pollBulkStatusOnce`) is responsible for:
 *   - performing the actual reload (`window.location.reload()` or
 *     `inertiaRouter.reload({ preserveState, preserveScroll })`)
 *   - clearing `seenRunning[kind]` before reload so a re-poll of the
 *     same terminal status doesn't fire a second reload
 */

/**
 * Decide whether (and how) to reload after a terminal bulk completion.
 *
 * Three reload branches in current Linkwise:
 *
 *   - `scan` done → full window reload — fresh entries data needed,
 *                   index_built_at + summary props all need to
 *                   re-fetch from the server.
 *   - `check` done → full window reload — staleCheck.is_stale is
 *                    computed server-side; only a fresh page load
 *                    re-evaluates the "Recent edits..." banner.
 *   - `inboundinsert` / `outboundinsert` done → partial Inertia reload
 *                                               — entries table needs
 *                                               new outbound/inbound
 *                                               counts, but a hard
 *                                               reload killed the
 *                                               success toast mid-tick
 *                                               (real bug 2026-05-11).
 *
 * All three are guarded by `seenRunning[kind] === true` so a stale
 * cached 'done' from a previous session can't trigger an infinite
 * reload loop on first poll.
 *
 * Other kinds (applyrule, urlchanger, bulkunlink, detailunlink, ...) all
 * return 'none' — they're handled entirely in-place by recordCompletion
 * + the banner. No page reload needed.
 *
 * @param {object} status - Terminal bulk-status payload.
 * @param {string} status.kind
 * @param {string} status.phase - Reload only fires on 'done' (cancelled/
 *                                error don't refresh data).
 * @param {object} seenRunning - The layout's per-kind observed-running
 *                               flags. Reload only fires when this
 *                               instance saw the bulk start (otherwise
 *                               a stale cached 'done' would loop-reload).
 * @returns {'full'|'partial'|'none'} What reload (if any) the caller
 *                                    should perform.
 */
export function pickTerminalReload(status, seenRunning) {
    if (status.phase !== 'done') return 'none';

    const k = status.kind;

    // scan + check need a hard reload — server-side props
    // (summary stats, staleCheck, index_built_at) only refresh on
    // a fresh page load.
    if (k === 'scan' && seenRunning?.scan) return 'full';
    if (k === 'check' && seenRunning?.check) return 'full';

    // outbound/inbound inserts use Inertia partial reload so the
    // success toast survives the render-tick. Real bug 2026-05-11
    // showed window.location.reload() killed it.
    if ((k === 'inboundinsert' || k === 'outboundinsert') && seenRunning?.[k]) {
        return 'partial';
    }

    return 'none';
}
