<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestion;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Pin: `linkwise:index` invalidates cached inbound suggestions after
 * writing the fresh index. A reindex changes the suggestion-output
 * structurally (different extraction rules → different anchor pool);
 * every cached row computed against the prior index is stale by
 * definition. Without this purge, the next `suggestFiltered` call
 * returns the pre-reindex shape until TTL expires (5 min default).
 *
 * Sister of the per-write pin-set in CacheInvalidationAfterWriteTest
 * (PRs #44-#48) but for index-shape changes.
 */
class IndexCommandCachePurgeTest extends TestCase
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

    public function test_it_flushes_inbound_suggestion_cache_after_reindex(): void
    {
        $targetId = 'target-entry-1';

        // Prime cache with a stale suggestion row.
        $cache = app(InboundSuggestionCache::class);
        $cache->store($targetId, [
            new InboundSuggestion(
                sourceEntryId: 'stale-source',
                sourceTitle: 'Stale',
                sourceUrl: null,
                sourceCollection: 'pages',
                targetEntryId: $targetId,
                anchorText: 'stale anchor',
                sentenceContext: '',
                score: 0.5,
            ),
        ]);
        $this->assertIsArray($cache->getCached($targetId),
            'Sanity: stale row must exist pre-reindex.');

        // Stub the indexer so the command runs without real Statamic
        // entries. `buildIndex` returns a record list that includes
        // $targetId — the post-save invalidation must purge the cache
        // entry for this id.
        $record = new EntryRecord(
            id: $targetId,
            title: 'Target',
            url: '/t',
            collection: 'pages',
            text: 'body',
            outboundLinks: [],
        );

        $indexerSpy = Mockery::mock(EntryIndexer::class)->makePartial();
        $indexerSpy->shouldReceive('buildIndex')->andReturn([$targetId => $record]);
        $indexerSpy->shouldReceive('enrichWithSuggestionCountsStreamed')
            ->andReturn([$targetId => $record]);
        $indexerSpy->shouldReceive('save')->andReturn(null);
        $indexerSpy->shouldReceive('load')->andReturn([$targetId => $record]);
        app()->instance(EntryIndexer::class, $indexerSpy);

        // Statamic's AddonServiceProvider command registration doesn't
        // light up in Testbench's `Artisan::call` path — invoke the
        // command directly via DI to test its handle() contract.
        $cmd = app(\Arturrossbach\Linkwise\Commands\IndexCommand::class);
        $cmd->setLaravel(app());
        $cmd->run(new ArrayInput([]), new NullOutput);

        $this->assertNull(
            $cache->getCached($targetId),
            'Cache row for '.$targetId.' should be invalidated after `linkwise:index`, '
            .'but stale row survived — reindex did not purge inbound suggestion cache.',
        );
    }
}
