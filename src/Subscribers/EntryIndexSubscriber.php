<?php

namespace Inkline\Linkwise\Subscribers;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Indexer\EntryRecord;
use Inkline\Linkwise\NLP\KeywordExtractor;
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

                $record = new EntryRecord(
                    id: $record->id,
                    title: $record->title,
                    url: $record->url,
                    collection: $record->collection,
                    text: $record->text,
                    outboundLinks: $record->outboundLinks,
                    keywords: $keywords,
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
