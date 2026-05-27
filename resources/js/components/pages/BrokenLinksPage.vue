<template>
    <LinkwiseLayout active-tab="broken" page-title="Linkwise — Broken Links" :is-empty="false" :is-first-run="isFirstRun" :rebuild-url="rebuildUrl" :rebuild-status-url="rebuildStatusUrl" :rebuild-cancel-url="rebuildCancelUrl">
        <!-- V1.2 locale-filter — applied at the broken_links level via the
             controller's `?locale=` filter, the filtered list arrives in
             brokenData already-trimmed. This widget just lets the user
             change the active scope. -->
        <div v-if="availableLocales && availableLocales.length > 0" class="mb-4 flex justify-end">
            <LocaleFilter
                :available="availableLocales"
                :current="activeLocale"
                @update="onLocaleChange"
            />
        </div>
        <!-- :key="renderKey" — universal post-bulk-completion remount.
             Klasse-10 applied broadly (User-Smoke 2026-05-21). -->
        <BrokenLinksTab :key="renderKey" :data="brokenData" :checking="checking" :check-progress="checkProgress" :initial-entry-filter="initialEntryFilter" :apply-url="applyUrl" :ignore-url="ignoreUrl" :unignore-url="unignoreUrl" :bulk-unlink-url="bulkUnlinkUrl" :bulk-unlink-status-url="bulkUnlinkStatusUrl" :bulk-unlink-cancel-url="bulkUnlinkCancelUrl" :export-url="exportUrl" :entry-hashes="entryHashes" @check-links="checkLinks" />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import BrokenLinksTab from '../dashboard/BrokenLinksTab.vue';
import LocaleFilter from '../LocaleFilter.vue';
import { bulkState } from '../../services/bulkOperationService.js';
import { router as inertiaRouter } from '@statamic/cms/inertia';

export default {
    components: { LinkwiseLayout, BrokenLinksTab, LocaleFilter },

    props: {
        brokenData: { type: Object, required: true },
        entryHashes: { type: Object, default: () => ({}) },
        availableLocales: { type: Array, default: () => [] },
        activeLocale: { default: null }, // type omitted — null is "all", see LocaleFilter.vue
        applyUrl: { type: String, default: '' },
        ignoreUrl: { type: String, default: '' },
        unignoreUrl: { type: String, default: '' },
        bulkUnlinkUrl: { type: String, default: '' },
        bulkUnlinkStatusUrl: { type: String, default: '' },
        bulkUnlinkCancelUrl: { type: String, default: '' },
        checkLinksUrl: { type: String, required: true },
        checkLinksStatusUrl: { type: String, required: true },
        checkLinksCancelUrl: { type: String, required: true },
        isFirstRun: { type: Boolean, default: false },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
        exportUrl: { type: String, default: '' },
        initialEntryFilter: { type: String, default: '' },
    },

    data() {
        return {
            checking: false,
            checkProgress: null, // { current, total, url } during scan
            pollTimer: null,
            // Bumped on bulkState.lastCompletion → forces BrokenLinksTab
            // re-mount. Klasse-10 universal pattern.
            renderKey: 0,
        };
    },

    async mounted() {
        // If a scan was already running (user refreshed or came back), attach to it
        await this.pollStatusOnce();
        if (this.checking) {
            this.startPolling();
        }
        // Overview Tab's "Run check" recommendation forwards here with ?auto_check=1 —
        // trigger the check automatically so the user doesn't need a second click.
        if (! this.checking && this.autoCheckFromQuery()) {
            this.clearAutoCheckQuery();
            this.checkLinks();
        }

        // Universal post-bulk refresh — fetch fresh props, then remount.
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
        this.stopPolling();
        if (typeof this._unwatchBulkCompletion === 'function') {
            this._unwatchBulkCompletion();
        }
    },

    methods: {
        // V1.2 locale-filter — drive via URL query, see LinksPage.vue for
        // the same pattern + rationale.
        onLocaleChange(locale) {
            const url = new URL(window.location.href);
            if (locale) {
                url.searchParams.set('locale', locale);
            } else {
                url.searchParams.delete('locale');
            }
            // See LinksPage.vue for the preserveState+renderKey rationale.
            inertiaRouter.visit(url.pathname + url.search, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => { this.renderKey++; },
            });
        },

        autoCheckFromQuery() {
            const params = new URLSearchParams(window.location.search);
            return params.get('auto_check') === '1';
        },

        clearAutoCheckQuery() {
            // Strip ?auto_check=1 from the URL so a later reload doesn't re-trigger
            const url = new URL(window.location.href);
            url.searchParams.delete('auto_check');
            window.history.replaceState({}, '', url.toString());
        },

        async checkLinks() {
            if (bulkState.active) {
                Statamic.$toast.info('Another bulk operation is running. Wait for it to finish.');
                return;
            }
            this.checking = true;
            this.checkProgress = null;

            try {
                const response = await fetch(this.checkLinksUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (response.status === 409) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.info(data.message || 'Another bulk operation is running. Wait for it to finish.');
                    this.checking = false;
                    return;
                }
                if (!response.ok) {
                    Statamic.$toast.error('Failed to start link check.');
                    this.checking = false;
                    return;
                }
                this.startPolling();
            } catch (error) {
                Statamic.$toast.error('Failed to start link check.');
                console.error('[Linkwise]', error);
                this.checking = false;
            }
        },

        startPolling() {
            this.stopPolling();
            this.pollTimer = setInterval(() => this.pollStatusOnce(), 1000);
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async pollStatusOnce() {
            try {
                const response = await fetch(this.checkLinksStatusUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const status = await response.json();

                if (status.phase === 'checking') {
                    this.checking = true;
                    this.checkProgress = {
                        current: status.current,
                        total: status.total,
                        url: status.url,
                    };
                } else if (status.phase === 'starting') {
                    this.checking = true;
                    this.checkProgress = null;
                } else if (status.phase === 'done') {
                    // Only reload if WE were actively polling a running scan.
                    // Stale done-state from a previous session must not trigger
                    // repeated reloads on every mount → infinite reload loop.
                    const wasActive = this.checking;
                    this.stopPolling();
                    this.checking = false;
                    this.checkProgress = null;
                    if (wasActive) {
                        Statamic.$toast.success(`Link check complete. ${status.broken_count} broken link(s) found in ${status.duration}s.`);
                        window.location.reload();
                    }
                } else if (status.phase === 'cancelled') {
                    const wasActive = this.checking;
                    this.stopPolling();
                    this.checking = false;
                    this.checkProgress = null;
                    if (wasActive) {
                        Statamic.$toast.info('Link check cancelled.');
                    }
                } else {
                    // idle or unknown — stop UI spinner
                    this.stopPolling();
                    this.checking = false;
                    this.checkProgress = null;
                }
            } catch {
                // ignore transient polling errors
            }
        },

        async cancelCheck() {
            try {
                await fetch(this.checkLinksCancelUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
            } catch {}
        },
    },
};
</script>
