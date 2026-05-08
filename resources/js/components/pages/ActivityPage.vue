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
                                <Badge :variant="kindVariant(snap.kind)" :text="kindLabel(snap)" />
                                <Badge v-if="snap.completed_at === null" variant="warning" text="In progress" class="ml-1" v-tooltip="'This bulk is still running. The entry is shown for transparency, but Revert is disabled until the bulk completes.'" />
                                <Badge v-if="snap.reverted_at" variant="default" text="↶ Reverted" class="ml-1" v-tooltip="'Reverted at ' + formatAbsolute(snap.reverted_at)" />
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
                        Operation: <strong>{{ kindLabel(detail.snapshot) }}</strong>
                        — started <strong>{{ formatAbsolute(detail.snapshot.started_at) }}</strong>
                        <template v-if="detail.snapshot.started_by"> by <strong>{{ detail.snapshot.started_by }}</strong></template>
                    </p>
                    <p v-if="summaryLabel(detail.snapshot)" class="mt-1">{{ summaryLabel(detail.snapshot) }}</p>
                </div>

                <!-- Revert action — when the operation is reversible (most apply / insert /
                     URL-changer ops are), Linkwise can dispatch the inverse bulk for you.
                     Otherwise we fall back to the manual recovery instructions. -->
                <div v-if="canRevert" class="mb-3 rounded-md border border-blue-200 dark:border-blue-900/30 bg-blue-50 dark:bg-blue-900/10 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-blue-900 dark:text-blue-200">Revert this operation</p>
                            <p class="text-xs text-blue-700 dark:text-blue-300 mt-0.5 leading-relaxed">
                                {{ revertExplanation }}
                                <span v-if="ageWarning" class="block mt-1 text-amber-700 dark:text-amber-400">{{ ageWarning }}</span>
                            </p>
                        </div>
                        <Button
                            text="Revert…"
                            variant="primary"
                            size="sm"
                            :disabled="reverting"
                            @click="confirmRevert = true"
                        />
                    </div>
                </div>
                <!-- Still-running state: clear "wait" message, no Revert. -->
                <Alert v-else-if="detail.snapshot.completed_at === null" variant="warning" class="mb-3">
                    <p class="text-sm">
                        <strong>This bulk is still running.</strong>
                        Wait for it to complete — the entry will become revertable as soon as the run finishes.
                        Watch the progress banner at the top of the screen, or refresh this page.
                    </p>
                </Alert>
                <!-- Already-reverted state: a one-liner is enough; the recovery
                     instructions only matter when no auto-revert is possible. -->
                <Alert v-else-if="detail.snapshot.reverted_at" variant="default" class="mb-3">
                    <p class="text-sm">
                        <strong>↶ Already reverted</strong> on
                        <span>{{ formatAbsolute(detail.snapshot.reverted_at) }}</span><template v-if="detail.reverted_by_user">
                        by <strong>{{ detail.reverted_by_user }}</strong></template>.
                        Look for the matching reverse-bulk further up in this list.
                    </p>
                </Alert>
                <Alert v-else variant="default" class="mb-3">
                    <p class="text-sm">
                        <strong>How to undo this:</strong>
                        <span v-if="nonReversibleReason">{{ nonReversibleReason }}</span>
                        <span v-else>Linkwise can't auto-revert this operation — use whichever recovery path fits your setup:</span>
                    </p>
                    <ul class="text-xs mt-2 ml-4 list-disc space-y-0.5 leading-relaxed">
                        <li><strong>Statamic Revisions</strong> (if enabled on the collection): open the entry → Revisions → restore.</li>
                        <li><strong>Git</strong> (if your <code>content/</code> is committed): roll the affected entry files back to a previous commit.</li>
                        <li><strong>Hosting backup</strong>: every hoster (Forge, Ploi, Cleavr, your own server) has scheduled snapshots.</li>
                    </ul>
                </Alert>

                <!-- Operation summary header — surfaces the uniform parts of
                     the operation (anchor, target, search term) once at the
                     top, so the table doesn't have to repeat them per row.
                     Mirrors how the DetailModal / SuggestionModal show the
                     intro paragraph above the items table — same convention. -->
                <div class="mb-3 rounded-md bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700/60 p-3 text-sm leading-relaxed">
                    <p class="text-gray-700 dark:text-gray-300" v-html="operationSummary"></p>
                </div>

                <div class="flex items-center justify-between mb-2 gap-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <strong>{{ detail.entries.length }}</strong> {{ detail.entries.length === 1 ? 'entry' : 'entries' }} affected:
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

                <!-- Kind-aware columns — each operation has a different
                     "interesting middle column" the way the DetailModal /
                     SuggestionModal pick different middle columns by mode.
                     applyrule + bulkunlink omit it (uniform via the header);
                     detailunlink shows the anchor+url removed; urlchanger
                     shows the URL swap; inbound/outbound-insert show the
                     anchor + target entry. -->
                <Panel>
                    <div class="overflow-x-auto">
                        <table data-size="sm" class="data-table w-full text-sm">
                            <thead>
                                <tr>
                                    <th scope="col" class="text-left">
                                        <div class="inline-flex items-center gap-1">
                                            {{ entryColumnLabel }}
                                            <HelpIcon :tooltip="entryColumnTooltip" />
                                        </div>
                                    </th>
                                    <th v-if="extraColumnLabel" scope="col" class="text-left">
                                        <div class="inline-flex items-center gap-1">
                                            {{ extraColumnLabel }}
                                            <HelpIcon :tooltip="extraColumnTooltip" />
                                        </div>
                                    </th>
                                    <th scope="col" class="text-left">
                                        <div class="inline-flex items-center gap-1">
                                            Status since bulk
                                            <HelpIcon tooltip="Compares the entry's current content to its state right after the bulk. 'Unchanged' means no edits since. 'Edited' means a user touched the entry — Revert would skip it. 'Deleted' means the entry no longer exists. '—' (legacy) means this snapshot was recorded before post-hash tracking shipped, so the comparison isn't possible." />
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in detail.entries" :key="e.id">
                                    <td>
                                        <a v-if="e.edit_url" :href="e.edit_url" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">{{ e.title }}</a>
                                        <span v-else>{{ e.title }}</span>
                                        <span v-if="e.collection" class="ml-2 text-xs text-gray-400">{{ e.collection }}</span>
                                    </td>
                                    <td v-if="extraColumnLabel" class="text-xs text-gray-600 dark:text-gray-400">
                                        <div v-if="entryExtraCell(e)" class="space-y-0.5" v-html="entryExtraCell(e)"></div>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="text-xs">
                                        <span v-if="e.status === 'unknown'" class="text-gray-400" v-tooltip="'This snapshot was recorded before per-entry post-hash tracking shipped — comparison with the current state is not possible.'">—</span>
                                        <Badge v-else :variant="statusVariant(e.status)" :text="statusLabel(e.status)" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>
        </Stack>

        <!-- Revert confirmation modal — fired from the drawer's "Revert…" button.
             Surfaces the planned-effects preview (X will be reverted, Y were
             modified since and will be skipped) so the user can decide. -->
        <ConfirmationModal
            :open="confirmRevert"
            @update:open="val => confirmRevert = val"
            @confirm="doRevert"
            :title="'Revert this operation?'"
            :body-text="confirmBodyText"
            button-text="Revert"
            :busy="reverting"
        />
    </LinkwiseLayout>
</template>

<script>
import LinkwiseLayout from '../LinkwiseLayout.vue';
import HelpIcon from '../shared/HelpIcon.vue';
import { Card, Panel, Button, Badge, Stack, Alert, ConfirmationModal } from '@statamic/cms/ui';
import { isReversible, nonReversibleReason as computeNonReversibleReason, buildRevertRequest } from '../../services/revertHelper.js';
import { setHeavyState } from '../../services/bulkOperationService.js';

export default {
    components: { LinkwiseLayout, HelpIcon, Card, Panel, Button, Badge, Stack, Alert, ConfirmationModal },

    props: {
        snapshots: { type: Array, default: () => [] },
        detailUrl: { type: String, required: true },
        markRevertedUrl: { type: String, default: '' },
        revertEndpoints: { type: Object, default: () => ({}) },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
    },

    data() {
        return {
            detail: null,
            detailLoading: false,
            confirmRevert: false,
            reverting: false,
        };
    },

    computed: {
        detailTitle() {
            if (!this.detail) return '';
            return this.kindLabel(this.detail.snapshot) + ' details';
        },

        canRevert() {
            return this.detail && isReversible(this.detail.snapshot);
        },

        nonReversibleReason() {
            return this.detail ? computeNonReversibleReason(this.detail.snapshot) : null;
        },

        revertExplanation() {
            if (!this.detail) return '';
            const snap = this.detail.snapshot;
            const items = snap.items || [];
            const modifiedCount = (this.detail.entries || []).filter(e => e.status === 'modified').length;
            const deletedCount = (this.detail.entries || []).filter(e => e.status === 'deleted').length;
            const skippable = modifiedCount + deletedCount;

            // For detailunlink, only internal-link items are reversible.
            // Show the user how many of their N items will actually re-link.
            let revertableItems = items.length;
            let externalSkipped = 0;
            if (snap.kind === 'detailunlink') {
                const internal = items.filter(i =>
                    typeof i.matched_url === 'string' && i.matched_url.startsWith('statamic://entry::')
                ).length;
                externalSkipped = items.length - internal;
                revertableItems = internal;
            }

            const willRevert = Math.max(0, revertableItems - skippable);
            const verb =
                snap.kind === 'urlchanger' ? 're-replace URLs' :
                snap.kind === 'detailunlink' ? 're-link' :
                'unlink the inserted links';

            const parts = [`Linkwise will ${verb} for ${willRevert} item${willRevert === 1 ? '' : 's'} via the same heavy-bulk pipeline. Progress shows in the global banner.`];
            if (skippable > 0) {
                parts.push(`${skippable} entr${skippable === 1 ? 'y was' : 'ies were'} edited or deleted since this bulk and will be skipped.`);
            }
            if (externalSkipped > 0) {
                parts.push(`${externalSkipped} external link${externalSkipped === 1 ? '' : 's'} can't be auto-re-linked (no target entry to point to) and will be skipped.`);
            }
            return parts.join(' ');
        },

        ageWarning() {
            if (!this.detail) return null;
            const startedAt = this.detail.snapshot.started_at;
            if (!startedAt) return null;
            const days = Math.floor((Date.now() - new Date(startedAt).getTime()) / 86400000);
            if (days >= 7) {
                return `Note: this operation is ${days} day${days === 1 ? '' : 's'} old. Other edits made since then may overlap with the revert.`;
            }
            return null;
        },

        confirmBodyText() {
            if (!this.detail) return '';
            return this.revertExplanation;
        },

        // ─── Kind-aware drawer chrome ─────────────────────────────────
        // The summary block + the table columns vary by snapshot kind so
        // the activity-log feels like the original DetailModal / Suggestion-
        // Modal, where every mode shows the columns that matter for that op.

        /** One-paragraph summary of what the operation did. Anchor + target
         *  for uniform ops (single-rule apply), counts for non-uniform ones. */
        operationSummary() {
            if (!this.detail) return '';
            const snap = this.detail.snapshot;
            const sum = snap.summary || {};
            const items = snap.items || [];
            const n = (snap.entry_count_total ?? snap.entry_ids?.length ?? 0);
            const firstItem = items[0] || {};

            if (snap.kind === 'applyrule') {
                if (sum.mode === 'multi-rule') {
                    return `Applied <strong>${sum.total_rules || items.length}</strong> auto-link rules — <strong>${sum.total_links_added || 0}</strong> link${(sum.total_links_added || 0) === 1 ? '' : 's'} inserted across <strong>${n}</strong> entries.`;
                }
                const anchor = sum.rule_keyword ? `<span class="font-mono">"${this.escape(sum.rule_keyword)}"</span>` : 'rule';
                const target = this.targetLabel(firstItem, 'url');
                return `Inserted ${anchor} → ${target} across <strong>${n}</strong> ${n === 1 ? 'entry' : 'entries'}.`;
            }
            if (snap.kind === 'detailunlink') {
                const mode = sum.source_mode || 'inbound';
                const titleHtml = sum.entry_title ? `<strong>"${this.escape(sum.entry_title)}"</strong>` : '<em>this entry</em>';
                if (mode === 'inbound') {
                    return `Removed <strong>${items.length}</strong> inbound link${items.length === 1 ? '' : 's'} pointing to ${titleHtml} — across ${n} source ${n === 1 ? 'entry' : 'entries'}.`;
                }
                return `Removed <strong>${items.length}</strong> outbound link${items.length === 1 ? '' : 's'} from ${titleHtml}.`;
            }
            if (snap.kind === 'inboundinsert') {
                const titleHtml = sum.entry_title ? `<strong>"${this.escape(sum.entry_title)}"</strong>` : '<em>the target entry</em>';
                return `Inserted <strong>${items.length}</strong> inbound link${items.length === 1 ? '' : 's'} pointing to ${titleHtml} — across ${n} source ${n === 1 ? 'entry' : 'entries'}.`;
            }
            if (snap.kind === 'outboundinsert') {
                const titleHtml = sum.entry_title ? `<strong>"${this.escape(sum.entry_title)}"</strong>` : '<em>the source entry</em>';
                return `Inserted <strong>${items.length}</strong> outbound link${items.length === 1 ? '' : 's'} from ${titleHtml}.`;
            }
            if (snap.kind === 'urlchanger') {
                if (sum.search) {
                    const action = sum.action === 'unlink' ? 'Unlinked' : 'Replaced';
                    return `${action} URLs matching <span class="font-mono">"${this.escape(sum.search)}"</span> across <strong>${n}</strong> ${n === 1 ? 'entry' : 'entries'} (<strong>${items.length}</strong> URL${items.length === 1 ? '' : 's'} in total).`;
                }
                return `Replaced <strong>${items.length}</strong> URL${items.length === 1 ? '' : 's'} across <strong>${n}</strong> ${n === 1 ? 'entry' : 'entries'}.`;
            }
            if (snap.kind === 'bulkunlink') {
                return `Removed <strong>${items.length}</strong> broken link${items.length === 1 ? '' : 's'} across <strong>${n}</strong> ${n === 1 ? 'entry' : 'entries'}.`;
            }
            return `Operation affected <strong>${n}</strong> ${n === 1 ? 'entry' : 'entries'}.`;
        },

        /** Header for the first table column. Different ops have different
         *  natural names for the entry being modified. */
        entryColumnLabel() {
            const k = this.detail?.snapshot?.kind || '';
            if (k === 'inboundinsert' || k === 'detailunlink') {
                const mode = this.detail?.snapshot?.summary?.source_mode || 'inbound';
                if (mode === 'inbound') return 'Source entry';
            }
            return 'Affected entry';
        },

        entryColumnTooltip() {
            const k = this.detail?.snapshot?.kind || '';
            if (k === 'inboundinsert' || (k === 'detailunlink' && this.detail?.snapshot?.summary?.source_mode === 'inbound')) {
                return 'The entry that contains (or contained) the link — i.e. the source side of the link relationship. Click to open in Statamic.';
            }
            return 'The entry where Linkwise wrote — its content received the change. Click to open in Statamic.';
        },

        /** Per-kind extra column — null means "no extra column, header carries
         *  enough context". Returned object: { label, tooltip }. */
        extraColumnConfig() {
            const k = this.detail?.snapshot?.kind || '';
            if (k === 'detailunlink') {
                return { label: 'Removed link', tooltip: 'The link Linkwise removed from this entry — anchor text plus its destination URL.' };
            }
            if (k === 'urlchanger') {
                return { label: 'URL change', tooltip: 'Old URL replaced by the new URL on this entry.' };
            }
            if (k === 'inboundinsert' || k === 'outboundinsert') {
                return { label: 'Anchor → Target', tooltip: 'The anchor text Linkwise inserted, plus the entry it now points at.' };
            }
            // applyrule + bulkunlink + multi-rule applyrule: header carries it.
            return null;
        },

        extraColumnLabel() { return this.extraColumnConfig?.label || null; },
        extraColumnTooltip() { return this.extraColumnConfig?.tooltip || ''; },
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

        async doRevert() {
            this.confirmRevert = false;
            if (!this.detail || this.reverting) return;

            const request = buildRevertRequest(this.detail.snapshot, this.revertEndpoints);
            if (!request) {
                Statamic.$toast.error('This operation cannot be reverted.');
                return;
            }

            this.reverting = true;
            const csrfToken = Statamic.$config.get('csrfToken');
            try {
                const response = await fetch(request.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(request.payload),
                });
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const reason = data?.error || data?.message || `HTTP ${response.status}`;
                    Statamic.$toast.error(`Could not start revert: ${reason}`);
                    this.reverting = false;
                    return;
                }
                Statamic.$toast.success('Revert started — see banner above for progress.');

                // Mark the original snapshot as reverted. Best-effort — the
                // revert bulk runs server-side regardless; this just hides
                // the Revert button on the activity-log row.
                if (this.markRevertedUrl) {
                    const markUrl = this.markRevertedUrl.replace('__ID__', encodeURIComponent(this.detail.snapshot.id));
                    fetch(markUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({}),
                    }).catch(() => {});
                }

                // Banner state — heavy bulk runs detached, LinkwiseLayout's
                // poller will refresh as the revert progresses across tabs.
                setHeavyState({
                    kind: request.kindLabel === 'replace' ? 'urlchanger' : 'detailunlink',
                    label: 'reverting bulk',
                    current: 0,
                    total: (request.payload.replacements || []).length,
                    canCancel: false,
                    cancelUrl: null,
                    heartbeat: Math.floor(Date.now() / 1000),
                    context: { entryTitle: 'Revert', sourceMode: request.payload.source_mode || '' },
                });

                // Close drawer + reload activity-log so the new snapshot + the
                // [Reverted] badge on the original both appear immediately.
                this.detail = null;
                setTimeout(() => this.$inertia.reload({ only: ['snapshots'] }), 600);
            } catch (e) {
                Statamic.$toast.error(`Could not start revert: ${e.message || 'network error'}`);
            } finally {
                this.reverting = false;
            }
        },

        kindLabel(snapOrKind) {
            // Accepts either a snapshot (preferred — can sharpen the label
            // with kind-specific context like inbound vs outbound) or a raw
            // kind string (legacy callers).
            const snap = typeof snapOrKind === 'string' ? { kind: snapOrKind } : snapOrKind;
            const kind = snap?.kind || '';
            const mode = snap?.summary?.source_mode || '';

            if (kind === 'detailunlink') {
                if (mode === 'inbound') return 'Bulk unlink inbound links';
                if (mode === 'outbound') return 'Bulk unlink outbound links';
                return 'Bulk unlink links';
            }
            return ({
                applyrule: 'Apply auto-link rule',
                bulkunlink: 'Bulk unlink broken links',
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

        // Build human-readable "what happened" lines per entry, one per item.
        // Each kind has different shape; the column shouldn't just dump the
        // raw anchor text or URL — that's what triggered the "Laravel" complaint.
        entryActionLines(e) {
            const kind = this.detail?.snapshot?.kind || '';
            const items = e.items || [];
            return items.map(it => {
                const anchor = it.anchor_text ? `<span class="font-mono">"${this.escape(it.anchor_text)}"</span>` : '';
                if (kind === 'applyrule') {
                    return `Inserted ${anchor || 'link'} → ${this.targetLabel(it, 'url')}`;
                }
                if (kind === 'inboundinsert' || kind === 'outboundinsert') {
                    const dir = kind === 'inboundinsert' ? 'inbound' : 'outbound';
                    return `Inserted ${dir} link ${anchor || ''} → ${this.targetLabel(it, 'target_entry_id')}`;
                }
                if (kind === 'detailunlink') {
                    return `Removed ${anchor || 'link'} → ${this.targetLabel(it, 'matched_url')}`;
                }
                if (kind === 'urlchanger') {
                    return `Replaced ${this.targetLabel(it, 'matched_url')} → ${this.targetLabel(it, 'new_url')}`;
                }
                if (kind === 'bulkunlink') {
                    return `Removed broken link → ${this.targetLabel(it, 'matched_url')}`;
                }
                return '';
            }).filter(Boolean);
        },

        // Per-row content for the kind-aware extra column (the middle one
        // between Affected entry and Status). Returns HTML, rendered via
        // v-html. Mirrors how the original DetailModal / SuggestionModal
        // pick a different per-item summary based on mode.
        entryExtraCell(e) {
            const k = this.detail?.snapshot?.kind || '';
            const items = e.items || [];
            if (items.length === 0) return '';
            const lines = items.map(it => {
                const anchor = it.anchor_text ? `<span class="font-mono">"${this.escape(it.anchor_text)}"</span>` : '';
                if (k === 'detailunlink') {
                    return `${anchor || '<em>link</em>'} → ${this.targetLabel(it, 'matched_url')}`;
                }
                if (k === 'urlchanger') {
                    return `${this.targetLabel(it, 'matched_url')} <span class="text-gray-400">→</span> ${this.targetLabel(it, 'new_url')}`;
                }
                if (k === 'inboundinsert' || k === 'outboundinsert') {
                    return `${anchor || '<em>link</em>'} → ${this.targetLabel(it, 'target_entry_id')}`;
                }
                return '';
            }).filter(Boolean);
            return lines.map(l => `<div class="leading-snug">${l}</div>`).join('');
        },

        // Render a target reference: prefer the resolved entry title (with
        // an edit-link to Statamic) when the value points at a Statamic entry,
        // otherwise fall back to the truncated raw URL/UUID. Backend fills
        // <field>_title and <field>_edit_url whenever it could resolve them.
        targetLabel(item, field) {
            const titleKey = field + '_title';
            const editKey = field + '_edit_url';
            const raw = item[field];
            if (!raw) return '<span class="text-gray-400">—</span>';
            if (item[titleKey] && item[editKey]) {
                return `<a href="${this.escape(item[editKey])}" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">${this.escape(item[titleKey])}</a>`;
            }
            if (item[titleKey]) {
                return `<span class="text-gray-700 dark:text-gray-300">${this.escape(item[titleKey])}</span>`;
            }
            return `<span class="text-gray-500">${this.escape(this.truncateUrl(raw))}</span>`;
        },

        // Tiny escape so v-html-rendered action lines can't bleed user content
        // into HTML. Anchor text and URLs come from snapshot files which are
        // technically trustable, but a stray < in an anchor would still mess
        // up rendering — better safe.
        escape(s) {
            if (s == null) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
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
