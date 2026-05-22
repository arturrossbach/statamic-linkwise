<?php

namespace Arturrossbach\Linkwise\Suggestions;

use Arturrossbach\Linkwise\Indexer\EntryRecord;

/**
 * Central authority for the `excluded_entries` / `excluded_collections`
 * config — answers "should this entry be visible to the Suggestion machinery?"
 *
 * ## Why this class exists
 *
 * User-bug 2026-05-22 (Cloudways smoke): putting `Home` in the
 * `excluded_entries` setting hid the entry from the Domains panel AND the
 * Broken-Links checker AND made the URL-Changer see "phantom" links the
 * Domains panel couldn't show — because `EntryIndexer::buildIndex` filtered
 * excluded entries OUT of the persisted index entirely, and every report
 * that read from the index inherited that filter for free.
 *
 * The blueprint copy promised "neither suggested nor suggesting" — i.e.
 * Suggestion-scope only. Real-link reports (Domains, Broken Links, URL-
 * Changer, Activity-Log) had no business being filtered.
 *
 * Post-fix: the Indexer is now agnostic, every Suggestion-generating path
 * consults THIS filter explicitly. Non-Suggestion reports see the full
 * universe of entries — matches the blueprint promise.
 *
 * ## Membership semantics
 *
 * An entry is "excluded from the Suggestion machinery" when its id appears
 * in `linkwise.excluded_entries`, OR its collection-handle appears in
 * `linkwise.excluded_collections`. Both lists OR-combined; either match
 * is enough.
 *
 * Both lists are read fresh on construction so test code can mutate
 * `config()->set(...)` between cases without manually clearing a cache.
 * Re-create the filter for each test run.
 */
class ExcludedEntryFilter
{
    /** @var list<string> */
    protected array $excludedEntries;

    /** @var list<string> */
    protected array $excludedCollections;

    public function __construct()
    {
        // Tolerate test envs without a booted Laravel container — `config()`
        // would throw BindingResolutionException there. Empty filter ≡ no
        // excluded entries, which is the correct semantic for a unit test
        // that didn't explicitly set the config.
        try {
            $entries = config('linkwise.excluded_entries', []);
            $collections = config('linkwise.excluded_collections', []);
        } catch (\Throwable) {
            $entries = [];
            $collections = [];
        }

        $this->excludedEntries = is_array($entries) ? array_values(array_filter(
            $entries,
            fn ($v) => is_string($v) && $v !== '',
        )) : [];

        $this->excludedCollections = is_array($collections) ? array_values(array_filter(
            $collections,
            fn ($v) => is_string($v) && $v !== '',
        )) : [];
    }

    /**
     * True when this entry should be invisible to the Suggestion machinery
     * (engines, badge counts, modal drill-in). Pure read; safe to call per
     * iteration in hot loops.
     */
    public function isExcluded(string $entryId, ?string $collection = null): bool
    {
        if ($entryId === '') {
            return false;
        }

        if (in_array($entryId, $this->excludedEntries, true)) {
            return true;
        }

        if ($collection !== null && $collection !== '' && in_array($collection, $this->excludedCollections, true)) {
            return true;
        }

        return false;
    }

    /**
     * Convenience overload for callers that already hold an EntryRecord.
     * Reads id + collection-handle off the record in one go.
     */
    public function isExcludedRecord(EntryRecord $record): bool
    {
        return $this->isExcluded($record->id, $record->collection);
    }

    /**
     * Are any filters configured at all? Cheap short-circuit for hot loops
     * — when both lists are empty, every call to isExcluded() would return
     * false and the gate is overhead.
     */
    public function hasFilters(): bool
    {
        return ! empty($this->excludedEntries) || ! empty($this->excludedCollections);
    }
}
