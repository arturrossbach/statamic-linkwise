<template>
    <Panel>
        <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
            <thead>
                <tr>
                    <th scope="col" class="w-8">
                        <input type="checkbox" class="rounded" :checked="allSelected" @change="$emit('toggle-select-all')" />
                    </th>
                    <SortableHeader label="Keyword" :active="sortField === 'keyword'" :direction="sortDirection" @sort="$emit('toggle-sort', 'keyword')" />
                    <SortableHeader label="Link Target" :active="sortField === 'url'" :direction="sortDirection" @sort="$emit('toggle-sort', 'url')" />
                    <SortableHeader label="Matches" align="center" :active="sortField === 'match_count'" :direction="sortDirection" @sort="$emit('toggle-sort', 'match_count')">
                        <HelpIcon tooltip="Entries where this keyword appears (regardless of link status). Click to see them in the preview." />
                    </SortableHeader>
                    <SortableHeader label="Already linked" align="center" :active="sortField === 'linked_count'" :direction="sortDirection" @sort="$emit('toggle-sort', 'linked_count')">
                        <HelpIcon tooltip="Entries where the keyword is already linked to this rule's target." />
                    </SortableHeader>
                    <SortableHeader label="Will link" align="center" :active="sortField === 'will_link_count'" :direction="sortDirection" @sort="$emit('toggle-sort', 'will_link_count')">
                        <HelpIcon tooltip="Entries that an Apply right now would actually insert a link into. Equals Matches minus already-linked, linked-elsewhere, and not-insertable." />
                    </SortableHeader>
                    <SortableHeader label="Last applied" :active="sortField === 'last_applied_at'" :direction="sortDirection" @sort="$emit('toggle-sort', 'last_applied_at')">
                        <HelpIcon tooltip="When this rule was most recently applied. 'Never' means it was created but Apply was never clicked." />
                    </SortableHeader>
                    <SortableHeader label="Settings" :sortable="false">
                        <HelpIcon tooltip="Per-rule options: case-sensitivity, auto-apply behavior, collection restriction. Only non-default options are shown." />
                    </SortableHeader>
                    <SortableHeader label="Actions" :sortable="false" align="right" />
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="rule in sortedRules"
                    :key="rule.id"
                    :ref="el => { if (el) rowRefs[rule.id] = el }"
                    :class="{ 'opacity-50': !rule.active }"
                >
                    <td>
                        <input type="checkbox" class="rounded" :value="rule.id" v-model="selectedRulesLocal" />
                    </td>
                    <td class="font-medium text-gray-900 dark:text-gray-100">
                        {{ rule.keyword }}
                        <span v-if="!rule.active" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700" v-tooltip="'Ignored — skipped during Apply All and Apply Selected'">Ignored</span>
                    </td>
                    <td class="text-gray-500 dark:text-gray-400 text-xs break-all">
                        <span v-if="rule.target_entry_id" v-tooltip="rule.url">
                            {{ findEntryTitle(rule.target_entry_id) || rule.url }}
                        </span>
                        <span v-else>{{ truncateUrl(rule.url) }}</span>
                    </td>
                    <td class="text-center">
                        <button v-if="rule.match_count > 0" @click="$emit('preview-rule', rule)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                            {{ rule.match_count }}
                        </button>
                        <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                    </td>
                    <td class="text-center">
                        <button v-if="rule.linked_count > 0" @click="$emit('preview-rule', rule)" class="hover:underline cursor-pointer text-green-600 dark:text-green-400">
                            {{ rule.linked_count }}
                        </button>
                        <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                    </td>
                    <td class="text-center">
                        <button v-if="wouldLinkForRule(rule) > 0" @click="$emit('preview-rule', rule)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400 font-medium">
                            {{ wouldLinkForRule(rule) }}
                        </button>
                        <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                    </td>
                    <td class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        <span v-if="rule.last_applied_at" v-tooltip="formatExactDate(rule.last_applied_at) + ' — ' + (rule.last_applied_links_added ?? 0) + ' link(s) added'">
                            {{ formatRelativeTime(rule.last_applied_at) }}
                        </span>
                        <span v-else class="italic text-gray-400 dark:text-gray-600">Never</span>
                    </td>
                    <td class="text-xs max-w-[180px]">
                        <!-- Full settings list per rule — short labels +
                             tooltips so the column stays narrow but every
                             option is visible without opening Edit. -->
                        <dl class="flex flex-col gap-0.5">
                            <div v-tooltip="'Case-sensitive: only matches the exact casing'">
                                <span class="text-gray-400 dark:text-gray-500">Case:</span>
                                <span class="ml-1 text-gray-700 dark:text-gray-300">{{ rule.case_sensitive ? 'yes' : 'no' }}</span>
                            </div>
                            <div v-tooltip="'Skip if already linked: do not insert when this keyword already has any link'">
                                <span class="text-gray-400 dark:text-gray-500">Skip:</span>
                                <span class="ml-1 text-gray-700 dark:text-gray-300">{{ rule.skip_if_exists ? 'yes' : 'no' }}</span>
                            </div>
                            <div v-tooltip="'Once per post: only insert one link per entry (V1 default — always yes)'">
                                <span class="text-gray-400 dark:text-gray-500">Once:</span>
                                <span class="ml-1 text-gray-700 dark:text-gray-300">{{ rule.once_per_post ? 'yes' : 'no' }}</span>
                            </div>
                            <div v-tooltip="'Auto-apply on entry save'">
                                <span class="text-gray-400 dark:text-gray-500">Auto:</span>
                                <span class="ml-1 text-gray-700 dark:text-gray-300">{{ formatAutoApply(rule.auto_apply_on_save) }}</span>
                            </div>
                            <div v-tooltip="'Collections this rule is restricted to'">
                                <span class="text-gray-400 dark:text-gray-500">Collections:</span>
                                <span class="ml-1 text-gray-700 dark:text-gray-300 break-words">{{ (rule.collections || []).length > 0 ? rule.collections.join(', ') : 'all' }}</span>
                            </div>
                        </dl>
                    </td>
                    <td class="text-right whitespace-nowrap">
                        <Button
                            text="Preview"
                            variant="default"
                            size="sm"
                            icon="eye"
                            :disabled="applyingAll || isRuleEditingAndDirty(rule)"
                            class="mr-1"
                            v-tooltip="isRuleEditingAndDirty(rule) ? 'Save changes first — unsaved edits will not appear in the preview' : 'See which entries would be affected'"
                            @click="$emit('preview-rule', rule)"
                        />
                        <Button
                            :text="rule.active ? `Apply (${wouldLinkForRule(rule)})` : 'Apply'"
                            variant="primary"
                            size="sm"
                            icon="sync"
                            :disabled="!rule.active || wouldLinkForRule(rule) === 0 || applyingAll || (applyAsyncRuleId !== null && applyAsyncRuleId !== rule.id) || isRuleEditingAndDirty(rule)"
                            :loading="applyingAll || applyAsyncRuleId === rule.id"
                            class="mr-2 min-w-[118px] justify-center"
                            v-tooltip="isRuleEditingAndDirty(rule) ? 'Save changes first — Apply uses the saved rule, not your edits' : (applyAsyncRuleId !== null && applyAsyncRuleId !== rule.id ? 'Another apply is in progress' : (!rule.active ? 'Rule is ignored — activate to apply' : (wouldLinkForRule(rule) === 0 ? 'Nothing new to link' : `Insert ${wouldLinkForRule(rule)} link(s) now`)))"
                            @click="$emit('apply-rule', rule)"
                        />
                        <Dropdown align="end">
                            <DropdownMenu>
                                <DropdownItem text="Edit" icon="pencil" @click="$emit('edit-rule', rule)" />
                                <DropdownItem :text="rule.active ? 'Ignore (skip during Apply)' : 'Activate'" :icon="rule.active ? 'eye-closed' : 'eye'" @click="$emit('toggle-active', rule)" />
                                <DropdownSeparator />
                                <DropdownItem text="Delete" icon="trash" variant="destructive" @click="$emit('confirm-delete', rule)" />
                            </DropdownMenu>
                        </Dropdown>
                    </td>
                </tr>
            </tbody>
        </table></div>
    </Panel>
</template>

<script>
/**
 * RuleListTable — extracted from AutoLinkingTab.vue (Sprint 5 PR 2d, REV-FE-01).
 *
 * Variante A: state stays in parent (sortedRules pre-computed, selectedRules
 * v-model'd, sortField/sortDirection read-only props). Sub-component is a
 * template wrapper that emits actions upward; only locally-owned state is
 * the per-row DOM ref bag (`rowRefs`) which the parent reaches via
 * `$refs.ruleListTable.getRowRef(id)` (Option B — single reader was
 * `scrollToRule()`).
 *
 * Pure formatting helpers (truncateUrl, formatAutoApply, formatExactDate,
 * wouldLinkForRule) are imported from utils/ruleFormatting.js — the Phase B
 * Vorbau (PR #27) means no method-prop bridge.
 *
 * State-coupled helpers stay local methods:
 *   - `findEntryTitle(id)` reads the `entries` prop
 *   - `formatRelativeTime(iso)` reads the `nowTick` prop for reactivity
 *     (parent ticks the minute-clock; we re-render when nowTick changes)
 *   - `isRuleEditingAndDirty(rule)` derives from `editingRule` + `formDirty`
 *     props
 *
 * Sort-mixin stays in Parent (Option X): `sortField`/`sortDirection` mutated
 * by parent's `toggleSort()` from the sortableMixin. We read-only-bind them
 * here for SortableHeader's `:active` and `:direction` indicators, never
 * v-model.
 */
import { Panel, Button, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import {
    truncateUrl,
    formatAutoApply,
    formatExactDate,
    wouldLinkForRule,
} from '../../utils/ruleFormatting.js';

export default {
    components: { Panel, Button, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, HelpIcon, SortableHeader },

    props: {
        sortedRules: { type: Array, required: true },
        selectedRules: { type: Array, required: true },
        sortField: { type: String, required: true },
        sortDirection: { type: String, required: true },
        allSelected: { type: Boolean, default: false },
        applyingAll: { type: Boolean, default: false },
        applyAsyncRuleId: { type: [String, Number, null], default: null },
        editingRule: { type: Object, default: null },
        formDirty: { type: Boolean, default: false },
        entries: { type: Array, default: () => [] },
        // Reactive minute-clock — parent owns the timer, we read this so
        // formatRelativeTime() re-runs every tick.
        nowTick: { type: Number, default: 0 },
    },

    emits: [
        'update:selectedRules',
        'toggle-select-all',
        'toggle-sort',
        'preview-rule',
        'apply-rule',
        'edit-rule',
        'toggle-active',
        'confirm-delete',
    ],

    data() {
        return {
            // Plain ref bag (not reactive — Vue 3 template-refs callback pattern).
            // Child-owned; parent reaches via $refs.ruleListTable.getRowRef(id).
            rowRefs: {},
        };
    },

    computed: {
        // v-model bridge for the selectedRules array prop. Required because
        // Vue 3 prohibits direct mutation of array props; we go through
        // update:selectedRules to keep the parent as the source of truth.
        selectedRulesLocal: {
            get() { return this.selectedRules; },
            set(v) { this.$emit('update:selectedRules', v); },
        },
    },

    methods: {
        truncateUrl,
        formatAutoApply,
        formatExactDate,
        wouldLinkForRule,

        findEntryTitle(entryId) {
            return this.entries.find(e => e.id === entryId)?.title || null;
        },

        /**
         * Renders "X minutes ago"-style relative time. Reads `this.nowTick`
         * to remain reactive on the parent's minute-tick timer (the
         * recompute-only-on-data-change rule in Vue would otherwise freeze
         * the label at mount time).
         */
        formatRelativeTime(iso) {
            // eslint-disable-next-line no-unused-expressions
            this.nowTick;
            if (!iso) return 'Never';
            const then = new Date(iso).getTime();
            const now = Date.now();
            if (Number.isNaN(then)) return '';
            const diff = Math.max(0, Math.floor((now - then) / 1000));
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

        /**
         * True when this rule is the one being edited AND the form has
         * unsaved changes — disables Preview/Apply since those operate
         * on the persisted rule (confusing for users with pending edits).
         */
        isRuleEditingAndDirty(rule) {
            return this.editingRule && this.editingRule.id === rule.id && this.formDirty;
        },

        /**
         * Exposes a single row's DOM element by rule-id. Parent calls this
         * from scrollToRule() via `$refs.ruleListTable.getRowRef(id)`.
         * Returns undefined if the row isn't currently in sortedRules
         * (e.g. filtered out by searchQuery) — caller no-ops gracefully.
         */
        getRowRef(ruleId) {
            return this.rowRefs[ruleId];
        },
    },
};
</script>
