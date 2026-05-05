<template>
    <LinkwiseLayout active-tab="keywords" page-title="Linkwise — Target Keywords" :is-empty="false" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <TargetKeywordsTab :data="keywordsData" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import TargetKeywordsTab from '../dashboard/TargetKeywordsTab.vue';

export default {
    components: { LinkwiseLayout, TargetKeywordsTab },

    props: {
        keywordsData: { type: Object, required: true },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
    },
};
</script>
