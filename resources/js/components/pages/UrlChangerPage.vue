<template>
    <LinkwiseLayout active-tab="urlchanger" page-title="Linkwise — URL Changer" :is-empty="false" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <!-- :key="renderKey" — universal post-bulk remount (Klasse-10). -->
        <UrlChangerTab ref="urlChanger" :key="renderKey" :data="urlChangerData" :domains="domains" :initial-search="initialSearch" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import UrlChangerTab from '../dashboard/UrlChangerTab.vue';
import { bulkState } from '../../services/bulkOperationService.js';
import { router as inertiaRouter } from '@statamic/cms/inertia';

export default {
    components: { LinkwiseLayout, UrlChangerTab },

    props: {
        urlChangerData: { type: Object, required: true },
        domains: { type: Array, default: () => [] },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
        initialSearch: { type: String, default: '' },
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
