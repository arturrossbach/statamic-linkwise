<template>
    <div>
        <!-- Intro -->
        <Card class="mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Target Keywords</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Tell Linkwise which keyword each page should rank for. When other entries discuss those topics,
                        Linkwise will recommend linking <em>to this entry</em> as a higher-priority suggestion. Content keywords
                        are auto-extracted from the entry text and shown for reference.
                    </p>
                </div>
                <HelpIcon tooltip="Custom keywords boost this entry's ranking in suggestion lists when source text mentions them. Use 1-5 keywords per entry — the page's main topics. Auto-extracted content keywords are read-only references." />
            </div>
        </Card>

        <!-- Filter -->
        <div class="flex flex-wrap items-center justify-between gap-y-2 mb-4">
            <div class="flex flex-wrap items-center gap-3 gap-y-2">
                <div class="w-64">
                    <Input
                        v-model="searchQuery"
                        placeholder="Search entries or keywords..."
                        size="sm"
                        :input-attrs="{ 'aria-label': 'Search target keywords' }"
                    />
                </div>
                <Checkbox
                    v-model="onlyWithoutCustom"
                    label="Show only entries without custom keywords"
                    size="sm"
                />
            </div>
            <span class="text-xs text-gray-400">
                {{ filteredEntries.length }} of {{ entries.length }} entries
                <span v-if="entriesWithCustomCount" class="ml-2 text-gray-500">
                    ({{ entriesWithCustomCount }} with keywords)
                </span>
            </span>
        </div>

        <!-- Table -->
        <Panel>
            <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                <thead>
                    <tr>
                        <SortableHeader label="Entry" :active="sortField === 'title'" :direction="sortDirection" @sort="toggleSort('title')" />
                        <SortableHeader label="Content Keywords" :sortable="false">
                            <HelpIcon tooltip="Top keywords automatically extracted from this entry's text. These represent what this page is about." />
                        </SortableHeader>
                        <SortableHeader label="Custom Keywords" :sortable="false">
                            <HelpIcon tooltip="Keywords you define manually. Click to edit. Entries with custom keywords get prioritized in link suggestions." />
                        </SortableHeader>
                        <SortableHeader label="Actions" :sortable="false" align="right" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in sortedEntries" :key="entry.id">
                        <td>
                            <Link :href="entry.edit_url" class="hover:text-blue-600 dark:hover:text-blue-400">
                                {{ entry.title }}
                            </Link>
                            <div class="text-xs text-gray-400">{{ entry.collection }}</div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1 items-center">
                                <!-- Each auto-extracted content keyword
                                     gets an ✕ button. Click MARKS the
                                     keyword as pending-exclude — visual
                                     strikethrough + faded — until the
                                     row's Save button commits via
                                     POST (User-Smoke 2026-05-21:
                                     ✕ alone could remove by accident,
                                     two-step Save + Confirm prevents). -->
                                <span
                                    v-for="kw in entry.content_keywords"
                                    :key="kw"
                                    class="inline-flex items-center gap-1 text-xs pl-1.5 pr-1 py-0.5 rounded"
                                    :class="isPendingExclude(entry.id, kw)
                                        ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 line-through opacity-70'
                                        : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400'"
                                >
                                    {{ kw }}
                                    <button
                                        v-if="! isPendingExclude(entry.id, kw)"
                                        @click="markForExclude(entry, kw)"
                                        class="opacity-50 hover:opacity-100 cursor-pointer"
                                        :title="`Mark '${kw}' for removal. Click Save to commit, or Cancel to undo.`"
                                        type="button"
                                    >
                                        ✕
                                    </button>
                                    <button
                                        v-else
                                        @click="unmarkForExclude(entry, kw)"
                                        class="opacity-70 hover:opacity-100 cursor-pointer text-red-600 dark:text-red-400"
                                        title="Undo — keep this keyword"
                                        type="button"
                                    >
                                        ↩
                                    </button>
                                </span>
                                <span v-if="entry.content_keywords.length === 0" class="text-xs text-gray-300 dark:text-gray-600">No keywords</span>
                                <!-- Save / Cancel only appear when at
                                     least one pending exclude exists
                                     for this row. -->
                                <div v-if="hasPending(entry.id)" class="inline-flex items-center gap-1 ml-2">
                                    <Button
                                        @click="openExcludeConfirm(entry.id)"
                                        :loading="savingExcludesFor[entry.id]"
                                        :text="`Save (${pendingExcludeCount(entry.id)})`"
                                        variant="primary"
                                        size="xs"
                                    />
                                    <Button
                                        @click="cancelExclude(entry.id)"
                                        :disabled="savingExcludesFor[entry.id]"
                                        text="Cancel"
                                        variant="default"
                                        size="xs"
                                    />
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1 items-center">
                                <span
                                    v-for="kw in entry.custom_keywords"
                                    :key="kw"
                                    class="text-xs px-1.5 py-0.5 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400"
                                >{{ kw }}</span>
                                <button
                                    v-if="entry.custom_keywords.length === 0"
                                    @click="editKeywords(entry)"
                                    class="text-xs px-2 py-0.5 rounded border border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:border-blue-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors cursor-pointer"
                                    type="button"
                                >+ Add keywords</button>
                            </div>
                        </td>
                        <td class="text-right">
                            <Button @click="editKeywords(entry)" text="Edit" size="sm" />
                        </td>
                    </tr>
                </tbody>
            </table></div>
        </Panel>

        <!-- Edit Keywords Stack -->
        <Stack :open="editModal !== null" @update:open="editModal = null" :title="editModal ? `Keywords: ${editModal.title}` : ''" size="half">
            <div v-if="editModal">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-2 font-medium">Content Keywords (auto-extracted, read-only reference)</label>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="kw in editModal.content_keywords"
                                :key="kw"
                                class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"
                            >{{ kw }}</span>
                            <span v-if="editModal.content_keywords.length === 0" class="text-xs text-gray-400">No content keywords extracted.</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-2 font-medium">Custom Keywords</label>
                        <textarea
                            v-model="editKeywordsText"
                            rows="4"
                            placeholder="Enter keywords, one per line or comma-separated"
                            class="w-full text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5"
                        ></textarea>
                        <p class="text-xs text-gray-400 mt-1">
                            Separate with commas or new lines. Max {{ MAX_KEYWORDS_PER_ENTRY }} keywords per entry, max {{ MAX_KEYWORD_LENGTH }} characters each.
                            <span v-if="editKeywordsValidation" class="ml-1 text-red-600 dark:text-red-400">{{ editKeywordsValidation }}</span>
                        </p>
                    </div>

                    <Button @click="saveKeywords" :loading="saving" :disabled="!!editKeywordsValidation" text="Save Keywords" variant="primary" />
                </div>
            </div>
        </Stack>

        <!-- Confirm before committing pending excludes.
             Wording: this is mostly reversible (file-edit), but the
             user explicitly asked for a confirm because clicking ✕ on
             the wrong badge is easy. -->
        <ConfirmationModal
            :open="confirmExcludeForEntry !== null"
            title="Exclude content keywords?"
            :body-text="confirmExcludeForEntry ? `Permanently exclude ${pendingExcludeCount(confirmExcludeForEntry)} keyword(s) from this entry's auto-list. They will not reappear after future Scan-Content runs. You can re-add them later by editing storage/linkwise/excluded-content-keywords.json.` : ''"
            button-text="Exclude"
            danger
            :busy="confirmExcludeForEntry ? savingExcludesFor[confirmExcludeForEntry] : false"
            @update:open="val => { if (! val) confirmExcludeForEntry = null; }"
            @confirm="commitPendingExcludes"
        />
    </div>
</template>

<script>
import { Link } from '@statamic/cms/inertia';
import { Card, Panel, Button, Stack, Input, Checkbox, ConfirmationModal } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import { sortableMixin } from '../shared/sortable.js';
import { readJson, writeJson } from '../../utils/safeStorage.js';

const STATE_KEY = 'linkwise.targetkeywords.state';

export default {
    components: { Link, Card, Panel, Button, Stack, Input, Checkbox, ConfirmationModal, HelpIcon, SortableHeader },

    mixins: [sortableMixin],

    props: {
        data: { type: Object, required: true },
    },

    data() {
        return {
            entries: [...(this.data?.entries || [])],
            searchQuery: '',
            onlyWithoutCustom: false,
            sortField: 'title',
            sortDirection: 'asc',
            editModal: null,
            editKeywordsText: '',
            saving: false,
            // `{ [entryId]: string[] }` — keywords the user has marked
            // for exclusion via ✕ but NOT yet committed via Save. Set
            // is cleared per-entry on Save success or Cancel. Survives
            // tab-internal sort / filter changes (lives in component
            // state, not in the entry-row object itself).
            pendingExcludes: {},
            // Per-entry "exclude is committing" flag — disables Save
            // while the POST roundtrip is in flight so a double-click
            // can't fire two requests.
            savingExcludesFor: {},
            // Entry id whose pending-excludes the ConfirmationModal is
            // currently asking about. `null` = modal closed.
            confirmExcludeForEntry: null,
        };
    },

    computed: {
        // Validation limits — single source of truth in PHP
        // (TargetKeywordController::MAX_*). Backend ships them as a prop so
        // there's no risk of FE/BE drifting apart.
        MAX_KEYWORDS_PER_ENTRY() {
            return this.data?.limits?.max_keywords_per_entry ?? 50;
        },
        MAX_KEYWORD_LENGTH() {
            return this.data?.limits?.max_keyword_length ?? 50;
        },

        filteredEntries() {
            const q = this.searchQuery.trim().toLowerCase();
            let list = this.entries;
            if (this.onlyWithoutCustom) {
                list = list.filter(e => e.custom_keywords.length === 0);
            }
            if (!q) return list;
            // Lowercase ALL fields for consistent case-insensitive match.
            return list.filter(e =>
                e.title.toLowerCase().includes(q) ||
                e.content_keywords.some(k => k.toLowerCase().includes(q)) ||
                e.custom_keywords.some(k => k.toLowerCase().includes(q))
            );
        },

        sortedEntries() {
            const field = this.sortField;
            const dir = this.sortDirection === 'asc' ? 1 : -1;
            return [...this.filteredEntries].sort((a, b) => {
                if (field === 'title') return a.title.localeCompare(b.title) * dir;
                return 0;
            });
        },

        entriesWithCustomCount() {
            return this.entries.filter(e => e.custom_keywords.length > 0).length;
        },

        /**
         * Live validation message for the edit-stack textarea. Empty string
         * = OK, otherwise the message is shown next to the input and the
         * Save button is disabled. Mirrors the backend validation rules so
         * the user sees the issue locally before hitting submit.
         */
        editKeywordsValidation() {
            const parsed = (this.editKeywordsText || '')
                .split(/[,\n]/)
                .map(k => k.trim())
                .filter(k => k.length > 0);
            if (parsed.length > this.MAX_KEYWORDS_PER_ENTRY) {
                return `Too many keywords: ${parsed.length} / ${this.MAX_KEYWORDS_PER_ENTRY} max.`;
            }
            const tooLong = parsed.find(k => k.length > this.MAX_KEYWORD_LENGTH);
            if (tooLong) {
                return `Keyword too long (max ${this.MAX_KEYWORD_LENGTH} chars): "${tooLong.substring(0, 30)}…"`;
            }
            return '';
        },
    },

    watch: {
        searchQuery() { this.persistState(); },
        onlyWithoutCustom() { this.persistState(); },
    },

    mounted() {
        // Hydrate filter state from sessionStorage so the user lands on the
        // same view after a tab switch (Inertia keeps the page mounted but a
        // hard navigation away and back would otherwise reset filters).
        // safeStorage swallows quota / private-mode failures.
        const stored = readJson(STATE_KEY);
        if (stored) {
            if (typeof stored.searchQuery === 'string') this.searchQuery = stored.searchQuery;
            if (typeof stored.onlyWithoutCustom === 'boolean') this.onlyWithoutCustom = stored.onlyWithoutCustom;
        }
    },

    methods: {
        persistState() {
            writeJson(STATE_KEY, {
                searchQuery: this.searchQuery || '',
                onlyWithoutCustom: this.onlyWithoutCustom,
            });
        },

        editKeywords(entry) {
            this.editModal = entry;
            this.editKeywordsText = entry.custom_keywords.join(', ');
        },

        // ── Pending-Exclude Two-Step (User-Smoke 2026-05-21) ─────────
        //
        // Flow:
        //   ✕ → markForExclude → keyword added to pendingExcludes
        //   ↩ on a pending badge → unmarkForExclude (undo)
        //   Save → openExcludeConfirm → ConfirmationModal
        //   modal Confirm → commitPendingExcludes (POST)
        //   Cancel → cancelExclude (clear pending for this entry)
        //
        // Two-step prevents accidental removal — single ✕ is too easy
        // to mis-click and the action persists across Scan-Content.

        isPendingExclude(entryId, keyword) {
            const pending = this.pendingExcludes[entryId];
            if (! Array.isArray(pending)) return false;
            const lowered = keyword.toLowerCase();
            return pending.includes(lowered);
        },

        hasPending(entryId) {
            const pending = this.pendingExcludes[entryId];
            return Array.isArray(pending) && pending.length > 0;
        },

        pendingExcludeCount(entryId) {
            const pending = this.pendingExcludes[entryId];
            return Array.isArray(pending) ? pending.length : 0;
        },

        markForExclude(entry, keyword) {
            const lowered = keyword.toLowerCase();
            const current = this.pendingExcludes[entry.id] || [];
            if (current.includes(lowered)) return;
            this.pendingExcludes = {
                ...this.pendingExcludes,
                [entry.id]: [...current, lowered],
            };
        },

        unmarkForExclude(entry, keyword) {
            const lowered = keyword.toLowerCase();
            const current = this.pendingExcludes[entry.id] || [];
            const next = current.filter(k => k !== lowered);
            if (next.length === 0) {
                // Drop the entry-key so hasPending() returns false.
                const { [entry.id]: _, ...rest } = this.pendingExcludes;
                this.pendingExcludes = rest;
            } else {
                this.pendingExcludes = { ...this.pendingExcludes, [entry.id]: next };
            }
        },

        cancelExclude(entryId) {
            const { [entryId]: _, ...rest } = this.pendingExcludes;
            this.pendingExcludes = rest;
        },

        openExcludeConfirm(entryId) {
            if (! this.hasPending(entryId)) return;
            this.confirmExcludeForEntry = entryId;
        },

        async commitPendingExcludes() {
            const entryId = this.confirmExcludeForEntry;
            if (! entryId) return;
            const pending = this.pendingExcludes[entryId] || [];
            if (pending.length === 0) {
                this.confirmExcludeForEntry = null;
                return;
            }

            const row = this.entries.find(e => e.id === entryId);
            if (! row) {
                this.confirmExcludeForEntry = null;
                return;
            }

            this.savingExcludesFor = { ...this.savingExcludesFor, [entryId]: true };

            // Full exclude list = existing excluded + pending. Backend
            // dedupes + lowercases.
            const currentExcluded = Array.isArray(row.excluded_content_keywords)
                ? row.excluded_content_keywords
                : [];
            const next = Array.from(new Set([...currentExcluded, ...pending]));

            try {
                const url = (this.data.exclude_url || '').replace('__ID__', entryId);
                if (! url) {
                    Statamic.$toast.error('Exclude URL not configured.');
                    return;
                }
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ keywords: next }),
                });
                if (! response.ok) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.error(data?.message || `Could not exclude: HTTP ${response.status}`);
                    return;
                }
                const result = await response.json();
                row.excluded_content_keywords = result.excluded || next;
                row.content_keywords = row.content_keywords.filter(
                    k => ! pending.includes(k.toLowerCase()),
                );
                // Clear pending for this entry on success.
                const { [entryId]: _, ...rest } = this.pendingExcludes;
                this.pendingExcludes = rest;
                Statamic.$toast.success(`Excluded ${pending.length} keyword(s).`);
            } catch (e) {
                Statamic.$toast.error(`Could not exclude: ${e.message || 'network error'}`);
            } finally {
                const { [entryId]: _, ...rest } = this.savingExcludesFor;
                this.savingExcludesFor = rest;
                this.confirmExcludeForEntry = null;
            }
        },

        async saveKeywords() {
            if (!this.editModal) return;
            this.saving = true;

            const keywords = this.editKeywordsText
                .split(/[,\n]/)
                .map(k => k.trim())
                .filter(k => k.length > 0);

            try {
                const url = this.data.update_url.replace('__ID__', this.editModal.id);
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ keywords }),
                });

                if (response.ok) {
                    const result = await response.json();
                    const entry = this.entries.find(e => e.id === this.editModal.id);
                    if (entry) entry.custom_keywords = result.keywords;
                    Statamic.$toast.success('Keywords saved.');
                    this.editModal = null;
                } else {
                    Statamic.$toast.error('Failed to save keywords.');
                }
            } catch (e) {
                Statamic.$toast.error('Failed to save keywords.');
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>
