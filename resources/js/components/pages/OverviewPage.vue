<template>
    <LinkwiseLayout
        ref="layout"
        active-tab="overview"
        page-title="Linkwise — Overview"
        :is-empty="!summary"
        :is-first-run="isFirstRun"
        :rebuild-url="rebuildUrl"
        :rebuild-status-url="rebuildStatusUrl"
        :rebuild-cancel-url="rebuildCancelUrl"
    >
        <!-- PR #102 audit C1 — Multisite-reindex prompt. Surfaces when the
             persisted index still contains records without a locale stamp
             (i.e. built before the multilanguage track shipped). One Scan
             Content run upgrades them. Banner is dismissed by clicking
             "Scan Content" or by the user manually re-running. -->
        <div v-if="multisiteReindexNeeded" class="mb-4 p-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60">
            <div class="flex items-start gap-3">
                <div class="flex-1 text-sm">
                    <p class="font-medium text-amber-800 mb-1">Multilingual content detected — index needs a refresh</p>
                    <p class="text-amber-700">Some entries were indexed before per-site locale tagging shipped. Run <strong>Scan Content</strong> once so cross-locale suggestion filtering applies to every entry.</p>
                </div>
                <button
                    type="button"
                    class="shrink-0 inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md bg-amber-600 hover:bg-amber-700 text-white shadow-sm"
                    @click="$refs.layout?.rebuildIndex()"
                >Scan Content</button>
            </div>
        </div>

        <!-- :key="renderKey" — universal post-bulk remount (Klasse-10). -->
        <OverviewTab
            :key="renderKey"
            :summary="summary"
            :health="health"
            :broken-count="brokenCount"
            :broken-last-checked="brokenLastChecked"
            :index-last-built-at="indexLastBuiltAt"
            :domains-count="domainsCount"
            :resolved-language="resolvedLanguage"
            :locale-breakdown="localeBreakdown"
            :is-multilingual="isMultilingual"
            @navigate="navigateToTab"
        />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import OverviewTab from '../dashboard/OverviewTab.vue';
import { bulkState } from '../../services/bulkOperationService.js';
import { router as inertiaRouter } from '@statamic/cms/inertia';

export default {
    components: { LinkwiseLayout, OverviewTab },

    props: {
        summary: { type: Object, default: null },
        health: { type: Object, default: () => ({}) },
        brokenCount: { type: Number, default: null },
        brokenLastChecked: { type: String, default: null },
        indexLastBuiltAt: { type: String, default: null },
        domainsCount: { type: Number, default: null },
        resolvedLanguage: { type: Object, default: null },
        multisiteReindexNeeded: { type: Boolean, default: false },
        localeBreakdown: { type: Object, default: () => ({}) },
        isMultilingual: { type: Boolean, default: false },
        isFirstRun: { type: Boolean, default: false },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
    },

    data() {
        return { renderKey: 0 };
    },

    mounted() {
        this._unwatchBulkCompletion = this.$watch(
            () => bulkState.lastCompletion,
            (completion) => {
                if (! completion) return;
                if (completion.phase !== 'done' && completion.phase !== 'cancelled') return;
                inertiaRouter.reload({
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => { this.renderKey++; },
                });
            },
        );
    },

    beforeUnmount() {
        if (typeof this._unwatchBulkCompletion === 'function') {
            this._unwatchBulkCompletion();
        }
    },

    methods: {
        navigateToTab(tab, options = {}) {
            // 'rebuild' delegates to the Layout's rebuildIndex() — same code
            // path as the header "Scan Content" button. Keeps 409-handling +
            // banner sync + toast polish in ONE place (DRY).
            if (tab === 'rebuild') {
                this.$refs.layout?.rebuildIndex();
                return;
            }

            const routes = {
                links: '/cp/linkwise/links',
                broken: '/cp/linkwise/broken',
                domains: '/cp/linkwise/domains',
            };
            let url = routes[tab] || '/cp/linkwise';
            if (tab === 'links' && options.orphaned) {
                url += '?orphaned=1';
            }
            if (tab === 'broken' && options.autoCheck) {
                url += '?auto_check=1';
            }
            this.$inertia.visit(url);
        },
    },
};
</script>
