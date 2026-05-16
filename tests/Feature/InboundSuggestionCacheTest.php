<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Suggestions\InboundSuggestion;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Characterisation tests for InboundSuggestionCache.
 *
 * Sprint 6 REV-IB-01 prep — neue Caching-Schicht braucht rigide
 * Pin-Tests vor Wire-In (advisor 2026-05-16):
 *   "neue Caching-Logik = neue Bug-Quelle (Stale-Cache-Drift,
 *    Invalidierung-Pfade). Plan dafür IB-01-prep mit Pin-Tests für
 *    Cache-Hit/Miss/Invalidierung bevor Code-Touch."
 *
 * Feature-test (not Unit) because the Laravel Cache facade is bound by
 * the package's TestCase bootstrap.
 */
class InboundSuggestionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function newCache(): InboundSuggestionCache
    {
        return new InboundSuggestionCache;
    }

    protected function makeSuggestion(string $sourceId = 's1', string $targetId = 't1', string $anchor = 'click'): InboundSuggestion
    {
        return new InboundSuggestion(
            sourceEntryId: $sourceId,
            sourceTitle: 'Source Title',
            sourceUrl: '/source-url',
            sourceCollection: 'pages',
            targetEntryId: $targetId,
            anchorText: $anchor,
            sentenceContext: 'foo click bar',
            score: 0.75,
            contextTruncatedStart: false,
            contextTruncatedEnd: true,
            matchType: 'phrase',
            matchReason: 'semantic',
        );
    }

    // ── Miss cases ─────────────────────────────────────────────────────

    public function test_empty_cache_returns_null(): void
    {
        $this->assertNull($this->newCache()->getCached('t1'));
    }

    public function test_different_target_misses(): void
    {
        $cache = $this->newCache();
        $cache->store('t1', [$this->makeSuggestion()]);
        $this->assertNull($cache->getCached('t999'));
    }

    public function test_corrupt_cache_value_returns_null(): void
    {
        // Defensive: if some external process wrote garbage into the cache
        // key, we treat as miss instead of crashing the modal.
        Cache::put('linkwise:inbound:suggestFiltered:t1', 'not-an-array', 60);
        $this->assertNull($this->newCache()->getCached('t1'));
    }

    // ── Hit + round-trip ───────────────────────────────────────────────

    public function test_store_then_get_returns_records(): void
    {
        $cache = $this->newCache();
        $cache->store('t1', [
            $this->makeSuggestion('s1', 't1', 'apple'),
            $this->makeSuggestion('s2', 't1', 'banana'),
        ]);

        $loaded = $cache->getCached('t1');
        $this->assertIsArray($loaded);
        $this->assertCount(2, $loaded);
        $this->assertSame('apple', $loaded[0]->anchorText);
        $this->assertSame('banana', $loaded[1]->anchorText);
    }

    public function test_round_trip_preserves_every_field(): void
    {
        // Critical contract: a cached suggestion must render identically
        // to a freshly-computed one in the modal. Pin every field of the
        // InboundSuggestion shape so a future toArray/fromArray drift
        // breaks loudly.
        $cache = $this->newCache();
        $original = $this->makeSuggestion();
        $cache->store('t1', [$original]);

        $loaded = $cache->getCached('t1');
        $r = $loaded[0];
        $this->assertSame('s1', $r->sourceEntryId);
        $this->assertSame('Source Title', $r->sourceTitle);
        $this->assertSame('/source-url', $r->sourceUrl);
        $this->assertSame('pages', $r->sourceCollection);
        $this->assertSame('t1', $r->targetEntryId);
        $this->assertSame('click', $r->anchorText);
        $this->assertSame('foo click bar', $r->sentenceContext);
        $this->assertEqualsWithDelta(0.75, $r->score, 1e-9);
        $this->assertFalse($r->contextTruncatedStart);
        $this->assertTrue($r->contextTruncatedEnd);
        $this->assertSame('phrase', $r->matchType);
        $this->assertSame('semantic', $r->matchReason);
    }

    public function test_empty_suggestion_list_is_a_valid_hit(): void
    {
        // Same key distinction as BrokenLinkScanCache: "target has no
        // inbound suggestions" must round-trip as [] (not null) so the
        // engine doesn't re-compute on every modal open for empty
        // targets.
        $cache = $this->newCache();
        $cache->store('t1', []);

        $loaded = $cache->getCached('t1');
        $this->assertIsArray($loaded);
        $this->assertSame([], $loaded);
    }

    public function test_store_with_null_source_url_round_trips(): void
    {
        // InboundSuggestion::sourceUrl is nullable. Verify the round-trip
        // doesn't silently coerce null to empty string (and vice versa).
        $cache = $this->newCache();
        $s = new InboundSuggestion(
            sourceEntryId: 's1',
            sourceTitle: 'T',
            sourceUrl: null,
            sourceCollection: 'c',
            targetEntryId: 't1',
            anchorText: 'a',
            sentenceContext: '',
            score: 0.0,
        );
        $cache->store('t1', [$s]);

        $loaded = $cache->getCached('t1');
        $this->assertNull($loaded[0]->sourceUrl);
    }

    // ── Multi-target independence ──────────────────────────────────────

    public function test_two_targets_independent(): void
    {
        $cache = $this->newCache();
        $cache->store('t1', [$this->makeSuggestion('s1', 't1', 'alpha')]);
        $cache->store('t2', [$this->makeSuggestion('s2', 't2', 'beta')]);

        $a = $cache->getCached('t1');
        $b = $cache->getCached('t2');
        $this->assertSame('alpha', $a[0]->anchorText);
        $this->assertSame('beta', $b[0]->anchorText);
    }

    public function test_overwrite_replaces_previous_row(): void
    {
        $cache = $this->newCache();
        $cache->store('t1', [$this->makeSuggestion('s1', 't1', 'old')]);
        $cache->store('t1', [$this->makeSuggestion('s2', 't1', 'new')]);

        $loaded = $cache->getCached('t1');
        $this->assertCount(1, $loaded);
        $this->assertSame('new', $loaded[0]->anchorText);
    }

    // ── forget() ───────────────────────────────────────────────────────

    public function test_forget_drops_only_the_target(): void
    {
        $cache = $this->newCache();
        $cache->store('t1', [$this->makeSuggestion()]);
        $cache->store('t2', [$this->makeSuggestion()]);

        $cache->forget('t1');

        $this->assertNull($cache->getCached('t1'));
        $this->assertIsArray($cache->getCached('t2'));
    }

    public function test_forget_unknown_target_no_op(): void
    {
        $cache = $this->newCache();
        $cache->store('t1', [$this->makeSuggestion()]);

        $cache->forget('never-existed');

        $this->assertIsArray($cache->getCached('t1'));
    }

    // ── Defensive: corrupt sub-rows ────────────────────────────────────

    public function test_corrupt_subrow_silently_dropped(): void
    {
        // One missing source_entry_id in a stored row → that row is
        // skipped, the others survive. Pattern mirrors BrokenLinkReport.
        Cache::put('linkwise:inbound:suggestFiltered:t1', [
            ['source_entry_id' => '', 'target_entry_id' => 't1'], // missing
            [
                'source_entry_id' => 's2',
                'source_title' => 'T',
                'source_url' => null,
                'source_collection' => 'c',
                'target_entry_id' => 't1',
                'anchor_text' => 'a',
                'sentence_context' => '',
                'score' => 0.5,
            ],
        ], 60);

        $loaded = $this->newCache()->getCached('t1');
        $this->assertCount(1, $loaded);
        $this->assertSame('s2', $loaded[0]->sourceEntryId);
    }
}
