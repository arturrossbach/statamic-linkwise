<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use PHPUnit\Framework\TestCase;

class EntryRecordTest extends TestCase
{
    public function test_serializes_to_array(): void
    {
        $record = new EntryRecord(
            id: 'abc-123',
            title: 'Test Entry',
            url: '/blog/test-entry',
            collection: 'articles',
            text: 'Some content here',
            outboundLinks: ['def-456', 'ghi-789'],
        );

        $this->assertSame([
            'id' => 'abc-123',
            'title' => 'Test Entry',
            'url' => '/blog/test-entry',
            'collection' => 'articles',
            'text' => 'Some content here',
            'outbound_links' => ['def-456', 'ghi-789'],
            'keywords' => [],
            'inbound_suggestion_count' => 0,
            'outbound_suggestion_count' => 0,
            'has_title_match' => false,
        ], $record->toArray());
    }

    public function test_deserializes_from_array(): void
    {
        $record = EntryRecord::fromArray([
            'id' => 'abc-123',
            'title' => 'Test Entry',
            'url' => '/blog/test-entry',
            'collection' => 'articles',
            'text' => 'Some content here',
            'outbound_links' => ['def-456'],
            'keywords' => ['test' => 0.5, 'entry' => 0.3],
        ]);

        $this->assertSame('abc-123', $record->id);
        $this->assertSame('Test Entry', $record->title);
        $this->assertSame('/blog/test-entry', $record->url);
        $this->assertSame('articles', $record->collection);
        $this->assertSame('Some content here', $record->text);
        $this->assertSame(['def-456'], $record->outboundLinks);
        $this->assertSame(['test' => 0.5, 'entry' => 0.3], $record->keywords);
    }

    public function test_roundtrip_serialization(): void
    {
        $original = new EntryRecord(
            id: 'test-id',
            title: 'Roundtrip Test',
            url: null,
            collection: 'pages',
            text: '',
            outboundLinks: [],
        );

        $restored = EntryRecord::fromArray($original->toArray());

        $this->assertSame($original->id, $restored->id);
        $this->assertSame($original->title, $restored->title);
        $this->assertSame($original->url, $restored->url);
        $this->assertSame($original->collection, $restored->collection);
        $this->assertSame($original->text, $restored->text);
        $this->assertSame($original->outboundLinks, $restored->outboundLinks);
        $this->assertSame($original->keywords, $restored->keywords);
    }
}
