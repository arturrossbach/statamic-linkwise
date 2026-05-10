<template>
    <div>
        <!-- SEO Best Practice Intro -->
        <Card class="mb-4">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Links Report</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                        Internal links help search engines discover and understand your content.
                        Every page should have <strong>at least 1 inbound internal link</strong> to avoid being an orphan page.
                        Industry best practice recommends <strong>3+ internal outbound links</strong> per post.
                    </p>
                </div>
                <HelpIcon tooltip="Orphan pages (0 inbound links) are harder to crawl and index (Google Search Central). The 3+ outbound recommendation is an industry convention used by Yoast and Semrush." />
            </div>
        </Card>

        <!-- Stale-index warning (age-based) -->
        <Alert v-if="showStaleBanner && !scanInProgress" variant="warning" class="mb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-sm">Content index is {{ indexAgeDays }} days old</p>
                    <p class="mt-0.5 text-xs opacity-80">Suggestion counts may be out of date if entries were edited since the last scan.</p>
                </div>
                <Button
                    v-if="rebuildUrl"
                    text="Re-scan"
                    icon="sync"
                    size="sm"
                    variant="primary"
                    :loading="rescanning"
                    :disabled="rescanning"
                    @click="triggerRescan"
                />
            </div>
        </Alert>

        <!-- Stale-counts warning (data-driven): triggered when a modal fetch
             reveals that the cached count and the live engine count for the
             same entry disagree. Stronger signal than the age-based banner —
             we just observed the divergence on a real entry the user looked
             at, not a guess based on time. Hide once the user has dismissed
             OR once a re-scan completes (page reloads, flag resets). -->
        <Alert v-if="divergenceDetected && !showStaleBanner && !scanInProgress" variant="warning" class="mb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-sm">Suggestion counts in this table are out of date</p>
                    <p class="mt-0.5 text-xs opacity-80">Engine results and cached counts disagree for an entry you just opened. Re-scan to refresh the table.</p>
                </div>
                <Button
                    v-if="rebuildUrl"
                    text="Re-scan"
                    icon="sync"
                    size="sm"
                    variant="primary"
                    :loading="rescanning"
                    :disabled="rescanning"
                    @click="triggerRescan"
                />
            </div>
        </Alert>

        <!-- Filter Bar -->
        <div class="flex flex-wrap items-center justify-between gap-y-2 mb-4">
            <div class="flex flex-wrap items-center gap-3 gap-y-2">
                <!-- Native <select> is intentional: Statamic's Select is a searchable Combobox,
                     overkill and laggy for a small static collection list -->
                <select
                    v-model="collectionFilter"
                    aria-label="Filter by collection"
                    class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md px-2 py-1.5"
                >
                    <option value="">All Collections</option>
                    <option v-for="c in collections" :key="c" :value="c">{{ c }}</option>
                </select>
                <div class="w-48">
                    <Input
                        v-model="searchQuery"
                        size="sm"
                        icon="magnifying-glass"
                        clearable
                        :placeholder="anchorOnly ? 'Search anchor only...' : 'Search title or anchor...'"
                        v-tooltip="anchorOnly ? 'Matches anchor text only — title matches are excluded' : 'Matches entry title OR any anchor text on its internal/external outbound links'"
                        aria-label="Search title or anchor text"
                    />
                </div>
                <label v-if="searchQuery.trim()" class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer whitespace-nowrap" v-tooltip="'Restrict the search to anchor-text hits only — useful when the title shares words with the search term but the entries you actually want are the ones containing the keyword as a link anchor.'">
                    <input type="checkbox" v-model="anchorOnly" class="rounded">
                    Anchor only
                </label>
                <label class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer whitespace-nowrap" v-tooltip="'Show only entries with zero inbound links (not linked from any other page)'">
                    <input type="checkbox" v-model="showOrphanedOnly" class="rounded">
                    Orphaned only
                </label>
                <label class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer whitespace-nowrap" v-tooltip="'Show only entries that contain a link pointing to themselves'">
                    <input type="checkbox" v-model="showSelfLinksOnly" class="rounded">
                    Self-links only
                </label>
                <label class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer whitespace-nowrap" v-tooltip="'Show only entries where inbound or outbound suggestions match another entry\'s title directly — the strongest link opportunities'">
                    <input type="checkbox" v-model="showTitleMatchesOnly" class="rounded">
                    Title matches only
                </label>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400">
                    {{ filteredEntries.length }} of {{ localEntries.length }} entries
                </span>
                <button
                    @click="exportCsv"
                    class="text-xs px-3 py-1.5 rounded border border-gray-300 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400"
                >
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="suggestionsLoading" class="py-12 flex flex-col items-center gap-3" role="status" aria-live="polite">
            <Icon name="loading" class="size-6 text-gray-400" />
            <span class="text-sm text-gray-400">Loading Links Report…</span>
        </div>

        <template v-else>
            <!-- Top Pagination -->
            <Pagination
                v-if="totalPages > 1"
                class="mb-3"
                :resource-meta="paginationMeta"
                :per-page="perPage"
                :show-per-page-selector="false"
                @page-selected="currentPage = $event"
            />

            <!-- Table -->
            <Panel>
                <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                <thead>
                    <tr>
                        <SortableHeader label="Entry Title" :active="sortField === 'title'" :direction="sortDirection" @sort="toggleSort('title')" />
                        <SortableHeader label="Collection" :active="sortField === 'collection'" :direction="sortDirection" @sort="toggleSort('collection')" />
                        <SortableHeader label="Inbound" align="center" :active="sortField === 'inbound_count'" :direction="sortDirection" @sort="toggleSort('inbound_count')">
                            <HelpIcon tooltip="Number of other entries linking TO this entry. Zero means orphaned." />
                        </SortableHeader>
                        <SortableHeader label="Internal Out" align="center" :active="sortField === 'outbound_count'" :direction="sortDirection" @sort="toggleSort('outbound_count')">
                            <HelpIcon tooltip="Outbound internal links (to other entries on your site)." />
                        </SortableHeader>
                        <SortableHeader label="External Out" align="center" :active="sortField === 'external_count'" :direction="sortDirection" @sort="toggleSort('external_count')">
                            <HelpIcon tooltip="Outbound external links (to other websites)." />
                        </SortableHeader>
                        <SortableHeader label="Inbound Sugg." align="center" :active="sortField === 'inbound_suggestions'" :direction="sortDirection" @sort="toggleSort('inbound_suggestions')">
                            <HelpIcon tooltip="Other entries that could link TO this entry but don't yet." />
                        </SortableHeader>
                        <SortableHeader label="Outbound Sugg." align="center" :active="sortField === 'outbound_suggestions'" :direction="sortDirection" @sort="toggleSort('outbound_suggestions')">
                            <HelpIcon tooltip="Link opportunities in this entry's text that could link to other entries." />
                        </SortableHeader>
                        <SortableHeader label="Actions" :sortable="false" align="right" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in paginatedEntries"
                        :key="entry.id"
                        :class="{ 'bg-red-50 dark:bg-red-900/10': entry.is_orphaned }"
                    >
                        <td class="break-words">
                            <div class="flex items-center gap-1.5">
                                <span v-if="entry.is_orphaned" class="w-2 h-2 rounded-full bg-red-500 flex-shrink-0" v-tooltip="'Orphaned — no inbound links'"></span>
                                <a :href="entry.edit_url" target="_blank" class="cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 break-words">
                                    {{ entry.title }}
                                </a>
                                <BardBadge :entry-id="entry.id" />
                            </div>
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">
                            {{ entry.collection }}
                        </td>
                        <td class="text-center">
                            <div class="inline-flex items-center gap-1">
                                <button v-if="entry.inbound_count > 0" @click="showDetail('inbound', entry)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                                    {{ entry.inbound_count }}
                                </button>
                                <span v-else class="text-red-500 font-bold">0</span>
                                <button v-if="entry.inbound_count === 0" @click="openSuggestModal('inbound', entry)" class="text-xs px-1 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50 cursor-pointer" v-tooltip="'Orphan page — click to find inbound link opportunities'">orphan</button>
                                <button v-else-if="entry.inbound_count < 3" @click="openSuggestModal('inbound', entry)" class="text-xs px-1 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-900/50 cursor-pointer">add +{{ 3 - entry.inbound_count }}</button>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="inline-flex items-center gap-1">
                                <button v-if="entry.outbound_count > 0" @click="showDetail('outbound', entry, 'internal')" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                                    {{ entry.outbound_count }}
                                </button>
                                <span v-else>0</span>
                                <span v-if="hasSelfLink(entry)" class="text-xs text-amber-500" v-tooltip="'Contains a self-link — wastes link equity'">⚠</span>
                                <button v-if="entry.outbound_count < 3" @click="openSuggestModal('outbound', entry)" class="text-xs px-1 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-900/50 cursor-pointer">add +{{ 3 - entry.outbound_count }}</button>
                            </div>
                        </td>
                        <td class="text-center">
                            <button v-if="entry.external_count > 0" @click="showDetail('outbound', entry, 'external')" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                                {{ entry.external_count }}
                            </button>
                            <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                        </td>
                        <td class="text-center">
                            <span v-if="suggestionsLoading" class="text-gray-300 dark:text-gray-600 animate-pulse">…</span>
                            <button v-else-if="getInboundSuggestionCount(entry.id) > 0" @click="openSuggestModal('inbound', entry)" class="hover:underline cursor-pointer text-green-600 dark:text-green-400 font-medium">
                                {{ getInboundSuggestionCount(entry.id) }}
                            </button>
                            <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                        </td>
                        <td class="text-center">
                            <span v-if="suggestionsLoading" class="text-gray-300 dark:text-gray-600 animate-pulse">…</span>
                            <button v-else-if="getOutboundSuggestionCount(entry.id) > 0" @click="openSuggestModal('outbound', entry)" class="hover:underline cursor-pointer text-green-600 dark:text-green-400 font-medium">
                                {{ getOutboundSuggestionCount(entry.id) }}
                            </button>
                            <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                        </td>
                        <td class="text-right">
                            <Dropdown align="end">
                                <DropdownMenu>
                                    <DropdownItem text="View Page" :href="entry.view_url" target="_blank" icon="external-link" v-if="entry.view_url" />
                                    <DropdownItem text="Edit Page" :href="entry.edit_url" icon="pencil" />
                                    <DropdownSeparator />
                                    <DropdownItem text="View Inbound Links" icon="arrow-left" @click="showDetail('inbound', entry)" />
                                    <DropdownItem text="View Outbound Links" icon="arrow-right" @click="showDetail('outbound', entry)" />
                                    <DropdownSeparator />
                                    <DropdownItem text="Add Inbound Links" @click="openSuggestModal('inbound', entry)" icon="plus" />
                                    <DropdownItem text="Add Outbound Links" @click="openSuggestModal('outbound', entry)" icon="plus" />
                                </DropdownMenu>
                            </Dropdown>
                        </td>
                    </tr>
                    <tr v-if="!paginatedEntries.length">
                        <td colspan="8" class="text-center text-gray-400 py-8">
                            No entries match your filters.
                        </td>
                    </tr>
                </tbody>
            </table></div>
            </Panel>

            <!-- Bottom Pagination -->
            <Pagination
                v-if="totalPages > 1"
                class="mt-4"
                :resource-meta="paginationMeta"
                :per-page="perPage"
                :show-per-page-selector="false"
                @page-selected="currentPage = $event"
            />
        </template>

        <!-- Detail Modal -->
        <DetailModal
            :modal="detailModal"
            :apply-url="applyUrl"
            :inbound-insert-url="inboundInsertUrl"
            :outbound-insert-url="outboundInsertUrl"
            :entries="localEntries"
            @close="closeModal"
        />

        <!-- Suggestions Modal (Inbound / Outbound) -->
        <SuggestionModal
            :modal="suggestModal"
            :loading="suggestLoading"
            :inserting="inserting"
            :insert-progress="insertProgress"
            :rebuild-url="rebuildUrl"
            :autolink-store-url="autolinkStoreUrl"
            @close="closeSuggestModal"
            @insert="handleSuggestInsert"
        />
    </div>
</template>

<script>
import { Card, Panel, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Pagination, Icon, Input, Alert, Button } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SuggestionModal from './SuggestionModal.vue';
import DetailModal from './DetailModal.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import BardBadge from '../shared/BardBadge.vue';
import { sortableMixin } from '../shared/sortable.js';
import { buildPaginationMeta } from '../shared/pagination.js';
import { router as inertiaRouter } from '@statamic/cms/inertia';
import { setHeavyState, recordCompletion, bulkState } from '../../services/bulkOperationService.js';

export default {
    components: { Card, Panel, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Pagination, Icon, Input, Alert, Button, HelpIcon, SuggestionModal, DetailModal, SortableHeader, BardBadge },

    mixins: [sortableMixin],

    props: {
        entries: { type: Array, required: true },
        collections: { type: Array, required: true },
        suggestionCountsUrl: { type: String, default: '' },
        applyUrl: { type: String, default: '' },
        inboundSuggestionsBaseUrl: { type: String, default: '' },
        outboundSuggestionsBaseUrl: { type: String, default: '' },
        inboundInsertUrl: { type: String, default: '' },
        outboundInsertUrl: { type: String, default: '' },
        autolinkStoreUrl: { type: String, default: '' },
        rebuildUrl: { type: String, default: '' },
        indexLastBuiltAt: { type: String, default: null },
        initialOrphaned: { type: Boolean, default: false },
    },

    data() {
        return {
            sortField: 'inbound_suggestions',
            sortDirection: 'desc',
            collectionFilter: '',
            searchQuery: '',
            // When true and searchQuery is set, the filter ignores title hits
            // and only matches entries whose internal/external outbound links
            // contain the search term as anchor text. The Anchor-only checkbox
            // shows up next to Search whenever the user types something —
            // hidden otherwise so the filter bar stays clean for the default
            // browse case.
            anchorOnly: false,
            showOrphanedOnly: this.initialOrphaned,
            showSelfLinksOnly: false,
            showTitleMatchesOnly: false,
            currentPage: 1,
            perPage: 50,
            detailModal: null,
            suggestModal: null,
            suggestLoading: false,
            suggestionCounts: {},
            suggestionsLoading: false,
            rescanning: false,
            // Set when a modal fetch reveals that the cached suggestion
            // count diverges from the live engine result — surfaces the
            // "data-driven" stale-counts banner separate from the 7-day
            // age-based one. Honest signal: cache and engine just disagreed
            // on a real entry the user just looked at.
            divergenceDetected: false,
            // Deep-clone because Inertia prop objects are readonly reactive —
            // mutations like `entry.inbound_count++` in close handlers would
            // silently fail on the prop. Watcher below resyncs on prop changes.
            localEntries: JSON.parse(JSON.stringify(this.entries || [])),
        };
    },

    mounted() {
        if (this.suggestionCountsUrl) {
            this.loadSuggestionCounts();
        }

        // Auto-open the suggestion modal when arriving via the entry-edit
        // sidebar links (`?open=<entryId>&mode=inbound|outbound`). Without
        // this, clicking the count badges in the entry sidebar dumped the
        // user on the Links Report table with no indication where to look —
        // they'd see the same count number again, with no path to the modal
        // they actually wanted.
        try {
            const params = new URLSearchParams(window.location.search);
            const openId = params.get('open');
            const mode = params.get('mode');
            if (openId && (mode === 'inbound' || mode === 'outbound')) {
                const entry = (this.entries || []).find((e) => e.id === openId);
                if (entry) {
                    this.openSuggestModal(mode, entry);
                }
            }
        } catch {
            // URLSearchParams or unexpected URL shape — non-critical
        }
    },

    computed: {
        // Days since the index was last built. Null if unknown.
        indexAgeDays() {
            if (!this.indexLastBuiltAt) return null;
            const age = Date.now() - new Date(this.indexLastBuiltAt).getTime();
            return Math.floor(age / (1000 * 60 * 60 * 24));
        },

        // Show the stale-index banner after 7 days — matches Overview's threshold.
        showStaleBanner() {
            return this.indexAgeDays !== null && this.indexAgeDays > 7;
        },

        // Hide the "out of date" banners while a content scan is running:
        // both banners' call-to-action IS "rescan" — showing them during the
        // very rescan they're asking for would be a contradiction.
        scanInProgress() {
            return bulkState.active?.kind === 'scan';
        },

        filteredEntries() {
            let entries = this.localEntries;

            if (this.collectionFilter) {
                entries = entries.filter(e => e.collection === this.collectionFilter);
            }

            if (this.searchQuery.trim()) {
                const q = this.searchQuery.toLowerCase();
                const skipTitle = this.anchorOnly;
                entries = entries.filter(e => {
                    // Anchor-only mode: skip title-match path entirely so the
                    // result is purely entries that USE this term as a link
                    // anchor — solves "I searched 'statamic' and got back
                    // 80 entries because every other title contains it".
                    if (! skipTitle && e.title.toLowerCase().includes(q)) return true;
                    // Anchor-text search across the entry's outbound links
                    // (both internal entry-references and external URLs).
                    // Per-row data is already loaded for the modal, so this
                    // costs nothing extra — no extra fetches, all in-memory.
                    const internalAnchors = e.internal_links_detail || [];
                    for (const l of internalAnchors) {
                        if ((l.anchor_text || '').toLowerCase().includes(q)) return true;
                    }
                    const externalAnchors = e.external_links || [];
                    for (const l of externalAnchors) {
                        if ((l.anchor_text || '').toLowerCase().includes(q)) return true;
                    }
                    return false;
                });
            }

            if (this.showOrphanedOnly) {
                entries = entries.filter(e => e.is_orphaned);
            }

            if (this.showSelfLinksOnly) {
                entries = entries.filter(e => this.hasSelfLink(e));
            }

            if (this.showTitleMatchesOnly) {
                entries = entries.filter(e => e.has_title_match);
            }

            return entries;
        },

        sortedEntries() {
            const field = this.sortField;
            const dir = this.sortDirection === 'asc' ? 1 : -1;

            return [...this.filteredEntries].sort((a, b) => {
                let aVal = a[field];
                let bVal = b[field];

                if (field === 'inbound_suggestions') {
                    aVal = this.getInboundSuggestionCount(a.id);
                    bVal = this.getInboundSuggestionCount(b.id);
                } else if (field === 'outbound_suggestions') {
                    aVal = this.getOutboundSuggestionCount(a.id);
                    bVal = this.getOutboundSuggestionCount(b.id);
                }

                if (typeof aVal === 'string') {
                    return aVal.localeCompare(bVal) * dir;
                }

                return (aVal - bVal) * dir;
            });
        },

        paginatedEntries() {
            const start = (this.currentPage - 1) * this.perPage;
            return this.sortedEntries.slice(start, start + this.perPage);
        },

        totalPages() {
            return Math.ceil(this.sortedEntries.length / this.perPage);
        },

        paginationMeta() {
            return buildPaginationMeta(this.sortedEntries.length, this.currentPage, this.perPage);
        },

    },

    watch: {
        collectionFilter() { this.currentPage = 1; },
        searchQuery(val) {
            this.currentPage = 1;
            // Auto-disable Anchor-only when the search is cleared — the
            // checkbox would otherwise be hidden but its state would persist
            // and surprise the user when they type a new query.
            if (! val.trim()) this.anchorOnly = false;
        },
        anchorOnly() { this.currentPage = 1; },
        showOrphanedOnly() { this.currentPage = 1; },
        showSelfLinksOnly() { this.currentPage = 1; },
        showTitleMatchesOnly() { this.currentPage = 1; },
        sortField() { this.currentPage = 1; },
        // Resync local state when Inertia updates entries (e.g. after rebuild reload).
        entries: {
            handler(val) {
                this.localEntries = JSON.parse(JSON.stringify(val || []));
            },
        },
    },

    methods: {
        ariaSortFor(field) {
            if (this.sortField !== field) return 'none';
            return this.sortDirection === 'asc' ? 'ascending' : 'descending';
        },

        async triggerRescan() {
            if (!this.rebuildUrl || this.rescanning) return;
            this.rescanning = true;
            try {
                const response = await fetch(this.rebuildUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!response.ok) {
                    Statamic.$toast.error('Failed to start re-scan.');
                    this.rescanning = false;
                    return;
                }
                // LinkwiseLayout picks up 'starting' via its status poll; reload gets us the progress UI.
                window.location.reload();
            } catch (error) {
                Statamic.$toast.error('Failed to start re-scan.');
                console.error('[Linkwise]', error);
                this.rescanning = false;
            }
        },

        async loadSuggestionCounts() {
            const isInitialLoad = Object.keys(this.suggestionCounts).length === 0;
            if (isInitialLoad) this.suggestionsLoading = true;
            try {
                const url = this.suggestionCountsUrl + (this.suggestionCountsUrl.includes('?') ? '&' : '?') + '_t=' + Date.now();
                const response = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' },
                });
                if (response.ok) {
                    this.suggestionCounts = await response.json();
                }
            } catch (e) {
                console.error('[Linkwise] Failed to load suggestion counts:', e);
            } finally {
                this.suggestionsLoading = false;
            }
        },

        getInboundSuggestionCount(entryId) {
            const c = this.suggestionCounts[entryId];
            if (c && typeof c === 'object') return c.inbound ?? 0;
            if (typeof c === 'number') return c; // backwards compat
            return 0;
        },

        getOutboundSuggestionCount(entryId) {
            const c = this.suggestionCounts[entryId];
            if (c && typeof c === 'object') return c.outbound ?? 0;
            return 0;
        },

        async refreshSuggestionCountForEntry(entryId, mode) {
            const baseUrl = mode === 'inbound'
                ? this.inboundSuggestionsBaseUrl
                : this.outboundSuggestionsBaseUrl;
            if (!baseUrl) return;
            try {
                const url = baseUrl.replace('__ID__', entryId);
                const response = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (response.ok) {
                    const data = await response.json();
                    const count = mode === 'inbound'
                        ? (data.suggestion_count ?? (data.suggestions || []).length)
                        : (data.group_count ?? (data.groups || []).length);
                    if (this.suggestionCounts[entryId]) {
                        const key = mode === 'inbound' ? 'inbound' : 'outbound';
                        this.suggestionCounts[entryId] = {
                            ...this.suggestionCounts[entryId],
                            [key]: count,
                        };
                    }
                }
            } catch (e) {
                console.error('[Linkwise] Failed to refresh suggestion count:', e);
            }
        },

        mergeSuggestionCounts(counts) {
            for (const [entryId, data] of Object.entries(counts)) {
                this.suggestionCounts[entryId] = {
                    ...this.suggestionCounts[entryId],
                    ...data,
                };
            }
        },

        hasSelfLink(entry) {
            return (entry.internal_links_detail || []).some(l => l.entry_id === entry.id);
        },

        // ─── Suggestion Modal ──────────────────────────────────────

        async openSuggestModal(mode, entry) {
            // Capture the cached count BEFORE the live fetch so we can detect staleness.
            // If the modal then returns 0 but we expected >0, the cache was out of date.
            const expectedCount = mode === 'inbound'
                ? this.getInboundSuggestionCount(entry.id)
                : this.getOutboundSuggestionCount(entry.id);

            this.suggestModal = {
                title: mode === 'inbound'
                    ? `Inbound suggestions for "${entry.title}"`
                    : `Outbound suggestions for "${entry.title}"`,
                entryTitle: entry.title,
                mode,
                entryId: entry.id,
                suggestions: [],
                groups: [],
                contentHash: '',
                entryHashes: {},
                expectedCount,
            };
            this.suggestLoading = true;

            try {
                const baseUrl = mode === 'inbound'
                    ? this.inboundSuggestionsBaseUrl
                    : this.outboundSuggestionsBaseUrl;
                const url = baseUrl.replace('__ID__', entry.id);

                const response = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (response.ok) {
                    const data = await response.json();

                    this.suggestModal.contentHash = data.content_hash || '';
                    this.suggestModal.entryHashes = data.entry_hashes || {};

                    // Compare cached count to live count — if they disagree
                    // for THIS entry, the cache is stale somewhere. Surface
                    // that to the user via the data-driven banner so they
                    // know to re-scan if the table looks wrong.
                    const liveCount = mode === 'outbound'
                        ? (data.group_count ?? (data.groups || []).length)
                        : (data.suggestion_count ?? (data.suggestions || []).length);
                    if (expectedCount !== liveCount) {
                        this.divergenceDetected = true;
                    }

                    if (mode === 'outbound') {
                        // Server returns pre-grouped data — just add UI state
                        const groups = (data.groups || []).map(g => ({
                            ...g,
                            _anchor: g.anchor_text,
                            _originalAnchor: g.anchor_text,
                            _truncatedStart: g.context_truncated_start || false,
                            _truncatedEnd: g.context_truncated_end || false,
                            _selectedTarget: g.targets[0]?.target_entry_id || null,
                            _expanded: false,
                            _status: 'pending',
                            _error: null,
                            _showReason: false,
                        }));
                        this.suggestModal.groups = groups;

                        // Use server-provided count (single source of truth)
                        if (this.suggestionCounts[entry.id]) {
                            this.suggestionCounts[entry.id] = {
                                ...this.suggestionCounts[entry.id],
                                outbound: data.group_count ?? groups.length,
                            };
                        }
                    } else {
                        const suggestions = (data.suggestions || []).map(s => ({
                            ...s,
                            _status: 'pending',
                            _error: null,
                            _showReason: false,
                            _anchor: s.anchor_text,
                            _originalAnchor: s.anchor_text,
                        }));
                        this.suggestModal.suggestions = suggestions;
                        this.suggestModal.totalAvailable = data.total_available ?? suggestions.length;

                        // Use server-provided count (single source of truth)
                        if (this.suggestionCounts[entry.id]) {
                            this.suggestionCounts[entry.id] = {
                                ...this.suggestionCounts[entry.id],
                                inbound: data.suggestion_count ?? suggestions.length,
                            };
                        }
                    }
                }
            } catch (e) {
                console.error('[Linkwise] Failed to load suggestions:', e);
            } finally {
                this.suggestLoading = false;
            }
        },

        closeSuggestModal() {
            if (!this.suggestModal) return;

            const mode = this.suggestModal.mode;
            const entryId = this.suggestModal.entryId;
            let insertedCount = 0;

            if (mode === 'inbound') {
                const inserted = (this.suggestModal.suggestions || []).filter(s => s._status === 'inserted');
                insertedCount = inserted.length;
                if (insertedCount > 0) {
                    const target = this.localEntries.find(e => e.id === entryId);
                    if (target) {
                        target.inbound_count += insertedCount;
                        target.is_orphaned = target.inbound_count === 0;
                    }
                    for (const s of inserted) {
                        const source = this.localEntries.find(e => e.id === s.source_entry_id);
                        if (source) source.outbound_count++;
                    }
                }
            } else {
                const inserted = (this.suggestModal.groups || []).filter(g => g._status === 'inserted');
                insertedCount = inserted.length;
                if (insertedCount > 0) {
                    const source = this.localEntries.find(e => e.id === entryId);
                    if (source) source.outbound_count += insertedCount;
                    for (const g of inserted) {
                        const target = this.localEntries.find(e => e.id === g._selectedTarget);
                        if (target) {
                            target.inbound_count++;
                            target.is_orphaned = false;
                        }
                    }
                }
            }

            this.suggestModal = null;

            // Refresh live count from server (async — persists to index for reload)
            if (insertedCount > 0) {
                this.refreshSuggestionCountForEntry(entryId, mode);
            }
        },

        handleSuggestInsert(selected) {
            // Snapshot everything from this.suggestModal BEFORE we close it.
            // The original bug was: closing the modal nulled this.suggestModal,
            // and the in-flight loop kept dereferencing this.suggestModal.entryHashes
            // → crashed iteration → toast showed only the count completed before close.
            const mode = this.suggestModal.mode;
            if (mode === 'outbound') {
                this.dispatchOutboundInsert(selected);
            } else {
                this.dispatchInboundInsert(selected);
            }
        },

        /**
         * Inbound bulk-insert — single batched request to the existing
         * InboundController::insert endpoint (already accepts up to 50
         * insertions per call). Was previously a frontend per-suggestion
         * loop wrapped in runBulkOperation; that turned a 15-item insert
         * into 15 round-trips for no gain. Backend handles batching atomically.
         *
         * Use-case is small (typical ≤15) and the user is actively in the
         * modal — heavy-async pattern would be overkill. Sync batched is
         * the right trade-off.
         */
        async dispatchInboundInsert(selected) {
            const items = [...selected];
            if (items.length === 0) return;

            const insertUrl = this.inboundInsertUrl;
            const entryHashes = { ...(this.suggestModal.entryHashes || {}) };
            const targetEntryId = this.suggestModal.entryId;

            // Build the insertions payload. Each suggestion targets a
            // DIFFERENT source entry (the entry that should now contain a
            // link to the modal-target entry).
            // Inbound-suggestion items come from InboundController::suggestions
            // → InboundSuggestion::toArray() which returns `source_entry_id`
            // (snake_case), NOT `id`. Earlier mapping `item.id` produced
            // undefined → JSON.stringify dropped the field → backend returned
            // 422 "source_entry_id is required" with NO useful UI message.
            // (Caught only after we added the renderable() validation logger.)
            const insertions = items.map(item => ({
                source_entry_id: item.source_entry_id,
                target_entry_id: targetEntryId,
                anchor_text: item._anchor || item.anchor_text,
                sentence_context: item.sentence_context || '',
            }));

            // Client-side cap with explicit message + alternative.
            // Backend cap is also 200 (InboundController::insert validation
            // rule); enforcing here means the user gets a USEFUL error,
            // not a generic "given data was invalid" 422 toast with no
            // hint of what to do next.
            const MAX_PER_BATCH = 200;
            if (insertions.length > MAX_PER_BATCH) {
                Statamic.$toast.error(
                    `You selected ${insertions.length} items, but Linkwise applies max ${MAX_PER_BATCH} per batch. ` +
                    `Please deselect some entries or apply in two passes.`,
                    { duration: 12000 },
                );
                return;
            }

            this.closeSuggestModal();

            // Heavy-bulk dispatch — backend spawns the LinkInsertCommand via
            // exec(), returns 200 immediately. The unified bulk-status poller
            // in LinkwiseLayout picks up real progress + handles the terminal
            // toast + reload via the shared 'inboundinsert' kind. No more
            // frontend pseudo-bulk simulation.
            const entryTitle = this.suggestModal?.entryTitle || '';

            try {
                const response = await fetch(insertUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        entry_hashes: entryHashes,
                        insertions,
                        entry_title: entryTitle,
                    }),
                });
                if (response.status === 409) {
                    const err = await response.json().catch(() => ({}));
                    Statamic.$toast.error(err.message || 'Another bulk operation is running. Wait for it to finish.', { duration: 12000 });
                    return;
                }
                if (!response.ok) {
                    const err = await response.json().catch(() => ({}));
                    // Surface specific 422 field errors instead of "given
                    // data was invalid". The renderable() exception logger
                    // captures the full payload server-side for support.
                    const fieldErrors = err.errors ? Object.values(err.errors).flat() : [];
                    const detail = fieldErrors[0] || err.message || `HTTP ${response.status}`;
                    Statamic.$toast.error(
                        `Could not start link insert: ${detail} If this persists, download the diagnostic ZIP via Help → and share with support.`,
                        { duration: 12000 },
                    );
                    return;
                }
                // Backend started the command. Trigger an immediate poll
                // so the live banner shows up without 1.5s lag.
                this.$emit('refresh-bulk-status');
            } catch (e) {
                Statamic.$toast.error(
                    `Could not start link insert — ${e.message || 'network error'}. Check your connection and retry.`,
                    { duration: 12000 },
                );
            }
        },

        /**
         * Outbound: one batch HTTP for ALL selected insertions. The backend
         * handles them atomically. Unlike Inbound this never had the for-loop
         * bug, but we still snapshot modal data so the toast logic doesn't
         * crash if the user closes the modal during the request.
         */
        async dispatchOutboundInsert(selected) {
            const groups = this.suggestModal.groups.filter(
                g => selected.includes(g.key) && g._status === 'pending'
            );
            if (groups.length === 0) return;

            const insertUrl = this.outboundInsertUrl;
            const entryId = this.suggestModal.entryId;
            const contentHash = this.suggestModal.contentHash || '';
            const insertions = groups.map(g => ({
                target_entry_id: g._selectedTarget,
                anchor_text: g._anchor,
            }));

            // Same client-side cap as Inbound — backend cap is 200,
            // mirror it client-side with a useful message + alternative.
            const MAX_PER_BATCH = 200;
            if (insertions.length > MAX_PER_BATCH) {
                Statamic.$toast.error(
                    `You selected ${insertions.length} suggestions, but Linkwise applies max ${MAX_PER_BATCH} per batch. ` +
                    `Please deselect some or apply in two passes.`,
                    { duration: 12000 },
                );
                return;
            }

            this.closeSuggestModal();

            // Heavy-bulk dispatch — backend spawns LinkInsertCommand via exec(),
            // returns 200 immediately. The unified bulk-status poller picks up
            // real progress under the 'outboundinsert' kind. Same UX surface
            // as DetailUnlink / Apply Rule / URL-Changer.
            const outboundEntryTitle = this.suggestModal?.entryTitle || '';

            try {
                const response = await fetch(insertUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        entry_id: entryId,
                        content_hash: contentHash,
                        insertions,
                        entry_title: outboundEntryTitle,
                    }),
                });
                if (response.status === 409) {
                    const err = await response.json().catch(() => ({}));
                    Statamic.$toast.error(err.message || 'Another bulk operation is running. Wait for it to finish.', { duration: 12000 });
                    return;
                }
                if (!response.ok) {
                    const err = await response.json().catch(() => ({}));
                    const fieldErrors = err.errors ? Object.values(err.errors).flat() : [];
                    const detail = fieldErrors[0] || err.message || `HTTP ${response.status}`;
                    Statamic.$toast.error(
                        `Could not start link insert: ${detail} If this persists, download the diagnostic ZIP via Help → and share with support.`,
                        { duration: 12000 },
                    );
                    return;
                }
                // Backend started the command; the unified poller will
                // surface progress + fire the terminal toast + reload the
                // page. Trigger an immediate poll so the banner shows
                // without 1.5s lag.
                this.$emit('refresh-bulk-status');
            } catch (e) {
                Statamic.$toast.error(
                    `Could not start link insert — ${e.message || 'network error'}. Check your connection and retry.`,
                    { duration: 12000 },
                );
            }
        },

        makeInboundInserter(insertUrl, entryHashes, total) {
            const csrfToken = Statamic.$config.get('csrfToken');
            return async (s, i) => {
                const isLast = i === total - 1;
                try {
                    const response = await fetch(insertUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            entry_hashes: { [s.source_entry_id]: entryHashes[s.source_entry_id] || '' },
                            insertions: [{
                                source_entry_id: s.source_entry_id,
                                target_entry_id: s.target_entry_id,
                                anchor_text: s._anchor,
                                sentence_context: s.sentence_context || '',
                            }],
                            skip_rebuild: !isLast,
                        }),
                    });
                    if (response.status === 409) {
                        const err = await response.json().catch(() => ({}));
                        return { success: false, error: err.message || 'Content was modified.' };
                    }
                    if (!response.ok) {
                        return { success: false, error: `Request failed (HTTP ${response.status})` };
                    }
                    const data = await response.json();
                    const result = data.results?.[0];
                    return result?.success
                        ? { success: true }
                        : { success: false, error: result?.error || 'Insert failed.' };
                } catch (e) {
                    return { success: false, error: 'Network error' };
                }
            };
        },

        reloadEntries() {
            try {
                inertiaRouter.reload({ only: ['entries'], preserveScroll: true });
            } catch {
                /* if not on an Inertia page, skip silently */
            }
        },

        // ─── Detail Modal ────────────────────────────────────────

        closeModal() {
            if (!this.detailModal) return;

            const unlinked = (this.detailModal.items || []).filter(i => i._unlinked);
            if (unlinked.length > 0) {
                const mode = this.detailModal.mode;
                const entryId = this.detailModal.entryId;

                if (mode === 'outbound') {
                    const entry = this.localEntries.find(e => e.id === entryId);
                    if (entry) {
                        const internalUnlinked = unlinked.filter(i => i.type === 'internal').length;
                        const externalUnlinked = unlinked.filter(i => i.type === 'external').length;
                        entry.outbound_count = Math.max(0, entry.outbound_count - internalUnlinked);
                        entry.external_count = Math.max(0, (entry.external_count || 0) - externalUnlinked);
                    }
                    for (const item of unlinked) {
                        if (item.type === 'internal' && item.url) {
                            const targetId = item.url.replace('statamic://entry::', '');
                            const target = this.localEntries.find(e => e.id === targetId);
                            if (target) {
                                target.inbound_count = Math.max(0, target.inbound_count - 1);
                                target.is_orphaned = target.inbound_count === 0;
                            }
                        }
                    }
                } else {
                    const entry = this.localEntries.find(e => e.id === entryId);
                    if (entry) {
                        entry.inbound_count = Math.max(0, entry.inbound_count - unlinked.length);
                        entry.is_orphaned = entry.inbound_count === 0;
                    }
                    for (const item of unlinked) {
                        const source = this.localEntries.find(e => e.id === item.id);
                        if (source) source.outbound_count = Math.max(0, source.outbound_count - 1);
                    }
                }

                // Refresh suggestion counts for the affected entry via modal endpoint
                // (triggers persist-on-open: live computation + index update)
                this.refreshSuggestionCountForEntry(entryId, mode);
            }

            this.detailModal = null;
        },

        showDetail(type, entry, filter = null) {
            if (type === 'inbound') {
                const items = [];
                const targetHref = 'statamic://entry::' + entry.id;
                const occurrences = {};

                for (const e of this.localEntries) {
                    if (e.id === entry.id) continue;
                    const details = e.internal_links_detail || [];
                    for (const link of details) {
                        if (link.entry_id === entry.id) {
                            const occKey = `${e.id}::${link.href}`;
                            const occIdx = occurrences[occKey] || 0;
                            occurrences[occKey] = occIdx + 1;

                            items.push({
                                id: e.id,
                                title: e.title,
                                collection: e.collection,
                                edit_url: e.edit_url,
                                anchor_text: link.anchor_text || '',
                                sentence_context: link.sentence_context || '',
                                context_truncated_start: link.context_truncated_start || false,
                                context_truncated_end: link.context_truncated_end || false,
                                _anchor: link.anchor_text || '',
                                _originalAnchor: link.anchor_text || '',
                                type: 'internal',
                                url: targetHref,
                                occurrence_index: occIdx,
                            });
                        }
                    }
                }

                this.detailModal = {
                    title: `Inbound links to "${entry.title}"`,
                    entryTitle: entry.title,
                    mode: 'inbound',
                    entryId: entry.id,
                    items,
                };
            } else {
                const entriesById = Object.fromEntries(this.localEntries.map(e => [e.id, e]));
                let items = [];
                const occurrences = {};

                // Internal links (with anchor text from Bard/Markdown)
                if (filter !== 'external') {
                    const internalDetails = entry.internal_links_detail || [];
                    for (const link of internalDetails) {
                        const found = entriesById[link.entry_id];
                        const isSelf = link.entry_id === entry.id;
                        const occKey = `${entry.id}::${link.href}`;
                        const occIdx = occurrences[occKey] || 0;
                        occurrences[occKey] = occIdx + 1;

                        items.push({
                            anchor_text: link.anchor_text || '',
                            sentence_context: link.sentence_context || '',
                            context_truncated_start: link.context_truncated_start || false,
                            context_truncated_end: link.context_truncated_end || false,
                            _anchor: link.anchor_text || '',
                            _originalAnchor: link.anchor_text || '',
                            url: link.href,
                            title: found ? found.title : 'Deleted entry (' + link.entry_id.substring(0, 8) + '...)',
                            edit_url: found ? found.edit_url : null,
                            type: 'internal',
                            warning: isSelf ? 'Self-link — wastes link equity' : (!found ? 'Target entry deleted' : null),
                            occurrence_index: occIdx,
                        });
                    }
                }

                // External links
                if (filter !== 'internal') {
                    const externalLinks = entry.external_links || [];
                    for (const link of externalLinks) {
                        const occKey = `${entry.id}::${link.url}`;
                        const occIdx = occurrences[occKey] || 0;
                        occurrences[occKey] = occIdx + 1;

                        items.push({
                            url: link.url,
                            title: link.anchor_text || link.url,
                            anchor_text: link.anchor_text || '',
                            sentence_context: link.sentence_context || '',
                            context_truncated_start: link.context_truncated_start || false,
                            context_truncated_end: link.context_truncated_end || false,
                            _anchor: link.anchor_text || '',
                            _originalAnchor: link.anchor_text || '',
                            edit_url: null,
                            type: 'external',
                            occurrence_index: occIdx,
                        });
                    }
                }

                const titleMap = {
                    internal: `Internal outbound links from "${entry.title}"`,
                    external: `External outbound links from "${entry.title}"`,
                };

                this.detailModal = {
                    title: titleMap[filter] || `Outbound links from "${entry.title}"`,
                    entryTitle: entry.title,
                    mode: 'outbound',
                    entryId: entry.id,
                    items,
                };
            }
        },

        /** Word-wrap text at max chars by inserting newlines at word boundaries. */
        wrapText(text, max = 35) {
            if (!text || text.length <= max) return text;
            const words = text.split(/\s+/);
            let line = '';
            const lines = [];
            for (const word of words) {
                if (line && (line + ' ' + word).length > max) {
                    lines.push(line);
                    line = word;
                } else {
                    line = line ? line + ' ' + word : word;
                }
            }
            if (line) lines.push(line);
            return lines.join('\n');
        },

        exportCsv() {
            const headers = ['Title', 'Collection', 'URL', 'Inbound', 'Int. Out', 'Ext. Out', 'Inb. Sugg.', 'Out. Sugg.', 'Orphan'];
            const rows = this.filteredEntries.map(e => [
                `"${this.wrapText(e.title, 30).replace(/"/g, '""')}"`,
                e.collection,
                `"${this.wrapText((e.url || '').replace(/^https?:\/\//, ''), 35).replace(/"/g, '""')}"`,
                e.inbound_count,
                e.outbound_count,
                e.external_count || 0,
                this.getInboundSuggestionCount(e.id),
                this.getOutboundSuggestionCount(e.id),
                e.is_orphaned ? 'Yes' : 'No',
            ]);

            const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', 'linkwise-report.csv');
            link.click();
            URL.revokeObjectURL(url);
        },
    },
};
</script>
