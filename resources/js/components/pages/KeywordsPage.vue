<template>
    <LinkwiseLayout active-tab="keywords" page-title="Linkwise — Custom Keywords" :is-empty="false" :is-first-run="isFirstRun" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <!-- :key="renderKey" — universal post-bulk remount (Klasse-10). -->
        <TargetKeywordsTab :key="renderKey" :data="keywordsData" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import TargetKeywordsTab from '../dashboard/TargetKeywordsTab.vue';
import { bulkState } from '../../services/bulkOperationService.js';
import { router as inertiaRouter } from '@statamic/cms/inertia';

export default {
    components: { LinkwiseLayout, TargetKeywordsTab },

    props: {
        keywordsData: { type: Object, required: true },
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
};
</script>
