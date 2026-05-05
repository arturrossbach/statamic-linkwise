<?php

namespace Inkline\Linkwise\Reports;

use Inkline\Linkwise\Indexer\EntryRecord;
use Inkline\Linkwise\Suggestions\SuggestionEngine;

class LinkReport
{
    /** @var array<string, int> */
    protected array $inbound;

    /** @var array<string, int>|null */
    protected ?array $suggestionCounts = null;

    /**
     * @param  EntryRecord[]  $records
     */
    public function __construct(
        protected array $records,
    ) {
        $this->inbound = $this->computeInboundCounts();
    }

    public function totalEntries(): int
    {
        return count($this->records);
    }

    public function totalInternalLinks(): int
    {
        $total = 0;

        foreach ($this->records as $record) {
            foreach ($record->outboundLinks as $targetId) {
                if (isset($this->records[$targetId])) {
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function inboundCounts(): array
    {
        return $this->inbound;
    }

    public function inboundCount(string $entryId): int
    {
        return $this->inbound[$entryId] ?? 0;
    }

    public function outboundCount(string $entryId): int
    {
        if (! isset($this->records[$entryId])) {
            return 0;
        }

        // Only count links to entries that exist in the index
        return count(array_filter(
            $this->records[$entryId]->outboundLinks,
            fn (string $targetId) => isset($this->records[$targetId]),
        ));
    }

    /**
     * @return EntryRecord[]
     */
    public function orphanedEntries(): array
    {
        try {
            $orphanedIgnore = config('linkwise.orphaned_ignore', []);
        } catch (\Throwable) {
            $orphanedIgnore = [];
        }
        $orphanedIgnore = is_array($orphanedIgnore) ? $orphanedIgnore : [];

        return array_filter(
            $this->records,
            fn (EntryRecord $r) => ($this->inbound[$r->id] ?? 0) === 0
                && ! in_array($r->id, $orphanedIgnore, true),
        );
    }

    public function orphanedCount(): int
    {
        return count($this->orphanedEntries());
    }

    public function avgOutboundPerEntry(): float
    {
        $total = $this->totalEntries();

        return $total > 0 ? round($this->totalInternalLinks() / $total, 1) : 0;
    }

    /**
     * Entry with the most inbound links.
     *
     * @return array{title: string, count: int}|null
     */
    public function mostLinkedEntry(): ?array
    {
        $nonOrphaned = array_filter($this->inbound, fn ($count) => $count > 0);

        if (empty($nonOrphaned)) {
            return null;
        }

        $max = max($nonOrphaned);

        // Not useful if all entries have the same count
        if ($max <= 1 && count($nonOrphaned) > 1 && min($nonOrphaned) === $max) {
            return null;
        }

        $maxId = array_keys($nonOrphaned, $max)[0];

        if (! isset($this->records[$maxId])) {
            return null;
        }

        return [
            'id' => $maxId,
            'title' => $this->records[$maxId]->title,
            'count' => $max,
        ];
    }

    /**
     * Entry with the fewest inbound links (excluding orphaned).
     * Returns null if identical to most linked (no spread = no insight).
     *
     * @return array{id: string, title: string, count: int}|null
     */
    public function leastLinkedEntry(): ?array
    {
        $nonOrphaned = array_filter($this->inbound, fn ($count) => $count > 0);

        if (count($nonOrphaned) < 2) {
            return null;
        }

        $min = min($nonOrphaned);
        $max = max($nonOrphaned);

        // No spread — all entries have the same count, metric is meaningless
        if ($min === $max) {
            return null;
        }

        $minId = array_keys($nonOrphaned, $min)[0];

        if (! isset($this->records[$minId])) {
            return null;
        }

        return [
            'id' => $minId,
            'title' => $this->records[$minId]->title,
            'count' => $min,
        ];
    }

    /**
     * Link health metrics.
     */
    public function health(): array
    {
        $total = $this->totalEntries();
        $orphaned = $this->orphanedCount();
        $coverage = $total > 0 ? (int) round((($total - $orphaned) / $total) * 100) : 0;
        $avgOutbound = $this->avgOutboundPerEntry();

        $coverageStatus = $coverage >= 80 ? 'great' : ($coverage >= 50 ? 'ok' : 'warning');
        $rawAvgStatus = $avgOutbound >= 2 ? 'great' : ($avgOutbound >= 1 ? 'ok' : 'warning');

        // Avg Outbound cannot be healthier than Inbound Coverage. A site with 2.5 avg outbound
        // but 32% orphaned entries doesn't deserve a "Great" badge — the link graph is uneven.
        $rank = ['warning' => 0, 'ok' => 1, 'great' => 2];
        $avgOutboundStatus = array_search(min($rank[$rawAvgStatus], $rank[$coverageStatus]), $rank, true);

        return [
            'coverage' => $coverage,
            'coverage_status' => $coverageStatus,
            'avg_outbound' => $avgOutbound,
            'avg_outbound_status' => $avgOutboundStatus,
        ];
    }

    public function toArray(): array
    {
        $entries = [];
        $collections = [];

        // Note: suggestion_count is set to 0 here.
        // The DashboardController overrides it with InboundEngine results.
        try {
            $orphanedIgnore = config('linkwise.orphaned_ignore', []);
        } catch (\Throwable) {
            $orphanedIgnore = [];
        }
        $orphanedIgnore = is_array($orphanedIgnore) ? $orphanedIgnore : [];

        // Do NOT call suggestionCounts() here — it's O(n²) and adds seconds to page load.
        foreach ($this->records as $record) {
            $inbound = $this->inbound[$record->id] ?? 0;
            // Filter out ghost links (to deleted entries not in index)
            $validOutbound = array_values(array_filter(
                $record->outboundLinks,
                fn (string $targetId) => isset($this->records[$targetId]),
            ));

            $entries[] = [
                'id' => $record->id,
                'title' => $record->title,
                'url' => $record->url,
                'collection' => $record->collection,
                'inbound_count' => $inbound,
                'outbound_count' => count($validOutbound),
                'outbound_links' => $validOutbound,
                'suggestion_count' => 0,
                'is_orphaned' => $inbound === 0 && ! in_array($record->id, $orphanedIgnore, true),
            ];

            if (! in_array($record->collection, $collections, true)) {
                $collections[] = $record->collection;
            }
        }

        sort($collections);

        return [
            'summary' => [
                'total_entries' => $this->totalEntries(),
                'total_links' => $this->totalInternalLinks(),
                'orphaned_count' => $this->orphanedCount(),
                'avg_outbound' => $this->avgOutboundPerEntry(),
                'most_linked' => $this->mostLinkedEntry(),
                'least_linked' => $this->leastLinkedEntry(),
                'external_links' => 0, // Populated by DashboardController with actual count
                'entries_with_outbound' => count(array_filter($this->records, fn (EntryRecord $r) => count(array_filter($r->outboundLinks, fn ($id) => isset($this->records[$id]))) > 0)),
                'entries_with_inbound' => count(array_filter($this->inbound, fn ($count) => $count > 0)),
            ],
            'health' => $this->health(),
            'entries' => $entries,
            'collections' => $collections,
        ];
    }

    /**
     * Count inbound link suggestions per entry.
     * For each entry, count how many OTHER entries' text mentions this entry's title/keywords
     * but doesn't already link to it.
     *
     * @return array<string, int>
     */
    public function suggestionCounts(): array
    {
        if ($this->suggestionCounts !== null) {
            return $this->suggestionCounts;
        }

        $engine = new SuggestionEngine;
        $counts = [];

        // Note: This only counts title/keyword matches via SuggestionEngine.
        // Custom target keywords are NOT included here — the DashboardController
        // overrides these counts using InboundEngine (single source of truth).
        foreach ($this->records as $targetRecord) {
            $singleIndex = [$targetRecord->id => $targetRecord];
            $count = 0;

            foreach ($this->records as $sourceRecord) {
                if ($sourceRecord->id === $targetRecord->id) {
                    continue;
                }

                if (in_array($targetRecord->id, $sourceRecord->outboundLinks, true)) {
                    continue;
                }

                if (empty($sourceRecord->text)) {
                    continue;
                }

                $suggestions = $engine->suggest(
                    $sourceRecord->text,
                    $singleIndex,
                    $sourceRecord->id,
                );

                $count += count($suggestions);
            }

            $counts[$targetRecord->id] = $count;
        }

        $this->suggestionCounts = $counts;

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    protected function computeInboundCounts(): array
    {
        $inbound = array_fill_keys(array_keys($this->records), 0);

        foreach ($this->records as $record) {
            foreach ($record->outboundLinks as $targetId) {
                if (isset($inbound[$targetId])) {
                    $inbound[$targetId]++;
                }
            }
        }

        return $inbound;
    }
}
