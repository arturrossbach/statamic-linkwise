<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BulkJobDispatcher;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * REV-XC-01 — Tests für BulkJobDispatcher.
 *
 * Pinning the cache-key namespacing + payload/status pre-state contract.
 * The actual `exec()` call is intentionally NOT mocked — these tests run
 * fast and verify the cache state the detached command will read on
 * startup. Real-flow verification of the exec path happens via the
 * existing real-flow tinker against prose-peak-test.
 */
class BulkJobDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['linkwise:test-kind:status', 'linkwise:test-kind:payload', 'linkwise:test-kind:cancel'] as $k) {
            Cache::forget($k);
        }
    }

    public function test_writes_status_and_payload_to_kind_namespaced_keys(): void
    {
        // Seed stale state to verify it gets wiped first.
        Cache::put('linkwise:test-kind:status', ['phase' => 'done', 'old' => true], 300);
        Cache::put('linkwise:test-kind:cancel', true, 300);

        BulkJobDispatcher::dispatch(
            kind: 'test-kind',
            command: 'linkwise:no-op-command-for-test',
            payload: ['some' => 'data'],
            initialStatus: ['phase' => 'starting', 'total' => 10],
            logFile: 'no-op.log',
            logLabel: 'No-Op',
        );

        // Status was REWRITTEN — stale 'done' / 'old' is gone.
        $status = Cache::get('linkwise:test-kind:status');
        $this->assertSame(['phase' => 'starting', 'total' => 10], $status);

        // Payload was set.
        $this->assertSame(['some' => 'data'], Cache::get('linkwise:test-kind:payload'));

        // Cancel flag was wiped.
        $this->assertNull(Cache::get('linkwise:test-kind:cancel'));
    }

    public function test_progress_only_mode_omits_payload(): void
    {
        BulkJobDispatcher::dispatch(
            kind: 'test-kind',
            command: 'linkwise:no-op-command-for-test',
            payload: null,
            initialStatus: ['phase' => 'starting'],
            logFile: 'no-op.log',
            logLabel: 'No-Op',
            extraArgs: ['--progress'],
        );

        // Status set …
        $this->assertSame(['phase' => 'starting'], Cache::get('linkwise:test-kind:status'));

        // … but no payload key (null payload means progress-only operations
        // like check-links / scan that don't consume a payload).
        $this->assertNull(Cache::get('linkwise:test-kind:payload'));
    }

    public function test_initial_status_preserved_byte_exact(): void
    {
        $initialStatus = [
            'phase' => 'starting',
            'total' => 42,
            'current' => 0,
            'source_mode' => 'outbound',
            'entry_title' => 'Test',
            'started_by' => 'Anna',
            'started_by_id' => 'user-1',
        ];

        BulkJobDispatcher::dispatch(
            kind: 'test-kind',
            command: 'linkwise:no-op',
            payload: ['x' => 1],
            initialStatus: $initialStatus,
            logFile: 'x.log',
            logLabel: 'X',
        );

        $this->assertSame($initialStatus, Cache::get('linkwise:test-kind:status'));
    }
}
