<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Contract pin for CR-H-2 (V1.x-Polish-Audit):
 *
 *   `SafeEntrySaver::load()` returns the documented `[?Entry, string]`
 *   tuple. When the entry is not found, the tuple is `[null, '']` —
 *   the hash slot is an empty string, NOT null, so callers using
 *   list-destructuring can rely on the hash type even when entry is
 *   null.
 *
 * All 5 known callers (RelinkService:97, BardLinkInserter:244/328/420,
 * UrlReplacer:83) check `if (! $entry)` before using `$hash` — this
 * test pins that contract so a future docblock-divergence or return-
 * shape change is caught.
 */
class SafeEntrySaverLoadContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_load_returns_null_entry_and_empty_string_hash_when_entry_not_found(): void
    {
        EntryFacade::shouldReceive('find')
            ->with('does-not-exist-123')
            ->andReturn(null);

        [$entry, $hash] = SafeEntrySaver::load('does-not-exist-123');

        $this->assertNull($entry, 'entry slot must be null when find() returns null');
        $this->assertSame('', $hash, 'hash slot must be empty string (NOT null) when entry not found');
    }

    public function test_load_returns_entry_and_nonempty_hash_when_entry_exists(): void
    {
        $entry = Mockery::mock(Entry::class);
        // SafeEntrySaver::hash() reads disk-path; mock it to avoid I/O.
        // Statamic Entry's `path()` returns the on-disk path; if absent,
        // the legacy md5(json_encode(data)) fallback kicks in (see
        // SafeEntrySaverHashTest for hash internals). Stub `path()` to
        // return null so the fallback path is exercised.
        $entry->shouldReceive('path')->andReturn(null);
        $entry->shouldReceive('data')->andReturn(collect(['title' => 'X']));
        $entry->shouldReceive('blueprint')->andReturn(null);

        EntryFacade::shouldReceive('find')
            ->with('exists-456')
            ->andReturn($entry);

        [$loaded, $hash] = SafeEntrySaver::load('exists-456');

        $this->assertSame($entry, $loaded);
        $this->assertIsString($hash);
        $this->assertNotSame('', $hash, 'hash for an existing entry must be non-empty');
    }
}
