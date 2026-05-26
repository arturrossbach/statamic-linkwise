<?php

namespace Arturrossbach\Linkwise\Suggestions;

class Suggestion
{
    public function __construct(
        public readonly string $targetEntryId,
        public readonly string $targetTitle,
        public readonly ?string $targetUrl,
        public readonly string $targetCollection,
        public readonly string $anchorText,
        public readonly int $position,
        public readonly float $score,
        public readonly string $sentenceContext = '',
        public readonly bool $contextTruncatedStart = false,
        public readonly bool $contextTruncatedEnd = false,
        public readonly string $matchType = '',
        public readonly string $matchReason = '',
        // V1.2 Cross-Tab-E — target's ISO locale. Surfaces as a badge in
        // the Outbound-Modal so the editor can confirm same-locale-
        // filter actually scoped correctly. Null = single-site / legacy
        // record (badge hides).
        public readonly ?string $targetLocale = null,
    ) {}

    public function toArray(): array
    {
        return [
            'target_entry_id' => $this->targetEntryId,
            'target_title' => $this->targetTitle,
            'target_url' => $this->targetUrl,
            'target_collection' => $this->targetCollection,
            'anchor_text' => $this->anchorText,
            'position' => $this->position,
            'score' => $this->score,
            'sentence_context' => $this->sentenceContext,
            'context_truncated_start' => $this->contextTruncatedStart,
            'context_truncated_end' => $this->contextTruncatedEnd,
            'match_type' => $this->matchType,
            'match_reason' => $this->matchReason,
            'target_locale' => $this->targetLocale,
        ];
    }
}
