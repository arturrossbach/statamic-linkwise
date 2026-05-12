<template>
    <LinkwiseLayout active-tab="links" page-title="Linkwise — Links Report" :is-empty="!entries || entries.length === 0" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <LinksReportTab
            ref="linksReport"
            :entries="entries"
            :collections="collections"
            :suggestion-counts-url="suggestionCountsUrl"
            :apply-url="applyUrl"
            :inbound-suggestions-base-url="inboundSuggestionsBaseUrl"
            :outbound-suggestions-base-url="outboundSuggestionsBaseUrl"
            :inbound-insert-url="inboundInsertUrl"
            :outbound-insert-url="outboundInsertUrl"
            :relink-preview-url="relinkPreviewUrl"
            :relink-url="relinkUrl"
            :autolink-store-url="autolinkStoreUrl"
            :rebuild-url="rebuildUrl"
            :index-last-built-at="indexLastBuiltAt"
            :initial-orphaned="initialOrphaned"
        />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import LinksReportTab from '../dashboard/LinksReportTab.vue';

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
        relinkPreviewUrl: { type: String, default: '' },
        relinkUrl: { type: String, default: '' },
        autolinkStoreUrl: { type: String, default: '' },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
        indexLastBuiltAt: { type: String, default: null },
        initialOrphaned: { type: Boolean, default: false },
    },
};
</script>
