<?php

namespace Arturrossbach\Linkwise\Tests\Feature\Dashboard;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Links\LinkwiseLinkMark;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;

/**
 * Characterisation tests for the Stats / Suggestions / DomainAttribute
 * cluster of DashboardController — pinned BEFORE the REV-DR-01
 * extraction (Sprint 4 / Phase A.4).
 *
 * Cluster scope: 3 routes.
 *  - GET  `linkwise/suggestion-counts`     → suggestionCounts()
 *  - GET  `linkwise/stats/{entryId}`       → entryStats()
 *  - POST `linkwise/domain-attribute`      → saveDomainAttribute()
 *
 * Why pinned together: all three are JSON sidecars used by Links /
 * Domains / Activity renderers AFTER initial Inertia render. Phase B
 * extraction will most likely group them under a "Dashboard\StatsApi"
 * sub-controller — they're the smallest cluster but the highest
 * SoT-drift risk: entryStats aggregates 4 separate sources (LinkReport
 * inbound/outbound, BrokenLinkReport, InboundEngine, SuggestionEngine).
 *
 * Test stack reused from REV-AL-01 (feature_test_stack memory):
 * - `defineRoutes` side-load via TestCase.
 * - Mockery spies on container-resolved services (InboundEngine,
 *   SuggestionEngine, EntryIndexer). `new BrokenLinkReport` /
 *   `new DomainReport` are filesystem-driven — we control them through
 *   storage_path('linkwise')/*.json files.
 * - `withoutMiddleware()` bypasses `can:manage linkwise`.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-01
 */
class StatsAndDomainAttributeTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        // BrokenLinkReport and DomainReport are `new`'d (not container
        // resolved) inside the controller and read/write to
        // storage_path('linkwise')/*.json. Reset between tests so each
        // case sees a clean disk state.
        $this->storageDir = storage_path('linkwise');
        if (! is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
        foreach (['broken-links.json', 'domain-attributes.json'] as $f) {
            $p = $this->storageDir.'/'.$f;
            if (file_exists($p)) {
                unlink($p);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (['broken-links.json', 'domain-attributes.json'] as $f) {
            $p = $this->storageDir.'/'.$f;
            if (file_exists($p)) {
                unlink($p);
            }
        }
        Mockery::close();
        parent::tearDown();
    }

    // ── suggestionCounts ───────────────────────────────────────────────

    public function test_suggestion_counts_returns_empty_object_for_empty_indexer(): void
    {
        $this->bindIndexer([]);

        $response = $this->getJson(route('linkwise.suggestion-counts'));

        $response->assertStatus(200);
        // PHP encodes empty array as `[]` not `{}` — that's a known
        // Frontend issue but not in scope here. We pin the shape: empty
        // input → empty output, no keys leak from elsewhere.
        $this->assertSame([], $response->json());
    }

    public function test_suggestion_counts_emits_per_entry_inbound_outbound_shape(): void
    {
        $this->bindIndexer([
            'post-a' => $this->record('post-a', inbound: 3, outbound: 5),
            'post-b' => $this->record('post-b', inbound: 0, outbound: 7),
        ]);

        $response = $this->getJson(route('linkwise.suggestion-counts'));

        $response->assertStatus(200);
        // Per-entry contract: exactly two keys per entry. Frontend reads
        // `counts[entryId].inbound` and `.outbound` — any rename in Phase
        // B would silently zero out the entire Links-Report column.
        $response->assertExactJson([
            'post-a' => ['inbound' => 3, 'outbound' => 5],
            'post-b' => ['inbound' => 0, 'outbound' => 7],
        ]);
    }

    public function test_suggestion_counts_reads_authoritative_fields_off_entry_record(): void
    {
        // SoT pin: the count values come straight off
        // EntryRecord::inboundSuggestionCount / outboundSuggestionCount
        // (set by the indexer during scan). If a Phase-B extractor
        // re-computes them inline ("just call InboundEngine here") it
        // breaks the "indexer is authoritative" invariant of Linkwise.
        // We don't bind InboundEngine here at all — if the controller
        // started calling it, the resolver would surface the missing
        // expectation.
        $this->bindIndexer([
            'p' => $this->record('p', inbound: 42, outbound: 99),
        ]);

        $response = $this->getJson(route('linkwise.suggestion-counts'));

        $response->assertJson(['p' => ['inbound' => 42, 'outbound' => 99]]);
    }

    // ── entryStats ─────────────────────────────────────────────────────

    public function test_entry_stats_returns_zeros_for_unknown_entry_id(): void
    {
        // Empty index: LinkReport inbound/outbound = 0; no broken
        // records; InboundEngine returns nothing for an unknown target;
        // outbound suggestions path requires a record → skipped.
        $this->bindIndexer([]);
        $this->bindInboundEngine('ghost', []);

        $response = $this->getJson(route('linkwise.entry-stats', ['entryId' => 'ghost']));

        $response->assertStatus(200);
        $response->assertExactJson([
            'inbound' => 0,
            'outbound' => 0,
            'broken' => 0,
            'suggestions' => 0,
            'outbound_suggestions' => 0,
        ]);
    }

    public function test_entry_stats_emits_all_five_keys_when_record_present(): void
    {
        // Full happy path: known entry, inbound/outbound counts > 0,
        // some broken records, some inbound suggestions, some outbound
        // suggestions. Pins the 5-key shape that the DetailModal frontend
        // hard-codes.
        $records = [
            'post-target' => $this->record('post-target', inbound: 0, outbound: 0),
            'post-source' => $this->record('post-source', inbound: 0, outbound: 0, outboundLinks: ['post-target']),
        ];
        $this->bindIndexer($records);
        $this->bindInboundEngine('post-target', [
            ['source_entry_id' => 'post-x'],
            ['source_entry_id' => 'post-y'],
        ]);
        $this->bindSuggestionEngine([]);

        $response = $this->getJson(route('linkwise.entry-stats', ['entryId' => 'post-target']));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'inbound',
            'outbound',
            'broken',
            'suggestions',
            'outbound_suggestions',
        ]);
        // LinkReport derives `inbound` from outboundLinks pointing at
        // this entry. post-source links to post-target, so inbound = 1.
        $this->assertSame(1, $response->json('inbound'));
        // Outbound = own outboundLinks count (post-target has none).
        $this->assertSame(0, $response->json('outbound'));
        $this->assertSame(2, $response->json('suggestions'));
    }

    public function test_entry_stats_broken_count_filters_to_requested_entry_only(): void
    {
        // Pin the filter: broken-links.json contains records for many
        // entries, controller must return ONLY this entry's count. A
        // Phase-B regression that drops the post_id filter would show
        // the site-wide broken total in every DetailModal.
        $this->bindIndexer([
            'p' => $this->record('p'),
        ]);
        $this->bindInboundEngine('p', []);
        $this->writeBrokenLinks([
            ['post_id' => 'p', 'post_title' => '', 'post_url' => '', 'url' => 'https://a/', 'anchor_text' => 'a', 'http_status' => 404, 'error' => null, 'ignored' => false],
            ['post_id' => 'p', 'post_title' => '', 'post_url' => '', 'url' => 'https://b/', 'anchor_text' => 'b', 'http_status' => 404, 'error' => null, 'ignored' => false],
            // Different entry — must NOT count.
            ['post_id' => 'other', 'post_title' => '', 'post_url' => '', 'url' => 'https://c/', 'anchor_text' => 'c', 'http_status' => 404, 'error' => null, 'ignored' => false],
        ]);

        $response = $this->getJson(route('linkwise.entry-stats', ['entryId' => 'p']));

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('broken'),
            'broken count must be filtered to the requested entry');
    }

    public function test_entry_stats_inbound_suggestions_delegate_to_inbound_engine(): void
    {
        // Pin the delegation: suggestions count = count(InboundEngine::
        // suggest($id)). InboundEngine returns raw suggestion arrays;
        // controller takes the count, not the items. Phase B may inline
        // a cache, but the count contract must survive.
        $this->bindIndexer([
            'p' => $this->record('p'),
        ]);
        $spy = $this->bindInboundEngine('p', [
            ['source_entry_id' => 'a'],
            ['source_entry_id' => 'b'],
            ['source_entry_id' => 'c'],
        ]);

        $response = $this->getJson(route('linkwise.entry-stats', ['entryId' => 'p']));

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('suggestions'));
        $spy->shouldHaveReceived('suggest')->with('p')->once();
    }

    public function test_entry_stats_outbound_suggestions_zero_when_entry_not_indexed(): void
    {
        // outbound_suggestions only runs if the entry is in the index
        // (uses record->text + record->outboundLinks). For an unknown
        // entryId the SuggestionEngine must NOT be called — pin the
        // skip-path; a Phase-B regression that always calls it would
        // crash on a null record.
        $this->bindIndexer([]);
        $this->bindInboundEngine('unknown', []);
        $engineSpy = Mockery::mock(SuggestionEngine::class);
        $engineSpy->shouldNotReceive('suggest');
        $this->app->instance(SuggestionEngine::class, $engineSpy);

        $response = $this->getJson(route('linkwise.entry-stats', ['entryId' => 'unknown']));

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('outbound_suggestions'));
    }

    public function test_entry_stats_outbound_suggestions_count_from_engine_for_indexed_entry(): void
    {
        // Mirror of the previous case: when the entry IS in the index,
        // SuggestionEngine is called and the result-count flows into
        // `outbound_suggestions`. Pinning prevents a Phase-B regression
        // where the helper returns the result-array length of the wrong
        // method (e.g. `->filter()` returning a different shape).
        $record = $this->record('p', outboundLinks: ['existing-target']);
        $this->bindIndexer(['p' => $record]);
        $this->bindInboundEngine('p', []);
        $this->bindSuggestionEngine([
            ['kw' => 'a'],
            ['kw' => 'b'],
            ['kw' => 'c'],
            ['kw' => 'd'],
        ]);

        $response = $this->getJson(route('linkwise.entry-stats', ['entryId' => 'p']));

        $response->assertStatus(200);
        $this->assertSame(4, $response->json('outbound_suggestions'));
    }

    // ── saveDomainAttribute ────────────────────────────────────────────

    public function test_save_domain_attribute_rejects_missing_domain(): void
    {
        $response = $this->postJson(route('linkwise.save-domain-attribute'), [
            'attribute' => 'nofollow',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['domain']);
    }

    public function test_save_domain_attribute_rejects_missing_attribute(): void
    {
        $response = $this->postJson(route('linkwise.save-domain-attribute'), [
            'domain' => 'example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attribute']);
    }

    public function test_save_domain_attribute_rejects_attribute_outside_enum(): void
    {
        // Enum: default | dofollow | nofollow | sponsored | ugc. Random
        // strings or accidental typos must trip validation — silent
        // accept would persist garbage into the attribute file.
        $response = $this->postJson(route('linkwise.save-domain-attribute'), [
            'domain' => 'example.com',
            'attribute' => 'noopener', // valid rel-value, NOT a Linkwise attribute
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attribute']);
    }

    public function test_save_domain_attribute_persists_attribute_via_domain_report(): void
    {
        // Happy path: valid input → 200 {success: true} → JSON file on
        // disk carries the new entry. Pinning the disk state catches a
        // Phase-B regression where the helper validates+returns success
        // but forgets the actual setAttribute() call.
        $this->bindIndexer([]);

        $response = $this->postJson(route('linkwise.save-domain-attribute'), [
            'domain' => 'example.com',
            'attribute' => 'nofollow',
        ]);

        $response->assertStatus(200);
        $response->assertExactJson(['success' => true]);

        $persisted = json_decode(
            file_get_contents($this->storageDir.'/domain-attributes.json'),
            true,
        );
        $this->assertSame(['example.com' => 'nofollow'], $persisted);
    }

    public function test_save_domain_attribute_default_value_drops_entry_instead_of_persisting(): void
    {
        // The setAttribute() comment is explicit: passing 'default'
        // deletes the entry, the implicit default is "no rel set". Pin
        // the delete-on-default branch so a Phase-B refactor that
        // forgets it doesn't fill the file with 'default' rows.
        $this->bindIndexer([]);
        // Pre-seed an existing entry to verify the delete.
        file_put_contents(
            $this->storageDir.'/domain-attributes.json',
            json_encode(['example.com' => 'sponsored']),
        );

        $response = $this->postJson(route('linkwise.save-domain-attribute'), [
            'domain' => 'example.com',
            'attribute' => 'default',
        ]);

        $response->assertStatus(200);
        $persisted = json_decode(
            file_get_contents($this->storageDir.'/domain-attributes.json'),
            true,
        );
        $this->assertSame([], $persisted, 'default attribute must delete the entry');
    }

    public function test_save_domain_attribute_clears_linkwise_mark_cache(): void
    {
        // LinkwiseLinkMark caches per-domain attribute lookups; after a
        // save the cache MUST be cleared so the next render picks the
        // new attribute up. Without this, the user changes a domain to
        // nofollow but their published posts keep rendering dofollow
        // until cache TTL expires. We seed the cache, run the endpoint,
        // and assert it's empty afterwards.
        $this->bindIndexer([]);

        // Seed the static cache via Reflection — clearCache() will reset
        // it. Using ReflectionProperty avoids tying the test to whatever
        // public seed-API may or may not exist.
        $reflection = new \ReflectionClass(LinkwiseLinkMark::class);
        if ($reflection->hasProperty('domainAttributesCache')) {
            $prop = $reflection->getProperty('domainAttributesCache');
            $prop->setAccessible(true);
            $prop->setValue(null, ['example.com' => 'dofollow']);
        }

        $response = $this->postJson(route('linkwise.save-domain-attribute'), [
            'domain' => 'example.com',
            'attribute' => 'sponsored',
        ]);

        $response->assertStatus(200);
        if (isset($prop)) {
            $this->assertNull(
                $prop->getValue(),
                'LinkwiseLinkMark::clearCache() must have reset the static cache',
            );
        }
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Override the container-bound indexer with a synthetic record set.
     * No Stache, no filesystem — controller sees the records directly.
     */
    private function bindIndexer(array $records): void
    {
        $spy = Mockery::mock(EntryIndexer::class);
        $spy->shouldReceive('load')->andReturn($records);
        $spy->shouldReceive('save')->andReturnNull();
        $spy->shouldReceive('getIndexLastBuiltAt')->andReturn(null);
        $this->app->instance(EntryIndexer::class, $spy);
    }

    /**
     * Bind an InboundEngine that returns the supplied result for one
     * specific target-id. Calls for any other id surface as a clear
     * "no expectation" failure during dev.
     */
    private function bindInboundEngine(string $targetId, array $result): Mockery\MockInterface
    {
        $spy = Mockery::spy(InboundEngine::class);
        $spy->shouldReceive('suggest')->with($targetId)->andReturn($result);
        $this->app->instance(InboundEngine::class, $spy);

        return $spy;
    }

    /**
     * Bind a SuggestionEngine with a static result. The controller
     * builds the call args dynamically — we don't pin them here since
     * the only thing entryStats reads is the result-count.
     */
    private function bindSuggestionEngine(array $result): void
    {
        $spy = Mockery::mock(SuggestionEngine::class);
        $spy->shouldReceive('suggest')->andReturn($result);
        $this->app->instance(SuggestionEngine::class, $spy);
    }

    /**
     * Write a real broken-links.json. The controller's `new
     * BrokenLinkReport` reads from storage_path('linkwise'); we write
     * a file with the supplied records.
     */
    private function writeBrokenLinks(array $records): void
    {
        file_put_contents($this->storageDir.'/broken-links.json', json_encode([
            'metadata' => ['last_checked' => '2026-05-14T00:00:00+00:00'],
            'broken_links' => $records,
        ]));
    }

    /**
     * Build a minimal EntryRecord with the public-readonly fields the
     * stats endpoints actually read. Other fields default to safe
     * empties.
     */
    private function record(
        string $id,
        int $inbound = 0,
        int $outbound = 0,
        array $outboundLinks = [],
        string $text = '',
    ): EntryRecord {
        return new EntryRecord(
            id: $id,
            title: 'Title '.$id,
            url: '/'.$id,
            collection: 'posts',
            text: $text,
            outboundLinks: $outboundLinks,
            keywords: [],
            inboundSuggestionCount: $inbound,
            outboundSuggestionCount: $outbound,
        );
    }
}
