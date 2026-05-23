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
        <div v-if="isEmpty" class="py-12">
            <Card class="max-w-2xl mx-auto">
                <div class="py-4 px-2">
                    <div class="text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-12 mx-auto mb-4 text-gray-300 dark:text-gray-600">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <h3 v-if="!rebuilding" class="text-lg font-medium text-gray-700 dark:text-gray-300">Welcome to Linkwise</h3>
                        <h3 v-else class="text-lg font-medium text-gray-700 dark:text-gray-300">Scanning your content...</h3>
                        <p v-if="!rebuilding" class="text-sm text-gray-500 dark:text-gray-400 mt-2 mb-4 max-w-md mx-auto">
                            Linkwise analyses your entries to discover internal-link opportunities. Here are the next steps to get the most out of it:
                        </p>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400 mt-2 mb-4">
                            Analysing entries, extracting keywords, and mapping links. This may take a moment.
                        </p>
                        <p v-if="rebuilding && activeBulk && activeBulk.total > 0" class="text-xs font-mono text-gray-500 dark:text-gray-400 mb-4">
                            {{ activeBulk.current }} / {{ activeBulk.total }}
                        </p>
                    </div>

                    <!--
                        First-run onboarding checklist. Replaces the
                        previous one-line welcome message — too many
                        editors didn't know which knob to turn first.
                        Linked to docs.linkwise (planned) + the Settings
                        page anchors so each step is a single click.
                    -->
                    <ol v-if="!rebuilding" class="text-sm text-gray-600 dark:text-gray-400 max-w-lg mx-auto space-y-3 my-6 list-decimal list-inside">
                        <li>
                            <strong class="text-gray-700 dark:text-gray-300">Pick your Content Language.</strong>
                            <span class="block ml-5 text-xs">
                                Drives stemming + stopword filtering. Visit
                                <a href="/cp/utilities/addon-settings/arturrossbach.statamic-linkwise" class="text-blue-600 dark:text-blue-400 hover:underline">Settings → Content Language</a>
                                if Linkwise hasn't auto-detected it correctly.
                            </span>
                        </li>
                        <li>
                            <strong class="text-gray-700 dark:text-gray-300">Choose which Collections to index.</strong>
                            <span class="block ml-5 text-xs">
                                Leave empty to index all. If you have draft-collections that shouldn't appear in suggestions, exclude them in
                                <a href="/cp/utilities/addon-settings/arturrossbach.statamic-linkwise" class="text-blue-600 dark:text-blue-400 hover:underline">Settings → Collections</a>.
                            </span>
                        </li>
                        <li>
                            <strong class="text-gray-700 dark:text-gray-300">Run your first Scan Content.</strong>
                            <span class="block ml-5 text-xs">
                                One-click below. Takes ~5–60 seconds depending on entry count.
                            </span>
                        </li>
                        <li>
                            <strong class="text-gray-700 dark:text-gray-300">Browse the Links Report.</strong>
                            <span class="block ml-5 text-xs">
                                See per-entry inbound/outbound link counts and click the Suggestion badges to add internal links.
                            </span>
                        </li>
                    </ol>

                    <div class="text-center">
                        <Button v-if="!rebuilding" @click="rebuildIndex" variant="primary" text="Scan Now" />
                    </div>

                    <!--
                        Docs + support pointer. Most editors will return
                        to this page on their first uncertain moment — a
                        single visible link to the documentation site
                        keeps the question funnel out of the support
                        inbox. Once the docs subdomain is live, swap
                        the GitHub README link below for the canonical
                        docs URL.
                    -->
                    <div v-if="!rebuilding" class="mt-8 pt-6 border-t border-gray-100 dark:border-gray-700/50 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Need help getting set up?
                            <a href="https://github.com/arturrossbach/statamic-linkwise#readme" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline">Read the docs</a>
                            ·
                            <a href="https://github.com/arturrossbach/statamic-linkwise/issues/new?template=question.yml" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline">Ask on GitHub</a>
                            ·
                            <a href="mailto:linkwise.support@gmail.com?subject=Linkwise%20setup%20question" class="text-blue-600 dark:text-blue-400 hover:underline">Email support</a>
                        </p>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Tab Navigation + Content -->
        <div v-else>
            <!--
                Exec-availability warning. Renders above the tab nav on every
                page when the server's PHP has disabled `exec()` and/or
                `proc_open()` via `disable_functions`. Without these, all
                bulk-job operations (Scan Content, Check Links, Bulk
                Unlink, Apply Rule, URL Changer Apply, Inbound/Outbound
                Insert) silently no-op — the button click reaches the
                server, BulkJobDispatcher's exec() call returns false,
                JobLock writes a 'starting' status that never advances,
                and the user sees a Scan Content button that does nothing.

                Session-dismissable (NOT persisted): the underlying
                hosting restriction doesn't go away on its own; we want
                to remind the editor every fresh session.
            -->
            <Alert
                v-if="showExecWarning"
                variant="error"
                heading="Linkwise can't run background jobs on this server"
                class="mb-4"
            >
                <p class="text-sm">
                    Your PHP installation has disabled <code class="text-xs px-1 py-0.5 rounded bg-red-100 dark:bg-red-900/30">{{ execWarningDisabledList }}</code> via the <code class="text-xs px-1 py-0.5 rounded bg-red-100 dark:bg-red-900/30">disable_functions</code> ini directive. Linkwise needs these to dispatch background jobs for <strong>Scan Content, Check Links, Bulk Unlink, Apply Rule</strong>, the <strong>URL Changer</strong>, and <strong>Inbound/Outbound Insert</strong>. Those features will not work until your hosting provider enables them — typically by upgrading from shared to managed or VPS hosting.
                </p>
                <p class="text-xs text-red-700 dark:text-red-400 mt-2">
                    Single-entry actions (creating individual links from the entry editor, custom keywords) continue to work normally.
                </p>
                <div class="mt-3 flex gap-2">
                    <Button text="Dismiss for this session" size="sm" @click="execWarningDismissed = true" />
                </div>
            </Alert>

            <!-- Tab nav: horizontally scrollable on narrow viewports so the
                 7 tabs never wrap into a multi-row stack (which fights the
                 border-bottom indicator) and never trigger page-level
                 horizontal scroll. Each tab label is whitespace-nowrap so
                 multi-word labels (Auto-Linking, Custom Keywords, URL Changer)
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

            <!-- Persistent-notifications accordion — extracted to
                 NotificationsAccordion.vue in Sprint 5 PR 3d. State
                 (notificationsCollapsed, interruptedBulk,
                 staleCheckDismissedFor, checkingFromBanner) stays here
                 because async paths outside the accordion's mount window
                 read/mutate them (e.g. fireTerminalToast auto-opens via
                 setting notificationsCollapsed=false). Sub-component is a
                 template wrapper with v-model + action emits. -->
            <NotificationsAccordion
                v-if="notificationCount > 0"
                v-model:notifications-collapsed="notificationsCollapsed"
                :notification-count="notificationCount"
                :show-stale-check="false"
                stale-check-title=""
                stale-check-body=""
                :checking-from-banner="false"
                :run-check-disabled="!!activeBulk"
                :show-completion="!!lastCompletion && !activeBulk"
                :completion-banner-variant="completionBannerVariant"
                :completion-banner-label="completionBannerLabel"
                :interrupted-bulk="interruptedBulk"
                :interrupted-bulk-label="interruptedBulkLabel"
                @dismiss-completion="dismissCompletion"
                @dismiss-interrupted-bulk="dismissInterruptedBulk"
            />

            <!-- Active-bulk banner — extracted to BulkStatusBanner.vue in
                 Sprint 5 PR 3c. State (activeBulk + bulkStuck + bulkBannerLabel)
                 stays here; the sub-component is a template wrapper with
                 props/events for cancel + force-clear. -->
            <BulkStatusBanner
                :active-bulk="activeBulk"
                :bulk-stuck="bulkStuck"
                :bulk-banner-label="bulkBannerLabel"
                :bulk-stale-seconds="bulkStaleSeconds"
                :cancelling="cancelling"
                :force-clearing="forceClearing"
                @cancel="cancelBulk"
                @force-clear="forceClearBulk"
            />

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
import { Head, Link, router as inertiaRouter } from '@statamic/cms/inertia';
import { Header, Card, Button, Alert, Icon, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, ConfirmationModal } from '@statamic/cms/ui';
import BulkStatusBanner from './BulkStatusBanner.vue';
import NotificationsAccordion from './NotificationsAccordion.vue';
import { bulkState, setHeavyState, cancelActive, getInterruptedBulk, clearInterruptedBulk, recordCompletion, getLastCompletion, clearLastCompletion } from '../services/bulkOperationService.js';
import { activeLabel, shortLabel, completionLabel, completionVariant } from '../services/bulkLabels.js';
import { buildCompletionSignature, isCompletionStale } from '../services/bulkSignature.js';
import { pickTerminalReload } from '../services/bulkTerminalReload.js';
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
    components: { Head, Link, Header, Card, Button, Alert, Icon, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, ConfirmationModal, BulkStatusBanner, NotificationsAccordion },

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
        // entire accordion when nothing is pending. The stale-check signal
        // was removed 2026-05-22 (user-direction "der nervt einfach, weg
        // damit") — it kept resurfacing for low-risk index updates the
        // user already planned to recheck on their own cadence.
        notificationCount() {
            let n = 0;
            if (this.lastCompletion && !this.activeBulk) n++;
            if (this.interruptedBulk) n++;
            return n;
        },

        /**
         * Exec-availability payload distributed by StaleCheckPresenter
         * alongside the stale-check signal. Reads `available` + the
         * individual primitive booleans + the disable_functions list
         * so the banner can render a specific remediation message.
         *
         * Linkwise's bulk-job pipeline (Scan Content, Check Links,
         * Bulk Unlink, Apply Rule, URL Changer Apply, Inbound/Outbound
         * Insert) dispatches via `exec("$php $artisan ... &")`. Shared
         * hosts that disable `exec()` / `proc_open()` via ini
         * `disable_functions` make those buttons silently no-op:
         * the dispatch returns false, the JobLock writes "starting"
         * status, the frontend polls forever. The banner makes the
         * constraint visible up-front instead.
         */
        execAvailability() {
            return this.$page?.props?.execAvailability || null;
        },

        showExecWarning() {
            if (this.execWarningDismissed) return false;

            return this.execAvailability !== null && this.execAvailability.available === false;
        },

        execWarningDisabledList() {
            const fns = this.execAvailability?.disabled_functions || [];
            if (fns.length === 0) {
                return 'exec, proc_open';
            }

            return fns.join(', ');
        },

        // staleCheck signal removed 2026-05-22. Banner was nagging editors
        // for every index rebuild even when they planned a manual broken-
        // link recheck on their own cadence. See notificationCount above.

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
            // Exec-availability warning dismiss state — session-scoped
            // (NOT persistent like staleCheck). Reason: the underlying
            // hosting restriction doesn't go away on its own; if a user
            // dismisses it once we still want to remind them next session
            // because the consequence (broken Scan Content button) is
            // far worse than re-displaying the warning.
            execWarningDismissed: false,
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
                { name: 'keywords', label: 'Custom Keywords', url: this.route('linkwise.keywords') },
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

    watch: {
        // Persist notifications-accordion open/collapsed state to
        // sessionStorage. Triggers on BOTH the v-model update from
        // NotificationsAccordion (user clicked summary) AND the
        // imperative `notificationsCollapsed = false` in fireTerminalToast
        // (auto-open on new completion). The old <details>-@toggle handler
        // only covered the first path — the watcher unifies them.
        // Sprint 5 PR 3d.
        notificationsCollapsed(collapsed) {
            writeString('linkwise:notifications:collapsed', collapsed ? '1' : '0');
        },
    },

    methods: {
        // runStaleCheck / dismissStaleCheck removed 2026-05-22 along with
        // the stale-check banner. Notifications accordion still pinned to
        // false for show-stale-check so the inner Alert never renders.

        // handleNotificationsToggle removed in Sprint 5 PR 3d — the
        // sub-component's v-model emit + the `notificationsCollapsed`
        // watcher below handle the toggle + persistence.

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
            // Optimistic UI: flip `rebuilding` (= activeBulk.kind==='scan')
            // immediately so the button shows its loading spinner the moment
            // the user clicks, not 5–7s later when the status-poller picks
            // up the server-side 'starting' phase. On slow hosts (Cloudways
            // with disable_functions-residual overhead) the exec() call to
            // dispatch the artisan command can take that long before the
            // first status write lands — the user previously thought their
            // click hadn't registered. Set the active state to a placeholder
            // 'starting' snapshot; the next pollBulkStatusOnce replaces it
            // with the real server status as soon as the bulk writes its
            // first heartbeat.
            setHeavyState({
                kind: 'scan',
                label: 'Scan Content',
                current: 0,
                total: 0,
                canCancel: false,
                cancelUrl: null,
                context: { phase: 'starting' },
            });
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
                    setHeavyState(null);
                    return;
                }
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.error(`Could not scan content: ${data?.error || data?.message || `HTTP ${response.status}`}`);
                    setHeavyState(null);
                    return;
                }

                // Trigger an immediate poll so the banner shows up without 1s lag.
                this.pollBulkStatusOnce();
            } catch (error) {
                Statamic.$toast.error(`Could not scan content: ${error.message || 'network error'}`);
                setHeavyState(null);
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
                        // succeeded/skipped surface during 'indexing' so the
                        // banner can render "5 done, 0 skipped — finalizing".
                        // bulkStatus controller maps full cache to `extra`,
                        // so prefer the explicit top-level fields first,
                        // fallback to extra. Real bug 2026-05-11.
                        succeeded: status.succeeded ?? extra.succeeded ?? undefined,
                        skipped: status.skipped ?? extra.skipped ?? undefined,
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

                // Signature construction + stale detection are extracted to
                // `services/bulkSignature.js` (Sprint 5 PR 3a). The 5+
                // documented bugs in the kind-specific signature truth-table
                // are pinned by `tests/Vue/services/bulkSignature.test.js`.
                const tExtra = status.extra || {};
                const signature = buildCompletionSignature(status);
                const SEEN_KEY = 'linkwise:bulkToastShown';
                if (readString(SEEN_KEY) === signature) return;
                writeString(SEEN_KEY, signature);

                if (! isCompletionStale(tExtra.heartbeat || 0, Date.now() / 1000)) {
                    this.fireTerminalToast(status);
                }

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

                // Terminal-reload decision is extracted to
                // `services/bulkTerminalReload.js` (Sprint 5 PR 3b). The
                // truth-table — including the `seenRunning` stale-cache
                // guard against infinite reload loops — is pinned by
                // `tests/Vue/services/bulkTerminalReload.test.js`.
                //
                // IMPORTANT ordering: clear `seenRunning[kind]` BEFORE
                // calling reload(). Otherwise a re-poll of the same
                // terminal status before the page actually navigates
                // away would re-fire the helper → second reload → loop.
                const reloadAction = pickTerminalReload(status, this.seenRunning);
                if (reloadAction !== 'none') {
                    this.seenRunning[status.kind] = false;
                    if (reloadAction === 'full') {
                        // scan / check: refresh server-side props (summary,
                        // staleCheck, index_built_at) — needs a hard reload.
                        window.location.reload();
                    } else if (reloadAction === 'partial') {
                        // inbound/outbound: Inertia partial reload preserves
                        // the Vue tree so the success toast + completion-banner
                        // survive (bug 2026-05-11: window.location.reload()
                        // killed the toast mid-render).
                        inertiaRouter.reload({ preserveState: true, preserveScroll: true });
                    }
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

            // Force-open the notifications <details> on EVERY completion —
            // the persistent recap banner is the only durable confirmation
            // the user has that their action landed. Bug 2 (2026-05-11):
            // the previous guard skipped success-completions, so a user
            // who had once collapsed notifications saw NO toast (Vue render
            // tick was killed by window.location.reload) AND no banner
            // (sitting inside the collapsed disclosure). Even on success
            // we now expand — user can re-collapse if they want.
            this.notificationsCollapsed = false;
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
