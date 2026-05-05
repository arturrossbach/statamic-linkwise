<?php

namespace Inkline\Linkwise\Suggestions;

class InboundSuggestion
{
    public function __construct(
        public readonly string $sourceEntryId,
        public readonly string $sourceTitle,
        public readonly ?string $sourceUrl,
        public readonly string $sourceCollection,
        public readonly string $targetEntryId,
        public readonly string $anchorText,
        public readonly string $sentenceContext,
        public readonly float $score,
        public readonly bool $contextTruncatedStart = false,
        public readonly bool $contextTruncatedEnd = false,
        public readonly string $matchType = '',
        public readonly string $matchReason = '',
    ) {}

    public function toArray(): array
    {
        return [
            'source_entry_id' => $this->sourceEntryId,
            'source_title' => $this->sourceTitle,
            'source_url' => $this->sourceUrl,
            'source_collection' => $this->sourceCollection,
            'target_entry_id' => $this->targetEntryId,
            'anchor_text' => $this->anchorText,
            'sentence_context' => $this->sentenceContext,
            'score' => $this->score,
            'context_truncated_start' => $this->contextTruncatedStart,
            'context_truncated_end' => $this->contextTruncatedEnd,
            'match_type' => $this->matchType,
            'match_reason' => $this->matchReason,
        ];
    }
}
