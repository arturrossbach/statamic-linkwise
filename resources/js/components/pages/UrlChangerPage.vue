<template>
    <LinkwiseLayout active-tab="urlchanger" page-title="Linkwise — URL Changer" :is-empty="false" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <UrlChangerTab ref="urlChanger" :data="urlChangerData" :domains="domains" :initial-search="initialSearch" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import UrlChangerTab from '../dashboard/UrlChangerTab.vue';

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
};
</script>
