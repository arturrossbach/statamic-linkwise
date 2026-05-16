<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * End-to-end pin for the per-entry side-channel + activeJobConflicting()
 * — Sprint 6 REV-BJ-03 wire-in.
 *
 * The unit-test suite (JobLockConflictTest) pins the pure truth-table.
 * This Feature-level suite pins the cache-side glue:
 *
 *   1. recordEntryIds writes to a stable side-cache key
 *   2. entryIdsForJob reads it back
 *   3. activeJobConflicting prefers the side-cache over status.extra
 *   4. forgetEntryIds + forceClear clean up correctly
 *   5. Disjoint per-entry bulks coexist (the user-promise)
 *   6. Intersecting per-entry bulks block
 *   7. GLOBAL jobs (scan/check) still take exclusive lock
 */
class JobLockSideChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_record_and_read_round_trip(): void
    {
        JobLock::recordEntryIds('bulkunlink', ['e1', 'e2']);
        $this->assertSame(['e1', 'e2'], JobLock::entryIdsForJob('bulkunlink'));
    }

    public function test_record_is_no_op_for_global_jobs(): void
    {
        // Scan/check take exclusive lock regardless of entry-set — recording
        // entry-ids for them is meaningless and we don't want the side-cache
        // to suggest otherwise.
        JobLock::recordEntryIds('scan', ['e1']);
        $this->assertNull(JobLock::entryIdsForJob('scan'));
    }

    public function test_record_unknown_job_no_op(): void
    {
        // Defensive: callers shouldn't pass typos but if they do, no crash.
        JobLock::recordEntryIds('xtypo', ['e1']);
        $this->assertNull(JobLock::entryIdsForJob('xtypo'));
    }

    public function test_record_coerces_to_string_list(): void
    {
        JobLock::recordEntryIds('bulkunlink', [42, 'e2']);
        $this->assertSame(['42', 'e2'], JobLock::entryIdsForJob('bulkunlink'));
    }

    public function test_forget_drops_side_cache(): void
    {
        JobLock::recordEntryIds('bulkunlink', ['e1']);
        JobLock::forgetEntryIds('bulkunlink');
        $this->assertNull(JobLock::entryIdsForJob('bulkunlink'));
    }

    public function test_force_clear_drops_side_cache_too(): void
    {
        Cache::put('linkwise:bulkunlink:status', ['phase' => 'running'], 60);
        JobLock::recordEntryIds('bulkunlink', ['e1']);

        JobLock::forceClear('bulkunlink');

        $this->assertNull(Cache::get('linkwise:bulkunlink:status'));
        $this->assertNull(JobLock::entryIdsForJob('bulkunlink'));
    }

    // ── activeJobConflicting: end-to-end user-promise ──────────────────

    public function test_disjoint_per_entry_jobs_do_not_block(): void
    {
        // bulkunlink running on e1+e2 must not block detailunlink incoming on e5.
        Cache::put('linkwise:bulkunlink:status', ['phase' => 'running'], 60);
        JobLock::recordEntryIds('bulkunlink', ['e1', 'e2']);

        $blocked = JobLock::activeJobConflicting('detailunlink', ['e5']);
        $this->assertNull($blocked, 'Disjoint per-entry bulks must not conflict');
    }

    public function test_intersecting_per_entry_jobs_block(): void
    {
        // bulkunlink on e1+e2 must block detailunlink on e2 (shared entry).
        Cache::put('linkwise:bulkunlink:status', ['phase' => 'running'], 60);
        JobLock::recordEntryIds('bulkunlink', ['e1', 'e2']);

        $blocked = JobLock::activeJobConflicting('detailunlink', ['e2']);
        $this->assertNotNull($blocked);
        $this->assertSame('bulkunlink', $blocked['name']);
    }

    public function test_global_scan_blocks_per_entry_bulks(): void
    {
        // Index-rebuild semaphore: scan blocks every per-entry bulk
        // regardless of entry-set.
        Cache::put('linkwise:scan:status', ['phase' => 'running'], 60);

        $blocked = JobLock::activeJobConflicting('bulkunlink', ['e1']);
        $this->assertNotNull($blocked);
        $this->assertSame('scan', $blocked['name']);
    }

    public function test_per_entry_bulk_blocks_global_scan(): void
    {
        // The other direction: bulkunlink running blocks a new scan.
        Cache::put('linkwise:bulkunlink:status', ['phase' => 'running'], 60);
        JobLock::recordEntryIds('bulkunlink', ['e1']);

        $blocked = JobLock::activeJobConflicting('scan', null);
        $this->assertNotNull($blocked);
        $this->assertSame('bulkunlink', $blocked['name']);
    }

    public function test_missing_side_cache_treats_as_conflict(): void
    {
        // Cross-PR compatibility: if the running job is a legacy command
        // that hasn't yet been wired to recordEntryIds, its entry-set is
        // unknown → conflict for safety.
        Cache::put('linkwise:applyrule:status', ['phase' => 'running'], 60);
        // Deliberately NOT calling recordEntryIds.

        $blocked = JobLock::activeJobConflicting('bulkunlink', ['e1']);
        $this->assertNotNull($blocked);
        $this->assertSame('applyrule', $blocked['name']);
    }

    public function test_terminal_phase_does_not_block(): void
    {
        // A job in 'done' / 'cancelled' / 'error' is not active and must
        // not contribute to conflict detection, even if its side-cache
        // is still lingering (TTL hasn't elapsed yet).
        Cache::put('linkwise:bulkunlink:status', ['phase' => 'done'], 60);
        JobLock::recordEntryIds('bulkunlink', ['e1']);

        $this->assertNull(JobLock::activeJobConflicting('detailunlink', ['e1']));
    }
}
