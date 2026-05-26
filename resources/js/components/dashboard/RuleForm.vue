<template>
    <Card class="mb-6">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
            {{ editingRule ? 'Edit Rule' : 'Create Rule' }}
        </h3>
        <div class="space-y-3">
            <!-- Keywords (Create: textarea, one keyword per line; Edit: single input) -->
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">
                    {{ editingRule ? 'Keyword' : 'Keywords (one per line — creates one rule per keyword)' }}
                </label>
                <input
                    v-if="editingRule"
                    v-model="newRule.keyword"
                    type="text"
                    class="w-full text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5"
                />
                <textarea
                    v-else
                    v-model="newRule.keyword"
                    :placeholder="'Laravel\nStatamic CMS\nRedis Setup'"
                    rows="3"
                    class="w-full text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5"
                ></textarea>
            </div>

            <!-- Link Target -->
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Link Target</label>
                <div class="flex items-center gap-4 mb-2">
                    <label class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" v-model="linkModeLocal" value="entry" class="text-blue-500"> Link to Entry
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" v-model="linkModeLocal" value="url" class="text-blue-500"> Custom URL
                    </label>
                </div>

                <!-- Entry Picker (Statamic native relationship-input — same as Bard link dialog) -->
                <div v-if="linkMode === 'entry'">
                    <div class="flex items-center gap-2">
                        <div
                            class="flex-1 text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800 min-w-0 cursor-pointer hover:border-gray-400 dark:hover:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-800/80 transition-colors"
                            role="button"
                            tabindex="0"
                            @click="openEntrySelector"
                            @keydown.enter.prevent="openEntrySelector"
                            @keydown.space.prevent="openEntrySelector"
                        >
                            <template v-if="selectedEntry">
                                <span class="text-gray-900 dark:text-gray-100 truncate">{{ selectedEntry.title }}</span>
                                <span class="text-xs text-gray-400 ml-2">{{ selectedEntry.collection }}</span>
                            </template>
                            <span v-else class="text-gray-400">Click to select an entry…</span>
                        </div>
                        <Button type="button" :text="selectedEntry ? 'Change' : 'Select entry'" @click="openEntrySelector" />
                    </div>
                    <relationship-input
                        ref="entryPicker"
                        class="hidden"
                        name="link_target"
                        :value="[]"
                        :config="relationshipConfig"
                        :item-data-url="relationshipItemDataUrl"
                        :selections-url="relationshipSelectionsUrl"
                        :filters-url="relationshipFiltersUrl"
                        :columns="[{ label: 'Title', field: 'title' }]"
                        :max-items="1"
                        :search="true"
                        @item-data-updated="onEntryPicked"
                    />
                </div>

                <!-- Custom URL -->
                <template v-if="linkMode === 'url'">
                    <input
                        v-model="newRule.url"
                        type="text"
                        placeholder="https://example.com/page"
                        :aria-invalid="newRule.url.trim() !== '' && !customUrlValid"
                        :class="[
                            'w-full text-sm border rounded-lg px-3 py-1.5 dark:bg-gray-800 focus:outline-none focus:ring-2',
                            newRule.url.trim() !== '' && !customUrlValid
                                ? 'border-red-400 focus:ring-red-400 dark:border-red-500'
                                : 'border-gray-300 dark:border-gray-700 focus:ring-blue-500',
                        ]"
                    />
                    <p v-if="newRule.url.trim() !== '' && !customUrlValid" class="mt-1 text-xs text-red-500">
                        Enter a valid URL (http(s)://…, mailto: or tel:).
                    </p>
                    <Alert variant="warning" class="mt-2">
                        <p class="text-xs">
                            Auto-linking to <strong>external URLs</strong> can look spammy to search engines when used at scale.
                            Prefer linking to internal entries where possible.
                        </p>
                    </Alert>
                </template>
            </div>

            <!-- Collections restriction (optional — empty = scan all collections) -->
            <div v-if="collections.length > 0">
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">
                    Limit to collections
                    <span class="text-gray-400">(optional — empty applies to all)</span>
                </label>
                <MultiSelect
                    v-model="newRule.collections"
                    :options="collections"
                    label="All collections"
                />
            </div>

            <!-- V1.2 Cross-Tab-B — per-rule locale restriction. Only renders
                 when the index actually carries 2+ locales (single-site or
                 single-locale-content installs don't need this). Empty
                 selection = match all sites (back-compat). -->
            <div v-if="availableLocales.length > 0">
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">
                    Limit to languages
                    <span class="text-gray-400">(optional — empty applies to all sites)</span>
                </label>
                <MultiSelect
                    v-model="newRule.locales"
                    :options="availableLocales"
                    label="All languages"
                />
            </div>

            <!-- Options. once_per_post is enforced=true for V1 (SEO best practice; multi-link
                 deferred). skip_if_exists removed (BardLinkInserter never overwrites). -->
            <div class="flex items-center gap-4 flex-wrap">
                <label class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" v-model="newRule.case_sensitive" class="rounded">
                    Case-sensitive
                    <HelpIcon tooltip="Only match the exact casing. 'Laravel' won't match 'laravel'." />
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                    <span>Auto-apply on save:</span>
                    <select
                        v-model="newRule.auto_apply_on_save"
                        class="text-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded px-2 py-1"
                    >
                        <option value="follow_global">Follow global setting{{ autoApplyOnSaveEnabled ? ' (currently ON)' : ' (currently OFF)' }}</option>
                        <option value="always">Always — even if global is off</option>
                        <option value="never">Never — manual only</option>
                    </select>
                    <HelpIcon tooltip="When 'Follow global setting' is chosen, this rule fires on save only when the global Auto-Apply toggle in CP > Linkwise > Settings is on. 'Always' overrides — fires regardless. 'Never' means only manual Apply works." />
                </label>
            </div>

            <div class="flex items-center gap-2">
                <Button @click="$emit('submit')" :disabled="!canCreate" :text="editingRule ? 'Update Rule' : 'Create Rule'" variant="primary" />
                <Button v-if="editingRule" @click="$emit('cancel')" text="Cancel" />
                <span
                    v-if="editingRule && formDirty"
                    class="text-xs text-amber-600 dark:text-amber-400 inline-flex items-center gap-1"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    Unsaved changes — click <strong>Update Rule</strong> to apply
                </span>
            </div>
        </div>
    </Card>
</template>

<script>
/**
 * RuleForm — extracted from AutoLinkingTab.vue (Sprint 5 PR 2c, REV-FE-01).
 *
 * Variante A: state stays in parent. This component is a template-only
 * wrapper that receives reactive props + emits up. The `newRule` object
 * is passed by reference and mutated in place via v-model on its fields
 * (Vue 3 silently accepts object-prop field mutations); `linkMode` and
 * `selectedEntry` are primitives and use v-model:* / update:* emits.
 *
 * Why no setup()/Composition-API: PR #24 confirmed "0 Linkwise components
 * use setup()" — staying Options-API matches the surrounding codebase and
 * keeps the byte-stable Pin-Tests (parent-state) green without `findComponent`
 * gymnastics.
 *
 * The relationship-input picker lives here too: the parent calls
 * `this.$refs.ruleForm?.openEntrySelector()` to trigger the modal, and
 * we re-emit `entry-picked` upward so the parent's onEntryPicked stays
 * the authority on selectedEntry + newRule.url assignment.
 */
import { Card, Button, Alert } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import MultiSelect from '../shared/MultiSelect.vue';

export default {
    components: { Card, Button, Alert, HelpIcon, MultiSelect },

    props: {
        editingRule: { type: Object, default: null },
        newRule: { type: Object, required: true },
        linkMode: { type: String, required: true },
        selectedEntry: { type: Object, default: null },
        customUrlValid: { type: Boolean, default: false },
        canCreate: { type: Boolean, default: false },
        formDirty: { type: Boolean, default: false },
        collections: { type: Array, default: () => [] },
        availableLocales: { type: Array, default: () => [] },
        autoApplyOnSaveEnabled: { type: Boolean, default: false },
        relationshipConfig: { type: Object, required: true },
        relationshipItemDataUrl: { type: String, required: true },
        relationshipSelectionsUrl: { type: String, required: true },
        relationshipFiltersUrl: { type: String, required: true },
    },

    emits: ['update:linkMode', 'submit', 'cancel', 'entry-picked'],

    computed: {
        // v-model bridges for primitive props — Vue 3 requires explicit
        // getter/setter when binding v-model to a prop value.
        linkModeLocal: {
            get() { return this.linkMode; },
            set(v) { this.$emit('update:linkMode', v); },
        },
    },

    methods: {
        /** Triggers the hidden relationship-input modal. Parent invokes this via $refs. */
        openEntrySelector() {
            this.$refs.entryPicker?.openSelector();
        },

        /** Forwards the picker payload upward; parent owns selectedEntry + newRule.url assignment. */
        onEntryPicked(data) {
            this.$emit('entry-picked', data);
        },
    },
};
</script>
