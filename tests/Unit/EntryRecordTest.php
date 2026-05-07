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

    public function test_throws_on_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntryRecord::fromArray([
            'title' => 'No id',
            'collection' => 'articles',
        ]);
    }

    public function test_throws_on_missing_title(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntryRecord::fromArray([
            'id' => 'abc-123',
            'collection' => 'articles',
        ]);
    }

    public function test_throws_on_missing_collection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntryRecord::fromArray([
            'id' => 'abc-123',
            'title' => 'No collection',
        ]);
    }

    public function test_throws_on_non_string_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntryRecord::fromArray([
            'id' => 123, // int, not string
            'title' => 'Bad id',
            'collection' => 'articles',
        ]);
    }

    public function test_accepts_empty_title_string(): void
    {
        // Empty string is a valid title (untitled drafts are real),
        // missing/null/non-string is not.
        $record = EntryRecord::fromArray([
            'id' => 'abc',
            'title' => '',
            'collection' => 'articles',
        ]);
        $this->assertSame('', $record->title);
    }

    public function test_falls_back_on_optional_fields_when_wrong_type(): void
    {
        $record = EntryRecord::fromArray([
            'id' => 'abc',
            'title' => 'T',
            'collection' => 'articles',
            'url' => 123, // wrong type → null
            'text' => ['array'], // wrong type → ''
            'outbound_links' => 'not-an-array', // wrong type → []
            'keywords' => 'nope', // wrong type → []
            'inbound_suggestion_count' => '5', // numeric string → 5
            'has_title_match' => 'yes', // truthy string → true
        ]);

        $this->assertNull($record->url);
        $this->assertSame('', $record->text);
        $this->assertSame([], $record->outboundLinks);
        $this->assertSame([], $record->keywords);
        $this->assertSame(5, $record->inboundSuggestionCount);
        $this->assertTrue($record->hasTitleMatch);
    }
}
