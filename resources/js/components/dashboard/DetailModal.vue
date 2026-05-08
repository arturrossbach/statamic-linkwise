<template>
    <Stack :open="modal !== null" @update:open="close" :title="modal?.title || ''">
        <div v-if="modal">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                <template v-if="modal.mode === 'inbound'">
                    <strong>{{ modal.items.length }}</strong> link(s) from other entries pointing <strong>to this page</strong>. Select links and click "Unlink" to remove them (the text will be preserved).
                </template>
                <template v-else>
                    <strong>{{ modal.items.length }}</strong> link(s) from this entry pointing <strong>to other pages</strong>. Select links and click "Unlink" to remove them (the text will be preserved).
                </template>
            </p>

            <div v-if="modal.items.length === 0" class="py-4 text-center text-gray-400">
                <p>No links found.</p>
                <p v-if="modal.mode === 'inbound'" class="mt-2 text-xs">
                    Use the "Add Inbound Links" action to find entries that could link here.
                </p>
            </div>
            <template v-else>
                <!-- Action bar + filter -->
                <div class="flex items-center justify-between h-9 mb-3">
                    <div class="flex items-center gap-3">
                        <Button v-if="applyUrl && selected.length > 0 && !unlinking && !globalBulkRunning" @click="confirmUnlink" :text="'Unlink ' + selected.length + ' selected'" size="sm" />
                        <span v-if="unlinking" class="text-xs text-gray-500 dark:text-gray-400 inline-flex items-center gap-2" role="status" aria-live="polite">
                            <Icon name="loading" class="size-4 text-blue-500" />
                            Unlinking — see banner above for progress.
                        </span>
                        <span v-else-if="selected.length > 0 && globalBulkRunning" class="text-xs text-gray-500 dark:text-gray-400" role="status" aria-live="polite">
                            Another bulk operation is running — wait for it to finish.
                        </span>
                        <!-- Re-link mirrors Unlink's gating: blocked while ANY bulk is
                             running (server would 409 on hash mismatch / JobLock collision
                             anyway, but a UI-side block spares the user the failure toast). -->
                        <Button v-if="applyUrl && modifiedSelected.length > 0 && !relinking && !unlinking && !globalBulkRunning" @click="executeRelink" :loading="relinking" :text="'Re-link ' + modifiedSelected.length + ' modified'" variant="primary" size="sm" />
                        <span v-else-if="modifiedSelected.length > 0 && globalBulkRunning && !relinking" class="text-xs text-gray-500 dark:text-gray-400" role="status" aria-live="polite">
                            Wait for the running bulk to finish before re-linking.
                        </span>
                    </div>
                    <label v-if="modal.mode === 'outbound' && modal.items.some(i => i.warning)" class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" v-model="selfLinkOnly" class="rounded">
                        Self-links only
                    </label>
                </div>

                <Panel>
                    <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                        <thead>
                            <tr>
                                <th v-if="applyUrl" scope="col" class="w-8">
                                    <input type="checkbox" class="rounded" :checked="allSelected" @change="toggleSelectAll" />
                                </th>
                                <template v-if="modal.mode === 'outbound'">
                                    <SortableHeader label="Anchor Text" :active="sortField === 'anchor_text'" :direction="sortDir" @sort="toggleSort('anchor_text')">
                                        <HelpIcon tooltip="The clickable text of the link as it appears in the content." />
                                    </SortableHeader>
                                    <SortableHeader label="Target" :active="sortField === 'title'" :direction="sortDir" @sort="toggleSort('title')" />
                                    <SortableHeader label="Type" align="center" :active="sortField === 'type'" :direction="sortDir" @sort="toggleSort('type')" />
                                </template>
                                <template v-else>
                                    <SortableHeader label="Source Entry" :active="sortField === 'title'" :direction="sortDir" @sort="toggleSort('title')">
                                        <HelpIcon tooltip="The entry that contains a link pointing to this page." />
                                    </SortableHeader>
                                    <SortableHeader label="Anchor Text" :active="sortField === 'anchor_text'" :direction="sortDir" @sort="toggleSort('anchor_text')" />
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, idx) in filteredItems" :key="`${item.id || item.url}-${idx}`" :class="{ 'opacity-40 line-through': item._unlinked }">
                                <td v-if="applyUrl">
                                    <input v-if="!item._unlinked && !item._unlinking" type="checkbox" class="rounded" :checked="selected.includes(item)" @change="toggleSelect(item)" />
                                    <Icon v-else-if="item._unlinking" name="loading" class="size-4 text-blue-500" />
                                    <span v-else class="text-xs text-green-500" aria-label="Unlinked">&#10003;</span>
                                </td>
                                <template v-if="modal.mode === 'outbound'">
                                    <td>
                                        <SuggestedPhrase
                                            v-if="item.anchor_text && item.sentence_context"
                                            :sentence-context="item.sentence_context"
                                            :anchor="item._anchor"
                                            :original-anchor="item._originalAnchor"
                                            :disabled="!!item._unlinked"
                                            :truncated-before="item.context_truncated_start || false"
                                            :truncated-after="item.context_truncated_end || false"
                                            @update:anchor="item._anchor = $event"
                                            @reset="item._anchor = item._originalAnchor"
                                        />
                                        <span v-else-if="item.anchor_text" class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ item.anchor_text }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="text-xs break-all">
                                        <template v-if="item.type === 'external'">
                                            <a :href="item.url" target="_blank" rel="noopener" class="text-gray-700 dark:text-gray-300 hover:underline">{{ item.url }}</a>
                                        </template>
                                        <template v-else>
                                            <a v-if="item.edit_url" :href="item.edit_url" target="_blank" class="hover:text-blue-600 dark:hover:text-blue-400">{{ item.title }}</a>
                                            <span v-else class="text-gray-500">{{ item.title }}</span>
                                        </template>
                                    </td>
                                    <td class="text-center whitespace-nowrap">
                                        <span class="text-xs text-gray-500">{{ item.type }}</span>
                                        <span v-if="item.warning" class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400" v-tooltip="item.warning">!</span>
                                    </td>
                                </template>
                                <template v-else>
                                    <td>
                                        <a v-if="item.edit_url" :href="item.edit_url" target="_blank" class="hover:text-blue-600 dark:hover:text-blue-400">{{ item.title }}</a>
                                        <span v-else>{{ item.title }}</span>
                                        <div class="text-xs text-gray-400">{{ item.collection }}</div>
                                    </td>
                                    <td>
                                        <SuggestedPhrase
                                            v-if="item.anchor_text && item.sentence_context"
                                            :sentence-context="item.sentence_context"
                                            :anchor="item._anchor"
                                            :original-anchor="item._originalAnchor"
                                            :disabled="!!item._unlinked"
                                            :truncated-before="item.context_truncated_start || false"
                                            :truncated-after="item.context_truncated_end || false"
                                            @update:anchor="item._anchor = $event"
                                            @reset="item._anchor = item._originalAnchor"
                                        />
                                        <span v-else-if="item.anchor_text" class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ item.anchor_text }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </table></div>
                </Panel>
            </template>
        </div>

        <ConfirmationModal
            :open="showUnlinkConfirm"
            title="Remove selected links?"
            :body-text="`Remove ${selected.length} link(s)? The text will remain but will no longer be linked.`"
            button-text="Unlink"
            danger
            :busy="unlinking"
            @update:open="val => showUnlinkConfirm = val"
            @confirm="executeUnlink"
        />
    </Stack>
</template>

<script>
import { Panel, Button, Stack, Icon, ConfirmationModal } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import SuggestedPhrase from '../shared/SuggestedPhrase.vue';
import { applyUrlReplacements, UNLINK_SENTINEL } from '../shared/urlReplacer.js';
import { bulkState, setHeavyState } from '../../services/bulkOperationService.js';
import { errorToast } from '../../utils/toast.js';

export default {
    components: { Panel, Button, Stack, Icon, ConfirmationModal, HelpIcon, SortableHeader, SuggestedPhrase },

    props: {
        modal: { type: Object, default: null },
        applyUrl: { type: String, default: '' },
        inboundInsertUrl: { type: String, default: '' },
        outboundInsertUrl: { type: String, default: '' },
        entries: { type: Array, default: () => [] },
    },

    emits: ['close'],

    data() {
        return {
            selected: [],
            sortField: '',
            sortDir: 'asc',
            selfLinkOnly: false,
            unlinking: false,
            relinking: false,
            showUnlinkConfirm: false,
        };
    },

    watch: {
        modal(val) {
            if (val) {
                this.selected = [];
                this.sortField = '';
                this.sortDir = 'asc';
                this.selfLinkOnly = false;
                // Reset operation state too — without this, progress from a previous
                // modal session can leak into a new open.
                this.unlinking = false;
                this.relinking = false;
                this.showUnlinkConfirm = false;
            }
        },
    },

    computed: {
        // True while ANY bulk operation (insert OR unlink) is running anywhere
        // in Linkwise. Used to disable the unlink button so the user can't
        // queue a second bulk while the first is in flight.
        globalBulkRunning() {
            return bulkState.active !== null;
        },

        filteredItems() {
            if (!this.modal) return [];
            let items = this.modal.items;

            if (this.selfLinkOnly && this.modal.mode === 'outbound') {
                items = items.filter(i => i.warning && i.warning.includes('Self-link'));
            }

            if (this.sortField) {
                const dir = this.sortDir === 'asc' ? 1 : -1;
                const field = this.sortField;
                items = [...items].sort((a, b) => {
                    const aVal = a[field] || '';
                    const bVal = b[field] || '';
                    return String(aVal).localeCompare(String(bVal)) * dir;
                });
            }

            return items;
        },

        modifiedSelected() {
            return this.selected.filter(i => i._anchor && i._anchor !== i._originalAnchor);
        },

        allSelected() {
            if (!this.modal) return false;
            const selectable = this.modal.items.filter(i => !i._unlinked);
            return selectable.length > 0 && selectable.every(i => this.selected.includes(i));
        },
    },

    methods: {
        close() {
            // Only block close for LIGHT bulks — those run as a frontend loop
            // that dies the moment the modal unmounts, leaving the user without
            // a path back to a half-finished operation.
            //
            // HEAVY bulks (detail-unlink-async, etc.) live in a detached artisan
            // process. The status banner is rendered globally by LinkwiseLayout
            // and reattaches on every Linkwise tab — closing the modal here is
            // safe AND necessary: keeping it open leaves the Statamic Stack's
            // fixed-inset overlay over the table dahinter, swallowing clicks
            // on the count badges (#49). The watcher in executeUnlink resets
            // local state when the bulk completes, even if the modal is closed.
            const isHeavy = bulkState.active?.source === 'heavy';
            if ((this.unlinking || this.relinking) && ! isHeavy) {
                Statamic.$toast.info('Wait for the operation to finish before closing.');
                return;
            }
            this.$emit('close');
        },

        ariaSortFor(field) {
            if (this.sortField !== field) return 'none';
            return this.sortDir === 'asc' ? 'ascending' : 'descending';
        },

        toggleSort(field) {
            if (this.sortField === field) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortField = field;
                this.sortDir = 'asc';
            }
        },

        toggleSelect(item) {
            const idx = this.selected.indexOf(item);
            if (idx > -1) this.selected.splice(idx, 1);
            else this.selected.push(item);
        },

        toggleSelectAll() {
            const selectable = this.modal.items.filter(i => !i._unlinked);
            if (this.allSelected) {
                this.selected = [];
            } else {
                this.selected = [...selectable];
            }
        },

        confirmUnlink() {
            if (this.selected.length === 0) return;
            this.showUnlinkConfirm = true;
        },

        async executeUnlink() {
            this.showUnlinkConfirm = false;

            // Bail synchronously if any bulk op is already running. Without this,
            // the local `unlinking` flag would flip to true even though the
            // service rejected the call, leaving the modal stuck on
            // "Unlinking..." forever.
            if (bulkState.active) {
                Statamic.$toast.info('Another bulk operation is running — wait for it to finish.');
                return;
            }

            // Snapshot everything we need BEFORE dispatch — the heavy job
            // outlives the modal/tab/page, so we can't reach back into them.
            const items = [...this.selected];
            if (items.length === 0) return;
            const mode = this.modal.mode;
            const sourceEntryId = this.modal.entryId;
            const entryTitle = this.modal.entryTitle || '';
            const total = items.length;

            // Build replacements + hash lookup. For outbound, all unlinks
            // target the same (source) entry. For inbound, each unlink
            // targets a DIFFERENT entry (the inbound-linker).
            const replacements = items.map(item => {
                const entryId = mode === 'outbound' ? sourceEntryId : item.id;
                return {
                    entry_id: entryId,
                    search: item.url,
                    field: item.field || '',
                    field_type: item.field_type || '',
                    matched_url: item.url,
                    occurrence_index: item.occurrence_index ?? 0,
                };
            });
            const entryHashes = {};
            for (const r of replacements) {
                if (!(r.entry_id in entryHashes)) entryHashes[r.entry_id] = this.getEntryHash(r.entry_id);
            }

            this.unlinking = true;
            this.unlinkProgress = '';

            try {
                const response = await fetch(this.cp_url('linkwise/detail-unlink-async'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        replacements,
                        entry_hashes: entryHashes,
                        source_mode: mode,
                        entry_title: entryTitle,
                    }),
                });
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const reason = data?.error || data?.message || `HTTP ${response.status}`;
                    errorToast(`Could not start: ${reason}`);
                    this.unlinking = false;
                    return;
                }
            } catch (e) {
                errorToast(`Could not start: ${e.message || 'network error'}`);
                this.unlinking = false;
                return;
            }

            // Immediate confirmation — heavy job runs server-side, banner
            // appears for everyone (including this user, even after closing
            // the modal). Sofort-Toast for UX clarity.
            Statamic.$toast.success(`Started — removing ${total} link${total === 1 ? '' : 's'} in the background.`);

            setHeavyState({
                kind: 'detailunlink',
                label: 'remove links',
                current: 0,
                total,
                canCancel: true,
                cancelUrl: this.cp_url('linkwise/detail-unlink-async/cancel'),
                heartbeat: Math.floor(Date.now() / 1000),
                context: { entryTitle, sourceMode: mode },
            });

            // Watch for terminal phase — close modal local-state then.
            const stop = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    if (previous?.kind === 'detailunlink' && current === null) {
                        stop();
                        this.unlinking = false;
                        if (this.modal) this.selected = [];
                    }
                },
            );
            setTimeout(() => stop(), 30 * 60 * 1000);
        },

        async executeRelink() {
            this.relinking = true;
            const items = [...this.modifiedSelected];
            let succeeded = 0;
            let failed = 0;

            const csrfToken = Statamic.$config.get('csrfToken');
            const insertUrl = this.modal.mode === 'outbound' ? this.outboundInsertUrl : this.inboundInsertUrl;

            for (const item of items) {
                const entryId = this.modal.mode === 'outbound'
                    ? this.modal.entryId
                    : item.id;
                const entryHash = this.getEntryHash(entryId);

                try {
                    // Step 1: Unlink old anchor
                    const unlinkResult = await applyUrlReplacements(
                        this.applyUrl,
                        item.url,
                        [{
                            entry_id: entryId,
                            field: '',
                            field_type: '',
                            matched_url: item.url,
                            occurrence_index: item.occurrence_index ?? 0,
                            new_url: UNLINK_SENTINEL,
                        }],
                        entryHash ? { [entryId]: entryHash } : {},
                    );

                    // Update hash after unlink
                    if (unlinkResult?.updated_hashes) {
                        for (const [eid, newHash] of Object.entries(unlinkResult.updated_hashes)) {
                            const e = this.entries.find(x => x.id === eid);
                            if (e) e.content_hash = newHash;
                        }
                    }

                    // Step 2: Insert new link with modified anchor
                    const targetEntryId = item.url.replace('statamic://entry::', '');
                    const body = this.modal.mode === 'outbound'
                        ? {
                            entry_id: entryId,
                            content_hash: this.getEntryHash(entryId),
                            insertions: [{ target_entry_id: targetEntryId, anchor_text: item._anchor, sentence_context: item.sentence_context || '' }],
                        }
                        : {
                            entry_hashes: { [entryId]: this.getEntryHash(entryId) },
                            insertions: [{ source_entry_id: entryId, target_entry_id: targetEntryId, anchor_text: item._anchor, sentence_context: item.sentence_context || '' }],
                        };

                    const response = await fetch(insertUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(body),
                    });

                    if (response.ok) {
                        const data = await response.json();
                        if (data.results?.[0]?.success) {
                            item._unlinked = false;
                            item._originalAnchor = item._anchor;
                            item.anchor_text = item._anchor;
                            succeeded++;
                        } else {
                            failed++;
                        }
                    } else {
                        failed++;
                    }
                } catch {
                    failed++;
                }
            }

            this.selected = [];
            this.relinking = false;

            if (succeeded > 0) Statamic.$toast.success(`${succeeded} link(s) re-linked.`);
            if (failed > 0) Statamic.$toast.error(`${failed} re-link(s) failed.`);
        },

        getEntryHash(entryId) {
            const entry = this.entries.find(e => e.id === entryId);
            return entry?.content_hash || '';
        },
    },
};
</script>
