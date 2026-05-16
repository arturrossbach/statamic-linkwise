<?php

namespace Arturrossbach\Linkwise\Links;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Statamic\Facades\Entry;

class BrokenLinkChecker
{
    protected int $timeout;

    protected int $retries;

    protected BrokenLinkReport $report;

    protected BrokenLinkScanCache $scanCache;

    /** @var array<string, string>  Map of "postId|url" → first_detected_at ISO timestamp */
    protected array $previousDetections = [];

    /** @var array<string, true>  Set of "postId|url" keys that were ignored in the last scan (O(1) lookup) */
    protected array $previouslyIgnored = [];

    public function __construct(
        protected EntryIndexer $indexer,
        ?BrokenLinkReport $report = null,
        ?int $timeout = null,
        ?int $retries = null,
        ?BrokenLinkScanCache $scanCache = null,
    ) {
        $this->report = $report ?? new BrokenLinkReport;
        $this->timeout = $timeout ?? config('linkwise.broken_links.timeout', 10);
        $this->retries = $retries ?? config('linkwise.broken_links.retries', 2);
        // Per-entry cache lets unchanged entries skip the walk + HTTP work
        // on subsequent runs. Sprint 6 REV-BL-05. TTL is 24h so external
        // URL drift (target site went down) still surfaces eventually.
        $this->scanCache = $scanCache ?? new BrokenLinkScanCache;
    }

    /**
     * Load previous broken link report so we can preserve first_detected_at for URLs
     * that were already broken in the last scan, and carry the ignored flag forward.
     */
    protected function loadPreviousDetections(): void
    {
        $report = $this->report->load();
        foreach ($report['broken_links'] as $record) {
            $key = $record->postId.'|'.$record->url;
            $this->previousDetections[$key] = $record->firstDetectedAt ?: now()->toIso8601String();
            if ($record->ignored) {
                $this->previouslyIgnored[$key] = true;
            }
        }
    }

    protected function wasIgnored(string $postId, string $url): bool
    {
        return isset($this->previouslyIgnored[$postId.'|'.$url]);
    }

    protected function firstDetectedOrNow(string $postId, string $url): string
    {
        return $this->previousDetections[$postId.'|'.$url] ?? now()->toIso8601String();
    }

    /**
     * Count total external URLs across all entries for progress reporting.
     * Counts unique URLs per entry (dedupes only within the same entry, matching check loop behavior).
     */
    protected function countTotalExternalUrls(array $records): int
    {
        $total = 0;
        foreach ($records as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) {
                continue;
            }
            $links = $this->extractExternalLinksFromEntry($entry);
            $total += count($links);
        }

        return $total;
    }

    /**
     * Check all links across all indexed entries.
     *
     * @param  callable|null  $onProgress  fn(int $current, int $total, string $url)
     * @return BrokenLinkRecord[]
     */
    public function checkAll(?callable $onProgress = null): array
    {
        $this->loadPreviousDetections();
        $records = $this->indexer->load();
        $brokenLinks = [];
        $checkedUrls = [];
        $nowUnix = time();

        // Count total URLs upfront so the UI can show progress
        $totalUrls = $this->countTotalExternalUrls($records);
        $progress = 0;

        $excludedEntries = config('linkwise.excluded_entries', []);
        $excludedEntries = is_array($excludedEntries) ? $excludedEntries : [];
        $excludedCollections = config('linkwise.excluded_collections', []);
        $excludedCollections = is_array($excludedCollections) ? $excludedCollections : [];
        $ignoredLinks = config('linkwise.ignored_links', '');
        $ignoredPatterns = is_string($ignoredLinks) ? array_filter(array_map('trim', explode("\n", $ignoredLinks))) : [];

        // Track which entry-ids we touched so the cache can evict rows for
        // entries that no longer exist in the index (otherwise the cache
        // grows unboundedly on sites with high churn). Sprint 6 REV-BL-05.
        $seenEntryIds = [];

        foreach ($records as $record) {
            if (in_array($record->id, $excludedEntries, true)) {
                continue;
            }
            if (! empty($excludedCollections) && in_array($record->collection, $excludedCollections, true)) {
                continue;
            }

            $seenEntryIds[$record->id] = true;

            $entry = Entry::find($record->id);

            // Incremental-scan cache: skip the walk + HTTP work entirely
            // when the entry is unchanged AND the cached scan is within TTL.
            // Cache-hit records are appended as-is, preserving the result
            // shape; cache-miss falls through to the full walk below and
            // stores the result for next time.
            //
            // Hash is computed from the live Entry — without it any pre-
            // existing cache row is meaningless. If the entry is gone
            // (Entry::find returned null), there's nothing to cache against
            // either; fall through and let the existing flow surface the
            // missing-target as an internal broken link.
            $currentHash = $entry ? SafeEntrySaver::hash($entry) : '';
            if ($entry && $currentHash !== '') {
                $cached = $this->scanCache->getCached($record->id, $currentHash, $nowUnix);
                if ($cached !== null) {
                    foreach ($cached as $cachedRecord) {
                        // Re-apply the ignored flag from the current report
                        // state — the user might have toggled ignored on a
                        // URL since the cache row was written. The ignored
                        // flag is metadata, not part of the dedup key.
                        $brokenLinks[] = $this->wasIgnored($cachedRecord->postId, $cachedRecord->url)
                            ? $cachedRecord->withIgnored(true)
                            : $cachedRecord->withIgnored(false);
                    }
                    // Advance the progress counter so the UI doesn't look
                    // frozen during a high-cache-hit run. Use the count of
                    // external links in the cached set as a proxy.
                    foreach ($cached as $cachedRecord) {
                        if ($cachedRecord->type === 'external') {
                            $progress++;
                            if ($onProgress) {
                                $onProgress($progress, $totalUrls, $cachedRecord->url);
                            }
                        }
                    }

                    continue;
                }
            }

            // Cache miss path — collect this entry's records so we can
            // store them at the end of the per-entry block.
            $entryBroken = [];

            // Check internal links (do target entries exist?). User-ignored internal
            // links still surface — we just carry the `ignored` flag so the UI can
            // hide or reveal them via the Status filter.
            $internalBroken = $this->checkInternalLinks($record, $records, $entry);
            foreach ($internalBroken as $r) {
                $final = $this->wasIgnored($r->postId, $r->url) ? $r->withIgnored(true) : $r;
                $brokenLinks[] = $final;
                $entryBroken[] = $final;
            }

            // Check external links (HTTP status)
            $externalLinks = $entry ? $this->extractExternalLinksFromEntry($entry) : [];

            foreach ($externalLinks as $link) {
                $url = $link['url'];

                // Skip ignored URL patterns (config-level, site-wide)
                if ($this->isIgnoredUrl($url, $ignoredPatterns)) {
                    continue;
                }

                // Skip already-checked URLs
                if (isset($checkedUrls[$url])) {
                    if ($checkedUrls[$url] !== null) {
                        $broken = new BrokenLinkRecord(
                            postId: $record->id,
                            postTitle: $record->title,
                            url: $url,
                            anchorText: $link['anchor_text'],
                            type: 'external',
                            statusCode: $checkedUrls[$url]->statusCode,
                            errorType: $checkedUrls[$url]->errorType,
                            firstDetectedAt: $this->firstDetectedOrNow($record->id, $url),
                            lastCheckedAt: now()->toIso8601String(),
                            sentenceContext: ContextExtractor::extract($record->text, $link['anchor_text']),
                            ignored: $this->wasIgnored($record->id, $url),
                        );
                        $brokenLinks[] = $broken;
                        $entryBroken[] = $broken;
                    }

                    continue;
                }

                $progress++;
                if ($onProgress) {
                    $onProgress($progress, $totalUrls, $url);
                }

                $result = $this->checkUrl($url);

                if ($result !== null) {
                    $broken = new BrokenLinkRecord(
                        postId: $record->id,
                        postTitle: $record->title,
                        url: $url,
                        anchorText: $link['anchor_text'],
                        type: 'external',
                        statusCode: $result['status_code'],
                        errorType: $result['error_type'],
                        firstDetectedAt: $this->firstDetectedOrNow($record->id, $url),
                        lastCheckedAt: now()->toIso8601String(),
                        sentenceContext: ContextExtractor::extract($record->text, $link['anchor_text']),
                        ignored: $this->wasIgnored($record->id, $url),
                    );
                    $brokenLinks[] = $broken;
                    $entryBroken[] = $broken;
                    $checkedUrls[$url] = $broken;
                } else {
                    $checkedUrls[$url] = null; // OK
                }
            }

            // Persist this entry's complete broken-link set into the
            // incremental-scan cache. Future runs within the TTL window
            // and with unchanged content_hash will skip the per-entry
            // walk + HTTP check and re-use this snapshot. Sprint 6
            // REV-BL-05. Skip when we couldn't compute a content_hash
            // (entry was deleted between index-load and walk) — caching
            // against an empty hash would never hit anyway.
            if ($currentHash !== '') {
                $this->scanCache->store($record->id, $currentHash, $entryBroken, $nowUnix);
            }
        }

        // Cache eviction: drop rows for entry-ids no longer in the index
        // (entry deleted since the last scan). Without this the cache file
        // grows unboundedly on sites with high churn.
        $this->scanCache->dropOrphans(array_keys($seenEntryIds));

        return $brokenLinks;
    }

    /**
     * Check if internal link targets exist in the index.
     *
     * @param  EntryRecord[]  $allRecords
     * @param  \Statamic\Entries\Entry|null  $entry  Pre-loaded Statamic entry
     * @return BrokenLinkRecord[]
     */
    protected function checkInternalLinks(EntryRecord $record, array $allRecords, $entry = null): array
    {
        $broken = [];
        $missingIds = [];

        foreach ($record->outboundLinks as $targetId) {
            if (! isset($allRecords[$targetId])) {
                $missingIds[] = $targetId;
            }
        }

        if (empty($missingIds)) {
            return [];
        }

        // Get anchor texts for the missing internal links
        $anchorMap = $entry ? $this->getInternalLinkAnchorsFromEntry($entry) : [];

        foreach ($missingIds as $targetId) {
            $anchor = $anchorMap[$targetId] ?? '';
            $targetUrl = 'statamic://entry::'.$targetId;
            $broken[] = new BrokenLinkRecord(
                postId: $record->id,
                postTitle: $record->title,
                url: $targetUrl,
                anchorText: $anchor,
                type: 'internal',
                statusCode: null,
                errorType: 'missing_entry',
                firstDetectedAt: $this->firstDetectedOrNow($record->id, $targetUrl),
                lastCheckedAt: now()->toIso8601String(),
                sentenceContext: ContextExtractor::extract($record->text, $anchor),
            );
        }

        return $broken;
    }

    /**
     * Get anchor texts for internal links from a pre-loaded entry.
     *
     * @return array<string, string>  Map of entry ID → anchor text
     */
    protected function getInternalLinkAnchorsFromEntry($entry): array
    {
        $anchors = [];

        EntryFieldWalker::walk($entry, function (array $bard) use (&$anchors) {
            foreach (TextExtractor::internalLinksWithAnchorFromBard($bard) as $link) {
                $anchors[$link['entry_id']] = $link['anchor_text'];
            }
        });

        return $anchors;
    }

    /**
     * Extract external links from a pre-loaded entry's Bard/Markdown content.
     *
     * @return array<array{url: string, anchor_text: string}>
     */
    protected function extractExternalLinksFromEntry($entry): array
    {
        $links = [];

        EntryFieldWalker::walk(
            $entry,
            function (array $bard) use (&$links) {
                $links = array_merge($links, TextExtractor::externalLinksFromBard($bard));
            },
            function (string $markdown) use (&$links) {
                $links = array_merge($links, TextExtractor::externalLinksFromMarkdown($markdown));
            },
        );

        return $links;
    }

    /**
     * Check a single URL via HTTP.
     * Public so callers like UrlChangerController can verify a replacement URL
     * before committing it to the broken-links report.
     *
     * @return array{status_code: ?int, error_type: string}|null  Null if URL is OK
     */
    public function checkUrl(string $url): ?array
    {
        try {
            $client = Http::timeout($this->timeout)
                ->retry($this->retries, 100, throw: false)
                ->withUserAgent('Mozilla/5.0 (compatible; Linkwise/1.0; +https://statamic.com)')
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                ]);

            // Try HEAD first (faster)
            $response = $client->head($url);
            $status = $response->status();

            // Some servers block HEAD, try GET
            if ($status === 405 || $status === 403) {
                $response = $client->get($url);
                $status = $response->status();
            }

            return $this->classifyStatus($status);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
                return ['status_code' => null, 'error_type' => 'ssl_error'];
            }

            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return ['status_code' => null, 'error_type' => 'timeout'];
            }

            return ['status_code' => null, 'error_type' => 'connection_failed'];
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] Link check failed for '.$url.': '.$e->getMessage());

            return ['status_code' => null, 'error_type' => 'connection_failed'];
        }
    }

    /**
     * Classify an HTTP status code as broken or OK.
     *
     * @return array{status_code: int, error_type: string}|null  Null if OK
     */
    protected function isIgnoredUrl(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // Convert wildcard pattern to regex
            $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';
            if (preg_match($regex, $url)) {
                return true;
            }
        }

        return false;
    }

    protected function classifyStatus(int $status): ?array
    {
        // 2xx = OK
        if ($status >= 200 && $status < 300) {
            return null;
        }

        // 3xx = Redirect — NOT broken. HTTP client follows redirects by default,
        // so a 3xx here means the redirect chain exceeded max (5) or ended in error.
        // Treat as OK — if the chain had real problems, we'd get a 4xx/5xx from the target.
        if ($status >= 300 && $status < 400) {
            return null;
        }

        // 404 = Not found
        if ($status === 404) {
            return ['status_code' => 404, 'error_type' => 'not_found'];
        }

        // 401, 403 = Forbidden/Unauthorized
        if ($status === 401 || $status === 403) {
            return ['status_code' => $status, 'error_type' => 'forbidden'];
        }

        // 4xx = Client error
        if ($status >= 400 && $status < 500) {
            return ['status_code' => $status, 'error_type' => 'not_found'];
        }

        // 5xx = Server error
        if ($status >= 500) {
            return ['status_code' => $status, 'error_type' => 'server_error'];
        }

        return null;
    }

}
