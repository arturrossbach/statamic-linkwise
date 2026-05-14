<?php

namespace Arturrossbach\Linkwise\Http\Controllers\Dashboard;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Links\LinkwiseLinkMark;
use Arturrossbach\Linkwise\Reports\DomainReport;
use Arturrossbach\Linkwise\Reports\LinkReport;
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
    ) {}

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
