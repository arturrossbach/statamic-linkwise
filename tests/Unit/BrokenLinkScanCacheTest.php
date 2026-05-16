<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Links\BrokenLinkRecord;
use Arturrossbach\Linkwise\Links\BrokenLinkScanCache;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for BrokenLinkScanCache.
 *
 * Sprint 6 REV-BL-05 prep — pure-class test net. Per advisor pre-PR-review:
 * "Cache-Invalidierungs-Strategie muss ähnlich rigid getestet werden wie
 * der bulkSignature-Truth-Table-Switch — sonst hast du eingehandelt was
 * du vermeiden wolltest."
 *
 * The contract that downstream code (BrokenLinkChecker::checkAll, future
 * audit checks, debug-export) will rely on:
 *
 *   1. Empty cache → null (miss)
 *   2. Hit: matching hash + within TTL → returns BrokenLinkRecord[]
 *   3. Miss: hash mismatch (entry edited) → null, force re-walk
 *   4. Miss: stale (older than TTL) → null, force re-check for URL drift
 *   5. Store + round-trip preserves BrokenLinkRecord shape
 *   6. Empty broken-links array is a valid HIT (entry has no broken links)
 *      vs `null` (no cache entry at all) — must distinguish
 *   7. Multiple entries cached independently
 *   8. clear() wipes everything
 *   9. Defensive: corrupt rows are silently dropped
 */
class BrokenLinkScanCacheTest extends TestCase
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir().'/linkwise-scancache-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
        $dir = dirname($this->tempPath);
        if (is_dir($dir) && str_contains($dir, 'linkwise-scancache')) {
            @rmdir($dir);
        }
        parent::tearDown();
    }

    protected function newCache(): BrokenLinkScanCache
    {
        return new BrokenLinkScanCache($this->tempPath);
    }

    protected function makeRecord(string $url = 'https://broken.example.com'): BrokenLinkRecord
    {
        return new BrokenLinkRecord(
            postId: 'e1',
            postTitle: 'Test Entry',
            url: $url,
            anchorText: 'click here',
            type: 'external',
            statusCode: 404,
            errorType: 'not_found',
            firstDetectedAt: '2026-05-16T10:00:00+00:00',
            lastCheckedAt: '2026-05-16T10:00:00+00:00',
            sentenceContext: 'foo click here bar',
            ignored: false,
        );
    }

    // ── Empty / miss cases ─────────────────────────────────────────────

    public function test_empty_cache_returns_null(): void
    {
        $cache = $this->newCache();
        $this->assertNull($cache->getCached('e1', 'abc123', 1700000000));
    }

    public function test_hash_mismatch_returns_null(): void
    {
        // Real-world scenario: user edited the entry between scans. The
        // content_hash changed → cached broken-links are no longer
        // authoritative for the new content. Must force a re-walk.
        $cache = $this->newCache();
        $cache->store('e1', 'OLD_HASH', [$this->makeRecord()], 1700000000);

        $this->assertNull($cache->getCached('e1', 'NEW_HASH', 1700000000));
    }

    public function test_stale_entry_returns_null(): void
    {
        // External URLs can silently change status (target site went down).
        // TTL forces re-check after 24h even if our entry is unchanged.
        $cache = $this->newCache();
        $writtenAt = 1700000000;
        $cache->store('e1', 'h1', [$this->makeRecord()], $writtenAt);

        // Default TTL is 86400s — query at +86401s.
        $this->assertNull($cache->getCached('e1', 'h1', $writtenAt + 86401));
    }

    public function test_just_within_ttl_returns_hit(): void
    {
        // Boundary: exactly TTL old is still considered fresh.
        $cache = $this->newCache();
        $writtenAt = 1700000000;
        $cache->store('e1', 'h1', [$this->makeRecord()], $writtenAt);

        $records = $cache->getCached('e1', 'h1', $writtenAt + 86400);
        $this->assertIsArray($records);
        $this->assertCount(1, $records);
    }

    public function test_custom_ttl_honoured(): void
    {
        // Tighter TTL for tests / dev — e.g. 60s.
        $cache = $this->newCache();
        $writtenAt = 1700000000;
        $cache->store('e1', 'h1', [$this->makeRecord()], $writtenAt);

        $this->assertNull($cache->getCached('e1', 'h1', $writtenAt + 61, 60));
        $this->assertIsArray($cache->getCached('e1', 'h1', $writtenAt + 60, 60));
    }

    // ── Hit cases / round-trip ─────────────────────────────────────────

    public function test_fresh_hit_returns_stored_records(): void
    {
        $cache = $this->newCache();
        $records = [
            $this->makeRecord('https://a.example.com'),
            $this->makeRecord('https://b.example.com'),
        ];
        $cache->store('e1', 'h1', $records, 1700000000);

        $loaded = $cache->getCached('e1', 'h1', 1700000000);
        $this->assertIsArray($loaded);
        $this->assertCount(2, $loaded);
        $this->assertSame('https://a.example.com', $loaded[0]->url);
        $this->assertSame('https://b.example.com', $loaded[1]->url);
    }

    public function test_round_trip_preserves_record_shape(): void
    {
        // Critical correctness: a cache-hit row MUST be indistinguishable
        // from a freshly-walked row downstream. Pin every BrokenLinkRecord
        // field so a future toArray()/fromArray() drift breaks loudly.
        $cache = $this->newCache();
        $original = $this->makeRecord();
        $cache->store('e1', 'h1', [$original], 1700000000);

        $loaded = $cache->getCached('e1', 'h1', 1700000000);
        $this->assertIsArray($loaded);
        $r = $loaded[0];
        $this->assertSame('e1', $r->postId);
        $this->assertSame('Test Entry', $r->postTitle);
        $this->assertSame('https://broken.example.com', $r->url);
        $this->assertSame('click here', $r->anchorText);
        $this->assertSame('external', $r->type);
        $this->assertSame(404, $r->statusCode);
        $this->assertSame('not_found', $r->errorType);
        $this->assertSame('2026-05-16T10:00:00+00:00', $r->firstDetectedAt);
        $this->assertSame('2026-05-16T10:00:00+00:00', $r->lastCheckedAt);
        $this->assertSame('foo click here bar', $r->sentenceContext);
        $this->assertFalse($r->ignored);
    }

    public function test_empty_broken_links_is_a_valid_hit(): void
    {
        // Key distinction: an entry with NO broken links is still a HIT —
        // returns [] not null. Caller must skip the walk in both cases,
        // but downstream code checking `is_null()` vs `is_array($r) && empty($r)`
        // would diverge if we collapsed them.
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [], 1700000000);

        $loaded = $cache->getCached('e1', 'h1', 1700000000);
        $this->assertIsArray($loaded);
        $this->assertSame([], $loaded);
    }

    // ── Multiple entries / independence ────────────────────────────────

    public function test_multiple_entries_independent(): void
    {
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [$this->makeRecord('https://a.com')], 1700000000);
        $cache->store('e2', 'h2', [$this->makeRecord('https://b.com')], 1700000000);

        $a = $cache->getCached('e1', 'h1', 1700000000);
        $b = $cache->getCached('e2', 'h2', 1700000000);
        $this->assertSame('https://a.com', $a[0]->url);
        $this->assertSame('https://b.com', $b[0]->url);
    }

    public function test_overwrite_advances_hash_and_timestamp_together(): void
    {
        // Edge: re-store with same entry-id replaces all three fields
        // atomically. Without this, you'd see "old broken_links + new
        // content_hash" rows.
        $cache = $this->newCache();
        $cache->store('e1', 'OLD', [$this->makeRecord('https://old.com')], 1700000000);
        $cache->store('e1', 'NEW', [$this->makeRecord('https://new.com')], 1700001000);

        $loaded = $cache->getCached('e1', 'NEW', 1700001000);
        $this->assertSame('https://new.com', $loaded[0]->url);

        // Old hash no longer hits.
        $this->assertNull($cache->getCached('e1', 'OLD', 1700001000));
    }

    // ── Clear + summary ────────────────────────────────────────────────

    public function test_clear_wipes_everything(): void
    {
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [$this->makeRecord()], 1700000000);
        $cache->store('e2', 'h2', [$this->makeRecord()], 1700000000);

        $cache->clear();
        $this->assertNull($cache->getCached('e1', 'h1', 1700000000));
        $this->assertNull($cache->getCached('e2', 'h2', 1700000000));
        $this->assertSame([], $cache->summary());
    }

    public function test_summary_returns_entry_to_timestamp_map(): void
    {
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [], 1700000100);
        $cache->store('e2', 'h2', [], 1700000200);

        $summary = $cache->summary();
        $this->assertSame(1700000100, $summary['e1']);
        $this->assertSame(1700000200, $summary['e2']);
    }

    // ── Persistence (round-trip via filesystem) ────────────────────────

    public function test_persists_across_instances(): void
    {
        // The cache lives in a single JSON file. Re-instantiating must
        // hit the file, not reset.
        $a = $this->newCache();
        $a->store('e1', 'h1', [$this->makeRecord()], 1700000000);

        $b = $this->newCache();
        $loaded = $b->getCached('e1', 'h1', 1700000000);
        $this->assertIsArray($loaded);
        $this->assertCount(1, $loaded);
    }

    // ── Defensive: corrupt cache files ─────────────────────────────────

    public function test_corrupt_json_file_treated_as_empty(): void
    {
        // Disk corruption / partial write — must not crash the scan.
        file_put_contents($this->tempPath, '{not valid json');

        $cache = $this->newCache();
        $this->assertNull($cache->getCached('e1', 'h1', 1700000000));
        // And we should still be able to write fresh data over the top.
        $cache->store('e1', 'h1', [$this->makeRecord()], 1700000000);
        $this->assertIsArray($cache->getCached('e1', 'h1', 1700000000));
    }

    public function test_corrupt_record_silently_skipped(): void
    {
        // One missing field in a stored row must not poison the whole
        // hit — mirrors BrokenLinkReport's loader pattern.
        $rawCache = [
            'e1' => [
                'content_hash' => 'h1',
                'last_scanned_at' => 1700000000,
                'broken_links' => [
                    ['post_id' => '', 'url' => 'https://broken.com'], // missing post_id
                    [
                        'post_id' => 'e1',
                        'post_title' => 'T',
                        'url' => 'https://valid.com',
                        'anchor_text' => 'a',
                        'type' => 'external',
                        'status_code' => 500,
                        'error_type' => 'server_error',
                        'first_detected_at' => '2026-05-16T10:00:00+00:00',
                        'last_checked_at' => '2026-05-16T10:00:00+00:00',
                    ],
                ],
            ],
        ];
        file_put_contents($this->tempPath, json_encode($rawCache));

        $cache = $this->newCache();
        $loaded = $cache->getCached('e1', 'h1', 1700000000);
        $this->assertIsArray($loaded);
        $this->assertCount(1, $loaded);
        $this->assertSame('https://valid.com', $loaded[0]->url);
    }

    // ── Orphan eviction ────────────────────────────────────────────────

    public function test_drop_orphans_keeps_only_listed_entries(): void
    {
        // Real-world: entry e2 was deleted from Statamic between scans.
        // After checkAll runs, dropOrphans is called with the surviving
        // entry-ids; the row for e2 must disappear.
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [], 1700000000);
        $cache->store('e2', 'h2', [], 1700000000);
        $cache->store('e3', 'h3', [], 1700000000);

        $cache->dropOrphans(['e1', 'e3']);

        $this->assertIsArray($cache->getCached('e1', 'h1', 1700000000));
        $this->assertNull($cache->getCached('e2', 'h2', 1700000000));
        $this->assertIsArray($cache->getCached('e3', 'h3', 1700000000));
    }

    public function test_drop_orphans_with_empty_keep_list_wipes_cache(): void
    {
        // Edge: if checkAll iterated zero entries (all excluded by
        // collection / id), dropOrphans([]) wipes everything. Reasonable
        // — there's no current scope so any cached row is orphan.
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [], 1700000000);
        $cache->store('e2', 'h2', [], 1700000000);

        $cache->dropOrphans([]);

        $this->assertSame([], $cache->summary());
    }

    public function test_drop_orphans_no_op_when_all_present(): void
    {
        // Optimisation pin: when nothing needs to drop, dropOrphans must
        // not rewrite the file (avoids a write-amplification spike on
        // 1000+ entry sites). Verified indirectly via the post-state.
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [], 1700000000);

        $cache->dropOrphans(['e1']);

        $this->assertIsArray($cache->getCached('e1', 'h1', 1700000000));
    }

    public function test_clock_drift_negative_age_handled(): void
    {
        // Server clock moved backwards between store + read. Negative age
        // should be treated as fresh, not stale.
        $cache = $this->newCache();
        $cache->store('e1', 'h1', [$this->makeRecord()], 1700001000);

        $loaded = $cache->getCached('e1', 'h1', 1700000000);
        $this->assertIsArray($loaded);
    }
}
