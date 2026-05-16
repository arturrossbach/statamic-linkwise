<?php

namespace Arturrossbach\Linkwise\Links;

/**
 * Per-entry cache of broken-link scan results.
 *
 * Sprint 6 REV-BL-05 — incremental scan support. Before this cache, every
 * `BrokenLinkChecker::checkAll()` run walked all entries + HTTP-checked all
 * external URLs even when 99% of entries had not changed since the last
 * scan. On Marketplace-Audience sites (>500 posts, per Domain-Entscheidung
 * REV-BL-01) that was minutes of wasted work.
 *
 * # Cache hit semantics
 *
 * An entry is a HIT when:
 *   1. its `content_hash` matches the cached value (entry unchanged), AND
 *   2. the cached scan is younger than the TTL (24h default).
 *
 * On hit, the caller skips the per-entry walk + external HTTP check and
 * re-uses the previously-found broken-link records as-is. The TTL bounds
 * the staleness: if an external URL silently broke (target site went down)
 * since the cache was written, the next run within 24h still shows the old
 * status; runs after the TTL re-check.
 *
 * # Correctness invariant
 *
 * The full broken-link report MUST be identical to a no-cache run within
 * the TTL window for unchanged entries. The cache stores the COMPLETE
 * `BrokenLinkRecord` array per entry (internal + external), so the result
 * shape doesn't drift across cache-hit vs cache-miss rows.
 *
 * # Storage
 *
 * Single JSON file at `storage/linkwise/broken-link-scan-cache.json`,
 * keyed by entry-id. Per-entry shape:
 *   {
 *     "content_hash": "abc123...",
 *     "last_scanned_at": 1747403456,   // Unix timestamp
 *     "broken_links": [<BrokenLinkRecord::toArray()>, ...]
 *   }
 *
 * Single-file simplicity is intentional: the cache is read once at the
 * start of a scan and written once at the end. No concurrent mutation
 * (JobLock pinned to scan-kind).
 */
class BrokenLinkScanCache
{
    public const DEFAULT_TTL_SECONDS = 86400; // 24 hours

    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise/broken-link-scan-cache.json');
    }

    /**
     * Read the cache for a single entry.
     *
     * Returns the cached BrokenLinkRecord[] when the entry is a HIT:
     *   - cache contains the entry, AND
     *   - cached `content_hash` matches `$currentHash` (entry unchanged), AND
     *   - cached scan is younger than $ttlSec (TTL).
     *
     * Returns `null` on any miss reason. Null vs empty-array distinguishes
     * "no cache entry" from "entry was checked but had no broken links" —
     * the caller wants to skip in both cases, but the latter is a HIT.
     *
     * @param  string  $entryId
     * @param  string  $currentHash  SafeEntrySaver::hash($entry) of the
     *                               entry being scanned right now.
     * @param  int  $nowUnix  Current Unix timestamp (parameterised so
     *                        tests don't have to mock time()).
     * @param  int  $ttlSec  Max age before a fresh re-scan is forced.
     * @return BrokenLinkRecord[]|null  null on miss, array on hit.
     */
    public function getCached(string $entryId, string $currentHash, int $nowUnix, int $ttlSec = self::DEFAULT_TTL_SECONDS): ?array
    {
        $all = $this->readAll();
        $row = $all[$entryId] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $cachedHash = $row['content_hash'] ?? '';
        if (! is_string($cachedHash) || $cachedHash !== $currentHash) {
            // Entry was edited since last scan — content_hash mismatch.
            // Must re-walk to surface any newly-introduced broken links.
            return null;
        }

        $lastScanned = $row['last_scanned_at'] ?? 0;
        if (! is_int($lastScanned)) {
            return null;
        }
        if (($nowUnix - $lastScanned) > $ttlSec) {
            // Older than TTL — external URLs might have silently changed
            // status. Force a fresh HTTP check.
            return null;
        }

        $raw = $row['broken_links'] ?? [];
        if (! is_array($raw)) {
            return null;
        }

        // Deserialise — one corrupt record cannot break the whole hit,
        // mirroring BrokenLinkReport's loader pattern.
        $records = [];
        foreach ($raw as $data) {
            if (! is_array($data)) {
                continue;
            }
            try {
                $records[] = BrokenLinkRecord::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                // Skip corrupt rows — caller still gets a partial hit, the
                // missing record(s) will be re-detected on next walk-miss
                // because content_hash won't have changed in the meantime.
            }
        }

        return $records;
    }

    /**
     * Persist the scan result for a single entry.
     *
     * Called by BrokenLinkChecker after it walks an entry and assembles
     * its broken-link records. Overwrites any prior cache row for the
     * same entry-id — content_hash + last_scanned_at advance together.
     *
     * @param  string  $entryId
     * @param  string  $contentHash  hash at the moment we walked the entry
     * @param  BrokenLinkRecord[]  $brokenLinks  records found in this entry
     * @param  int  $nowUnix
     */
    public function store(string $entryId, string $contentHash, array $brokenLinks, int $nowUnix): void
    {
        $all = $this->readAll();
        $all[$entryId] = [
            'content_hash' => $contentHash,
            'last_scanned_at' => $nowUnix,
            'broken_links' => array_map(fn (BrokenLinkRecord $r) => $r->toArray(), $brokenLinks),
        ];
        $this->writeAll($all);
    }

    /**
     * Wipe the entire cache. Called after the user runs a manual rescan
     * from the UI (force-fresh path) — and exposed for tests.
     */
    public function clear(): void
    {
        if (is_file($this->storagePath)) {
            @unlink($this->storagePath);
        }
    }

    /**
     * Diagnostic: list cached entry ids alongside their last-scanned-at
     * timestamps. Useful for debug-export bundles and the audit suite.
     *
     * @return array<string, int>  entryId => last_scanned_at unix ts
     */
    public function summary(): array
    {
        $all = $this->readAll();
        $out = [];
        foreach ($all as $entryId => $row) {
            if (! is_array($row)) {
                continue;
            }
            $ts = $row['last_scanned_at'] ?? 0;
            $out[(string) $entryId] = is_int($ts) ? $ts : 0;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    protected function readAll(): array
    {
        if (! is_file($this->storagePath)) {
            return [];
        }
        $raw = @file_get_contents($this->storagePath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, array<string, mixed>>  $all */
    protected function writeAll(array $all): void
    {
        $dir = dirname($this->storagePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $this->storagePath,
            json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }
}
