<template>
    <Stack :open="previewModal !== null" @update:open="$emit('close')" :title="previewModal?.title || ''">
        <div v-if="previewModal">
            <div v-if="previewModal.loading" class="py-4 text-center text-gray-400">Checking entries...</div>
            <div v-else-if="previewModal.items.length === 0" class="py-4 text-center text-gray-400">No matching entries found.</div>
            <div v-else>
                <!-- Status Filter + Summary + Apply button -->
                <div class="flex items-center justify-between mb-4 gap-4 flex-wrap">
                    <div class="flex items-center gap-3">
                        <select
                            v-if="availablePreviewStatusOptions.length > 1"
                            v-model="previewStatusFilter"
                            aria-label="Filter by status"
                            class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md px-2 py-1.5"
                        >
                            <option value="">All statuses</option>
                            <option v-for="opt in availablePreviewStatusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <strong :class="applyablePreviewCount > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'">{{ applyablePreviewCount }}</strong> will be linked<template v-if="applyablePreviewCount !== wouldLinkCount"> ({{ wouldLinkCount }} matching, {{ wouldLinkCount - applyablePreviewCount }} excluded)</template>,
                            {{ linkedToTargetCount }} linked to target,
                            {{ linkedElsewhereCount }} linked elsewhere<template v-if="notInsertableCount > 0">,
                            {{ notInsertableCount }} cannot insert</template>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="linkedToTargetCount > 0 && previewModal.ruleId"
                            :text="`Unlink (${selectedUnlinkIdsLocal.length})`"
                            :disabled="selectedUnlinkIdsLocal.length === 0 || unlinkingFromPreview"
                            :loading="unlinkingFromPreview"
                            v-tooltip="'Remove the rule\'s link from selected entries (uses the same atomic, conflict-safe save path as DetailModal Bulk Unlink)'"
                            @click="$emit('unlink')"
                        />
                        <Button
                            v-if="wouldLinkCount > 0 && previewModal.ruleId"
                            :text="`Apply (${applyablePreviewCount})`"
                            variant="primary"
                            :disabled="applyablePreviewCount === 0 || applyingPreview"
                            :loading="applyingPreview"
                            @click="$emit('apply')"
                        />
                    </div>
                </div>

                <Panel>
                    <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                        <thead>
                            <tr>
                                <SortableHeader :sortable="false">
                                    <input
                                        type="checkbox"
                                        class="rounded"
                                        :checked="allPreviewRowsSelected"
                                        :indeterminate.prop="somePreviewRowsSelected && !allPreviewRowsSelected"
                                        :disabled="togglablePreviewRowCount === 0"
                                        @change="togglePreviewSelectAll"
                                        v-tooltip="'Toggle every selectable row in this preview (would-link rows for Apply, linked-to-target rows for Unlink)'"
                                        aria-label="Select all selectable rows"
                                    />
                                </SortableHeader>
                                <SortableHeader label="Target Entry" :active="previewSortField === 'title'" :direction="previewSortDirection" @sort="togglePreviewSort('title')" />
                                <SortableHeader label="Context" :sortable="false" />
                                <SortableHeader label="Status" align="center" :active="previewSortField === 'link_status'" :direction="previewSortDirection" @sort="togglePreviewSort('link_status')" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(item, idx) in sortedPreviewItems"
                                :key="`${item.id}-${idx}`"
                                :class="{ 'opacity-50': item.link_status === 'would_link' && excludedEntryIdsLocal.includes(item.id) }"
                            >
                                <td>
                                    <input
                                        v-if="item.link_status === 'would_link'"
                                        type="checkbox"
                                        :checked="!excludedEntryIdsLocal.includes(item.id)"
                                        @change="toggleExclude(item.id)"
                                        class="rounded"
                                        :aria-label="`Include '${item.title}' when applying`"
                                        v-tooltip="'Uncheck to skip this entry when applying'"
                                    />
                                    <input
                                        v-else-if="item.link_status === 'linked_to_target'"
                                        type="checkbox"
                                        :checked="selectedUnlinkIdsLocal.includes(item.id)"
                                        @change="toggleUnlinkSelection(item.id)"
                                        class="rounded"
                                        :aria-label="`Select '${item.title}' for unlink`"
                                        v-tooltip="'Check to include this entry when removing the rule\'s links'"
                                    />
                                </td>
                                <td>
                                    <Link v-if="item.edit_url" :href="item.edit_url" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400">{{ item.title }}</Link>
                                    <span v-else class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.title }}</span>
                                </td>
                                <td class="text-gray-400 dark:text-gray-500 text-xs">
                                    <span v-if="item.sentence_context" v-html="highlightKeyword(item.sentence_context, previewModal.keyword)"></span>
                                    <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                </td>
                                <td class="text-center whitespace-nowrap">
                                    <span v-if="item.link_status === 'linked_to_target'" class="text-xs px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">Linked to target</span>
                                    <span v-else-if="item.link_status === 'linked_elsewhere'" class="text-xs px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400" v-tooltip="'Already linked to a different URL. Use the URL Changer to update it.'">Linked elsewhere</span>
                                    <span v-else-if="item.link_status === 'not_insertable'" class="text-xs px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400" v-tooltip="'Keyword was found in plain text, but Bard cannot insert a link there — the text may span multiple nodes or sit inside a code block.'">Cannot insert</span>
                                    <span v-else class="text-xs px-1.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">Would link</span>
                                </td>
                            </tr>
                        </tbody>
                    </table></div>
                </Panel>
            </div>
        </div>
    </Stack>
</template>

<script>
import { Link } from '@statamic/cms/inertia';
import { Panel, Button, Stack } from '@statamic/cms/ui';
import SortableHeader from '../shared/SortableHeader.vue';
import { highlightKeyword } from '../../utils/highlight.js';

/**
 * Preview-modal status filter options. Order matches the visual sort
 * priority in `sortedPreviewItems` (would_link first, then elsewhere,
 * then not_insertable, then already-linked-to-target).
 */
const PREVIEW_STATUS_OPTIONS = [
    { value: 'would_link', label: 'Would link' },
    { value: 'linked_to_target', label: 'Linked to target' },
    { value: 'linked_elsewhere', label: 'Linked elsewhere' },
    { value: 'not_insertable', label: 'Cannot insert' },
];

/**
 * RulePreviewModal — extracted from AutoLinkingTab.vue in Sprint 5 PR 2e.
 *
 * Variante A (refined): the lifecycle-state — `previewModal`, plus the
 * two selection pools `excludedEntryIds` (would_link rows) and
 * `selectedUnlinkIds` (linked_to_target rows) — stays in the parent
 * because the async paths (previewRule, applyFromPreview,
 * unlinkSelectedFromPreview) need to read them outside the modal's
 * mount window. Pure view-state (sort/filter/derived counts) moves
 * here since no async path or pin-test reads it.
 *
 * Selection pools are exposed via `v-model:excluded-entry-ids` and
 * `v-model:selected-unlink-ids` so the toggle handlers can mutate
 * them through update events without breaking Vue 3's prop-immutability.
 *
 * Actions (`@apply`, `@unlink`, `@close`) are no-arg — the parent
 * already knows `previewModal.ruleId`, the selected ids, and the
 * exclusion list. The child just signals intent.
 */
export default {
    name: 'RulePreviewModal',

    components: { Link, Panel, Button, Stack, SortableHeader },

    props: {
        // Full modal object or null. Owned by parent so async paths can
        // close/null it independently of the modal's open-prop reaction.
        previewModal: { type: Object, default: null },
        // Selection pools (v-model). Parent reads them in
        // applyFromPreview / unlinkSelectedFromPreview.
        excludedEntryIds: { type: Array, default: () => [] },
        selectedUnlinkIds: { type: Array, default: () => [] },
        // Async progress flags — parent owns the lifecycle.
        unlinkingFromPreview: { type: Boolean, default: false },
        applyingPreview: { type: Boolean, default: false },
    },

    emits: [
        'close',
        'apply',
        'unlink',
        'update:excludedEntryIds',
        'update:selectedUnlinkIds',
    ],

    data() {
        return {
            previewSortField: 'title',
            previewSortDirection: 'asc',
            // Single status filter for the Preview table. '' = no filter.
            previewStatusFilter: '',
        };
    },

    watch: {
        // Reset internal sort/filter when a NEW modal opens (ruleId
        // changes) so the user never sees rule B with rule A's filter.
        // The parent's resetPreviewModalState() already clears the
        // selection pools — this watch covers the view-state half.
        'previewModal.ruleId'(newId, oldId) {
            if (newId && newId !== oldId) {
                this.previewSortField = 'title';
                this.previewSortDirection = 'asc';
                this.previewStatusFilter = '';
            }
        },
    },

    computed: {
        // v-model bridges so toggle handlers can mutate via update events.
        excludedEntryIdsLocal: {
            get() { return this.excludedEntryIds; },
            set(v) { this.$emit('update:excludedEntryIds', v); },
        },
        selectedUnlinkIdsLocal: {
            get() { return this.selectedUnlinkIds; },
            set(v) { this.$emit('update:selectedUnlinkIds', v); },
        },

        // Only show filter options that actually appear in this preview's items.
        availablePreviewStatusOptions() {
            const presentStatuses = new Set();
            for (const i of this.previewModal?.items || []) {
                if (i.link_status) presentStatuses.add(i.link_status);
            }
            return PREVIEW_STATUS_OPTIONS.filter(o => presentStatuses.has(o.value));
        },

        /**
         * Flat list for the Preview table. Applies user-chosen status filter, then
         * sort (previewSortField / previewSortDirection). Tie-breaker keeps
         * multi-occurrence rows of the same entry together.
         */
        sortedPreviewItems() {
            let items = [...(this.previewModal?.items || [])];
            if (this.previewStatusFilter) {
                items = items.filter(i => i.link_status === this.previewStatusFilter);
            }
            const statusRank = { would_link: 0, linked_elsewhere: 1, not_insertable: 2, linked_to_target: 3 };
            const dir = this.previewSortDirection === 'asc' ? 1 : -1;
            const field = this.previewSortField;
            return items.sort((a, b) => {
                let primary;
                if (field === 'link_status') {
                    primary = ((statusRank[a.link_status] ?? 4) - (statusRank[b.link_status] ?? 4)) * dir;
                } else {
                    primary = String(a[field] ?? '').localeCompare(String(b[field] ?? '')) * dir;
                }
                if (primary !== 0) return primary;
                const tByTitle = String(a.title || '').localeCompare(String(b.title || ''));
                if (tByTitle !== 0) return tByTitle;
                return (statusRank[a.link_status] ?? 4) - (statusRank[b.link_status] ?? 4);
            });
        },

        groupedPreview() {
            if (!this.previewModal?.items) return [];
            const groups = {};
            for (const item of this.previewModal.items) {
                if (!groups[item.id]) {
                    groups[item.id] = {
                        id: item.id,
                        title: item.title,
                        edit_url: item.edit_url,
                        occurrences: [],
                        hasWouldLink: false,
                    };
                }
                groups[item.id].occurrences.push(item);
                if (item.link_status === 'would_link') groups[item.id].hasWouldLink = true;
            }
            return Object.values(groups);
        },

        wouldLinkCount() {
            return this.groupedPreview.filter(g => g.hasWouldLink).length;
        },

        /**
         * Header-checkbox state for the preview table. Selection lives in
         * two pools (excludedEntryIds for would_link, selectedUnlinkIds for
         * linked_to_target) — these computeds collapse both into the
         * canonical select-all semantics: are all toggleable rows ON?
         *
         * Toggleable = a row whose status has a checkbox (would_link or
         * linked_to_target). Sentence_status rows (linked_elsewhere,
         * not_insertable) are skipped — no checkbox, nothing to toggle.
         *
         * Filter-aware: when previewStatusFilter is set the count covers
         * only visible rows, matching the BrokenLinks "select all visible"
         * convention.
         */
        togglablePreviewRows() {
            return this.sortedPreviewItems.filter(
                i => i.link_status === 'would_link' || i.link_status === 'linked_to_target',
            );
        },

        togglablePreviewRowCount() {
            return this.togglablePreviewRows.length;
        },

        somePreviewRowsSelected() {
            for (const item of this.togglablePreviewRows) {
                if (item.link_status === 'would_link' && ! this.excludedEntryIdsLocal.includes(item.id)) {
                    return true;
                }
                if (item.link_status === 'linked_to_target' && this.selectedUnlinkIdsLocal.includes(item.id)) {
                    return true;
                }
            }
            return false;
        },

        allPreviewRowsSelected() {
            if (this.togglablePreviewRows.length === 0) return false;
            for (const item of this.togglablePreviewRows) {
                if (item.link_status === 'would_link' && this.excludedEntryIdsLocal.includes(item.id)) {
                    return false;
                }
                if (item.link_status === 'linked_to_target' && ! this.selectedUnlinkIdsLocal.includes(item.id)) {
                    return false;
                }
            }
            return true;
        },

        linkedToTargetCount() {
            if (!this.previewModal?.items) return 0;
            const ids = new Set();
            for (const i of this.previewModal.items) {
                if (i.link_status === 'linked_to_target') ids.add(i.id);
            }
            return ids.size;
        },

        linkedElsewhereCount() {
            if (!this.previewModal?.items) return 0;
            const ids = new Set();
            for (const i of this.previewModal.items) {
                if (i.link_status === 'linked_elsewhere') ids.add(i.id);
            }
            return ids.size;
        },

        notInsertableCount() {
            if (!this.previewModal?.items) return 0;
            const ids = new Set();
            for (const i of this.previewModal.items) {
                if (i.link_status === 'not_insertable') ids.add(i.id);
            }
            return ids.size;
        },

        // Entries that WILL be linked: all would_link groups that the user has not excluded.
        applyablePreviewCount() {
            return this.groupedPreview.filter(g => g.hasWouldLink && !this.excludedEntryIdsLocal.includes(g.id)).length;
        },
    },

    methods: {
        highlightKeyword,

        togglePreviewSort(field) {
            if (this.previewSortField === field) {
                this.previewSortDirection = this.previewSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.previewSortField = field;
                this.previewSortDirection = 'asc';
            }
        },

        toggleExclude(entryId) {
            const next = [...this.excludedEntryIdsLocal];
            const idx = next.indexOf(entryId);
            if (idx > -1) next.splice(idx, 1);
            else next.push(entryId);
            this.excludedEntryIdsLocal = next;
        },

        toggleUnlinkSelection(entryId) {
            const next = [...this.selectedUnlinkIdsLocal];
            const idx = next.indexOf(entryId);
            if (idx > -1) next.splice(idx, 1);
            else next.push(entryId);
            this.selectedUnlinkIdsLocal = next;
        },

        /**
         * Header-checkbox handler. Toggles every togglable row's selection
         * state in one shot, across both action pools:
         *   - would_link rows  → in/out of excludedEntryIds (apply scope)
         *   - linked_to_target → in/out of selectedUnlinkIds (unlink scope)
         *
         * The single click updates BOTH the "Apply (X)" and "Unlink (Y)"
         * counters consistently. User then clicks whichever action button
         * matches their intent.
         */
        togglePreviewSelectAll() {
            const togglable = this.togglablePreviewRows;
            if (togglable.length === 0) return;

            if (this.allPreviewRowsSelected) {
                // De-select all: exclude every would_link, clear unlink set.
                const wouldLinkIds = togglable
                    .filter(i => i.link_status === 'would_link')
                    .map(i => i.id);
                // Merge with any pre-existing exclusions outside the visible
                // togglable set (filter-aware behaviour) so we don't
                // accidentally re-include rows the user excluded earlier.
                this.excludedEntryIdsLocal = Array.from(
                    new Set([...this.excludedEntryIdsLocal, ...wouldLinkIds]),
                );
                const linkedTargetIds = new Set(
                    togglable.filter(i => i.link_status === 'linked_to_target').map(i => i.id),
                );
                this.selectedUnlinkIdsLocal = this.selectedUnlinkIdsLocal.filter(
                    id => ! linkedTargetIds.has(id),
                );
            } else {
                // Select all: clear exclusions of visible would_link rows,
                // add visible linked_to_target rows to the unlink set.
                const wouldLinkIds = new Set(
                    togglable.filter(i => i.link_status === 'would_link').map(i => i.id),
                );
                this.excludedEntryIdsLocal = this.excludedEntryIdsLocal.filter(
                    id => ! wouldLinkIds.has(id),
                );
                const newUnlinkIds = togglable
                    .filter(i => i.link_status === 'linked_to_target')
                    .map(i => i.id);
                this.selectedUnlinkIdsLocal = Array.from(
                    new Set([...this.selectedUnlinkIdsLocal, ...newUnlinkIds]),
                );
            }
        },
    },
};
</script>
