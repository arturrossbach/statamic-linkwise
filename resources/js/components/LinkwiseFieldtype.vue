<template>
    <div class="linkwise-sidebar" role="region" aria-label="Linkwise overview">
        <div class="linkwise-header">
            <h3 class="text-sm font-bold flex items-center gap-1">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                </svg>
                Linkwise
            </h3>
        </div>

        <!-- Link Stats -->
        <div v-if="loadingStats" class="linkwise-stats">
            <p class="text-xs text-gray-400">Loading stats...</p>
        </div>
        <div v-else-if="linkStats" class="linkwise-stats">
            <div class="text-xs text-gray-600 dark:text-gray-400 flex gap-3">
                <span>Inbound: <strong class="text-gray-800 dark:text-gray-100">{{ linkStats.inbound }}</strong></span>
                <span>Outbound: <strong class="text-gray-800 dark:text-gray-100">{{ linkStats.outbound }}</strong></span>
            </div>

            <div v-if="linkStats.broken > 0" class="mt-1">
                <a :href="brokenUrl" target="_blank" class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                    {{ linkStats.broken }} broken link{{ linkStats.broken !== 1 ? 's' : '' }} &rarr;
                </a>
            </div>

            <!-- Outbound Suggestions -->
            <div v-if="linkStats.outbound_suggestions > 0 && entryId" class="mt-2 p-2 rounded bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800">
                <a :href="outboundSuggestUrl" target="_blank" class="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium">
                    {{ linkStats.outbound_suggestions }} outbound suggestion{{ linkStats.outbound_suggestions !== 1 ? 's' : '' }} &rarr;
                </a>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Words in this entry that could link to other pages.</p>
            </div>

            <!-- Inbound Suggestions -->
            <div v-if="linkStats.suggestions > 0 && entryId" class="mt-2 p-2 rounded bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800">
                <a :href="inboundSuggestUrl" target="_blank" class="text-xs text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium">
                    {{ linkStats.suggestions }} inbound suggestion{{ linkStats.suggestions !== 1 ? 's' : '' }} &rarr;
                </a>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Other entries could link to this page.</p>
            </div>

            <!-- Orphaned warning -->
            <div v-else-if="linkStats.inbound === 0 && entryId" class="mt-2 p-2 rounded bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800">
                <a :href="orphanedUrl" target="_blank" class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 font-medium">
                    Orphaned — no inbound links &rarr;
                </a>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Find entries that could link here.</p>
            </div>
        </div>

        <div v-else-if="!loadingStats" class="linkwise-empty">
            <p class="text-xs text-gray-500 dark:text-gray-400">No link data available.</p>
        </div>
    </div>
</template>

<script>
export default {
    inject: {
        container: { from: 'PublishContainerContext', default: null },
    },

    props: {
        handle: String,
        meta: { type: Object, default: () => ({}) },
        value: { default: null },
        config: { type: Object, default: () => ({}) },
    },

    data() {
        return {
            linkStats: null,
            loadingStats: false,
        };
    },

    computed: {
        entryId() {
            const vals = this.container?.values;
            const v = vals?.value ?? vals ?? {};
            if (v?.id) return v.id;

            const match = window.location.pathname.match(/\/entries\/([^/]+)/);
            return match ? match[1] : null;
        },

        linksUrl() {
            const cpUrl = window.StatamicConfig?.cpUrl || '';
            return cpUrl + '/linkwise/links';
        },

        outboundSuggestUrl() {
            const cpUrl = window.StatamicConfig?.cpUrl || '';
            return `${cpUrl}/linkwise/links?open=${this.entryId}&mode=outbound`;
        },

        inboundSuggestUrl() {
            const cpUrl = window.StatamicConfig?.cpUrl || '';
            return `${cpUrl}/linkwise/links?open=${this.entryId}&mode=inbound`;
        },

        orphanedUrl() {
            const cpUrl = window.StatamicConfig?.cpUrl || '';
            return `${cpUrl}/linkwise/links?open=${this.entryId}&mode=inbound`;
        },

        brokenUrl() {
            const cpUrl = window.StatamicConfig?.cpUrl || '';
            return cpUrl + '/linkwise/broken?entry=' + this.entryId;
        },
    },

    mounted() {
        this.fetchStats();
    },

    methods: {
        async fetchStats() {
            const id = this.entryId;
            if (!id) return;

            this.loadingStats = true;

            try {
                const cpUrl = window.StatamicConfig?.cpUrl || '';
                const csrfToken = window.StatamicConfig?.csrfToken || '';

                const response = await fetch(cpUrl + '/linkwise/stats/' + id, {
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) return;

                this.linkStats = await response.json();
            } catch (e) {
                console.error('[Linkwise]', e);
            } finally {
                this.loadingStats = false;
            }
        },
    },
};
</script>

<style scoped>
.linkwise-sidebar {
    padding: 0;
}

.linkwise-header {
    margin-bottom: 0.75rem;
}

.linkwise-stats {
    margin-bottom: 0.5rem;
}
</style>
