<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestion;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Integration pins for the Sprint 6 REV-IB-01 wire-in:
 * `InboundEngine::suggestFiltered` now consults `InboundSuggestionCache`
 * before doing the live compute.
 *
 * Two paths matter:
 *   1. Cache HIT: live compute is skipped entirely, cached result wins.
 *      `lastTotalCount` is set from the cache. limit is applied to the
 *      cached super-set.
 *   2. Cache MISS: live compute runs, result is stored.
 *
 * The unit-suite (InboundSuggestionCacheTest) pins the cache class in
 * isolation. This suite pins the engine-side wiring.
 */
class InboundEngineCacheIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_cache_hit_skips_live_compute(): void
    {
        // Stub indexer + keyword manager so we can detect whether the
        // live compute was triggered (it would call indexer->load()).
        $indexer = $this->createMock(EntryIndexer::class);
        $indexer->expects($this->never())->method('load');

        $keywordManager = $this->createMock(TargetKeywordManager::class);

        $cache = new InboundSuggestionCache;
        $cache->store('t1', [
            new InboundSuggestion(
                sourceEntryId: 's1',
                sourceTitle: 'Cached',
                sourceUrl: '/cached',
                sourceCollection: 'pages',
                targetEntryId: 't1',
                anchorText: 'cached anchor',
                sentenceContext: 'context',
                score: 0.9,
            ),
        ]);

        $engine = new InboundEngine(
            $indexer,
            new SuggestionEngine(minPhraseWords: 2, minScore: 0.4),
            $keywordManager,
            $cache,
        );

        $result = $engine->suggestFiltered('t1');

        $this->assertCount(1, $result);
        $this->assertSame('cached anchor', $result[0]->anchorText);
        $this->assertSame(1, $engine->getLastTotalCount());
    }

    public function test_cache_hit_respects_limit_slice(): void
    {
        $indexer = $this->createMock(EntryIndexer::class);
        $indexer->expects($this->never())->method('load');
        $keywordManager = $this->createMock(TargetKeywordManager::class);

        $cache = new InboundSuggestionCache;
        $cache->store('t1', [
            $this->makeCachedSuggestion('s1', 't1', 'a'),
            $this->makeCachedSuggestion('s2', 't1', 'b'),
            $this->makeCachedSuggestion('s3', 't1', 'c'),
        ]);

        $engine = new InboundEngine(
            $indexer,
            new SuggestionEngine(minPhraseWords: 2, minScore: 0.4),
            $keywordManager,
            $cache,
        );

        $result = $engine->suggestFiltered('t1', limit: 2);

        // The cache holds 3 items; the limit slices to 2 BUT
        // lastTotalCount reflects the full super-set (3) so the
        // "X of Y" modal header is honest. Same semantics as the
        // pre-cache code path.
        $this->assertCount(2, $result);
        $this->assertSame(3, $engine->getLastTotalCount());
    }

    public function test_cache_miss_with_empty_indexer_returns_empty_and_stores(): void
    {
        // Empty indexer → engine produces empty filtered result.
        // After the call, the cache should hold the empty list so the
        // next call hits ([] not null).
        $indexer = $this->createMock(EntryIndexer::class);
        $indexer->method('load')->willReturn([]);
        $keywordManager = $this->createMock(TargetKeywordManager::class);

        $cache = new InboundSuggestionCache;
        $engine = new InboundEngine(
            $indexer,
            new SuggestionEngine(minPhraseWords: 2, minScore: 0.4),
            $keywordManager,
            $cache,
        );

        $first = $engine->suggestFiltered('t1');
        $this->assertSame([], $first);

        // Verify post-write the cache holds the empty list.
        $cached = $cache->getCached('t1');
        $this->assertIsArray($cached);
        $this->assertSame([], $cached);
    }

    public function test_cache_miss_with_limit_does_not_store(): void
    {
        // When the caller asks for a limited slice, suggest() truncates
        // upstream — we don't want to memoize a truncated row as the
        // canonical full result. Pin that decision.
        $indexer = $this->createMock(EntryIndexer::class);
        $indexer->method('load')->willReturn([]);
        $keywordManager = $this->createMock(TargetKeywordManager::class);

        $cache = new InboundSuggestionCache;
        $engine = new InboundEngine(
            $indexer,
            new SuggestionEngine(minPhraseWords: 2, minScore: 0.4),
            $keywordManager,
            $cache,
        );

        $engine->suggestFiltered('t1', limit: 5);

        $this->assertNull($cache->getCached('t1'));
    }

    public function test_cache_isolated_per_target(): void
    {
        $indexer = $this->createMock(EntryIndexer::class);
        $indexer->expects($this->never())->method('load');
        $keywordManager = $this->createMock(TargetKeywordManager::class);

        $cache = new InboundSuggestionCache;
        $cache->store('t1', [$this->makeCachedSuggestion('s1', 't1', 'a')]);
        $cache->store('t2', [$this->makeCachedSuggestion('s2', 't2', 'b')]);

        $engine = new InboundEngine(
            $indexer,
            new SuggestionEngine(minPhraseWords: 2, minScore: 0.4),
            $keywordManager,
            $cache,
        );

        $this->assertSame('a', $engine->suggestFiltered('t1')[0]->anchorText);
        $this->assertSame('b', $engine->suggestFiltered('t2')[0]->anchorText);
    }

    protected function makeCachedSuggestion(string $source, string $target, string $anchor): InboundSuggestion
    {
        return new InboundSuggestion(
            sourceEntryId: $source,
            sourceTitle: 'T',
            sourceUrl: null,
            sourceCollection: 'pages',
            targetEntryId: $target,
            anchorText: $anchor,
            sentenceContext: '',
            score: 0.5,
        );
    }
}
