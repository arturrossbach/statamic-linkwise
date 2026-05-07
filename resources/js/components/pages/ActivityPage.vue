<template>
    <LinkwiseLayout
        active-tab="activity"
        page-title="Linkwise — Activity Log"
        :is-empty="false"
        :rebuild-url="rebuildUrl"
        :rebuild-status-url="rebuildStatusUrl"
        :rebuild-cancel-url="rebuildCancelUrl"
    >
        <Card class="mb-4">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Activity Log</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                        Read-only forensic record of every Linkwise bulk operation in the last 30 days.
                        Use this to see what changed and which entries were affected — recovery happens through your normal backup workflow
                        (Statamic Revisions, Git, or your hosting provider's backup).
                    </p>
                </div>
                <HelpIcon tooltip="Snapshots are written before each bulk runs. They contain entry IDs and content hashes (no entry contents). Older than 30 days are auto-deleted." />
            </div>
        </Card>

        <div v-if="snapshots.length === 0" class="py-16 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-10 mx-auto mb-3 text-gray-300 dark:text-gray-600">
                <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <p class="text-gray-600 dark:text-gray-400 font-medium">No bulk operations recorded yet</p>
            <p class="text-xs text-gray-400 mt-1">As soon as you Apply a rule, run the URL Changer, unlink in bulk, or insert links in bulk, the operations will appear here.</p>
        </div>

        <Panel v-else>
            <div class="overflow-x-auto">
                <table data-size="sm" class="data-table w-full text-sm">
                    <thead>
                        <tr>
                            <th scope="col" class="text-left">When</th>
                            <th scope="col" class="text-left">Operation</th>
                            <th scope="col" class="text-left">Started by</th>
                            <th scope="col" class="text-left">Entries affected</th>
                            <th scope="col" class="text-left">Details</th>
                            <th scope="col" class="text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="snap in snapshots" :key="snap.id">
                            <td class="whitespace-nowrap text-xs text-gray-600 dark:text-gray-400" v-tooltip="snap.started_at || ''">
                                {{ formatRelative(snap.started_at) }}
                            </td>
                            <td class="whitespace-nowrap">
                                <Badge :variant="kindVariant(snap.kind)" :text="kindLabel(snap.kind)" />
                            </td>
                            <td class="whitespace-nowrap text-xs">
                                {{ snap.started_by || '—' }}
                            </td>
                            <td class="whitespace-nowrap text-xs">
                                <span class="font-medium">{{ snap.entry_count_total }}</span>
                                <span class="text-gray-400 ml-1">{{ snap.entry_count_total === 1 ? 'entry' : 'entries' }}</span>
                            </td>
                            <td class="text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="snap.summary && summaryLabel(snap)">{{ summaryLabel(snap) }}</span>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <Button @click="openDetail(snap)" text="View entries" size="xs" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Panel>

        <!-- Detail Drawer -->
        <Stack :open="detail !== null" @update:open="closeDetail" :title="detailTitle">
            <div v-if="detail">
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                    <p>
                        Operation: <strong>{{ kindLabel(detail.snapshot.kind) }}</strong>
                        — started <strong>{{ formatAbsolute(detail.snapshot.started_at) }}</strong>
                        <template v-if="detail.snapshot.started_by"> by <strong>{{ detail.snapshot.started_by }}</strong></template>
                    </p>
                    <p v-if="summaryLabel(detail.snapshot)" class="mt-1">{{ summaryLabel(detail.snapshot) }}</p>
                </div>

                <Alert variant="default" class="mb-3">
                    <p class="text-sm">
                        <strong>How to undo:</strong> Linkwise can't restore entries directly — that's by design. Use whichever recovery path fits your setup:
                    </p>
                    <ul class="text-xs mt-2 ml-4 list-disc space-y-0.5 leading-relaxed">
                        <li><strong>Statamic Revisions</strong> (if enabled on the collection): open the entry → Revisions → restore.</li>
                        <li><strong>Git</strong> (if your <code>content/</code> is committed): roll the affected entry files back to a previous commit.</li>
                        <li><strong>Hosting backup</strong>: every hoster (Forge, Ploi, Cleavr, your own server) has scheduled snapshots.</li>
                    </ul>
                </Alert>

                <div class="flex items-center justify-between mb-2 gap-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <strong>{{ detail.entries.length }}</strong> {{ detail.entries.length === 1 ? 'entry' : 'entries' }} were affected:
                    </p>
                    <a
                        v-if="detail.deep_link_url_changer"
                        :href="detail.deep_link_url_changer"
                        class="text-xs text-blue-600 dark:text-blue-400 hover:underline whitespace-nowrap"
                        v-tooltip="'Open the URL Changer with the same search pre-filled, so you can manually unlink or replace these in bulk.'"
                    >
                        Find these in URL Changer ↗
                    </a>
                </div>

                <Panel>
                    <div class="overflow-x-auto">
                        <table data-size="sm" class="data-table w-full text-sm">
                            <thead>
                                <tr>
                                    <th scope="col" class="text-left">Title</th>
                                    <th scope="col" class="text-left">Collection</th>
                                    <th scope="col" class="text-left">What happened</th>
                                    <th scope="col" class="text-left">Status since bulk</th>
                                    <th scope="col" class="text-right"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in detail.entries" :key="e.id">
                                    <td>{{ e.title }}</td>
                                    <td class="text-xs text-gray-500">{{ e.collection || '—' }}</td>
                                    <td class="text-xs text-gray-600 dark:text-gray-400">
                                        <div v-if="e.items && e.items.length > 0" class="space-y-0.5">
                                            <div v-for="(item, i) in e.items" :key="i" class="leading-snug">
                                                <span v-if="item.anchor_text" class="font-mono text-xs">"{{ item.anchor_text }}"</span>
                                                <span v-if="item.matched_url" class="text-gray-500"> → {{ truncateUrl(item.matched_url) }}</span>
                                                <span v-if="item.new_url" class="text-gray-500"> → {{ truncateUrl(item.new_url) }}</span>
                                            </div>
                                        </div>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="text-xs">
                                        <Badge :variant="statusVariant(e.status)" :text="statusLabel(e.status)" />
                                    </td>
                                    <td class="text-right">
                                        <a v-if="e.edit_url" :href="e.edit_url" target="_blank" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">Open ↗</a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>
        </Stack>
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import HelpIcon from '../shared/HelpIcon.vue';
import { Card, Panel, Button, Badge, Stack, Alert } from '@statamic/cms/ui';

export default {
    components: { LinkwiseLayout, HelpIcon, Card, Panel, Button, Badge, Stack, Alert },

    props: {
        snapshots: { type: Array, default: () => [] },
        detailUrl: { type: String, required: true },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
    },

    data() {
        return {
            detail: null,
            detailLoading: false,
        };
    },

    computed: {
        detailTitle() {
            if (!this.detail) return '';
            return this.kindLabel(this.detail.snapshot.kind) + ' details';
        },
    },

    methods: {
        async openDetail(snap) {
            this.detailLoading = true;
            const url = this.detailUrl.replace('__ID__', encodeURIComponent(snap.id));
            try {
                const response = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' },
                });
                if (!response.ok) {
                    Statamic.$toast.error('Could not load operation details.');
                    return;
                }
                this.detail = await response.json();
            } catch {
                Statamic.$toast.error('Could not load operation details.');
            } finally {
                this.detailLoading = false;
            }
        },

        closeDetail() {
            this.detail = null;
        },

        kindLabel(kind) {
            return ({
                applyrule: 'Apply auto-link rule',
                bulkunlink: 'Bulk-unlink broken links',
                detailunlink: 'Detail-modal bulk unlink',
                inboundinsert: 'Bulk insert inbound links',
                outboundinsert: 'Bulk insert outbound links',
                urlchanger: 'URL Changer apply',
            }[kind] || kind);
        },

        kindVariant(kind) {
            // Statamic Badge variants — keep apply / insert vs unlink visually distinct
            return ({
                applyrule: 'success',
                inboundinsert: 'success',
                outboundinsert: 'success',
                urlchanger: 'info',
                bulkunlink: 'warning',
                detailunlink: 'warning',
            }[kind] || 'default');
        },

        statusLabel(status) {
            return ({
                unchanged: 'Unchanged since bulk',
                modified: 'Edited since bulk',
                deleted: 'Deleted',
                unknown: 'Unknown',
            }[status] || status);
        },

        statusVariant(status) {
            return ({
                unchanged: 'default',
                modified: 'warning',
                deleted: 'error',
                unknown: 'default',
            }[status] || 'default');
        },

        summaryLabel(snap) {
            if (!snap.summary) return '';
            const s = snap.summary;
            if (snap.kind === 'applyrule') return s.rule_keyword ? `Rule: "${s.rule_keyword}"` : (s.mode === 'multi-rule' ? `${s.total_rules} rules` : '');
            if (snap.kind === 'urlchanger') return s.search ? `Search: "${s.search}" — ${s.action || 'apply'}` : '';
            if (snap.kind === 'detailunlink') return s.entry_title ? `${s.source_mode || ''} unlink on "${s.entry_title}"` : '';
            if (snap.kind === 'inboundinsert' || snap.kind === 'outboundinsert') {
                return s.entry_title ? `${snap.kind === 'inboundinsert' ? 'into' : 'from'} "${s.entry_title}"` : '';
            }
            if (snap.kind === 'bulkunlink') return `${s.replacement_count || ''} broken links`;
            return '';
        },

        formatRelative(iso) {
            if (!iso) return '—';
            const now = Date.now();
            const t = new Date(iso).getTime();
            const diff = Math.max(0, now - t);
            const min = Math.floor(diff / 60000);
            if (min < 1) return 'just now';
            if (min < 60) return `${min} min ago`;
            const hr = Math.floor(min / 60);
            if (hr < 24) return `${hr} h ago`;
            const day = Math.floor(hr / 24);
            return `${day} d ago`;
        },

        formatAbsolute(iso) {
            if (!iso) return '—';
            try {
                return new Date(iso).toLocaleString();
            } catch {
                return iso;
            }
        },

        truncateUrl(url) {
            if (!url) return '';
            // statamic://entry::UUID is the verbose internal form — replace with
            // a compact "→ entry" so the table doesn't get hijacked by 60-char URIs.
            if (url.startsWith('statamic://entry::')) {
                return 'entry: ' + url.replace('statamic://entry::', '').slice(0, 8) + '…';
            }
            if (url.length > 50) {
                return url.slice(0, 47) + '…';
            }
            return url;
        },
    },
};
</script>
