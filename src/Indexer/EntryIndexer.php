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
        // Note (2026-05-22): excluded_entries / excluded_collections used to
        // be applied HERE — the filter dropped excluded entries out of the
        // persisted index entirely. That semantics leaked into every Indexer
        // consumer: Domains, Broken-Links, URL-Changer, Activity-Log — even
        // though the blueprint copy explicitly promised "neither suggested
        // nor suggesting" (Suggestion-scope only). User-bug 2026-05-22:
        // putting `Home` in excluded_entries made the Domains panel empty
        // and the URL-Changer see phantom links.
        //
        // Post-fix: the Indexer is universe-of-entries-agnostic. Every
        // Suggestion-generating path consults `ExcludedEntryFilter` explicitly;
        // non-Suggestion reports see every entry. See [[architectural_health]]
        // for the new bug-class entry.
        $collections = config('linkwise.collections', []);
        $statusFilter = config('linkwise.entry_status', 'published');

        $query = EntryFacade::query();

        if (! empty($collections)) {
            $query->whereIn('collection', $collections);
        }

        $entries = $query->get();
        $records = [];

        foreach ($entries as $entry) {
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
            // Carry over the three engine-computed fields from the previous
            // index; everything else (id/title/url/collection/text/keywords/
            // outboundLinks/tokens/outboundLinkOccurrences) stays on $record.
            // REV-DR-03: `with()` collapses the manual 12-field copy.
            $enriched[$id] = $record->with([
                'inboundSuggestionCount' => $old?->inboundSuggestionCount ?? 0,
                'outboundSuggestionCount' => $old?->outboundSuggestionCount ?? 0,
                'hasTitleMatch' => $old?->hasTitleMatch ?? false,
            ]);
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
        // Excluded-entries gate (2026-05-22): post-Indexer-filter-removal, every
        // Suggestion-generating path consults ExcludedEntryFilter explicitly.
        // Here: source-side skip (no outbound suggestions for excluded sources)
        // + target-side skip (no inbound count for excluded targets).
        $excludedFilter = new \Arturrossbach\Linkwise\Suggestions\ExcludedEntryFilter;

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

            // Excluded entries contribute zero Suggestion-counts in either
            // direction. We still keep the record in the index for non-
            // Suggestion reports (Domains/BrokenLinks/Links Report).
            if ($excludedFilter->isExcludedRecord($record)) {
                $outboundCounts[$entryId] = 0;
                continue;
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
                    // Carried so the Phase-2 verify-loop's dry-run-insert
                    // call below uses the SAME 6-argument shape that
                    // `InboundEngine::suggestFiltered` (post-`4e6573d`) and
                    // `LinkInsertCommand::execute` real-write (Z. 198-211)
                    // use. Without it, Phase-2 over-counts: a candidate
                    // whose anchor sits in a writable region but whose
                    // sentence-context lives in a non-writable field
                    // would be accepted by a 5-arg dry-run but rejected
                    // by the real-write at apply-time. Persisted
                    // `inboundSuggestionCount` would surface the over-
                    // count as a higher table number than what
                    // `suggestFiltered` shows on modal drill-in (sister
                    // of the InboundEngine bug; Klasse-B B-2 audit
                    // 2026-05-16).
                    'sentenceContext' => $s->sentenceContext,
                ];
            }
        }

        // Phase 2: Verify inbound candidates with exact same filters as modal
        // (anchorIsLinkedInEntry + dry-run insert)
        $inboundCounts = [];
        foreach ($records as $entryId => $record) {
            // Excluded targets contribute zero inbound suggestions — even if
            // Phase 1 happened to collect candidates pointing here (excluded
            // sources skip Phase 1 entirely, but a non-excluded source whose
            // engine returned this excluded target would still surface here).
            if ($excludedFilter->isExcludedRecord($record)) {
                $inboundCounts[$entryId] = 0;
                continue;
            }
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

                // Filter 2: dry-run insert (same as modal endpoint).
                // The 6th argument ($candidate['sentenceContext']) mirrors
                // `InboundEngine::suggestFiltered` (post-`4e6573d`) and the
                // real-write `LinkInsertCommand:198-211`. Without it, this
                // verify-loop accepts candidates whose sentence-context
                // lies in a non-writable region (subtitle/textarea/plain-
                // string replicator set) — the persisted
                // `inboundSuggestionCount` would over-count vs. what the
                // modal's `suggestFiltered` shows on drill-in. Klasse-B
                // B-2 audit-finding 2026-05-16.
                $href = 'statamic://entry::'.$candidate['targetEntryId'];
                try {
                    if (\Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkIntoEntryWithHref(
                        $candidate['sourceEntryId'], $candidate['anchorText'], $href, false, false, $candidate['sentenceContext']
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
            // REV-DR-03: was 13-line `new EntryRecord(...)` field-by-field copy.
            $enriched[$id] = $record->with([
                'inboundSuggestionCount' => $inboundCounts[$id] ?? 0,
                'outboundSuggestionCount' => $outboundCounts[$id] ?? 0,
                'hasTitleMatch' => $hasTitleMatch[$id] ?? false,
            ]);
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
        $excludedFilter = new \Arturrossbach\Linkwise\Suggestions\ExcludedEntryFilter;
        $counts = [];
        $changed = false;

        foreach ($entryIds as $entryId) {
            if (! isset($records[$entryId])) {
                continue;
            }

            $record = $records[$entryId];

            // Excluded entries always count as zero in both directions —
            // the Suggestion engines refuse to operate on them, so we don't
            // even call out.
            if ($excludedFilter->isExcludedRecord($record)) {
                $outboundCount = 0;
                $inboundCount = 0;
            } else {
                // Outbound: same code path as OutboundController (via shared Grouper)
                $suggestions = $engine->suggest($record->text, $records, $entryId, $record->outboundLinks);
                $outboundCount = \Arturrossbach\Linkwise\Suggestions\OutboundSuggestionGrouper::countGroups($suggestions, $entryId);

                // Inbound: use suggestFiltered (single source of truth)
                $inboundCount = count($inboundEngine->suggestFiltered($entryId));
            }

            $counts[$entryId] = [
                'inbound' => $inboundCount,
                'outbound' => $outboundCount,
            ];

            // Update the index record so counts persist on reload
            if ($record->inboundSuggestionCount !== $inboundCount || $record->outboundSuggestionCount !== $outboundCount) {
                // REV-DR-03: was 13-line `new EntryRecord(...)` field-by-field copy.
                $records[$entryId] = $record->with([
                    'inboundSuggestionCount' => $inboundCount,
                    'outboundSuggestionCount' => $outboundCount,
                ]);
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
        $titles = [];
        foreach ($records as $id => $record) {
            // Combined title + body for the TF-IDF tokenizer — same as
            // before this refactor, keeps title-only words in the
            // extraction stream so they can still surface as keywords.
            $corpus[$id] = $record->title.' '.$record->text;
            // Title alone goes to extractAllWithTitles() as the
            // FrequencyFilter title-protect context. The same words
            // are stemmed and indexed in the protect set, which
            // shields mid-frequency domain words (Rezept, Notebook,
            // Suchmaschine) that the editor put in the title from the
            // 50k-stopword cull. Body-only domain words that happen
            // to be mid-frequency still get filtered — see Custom
            // Stopwords / Custom Target Keywords escape valves.
            $titles[$id] = $record->title;
        }

        $allKeywords = $this->keywordExtractor->extractAllWithTitles($corpus, $titles);

        // Preserve ALL existing EntryRecord fields — only the keyword
        // map gets replaced. Earlier this method dropped suggestion
        // counts + hasTitleMatch by omitting them from the constructor
        // call, leaving the next chain stage (preserveSuggestionCounts /
        // enrichWithSuggestionCounts) to refill them. Worked, but a
        // refactor that broke that chain would silently reset every
        // entry's counts to 0 with no log signal. Defensive: preserve
        // upstream, let downstream stages override only what they own.
        $enriched = [];
        foreach ($records as $id => $record) {
            // REV-DR-03: was 13-line `new EntryRecord(...)` — only keywords
            // change here, every other field flows through unchanged.
            $enriched[$id] = $record->with([
                'keywords' => $allKeywords[$id] ?? [],
            ]);
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
        // Parallel total-occurrence list (NOT deduped) for inbound-count parity
        // with the modal — see Bug 2026-05-12 (index=3 vs modal=4).
        $linkOccurrences = [];

        foreach ($extracted['bard'] as $bardContent) {
            $text .= TextExtractor::fromBard($bardContent)."\n";
            $links = array_merge($links, TextExtractor::linksFromBard($bardContent));
            $linkOccurrences = array_merge($linkOccurrences, TextExtractor::linksFromBardWithOccurrences($bardContent));
        }

        // Also extract from Markdown fields
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            $fields = [];
        }

        // Top-level read/write symmetry with BardLinkInserter:363-380:
        // the writer touches `markdown` only (text/textarea are plaintext
        // per Statamic's contract — writing `[anchor](url)` into them
        // would surface as visible literal syntax in any template that
        // doesn't manually pipe through `| markdown`). Indexing text/
        // textarea here would produce phantom anchor candidates that
        // always fail at apply-time with "anchor text not found in
        // writable region". Symmetric retreat keeps reads and writes
        // operating on the same field set.
        foreach ($fields as $handle => $field) {
            if ($field->type() === 'markdown' && $handle !== 'title') {
                $value = $entry->get($handle);

                if (is_string($value) && ! empty($value)) {
                    // Strip Markdown formatting for plain text.
                    $plain = preg_replace('/\[([^\[\]]+)\]\([^)]+\)/', '$1', $value); // [text](url) → text
                    $plain = preg_replace('/[#*_~`>]/', '', $plain); // Remove Markdown syntax
                    $text .= trim($plain)."\n";

                    // Extract Markdown links as outbound links.
                    $links = array_merge($links, TextExtractor::linksFromMarkdown($value));
                    $linkOccurrences = array_merge($linkOccurrences, TextExtractor::linksFromMarkdownWithOccurrences($value));
                }
            }
        }

        // Pre-tokenize so EntryIndexSubscriber can skip the per-save full-
        // corpus re-tokenization. Real perf win on medium/large sites:
        // an editor save on a 1000-entry site went from O(N) tokenize
        // calls to O(1) hash lookups via $existing->tokens.
        $cleanText = trim($text);
        $tokens = $this->keywordExtractor->tokenize($title.' '.$cleanText);

        return new EntryRecord(
            id: $entry->id(),
            title: $title,
            url: $entry->url(),
            collection: $entry->collectionHandle(),
            text: $cleanText,
            outboundLinks: array_values(array_unique($links)),
            tokens: $tokens,
            // Total occurrences (NOT deduped) for inbound-count parity with
            // the modal — see Bug 2026-05-12 (table=3 vs modal=4 drift).
            // $links is the deduped list (linksFromBard/Markdown already
            // unique each Bard subtree), $linkOccurrences is collected in
            // parallel from the *WithOccurrences helpers.
            outboundLinkOccurrences: array_values($linkOccurrences),
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

        // Atomic write via shared helper — kill -9 mid-write leaves
        // either the old index or the new one on disk, never a
        // truncated half-write that load() would silently parse as
        // empty. See AtomicJsonWriter for the staging-and-rename
        // pattern; same writer is used by DomainReport::saveAttributes.
        \Arturrossbach\Linkwise\Support\AtomicJsonWriter::write(
            $this->getIndexPath(),
            $data,
            'EntryIndexer::save',
        );

        // Update in-memory cache so subsequent reads see the saved state
        // even when the disk write returned false (the in-memory copy
        // remains useful for the rest of the request).
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

        // JsonFileStore handles missing-file (legitimate fresh state),
        // unreadable-file, and corrupt-JSON branches with consistent
        // warning logs. Missing/empty/corrupt → returns [] so we serve
        // an empty index rather than crash the CP — same outcome as
        // before but the "corrupt index.json" case now lands in logs
        // for the operator to investigate.
        $data = \Arturrossbach\Linkwise\Support\JsonFileStore::load(
            $this->getIndexPath(),
            [],
            'EntryIndexer::load',
        );

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
     * Extract searchable content from an entry — Bard JSON trees only,
     * including Bard fragments nested inside Replicator sets.
     *
     * Symmetric with the writer's retreat for plain-string set fields
     * (see {@see \Arturrossbach\Linkwise\Support\Replicator\ReplicatorLinkRouter::processReplicatorWithHref}
     * lines 160-167: writing `[anchor](url)` into a plaintext template
     * surfaces as visible literal syntax — the writer skips them, so the
     * indexer must too). Indexing card headings, button labels, accordion
     * plaintext bodies, etc. produced phantom anchor candidates that
     * always failed at apply-time. Bard fragments inside the same sets
     * are still walked below.
     *
     * @return array{bard: array}
     */
    protected function extractBardContent(Entry $entry): array
    {
        $bardContents = [];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] Could not read blueprint for entry '.$entry->id().': '.$e->getMessage());

            return ['bard' => $bardContents];
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->value($handle);

            if (! is_array($value) || empty($value)) {
                continue;
            }

            if ($field->type() === 'bard') {
                $bardContents[] = $value;
            } elseif ($field->type() === 'replicator') {
                $this->extractBardFromReplicator($value, $bardContents);
            }
        }

        return ['bard' => $bardContents];
    }

    /**
     * Recursively walk Replicator set items, collecting Bard fragments
     * only. Plain-string set fields (card headings, button labels,
     * accordion plaintext bodies, …) are intentionally skipped — they
     * mirror the writer's retreat (see class docblock).
     */
    protected function extractBardFromReplicator(array $sets, array &$bardContents): void
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

                if (! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $bardContents[] = $value;
                } elseif ($this->looksLikeReplicatorContent($value)) {
                    $this->extractBardFromReplicator($value, $bardContents);
                }
            }
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
