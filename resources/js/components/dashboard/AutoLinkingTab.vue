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

        <!-- Create / Edit Rule Form -->
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
                            <input type="radio" v-model="linkMode" value="entry" class="text-blue-500"> Link to Entry
                        </label>
                        <label class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5 cursor-pointer">
                            <input type="radio" v-model="linkMode" value="url" class="text-blue-500"> Custom URL
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
                <div v-if="(data.collections || []).length > 0">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">
                        Limit to collections
                        <span class="text-gray-400">(optional — empty applies to all)</span>
                    </label>
                    <MultiSelect
                        v-model="newRule.collections"
                        :options="data.collections"
                        label="All collections"
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
                            <option value="follow_global">Follow global setting{{ data.auto_apply_on_save_enabled ? ' (currently ON)' : ' (currently OFF)' }}</option>
                            <option value="always">Always — even if global is off</option>
                            <option value="never">Never — manual only</option>
                        </select>
                        <HelpIcon tooltip="When 'Follow global setting' is chosen, this rule fires on save only when the global Auto-Apply toggle in CP > Linkwise > Settings is on. 'Always' overrides — fires regardless. 'Never' means only manual Apply works." />
                    </label>
                </div>

                <div class="flex items-center gap-2">
                    <Button @click="saveRule" :disabled="!canCreate" :text="editingRule ? 'Update Rule' : 'Create Rule'" variant="primary" />
                    <Button v-if="editingRule" @click="cancelEdit" text="Cancel" />
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

        <!-- Rules Table -->
        <Panel v-else>
            <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="w-8">
                            <input type="checkbox" class="rounded" :checked="allSelected" @change="toggleSelectAll" />
                        </th>
                        <SortableHeader label="Keyword" :active="sortField === 'keyword'" :direction="sortDirection" @sort="toggleSort('keyword')" />
                        <SortableHeader label="Link Target" :active="sortField === 'url'" :direction="sortDirection" @sort="toggleSort('url')" />
                        <SortableHeader label="Matches" align="center" :active="sortField === 'match_count'" :direction="sortDirection" @sort="toggleSort('match_count')">
                            <HelpIcon tooltip="Entries where this keyword appears (regardless of link status). Click to see them in the preview." />
                        </SortableHeader>
                        <SortableHeader label="Already linked" align="center" :active="sortField === 'linked_count'" :direction="sortDirection" @sort="toggleSort('linked_count')">
                            <HelpIcon tooltip="Entries where the keyword is already linked to this rule's target." />
                        </SortableHeader>
                        <SortableHeader label="Will link" align="center" :active="sortField === 'will_link_count'" :direction="sortDirection" @sort="toggleSort('will_link_count')">
                            <HelpIcon tooltip="Entries that an Apply right now would actually insert a link into. Equals Matches minus already-linked, linked-elsewhere, and not-insertable." />
                        </SortableHeader>
                        <SortableHeader label="Last applied" :active="sortField === 'last_applied_at'" :direction="sortDirection" @sort="toggleSort('last_applied_at')">
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
                        :ref="el => { if (el) ruleRowRefs[rule.id] = el }"
                        :class="{ 'opacity-50': !rule.active }"
                    >
                        <td>
                            <input type="checkbox" class="rounded" :value="rule.id" v-model="selectedRules" />
                        </td>
                        <td class="font-medium text-gray-900 dark:text-gray-100">
                            {{ rule.keyword }}
                            <span v-if="!rule.active" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400" v-tooltip="'Ignored — skipped during Apply All and Apply Selected'">Ignored</span>
                        </td>
                        <td class="text-gray-500 dark:text-gray-400 text-xs break-all">
                            <span v-if="rule.target_entry_id" v-tooltip="rule.url">
                                {{ findEntryTitle(rule.target_entry_id) || rule.url }}
                            </span>
                            <span v-else>{{ truncateUrl(rule.url) }}</span>
                        </td>
                        <td class="text-center">
                            <button v-if="rule.match_count > 0" @click="previewRule(rule)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400">
                                {{ rule.match_count }}
                            </button>
                            <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                        </td>
                        <td class="text-center">
                            <button v-if="rule.linked_count > 0" @click="previewRule(rule)" class="hover:underline cursor-pointer text-green-600 dark:text-green-400">
                                {{ rule.linked_count }}
                            </button>
                            <span v-else class="text-gray-300 dark:text-gray-600">0</span>
                        </td>
                        <td class="text-center">
                            <button v-if="wouldLinkForRule(rule) > 0" @click="previewRule(rule)" class="hover:underline cursor-pointer text-blue-600 dark:text-blue-400 font-medium">
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
                                @click="previewRule(rule)"
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
                                @click="applyRule(rule)"
                            />
                            <Dropdown align="end">
                                <DropdownMenu>
                                    <DropdownItem text="Edit" icon="pencil" @click="editRule(rule)" />
                                    <DropdownItem :text="rule.active ? 'Ignore (skip during Apply)' : 'Activate'" :icon="rule.active ? 'eye-closed' : 'eye'" @click="toggleActive(rule)" />
                                    <DropdownSeparator />
                                    <DropdownItem text="Delete" icon="trash" variant="destructive" @click="confirmDelete(rule)" />
                                </DropdownMenu>
                            </Dropdown>
                        </td>
                    </tr>
                </tbody>
            </table></div>
        </Panel>

        <!-- Preview Modal -->
        <Stack :open="previewModal !== null" @update:open="closePreviewModal" :title="previewModal?.title || ''">
            <div v-if="previewModal">
                <div v-if="previewModal.loading" class="py-4 text-center text-gray-400">Checking entries...</div>
                <div v-else-if="previewModal.items.length === 0" class="py-4 text-center text-gray-400">No matching entries found.</div>
                <div v-else>
                    <!-- Status Filter + Summary + Apply button -->
                    <div class="flex items-center justify-between mb-4 gap-4 flex-wrap">
                        <div class="flex items-center gap-3">
                            <select
                                v-if="availablePreviewStatusOptions.length > 1"
                                v-model="previewStatusFilter"
                                aria-label="Filter by status"
                                class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md px-2 py-1.5"
                            >
                                <option value="">All statuses</option>
                                <option v-for="opt in availablePreviewStatusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                <strong :class="applyablePreviewCount > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'">{{ applyablePreviewCount }}</strong> will be linked<template v-if="applyablePreviewCount !== wouldLinkCount"> ({{ wouldLinkCount }} matching, {{ wouldLinkCount - applyablePreviewCount }} excluded)</template>,
                                {{ linkedToTargetCount }} linked to target,
                                {{ linkedElsewhereCount }} linked elsewhere<template v-if="notInsertableCount > 0">,
                                {{ notInsertableCount }} cannot insert</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                v-if="linkedToTargetCount > 0 && previewModal.ruleId"
                                :text="`Unlink (${selectedUnlinkIds.length})`"
                                :disabled="selectedUnlinkIds.length === 0 || unlinkingFromPreview"
                                :loading="unlinkingFromPreview"
                                v-tooltip="'Remove the rule\'s link from selected entries (uses the same atomic, conflict-safe save path as DetailModal Bulk Unlink)'"
                                @click="unlinkSelectedFromPreview"
                            />
                            <Button
                                v-if="wouldLinkCount > 0 && previewModal.ruleId"
                                :text="`Apply (${applyablePreviewCount})`"
                                variant="primary"
                                :disabled="applyablePreviewCount === 0 || applyingPreview"
                                :loading="applyingPreview"
                                @click="applyFromPreview"
                            />
                        </div>
                    </div>

                    <Panel>
                        <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                            <thead>
                                <tr>
                                    <SortableHeader :sortable="false">
                                        <input
                                            type="checkbox"
                                            class="rounded"
                                            :checked="allPreviewRowsSelected"
                                            :indeterminate.prop="somePreviewRowsSelected && !allPreviewRowsSelected"
                                            :disabled="togglablePreviewRowCount === 0"
                                            @change="togglePreviewSelectAll"
                                            v-tooltip="'Toggle every selectable row in this preview (would-link rows for Apply, linked-to-target rows for Unlink)'"
                                            aria-label="Select all selectable rows"
                                        />
                                    </SortableHeader>
                                    <SortableHeader label="Target Entry" :active="previewSortField === 'title'" :direction="previewSortDirection" @sort="togglePreviewSort('title')" />
                                    <SortableHeader label="Context" :sortable="false" />
                                    <SortableHeader label="Status" align="center" :active="previewSortField === 'link_status'" :direction="previewSortDirection" @sort="togglePreviewSort('link_status')" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(item, idx) in sortedPreviewItems"
                                    :key="`${item.id}-${idx}`"
                                    :class="{ 'opacity-50': item.link_status === 'would_link' && excludedEntryIds.includes(item.id) }"
                                >
                                    <td>
                                        <input
                                            v-if="item.link_status === 'would_link'"
                                            type="checkbox"
                                            :checked="!excludedEntryIds.includes(item.id)"
                                            @change="toggleExclude(item.id)"
                                            class="rounded"
                                            :aria-label="`Include '${item.title}' when applying`"
                                            v-tooltip="'Uncheck to skip this entry when applying'"
                                        />
                                        <input
                                            v-else-if="item.link_status === 'linked_to_target'"
                                            type="checkbox"
                                            :checked="selectedUnlinkIds.includes(item.id)"
                                            @change="toggleUnlinkSelection(item.id)"
                                            class="rounded"
                                            :aria-label="`Select '${item.title}' for unlink`"
                                            v-tooltip="'Check to include this entry when removing the rule\'s links'"
                                        />
                                    </td>
                                    <td>
                                        <Link v-if="item.edit_url" :href="item.edit_url" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400">{{ item.title }}</Link>
                                        <span v-else class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.title }}</span>
                                        <BardBadge v-if="item.id" :entry-id="item.id" class="ml-1.5" />
                                    </td>
                                    <td class="text-gray-400 dark:text-gray-500 text-xs">
                                        <span v-if="item.sentence_context" v-html="highlightKeyword(item.sentence_context, previewModal.keyword)"></span>
                                        <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                    </td>
                                    <td class="text-center whitespace-nowrap">
                                        <span v-if="item.link_status === 'linked_to_target'" class="text-xs px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">Linked to target</span>
                                        <span v-else-if="item.link_status === 'linked_elsewhere'" class="text-xs px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400" v-tooltip="'Already linked to a different URL. Use the URL Changer to update it.'">Linked elsewhere</span>
                                        <span v-else-if="item.link_status === 'not_insertable'" class="text-xs px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400" v-tooltip="'Keyword was found in plain text, but Bard cannot insert a link there — the text may span multiple nodes or sit inside a code block.'">Cannot insert</span>
                                        <span v-else class="text-xs px-1.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">Would link</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table></div>
                    </Panel>
                </div>
            </div>
        </Stack>

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
import { Link, router as inertiaRouter } from '@statamic/cms/inertia';
import { Card, Panel, Button, Stack, ConfirmationModal, Modal, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Alert, Icon, Badge } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import MultiSelect from '../shared/MultiSelect.vue';
import BardBadge from '../shared/BardBadge.vue';
import { sortableMixin } from '../shared/sortable.js';
import { highlightKeyword } from '../../utils/highlight.js';
import { isValidReplacementUrl } from '../../utils/urlValidation.js';
import { isFormDirty } from '../../utils/formDirty.js';
import { bulkState, setHeavyState } from '../../services/bulkOperationService.js';

const PREVIEW_STATUS_OPTIONS = [
    { value: 'would_link', label: 'Would link' },
    { value: 'linked_to_target', label: 'Linked to target' },
    { value: 'linked_elsewhere', label: 'Linked elsewhere' },
    { value: 'not_insertable', label: 'Cannot insert' },
];

export default {
    components: { Link, Card, Panel, Button, Stack, ConfirmationModal, Modal, Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Alert, Icon, Badge, HelpIcon, SortableHeader, MultiSelect, BardBadge },

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
            previewSortField: 'title',
            previewSortDirection: 'asc',
            // Single status filter for the Preview table. '' = no filter (show all).
            previewStatusFilter: '',
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
            // Plain ref bag (not reactive — Vue 3 template-refs callback pattern).
            ruleRowRefs: {},
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
            const totalNewLinks = selectedActive.reduce((s, r) => s + this.wouldLinkForRule(r), 0);
            const ruleWord = selectedActive.length === 1 ? 'rule' : 'rules';
            const linkWord = totalNewLinks === 1 ? 'link' : 'links';
            const inactiveSuffix = inactiveCount > 0
                ? ` (${inactiveCount} ignored ${inactiveCount === 1 ? 'rule is' : 'rules are'} skipped)`
                : '';
            return `Apply ${selectedActive.length} active ${ruleWord} — about ${totalNewLinks} new ${linkWord} will be inserted${inactiveSuffix}. Inserted links stay in the entries even if you later delete the rule.`;
        },

        // Only show filter options that actually appear in this preview's items.
        availablePreviewStatusOptions() {
            const presentStatuses = new Set();
            for (const i of this.previewModal?.items || []) {
                if (i.link_status) presentStatuses.add(i.link_status);
            }
            return PREVIEW_STATUS_OPTIONS.filter(o => presentStatuses.has(o.value));
        },

        /**
         * Flat list for the Preview table. Applies user-chosen status filter, then
         * sort (previewSortField / previewSortDirection). Tie-breaker keeps
         * multi-occurrence rows of the same entry together.
         */
        sortedPreviewItems() {
            let items = [...(this.previewModal?.items || [])];
            if (this.previewStatusFilter) {
                items = items.filter(i => i.link_status === this.previewStatusFilter);
            }
            const statusRank = { would_link: 0, linked_elsewhere: 1, not_insertable: 2, linked_to_target: 3 };
            const dir = this.previewSortDirection === 'asc' ? 1 : -1;
            const field = this.previewSortField;
            return items.sort((a, b) => {
                let primary;
                if (field === 'link_status') {
                    primary = ((statusRank[a.link_status] ?? 4) - (statusRank[b.link_status] ?? 4)) * dir;
                } else {
                    primary = String(a[field] ?? '').localeCompare(String(b[field] ?? '')) * dir;
                }
                if (primary !== 0) return primary;
                const tByTitle = String(a.title || '').localeCompare(String(b.title || ''));
                if (tByTitle !== 0) return tByTitle;
                return (statusRank[a.link_status] ?? 4) - (statusRank[b.link_status] ?? 4);
            });
        },

        groupedPreview() {
            if (!this.previewModal?.items) return [];
            const groups = {};
            for (const item of this.previewModal.items) {
                if (!groups[item.id]) {
                    groups[item.id] = {
                        id: item.id,
                        title: item.title,
                        edit_url: item.edit_url,
                        occurrences: [],
                        hasWouldLink: false,
                    };
                }
                groups[item.id].occurrences.push(item);
                if (item.link_status === 'would_link') groups[item.id].hasWouldLink = true;
            }
            return Object.values(groups);
        },

        wouldLinkCount() {
            return this.groupedPreview.filter(g => g.hasWouldLink).length;
        },

        /**
         * Header-checkbox state for the preview table. Selection lives in
         * two pools (excludedEntryIds for would_link, selectedUnlinkIds for
         * linked_to_target) — these computeds collapse both into the
         * canonical select-all semantics: are all toggleable rows ON?
         *
         * Toggleable = a row whose status has a checkbox (would_link or
         * linked_to_target). Sentence_status rows (linked_elsewhere,
         * not_insertable) are skipped — no checkbox, nothing to toggle.
         *
         * Filter-aware: when previewStatusFilter is set the count covers
         * only visible rows, matching the BrokenLinks "select all visible"
         * convention.
         */
        togglablePreviewRows() {
            return this.sortedPreviewItems.filter(
                i => i.link_status === 'would_link' || i.link_status === 'linked_to_target',
            );
        },

        togglablePreviewRowCount() {
            return this.togglablePreviewRows.length;
        },

        somePreviewRowsSelected() {
            for (const item of this.togglablePreviewRows) {
                if (item.link_status === 'would_link' && ! this.excludedEntryIds.includes(item.id)) {
                    return true;
                }
                if (item.link_status === 'linked_to_target' && this.selectedUnlinkIds.includes(item.id)) {
                    return true;
                }
            }
            return false;
        },

        allPreviewRowsSelected() {
            if (this.togglablePreviewRows.length === 0) return false;
            for (const item of this.togglablePreviewRows) {
                if (item.link_status === 'would_link' && this.excludedEntryIds.includes(item.id)) {
                    return false;
                }
                if (item.link_status === 'linked_to_target' && ! this.selectedUnlinkIds.includes(item.id)) {
                    return false;
                }
            }
            return true;
        },

        linkedToTargetCount() {
            if (!this.previewModal?.items) return 0;
            const ids = new Set();
            for (const i of this.previewModal.items) {
                if (i.link_status === 'linked_to_target') ids.add(i.id);
            }
            return ids.size;
        },

        linkedElsewhereCount() {
            if (!this.previewModal?.items) return 0;
            const ids = new Set();
            for (const i of this.previewModal.items) {
                if (i.link_status === 'linked_elsewhere') ids.add(i.id);
            }
            return ids.size;
        },

        notInsertableCount() {
            if (!this.previewModal?.items) return 0;
            const ids = new Set();
            for (const i of this.previewModal.items) {
                if (i.link_status === 'not_insertable') ids.add(i.id);
            }
            return ids.size;
        },

        // Entries that WILL be linked: all would_link groups that the user has not excluded.
        applyablePreviewCount() {
            return this.groupedPreview.filter(g => g.hasWouldLink && !this.excludedEntryIds.includes(g.id)).length;
        },

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
                    return (this.wouldLinkForRule(a) - this.wouldLinkForRule(b)) * dir;
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
            // bulkState). Then re-fetch entry hashes etc. via Inertia reload.
            const stop = this.$watch(
                () => bulkState.active,
                (current, previous) => {
                    if (previous?.kind === 'applyrule' && current === null) {
                        stop();
                        this.selectedRules = [];
                        this.applyingAll = false;
                        // Refresh page data — link counts changed.
                        if (typeof this.fetchData === 'function') this.fetchData();
                    }
                },
            );
            // Safety: stop watcher after 30 minutes.
            setTimeout(() => stop(), 30 * 60 * 1000);
        },

        emptyRule() {
            return { keyword: '', url: '', collections: [], once_per_post: true, skip_if_exists: false, case_sensitive: false, auto_apply_on_save: 'follow_global' };
        },

        openEntrySelector() {
            this.$refs.entryPicker?.openSelector();
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

        /**
         * Entries where this rule would insert a NEW link right now.
         * = match_count minus linked-to-target minus linked-elsewhere minus not-insertable.
         * May be stale if the index is old (same staleness caveat as the rest of the table).
         */
        wouldLinkForRule(rule) {
            const total = rule.match_count || 0;
            const toTarget = rule.linked_count || 0;
            const elsewhere = rule.linked_elsewhere_count || 0;
            const notInsertable = rule.not_insertable_count || 0;
            return Math.max(0, total - toTarget - elsewhere - notInsertable);
        },

        findEntryTitle(entryId) {
            return this.entries.find(e => e.id === entryId)?.title || null;
        },

        editRule(rule) {
            this.editingRule = rule;
            this.newRule = {
                keyword: rule.keyword,
                url: rule.url,
                collections: Array.isArray(rule.collections) ? [...rule.collections] : [],
                once_per_post: rule.once_per_post,
                skip_if_exists: rule.skip_if_exists,
                case_sensitive: rule.case_sensitive,
                auto_apply_on_save: this.normalizeAutoApply(rule.auto_apply_on_save),
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
                const el = this.ruleRowRefs[ruleId];
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
                    this.applyAsyncRuleId = null;
                    this.applyAsyncProgress = null;
                    return;
                }
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const reason = data?.error || data?.message || `HTTP ${response.status}`;
                    Statamic.$toast.error(`Could not start apply: ${reason}`);
                    this.applyAsyncRuleId = null;
                    this.applyAsyncProgress = null;
                    return;
                }
            } catch (e) {
                Statamic.$toast.error(`Could not start apply: ${e.message || 'network error'}`);
                this.applyAsyncRuleId = null;
                this.applyAsyncProgress = null;
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
                    this.stopApplyAsyncPolling();
                    this.applyAsyncProgress = null;

                    if (!wasActive) {
                        // Stale done from a previous session — ignore.
                        return;
                    }

                    const linksAdded = status.links_added || 0;
                    this.applyAsyncResult = {
                        rule_keyword: status.rule_keyword || '',
                        links_added: linksAdded,
                        cancelled: status.phase === 'cancelled',
                    };

                    // Update the rule row's counts so the table reflects what just happened.
                    const rule = this.rules.find(r => r.id === status.rule_id);
                    if (rule && linksAdded > 0) {
                        rule.links_added = (rule.links_added || 0) + linksAdded;
                        rule.linked_count = (rule.linked_count || 0) + linksAdded;
                    }

                    // Toast firing is delegated to LinkwiseLayout (cross-tab dedup'd).
                    // We only update local UI state here.
                    this.applyAsyncRuleId = null;
                } else if (status.phase === 'error') {
                    this.stopApplyAsyncPolling();
                    this.applyAsyncProgress = null;
                    this.applyAsyncRuleId = null;
                    // Error toast also handled by LinkwiseLayout.
                } else {
                    // idle / unknown — nothing to attach to
                    this.stopApplyAsyncPolling();
                    this.applyAsyncProgress = null;
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
            this.previewStatusFilter = '';
        },

        togglePreviewSort(field) {
            if (this.previewSortField === field) {
                this.previewSortDirection = this.previewSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.previewSortField = field;
                this.previewSortDirection = 'asc';
            }
        },

        toggleExclude(entryId) {
            const idx = this.excludedEntryIds.indexOf(entryId);
            if (idx > -1) this.excludedEntryIds.splice(idx, 1);
            else this.excludedEntryIds.push(entryId);
        },

        toggleUnlinkSelection(entryId) {
            const idx = this.selectedUnlinkIds.indexOf(entryId);
            if (idx > -1) this.selectedUnlinkIds.splice(idx, 1);
            else this.selectedUnlinkIds.push(entryId);
        },

        /**
         * Header-checkbox handler. Toggles every togglable row's selection
         * state in one shot, across both action pools:
         *   - would_link rows  → in/out of excludedEntryIds (apply scope)
         *   - linked_to_target → in/out of selectedUnlinkIds (unlink scope)
         *
         * The single click updates BOTH the "Apply (X)" and "Unlink (Y)"
         * counters consistently. User then clicks whichever action button
         * matches their intent.
         */
        togglePreviewSelectAll() {
            const togglable = this.togglablePreviewRows;
            if (togglable.length === 0) return;

            if (this.allPreviewRowsSelected) {
                // De-select all: exclude every would_link, clear unlink set.
                const wouldLinkIds = togglable
                    .filter(i => i.link_status === 'would_link')
                    .map(i => i.id);
                // Merge with any pre-existing exclusions outside the visible
                // togglable set (filter-aware behaviour) so we don't
                // accidentally re-include rows the user excluded earlier.
                this.excludedEntryIds = Array.from(
                    new Set([...this.excludedEntryIds, ...wouldLinkIds]),
                );
                const linkedTargetIds = new Set(
                    togglable.filter(i => i.link_status === 'linked_to_target').map(i => i.id),
                );
                this.selectedUnlinkIds = this.selectedUnlinkIds.filter(
                    id => ! linkedTargetIds.has(id),
                );
            } else {
                // Select all: clear exclusions of visible would_link rows,
                // add visible linked_to_target rows to the unlink set.
                const wouldLinkIds = new Set(
                    togglable.filter(i => i.link_status === 'would_link').map(i => i.id),
                );
                this.excludedEntryIds = this.excludedEntryIds.filter(
                    id => ! wouldLinkIds.has(id),
                );
                const newUnlinkIds = togglable
                    .filter(i => i.link_status === 'linked_to_target')
                    .map(i => i.id);
                this.selectedUnlinkIds = Array.from(
                    new Set([...this.selectedUnlinkIds, ...newUnlinkIds]),
                );
            }
        },

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
                    }
                },
            );
            // Safety: stop watcher after 30 min in case the bulk dies silently.
            setTimeout(() => stop(), 30 * 60 * 1000);
        },

        async applyFromPreview() {
            if (!this.previewModal?.ruleId || this.applyingPreview) return;
            if (this.applyablePreviewCount === 0) return;

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


        highlightKeyword,

        truncateUrl(url) {
            return url.length > 50 ? url.substring(0, 47) + '...' : url;
        },

        // Map the tristate to a readable label for the Settings column.
        formatAutoApply(value) {
            return {
                always: 'Always',
                never: 'Never',
                follow_global: 'Follow global',
            }[value] || 'Follow global';
        },

        // Coerce backend values to the tri-state. Backwards-compat for old
        // rules that stored a bool: true → 'follow_global', false → 'never'.
        // Anything else → 'follow_global' (the safe default).
        normalizeAutoApply(value) {
            if (value === true) return 'follow_global';
            if (value === false) return 'never';
            if (['follow_global', 'always', 'never'].includes(value)) return value;
            return 'follow_global';
        },

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

        formatExactDate(iso) {
            if (!iso) return '';
            try {
                return new Date(iso).toLocaleString();
            } catch {
                return iso;
            }
        },

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
