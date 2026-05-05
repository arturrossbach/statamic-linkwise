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
    public function __construct(
        protected EntryIndexer $indexer,
        protected SuggestionEngine $engine,
        protected TargetKeywordManager $keywordManager,
    ) {}

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
        $suggestions = $this->suggest($targetEntryId, $limit);

        $filtered = array_values(array_filter($suggestions, function ($s) {
            $href = 'statamic://entry::'.$s->targetEntryId;

            try {
                return \Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkIntoEntryWithHref(
                    $s->sourceEntryId, $s->anchorText, $href, false, false
                );
            } catch (\Throwable) {
                return false;
            }
        }));

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
            } catch (\Throwable) {
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
     */
    public function anchorIsLinkedInEntry(string $entryId, string $anchorText): bool
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            return false;
        }

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return false;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                if ($this->bardHasLinkedText($value, $anchorText)) {
                    return true;
                }
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                if ($this->replicatorHasLinkedAnchor($value, $anchorText)) {
                    return true;
                }
            } elseif (in_array($field->type(), ['markdown', 'textarea', 'text'], true)
                && is_string($value) && ! empty($value) && $handle !== 'title') {
                // Symmetric to BardLinkInserter: any plain-string field at
                // top-level can carry a markdown link via [text](href). Check
                // for it so the dry-run filter doesn't re-suggest the same
                // anchor that's already been linked there.
                if ($this->markdownHasLinkedOverlap($value, $anchorText)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively walk a Replicator value tree, returning true if any nested
     * Bard tree OR plain-string field already contains a link with an anchor
     * overlapping the supplied anchorText. Plain strings are checked the
     * same way markdown fields are — covers Peak Cards heading/text,
     * accordion bodies, button labels and similar nested user content.
     */
    protected function replicatorHasLinkedAnchor(array $sets, string $anchorText): bool
    {
        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $key => $val) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                    continue;
                }

                if (is_string($val)) {
                    if ($val === '') {
                        continue;
                    }
                    if ($this->markdownHasLinkedOverlap($val, $anchorText)) {
                        return true;
                    }
                    continue;
                }

                if (! is_array($val) || empty($val)) {
                    continue;
                }

                if (\Arturrossbach\Linkwise\Support\ProseMirrorTypes::looksLikeBardContent($val)) {
                    if ($this->bardHasLinkedText($val, $anchorText)) {
                        return true;
                    }
                } elseif (isset($val[0]['type'], $val[0]['id'])) {
                    if ($this->replicatorHasLinkedAnchor($val, $anchorText)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if any word in the anchor text overlaps with linked text in Bard content.
     */
    protected function bardHasLinkedText(array $content, string $anchorText): bool
    {
        $anchorWords = preg_split('/\s+/', mb_strtolower($anchorText));

        foreach ($content as $node) {
            if (isset($node['text'], $node['marks'])) {
                $hasLink = false;
                foreach ($node['marks'] as $mark) {
                    if (($mark['type'] ?? '') === 'link') {
                        $hasLink = true;
                        break;
                    }
                }
                if ($hasLink) {
                    $linkedWords = preg_split('/\s+/', mb_strtolower($node['text']));
                    foreach ($anchorWords as $w) {
                        if ($w !== '' && in_array($w, $linkedWords, true)) {
                            return true;
                        }
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                if ($this->bardHasLinkedText($node['content'], $anchorText)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if any word in the anchor text overlaps with a Markdown link's text.
     */
    protected function markdownHasLinkedOverlap(string $markdown, string $anchorText): bool
    {
        // Extract all link texts from Markdown: [link text](url)
        if (! preg_match_all('/\[([^\[\]]+)\]\([^)]+\)/', $markdown, $matches)) {
            return false;
        }

        $anchorWords = preg_split('/\s+/', mb_strtolower($anchorText));

        foreach ($matches[1] as $linkText) {
            $linkedWords = preg_split('/\s+/', mb_strtolower($linkText));
            foreach ($anchorWords as $w) {
                if ($w !== '' && in_array($w, $linkedWords, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
