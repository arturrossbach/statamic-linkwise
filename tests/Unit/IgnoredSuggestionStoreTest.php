<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Suggestions\IgnoredSuggestionStore;
use PHPUnit\Framework\TestCase;

/**
 * Structural pin for the ignored-suggestion pair store.
 *
 * Covers Klasse-10 guarantee-stack assertions (2026-05-22):
 *  - Pair persists across instances (file storage) → fail mode #5
 *    "page reload, ignored pair comes back".
 *  - Pair is undirected — ignore(A,B) means isIgnored(B,A)=true →
 *    fail mode #7 "ignore from inbound, still shows in outbound modal".
 *  - Duplicate ignore is idempotent → fail mode #2 "double-click
 *    double-ignores, storage corrupts or breaks count".
 *  - ignoredCountFor() returns count of pairs the entry participates
 *    in (either side) → backbone for StatsApiController subtraction.
 */
class IgnoredSuggestionStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/linkwise_ignored_pin_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir.'/ignored-suggestions.json';
        if (is_file($file)) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_ignore_then_isIgnored_returns_true_for_same_direction(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'b-uuid');

        $this->assertTrue($store->isIgnored('a-uuid', 'b-uuid'));
    }

    public function test_pair_is_undirected_symmetric_lookup(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'b-uuid');

        // Reverse direction must also resolve to ignored. Klasse-10
        // fail mode #7: ignore from inbound modal must hide the same
        // pair when viewed from outbound modal too.
        $this->assertTrue($store->isIgnored('b-uuid', 'a-uuid'));
    }

    public function test_duplicate_ignore_is_idempotent(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'b-uuid');
        $store->ignore('a-uuid', 'b-uuid');
        $store->ignore('b-uuid', 'a-uuid'); // reverse-direction duplicate

        $this->assertCount(
            1,
            $store->loadAll(),
            'Klasse-10 fail mode #2: double-click must not produce two storage entries for the same pair.'
        );
    }

    public function test_unignore_removes_pair_regardless_of_direction(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'b-uuid');
        $store->unignore('b-uuid', 'a-uuid'); // reverse direction

        $this->assertFalse($store->isIgnored('a-uuid', 'b-uuid'));
        $this->assertCount(0, $store->loadAll());
    }

    public function test_unignore_unknown_pair_is_no_op(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->unignore('a-uuid', 'b-uuid'); // never ignored to begin with

        $this->assertCount(0, $store->loadAll());
        $this->assertFalse($store->isIgnored('a-uuid', 'b-uuid'));
    }

    public function test_ignoredCountFor_returns_count_either_side(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'b-uuid');
        $store->ignore('c-uuid', 'a-uuid');  // 'a' on the other side
        $store->ignore('x-uuid', 'y-uuid');  // doesn't involve 'a'

        // 'a' participates in 2 pairs, regardless of side. Backbone
        // for StatsApiController::suggestionCounts subtraction.
        $this->assertSame(2, $store->ignoredCountFor('a-uuid'));
        $this->assertSame(1, $store->ignoredCountFor('b-uuid'));
        $this->assertSame(1, $store->ignoredCountFor('c-uuid'));
        $this->assertSame(0, $store->ignoredCountFor('z-uuid'));
    }

    public function test_self_pair_is_rejected_silently(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'a-uuid');

        // Self-pair never persists — engine would never suggest an
        // entry as its own target anyway. Mirrors the controller's
        // validate "distinct" rule but at the store-level too.
        $this->assertCount(0, $store->loadAll());
        $this->assertFalse($store->isIgnored('a-uuid', 'a-uuid'));
    }

    public function test_empty_id_is_rejected_silently(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('', 'b-uuid');
        $store->ignore('a-uuid', '');

        $this->assertCount(0, $store->loadAll());
    }

    public function test_persists_across_instances(): void
    {
        $first = new IgnoredSuggestionStore($this->tmpDir);
        $first->ignore('a-uuid', 'b-uuid');

        // Fresh instance, same dir → must read the persisted file.
        // Klasse-10 fail mode #5: page reload must keep ignore.
        $second = new IgnoredSuggestionStore($this->tmpDir);
        $this->assertTrue($second->isIgnored('a-uuid', 'b-uuid'));
        $this->assertSame(1, $second->ignoredCountFor('a-uuid'));
    }

    public function test_normalises_pair_alphabetically_in_storage(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('z-uuid', 'a-uuid'); // unsorted input

        $pairs = $store->loadAll();
        $this->assertCount(1, $pairs);
        // 'a' < 'z' → expect ['a-uuid', 'z-uuid'] order in storage.
        $this->assertSame(['a-uuid', 'z-uuid'], $pairs[0]);
    }

    public function test_loadAll_filters_malformed_entries(): void
    {
        // Hand-write a partly-corrupt file. Real-world: editor edits
        // the JSON, leaves a stray entry without the second id, or
        // an entire malformed record. Store must skip those silently.
        $file = $this->tmpDir.'/ignored-suggestions.json';
        file_put_contents($file, json_encode([
            ['a-uuid', 'b-uuid'],     // good
            ['only-one-id'],          // malformed: 1 element
            'not-an-array',           // malformed: scalar
            ['c-uuid', 42],           // malformed: non-string second
        ]));

        $store = new IgnoredSuggestionStore($this->tmpDir);
        $pairs = $store->loadAll();
        $this->assertCount(1, $pairs);
        $this->assertSame(['a-uuid', 'b-uuid'], $pairs[0]);
    }

    public function test_clearAll_wipes_storage(): void
    {
        $store = new IgnoredSuggestionStore($this->tmpDir);
        $store->ignore('a-uuid', 'b-uuid');
        $store->ignore('c-uuid', 'd-uuid');
        $store->clearAll();

        $this->assertCount(0, $store->loadAll());
    }
}
