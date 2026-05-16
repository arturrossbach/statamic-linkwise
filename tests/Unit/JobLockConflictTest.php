<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\JobLock;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for the conflict-detection truth-table added in
 * Sprint 6 REV-BJ-03.
 *
 * The user-visible promise of per-entry-locking is: "Editor A running
 * bulk-unlink on post-1 must NOT block Editor B's URL-changer on post-7."
 * That promise is encoded by `JobLock::conflicts()`. These tests pin the
 * truth-table so a future refactor that drops scope-awareness or breaks
 * the intersection logic fails loudly instead of silently re-introducing
 * the global-block UX.
 *
 * `entryIdsOf` is tested as the read-side of the same contract: it must
 * cope with legacy status caches that lack the new `extra.entry_ids`
 * field (return null → caller treats as conflict for safety).
 */
class JobLockConflictTest extends TestCase
{
    // ── conflicts() truth-table ────────────────────────────────────────

    public function test_global_vs_anything_always_conflicts(): void
    {
        // Scan/check take exclusive lock — index-rebuild semaphore.
        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_GLOBAL, null,
            JobLock::SCOPE_PER_ENTRY, ['e1'],
        ), 'GLOBAL running blocks PER_ENTRY incoming');

        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, ['e1'],
            JobLock::SCOPE_GLOBAL, null,
        ), 'PER_ENTRY running blocks GLOBAL incoming (e.g. someone tries to run scan mid-bulk)');

        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_GLOBAL, null,
            JobLock::SCOPE_GLOBAL, null,
        ), 'GLOBAL vs GLOBAL conflicts');
    }

    public function test_disjoint_per_entry_sets_do_not_conflict(): void
    {
        // Core BJ-03 user-promise: Editor A on post-1 doesn't block
        // Editor B on post-7.
        $this->assertFalse(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, ['e1'],
            JobLock::SCOPE_PER_ENTRY, ['e7'],
        ));
    }

    public function test_intersecting_per_entry_sets_conflict(): void
    {
        // Same entry — two bulks both wanting to write post-5 race on
        // the index file; one must wait.
        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, ['e1', 'e5'],
            JobLock::SCOPE_PER_ENTRY, ['e5', 'e9'],
        ));
    }

    public function test_per_entry_with_missing_running_ids_conflicts_for_safety(): void
    {
        // Pre-Sprint-6 commands didn't write entry_ids into status cache.
        // Mid-flight migration: the running job's entry-set is null →
        // we must assume potential intersection, blocking new bulks
        // until the running one finishes. Legacy bulks aren't slower
        // than today, just no faster.
        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, null,
            JobLock::SCOPE_PER_ENTRY, ['e1'],
        ));
    }

    public function test_per_entry_with_missing_requesting_ids_conflicts_for_safety(): void
    {
        // Requesting side doesn't know its entry-set yet (e.g. caller
        // hasn't computed it, or applyrule pre-keyword-resolution).
        // Conservative behaviour: block.
        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, ['e1'],
            JobLock::SCOPE_PER_ENTRY, null,
        ));
    }

    public function test_disjoint_with_string_int_id_mix(): void
    {
        // Defensive: entry-ids come from both Statamic Entry IDs
        // (strings like "abc-uuid") and legacy contexts (numeric ids
        // serialised as int in cache JSON). Coerce both sides so the
        // intersection check doesn't false-negative.
        $this->assertFalse(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, [42],
            JobLock::SCOPE_PER_ENTRY, ['7'],
        ));
        $this->assertTrue(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, [42],
            JobLock::SCOPE_PER_ENTRY, ['42'],
        ));
    }

    public function test_empty_per_entry_set_treated_as_no_targets(): void
    {
        // A job with `entry_ids: []` has no targets — practically
        // shouldn't run, but conflict-wise it's disjoint from everything.
        // (`array_intersect([], [...])` is [].)
        $this->assertFalse(JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, [],
            JobLock::SCOPE_PER_ENTRY, ['e1'],
        ));
    }

    public function test_large_disjoint_sets_perform(): void
    {
        // Big sites (>500 posts per Marketplace audience): bulkunlink
        // can carry 500+ entry-ids. Conflict check must stay O(n+m),
        // and the test must complete in milliseconds.
        $a = array_map(fn ($i) => 'a'.$i, range(1, 500));
        $b = array_map(fn ($i) => 'b'.$i, range(1, 500));

        $start = microtime(true);
        $result = JobLock::conflicts(
            JobLock::SCOPE_PER_ENTRY, $a,
            JobLock::SCOPE_PER_ENTRY, $b,
        );
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result);
        $this->assertLessThan(0.05, $elapsed, '500-vs-500 conflict check must stay under 50ms');
    }

    // ── entryIdsOf() — status-cache shape adapter ──────────────────────

    public function test_entry_ids_of_returns_list_when_present(): void
    {
        $status = ['phase' => 'running', 'extra' => ['entry_ids' => ['e1', 'e2', 'e3']]];
        $this->assertSame(['e1', 'e2', 'e3'], JobLock::entryIdsOf($status));
    }

    public function test_entry_ids_of_missing_extra_returns_null(): void
    {
        // Legacy / starting-phase status without an extra block yet.
        // Caller treats null as "unknown → conflict for safety".
        $this->assertNull(JobLock::entryIdsOf(['phase' => 'running']));
    }

    public function test_entry_ids_of_missing_field_returns_null(): void
    {
        // Has extra block but no entry_ids field (e.g. urlchanger's
        // extra carries 'action' + 'search' but not entry_ids yet).
        $this->assertNull(JobLock::entryIdsOf(['phase' => 'running', 'extra' => ['action' => 'replace']]));
    }

    public function test_entry_ids_of_non_array_value_returns_null(): void
    {
        // Cache corruption / dev-mode misuse — must not crash.
        $this->assertNull(JobLock::entryIdsOf(['phase' => 'running', 'extra' => ['entry_ids' => 'not-an-array']]));
    }

    public function test_entry_ids_of_coerces_to_string_list(): void
    {
        // Integer ids (legacy or test fixtures) and stray non-scalars
        // (defensive — silently drop the latter, coerce the former).
        $status = [
            'phase' => 'running',
            'extra' => ['entry_ids' => ['e1', 42, ['nested'], 'e3', null]],
        ];
        $this->assertSame(['e1', '42', 'e3'], JobLock::entryIdsOf($status));
    }
}
