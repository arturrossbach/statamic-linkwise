<template>
    <Stack :open="modal !== null" @update:open="close" :title="modal?.title || ''">
        <div v-if="modal">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                <template v-if="modal.mode === 'inbound'">
                    <strong>{{ modal.suggestions.length }}</strong><template v-if="modal.totalAvailable > modal.suggestions.length"> of {{ modal.totalAvailable }}</template> suggestion(s) — entries that could link <strong>to this page</strong>. Select and click "Add" to insert.
                </template>
                <template v-else>
                    <strong>{{ (modal.groups || []).length }}</strong> suggestion(s) — phrases in this entry that could link <strong>to other entries</strong>. Select and click "Add" to insert.
                </template>
            </p>

            <!--
                A — Educational guide. Collapsed by default (<details> — same
                dark-mode-verified pattern as NotificationsAccordion /
                OverviewTab recommendations). EVERY claim is a verbatim quote
                from official Google Search Central documentation — no
                invented statistics, no thresholds, no "Google penalises X".
                Sources:
                  - developers.google.com/search/docs/crawling-indexing/links-crawlable
                  - developers.google.com/search/docs/essentials/spam-policies
            -->
            <details class="mb-3 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                <summary class="cursor-pointer select-none px-3 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md">
                    What Google says about internal links
                </summary>
                <div class="px-3 pb-3 pt-1 text-xs text-gray-600 dark:text-gray-400 space-y-2 leading-relaxed">
                    <p>Linkwise surfaces opportunities; the guidance below is Google's, quoted so you can decide for yourself.</p>
                    <template v-if="modal.mode === 'inbound'">
                        <p><strong class="text-gray-700 dark:text-gray-300">On internal links:</strong> “Every page you care about should have a link from at least one other page on your site.”</p>
                        <p><strong class="text-gray-700 dark:text-gray-300">On anchor text:</strong> it should be “descriptive, reasonably concise, and relevant to the page that it's on and to the page it links to,” and you should “resist the urge to cram every keyword” — keyword stuffing is “a violation of our spam policies.”</p>
                        <p>You can edit any suggestion's phrase by double-clicking adjacent words before adding it.</p>
                    </template>
                    <template v-else>
                        <p><strong class="text-gray-700 dark:text-gray-300">On anchor text:</strong> it should be “descriptive, reasonably concise, and relevant to the page that it's on and to the page it links to.”</p>
                        <p><strong class="text-gray-700 dark:text-gray-300">On wording:</strong> “Write as naturally as possible, and resist the urge to cram every keyword” — keyword stuffing is “a violation of our spam policies.”</p>
                        <p>You can edit any suggestion's phrase by double-clicking adjacent words before adding it.</p>
                    </template>
                    <p>Source: <a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline">Google Search Central — Link best practices</a>.</p>
                </div>
            </details>

            <!--
                B — "Pre-flight observations" (inbound only). One combined
                info box, each bullet a measured FACT + a hard-fact anchor:
                  - anchor concentration → fact + Google "descriptive/varied"
                  - generic anchor       → Google's OWN discouraged list (verbatim)
                  - low match score      → Linkwise's OWN metric (labelled, not SEO)
                No invented "harm" thresholds, no ranking claims, no Linkwise
                prescription. Renders nothing when the list is empty (no
                "all clear" theatre — that'd be an unprovable claim too).
            -->
            <Alert
                v-if="inboundObservations.length > 0"
                variant="info"
                class="mb-3"
            >
                <p class="text-sm">
                    <strong>{{ inboundObservations[0].count }} of {{ inboundObservations[0].total }}</strong>
                    suggestions use the same anchor text “<strong>{{ inboundObservations[0].anchor }}</strong>”.
                    Google recommends anchor text be “descriptive, reasonably concise, and relevant” — it makes no published statement about repeating one anchor across internal links specifically, so the call is yours. You can edit a row's phrase by double-clicking words.
                </p>
            </Alert>

            <div v-if="loading" class="py-8 text-center text-gray-400">
                Loading suggestions...
            </div>
            <div v-else-if="isEmpty">
                <Alert
                    v-if="modal.expectedCount > 0"
                    variant="warning"
                    heading="Count was out of date"
                >
                    <p class="text-sm">
                        The Links Report showed <strong>{{ modal.expectedCount }}</strong>
                        suggestion{{ modal.expectedCount === 1 ? '' : 's' }}, but none are still valid.
                        Entries were edited since the last scan.
                    </p>
                    <div class="mt-3">
                        <Button
                            v-if="rebuildUrl"
                            text="Re-scan content"
                            icon="sync"
                            variant="primary"
                            size="sm"
                            :loading="rescanning"
                            :disabled="rescanning"
                            @click="triggerRescan"
                        />
                    </div>
                </Alert>
                <!-- Empty state: instead of a dead-end message, give the user a
                     concrete path forward. Tips depend on mode (inbound vs
                     outbound) — what helps for "no one links TO this page" is
                     different from "this page links to no one". -->
                <div v-else class="py-6 px-2">
                    <div class="text-center mb-5">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-10 mx-auto mb-2 text-gray-300 dark:text-gray-600">
                            <path d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.354a15.998 15.998 0 01-3 0M3 16.5l2.25-9 2.25 9M3.75 18h3M16.5 16.5l2.25-9 2.25 9m-4.5 1.5h3m-9 5.25l-3-3.75v-7.5a3 3 0 013-3h6a3 3 0 013 3v7.5l-3 3.75H10.5z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            <template v-if="modal.mode === 'inbound'">No inbound opportunities yet</template>
                            <template v-else>No outbound suggestions yet</template>
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md mx-auto">
                            <template v-if="modal.mode === 'inbound'">
                                No other entries currently mention topics that match this page.
                            </template>
                            <template v-else>
                                This entry doesn't yet mention topics covered by your other entries.
                            </template>
                        </p>
                    </div>

                    <div class="max-w-md mx-auto">
                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-2">Try this:</p>
                        <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <template v-if="modal.mode === 'inbound'">
                                <li class="flex gap-2">
                                    <span class="text-blue-500 shrink-0">→</span>
                                    <span>
                                        Set <a href="/cp/linkwise/keywords" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">Custom Target Keywords</a>
                                        for this page so Linkwise prioritizes it when other entries mention those topics.
                                    </span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="text-blue-500 shrink-0">→</span>
                                    <span>
                                        Add an <a href="/cp/linkwise/autolink" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">Auto-Linking Rule</a>
                                        to link a specific keyword across the site to this page.
                                    </span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="text-blue-500 shrink-0">→</span>
                                    <span>Write more content in other entries that references this page's topic.</span>
                                </li>
                            </template>
                            <template v-else>
                                <li class="flex gap-2">
                                    <span class="text-blue-500 shrink-0">→</span>
                                    <span>
                                        Add longer or richer content — short entries rarely contain matchable phrases.
                                    </span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="text-blue-500 shrink-0">→</span>
                                    <span>
                                        Set up <a href="/cp/linkwise/autolink" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">Auto-Linking Rules</a>
                                        to automatically link specific keywords to target pages.
                                    </span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="text-blue-500 shrink-0">→</span>
                                    <span>
                                        Set <a href="/cp/linkwise/keywords" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">Custom Target Keywords</a>
                                        on related entries so they get prioritized when this page mentions their topic.
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
            <template v-else>
                <!-- Action bar -->
                <div class="h-9 mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Button v-if="selected.length > 0 && !inserting && !globalBulkRunning" @click="insertSuggestions" :text="'Add ' + selected.length + ' link' + (selected.length !== 1 ? 's' : '')" variant="primary" size="sm" />
                        <span v-else-if="selected.length > 0 && globalBulkRunning" class="text-xs text-gray-500 dark:text-gray-400" role="status" aria-live="polite">
                            Another bulk operation is running — wait for it to finish.
                        </span>
                        <span v-if="inserting" class="text-xs text-gray-500 dark:text-gray-400 inline-flex items-center gap-2" role="status" aria-live="polite">
                            <Icon name="loading" class="size-4 text-blue-500" />
                            Adding {{ insertProgress }}...
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <!--
                            Show-ignored toggle. Only rendered when at least one
                            row has been ignored — otherwise there's nothing to
                            reveal and the chip would be noise. Default OFF so
                            the modal opens "actionable items only" (this is
                            what the Links Report badge count subtracts to —
                            counts stay in sync visually).
                        -->
                        <button
                            v-if="ignoredCount > 0"
                            type="button"
                            class="text-xs px-2 py-1 rounded-lg border inline-flex items-center gap-1.5"
                            :class="showIgnored
                                ? 'bg-blue-50 text-blue-700 border-blue-300 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-700'
                                : 'bg-gray-50 text-gray-600 border-gray-300 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700'"
                            @click="showIgnored = !showIgnored"
                            v-tooltip="showIgnored ? 'Hide ignored suggestions' : 'Reveal ignored suggestions (to un-ignore)'"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                            {{ showIgnored ? 'Hide ignored' : 'Show ignored' }} ({{ ignoredCount }})
                        </button>

                        <div v-if="availableMatchOptions.length >= 2" class="flex items-center gap-2">
                            <label class="text-xs text-gray-500 dark:text-gray-400">Match type:</label>
                            <select v-model="matchFilter" class="text-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-2 py-1">
                                <option value="">All types</option>
                                <option v-for="opt in availableMatchOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Error summary -->
                <div v-if="errors.length > 0" class="mb-3 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                    <p class="text-sm font-medium text-red-700 dark:text-red-400">{{ errors.length }} link(s) failed:</p>
                    <ul class="mt-1 text-xs text-red-600 dark:text-red-400 list-disc list-inside">
                        <li v-for="(err, i) in errors" :key="i">
                            <strong>{{ err.title }}</strong>: {{ err.error }}
                        </li>
                    </ul>
                </div>

                <!-- Inbound: Flat list -->
                <Panel v-if="modal.mode === 'inbound'">
                    <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="w-8">
                                    <input type="checkbox" class="rounded" :checked="allSelected" @change="toggleSelectAll" />
                                </th>
                                <SortableHeader label="Source Entry" :active="sortField === 'source_title'" :direction="sortDir" @sort="toggleSort('source_title')">
                                    <HelpIcon tooltip="The entry where the link will be inserted." />
                                </SortableHeader>
                                <SortableHeader label="Suggested Phrase" :sortable="false">
                                    <HelpIcon tooltip="The sentence containing the keyword. Double-click adjacent words to expand/shrink the linked text." />
                                </SortableHeader>
                                <SortableHeader label="Match" align="center" :active="sortField === 'score'" :direction="sortDir" @sort="toggleSort('score')">
                                    <HelpIcon tooltip="How well this suggestion matches. Higher = more relevant." />
                                </SortableHeader>
                                <SortableHeader label="Rule" :sortable="false" align="right">
                                    <HelpIcon tooltip="Promote this suggestion to a permanent Auto-Link rule. The keyword will then auto-link to this entry across all future content." />
                                </SortableHeader>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="s in sortedInbound"
                                :key="s.source_entry_id"
                                :class="{
                                    'bg-green-50 dark:bg-green-900/10 opacity-60': s._status === 'inserted',
                                    'bg-red-50 dark:bg-red-900/10': s._status === 'failed',
                                    'opacity-60': s.is_ignored && s._status === 'pending',
                                }"
                            >
                                <td>
                                    <input v-if="s._status === 'pending' && !s.is_ignored" type="checkbox" class="rounded" :checked="selected.includes(s)" @change="toggleSelect(s)" />
                                    <span v-else-if="s._status === 'pending' && s.is_ignored" class="text-xs text-gray-400" v-tooltip="'Un-ignore this suggestion before you can add it'">—</span>
                                    <Icon v-else-if="s._status === 'inserting'" name="loading" class="size-4 text-blue-500" />
                                    <span v-else-if="s._status === 'inserted'" class="text-xs text-green-500" aria-label="Inserted">&#10003;</span>
                                    <span v-else-if="s._status === 'failed'" class="text-xs text-red-500" aria-label="Failed">✕</span>
                                </td>
                                <td>
                                    <a v-if="s.source_edit_url" :href="s.source_edit_url" target="_blank" class="font-medium text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400">{{ s.source_title }}</a>
                                    <span v-else class="font-medium text-gray-900 dark:text-gray-100">{{ s.source_title }}</span>
                                    <!-- V1.2 Cross-Tab-E — source locale badge. Hides on null/empty. -->
                                    <span v-if="s.source_locale && hasMultipleLocales" class="ml-1 inline-flex items-center px-1 py-0.5 rounded text-[10px] uppercase tracking-wider bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400" v-tooltip="'Site locale'">{{ s.source_locale }}</span>
                                    <div class="text-xs text-gray-400">{{ s.source_collection }}</div>
                                    <div v-if="s._status === 'failed' && s._error" class="text-xs text-red-500 mt-1">{{ s._error }}</div>
                                </td>
                                <td>
                                    <SuggestedPhrase
                                        :sentence-context="s.sentence_context"
                                        :anchor="s._anchor"
                                        :original-anchor="s._originalAnchor"
                                        :disabled="s._status !== 'pending' || s.is_ignored"
                                        :truncated-before="s.context_truncated_start || false"
                                        :truncated-after="s.context_truncated_end || false"
                                        @update:anchor="s._anchor = $event"
                                        @reset="s._anchor = s._originalAnchor"
                                    />
                                    <button v-if="s.match_reason" @click="s._showReason = !s._showReason" class="text-xs text-blue-500 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 underline cursor-pointer mt-0.5 inline-flex items-center gap-0.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3" viewBox="0 0 24 24" fill="currentColor"><path d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/></svg>
                                        Why?
                                    </button>
                                    <div v-if="s._showReason" class="text-xs text-gray-500 dark:text-gray-400 mt-1 px-2 py-1 bg-gray-50 dark:bg-gray-800 rounded">{{ s.match_reason }}</div>
                                </td>
                                <td class="text-center whitespace-nowrap">
                                    <span :class="matchBadgeClass(s.match_type)" class="text-xs px-1.5 py-0.5 rounded-full cursor-help" v-tooltip="matchTooltip(s.match_type)">{{ matchLabel(s.match_type) }}</span>
                                    <span class="text-xs text-gray-400 ml-1">{{ Math.round(s.score * 100) }}%</span>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <Button v-if="autolinkStoreUrl && !s._promotedToRule && !s.is_ignored" size="xs" variant="default" icon="plus" :loading="s._promoting" @click="promoteSuggestionToRule(s)" v-tooltip="`Always auto-link &quot;${s._anchor}&quot; → this entry, in any future content`" />
                                        <span v-else-if="s._promotedToRule" class="text-xs text-green-500 inline-flex items-center gap-1" v-tooltip="'Rule created — open Auto-Linking tab to manage'">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                            Rule
                                        </span>
                                        <!--
                                            Ignore / Un-ignore button. Direction:
                                            current modal entry is the "source" of the
                                            pair, s.source_entry_id (the entry that
                                            would link IN) is the "target" in the
                                            ignored-pair tuple. The store normalises
                                            either way — direction is irrelevant for
                                            persistence.
                                        -->
                                        <Button
                                            v-if="s._status === 'pending' && !s.is_ignored && ignoreSuggestionUrl"
                                            size="xs"
                                            variant="default"
                                            icon="trash"
                                            :loading="!!ignoreBusy[ignoreKey(s, false)]"
                                            @click="ignoreItem(s, false)"
                                            v-tooltip="'Ignore this suggestion (hides the pair until you un-ignore it)'"
                                        />
                                        <Button
                                            v-else-if="s._status === 'pending' && s.is_ignored && unignoreSuggestionUrl"
                                            size="xs"
                                            variant="default"
                                            icon="history"
                                            :loading="!!ignoreBusy[ignoreKey(s, false)]"
                                            @click="unignoreItem(s, false)"
                                            v-tooltip="'Un-ignore — bring this suggestion back into normal view'"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table></div>
                </Panel>

                <!-- Outbound: Grouped -->
                <Panel v-else>
                    <div class="overflow-x-auto"><table data-size="sm" class="data-table w-full text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="w-8">
                                    <input type="checkbox" class="rounded" :checked="allSelected" @change="toggleSelectAll" />
                                </th>
                                <SortableHeader label="Suggested Phrase" :sortable="false">
                                    <HelpIcon tooltip="The sentence containing the keyword. Double-click adjacent words to expand/shrink the linked text." />
                                </SortableHeader>
                                <SortableHeader label="Target Entry" :active="sortField === 'target_title'" :direction="sortDir" @sort="toggleSort('target_title')">
                                    <HelpIcon tooltip="The entry this text will link to. Click alternatives to choose a different target." />
                                </SortableHeader>
                                <SortableHeader label="Match" align="center" :active="sortField === 'score'" :direction="sortDir" @sort="toggleSort('score')" />
                                <SortableHeader label="Rule" :sortable="false" align="right">
                                    <HelpIcon tooltip="Promote this phrase to a permanent Auto-Link rule. The keyword will then auto-link to the chosen target across all future content." />
                                </SortableHeader>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="group in sortedOutbound" :key="group.key">
                                <tr :class="{
                                    'bg-green-50 dark:bg-green-900/10': group._status === 'inserted',
                                    'bg-red-50 dark:bg-red-900/10': group._status === 'failed',
                                    'opacity-60': group._status === 'pending' && (selectedTarget(group)?.is_ignored),
                                }">
                                    <td>
                                        <input v-if="group._status === 'pending' && !selectedTarget(group)?.is_ignored" type="checkbox" :checked="selected.includes(group.key)" @change="toggleSelect(group.key)" class="rounded" />
                                        <span v-else-if="group._status === 'pending' && selectedTarget(group)?.is_ignored" class="text-xs text-gray-400" v-tooltip="'Un-ignore this suggestion before you can add it'">—</span>
                                        <Icon v-else-if="group._status === 'inserting'" name="loading" class="size-4 text-blue-500" />
                                        <span v-else-if="group._status === 'inserted'" class="text-xs text-green-500" aria-label="Inserted">&#10003;</span>
                                        <span v-else-if="group._status === 'failed'" class="text-xs text-red-500" aria-label="Failed">✕</span>
                                    </td>
                                    <td>
                                        <SuggestedPhrase
                                            :sentence-context="group.sentence_context"
                                            :anchor="group._anchor"
                                            :original-anchor="group._originalAnchor"
                                            :disabled="group._status !== 'pending' || selectedTarget(group)?.is_ignored"
                                            :truncated-before="group._truncatedStart || false"
                                            :truncated-after="group._truncatedEnd || false"
                                            @update:anchor="group._anchor = $event"
                                            @reset="group._anchor = group._originalAnchor"
                                        />
                                        <button v-if="group.targets[0]?.match_reason" @click="group._showReason = !group._showReason" class="text-xs text-blue-500 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 underline cursor-pointer mt-0.5 inline-flex items-center gap-0.5">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="size-3" viewBox="0 0 24 24" fill="currentColor"><path d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/></svg>
                                            Why?
                                        </button>
                                        <div v-if="group._showReason" class="text-xs text-gray-500 dark:text-gray-400 mt-1 px-2 py-1 bg-gray-50 dark:bg-gray-800 rounded">{{ group.targets[0]?.match_reason }}</div>
                                        <div v-if="group._status === 'failed' && group._error" class="text-xs text-red-500 mt-1">{{ group._error }}</div>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            <a v-if="selectedTarget(group)?.target_edit_url" :href="selectedTarget(group).target_edit_url" target="_blank" class="text-xs font-medium text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400">{{ selectedTarget(group)?.target_title }}</a>
                                            <span v-else class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ selectedTarget(group)?.target_title }}</span>
                                            <!-- V1.2 Cross-Tab-E — target locale badge. -->
                                            <span v-if="selectedTarget(group)?.target_locale && hasMultipleLocales" class="inline-flex items-center px-1 py-0.5 rounded text-[10px] uppercase tracking-wider bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400" v-tooltip="'Site locale'">{{ selectedTarget(group)?.target_locale }}</span>
                                            <button
                                                v-if="group.targets.length > 1 && group._status === 'pending'"
                                                @click="group._expanded = !group._expanded"
                                                class="text-xs text-blue-500 hover:text-blue-700 dark:text-blue-400 shrink-0 inline-flex items-center gap-0.5 cursor-pointer"
                                            >
                                                {{ group._expanded ? '−' : '+' }} {{ group.targets.length }} alternatives
                                            </button>
                                        </div>
                                    </td>
                                    <td class="text-center whitespace-nowrap">
                                        <span :class="matchBadgeClass(group.targets[0]?.match_type)" class="text-xs px-1.5 py-0.5 rounded-full cursor-help" v-tooltip="matchTooltip(group.targets[0]?.match_type)">{{ matchLabel(group.targets[0]?.match_type) }}</span>
                                        <span class="text-xs text-gray-400 ml-1">{{ Math.round((group.targets[0]?.score || 0) * 100) }}%</span>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <Button v-if="autolinkStoreUrl && !group._promotedToRule && group._status === 'pending' && !selectedTarget(group)?.is_ignored" size="xs" variant="default" icon="plus" :loading="group._promoting" @click="promoteGroupToRule(group)" v-tooltip="`Always auto-link &quot;${group._anchor}&quot; → &quot;${selectedTarget(group)?.target_title}&quot;, in any future content`" />
                                            <span v-else-if="group._promotedToRule" class="text-xs text-green-500 inline-flex items-center gap-1" v-tooltip="'Rule created — open Auto-Linking tab to manage'">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                                Rule
                                            </span>
                                            <!--
                                                Outbound: ignore acts on the currently-
                                                selected target inside the group (radio
                                                button in the accordion picks it). Ignoring
                                                one target out of N hides only that pair;
                                                the group itself stays visible as long as
                                                at least one target remains actionable —
                                                this matches sortedOutbound's "some
                                                non-ignored" hide-rule.
                                            -->
                                            <Button
                                                v-if="group._status === 'pending' && selectedTarget(group) && !selectedTarget(group).is_ignored && ignoreSuggestionUrl"
                                                size="xs"
                                                variant="default"
                                                icon="trash"
                                                :loading="!!ignoreBusy[ignoreKey(selectedTarget(group), true)]"
                                                @click="ignoreItem(selectedTarget(group), true)"
                                                v-tooltip="'Ignore this suggestion (hides the pair until you un-ignore it)'"
                                            />
                                            <Button
                                                v-else-if="group._status === 'pending' && selectedTarget(group) && selectedTarget(group).is_ignored && unignoreSuggestionUrl"
                                                size="xs"
                                                variant="default"
                                                icon="history"
                                                :loading="!!ignoreBusy[ignoreKey(selectedTarget(group), true)]"
                                                @click="unignoreItem(selectedTarget(group), true)"
                                                v-tooltip="'Un-ignore — bring this suggestion back into normal view'"
                                            />
                                        </div>
                                    </td>
                                </tr>
                                <!-- Accordion -->
                                <tr v-if="group._expanded && group._status === 'pending'" v-for="target in group.targets" :key="target.target_entry_id">
                                    <td colspan="5" class="!p-0">
                                        <div
                                            class="flex items-center gap-4 px-6 py-2 cursor-pointer border-t border-gray-100 dark:border-gray-700/50"
                                            :class="{
                                                'bg-blue-50 dark:bg-blue-900/10': group._selectedTarget === target.target_entry_id,
                                                'hover:bg-gray-50 dark:hover:bg-gray-800/50': group._selectedTarget !== target.target_entry_id,
                                            }"
                                            @click="group._selectedTarget = target.target_entry_id"
                                        >
                                            <input type="radio" :name="'suggest-target-' + group.key" :value="target.target_entry_id" v-model="group._selectedTarget" class="text-blue-500 shrink-0" />
                                            <div class="flex-1 text-xs">
                                                <a v-if="target.target_edit_url" :href="target.target_edit_url" target="_blank" class="font-medium text-gray-900 dark:text-gray-100 hover:text-blue-600 dark:hover:text-blue-400" @click.stop>{{ target.target_title }}</a>
                                                <span v-else class="font-medium text-gray-900 dark:text-gray-100">{{ target.target_title }}</span>
                                                <span class="text-gray-400 ml-2">{{ target.target_collection }}</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table></div>
                </Panel>
            </template>
        </div>
    </Stack>
</template>

<script>
import { Panel, Button, Stack, Icon, Alert } from '@statamic/cms/ui';
import HelpIcon from '../shared/HelpIcon.vue';
import SortableHeader from '../shared/SortableHeader.vue';
import SuggestedPhrase from '../shared/SuggestedPhrase.vue';
import { bulkState } from '../../services/bulkOperationService.js';

export default {
    components: { Panel, Button, Stack, Icon, Alert, HelpIcon, SortableHeader, SuggestedPhrase },

    props: {
        modal: { type: Object, default: null },
        loading: { type: Boolean, default: false },
        inserting: { type: Boolean, default: false },
        insertProgress: { type: String, default: '' },
        // V1.2.1 single-locale badge gate, propagated from LinksReportTab
        // (sister-bug fix 2026-05-27): the source/target locale badges must
        // only render on genuinely multilingual installs (≥2 locales), same
        // rule as LinksReportTab's per-row badge. Without this the badge
        // showed an "EN" pill on single-locale sites — the exact v1.2.1
        // complaint, never propagated to this modal.
        hasMultipleLocales: { type: Boolean, default: false },
        rebuildUrl: { type: String, default: '' },
        autolinkStoreUrl: { type: String, default: '' },
        // Klasse-10 guarantee-stack 2026-05-22 — per-pair ignored
        // suggestions. POST = ignore, DELETE = un-ignore. Pair is
        // undirected; backend normalises the (entryA, entryB) tuple
        // by sorting ASCII-ascending before persisting.
        ignoreSuggestionUrl: { type: String, default: '' },
        unignoreSuggestionUrl: { type: String, default: '' },
    },

    emits: ['close', 'insert', 'ignored'],

    data() {
        return {
            selected: [],
            sortField: '',
            sortDir: 'asc',
            matchFilter: '',
            rescanning: false,
            // Show-ignored toggle: default OFF so the modal opens with
            // only actionable suggestions visible. Header chip shows
            // "Show ignored (N)"; clicking flips this — ignored rows
            // reveal greyed out with an undo ↩ button.
            showIgnored: false,
            // Per-item in-flight flag for the ignore/un-ignore POST/
            // DELETE round-trip; prevents double-click double-fires.
            // Keyed by inbound source_entry_id OR outbound group.key.
            ignoreBusy: {},
        };
    },

    watch: {
        modal(val) {
            if (val) {
                this.selected = [];
                this.sortField = '';
                this.sortDir = 'asc';
                this.matchFilter = '';
                this.showIgnored = false;
                this.ignoreBusy = {};
            }
        },
    },

    computed: {
        // True while ANY bulk op is running anywhere in Linkwise. Used to
        // hide the "Add N links" button so the user can't queue a second
        // bulk while the first is in flight.
        globalBulkRunning() {
            return bulkState.active !== null;
        },

        isEmpty() {
            if (!this.modal) return true;
            if (this.modal.mode === 'outbound') return (this.modal.groups || []).length === 0;
            return (this.modal.suggestions || []).length === 0;
        },

        /**
         * Match-type options derived from the actual suggestions.
         * Title + stem are grouped as a single "Title" bucket.
         * Dropdown is only shown when ≥ 2 distinct buckets exist.
         */
        availableMatchOptions() {
            if (!this.modal) return [];
            const raw = this.modal.mode === 'outbound'
                ? (this.modal.groups || []).map(g => g.targets[0]?.match_type).filter(Boolean)
                : (this.modal.suggestions || []).map(s => s.match_type).filter(Boolean);

            const types = new Set(raw);
            const options = [];
            if (types.has('title') || types.has('stem')) {
                options.push({ value: 'title_all', label: 'Title' });
            }
            if (types.has('keyword')) {
                options.push({ value: 'keyword', label: 'Keyword' });
            }
            if (types.has('custom')) {
                options.push({ value: 'custom', label: 'Custom' });
            }
            return options;
        },

        allSelected() {
            // "Select All" semantics must match the visible/actionable
            // rows — i.e. exclude is_ignored items. Otherwise checking
            // the master checkbox adds ignored rows to `selected` even
            // though they're hidden in the default-filtered table and
            // can't be inserted (the ignore-button row never reaches
            // the insert pipeline). User-bug 2026-05-22: 3 ignored +
            // 3 actionable rows → master-check showed "Add 6 links"
            // even though only 3 should land.
            if (!this.modal) return false;
            if (this.modal.mode === 'outbound') {
                const selectable = (this.modal.groups || []).filter(g => {
                    if (g._status !== 'pending') return false;
                    const sel = (g.targets || []).find(t => t.target_entry_id === g._selectedTarget) || (g.targets || [])[0];
                    return ! (sel && sel.is_ignored);
                });
                return selectable.length > 0 && selectable.every(g => this.selected.includes(g.key));
            }
            const selectable = (this.modal.suggestions || []).filter(s => s._status === 'pending' && ! s.is_ignored);
            return selectable.length > 0 && selectable.every(s => this.selected.includes(s));
        },

        /**
         * How many ignored rows the current modal carries. Drives the
         * header chip "Show ignored (N)" — when 0, the chip is hidden
         * because there's nothing to toggle.
         *
         * Inbound: count is_ignored=true suggestions.
         * Outbound: count groups where EVERY target is ignored (matches
         * the hide-rule in sortedOutbound).
         */
        ignoredCount() {
            if (!this.modal) return 0;
            if (this.modal.mode === 'outbound') {
                // Bug A 2026-05-22 fix: count individual ignored
                // targets, NOT only fully-ignored groups. Previous
                // logic (`every(t => t.is_ignored)`) returned 0 when
                // a group had 2+ targets and only one was ignored →
                // "Show ignored (N)" chip was hidden → user couldn't
                // navigate back to the ignored row.
                return (this.modal.groups || []).reduce(
                    (acc, g) => acc + (g.targets || []).filter(t => t.is_ignored).length,
                    0,
                );
            }
            return (this.modal.suggestions || []).filter(s => s.is_ignored).length;
        },

        errors() {
            if (!this.modal) return [];
            if (this.modal.mode === 'outbound') {
                return (this.modal.groups || [])
                    .filter(g => g._status === 'failed' && g._error)
                    .map(g => ({ title: g._anchor, error: g._error }));
            }
            return (this.modal.suggestions || [])
                .filter(s => s._status === 'failed' && s._error)
                .map(s => ({ title: s.source_title, error: s._error }));
        },

        /**
         * "Pre-flight observations" for inbound suggestions — a combined set
         * of FACTS about the suggestions in front of the user, each paired
         * with a hard-fact anchor. No invented thresholds for "harm", no
         * ranking-claims, no Linkwise prescription — every line is either a
         * measured count or a verbatim Google quote. The user decides.
         *
         * Returns [] when nothing notable (the section then doesn't render —
         * deliberately NO "all clear ✅", which would itself be an unprovable
         * claim that the suggestions are good).
         *
         * Each entry: { key, kind: 'anchor'|'generic'|'confidence', ...data }.
         */
        inboundObservations() {
            if (!this.modal || this.modal.mode !== 'inbound') return [];

            // Actionable set = non-ignored suggestions the user could add.
            const actionable = (this.modal.suggestions || []).filter(s => !s.is_ignored);
            const total = actionable.length;
            if (total === 0) return [];

            const out = [];

            // ── Anchor concentration (the ONLY observation) ──
            // Measured fact. Comparison is trim + lowercase, NOT stemmed
            // ("Kubernetes" vs "Kubernetes pods" stay distinct).
            //
            // Display threshold = top anchor repeats ≥3× AND is ≥25% of the
            // actionable set. This is purely a "is it worth surfacing" UX
            // choice, NOT an SEO-harm claim — no source gives a numeric
            // danger point. A bare ≥2 fired on nearly every modal (anchors
            // naturally repeat) and became noise; ≥3 + ≥25% catches genuine
            // concentration (the "30 with the same anchor" case) while
            // ignoring trivial doubles.
            const groups = {};
            for (const s of actionable) {
                const key = String(s._anchor || '').trim().toLowerCase();
                if (key === '') continue;
                if (!groups[key]) groups[key] = { anchor: (s._anchor || '').trim(), count: 0 };
                groups[key].count++;
            }
            let topAnchor = null;
            for (const k in groups) {
                if (!topAnchor || groups[k].count > topAnchor.count) topAnchor = groups[k];
            }
            if (topAnchor && topAnchor.count >= 3 && (topAnchor.count / total) >= 0.25) {
                out.push({ key: 'anchor', kind: 'anchor', anchor: topAnchor.anchor, count: topAnchor.count, total });
            }

            // NOTE — two other observations were considered and DROPPED for
            // honesty (2026-05-27):
            //   - "Generic anchor" (Google's "click here"/"read more" list):
            //     exact-match only (no AI), and Linkwise derives anchors from
            //     content keywords, never generic phrases — verified 0 hits
            //     across 864 suggestions. Narrow + structurally never fires.
            //   - "Low match score": the engine's own min_score (default 0.4)
            //     already filters weaker matches before they reach this modal
            //     (min observed score 0.5), so the check was dead + redundant.
            // Both removed rather than shipped as impressive-but-dead code.

            return out;
        },

        sortedInbound() {
            if (!this.modal || this.modal.mode !== 'inbound') return [];
            let items = [...(this.modal.suggestions || [])];
            // Hide ignored rows when the toggle is off — that's the
            // default, "show me actionable items" view.
            if (!this.showIgnored) {
                items = items.filter(s => !s.is_ignored);
            }
            if (this.matchFilter) {
                if (this.matchFilter === 'title_all') {
                    items = items.filter(s => s.match_type === 'title' || s.match_type === 'stem');
                } else {
                    items = items.filter(s => s.match_type === this.matchFilter);
                }
            }
            if (!this.sortField) return items;
            const dir = this.sortDir === 'asc' ? 1 : -1;
            return items.sort((a, b) => {
                const aVal = a[this.sortField] || '';
                const bVal = b[this.sortField] || '';
                if (typeof aVal === 'number' || this.sortField === 'score') return ((aVal || 0) - (bVal || 0)) * dir;
                return String(aVal).localeCompare(String(bVal)) * dir;
            });
        },

        sortedOutbound() {
            if (!this.modal || this.modal.mode !== 'outbound') return [];
            let items = [...(this.modal.groups || [])];
            // Bug B 2026-05-22 fix: hide a group when its CURRENTLY-
            // selected target is ignored. The accordion lets the user
            // pick which target they meant — if that pick is ignored,
            // hide the row (the user can still see it via "Show
            // ignored"). Previous logic only hid groups where EVERY
            // target was ignored — which meant 2-target groups stayed
            // partially-visible with a stale top-row even after the
            // user explicitly ignored the picked target.
            if (!this.showIgnored) {
                items = items.filter(g => {
                    const sel = (g.targets || []).find(t => t.target_entry_id === g._selectedTarget) || (g.targets || [])[0];
                    return ! (sel && sel.is_ignored);
                });
            }
            if (this.matchFilter) {
                if (this.matchFilter === 'title_all') {
                    items = items.filter(g => g.targets[0]?.match_type === 'title' || g.targets[0]?.match_type === 'stem');
                } else {
                    items = items.filter(g => g.targets[0]?.match_type === this.matchFilter);
                }
            }
            if (!this.sortField) return items;
            const dir = this.sortDir === 'asc' ? 1 : -1;
            return items.sort((a, b) => {
                let aVal, bVal;
                if (this.sortField === 'score') {
                    aVal = a.targets[0]?.score || 0;
                    bVal = b.targets[0]?.score || 0;
                    return (aVal - bVal) * dir;
                } else if (this.sortField === 'target_title') {
                    aVal = this.selectedTarget(a)?.target_title || '';
                    bVal = this.selectedTarget(b)?.target_title || '';
                } else {
                    aVal = a[this.sortField] || '';
                    bVal = b[this.sortField] || '';
                }
                return String(aVal).localeCompare(String(bVal)) * dir;
            });
        },
    },

    methods: {
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
                if (!response.ok) {
                    Statamic.$toast.error('Failed to start re-scan.');
                    this.rescanning = false;
                    return;
                }
                // LinkwiseLayout polls status on mount + picks up the in-progress phase.
                // Full reload ensures user sees the progress UI in the header.
                window.location.reload();
            } catch (error) {
                Statamic.$toast.error('Failed to start re-scan.');
                console.error('[Linkwise]', error);
                this.rescanning = false;
            }
        },

        close() {
            this.$emit('close');
        },

        ariaSortFor(field) {
            if (this.sortField !== field) return 'none';
            return this.sortDir === 'asc' ? 'ascending' : 'descending';
        },

        toggleSort(field) {
            if (this.sortField === field) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortField = field;
                this.sortDir = field === 'score' ? 'desc' : 'asc';
            }
        },

        toggleSelect(item) {
            const idx = this.selected.indexOf(item);
            if (idx > -1) this.selected.splice(idx, 1);
            else this.selected.push(item);
        },

        toggleSelectAll() {
            // Mirror allSelected's filter — must exclude is_ignored
            // items so "Add N links" reflects the actually-actionable
            // count (not the hidden ignored rows). See allSelected
            // computed for the user-bug rationale (2026-05-22).
            if (this.allSelected) {
                this.selected = [];
                return;
            }
            if (this.modal.mode === 'outbound') {
                this.selected = (this.modal.groups || [])
                    .filter(g => {
                        if (g._status !== 'pending') return false;
                        const sel = (g.targets || []).find(t => t.target_entry_id === g._selectedTarget) || (g.targets || [])[0];
                        return ! (sel && sel.is_ignored);
                    })
                    .map(g => g.key);
            } else {
                this.selected = [...(this.modal.suggestions || []).filter(s => s._status === 'pending' && ! s.is_ignored)];
            }
        },

        insertSuggestions() {
            // Belt-and-suspenders: even if a future code path bypasses
            // toggleSelectAll's filter and lands an ignored row into
            // `selected` (e.g. row was un-ignored then re-ignored without
            // the un-select hook firing), we drop them here before they
            // reach the insert pipeline. Server (LinkInsertCommand) skips
            // ignored pairs per-item too — three layers, undirected pair.
            let payload = this.selected;
            if (this.modal && this.modal.mode === 'outbound') {
                payload = payload.filter(key => {
                    const g = (this.modal.groups || []).find(x => x.key === key);
                    if (!g) return false;
                    const sel = (g.targets || []).find(t => t.target_entry_id === g._selectedTarget) || (g.targets || [])[0];
                    return ! (sel && sel.is_ignored);
                });
            } else {
                payload = payload.filter(s => ! (s && s.is_ignored));
            }
            // If the filter ate everything (only ignored items leaked into
            // `selected`), skip the round-trip — server would reject with
            // 422 'insertions min:1' and the user would see a cryptic toast.
            if (payload.length === 0) {
                this.selected = [];
                return;
            }
            this.$emit('insert', payload);
            this.selected = [];
        },

        selectedTarget(group) {
            return group.targets.find(t => t.target_entry_id === group._selectedTarget) || group.targets[0];
        },

        /**
         * Promote a single inbound suggestion to a permanent Auto-Link rule.
         * keyword = current anchor (after any user-edits), target = the entry
         * the modal is currently open for. The rule will then auto-link this
         * anchor in any future content too, not just for this one suggestion.
         */
        async promoteSuggestionToRule(s) {
            if (!this.autolinkStoreUrl || !this.modal) return;
            s._promoting = true;
            try {
                await this.createRule(s._anchor, this.modal.entryId);
                s._promotedToRule = true;
            } catch (e) {
                Statamic.$toast.error(`Could not create rule: ${e.message}`);
            } finally {
                s._promoting = false;
            }
        },

        /**
         * Same as promoteSuggestionToRule but for outbound mode: keyword =
         * group anchor, target = the entry the user picked from the alternatives.
         */
        async promoteGroupToRule(group) {
            if (!this.autolinkStoreUrl) return;
            const target = this.selectedTarget(group);
            if (!target) return;
            group._promoting = true;
            try {
                await this.createRule(group._anchor, target.target_entry_id);
                group._promotedToRule = true;
            } catch (e) {
                Statamic.$toast.error(`Could not create rule: ${e.message}`);
            } finally {
                group._promoting = false;
            }
        },

        async createRule(keyword, targetEntryId) {
            const response = await window.fetch(this.autolinkStoreUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    keyword,
                    url: 'statamic://entry::' + targetEntryId,
                    once_per_post: true,
                    skip_if_exists: false,
                    case_sensitive: false,
                }),
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const reason = data?.error || data?.message || `HTTP ${response.status}`;
                throw new Error(reason);
            }
            Statamic.$toast.success(`Rule created for "${keyword}". Open Auto-Linking tab to manage.`);
        },

        matchLabel(type) {
            return { title: 'Title', stem: 'Title', keyword: 'Keyword', custom: 'Custom' }[type] || type || '?';
        },

        matchTooltip(type) {
            return {
                title: 'The entry title (or parts of it) was found in the text. Strong match.',
                stem: 'Words from the entry title were found in similar form (e.g. "building" matches "build"). Strong match.',
                keyword: 'Both entries share similar topics based on keyword analysis. Weaker match.',
                custom: 'A manually set target keyword was found in the text.',
            }[type] || 'Match type unknown';
        },

        /**
         * Toggle a (source, target) pair's ignored state. Inbound mode
         * uses (modal.entryId, s.source_entry_id); outbound mode uses
         * (modal.entryId, target.target_entry_id). The store normalises
         * the tuple direction-agnostic, so the same call works for
         * either side.
         *
         * Klasse-10 count guarantee:
         *   1. Optimistic local mutation flips is_ignored + recomputes
         *      sortedInbound/sortedOutbound + the in-modal header
         *      count.
         *   2. inertiaRouter.reload({only:['entries']}) fetches fresh
         *      entries[] from server → InertiaPagesController::links
         *      re-reads the index AND StatsApiController subtraction
         *      → Links Report table badges stay in sync with what the
         *      modal shows.
         *   3. If the server POST/DELETE fails we revert the optimistic
         *      flag so visible state doesn't lie about persistence.
         */
        async ignoreItem(item, isOutboundTarget = false) {
            if (!this.modal) return;
            if (!this.ignoreSuggestionUrl) return;
            const key = this.ignoreKey(item, isOutboundTarget);
            if (this.ignoreBusy[key]) return;
            this.ignoreBusy = { ...this.ignoreBusy, [key]: true };

            const sourceId = this.modal.entryId;
            const otherId = isOutboundTarget ? item.target_entry_id : item.source_entry_id;

            // Optimistic flip
            item.is_ignored = true;
            // If the user had ticked the checkbox before ignoring,
            // drop it from `selected` so the "Add N links" counter
            // doesn't keep an ignored row's vote. Inbound stores the
            // suggestion object itself; outbound stores group.key —
            // we know the row identity from `item` for inbound, but
            // outbound calls `ignoreItem(target, true)` where `item`
            // is the target, not the group. The corresponding group's
            // key needs an extra lookup. Both paths handled below.
            const inboundIdx = this.selected.indexOf(item);
            if (inboundIdx > -1) this.selected.splice(inboundIdx, 1);
            if (isOutboundTarget && this.modal.groups) {
                for (const g of this.modal.groups) {
                    const isMatch = (g.targets || []).some(t => t.target_entry_id === item.target_entry_id);
                    if (isMatch) {
                        const groupIdx = this.selected.indexOf(g.key);
                        if (groupIdx > -1) this.selected.splice(groupIdx, 1);
                    }
                }
            }
            // Bug B 2026-05-22: auto-reveal ignored rows after the
            // user explicitly ignores one. Without this the row
            // vanishes (sortedInbound/sortedOutbound filter is_ignored
            // out when showIgnored=false) and the ↩ un-ignore button
            // is never rendered in the same frame — user has to find
            // the "Show ignored" chip to recover. Flipping the toggle
            // here keeps the row visible (greyed + ↩) so the action
            // is immediately reversible.
            this.showIgnored = true;

            try {
                const response = await window.fetch(this.ignoreSuggestionUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ source_entry_id: sourceId, target_entry_id: otherId }),
                });
                if (!response.ok) {
                    item.is_ignored = false;
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.error(data?.message || 'Could not ignore suggestion.');
                    return;
                }
                // Refresh entries[] so the Links Report badge counts
                // re-render with the subtracted total. preserveScroll
                // keeps the user in place; preserveState keeps the
                // modal open with its own decorated state intact (we
                // already flipped is_ignored locally above).
                // **Klasse-10 count guarantee (CORRECTED 2026-05-22):**
                // The Links Report badge count is rendered from
                // LinksReportTab.suggestionCounts (filled by
                // StatsApiController::suggestionCounts → already
                // applies the ignored subtraction). The badge is
                // NOT rendered from entries[], so an Inertia
                // partial-reload of entries[] would not move the
                // visible count. Instead emit an 'ignored' event
                // so the parent component re-runs
                // loadSuggestionCounts() — the single source of
                // truth for badge numbers.
                this.$emit('ignored');
            } catch (e) {
                item.is_ignored = false;
                Statamic.$toast.error('Network error while ignoring suggestion.');
                console.error('[Linkwise]', e);
            } finally {
                const next = { ...this.ignoreBusy };
                delete next[key];
                this.ignoreBusy = next;
            }
        },

        async unignoreItem(item, isOutboundTarget = false) {
            if (!this.modal) return;
            if (!this.unignoreSuggestionUrl) return;
            const key = this.ignoreKey(item, isOutboundTarget);
            if (this.ignoreBusy[key]) return;
            this.ignoreBusy = { ...this.ignoreBusy, [key]: true };

            const sourceId = this.modal.entryId;
            const otherId = isOutboundTarget ? item.target_entry_id : item.source_entry_id;

            item.is_ignored = false;

            try {
                const response = await window.fetch(this.unignoreSuggestionUrl, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ source_entry_id: sourceId, target_entry_id: otherId }),
                });
                if (!response.ok) {
                    item.is_ignored = true;
                    const data = await response.json().catch(() => ({}));
                    Statamic.$toast.error(data?.message || 'Could not un-ignore suggestion.');
                    return;
                }
                // **Klasse-10 count guarantee (CORRECTED 2026-05-22):**
                // The Links Report badge count is rendered from
                // LinksReportTab.suggestionCounts (filled by
                // StatsApiController::suggestionCounts → already
                // applies the ignored subtraction). The badge is
                // NOT rendered from entries[], so an Inertia
                // partial-reload of entries[] would not move the
                // visible count. Instead emit an 'ignored' event
                // so the parent component re-runs
                // loadSuggestionCounts() — the single source of
                // truth for badge numbers.
                this.$emit('ignored');
            } catch (e) {
                item.is_ignored = true;
                Statamic.$toast.error('Network error while un-ignoring suggestion.');
                console.error('[Linkwise]', e);
            } finally {
                const next = { ...this.ignoreBusy };
                delete next[key];
                this.ignoreBusy = next;
            }
        },

        ignoreKey(item, isOutboundTarget) {
            return isOutboundTarget
                ? `out:${item.target_entry_id}`
                : `in:${item.source_entry_id}`;
        },

        matchBadgeClass(type) {
            return {
                title: 'bg-green-100 text-green-700',
                stem: 'bg-green-100 text-green-700',
                keyword: 'bg-blue-100 text-blue-700',
                custom: 'bg-purple-100 text-purple-700',
            }[type] || 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
        },
    },
};
</script>
