<template>
    <!-- Tab-spanning bulk-operation banner. Single source of truth for ALL
         bulks (light + heavy) — visible across every Linkwise tab. Switches
         to a "stuck" warning variant when the heartbeat is stale (process
         likely crashed without the shutdown-guard firing — e.g. server
         restart). User gets a Force-clear button. -->
    <Alert
        v-if="activeBulk"
        :variant="bulkStuck ? 'warning' : 'default'"
        class="mb-4 sticky top-0 z-30 shadow-md"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-center justify-between gap-2 text-sm">
            <div class="flex items-center gap-2">
                <span v-if="bulkStuck" class="font-medium">Operation may be stuck —</span>
                <span>{{ bulkBannerLabel }}</span>
                <!-- N/M counter is shown for every phase that has a total —
                     including 'indexing'. Without this the user only saw
                     "Finalizing index…" for fast bulks that completed between
                     polling ticks; they had no evidence the work itself had
                     landed. Real bug 2026-05-11: user said "Heavy-Banner zeigt
                     sofort 'finalizing index' statt vorher den Progress". -->
                <span v-if="activeBulk.total > 0" class="font-mono text-xs text-gray-500 dark:text-gray-400">
                    {{ activeBulk.current }} / {{ activeBulk.total }}
                </span>
                <!-- Outcome counts during indexing — writes are done, surface
                     succeeded/skipped so the user knows what actually happened
                     before the index rebuild. -->
                <span v-if="activeBulk.phase === 'indexing' && (activeBulk.succeeded !== undefined || activeBulk.skipped !== undefined)" class="text-xs text-gray-500 dark:text-gray-400">
                    ({{ activeBulk.succeeded || 0 }} done<span v-if="(activeBulk.skipped || 0) > 0">, {{ activeBulk.skipped }} skipped</span>)
                </span>
                <!-- Indexing/finalizing phase: the writes are complete but the
                     command is still rebuilding the index + recomputing
                     suggestion counts (can take 1-3min on large sites). -->
                <span v-if="activeBulk.phase === 'indexing'" class="text-xs text-gray-500 dark:text-gray-400 italic">
                    Finalizing index…
                </span>
                <span v-else-if="!activeBulk.total && activeBulk.message" class="text-xs text-gray-500 dark:text-gray-400">
                    {{ activeBulk.message }}
                </span>
                <span v-if="bulkStuck" class="text-xs text-gray-500 dark:text-gray-400">
                    (no progress for {{ bulkStaleSeconds }}s)
                </span>
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="bulkStuck" @click="$emit('force-clear')" :loading="forceClearing" text="Force-clear" variant="default" size="xs" />
                <Button v-if="activeBulk.canCancel && !bulkStuck" @click="$emit('cancel')" :loading="cancelling" text="Cancel" variant="default" size="xs" />
            </div>
        </div>
        <!-- Heavy bulks survive navigation/reload — they run in a detached
             artisan process, the banner re-attaches via a global state poll
             on every Linkwise tab. Telling the user means they don't have to
             babysit the tab during a 10-minute scan. -->
        <p v-if="activeBulk.source === 'heavy' && !bulkStuck" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            You can safely navigate to other Linkwise tabs or away from this page — the operation continues in the background and the banner will reappear when you come back.
        </p>
    </Alert>
</template>

<script>
import { Alert, Button } from '@statamic/cms/ui';

/**
 * BulkStatusBanner — extracted from LinkwiseLayout.vue in Sprint 5 PR 3c.
 *
 * The tab-spanning active-bulk Alert. Lives OUTSIDE the notifications
 * accordion because it's transient AND critical — never collapsable, always
 * sticky-top.
 *
 * Variante A (etabliertes Sprint-5-Pattern aus PR 2c-2e): state stays in
 * parent (LinkwiseLayout owns `bulkState.active` via `bulkOperationService`),
 * this component is a template wrapper with props/events.
 *
 * The labels (`bulkBannerLabel`, `bulkStaleSeconds`) and the boolean
 * (`bulkStuck`) are computed in the parent because they depend on
 * `bulkState.active` + `tickClock` + the shared `bulkLabels` module — passing
 * them down as props avoids re-importing the module here.
 */
export default {
    name: 'BulkStatusBanner',

    components: { Alert, Button },

    props: {
        // Reactive bulk state from bulkOperationService. null when nothing
        // running; otherwise the shape `{ kind, label, phase, current,
        // total, source, canCancel, message, heartbeat, ... }`.
        activeBulk: { type: Object, default: null },
        // Pre-computed "operation may be stuck" boolean. Lives in parent
        // because it reads tickClock against activeBulk.heartbeat — pure
        // value forwards cleanly.
        bulkStuck: { type: Boolean, default: false },
        // Human label for the active-bulk row ("Applying rule X" etc.).
        // Built in parent via the bulkLabels::activeLabel helper.
        bulkBannerLabel: { type: String, default: '' },
        // Seconds since last heartbeat — only meaningful when bulkStuck is true.
        bulkStaleSeconds: { type: Number, default: 0 },
        // Async-action flags so the button shows its loading spinner.
        cancelling: { type: Boolean, default: false },
        forceClearing: { type: Boolean, default: false },
    },

    emits: ['cancel', 'force-clear'],
};
</script>
