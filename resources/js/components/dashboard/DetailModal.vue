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
                                            :anchor-offset-in-context="Number.isInteger(item.anchor_offset_in_context) ? item.anchor_offset_in_context : null"
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
                                            <BardBadge v-if="item.id" :entry-id="item.id" class="ml-1.5" />
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
                                        <BardBadge v-if="item.id" :entry-id="item.id" class="ml-1.5" />
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
                                            :anchor-offset-in-context="Number.isInteger(item.anchor_offset_in_context) ? item.anchor_offset_in_context : null"
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
import BardBadge from '../shared/BardBadge.vue';
import { bulkState, setHeavyState, runBulkOperation, recordCompletion } from '../../services/bulkOperationService.js';
import { errorToast } from '../../utils/toast.js';

export default {
    components: { Panel, Button, Stack, Icon, ConfirmationModal, HelpIcon, SortableHeader, SuggestedPhrase, BardBadge },

    props: {
        modal: { type: Object, default: null },
        applyUrl: { type: String, default: '' },
        relinkUrl: { type: String, default: '' },
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
                    // Carried into the snapshot so the activity-log drawer's
                    // Context column shows the sentence + anchor (and a
                    // future Unlink-revert can re-insert at the same spot).
                    anchor_text: item.anchor_text || '',
                    sentence_context: item.sentence_context || '',
                };
            });
            const entryHashes = {};
            for (const r of replacements) {
                if (!(r.entry_id in entryHashes)) entryHashes[r.entry_id] = this.getEntryHash(r.entry_id);
            }

            this.unlinking = true;

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
                    if (response.status === 409) {
                        // Backend conflict response carries a user-readable
                        // "Entry was modified by another editor..." in
                        // data.message; data.error is a machine code
                        // ("conflict") that's useless as a toast. Surface
                        // the message; align with the Re-link path which
                        // already shows "modified" cleanly.
                        errorToast(data?.message || 'Entry was modified by another editor — reload and try again.');
                    } else {
                        const reason = data?.message || data?.error || `HTTP ${response.status}`;
                        errorToast(`Could not start: ${reason}`);
                    }
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
            // Snapshot what we're about to unlink so the terminal watcher
            // can mark them _unlinked when the bulk completes. Without this,
            // rows stay visible + interactive after a successful unlink and
            // confuse the user into thinking the operation didn't run.
            const itemsBeingUnlinked = items;

            const stop = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    if (previous?.kind === 'detailunlink' && current === null) {
                        stop();
                        this.unlinking = false;

                        // Only mark items as _unlinked when we know the bulk
                        // landed cleanly. Skipped > 0 means the per-item
                        // outcome is mixed (some written, some refused) and
                        // we cannot tell from completion stats alone WHICH
                        // items succeeded — marking all of them as _unlinked
                        // would lie about the skipped ones (they still
                        // carry the link). Honest fallback: leave the rows
                        // alone and tell the user to refresh.
                        const completion = bulkState.lastCompletion;
                        const skipped = completion?.kind === 'detailunlink'
                            ? (completion?.extra?.skipped ?? 0)
                            : 0;
                        if (completion?.kind === 'detailunlink' && skipped === 0) {
                            itemsBeingUnlinked.forEach(it => { it._unlinked = true; });
                        } else if (skipped > 0) {
                            Statamic.$toast.info(`${skipped} item(s) skipped — close + reopen the modal to see current state.`);
                        }

                        if (this.modal) this.selected = [];
                    }
                },
            );
            setTimeout(() => stop(), 30 * 60 * 1000);
        },

        /**
         * Re-link selected items whose anchor was edited via SuggestedPhrase.
         * Per item runs unlink-then-insert as a chain. Routed through the
         * shared runBulkOperation wrapper so the user gets the standard
         * Linkwise UX:
         *   - Tab-spanning banner with X / N progress + Cancel button
         *   - Survives modal close + tab navigation (loop continues, mutations
         *     are guarded against an unmounted modal)
         *   - Single completion toast (variant chosen via final state)
         *   - beforeunload guard against accidental tab close
         *   - Concurrency guard against parallel bulks
         *
         * Old implementation was a raw `for (item of items) { await fetch }`
         * loop directly in the component. Closing the modal mid-run left the
         * loop continuing to write `item._anchor = ...` against a now-detached
         * Vue instance — exactly the bug class the bulkOperationService
         * docstring exists to prevent.
         */
        async executeRelink() {
            if (bulkState.active) {
                Statamic.$toast.info('Another bulk operation is running — wait for it to finish.');
                return;
            }

            // Snapshot everything we need BEFORE dispatch — the loop may
            // outlive the modal/tab. We can't reach back into them.
            const items = [...this.modifiedSelected];
            if (items.length === 0) return;
            const mode = this.modal.mode;
            const sourceEntryId = this.modal.entryId;
            const entryTitle = this.modal.entryTitle || '';
            const relinkUrl = this.relinkUrl;
            const csrfToken = Statamic.$config.get('csrfToken');
            // Capture entry-ref array so post-completion `item._unlinked` etc.
            // mutations skip cleanly if the modal has unmounted in between.
            const entriesRef = this.entries;
            const modalAlive = () => this.modal !== null;

            this.relinking = true;

            await runBulkOperation({
                kind: 'detail-relink',
                label: 're-link',
                context: { entryTitle, mode },
                items,
                perItem: async (item) => {
                    const entryId = mode === 'outbound' ? sourceEntryId : item.id;
                    const entryHash = this.getEntryHash(entryId);

                    // Bug 17 Phase C (2026-05-12): single atomic call.
                    // Backend RelinkService removes the existing link mark
                    // and inserts the new one on the SAME in-memory entry
                    // tree, one save, hash-checked. Replaces the previous
                    // pre-flight + Step 1 (url-changer apply unlink) +
                    // Step 2 (async link-insert) chain that produced four
                    // entangled symptoms (partial-state, "removed" not
                    // "re-linked" in activity log, Bulk-Step-2-blocks-
                    // next-Step-1, double-toast).
                    const isStatamicEntryUrl = (item.url || '').startsWith('statamic://entry::');
                    const body = {
                        entry_id: entryId,
                        content_hash: entryHash,
                        original_href: item.url,
                        occurrence_index: item.occurrence_index ?? 0,
                        original_anchor: item.anchor_text || '',
                        new_anchor: item._anchor,
                        sentence_context: item.sentence_context || '',
                        // Bug 19 fix: the sentence_context is a ±N-char window
                        // around the anchor and can span multiple identical
                        // occurrences. The backend uses this offset to narrow
                        // the context-range filter to a single position so a
                        // shrink-re-link cannot wander to an earlier match.
                        anchor_offset_in_context: Number.isInteger(item.anchor_offset_in_context)
                            ? item.anchor_offset_in_context
                            : null,
                    };
                    // Internal links carry target_entry_id; external links
                    // (https://…) carry new_href. The DetailModal re-link
                    // never changes the URL — only the anchor — so target
                    // === original.
                    if (isStatamicEntryUrl) {
                        body.target_entry_id = item.url.replace('statamic://entry::', '');
                    } else {
                        body.new_href = item.url;
                    }

                    let response;
                    try {
                        response = await fetch(relinkUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify(body),
                        });
                    } catch (e) {
                        return { success: false, error: `relink failed: ${e?.message || 'network error'}` };
                    }
                    if (! response.ok) {
                        return { success: false, error: `HTTP ${response.status}` };
                    }
                    let data;
                    try {
                        data = await response.json();
                    } catch {
                        return { success: false, error: 'invalid server response' };
                    }
                    if (data.ok !== true) {
                        // Atomic refusal — RelinkService returns an
                        // action-oriented message; only fall back if
                        // neither message nor reason is set.
                        return {
                            success: false,
                            error: data.message || data.reason || 'Re-link could not be performed',
                        };
                    }

                    // Refresh entry hash post-save so subsequent ops on
                    // the same entry use the latest state. Skipped silently
                    // if entries ref no longer points at our payload
                    // (modal unmounted).
                    if (data.new_hash && Array.isArray(entriesRef)) {
                        const e = entriesRef.find(x => x.id === entryId);
                        if (e) e.content_hash = data.new_hash;
                    }

                    // Mutate the row only when the modal is still alive —
                    // a user who closed mid-run shouldn't get state writes
                    // against a detached component instance.
                    if (modalAlive()) {
                        item._unlinked = false;
                        item._originalAnchor = item._anchor;
                        item.anchor_text = item._anchor;
                    }
                    return { success: true };
                },
            });

            // runBulkOperation already fired the completion toast (single
            // unified line, not 2 separate success/error toasts). Reset
            // local UI state — selected list cleared, button re-enabled.
            this.relinking = false;
            if (modalAlive()) this.selected = [];

            // Sprint 6 follow-up (user-reported 2026-05-16, refined after
            // PR #46 caused a visible "flicker" from a `preserveScroll`
            // partial reload):
            //
            // The per-item success branch above already mutated
            // `item.anchor_text` + `item._originalAnchor` in place — modal
            // rendering reflects the NEW anchor immediately. The stale
            // surface is the PARENT's `localEntries` clone (used by
            // showDetail to rebuild items on the NEXT modal open). Fire
            // a single `recordCompletion('detailrelink')` so the watcher
            // in LinksReportTab refreshes the suggestionCounts endpoint
            // AND triggers `reloadEntries()` (see LinksReportTab handler)
            // to pull fresh `entries`/`internal_links_detail` from the
            // server. The inertia reload runs in the BACKGROUND while the
            // modal stays open — no flicker, no remount.
            try {
                recordCompletion({
                    kind: 'detailrelink',
                    phase: 'done',
                    extra: { heartbeat: Math.floor(Date.now() / 1000) },
                });
            } catch (e) {
                console.error('[Linkwise] recordCompletion(detailrelink) failed:', e);
            }
        },

        getEntryHash(entryId) {
            const entry = this.entries.find(e => e.id === entryId);
            return entry?.content_hash || '';
        },
    },
};
</script>
