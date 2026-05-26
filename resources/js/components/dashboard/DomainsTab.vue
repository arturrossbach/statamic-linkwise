<template>
    <div>
        <!-- Intro -->
        <Card class="mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">External Domains</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                        Every external domain your content links to, with the number of entries and total links pointing there. Use this to audit outbound links and control SEO signals per domain.
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">
                        <strong class="text-gray-700 dark:text-gray-300">Set a rel attribute</strong> per domain (Nofollow / Sponsored / UGC) and Linkwise applies it automatically to every link pointing to that domain when the page is rendered. Useful for affiliate, sponsored, or untrusted-source links across many entries at once.
                    </p>
                    <!-- V1.2 Cross-Tab-F — sprach-agnostisch note. Only renders
                         on multisite installs (passed via prop) so single-site
                         CPs don't get a noise-line. -->
                    <p v-if="isMultisite" class="text-xs text-gray-400 dark:text-gray-500 mt-1.5 italic">
                        Domains are sprach-agnostisch — the list aggregates across all sites. Locale filtering is intentionally absent here since a broken external URL is broken in every locale.
                    </p>
                </div>
                <HelpIcon tooltip="Domain attributes are stored in storage/linkwise/domain-attributes.json and applied via Linkwise's Bard link mark — no Bard content is rewritten." />
            </div>
        </Card>

        <!-- Stale-index warning — same pattern + threshold as Links Report. -->
        <Alert v-if="showStaleBanner" variant="warning" class="mb-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-sm">Content index is {{ indexAgeDays }} days old</p>
                    <p class="mt-0.5 text-xs opacity-80">Domain link counts may be out of date if entries were edited since the last scan.</p>
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
                <input
                    v-model="searchQuery"
                    type="text"
                    aria-label="Search domains"
                    placeholder="Search domains..."
                    class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5 w-52"
                />
                <select
                    v-model="attributeFilter"
                    class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5"
                >
                    <option value="">All Attributes</option>
                    <option value="custom">Custom (any non-default)</option>
                    <option value="default" title="No rel attribute set — default behavior">Default</option>
                    <option value="dofollow" title="rel=dofollow — pass PageRank">Dofollow</option>
                    <option value="nofollow" title="rel=nofollow — block PageRank flow">Nofollow</option>
                    <option value="sponsored" title="rel=sponsored nofollow — paid/affiliate links">Sponsored</option>
                    <option value="ugc" title="rel=ugc nofollow — user-generated content">UGC</option>
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 gap-y-2">
                <span class="text-xs text-gray-400">
                    <strong class="text-gray-600 dark:text-gray-300">{{ totalLinks }}</strong> link{{ totalLinks === 1 ? '' : 's' }} across
                    <strong class="text-gray-600 dark:text-gray-300">{{ sortedDomains.length }}</strong>
                    of {{ localDomains.length }} domain{{ localDomains.length === 1 ? '' : 's' }}
                </span>
                <Button v-if="exportUrl && localDomains.length > 0" @click="exportCsv" text="Export CSV" icon="download" v-tooltip="'Download all domains as CSV'" />
            </div>
        </div>

        <!-- Empty State -->
        <div v-if="localDomains.length === 0" class="py-12 text-center">
            <p class="text-gray-500 dark:text-gray-400 mb-3">No external domains found.</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-4">External domains appear here once you link to them from any Bard or Markdown field. Run a content scan to discover them.</p>
            <Button v-if="rebuildUrl" text="Scan Content" icon="sync" variant="primary" :loading="rescanning" @click="triggerRescan" />
        </div>

        <!-- Table -->
        <Panel v-else>
            <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                <thead>
                    <tr>
                        <SortableHeader label="Domain" :active="sortField === 'domain'" :direction="sortDirection" @sort="toggleSort('domain')">
                            <HelpIcon tooltip="External domain that one or more entries link to. Click to open in a new tab." />
                        </SortableHeader>
                        <SortableHeader label="Attribute" align="center" :sortable="false">
                            <HelpIcon tooltip="Link relation attribute applied to all links pointing to this domain. Affects SEO signals — see options for explanations." />
                        </SortableHeader>
                        <SortableHeader label="Entries" align="center" :active="sortField === 'post_count'" :direction="sortDirection" @sort="toggleSort('post_count')">
                            <HelpIcon tooltip="Number of entries that contain links to this domain." />
                        </SortableHeader>
                        <SortableHeader label="Links" align="center" :active="sortField === 'link_count'" :direction="sortDirection" @sort="toggleSort('link_count')">
                            <HelpIcon tooltip="Total number of links pointing to this domain across all entries." />
                        </SortableHeader>
                        <SortableHeader label="Actions" :sortable="false" align="right" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="domain in sortedDomains" :key="domain.domain">
                        <td>
                            <a :href="'https://' + domain.domain" target="_blank" rel="noopener noreferrer" class="text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400">
                                {{ domain.domain }}
                            </a>
                        </td>
                        <td>
                            <div class="flex justify-center">
                                <select
                                    :value="domain.attribute"
                                    @change="updateAttribute(domain.domain, $event.target.value)"
                                    class="text-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded px-2 py-1"
                                >
                                <option value="default" title="No rel attribute set — default behavior">Default</option>
                                <option value="dofollow" title="rel=dofollow — pass PageRank">Dofollow</option>
                                <option value="nofollow" title="rel=nofollow — block PageRank flow">Nofollow</option>
                                <option value="sponsored" title="rel=sponsored nofollow — paid/affiliate links">Sponsored</option>
                                <option value="ugc" title="rel=ugc nofollow — user-generated content">UGC</option>
                            </select>
                            </div>
                        </td>
                        <td class="text-center">
                            <button v-if="domain.post_count > 0" @click="showDetail('posts', domain)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                                {{ domain.post_count }}
                            </button>
                            <span v-else>0</span>
                        </td>
                        <td class="text-center">
                            <button v-if="domain.link_count > 0" @click="showDetail('links', domain)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                                {{ domain.link_count }}
                            </button>
                            <span v-else>0</span>
                        </td>
                        <td class="text-right">
                            <Button
                                size="sm"
                                variant="default"
                                icon="pencil"
                                text="Edit Links"
                                v-tooltip="'Open URL Changer pre-filtered to this domain — search/replace URLs site-wide'"
                                @click="$emit('edit-domain', domain.domain)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table></div>
        </Panel>

        <!-- Detail Modal -->
        <!-- size="full" — wider modal so the new (longer) sentence_context
             column has room to breathe (User-Smoke 2026-05-21). -->
        <Stack :open="detailModal !== null" @update:open="closeDetailModal" :title="detailModal?.title || ''" size="full">
            <div v-if="detailModal">
                <!-- Filter bar inside modal: search + count -->
                <div class="flex items-center justify-between mb-3 gap-3">
                    <input
                        v-model="modalSearchQuery"
                        type="text"
                        :aria-label="detailModal.type === 'posts' ? 'Search entries' : 'Search anchor text or URL'"
                        :placeholder="detailModal.type === 'posts' ? 'Search entries...' : 'Search anchor or URL...'"
                        class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5 w-64"
                    />
                    <span class="text-xs text-gray-400">
                        <strong class="text-gray-600 dark:text-gray-300">{{ sortedModalItems.length }}</strong>
                        of {{ detailModal.items.length }}
                        {{ detailModal.type === 'posts' ? 'entries' : 'links' }}
                    </span>
                </div>

                <Panel>
                    <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                        <thead>
                            <tr>
                                <!-- Anchor column removed (User 2026-05-21):
                                     redundant with Context which already
                                     highlights the anchor via highlightAnchor(). -->
                                <SortableHeader v-if="detailModal.type === 'links'" label="Context" :sortable="false" />
                                <SortableHeader
                                    :label="detailModal.type === 'posts' ? 'Entry' : 'URL'"
                                    :active="modalSortField === (detailModal.type === 'posts' ? 'title' : 'url')"
                                    :direction="modalSortDirection"
                                    @sort="toggleModalSort(detailModal.type === 'posts' ? 'title' : 'url')"
                                />
                                <SortableHeader v-if="detailModal.type === 'links'" label="Entry" :active="modalSortField === 'post_title'" :direction="modalSortDirection" @sort="toggleModalSort('post_title')" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, idx) in sortedModalItems" :key="`${item.post_id || item.title}-${item.url || idx}`">
                                <!-- Anchor cell removed (header dropped above)
                                     — anchor is highlighted inside Context. -->
                                <td v-if="detailModal.type === 'links'" class="text-gray-400 dark:text-gray-500 text-xs">
                                    <span v-if="item.sentence_context" v-html="highlightAnchor(item.sentence_context, item.anchor_text)"></span>
                                    <span v-else>—</span>
                                </td>
                                <td>
                                    <template v-if="detailModal.type === 'posts'">
                                        <Link v-if="item.edit_url" :href="item.edit_url" class="hover:text-blue-600 dark:hover:text-blue-400">{{ item.title }}</Link>
                                        <span v-else>{{ item.title }}</span>
                                    </template>
                                    <a v-else :href="item.url" target="_blank" rel="noopener noreferrer" class="text-gray-700 dark:text-gray-300 hover:underline text-xs break-all">
                                        {{ item.url }}
                                    </a>
                                </td>
                                <td v-if="detailModal.type === 'links'">
                                    <Link v-if="item.edit_url" :href="item.edit_url" class="hover:text-blue-600 dark:hover:text-blue-400 text-sm">{{ item.post_title }}</Link>
                                    <span v-else class="text-gray-500">{{ item.post_title }}</span>
                                </td>
                            </tr>
                            <tr v-if="sortedModalItems.length === 0">
                                <td :colspan="detailModal.type === 'posts' ? 1 : 4" class="text-center text-xs text-gray-400 py-6">
                                    No matches for "{{ modalSearchQuery }}"
                                </td>
                            </tr>
                        </tbody>
                    </table></div>
                </Panel>
            </div>
        </Stack>
    </div>
</template>

<script>
import { Link } from '@statamic/cms/inertia';
import { router as inertiaRouter } from '@statamic/cms/inertia';
import { Card, Panel, Stack, Button, Alert } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import { highlightAnchor } from '../../utils/highlight.js';
import { sortableMixin } from '../shared/sortable.js';
import { readJson, writeJson } from '../../utils/safeStorage.js';

export default {
    components: { Link, Card, Panel, Stack, Button, Alert, HelpIcon, SortableHeader },

    mixins: [sortableMixin],

    emits: ['edit-domain'],

    props: {
        domains: { type: Array, required: true },
        // V1.2 Cross-Tab-F — true on multisite installs so the sprach-
        // agnostisch caption renders. Single-site CPs see no extra note.
        isMultisite: { type: Boolean, default: false },
        saveUrl: { type: String, required: true },
        exportUrl: { type: String, default: '' },
        rebuildUrl: { type: String, default: '' },
        indexLastBuiltAt: { type: String, default: null },
    },

    data() {
        return {
            // Deep-clone — Inertia props are readonly reactive proxies, mutating
            // them (e.g. `domain.attribute = newValue`) silently fails. Same
            // pattern as AutoLinkingTab.localRules / LinksReportTab.localEntries.
            localDomains: JSON.parse(JSON.stringify(this.domains || [])),
            searchQuery: '',
            attributeFilter: '',
            sortField: 'link_count',
            sortDirection: 'desc',
            detailModal: null,
            modalSearchQuery: '',
            modalSortField: '',
            modalSortDirection: 'asc',
            rescanning: false,
            // sessionStorage-backed filter persistence — survives tab navigation
            // so the user's narrowed view doesn't reset every time they come back.
        };
    },

    mounted() {
        const parsed = readJson('linkwise:domains:filters');
        if (parsed) {
            this.searchQuery = parsed.searchQuery || '';
            this.attributeFilter = parsed.attributeFilter || '';
            this.sortField = parsed.sortField || 'link_count';
            this.sortDirection = parsed.sortDirection || 'desc';
        }
    },

    watch: {
        searchQuery() { this.persistFilters(); },
        attributeFilter() { this.persistFilters(); },
        sortField() { this.persistFilters(); },
        sortDirection() { this.persistFilters(); },
        // Re-sync the local `localDomains` deep-clone (line 251) when the
        // parent Inertia prop updates — e.g. after a save-domain-attribute
        // POST triggers an Inertia partial-reload, or any future bulk-op
        // refreshes domains. Klasse-10 sister-fix (User-Smoke 2026-05-19).
        // Mirrors LinksReportTab.vue:608 `watch.entries → localEntries`.
        domains: {
            deep: true,
            handler(val) {
                this.localDomains = JSON.parse(JSON.stringify(val || []));
            },
        },
    },

    computed: {
        // Days since the index was last built. Same threshold as Links Report.
        indexAgeDays() {
            if (!this.indexLastBuiltAt) return null;
            const age = Date.now() - new Date(this.indexLastBuiltAt).getTime();
            return Math.floor(age / (1000 * 60 * 60 * 24));
        },
        showStaleBanner() {
            return this.indexAgeDays !== null && this.indexAgeDays > 7;
        },

        // Glance-stats for the header: total external links + total domains.
        totalLinks() {
            return this.localDomains.reduce((sum, d) => sum + (d.link_count || 0), 0);
        },

        filteredDomains() {
            let items = this.localDomains;

            if (this.searchQuery.trim()) {
                const q = this.searchQuery.toLowerCase();
                items = items.filter(d => d.domain.toLowerCase().includes(q));
            }

            if (this.attributeFilter === 'custom') {
                // "Custom" — any rule explicitly set away from the default.
                items = items.filter(d => d.attribute && d.attribute !== 'default');
            } else if (this.attributeFilter) {
                items = items.filter(d => d.attribute === this.attributeFilter);
            }

            return items;
        },

        // Filter + sort the items inside the open Detail Modal. Supports both
        // 'posts' mode (entries linking to a domain) and 'links' mode
        // (individual link occurrences with anchor + context).
        sortedModalItems() {
            if (!this.detailModal) return [];
            let items = this.detailModal.items;

            const q = this.modalSearchQuery.trim().toLowerCase();
            if (q) {
                items = items.filter(item => {
                    if (this.detailModal.type === 'posts') {
                        return (item.title || '').toLowerCase().includes(q);
                    }
                    return (item.anchor_text || '').toLowerCase().includes(q)
                        || (item.url || '').toLowerCase().includes(q)
                        || (item.post_title || '').toLowerCase().includes(q);
                });
            }

            if (this.modalSortField) {
                const dir = this.modalSortDirection === 'asc' ? 1 : -1;
                const field = this.modalSortField;
                items = [...items].sort((a, b) => {
                    const aVal = (a[field] || '').toString();
                    const bVal = (b[field] || '').toString();
                    return aVal.localeCompare(bVal) * dir;
                });
            }

            return items;
        },

        sortedDomains() {
            const field = this.sortField;
            const dir = this.sortDirection === 'asc' ? 1 : -1;

            return [...this.filteredDomains].sort((a, b) => {
                const aVal = a[field];
                const bVal = b[field];

                if (typeof aVal === 'string') {
                    return aVal.localeCompare(bVal) * dir;
                }

                return (aVal - bVal) * dir;
            });
        },
    },

    methods: {
        highlightAnchor,

        exportCsv() {
            window.location.href = this.exportUrl;
        },

        // Persist sort + filter state so it survives Inertia tab-nav.
        // safeStorage swallows quota / private-mode failures — filters
        // just won't survive reload (a UX nicety, not load-bearing).
        persistFilters() {
            writeJson('linkwise:domains:filters', {
                searchQuery: this.searchQuery,
                attributeFilter: this.attributeFilter,
                sortField: this.sortField,
                sortDirection: this.sortDirection,
            });
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
                if (response.status === 409) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.info(data.message || 'Another bulk operation is running.');
                    return;
                }
                if (!response.ok) {
                    Statamic.$toast.error('Could not start scan.');
                    return;
                }
                Statamic.$toast.success('Scan started — refresh in a minute.');
            } catch (e) {
                Statamic.$toast.error(`Could not start scan: ${e.message || 'network error'}`);
            } finally {
                this.rescanning = false;
            }
        },

        defaultSortDirection(field) {
            return field === 'domain' ? 'asc' : 'desc';
        },

        async updateAttribute(domain, attribute) {
            try {
                const response = await fetch(this.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ domain, attribute }),
                });

                if (response.ok) {
                    // Update local state — mutates localDomains, NOT the
                    // readonly Inertia prop.
                    const d = this.localDomains.find(d => d.domain === domain);
                    if (d) d.attribute = attribute;
                    Statamic.$toast.success(`Attribute for ${domain} updated.`);
                    return;
                }
                // Surface the real backend reason. Validation errors include
                // a `message` field (Laravel's default), error responses an `error`.
                const data = await response.json().catch(() => ({}));
                const reason = data?.error || data?.message || `HTTP ${response.status}`;
                Statamic.$toast.error(`Could not update ${domain}: ${reason}`);
            } catch (error) {
                Statamic.$toast.error(`Could not update ${domain}: ${error.message || 'network error'}`);
            }
        },

        showDetail(type, domain) {
            // Reset modal-internal state so a previous search/sort doesn't
            // leak into the newly opened modal.
            this.modalSearchQuery = '';
            this.modalSortField = '';
            this.modalSortDirection = 'asc';

            if (type === 'posts') {
                this.detailModal = {
                    title: `Entries linking to ${domain.domain}`,
                    type: 'posts',
                    items: domain.posts,
                };
            } else {
                this.detailModal = {
                    title: `Links to ${domain.domain}`,
                    type: 'links',
                    domain: domain.domain,
                    items: domain.links,
                };
            }
        },

        closeDetailModal() {
            this.detailModal = null;
        },

        toggleModalSort(field) {
            if (this.modalSortField === field) {
                this.modalSortDirection = this.modalSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.modalSortField = field;
                this.modalSortDirection = 'asc';
            }
        },

    },
};
</script>
