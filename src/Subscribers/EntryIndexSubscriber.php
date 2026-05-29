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

            // H-6 (Code-Review 2026-05-29): mirror buildIndex's status filter
            // (EntryIndexer.php:59-60). The full scan skips unpublished entries
            // when entry_status is 'published' (the default); handleSaved did
            // not, so drafts polluted the index and a published→draft save left
            // a stale published record behind. Drop any existing record on this
            // path so the incremental index converges to the same state a full
            // rescan would produce.
            $statusFilter = config('linkwise.entry_status', 'published');
            if ($statusFilter === 'published' && method_exists($entry, 'published') && ! $entry->published()) {
                $records = $this->indexer->load();
                if (isset($records[$entry->id()])) {
                    unset($records[$entry->id()]);
                    $this->indexer->save($records);
                }

                return;
            }

            $records = $this->indexer->load();
            $previous = $records[$entry->id()] ?? null;
            $record = $this->indexer->indexEntry($entry);

            if ($record !== null) {
                // Re-extract keywords for the saved entry against the existing
                // corpus. Read pre-tokenized tokens from each EntryRecord
                // (stored by indexEntry on the last full Scan Content) — saves
                // the O(N) re-tokenize-everything that used to land in every
                // editor save and made saves lag 3-10s on 1000-entry sites.
                // Fallback to on-the-fly tokenize for legacy index entries
                // written before the tokens field shipped (empty array). Once
                // a Scan Content runs after the upgrade, the fallback path
                // never fires again.
                $corpusTokens = [];
                foreach ($records as $id => $existing) {
                    if ($id === $record->id) continue;
                    $corpusTokens[$id] = ! empty($existing->tokens)
                        ? $existing->tokens
                        : $this->extractor->tokenize($existing->title.' '.$existing->text);
                }

                $keywords = $this->extractor->extractSingle(
                    $record->title.' '.$record->text,
                    $corpusTokens,
                    // Title-Protect against the FrequencyFilter
                    // (2026-05-22): mid-frequency domain words that
                    // the editor put in the title (e.g. `Rezept`,
                    // `Notebook`) survive the filter that would
                    // otherwise treat them as common-language junk.
                    $record->title,
                );

                // Preserve the previous record's suggestion counts +
                // hasTitleMatch — indexEntry() returns a fresh record
                // with default-zero counts (those fields are computed
                // by the suggestion-engine pass, not by content
                // walking). Without preserving here, every editor save
                // resets that entry's dashboard counts to 0/0/false
                // until the next full Scan Content rebuild.
                //
                // REV-DR-03 (2026-05-13): was 15-line `new EntryRecord(...)`
                // field-by-field copy. Missing outboundLinkOccurrences here
                // caused inbound-count drift (commit f57bc85) — `with()`
                // collapses the override to the three engine-computed
                // fields we actually need to roll back from $previous;
                // every other field flows through unchanged from the
                // freshly-indexed $record. Adding a new EntryRecord field
                // no longer requires editing this file.
                $record = $record->with([
                    'keywords' => $keywords,
                    'inboundSuggestionCount' => $previous?->inboundSuggestionCount ?? 0,
                    'outboundSuggestionCount' => $previous?->outboundSuggestionCount ?? 0,
                    'hasTitleMatch' => $previous?->hasTitleMatch ?? false,
                ]);

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
