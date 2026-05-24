<?php

namespace Arturrossbach\Linkwise\Tests\Feature\Dashboard;

use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;

/**
 * Characterisation tests for the Activity-Detail-API cluster of
 * DashboardController — pinned BEFORE the REV-DR-01 extraction (Sprint 4
 * Part 5, V1-Refactor-Roadmap).
 *
 * Cluster scope: GET `linkwise/activity/{id}` ({@see
 * DashboardController::activityDetail}) + POST
 * `linkwise/activity/{id}/mark-reverted` ({@see
 * DashboardController::markActivityReverted}). Bug-density driven choice:
 * ~8 bug-fix commits in 6 months touched this cluster (revert-gating,
 * sentence_context, target-entry-title resolution, per-entry skip
 * visibility, hash pre-flight).
 *
 * Test stack reused from REV-AL-01 (see feature_test_stack memory):
 * - `defineRoutes` side-load in {@see TestCase::defineRoutes()}.
 * - `withoutMiddleware()` to bypass `can:manage linkwise` gate.
 * - Mockery spies on {@see BulkSnapshotStore} — the only collaborator
 *   touched by both methods. Entry::find lookups stay un-mocked: real
 *   Stache returns null for synthetic IDs and the controller's try/catch
 *   gracefully falls back to entry-ID-as-title. We pin that fallback path
 *   as the test contract rather than booting Stache fixtures.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-01
 */
class ActivityDetailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    /**
     * Extend the base {@see TestCase::defineRoutes()} side-load with a
     * stub for the production-prefixed route name `statamic.cp.linkwise.
     * urlchanger` so {@see Statamic::cpRoute()} (which prepends `statamic.
     * cp.`) resolves without throwing. Our routes/cp.php registers the
     * unprefixed `linkwise.urlchanger` only — production's CP-prefix gets
     * added by Statamic::additionalCpRoutes() which doesn't boot in
     * Orchestra Testbench.
     *
     * Scoped to this Test-Class so other Feature-Suites aren't affected.
     * Phase B extraction won't need this — `cp_route` calls stay in the
     * controller layer; the helper becomes pure.
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        $router->get('___test-stub/url-changer', fn () => '')
            ->name('statamic.cp.linkwise.urlchanger');
    }

    public function test_activity_detail_returns_404_when_snapshot_id_unknown(): void
    {
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn(null);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-does-not-exist']));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Snapshot not found']);
    }

    public function test_activity_detail_returns_top_level_shape_with_snapshot_and_entries(): void
    {
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->with('snap-1')->andReturn($this->baseSnapshot([
            'entry_ids' => ['entry-a', 'entry-b'],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([
            'entry-a' => 'unchanged',
            'entry-b' => 'modified',
        ]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $response->assertStatus(200);
        // Top-level shape pin — drawer reads these keys directly. Renames
        // without frontend coordination break the activity-log UI.
        $response->assertJsonStructure([
            'snapshot',
            'entries',
            'deep_link_url_changer',
            'reverted_by_user',
            'reverted_from',
        ]);
        $this->assertCount(2, $response->json('entries'));
    }

    public function test_activity_detail_groups_items_by_entry_id_with_source_entry_id_fallback(): void
    {
        // Link-insert items key off `source_entry_id` (the entry being
        // modified), other kinds use `entry_id`. The fallback chain pins
        // the per-item-operation contract (commit 6839eb8 — "per-item
        // operation data + URL Changer deep-link").
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'entry_ids' => ['entry-a'],
            'items' => [
                ['entry_id' => 'entry-a', 'anchor' => 'foo'],
                ['source_entry_id' => 'entry-a', 'anchor' => 'bar'],
            ],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn(['entry-a' => 'unchanged']);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $items = $response->json('entries.0.items');
        $this->assertCount(2, $items, 'Both entry_id-keyed and source_entry_id-keyed items must land under entry-a');
    }

    public function test_activity_detail_skips_items_without_entry_id_or_source_entry_id(): void
    {
        // Multi-rule applyrule items have `rule_id` but no entry key —
        // they're listed separately by the drawer, not under any entry.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'entry_ids' => ['entry-a'],
            'items' => [
                ['entry_id' => 'entry-a', 'anchor' => 'foo'],
                ['rule_id' => 'rule-x', 'matched_keyword' => 'baz'], // no entry_id
            ],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn(['entry-a' => 'unchanged']);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $this->assertCount(1, $response->json('entries.0.items'),
            'Items without entry_id/source_entry_id must NOT appear under an entry.');
    }

    public function test_activity_detail_skips_non_array_items_defensively(): void
    {
        // Defensive — corrupted/legacy snapshots may contain scalar items.
        // Controller filters with `! is_array($item)` to avoid PHP fatals.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'entry_ids' => ['entry-a'],
            'items' => [
                'corrupted-scalar',
                ['entry_id' => 'entry-a', 'anchor' => 'foo'],
                null,
            ],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn(['entry-a' => 'unchanged']);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('entries.0.items'));
    }

    public function test_activity_detail_propagates_compareToCurrent_status_per_entry(): void
    {
        // The drawer renders "5 of 80 entries were edited since the bulk"
        // — that count comes from per-entry status. compareToCurrent is the
        // authoritative source, controller forwards verbatim.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'entry_ids' => ['e-1', 'e-2', 'e-3'],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([
            'e-1' => 'unchanged',
            'e-2' => 'modified',
            'e-3' => 'deleted',
        ]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $statuses = collect($response->json('entries'))->pluck('status', 'id')->all();
        $this->assertSame([
            'e-1' => 'unchanged',
            'e-2' => 'modified',
            'e-3' => 'deleted',
        ], $statuses);
    }

    public function test_activity_detail_falls_back_to_deleted_label_when_entry_lookup_fails(): void
    {
        // Synthetic IDs aren't in the Stache → `Entry::find()` returns null.
        // Post-2026-05-24 (user-bug from multilang smoke): the controller no
        // longer falls back to the raw UUID — it surfaces a literal
        // '(deleted entry)' label + is_deleted=true flag so the drawer can
        // render a muted "deleted" badge instead of an opaque hash. The
        // OPTIONAL snapshot-stored title path is exercised in the next test;
        // this test pins the empty-snapshot case.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'entry_ids' => ['nonexistent-entry-uuid'],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn(['nonexistent-entry-uuid' => 'deleted']);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $entry = $response->json('entries.0');
        $this->assertSame('nonexistent-entry-uuid', $entry['id']);
        $this->assertSame('(deleted entry)', $entry['title'], 'Fallback title must be a human-readable label, not the raw UUID');
        $this->assertTrue($entry['is_deleted'], 'is_deleted flag must be true for unresolvable entries');
        $this->assertNull($entry['edit_url'], 'edit_url must stay null when entry resolution fails');
        $this->assertNull($entry['collection']);
    }

    public function test_activity_detail_resolves_reverted_by_user_from_reverter_snapshot(): void
    {
        // When the original snapshot was reverted, drawer shows "Already
        // reverted on DATE by NAME". NAME comes from the *reverter
        // snapshot's* started_by (the user who clicked Revert), not the
        // original snapshot's started_by.
        $original = $this->baseSnapshot([
            'reverted_at' => '2026-04-01 12:00:00',
            'reverted_by' => 'revert-snap-id',
        ]);
        $reverterSnap = $this->baseSnapshot([
            'id' => 'revert-snap-id',
            'started_by' => 'Bob the Reverter',
        ]);

        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->with('snap-1')->andReturn($original);
        $spy->shouldReceive('get')->with('revert-snap-id')->andReturn($reverterSnap);
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $this->assertSame('Bob the Reverter', $response->json('reverted_by_user'));
    }

    public function test_activity_detail_resolves_reverted_from_descriptor_when_summary_reverts_set(): void
    {
        // When THIS snapshot is itself a revert, drawer shows "↶ Reverts
        // Apply Rule 'X' from <date>" up top. Lineage comes from summary.reverts
        // pointer (commit 1fc0b7d — re-link snapshot kind + symmetric revert).
        $original = $this->baseSnapshot([
            'id' => 'original-snap',
            'kind' => 'applyrule',
            'started_at' => '2026-03-15 09:00:00',
            'started_by' => 'Alice',
            'summary' => ['rule_id' => 'rule-42'],
        ]);
        $revertSnap = $this->baseSnapshot([
            'kind' => 'detailunlink',
            'summary' => ['reverts' => 'original-snap'],
        ]);

        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->with('snap-1')->andReturn($revertSnap);
        $spy->shouldReceive('get')->with('original-snap')->andReturn($original);
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $revertedFrom = $response->json('reverted_from');
        $this->assertSame('original-snap', $revertedFrom['id']);
        $this->assertSame('applyrule', $revertedFrom['kind']);
        $this->assertSame('Alice', $revertedFrom['started_by']);
        $this->assertSame(['rule_id' => 'rule-42'], $revertedFrom['summary']);
    }

    public function test_activity_detail_returns_null_reverted_from_when_original_missing(): void
    {
        // Original snapshot may have been deleted (rotation, manual cleanup).
        // Defensive: reverted_from is null, the drawer hides the lineage line
        // — no fatal, no broken descriptor.
        $revertSnap = $this->baseSnapshot([
            'summary' => ['reverts' => 'deleted-original'],
        ]);

        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->with('snap-1')->andReturn($revertSnap);
        $spy->shouldReceive('get')->with('deleted-original')->andReturn(null);
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $this->assertNull($response->json('reverted_from'));
    }

    public function test_activity_detail_preserves_revert_skipped_records_through_enrichment(): void
    {
        // Per-entry skip visibility is the bug-fix payload of commit 543202c
        // ("record per-entry skips during bulk runs"). The drawer's
        // skipped-entries table reads `revert_skipped[*]` directly.
        // Best-effort Entry::find adds edit_url/collection; failure leaves
        // the row as persisted. We pin: original fields survive enrichment.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'revert_skipped' => [
                [
                    'entry_id' => 'entry-skipped',
                    'entry_title' => 'Old Title at Skip Time',
                    'modified_by' => 'Other User',
                    'modified_at' => '2026-04-01 10:00:00',
                ],
            ],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $skip = $response->json('snapshot.revert_skipped.0');
        // Original fields preserved verbatim — enrichment only ADDS fields,
        // never overwrites entry_title or modified_by/_at.
        $this->assertSame('entry-skipped', $skip['entry_id']);
        $this->assertSame('Old Title at Skip Time', $skip['entry_title']);
        $this->assertSame('Other User', $skip['modified_by']);
    }

    public function test_activity_detail_preserves_bulk_skipped_records_through_enrichment(): void
    {
        // bulk_skipped is a PARALLEL enrichment loop to revert_skipped
        // (DashboardController:659-676 vs 635-652) — separate bug-line:
        // commit ccd4423 (Bug 9 "bulk endpoints skip conflicts per-record")
        // + 543202c ("per-entry skips during bulk runs"). Drift between
        // the two loops during Phase-B extraction would be silent. Pin
        // each loop independently.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'bulk_skipped' => [
                [
                    'entry_id' => 'entry-stuck',
                    'entry_title' => 'Article with anchor conflict',
                    'reason' => 'anchor_not_found',
                    'modified_at' => '2026-04-01 11:30:00',
                ],
            ],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $skip = $response->json('snapshot.bulk_skipped.0');
        $this->assertSame('entry-stuck', $skip['entry_id']);
        $this->assertSame('Article with anchor conflict', $skip['entry_title']);
        $this->assertSame('anchor_not_found', $skip['reason']);
    }

    public function test_activity_detail_does_not_corrupt_top_level_snapshot_items_during_enrichment(): void
    {
        // Top-level snap.items is enriched in a SEPARATE loop (lines 595-627)
        // from the per-entry items loop (lines 550-578). The drawer's
        // `operationSummary` reads snap.items directly. Pin: enrichment
        // adds *_title/*_edit_url fields when resolvable, leaves originals
        // intact when Entry::find misses, never mutates the existing keys
        // and never crashes on the statamic://entry::UUID pattern.
        //
        // Note: kind=inboundinsert keeps deepLinkSearchFor on the null path
        // — cp_route would otherwise fire and the test-env routing quirk
        // (unprefixed `linkwise.*` vs production's `statamic.cp.linkwise.*`)
        // would throw. The kind-switch itself is pinned in the
        // ActivityDeepLinkSearchTest unit suite.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'kind' => 'inboundinsert',
            'items' => [
                [
                    'entry_id' => 'entry-a',
                    'anchor' => 'click here',
                    'url' => 'statamic://entry::aaa-bbb-ccc',
                    'new_url' => 'https://example.com/external',
                ],
            ],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $response->assertStatus(200);
        $topItem = $response->json('snapshot.items.0');
        $this->assertSame('entry-a', $topItem['entry_id']);
        $this->assertSame('click here', $topItem['anchor']);
        $this->assertSame('statamic://entry::aaa-bbb-ccc', $topItem['url']);
        $this->assertSame('https://example.com/external', $topItem['new_url']);
        // Enrichment fields are absent when Entry::find misses (synthetic
        // UUIDs aren't in the Stache). The fields would appear under
        // `url_title` / `url_edit_url` etc. if resolution succeeded —
        // pinning their conditional appearance proves the loop scans the
        // configured field list ['url', 'matched_url', 'new_url',
        // 'target_entry_id'] without crashing.
        $this->assertArrayNotHasKey('url_title', $topItem);
    }

    public function test_activity_detail_deep_link_is_null_for_kinds_without_search_term(): void
    {
        // inboundinsert + outboundinsert + multi-rule applyrule explicitly
        // return null from deepLinkSearchFor — no single URL across items.
        // Pin: drawer's "Find these in URL Changer" button stays hidden.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'kind' => 'inboundinsert',
            'items' => [['entry_id' => 'e-1', 'url' => 'https://example.com']],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $this->assertNull($response->json('deep_link_url_changer'));
    }

    public function test_activity_detail_wraps_non_null_deep_link_search_into_url_changer_route(): void
    {
        // Pin the wrap contract: when deepLinkSearchFor returns non-null,
        // the controller emits `cp_route('linkwise.urlchanger').'?search='.
        // urlencode($term)`. Phase B extraction may move the helper into
        // a service — the wrap-code in the controller's JSON response
        // must keep building the same URL shape.
        //
        // Note: the `statamic.cp.linkwise.urlchanger` alias is registered
        // in this class's defineRoutes() override (the test-routes file
        // only side-loads unprefixed names; production's CP-prefix layer
        // doesn't boot in Orchestra Testbench).
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn($this->baseSnapshot([
            'kind' => 'urlchanger',
            'summary' => ['search' => '/old-blog?id=42'],
        ]));
        $spy->shouldReceive('compareToCurrent')->andReturn([]);

        $response = $this->getJson(route('linkwise.activity.detail', ['id' => 'snap-1']));

        $deepLink = $response->json('deep_link_url_changer');
        $this->assertNotNull($deepLink, 'Non-null helper return must emit a deep-link URL');
        $this->assertStringContainsString('?search='.urlencode('/old-blog?id=42'), $deepLink,
            'Search term must be urlencoded and appended as ?search=');
    }

    public function test_mark_reverted_returns_404_when_snapshot_id_unknown(): void
    {
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn(null);

        $response = $this->postJson(
            route('linkwise.activity.mark-reverted', ['id' => 'snap-missing']),
            [],
        );

        $response->assertStatus(404);
        $response->assertJson(['error' => 'snapshot_not_found']);
    }

    public function test_mark_reverted_returns_409_in_progress_when_completed_at_explicit_null(): void
    {
        // Defense-in-depth gate added in commit 37c0dde ("gate Revert behind
        // completed_at — no racing in-flight bulks"). Frontend already
        // disables Revert for in-flight, this gate catches malicious /
        // racing clients.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn([
            'id' => 'snap-1',
            'completed_at' => null, // EXPLICIT null = still running
        ]);

        $response = $this->postJson(
            route('linkwise.activity.mark-reverted', ['id' => 'snap-1']),
            ['reverted_by' => 'revert-snap-id'],
        );

        $response->assertStatus(409);
        $response->assertJson(['error' => 'in_progress']);
        $this->assertStringContainsString('still running', $response->json('message') ?? '');
        // Pin negative: store must NOT be mutated when the gate trips.
        $spy->shouldNotHaveReceived('markReverted');
    }

    public function test_mark_reverted_treats_legacy_snapshot_without_completed_at_as_completed(): void
    {
        // Legacy snapshots from before completed_at shipped don't have the
        // key at all. They're old, ergo done. Distinguishing missing-key
        // from explicit-null avoids breaking the Revert button on historic
        // log entries.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn([
            'id' => 'legacy-snap',
            'started_at' => '2025-12-01 09:00:00',
            // NO completed_at key at all
        ]);
        $spy->shouldReceive('markReverted')->andReturnNull();

        $response = $this->postJson(
            route('linkwise.activity.mark-reverted', ['id' => 'legacy-snap']),
            ['reverted_by' => 'revert-snap-id'],
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_mark_reverted_forwards_reverted_by_payload_to_store(): void
    {
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn([
            'id' => 'snap-1',
            'completed_at' => '2026-04-01 12:00:00',
        ]);
        $spy->shouldReceive('markReverted')->andReturnNull();

        $this->postJson(
            route('linkwise.activity.mark-reverted', ['id' => 'snap-1']),
            ['reverted_by' => 'revert-snap-id-abc'],
        )->assertStatus(200);

        $spy->shouldHaveReceived('markReverted')
            ->with('snap-1', 'revert-snap-id-abc')
            ->once();
    }

    public function test_mark_reverted_validates_reverted_by_max_length(): void
    {
        // Cap from commit 29850df ("Cap unbounded validation rules across
        // all Linkwise controllers") — max:128 prevents log-flooding via
        // oversized identifier strings.
        $spy = $this->bindSnapshotSpy();
        $spy->shouldReceive('get')->andReturn([
            'id' => 'snap-1',
            'completed_at' => '2026-04-01 12:00:00',
        ]);

        $response = $this->postJson(
            route('linkwise.activity.mark-reverted', ['id' => 'snap-1']),
            ['reverted_by' => str_repeat('x', 129)],
        );

        $response->assertStatus(422);
        $spy->shouldNotHaveReceived('markReverted');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Build a minimal snapshot dict with sensible defaults. Tests override
     * only the fields they care about — keeps each test's intent visible.
     */
    private function baseSnapshot(array $overrides = []): array
    {
        return array_merge([
            'id' => 'snap-1',
            'kind' => 'applyrule',
            'started_by' => 'Test User',
            'started_at' => '2026-04-01 12:00:00',
            'completed_at' => '2026-04-01 12:05:00',
            'entry_count_total' => 0,
            'entry_ids' => [],
            'items' => [],
            'summary' => [],
            'reverted_at' => null,
            'reverted_by' => null,
            'revert_skipped' => [],
            'bulk_skipped' => [],
        ], $overrides);
    }

    // ── infra ──────────────────────────────────────────────────────────

    /**
     * Replace {@see BulkSnapshotStore} singleton with a spy. Individual
     * tests append `shouldReceive('get')->andReturn(...)` for the
     * scenarios they pin.
     */
    private function bindSnapshotSpy(): Mockery\MockInterface
    {
        $spy = Mockery::spy(BulkSnapshotStore::class);
        $this->app->instance(BulkSnapshotStore::class, $spy);

        return $spy;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
