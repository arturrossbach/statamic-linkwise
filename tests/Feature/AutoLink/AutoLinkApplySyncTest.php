<?php

namespace Arturrossbach\Linkwise\Tests\Feature\AutoLink;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\AutoLink\AutoLinkRule;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * Characterisation tests for the SYNC apply endpoint of AutoLinkController.
 *
 * Pins HTTP-level behaviour BEFORE the Apply-Sync extraction (REV-AL-01,
 * Sprint 4 Part 4). These tests must stay green after the controller is
 * split into a sub-controller.
 *
 * Smoke-first strategy: starts with the 404 path so we learn early if the
 * test-env can boot Statamic routes + bypass the `can:manage linkwise`
 * gate at all. Additional tests get layered on once the scaffold works.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-AL-01
 */
class AutoLinkApplySyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bypass the `can:manage linkwise` gate + Statamic CP-auth middleware.
        // We're pinning controller behaviour, not auth. Auth concerns get their
        // own test if/when needed.
        $this->withoutMiddleware();
    }

    public function test_apply_returns_404_when_rule_id_is_unknown(): void
    {
        $response = $this->postJson(
            route('linkwise.autolink.apply', ['id' => 'rule-that-does-not-exist']),
            ['preview' => true],
        );

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Rule not found']);
    }

    public function test_non_preview_apply_returns_409_when_another_bulk_job_is_active(): void
    {
        // Seed a rule so the controller passes the 404-gate and reaches
        // the JobLock-check (which is what this test pins).
        $rule = $this->seedRule();

        // Fake an active "scan" job — JobLock::activeJob() reads this cache
        // key and reports any phase in ACTIVE_PHASES (`'running'` is one).
        Cache::put('linkwise:scan:status', [
            'phase' => 'running',
            'started_by' => 'Anna',
            'started_by_id' => 'user-123',
            'current' => 7,
            'total' => 42,
        ], 600);

        $response = $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => false],
        );

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'busy',
            'active_job' => 'scan',
            'started_by' => 'Anna',
        ]);
        // Pin that the user-facing message mentions the busy label, so
        // accidental copy edits in JobLock::buildBusyMessage don't slip past.
        $this->assertStringContainsString(
            'content scan',
            $response->json('message') ?? '',
        );
    }

    public function test_preview_apply_bypasses_job_lock_gate(): void
    {
        // Preview is read-only — REV-BJ-03 deliberately exempts it from the
        // global lock so editors can re-preview while another bulk runs.
        // This test pins that exemption: even with an active scan, preview
        // must NOT 409 on the lock-check. (It may fail downstream for
        // unrelated reasons in this minimal env — that's why we only assert
        // the *negative*: status is not 409 with error=busy.)
        $rule = $this->seedRule();
        Cache::put('linkwise:scan:status', ['phase' => 'running'], 600);

        $response = $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => true],
        );

        // Either 200 (applier ran cleanly) or 500 (env limitation downstream)
        // is acceptable here — what matters is the lock-check did NOT fire.
        $this->assertNotSame(409, $response->status(),
            'Preview must bypass JobLock — got 409 with body: '.$response->getContent());
    }

    public function test_non_preview_records_snapshot_with_applyrule_kind_and_rule_summary(): void
    {
        $rule = $this->seedRule();
        $this->stubEmptyIndexer();
        $snapshotSpy = $this->bindSnapshotSpy();

        $response = $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => false],
        );

        $response->assertStatus(200);
        $snapshotSpy->shouldHaveReceived('record')
            ->withArgs(function (...$args) use ($rule) {
                // record() uses named-args in production code; Mockery still
                // sees them positionally as the call leaves the controller.
                // Pin kind + rule-summary forensic fields — the rest (preHashes,
                // entryIds, items) varies with the empty-index path.
                [$kind, $entryIds, $preHashes, $summary] = $args;
                return $kind === 'applyrule'
                    && $summary['rule_id'] === $rule->id
                    && $summary['rule_keyword'] === $rule->keyword
                    && $summary['caller'] === 'sync';
            })
            ->once();
    }

    public function test_non_preview_with_zero_affected_entries_skips_completion_block(): void
    {
        // Empirical pin (not the spec we wish for): when the preview-for-
        // snapshot finds zero affected entries, the controller's completion
        // block (line 460-477) is short-circuited by `! empty($snapshotEntryIds)`.
        // `record()` already wrote the snapshot, but `markCompleted` and
        // `recordPostHashesForEntries` are NEVER called. Result: an orphan
        // snapshot file with `completed_at = null` — invisible to the activity
        // log's Revert button (which hides in-flight snapshots).
        //
        // Documented as a latent bug-smell, not a desired behaviour. A future
        // fix should either (a) not record a snapshot at all when the preview
        // finds nothing, or (b) call markCompleted with links_added=0. Both
        // are out-of-scope for REV-AL-01; this test pins the status quo so the
        // Apply-Sync extraction stays behaviour-preserving.
        $rule = $this->seedRule();
        $this->stubEmptyIndexer();
        $snapshotSpy = $this->bindSnapshotSpy();

        $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => false],
        )->assertStatus(200);

        $snapshotSpy->shouldHaveReceived('record')->once();
        $snapshotSpy->shouldNotHaveReceived('markCompleted');
        $snapshotSpy->shouldNotHaveReceived('recordPostHashesForEntries');
    }

    public function test_non_preview_skips_appendWrittenItem_when_no_entries_affected(): void
    {
        $rule = $this->seedRule();
        $this->stubEmptyIndexer();
        $snapshotSpy = $this->bindSnapshotSpy();

        $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => false],
        )->assertStatus(200);

        // Empty index → preview-for-snapshot finds nothing → appendWrittenItem
        // never called. This pins the append-on-success contract: items are
        // appended ONLY after the real apply confirms a write, not at snapshot
        // time. (Drift-prevention vs LinkInsertCommand & friends.)
        $snapshotSpy->shouldNotHaveReceived('appendWrittenItem');
    }

    public function test_non_preview_stamps_last_applied_at_on_rule(): void
    {
        $rule = $this->seedRule();
        $this->stubEmptyIndexer();
        $this->bindSnapshotSpy();

        $response = $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => false],
        );

        $response->assertStatus(200);

        // Stamp lands on the persisted rule, not just the response — re-read
        // from the manager to prove persistence.
        $stamped = app(AutoLinkManager::class)->getRule($rule->id);
        $this->assertNotNull($stamped->lastAppliedAt,
            'last_applied_at must be stamped after a non-preview run');
        $this->assertSame(0, $stamped->lastAppliedLinksAdded,
            'last_applied_links_added must reflect the run (0 on empty index)');

        // Response echoes the freshly-stamped rule so the frontend doesn't
        // need a separate fetch.
        $this->assertArrayHasKey('rule', $response->json());
        $this->assertNotNull($response->json('rule.last_applied_at'));
    }

    public function test_non_preview_skips_indexer_clear_when_no_links_added(): void
    {
        $rule = $this->seedRule();
        $indexerSpy = $this->stubEmptyIndexer();
        $this->bindSnapshotSpy();

        $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => false],
        )->assertStatus(200);

        $indexerSpy->shouldNotHaveReceived('clearCache');
        $indexerSpy->shouldNotHaveReceived('buildIndex');
    }

    public function test_preview_does_not_record_snapshot(): void
    {
        $rule = $this->seedRule();
        $this->stubEmptyIndexer();
        $snapshotSpy = $this->bindSnapshotSpy();

        $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => true],
        )->assertStatus(200);

        // Preview is read-only forensics-exempt — no snapshot must be created.
        $snapshotSpy->shouldNotHaveReceived('record');
        $snapshotSpy->shouldNotHaveReceived('markCompleted');
    }

    public function test_preview_does_not_stamp_last_applied(): void
    {
        $rule = $this->seedRule();
        $this->stubEmptyIndexer();
        $this->bindSnapshotSpy();

        $this->postJson(
            route('linkwise.autolink.apply', ['id' => $rule->id]),
            ['preview' => true],
        )->assertStatus(200);

        $stamped = app(AutoLinkManager::class)->getRule($rule->id);
        $this->assertNull($stamped->lastAppliedAt,
            'last_applied_at must remain null on preview-only runs');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Seed a single AutoLink rule into the Orchestra-Testbench temp storage,
     * returning the persisted rule. Default values are intentionally minimal
     * — tests override only what they care about.
     */
    private function seedRule(array $overrides = []): AutoLinkRule
    {
        $manager = app(AutoLinkManager::class);

        return $manager->createRule(array_merge([
            'keyword' => 'example',
            'url' => 'https://example.com',
            'active' => true,
        ], $overrides));
    }

    /**
     * Replace the {@see EntryIndexer} singleton with a spy whose `load()` and
     * `save()` are no-ops returning an empty index. Lets `AutoLinkApplier`
     * iterate zero entries and return cleanly without touching the Stache.
     *
     * @return \Mockery\MockInterface  spy on the (post-call-verified) indexer
     */
    private function stubEmptyIndexer(): Mockery\MockInterface
    {
        $spy = Mockery::spy(EntryIndexer::class);
        $spy->shouldReceive('load')->andReturn([]);
        $spy->shouldReceive('save')->andReturnNull();
        $this->app->instance(EntryIndexer::class, $spy);

        return $spy;
    }

    /**
     * Replace the {@see BulkSnapshotStore} singleton with a spy. `record()`
     * still has to return a non-null id-string because the controller uses
     * it for follow-up calls.
     *
     * @return \Mockery\MockInterface
     */
    private function bindSnapshotSpy(): Mockery\MockInterface
    {
        $spy = Mockery::spy(BulkSnapshotStore::class);
        $spy->shouldReceive('record')->andReturn('test-snapshot-id');
        $this->app->instance(BulkSnapshotStore::class, $spy);

        return $spy;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
