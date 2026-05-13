<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BulkStatusWriter;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Characterization + extension tests for BulkStatusWriter (REV-OB-01).
 *
 * These tests pin down the EXACT shape that LinkInsertCommand was writing
 * inline at 7 sites — so the migration provably reproduces what the
 * frontend poller already consumes. If you change a key name here, you're
 * breaking the read-contract that LinkwiseLayout.vue depends on.
 */
class BulkStatusWriterTest extends TestCase
{
    private string $statusKey = 'linkwise:test-bulk:status';

    private array $context = [
        'source_mode' => 'outbound',
        'entry_title' => 'My Entry',
        'started_by' => 'Artur',
        'started_by_id' => 'user-42',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget($this->statusKey);
    }

    public function test_running_writes_progress_with_heartbeat_and_context(): void
    {
        $w = new BulkStatusWriter($this->statusKey, $this->context);

        $w->running(current: 5, total: 20, succeeded: 3, skipped: 2);

        $written = Cache::get($this->statusKey);
        $this->assertIsArray($written);
        $this->assertSame('running', $written['phase']);
        $this->assertSame(5, $written['current']);
        $this->assertSame(20, $written['total']);
        $this->assertSame(3, $written['succeeded']);
        $this->assertSame(2, $written['skipped']);
        $this->assertSame('outbound', $written['source_mode']);
        $this->assertSame('My Entry', $written['entry_title']);
        $this->assertSame('Artur', $written['started_by']);
        $this->assertSame('user-42', $written['started_by_id']);
        $this->assertIsInt($written['heartbeat']);
    }

    public function test_running_defaults_succeeded_and_skipped_to_zero(): void
    {
        $w = new BulkStatusWriter($this->statusKey, $this->context);

        $w->running(current: 0, total: 10);

        $written = Cache::get($this->statusKey);
        $this->assertSame(0, $written['succeeded']);
        $this->assertSame(0, $written['skipped']);
    }

    public function test_cancelled_carries_progress_and_errors_without_heartbeat(): void
    {
        $w = new BulkStatusWriter($this->statusKey, $this->context);

        $errors = ['Anchor text not found in entry' => 1];
        $w->cancelled(current: 7, total: 20, succeeded: 5, skipped: 2, errors: $errors);

        $written = Cache::get($this->statusKey);
        $this->assertSame('cancelled', $written['phase']);
        $this->assertSame(7, $written['current']);
        $this->assertSame(20, $written['total']);
        $this->assertSame(5, $written['succeeded']);
        $this->assertSame(2, $written['skipped']);
        $this->assertSame($errors, $written['errors']);
        // Cancelled = terminal phase, no heartbeat needed (no further updates).
        $this->assertArrayNotHasKey('heartbeat', $written);
    }

    public function test_indexing_writes_full_progress_and_heartbeat(): void
    {
        $w = new BulkStatusWriter($this->statusKey, $this->context);

        $w->indexing(total: 20, succeeded: 18, skipped: 2);

        $written = Cache::get($this->statusKey);
        $this->assertSame('indexing', $written['phase']);
        $this->assertSame(20, $written['current'],
            'indexing is post-loop — current must equal total so the banner does not show stuck N/N');
        $this->assertSame(20, $written['total']);
        $this->assertSame(18, $written['succeeded']);
        $this->assertSame(2, $written['skipped']);
        $this->assertIsInt($written['heartbeat']);
    }

    public function test_done_mirrors_progress_into_extra_block(): void
    {
        $w = new BulkStatusWriter($this->statusKey, $this->context);

        $errors = ['Entry was modified by another editor' => 3];
        $w->done(total: 20, succeeded: 15, skipped: 5, errors: $errors);

        $written = Cache::get($this->statusKey);
        $this->assertSame('done', $written['phase']);
        $this->assertSame(20, $written['current']);
        $this->assertSame(20, $written['total']);
        $this->assertSame(15, $written['succeeded']);
        $this->assertSame(5, $written['skipped']);
        $this->assertSame($errors, $written['errors']);

        // Root-level heartbeat (Bug 2026-05-11 fix — dedup-signature reads here)
        $this->assertIsInt($written['heartbeat']);

        // Mirror block — frontend's completionLabel/dedup reads from 'extra'
        $this->assertArrayHasKey('extra', $written);
        $this->assertSame(15, $written['extra']['succeeded']);
        $this->assertSame(5, $written['extra']['skipped']);
        $this->assertSame($errors, $written['extra']['errors']);
        $this->assertSame('outbound', $written['extra']['source_mode']);
        $this->assertSame('My Entry', $written['extra']['entry_title']);
        $this->assertSame('Artur', $written['extra']['started_by']);
        $this->assertSame('user-42', $written['extra']['started_by_id']);
        $this->assertIsInt($written['extra']['heartbeat']);
    }

    public function test_done_root_heartbeat_matches_extra_heartbeat(): void
    {
        $w = new BulkStatusWriter($this->statusKey, $this->context);

        $w->done(total: 1, succeeded: 1, skipped: 0);

        $written = Cache::get($this->statusKey);
        // Both heartbeats must be the SAME time() call — frontend may
        // compare them for sanity.
        $this->assertSame($written['heartbeat'], $written['extra']['heartbeat']);
    }

    public function test_mergeContext_updates_subsequent_writes(): void
    {
        $w = new BulkStatusWriter($this->statusKey, ['source_mode' => 'outbound']);

        $w->mergeContext(['entry_title' => 'Added Later']);
        $w->running(current: 1, total: 5);

        $written = Cache::get($this->statusKey);
        $this->assertSame('outbound', $written['source_mode']);
        $this->assertSame('Added Later', $written['entry_title']);
    }
}
