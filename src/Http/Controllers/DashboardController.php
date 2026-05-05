<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Arturrossbach\Linkwise\AutoLink\AutoLinkApplier;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\Links\BrokenLinkChecker;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Links\LinkwiseLinkMark;
use Arturrossbach\Linkwise\Reports\DomainReport;
use Arturrossbach\Linkwise\Reports\LinkReport;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected TargetKeywordManager $keywordManager,
        protected AutoLinkManager $autoLinkManager,
        protected AutoLinkApplier $autoLinkApplier,
        protected InboundEngine $inboundEngine,
    ) {}

    /**
     * Cross-tab "broken-link check is stale" banner data. Compares index
     * last-built timestamp against last broken-link check. If the index is
     * newer (entries were edited since the last check) by more than the
     * threshold, the banner triggers — telling the user new edits may have
     * introduced broken URLs that the most recent check could not have seen.
     *
     * Spread into every Linkwise page's Inertia props so LinkwiseLayout can
     * render the banner on whichever tab the user is currently on.
     */
    protected function staleCheckProps(): array
    {
        $indexAt = $this->indexer->getIndexLastBuiltAt();
        $brokenReport = new BrokenLinkReport;
        $brokenLastChecked = $brokenReport->load()['metadata']['last_checked'] ?? null;

        // 5-minute grace window so a check that ran moments after a save
        // doesn't flicker the banner. Editors rarely care about stale-check
        // signals at sub-minute granularity.
        $isStale = false;
        if ($indexAt) {
            if (! $brokenLastChecked) {
                $isStale = true; // initial check never run
            } else {
                $isStale = strtotime($indexAt) > strtotime($brokenLastChecked) + 300;
            }
        }

        return [
            'staleCheck' => [
                'is_stale' => $isStale,
                'index_built_at' => $indexAt,
                'broken_last_checked' => $brokenLastChecked,
                'check_url' => cp_route('linkwise.check-links'),
                'check_status_url' => cp_route('linkwise.check-links.status'),
            ],
        ];
    }

    // ─── Page: Overview ────────────────────────────────────────────────

    public function index(): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $data = $report->toArray();

        $totalExternal = 0;
        foreach ($data['entries'] as &$entry) {
            $statamicEntry = \Statamic\Facades\Entry::find($entry['id']);
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

        return Inertia::render('linkwise::Overview', [
            'summary' => $data['summary'],
            'health' => $report->health(),
            'brokenCount' => $brokenData['metadata']['broken_count'] ?? null,
            'brokenLastChecked' => $brokenData['metadata']['last_checked'] ?? null,
            'indexLastBuiltAt' => $this->indexer->getIndexLastBuiltAt(),
            'domainsCount' => count($domainReport->toArray()),
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
        ] + $this->staleCheckProps());
    }

    // ─── Page: Links Report ────────────────────────────────────────────

    public function links(Request $request): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $data = $report->toArray();

        foreach ($data['entries'] as &$entry) {
            $entry['edit_url'] = cp_route('collections.entries.edit', [$entry['collection'], $entry['id']]);
            $entry['view_url'] = $entry['url'];
            $entry['has_title_match'] = $records[$entry['id']]->hasTitleMatch ?? false;

            $statamicEntry = \Statamic\Facades\Entry::find($entry['id']);
            $entry['content_hash'] = $statamicEntry ? SafeEntrySaver::hash($statamicEntry) : '';

            $externalLinks = [];
            $internalLinksWithAnchors = [];
            if ($statamicEntry) {
                $externalLinks = $this->extractExternalLinksFromEntry($statamicEntry);
                $internalLinksWithAnchors = $this->extractInternalLinksWithAnchors($statamicEntry);
            }

            $entryText = $records[$entry['id']]->text ?? '';
            $anchorOccurrences = [];
            foreach ($externalLinks as &$extLink) {
                $anchor = $extLink['anchor_text'] ?? '';
                $key = mb_strtolower($anchor);
                $occ = $anchorOccurrences[$key] ?? 0;
                $ctx = ContextExtractor::extractStructured($entryText, $anchor, 120, $occ);
                $extLink['sentence_context'] = $ctx ? $ctx['text'] : '';
                $extLink['context_truncated_start'] = $ctx['truncated_start'] ?? false;
                $extLink['context_truncated_end'] = $ctx['truncated_end'] ?? false;
                $anchorOccurrences[$key] = $occ + 1;
            }
            $anchorOccurrences = [];
            foreach ($internalLinksWithAnchors as &$intLink) {
                $anchor = $intLink['anchor_text'] ?? '';
                $key = mb_strtolower($anchor);
                $occ = $anchorOccurrences[$key] ?? 0;
                $ctx = ContextExtractor::extractStructured($entryText, $anchor, 120, $occ);
                $intLink['sentence_context'] = $ctx ? $ctx['text'] : '';
                $intLink['context_truncated_start'] = $ctx['truncated_start'] ?? false;
                $intLink['context_truncated_end'] = $ctx['truncated_end'] ?? false;
                $anchorOccurrences[$key] = $occ + 1;
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
            'applyUrl' => cp_route('linkwise.url-changer.apply'),
            'inboundSuggestionsBaseUrl' => cp_route('linkwise.inbound.suggestions', '__ID__'),
            'outboundSuggestionsBaseUrl' => cp_route('linkwise.outbound.suggestions', '__ID__'),
            'inboundInsertUrl' => cp_route('linkwise.inbound.insert'),
            'outboundInsertUrl' => cp_route('linkwise.outbound.insert'),
            'autolinkStoreUrl' => cp_route('linkwise.autolink.store'),
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'indexLastBuiltAt' => $this->indexer->getIndexLastBuiltAt(),
            'initialOrphaned' => (bool) $request->query('orphaned'),
        ] + $this->staleCheckProps());
    }

    // ─── Page: Broken Links ────────────────────────────────────────────

    public function broken(Request $request): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $entries = $report->toArray()['entries'];

        $brokenReport = new BrokenLinkReport;
        $brokenData = $brokenReport->toArray();

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
                $entry = \Statamic\Facades\Entry::find($bl['post_id']);
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
        ] + $this->staleCheckProps());
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

        return Inertia::render('linkwise::Domains', [
            'domains' => $domainsData,
            'saveUrl' => cp_route('linkwise.save-domain-attribute'),
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'exportUrl' => cp_route('linkwise.domains.export'),
            'indexLastBuiltAt' => $this->indexer->getIndexLastBuiltAt(),
        ] + $this->staleCheckProps());
    }

    // ─── Page: Auto-Linking ────────────────────────────────────────────

    public function autolink(): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $entries = $report->toArray()['entries'];

        // Enrich entries with edit URLs for the entry picker
        foreach ($entries as &$entry) {
            $entry['edit_url'] = cp_route('collections.entries.edit', [$entry['collection'], $entry['id']]);
            $statamicEntry = \Statamic\Facades\Entry::find($entry['id']);
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
                'auto_apply_on_save_enabled' => (bool) config('linkwise.auto_apply_on_save_enabled', false),
                'urls' => [
                    'store' => cp_route('linkwise.autolink.store'),
                    'apply_all' => cp_route('linkwise.autolink.apply-all'),
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
        ] + $this->staleCheckProps());
    }

    // ─── Page: Target Keywords ─────────────────────────────────────────

    public function keywords(): \Inertia\Response
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $entries = collect($report->toArray()['entries'])->keyBy('id');

        $customKeywords = $this->keywordManager->loadAll();
        $targetKeywordsData = [];

        foreach ($records as $entryRecord) {
            $entry = $entries[$entryRecord->id] ?? null;
            if (! $entry) {
                continue;
            }

            $contentKeywords = $this->extractContentKeywords($entryRecord);

            $targetKeywordsData[] = [
                'id' => $entryRecord->id,
                'title' => $entry['title'],
                'collection' => $entry['collection'],
                'edit_url' => cp_route('collections.entries.edit', [$entry['collection'], $entry['id']]),
                'content_keywords' => $contentKeywords,
                'custom_keywords' => $customKeywords[$entryRecord->id] ?? [],
            ];
        }

        return Inertia::render('linkwise::Keywords', [
            'keywordsData' => [
                'entries' => $targetKeywordsData,
                'update_url' => cp_route('linkwise.target-keywords.update', '__ID__'),
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
        ] + $this->staleCheckProps());
    }

    // ─── Page: URL Changer ─────────────────────────────────────────────

    public function urlChanger(Request $request): \Inertia\Response
    {
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
            'rebuildUrl' => cp_route('linkwise.rebuild-index'),
            'rebuildStatusUrl' => cp_route('linkwise.rebuild-index.status'),
            'rebuildCancelUrl' => cp_route('linkwise.rebuild-index.cancel'),
            'initialSearch' => $request->query('search', ''),
        ] + $this->staleCheckProps());
    }

    // ─── API Endpoints ─────────────────────────────────────────────────

    public function suggestionCounts(): JsonResponse
    {
        $records = $this->indexer->load();
        $counts = [];

        foreach ($records as $record) {
            $counts[$record->id] = [
                'inbound' => $record->inboundSuggestionCount,
                'outbound' => $record->outboundSuggestionCount,
            ];
        }

        return response()->json($counts);
    }

    public function entryStats(string $entryId): JsonResponse
    {
        $records = $this->indexer->load();
        $report = new LinkReport($records);

        $brokenReport = new BrokenLinkReport;
        $brokenData = $brokenReport->load();
        $brokenCount = count(array_filter(
            $brokenData['broken_links'],
            fn ($bl) => $bl->postId === $entryId,
        ));

        // Inbound suggestion count (how many other entries could link here)
        $inboundSuggestionCount = count($this->inboundEngine->suggest($entryId));

        // Outbound suggestion count (how many link opportunities exist in this entry's text)
        $outboundSuggestionCount = 0;
        $record = $records[$entryId] ?? null;
        if ($record) {
            $engine = app(\Arturrossbach\Linkwise\Suggestions\SuggestionEngine::class);
            $outboundSuggestionCount = count($engine->suggest($record->text, $records, $entryId, $record->outboundLinks));
        }

        return response()->json([
            'inbound' => $report->inboundCount($entryId),
            'outbound' => $report->outboundCount($entryId),
            'broken' => $brokenCount,
            'suggestions' => $inboundSuggestionCount,
            'outbound_suggestions' => $outboundSuggestionCount,
        ]);
    }

    public function saveDomainAttribute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain' => 'required|string|max:253',
            'attribute' => 'required|in:default,dofollow,nofollow,sponsored,ugc',
        ]);

        // setAttribute() is concurrent-safe (file-lock) and drops 'default'
        // entries instead of persisting them — the implicit default is "no rel".
        $report = new DomainReport($this->indexer);
        $report->setAttribute($data['domain'], $data['attribute']);
        LinkwiseLinkMark::clearCache();

        return response()->json(['success' => true]);
    }

    public function checkLinks(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('check')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        // Spawn the check as a detached background process — web worker returns immediately.
        // This frees all session/file locks and doesn't block navigation or other CP requests.
        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('check-links.log', 'Check Links'));

        \Illuminate\Support\Facades\Cache::put('linkwise:check:status', ['phase' => 'starting'], 300);
        \Illuminate\Support\Facades\Cache::forget('linkwise:check:cancel');

        // `>> log 2>&1 &` appends + detaches — preserves prior runs so a
        // successful re-run doesn't wipe a failed run's evidence.
        exec("$php $artisan linkwise:check-links --progress >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Check started']);
    }

    public function checkLinksStatus(Request $request): JsonResponse
    {
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('linkwise:check:status') ?? ['phase' => 'idle'],
        );
    }

    public function checkLinksCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:check:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    public function rebuildIndex(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('scan')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        // Spawn the scan as a detached background process — web worker returns immediately.
        // This frees all session/file locks and doesn't block navigation or other CP requests.
        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('scan-content.log', 'Scan Content'));

        \Illuminate\Support\Facades\Cache::put('linkwise:scan:status', ['phase' => 'starting'], 300);
        \Illuminate\Support\Facades\Cache::forget('linkwise:scan:cancel');

        exec("$php $artisan linkwise:index --progress >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Scan started']);
    }

    public function rebuildIndexStatus(Request $request): JsonResponse
    {
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('linkwise:scan:status') ?? ['phase' => 'idle'],
        );
    }

    public function rebuildIndexCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:scan:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    public function bulkUnlink(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('bulkunlink')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'replacements' => 'required|array|min:1',
            'replacements.*.entry_id' => 'required|string',
            'replacements.*.matched_url' => 'required|string',
            'replacements.*.new_url' => 'required|string',
            'replacements.*.field' => 'nullable|string',
            'replacements.*.field_type' => 'nullable|string',
            'replacements.*.occurrence_index' => 'nullable|integer',
            'replacements.*.search' => 'nullable|string',
        ]);

        \Illuminate\Support\Facades\Cache::put('linkwise:bulkunlink:payload', $validated, 600);
        \Illuminate\Support\Facades\Cache::put('linkwise:bulkunlink:status', [
            'phase' => 'starting',
            'total' => count($validated['replacements']),
        ], 600);
        \Illuminate\Support\Facades\Cache::forget('linkwise:bulkunlink:cancel');

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('bulk-unlink.log', 'Bulk Unlink'));

        exec("$php $artisan linkwise:bulk-unlink >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Bulk unlink started']);
    }

    public function bulkUnlinkStatus(Request $request): JsonResponse
    {
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('linkwise:bulkunlink:status') ?? ['phase' => 'idle'],
        );
    }

    public function bulkUnlinkCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:bulkunlink:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    /**
     * CSV export of all broken links from the latest report.
     * Same shape the user sees in the Broken Links table — useful for
     * sharing with colleagues, tracking over time, or post-mortem on a fix.
     */
    public function brokenLinksCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = new \Arturrossbach\Linkwise\Links\BrokenLinkReport;
        // load() returns ['metadata' => ..., 'broken_links' => BrokenLinkRecord[]]
        // — must iterate the broken_links bucket, not the wrapper array.
        $records = $report->load()['broken_links'];
        $filename = 'linkwise-broken-links-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Entry Title',
                'Entry ID',
                'Broken URL',
                'Anchor Text',
                'Type',
                'Status Code',
                'Status Label',
                'Error Type',
                'First Detected',
                'Last Checked',
                'Ignored',
                'Sentence Context',
            ]);
            foreach ($records as $r) {
                // Mark the anchor text inside sentence_context with **bold**
                // so reviewers can see WHICH word in the sentence is the
                // broken link — plain text otherwise gives no visual cue.
                $context = $r->sentenceContext;
                if ($context && $r->anchorText) {
                    $context = preg_replace(
                        '/'.preg_quote($r->anchorText, '/').'/i',
                        '**$0**',
                        $context,
                        1, // first occurrence only — same as the UI highlight
                    );
                }
                fputcsv($out, [
                    $r->postTitle,
                    $r->postId,
                    $r->url,
                    $r->anchorText,
                    $r->type,
                    $r->statusCode ?? '',
                    $r->statusLabel(),
                    $r->errorType,
                    $r->firstDetectedAt,
                    $r->lastCheckedAt,
                    $r->ignored ? 'yes' : 'no',
                    $context,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * CSV export of the Domains report — external domains the site links to,
     * with link counts and per-domain attributes (nofollow/dofollow/etc).
     */
    public function domainsCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = new \Arturrossbach\Linkwise\Reports\DomainReport($this->indexer);
        $domainsData = $report->toArray();
        $filename = 'linkwise-domains-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($domainsData) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Domain',
                'Posts Count',
                'Links Count',
                'Attribute',
            ]);
            foreach ($domainsData as $domain) {
                fputcsv($out, [
                    $domain['domain'] ?? '',
                    $domain['post_count'] ?? 0,
                    $domain['link_count'] ?? 0,
                    $domain['attribute'] ?? 'default',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Unified status endpoint for ALL heavy bulk jobs (scan, check, bulk-unlink,
     * apply-rule). Used by LinkwiseLayout's tab-spanning banner so the user sees
     * one consistent "something is running" indicator regardless of which job
     * it is or which tab they're on.
     */
    /**
     * Trigger a DetailModal Bulk-Unlink as a single heavy job.
     *
     * Used by the per-entry detail modal's "Unlink selected" button. Same
     * heavy-pattern as URL Changer Apply: one POST, server iterates internally,
     * single banner with progress, single cancel, single completion banner.
     *
     * Concurrency: refuses while ANY heavy job is running (one-bulk-at-a-time
     * is enforced globally by JobLock).
     */
    public function detailUnlinkAsync(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('detailunlink')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'replacements' => 'required|array|min:1',
            'replacements.*.entry_id' => 'required|string',
            'replacements.*.field' => 'nullable|string',
            'replacements.*.field_type' => 'nullable|string',
            'replacements.*.matched_url' => 'required|string',
            'replacements.*.occurrence_index' => 'required|numeric|min:0',
            'replacements.*.search' => 'nullable|string',
            'entry_hashes' => 'sometimes|array',
            'source_mode' => 'sometimes|in:inbound,outbound',
            'entry_title' => 'sometimes|nullable|string',
        ]);

        // Pre-flight hash check — fail-fast 409 before dispatch instead of
        // letting the loop encounter conflicts mid-run.
        $allHashes = $validated['entry_hashes'] ?? [];
        $replacementEntryIds = array_flip(array_unique(array_column($validated['replacements'], 'entry_id')));
        $relevantHashes = array_intersect_key($allHashes, $replacementEntryIds);
        $conflicts = \Arturrossbach\Linkwise\Support\SafeEntrySaver::verifyHashes($relevantHashes);
        if (! empty($conflicts)) {
            $title = reset($conflicts);

            return response()->json([
                'error' => 'conflict',
                'message' => 'Entry "'.$title.'" was modified by another editor. Please reload and try again.',
                'entry_id' => array_key_first($conflicts),
            ], 409);
        }

        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        // Wipe stale terminal-status from a previous run.
        \Illuminate\Support\Facades\Cache::forget('linkwise:detailunlink:status');
        \Illuminate\Support\Facades\Cache::forget('linkwise:detailunlink:cancel');

        \Illuminate\Support\Facades\Cache::put('linkwise:detailunlink:payload', [
            'replacements' => $validated['replacements'],
            'entry_hashes' => $allHashes,
            'source_mode' => $validated['source_mode'] ?? 'inbound',
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 600);
        \Illuminate\Support\Facades\Cache::put('linkwise:detailunlink:status', [
            'phase' => 'starting',
            'total' => count($validated['replacements']),
            'current' => 0,
            'source_mode' => $validated['source_mode'] ?? 'inbound',
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 600);

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('detail-unlink.log', 'Detail Unlink'));

        exec("$php $artisan linkwise:detail-unlink >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Detail unlink started']);
    }

    public function detailUnlinkStatus(Request $request): JsonResponse
    {
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('linkwise:detailunlink:status') ?? ['phase' => 'idle'],
        );
    }

    public function detailUnlinkCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:detailunlink:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    /**
     * Cancel an in-flight inbound bulk-add. The LinkInsertCommand checks this
     * flag at the per-item boundary and exits cleanly with a 'cancelled'
     * status snapshot. Same lightweight-flag pattern as DetailUnlink + UrlChanger.
     */
    public function inboundInsertCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:inboundinsert:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    /**
     * Cancel an in-flight outbound bulk-add — same flag pattern as inbound.
     */
    public function outboundInsertCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:outboundinsert:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    /**
     * Force-clear a stuck heavy-job. Used by the "Operation seems stuck" UI
     * banner when a process crashed in a way the crash-guard missed (e.g.
     * server restart before shutdown_function fired) — without this, the
     * JobLock would hang on 'running' until cache TTL expires (typically 5-10
     * minutes), blocking all other bulks for the user.
     */
    public function bulkClear(Request $request, string $kind): JsonResponse
    {
        \Arturrossbach\Linkwise\Support\JobLock::forceClear($kind);

        return response()->json(['success' => true, 'cleared' => $kind]);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $snapshot = \Arturrossbach\Linkwise\Support\JobLock::snapshot();
        if (! $snapshot) {
            return response()->json(['phase' => 'idle']);
        }

        // Map kind → existing cancel route. The frontend cancel button uses
        // this URL directly so each kind keeps its own server-side cancel.
        $cancelUrls = [
            'scan' => cp_route('linkwise.rebuild-index.cancel'),
            'check' => cp_route('linkwise.check-links.cancel'),
            'bulkunlink' => cp_route('linkwise.bulk-unlink.cancel'),
            'applyrule' => cp_route('linkwise.autolink.apply-async.cancel'),
            'urlchanger' => cp_route('linkwise.url-changer.apply-cancel'),
            'detailunlink' => cp_route('linkwise.detail-unlink.cancel'),
            'inboundinsert' => cp_route('linkwise.inbound.insert.cancel'),
            'outboundinsert' => cp_route('linkwise.outbound.insert.cancel'),
        ];

        $status = $snapshot['status'];

        return response()->json([
            'kind' => $snapshot['name'],
            'label' => $snapshot['label'],
            'phase' => $status['phase'] ?? 'running',
            'current' => $status['current'] ?? 0,
            'total' => $status['total'] ?? 0,
            'message' => $status['message'] ?? null,
            'cancel_url' => $cancelUrls[$snapshot['name']] ?? null,
            'terminal' => $snapshot['terminal'],
            // Pass through the full status so the layout can read kind-specific
            // fields (e.g. apply-rule's links_added, rule_keyword) when building
            // the completion toast.
            'extra' => $status,
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

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

    protected function extractInternalLinksWithAnchors($entry): array
    {
        $links = [];

        EntryFieldWalker::walk(
            $entry,
            function (array $bard) use (&$links) {
                $links = array_merge($links, TextExtractor::internalLinksWithAnchorFromBard($bard));
            },
            function (string $markdown) use (&$links) {
                if (preg_match_all('#\[([^\[\]]*)\]\(statamic://entry::([^)]+)\)#', $markdown, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $links[] = ['entry_id' => $m[2], 'anchor_text' => $m[1], 'href' => 'statamic://entry::'.$m[2]];
                    }
                }
            },
        );

        return $links;
    }

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

    /**
     * Debug-Export — bundles environment + Linkwise diagnostic data into a
     * single ZIP for support tickets.
     *
     * GDPR-compliance: privacy-first by design. Default export contains:
     * - aggregate counts and stats (no PII)
     * - software version info (no PII)
     * - whitelisted non-sensitive config keys (no API keys, no URL patterns,
     *   no entry IDs)
     *
     * Logs are OPT-IN via ?include_logs=1 because:
     * - log lines may contain URLs from the user's site
     * - URLs may include personally-identifiable paths (/users/john-doe)
     * - URLs may include query strings with PII (?email=, ?token=)
     *
     * The frontend forces a confirmation dialog when toggling include_logs
     * so the user makes an informed choice before potentially sharing PII.
     */
    public function debugExport(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $includeLogs = $request->boolean('include_logs');

        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $reportData = $report->toArray();
        $brokenReport = new BrokenLinkReport;
        $brokenData = $brokenReport->load();
        $rules = $this->autoLinkManager->loadRules();

        $storagePath = storage_path('linkwise');
        $tempZip = tempnam(sys_get_temp_dir(), 'linkwise-debug-').'.zip';

        $zip = new \ZipArchive;
        if ($zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create debug archive.');
        }

        // 1. Environment + version snapshot. Whitelist-only config snapshot:
        //    ONLY non-sensitive keys are included. Excludes ignored_links
        //    (URL patterns), excluded_entries / orphaned_ignore (entry IDs),
        //    api_key, and anything else that could identify a site.
        $cfg = config('linkwise', []);
        $aiCfg = $cfg['ai'] ?? [];
        $brokenCfg = $cfg['broken_links'] ?? [];
        $configSafe = [
            // What COLLECTIONS the addon scans — handles only, e.g. "blog".
            // Generic and useful for debugging "why isn't my blog scanned".
            'collections' => is_array($cfg['collections'] ?? null) ? $cfg['collections'] : [],
            'excluded_collections' => is_array($cfg['excluded_collections'] ?? null) ? $cfg['excluded_collections'] : [],
            // AI configured? Yes/no/redacted. Never the key itself.
            'ai_configured' => ! empty($aiCfg['api_key']),
            'ai_provider' => $aiCfg['provider'] ?? null,
            'ai_model' => $aiCfg['model'] ?? null,
            // Broken-link timeout/retries — ints, no PII risk.
            'broken_links_timeout' => $brokenCfg['timeout'] ?? null,
            'broken_links_retries' => $brokenCfg['retries'] ?? null,
            // Counts (NOT contents) of site-specific lists. The number tells
            // support "user has 5 ignored URL patterns" without revealing them.
            'ignored_links_count' => isset($cfg['ignored_links']) && is_string($cfg['ignored_links'])
                ? count(array_filter(array_map('trim', explode("\n", $cfg['ignored_links']))))
                : 0,
            'excluded_entries_count' => is_array($cfg['excluded_entries'] ?? null) ? count($cfg['excluded_entries']) : 0,
            'orphaned_ignore_count' => is_array($cfg['orphaned_ignore'] ?? null) ? count($cfg['orphaned_ignore']) : 0,
        ];
        $info = [
            'generated_at' => now()->toIso8601String(),
            'include_logs' => $includeLogs,
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'laravel_version' => app()->version(),
            'statamic_version' => \Statamic\Statamic::version(),
            'linkwise_version' => $this->linkwiseVersion(),
            'config' => $configSafe,
        ];
        $zip->addFromString('info.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 2. Index summary — counts only, no entry content/text/keywords.
        $perCollection = [];
        foreach ($records as $r) {
            $perCollection[$r->collection] = ($perCollection[$r->collection] ?? 0) + 1;
        }
        $indexSummary = [
            'total_entries' => count($records),
            'entries_per_collection' => $perCollection,
            'total_internal_links' => $reportData['summary']['total_links'] ?? 0,
            'orphaned_count' => $reportData['summary']['orphaned_count'] ?? 0,
            'index_last_built_at' => $this->indexer->getIndexLastBuiltAt(),
        ];
        $zip->addFromString('index-summary.json', json_encode($indexSummary, JSON_PRETTY_PRINT));

        // 3. Broken-links summary — per error_type counts, NO actual URLs.
        $errorTypes = [];
        $statusCodes = [];
        foreach ($brokenData['broken_links'] ?? [] as $r) {
            $errorTypes[$r->errorType] = ($errorTypes[$r->errorType] ?? 0) + 1;
            $code = $r->statusCode ?? 'null';
            $statusCodes[$code] = ($statusCodes[$code] ?? 0) + 1;
        }
        $brokenSummary = [
            'total_broken' => count($brokenData['broken_links'] ?? []),
            'last_checked' => $brokenData['metadata']['last_checked'] ?? null,
            'by_error_type' => $errorTypes,
            'by_status_code' => $statusCodes,
        ];
        $zip->addFromString('broken-links-summary.json', json_encode($brokenSummary, JSON_PRETTY_PRINT));

        // 4. Auto-Link rules summary — counts + states, NO keywords/URLs.
        $activeRules = 0;
        $rulesWithCollections = 0;
        foreach ($rules as $rule) {
            if ($rule->active) {
                $activeRules++;
            }
            if (! empty($rule->collections)) {
                $rulesWithCollections++;
            }
        }
        $rulesSummary = [
            'total_rules' => count($rules),
            'active_rules' => $activeRules,
            'rules_with_collection_scope' => $rulesWithCollections,
        ];
        $zip->addFromString('autolink-rules-summary.json', json_encode($rulesSummary, JSON_PRETTY_PRINT));

        // 5. Storage stats — file sizes (KB) of every linkwise/* file. Useful
        //    for diagnosing "index is huge / broken-links.json bloated".
        $storageStats = [];
        if (is_dir($storagePath)) {
            foreach (new \DirectoryIterator($storagePath) as $f) {
                if ($f->isFile()) {
                    $storageStats[$f->getFilename()] = [
                        'size_kb' => (int) round($f->getSize() / 1024),
                        'modified' => date('c', $f->getMTime()),
                    ];
                }
            }
        }
        $zip->addFromString('storage-stats.json', json_encode($storageStats, JSON_PRETTY_PRINT));

        // 5b. Runtime environment — PHP runtime + server info. Always included
        //     (no PII). Critical for diagnosing "scan dies after 30s"
        //     (max_execution_time), "out of memory at N entries" (memory_limit),
        //     "OPcache cache stale" (opcache settings) classes of bugs.
        $runtimeEnv = [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'memory_get_usage' => memory_get_usage(true),
            'memory_get_peak_usage' => memory_get_peak_usage(true),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'max_input_vars' => ini_get('max_input_vars'),
            'opcache_enabled' => ini_get('opcache.enable'),
            'opcache_revalidate_freq' => ini_get('opcache.revalidate_freq'),
            'date_timezone' => date_default_timezone_get(),
            'locale' => setlocale(LC_ALL, 0),
            'extensions_loaded' => array_values(array_filter(
                ['curl', 'zip', 'mbstring', 'intl', 'gd', 'imagick', 'fileinfo', 'opcache', 'sodium', 'simplexml', 'dom'],
                'extension_loaded',
            )),
            'server_software' => $request->server('SERVER_SOFTWARE') ?? 'unknown',
            'request_scheme' => $request->server('REQUEST_SCHEME') ?? 'unknown',
            'app_env' => app()->environment(),
            'app_debug' => (bool) config('app.debug'),
            'app_url' => parse_url(config('app.url') ?? '', PHP_URL_HOST) ?? 'unknown',
            'cache_default' => config('cache.default'),
        ];
        $zip->addFromString('runtime-env.json', json_encode($runtimeEnv, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 6. Verbose-mode contents — opt-in, may contain URLs/keywords/PII.
        //    Modal-confirmed by the user before download fires. This block is
        //    where debugging actually happens: full state JSON + stack traces.
        if ($includeLogs) {
            // 6a. Linkwise's own logs (progress trails — apply-rule, scan, etc.)
            if (is_dir($storagePath)) {
                foreach (glob($storagePath.'/*.log') as $logPath) {
                    $name = basename($logPath);
                    $tail = $this->tailLines($logPath, 500);
                    $zip->addFromString('logs/'.$name, $tail);
                }
            }

            // 6b. Laravel error log — filtered to Linkwise-related entries.
            //     THIS is where actual stack traces live (Linkwise's own *.log
            //     are progress logs, not error logs). Multi-line capture
            //     because stack traces span dozens of lines.
            $laravelLog = storage_path('logs/laravel.log');
            if (is_readable($laravelLog)) {
                $linkwiseEntries = $this->extractLinkwiseLogEntries($laravelLog, 50);
                $zip->addFromString(
                    'logs/laravel-linkwise-errors.log',
                    $linkwiseEntries !== '' ? $linkwiseEntries : "(no Linkwise-related entries found in laravel.log)\n",
                );

                // 6b-extra. Full unfiltered tail of laravel.log. Catches
                // Statamic-core exceptions triggered by Linkwise calls that
                // don't mention "linkwise" in their stack — would otherwise
                // be invisible to the filtered file above.
                $fullTail = $this->tailLines($laravelLog, 1000);
                $zip->addFromString('logs/laravel-full-tail.log', $fullTail);
            }

            // 6c. Full state JSONs — needed to reproduce "rule X doesn't fire"
            //     or "entry Y shows wrong inbound count" bugs. URLs/keywords
            //     present, hence opt-in only.
            foreach (['broken-links.json', 'autolink-rules.json', 'target-keywords.json', 'domain-attributes.json'] as $file) {
                $path = $storagePath.'/'.$file;
                if (is_readable($path)) {
                    $zip->addFile($path, 'state/'.$file);
                }
            }

            // 6d. Filtered index.json — entry IDs + titles + outbound link IDs
            //     ONLY. Strips text content + keywords (potential PII bulk).
            //     Sufficient to debug ghost-link / missing-entry bugs without
            //     leaking the user's editorial content.
            $indexPath = $storagePath.'/index.json';
            if (is_readable($indexPath)) {
                $rawIndex = json_decode(file_get_contents($indexPath), true);
                if (is_array($rawIndex)) {
                    $stripped = [];
                    foreach ($rawIndex as $id => $entry) {
                        $stripped[$id] = [
                            'id' => $entry['id'] ?? $id,
                            'title' => $entry['title'] ?? '',
                            'collection' => $entry['collection'] ?? '',
                            'url' => $entry['url'] ?? null,
                            'outbound_links' => $entry['outbound_links'] ?? [],
                            // Drop text + keywords — high PII bulk, low debug value
                            'text_length' => isset($entry['text']) ? strlen((string) $entry['text']) : 0,
                            'keyword_count' => isset($entry['keywords']) && is_array($entry['keywords']) ? count($entry['keywords']) : 0,
                        ];
                    }
                    $zip->addFromString('state/index-filtered.json', json_encode($stripped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        // 7. README inside the ZIP. Two privacy modes documented separately
        //    so the recipient knows whether the user opted into the verbose
        //    bundle or not.
        $zipReadme = "Linkwise Debug Export\n=====================\n\n".
            "Generated: ".$info['generated_at']."\n".
            "Mode:      ".($includeLogs ? 'VERBOSE (logs + state included)' : 'SAFE (counts only)')."\n\n".
            "Always included (privacy-safe):\n".
            "  info.json                    — versions + whitelisted config\n".
            "                                 (no API key, no URL patterns,\n".
            "                                  no entry IDs)\n".
            "  runtime-env.json             — PHP runtime + server info\n".
            "                                 (memory_limit, max_execution_time,\n".
            "                                  opcache, extensions, server software)\n".
            "  index-summary.json           — entry counts per collection\n".
            "  broken-links-summary.json    — error_type / status_code counts\n".
            "  autolink-rules-summary.json  — rule counts only\n".
            "  storage-stats.json           — file sizes + mtimes\n\n".
            ($includeLogs
                ? "VERBOSE additions (may contain PII — review before sharing):\n".
                  "  logs/*.log                   — Linkwise progress logs\n".
                  "                                 (apply, scan, bulk-unlink)\n".
                  "  logs/laravel-linkwise-errors.log\n".
                  "                               — stack traces from laravel.log\n".
                  "                                 filtered to Linkwise mentions\n".
                  "  logs/laravel-full-tail.log   — last 1000 lines of\n".
                  "                                 laravel.log (unfiltered).\n".
                  "                                 Catches non-Linkwise-mentioned\n".
                  "                                 errors triggered by Linkwise.\n".
                  "  state/broken-links.json      — full broken-link report\n".
                  "                                 (URLs, anchor text, context)\n".
                  "  state/autolink-rules.json    — rule keyword + URL targets\n".
                  "  state/target-keywords.json   — custom keywords per entry\n".
                  "  state/domain-attributes.json — per-domain rel attributes\n".
                  "  state/index-filtered.json    — entry IDs/titles + outbound\n".
                  "                                 link relationships\n".
                  "                                 (text content + keywords stripped)\n\n".
                  "PRIVACY WARNING\n".
                  "===============\n".
                  "This is a VERBOSE export. It contains URLs from your site,\n".
                  "auto-link keywords, anchor text, and Laravel stack traces.\n".
                  "These can include personally identifiable information\n".
                  "(usernames in URLs, email patterns, IP addresses, request\n".
                  "data in stack traces).\n\n".
                  "Before sharing this ZIP:\n".
                  "  - Open it locally and review what's inside\n".
                  "  - Encrypt or password-protect when transmitting via email\n".
                  "  - Send only to trusted Linkwise support\n".
                  "  - Delete the ZIP after the support ticket is closed\n".
                  "API keys are NEVER included.\n"
                : "Privacy:\n".
                  "  This export contains NO URLs from your site, NO entry\n".
                  "  content, NO keywords, and NO API keys. Safe to share.\n\n".
                  "  For deeper debugging (stack traces + state), use\n".
                  "  'Download diagnostic ZIP — with logs' from the Help menu.\n"
            );
        $zip->addFromString('README.txt', $zipReadme);

        $zip->close();

        $filename = 'linkwise-debug-'.now()->format('Y-m-d-His').'.zip';

        return response()->download($tempZip, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Frontend error sink. Receives JS / Vue render / unhandled-rejection
     * errors from the addon's reporter and appends them to a Linkwise-local
     * log file. NEVER calls out to a third-party — DSGVO-friendly + no SaaS
     * dependency. Lands in storage/linkwise/frontend-errors.log which then
     * gets bundled into the verbose debug-export ZIP.
     *
     * Privacy: PII-scrubber strips query strings and masks /users/<name>
     * style paths. Payload size is capped at 8KB to prevent log-flooding.
     * Rate-limit handled by middleware (100/min/user).
     */
    public function frontendError(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string|max:6000',
            'source' => 'nullable|string|max:200',
            'url' => 'nullable|string|max:2000',
            'kind' => 'nullable|string|max:50',
        ]);

        $logDir = storage_path('linkwise');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logPath = $logDir.'/frontend-errors.log';

        // Naïve size-based rotation: when the log crosses 5MB, rename to
        // .1 (overwriting the previous .1) and start a fresh file. One
        // backup is enough — we don't need a long history of frontend errors.
        if (file_exists($logPath) && filesize($logPath) > 5 * 1024 * 1024) {
            @rename($logPath, $logPath.'.1');
        }

        $entry = json_encode([
            'ts' => now()->toIso8601String(),
            'kind' => $validated['kind'] ?? 'error',
            'message' => $this->scrubPii($validated['message']),
            'source' => $validated['source'] ?? null,
            'url' => $this->scrubPii($validated['url'] ?? ''),
            'stack' => $this->scrubPii($validated['stack'] ?? ''),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ], JSON_UNESCAPED_SLASHES);

        @file_put_contents($logPath, $entry."\n", FILE_APPEND | LOCK_EX);

        return response()->json(['ok' => true], 204);
    }

    /**
     * Strip query strings + mask user-identifier-style URL paths from
     * arbitrary text. Best-effort only — the reporter must additionally
     * scrub on the client side; this is the second line of defence.
     */
    protected function scrubPii(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // Remove query strings entirely (often contain ?email=, ?token=)
        $text = preg_replace('/(\?[^\s"\'<>]+)/', '?[redacted]', $text) ?? $text;
        // Mask /users/<name> style paths to /users/[id]
        $text = preg_replace('#/(users|members|profile|account)/[^/\s"\'<>]+#i', '/$1/[id]', $text) ?? $text;

        return $text;
    }

    /**
     * Read the linkwise package version from composer.json. Returns 'dev'
     * when running from a git clone without a tagged release.
     */
    protected function linkwiseVersion(): string
    {
        $composerPath = __DIR__.'/../../../composer.json';
        if (! file_exists($composerPath)) {
            return 'unknown';
        }
        $data = json_decode(file_get_contents($composerPath), true);

        return $data['version'] ?? 'dev';
    }

    /**
     * Read the last N lines of a file without loading the whole thing.
     * Walks backwards from EOF in 4KB chunks until enough newlines are seen.
     * Critical for log files that may have grown to gigabytes.
     */
    protected function tailLines(string $path, int $maxLines): string
    {
        if (! file_exists($path) || ! is_readable($path)) {
            return '';
        }
        $size = filesize($path);
        if ($size === 0) {
            return '';
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }

        $buffer = '';
        $chunkSize = 4096;
        $pos = $size;
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $maxLines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($fp, $pos);
            $chunk = fread($fp, $readSize);
            $buffer = $chunk.$buffer;
            $lineCount = substr_count($buffer, "\n");
        }
        fclose($fp);

        $lines = explode("\n", $buffer);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return implode("\n", $lines);
    }

    /**
     * Extract Laravel-log entries that mention Linkwise. Each Laravel log
     * entry is a multi-line block starting with `[YYYY-MM-DD HH:MM:SS]` —
     * we split on that boundary, filter blocks containing "Arturrossbach\Linkwise"
     * or a case-insensitive "linkwise" mention, and keep the most recent
     * $maxEntries. This is the only place stack traces actually live.
     *
     * Read the whole file in one shot — bounded by reasonable Laravel log
     * sizes (multi-MB). For huge logs (>50MB), the tail-2MB approach below
     * keeps memory predictable.
     */
    protected function extractLinkwiseLogEntries(string $path, int $maxEntries): string
    {
        $maxRead = 2 * 1024 * 1024; // 2MB cap — older entries dropped
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }
        if ($size > $maxRead) {
            fseek($fp, $size - $maxRead);
            // Skip partial first line so we start at a clean entry boundary.
            fgets($fp);
        }
        $content = stream_get_contents($fp);
        fclose($fp);

        if ($content === false || $content === '') {
            return '';
        }

        // Split on Laravel timestamp prefix `[YYYY-MM-DD HH:MM:SS]`. Use a
        // lookahead so the timestamp stays attached to its block.
        $blocks = preg_split('/(?=^\[\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\])/m', $content);
        if (! is_array($blocks)) {
            return '';
        }

        $matches = array_values(array_filter($blocks, fn (string $b) => stripos($b, 'linkwise') !== false));
        if (empty($matches)) {
            return '';
        }
        if (count($matches) > $maxEntries) {
            $matches = array_slice($matches, -$maxEntries);
        }

        return implode('', $matches);
    }
}
