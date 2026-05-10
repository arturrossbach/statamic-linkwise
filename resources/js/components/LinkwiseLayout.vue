<template>
    <div>
        <Head :title="pageTitle" />

        <Header title="Linkwise">
            <template #icon>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5 text-gray-500" aria-hidden="true">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                </svg>
            </template>

            <template #actions>
                <slot name="actions">
                    <div class="flex items-center gap-2">
                        <Button @click="rebuildIndex" :loading="rebuilding" :disabled="!!activeBulk" text="Scan Content" icon="sync" v-tooltip="'Re-analyze all entries: extract text, keywords, and map internal links'" />
                        <Dropdown align="end">
                            <!-- Override the default dots-icon trigger with a
                                 labeled "Help" button so users searching for
                                 support actually find the menu. -->
                            <template #trigger>
                                <Button text="Help" icon="info" v-tooltip="'Documentation, diagnostic export, version'" />
                            </template>
                            <DropdownMenu>
                                <DropdownItem text="Documentation" icon="external-link" @click="openDocs" />
                                <DropdownSeparator />
                                <DropdownItem text="Report a bug" icon="alert-warning-exclamation-mark" @click="openGithubIssue" />
                                <DropdownItem text="Email support" icon="mail" @click="openSupportEmail" />
                                <DropdownItem text="Statamic Discord" icon="social-discord-logo" @click="openDiscord" />
                                <DropdownSeparator />
                                <DropdownItem text="Download diagnostic ZIP" icon="download" @click="confirmDebugExportWithLogs" />
                                <DropdownSeparator />
                                <DropdownItem :text="`Linkwise ${linkwiseVersion}`" disabled />
                            </DropdownMenu>
                        </Dropdown>
                    </div>
                </slot>
            </template>
        </Header>

        <!-- Empty State: auto-scan on first visit -->
        <div v-if="isEmpty" class="py-12 text-center">
            <Card class="max-w-md mx-auto">
                <div class="py-4">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-12 mx-auto mb-4 text-gray-300 dark:text-gray-600">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <h3 v-if="!rebuilding" class="text-lg font-medium text-gray-700 dark:text-gray-300">Welcome to Linkwise</h3>
                    <h3 v-else class="text-lg font-medium text-gray-700 dark:text-gray-300">Scanning your content...</h3>
                    <p v-if="!rebuilding" class="text-sm text-gray-500 dark:text-gray-400 mt-2 mb-4">
                        Scanning your content to discover link opportunities.
                    </p>
                    <p v-else class="text-sm text-gray-500 dark:text-gray-400 mt-2 mb-4">
                        Analyzing entries, extracting keywords, and mapping links. This may take a moment.
                    </p>
                    <p v-if="rebuilding && activeBulk && activeBulk.total > 0" class="text-xs font-mono text-gray-500 dark:text-gray-400 mb-4">
                        {{ activeBulk.current }} / {{ activeBulk.total }}
                    </p>
                    <Button v-if="!rebuilding" @click="rebuildIndex" variant="primary" text="Scan Now" />
                </div>
            </Card>
        </div>

        <!-- Tab Navigation + Content -->
        <div v-else>
            <!-- Tab nav: horizontally scrollable on narrow viewports so the
                 7 tabs never wrap into a multi-row stack (which fights the
                 border-bottom indicator) and never trigger page-level
                 horizontal scroll. Each tab label is whitespace-nowrap so
                 multi-word labels (Auto-Linking, Target Keywords, URL Changer)
                 stay on a single line. -->
            <nav class="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-4 overflow-x-auto" aria-label="Linkwise tabs">
                <Link
                    v-for="tab in tabs"
                    :key="tab.name"
                    :href="tab.url"
                    class="px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap"
                    :class="tab.name === activeTab
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600'"
                    :preserve-scroll="false"
                >
                    {{ tab.label }}
                </Link>
            </nav>

            <!-- Persistent-notifications accordion: groups stale-check +
                 completion + recovery banners under one summary so users
                 who have seen them aren't pushed downward on every page-nav.
                 Joomla-style "X notifications" header, individual dismiss
                 buttons inside still work. Active-bulk banner stays outside
                 because it's transient AND critical — never collapsable. -->
            <details
                v-if="notificationCount > 0"
                :open="!notificationsCollapsed"
                @toggle="handleNotificationsToggle"
                class="mb-4 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40"
            >
                <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800/60 rounded-md">
                    {{ notificationCount }} {{ notificationCount === 1 ? 'notification' : 'notifications' }}
                </summary>
                <div class="px-3 pb-3 pt-1 flex flex-col gap-2">
                    <!-- Stale-check: index newer than last broken-link check.
                         Always-stacked layout so buttons aren't cramped. -->
                    <Alert v-if="showStaleCheck" variant="warning" role="status">
                        <div class="flex flex-col gap-3 text-sm">
                            <div>
                                <p class="font-medium">{{ staleCheckTitle }}</p>
                                <p class="mt-0.5 text-xs opacity-80">{{ staleCheckBody }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button @click="runStaleCheck" :loading="checkingFromBanner" :disabled="!!activeBulk" text="Run check now" size="xs" />
                                <Button @click="dismissStaleCheck" text="Dismiss" variant="default" size="xs" />
                            </div>
                        </div>
                    </Alert>

                    <!-- Completion: persistent recap of the last bulk so users
                         who missed the toast can still see the result.
                         Statamic's <Alert variant="..."> already renders its
                         own variant-matched icon (check / warning / x); don't
                         add an extra <Icon> here or the banner shows two
                         icons side-by-side. -->
                    <Alert v-if="lastCompletion && !activeBulk" :variant="completionBannerVariant" role="status">
                        <div class="flex items-start justify-between gap-4 text-sm">
                            <span>{{ completionBannerLabel }}</span>
                            <Button @click="dismissCompletion" text="Dismiss" variant="default" size="xs" />
                        </div>
                    </Alert>

                    <!-- Recovery: page reloaded mid-bulk — tells the user how
                         far it got. No resume action. -->
                    <Alert v-if="interruptedBulk" variant="warning" role="status">
                        <div class="flex items-start justify-between gap-4 text-sm">
                            <div>
                                <div class="font-medium">Previous bulk operation was interrupted.</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                    {{ interruptedBulkLabel }} — completed {{ interruptedBulk.current }} of {{ interruptedBulk.total }} before the page was reloaded.
                                    <span v-if="interruptedBulk.skipped > 0">{{ interruptedBulk.skipped }} skipped.</span>
                                    Re-run the same operation if you need the rest.
                                </div>
                            </div>
                            <Button @click="dismissInterruptedBulk" text="Dismiss" variant="default" size="xs" />
                        </div>
                    </Alert>
                </div>
            </details>

            <!-- Tab-spanning bulk-operation banner. Single source of truth for
                 ALL bulks (light + heavy) — visible across every Linkwise tab.
                 Switches to a "stuck" warning variant when the heartbeat is
                 stale (process likely crashed without the shutdown-guard
                 firing — e.g. server restart). User gets a Force-clear button. -->
            <Alert v-if="activeBulk" :variant="bulkStuck ? 'warning' : 'default'" class="mb-4 sticky top-0 z-30 shadow-md" role="status" aria-live="polite">
                <div class="flex items-center justify-between gap-2 text-sm">
                    <div class="flex items-center gap-2">
                        <span v-if="bulkStuck" class="font-medium">Operation may be stuck —</span>
                        <span>{{ bulkBannerLabel }}</span>
                        <!-- Indexing/finalizing phase: the visible counter has
                             hit N/N but the command is still rebuilding the
                             index + recomputing suggestion counts (can take
                             1-3min on large sites). Show "Finalizing…" so
                             the user doesn't think the job hung at N/N. -->
                        <span v-if="activeBulk.phase === 'indexing'" class="text-xs text-gray-500 dark:text-gray-400 italic">
                            Finalizing index…
                        </span>
                        <span v-else-if="activeBulk.total > 0" class="font-mono text-xs text-gray-500 dark:text-gray-400">
                            {{ activeBulk.current }} / {{ activeBulk.total }}
                        </span>
                        <span v-else-if="activeBulk.message" class="text-xs text-gray-500 dark:text-gray-400">
                            {{ activeBulk.message }}
                        </span>
                        <span v-if="bulkStuck" class="text-xs text-gray-500 dark:text-gray-400">
                            (no progress for {{ bulkStaleSeconds }}s)
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button v-if="bulkStuck" @click="forceClearBulk" :loading="forceClearing" text="Force-clear" variant="default" size="xs" />
                        <Button v-if="activeBulk.canCancel && !bulkStuck" @click="cancelBulk" :loading="cancelling" text="Cancel" variant="default" size="xs" />
                    </div>
                </div>
                <!-- Heavy bulks survive navigation/reload — they run in a detached
                     artisan process, the banner re-attaches via a global state
                     poll on every Linkwise tab. Telling the user means they
                     don't have to babysit the tab during a 10-minute scan. -->
                <p v-if="activeBulk.source === 'heavy' && !bulkStuck" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    You can safely navigate to other Linkwise tabs or away from this page — the operation continues in the background and the banner will reappear when you come back.
                </p>
            </Alert>

            <slot />

            <!-- Per-page support footer. Three independent paths so a buyer
                 who hits a bug picks whichever fits — we still get the report.
                 Visible on every Linkwise tab AFTER the tab content; deliberately
                 understated so it doesn't compete with the data. -->
            <div class="text-xs text-gray-400 dark:text-gray-500 text-center mt-12 mb-4 select-none">
                Need help?
                <a :href="githubIssueUrl" target="_blank" rel="noopener noreferrer" class="underline hover:text-gray-600 dark:hover:text-gray-300 transition-colors">Open issue</a>
                <span class="opacity-50 mx-1">·</span>
                <a :href="supportMailto" class="underline hover:text-gray-600 dark:hover:text-gray-300 transition-colors">Email us</a>
                <span class="opacity-50 mx-1">·</span>
                <a href="https://statamic.com/discord" target="_blank" rel="noopener noreferrer" class="underline hover:text-gray-600 dark:hover:text-gray-300 transition-colors">Discord</a>
                <span class="opacity-50 mx-1">·</span>
                <a href="#" @click.prevent="confirmDebugExportWithLogs" class="underline hover:text-gray-600 dark:hover:text-gray-300 transition-colors">Download diagnostic ZIP</a>
            </div>
        </div>

        <!-- Debug-export "with logs" confirmation. Default download path is
             GDPR-safe (counts + stats only) and runs without confirmation;
             this modal only fires for the explicit log-bundled variant. -->
        <ConfirmationModal
            :open="confirmDebugWithLogs"
            @update:open="confirmDebugWithLogs = $event"
            @confirm="executeDebugExportWithLogs"
            @cancel="confirmDebugWithLogs = false"
            title="Include log files in the export?"
            body-text="Logs may contain URLs from pages Linkwise has scanned. URLs can identify users (e.g. /users/john-doe) or contain sensitive query strings. Review the ZIP locally before sharing it with anyone."
            button-text="Include logs and download"
        />
    </div>
</template>

<script>
import { Head, Link } from '@statamic/cms/inertia';
import { Header, Card, Button, Alert, Icon, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, ConfirmationModal } from '@statamic/cms/ui';
import { bulkState, setHeavyState, cancelActive, getInterruptedBulk, clearInterruptedBulk, recordCompletion, getLastCompletion, clearLastCompletion } from '../services/bulkOperationService.js';
import { activeLabel, shortLabel, completionLabel, completionVariant } from '../services/bulkLabels.js';
import { readString, writeString } from '../utils/safeStorage.js';

// Hardcoded for V1 — wire from composer.json via route props once we tag a
// release. Visible at the bottom of the dropdown to help support reproduce
// version-specific issues.
const LINKWISE_VERSION = '1.0.0-dev';
const DEBUG_EXPORT_URL = '/cp/linkwise/debug-export';
const DOCS_URL = 'https://github.com/arturrossbach/statamic-linkwise#readme';

// Support channels surfaced on every Linkwise page (footer + Help dropdown).
// Designed for minimum friction: GitHub issue + email + diagnostic ZIP, three
// independent paths so a buyer who hits a bug can pick whichever fits and we
// still get the report. Update GITHUB_ISSUES_NEW_URL after a possible repo
// transfer (e.g. arturrossbach/linkwise) — the bug.yml template name is stable.
const SUPPORT_EMAIL = 'linkwise.support@gmail.com';
const GITHUB_ISSUES_NEW_URL = 'https://github.com/arturrossbach/statamic-linkwise/issues/new?template=bug.yml';
// Statamic's official Discord — addon-specific discussions in #addons.
// Worth surfacing as a fourth support channel for users who prefer chat
// over GitHub issues / email and to plug Linkwise into the community.
const DISCORD_URL = 'https://statamic.com/discord';

export default {
    components: { Head, Link, Header, Card, Button, Alert, Icon, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, ConfirmationModal },

    props: {
        activeTab: { type: String, required: true },
        pageTitle: { type: String, default: 'Linkwise' },
        isEmpty: { type: Boolean, default: false },
        rebuildUrl: { type: String, required: true },
        rebuildStatusUrl: { type: String, default: '' },
        rebuildCancelUrl: { type: String, default: '' },
    },

    computed: {
        // Surface the bundled version string in the header dropdown — quickest
        // way for support to ask "what version are you running?".
        linkwiseVersion() {
            return LINKWISE_VERSION;
        },

        // Per-page support footer + Help dropdown both use these. The mailto
        // pre-fills a 4-line skeleton (version + symptoms + expectation + ZIP
        // hint) so the user doesn't stare at a blank email and bail. Subject
        // is constant so support emails group naturally in the inbox.
        supportMailto() {
            const subject = encodeURIComponent('Linkwise support');
            const body = encodeURIComponent(
                `Linkwise version: ${LINKWISE_VERSION}\n\n` +
                `What went wrong:\n\n\n` +
                `What I expected:\n\n\n` +
                `(Tip: attach your diagnostic ZIP — CP → Linkwise → Help → Download diagnostic ZIP)`,
            );
            return `mailto:${SUPPORT_EMAIL}?subject=${subject}&body=${body}`;
        },

        // GitHub issue URL with bug.yml template prefilled. Opens to the
        // issue-form picker which lands the user directly in the form.
        githubIssueUrl() {
            return GITHUB_ISSUES_NEW_URL;
        },

        /**
         * Last completed (or cancelled / errored) bulk — drives the persistent
         * dismissible banner so the user gets the outcome even if they missed
         * the toast. Single source of truth: `bulkState.lastCompletion` in the
         * shared bulkOperationService. Child components (LinksReportTab,
         * AutoLinkingTab) call recordCompletion() which writes to that shared
         * reactive state — this computed picks the change up directly so the
         * banner updates regardless of who recorded the completion.
         */
        lastCompletion() {
            return bulkState.lastCompletion;
        },

        // Count of currently-visible persistent notifications. Drives the
        // <details> summary "X notifications" + the v-if that hides the
        // entire accordion when nothing is pending.
        notificationCount() {
            let n = 0;
            if (this.showStaleCheck) n++;
            if (this.lastCompletion && !this.activeBulk) n++;
            if (this.interruptedBulk) n++;
            return n;
        },

        /**
         * Stale-check signal from server (set by DashboardController::staleCheckProps).
         * is_stale === true means the index has been rebuilt since the last
         * broken-link check (>5min). Read from $page.props so every Linkwise
         * page picks it up without each *Page.vue having to declare a prop.
         */
        staleCheck() {
            return this.$page?.props?.staleCheck || null;
        },

        showStaleCheck() {
            // Never stack on top of an active bulk — keeps visual hierarchy.
            if (this.activeBulk) return false;
            if (! this.staleCheck?.is_stale) return false;
            // Dismiss persists per index-state (keyed by index_built_at).
            // The user's dismissal sticks across reloads + tab navigations
            // until the next scan changes the index timestamp — at which
            // point the stored dismissal no longer matches and the banner
            // resurfaces, because the staleness condition is genuinely new.
            const dismissedFor = this.staleCheckDismissedFor;
            const currentIndexAt = this.staleCheck?.index_built_at || '';
            if (dismissedFor && dismissedFor === currentIndexAt) return false;
            return true;
        },

        staleCheckTitle() {
            if (!this.staleCheck?.broken_last_checked) {
                return 'Broken-link check has never run';
            }
            return 'Recent edits may have introduced new broken links';
        },

        staleCheckBody() {
            const c = this.staleCheck;
            if (!c) return '';
            if (!c.broken_last_checked) {
                return 'Run the initial check to surface dead URLs across your content.';
            }
            return 'The content index has been updated since the last broken-link check. Re-run the check to catch any new dead URLs.';
        },

        // Single source of truth for "is anything running anywhere in Linkwise".
        // Reads from the bulkOperationService reactive store — covers light ops
        // (inbound-insert, detail-unlink, ...) AND heavy ops (scan, check,
        // bulkunlink, applyrule) which are pushed in by pollBulkStatusOnce().
        activeBulk() {
            return bulkState.active;
        },

        // Backwards-compat for the empty-state Card and the Scan Content button.
        // Was a local `rebuilding` data flag; now derived from the unified state.
        rebuilding() {
            return this.activeBulk?.kind === 'scan';
        },

        /**
         * "Operation may be stuck" detector. Heartbeat is server-side time()
         * stamped on every progress write. If we've seen no fresh heartbeat
         * for >120s the process likely died without the shutdown-guard firing
         * (e.g. server hard-restart). User can click Force-clear to recover.
         */
        bulkStuck() {
            const a = this.activeBulk;
            if (!a || !a.heartbeat) return false;
            // tickClock is updated every 5s — drives reactivity for time-based comparison
            const ageSec = (this.tickClock / 1000) - a.heartbeat;
            return ageSec > 120;
        },

        bulkStaleSeconds() {
            const a = this.activeBulk;
            if (!a || !a.heartbeat) return 0;
            return Math.round((this.tickClock / 1000) - a.heartbeat);
        },

        // Variant signal for the persistent completion banner. Delegates
        // to the shared bulkLabels module — same logic now drives toast
        // colour AND banner colour from one place.
        completionBannerVariant() {
            const c = this.lastCompletion;
            if (!c) return 'default';
            return completionVariant(c.kind, c.phase, c.extra || {});
        },

        // Past-tense summary of the completed bulk. Same source of truth
        // as the terminal toast (fireTerminalToast also delegates to
        // completionLabel) so banner and toast can never drift in copy.
        completionBannerLabel() {
            const c = this.lastCompletion;
            if (!c) return '';
            return completionLabel(c.kind, c.phase, c.extra || {}, c.label || 'Operation');
        },

        // Live banner — what's running RIGHT NOW. Pure delegation.
        bulkBannerLabel() {
            const a = this.activeBulk;
            if (!a) return '';
            return activeLabel(a.kind, a.context || {});
        },

        // Recovery banner after mid-operation reload. Different shape from
        // active because we only persist (kind, ruleKeyword, search) —
        // no live counters or owner info available.
        interruptedBulkLabel() {
            const a = this.interruptedBulk;
            if (!a) return '';
            return shortLabel(a.kind, { ...(a.context || {}), label: a.label });
        },
    },

    data() {
        return {
            // Snapshot of an interrupted-bulk record from sessionStorage —
            // populated on mount if the user reloaded mid-bulk. Drives the
            // recovery banner; cleared via dismissInterruptedBulk().
            interruptedBulk: null,
            bulkPollTimer: null,
            cancelling: false,
            forceClearing: false,
            // Reactive 5s clock — drives time-based computeds like bulkStuck
            // (without it Vue wouldn't recompute "is heartbeat stale?" since
            // heartbeat is set once and Date.now() isn't reactive).
            tickClock: Date.now(),
            tickClockTimer: null,
            // Stale-check banner state. dismissedFor stores the index_built_at
            // value the user dismissed for — persists across reloads via
            // sessionStorage (initialised in mounted) so dismissing actually
            // sticks until the next scan changes the index timestamp.
            checkingFromBanner: false,
            staleCheckDismissedFor: null,
            // Persists collapsed/expanded state of the persistent-notifications
            // accordion across reloads + tab navigations. Default false (open
            // on first load) so users see new notifications without searching;
            // once they collapse it, the choice sticks for the session.
            notificationsCollapsed: false,
            // Debug-export "with logs" requires explicit confirmation because
            // log files may contain URLs from scanned pages. Modal opens via
            // dropdown, user confirms, only then does the download fire.
            confirmDebugWithLogs: false,
            // Per-kind flag set when the poller has observed a non-terminal phase
            // in THIS instance. Prevents firing stale completion toasts/actions
            // when a 'done' state is still in the server cache from a previous
            // session (cache TTL 300s would otherwise cause reload loops on scan).
            seenRunning: { scan: false, check: false, bulkunlink: false, applyrule: false, urlchanger: false, detailunlink: false, inboundinsert: false, outboundinsert: false },
            tabs: [
                { name: 'overview', label: 'Overview', url: this.route('linkwise.dashboard') },
                { name: 'links', label: 'Links Report', url: this.route('linkwise.links') },
                { name: 'broken', label: 'Broken Links', url: this.route('linkwise.broken') },
                { name: 'domains', label: 'Domains', url: this.route('linkwise.domains') },
                { name: 'autolink', label: 'Auto-Linking', url: this.route('linkwise.autolink') },
                { name: 'keywords', label: 'Target Keywords', url: this.route('linkwise.keywords') },
                { name: 'urlchanger', label: 'URL Changer', url: this.route('linkwise.urlchanger') },
                // Hard-coded URL — `this.route('linkwise.activity')` returned
                // an empty/dashboard fallback when the named route wasn't yet
                // in Statamic's client-side route map (route was added after
                // the CP's last boot). String is the durable workaround.
                { name: 'activity', label: 'Activity Log', url: '/cp/linkwise/activity' },
            ],
        };
    },

    async mounted() {
        // Pick up any interrupted-bulk record left over from a mid-operation
        // page reload. Drives the recovery banner — info-only, user dismisses
        // when reviewed.
        this.interruptedBulk = getInterruptedBulk();
        // Pick up the last-completed-bulk result so the persistent banner
        // survives tab switches and reloads — toast might be gone after 12s
        // but the user might still want to know "did my apply finish OK".
        // Side-effect: hydrates bulkState.lastCompletion from sessionStorage
        // so the computed `lastCompletion` below picks it up reactively.
        getLastCompletion();

        // Restore the persisted "dismissed for which index_built_at" marker
        // so reloads + tab switches don't resurrect the stale-check banner
        // the user already acknowledged. The next scan invalidates this
        // because index_built_at changes and the comparison stops matching.
        // safeStorage swallows quota/private-mode failures — banner just
        // won't stay dismissed across reload (a UX nicety, not load-bearing).
        this.staleCheckDismissedFor = readString('linkwise:staleCheck:dismissedFor');

        // Restore collapsed/expanded state of the notifications accordion so
        // a user who collapsed it doesn't get it re-pushed open on every nav.
        this.notificationsCollapsed = readString('linkwise:notifications:collapsed') === '1';

        // Tick the reactive clock every 5s so bulkStuck recomputes — without
        // this the heartbeat-staleness check would never re-evaluate.
        this.tickClockTimer = setInterval(() => { this.tickClock = Date.now(); }, 5000);

        // Unified bulk-status poller: replaces the old per-job polling
        // (rebuild + apply-async). Runs on every Linkwise tab so progress +
        // completion toasts work no matter where the user is.
        await this.pollBulkStatusOnce();
        this.startBulkStatusPolling();

        // Auto-scan on first visit if nothing else is already running.
        if (this.isEmpty && !bulkState.active) {
            this.rebuildIndex();
        }
    },

    beforeUnmount() {
        this.stopBulkStatusPolling();
        if (this.tickClockTimer) clearInterval(this.tickClockTimer);
    },

    methods: {
        /**
         * Banner CTA: kick off a broken-link check. Reuses the existing
         * /linkwise/check-links endpoint so the unified bulk-status poller
         * picks up the running phase and renders the live progress banner —
         * the stale-check banner stays out of the way once the check starts.
         */
        async runStaleCheck() {
            const url = this.staleCheck?.check_url;
            if (!url) return;

            // Persist user-intent: clicking "Run check now" implies "I've
            // acknowledged the staleness and am acting on it — don't show
            // me this banner again for this index version". Without this,
            // multiple paths can re-surface the banner after the check
            // (server-side timing edges, index-rebuilding subscribers,
            // browser-side prop caching). The dismiss is keyed to the
            // CURRENT index_built_at, so a future scan that produces a new
            // index version will correctly resurface the banner.
            const indexAt = this.staleCheck?.index_built_at || '';
            if (indexAt) {
                this.staleCheckDismissedFor = indexAt;
                writeString('linkwise:staleCheck:dismissedFor', indexAt);
            }

            this.checkingFromBanner = true;
            try {
                const response = await fetch(url, {
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
                    Statamic.$toast.error('Failed to start link check.');
                    return;
                }
                // Trigger an immediate poll so the live banner shows up
                // without 1.5s lag.
                this.pollBulkStatusOnce();
            } catch (error) {
                Statamic.$toast.error('Failed to start link check.');
                console.error('[Linkwise]', error);
            } finally {
                this.checkingFromBanner = false;
            }
        },

        /**
         * Persist the open/collapsed state of the notifications accordion to
         * sessionStorage so users who collapsed it don't get it pushed open
         * on every page-nav. The <details> element fires `toggle` after the
         * browser flips its internal `open` state — read it back into the
         * Vue data flag and persist.
         */
        handleNotificationsToggle(event) {
            this.notificationsCollapsed = !event.target.open;
            writeString(
                'linkwise:notifications:collapsed',
                this.notificationsCollapsed ? '1' : '0',
            );
        },

        dismissStaleCheck() {
            // Persist the dismissal scoped to the current index timestamp.
            // Reloads + tab switches honour it; the next scan changes
            // index_built_at and the comparison in showStaleCheck stops
            // matching, so a genuinely-new staleness condition resurfaces.
            const indexAt = this.staleCheck?.index_built_at || '';
            this.staleCheckDismissedFor = indexAt;
            writeString('linkwise:staleCheck:dismissedFor', indexAt);
        },

        /**
         * Open the README for this addon in a new tab. Lives in the header
         * dropdown so it's reachable from every Linkwise page.
         */
        openDocs() {
            window.open(DOCS_URL, '_blank', 'noopener,noreferrer');
        },

        /**
         * Help dropdown: open the GitHub bug-report template in a new tab.
         * Mirror of the footer link — surfaced in two places because users who
         * scan the header for help shouldn't need to scroll the page.
         */
        openGithubIssue() {
            window.open(GITHUB_ISSUES_NEW_URL, '_blank', 'noopener,noreferrer');
        },

        /**
         * Help dropdown: open mailto with pre-filled subject + 4-line body
         * skeleton. Uses the same template as the footer's "Email us" link.
         */
        openSupportEmail() {
            window.location.href = this.supportMailto;
        },

        /**
         * Help dropdown: open Statamic's official Discord in a new tab.
         * Statamic Marketplace creators use #addons there for quick chat
         * support — fourth channel beyond GitHub-issue / email / diagnostic-ZIP.
         */
        openDiscord() {
            window.open(DISCORD_URL, '_blank', 'noopener,noreferrer');
        },

        /**
         * Trigger the debug-export download. The default path (no logs) is
         * GDPR-safe by design: counts and stats only, no URLs from the user's
         * site. Calling with includeLogs=true appends ?include_logs=1 — only
         * fired AFTER the confirmation modal has been accepted.
         *
         * Uses a temp <a download> element instead of fetch() so the browser
         * handles the binary stream natively (no need to convert to Blob).
         */
        downloadDebugExport(includeLogs = false) {
            const url = includeLogs
                ? `${DEBUG_EXPORT_URL}?include_logs=1`
                : DEBUG_EXPORT_URL;
            const a = document.createElement('a');
            a.href = url;
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        },

        /**
         * Dropdown click for "Download with logs" — opens the confirmation
         * modal. The actual download only fires when the user confirms.
         */
        confirmDebugExportWithLogs() {
            this.confirmDebugWithLogs = true;
        },

        executeDebugExportWithLogs() {
            this.confirmDebugWithLogs = false;
            this.downloadDebugExport(true);
        },

        dismissInterruptedBulk() {
            clearInterruptedBulk();
            this.interruptedBulk = null;
        },

        dismissCompletion() {
            clearLastCompletion();
        },

        route(name) {
            // Statamic CP routes are prefixed with /cp/
            const routes = {
                'linkwise.dashboard': '/cp/linkwise',
                'linkwise.links': '/cp/linkwise/links',
                'linkwise.broken': '/cp/linkwise/broken',
                'linkwise.domains': '/cp/linkwise/domains',
                'linkwise.autolink': '/cp/linkwise/autolink',
                'linkwise.keywords': '/cp/linkwise/keywords',
                'linkwise.urlchanger': '/cp/linkwise/url-changer',
            };
            return routes[name] || '/cp/linkwise';
        },

        /**
         * Start a content scan. Status updates flow through the unified
         * bulk-status poller — no per-job polling here anymore.
         */
        async rebuildIndex() {
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
                    Statamic.$toast.info(data.message || 'Another bulk operation is running. Wait for it to finish.');
                    return;
                }
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.error(`Could not scan content: ${data?.error || data?.message || `HTTP ${response.status}`}`);
                    return;
                }

                // Trigger an immediate poll so the banner shows up without 1s lag.
                this.pollBulkStatusOnce();
            } catch (error) {
                Statamic.$toast.error(`Could not scan content: ${error.message || 'network error'}`);
            }
        },

        startBulkStatusPolling() {
            this.stopBulkStatusPolling();
            this.bulkPollTimer = setInterval(() => this.pollBulkStatusOnce(), 1500);
        },

        stopBulkStatusPolling() {
            if (this.bulkPollTimer) {
                clearInterval(this.bulkPollTimer);
                this.bulkPollTimer = null;
            }
        },

        /**
         * Poll the unified /linkwise/bulk-status endpoint. Pushes the active
         * heavy job into bulkState so the banner reflects it; on terminal
         * phases, fires the kind-specific completion toast (with sessionStorage
         * dedup) and clears the heavy state.
         */
        async pollBulkStatusOnce() {
            const url = this.cp_url('linkwise/bulk-status');
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) return;
                const status = await response.json();
                const phase = status.phase || 'idle';

                if (phase === 'idle') {
                    setHeavyState(null);
                    return;
                }

                if (!status.terminal) {
                    // Active running phase: surface progress in the banner.
                    if (status.kind && this.seenRunning[status.kind] !== undefined) {
                        this.seenRunning[status.kind] = true;
                    }
                    // Build kind-specific context so the banner can render
                    // detailed labels like 'Applying rule "keyword"'.
                    const extra = status.extra || {};
                    const context = {};
                    if (status.kind === 'applyrule') {
                        if (extra.rule_keyword) context.ruleKeyword = extra.rule_keyword;
                        // Multi-rule run — banner shows nested progress.
                        if (extra.total_rules) {
                            context.totalRules = extra.total_rules;
                            context.ruleIndex = extra.current_rule_index || 0;
                        }
                    }
                    if (status.kind === 'urlchanger') {
                        context.action = extra.action || 'apply';
                        context.search = extra.search || '';
                        // Hide owner label when it's the current user — "(by me)"
                        // is just noise. Show only when a colleague started it.
                        const currentUserId = (typeof Statamic !== 'undefined' && Statamic.user) ? Statamic.user.id : null;
                        const ownedByOther = extra.started_by_id && currentUserId && extra.started_by_id !== currentUserId;
                        context.startedBy = ownedByOther ? extra.started_by : null;
                    }
                    if (status.kind === 'detailunlink') {
                        context.sourceMode = extra.source_mode || 'inbound';
                        context.entryTitle = extra.entry_title || '';
                        const currentUserId = (typeof Statamic !== 'undefined' && Statamic.user) ? Statamic.user.id : null;
                        const ownedByOther = extra.started_by_id && currentUserId && extra.started_by_id !== currentUserId;
                        context.startedBy = ownedByOther ? extra.started_by : null;
                    }
                    setHeavyState({
                        kind: status.kind,
                        label: status.label,
                        // Pass through the raw phase ('starting' / 'running' /
                        // 'indexing' / 'checking' / ...) so the banner can
                        // distinguish "still doing main work" from "finalizing
                        // the index" (which can take a couple of minutes after
                        // the visible counter hits N/N — without this, the
                        // banner sits stuck at 80/80 looking dead).
                        phase,
                        current: status.current || 0,
                        total: status.total || 0,
                        message: status.message || null,
                        cancelUrl: status.cancel_url || null,
                        canCancel: !!status.cancel_url,
                        // Server-side timestamp from each running-status write —
                        // used by bulkStuck to detect dead processes.
                        heartbeat: extra.heartbeat || null,
                        context,
                    });
                    return;
                }

                // Terminal phase. Clear heavy state, dedup completion toast.
                setHeavyState(null);

                // Build a CONTENT-based signature so different runs of the
                // same kind produce different signatures. Without the kind-
                // specific extras, multi-rule runs ALL get signature
                // `applyrule:done:0:0:` (because current/total live in
                // status.extra for multi-mode) → dedup would block every
                // run after the first.
                const tExtra = status.extra || {};
                let kindSig = '';
                if (status.kind === 'applyrule') {
                    kindSig = `:r${tExtra.total_rules || 0}:la${tExtra.total_links_added || tExtra.links_added || 0}`;
                } else if (status.kind === 'urlchanger') {
                    kindSig = `:a${tExtra.action || ''}:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}`;
                } else if (status.kind === 'detailunlink') {
                    kindSig = `:m${tExtra.source_mode || ''}:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}`;
                } else if (status.kind === 'bulkunlink') {
                    kindSig = `:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}`;
                } else if (status.kind === 'outboundinsert' || status.kind === 'inboundinsert') {
                    // Without this branch, repeated outbound/inbound inserts
                    // with identical succeeded/skipped numbers (very common
                    // case: skipped:1 from anchor-not-found over and over)
                    // produced the SAME signature and the second + every
                    // subsequent toast got dedup-suppressed. User saw the
                    // banner blink and nothing else. Real bug 2026-05-10.
                    // started_by_id makes the signature unique per session/
                    // user; combined with heartbeat (a per-run timestamp)
                    // every actual run gets a fresh signature.
                    kindSig = `:s${tExtra.succeeded || 0}:sk${tExtra.skipped || 0}:hb${tExtra.heartbeat || tExtra.started_by_id || ''}`;
                }
                const signature = `${status.kind}:${phase}:${status.current}:${status.total}:${status.message || ''}${kindSig}`;
                const SEEN_KEY = 'linkwise:bulkToastShown';
                if (readString(SEEN_KEY) === signature) return;
                writeString(SEEN_KEY, signature);

                this.fireTerminalToast(status);

                // Persist a snapshot of the completion so a dismissible banner
                // shows the result even if the user was off-screen for the
                // toast. Re-rendered on every mount via getLastCompletion().
                const completionSnap = {
                    kind: status.kind,
                    label: status.label,
                    phase,
                    current: status.current,
                    total: status.total,
                    extra: status.extra || {},
                };
                // recordCompletion writes to bulkState.lastCompletion (reactive
                // shared) — the layout's `lastCompletion` computed picks it up.
                recordCompletion(completionSnap);

                // Scan needs a full reload to refresh entries data — but only
                // if WE observed the scan running in this layout instance.
                // Otherwise stale 'done' from a previous session (cache TTL
                // 300s) would cause an infinite reload loop.
                if (status.kind === 'scan' && phase === 'done' && this.seenRunning.scan) {
                    this.seenRunning.scan = false;
                    window.location.reload();
                }
                // After a broken-link check, reload so staleCheck.is_stale
                // re-computes server-side. Without this, the "Recent edits..."
                // banner sticks around because Inertia keeps the prop value
                // from before the check ran. Same once-per-instance guard
                // pattern as scan to avoid infinite reload loops.
                if (status.kind === 'check' && phase === 'done' && this.seenRunning.check) {
                    this.seenRunning.check = false;
                    window.location.reload();
                }
                // After inbound/outbound bulk-add: reload so the entries
                // table reflects new outbound/inbound counts and the index
                // changes from finalizeIndex() are visible. Same once-per-
                // instance guard so a stale 'done' from a previous session
                // (cache TTL 300s) doesn't loop.
                if ((status.kind === 'inboundinsert' || status.kind === 'outboundinsert')
                    && phase === 'done'
                    && this.seenRunning[status.kind]) {
                    this.seenRunning[status.kind] = false;
                    window.location.reload();
                }
            } catch {
                // transient errors — try again next tick
            }
        },

        /**
         * Render the terminal toast for a completed bulk. Delegates the
         * full label-construction + variant-selection to the shared
         * bulkLabels module, then maps the variant onto Statamic's toast
         * API. Same module drives the persistent completion banner —
         * banner copy and toast copy can never drift out of sync.
         *
         * Long-message-bias: errors carry a 12s duration so the user has
         * time to read multi-clause failure reasons before the toast fades.
         */
        fireTerminalToast(status) {
            const message = completionLabel(
                status.kind,
                status.phase,
                status.extra || {},
                status.label || 'Task',
            );
            const variant = completionVariant(status.kind, status.phase, status.extra || {});

            switch (variant) {
                case 'success':
                    Statamic.$toast.success(message);
                    break;
                case 'warning':
                    // 'warning' -> info (Statamic.$toast lacks a warning
                    // channel) but with extended duration so a skipped-
                    // outcome message ("Could not add any links — anchor
                    // not found. Re-scan and retry.") doesn't flash by.
                    // 12s matches the error variant.
                    Statamic.$toast.info(message, { duration: 12000 });
                    break;
                case 'error':
                    Statamic.$toast.error(message, { duration: 12000 });
                    break;
                default:
                    Statamic.$toast.info(message);
            }

            // Force-open the notifications <details> when a non-success
            // outcome lands so the persistent banner is visible. Without
            // this the user only sees a brief toast — the recap banner
            // sits silently inside a collapsed disclosure.
            if (variant !== 'success') {
                this.notificationsCollapsed = false;
            }
        },

        async cancelBulk() {
            this.cancelling = true;
            await cancelActive();
            // Light cancel resolves immediately when the loop exits; heavy
            // cancel needs a polling cycle to propagate. Reset the loading
            // state after a short delay so the button doesn't lock up.
            setTimeout(() => { this.cancelling = false; }, 1500);
        },

        /**
         * Force-clear a stuck heavy-job. Called from the "Force-clear" button
         * that appears in the banner when heartbeat staleness exceeds 120s.
         * Hits POST /linkwise/bulk-clear/{kind} which calls JobLock::forceClear,
         * wiping status / payload / cancel cache keys. Then forces a fresh
         * poll so the banner updates immediately.
         */
        async forceClearBulk() {
            const kind = this.activeBulk?.kind;
            if (!kind) return;
            this.forceClearing = true;
            try {
                await fetch(this.cp_url(`linkwise/bulk-clear/${kind}`), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                // Clear immediately on the frontend too — poller would catch
                // up within 1.5s but the user wants to see action now.
                setHeavyState(null);
            } catch {
                Statamic.$toast.error('Could not force-clear stuck operation.');
            } finally {
                this.forceClearing = false;
                this.pollBulkStatusOnce();
            }
        },
    },
};
</script>

<!-- Unscoped on purpose: Statamic's tooltip directive renders the popper to
     a teleport target outside the component tree, so a scoped style wouldn't
     reach it. The selector is global, so it'll affect every tooltip in the
     CP — but only while a Linkwise page is mounted (the rule is shipped via
     this layout component's <style>). The change is conservative: max-width
     + word-wrap; short tooltips look identical, only long ones wrap. -->
<style>
.v-popper--theme-tooltip .v-popper__inner {
    max-width: 28rem;
    white-space: normal;
    line-height: 1.4;
}
</style>
