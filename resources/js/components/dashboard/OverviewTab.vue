<template>
    <div>
        <!-- Data-freshness Indicator + Recommendations -->
        <div v-if="indexLastBuiltAt || recommendations.length > 0 || resolvedLanguage" class="mb-4 space-y-2">
            <div class="text-xs text-gray-500 dark:text-gray-400">
                <span v-if="indexLastBuiltAt">Last indexed {{ relativeTime(indexLastBuiltAt) }}</span>
                <span v-if="brokenLastChecked">
                    <span v-if="indexLastBuiltAt"> · </span>Last link check {{ relativeTime(brokenLastChecked) }}
                </span>
                <span v-if="resolvedLanguage">
                    <span v-if="indexLastBuiltAt || brokenLastChecked"> · </span>Content language: <strong class="text-gray-700 dark:text-gray-300">{{ resolvedLanguage.name }}</strong>
                    <span v-if="resolvedLanguage.source === 'auto-detected'" class="text-amber-700 dark:text-amber-400" v-tooltip="resolvedLanguage.source_detail">
                        (auto-detected)
                    </span>
                    <span v-else-if="resolvedLanguage.source === 'fallback'" class="text-amber-700 dark:text-amber-400" v-tooltip="resolvedLanguage.source_detail">
                        (fallback)
                    </span>
                </span>
            </div>

            <!-- Recommendations grouped under a Joomla-style <details> summary
                 so power-users who already know about the long-standing issues
                 (e.g. 192 orphaned entries) can collapse the section once and
                 keep the Overview metrics above the fold. Default open so new
                 recommendations are visible; collapsed state persists per-
                 session via sessionStorage. -->
            <details
                v-if="recommendations.length > 0"
                :open="!recommendationsCollapsed"
                @toggle="handleRecommendationsToggle"
                class="rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40"
            >
                <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800/60 rounded-md">
                    {{ recommendations.length }} {{ recommendations.length === 1 ? 'recommendation' : 'recommendations' }}
                </summary>
                <div class="px-3 pb-3 pt-1 flex flex-col gap-2">
                    <Alert
                        v-for="rec in recommendations"
                        :key="rec.key"
                        :variant="severityVariant(rec.severity)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm">{{ rec.title }}</p>
                                <p class="mt-0.5 text-xs opacity-80">{{ rec.body }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button
                                    v-if="rec.action"
                                    :text="rec.action.label"
                                    size="sm"
                                    variant="default"
                                    @click="handleRecommendationAction(rec.action)"
                                />
                                <!-- Per-recommendation dismiss. Persists in
                                     sessionStorage so a closed banner stays
                                     closed for the tab session but reappears
                                     after a hard reload / new session if the
                                     underlying condition still holds. User-
                                     ask 2026-05-22 (Cloudways smoke): "der
                                     nervt einfach, weg damit". -->
                                <button
                                    @click="dismissRecommendation(rec.key)"
                                    class="text-xs opacity-50 hover:opacity-100 px-1.5 py-0.5"
                                    :title="`Dismiss '${rec.title}' for this session`"
                                    type="button"
                                    aria-label="Dismiss recommendation"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </Alert>
                </div>
            </details>
        </div>

        <!-- Row 1: Core Metrics -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            <Card
                :class="clickableCardClass"
                role="button"
                tabindex="0"
                @click="$emit('navigate', 'links')"
                @keydown.enter.prevent="$emit('navigate', 'links')"
                @keydown.space.prevent="$emit('navigate', 'links')"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Entries Indexed</div>
                        <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-gray-100">{{ summary.total_entries }}</div>
                        <!-- V1.2 Cross-Tab-C — per-locale breakdown chips.
                             Only renders when the controller emitted a
                             non-empty `localeBreakdown` (multisite + ≥2
                             distinct locales in the index). Single-site
                             stays visually unchanged. -->
                        <div v-if="hasLocaleBreakdown" class="mt-1.5 flex flex-wrap items-center gap-1">
                            <span
                                v-for="(count, code) in localeBreakdown"
                                :key="code"
                                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wider bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"
                            >
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ count }}</span>
                                <span>{{ code }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <HelpIcon tooltip="Total entries tracked by Linkwise. Click to open the Links Report." />
                        <ClickIndicator />
                    </div>
                </div>
            </Card>

            <Card
                :class="clickableCardClass"
                role="button"
                tabindex="0"
                @click="$emit('navigate', 'links')"
                @keydown.enter.prevent="$emit('navigate', 'links')"
                @keydown.space.prevent="$emit('navigate', 'links')"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Outbound Links</div>
                        <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-gray-100">{{ summary.total_links }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">from {{ summary.entries_with_outbound || 0 }} of {{ summary.total_entries }} entries</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <HelpIcon tooltip="Total internal links going from one entry to another. More outbound links help search engines discover your content." />
                        <ClickIndicator />
                    </div>
                </div>
            </Card>

            <Card
                :class="clickableCardClass"
                role="button"
                tabindex="0"
                @click="$emit('navigate', 'links', { orphaned: true })"
                @keydown.enter.prevent="$emit('navigate', 'links', { orphaned: true })"
                @keydown.space.prevent="$emit('navigate', 'links', { orphaned: true })"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Orphaned Entries</div>
                        <div class="text-2xl font-bold mt-1" :class="summary.orphaned_count > 0 ? 'text-red-600' : 'text-green-600'">
                            {{ summary.orphaned_count }}
                        </div>
                        <div v-if="summary.orphaned_count > 0" class="text-xs text-gray-400 mt-0.5">{{ orphanedPercent }}% have no inbound links</div>
                        <div v-else class="text-xs text-green-500 mt-0.5">Every entry is linked</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <HelpIcon tooltip="Entries that no other entry links to. Search engines may not find these pages. Click to see them." />
                        <ClickIndicator />
                    </div>
                </div>
            </Card>

            <Card
                :class="clickableCardClass"
                role="button"
                tabindex="0"
                @click="$emit('navigate', 'domains')"
                @keydown.enter.prevent="$emit('navigate', 'domains')"
                @keydown.space.prevent="$emit('navigate', 'domains')"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">External Domains</div>
                        <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-gray-100">{{ domainsCount ?? '–' }}</div>
                        <div v-if="summary.external_links > 0" class="text-xs text-gray-400 mt-0.5">{{ summary.external_links }} outgoing links</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <HelpIcon tooltip="External websites you link to. Click to manage nofollow, sponsored, and UGC attributes." />
                        <ClickIndicator />
                    </div>
                </div>
            </Card>
        </div>

        <!-- Row 2: Health + Highlights (symmetrical 4-card row) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Broken Links -->
            <Card
                :class="brokenCount !== null ? clickableCardClass : 'h-full'"
                :role="brokenCount !== null ? 'button' : null"
                :tabindex="brokenCount !== null ? 0 : null"
                @click="brokenCount !== null && $emit('navigate', 'broken')"
                @keydown.enter.prevent="brokenCount !== null && $emit('navigate', 'broken')"
                @keydown.space.prevent="brokenCount !== null && $emit('navigate', 'broken')"
            >
                <div class="flex items-start justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Broken Links</div>
                        <div class="text-2xl font-bold mt-1" :class="brokenCountColor">
                            {{ brokenCount ?? '–' }}
                        </div>
                        <div v-if="brokenCount === 0" class="text-xs text-green-500 mt-0.5">All links healthy</div>
                        <div v-else-if="brokenCount > 0" class="text-xs text-red-400 mt-0.5">Click to review and fix</div>
                        <div v-else class="text-xs text-gray-400 mt-0.5">Not yet checked</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <HelpIcon tooltip="Links pointing to URLs that no longer exist (404, timeouts, SSL errors)." />
                        <ClickIndicator v-if="brokenCount !== null" />
                    </div>
                </div>
            </Card>

            <!-- Link Coverage with progress bar — clickable: drills into the
                 actionable subset (orphaned entries) so the user can fix the
                 root cause of low coverage. Same target as Orphaned Entries
                 card by design — two angles on the same insight. -->
            <Card
                :class="clickableCardClass"
                role="button"
                tabindex="0"
                @click="$emit('navigate', 'links', { orphaned: true })"
                @keydown.enter.prevent="$emit('navigate', 'links', { orphaned: true })"
                @keydown.space.prevent="$emit('navigate', 'links', { orphaned: true })"
            >
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Inbound Coverage</div>
                            <span :class="badgeClass(health.coverage_status)" class="text-xs font-medium px-2 py-0.5 rounded-full">
                                {{ badgeLabel(health.coverage_status) }}
                            </span>
                        </div>
                        <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-gray-100">{{ health.coverage }}%</div>
                        <div class="text-xs text-gray-400 mt-0.5">{{ summary.entries_with_inbound || 0 }} of {{ summary.total_entries }} entries receive links</div>
                        <div class="mt-2 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden" role="progressbar" :aria-valuenow="health.coverage" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full rounded-full transition-all duration-500" :class="barClass(health.coverage_status)" :style="{ width: health.coverage + '%' }"></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 ml-2">
                        <HelpIcon tooltip="Percentage of entries reachable through internal links. 100% means every entry gets at least one inbound link. Click to drill into orphans." />
                        <ClickIndicator />
                    </div>
                </div>
            </Card>

            <!-- Avg Outbound — clickable: jumps to Links Report so users can
                 sort/filter to see which entries are dragging the average down. -->
            <Card
                :class="clickableCardClass"
                role="button"
                tabindex="0"
                @click="$emit('navigate', 'links')"
                @keydown.enter.prevent="$emit('navigate', 'links')"
                @keydown.space.prevent="$emit('navigate', 'links')"
            >
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Avg Outbound Links</div>
                            <span :class="badgeClass(health.avg_outbound_status)" class="text-xs font-medium px-2 py-0.5 rounded-full">
                                {{ badgeLabel(health.avg_outbound_status) }}
                            </span>
                        </div>
                        <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-gray-100">{{ health.avg_outbound }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">per entry · reaching {{ health.coverage }}% of entries</div>
                    </div>
                    <div class="flex items-center gap-1 ml-2">
                        <HelpIcon tooltip="Average internal links per entry. Badge reflects combined health: a high average doesn't help if links concentrate on few targets (low inbound coverage). Click to browse all entries." />
                        <ClickIndicator />
                    </div>
                </div>
            </Card>

            <!-- Top Linked (combined Most + Least in one compact card) -->
            <Card v-if="summary.most_linked || summary.least_linked" class="h-full">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Top Linked</div>
                        <div v-if="summary.most_linked" class="mt-1">
                            <div class="text-xs text-gray-500 dark:text-gray-400">Most ({{ summary.most_linked.count }}×)</div>
                            <a v-if="summary.most_linked.edit_url" :href="summary.most_linked.edit_url" class="text-sm font-semibold text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400 truncate block">{{ summary.most_linked.title }}</a>
                            <span v-else class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate block">{{ summary.most_linked.title }}</span>
                        </div>
                        <div v-if="summary.least_linked" class="mt-2">
                            <div class="text-xs text-gray-500 dark:text-gray-400">Least ({{ summary.least_linked.count }}×)</div>
                            <a v-if="summary.least_linked.edit_url" :href="summary.least_linked.edit_url" class="text-sm font-semibold text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400 truncate block">{{ summary.least_linked.title }}</a>
                            <span v-else class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate block">{{ summary.least_linked.title }}</span>
                        </div>
                    </div>
                    <HelpIcon class="ml-2" tooltip="Entries with the highest and lowest inbound link counts. Click to open them." />
                </div>
            </Card>
            <!-- Placeholder card if no linked spread (keeps layout 4-wide) -->
            <Card v-else class="h-full">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Top Linked</div>
                        <div class="mt-2 text-xs text-gray-400">Not enough variation — more data needed.</div>
                    </div>
                    <HelpIcon class="ml-2" tooltip="Shows the most and least linked entries once your site has enough variation." />
                </div>
            </Card>
        </div>

        <!-- Persistent support surface on the Overview/landing tab. The
             Header-dropdown is fast to discover, the page-footer line is the
             safety-net — this card is the prominent "we have your back" anchor
             that V1 buyers see on every Overview visit. Three explicit buttons
             instead of inline links so the visual weight matches the metric
             cards above. -->
        <Card class="mt-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-base text-gray-900 dark:text-gray-100">Need help with Linkwise?</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Open an issue on GitHub, ask in the Statamic Discord (#addons), email us,
                        or download a diagnostic ZIP — we ship fixes ~3× faster with the ZIP attached.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <Button @click="openGithubIssue" text="Report a bug" icon="alert-warning-exclamation-mark" />
                    <Button @click="openSupportEmail" text="Email us" icon="mail" />
                    <Button @click="openDiscord" text="Discord" icon="social-discord-logo" v-tooltip="'Opens Statamic\'s official Discord — discuss Linkwise in #addons or DM @artur_rossbach'" />
                    <Button @click="downloadDiagnosticZip" text="Diagnostic ZIP" icon="download" />
                </div>
            </div>
        </Card>
    </div>
</template>

<script>
import { Card, Button, Alert } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import ClickIndicator from '../shared/ClickIndicator.vue';
import { readString, writeString, readJson, writeJson } from '../../utils/safeStorage.js';

// Threshold: after 7 days, suggest a re-scan
const STALE_INDEX_DAYS = 7;
// Threshold: over 30 days, suggest re-check of broken links
const STALE_CHECK_DAYS = 30;

// Shared styles for clickable metric cards — a11y + hover/focus state, applied directly on <Card>.
const CLICKABLE_CARD_CLASS = 'h-full cursor-pointer transition hover:ring-blue-400 dark:hover:ring-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';

export default {
    components: { Card, Button, Alert, HelpIcon, ClickIndicator },

    props: {
        summary: { type: Object, required: true },
        health: { type: Object, required: true },
        brokenCount: { type: Number, default: null },
        brokenLastChecked: { type: String, default: null },
        indexLastBuiltAt: { type: String, default: null },
        domainsCount: { type: Number, default: null },
        // {code, name, source: 'explicit'|'auto-detected'|'fallback', source_detail}
        // — used to surface which language Linkwise's NLP pipeline is
        // actually using, especially when the user left the language
        // setting empty and Linkwise auto-detected from Statamic's locale.
        resolvedLanguage: { type: Object, default: null },
        // V1.2 Cross-Tab-C — locale → count map. Empty/missing on single-
        // site installs (the controller returns [] when the index has
        // fewer than 2 distinct locales). Sorted by locale-code at the
        // controller layer; frontend respects iteration order.
        localeBreakdown: { type: Object, default: () => ({}) },
    },

    emits: ['navigate'],

    computed: {
        hasLocaleBreakdown() {
            return this.localeBreakdown && Object.keys(this.localeBreakdown).length > 0;
        },
    },

    data() {
        return {
            // Persists collapsed/expanded state of the recommendations <details>
            // accordion. Default false (open on first visit) so users see new
            // recommendations without searching; once they collapse it, the
            // choice sticks per-session via sessionStorage.
            recommendationsCollapsed: false,
            // Set of recommendation keys the user dismissed via ✕ in this
            // browser session. Persisted in sessionStorage so closed banners
            // stay closed for tab-switches but reappear after a fresh login.
            // User-Smoke 2026-05-22 (Cloudways): "30 orphaned (97%)" + the
            // coverage banner were nagging for a permanent state the user
            // already plans to fix later.
            dismissedRecommendations: [],
        };
    },

    mounted() {
        // safeStorage returns null on quota / private-mode failure; the
        // `=== '1'` comparison degrades to false → accordion just defaults
        // to open every load.
        this.recommendationsCollapsed = readString('linkwise:overview:recommendationsCollapsed') === '1';

        // Hydrate dismissed-recommendations set from sessionStorage so a
        // banner the user closed earlier in the session stays closed across
        // tab-switches. New session → array reset → all valid banners
        // reappear (intentional: if the user is back, give them another
        // chance to notice).
        const dismissed = readJson('linkwise:overview:dismissedRecommendations', []);
        this.dismissedRecommendations = Array.isArray(dismissed) ? dismissed : [];
    },

    computed: {
        clickableCardClass() {
            return CLICKABLE_CARD_CLASS;
        },

        orphanedPercent() {
            if (!this.summary.total_entries) return 0;
            return Math.round((this.summary.orphaned_count / this.summary.total_entries) * 100);
        },

        brokenCountColor() {
            if (this.brokenCount === null) return 'text-gray-400';
            return this.brokenCount > 0 ? 'text-red-600' : 'text-green-600';
        },

        indexAgeDays() {
            if (!this.indexLastBuiltAt) return null;
            const age = Date.now() - new Date(this.indexLastBuiltAt).getTime();
            return Math.floor(age / (1000 * 60 * 60 * 24));
        },

        brokenCheckAgeDays() {
            if (!this.brokenLastChecked) return null;
            const age = Date.now() - new Date(this.brokenLastChecked).getTime();
            return Math.floor(age / (1000 * 60 * 60 * 24));
        },

        /**
         * Dynamic recommendations based on current state.
         * Ordered by urgency — most critical first. Recommendations the
         * user dismissed in this session via the ✕ button are filtered
         * out via `dismissedRecommendations`.
         */
        recommendations() {
            const all = this.allRecommendations;
            if (this.dismissedRecommendations.length === 0) return all;
            return all.filter(r => ! this.dismissedRecommendations.includes(r.key));
        },

        /**
         * Full recommendation set BEFORE dismissal filtering. Split into
         * its own computed so the dismiss step remains a transparent
         * filter — keeps the ordering + key-generation logic untouched
         * by the user's session-scoped exclusions.
         */
        allRecommendations() {
            const recs = [];

            // Broken links never checked
            if (this.brokenCount === null) {
                recs.push({
                    key: 'broken-unchecked',
                    severity: 'warning',
                    title: 'Broken links never checked',
                    body: 'Run an initial scan to find dead URLs before they hurt your SEO.',
                    action: { label: 'Run check', tab: 'broken', options: { autoCheck: true } },
                });
            }

            // Broken links exist → urgent
            if (this.brokenCount > 0) {
                recs.push({
                    key: 'broken-exist',
                    severity: 'critical',
                    title: `${this.brokenCount} broken link${this.brokenCount === 1 ? '' : 's'} found`,
                    body: 'Dead links damage user experience and SEO. Fix them now.',
                    action: { label: 'Review & fix', tab: 'broken' },
                });
            } else if (this.brokenCheckAgeDays !== null && this.brokenCheckAgeDays > STALE_CHECK_DAYS) {
                // Last check is stale
                recs.push({
                    key: 'broken-stale',
                    severity: 'info',
                    title: 'Broken-link check is over a month old',
                    body: 'External URLs change often. Consider running a fresh check.',
                    action: { label: 'Run check', tab: 'broken', options: { autoCheck: true } },
                });
            }

            // High orphan rate
            if (this.summary.orphaned_count > 0 && this.orphanedPercent >= 20) {
                recs.push({
                    key: 'orphans-high',
                    severity: 'warning',
                    title: `${this.summary.orphaned_count} orphaned entries (${this.orphanedPercent}%)`,
                    body: 'These pages are not linked from anywhere. Add internal links so search engines can find them.',
                    action: { label: 'See orphans', tab: 'links', options: { orphaned: true } },
                });
            }

            // Index stale
            if (this.indexAgeDays !== null && this.indexAgeDays > STALE_INDEX_DAYS) {
                recs.push({
                    key: 'index-stale',
                    severity: 'info',
                    title: `Content index is ${this.indexAgeDays} days old`,
                    body: 'If you have published or edited entries since, re-scan for fresh suggestions.',
                    action: { label: 'Re-scan', tab: 'rebuild' },
                });
            }

            // Coverage warning
            if (this.health.coverage_status === 'warning') {
                recs.push({
                    key: 'coverage-low',
                    severity: 'warning',
                    title: `Inbound coverage at ${this.health.coverage}%`,
                    body: 'Most entries don\'t receive internal links. Use the Links Report to find linking opportunities.',
                    action: { label: 'See entries', tab: 'links' },
                });
            }

            return recs;
        },
    },

    methods: {
        dismissRecommendation(key) {
            if (this.dismissedRecommendations.includes(key)) return;
            this.dismissedRecommendations = [...this.dismissedRecommendations, key];
            writeJson('linkwise:overview:dismissedRecommendations', this.dismissedRecommendations);
        },

        badgeClass(status) {
            return {
                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400': status === 'great',
                'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400': status === 'ok',
                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400': status === 'warning',
            };
        },

        badgeLabel(status) {
            return { great: 'Great', ok: 'OK', warning: 'Needs Work' }[status] || status;
        },

        barClass(status) {
            return {
                'bg-green-500': status === 'great',
                'bg-yellow-500': status === 'ok',
                'bg-red-500': status === 'warning',
            };
        },

        severityVariant(severity) {
            return { critical: 'error', warning: 'warning', info: 'default' }[severity] || 'default';
        },

        handleRecommendationAction(action) {
            this.$emit('navigate', action.tab, action.options || {});
        },

        /**
         * Persist open/collapsed state of the recommendations accordion.
         * <details> fires `toggle` after the browser flips its internal
         * `open` state — read it back into Vue data + sessionStorage.
         */
        handleRecommendationsToggle(event) {
            this.recommendationsCollapsed = !event.target.open;
            writeString(
                'linkwise:overview:recommendationsCollapsed',
                this.recommendationsCollapsed ? '1' : '0',
            );
        },

        /**
         * Help-Card actions: same three channels as the header dropdown +
         * page-footer (GitHub issue, mailto, diagnostic ZIP). Inlined here so
         * the Overview Tab can stand alone without depending on parent layout
         * methods. URLs duplicated from LinkwiseLayout's constants — if those
         * change (e.g. repo transfer, support email update) update both.
         */
        openGithubIssue() {
            window.open('https://github.com/arturrossbach/statamic-linkwise/issues/new?template=bug.yml', '_blank', 'noopener,noreferrer');
        },

        openSupportEmail() {
            window.location.href = 'mailto:linkwise.support@gmail.com?subject=' + encodeURIComponent('Linkwise support');
        },

        /**
         * Help-Card: open the official Statamic Discord — fourth support
         * channel beside GitHub-issue / email / diagnostic-ZIP. The
         * #addons channel there is where Statamic-creator chat happens.
         */
        openDiscord() {
            window.open('https://statamic.com/discord', '_blank', 'noopener,noreferrer');
        },

        /**
         * Trigger the diagnostic-ZIP download. Always asks for confirmation
         * because the ZIP includes log files which may contain URLs from
         * scanned pages — the user should know what's about to leave their
         * server. Same modal-text pattern as the LinkwiseLayout dropdown,
         * implemented as a native confirm() here to avoid lifting the
         * Statamic ConfirmationModal across the parent boundary.
         */
        downloadDiagnosticZip() {
            const ok = window.confirm(
                'The diagnostic ZIP includes log files which may contain URLs from pages Linkwise has scanned. ' +
                'URLs can identify users (e.g. /users/john-doe) or contain sensitive query strings. ' +
                'Review the ZIP locally before sharing it with anyone.\n\n' +
                'Continue and download?',
            );
            if (! ok) return;
            const a = document.createElement('a');
            a.href = '/cp/linkwise/debug-export?include_logs=1';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        },

        /** Format an ISO timestamp as "3 hours ago" / "2 days ago" */
        relativeTime(iso) {
            if (!iso) return '';
            const diff = Date.now() - new Date(iso).getTime();
            const mins = Math.floor(diff / 60000);
            if (mins < 1) return 'just now';
            if (mins < 60) return `${mins} minute${mins === 1 ? '' : 's'} ago`;
            const hours = Math.floor(mins / 60);
            if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
            const days = Math.floor(hours / 24);
            if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;
            const months = Math.floor(days / 30);
            return `${months} month${months === 1 ? '' : 's'} ago`;
        },
    },
};
</script>
