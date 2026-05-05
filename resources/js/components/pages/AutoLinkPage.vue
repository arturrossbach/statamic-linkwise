<template>
    <LinkwiseLayout active-tab="autolink" page-title="Linkwise — Auto-Linking" :is-empty="false" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <AutoLinkingTab :data="autolinkData" :entries="entries" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import AutoLinkingTab from '../dashboard/AutoLinkingTab.vue';

export default {
    components: { LinkwiseLayout, AutoLinkingTab },

    props: {
        autolinkData: { type: Object, required: true },
        entries: { type: Array, default: () => [] },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
    },
};
</script>
