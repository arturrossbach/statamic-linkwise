<template>
    <div>
        <!-- Intro -->
        <Card class="mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Auto-Linking Rules</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                        Define keyword-to-URL rules. When applied, Linkwise scans all entries and inserts links wherever the keyword appears.
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">
                        <strong class="text-gray-700 dark:text-gray-300">When to use Auto-Linking vs Suggestions:</strong>
                        Use <strong>Auto-Linking</strong> for terms you always want linked the same way — brand names, products, recurring concepts. Use the <strong>Links Report → Suggestions</strong> for context-aware, one-by-one link recommendations driven by content similarity.
                    </p>
                </div>
                <HelpIcon tooltip="Auto-linking saves time for repeated links. Multi-word keywords like 'Redis Setup' are matched as a phrase." />
            </div>
        </Card>

        <!-- Create / Edit Rule Form — extracted to RuleForm.vue in Sprint 5 PR 2c.
             Variante A: state stays here (newRule/linkMode/selectedEntry), the
             sub-component is a template wrapper with props/events. Parent calls
             `this.$refs.ruleForm?.openEntrySelector()` to trigger the picker. -->
        <RuleForm
            ref="ruleForm"
            :editing-rule="editingRule"
            :new-rule="newRule"
            v-model:link-mode="linkMode"
            :selected-entry="selectedEntry"
            :custom-url-valid="customUrlValid"
            :can-create="canCreate"
            :form-dirty="formDirty"
            :collections="data.collections || []"
            :available-locales="data.available_locales || []"
            :auto-apply-on-save-enabled="data.auto_apply_on_save_enabled"
            :relationship-config="relationshipConfig"
            :relationship-item-data-url="relationshipItemDataUrl"
            :relationship-selections-url="relationshipSelectionsUrl"
            :relationship-filters-url="relationshipFiltersUrl"
            @submit="saveRule"
            @cancel="cancelEdit"
            @entry-picked="onEntryPicked"
        />

        <!-- Rules Header -->
        <div class="flex flex-wrap items-center justify-between gap-3 gap-y-2 mb-4" v-if="rules.length > 0">
            <div class="flex flex-wrap items-center gap-3 gap-y-2">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search rules..."
                    class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5 w-48"
                />
                <span class="text-xs text-gray-400">{{ filteredRules.length }} rule(s)</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 gap-y-2">
                <template v-if="selectedRules.length > 0">
                    <Button @click="confirmApplySelected" :loading="applyingAll" :text="`Apply Selected (${selectedRules.length})`" icon="sync" />
                    <Button v-if="selectedHasActive" @click="bulkSetActive(false)" :text="`Ignore (${selectedActiveCount})`" variant="default" />
                    <Button v-if="selectedHasInactive" @click="bulkSetActive(true)" :text="`Activate (${selectedInactiveCount})`" variant="default" />
                    <Button @click="confirmBulkDelete" :text="`Delete (${selectedRules.length})`" variant="danger" />
                </template>
                <template v-else>
                    <Button v-if="data.urls.import" @click="$refs.importFileInput.click()" :loading="importing" text="Import CSV" icon="upload" v-tooltip="'Bulk-create rules from a CSV file (round-trip with Export)'" />
                    <Button v-if="data.urls.export" @click="exportRules" text="Export CSV" icon="download" v-tooltip="'Download all rules as CSV'" />
                </template>
                <input type="file" ref="importFileInput" accept=".csv,text/csv" class="hidden" @change="handleImportFile" />
            </div>
        </div>

        <!-- Import-result Modal -->
        <Modal v-if="importResult" :open="importResult !== null" @update:open="importResult = null" title="CSV Import Result">
            <div class="text-sm space-y-2">
                <p>
                    <strong class="text-green-600 dark:text-green-400">{{ importResult.created }}</strong> rule(s) created.
                    <template v-if="importResult.skipped > 0">
                        <strong class="text-amber-600 dark:text-amber-400">{{ importResult.skipped }}</strong> skipped.
                    </template>
                </p>
                <ul v-if="importResult.errors && importResult.errors.length" class="mt-2 text-xs text-gray-500 dark:text-gray-400 list-disc list-inside max-h-60 overflow-y-auto">
                    <li v-for="(err, idx) in importResult.errors" :key="idx">{{ err }}</li>
                </ul>
                <p v-if="importResult.errors_truncated" class="text-xs italic text-gray-400">
                    More errors omitted — only the first 50 are shown.
                </p>
            </div>
            <template #footer>
                <Button text="Close" @click="importResult = null" />
            </template>
        </Modal>

        <!-- Empty State -->
        <div v-if="rules.length === 0" class="py-8 text-center text-gray-400 text-sm">
            No auto-link rules yet. Create one above.
        </div>

        <!-- Rules Table — extracted to RuleListTable.vue (Sprint 5 PR 2d).
             Variante A: state stays here (sortedRules/selectedRules/sortField
             pre-computed in this orchestrator); the sub-component is a pure
             template wrapper with props down + events up. The per-row DOM-ref
             bag moved with the table; parent's scrollToRule() reaches it via
             $refs.ruleListTable.getRowRef(id) (Option B — single reader). -->
        <RuleListTable
            v-else
            ref="ruleListTable"
            :sorted-rules="sortedRules"
            v-model:selected-rules="selectedRules"
            :sort-field="sortField"
            :sort-direction="sortDirection"
            :all-selected="allSelected"
            :applying-all="applyingAll"
            :apply-async-rule-id="applyAsyncRuleId"
            :editing-rule="editingRule"
            :form-dirty="formDirty"
            :entries="entries"
            :now-tick="nowTick"
            @toggle-select-all="toggleSelectAll"
            @toggle-sort="toggleSort"
            @preview-rule="previewRule"
            @apply-rule="applyRule"
            @edit-rule="editRule"
            @toggle-active="toggleActive"
            @confirm-delete="confirmDelete"
        />

        <!-- Preview Modal — extracted to RulePreviewModal.vue in Sprint 5 PR 2e.
             Variante A (refined): previewModal + selection pools (excludedEntryIds,
             selectedUnlinkIds) stay here because async paths (previewRule,
             applyFromPreview, unlinkSelectedFromPreview) read them outside the
             modal's mount window. Pure view-state (sort/filter/derived counts)
             lives inside the child.  Actions are no-arg emits — the parent
             already knows previewModal.ruleId + the selection pools. -->
        <RulePreviewModal
            :preview-modal="previewModal"
            v-model:excluded-entry-ids="excludedEntryIds"
            v-model:selected-unlink-ids="selectedUnlinkIds"
            :unlinking-from-preview="unlinkingFromPreview"
            :applying-preview="applyingPreview"
            @close="closePreviewModal"
            @apply="applyFromPreview"
            @unlink="unlinkSelectedFromPreview"
        />

        <!-- Delete Confirmation -->
        <ConfirmationModal
            :open="deleteConfirm !== null"
            @update:open="deleteConfirm = null"
            @confirm="executeDelete"
            @cancel="deleteConfirm = null"
            title="Delete Auto-Link Rule"
            body-text="This removes the rule. Links already inserted in entries will remain and must be removed manually."
            button-text="Delete Rule"
            :danger="true"
        />

        <!-- Bulk Delete Confirmation -->
        <ConfirmationModal
            :open="bulkDeleteConfirm"
            @update:open="bulkDeleteConfirm = false"
            @confirm="executeBulkDelete"
            @cancel="bulkDeleteConfirm = false"
            title="Delete selected rules?"
            :body-text="`This removes ${selectedRules.length} rule(s). Links already inserted in entries will remain and must be removed manually.`"
            button-text="Delete Selected"
            :danger="true"
        />

        <!-- Apply Confirmation — protects against accidental mass-insert -->
        <ConfirmationModal
            :open="applyConfirm !== null"
            @update:open="applyConfirm = null"
            @confirm="executeApply"
            @cancel="applyConfirm = null"
            title="Apply auto-link rules?"
            :body-text="applyConfirmBodyText"
            button-text="Apply"
            :busy="applyingAll"
        />
    </div>
</template>

<script>
import { router as inertiaRouter } from '@statamic/cms/inertia';
import { Card, Button, ConfirmationModal, Modal, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Alert, Icon, Badge } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import MultiSelect from '../shared/MultiSelect.vue';
import RuleForm from './RuleForm.vue';
import RuleListTable from './RuleListTable.vue';
import RulePreviewModal from './RulePreviewModal.vue';
import { sortableMixin } from '../shared/sortable.js';
import { isValidReplacementUrl } from '../../utils/urlValidation.js';
import { isFormDirty } from '../../utils/formDirty.js';
import {
    truncateUrl,
    formatAutoApply,
    normalizeAutoApply,
    formatExactDate,
    wouldLinkForRule,
} from '../../utils/ruleFormatting.js';
import { bulkState, setHeavyState } from '../../services/bulkOperationService.js';

export default {
    components: { Card, Button, ConfirmationModal, Modal, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Alert, Icon, Badge, HelpIcon, MultiSelect, RuleForm, RuleListTable, RulePreviewModal },

    mixins: [sortableMixin],

    props: {
        data: { type: Object, required: true },
        entries: { type: Array, default: () => [] },
    },

    data() {
        return {
            // Deep-clone so we can mutate rule fields (links_added, linked_count, active)
            // without hitting the readonly Inertia prop proxy.
            rules: JSON.parse(JSON.stringify(this.data?.rules || [])),
            selectedRules: [],
            searchQuery: '',
            // Default newest-first so freshly created rules sit at the top.
            // User can switch to alphabetical via the Keyword column header.
            sortField: 'created_at',
            sortDirection: 'desc',
            // Preview-modal view-state (sort field/direction + status filter)
            // lives inside RulePreviewModal.vue — see Sprint 5 PR 2e.
            applyingAll: false,
            previewModal: null,
            // IDs of entries the user unchecked in the Preview modal — Apply skips these.
            excludedEntryIds: [],
            // IDs of entries the user CHECKED for bulk-unlink. Separate from
            // excludedEntryIds because the two actions operate on disjoint
            // status pools (would_link vs linked_to_target) and a single
            // selection set with overlapping semantics confused users in
            // testing.
            selectedUnlinkIds: [],
            unlinkingFromPreview: false,
            applyingPreview: false,
            // Async apply progress — survives tab switch / reload via cache-backed polling.
            applyAsyncProgress: null, // null | { rule_keyword, current, total }
            applyAsyncRuleId: null,
            applyAsyncPollTimer: null,
            applyAsyncResult: null, // shown briefly after completion
            // ruleRowRefs moved to RuleListTable.vue (Sprint 5 PR 2d, Option B):
            // child owns the per-row DOM-ref bag, scrollToRule() reaches it via
            // $refs.ruleListTable.getRowRef(id).
            deleteConfirm: null,
            bulkDeleteConfirm: false,
            // Reactive minute-clock for "Last applied: X ago" labels.
            nowTick: Date.now(),
            nowTickTimer: null,
            importing: false,
            importResult: null,
            applyConfirm: null, // { mode: 'all' | 'selected' }
            editingRule: null,
            // Snapshot of the form when entering edit mode — used to detect unsaved changes.
            editingRuleSnapshot: null,
            editingLinkModeSnapshot: null,
            linkMode: 'entry',
            selectedEntry: null,
            newRule: this.emptyRule(),
        };
    },

    computed: {
        entryHashes() {
            const hashes = {};
            for (const e of this.entries) {
                if (e.content_hash) hashes[e.id] = e.content_hash;
            }
            return hashes;
        },
        // True when the form is in edit mode AND the user has made unsaved changes.
        // Compares the live form state to the snapshot taken when edit started.
        // Logic extracted to {@see resources/js/utils/formDirty.js} during
        // REV-FE-01 Phase B — same dirty-tracking semantics will be reused
        // by URL Changer + Target Keyword edit-modals as they grow.
        formDirty() {
            if (!this.editingRule) return false;
            return isFormDirty(
                this.editingRuleSnapshot,
                this.newRule,
                this.editingLinkModeSnapshot,
                this.linkMode,
            );
        },

        canCreate() {
            if (!this.newRule.keyword.trim()) return false;
            if (this.linkMode === 'entry') return this.selectedEntry !== null;
            return this.customUrlValid;
        },

        // Bulk-action helpers — surface the active/inactive split inside the
        // current selection so the toolbar shows e.g. "Activate (3)" only when
        // there are inactive rules to activate.
        selectedActiveCount() {
            return this.rules.filter(r => this.selectedRules.includes(r.id) && r.active).length;
        },
        selectedInactiveCount() {
            return this.selectedRules.length - this.selectedActiveCount;
        },
        selectedHasActive() {
            return this.selectedActiveCount > 0;
        },
        selectedHasInactive() {
            return this.selectedInactiveCount > 0;
        },

        /**
         * Validates the Custom URL input. Same rules as the Broken Links tab —
         * actually the SAME implementation since REV-FE-01 Phase B: both flows
         * delegate to {@see resources/js/utils/urlValidation.js#isValidReplacementUrl}.
         * Removes the duplicate body that lived inline since the AutoLink tab
         * shipped, eliminating drift risk between the two surfaces.
         */
        customUrlValid() {
            return isValidReplacementUrl(this.newRule.url);
        },

        applyConfirmBodyText() {
            if (!this.applyConfirm) return '';
            // Aggregate totals from the selected rules so the user sees what's about to happen,
            // not just a raw rule count. This is the "Apply Selected" preview-summary.
            const selectedActive = this.rules.filter(r => this.selectedRules.includes(r.id) && r.active);
            const inactiveCount = this.selectedRules.length - selectedActive.length;
            const totalNewLinks = selectedActive.reduce((s, r) => s + wouldLinkForRule(r), 0);
            const ruleWord = selectedActive.length === 1 ? 'rule' : 'rules';
            const linkWord = totalNewLinks === 1 ? 'link' : 'links';
            const inactiveSuffix = inactiveCount > 0
                ? ` (${inactiveCount} ignored ${inactiveCount === 1 ? 'rule is' : 'rules are'} skipped)`
                : '';
            return `Apply ${selectedActive.length} active ${ruleWord} — about ${totalNewLinks} new ${linkWord} will be inserted${inactiveSuffix}. Inserted links stay in the entries even if you later delete the rule.`;
        },

        // Preview-modal computeds (availablePreviewStatusOptions, sortedPreviewItems,
        // groupedPreview, wouldLinkCount, togglablePreview*, somePreview*,
        // allPreview*, linkedToTarget/Elsewhere/NotInsertableCount,
        // applyablePreviewCount) all moved to RulePreviewModal.vue in Sprint 5
        // PR 2e — none are read by the parent's async paths.

        allSelected() {
            return this.filteredRules.length > 0 && this.filteredRules.every(r => this.selectedRules.includes(r.id));
        },

        filteredRules() {
            if (!this.searchQuery.trim()) return this.rules;
            const q = this.searchQuery.toLowerCase();
            return this.rules.filter(r =>
                r.keyword.toLowerCase().includes(q) || r.url.toLowerCase().includes(q)
            );
        },

        sortedRules() {
            const numeric = ['match_count', 'linked_count'];
            const dir = this.sortDirection === 'asc' ? 1 : -1;
            const field = this.sortField;
            return [...this.filteredRules].sort((a, b) => {
                if (field === 'will_link_count') {
                    return (wouldLinkForRule(a) - wouldLinkForRule(b)) * dir;
                }
                const aVal = a[field];
                const bVal = b[field];
                if (numeric.includes(field)) return ((aVal ?? 0) - (bVal ?? 0)) * dir;
                return String(aVal ?? '').localeCompare(String(bVal ?? '')) * dir;
            });
        },

        // Relationship-input config: all collections, pick exactly one entry.
        relationshipConfig() {
            return {
                type: 'entries',
                collections: [],
                max_items: 1,
            };
        },

        configParameter() {
            // utf8btoa: base64-encode while correctly handling multi-byte characters.
            // Not exposed as a Vue global in the addon context, so inline it.
            return btoa(unescape(encodeURIComponent(JSON.stringify(this.relationshipConfig))));
        },

        relationshipItemDataUrl() {
            return this.cp_url('fieldtypes/relationship/data') + '?' + new URLSearchParams({ config: this.configParameter }).toString();
        },

        relationshipSelectionsUrl() {
            return this.cp_url('fieldtypes/relationship') + '?' + new URLSearchParams({ config: this.configParameter }).toString();
        },

        relationshipFiltersUrl() {
            return this.cp_url('fieldtypes/relationship/filters') + '?' + new URLSearchParams({ config: this.configParameter }).toString();
        },
    },

    mounted() {
        // Attach to any in-flight async-apply job started in a previous tab/session.
        // Without this, the user reloads, sees a fresh page, but the background apply
        // is still running silently — they wouldn't know it finished.
        this.pollApplyAsyncStatusOnce();
        // Tick the clock once per minute so "Last applied: 2 minutes ago" stays
        // accurate without a manual refresh.
        this.nowTickTimer = setInterval(() => { this.nowTick = Date.now(); }, 60000);
    },

    beforeUnmount() {
        this.stopApplyAsyncPolling();
        if (this.nowTickTimer) clearInterval(this.nowTickTimer);
    },

    // No `watch` section needed for `this.rules` re-sync — the parent
    // AutoLinkPage uses `:key="renderKey"` (Vue idiom) which forces a
    // full remount of this component whenever the Inertia partial-
    // reload updates `autolinkData`. `data()` runs fresh on remount,
    // so the deep-clone happens with the new prop in scope. No watcher
    // overlap, single source of truth (User-Smoke 2026-05-19 confirmed
    // — watch.data was unreliable, :key remount works reliably).

    methods: {
        toggleSelectAll() {
            if (this.allSelected) {
                this.selectedRules = [];
            } else {
                this.selectedRules = this.filteredRules.map(r => r.id);
            }
        },

        confirmApplySelected() {
            if (this.selectedRules.length === 0) return;
            this.applyConfirm = { mode: 'selected' };
        },

        async executeApply() {
            this.applyConfirm = null;
            await this.applySelected();
        },

        /**
         * Apply Selected — single heavy job for ALL selected rules.
         *
         * Replaces the previous frontend-loop approach (which 409'd intermediate
         * rules due to bulkState propagation lag) with the same pattern the URL
         * Changer uses: one POST, server iterates internally, single banner with
         * nested progress (rule X of Y), single cancel, single terminal toast.
         */
        async applySelected() {
            if (this.selectedRules.length === 0) return;
            if (bulkState.active) {
                Statamic.$toast.info('Another bulk operation is running. Wait for it to finish.');
                return;
            }
            const url = this.data.urls.apply_selected_async;
            if (!url) {
                Statamic.$toast.error('Apply Selected (async) endpoint not configured.');
                return;
            }

            this.applyingAll = true;
            const ruleIds = [...this.selectedRules];

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        rule_ids: ruleIds,
                        entry_hashes: this.entryHashes,
                    }),
                });
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    if (response.status === 409) {
                        Statamic.$toast.error(data?.message || 'Entry was modified by another editor — reload and try again.');
                    } else {
                        const reason = data?.message || data?.error || `HTTP ${response.status}`;
                        Statamic.$toast.error(`Could not start: ${reason}`);
                    }
                    this.applyingAll = false;
                    return;
                }
            } catch (e) {
                Statamic.$toast.error(`Could not start: ${e.message || 'network error'}`);
                this.applyingAll = false;
                return;
            }

            // Immediate confirmation that the dispatch landed — covers the
            // case where the user navigates away before the terminal toast
            // fires (which was happening: tab-switch unmounted the layout's
            // poller before it could observe 'done').
            Statamic.$toast.success(`Started — applying ${ruleIds.length} rule${ruleIds.length === 1 ? '' : 's'} in the background.`);

            // Surface global banner immediately. Layout poller takes over
            // updating ruleIndex / current / total within 1.5s.
            setHeavyState({
                kind: 'applyrule',
                label: 'auto-link apply',
                current: 0,
                total: 0,
                canCancel: true,
                cancelUrl: this.data.urls.apply_async_cancel || null,
                context: { totalRules: ruleIds.length, ruleIndex: 0, ruleKeyword: '' },
            });

            // Wait for the heavy job to finish (success/cancel/error all clear
            // bulkState). Then refresh page data — link counts on every rule
            // changed, and `wouldLinkForRule()` reads from `data.entries[]` so
            // a stale entries-prop leaves "Apply (N)" buttons showing pre-apply
            // counts. Sister-pattern to BrokenLinksTab:708.
            //
            // Pre-fix this branch called `this.fetchData()` which was NEVER
            // defined anywhere — `typeof === 'function'` silently no-op'd
            // since the AutoLinking-Tab was extracted. User-Smoke 2026-05-17
            // hidden 4th sister of Klasse 7 — fixed alongside the
            // unlinkSelectedFromPreview gap below.
            const stop = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    if (previous?.kind === 'applyrule' && current === null) {
                        stop();
                        this.selectedRules = [];
                        this.applyingAll = false;
                        // Inertia partial-reload — parent's :key="renderKey"
                        // watch on autolinkData/entries re-mounts this
                        // component so data() runs fresh. See single-
                        // rule path for full rationale.
                        inertiaRouter.reload({
                            only: ['autolinkData', 'entries'],
                            preserveScroll: true,
                        });
                    }
                },
            );
            // Safety: stop watcher after 30 minutes.
            setTimeout(() => stop(), 30 * 60 * 1000);
        },

        emptyRule() {
            return { keyword: '', url: '', collections: [], locales: [], once_per_post: true, skip_if_exists: false, case_sensitive: false, auto_apply_on_save: 'follow_global' };
        },

        openEntrySelector() {
            // entryPicker ref now lives inside RuleForm (Sprint 5 PR 2c).
            // Bridge through the sub-component method so the parent's
            // edit-from-row flow (editRule → openEntrySelector) still works.
            this.$refs.ruleForm?.openEntrySelector();
        },

        /**
         * Handles selection from the Statamic relationship-input modal.
         * Payload is an array of entry data objects; we take the first
         * (max_items=1) and mirror it into selectedEntry + newRule.url.
         */
        onEntryPicked(data) {
            if (!Array.isArray(data) || data.length === 0) return;
            const picked = data[0];
            this.selectedEntry = {
                id: picked.id,
                title: picked.title,
                collection: picked.collection?.handle || picked.collection || '',
            };
            this.newRule.url = 'statamic://entry::' + picked.id;
        },

        /**
         * True if this rule is the one currently being edited AND the form has unsaved
         * changes. Used to disable the row's Preview/Apply buttons since those would
         * operate on the persisted rule (not the unsaved edits) — confusing for the user.
         */
        isRuleEditingAndDirty(rule) {
            return this.editingRule && this.editingRule.id === rule.id && this.formDirty;
        },

        // wouldLinkForRule + truncateUrl + formatAutoApply + normalizeAutoApply
        // + formatExactDate registered below as shorthand imports from
        // utils/ruleFormatting.js (Sprint 5 PR 2d-prep, analog to PR #24's
        // formDirty.js extract).

        findEntryTitle(entryId) {
            return this.entries.find(e => e.id === entryId)?.title || null;
        },

        editRule(rule) {
            this.editingRule = rule;
            this.newRule = {
                keyword: rule.keyword,
                url: rule.url,
                collections: Array.isArray(rule.collections) ? [...rule.collections] : [],
                locales: Array.isArray(rule.locales) ? [...rule.locales] : [],
                once_per_post: rule.once_per_post,
                skip_if_exists: rule.skip_if_exists,
                case_sensitive: rule.case_sensitive,
                auto_apply_on_save: normalizeAutoApply(rule.auto_apply_on_save),
            };
            // Snapshot the form's starting state so we can detect unsaved edits.
            this.editingRuleSnapshot = JSON.parse(JSON.stringify(this.newRule));

            if (rule.target_entry_id) {
                this.linkMode = 'entry';
                this.selectedEntry = this.entries.find(e => e.id === rule.target_entry_id) || null;
            } else {
                this.linkMode = 'url';
                this.selectedEntry = null;
            }
            this.editingLinkModeSnapshot = this.linkMode;

            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        cancelEdit() {
            this.editingRule = null;
            this.editingRuleSnapshot = null;
            this.editingLinkModeSnapshot = null;
            this.newRule = this.emptyRule();
            this.selectedEntry = null;
        },

        async saveRule() {
            if (!this.canCreate) return;

            if (this.editingRule) {
                await this.updateExistingRule();
            } else {
                await this.createNewRules();
            }
        },

        scrollToRule(ruleId) {
            if (!ruleId) return;
            this.$nextTick(() => {
                // Row refs moved to RuleListTable.vue (Sprint 5 PR 2d, Option B).
                // Bridge: child exposes getRowRef(id); returns undefined if the
                // rule isn't currently in sortedRules (e.g. searchQuery filter)
                // — we no-op gracefully in that case.
                const el = this.$refs.ruleListTable?.getRowRef(ruleId);
                if (el && typeof el.scrollIntoView === 'function') {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        },

        async createNewRules() {
            // Multi-keyword: split by newlines
            const keywords = this.newRule.keyword.split('\n').map(k => k.trim()).filter(k => k.length > 0);

            let created = 0;
            const createdIds = [];
            for (const keyword of keywords) {
                try {
                    const ruleData = { ...this.newRule, keyword };
                    const response = await this.fetch(this.data.urls.store, 'POST', ruleData);
                    if (response.success) {
                        this.rules.push(response.rule);
                        createdIds.push(response.rule.id);
                        created++;
                    }
                } catch (e) {
                    Statamic.$toast.error(`Could not create rule "${keyword}": ${e.message}`);
                }
            }

            if (created > 0) {
                Statamic.$toast.success(`${created} rule(s) created.`);
                this.newRule = this.emptyRule();
                this.selectedEntry = null;
                // Highlight + scroll to the first newly created row so the user
                // can find it once the sort moves it to its alphabetical position.
                if (createdIds.length > 0) this.scrollToRule(createdIds[0]);
            }
        },

        async updateExistingRule() {
            try {
                const url = this.data.urls.store.replace('/rules', `/rules/${this.editingRule.id}`);
                const response = await this.fetch(url, 'PUT', this.newRule);
                if (response.success) {
                    const updatedId = this.editingRule.id;
                    const idx = this.rules.findIndex(r => r.id === updatedId);
                    if (idx !== -1) this.rules[idx] = response.rule;
                    Statamic.$toast.success('Rule updated.');
                    this.cancelEdit();
                    this.scrollToRule(updatedId);
                }
            } catch (e) {
                Statamic.$toast.error(`Could not update rule: ${e.message}`);
            }
        },

        confirmDelete(rule) {
            this.deleteConfirm = rule;
        },

        async executeDelete() {
            const rule = this.deleteConfirm;
            this.deleteConfirm = null;
            if (!rule) return;
            await this.deleteRule(rule);
        },

        async deleteRule(rule) {
            try {
                const url = this.data.urls.store.replace('/rules', `/rules/${rule.id}`);
                await this.fetch(url, 'DELETE');
                this.rules = this.rules.filter(r => r.id !== rule.id);
                Statamic.$toast.success('Rule deleted.');
            } catch (e) {
                Statamic.$toast.error(`Could not delete rule "${rule.keyword}": ${e.message}`);
            }
        },

        confirmBulkDelete() {
            if (this.selectedRules.length === 0) return;
            this.bulkDeleteConfirm = true;
        },

        async executeBulkDelete() {
            const ids = [...this.selectedRules];
            this.bulkDeleteConfirm = false;
            if (ids.length === 0) return;
            try {
                const response = await this.fetch(this.data.urls.bulk_delete, 'POST', { ids });
                this.rules = this.rules.filter(r => !ids.includes(r.id));
                this.selectedRules = [];
                Statamic.$toast.success(`${response.deleted ?? ids.length} rule(s) deleted.`);
            } catch (e) {
                Statamic.$toast.error(`Could not delete rules: ${e.message}`);
            }
        },

        async bulkSetActive(active) {
            const ids = [...this.selectedRules];
            if (ids.length === 0) return;
            try {
                const response = await this.fetch(this.data.urls.bulk_toggle, 'POST', { ids, active });
                // Update local rules so the table reflects the new state without a reload.
                for (const rule of this.rules) {
                    if (ids.includes(rule.id)) rule.active = active;
                }
                const verb = active ? 'activated' : 'ignored';
                Statamic.$toast.success(`${response.changed ?? ids.length} rule(s) ${verb}.`);
            } catch (e) {
                Statamic.$toast.error(`Could not toggle rules: ${e.message}`);
            }
        },

        async toggleActive(rule) {
            try {
                const url = this.data.urls.store.replace('/rules', `/rules/${rule.id}`);
                const response = await this.fetch(url, 'PUT', { active: !rule.active });
                if (response.success) {
                    rule.active = response.rule.active;
                    Statamic.$toast.success(rule.active ? 'Rule activated.' : 'Rule ignored.');
                }
            } catch (e) {
                Statamic.$toast.error(`Could not toggle rule "${rule.keyword}": ${e.message}`);
            }
        },

        applyRule(rule) {
            // Per-row Apply uses the async background path so the UI never freezes
            // on long runs. Same dispatch contract as applyFromPreview.
            return this.dispatchApplyAsync(rule, { entry_hashes: this.entryHashes });
        },

        /**
         * Trigger the async background Apply for a single rule and start polling.
         * Returns once the dispatch was accepted by the server (not when the apply finishes).
         */
        async dispatchApplyAsync(rule, body) {
            // Refuse client-side if any other bulk (light or heavy) is running.
            // Server-side JobLock catches heavy+heavy, but light operations are
            // client-side only — without this gate, starting an apply-rule
            // while a light bulk-insert runs would 409 every per-item HTTP and
            // ruin the light bulk's UX.
            if (bulkState.active) {
                Statamic.$toast.info('Another bulk operation is running. Wait for it to finish.');
                return;
            }

            const url = (this.data.urls.apply_async || '').replace('__ID__', rule.id);
            if (!url) {
                Statamic.$toast.error('Async apply is not configured.');
                return;
            }

            // Set the loading state IMMEDIATELY so the button visually responds
            // to the click. We undo it if dispatch fails.
            this.applyAsyncRuleId = rule.id;
            this.applyAsyncProgress = { rule_keyword: rule.keyword, current: 0, total: 0 };
            this.applyAsyncResult = null;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body || {}),
                });
                if (response.status === 409) {
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.info(data.message || 'Another bulk operation is running. Wait for it to finish.');
                    this.teardownApplyAsync();
                    return;
                }
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const reason = data?.error || data?.message || `HTTP ${response.status}`;
                    Statamic.$toast.error(`Could not start apply: ${reason}`);
                    this.teardownApplyAsync();
                    return;
                }
            } catch (e) {
                Statamic.$toast.error(`Could not start apply: ${e.message || 'network error'}`);
                this.teardownApplyAsync();
                return;
            }

            // Surface the global cross-tab banner IMMEDIATELY. Without this the
            // user sees no banner for short apply-runs because the LinkwiseLayout
            // poller's 1.5s interval is slower than a small-rule apply (<1s),
            // missing the 'running' phase entirely. Layout poller takes over
            // updating current/total when its next tick fires.
            setHeavyState({
                kind: 'applyrule',
                label: 'auto-link apply',
                current: 0,
                total: 0,
                canCancel: true,
                cancelUrl: this.data.urls.apply_async_cancel || null,
                context: { ruleKeyword: rule.keyword },
            });

            this.startApplyAsyncPolling();

            // Belt-and-suspenders refresh: same $watch pattern as
            // unlinkSelectedFromPreview line 1141 + dispatchApplyMultiple
            // line 544. The setInterval-driven reload inside
            // pollApplyAsyncStatusOnce's done branch (line ~941) was
            // unreliable in User-Smoke 2026-05-19 — table didn't refresh.
            // This $watch fires reactively when the global bulkState
            // transitions from 'applyrule' active → null (set by
            // LinkwiseLayout's poller on terminal). It triggers the
            // same inertiaRouter.reload, ensuring AutoLinkPage's
            // :key="renderKey" watch picks up the prop change and
            // re-mounts this tab with fresh data.
            const stopApplyWatcher = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    if (previous?.kind === 'applyrule' && current === null) {
                        stopApplyWatcher();
                        inertiaRouter.reload({
                            only: ['autolinkData', 'entries'],
                            preserveScroll: true,
                        });
                    }
                },
            );
            // Safety: stop watcher after 30 min.
            setTimeout(() => stopApplyWatcher(), 30 * 60 * 1000);
        },

        startApplyAsyncPolling() {
            this.stopApplyAsyncPolling();
            this.applyAsyncPollTimer = setInterval(() => this.pollApplyAsyncStatusOnce(), 1000);
        },

        stopApplyAsyncPolling() {
            if (this.applyAsyncPollTimer) {
                clearInterval(this.applyAsyncPollTimer);
                this.applyAsyncPollTimer = null;
            }
        },

        /**
         * Single source of truth for ending an async-apply lifecycle.
         *
         * User-Smoke 2026-05-17 found two branches in
         * pollApplyAsyncStatusOnce that cleared `applyAsyncProgress` but
         * forgot `applyAsyncRuleId` (idle/unknown branch and `wasActive=
         * false` early-return). Effect: the row's Apply button stayed
         * stuck in loading-state and ALL other rules' Apply buttons
         * stayed greyed out via RuleListTable's
         * `applyAsyncRuleId !== rule.id` gate (RuleListTable.vue:116).
         *
         * Bundling all teardown into one helper means every future exit
         * point gets the full cleanup for free — analog to a `finally`
         * block. See [[architectural_health]] Klasse 9a (per-kind
         * terminal-status shape parity).
         */
        teardownApplyAsync() {
            this.stopApplyAsyncPolling();
            this.applyAsyncProgress = null;
            this.applyAsyncRuleId = null;
        },

        async pollApplyAsyncStatusOnce() {
            const url = this.data.urls.apply_async_status;
            if (!url) return;
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) return;
                const status = await response.json();

                if (status.phase === 'starting' || status.phase === 'running') {
                    this.applyAsyncProgress = {
                        rule_keyword: status.rule_keyword || this.applyAsyncProgress?.rule_keyword || '',
                        current: status.current || 0,
                        total: status.total || 0,
                        records_processed: status.records_processed || 0,
                        records_total: status.records_total || 0,
                        links_added: status.links_added || 0,
                    };
                } else if (status.phase === 'done' || status.phase === 'cancelled') {
                    const wasActive = this.applyAsyncProgress !== null;
                    const linksAdded = status.links_added || 0;
                    const ruleId = status.rule_id;
                    const ruleKeyword = status.rule_keyword || '';
                    const cancelled = status.phase === 'cancelled';

                    // Teardown FIRST — covers both the wasActive=true and
                    // the wasActive=false (stale-done) path. Pre-fix, the
                    // early-return for stale-done left `applyAsyncRuleId`
                    // set and stuck the row's Apply button (User-Smoke
                    // 2026-05-17). teardownApplyAsync() makes that
                    // structurally impossible.
                    this.teardownApplyAsync();

                    if (!wasActive) {
                        // Stale done from a previous session — UI was already
                        // idle, no need to surface a result banner.
                        return;
                    }

                    this.applyAsyncResult = {
                        rule_keyword: ruleKeyword,
                        links_added: linksAdded,
                        cancelled,
                    };

                    // Inertia partial-reload + parent :key remount.
                    // AutoLinkPage watches autolinkData/entries deep
                    // and bumps `renderKey` when they change, which
                    // re-mounts this component so data() runs fresh
                    // with the new props (User-Smoke 2026-05-19: this
                    // was the only reliable refresh path after Vue
                    // nested-prop watch + onSuccess direct-mutation
                    // both failed).
                    inertiaRouter.reload({
                        only: ['autolinkData', 'entries'],
                        preserveScroll: true,
                    });
                } else if (status.phase === 'error') {
                    // Error toast also handled by LinkwiseLayout.
                    this.teardownApplyAsync();
                } else {
                    // idle / unknown phase — server lost the job or TTL
                    // expired. Pre-fix this branch only cleared progress
                    // and left applyAsyncRuleId stuck (User-Smoke 2026-
                    // 05-17). Treat as a soft-terminal: full teardown.
                    this.teardownApplyAsync();
                }
            } catch {
                // ignore transient errors
            }
        },

        async cancelApplyAsync() {
            const url = this.data.urls.apply_async_cancel;
            if (!url) return;
            try {
                await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
            } catch {
                // ignore — the polling will eventually report status anyway
            }
        },

        async previewRule(rule) {
            this.resetPreviewModalState();
            this.previewModal = {
                title: `Preview: "${rule.keyword}"`,
                keyword: rule.keyword,
                ruleId: rule.id,
                loading: true,
                items: [],
            };
            try {
                const url = this.data.urls.store.replace('/rules', `/apply/${rule.id}`);
                const response = await this.fetch(url, 'POST', { preview: true });
                // Enrich with edit_url from entries prop
                const items = (response.affected_entries || []).map(item => ({
                    ...item,
                    edit_url: this.entries.find(e => e.id === item.id)?.edit_url || null,
                }));
                this.previewModal.items = items;
                this.previewModal.loading = false;

                // Sync local hashes with the live values returned by Preview so
                // a subsequent Apply uses fresh state (recovers from a prior 409).
                if (response.entry_hashes) {
                    for (const [eid, hash] of Object.entries(response.entry_hashes)) {
                        const entry = this.entries.find(e => e.id === eid);
                        if (entry) entry.content_hash = hash;
                    }
                }
            } catch (e) {
                this.previewModal = null;
                Statamic.$toast.error(`Could not load preview: ${e.message}`);
            }
        },

        closePreviewModal() {
            this.previewModal = null;
            this.resetPreviewModalState();
        },

        /**
         * Centralized reset for preview-modal selection state. Both
         * previewRule (open path) and closePreviewModal (close path) call
         * this so every modal session starts clean — without it, selecting
         * "Unlink (3)" in rule A and then opening rule B's preview would
         * surface "Unlink (3)" in B's modal even though no row in B is
         * checked. Mirrors DetailModal / SuggestionModal's watch-on-open
         * reset pattern, just imperative since previewModal is not a prop.
         */
        resetPreviewModalState() {
            this.excludedEntryIds = [];
            this.selectedUnlinkIds = [];
            // previewStatusFilter (+ sort field/dir) is owned by
            // RulePreviewModal.vue and resets via its watch on
            // previewModal.ruleId — see Sprint 5 PR 2e.
        },

        // togglePreviewSort / toggleExclude / toggleUnlinkSelection /
        // togglePreviewSelectAll all moved to RulePreviewModal.vue in
        // Sprint 5 PR 2e — they mutate selection pools via v-model emits
        // (update:excludedEntryIds / update:selectedUnlinkIds).

        /**
         * Bulk-remove the rule's link from selected `linked_to_target` entries.
         * Reuses the DetailModal Bulk-Unlink heavy-bulk path 1:1 — same
         * endpoint, same payload contract, same banner. Differences:
         *   - source_mode is 'outbound' (we're removing each entry's
         *     outbound link to the rule's target)
         *   - matched_url is the rule's href (statamic://entry::ID for
         *     internal rules, rule.url for external)
         *   - occurrence_index = 0 because AutoLink enforces once_per_post,
         *     so the rule's own insertion is always the first match
         */
        async unlinkSelectedFromPreview() {
            if (!this.previewModal?.ruleId || this.unlinkingFromPreview) return;
            if (this.selectedUnlinkIds.length === 0) return;

            const rule = this.rules.find(r => r.id === this.previewModal.ruleId);
            if (!rule) return;

            // Build the rule's href the same way the engine does (mirrors
            // AutoLinkRule::isExternal): statamic://entry::ID for internal
            // refs, otherwise the literal external URL.
            const matchedUrl = rule.target_entry_id
                ? `statamic://entry::${rule.target_entry_id}`
                : rule.url;

            const ids = [...this.selectedUnlinkIds];
            const total = ids.length;
            const replacements = ids.map(entryId => ({
                entry_id: entryId,
                matched_url: matchedUrl,
                occurrence_index: 0,
                search: matchedUrl,
            }));

            // Hash check covers only the entries we're modifying — reusing
            // the existing entryHashes computed (filtered by relevant ids).
            const allHashes = this.entryHashes;
            const relevantHashes = {};
            for (const id of ids) {
                if (allHashes[id]) relevantHashes[id] = allHashes[id];
            }

            this.unlinkingFromPreview = true;
            const ruleKeyword = rule.keyword || 'rule';
            this.closePreviewModal();

            try {
                const response = await fetch('/cp/linkwise/detail-unlink-async', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        replacements,
                        entry_hashes: relevantHashes,
                        source_mode: 'outbound',
                        entry_title: `Auto-Link rule "${ruleKeyword}"`,
                    }),
                });
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    if (response.status === 409) {
                        Statamic.$toast.error(data?.message || 'Entry was modified by another editor — reload and try again.');
                    } else {
                        const reason = data?.message || data?.error || `HTTP ${response.status}`;
                        Statamic.$toast.error(`Could not start: ${reason}`);
                    }
                    this.unlinkingFromPreview = false;

                    return;
                }
            } catch (e) {
                Statamic.$toast.error(`Could not start: ${e.message || 'network error'}`);
                this.unlinkingFromPreview = false;

                return;
            }

            Statamic.$toast.success(`Started — removing ${total} link${total === 1 ? '' : 's'} in the background.`);

            setHeavyState({
                kind: 'detailunlink',
                label: 'remove links',
                current: 0,
                total,
                canCancel: true,
                cancelUrl: '/cp/linkwise/detail-unlink-async/cancel',
                heartbeat: Math.floor(Date.now() / 1000),
                context: { entryTitle: `Auto-Link rule "${ruleKeyword}"`, sourceMode: 'outbound' },
            });

            const stop = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    if (previous?.kind === 'detailunlink' && current === null) {
                        stop();
                        this.unlinkingFromPreview = false;
                        this.selectedUnlinkIds = [];
                        // Inertia partial-reload — parent's :key="renderKey"
                        // watch on autolinkData/entries re-mounts this
                        // component so data() runs fresh. See single-
                        // rule path for full rationale.
                        inertiaRouter.reload({
                            only: ['autolinkData', 'entries'],
                            preserveScroll: true,
                        });
                    }
                },
            );
            // Safety: stop watcher after 30 min in case the bulk dies silently.
            setTimeout(() => stop(), 30 * 60 * 1000);
        },

        async applyFromPreview() {
            if (!this.previewModal?.ruleId || this.applyingPreview) return;

            // Defensive recompute: child computes applyablePreviewCount over
            // groupedPreview, but the child only fires `apply` when its button
            // is enabled (count > 0). We still guard here against direct calls.
            const applyableIds = new Set();
            for (const item of (this.previewModal?.items || [])) {
                if (item.link_status === 'would_link' && !this.excludedEntryIds.includes(item.id)) {
                    applyableIds.add(item.id);
                }
            }
            if (applyableIds.size === 0) return;

            const rule = this.rules.find(r => r.id === this.previewModal.ruleId);
            if (!rule) return;

            this.applyingPreview = true;
            const excluded = [...this.excludedEntryIds];
            // Close the modal — progress is reported via the global async banner.
            this.closePreviewModal();
            await this.dispatchApplyAsync(rule, {
                entry_hashes: this.entryHashes,
                excluded_entry_ids: excluded,
            });
            this.applyingPreview = false;
        },

        // highlightKeyword moved into RulePreviewModal.vue (only consumer) in
        // Sprint 5 PR 2e.

        // Pure formatting helpers — implementations live in utils/ruleFormatting.js.
        // Registered as shorthand methods so the template can call them by name
        // (Vue Options-API resolves template identifiers via component context,
        // not module scope). Script-side callers use the bare import directly.
        truncateUrl,
        formatAutoApply,
        normalizeAutoApply,
        wouldLinkForRule,

        exportRules() {
            // Direct browser navigation — backend streams the CSV with
            // Content-Disposition: attachment, browser handles download.
            window.location.href = this.data.urls.export;
        },

        async handleImportFile(event) {
            const file = event.target.files?.[0];
            event.target.value = ''; // allow re-importing the same file
            if (!file) return;
            this.importing = true;
            try {
                const formData = new FormData();
                formData.append('file', file);
                const response = await window.fetch(this.data.urls.import, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    Statamic.$toast.error(data.error || `Import failed (HTTP ${response.status})`);
                    return;
                }
                this.importResult = data;
                if (data.created > 0) {
                    Statamic.$toast.success(`${data.created} rule(s) imported.`);
                    // Append imported rules directly to the local table so they show up
                    // without a page reload. Stats (match_count etc.) are 0 until the
                    // user clicks Preview/Apply or reloads — backend doesn't run preview
                    // for imports because that would be 500ms × N rules and stall.
                    if (Array.isArray(data.rules) && data.rules.length > 0) {
                        this.rules.push(...data.rules);
                    }
                } else {
                    Statamic.$toast.info('No rules created — see details.');
                }
            } catch (e) {
                Statamic.$toast.error(`Could not import: ${e.message || 'network error'}`);
            } finally {
                this.importing = false;
            }
        },

        // Reactive ticker so the relative-time string ("3 minutes ago")
        // refreshes without a hard reload. Read in formatRelativeTime so
        // every recompute picks up the new clock.
        // (Note: declared in data() — see `nowTick` reference there.)
        formatRelativeTime(iso) {
            // Read the ticker so this method becomes reactive on it.
            // eslint-disable-next-line no-unused-expressions
            this.nowTick;
            if (!iso) return 'Never';
            const then = new Date(iso).getTime();
            const now = Date.now();
            if (Number.isNaN(then)) return '';
            const diff = Math.max(0, Math.floor((now - then) / 1000)); // seconds
            if (diff < 10) return 'just now';
            if (diff < 60) return `${diff} seconds ago`;
            const m = Math.floor(diff / 60);
            if (m < 60) return `${m} minute${m === 1 ? '' : 's'} ago`;
            const h = Math.floor(m / 60);
            if (h < 24) return `${h} hour${h === 1 ? '' : 's'} ago`;
            const d = Math.floor(h / 24);
            if (d < 30) return `${d} day${d === 1 ? '' : 's'} ago`;
            const mo = Math.floor(d / 30);
            if (mo < 12) return `${mo} month${mo === 1 ? '' : 's'} ago`;
            const y = Math.floor(mo / 12);
            return `${y} year${y === 1 ? '' : 's'} ago`;
        },

        formatExactDate,

        async fetch(url, method, body = null) {
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            };
            if (body) options.body = JSON.stringify(body);
            const response = await window.fetch(url, options);
            if (!response.ok) {
                const errorData = await response.json().catch(() => null);
                // Always surface a real reason: prefer backend's `error` string, then `message`,
                // then HTTP status — never throw a bare "Failed to ..." with no detail.
                const reason = errorData?.error || errorData?.message || `HTTP ${response.status}`;
                const err = new Error(reason);
                err.status = response.status;
                err.payload = errorData;
                throw err;
            }
            return response.json();
        },
    },
};
</script>
