<?php

namespace Arturrossbach\Linkwise\Http\Controllers\Dashboard;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Links\LinkwiseLinkMark;
use Arturrossbach\Linkwise\Reports\DomainReport;
use Arturrossbach\Linkwise\Reports\LinkReport;
use Arturrossbach\Linkwise\Suggestions\IgnoredSuggestionStore;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Stats / Suggestion-Counts / Domain-Attribute JSON API endpoints.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Http\Controllers\DashboardController}
 * during REV-DR-01 Phase B (Sprint 4 Part 5, PR 1). Lowest-risk cluster: 3
 * methods, 0 AL-specific bug history, no cross-cutting helper usage.
 *
 * Behaviour pinned by {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\StatsAndDomainAttributeTest}.
 */
class StatsApiController extends CpController
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected InboundEngine $inboundEngine,
        protected IgnoredSuggestionStore $ignoredStore,
    ) {}

    public function suggestionCounts(): JsonResponse
    {
        $records = $this->indexer->load();
        // 2026-05-22: post-Indexer-filter-removal, excluded entries are in the
        // index but their Suggestion counts must always read as zero. The
        // EntryIndexer already writes 0 for them, but a fresh-index race or
        // a custom record with stale counts could surface non-zero here.
        // Defense-in-depth: re-zero excluded records at read time.
        $excludedFilter = new \Arturrossbach\Linkwise\Suggestions\ExcludedEntryFilter;
        $counts = [];

        // Subtract per-entry ignored pairs from the cached totals so
        // the Links Report badges show the actual actionable
        // suggestion count (what the modal would display by default).
        //
        // **Approximation note (Klasse-10 guarantee-stack 2026-05-22):**
        // `ignoredCountFor($id)` is direction-agnostic — counts pairs
        // the entry participates in, regardless of whether the engine
        // suggested A→B, B→A, or both. We subtract from BOTH inbound
        // and outbound; in the worst case this slightly over-subtracts
        // when a pair was only suggested in one direction. Bounded by
        // `max(0, …)` so badges never go negative.
        //
        // The Modal counts (OutboundController + InboundController)
        // are exact — they decorate each engine suggestion with
        // `is_ignored` and the frontend computes visible = total - sum.
        // Badges in Links Report align with Modal counts exactly after
        // the next Scan Content (cached totals get refreshed from the
        // engine, then this subtraction is over the same baseline).
        foreach ($records as $record) {
            // Excluded entries: zero on both sides regardless of any stale
            // count that might still be on the record. The badge in Links
            // Report should never imply Linkwise has Suggestion-work to do
            // for an entry the user explicitly excluded.
            if ($excludedFilter->isExcludedRecord($record)) {
                $counts[$record->id] = ['inbound' => 0, 'outbound' => 0];
                continue;
            }

            $ignoredHere = $this->ignoredStore->ignoredCountFor($record->id);
            // Shape pinned by StatsAndDomainAttributeTest — only
            // 'inbound' + 'outbound' keys leave this method. Extra
            // metadata (totals, ignored count) is not consumed by
            // the frontend (LinksReportTab reads c.inbound / c.outbound
            // only) and would force the entire downstream test stack
            // to budge — YAGNI, drop them.
            $counts[$record->id] = [
                'inbound' => max(0, $record->inboundSuggestionCount - $ignoredHere),
                'outbound' => max(0, $record->outboundSuggestionCount - $ignoredHere),
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
            $engine = app(SuggestionEngine::class);
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
}
