<template>
    <!-- Persistent-notifications accordion: groups stale-check + completion +
         recovery banners under one summary so users who have seen them aren't
         pushed downward on every page-nav. Joomla-style "X notifications"
         header, individual dismiss buttons inside still work. The active-bulk
         banner stays OUTSIDE this component because it's transient AND
         critical — never collapsable. -->
    <details
        :open="!notificationsCollapsed"
        @toggle="$emit('update:notificationsCollapsed', !$event.target.open)"
        class="mb-4 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40"
    >
        <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800/60 rounded-md">
            {{ notificationCount }} {{ notificationCount === 1 ? 'notification' : 'notifications' }}
        </summary>
        <div class="px-3 pb-3 pt-1 flex flex-col gap-2">
            <!-- Stale-check: index newer than last broken-link check.
                 Always-stacked layout so buttons aren't cramped. -->
            <Alert v-if="showStaleCheck" variant="warning" role="status">
                <div class="flex flex-col gap-3 text-sm">
                    <div>
                        <p class="font-medium">{{ staleCheckTitle }}</p>
                        <p class="mt-0.5 text-xs opacity-80">{{ staleCheckBody }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button @click="$emit('run-stale-check')" :loading="checkingFromBanner" :disabled="runCheckDisabled" text="Run check now" size="xs" />
                        <Button @click="$emit('dismiss-stale-check')" text="Dismiss" variant="default" size="xs" />
                    </div>
                </div>
            </Alert>

            <!-- Completion: persistent recap of the last bulk so users who
                 missed the toast can still see the result. Statamic's
                 <Alert variant="..."> already renders its own variant-matched
                 icon (check / warning / x); don't add an extra <Icon> here
                 or the banner shows two icons side-by-side. -->
            <Alert v-if="showCompletion" :variant="completionBannerVariant" role="status">
                <div class="flex items-start justify-between gap-4 text-sm">
                    <span>{{ completionBannerLabel }}</span>
                    <Button @click="$emit('dismiss-completion')" text="Dismiss" variant="default" size="xs" />
                </div>
            </Alert>

            <!-- Recovery: page reloaded mid-bulk — tells the user how far
                 it got. No resume action. -->
            <Alert v-if="interruptedBulk" variant="warning" role="status">
                <div class="flex items-start justify-between gap-4 text-sm">
                    <div>
                        <div class="font-medium">Previous bulk operation was interrupted.</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                            {{ interruptedBulkLabel }} — completed {{ interruptedBulk.current }} of {{ interruptedBulk.total }} before the page was reloaded.
                            <span v-if="interruptedBulk.skipped > 0">{{ interruptedBulk.skipped }} skipped.</span>
                            Re-run the same operation if you need the rest.
                        </div>
                    </div>
                    <Button @click="$emit('dismiss-interrupted-bulk')" text="Dismiss" variant="default" size="xs" />
                </div>
            </Alert>
        </div>
    </details>
</template>

<script>
import { Alert, Button } from '@statamic/cms/ui';

/**
 * NotificationsAccordion — extracted from LinkwiseLayout.vue in Sprint 5 PR 3d.
 *
 * The persistent-notifications `<details>` accordion + its three inner
 * banners (stale-check, completion-recap, interrupted-bulk recovery).
 * Extracted as ONE cohesive component rather than three separate ones
 * because the accordion shell + the `notificationCount` summary logic
 * couple the banners — splitting them into 3 components would require
 * duplicating the shell or threading the summary count through awkwardly.
 *
 * Variante A (etabliertes Sprint-5-Pattern): state stays in parent
 * (LinkwiseLayout owns `notificationsCollapsed`, `interruptedBulk`,
 * `staleCheckDismissedFor`, `checkingFromBanner` — these are all read
 * outside this component too, e.g. `notificationsCollapsed` is auto-set
 * to `false` by `fireTerminalToast` when a new completion lands).
 *
 * Three pieces of API surface:
 *   1. `v-model:notifications-collapsed` — bidirectional so the parent's
 *      auto-open-on-completion logic flips it.
 *   2. Pre-computed labels + variants (variant strings, completion label,
 *      interrupted label) so this component doesn't need to re-import
 *      the bulkLabels module.
 *   3. Action emits — parent handles the async side-effects.
 */
export default {
    name: 'NotificationsAccordion',

    components: { Alert, Button },

    props: {
        // v-model bridge — parent can flip it open via auto-open on new
        // completion (fireTerminalToast sets it to false).
        notificationsCollapsed: { type: Boolean, default: false },

        // Visible-notification count drives the "X notifications" summary
        // text. Parent computes this from showStaleCheck + lastCompletion
        // + interruptedBulk so the parent can also `v-if` this whole
        // accordion when it's 0.
        notificationCount: { type: Number, required: true },

        // ── Stale-check banner ────────────────────────────────────────
        showStaleCheck: { type: Boolean, default: false },
        staleCheckTitle: { type: String, default: '' },
        staleCheckBody: { type: String, default: '' },
        checkingFromBanner: { type: Boolean, default: false },
        // True when the "Run check now" button should be disabled — parent
        // resolves this from `!!activeBulk` so we don't have to know what
        // "active bulk" means here.
        runCheckDisabled: { type: Boolean, default: false },

        // ── Completion banner ─────────────────────────────────────────
        // Pre-computed by parent (depends on lastCompletion AND activeBulk
        // — the banner hides during an active bulk so the active-bulk
        // banner above isn't competing for attention).
        showCompletion: { type: Boolean, default: false },
        completionBannerVariant: { type: String, default: 'default' },
        completionBannerLabel: { type: String, default: '' },

        // ── Interrupted-bulk recovery banner ──────────────────────────
        // Full snapshot from sessionStorage — passed as object because
        // the template references {current, total, skipped} directly.
        interruptedBulk: { type: Object, default: null },
        interruptedBulkLabel: { type: String, default: '' },
    },

    emits: [
        'update:notificationsCollapsed',
        'run-stale-check',
        'dismiss-stale-check',
        'dismiss-completion',
        'dismiss-interrupted-bulk',
    ],
};
</script>
