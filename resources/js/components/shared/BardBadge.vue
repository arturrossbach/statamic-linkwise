<script>
/**
 * Dev-mode-only "BARD" badge for entry rows in CP tables.
 *
 * Renders nothing in production. In local dev, shows a small purple
 * Statamic Badge next to the entry title for entries that actually
 * carry Bard data (top-level Bard field with content, OR Replicator
 * with at least one nested Bard fragment). Lets the developer pick
 * Bard entries vs Markdown-only ones at a glance when testing
 * field-type-specific code paths.
 *
 * Driven by Inertia::share('linkwise') from ServiceProvider:
 *   - $page.props.linkwise.dev_mode (bool)
 *   - $page.props.linkwise.bard_entry_ids (string[])
 *
 * Usage: <BardBadge :entry-id="entry.id" />
 */
import { Badge } from '@statamic/cms/ui';

export default {
    name: 'BardBadge',
    components: { Badge },
    props: {
        entryId: { type: String, required: true },
    },
    computed: {
        show() {
            const ls = this.$page?.props?.linkwise;
            if (!ls?.dev_mode) return false;
            const ids = ls.bard_entry_ids;
            if (!Array.isArray(ids)) return false;
            return ids.includes(this.entryId);
        },
    },
};
</script>

<template>
    <Badge v-if="show" text="BARD" color="purple" size="sm" v-tooltip="'Dev-mode marker: this entry has Bard content (or replicator with nested Bard).'" />
</template>
