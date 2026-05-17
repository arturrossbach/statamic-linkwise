<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Feature pin for `EntryHashesController::index`.
 *
 * Why this endpoint exists:
 *   Klasse-7 C-1 residual race (docs/ARCHITECTURE_REVIEW.md). The C-1
 *   fix (PR #49) covered post-completion `reloadEntries()` so the
 *   localEntries hash-map refreshes after a bulk completes. That
 *   leaves a ~100-800ms race window: if the user opens a new
 *   DetailModal via `showDetail` BEFORE the partial-reload returns,
 *   the synchronous `localEntries[].content_hash` read still produces
 *   the OLD hash, the next bulk-unlink still ships OLD-hash, the
 *   user STILL gets the grey "entry was modified" toast.
 *
 *   `showDetail` becomes async, fetches fresh hashes from THIS endpoint
 *   before populating the modal's items list, mutates the local map
 *   in place. Full race closure.
 *
 * Contract pinned here:
 *   - Validation: requires non-empty `ids[]` array of strings.
 *   - Returns `{hashes: {entry_id: content_hash}}` for found entries.
 *   - Unknown / deleted entries skipped silently (no crash, no error).
 *   - Idempotent (GET method) so browsers + proxies can cache if
 *     they want — though we send `no-cache` headers from the client
 *     to defeat that.
 */
class EntryHashesEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No CP auth needed — pinning controller behaviour, not auth.
        $this->withoutMiddleware();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_hash_map_for_known_entry_ids(): void
    {
        // Mockery the Statamic Entry facade so we don't need a real
        // Stache. SafeEntrySaver::hash hashes the entry's data() output —
        // the mock just needs to satisfy that surface. Pattern mirrors
        // the existing Mockery-Spy approach in AutoLinkApplySyncTest.
        $entryA = $this->mockEntry('entry-a', ['title' => 'Page A', 'body' => 'hello']);
        $entryB = $this->mockEntry('entry-b', ['title' => 'Page B', 'body' => 'world']);

        EntryFacade::shouldReceive('find')->with('entry-a')->andReturn($entryA);
        EntryFacade::shouldReceive('find')->with('entry-b')->andReturn($entryB);

        $response = $this->getJson(
            route('linkwise.entry-hashes', ['ids' => ['entry-a', 'entry-b']]),
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('hashes', $data);
        $this->assertArrayHasKey('entry-a', $data['hashes']);
        $this->assertArrayHasKey('entry-b', $data['hashes']);
        $this->assertSame(SafeEntrySaver::hash($entryA), $data['hashes']['entry-a']);
        $this->assertSame(SafeEntrySaver::hash($entryB), $data['hashes']['entry-b']);
    }

    public function test_silently_skips_unknown_entry_ids(): void
    {
        // The endpoint must not error when an id resolves to null —
        // entries can be deleted between page-load and hash-fetch.
        // Skipping is the right contract because the frontend already
        // has to handle "no fresh hash available" (falls back to its
        // cached value). Throwing 404 here would break legitimate
        // mixed-set requests.
        EntryFacade::shouldReceive('find')->with('does-not-exist')->andReturn(null);
        $entry = $this->mockEntry('entry-a', ['title' => 'A']);
        EntryFacade::shouldReceive('find')->with('entry-a')->andReturn($entry);

        $response = $this->getJson(
            route('linkwise.entry-hashes', ['ids' => ['entry-a', 'does-not-exist']]),
        );

        $response->assertStatus(200);
        $hashes = $response->json('hashes');
        $this->assertArrayHasKey('entry-a', $hashes);
        $this->assertArrayNotHasKey('does-not-exist', $hashes);
    }

    public function test_returns_empty_map_when_all_ids_unknown(): void
    {
        EntryFacade::shouldReceive('find')->with('ghost-1')->andReturn(null);
        EntryFacade::shouldReceive('find')->with('ghost-2')->andReturn(null);

        $response = $this->getJson(
            route('linkwise.entry-hashes', ['ids' => ['ghost-1', 'ghost-2']]),
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('hashes'));
    }

    public function test_validates_missing_ids_param(): void
    {
        $response = $this->getJson(route('linkwise.entry-hashes'));

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('ids', $errors);
    }

    public function test_validates_empty_ids_array(): void
    {
        $response = $this->getJson(
            route('linkwise.entry-hashes', ['ids' => []]),
        );

        $response->assertStatus(422);
    }

    public function test_caps_request_size_to_prevent_url_overflow(): void
    {
        // 501 IDs > 500 cap — URL-length-explosion guard. If a caller
        // ever needs >500 entries' hashes in one shot we'd refactor to
        // POST batch, but today's flows (DetailModal source set) stay
        // well under that.
        $ids = array_map(fn ($i) => "entry-$i", range(1, 501));

        $response = $this->getJson(
            route('linkwise.entry-hashes', ['ids' => $ids]),
        );

        $response->assertStatus(422);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Build a minimal Entry mock that satisfies SafeEntrySaver::hash.
     *
     * hash() first tries `$entry->path()` (md5 over file contents minus
     * updated_at lines). If path is null/empty/non-existent it falls
     * back to `$entry->data()->all()` (md5 over JSON-encoded data minus
     * timestamp keys). Mocking path() → null forces the data-fallback
     * which is the deterministic path for tests.
     */
    private function mockEntry(string $id, array $data): Entry
    {
        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn($id);
        $entry->shouldReceive('path')->andReturn(null);  // forces data-fallback hashing
        $entry->shouldReceive('data')->andReturn(collect($data));

        return $entry;
    }
}
