<template>
    <LinkwiseLayout
        ref="layout"
        active-tab="overview"
        page-title="Linkwise — Overview"
        :is-empty="!summary"
        :rebuild-url="rebuildUrl"
        :rebuild-status-url="rebuildStatusUrl"
        :rebuild-cancel-url="rebuildCancelUrl"
    >
        <OverviewTab
            :summary="summary"
            :health="health"
            :broken-count="brokenCount"
            :broken-last-checked="brokenLastChecked"
            :index-last-built-at="indexLastBuiltAt"
            :domains-count="domainsCount"
            @navigate="navigateToTab"
        />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import OverviewTab from '../dashboard/OverviewTab.vue';

export default {
    components: { LinkwiseLayout, OverviewTab },

    props: {
        summary: { type: Object, default: null },
        health: { type: Object, default: () => ({}) },
        brokenCount: { type: Number, default: null },
        brokenLastChecked: { type: String, default: null },
        indexLastBuiltAt: { type: String, default: null },
        domainsCount: { type: Number, default: null },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
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
