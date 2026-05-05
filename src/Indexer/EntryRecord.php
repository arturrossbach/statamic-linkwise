<?php

namespace Arturrossbach\Linkwise\Indexer;

class EntryRecord
{
    /**
     * @param  array<string, float>  $keywords  TF-IDF keyword scores (term => score)
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
            'keywords' => $this->keywords,
            'inbound_suggestion_count' => $this->inboundSuggestionCount,
            'outbound_suggestion_count' => $this->outboundSuggestionCount,
            'has_title_match' => $this->hasTitleMatch,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'],
            url: $data['url'] ?? null,
            collection: $data['collection'],
            text: $data['text'] ?? '',
            outboundLinks: $data['outbound_links'] ?? [],
            keywords: $data['keywords'] ?? [],
            inboundSuggestionCount: $data['inbound_suggestion_count'] ?? 0,
            outboundSuggestionCount: $data['outbound_suggestion_count'] ?? 0,
            hasTitleMatch: $data['has_title_match'] ?? false,
        );
    }
}
