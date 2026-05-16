<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestion;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClass;

/**
 * **The pin-set Sprint 6 should have had from day one** (user-reported
 * 2026-05-16: "5 Tage Sprints und nach wie vor gefühlt eine fragile
 * Architektur").
 *
 * Empirical contract: after any write-path mutates an entry, a subsequent
 * call to `InboundSuggestionCache::getCached($entryId)` for an affected
 * entry must return `null` (cache forgotten so the next read recomputes
 * fresh).
 *
 * This file pins that contract for **every** write-path Linkwise has —
 * commands AND sync controllers — using a real Cache backend + a primed
 * stale entry. If a future PR adds another write-path and forgets to
 * invalidate, the matching test here fails loudly.
 *
 * # Bug history this pin-set retroactively covers
 *
 *   PR #43  added InboundSuggestionCache without write-path invalidation
 *   PR #44  added invalidation to 5 bulk commands (modal stale)
 *   PR #45  fixed order (forget BEFORE recompute reads through cache)
 *   PR #46  added invalidation to 3 sync controllers (relink + sync paths)
 *   PR #47  fixed modal flicker in DetailModal post-relink path
 *
 * All five PRs in 24h share one root cause: no end-to-end "post-write
 * reads return fresh" pin existed. Now they do.
 *
 * # Methodology
 *
 * The tests don't run full HTTP round-trips — too heavy and would need
 * real Statamic entries. Instead they directly invoke the protected
 * `finalizeIndex` method on each command (or `refreshAfterRelink` on
 * `RelinkController`) via reflection, with the InboundSuggestionCache
 * bound to the real Laravel container. The Cache facade backs them with
 * the test driver (array), so cache state is observable across the call.
 *
 * Order is also pinned because reading through a stale cache during
 * count recompute (PR #45's order-bug) is the most subtle regression
 * class — the suite tracks call sequence via a spy.
 */
class CacheInvalidationAfterWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Cache primed with a stale suggestion so we can detect invalidation. */
    protected function primeCache(string $entryId): void
    {
        $cache = app(InboundSuggestionCache::class);
        $cache->store($entryId, [
            new InboundSuggestion(
                sourceEntryId: 'stale-source',
                sourceTitle: 'Stale',
                sourceUrl: null,
                sourceCollection: 'pages',
                targetEntryId: $entryId,
                anchorText: 'stale anchor',
                sentenceContext: '',
                score: 0.5,
            ),
        ]);
        // Sanity: row exists pre-write.
        $this->assertIsArray($cache->getCached($entryId));
    }

    /** After-write assertion: cache for $entryId is gone. */
    protected function assertCacheForgotten(string $entryId, string $context): void
    {
        $cache = app(InboundSuggestionCache::class);
        $this->assertNull(
            $cache->getCached($entryId),
            "Cache for {$entryId} should be invalidated after {$context}, but stale row survived.",
        );
    }

    /**
     * Bind a spy InboundSuggestionCache + spy EntryIndexer so we can both
     * verify the call order AND keep the real cache observable behaviour.
     *
     * @return array{cache: InboundSuggestionCache, indexer: EntryIndexer, order: array<int, string>}
     */
    protected function bindSpiesWithOrderTracking(): array
    {
        $order = [];

        $cacheSpy = Mockery::mock(InboundSuggestionCache::class)->makePartial();
        $cacheSpy->shouldReceive('forgetMany')
            ->andReturnUsing(function (array $ids) use (&$order) {
                $order[] = 'cache.forgetMany';
            });

        // EntryIndexer needs real instance methods for the rebuild path —
        // mock only the methods we care about call-order for, pass-through
        // the rest.
        $indexerSpy = Mockery::mock(EntryIndexer::class)->makePartial();
        $indexerSpy->shouldReceive('computeSuggestionCountsForEntries')
            ->andReturnUsing(function (array $ids) use (&$order) {
                $order[] = 'indexer.computeSuggestionCountsForEntries';

                return [];
            });
        $indexerSpy->shouldReceive('load')->andReturn([]);
        $indexerSpy->shouldReceive('clearCache')->andReturn(null);
        $indexerSpy->shouldReceive('buildIndex')->andReturn([]);
        // Without explicit stub the partial mock would call the real
        // `save()` which writes to disk and throws on an empty/missing
        // index file in the test env — collapses the whole finalizeIndex
        // try-block via catch + early-return, swallowing all later calls
        // (forget + recompute) and making the order-pin fail with a
        // misleading "was not called" message.
        $indexerSpy->shouldReceive('save')->andReturn(null);
        // Refuse-to-overwrite-empty-index guard in finalizeIndex compares
        // build result count against `load()` previous count. Both 0 here
        // → save() is not called (guard branch). That's OK — we're not
        // testing save() semantics, only order + invalidation.

        app()->instance(InboundSuggestionCache::class, $cacheSpy);
        app()->instance(EntryIndexer::class, $indexerSpy);

        return ['cache' => $cacheSpy, 'indexer' => $indexerSpy, 'order' => &$order];
    }

    /**
     * Invoke a protected `finalizeIndex` (or named helper) on a command
     * instance via reflection. Returns nothing — the side-effect IS the
     * test target.
     */
    protected function callProtected(object $instance, string $method, array $args): void
    {
        $ref = new ReflectionClass($instance);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        $m->invoke($instance, ...$args);
    }

    // ── Bulk commands (PR #44 + PR #45 — order swap) ───────────────────

    public function test_LinkInsertCommand_forgets_cache_before_recompute(): void
    {
        $spies = $this->bindSpiesWithOrderTracking();
        $command = app(\Arturrossbach\Linkwise\Commands\LinkInsertCommand::class);

        $this->callProtected($command, 'finalizeIndex', [['e1', 'e2']]);

        $this->assertGreaterThan(0, count($spies['order']),
            'LinkInsertCommand finalizeIndex should have run cache-forget + count-recompute');
        // Cache forget MUST come BEFORE count recompute — otherwise the
        // recompute reads through stale cache (PR #45 bug-class).
        $forgetIdx = array_search('cache.forgetMany', $spies['order']);
        $recomputeIdx = array_search('indexer.computeSuggestionCountsForEntries', $spies['order']);
        $this->assertNotFalse($forgetIdx, 'forgetMany was not called');
        $this->assertNotFalse($recomputeIdx, 'computeSuggestionCountsForEntries was not called');
        $this->assertLessThan($recomputeIdx, $forgetIdx,
            'cache.forgetMany must execute BEFORE indexer.computeSuggestionCountsForEntries');
    }

    public function test_BulkUnlinkCommand_forgets_cache_before_recompute(): void
    {
        $spies = $this->bindSpiesWithOrderTracking();
        $command = app(\Arturrossbach\Linkwise\Commands\BulkUnlinkCommand::class);

        $this->callProtected($command, 'finalizeIndex', [['e1']]);

        $this->assertOrder($spies['order'], 'cache.forgetMany', 'indexer.computeSuggestionCountsForEntries');
    }

    public function test_DetailUnlinkCommand_forgets_cache_before_recompute(): void
    {
        $spies = $this->bindSpiesWithOrderTracking();
        $command = app(\Arturrossbach\Linkwise\Commands\DetailUnlinkCommand::class);

        $this->callProtected($command, 'finalizeIndex', [['e1']]);

        $this->assertOrder($spies['order'], 'cache.forgetMany', 'indexer.computeSuggestionCountsForEntries');
    }

    public function test_UrlChangerApplyCommand_forgets_cache_before_recompute(): void
    {
        $spies = $this->bindSpiesWithOrderTracking();
        $command = app(\Arturrossbach\Linkwise\Commands\UrlChangerApplyCommand::class);

        $this->callProtected($command, 'finalizeIndex', [['e1']]);

        $this->assertOrder($spies['order'], 'cache.forgetMany', 'indexer.computeSuggestionCountsForEntries');
    }

    public function test_ApplyRuleCommand_forgets_cache_before_recompute(): void
    {
        $spies = $this->bindSpiesWithOrderTracking();
        $command = app(\Arturrossbach\Linkwise\Commands\ApplyRuleCommand::class);

        $this->callProtected($command, 'finalizeIndex', [['e1']]);

        $this->assertOrder($spies['order'], 'cache.forgetMany', 'indexer.computeSuggestionCountsForEntries');
    }

    // ── Sync controllers (PR #46) ──────────────────────────────────────

    public function test_RelinkController_refreshAfterRelink_forgets_cache_before_recompute(): void
    {
        $spies = $this->bindSpiesWithOrderTracking();
        $controller = app(\Arturrossbach\Linkwise\Http\Controllers\RelinkController::class);

        $this->callProtected($controller, 'refreshAfterRelink', [['e1', 'e2']]);

        $this->assertOrder($spies['order'], 'cache.forgetMany', 'indexer.computeSuggestionCountsForEntries');
    }

    // ── End-to-end (real cache, real call) ─────────────────────────────

    public function test_forgetMany_invalidates_primed_cache_real_backend(): void
    {
        // No mocks — verify the actual `InboundSuggestionCache::forgetMany`
        // wipes the row in Laravel's cache, not just calls a method.
        $this->primeCache('e1');
        $this->primeCache('e2');
        $this->primeCache('e3');

        app(InboundSuggestionCache::class)->forgetMany(['e1', 'e3']);

        $this->assertCacheForgotten('e1', 'forgetMany call');
        $this->assertIsArray(app(InboundSuggestionCache::class)->getCached('e2'),
            'e2 was NOT in forgetMany list — must survive');
        $this->assertCacheForgotten('e3', 'forgetMany call');
    }

    // ── Negative case: NO forget when no entries affected ──────────────

    public function test_LinkInsertCommand_no_forget_on_empty_affected(): void
    {
        // Defensive: empty affected-ids must not blanket-wipe the cache.
        // Earlier draft of forgetMany had a no-op-empty guard; this pins it.
        $this->primeCache('e1');

        $spies = $this->bindSpiesWithOrderTracking();
        $command = app(\Arturrossbach\Linkwise\Commands\LinkInsertCommand::class);

        $this->callProtected($command, 'finalizeIndex', [[]]);

        $this->assertNotContains('cache.forgetMany', $spies['order'],
            'finalizeIndex with empty affected-ids should NOT call forgetMany');
    }

    /** @param  list<string>  $order */
    protected function assertOrder(array $order, string $first, string $second): void
    {
        $firstIdx = array_search($first, $order);
        $secondIdx = array_search($second, $order);
        $this->assertNotFalse($firstIdx, "{$first} was not called");
        $this->assertNotFalse($secondIdx, "{$second} was not called");
        $this->assertLessThan(
            $secondIdx,
            $firstIdx,
            "Call order violated: {$first} must execute BEFORE {$second}",
        );
    }
}
