<template>
    <LinkwiseLayout active-tab="autolink" page-title="Linkwise — Auto-Linking" :is-empty="false" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <!--
            :key="renderKey" ensures the tab re-mounts when the parent
            Inertia props (autolinkData / entries) change post-apply or
            post-bulk-unlink. Without this, Vue's nested-prop reactivity
            doesn't propagate updates into AutoLinkingTab's deep-cloned
            `this.rules` local state (User-Smoke 2026-05-19, Klasse 10).
            Bumping renderKey forces a fresh data() run with the new
            props in scope — the canonical Vue pattern for "force
            re-mount on data refresh", elegant + reliable.
        -->
        <AutoLinkingTab :data="autolinkData" :entries="entries" :key="renderKey" />
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

    data() {
        return {
            // Incremented whenever Inertia partial-reload updates the
            // page props. Drives the :key on AutoLinkingTab so the tab
            // re-mounts cleanly with fresh data() and fresh deep-clones
            // of the rules + entries.
            renderKey: 0,
        };
    },

    watch: {
        // Deep-watch the props themselves so we re-mount whenever the
        // backend hands us new content. Triggers on every
        // inertiaRouter.reload({only: ['autolinkData', 'entries']})
        // that AutoLinkingTab fires after applyrule / detailunlink /
        // multi-rule completion.
        autolinkData: { deep: true, handler() { this.renderKey++; } },
        entries: { deep: true, handler() { this.renderKey++; } },
    },
};
</script>
