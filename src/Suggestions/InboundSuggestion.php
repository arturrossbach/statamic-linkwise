<?php

namespace Arturrossbach\Linkwise\Suggestions;

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
        // V1.2 Cross-Tab-E — source's ISO locale. Surfaces in the
        // Modal-header + per-row badge so the editor can confirm the
        // same-locale-filter actually worked. Null = single-site /
        // legacy record (badge hides).
        public readonly ?string $sourceLocale = null,
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
            'source_locale' => $this->sourceLocale,
        ];
    }

    /**
     * Round-trip constructor for cache deserialisation (Sprint 6 REV-IB-01).
     * Mirrors `BrokenLinkRecord::fromArray()`: throws on missing required
     * keys so a corrupt cache row can't poison the suggestion list.
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['source_entry_id']) || ! is_string($data['source_entry_id'])) {
            throw new \InvalidArgumentException('InboundSuggestion: missing required field "source_entry_id"');
        }
        if (empty($data['target_entry_id']) || ! is_string($data['target_entry_id'])) {
            throw new \InvalidArgumentException('InboundSuggestion: missing required field "target_entry_id"');
        }

        return new self(
            sourceEntryId: $data['source_entry_id'],
            sourceTitle: isset($data['source_title']) && is_string($data['source_title']) ? $data['source_title'] : '',
            sourceUrl: isset($data['source_url']) && is_string($data['source_url']) ? $data['source_url'] : null,
            sourceCollection: isset($data['source_collection']) && is_string($data['source_collection']) ? $data['source_collection'] : '',
            targetEntryId: $data['target_entry_id'],
            anchorText: isset($data['anchor_text']) && is_string($data['anchor_text']) ? $data['anchor_text'] : '',
            sentenceContext: isset($data['sentence_context']) && is_string($data['sentence_context']) ? $data['sentence_context'] : '',
            score: isset($data['score']) && is_numeric($data['score']) ? (float) $data['score'] : 0.0,
            contextTruncatedStart: (bool) ($data['context_truncated_start'] ?? false),
            contextTruncatedEnd: (bool) ($data['context_truncated_end'] ?? false),
            matchType: isset($data['match_type']) && is_string($data['match_type']) ? $data['match_type'] : '',
            matchReason: isset($data['match_reason']) && is_string($data['match_reason']) ? $data['match_reason'] : '',
            sourceLocale: isset($data['source_locale']) && is_string($data['source_locale']) && $data['source_locale'] !== '' ? $data['source_locale'] : null,
        );
    }
}
