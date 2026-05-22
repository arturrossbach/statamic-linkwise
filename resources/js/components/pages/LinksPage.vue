<template>
    <LinkwiseLayout active-tab="links" page-title="Linkwise — Links Report" :is-empty="!entries || entries.length === 0" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <!--
            :key="renderKey" forces the tab to re-mount after any bulk
            operation completes. Same pattern as AutoLinkPage (PR #65) —
            this is Klasse-10 applied broadly: every counter, every list,
            every cached field in the tab refreshes via data() running
            fresh. User-Smoke 2026-05-21: inbound-suggestion-apply did
            not refresh the suggestion-count column even though
            LinksReportTab had a watcher — too many narrow refresh
            paths, too easy to miss one. This is the universal hammer.
        -->
        <LinksReportTab
            ref="linksReport"
            :key="renderKey"
            :entries="entries"
            :collections="collections"
            :suggestion-counts-url="suggestionCountsUrl"
            :apply-url="applyUrl"
            :inbound-suggestions-base-url="inboundSuggestionsBaseUrl"
            :outbound-suggestions-base-url="outboundSuggestionsBaseUrl"
            :inbound-insert-url="inboundInsertUrl"
            :outbound-insert-url="outboundInsertUrl"
            :relink-url="relinkUrl"
            :autolink-store-url="autolinkStoreUrl"
            :ignore-suggestion-url="ignoreSuggestionUrl"
            :unignore-suggestion-url="unignoreSuggestionUrl"
            :rebuild-url="rebuildUrl"
            :index-last-built-at="indexLastBuiltAt"
            :initial-orphaned="initialOrphaned"
        />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import LinksReportTab from '../dashboard/LinksReportTab.vue';
import { bulkState } from '../../services/bulkOperationService.js';
import { router as inertiaRouter } from '@statamic/cms/inertia';

export default {
    components: { LinkwiseLayout, LinksReportTab },

    props: {
        entries: { type: Array, default: () => [] },
        collections: { type: Array, default: () => [] },
        suggestionCountsUrl: { type: String, default: '' },
        applyUrl: { type: String, default: '' },
        inboundSuggestionsBaseUrl: { type: String, default: '' },
        outboundSuggestionsBaseUrl: { type: String, default: '' },
        inboundInsertUrl: { type: String, default: '' },
        outboundInsertUrl: { type: String, default: '' },
        relinkUrl: { type: String, default: '' },
        autolinkStoreUrl: { type: String, default: '' },
        ignoreSuggestionUrl: { type: String, default: '' },
        unignoreSuggestionUrl: { type: String, default: '' },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
        indexLastBuiltAt: { type: String, default: null },
        initialOrphaned: { type: Boolean, default: false },
    },

    data() {
        return {
            renderKey: 0,
        };
    },

    mounted() {
        // Universal post-bulk refresh. Two-step: (1) fetch fresh Inertia
        // page props from server, (2) on success bump renderKey to
        // force a clean remount of the tab with those fresh props.
        // Without the Inertia reload first, :key remount would just
        // re-run data() against STALE props (Vue components stay
        // mounted across partial reloads — props themselves don't
        // auto-refetch).
        //
        // Why every kind: LinkwiseLayout's poller fires partial reloads
        // for SOME kinds (inboundinsert/outboundinsert via
        // pickTerminalReload) but NOT for others (applyrule, urlchanger
        // etc. → "none"). This page-level watcher closes the gap —
        // every completion triggers the same fetch+remount cycle.
        this._unwatchBulkCompletion = this.$watch(
            () => bulkState.lastCompletion,
            (completion) => {
                if (! completion) return;
                if (completion.phase !== 'done' && completion.phase !== 'cancelled') return;
                inertiaRouter.reload({
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        this.renderKey++;
                    },
                });
            },
        );
    },

    beforeUnmount() {
        if (typeof this._unwatchBulkCompletion === 'function') {
            this._unwatchBulkCompletion();
        }
    },
};
</script>
