<template>
    <div>
        <!-- Intro -->
        <Card class="mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Custom Keywords</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Add custom keywords to an entry to make Linkwise prefer it as a link <em>target</em>. When another
                        entry's content mentions one of these keywords, the matching anchor will be suggested as a link
                        to this entry — at a higher priority than the default title-matching path. Use 1–5 keywords per
                        entry that capture the page's main topics.
                    </p>
                </div>
                <HelpIcon tooltip="Custom keywords are how you tell Linkwise: 'When other entries say X, suggest a link to this page'. Title-match suggestions still happen automatically — custom keywords are an extra boost for the topics your title doesn't fully cover." />
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
                        :input-attrs="{ 'aria-label': 'Search custom keywords' }"
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
                                <span
                                    v-for="kw in entry.custom_keywords"
                                    :key="kw"
                                    class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-700"
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
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-2 font-medium">Auto-extracted reference</label>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="kw in editModal.content_keywords"
                                :key="kw"
                                class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"
                            >{{ kw }}</span>
                            <span v-if="editModal.content_keywords.length === 0" class="text-xs text-gray-400">No keywords could be extracted from this entry's text.</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5 leading-relaxed">
                            For reference only — pulled from this entry's text. If any of these match how you want this page to be discovered, copy them into the box below as custom keywords.
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-2 font-medium">Custom keywords</label>
                        <textarea
                            v-model="editKeywordsText"
                            rows="4"
                            placeholder="Enter keywords, one per line or comma-separated"
                            class="w-full text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5"
                        ></textarea>
                        <p class="text-xs text-gray-400 mt-1 leading-relaxed">
                            When another entry's content mentions any of these keywords, Linkwise suggests a link to <em>this</em> entry — at a higher priority than the default title-matching path.
                            Separate with commas or new lines. Max {{ MAX_KEYWORDS_PER_ENTRY }} keywords per entry, max {{ MAX_KEYWORD_LENGTH }} characters each.
                            <span v-if="editKeywordsValidation" class="ml-1 text-red-600 dark:text-red-400">{{ editKeywordsValidation }}</span>
                        </p>
                    </div>

                    <Button @click="saveKeywords" :loading="saving" :disabled="!!editKeywordsValidation" text="Save Keywords" variant="primary" />
                </div>
            </div>
        </Stack>

    </div>
</template>

<script>
import { Link } from '@statamic/cms/inertia';
import { Card, Panel, Button, Stack, Input, Checkbox } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import { sortableMixin } from '../shared/sortable.js';
import { readJson, writeJson } from '../../utils/safeStorage.js';

const STATE_KEY = 'linkwise.targetkeywords.state';

export default {
    components: { Link, Card, Panel, Button, Stack, Input, Checkbox, HelpIcon, SortableHeader },

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
