<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use PHPUnit\Framework\TestCase;

class InboundEngineTest extends TestCase
{
    protected function createEngine(array $records): InboundEngine
    {
        $indexer = $this->createMock(EntryIndexer::class);
        $indexer->method('load')->willReturn($records);

        $keywordManager = $this->createMock(TargetKeywordManager::class);
        $keywordManager->method('getKeywords')->willReturn([]);

        return new InboundEngine($indexer, new SuggestionEngine(minPhraseWords: 2, minScore: 0.4), $keywordManager);
    }

    public function test_finds_inbound_suggestions(): void
    {
        $records = [
            'target' => new EntryRecord('target', 'Redis Setup Guide', '/redis', 'articles', '', outboundLinks: []),
            'source' => new EntryRecord('source', 'Deployment Tips', '/deploy', 'articles',
                'When deploying, follow the Redis Setup Guide for caching.', outboundLinks: []),
        ];

        $engine = $this->createEngine($records);
        $suggestions = $engine->suggest('target');

        $this->assertCount(1, $suggestions);
        $this->assertSame('source', $suggestions[0]->sourceEntryId);
        $this->assertSame('target', $suggestions[0]->targetEntryId);
        $this->assertSame('Redis Setup Guide', $suggestions[0]->anchorText);
    }

    public function test_skips_entries_already_linking_to_target(): void
    {
        $records = [
            'target' => new EntryRecord('target', 'Redis Setup Guide', '/redis', 'articles', '', outboundLinks: []),
            'source' => new EntryRecord('source', 'Deployment Tips', '/deploy', 'articles',
                'Follow the Redis Setup Guide here.', outboundLinks: ['target']),
        ];

        $engine = $this->createEngine($records);
        $suggestions = $engine->suggest('target');

        $this->assertCount(0, $suggestions);
    }

    public function test_returns_empty_for_unknown_entry(): void
    {
        $engine = $this->createEngine([]);
        $suggestions = $engine->suggest('nonexistent');

        $this->assertCount(0, $suggestions);
    }

    public function test_multiple_sources(): void
    {
        $records = [
            'target' => new EntryRecord('target', 'Redis Setup', '/redis', 'articles', '', outboundLinks: []),
            'a' => new EntryRecord('a', 'Entry A', '/a', 'articles',
                'Check the Redis Setup instructions.', outboundLinks: []),
            'b' => new EntryRecord('b', 'Entry B', '/b', 'articles',
                'Our Redis Setup is documented here.', outboundLinks: []),
        ];

        $engine = $this->createEngine($records);
        $suggestions = $engine->suggest('target');

        $this->assertCount(2, $suggestions);
        $sourceIds = array_map(fn ($s) => $s->sourceEntryId, $suggestions);
        $this->assertContains('a', $sourceIds);
        $this->assertContains('b', $sourceIds);
    }

    public function test_skips_self(): void
    {
        $records = [
            'target' => new EntryRecord('target', 'Redis Setup', '/redis', 'articles',
                'This article covers Redis Setup in detail.', outboundLinks: []),
        ];

        $engine = $this->createEngine($records);
        $suggestions = $engine->suggest('target');

        $this->assertCount(0, $suggestions);
    }
}
