<?php

namespace Arturrossbach\Linkwise\Http\Controllers\Dashboard;

use Arturrossbach\Linkwise\AutoLink\AutoLinkApplier;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Keywords\ExcludedContentKeywordManager;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Reports\DomainReport;
use Arturrossbach\Linkwise\Reports\LinkReport;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\StaleCheckPresenter;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Inertia-Page-Renderer für alle 8 Linkwise-CP-Tabs.
 *
 * Extrahiert aus {@see \Arturrossbach\Linkwise\Http\Controllers\DashboardController}
 * während REV-DR-01 Phase B PR 5. Cluster-Scope: 8 read-only GET-Routen
 * (Overview/Links/BrokenLinks/Domains/AutoLink/Keywords/Activity/UrlChanger),
 * jede rendert ein Inertia-Template mit aggregierten Props.
 *
 * Alle 8 Renderer spreaden {@see StaleCheckPresenter::buildProps()} in ihre
 * Props (siehe stateless static seit PR 2 — kein 8× DI-Plumbing nötig). Der
 * Stale-Check liefert das tab-übergreifende "broken-link check is stale"
 * Banner-Datenpaket das `LinkwiseLayout` rendert.
 *
 * Constructor-DI: 4 Services (analog DC pre-PR1). `BulkSnapshotStore` wird
 * inline via `app()` resolved in `activity()` (matches DC-source pattern +
 * Test-Stack-Empfindlichkeit aus PR 3).
 *
 * Behaviour gepinnt durch:
 * - {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\InertiaRendererStaleCheckTest}
 *   (alle 8 Renderer × stale-check-prop-Distribution, 8 Cases)
 * - {@see \Arturrossbach\Linkwise\Tests\Unit\Dashboard\StaleCheckPropsTest}
 *   (Helper-Semantik via Reflection, 6 Cases)
 */
class InertiaPagesController extends CpController
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected TargetKeywordManager $keywordManager,
        protected AutoLinkManager $autoLinkManager,
        protected AutoLinkApplier $autoLinkApplier,
        protected ExcludedContentKeywordManager $excludedKeywordManager,
    ) {}

    // ─── Page: Overview ────────────────────────────────────────────────

    public function index(): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $data = $report->toArray();

        $totalExternal = 0;
        foreach ($data['entries'] as &$entry) {
            $statamicEntry = Entry::find($entry['id']);
            $externalCount = $statamicEntry ? count($this->extractExternalLinksFromEntry($statamicEntry)) : 0;
            $totalExternal += $externalCount;
        }

        $data['summary']['external_links'] = $totalExternal;

        $brokenReport = new BrokenLinkReport;
        $brokenData = $brokenReport->toArray();

        $domainReport = new DomainReport($this->indexer);

        // Enrich most/least linked with edit_url so Overview cards are clickable
        foreach (['most_linked', 'least_linked'] as $key) {
            if (! empty($data['summary'][$key]) && ! empty($data['summary'][$key]['id'])) {
                $mlEntry = collect($data['entries'])->firstWhere('id', $data['summary'][$key]['id']);
                if ($mlEntry) {
                    $data['summary'][$key]['edit_url'] = cp_route('collections.entries.edit', [$mlEntry['collection'], $mlEntry['id']]);
                }
            }
        }

        $resolvedLanguage = \Arturrossbach\Linkwise\NLP\LanguageRegistry::resolveWithSource();

        // V1.2 Cross-Tab-C — per-locale entry breakdown for the Overview's
        // headline stats card. Frontend renders "165 EN · 10 DE · 10 NL"
        // chips under the total. Empty + single-locale indices return an
        // empty breakdown so the chips don't render (single-site stays
        // visually identical to pre-V1.2).
        $localeBreakdown = [];
        foreach ($records as $r) {
            if ($r->locale === null) continue;
            $localeBreakdown[$r->locale] = ($localeBreakdown[$r->locale] ?? 0) + 1;
        }
        if (count($localeBreakdown) < 2) {
            $localeBreakdown = [];
        } else {
            ksort($localeBreakdown);
        }

        // PR #102 audit C1 — flag a "Re-Run Scan Content" banner on the
        // Overview when the install is multisite-enabled AND any record in
        // the persisted index lacks a locale stamp. That combination means
        // the index was built before the multilanguage track shipped (or by
        // a partial reindex) and the SuggestionEngine's same-locale filter
        // is silently passing through null-locale targets that should now
        // be scoped. A single Scan Content run fixes it.
        $multisiteReindexNeeded = false;
        try {
            if (\Statamic\Facades\Site::multiEnabled()) {
                foreach ($records as $r) {
                    if ($r->locale === null) {
                        $multisiteReindexNeeded = true;
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // Site facade unavailable in some test contexts — skip silently.
        }

        return Inertia::render('linkwise::Overview', [
            'summary' => $data['summary'],
            'health' => $report->health(),
            'brokenCount' => $brokenData['metadata']['broken_count'] ?? null,
            'brokenLastChecked' => $brokenData['metadata']['last_checked'] ?? null,
            'indexLastBuiltAt' => $this->indexer->getIndexLastBuiltAt(),
            'domainsCount' => count($domainReport->toArray()),
            'resolvedLanguage' => [
                'code' => $resolvedLanguage['code'],
                'name' => \Arturrossbach\Linkwise\NLP\LanguageRegistry::name($resolvedLanguage['code']),
                'source' => $resolvedLanguage['source'],
                'source_detail' => $resolvedLanguage['source_detail'],
            ],
            'multisiteReindexNeeded' => $multisiteReindexNeeded,
            'localeBreakdown' => $localeBreakdown,
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: Links Report ────────────────────────────────────────────

    public function links(Request $request): \Inertia\Response
    {
        $records = $this->indexer->load();

        // V1.2 locale-filter: apply `?locale=de` before LinkReport so the
        // table-level counts (entries.length, inbound/outbound aggregations
        // produced inside LinkReport) reflect the filtered scope. Filter is
        // null-safe — when no `?locale=` is set, all records pass.
        $filterState = \Arturrossbach\Linkwise\Support\LocaleFilterPresenter::apply($records, $request);
        $records = $filterState['filteredRecords'];

        $report = new LinkReport($records);
        $data = $report->toArray();

        foreach ($data['entries'] as &$entry) {
            $entry['edit_url'] = cp_route('collections.entries.edit', [$entry['collection'], $entry['id']]);
            $entry['view_url'] = $entry['url'];
            $entry['has_title_match'] = $records[$entry['id']]->hasTitleMatch ?? false;

            $statamicEntry = Entry::find($entry['id']);
            $entry['content_hash'] = $statamicEntry ? SafeEntrySaver::hash($statamicEntry) : '';

            $externalLinks = [];
            $internalLinksWithAnchors = [];
            if ($statamicEntry) {
                // Single-pass walk: text + internal + external links, each
                // annotated with the char-offset where its anchor sits in
                // that text. Replaces the naive occurrence counter that
                // silently picked unlinked anchor positions when the same
                // anchor word appeared both linked and unlinked in the same
                // entry (Bug 2026-05-11: DetailModal showed contexts for
                // unverlinkte Stellen, while the actually-linked positions
                // never made it into the modal).
                $bundle = TextExtractor::extractFromEntry($statamicEntry);
                $entryText = $bundle['text'];

                foreach ($bundle['external_links'] as $extLink) {
                    // Display-only context — relax paragraph-clamp so
                    // short-paragraph anchors (e.g. caption-style) get
                    // surrounding sentences instead of just the anchor
                    // alone. Suggestion-match paths use their own
                    // context-extractor with its own clamp — see
                    // ContextExtractor::extractAtOffset for rationale.
                    // (User-Smoke 2026-05-21).
                    $ctx = ContextExtractor::extractAtOffset(
                        $entryText,
                        $extLink['offset'],
                        mb_strlen($extLink['anchor_text']),
                        240,
                        clampToParagraph: false,
                    );
                    $externalLinks[] = [
                        'url' => $extLink['url'],
                        'anchor_text' => $extLink['anchor_text'],
                        'sentence_context' => $ctx['text'] ?? '',
                        'context_truncated_start' => $ctx['truncated_start'] ?? false,
                        'context_truncated_end' => $ctx['truncated_end'] ?? false,
                        // Frontend uses this to highlight the EXACT linked
                        // occurrence in the snippet, not a naive indexOf
                        // that would colour the first string-match (which
                        // may be unlinked).
                        'anchor_offset_in_context' => $ctx['anchor_offset'] ?? null,
                    ];
                }

                foreach ($bundle['internal_links'] as $intLink) {
                    // Display-only — see external_links comment above.
                    $ctx = ContextExtractor::extractAtOffset(
                        $entryText,
                        $intLink['offset'],
                        mb_strlen($intLink['anchor_text']),
                        240,
                        clampToParagraph: false,
                    );
                    $internalLinksWithAnchors[] = [
                        'entry_id' => $intLink['entry_id'],
                        'anchor_text' => $intLink['anchor_text'],
                        'href' => $intLink['href'],
                        'sentence_context' => $ctx['text'] ?? '',
                        'context_truncated_start' => $ctx['truncated_start'] ?? false,
                        'context_truncated_end' => $ctx['truncated_end'] ?? false,
                        'anchor_offset_in_context' => $ctx['anchor_offset'] ?? null,
                    ];
                }
            }

            $entry['external_links'] = $externalLinks;
            $entry['external_count'] = count($externalLinks);
            $entry['internal_links_detail'] = $internalLinksWithAnchors;
            $entry['outbound_count'] = count($internalLinksWithAnchors);
            $entry['outbound_total'] = $entry['outbound_count'] + $entry['external_count'];
        }
        unset($entry);

        // inbound_count and is_orphaned come from LinkReport (shared with Overview).
        // Live recomputation was tempting ("match the detail modal") but caused the
        // Overview summary and the Links Report table to disagree on orphan counts.
        // If the index is stale, the staleness banner and modal warnings handle it.

        return Inertia::render('linkwise::Links', [
            'entries' => $data['entries'],
            'collections' => $data['collections'],
            'suggestionCountsUrl' => cp_route('linkwise.suggestion-counts'),
            // Klasse-7 C-1 residual race-closure: showDetail fetches fresh
            // content_hashes from this endpoint before populating the
            // DetailModal so the next bulk operation uses current state
            // (not the stale localEntries snapshot from before the last
            // partial reload).
            'entryHashesUrl' => cp_route('linkwise.entry-hashes'),
            'applyUrl' => cp_route('linkwise.url-changer.apply'),
            'inboundSuggestionsBaseUrl' => cp_route('linkwise.inbound.suggestions', '__ID__'),
            'outboundSuggestionsBaseUrl' => cp_route('linkwise.outbound.suggestions', '__ID__'),
            'inboundInsertUrl' => cp_route('linkwise.inbound.insert'),
            'outboundInsertUrl' => cp_route('linkwise.outbound.insert'),
            'relinkUrl' => cp_route('linkwise.relink'),
            'autolinkStoreUrl' => cp_route('linkwise.autolink.store'),
            // Per-pair ignored-suggestion endpoints (Klasse-10 guarantee-stack 2026-05-22).
            // Both routes are CSRF-protected; modal hits them with the
            // standard X-CSRF-TOKEN header. POST = ignore, DELETE = unignore.
            'ignoreSuggestionUrl' => cp_route('linkwise.ignored-suggestions.ignore'),
            'unignoreSuggestionUrl' => cp_route('linkwise.ignored-suggestions.unignore'),
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'indexLastBuiltAt' => $this->indexer->getIndexLastBuiltAt(),
            'initialOrphaned' => (bool) $request->query('orphaned'),
            'availableLocales' => $filterState['availableLocales'],
            'activeLocale' => $filterState['activeLocale'],
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: Broken Links ────────────────────────────────────────────

    public function broken(Request $request): \Inertia\Response
    {
        $records = $this->indexer->load();

        // V1.2 locale-filter: applied at the broken_links level after
        // BrokenLinkReport runs, since BrokenLinkReport itself reads its
        // own JSON store (not the entry index). Filter strategy: compute
        // the allowed post_id set from filtered records, then trim
        // broken_links to those whose post_id is in the set.
        $filterState = \Arturrossbach\Linkwise\Support\LocaleFilterPresenter::apply($records, $request);
        $allowedPostIds = $filterState['activeLocale'] !== null
            ? array_fill_keys(array_keys($filterState['filteredRecords']), true)
            : null;

        $report = new LinkReport($records);
        $entries = $report->toArray()['entries'];

        $brokenReport = new BrokenLinkReport;
        $brokenData = $brokenReport->toArray();

        if ($allowedPostIds !== null) {
            $brokenData['broken_links'] = array_values(array_filter(
                $brokenData['broken_links'],
                fn ($bl) => isset($allowedPostIds[$bl['post_id']]),
            ));
            // Keep metadata in sync — broken_count should match what the user sees.
            $brokenData['metadata']['broken_count'] = count($brokenData['broken_links']);
        }

        foreach ($brokenData['broken_links'] as &$brokenLink) {
            $entryData = collect($entries)->firstWhere('id', $brokenLink['post_id']);
            $brokenLink['edit_url'] = $entryData
                ? cp_route('collections.entries.edit', [$entryData['collection'], $entryData['id']])
                : null;
        }

        // Content hashes for optimistic locking
        $entryHashes = [];
        foreach ($brokenData['broken_links'] as $bl) {
            if (! isset($entryHashes[$bl['post_id']])) {
                $entry = Entry::find($bl['post_id']);
                $entryHashes[$bl['post_id']] = $entry ? SafeEntrySaver::hash($entry) : '';
            }
        }

        return Inertia::render('linkwise::BrokenLinks', [
            'brokenData' => $brokenData,
            'entryHashes' => $entryHashes,
            'applyUrl' => cp_route('linkwise.url-changer.apply'),
            'ignoreUrl' => cp_route('linkwise.ignored-links.ignore'),
            'unignoreUrl' => cp_route('linkwise.ignored-links.unignore'),
            'bulkUnlinkUrl' => cp_route('linkwise.bulk-unlink'),
            'bulkUnlinkStatusUrl' => cp_route('linkwise.bulk-unlink.status'),
            'bulkUnlinkCancelUrl' => cp_route('linkwise.bulk-unlink.cancel'),
            'checkLinksUrl' => cp_route('linkwise.check-links'),
            'checkLinksStatusUrl' => cp_route('linkwise.check-links.status'),
            'checkLinksCancelUrl' => cp_route('linkwise.check-links.cancel'),
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'exportUrl' => cp_route('linkwise.broken-links.export'),
            'initialEntryFilter' => $request->query('entry', ''),
            'availableLocales' => $filterState['availableLocales'],
            'activeLocale' => $filterState['activeLocale'],
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: Domains ─────────────────────────────────────────────────

    public function domains(): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $entries = $report->toArray()['entries'];

        $domainReport = new DomainReport($this->indexer);
        $domainsData = $domainReport->toArray();

        foreach ($domainsData as &$domain) {
            foreach ($domain['posts'] as &$post) {
                $entryData = collect($entries)->firstWhere('id', $post['id']);
                $post['edit_url'] = $entryData
                    ? cp_route('collections.entries.edit', [$entryData['collection'], $entryData['id']])
                    : null;
            }
            foreach ($domain['links'] as &$link) {
                $entryData = collect($entries)->firstWhere('id', $link['post_id']);
                $link['edit_url'] = $entryData
                    ? cp_route('collections.entries.edit', [$entryData['collection'], $entryData['id']])
                    : null;
            }
        }

        $isMultisite = false;
        try {
            $isMultisite = \Statamic\Facades\Site::multiEnabled();
        } catch (\Throwable) {
            // Facade missing in some test contexts — default false.
        }

        return Inertia::render('linkwise::Domains', [
            'domains' => $domainsData,
            'isMultisite' => $isMultisite,
            'saveUrl' => cp_route('linkwise.save-domain-attribute'),
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'exportUrl' => cp_route('linkwise.domains.export'),
            'indexLastBuiltAt' => $this->indexer->getIndexLastBuiltAt(),
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: Auto-Linking ────────────────────────────────────────────

    public function autolink(Request $request): \Inertia\Response
    {
        $records = $this->indexer->load();
        // V1.2 Cross-Tab-B — surface availableLocales so RuleForm can render
        // the per-rule locale multi-select. NOT a filter (rules themselves
        // carry the scope, the page list shows all rules regardless).
        $availableLocales = \Arturrossbach\Linkwise\Support\LocaleFilterPresenter::availableLocales($records);
        $report = new LinkReport($records);
        $entries = $report->toArray()['entries'];

        // Enrich entries with edit URLs for the entry picker
        foreach ($entries as &$entry) {
            $entry['edit_url'] = cp_route('collections.entries.edit', [$entry['collection'], $entry['id']]);
            $statamicEntry = Entry::find($entry['id']);
            $entry['content_hash'] = $statamicEntry ? SafeEntrySaver::hash($statamicEntry) : '';
        }

        $rules = $this->autoLinkManager->loadRules();
        $rulesArray = [];

        foreach ($rules as $rule) {
            $ruleData = $rule->toArray();
            $preview = $this->autoLinkApplier->applyRule($rule, true);

            $ruleData['match_count'] = count($preview['affected_entries']);
            $ruleData['linked_count'] = count(array_filter(
                $preview['affected_entries'],
                fn ($e) => ($e['link_status'] ?? '') === 'linked_to_target',
            ));
            $ruleData['linked_elsewhere_count'] = count(array_filter(
                $preview['affected_entries'],
                fn ($e) => ($e['link_status'] ?? '') === 'linked_elsewhere',
            ));
            $ruleData['not_insertable_count'] = count(array_filter(
                $preview['affected_entries'],
                fn ($e) => ($e['link_status'] ?? '') === 'not_insertable',
            ));

            $rulesArray[] = $ruleData;
        }

        $collections = collect($report->toArray()['collections'])->values()->all();

        return Inertia::render('linkwise::AutoLink', [
            'autolinkData' => [
                'rules' => $rulesArray,
                'collections' => $collections,
                'available_locales' => $availableLocales,
                'auto_apply_on_save_enabled' => (bool) config('linkwise.auto_apply_on_save_enabled', false),
                'urls' => [
                    'store' => cp_route('linkwise.autolink.store'),
                    'apply_async' => cp_route('linkwise.autolink.apply-async', '__ID__'),
                    'apply_selected_async' => cp_route('linkwise.autolink.apply-selected-async'),
                    'apply_async_status' => cp_route('linkwise.autolink.apply-async.status'),
                    'apply_async_cancel' => cp_route('linkwise.autolink.apply-async.cancel'),
                    'bulk_delete' => cp_route('linkwise.autolink.bulk-delete'),
                    'bulk_toggle' => cp_route('linkwise.autolink.bulk-toggle'),
                    'export' => cp_route('linkwise.autolink.export'),
                    'import' => cp_route('linkwise.autolink.import'),
                ],
            ],
            'entries' => $entries,
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: Target Keywords ─────────────────────────────────────────

    public function keywords(): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $entries = collect($report->toArray()['entries'])->keyBy('id');

        $customKeywords = $this->keywordManager->loadAll();
        $excludedKeywords = $this->excludedKeywordManager->loadAll();
        $targetKeywordsData = [];

        foreach ($records as $entryRecord) {
            $entry = $entries[$entryRecord->id] ?? null;
            if (! $entry) {
                continue;
            }

            $contentKeywords = $this->extractContentKeywords($entryRecord);

            // Filter out user-excluded content keywords (block-list).
            // Case-insensitive match: excluded list is stored lowercased.
            // User-Smoke 2026-05-21 — noisy auto-extracted keywords
            // (e.g. "Mehr") would otherwise keep returning after every
            // re-index.
            $excludedForEntry = $excludedKeywords[$entryRecord->id] ?? [];
            if (! empty($excludedForEntry)) {
                $excludedSet = array_flip($excludedForEntry);
                $contentKeywords = array_values(array_filter(
                    $contentKeywords,
                    fn ($k) => ! isset($excludedSet[mb_strtolower($k)]),
                ));
            }

            $targetKeywordsData[] = [
                'id' => $entryRecord->id,
                'title' => $entry['title'],
                'collection' => $entry['collection'],
                'edit_url' => cp_route('collections.entries.edit', [$entry['collection'], $entry['id']]),
                'content_keywords' => $contentKeywords,
                'custom_keywords' => $customKeywords[$entryRecord->id] ?? [],
                // Echoed back so the frontend knows what's already on
                // the block-list (e.g. for an "undo" / "manage excluded"
                // sub-view; v1 only needs ✕ on visible badges, but the
                // list is cheap to expose and avoids a future API call).
                'excluded_content_keywords' => $excludedForEntry,
            ];
        }

        return Inertia::render('linkwise::Keywords', [
            'keywordsData' => [
                'entries' => $targetKeywordsData,
                'update_url' => cp_route('linkwise.target-keywords.update', '__ID__'),
                'exclude_url' => cp_route('linkwise.excluded-content-keywords.update', '__ID__'),
                // Single-source-of-truth for validation limits — backend
                // class constants are the ground truth, frontend reads via
                // prop. Eliminates the sync-risk of duplicating "50" in two
                // places (was a code-review blocker).
                'limits' => [
                    'max_keywords_per_entry' => \Arturrossbach\Linkwise\Http\Controllers\TargetKeywordController::MAX_KEYWORDS_PER_ENTRY,
                    'max_keyword_length' => \Arturrossbach\Linkwise\Http\Controllers\TargetKeywordController::MAX_KEYWORD_LENGTH,
                ],
            ],
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: Activity Log ────────────────────────────────────────────

    /**
     * Activity-Log page — read-only forensic record of every write-bulk
     * Linkwise has performed in the last 30 days. The user-facing answer
     * to "what did Linkwise just do, and how do I roll it back?".
     *
     * No restore action is offered (would clash with concurrent edits and
     * with non-Stache storage drivers). The page lists what happened and
     * which entries were touched; recovery itself is the user's job via
     * git / Statamic Revisions / hosting backup. See FAQ for guidance.
     */
    public function activity(Request $request): \Inertia\Response
    {
        $store = app(BulkSnapshotStore::class);
        $snapshots = $store->list(50);

        // Resolve entry titles + edit URLs for the listing — entry IDs alone
        // aren't useful in a UI. Cap title resolution to the first 5 entries
        // per snapshot so a 1000-entry batch doesn't hammer Entry::find().
        $listing = array_map(function ($snap) {
            $previewIds = array_slice($snap['entry_ids'] ?? [], 0, 5);
            $previewTitles = [];
            foreach ($previewIds as $id) {
                try {
                    $entry = Entry::find($id);
                    $previewTitles[] = $entry ? ($entry->get('title') ?? $id) : $id.' (deleted)';
                } catch (\Throwable) {
                    $previewTitles[] = $id;
                }
            }

            return [
                'id' => $snap['id'] ?? '',
                'kind' => $snap['kind'] ?? 'unknown',
                'started_by' => $snap['started_by'] ?? null,
                'started_at' => $snap['started_at'] ?? null,
                // null = still in flight (or crashed before markCompleted).
                // Frontend shows an "In progress" badge and hides Revert.
                // Legacy snapshots from before this field shipped don't have
                // the key at all — fall back to started_at so they're treated
                // as completed (which they are, by definition: they're old).
                'completed_at' => array_key_exists('completed_at', $snap)
                    ? $snap['completed_at']
                    : ($snap['started_at'] ?? null),
                'entry_count_total' => $snap['entry_count_total'] ?? count($snap['entry_ids'] ?? []),
                'preview_titles' => $previewTitles,
                'summary' => $snap['summary'] ?? [],
                'reverted_at' => $snap['reverted_at'] ?? null,
                'reverted_by' => $snap['reverted_by'] ?? null,
                // Forwarded so the listing's "Entries affected" cell can
                // subtract skipped entries via effectiveEntryCount(snap).
                // Pass the array (not just count) — entrySkipDelta needs
                // (snap.revert_skipped || []).length on the same shape the
                // drawer reads.
                'revert_skipped' => $snap['revert_skipped'] ?? [],
            ];
        }, $snapshots);

        return Inertia::render('linkwise::Activity', [
            'snapshots' => $listing,
            'detailUrl' => cp_route('linkwise.activity.detail', '__ID__'),
            'markRevertedUrl' => cp_route('linkwise.activity.mark-reverted', '__ID__'),
            // Distribution-pin (InertiaRendererRequiredUrlPropsTest): all 8
            // Inertia renderers must carry the rebuild-trio so the
            // shared LinkwiseLayout's "Scan Content" button works
            // identically across tabs. Activity was the outlier
            // (User-Smoke 2026-05-17: "Could not scan content: HTTP
            // 404" on Activity tab only) — fix forces the prop into
            // the response, pin prevents regression.
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            // Endpoints used by the Revert flow — frontend builds the inverse
            // payload from snapshot.items and POSTs to whichever fits the kind.
            'revertEndpoints' => [
                // applyrule + inboundinsert + outboundinsert revert through detail-unlink-async
                'detailUnlink' => cp_route('linkwise.detail-unlink.async'),
                // detailunlink (inbound) revert through inbound-insert
                'inboundInsert' => cp_route('linkwise.inbound.insert'),
                // detailunlink (outbound) revert through outbound-insert
                'outboundInsert' => cp_route('linkwise.outbound.insert'),
                // urlchanger revert through urlchanger apply-async with swapped URLs
                'urlChangerApply' => cp_route('linkwise.url-changer.apply-async'),
                // relink (Bug 17 Phase C) revert through the same atomic
                // endpoint with original/new swapped
                'relink' => cp_route('linkwise.relink'),
            ],
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Page: URL Changer ─────────────────────────────────────────────

    public function urlChanger(Request $request): \Inertia\Response
    {
        // V1.2 Cross-Tab-D — surface availableLocales so the URL Changer
        // form can render the "Apply to: All sites / <locale>" select.
        $records = $this->indexer->load();
        $availableLocales = \Arturrossbach\Linkwise\Support\LocaleFilterPresenter::availableLocales($records);

        $domainReport = new DomainReport($this->indexer);
        // Frontend expects `[{domain: 'example.com'}, ...]` (it accesses
        // `d.domain` in the autocomplete). Returning plain strings crashed
        // the search filter — keep objects.
        $domains = collect($domainReport->toArray())
            ->map(fn ($d) => ['domain' => $d['domain']])
            ->values()
            ->toArray();

        return Inertia::render('linkwise::UrlChanger', [
            'urlChangerData' => [
                'preview_url' => cp_route('linkwise.url-changer.preview'),
                'apply_url' => cp_route('linkwise.url-changer.apply'),
                'apply_async_url' => cp_route('linkwise.url-changer.apply-async'),
                'apply_status_url' => cp_route('linkwise.url-changer.apply-status'),
                'apply_cancel_url' => cp_route('linkwise.url-changer.apply-cancel'),
            ],
            'domains' => $domains,
            'availableLocales' => $availableLocales,
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'initialSearch' => $request->query('search', ''),
        ] + StaleCheckPresenter::buildProps($this->indexer));
    }

    // ─── Helpers (private, no external callers) ────────────────────────

    /**
     * Build top-10 content keywords for a given entry (used by Target Keywords
     * tab). Returns stem-back-to-original-word strings via the Snowball
     * stemmer + TF-IDF stems pre-computed in `EntryRecord::keywords`.
     */
    protected function extractContentKeywords($entryRecord): array
    {
        if (empty($entryRecord->keywords) || empty($entryRecord->text)) {
            return [];
        }

        $stemmer = new \Arturrossbach\Linkwise\NLP\Stemmer;
        $textWords = preg_split('/[\s\p{P}]+/u', mb_strtolower($entryRecord->title.' '.$entryRecord->text));
        $textWords = array_filter($textWords, fn ($w) => mb_strlen($w) >= 2);

        $stemToOriginal = [];
        foreach ($textWords as $word) {
            $stem = $stemmer->stem($word);
            if (! isset($stemToOriginal[$stem])) {
                $stemToOriginal[$stem] = $word;
            }
        }

        $contentKeywords = [];
        $tfidfStems = array_slice(array_keys($entryRecord->keywords), 0, 10);
        foreach ($tfidfStems as $stem) {
            $contentKeywords[] = $stemToOriginal[$stem] ?? $stem;
        }

        return $contentKeywords;
    }

    /**
     * Aggregate external links across all Bard + Markdown fields of an entry.
     * Used by Overview to count external-link totals for the summary header.
     */
    protected function extractExternalLinksFromEntry($entry): array
    {
        $links = [];

        EntryFieldWalker::walk(
            $entry,
            function (array $bard) use (&$links) {
                $links = array_merge($links, TextExtractor::externalLinksFromBard($bard));
            },
            function (string $markdown) use (&$links) {
                $links = array_merge($links, TextExtractor::externalLinksFromMarkdown($markdown));
            },
        );

        return $links;
    }
}
