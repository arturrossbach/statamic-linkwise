<?php

namespace Arturrossbach\Linkwise\Indexer;

use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\NLP\KeywordExtractor;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

class EntryIndexer
{
    protected string $storagePath;

    protected KeywordExtractor $keywordExtractor;

    public function __construct(?string $storagePath = null, ?KeywordExtractor $keywordExtractor = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
        $this->keywordExtractor = $keywordExtractor ?? new KeywordExtractor;
    }

    /**
     * Build the full index from all entries, including TF-IDF keyword extraction.
     *
     * @return EntryRecord[]
     */
    public function buildIndex(bool $withSuggestions = false): array
    {
        $collections = config('linkwise.collections', []);
        $excludedCollections = config('linkwise.excluded_collections', []);
        $excludedEntries = config('linkwise.excluded_entries', []);
        $statusFilter = config('linkwise.entry_status', 'published');

        $query = EntryFacade::query();

        if (! empty($collections)) {
            $query->whereIn('collection', $collections);
        }

        $entries = $query->get();
        $records = [];

        foreach ($entries as $entry) {
            // Skip excluded collections
            if (! empty($excludedCollections) && in_array($entry->collectionHandle(), $excludedCollections, true)) {
                continue;
            }

            // Skip excluded entries
            if (! empty($excludedEntries) && in_array($entry->id(), $excludedEntries, true)) {
                continue;
            }

            // Skip unpublished entries if status filter is 'published'
            if ($statusFilter === 'published' && method_exists($entry, 'published') && ! $entry->published()) {
                continue;
            }

            $record = $this->indexEntry($entry);

            if ($record !== null) {
                $records[$record->id] = $record;
            }
        }

        // Extract TF-IDF keywords across the full corpus
        $records = $this->enrichWithKeywords($records);

        if ($withSuggestions) {
            $records = $this->enrichWithSuggestionCounts($records);
        } else {
            // Preserve suggestion counts from previous index
            $records = $this->preserveSuggestionCounts($records);
        }

        return $records;
    }

    /**
     * Pre-compute suggestion counts for all records.
     * This avoids expensive live computation on every page load.
     *
     * @param  EntryRecord[]  $records
     * @return EntryRecord[]
     */
    /**
     * Carry over suggestion counts from the previous index (fast rebuild).
     */
    protected function preserveSuggestionCounts(array $records): array
    {
        $oldRecords = $this->load();
        $enriched = [];

        foreach ($records as $id => $record) {
            $old = $oldRecords[$id] ?? null;
            $enriched[$id] = new EntryRecord(
                id: $record->id,
                title: $record->title,
                url: $record->url,
                collection: $record->collection,
                text: $record->text,
                outboundLinks: $record->outboundLinks,
                keywords: $record->keywords,
                inboundSuggestionCount: $old?->inboundSuggestionCount ?? 0,
                outboundSuggestionCount: $old?->outboundSuggestionCount ?? 0,
                hasTitleMatch: $old?->hasTitleMatch ?? false,
            );
        }

        return $enriched;
    }

    protected function enrichWithSuggestionCounts(array $records): array
    {
        return $this->enrichWithSuggestionCountsStreamed($records);
    }

    /**
     * Compute suggestion counts with optional progress callback.
     *
     * @param  callable|null  $onProgress  fn(int $current, int $total, string $title)
     */
    public function enrichWithSuggestionCountsStreamed(array $records, ?callable $onProgress = null): array
    {
        $engine = app(\Arturrossbach\Linkwise\Suggestions\SuggestionEngine::class);
        $inboundEngine = app(\Arturrossbach\Linkwise\Suggestions\InboundEngine::class);

        $total = count($records);
        $current = 0;
        $outboundCounts = [];
        $hasTitleMatch = array_fill_keys(array_keys($records), false);

        // Collect ALL inverted inbound candidates: target → [{sourceId, anchorText}]
        $inboundCandidates = [];

        // Phase 1: Outbound suggestions + inbound candidate collection
        foreach ($records as $entryId => $record) {
            $current++;
            if ($onProgress) {
                $onProgress($current, $total, $record->title);
            }

            // Unlimited suggestions for accurate inbound inversion (Phase 2)
            $allSuggestions = $engine->suggest($record->text, $records, $entryId, $record->outboundLinks, maxSuggestions: 0);

            // Outbound count: SEPARATE engine call with default limit (identical to modal API)
            $outboundSuggestions = $engine->suggest($record->text, $records, $entryId, $record->outboundLinks);
            $outboundCounts[$entryId] = \Arturrossbach\Linkwise\Suggestions\OutboundSuggestionGrouper::countGroups($outboundSuggestions, $entryId);

            // Collect inbound candidates + title match tracking
            foreach ($allSuggestions as $s) {
                if (in_array($s->matchType, ['title', 'stem'], true)) {
                    $hasTitleMatch[$entryId] = true;
                    if (isset($hasTitleMatch[$s->targetEntryId])) {
                        $hasTitleMatch[$s->targetEntryId] = true;
                    }
                }

                $inboundCandidates[$s->targetEntryId][] = [
                    'sourceEntryId' => $entryId,
                    'anchorText' => $s->anchorText,
                    'targetEntryId' => $s->targetEntryId,
                ];
            }
        }

        // Phase 2: Verify inbound candidates with exact same filters as modal
        // (anchorIsLinkedInEntry + dry-run insert)
        $inboundCounts = [];
        foreach ($records as $entryId => $record) {
            if (! isset($inboundCandidates[$entryId])) {
                $inboundCounts[$entryId] = 0;
                continue;
            }

            $verified = 0;
            // Deduplicate by source+anchor (matches modal semantics): the
            // modal shows one row per (source, anchor) opportunity. Two
            // anchors from the same source pointing to this target are two
            // distinct link decisions, not one. The previous per-source
            // dedup made the table report "1 inbound" while the modal
            // listed 2 rows — same data, two contradictory numbers.
            $seen = [];
            foreach ($inboundCandidates[$entryId] as $candidate) {
                $dedupKey = $candidate['sourceEntryId'].'|'.mb_strtolower($candidate['anchorText']);
                if (isset($seen[$dedupKey])) {
                    continue;
                }

                // Filter 1: anchor not already linked in source entry
                try {
                    if ($inboundEngine->anchorIsLinkedInEntry($candidate['sourceEntryId'], $candidate['anchorText'])) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Falls through to Filter 2; the dry-run insert will fail
                    // for already-linked anchors anyway, so a Filter 1 throw
                    // doesn't currently overcount. Log so we notice when it
                    // happens — silent failure here masked the indexAll bug
                    // for a full day before someone noticed.
                    Log::warning('[Linkwise] anchorIsLinkedInEntry failed during Phase 2: '.$e->getMessage());
                }

                // Filter 2: dry-run insert (same as modal endpoint)
                $href = 'statamic://entry::'.$candidate['targetEntryId'];
                try {
                    if (\Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkIntoEntryWithHref(
                        $candidate['sourceEntryId'], $candidate['anchorText'], $href, false, false
                    )) {
                        $verified++;
                        $seen[$dedupKey] = true;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Linkwise] dry-run insert failed during Phase 2 for entry '.$candidate['sourceEntryId'].': '.$e->getMessage());
                }
            }

            $inboundCounts[$entryId] = $verified;
        }

        $enriched = [];
        foreach ($records as $id => $record) {
            $enriched[$id] = new EntryRecord(
                id: $record->id,
                title: $record->title,
                url: $record->url,
                collection: $record->collection,
                text: $record->text,
                outboundLinks: $record->outboundLinks,
                keywords: $record->keywords,
                inboundSuggestionCount: $inboundCounts[$id] ?? 0,
                outboundSuggestionCount: $outboundCounts[$id] ?? 0,
                hasTitleMatch: $hasTitleMatch[$id] ?? false,
            );
        }

        return $enriched;
    }

    /**
     * Compute live suggestion counts for specific entries and persist to the index.
     * Uses the same code path as the modal APIs (including dry-run filter) for consistency.
     *
     * @param  string[]  $entryIds
     * @return array<string, array{inbound: int, outbound: int}>
     */
    public function computeSuggestionCountsForEntries(array $entryIds): array
    {
        $records = $this->load();
        $engine = app(\Arturrossbach\Linkwise\Suggestions\SuggestionEngine::class);
        $inboundEngine = app(\Arturrossbach\Linkwise\Suggestions\InboundEngine::class);
        $counts = [];
        $changed = false;

        foreach ($entryIds as $entryId) {
            if (! isset($records[$entryId])) {
                continue;
            }

            $record = $records[$entryId];

            // Outbound: same code path as OutboundController (via shared Grouper)
            $suggestions = $engine->suggest($record->text, $records, $entryId, $record->outboundLinks);
            $outboundCount = \Arturrossbach\Linkwise\Suggestions\OutboundSuggestionGrouper::countGroups($suggestions, $entryId);

            // Inbound: use suggestFiltered (single source of truth)
            $inboundCount = count($inboundEngine->suggestFiltered($entryId));

            $counts[$entryId] = [
                'inbound' => $inboundCount,
                'outbound' => $outboundCount,
            ];

            // Update the index record so counts persist on reload
            if ($record->inboundSuggestionCount !== $inboundCount || $record->outboundSuggestionCount !== $outboundCount) {
                $records[$entryId] = new EntryRecord(
                    id: $record->id,
                    title: $record->title,
                    url: $record->url,
                    collection: $record->collection,
                    text: $record->text,
                    outboundLinks: $record->outboundLinks,
                    keywords: $record->keywords,
                    inboundSuggestionCount: $inboundCount,
                    outboundSuggestionCount: $outboundCount,
                    hasTitleMatch: $record->hasTitleMatch,
                );
                $changed = true;
            }
        }

        // Persist updated counts to disk
        if ($changed) {
            $this->save($records);
            $this->cachedRecords = $records;
        }

        return $counts;
    }

    /**
     * Enrich all records with TF-IDF keywords computed across the corpus.
     *
     * @param  EntryRecord[]  $records
     * @return EntryRecord[]
     */
    protected function enrichWithKeywords(array $records): array
    {
        $corpus = [];
        foreach ($records as $id => $record) {
            // Combine title + text for keyword extraction
            $corpus[$id] = $record->title.' '.$record->text;
        }

        $allKeywords = $this->keywordExtractor->extractAll($corpus);

        $enriched = [];
        foreach ($records as $id => $record) {
            $enriched[$id] = new EntryRecord(
                id: $record->id,
                title: $record->title,
                url: $record->url,
                collection: $record->collection,
                text: $record->text,
                outboundLinks: $record->outboundLinks,
                keywords: $allKeywords[$id] ?? [],
            );
        }

        return $enriched;
    }

    /**
     * Index a single entry.
     */
    public function indexEntry(Entry $entry): ?EntryRecord
    {
        $title = $entry->get('title') ?? $entry->title();

        if (empty($title)) {
            return null;
        }

        $extracted = $this->extractBardContent($entry);
        $text = '';
        $links = [];

        foreach ($extracted['bard'] as $bardContent) {
            $text .= TextExtractor::fromBard($bardContent)."\n";
            $links = array_merge($links, TextExtractor::linksFromBard($bardContent));
        }

        // Plain text from text/textarea/markdown fields nested in Replicator
        // sets — Peak Cards/Accordion/Quote/etc. live here. Without this loop
        // entries whose content sits in non-Bard sets are indexed as empty.
        if (! empty($extracted['plain'])) {
            $text .= implode("\n", $extracted['plain'])."\n";
        }

        // Also extract from Markdown and Textarea fields
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            $fields = [];
        }

        foreach ($fields as $handle => $field) {
            if (in_array($field->type(), ['markdown', 'textarea', 'text'], true) && $handle !== 'title') {
                $value = $entry->get($handle);

                if (is_string($value) && ! empty($value)) {
                    // Strip Markdown formatting for plain text — safe on
                    // plaintext fields too (no markdown syntax to strip = no-op).
                    $plain = preg_replace('/\[([^\[\]]+)\]\([^)]+\)/', '$1', $value); // [text](url) → text
                    $plain = preg_replace('/[#*_~`>]/', '', $plain); // Remove Markdown syntax
                    $text .= trim($plain)."\n";

                    // Extract Markdown links as outbound links — only for
                    // genuine `markdown` fields. `text`/`textarea` render as
                    // plaintext per Statamic's contract, so `[…](url)` there
                    // is literal text, not a link. Symmetric with the write-
                    // side retreat in BardLinkInserter — reads only what
                    // writes can also reach.
                    if ($field->type() === 'markdown') {
                        $links = array_merge($links, TextExtractor::linksFromMarkdown($value));
                    }
                }
            }
        }

        return new EntryRecord(
            id: $entry->id(),
            title: $title,
            url: $entry->url(),
            collection: $entry->collectionHandle(),
            text: trim($text),
            outboundLinks: array_unique($links),
        );
    }

    /**
     * Save the index to disk.
     *
     * @param  EntryRecord[]  $records
     */
    public function save(array $records): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $data = array_map(fn (EntryRecord $r) => $r->toArray(), $records);

        file_put_contents(
            $this->getIndexPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        // Update in-memory cache so subsequent reads see the saved state
        $this->cachedRecords = $records;
    }

    /**
     * Load the index from disk.
     *
     * @return EntryRecord[]
     */
    protected ?array $cachedRecords = null;

    public function load(): array
    {
        if ($this->cachedRecords !== null) {
            return $this->cachedRecords;
        }

        $path = $this->getIndexPath();

        if (! file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            return [];
        }

        $records = [];

        // Skip-on-invalid: one corrupt index entry must not break the
        // whole CP. EntryRecord::fromArray throws InvalidArgumentException
        // when required fields are missing — we log + skip and keep
        // serving the rest of the index. The audit command surfaces the
        // exact corrupt records for hygiene later.
        foreach ($data as $item) {
            if (! is_array($item)) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] EntryIndexer: skipping non-array index entry',
                );
                continue;
            }
            try {
                $record = EntryRecord::fromArray($item);
                $records[$record->id] = $record;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] EntryIndexer: skipping corrupt index entry — '.$e->getMessage(),
                );
            }
        }

        $this->cachedRecords = $records;

        return $records;
    }

    /**
     * Clear the in-memory cache (e.g. after rebuilding the index).
     */
    public function clearCache(): void
    {
        $this->cachedRecords = null;
    }

    /**
     * Get the age of the index file in seconds, or null if no index exists.
     */
    public function getIndexAge(): ?int
    {
        $path = $this->getIndexPath();

        if (! file_exists($path)) {
            return null;
        }

        return time() - filemtime($path);
    }

    /**
     * ISO timestamp of when the index was last built, or null if never.
     * Used by Overview tab to show data-freshness indicators.
     */
    public function getIndexLastBuiltAt(): ?string
    {
        $path = $this->getIndexPath();

        if (! file_exists($path)) {
            return null;
        }

        return date('c', filemtime($path));
    }

    protected function getIndexPath(): string
    {
        return $this->storagePath.'/index.json';
    }

    /**
     * Extract searchable content from an entry — both Bard JSON trees AND
     * plain strings nested inside Replicator sets (card headings, card text,
     * accordion content, button labels, etc.). Without the plain-string
     * branch, Peak sites that use Cards/Accordion/Quote sets had 0% of their
     * content indexed; Linkwise saw only entries with the dedicated `article`
     * Bard set.
     *
     * @return array{bard: array, plain: array<int, string>}
     */
    protected function extractBardContent(Entry $entry): array
    {
        $bardContents = [];
        $plainTexts = [];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] Could not read blueprint for entry '.$entry->id().': '.$e->getMessage());

            return ['bard' => $bardContents, 'plain' => $plainTexts];
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->value($handle);

            if (! is_array($value) || empty($value)) {
                continue;
            }

            if ($field->type() === 'bard') {
                $bardContents[] = $value;
            } elseif ($field->type() === 'replicator') {
                $this->extractBardFromReplicator($value, $bardContents, $plainTexts);
            }
        }

        return ['bard' => $bardContents, 'plain' => $plainTexts];
    }

    /**
     * Recursively walk Replicator set items, collecting both Bard arrays AND
     * plain string values (text, textarea, markdown fields). Filters out
     * replicator metadata, UUIDs (entry/asset references), numeric values,
     * boolean-like strings, and very short strings to keep noise out of the
     * keyword pool.
     */
    protected function extractBardFromReplicator(array $sets, array &$bardContents, array &$plainTexts): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                // Skip replicator metadata keys (id, type, enabled)
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                    continue;
                }

                // Plain string — gather if it carries actual content. Skip
                // UUIDs leaked from entry/asset link fields, numeric values
                // (image dimensions, sort indexes), and boolean-like markers.
                if (is_string($value)) {
                    $this->collectPlainString($value, $plainTexts);
                    continue;
                }

                if (! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $bardContents[] = $value;
                } elseif ($this->looksLikeReplicatorContent($value)) {
                    $this->extractBardFromReplicator($value, $bardContents, $plainTexts);
                }
            }
        }
    }

    /**
     * Apply quality filters before adding a string value to the plain-text
     * pool. Anything that survives gets fed into keyword extraction and
     * anchor matching downstream, so the filters guard against UUIDs
     * surfacing as anchors and against pure-noise tokens polluting TF-IDF.
     */
    protected function collectPlainString(string $value, array &$plainTexts): void
    {
        $trimmed = trim($value);

        if (mb_strlen($trimmed) < 5) {
            return;
        }
        if (is_numeric($trimmed)) {
            return;
        }
        if (in_array(mb_strtolower($trimmed), ['true', 'false', 'yes', 'no', 'null'], true)) {
            return;
        }
        // UUID v4 pattern — entry references, asset IDs, button IDs leak
        // through replicator value lists. Without this filter they showed
        // up as anchor candidates ("9beb33f6-..." as match keyword).
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $trimmed)) {
            return;
        }
        // Strip Markdown formatting so the plain content reads cleanly when
        // it gets embedded into the entry text — same approach as the
        // top-level markdown handling in indexEntry().
        $clean = preg_replace('/\[([^\[\]]+)\]\([^)]+\)/', '$1', $trimmed);
        $clean = preg_replace('/[#*_~`>]/', '', $clean);
        $clean = trim($clean);

        if ($clean !== '') {
            $plainTexts[] = $clean;
        }
    }

    /**
     * Check if an array looks like Replicator set data.
     */
    protected function looksLikeReplicatorContent(array $value): bool
    {
        $first = reset($value);

        return is_array($first) && isset($first['type']) && isset($first['id']);
    }
}
