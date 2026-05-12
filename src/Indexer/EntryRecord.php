<?php

namespace Arturrossbach\Linkwise\Indexer;

class EntryRecord
{
    /**
     * @param  array<string, float>  $keywords  TF-IDF keyword scores (term => score)
     * @param  list<string>  $tokens  Pre-tokenized title+text used by EntryIndexSubscriber
     *   to skip the per-save full-corpus re-tokenization. Computed during
     *   buildIndex via KeywordExtractor::tokenize. Empty for legacy index
     *   entries written before this field shipped — callers fall back to
     *   on-demand tokenize when missing. Re-computed on every Scan Content
     *   so language/stopwords config changes propagate.
     */
    /**
     * @param  list<string>  $outboundLinks  Distinct target IDs (deduped). Used by
     *   "already linked" / `in_array` checks everywhere — semantics preserved.
     * @param  list<string>  $outboundLinkOccurrences  Target IDs PER text-node link mark
     *   (NOT deduped). Used by LinkReport for inbound-count parity with the modal's
     *   per-occurrence listing (Bug 2026-05-12: index column showed 3 because
     *   outboundLinks deduplicated multi-link sources; modal showed 4 because it
     *   walks each text-node separately). Legacy index records without this field
     *   fall back to outboundLinks (= distinct count, the old behaviour).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $collection,
        public readonly string $text,
        public readonly array $outboundLinks,
        public readonly array $keywords = [],
        public readonly int $inboundSuggestionCount = 0,
        public readonly int $outboundSuggestionCount = 0,
        public readonly bool $hasTitleMatch = false,
        public readonly array $tokens = [],
        public readonly array $outboundLinkOccurrences = [],
    ) {}

    /** Return a copy with an updated inbound suggestion count. */
    public function withInboundSuggestionCount(int $count): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            url: $this->url,
            collection: $this->collection,
            text: $this->text,
            outboundLinks: $this->outboundLinks,
            keywords: $this->keywords,
            inboundSuggestionCount: $count,
            outboundSuggestionCount: $this->outboundSuggestionCount,
            hasTitleMatch: $this->hasTitleMatch,
            tokens: $this->tokens,
            outboundLinkOccurrences: $this->outboundLinkOccurrences,
        );
    }

    /** Return a copy with an updated outbound suggestion count. */
    public function withOutboundSuggestionCount(int $count): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            url: $this->url,
            collection: $this->collection,
            text: $this->text,
            outboundLinks: $this->outboundLinks,
            keywords: $this->keywords,
            inboundSuggestionCount: $this->inboundSuggestionCount,
            outboundSuggestionCount: $count,
            hasTitleMatch: $this->hasTitleMatch,
            tokens: $this->tokens,
            outboundLinkOccurrences: $this->outboundLinkOccurrences,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'collection' => $this->collection,
            'text' => $this->text,
            'outbound_links' => $this->outboundLinks,
            'outbound_link_occurrences' => $this->outboundLinkOccurrences,
            'keywords' => $this->keywords,
            'inbound_suggestion_count' => $this->inboundSuggestionCount,
            'outbound_suggestion_count' => $this->outboundSuggestionCount,
            'has_title_match' => $this->hasTitleMatch,
            'tokens' => $this->tokens,
        ];
    }

    /**
     * @throws \InvalidArgumentException when required fields are missing/empty.
     *   Loaders MUST catch this and skip the offending record so that
     *   one corrupt entry can't break the whole index read.
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['id']) || ! is_string($data['id'])) {
            throw new \InvalidArgumentException('EntryRecord: missing required field "id"');
        }
        if (! isset($data['title']) || ! is_string($data['title'])) {
            throw new \InvalidArgumentException('EntryRecord: missing required field "title"');
        }
        if (empty($data['collection']) || ! is_string($data['collection'])) {
            throw new \InvalidArgumentException('EntryRecord: missing required field "collection"');
        }

        return new self(
            id: $data['id'],
            title: $data['title'],
            url: isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            collection: $data['collection'],
            text: isset($data['text']) && is_string($data['text']) ? $data['text'] : '',
            outboundLinks: isset($data['outbound_links']) && is_array($data['outbound_links']) ? $data['outbound_links'] : [],
            keywords: isset($data['keywords']) && is_array($data['keywords']) ? $data['keywords'] : [],
            inboundSuggestionCount: (int) ($data['inbound_suggestion_count'] ?? 0),
            outboundSuggestionCount: (int) ($data['outbound_suggestion_count'] ?? 0),
            hasTitleMatch: (bool) ($data['has_title_match'] ?? false),
            tokens: isset($data['tokens']) && is_array($data['tokens']) ? array_values(array_filter($data['tokens'], 'is_string')) : [],
            outboundLinkOccurrences: isset($data['outbound_link_occurrences']) && is_array($data['outbound_link_occurrences']) ? $data['outbound_link_occurrences'] : [],
        );
    }
}
