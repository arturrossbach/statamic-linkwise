<?php

namespace Arturrossbach\Linkwise\Suggestions;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Support\BardLinkInserter;

/**
 * Single source of truth for outbound suggestion grouping + counting.
 * Used by: OutboundController (modal API), EntryIndexer (pre-computed counts).
 */
class OutboundSuggestionGrouper
{
    /**
     * Get filtered, grouped outbound suggestions for an entry.
     *
     * @param  Suggestion[]  $suggestions  Raw suggestions from SuggestionEngine
     * @param  string  $entryId  The source entry ID
     * @return array{groups: array, count: int}
     */
    public static function groupAndFilter(array $suggestions, string $entryId): array
    {
        // Dry-run filter: only keep suggestions that can actually be inserted.
        // The 6th argument ($s->sentenceContext) mirrors the real-write
        // path in LinkInsertCommand:198-211. Without it the dry-run accepts
        // suggestions whose sentence-context lies in a non-writable region,
        // the outbound modal shows them, the user clicks Apply, the
        // real-write rejects with `context_mismatch`. Same parity fix
        // as InboundEngine::suggestFiltered (4e6573d) and the EntryIndexer
        // Phase-2 verify-loop. Klasse-B B-1 audit-finding 2026-05-16.
        $filtered = array_filter($suggestions, function (Suggestion $s) use ($entryId) {
            $href = 'statamic://entry::'.$s->targetEntryId;

            try {
                return BardLinkInserter::insertLinkIntoEntryWithHref(
                    $entryId, $s->anchorText, $href, false, false, $s->sentenceContext
                );
            } catch (\Throwable) {
                return false;
            }
        });

        // Group by anchor+context (same phrase → multiple target options)
        $groupMap = [];
        foreach ($filtered as $s) {
            $key = mb_strtolower($s->anchorText).'||'.mb_substr($s->sentenceContext, 0, 50);

            if (! isset($groupMap[$key])) {
                $groupMap[$key] = [
                    'key' => $key,
                    'anchor_text' => $s->anchorText,
                    'sentence_context' => $s->sentenceContext,
                    'context_truncated_start' => $s->contextTruncatedStart,
                    'context_truncated_end' => $s->contextTruncatedEnd,
                    'targets' => [],
                ];
            }

            // Deduplicate by target_entry_id within the group
            $targetIds = array_column($groupMap[$key]['targets'], 'target_entry_id');
            if (! in_array($s->targetEntryId, $targetIds, true)) {
                $groupMap[$key]['targets'][] = [
                    'target_entry_id' => $s->targetEntryId,
                    'target_title' => $s->targetTitle,
                    'target_collection' => $s->targetCollection,
                    'target_locale' => $s->targetLocale,
                    'anchor_text' => $s->anchorText,
                    'score' => $s->score,
                    'sentence_context' => $s->sentenceContext,
                    'context_truncated_start' => $s->contextTruncatedStart,
                    'context_truncated_end' => $s->contextTruncatedEnd,
                    'match_type' => $s->matchType,
                    'match_reason' => $s->matchReason,
                ];
            }
        }

        // Sort targets within each group by score desc
        foreach ($groupMap as &$group) {
            usort($group['targets'], fn ($a, $b) => $b['score'] <=> $a['score']);
        }
        unset($group);

        // Sort groups by best target score desc
        $groups = array_values($groupMap);
        usort($groups, fn ($a, $b) => ($b['targets'][0]['score'] ?? 0) <=> ($a['targets'][0]['score'] ?? 0));

        return [
            'groups' => $groups,
            'count' => count($groups),
        ];
    }

    /**
     * Count outbound suggestion groups for an entry (for index pre-computation).
     */
    public static function countGroups(array $suggestions, string $entryId): int
    {
        return self::groupAndFilter($suggestions, $entryId)['count'];
    }
}
