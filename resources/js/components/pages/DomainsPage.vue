<template>
    <LinkwiseLayout active-tab="domains" page-title="Linkwise — Domains" :is-empty="false" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <DomainsTab :domains="domains" :save-url="saveUrl" :export-url="exportUrl" :rebuild-url="rebuildUrl" :index-last-built-at="indexLastBuiltAt" @edit-domain="editDomain" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import DomainsTab from '../dashboard/DomainsTab.vue';

export default {
    components: { LinkwiseLayout, DomainsTab },

    props: {
        domains: { type: Array, required: true },
        saveUrl: { type: String, required: true },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
        exportUrl: { type: String, default: '' },
        indexLastBuiltAt: { type: String, default: null },
    },

    methods: {
        editDomain(domain) {
            this.$inertia.visit('/cp/linkwise/url-changer?search=' + encodeURIComponent(domain));
        },
    },
};
</script>
