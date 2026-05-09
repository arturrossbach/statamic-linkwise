import { reactive } from 'vue';
import { errorToast, warnToast } from '../utils/toast.js';
import { readJson, writeJson, remove as removeStorage } from '../utils/safeStorage.js';

/**
 * Lightweight bulk-operation service for Linkwise.
 *
 * Why this exists:
 * Bulk inserts/unlinks ran inside Vue components as `for (...) await fetch(...)`.
 * Inertia tab navigation unmounts the page component, which used to break the
 * loop and leave the user with a partial result. Moving the loop into a JS
 * module survives that — Inertia preserves the module scope across navigations.
 *
 * Trade-offs vs a full backend job (BulkUnlinkCommand pattern):
 *   ✓ survives Inertia tab switches and modal closes
 *   ✓ ~80 LOC, no backend changes
 *   ✗ does NOT survive a full browser reload, tab close, or backend crash
 *
 * Operations covered: bulk-insert (inbound + outbound suggestions),
 * bulk-unlink in the DetailModal. Existing heavy jobs (scan, check-links,
 * bulk-unlink in Broken Links, apply-rule) keep their detached-artisan
 * pattern — they're truly long-running and need reload-resilience.
 */

/**
 * Reactive store. Single source of truth for "is any bulk operation running
 * anywhere in Linkwise". Both light (this module) and heavy (artisan jobs,
 * polled by LinkwiseLayout) write into it. Components bind their banner +
 * disable-on-busy logic to `bulkState.active`.
 *
 *   bulkState.active = null  → nothing is running
 *   bulkState.active = {
 *     source: 'light' | 'heavy',
 *     kind:   stable id ('inbound-insert', 'scan', 'applyrule', ...),
 *     label:  human label
 *     current/total: progress
 *     cancelUrl: heavy only — server endpoint to call on cancel
 *     canCancel: bool
 *   }
 */
export const bulkState = reactive({
    active: null,
    // Persistent completion-banner snapshot. Also mirrored to sessionStorage
    // so the recap survives reloads, but the reactive copy here is what
    // drives LinkwiseLayout's banner — so a child component (e.g.
    // LinksReportTab's inbound-bulk-add) can call recordCompletion() and
    // the layout updates immediately without a remount.
    lastCompletion: null,
});

/**
 * sessionStorage key for the interrupted-bulk recovery record. Persists across
 * page reloads (but not browser-tab close), so the user gets a "your previous
 * bulk was interrupted at X / Y" banner if they reload mid-run.
 *
 * Cleared when:
 *   - bulk completes (success, partial, error — but ran to the end)
 *   - user dismisses the recovery banner via clearInterruptedBulk()
 *
 * NOT cleared on cancel (user wants to know they cancelled at X/Y) — the
 * runtime cancel toast still fires, plus the recovery banner aids review.
 */
const RECOVERY_KEY = 'linkwise.bulk.recovery';

function writeRecovery(snapshot) {
    writeJson(RECOVERY_KEY, snapshot);
}

function clearRecovery() {
    removeStorage(RECOVERY_KEY);
}

/**
 * Read any persisted interrupted-bulk record. Called by LinkwiseLayout on
 * mount. Returns null when nothing's pending or when the record is older than
 * 30 minutes (stale, probably from a previous unrelated session).
 */
export function getInterruptedBulk() {
    const data = readJson(RECOVERY_KEY);
    if (!data) return null;
    const ageMs = Date.now() - (data.startedAt || 0);
    if (ageMs > 30 * 60 * 1000) {
        clearRecovery();
        return null;
    }
    return data;
}

export function clearInterruptedBulk() {
    clearRecovery();
}

/**
 * sessionStorage key for the last-completed-bulk result. Drives a persistent
 * dismissible banner so the user gets the outcome even if they were brewing
 * coffee when the toast expired.
 *
 * Cleared when:
 *   - user dismisses via clearLastCompletion()
 *   - a NEW bulk of the same kind completes (overwrites)
 */
const LAST_COMPLETION_KEY = 'linkwise:bulk:lastCompletion';

/**
 * Record a completed (or cancelled / errored) bulk so the layout can show a
 * persistent banner with the result. Called by the layout poller from its
 * fireTerminalToast path.
 */
export function recordCompletion(snapshot) {
    const record = {
        ...snapshot,
        recordedAt: Date.now(),
    };
    // Reactive copy first — drives the layout banner immediately, even if a
    // child component (LinksReportTab) made the call. sessionStorage
    // persistence is best-effort: if Quota/Private Mode rejects the write,
    // the reactive copy still works for the current page; recap won't
    // survive reload but the banner DOES show. Banner is a UX bonus,
    // not load-bearing.
    bulkState.lastCompletion = record;
    writeJson(LAST_COMPLETION_KEY, record);
}

export function getLastCompletion() {
    // Prefer the reactive in-memory copy (set by recordCompletion). Fall
    // back to sessionStorage on first access (e.g. after a page reload
    // when the reactive state is fresh but sessionStorage holds the
    // last bulk's record). Hydrating bulkState.lastCompletion on this
    // first read means subsequent reactive consumers see the same value.
    if (bulkState.lastCompletion) {
        return bulkState.lastCompletion;
    }
    const data = readJson(LAST_COMPLETION_KEY);
    if (!data) return null;
    // Auto-expire after 1 hour to avoid stale "yesterday's bulk" banners.
    const ageMs = Date.now() - (data.recordedAt || 0);
    if (ageMs > 60 * 60 * 1000) {
        removeStorage(LAST_COMPLETION_KEY);
        return null;
    }
    bulkState.lastCompletion = data;
    return data;
}

export function clearLastCompletion() {
    bulkState.lastCompletion = null;
    removeStorage(LAST_COMPLETION_KEY);
}

// Backwards-compat alias used by older imports — same reactive object.
const state = bulkState;

// Cancel flag for the in-flight LIGHT loop. Heavy jobs cancel via their own
// server endpoint, addressed in cancelActive() below.
let lightCancelRequested = false;

let beforeUnloadAttached = false;

function attachBeforeUnloadGuard() {
    if (beforeUnloadAttached) return;
    beforeUnloadAttached = true;
    window.addEventListener('beforeunload', (e) => {
        if (!state.active) return;
        // Browsers ignore the message text since 2017 but require returnValue
        // to be set in order to show their generic "leave?" prompt.
        e.preventDefault();
        e.returnValue = '';
    });
}

/**
 * Read the active bulk op (if any). Reactive — components can render this
 * directly in templates and the UI updates as `current/total` advances.
 */
export function getActiveBulkOperation() {
    return state.active;
}

/**
 * Execute a LIGHT bulk operation. Returns the final status object.
 *
 * Concurrency: refuses to start while ANY bulk (light or heavy) is active.
 * Cancellation: the loop checks `lightCancelRequested` between items, set by
 * `cancelActive()` from the unified Cancel button on the banner.
 */
export async function runBulkOperation({ kind, label, context, items, perItem, onItemDone, onComplete }) {
    if (state.active) {
        warnToast('Another bulk operation is running. Wait for it to finish.');
        return null;
    }

    attachBeforeUnloadGuard();
    lightCancelRequested = false;

    state.active = {
        source: 'light',
        kind,
        label,
        // Caller-supplied context (e.g. { entryTitle, mode, ruleKeyword })
        // — read by the banner to render kind-specific detail like
        // "Removing inbound links to 'Article Title'".
        context: context || {},
        current: 0,
        total: items.length,
        succeeded: 0,
        skipped: 0,
        errors: {},
        canCancel: true,
    };

    const startedAt = Date.now();
    let cancelled = false;

    // Initial recovery snapshot — picked up by LinkwiseLayout on mount if the
    // user reloads mid-bulk. Updated after every item.
    writeRecovery({ kind, label, current: 0, total: items.length, succeeded: 0, skipped: 0, startedAt, context: context || {} });

    try {
        for (let i = 0; i < items.length; i++) {
            if (lightCancelRequested) {
                cancelled = true;
                break;
            }
            const item = items[i];
            // Increment BEFORE the await so the banner shows "X / Y" while the
            // current item is in flight, not after it lands.
            state.active.current = i + 1;
            let result;
            try {
                result = await perItem(item, i);
            } catch (e) {
                result = { success: false, error: e?.message || 'Unexpected error' };
            }

            if (result?.success) {
                state.active.succeeded++;
            } else {
                state.active.skipped++;
                const msg = result?.error || 'Unknown error';
                state.active.errors[msg] = (state.active.errors[msg] || 0) + 1;
            }
            onItemDone?.(item, result);

            // Refresh the recovery snapshot. Reads the current state.active
            // values which the perItem callback may have overridden (e.g. URL
            // Changer remaps current/total to replacement-count instead of
            // entry-count for banner consistency).
            writeRecovery({
                kind, label, startedAt,
                current: state.active.current,
                total: state.active.total,
                succeeded: state.active.succeeded,
                skipped: state.active.skipped,
                context: state.active.context || {},
            });
        }

        const final = { ...state.active, cancelled };
        onComplete?.(final);

        // Clear recovery — the operation reached its end (success, partial,
        // error, or cancel). The completion toast already informed the user.
        clearRecovery();

        // Final toast — fires from the module so it shows up regardless of
        // which Linkwise tab the user is on.
        if (cancelled) {
            warnToast(`Cancelled — ${final.succeeded} ${label} before stop.`);
        } else if (final.succeeded > 0 && final.skipped === 0) {
            Statamic.$toast.success(`${final.succeeded} ${label}.`);
        } else if (final.succeeded > 0 && final.skipped > 0) {
            warnToast(`${final.succeeded} ${label}, ${final.skipped} skipped.`);
        } else if (final.succeeded === 0 && final.skipped > 0) {
            // Surface the most-frequent error reason so the user has a clue
            // why nothing worked. final.errors is { reason: count, ... }.
            const reasons = Object.entries(final.errors || {});
            reasons.sort((a, b) => b[1] - a[1]);
            const topReason = reasons[0]?.[0] || 'unknown error';
            errorToast(`Could not complete: 0 of ${final.total} succeeded. ${topReason}`);
        }

        return final;
    } finally {
        // Only clear state if we set it (light source). Heavy operations are
        // managed by setHeavyState() — never clear them from here.
        if (state.active && state.active.source === 'light') {
            state.active = null;
        }
        lightCancelRequested = false;
    }
}

/**
 * Push a heavy-job snapshot from the LinkwiseLayout poller into the unified
 * state. Heavy jobs (scan, check, bulkunlink, applyrule) live server-side and
 * survive reloads; the layout polls /bulk-status periodically and forwards
 * the active-job info here so the same banner + disable-on-busy works for
 * heavy as it does for light.
 *
 * Pass null to clear the heavy state. Light state always wins — never clobber
 * an in-flight light loop.
 */
export function setHeavyState(snapshot) {
    if (state.active && state.active.source === 'light') return;

    if (snapshot === null) {
        if (state.active && state.active.source === 'heavy') {
            state.active = null;
        }
        return;
    }

    state.active = {
        source: 'heavy',
        ...snapshot,
    };
}

/**
 * Cancel whatever's currently running. Light → flip the cancel flag, the loop
 * exits at next iteration. Heavy → POST to the kind's cancel endpoint; the
 * server marks the job for cancellation and the next /bulk-status poll will
 * show 'cancelled' phase, clearing the banner.
 */
export async function cancelActive() {
    if (!state.active) return;

    if (state.active.source === 'light') {
        lightCancelRequested = true;
        return;
    }

    if (state.active.source === 'heavy' && state.active.cancelUrl) {
        try {
            await fetch(state.active.cancelUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
        } catch {
            // Best-effort: if the cancel HTTP fails, the user can retry.
            // The next poll will surface whatever the server actually decided.
        }
    }
}
