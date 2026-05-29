<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\NLP\KeywordExtractor;
use Arturrossbach\Linkwise\Subscribers\EntryIndexSubscriber;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;
use Statamic\Entries\Entry;
use Statamic\Events\EntrySaved;

/**
 * H-6 (Code-Review 2026-05-29): EntryIndexSubscriber::handleSaved indexed
 * every saved entry unconditionally, with no published-status check, while
 * the full-scan buildIndex (EntryIndexer.php:59-60) skips unpublished entries
 * when config('linkwise.entry_status') === 'published' (the default).
 *
 * Two consequences the subscriber must mirror from buildIndex:
 *   1. Saving an unpublished entry must NOT add it to the index.
 *   2. A published→draft transition must REMOVE the existing record (a full
 *      rescan would have excluded it; the incremental path must converge to
 *      the same state, not leave a stale published copy).
 *
 * Both assertions fail before the fix (the unpublished entry gets indexed /
 * the stale record survives).
 */
class EntryIndexSubscriberPublishedFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSubscriber(): array
    {
        $indexer = new EntryIndexer(sys_get_temp_dir().'/linkwise-h6-test-'.uniqid());
        $subscriber = new EntryIndexSubscriber($indexer, new KeywordExtractor(maxKeywords: 10));

        return [$subscriber, $indexer];
    }

    /**
     * Build the event without its constructor — the real EntrySaved ctor
     * spins up Statamic's InitiatorStack (root()/ancestors()/Blink), which
     * is irrelevant here: handleSaved only reads $event->entry.
     */
    private function savedEvent(Entry $entry): EntrySaved
    {
        $event = (new \ReflectionClass(EntrySaved::class))->newInstanceWithoutConstructor();
        $event->entry = $entry;

        return $event;
    }

    private function entry(string $id, string $title, bool $published): Entry
    {
        $bard = Mockery::mock();
        $bard->shouldReceive('type')->andReturn('bard');

        $fieldsCollection = Mockery::mock();
        $fieldsCollection->shouldReceive('all')->andReturn(['body' => $bard]);

        $blueprint = Mockery::mock();
        $blueprint->shouldReceive('fields')->andReturn($fieldsCollection);

        $value = [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'some indexable body content here']],
        ]];

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn($id);
        $entry->shouldReceive('title')->andReturn($title);
        $entry->shouldReceive('blueprint')->andReturn($blueprint);
        $entry->shouldReceive('url')->andReturn('/'.$id);
        $entry->shouldReceive('collectionHandle')->andReturn('pages');
        $entry->shouldReceive('published')->andReturn($published);
        $entry->shouldReceive('get')->with('body')->andReturn($value);
        $entry->shouldReceive('value')->with('body')->andReturn($value);
        $entry->shouldReceive('get')->with('title')->andReturn($title);

        return $entry;
    }

    public function test_saving_a_published_entry_indexes_it(): void
    {
        // Guard the happy path — the filter must not over-reach and drop
        // legitimately-published entries.
        [$subscriber, $indexer] = $this->makeSubscriber();

        $subscriber->handleSaved($this->savedEvent($this->entry('pub1', 'Published One', true)));

        $this->assertArrayHasKey('pub1', $indexer->load());
    }

    public function test_saving_an_unpublished_entry_does_not_index_it(): void
    {
        [$subscriber, $indexer] = $this->makeSubscriber();

        $subscriber->handleSaved($this->savedEvent($this->entry('draft1', 'Draft One', false)));

        $this->assertArrayNotHasKey(
            'draft1',
            $indexer->load(),
            'unpublished entry must not be indexed when entry_status is published',
        );
    }

    public function test_publishing_then_unpublishing_removes_the_record(): void
    {
        [$subscriber, $indexer] = $this->makeSubscriber();

        // First save while published → indexed.
        $subscriber->handleSaved($this->savedEvent($this->entry('e1', 'Toggle Me', true)));
        $this->assertArrayHasKey('e1', $indexer->load());

        // Editor unpublishes and saves again → must be removed, not left stale.
        $subscriber->handleSaved($this->savedEvent($this->entry('e1', 'Toggle Me', false)));
        $this->assertArrayNotHasKey(
            'e1',
            $indexer->load(),
            'published→draft transition must drop the entry from the index',
        );
    }
}
