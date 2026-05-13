<?php

namespace Arturrossbach\Linkwise\Tests\Feature\Dashboard;

use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Characterisation tests for the Bulk-Job-Status-Polling cluster of
 * DashboardController — pinned BEFORE the REV-DR-01 extraction
 * (Sprint 4 Part 5 / Phase A.2).
 *
 * Cluster scope: 4 background-job trios (rebuildIndex / checkLinks /
 * bulkUnlink / detailUnlinkAsync — each with dispatch + status + cancel)
 * plus the 4 special endpoints (bulkClear, bulkStatus,
 * inboundInsertCancel, outboundInsertCancel). Total 15 routes.
 *
 * Bug-density driven choice: ~4 bug-fix commits in 6 months — Bug 21
 * (5f73385 detached-exec PHP-Binary), Bug 9 (ccd4423 skip-vs-fail-fast
 * per-record conflicts), Sprint A persisted tokens (9673dd0 BulkUnlink
 * pre-flight hash check), anchor-fingerprint guard (2a15715
 * anchor_text validation rule).
 *
 * Test-stack notes:
 * - Cache-driven JobLock — `Cache::put('linkwise:<job>:status',
 *   ['phase' => 'running'])` simulates an active job.
 * - Dispatch-success paths (exec() detached subprocess) are NOT pinned
 *   here. The subprocess spawn is environment-coupled (php artisan path,
 *   shell, async log redirect) — Real-Flow-Verify (route:list + manual
 *   tinker) covers wiring. This suite pins everything that runs BEFORE
 *   exec(): JobLock gates, validation, hash pre-flight, cache-payload
 *   side-effects.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-01
 */
class BulkPollingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        // Stale cache between tests would leak active-job phases.
        Cache::flush();
    }

    /**
     * Extend the base side-load with `statamic.cp.linkwise.*` aliases
     * for the 8 cancel-routes that {@see DashboardController::bulkStatus()}
     * builds via {@see cp_route()}. Without these, the non-idle branch
     * of bulkStatus throws RouteNotFoundException (production routes
     * are prefixed `statamic.cp.linkwise.*`; our defineRoutes side-load
     * registers them unprefixed only).
     *
     * Phase B will drop most of these once the helper extraction moves
     * cp_route calls out of bulkStatus into a dedicated service method.
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $cpAliases = [
            'linkwise.rebuild-index.cancel',
            'linkwise.check-links.cancel',
            'linkwise.bulk-unlink.cancel',
            'linkwise.autolink.apply-async.cancel',
            'linkwise.url-changer.apply-cancel',
            'linkwise.detail-unlink.cancel',
            'linkwise.inbound.insert.cancel',
            'linkwise.outbound.insert.cancel',
        ];
        foreach ($cpAliases as $name) {
            $router->post('___test-stub/'.str_replace('.', '-', $name), fn () => '')
                ->name('statamic.cp.'.$name);
        }
    }

    // ── 409-Gates: dispatch must refuse while another bulk runs ────────

    public function test_check_links_returns_409_when_scan_job_active(): void
    {
        $this->simulateActiveJob('scan', startedBy: 'Anna');

        $response = $this->postJson(route('linkwise.check-links'));

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'busy',
            'active_job' => 'scan',
            'started_by' => 'Anna',
        ]);
        // Pin user-facing label in the busy message so accidental copy
        // edits in JobLock::buildBusyMessage trip a test.
        $this->assertStringContainsString('content scan', $response->json('message') ?? '');
    }

    public function test_rebuild_index_returns_409_when_check_job_active(): void
    {
        $this->simulateActiveJob('check', startedBy: 'Bob');

        $response = $this->postJson(route('linkwise.rebuild-index'));

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'busy',
            'active_job' => 'check',
            'started_by' => 'Bob',
        ]);
    }

    public function test_bulk_unlink_returns_409_when_scan_job_active(): void
    {
        // Pin: bulkUnlink dispatch refuses if ANY other job is active.
        // JobLock::activeJob('bulkunlink') excludes self, includes scan.
        $this->simulateActiveJob('scan');

        $response = $this->postJson(route('linkwise.bulk-unlink'), [
            'replacements' => [['entry_id' => 'e1', 'matched_url' => 'a', 'new_url' => 'b']],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'busy');
        $response->assertJsonPath('active_job', 'scan');
    }

    public function test_detail_unlink_async_returns_409_when_bulkunlink_job_active(): void
    {
        $this->simulateActiveJob('bulkunlink');

        $response = $this->postJson(route('linkwise.detail-unlink.async'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'a',
                'occurrence_index' => 0,
            ]],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('active_job', 'bulkunlink');
    }

    // ── Status endpoints: idle fallback when no cache key ──────────────

    public function test_check_links_status_returns_idle_fallback(): void
    {
        $response = $this->getJson(route('linkwise.check-links.status'));

        $response->assertStatus(200);
        $response->assertExactJson(['phase' => 'idle']);
    }

    public function test_rebuild_index_status_returns_idle_fallback(): void
    {
        $response = $this->getJson(route('linkwise.rebuild-index.status'));

        $response->assertStatus(200);
        $response->assertExactJson(['phase' => 'idle']);
    }

    public function test_bulk_unlink_status_returns_idle_fallback(): void
    {
        $response = $this->getJson(route('linkwise.bulk-unlink.status'));

        $response->assertStatus(200);
        $response->assertExactJson(['phase' => 'idle']);
    }

    public function test_detail_unlink_status_returns_idle_fallback(): void
    {
        $response = $this->getJson(route('linkwise.detail-unlink.status'));

        $response->assertStatus(200);
        $response->assertExactJson(['phase' => 'idle']);
    }

    public function test_status_endpoints_pass_through_cached_payload(): void
    {
        // Status endpoints are pure cache-read passthroughs. Pin: whatever
        // shape the command writes to `linkwise:<job>:status` (current,
        // total, phase, started_by, etc.) is returned verbatim — frontend
        // polling builds the progress UI from these keys.
        Cache::put('linkwise:scan:status', [
            'phase' => 'running',
            'current' => 42,
            'total' => 100,
            'started_by' => 'Carol',
        ], 600);

        $response = $this->getJson(route('linkwise.rebuild-index.status'));

        $response->assertExactJson([
            'phase' => 'running',
            'current' => 42,
            'total' => 100,
            'started_by' => 'Carol',
        ]);
    }

    // ── Cancel endpoints: lightweight cache-flag write pattern ─────────

    public function test_check_links_cancel_writes_cancel_flag_to_cache(): void
    {
        $this->postJson(route('linkwise.check-links.cancel'))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue(Cache::get('linkwise:check:cancel'),
            'cancel flag must be set so the in-flight worker picks it up at next item boundary');
    }

    public function test_rebuild_index_cancel_writes_cancel_flag_to_cache(): void
    {
        $this->postJson(route('linkwise.rebuild-index.cancel'))->assertStatus(200);

        $this->assertTrue(Cache::get('linkwise:scan:cancel'));
    }

    public function test_bulk_unlink_cancel_writes_cancel_flag_to_cache(): void
    {
        $this->postJson(route('linkwise.bulk-unlink.cancel'))->assertStatus(200);

        $this->assertTrue(Cache::get('linkwise:bulkunlink:cancel'));
    }

    public function test_detail_unlink_cancel_writes_cancel_flag_to_cache(): void
    {
        $this->postJson(route('linkwise.detail-unlink.cancel'))->assertStatus(200);

        $this->assertTrue(Cache::get('linkwise:detailunlink:cancel'));
    }

    public function test_inbound_insert_cancel_writes_cancel_flag_to_cache(): void
    {
        // The two insert-cancel endpoints (currently inside
        // DashboardController) belong semantically next to
        // InboundController::insert / OutboundController::insert. Pinning
        // them here so Phase B can move them out by ROUTE-NAME without
        // changing the contract.
        $this->postJson(route('linkwise.inbound.insert.cancel'))->assertStatus(200);

        $this->assertTrue(Cache::get('linkwise:inboundinsert:cancel'));
    }

    public function test_outbound_insert_cancel_writes_cancel_flag_to_cache(): void
    {
        $this->postJson(route('linkwise.outbound.insert.cancel'))->assertStatus(200);

        $this->assertTrue(Cache::get('linkwise:outboundinsert:cancel'));
    }

    // ── bulkUnlink — pre-flight hash check (Bug 9 / Sprint A) ──────────

    public function test_bulk_unlink_validates_replacements_required_min_1(): void
    {
        // Empty / missing replacements → 422 before any cache touch.
        $response = $this->postJson(route('linkwise.bulk-unlink'), [
            'replacements' => [],
        ]);

        $response->assertStatus(422);
        $this->assertNull(Cache::get('linkwise:bulkunlink:payload'));
    }

    public function test_bulk_unlink_validates_anchor_text_max_length(): void
    {
        // Commit 2a15715 ("Anchor-fingerprint guard: prevent silent wrong-
        // link unlink"). Without anchor_text on the validation rule list,
        // Laravel strips it from $validated, the cache-payload doesn't
        // carry it, BulkUnlinkCommand can't enforce the guard, and the
        // system silently unlinks the wrong link with the same URL.
        // Pin: anchor_text capped at 512 chars (validation rule presence
        // is the contract — the cap protects against log-flooding).
        $response = $this->postJson(route('linkwise.bulk-unlink'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'a',
                'new_url' => 'b',
                'anchor_text' => str_repeat('x', 513),
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['replacements.0.anchor_text']);
    }

    public function test_bulk_unlink_pre_flight_skips_conflict_check_for_missing_entries(): void
    {
        // Empirical pin (status quo, not the spec we'd wish for):
        // SafeEntrySaver::verifyHashes returns conflicts only when
        // `Entry::find() && hash !== expected`. A deleted / missing
        // entry → no conflict reported, dispatch proceeds. The
        // per-record verifyHashes inside BulkUnlinkCommand catches it
        // again at write-time; the pre-flight is a fast-path heuristic.
        //
        // Coverage gap pinned in Phase B's TODO: the proper 409-mismatch
        // branch (entry exists in Stache, hash differs) needs a
        // hash-mock or fixture-entry — out of scope for this test stack,
        // covered indirectly by per-record verifyHashes in
        // BulkCommandHashGuardTest.
        $response = $this->postJson(route('linkwise.bulk-unlink'), [
            'replacements' => [[
                'entry_id' => 'nonexistent-uuid',
                'matched_url' => 'https://example.com/old',
                'new_url' => 'https://example.com/new',
            ]],
            'entry_hashes' => [
                'nonexistent-uuid' => 'sha256-of-something-the-test-env-cannot-match',
            ],
        ]);

        $response->assertStatus(200);
        // Dispatch proceeded past pre-flight — cache shows starting.
        $status = Cache::get('linkwise:bulkunlink:status');
        $this->assertSame('starting', $status['phase'] ?? null);
    }

    public function test_bulk_unlink_skips_hash_pre_flight_when_no_hashes_shipped(): void
    {
        // Legacy frontend / scripted callers don't send entry_hashes.
        // Pin: controller proceeds past the hash-gate (status cache gets
        // 'starting'). We don't pin the exec() path itself — the
        // pre-cache mutation is the observable contract.
        $this->postJson(route('linkwise.bulk-unlink'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'a',
                'new_url' => 'b',
                // No 'entry_hashes' top-level key → pre-flight skipped.
            ]],
        ]);

        $status = Cache::get('linkwise:bulkunlink:status');
        $this->assertIsArray($status);
        $this->assertSame('starting', $status['phase']);
        $this->assertSame(1, $status['total']);
    }

    public function test_bulk_unlink_persists_validated_payload_to_cache_before_exec(): void
    {
        // The detached worker reads its job from
        // `linkwise:bulkunlink:payload`. Pin: the cache write happens
        // before exec() spawns, with the validated shape + the
        // started_by/started_by_id injected from auth().
        $this->postJson(route('linkwise.bulk-unlink'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'https://example.com/a',
                'new_url' => 'https://example.com/b',
                'anchor_text' => 'click here',
            ]],
        ]);

        $payload = Cache::get('linkwise:bulkunlink:payload');
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload['replacements']);
        $this->assertSame('click here', $payload['replacements'][0]['anchor_text']);
        $this->assertArrayHasKey('started_by', $payload);
        $this->assertArrayHasKey('started_by_id', $payload);
    }

    // ── bulkStatus aggregation: non-idle paths ─────────────────────────

    public function test_bulk_status_aggregates_active_job_from_joblock_snapshot(): void
    {
        // bulkStatus reads JobLock::snapshot() — when ANY job has an
        // active phase the response shape carries kind/label/phase/
        // current/total + cancel_url + terminal flag + extra (full
        // status forwarded). Frontend's LinkwiseLayout renders the
        // global progress banner from this shape.
        Cache::put('linkwise:scan:status', [
            'phase' => 'running',
            'current' => 7,
            'total' => 42,
            'started_by' => 'Dana',
        ], 600);

        $response = $this->getJson(route('linkwise.bulk-status'));

        $response->assertStatus(200);
        $response->assertJson([
            'kind' => 'scan',
            'label' => 'content scan',
            'phase' => 'running',
            'current' => 7,
            'total' => 42,
            'terminal' => false,
        ]);
        $this->assertArrayHasKey('cancel_url', $response->json());
        $this->assertArrayHasKey('extra', $response->json());
        $this->assertSame('Dana', $response->json('extra.started_by'));
    }

    public function test_bulk_status_falls_back_to_latest_terminal_when_no_job_active(): void
    {
        // Per JobLock::snapshot(): if no active job, fall back to the
        // most-recent TERMINAL phase (done/cancelled/error). Frontend
        // fires the completion toast from this snapshot, then clears
        // the banner.
        Cache::put('linkwise:check:status', [
            'phase' => 'done',
            'heartbeat' => time(),
            'current' => 100,
            'total' => 100,
            'message' => 'Check complete',
        ], 600);

        $response = $this->getJson(route('linkwise.bulk-status'));

        $response->assertStatus(200);
        $response->assertJsonPath('kind', 'check');
        $response->assertJsonPath('phase', 'done');
        $response->assertJsonPath('terminal', true);
    }

    // ── detail-unlink dispatch validation (parallel to bulkUnlink) ─────

    public function test_detail_unlink_async_validates_occurrence_index_required(): void
    {
        // occurrence_index is REQUIRED on detail-unlink (vs. nullable on
        // bulk-unlink) because the detail-modal flow always carries it
        // — used by DetailUnlinkCommand to locate the specific link
        // among multiple matches in the same entry. Missing it would
        // make the command guess (= silent wrong-unlink risk, same class
        // as Bug 2026-05-09 anchor-fingerprint).
        $response = $this->postJson(route('linkwise.detail-unlink.async'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'https://example.com',
                // no occurrence_index
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['replacements.0.occurrence_index']);
    }

    public function test_detail_unlink_async_persists_anchor_and_sentence_context_to_payload(): void
    {
        // Commit 3d56a11 ("Activity Log: sentence_context per item")
        // wired anchor + sentence through to the snapshot so the
        // drawer's Context column shows the editor's view at unlink-
        // time. Drift here = Context column reverts to "—". Pin both
        // fields on the cached payload — that's where the command picks
        // them up.
        $this->postJson(route('linkwise.detail-unlink.async'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'https://example.com',
                'occurrence_index' => 0,
                'anchor_text' => 'click here',
                'sentence_context' => 'Read more in our guide: click here for details.',
            ]],
            'source_mode' => 'inbound',
            'entry_title' => 'My Article',
        ])->assertStatus(200);

        $payload = Cache::get('linkwise:detailunlink:payload');
        $this->assertIsArray($payload);
        $this->assertSame('click here', $payload['replacements'][0]['anchor_text']);
        $this->assertSame(
            'Read more in our guide: click here for details.',
            $payload['replacements'][0]['sentence_context'],
        );
        $this->assertSame('inbound', $payload['source_mode']);
        $this->assertSame('My Article', $payload['entry_title']);
    }

    public function test_detail_unlink_async_carries_reverts_lineage_pointer(): void
    {
        // Activity-Log Revert flow passes `reverts` so the new snapshot
        // is marked as a reverse-of-X. Drift = activity-log lineage
        // (reverted_from descriptor) breaks. Pinned alongside
        // ActivityDetailTest's resolves_reverted_from coverage.
        $this->postJson(route('linkwise.detail-unlink.async'), [
            'replacements' => [[
                'entry_id' => 'e1',
                'matched_url' => 'https://example.com',
                'occurrence_index' => 0,
            ]],
            'reverts' => 'original-snap-id',
        ])->assertStatus(200);

        $payload = Cache::get('linkwise:detailunlink:payload');
        $this->assertSame('original-snap-id', $payload['reverts']);
    }

    // ── bulkClear: forceClear bridge to JobLock ────────────────────────

    public function test_bulk_clear_forwards_kind_to_joblock_and_clears_cache_keys(): void
    {
        // JobLock::forceClear($kind) wipes status + payload + cancel
        // keys for that job. The "stuck operation" UI calls bulkClear
        // when the shutdown crash-guard somehow missed (server restart
        // before shutdown_function fires).
        Cache::put('linkwise:scan:status', ['phase' => 'running'], 600);
        Cache::put('linkwise:scan:payload', ['some' => 'data'], 600);
        Cache::put('linkwise:scan:cancel', true, 60);

        $response = $this->postJson(route('linkwise.bulk-clear', ['kind' => 'scan']));

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'cleared' => 'scan']);
        $this->assertNull(Cache::get('linkwise:scan:status'),
            'status key must be cleared');
        $this->assertNull(Cache::get('linkwise:scan:payload'),
            'payload key must be cleared');
        $this->assertNull(Cache::get('linkwise:scan:cancel'),
            'cancel key must be cleared');
    }

    public function test_bulk_clear_with_unknown_kind_is_noop(): void
    {
        // Defensive — forceClear silently ignores unknown kinds (no
        // JOBS entry). Pin: response is still success=true (frontend
        // can call without knowing the canonical list), no side
        // effects elsewhere.
        Cache::put('linkwise:scan:status', ['phase' => 'running'], 600);

        $response = $this->postJson(route('linkwise.bulk-clear', ['kind' => 'unknown-kind']));

        $response->assertStatus(200);
        $this->assertNotNull(Cache::get('linkwise:scan:status'),
            'Unrelated job state must NOT be touched by unknown-kind clear');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Seed a cached status for the named job so {@see
     * \Arturrossbach\Linkwise\Support\JobLock::activeJob()} picks it up.
     *
     * Phase 'running' is one of ACTIVE_PHASES — the others ('starting',
     * 'indexing', 'suggestions', 'saving', 'checking') route through the
     * same gate, so picking 'running' is sufficient for the 409-pin.
     */
    private function simulateActiveJob(string $name, ?string $startedBy = null): void
    {
        $key = \Arturrossbach\Linkwise\Support\JobLock::JOBS[$name]['key'];
        $payload = ['phase' => 'running', 'current' => 1, 'total' => 10];
        if ($startedBy !== null) {
            $payload['started_by'] = $startedBy;
        }
        Cache::put($key, $payload, 600);
    }
}
