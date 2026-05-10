<template>
    <div>
        <!-- Top Bar: Last checked + Check Links -->
        <!-- Per-tab check-progress was removed — the global LinkwiseLayout
             banner already shows the running 'check' job on every tab, so
             repeating it here was redundant. The Button below carries
             :loading=checking as the in-tab affordance. -->
        <div class="flex items-center justify-between mb-3 gap-3">
            <span v-if="metadata" class="text-xs text-gray-400">
                Last checked: {{ formatDate(metadata.last_checked) }}
            </span>
            <span v-else></span>
            <div class="flex items-center gap-2 shrink-0">
                <Button v-if="exportUrl && data.broken_links && data.broken_links.length > 0" @click="exportCsv" text="Export CSV" icon="download" v-tooltip="'Download all broken links as CSV'" />
                <Button @click="$emit('check-links')" :loading="checking" :disabled="checking" text="Check Links" icon="sync" v-tooltip="'Test all external URLs for broken links (404, timeouts, SSL errors)'" />
            </div>
        </div>

        <!-- Filter Bar + Bulk Actions -->
        <div class="flex flex-wrap items-center justify-between gap-y-2 gap-x-3 mb-4">
            <div class="flex flex-wrap items-center gap-2">
                <div class="w-64 shrink-0">
                    <Input
                        v-model="searchQuery"
                        size="sm"
                        icon="magnifying-glass"
                        clearable
                        placeholder="Search entries or URLs..."
                        aria-label="Search broken links"
                        :disabled="applying"
                    />
                </div>
                <!-- Native <select> is intentional: Statamic's Select is a searchable Combobox,
                     which is overkill and laggy (~600ms open) for 3 static options -->
                <select
                    v-model="typeFilter"
                    aria-label="Filter by link type"
                    :disabled="applying"
                    class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md px-2 py-1.5 shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <option v-for="opt in typeFilterOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <MultiSelect
                    v-model="activeStatuses"
                    :options="availableStatuses"
                    label="Status"
                    :disabled="applying"
                />
            </div>
            <Button v-if="selectedLinks.length > 0" @click="openBulkUnlinkConfirm" :loading="applying" :text="'Unlink ' + selectedLinks.length + ' selected'" size="sm" />
        </div>

        <!-- No Report Yet -->
        <div v-if="!metadata && brokenLinks.length === 0" class="py-12 text-center">
            <p class="text-gray-500 dark:text-gray-400 mb-4">No link check has been run yet.</p>
            <Button @click="$emit('check-links')" :loading="checking" variant="primary" text="Check All Links" />
        </div>

        <!-- No Broken Links — table still shows if user included "Ignored" in the Status filter -->
        <div v-else-if="activeBrokenCount === 0 && sortedLinks.length === 0" class="py-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-12 mx-auto mb-3 text-green-400">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <p class="text-green-600 dark:text-green-400 font-medium">No broken links found.</p>
            <p class="text-xs text-gray-400 mt-1">All links are healthy.</p>
            <Button
                v-if="ignoredCount > 0"
                @click="showIgnoredInFilter"
                size="sm"
                variant="default"
                :text="'Show ' + ignoredCount + ' ignored link(s)'"
                class="mt-3"
            />
        </div>

        <!-- Broken Links Table -->
        <div v-else>
            <!-- Conflict Banner (another editor modified an entry) -->
            <div
                v-if="conflictBanner"
                class="mb-3 px-4 py-3 rounded-lg border bg-yellow-50 border-yellow-300 text-yellow-900 dark:bg-yellow-900/20 dark:border-yellow-800/50 dark:text-yellow-200 flex items-start gap-3"
                role="alert"
            >
                <Icon name="warning" class="size-4 shrink-0 mt-0.5" />
                <div class="flex-1 text-sm">
                    <p class="font-medium">Conflict — entry was modified elsewhere</p>
                    <p class="mt-0.5 text-xs">{{ conflictBanner }} The list below has been refreshed with the current scan state — review the row's anchor + context before clicking again, the link may have moved or been replaced.</p>
                </div>
                <Button
                    @click="conflictBanner = null"
                    variant="ghost"
                    size="sm"
                    icon="x"
                    aria-label="Dismiss"
                />
            </div>

            <!-- Bulk Unlink Progress/Result Banner -->
            <div
                v-if="bulkProgress"
                class="mb-3 px-4 py-3 rounded-lg border bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800/50 dark:text-blue-300 flex items-center gap-3"
                role="status"
                aria-live="polite"
            >
                <Icon name="loading" class="size-4 shrink-0" />
                <span class="text-sm font-medium">Unlinking {{ bulkProgress.current }} / {{ bulkProgress.total }}…</span>
            </div>

            <div
                v-else-if="bulkResult"
                class="mb-3 px-4 py-3 rounded-lg border"
                :class="bulkResultClasses"
                role="status"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <p class="text-sm font-medium">
                            <template v-if="bulkResult.cancelled">Cancelled &middot; </template>
                            {{ bulkResult.succeeded }} of {{ bulkResult.total }} unlinked
                            <template v-if="bulkResult.skipped > 0"> &middot; {{ bulkResult.skipped }} skipped</template>
                        </p>
                        <ul v-if="bulkResult.skipped > 0" class="mt-1 text-xs space-y-0.5">
                            <li v-for="(count, reason) in bulkResult.errors" :key="reason">
                                · {{ count }}× {{ reason }}
                            </li>
                        </ul>
                    </div>
                    <Button
                        @click="dismissBulkResult"
                        variant="ghost"
                        size="sm"
                        icon="x"
                        aria-label="Dismiss"
                    />
                </div>
            </div>

            <Pagination
                v-if="showPagination"
                class="mb-3"
                :resource-meta="paginationMeta"
                :per-page="perPage"
                :show-per-page-selector="false"
                @page-selected="currentPage = $event"
            />
            <Panel>
                <div class="overflow-x-auto">
                <table data-size="sm" class="data-table w-full text-sm" style="table-layout: fixed; min-width: 900px;">
                    <colgroup>
                        <col v-if="applyUrl" style="width: 36px;" />
                        <col style="width: 17%;" />
                        <col style="width: 21%;" />
                        <col style="width: 22%;" />
                        <col style="width: 70px;" />
                        <col style="width: 130px;" />
                        <col style="width: 110px;" />
                        <col v-if="applyUrl" style="width: 150px;" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th v-if="applyUrl" scope="col">
                                <input
                                    type="checkbox"
                                    class="rounded"
                                    :checked="allSelected"
                                    :indeterminate.prop="someSelected && !allSelected"
                                    :disabled="applying"
                                    aria-label="Select all broken links"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <SortableHeader label="Entry" :active="sortField === 'post_title'" :direction="sortDirection" @sort="handleSort('post_title')" />
                            <SortableHeader label="Broken URL" :active="sortField === 'url'" :direction="sortDirection" @sort="handleSort('url')" />
                            <SortableHeader label="Context" :sortable="false" />
                            <SortableHeader label="Type" align="center" :active="sortField === 'type'" :direction="sortDirection" @sort="handleSort('type')" />
                            <SortableHeader label="Status" align="center" :active="sortField === 'status_label'" :direction="sortDirection" @sort="handleSort('status_label')">
                                <HelpIcon tooltip="HTTP status code or error type returned when checking this URL." />
                            </SortableHeader>
                            <SortableHeader label="Discovered" align="right" :active="sortField === 'first_detected_at'" :direction="sortDirection" @sort="handleSort('first_detected_at')">
                                <HelpIcon tooltip="When this broken link was first detected. Preserved across re-scans so you can see how long it has been broken." />
                            </SortableHeader>
                            <SortableHeader v-if="applyUrl" label="Actions" :sortable="false" align="right" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="link in paginatedLinks"
                            :key="`${link.post_id}-${link.url}`"
                            :class="{
                                'bg-green-50 dark:bg-green-900/10': link._fixed,
                                'bg-gray-100 dark:bg-gray-800/40 opacity-60': link.ignored && !link._fixed,
                                'opacity-60 pointer-events-none': link._applying,
                            }"
                        >
                            <td v-if="applyUrl">
                                <input
                                    v-if="!link._fixed && !link.ignored"
                                    type="checkbox"
                                    class="rounded"
                                    :checked="selectedSet.has(link)"
                                    :disabled="applying"
                                    :aria-label="'Select broken link in ' + link.post_title"
                                    @change="toggleSelect(link)"
                                />
                                <span v-else-if="link._fixed" class="text-xs text-green-500" aria-label="Fixed">&#10003;</span>
                            </td>
                            <td class="break-words">
                                <Link v-if="link.edit_url" :href="link.edit_url" class="hover:text-blue-600 dark:hover:text-blue-400 cursor-pointer">
                                    {{ link.post_title }}
                                </Link>
                                <span v-else class="text-gray-900 dark:text-gray-100">{{ link.post_title }}</span>
                                <BardBadge :entry-id="link.post_id" class="ml-1.5" />
                            </td>
                            <td class="break-all">
                                <span v-if="link.type === 'internal'" class="text-xs text-gray-500 dark:text-gray-400 break-all">
                                    Missing entry: {{ link.url.replace('statamic://entry::', '') }}
                                </span>
                                <template v-else-if="editingLink === link">
                                    <!-- <form @submit.prevent> is the canonical browser-native way to
                                         handle Enter in an input. Works more reliably than @keydown.enter
                                         across browsers/focus transitions. -->
                                    <form class="flex flex-col gap-1" @submit.prevent="applyReplace(link)">
                                        <div class="flex items-center gap-1">
                                            <input
                                                ref="editInput"
                                                v-model="replaceUrl"
                                                type="text"
                                                aria-label="Replacement URL"
                                                :disabled="applying"
                                                :aria-invalid="replaceUrl.trim() !== '' && !replaceUrlValid"
                                                :class="[
                                                    'flex-1 text-sm h-8 px-2 rounded-md border dark:bg-gray-800 focus:outline-none focus:ring-2 disabled:opacity-60 disabled:cursor-not-allowed',
                                                    replaceUrl.trim() !== '' && !replaceUrlValid
                                                        ? 'border-red-400 focus:ring-red-400 dark:border-red-500'
                                                        : 'border-gray-300 dark:border-gray-700 focus:ring-blue-500',
                                                ]"
                                                @keydown.escape.prevent="applying || (editingLink = null)"
                                            />
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                icon="checkmark"
                                                :loading="applying"
                                                :disabled="!replaceUrlValid || applying"
                                                :aria-label="'Apply replacement URL'"
                                                v-tooltip="'Apply new URL'"
                                            />
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                icon="x"
                                                :disabled="applying"
                                                :aria-label="'Cancel edit'"
                                                v-tooltip="'Cancel'"
                                                @click="editingLink = null"
                                            />
                                        </div>
                                        <p v-if="replaceUrl.trim() !== '' && !replaceUrlValid" class="text-xs text-red-500">
                                            Enter a valid URL (http(s)://…, mailto: or tel:).
                                        </p>
                                    </form>
                                </template>
                                <template v-else>
                                    <span v-if="link._fixed === 'fixed'" class="text-xs break-all">
                                        <span v-if="link._originalUrl && link._originalUrl !== link.url" class="line-through text-gray-400 block">
                                            {{ truncateUrl(link._originalUrl) }}
                                        </span>
                                        <span class="inline-flex items-center">
                                            <a :href="link.url" target="_blank" rel="noopener" class="text-green-600 dark:text-green-400 hover:underline break-all">
                                                {{ link._originalUrl && link._originalUrl !== link.url ? '→ ' : '' }}{{ truncateUrl(link.url) }}
                                            </a>
                                            <Button
                                                v-if="applyUrl"
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil"
                                                class="ml-1 align-middle"
                                                aria-label="Edit URL"
                                                :disabled="applying || !!link._applying"
                                                v-tooltip="'Edit URL — recover from a typo in the replacement'"
                                                @click="startReplace(link)"
                                            />
                                        </span>
                                    </span>
                                    <span v-else-if="link._fixed === 'unlinked'" class="text-xs break-all line-through text-gray-400">
                                        {{ truncateUrl(link.url) }}
                                    </span>
                                    <span v-else>
                                        <a :href="link.url" target="_blank" rel="noopener" class="text-gray-700 dark:text-gray-300 hover:underline break-all text-xs">
                                            {{ truncateUrl(link.url) }}
                                        </a>
                                        <Button
                                            v-if="applyUrl && !link.ignored"
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil"
                                            class="ml-1 align-middle"
                                            aria-label="Edit URL"
                                            :disabled="applying || !!link._applying"
                                            v-tooltip="'Edit URL'"
                                            @click="startReplace(link)"
                                        />
                                    </span>
                                </template>
                            </td>
                            <td class="text-gray-400 dark:text-gray-500 text-xs break-words">
                                <span v-if="link.sentence_context" v-html="highlightAnchor(link.sentence_context, link.anchor_text)"></span>
                                <span v-else-if="link.anchor_text">{{ link.anchor_text }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="text-center">
                                <span class="text-xs text-gray-500">{{ link.type }}</span>
                            </td>
                            <td class="text-center whitespace-nowrap">
                                <span v-if="link._fixed" class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                    {{ link._fixed === 'unlinked' ? 'Unlinked' : 'Fixed' }}
                                </span>
                                <span v-else-if="link.ignored" class="text-xs font-medium px-2 py-0.5 rounded-full bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300 whitespace-nowrap">
                                    Ignored
                                </span>
                                <span v-else :class="statusBadgeClass(link.error_type)" class="text-xs font-medium px-2 py-0.5 rounded-full whitespace-nowrap">
                                    {{ link.status_label }}
                                </span>
                            </td>
                            <td class="text-right text-xs text-gray-400 break-words">
                                <span v-tooltip="link.last_checked_at && link.last_checked_at !== link.first_detected_at ? 'Last checked: ' + formatDate(link.last_checked_at) : null">
                                    {{ formatDate(link.first_detected_at) }}
                                </span>
                            </td>
                            <td v-if="applyUrl" class="text-right whitespace-nowrap">
                                <span v-if="link._fixed" class="text-xs text-green-500">&#10003;</span>
                                <div v-else class="inline-flex items-center gap-1 justify-end">
                                    <Button
                                        v-if="link.ignored"
                                        @click="unignoreLink(link)"
                                        variant="default"
                                        text="Unignore"
                                        size="sm"
                                        :loading="link._applying === 'unignore'"
                                        :disabled="!!link._applying"
                                        v-tooltip="'Remove from ignored list — re-scan to verify if still broken'"
                                    />
                                    <Button
                                        v-else
                                        @click="ignoreLink(link)"
                                        variant="default"
                                        text="Ignore"
                                        size="sm"
                                        :loading="link._applying === 'ignore'"
                                        :disabled="applying || !!link._applying"
                                        v-tooltip="'Mark as false positive — excluded from future scans until un-ignored'"
                                    />
                                    <Button
                                        v-if="!link.ignored"
                                        @click="promptUnlink(link)"
                                        variant="danger"
                                        text="Unlink"
                                        size="sm"
                                        :loading="link._applying === 'unlink'"
                                        :disabled="applying || !!link._applying"
                                    />
                                    <!-- Invisible placeholder to preserve column alignment when Unlink isn't applicable -->
                                    <span v-else class="invisible" aria-hidden="true">
                                        <Button variant="danger" text="Unlink" size="sm" />
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </Panel>

            <Pagination
                v-if="showPagination"
                class="mt-4"
                :resource-meta="paginationMeta"
                :per-page="perPage"
                :show-per-page-selector="false"
                @page-selected="currentPage = $event"
            />

            <div class="mt-3 text-xs text-gray-400">
                {{ activeBrokenCount }} broken
                <span v-if="ignoredCount > 0"> &middot; {{ ignoredCount }} ignored</span>
                <span v-if="selectedLinks.length > 0"> &middot; {{ selectedLinks.length }} selected</span>
                <span v-if="metadata"> &middot; Check took {{ metadata.duration_seconds }}s</span>
            </div>
        </div>

        <!-- Confirmation Modals -->
        <ConfirmationModal
            :open="singleUnlinkTarget !== null"
            title="Remove this link?"
            :body-text="singleUnlinkTarget ? `Remove link to \u201C${truncateUrl(singleUnlinkTarget.url)}\u201D? The text will remain but will no longer be linked.` : ''"
            button-text="Unlink"
            danger
            :busy="applying"
            @update:open="val => { if (!val) singleUnlinkTarget = null; }"
            @confirm="executeSingleUnlink"
        />

        <ConfirmationModal
            :open="showBulkUnlinkConfirm"
            title="Remove selected links?"
            :body-text="`Remove ${selectedLinks.length} link(s)? The text will remain but will no longer be linked.`"
            button-text="Unlink all"
            danger
            :busy="applying"
            @update:open="val => showBulkUnlinkConfirm = val"
            @confirm="bulkUnlink"
        />
    </div>
</template>

<script>
import { Link, router as inertiaRouter } from '@statamic/cms/inertia';
import { Panel, Button, Icon, Input, ConfirmationModal, Pagination } from '@statamic/cms/ui';
import MultiSelect from '../shared/MultiSelect.vue';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import BardBadge from '../shared/BardBadge.vue';
import { highlightAnchor } from '../../utils/highlight.js';
import { isValidReplacementUrl } from '../../utils/urlValidation.js';
import { sortableMixin } from '../shared/sortable.js';
import { applyUrlReplacements, UNLINK_SENTINEL } from '../shared/urlReplacer.js';
import { buildPaginationMeta, paginateItems } from '../shared/pagination.js';
import { readJson, writeJson } from '../../utils/safeStorage.js';
import { bulkState } from '../../services/bulkOperationService.js';

const TYPE_FILTER_OPTIONS = [
    { value: '', label: 'All Types' },
    { value: 'internal', label: 'Internal' },
    { value: 'external', label: 'External' },
];

const STATUS_BADGE_CLASSES = {
    not_found: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    missing_entry: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    timeout: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    ssl_error: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    connection_failed: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    redirect: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    forbidden: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    server_error: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
};

export default {
    components: { Link, Panel, Button, Icon, Input, ConfirmationModal, Pagination, MultiSelect, HelpIcon, SortableHeader, BardBadge },

    mixins: [sortableMixin],

    props: {
        data: { type: Object, required: true },
        checking: { type: Boolean, default: false },
        initialEntryFilter: { type: String, default: '' },
        applyUrl: { type: String, default: '' },
        ignoreUrl: { type: String, default: '' },
        unignoreUrl: { type: String, default: '' },
        bulkUnlinkUrl: { type: String, default: '' },
        bulkUnlinkStatusUrl: { type: String, default: '' },
        bulkUnlinkCancelUrl: { type: String, default: '' },
        exportUrl: { type: String, default: '' },
        entryHashes: { type: Object, default: () => ({}) },
        checkProgress: { type: Object, default: null },
    },

    emits: ['check-links'],

    async mounted() {
        // Attach to any in-flight bulk-unlink job (user refreshed / switched tab / came back)
        await this.pollBulkStatusOnce();
        if (this.applying) {
            this.startBulkPolling();
        }
    },

    beforeUnmount() {
        this.stopBulkPolling();
    },

    data() {
        const saved = readJson('linkwise-broken-state');
        return {
            searchQuery: this.initialEntryFilter || saved?.searchQuery || '',
            typeFilter: saved?.typeFilter || '',
            activeStatuses: this.validateSavedStatuses(saved?.activeStatuses),
            sortField: saved?.sortField || '',
            sortDirection: saved?.sortDirection || 'asc',
            // Deep-clone because Inertia prop objects are readonly reactive —
            // mutating `link.ignored = true` on the originals is silently swallowed.
            localLinks: JSON.parse(JSON.stringify(this.data?.broken_links || [])),
            localHashes: { ...this.entryHashes },
            selected: [],
            editingLink: null,
            replaceUrl: '',
            applying: false,
            singleUnlinkTarget: null,
            showBulkUnlinkConfirm: false,
            bulkResult: null,
            bulkProgress: null,
            bulkPollTimer: null,
            conflictBanner: null,
            currentPage: 1,
            perPage: 50,
        };
    },


    watch: {
        searchQuery() { this.currentPage = 1; this.persistState(); },
        typeFilter() { this.currentPage = 1; this.persistState(); },
        activeStatuses: { deep: true, handler() { this.currentPage = 1; this.persistState(); } },
        sortField() { this.persistState(); },
        sortDirection() { this.persistState(); },
        // Drop stale activeStatuses values when their bucket disappears
        // (e.g. user unignores the last ignored row → "Ignored" no longer exists)
        availableStatuses(current) {
            const stale = this.activeStatuses.filter(s => !current.includes(s));
            if (stale.length > 0) {
                this.activeStatuses = this.activeStatuses.filter(s => current.includes(s));
            }
        },
        // Resync local state when Inertia updates broken_links (e.g. after a
        // bulk-unlink reload). Without this, localLinks stays frozen at mount
        // and we'd offer stale rows that already got unlinked server-side.
        'data.broken_links': {
            handler(val) {
                this.localLinks = JSON.parse(JSON.stringify(val || []));
                this.selected = [];
            },
        },
        entryHashes: {
            handler(val) {
                this.localHashes = { ...val };
            },
        },
    },

    computed: {
        metadata() {
            return this.data?.metadata || null;
        },

        replaceUrlValid() {
            return isValidReplacementUrl(this.replaceUrl);
        },

        brokenLinks() {
            // Guard: a corrupted sessionStorage state, a watcher firing during
            // teardown, or an Inertia hot-swap could briefly leave localLinks
            // as a non-array (or undefined) and crash the entire computed
            // chain — which is exactly what makes the page render zero rows
            // even when the JSON has 152 entries.
            return Array.isArray(this.localLinks) ? this.localLinks : [];
        },

        filteredLinks() {
            let links = this.brokenLinks;

            if (this.searchQuery.trim()) {
                const q = this.searchQuery.toLowerCase();
                links = links.filter(l =>
                    l.post_title.toLowerCase().includes(q) ||
                    l.url.toLowerCase().includes(q) ||
                    (l.anchor_text && l.anchor_text.toLowerCase().includes(q))
                );
            }

            if (this.typeFilter) {
                links = links.filter(l => l.type === this.typeFilter);
            }

            // Status filter works on two dimensions: HTTP status labels AND the
            // synthetic "Ignored" option which filters by the `ignored` flag.
            // When activeStatuses is a strict subset, filter accordingly.
            if (this.activeStatuses && this.activeStatuses.length > 0 && this.activeStatuses.length < this.availableStatuses.length) {
                const wantIgnored = this.activeStatuses.includes('Ignored');
                const wantStatuses = this.activeStatuses.filter(s => s !== 'Ignored');
                links = links.filter(l => {
                    if (l.ignored) return wantIgnored;
                    return wantStatuses.includes(l.status_label);
                });
            }

            return links;
        },

        activeBrokenCount() {
            return this.localLinks.filter(l => !l.ignored).length;
        },

        ignoredCount() {
            return this.localLinks.filter(l => l.ignored).length;
        },


        sortedLinks() {
            if (!this.sortField) return this.filteredLinks;

            const field = this.sortField;
            const dir = this.sortDirection === 'asc' ? 1 : -1;

            return [...this.filteredLinks].sort((a, b) => {
                const aVal = a[field] || '';
                const bVal = b[field] || '';
                return aVal.localeCompare(bVal) * dir;
            });
        },

        paginatedLinks() {
            return paginateItems(this.sortedLinks, this.currentPage, this.perPage);
        },

        paginationMeta() {
            return buildPaginationMeta(this.sortedLinks.length, this.currentPage, this.perPage);
        },

        showPagination() {
            return this.sortedLinks.length > this.perPage;
        },

        availableStatuses() {
            // HTTP status labels of non-ignored rows, plus synthetic "Ignored" when
            // any ignored rows exist. status_label stays the actual HTTP reason.
            const statuses = new Set();
            let hasIgnored = false;
            for (const l of this.brokenLinks) {
                if (l.ignored) hasIgnored = true;
                else statuses.add(l.status_label);
            }
            const sorted = [...statuses].sort();
            return hasIgnored ? [...sorted, 'Ignored'] : sorted;
        },

        /**
         * Rows on the current page that can be bulk-selected (exclude fixed + ignored).
         * Scoped to current page so "Select all" affects only visible rows — matches
         * Gmail-style pagination UX and prevents accidental "select 500" operations.
         */
        unfixedLinks() {
            return this.paginatedLinks.filter(l => !l._fixed && !l.ignored);
        },

        selectedLinks() {
            // User may have selected rows across multiple pages before clicking Unlink
            return this.selected.filter(l => !l._fixed);
        },

        selectedSet() {
            // Set for O(1) membership checks in allSelected and row checkbox
            return new Set(this.selected);
        },

        allSelected() {
            if (this.unfixedLinks.length === 0) return false;
            return this.unfixedLinks.every(l => this.selectedSet.has(l));
        },

        someSelected() {
            // At least one row on current page is selected — drives indeterminate checkbox
            return this.unfixedLinks.some(l => this.selectedSet.has(l));
        },

        typeFilterOptions() {
            return TYPE_FILTER_OPTIONS;
        },

        bulkResultClasses() {
            if (!this.bulkResult) return '';
            if (this.bulkResult.skipped === 0) {
                return 'bg-green-50 border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800/50 dark:text-green-300';
            }
            if (this.bulkResult.succeeded === 0) {
                return 'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800/50 dark:text-red-300';
            }
            return 'bg-yellow-50 border-yellow-200 text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-800/50 dark:text-yellow-300';
        },
    },

    methods: {
        ariaSortFor(field) {
            if (this.sortField !== field) return 'none';
            return this.sortDirection === 'asc' ? 'ascending' : 'descending';
        },

        /** Wrap sortableMixin's toggleSort so it no-ops during bulk operations. */
        handleSort(field) {
            if (this.applying) return;
            this.toggleSort(field);
        },

        /**
         * Optimistic-lock conflict handler. Show persistent banner (toast would
         * auto-dismiss) and reload entryHashes so the user's next attempt uses
         * a fresh hash.
         */
        handleConflict(message) {
            this.conflictBanner = message || 'Entry was modified by someone else.';
            // Reload the broken-links scan data too, not just hashes. Without
            // this, the row keeps showing the OLD anchor + context after the
            // refresh — so when the user clicks "try again" they're acting on
            // stale information. With brokenData refreshed, the row updates
            // to the current scan state (different anchor, different sentence
            // context, or gone entirely if the link was removed elsewhere)
            // and the user can VERIFY before clicking again.
            inertiaRouter.reload({ only: ['brokenData', 'entryHashes'], preserveScroll: true });
        },

        toggleSelect(link) {
            const idx = this.selected.indexOf(link);
            if (idx > -1) this.selected.splice(idx, 1);
            else this.selected.push(link);
        },

        toggleSelectAll() {
            if (this.allSelected) {
                this.selected = [];
            } else {
                this.selected = [...this.unfixedLinks];
            }
        },

        openBulkUnlinkConfirm() {
            if (this.selectedLinks.length === 0) return;
            this.showBulkUnlinkConfirm = true;
        },

        async ignoreLink(link) {
            if (!this.ignoreUrl || link._applying) return;
            link._applying = 'ignore';
            try {
                const response = await fetch(this.ignoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ post_id: link.post_id, url: link.url }),
                });
                if (!response.ok) throw new Error('ignore failed');

                link.ignored = true;
                this.selected = this.selected.filter(l => l !== link);
                Statamic.$toast.success('Link ignored.');
            } catch {
                Statamic.$toast.error('Failed to ignore link.');
            } finally {
                link._applying = null;
            }
        },

        async unignoreLink(link) {
            if (!this.unignoreUrl || link._applying) return;
            link._applying = 'unignore';
            try {
                const response = await fetch(this.unignoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ post_id: link.post_id, url: link.url }),
                });
                if (!response.ok) throw new Error('unignore failed');

                link.ignored = false;
                Statamic.$toast.success('Link un-ignored. Re-scan to verify if still broken.');
            } catch {
                Statamic.$toast.error('Failed to un-ignore link.');
            } finally {
                link._applying = null;
            }
        },

        async bulkUnlink() {
            if (bulkState.active) {
                Statamic.$toast.info('Another bulk operation is running. Wait for it to finish.');
                return;
            }
            if (!this.bulkUnlinkUrl) {
                Statamic.$toast.error('Bulk unlink is not configured.');
                return;
            }
            const links = [...this.selectedLinks];
            if (links.length === 0) return;

            const replacements = links.map(link => ({
                entry_id: link.post_id,
                field: link.field || '',
                field_type: link.field_type || '',
                matched_url: link.url,
                occurrence_index: 0,
                // Anchor-fingerprint guard: backend refuses to mutate if the
                // link at occurrence_index N now wraps a different text than
                // the scan recorded. Without this, a moved/re-anchored link
                // gets silently unlinked because the URL alone matches.
                anchor_text: link.anchor_text || '',
                new_url: UNLINK_SENTINEL,
                search: link.url,
            }));

            this.showBulkUnlinkConfirm = false;
            this.bulkResult = null;

            try {
                const response = await fetch(this.bulkUnlinkUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ replacements }),
                });
                if (response.status === 409) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.info(data.message || 'Another bulk operation is running. Wait for it to finish.');
                    return;
                }
                if (!response.ok) throw new Error('start failed');
            } catch {
                Statamic.$toast.error('Failed to start bulk unlink.');
                return;
            }

            this.applying = true;
            this.bulkProgress = { current: 0, total: replacements.length };
            this.selected = [];
            this.startBulkPolling();
        },

        startBulkPolling() {
            this.stopBulkPolling();
            this.bulkPollTimer = setInterval(() => this.pollBulkStatusOnce(), 1000);
        },

        stopBulkPolling() {
            if (this.bulkPollTimer) {
                clearInterval(this.bulkPollTimer);
                this.bulkPollTimer = null;
            }
        },

        async pollBulkStatusOnce() {
            if (!this.bulkUnlinkStatusUrl) return;
            try {
                const response = await fetch(this.bulkUnlinkStatusUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const status = await response.json();

                if (status.phase === 'starting' || status.phase === 'running') {
                    this.applying = true;
                    this.bulkProgress = {
                        current: status.current || 0,
                        total: status.total || 0,
                    };
                } else if (status.phase === 'done' || status.phase === 'cancelled') {
                    // Only surface as "just completed" if WE were polling an active bulk.
                    // Stale done-state from a previous session should not show a banner.
                    const wasActive = this.applying;
                    this.stopBulkPolling();
                    this.applying = false;
                    this.bulkProgress = null;

                    if (wasActive) {
                        this.bulkResult = {
                            total: status.total || 0,
                            succeeded: status.succeeded || 0,
                            skipped: status.skipped || 0,
                            errors: status.errors || {},
                            cancelled: status.phase === 'cancelled',
                        };
                        // Refresh broken_links + hashes from server (only those props)
                        inertiaRouter.reload({ only: ['brokenData', 'entryHashes'], preserveScroll: true });
                    }
                } else {
                    // idle / unknown — nothing to attach to
                    this.stopBulkPolling();
                    this.applying = false;
                    this.bulkProgress = null;
                }
            } catch {
                // ignore transient polling errors
            }
        },


        dismissBulkResult() {
            this.bulkResult = null;
        },

        showIgnoredInFilter() {
            if (!this.activeStatuses.includes('Ignored')) {
                this.activeStatuses = [...this.activeStatuses, 'Ignored'];
            }
        },

        validateSavedStatuses(saved) {
            // Compute same dimensional set as the availableStatuses computed:
            // HTTP statuses from non-ignored rows, plus synthetic 'Ignored' if any exist.
            // Defensive: data?.broken_links could be a non-iterable in edge cases
            // (object instead of array, null, undefined). Bail to a safe default.
            const source = Array.isArray(this.data?.broken_links) ? this.data.broken_links : [];
            const statuses = new Set();
            let hasIgnored = false;
            for (const l of source) {
                if (l.ignored) hasIgnored = true;
                else statuses.add(l.status_label);
            }
            const httpStatuses = [...statuses].sort();
            const all = hasIgnored ? [...httpStatuses, 'Ignored'] : httpStatuses;
            // Default: HTTP statuses only, no 'Ignored' → hides ignored by default
            const defaultActive = httpStatuses;
            if (!saved || !Array.isArray(saved)) return defaultActive;
            const valid = saved.filter(s => all.includes(s));
            return valid.length > 0 ? valid : defaultActive;
        },

        highlightAnchor,

        truncateUrl(url) {
            return url.length > 60 ? url.substring(0, 57) + '...' : url;
        },

        formatDate(dateStr) {
            if (!dateStr) return '—';
            try {
                return new Date(dateStr).toLocaleDateString(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric',
                    hour: '2-digit', minute: '2-digit',
                });
            } catch {
                return dateStr;
            }
        },

        exportCsv() {
            window.location.href = this.exportUrl;
        },

        persistState() {
            writeJson('linkwise-broken-state', {
                searchQuery: this.searchQuery,
                typeFilter: this.typeFilter,
                activeStatuses: this.activeStatuses,
                sortField: this.sortField,
                sortDirection: this.sortDirection,
            });
        },

        promptUnlink(link) {
            this.singleUnlinkTarget = link;
        },

        async executeSingleUnlink() {
            const link = this.singleUnlinkTarget;
            if (!link) return;
            this.applying = true;
            try {
                const result = await this._unlinkOne(link);
                if (result.success) {
                    Statamic.$toast.success('Link removed.');
                } else if (result.conflict) {
                    this.handleConflict(result.error);
                } else if (result.missing) {
                    this.removeLinkLocally(link);
                    Statamic.$toast.info(result.error);
                    inertiaRouter.reload({ only: ['brokenData', 'entryHashes'], preserveScroll: true });
                } else {
                    Statamic.$toast.error(result.error);
                }
            } finally {
                this.applying = false;
                this.singleUnlinkTarget = null;
            }
        },

        /**
         * Unlink one broken link — core operation shared between per-row and bulk unlink.
         * Returns `{ success: true }` or `{ success: false, error: string }`.
         * Callers handle user feedback (toast vs aggregated panel).
         */
        async _unlinkOne(link) {
            try {
                const result = await applyUrlReplacements(
                    this.applyUrl,
                    link.url,
                    [{
                        entry_id: link.post_id,
                        field: link.field || '',
                        field_type: link.field_type || '',
                        matched_url: link.url,
                        occurrence_index: 0,
                        anchor_text: link.anchor_text || '',
                        new_url: UNLINK_SENTINEL,
                    }],
                    this.localHashes,
                    { mode: 'exact' }, // exact URL match — we target one specific broken link
                );
                this.refreshHashes(result);

                if ((result.total_replacements ?? 0) === 0) {
                    return {
                        success: false,
                        error: 'Link was not found at the scanned position — may have moved or been removed since the scan. List refreshed.',
                        missing: true,
                    };
                }

                this.markAsUnlinked(link);
                return { success: true };
            } catch (error) {
                return {
                    success: false,
                    error: error.message || 'Unlink failed.',
                    conflict: !!error.conflict,
                };
            }
        },

        /**
         * Drop a link from localLinks AND from the selection set.
         * Avoids orphaned selection entries (link gone from paginatedLinks
         * but still counted by `selectedLinks.length`).
         */
        removeLinkLocally(link) {
            const idx = this.localLinks.indexOf(link);
            if (idx !== -1) this.localLinks.splice(idx, 1);
            const selIdx = this.selected.indexOf(link);
            if (selIdx !== -1) this.selected.splice(selIdx, 1);
        },

        markAsUnlinked(link) {
            this.removeLinkLocally(link);
        },

        markAsReplaced(link, newUrl) {
            link._fixed = 'fixed';
            link._newUrl = newUrl;
            // Capture the original-broken URL for the "before → after"
            // display (line-through → green). Only set on the FIRST fix
            // so subsequent edits keep showing the true origin, not an
            // intermediate state.
            if (!link._originalUrl) {
                link._originalUrl = link.url;
            }
            // Sync the canonical URL field so a subsequent edit (typo
            // recovery: "https://www.spiegel.de#" → "https://www.spiegel.de")
            // targets the URL that's actually in the entry now, not the
            // original-broken URL the row was created from.
            link.url = newUrl;
        },

        refreshHashes(result) {
            if (result.updated_hashes) {
                Object.assign(this.localHashes, result.updated_hashes);
            }
        },


        startReplace(link) {
            this.editingLink = link;
            this.replaceUrl = link.url;
            this.$nextTick(() => {
                const input = this.$refs.editInput;
                if (Array.isArray(input)) input[0]?.focus();
                else input?.focus();
            });
        },

        async applyReplace(link) {
            if (!this.replaceUrlValid || this.applying) return;
            if (this.replaceUrl.trim() === link.url) {
                Statamic.$toast.info('URL unchanged.');
                return;
            }
            if (!this.applyUrl) {
                Statamic.$toast.error('Apply URL not configured.');
                return;
            }
            this.applying = true;
            try {
                const result = await applyUrlReplacements(
                    this.applyUrl,
                    link.url,
                    [{
                        entry_id: link.post_id,
                        field: link.field || '',
                        field_type: link.field_type || '',
                        matched_url: link.url,
                        occurrence_index: 0,
                        anchor_text: link.anchor_text || '',
                        new_url: this.replaceUrl.trim(),
                    }],
                    this.localHashes,
                    { mode: 'exact' }, // exact URL match — we target one specific broken link
                );
                this.refreshHashes(result);

                if ((result.total_replacements ?? 0) === 0) {
                    // Link was not present in the current entry content — likely
                    // removed by another editor between page load and this click.
                    // Remove the row locally for immediate feedback; Inertia reload
                    // re-syncs with server truth (backend already called removeLink).
                    this.removeLinkLocally(link);
                    Statamic.$toast.info('Link was not found at the scanned position — may have moved or been removed since the scan. List refreshed.');
                    inertiaRouter.reload({ only: ['brokenData', 'entryHashes'], preserveScroll: true });
                    this.editingLink = null;
                    return;
                }

                const stillBroken = (result.still_broken || []).find(
                    sb => sb.entry_id === link.post_id && sb.new_url === this.replaceUrl.trim()
                );
                if (stillBroken) {
                    link.url = this.replaceUrl.trim();
                    link.status_label = stillBroken.status_label;
                    link.error_type = stillBroken.error_type;
                    link._fixed = false;
                    Statamic.$toast.error(`URL replaced, but new URL is also broken (${stillBroken.status_label}).`);
                } else {
                    this.markAsReplaced(link, this.replaceUrl.trim());
                    Statamic.$toast.success('Link replaced and verified OK.');
                }
                this.editingLink = null;
            } catch (error) {
                if (error.conflict) {
                    this.handleConflict(error.message);
                } else {
                    Statamic.$toast.error(error.message || 'Replace failed.');
                }
            } finally {
                this.applying = false;
            }
        },

        statusBadgeClass(errorType) {
            return STATUS_BADGE_CLASSES[errorType] || '';
        },
    },
};
</script>
