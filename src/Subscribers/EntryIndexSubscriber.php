<?php

namespace Arturrossbach\Linkwise\Subscribers;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\NLP\KeywordExtractor;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;

class EntryIndexSubscriber
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected KeywordExtractor $extractor,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaved::class, self::class.'@handleSaved');
        $events->listen(EntryDeleted::class, self::class.'@handleDeleted');
    }

    public function handleSaved(EntrySaved $event): void
    {
        try {
            $entry = $event->entry;

            $collections = config('linkwise.collections', []);

            if (! empty($collections) && ! in_array($entry->collectionHandle(), $collections, true)) {
                return;
            }

            $records = $this->indexer->load();
            $previous = $records[$entry->id()] ?? null;
            $record = $this->indexer->indexEntry($entry);

            if ($record !== null) {
                // Re-extract keywords for the saved entry against the existing corpus
                $corpusTokens = [];
                foreach ($records as $id => $existing) {
                    if ($id !== $record->id) {
                        $corpusTokens[$id] = $this->extractor->tokenize($existing->title.' '.$existing->text);
                    }
                }

                $keywords = $this->extractor->extractSingle(
                    $record->title.' '.$record->text,
                    $corpusTokens,
                );

                // Preserve the previous record's suggestion counts +
                // hasTitleMatch — indexEntry() returns a fresh record
                // with default-zero counts (those fields are computed
                // by the suggestion-engine pass, not by content
                // walking). Without preserving here, every editor save
                // resets that entry's dashboard counts to 0/0/false
                // until the next full Scan Content rebuild — confusing
                // for the user and inconsistent with how full-rebuild
                // (EntryIndexer::enrichWithKeywords, fixed earlier today
                // by the same defensive pattern) handles it.
                $record = new EntryRecord(
                    id: $record->id,
                    title: $record->title,
                    url: $record->url,
                    collection: $record->collection,
                    text: $record->text,
                    outboundLinks: $record->outboundLinks,
                    keywords: $keywords,
                    inboundSuggestionCount: $previous?->inboundSuggestionCount ?? 0,
                    outboundSuggestionCount: $previous?->outboundSuggestionCount ?? 0,
                    hasTitleMatch: $previous?->hasTitleMatch ?? false,
                );

                $records[$record->id] = $record;
            } else {
                unset($records[$entry->id()]);
            }

            $this->indexer->save($records);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] Failed to update index on entry save: '.$e->getMessage());
        }
    }

    public function handleDeleted(EntryDeleted $event): void
    {
        try {
            $entry = $event->entry;
            $records = $this->indexer->load();

            if (isset($records[$entry->id()])) {
                unset($records[$entry->id()]);
                $this->indexer->save($records);
            }
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] Failed to update index on entry delete: '.$e->getMessage());
        }
    }
}
