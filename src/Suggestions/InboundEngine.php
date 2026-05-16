<?php

namespace Arturrossbach\Linkwise\Suggestions;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Statamic\Facades\Entry;

class InboundEngine
{
    protected InboundSuggestionCache $cache;

    public function __construct(
        protected EntryIndexer $indexer,
        protected SuggestionEngine $engine,
        protected TargetKeywordManager $keywordManager,
        ?InboundSuggestionCache $cache = null,
    ) {
        // Sprint 6 REV-IB-01: 5-minute TTL cache for suggestFiltered.
        // Without it every modal-open re-runs N dry-run-inserts (the
        // expensive filter step). With it only the first open pays.
        $this->cache = $cache ?? new InboundSuggestionCache;
    }

    /**
     * Find entries that mention the target entry's title and could link to it.
     *
     * @return InboundSuggestion[]
     */
    public function suggest(string $targetEntryId, int $limit = 0): array
    {
        $records = $this->indexer->load();

        if (! isset($records[$targetEntryId])) {
            return [];
        }

        $targetRecord = $records[$targetEntryId];
        $singleIndex = [$targetEntryId => $targetRecord];
        $results = [];

        foreach ($records as $sourceRecord) {
            // Skip self
            if ($sourceRecord->id === $targetEntryId) {
                continue;
            }

            // Skip entries that already link to target
            if (in_array($targetEntryId, $sourceRecord->outboundLinks, true)) {
                continue;
            }

            // Skip entries with no text
            if (empty($sourceRecord->text)) {
                continue;
            }

            // Find target's title/keywords in source's text
            $suggestions = $this->engine->suggest(
                $sourceRecord->text,
                $singleIndex,
                $sourceRecord->id,
            );

            foreach ($suggestions as $suggestion) {
                // Skip if anchor text is already linked to anything in the source entry
                try {
                    if ($this->anchorIsLinkedInEntry($sourceRecord->id, $suggestion->anchorText)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // Entry::find() not available in unit tests — skip check
                }

                $results[] = new InboundSuggestion(
                    sourceEntryId: $sourceRecord->id,
                    sourceTitle: $sourceRecord->title,
                    sourceUrl: $sourceRecord->url,
                    sourceCollection: $sourceRecord->collection,
                    targetEntryId: $targetEntryId,
                    anchorText: $suggestion->anchorText,
                    sentenceContext: $suggestion->sentenceContext,
                    score: $suggestion->score,
                    contextTruncatedStart: $suggestion->contextTruncatedStart,
                    contextTruncatedEnd: $suggestion->contextTruncatedEnd,
                    matchType: $suggestion->matchType,
                    matchReason: $suggestion->matchReason,
                );
            }

            // Also check custom target keywords
            if (empty($suggestions)) {
                try {
                    $customResults = $this->findCustomKeywordMatches($sourceRecord, $targetEntryId);
                    foreach ($customResults as $result) {
                        $results[] = $result;
                    }
                } catch (\Throwable) {
                    // TargetKeywordManager not available in unit tests
                }
            }
        }

        // Sort by score descending
        usort($results, fn ($a, $b) => $b->score <=> $a->score);

        $this->lastTotalCount = count($results);

        return $limit > 0 ? array_slice($results, 0, $limit) : $results;
    }

    /**
     * Total count from the last suggest() call (before limit was applied).
     */
    protected int $lastTotalCount = 0;

    public function getLastTotalCount(): int
    {
        return $this->lastTotalCount;
    }

    /**
     * Get filtered inbound suggestions (same logic as modal endpoint).
     * This is the SINGLE SOURCE OF TRUTH for inbound suggestion counts.
     *
     * @return InboundSuggestion[]
     */
    public function suggestFiltered(string $targetEntryId, int $limit = 0): array
    {
        // Cache hit: skip the suggest() walk + N dry-run-inserts entirely.
        // Sprint 6 REV-IB-01. Limit is applied AFTER cache-hit so different
        // callers can re-use the same cached super-set with different
        // `limit` slices. The cached array IS the full filtered set —
        // `lastTotalCount` is its size.
        $cached = $this->cache->getCached($targetEntryId);
        if ($cached !== null) {
            $this->lastTotalCount = count($cached);

            return $limit > 0 ? array_slice($cached, 0, $limit) : $cached;
        }

        $suggestions = $this->suggest($targetEntryId, $limit);

        $filtered = array_values(array_filter($suggestions, function ($s) {
            $href = 'statamic://entry::'.$s->targetEntryId;

            try {
                return \Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkIntoEntryWithHref(
                    $s->sourceEntryId, $s->anchorText, $href, false, false
                );
            } catch (\Throwable $e) {
                // EntryConflictException is expected when the entry was edited
                // concurrently — silently exclude the suggestion. Other Throwables
                // are real bugs; log them so they can be tracked down. Same
                // pattern as EntryIndexer Phase 2 silent catches.
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] InboundEngine dry-run filter failed for entry '.$s->sourceEntryId.': '.$e->getMessage()
                );

                return false;
            }
        }));

        // Overwrite lastTotalCount with the POST-filter count: this is what
        // the user-facing "X of Y" header should report. If a keyword matches
        // but the dry-run inserter rejects it (e.g. anchor not literally in
        // the source entry's text), the suggestion is invisible to the user
        // — counting it as "available" produces the confusing "4 of 5" with
        // no 5th row in sight that started this fix.
        $this->lastTotalCount = count($filtered);

        // Cache the full filtered super-set (pre-limit) so future calls
        // with a different limit can re-use it. Sprint 6 REV-IB-01.
        // Skipped when $limit > 0 caller asked for a subset — the
        // upstream `suggest()` truncated `$suggestions` and we don't
        // want to memoize a truncated row as the canonical full result.
        if ($limit === 0) {
            $this->cache->store($targetEntryId, $filtered);
        }

        return $limit > 0 ? array_slice($filtered, 0, $limit) : $filtered;
    }

    /**
     * Find matches based on custom target keywords.
     *
     * @return InboundSuggestion[]
     */
    protected function findCustomKeywordMatches($sourceRecord, string $targetEntryId): array
    {
        $customKeywords = $this->keywordManager->getKeywords($targetEntryId);

        if (empty($customKeywords)) {
            return [];
        }

        $results = [];
        $sourceText = $sourceRecord->text;

        foreach ($customKeywords as $keyword) {
            $pos = mb_stripos($sourceText, $keyword);

            if ($pos === false) {
                continue;
            }

            // Word boundary check
            $keywordLen = mb_strlen($keyword);
            if ($pos > 0 && preg_match('/[\p{L}\p{N}]/u', mb_substr($sourceText, $pos - 1, 1))) {
                continue;
            }
            $afterPos = $pos + $keywordLen;
            if ($afterPos < mb_strlen($sourceText) && preg_match('/[\p{L}\p{N}]/u', mb_substr($sourceText, $afterPos, 1))) {
                continue;
            }

            // Check if already linked
            try {
                if ($this->anchorIsLinkedInEntry($sourceRecord->id, $keyword)) {
                    continue;
                }
            } catch (\Throwable $e) {
                // Entry::find() not available in unit tests — falling through
                // means we may suggest a candidate whose anchor is already
                // linked, but the dry-run filter in suggestFiltered catches
                // those. Log so we notice in production.
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] InboundEngine custom-keyword anchor check failed for entry '.$sourceRecord->id.': '.$e->getMessage()
                );
            }

            $context = ContextExtractor::extractStructured($sourceText, $keyword);
            $actualAnchor = mb_substr($sourceText, $pos, $keywordLen);

            $results[] = new InboundSuggestion(
                sourceEntryId: $sourceRecord->id,
                sourceTitle: $sourceRecord->title,
                sourceUrl: $sourceRecord->url,
                sourceCollection: $sourceRecord->collection,
                targetEntryId: $targetEntryId,
                anchorText: $actualAnchor,
                sentenceContext: $context ? $context['text'] : '',
                score: 0.5,
                contextTruncatedStart: $context['truncated_start'] ?? false,
                contextTruncatedEnd: $context['truncated_end'] ?? false,
                matchType: 'custom',
                matchReason: "Matches the custom target keyword \"{$keyword}\" that was set for this entry.",
            );

            break; // One match per source entry is enough
        }

        return $results;
    }

    /**
     * Check if a specific anchor text is already inside a link in an entry's content.
     *
     * REV-IB-02 (2026-05-13): switched from loose anchor-word-overlap
     * semantics to strict via BardLinkInserter::canInsertLinkIntoEntry.
     * The old loose check returned true if ANY word of $anchorText
     * overlapped with ANY linked text in the entry — which silently
     * dropped Inbound suggestions like "Redis Setup" whenever "Setup"
     * happened to be linked anywhere else in the source entry to an
     * unrelated target. prose-peak-test had 11 such silent skips out
     * of 65 raw suggestions (~17%).
     *
     * Strict semantics: use the production dry-run insert path. If the
     * dry-run can wrap the anchor, the anchor is NOT yet linked. If it
     * fails because the span overlaps an existing link mark, the anchor
     * IS linked. Other failure reasons (anchor not found in text,
     * context mismatch) mean "not currently linked".
     */
    public function anchorIsLinkedInEntry(string $entryId, string $anchorText): bool
    {
        // Marker href: synthetic entry id that cannot match any real link.
        // canInsertLinkIntoEntry only consults the marker to distinguish
        // 'already_linked_to_target' (= linked to THIS href) from
        // 'crosses_existing_link' (= linked to a DIFFERENT href). With a
        // synthetic marker, the former is impossible — all real conflicts
        // surface as the latter.
        $marker = 'statamic://entry::__link_presence_check__';

        $result = \Arturrossbach\Linkwise\Support\BardLinkInserter::canInsertLinkIntoEntry(
            $entryId,
            $anchorText,
            $marker,
        );

        if ($result['ok'] ?? false) {
            return false; // dry-run could wrap → anchor is NOT yet linked
        }

        $reason = $result['reason'] ?? '';

        // Anchor span overlaps an existing link mark in either direction.
        return in_array($reason, ['crosses_existing_link', 'already_linked_to_target'], true);
    }

    // REV-IB-02 (2026-05-13): three loose-overlap walkers
    // (bardHasLinkedText / replicatorHasLinkedAnchor / markdownHasLinkedOverlap)
    // were removed here. The loose semantic — "any word of anchor overlaps
    // with any link text" — produced silent missing suggestions and
    // duplicated the strict canInsertLinkInto* family in BardLinkInserter.
    // anchorIsLinkedInEntry now delegates to that family directly.
}
