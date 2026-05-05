<?php

namespace Inkline\Linkwise\Links;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Indexer\EntryRecord;
use Inkline\Linkwise\Support\ContextExtractor;
use Inkline\Linkwise\Support\EntryFieldWalker;
use Inkline\Linkwise\Support\TextExtractor;
use Statamic\Facades\Entry;

class BrokenLinkChecker
{
    protected int $timeout;

    protected int $retries;

    protected BrokenLinkReport $report;

    /** @var array<string, string>  Map of "postId|url" → first_detected_at ISO timestamp */
    protected array $previousDetections = [];

    /** @var array<string, true>  Set of "postId|url" keys that were ignored in the last scan (O(1) lookup) */
    protected array $previouslyIgnored = [];

    public function __construct(
        protected EntryIndexer $indexer,
        ?BrokenLinkReport $report = null,
        ?int $timeout = null,
        ?int $retries = null,
    ) {
        $this->report = $report ?? new BrokenLinkReport;
        $this->timeout = $timeout ?? config('linkwise.broken_links.timeout', 10);
        $this->retries = $retries ?? config('linkwise.broken_links.retries', 2);
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

        // Count total URLs upfront so the UI can show progress
        $totalUrls = $this->countTotalExternalUrls($records);
        $progress = 0;

        $excludedEntries = config('linkwise.excluded_entries', []);
        $excludedEntries = is_array($excludedEntries) ? $excludedEntries : [];
        $excludedCollections = config('linkwise.excluded_collections', []);
        $excludedCollections = is_array($excludedCollections) ? $excludedCollections : [];
        $ignoredLinks = config('linkwise.ignored_links', '');
        $ignoredPatterns = is_string($ignoredLinks) ? array_filter(array_map('trim', explode("\n", $ignoredLinks))) : [];

        foreach ($records as $record) {
            if (in_array($record->id, $excludedEntries, true)) {
                continue;
            }
            if (! empty($excludedCollections) && in_array($record->collection, $excludedCollections, true)) {
                continue;
            }

            $entry = Entry::find($record->id);

            // Check internal links (do target entries exist?). User-ignored internal
            // links still surface — we just carry the `ignored` flag so the UI can
            // hide or reveal them via the Status filter.
            $internalBroken = $this->checkInternalLinks($record, $records, $entry);
            foreach ($internalBroken as $r) {
                $brokenLinks[] = $this->wasIgnored($r->postId, $r->url) ? $r->withIgnored(true) : $r;
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
                        $brokenLinks[] = new BrokenLinkRecord(
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
                    $checkedUrls[$url] = $broken;
                } else {
                    $checkedUrls[$url] = null; // OK
                }
            }
        }

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
