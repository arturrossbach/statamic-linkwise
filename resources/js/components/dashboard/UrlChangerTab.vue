<template>
    <div>
        <!-- Intro -->
        <Card class="mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">URL Changer</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Find links by domain, URL, or keyword. Select which ones to replace and enter new URLs individually or for all at once.
                    </p>
                </div>
                <HelpIcon tooltip="Smart match: finds links by domain, partial text, or path (e.g. 'spiegel.de' finds all links to that domain). Exact match: matches one specific URL string only. Leave search empty to list all links." />
            </div>
        </Card>

        <!-- Search with Combobox (WCAG WAI-ARIA 1.2 Combobox Pattern) -->
        <Card class="mb-6">
            <div class="relative">
                <div class="flex items-center gap-2 mb-1">
                    <label id="url-search-label" class="text-xs text-gray-500 dark:text-gray-400">Search for a domain, URL, or keyword</label>
                    <select v-model="searchMode" class="text-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded px-1.5 py-0.5" aria-label="Match mode">
                        <option value="smart">Smart match</option>
                        <option value="exact">Exact match</option>
                    </select>
                </div>
                <input
                    ref="searchInput"
                    v-model="search"
                    type="text"
                    placeholder="e.g. spiegel.de, thesun, /topic/old-page"
                    class="w-full text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-2"
                    @keydown.down.prevent="navigateSuggestion(1)"
                    @keydown.up.prevent="navigateSuggestion(-1)"
                    @keydown.enter.prevent="onEnter"
                    @keydown.escape="closeSuggestions"
                    @keydown.tab="closeSuggestions"
                    @keydown.home.prevent="suggestionIndex = 0"
                    @keydown.end.prevent="suggestionIndex = filteredDomains.length - 1"
                    @focus="showSuggestions = true"
                    @blur="hideSuggestionsDelayed"
                    role="combobox"
                    autocomplete="off"
                    aria-autocomplete="list"
                    aria-haspopup="listbox"
                    aria-labelledby="url-search-label"
                    :aria-expanded="String(listboxOpen)"
                    :aria-activedescendant="activeDescendantId"
                    aria-controls="domain-listbox"
                />
                <!-- Loading indicator -->
                <div v-if="previewing" class="flex items-center gap-1 text-xs text-gray-400 mt-2">
                    <Icon name="loading" class="size-3.5 animate-spin" />
                    searching…
                </div>
                <!-- Domain suggestions listbox -->
                <ul
                    v-show="listboxOpen"
                    id="domain-listbox"
                    class="absolute z-50 left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                    role="listbox"
                    aria-labelledby="url-search-label"
                    :aria-hidden="String(!listboxOpen)"
                >
                    <li
                        v-for="(domain, idx) in filteredDomains"
                        :key="domain.domain"
                        :id="'domain-option-' + idx"
                        @mousedown.prevent="selectDomain(domain.domain)"
                        :class="idx === suggestionIndex ? 'bg-blue-50 dark:bg-blue-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700'"
                        class="px-3 py-2 text-sm cursor-pointer flex items-center justify-between"
                        role="option"
                        :aria-selected="idx === suggestionIndex"
                    >
                        <span class="text-gray-900 dark:text-gray-100">{{ domain.domain }}</span>
                        <span class="text-xs text-gray-400">{{ domain.link_count }} links</span>
                    </li>
                </ul>
                <!-- Live region for screen readers -->
                <div class="sr-only" aria-live="polite" aria-atomic="true">
                    <span v-if="listboxOpen">{{ filteredDomains.length }} suggestions available</span>
                </div>
            </div>
        </Card>

        <!-- Results summary -->
        <div v-if="searched && !previewing && matches.length > 0" class="mt-2 mb-4 text-xs text-gray-400">
            Found {{ matches.length }} {{ matches.length === 1 ? 'link' : 'links' }} in {{ uniqueEntryCount }} {{ uniqueEntryCount === 1 ? 'entry' : 'entries' }}
        </div>

        <!-- No Results -->
        <div v-if="searched && matches.length === 0 && !previewing" class="py-8 text-center">
            <p v-if="search.trim()" class="text-gray-500 dark:text-gray-400">No links found matching "{{ search }}".</p>
            <p v-else class="text-gray-500 dark:text-gray-400">No links found in any entry.</p>
        </div>

        <!-- Results -->
        <div v-if="matches.length > 0">
            <!-- Actions Bar — two semantic groups:
                 (1) Input + "Set for all" — populating the staging field
                 (2) Apply / Unlink — committing the change to all rows
                 Group 1 grows to fill space; group 2 stays compact. On
                 narrow viewports both groups stack via outer flex-wrap. -->
            <Card class="mb-4">
                <div class="flex flex-wrap items-center gap-3 gap-y-3">
                    <!-- Group 1: staging input -->
                    <div class="flex flex-wrap items-center gap-2 flex-1 min-w-[260px]">
                        <label class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">New URL:</label>
                        <div class="flex-1 min-w-[160px]">
                            <Input
                                v-model="bulkReplaceUrl"
                                placeholder="https://new-domain.com"
                                size="sm"
                                :input-attrs="{ 'aria-invalid': bulkUrlInvalid ? 'true' : 'false', style: bulkUrlInvalid ? invalidInputStyle : '', 'data-linkwise-invalid': bulkUrlInvalid ? 'true' : 'false' }"
                            />
                        </div>
                        <Button @click="setBulkReplace" :disabled="!bulkReplaceUrl.trim() || bulkUrlInvalid" :text="setForLabel" size="sm" />
                    </div>
                    <!-- Group 2: commit actions -->
                    <div class="flex flex-wrap items-center gap-2">
                        <Button @click="confirmAction = 'replace'" :disabled="readyCount === 0 || globalBulkRunning" variant="primary" :text="'Apply ' + readyCount + ' change' + (readyCount !== 1 ? 's' : '')" icon="checkmark" v-tooltip="globalBulkRunning ? 'Waiting for the running bulk operation to finish' : null" />
                        <Button @click="confirmAction = 'unlink'" :disabled="selectedCount === 0 || globalBulkRunning" :text="'Unlink ' + selectedCount + ' link' + (selectedCount !== 1 ? 's' : '')" v-tooltip="globalBulkRunning ? 'Waiting for the running bulk operation to finish' : null" />
                    </div>
                </div>
                <p v-if="bulkUrlInvalid" class="mt-2 text-xs text-red-600 dark:text-red-400">
                    Not a valid URL. Use http(s)://, mailto: or tel:.
                </p>
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    Fills the New URL field on selected rows (or all rows if none selected). Apply only replaces rows that are <strong>both selected and have a valid New URL</strong>.
                </p>
            </Card>

            <!-- Matches Table -->
            <Panel>
                <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm table-fixed" style="min-width: 1100px;">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 32px">
                                <Checkbox
                                    :model-value="allSelected"
                                    :indeterminate="selectedCount > 0 && !allSelected"
                                    solo
                                    size="sm"
                                    @update:model-value="toggleSelectAll"
                                />
                            </th>
                            <SortableHeader label="Entry" :sortable="false" style="width: 18%" />
                            <SortableHeader label="Anchor" :sortable="false" style="width: 8%" />
                            <SortableHeader label="Context" :sortable="false" style="width: 22%" />
                            <SortableHeader label="Current URL" :sortable="false" style="width: 20%" />
                            <SortableHeader label="Replace with" :sortable="false" style="width: 28%" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(match, idx) in matches" :key="`${match.entry_id}-${match.matched_url}-${match.occurrence_index}`">
                            <td>
                                <Checkbox v-model="match.selected" solo size="sm" />
                            </td>
                            <td>
                                <Link :href="match.edit_url" class="hover:text-blue-600 dark:hover:text-blue-400 block truncate" v-tooltip="match.entry_title">
                                    {{ match.entry_title }}
                                </Link>
                                <div class="text-xs text-gray-400">{{ match.collection }}</div>
                            </td>
                            <td class="font-medium text-gray-900 dark:text-gray-100">{{ match.anchor_text || '—' }}</td>
                            <td class="text-xs text-gray-400 dark:text-gray-500 max-w-xs" v-html="highlightAnchor(match.context, match.anchor_text)"></td>
                            <td class="text-xs text-gray-500 dark:text-gray-400 break-all overflow-hidden">{{ match.matched_url }}</td>
                            <td>
                                <Input
                                    v-model="match.new_url"
                                    placeholder="https://..."
                                    size="sm"
                                    :input-attrs="{ 'aria-invalid': rowUrlInvalid(match) ? 'true' : 'false', style: rowUrlInvalid(match) ? invalidInputStyle : '', 'data-linkwise-invalid': rowUrlInvalid(match) ? 'true' : 'false' }"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table></div>
            </Panel>
        </div>

        <!-- Confirmation Modal -->
        <ConfirmationModal
            :open="confirmAction !== null"
            @update:open="confirmAction = null"
            @confirm="onConfirm"
            @cancel="confirmAction = null"
            :title="confirmAction === 'unlink' ? 'Unlink selected' : 'Replace URLs'"
            :body-text="confirmAction === 'unlink'
                ? 'This will remove ' + selectedCount + ' link(s) from your entries. The text will remain but will no longer be linked. This cannot be undone.'
                : 'This will replace ' + readyCount + ' URL(s) across your entries. This cannot be undone.'"
            :button-text="confirmAction === 'unlink' ? 'Unlink' : 'Replace'"
            :danger="confirmAction === 'unlink'"
        />
    </div>
</template>

<script>
import { Link, router as inertiaRouter } from '@statamic/cms/inertia';
import { Card, Panel, Button, Icon, Input, Checkbox, ConfirmationModal } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import { highlightAnchor } from '../../utils/highlight.js';
import { isValidReplacementUrl } from '../../utils/urlValidation.js';
import { errorToast } from '../../utils/toast.js';
import { bulkState, setHeavyState } from '../../services/bulkOperationService.js';
import { readJson, writeJson } from '../../utils/safeStorage.js';

const URL_CHANGER_STATE_KEY = 'linkwise.urlchanger.state';

export default {
    components: { Link, Card, Panel, Button, Icon, Input, Checkbox, ConfirmationModal, HelpIcon, SortableHeader },

    props: {
        data: { type: Object, required: true },
        domains: { type: Array, default: () => [] },
        initialSearch: { type: String, default: '' },
    },

    data() {
        return {
            search: this.initialSearch || '',
            searchMode: 'smart',
            previewedMode: 'smart',
            bulkReplaceUrl: '',
            suppressSuggestions: false,
            previewing: false,
            searched: false,
            matches: [],
            entryHashes: {},
            confirmAction: null,
            showSuggestions: false,
            suggestionIndex: -1,
            debounceTimer: null,
        };
    },

    computed: {
        // True while ANY Linkwise bulk op is running (light or heavy). Disables
        // Apply/Unlink so user can't queue a second bulk before the first ends.
        globalBulkRunning() {
            return bulkState.active !== null;
        },
        canPreview() {
            return this.search.trim().length >= 3 && !this.previewing;
        },
        selectedCount() {
            return this.matches.filter(m => m.selected).length;
        },
        readyCount() {
            // Apply targets: rows that are selected AND have a VALID new URL.
            // Validation prevents "Apply 5" then backend rejects garbage like
            // "www.fose.de - hallo".
            return this.matches.filter(m => m.selected && isValidReplacementUrl(m.new_url)).length;
        },
        setForLabel() {
            // "Set for X selected" when rows are checked, "Set for all (Y)" otherwise.
            // Mirrors the bifurcation in setBulkReplace().
            return this.selectedCount > 0
                ? `Set for ${this.selectedCount} selected`
                : `Set for all (${this.matches.length})`;
        },
        bulkUrlInvalid() {
            // True only when user has typed something AND it doesn't validate.
            // Empty input is "neutral" — don't flag a pristine field.
            const v = (this.bulkReplaceUrl || '').trim();
            return v !== '' && !isValidReplacementUrl(v);
        },
        /**
         * Inline style for the invalid-input visual state. Reused by the
         * bulk-replace input + per-row inputs.
         *
         * Why inline-style instead of Tailwind classes: Linkwise has no own
         * Tailwind config — Statamic's Tailwind only scans ITS OWN source
         * files, not Linkwise components. So Tailwind class strings like
         * `border-red-500` would never make it into the final CSS bundle
         * and the border would silently stay default-gray. Inline `style`
         * survives the build pipeline unchanged.
         *
         * #ef4444 = Tailwind red-500 hex equivalent.
         */
        invalidInputStyle() {
            return 'border-color: #ef4444 !important; box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.45);';
        },
        allSelected() {
            return this.matches.length > 0 && this.matches.every(m => m.selected);
        },
        uniqueEntryCount() {
            return new Set(this.matches.map(m => m.entry_id)).size;
        },
        filteredDomains() {
            if (!this.search.trim()) return this.domains.slice(0, 10);
            const q = this.search.trim().toLowerCase();
            return this.domains.filter(d => d.domain.toLowerCase().includes(q)).slice(0, 8);
        },
        listboxOpen() {
            return this.showSuggestions && this.filteredDomains.length > 0;
        },
        activeDescendantId() {
            return this.suggestionIndex >= 0 ? 'domain-option-' + this.suggestionIndex : undefined;
        },
    },

    watch: {
        searchMode() {
            // Re-run preview on mode switch — empty search is allowed (lists all links).
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => this.runPreview(), 400);
            this.persistState();
        },
        search(val) {
            this.searched = false;
            this.suggestionIndex = -1;
            this.persistState();

            if (this.suppressSuggestions) {
                this.suppressSuggestions = false;
            } else {
                this.showSuggestions = true;
            }

            // Debounced live search.
            // - Empty input → list all links (default view).
            // - 1 or 2 chars → wait, too speculative.
            // - 3+ chars → run preview.
            clearTimeout(this.debounceTimer);
            const len = val.trim().length;
            if (len === 0 || len >= 3) {
                this.debounceTimer = setTimeout(() => {
                    this.runPreview();
                }, 400);
            }
        },
    },

    mounted() {
        // Hydrate from sessionStorage UNLESS the page was opened with a
        // ?search= query param (deep-link from Domains tab "Edit Links" wins
        // over a stale stored search). Storage holds {search, mode}.
        // safeStorage swallows quota / private-mode failures silently —
        // hydration just falls through to defaults.
        if (!this.initialSearch) {
            const stored = readJson(URL_CHANGER_STATE_KEY);
            if (stored) {
                if (typeof stored.search === 'string') this.search = stored.search;
                if (stored.mode === 'smart' || stored.mode === 'exact') {
                    this.searchMode = stored.mode;
                    this.previewedMode = stored.mode;
                }
            }
        }

        // Always run a preview on mount — empty search returns all links,
        // a value returns the filtered set. Vue does NOT fire the search
        // watcher for the initial data() value, so we trigger manually.
        this.suppressSuggestions = true;
        this.runPreview();
    },

    beforeUnmount() {
        clearTimeout(this.debounceTimer);
        clearTimeout(this.suggestionsHideTimer);
    },

    methods: {
        setSearch(value) {
            this.suppressSuggestions = true;
            this.search = value;
            this.searchMode = 'smart';
        },

        toggleSelectAll(value) {
            // Statamic Checkbox emits update:model-value with the new bool.
            // Indeterminate state always resolves to checked=true on click.
            const checked = !!value;
            this.matches.forEach(m => m.selected = checked);
        },

        rowUrlInvalid(match) {
            // Per-row visual hint: red border only when user has typed something
            // but it doesn't validate.
            return !!(match.new_url && match.new_url.trim() !== '' && !isValidReplacementUrl(match.new_url));
        },

        /**
         * Fill the New URL field on a batch of rows. If any rows are selected,
         * fill ONLY those — selected = "scoped" intent. Otherwise fall back to
         * all visible rows. Always set selected=true on filled rows so Apply
         * picks them up (readyCount = selected ∧ has-new_url).
         */
        setBulkReplace() {
            const url = this.bulkReplaceUrl.trim();
            if (!url) return;
            const targets = this.matches.some(m => m.selected)
                ? this.matches.filter(m => m.selected)
                : this.matches;
            targets.forEach(m => {
                m.new_url = url;
                m.selected = true;
            });
            Statamic.$toast.success(`Set new URL on ${targets.length} row${targets.length === 1 ? '' : 's'}.`);
        },

        selectDomain(domain) {
            this.suppressSuggestions = true;
            this.search = domain;
            this.closeSuggestions();
        },

        navigateSuggestion(direction) {
            if (!this.listboxOpen) {
                this.showSuggestions = true;
                return;
            }
            const max = this.filteredDomains.length - 1;
            if (direction > 0) {
                this.suggestionIndex = this.suggestionIndex >= max ? 0 : this.suggestionIndex + 1;
            } else {
                this.suggestionIndex = this.suggestionIndex <= 0 ? max : this.suggestionIndex - 1;
            }
        },

        onEnter() {
            if (this.listboxOpen && this.suggestionIndex >= 0 && this.filteredDomains[this.suggestionIndex]) {
                // Select the highlighted suggestion — sets the input value, triggers live search
                this.selectDomain(this.filteredDomains[this.suggestionIndex].domain);
            } else {
                // No suggestion selected — run search directly
                this.closeSuggestions();
                clearTimeout(this.debounceTimer);
                this.runPreview();
            }
        },

        closeSuggestions() {
            this.showSuggestions = false;
            this.suggestionIndex = -1;
        },

        hideSuggestionsDelayed() {
            this.suggestionsHideTimer = setTimeout(() => this.closeSuggestions(), 200);
        },

        /**
         * Persist current search + mode to sessionStorage so the user lands on
         * the same view after a tab switch (Inertia keeps the page mounted but
         * a hard navigation away and back would otherwise lose state).
         */
        persistState() {
            writeJson(URL_CHANGER_STATE_KEY, {
                search: this.search || '',
                mode: this.searchMode,
            });
        },

        highlightAnchor,

        async runPreview() {
            this.previewing = true;
            this.matches = [];
            this.searched = false;

            try {
                const response = await fetch(this.data.preview_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ search: this.search, mode: this.searchMode }),
                });

                if (response.ok) {
                    const result = await response.json();
                    // Lock the mode used for THIS preview — Apply must use the same
                    // mode so occurrence_index counters align. If the user switches
                    // mode after preview, Apply still uses the mode that produced
                    // the visible matches.
                    this.previewedMode = this.searchMode;
                    // Store content hashes for optimistic locking
                    this.entryHashes = {};
                    this.matches = [];
                    for (const entry of result.entries) {
                        this.entryHashes[entry.id] = entry.content_hash;
                        for (const occ of entry.occurrences) {
                            this.matches.push({
                                entry_id: entry.id,
                                entry_title: entry.title,
                                collection: entry.collection,
                                edit_url: entry.edit_url,
                                anchor_text: occ.anchor_text,
                                context: occ.context || '',
                                matched_url: occ.matched_url,
                                field: occ.field,
                                field_type: occ.field_type,
                                occurrence_index: occ.occurrence_index,
                                new_url: '',
                                selected: false,
                            });
                        }
                    }
                } else {
                    // Surface the backend reason — analog to Domains tab's
                    // pattern. 422 returns {message}, 423/500 return {error}.
                    const data = await response.json().catch(() => null);
                    const reason = data?.message || data?.error || `HTTP ${response.status}`;
                    errorToast(`Search failed: ${reason}`);
                }
            } catch (error) {
                errorToast(`Search failed: ${error.message || 'network error'}`);
                console.error('[Linkwise] URL search failed:', error);
            } finally {
                this.previewing = false;
                this.searched = true;
            }
        },

        onConfirm() {
            if (this.confirmAction === 'unlink') {
                this.executeUnlink();
            } else {
                this.executeApply();
            }
        },

        executeApply() {
            this.confirmAction = null;
            // Same gate as readyCount: selected AND valid URL.
            const ready = this.matches.filter(m => m.selected && isValidReplacementUrl(m.new_url));
            if (ready.length === 0) return;
            this.dispatchBulk(
                ready.map(m => ({ ...this._toReplacement(m), new_url: m.new_url.trim() })),
                'apply',
            );
        },

        executeUnlink() {
            this.confirmAction = null;
            const selected = this.matches.filter(m => m.selected);
            if (selected.length === 0) return;
            this.dispatchBulk(
                selected.map(m => ({ ...this._toReplacement(m), new_url: '__LINKWISE_UNLINK__' })),
                'unlink',
            );
        },

        _toReplacement(m) {
            return {
                entry_id: m.entry_id,
                field: m.field,
                field_type: m.field_type,
                matched_url: m.matched_url,
                occurrence_index: m.occurrence_index,
                // Carried forward into the activity-log snapshot so the
                // drawer can show "this anchor in this sentence", and
                // revertHelper can re-add the link mark on Unlink-revert
                // (outbound-insert needs the anchor to find the right text).
                anchor_text: m.anchor_text || '',
                sentence_context: m.context || '',
            };
        },

        /**
         * Dispatch a URL Changer bulk as a HEAVY (server-side) job.
         *
         * Why heavy: a domain migration can hit 500+ replacements. A frontend
         * loop dies on browser tab close, browser refresh, or nav-away —
         * losing all progress. The detached artisan command survives all of
         * those. The LinkwiseLayout poller picks up status on every tab and
         * fires the completion toast when phase reaches 'done'.
         *
         * @param  {Array}  replacements  fully-built replacement objects
         * @param  {string} action        'apply' or 'unlink' — drives banner verb + toast
         */
        async dispatchBulk(replacements, action) {
            if (replacements.length === 0) return;
            if (!this.data.apply_async_url) {
                Statamic.$toast.error('URL Changer async endpoint not configured.');
                return;
            }

            try {
                const response = await fetch(this.data.apply_async_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        search: this.search || '',
                        mode: this.previewedMode,
                        action,
                        replacements,
                        entry_hashes: this.entryHashes,
                    }),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => null);
                    // Laravel 422 returns `errors: { 'field.path': ['msg', ...] }`.
                    // Surface the first concrete validation error so the user
                    // sees "replacements.0.occurrence_index: required" instead
                    // of generic "the given data was invalid".
                    let reason;
                    if (data?.errors && typeof data.errors === 'object') {
                        const firstKey = Object.keys(data.errors)[0];
                        const firstMsg = Array.isArray(data.errors[firstKey])
                            ? data.errors[firstKey][0]
                            : String(data.errors[firstKey]);
                        reason = `${firstKey}: ${firstMsg}`;
                    } else {
                        reason = data?.message || data?.error || `HTTP ${response.status}`;
                    }
                    errorToast(`Could not start: ${reason}`);
                    return;
                }

                // Immediate confirmation that the dispatch landed — covers the
                // case where the user navigates away before the terminal toast
                // fires (which was happening: tab-switch unmounted the layout's
                // poller before it could observe 'done').
                const verb = action === 'unlink' ? 'unlinking' : 'replacing';
                Statamic.$toast.success(`Started — ${verb} ${replacements.length} URL${replacements.length === 1 ? '' : 's'} in the background.`);

                // Surface the global cross-tab banner IMMEDIATELY. Without this
                // a user-visible banner only appears after the layout poller's
                // 1.5s interval — short bulks would finish before that and
                // produce no banner at all. Layout poller updates current/total
                // when its next tick fires.
                setHeavyState({
                    kind: 'urlchanger',
                    label: 'URL changer',
                    current: 0,
                    total: replacements.length,
                    canCancel: true,
                    cancelUrl: this.data.apply_cancel_url || null,
                    context: { action, search: this.search || '', startedBy: null },
                });

                // Trigger succeeded — server-side job is now running. The
                // LinkwiseLayout poller picks it up within 1-2s and the live
                // banner appears. Completion toast is fired by the poller's
                // fireTerminalToast() when phase=done. We start watching the
                // bulk state to refresh the matches table when it finishes.
                this.watchBulkCompletion();
            } catch (error) {
                errorToast(`Could not start: ${error.message || 'network error'}`);
            }
        },

        /**
         * Watch the unified bulkState for our urlchanger job to finish, then
         * refresh the local UI. Without this the matches table would stay on
         * pre-bulk data even after the server job completed.
         */
        watchBulkCompletion() {
            // Vue 3 watch via $watch since we want a one-shot observer.
            const stop = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    // Transition from 'urlchanger active' to null = job finished
                    // (success, partial, error, or cancel — poller cleared it).
                    if (previous?.kind === 'urlchanger' && current === null) {
                        stop();
                        // Refresh matches table — the action toast already
                        // told the user what happened. We just sync the UI.
                        this.runPreview();
                        // Reset bulk-input regardless of outcome (the matches
                        // table refresh wipes selection anyway).
                        this.bulkReplaceUrl = '';
                        // Domains may have entries that lost all their links.
                        inertiaRouter.reload({ only: ['domains'], preserveScroll: true });
                    }
                },
            );
            // Safety: also stop the watcher after 30 minutes — if something
            // goes catastrophically wrong on the server, don't leak watchers.
            setTimeout(() => stop(), 30 * 60 * 1000);
        },
    },
};
</script>
