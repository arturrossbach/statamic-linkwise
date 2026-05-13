<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Commands\AuditCommand;
use PHPUnit\Framework\TestCase;

/**
 * Characterization + extension tests for the apply-counter-honesty audit
 * check.
 *
 * Today the check fires "items.length != completion_stats.succeeded" for
 * every snapshot where succeeded == -1 (= a legacy-sentinel value from a
 * pre-refactor code path). prose-peak-test carries 16 such snapshots from
 * 2026-05-09/10/11; they keep the audit dashboard at "27 failures" for the
 * past 4 days. Operator trains themselves to ignore audit output, then
 * misses real failures.
 *
 * REV-UC-04 extracts the snapshot classification into a pure-static helper
 * and adds an explicit legacy-sentinel skip. The 16 stale snapshots
 * disappear from the failure list with one log line per skip; new bulks
 * with real mismatches still fail loudly.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-UC-04
 */
class AuditApplyCounterHonestyTest extends TestCase
{
    /** A "done" snapshot whose items count matches the recorded succeeded count → pass. */
    public function test_matching_count_passes(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'done', 'succeeded' => 5],
            'items' => [['x'], ['y'], ['z'], ['a'], ['b']],
        ]);

        $this->assertSame('pass', $verdict['action']);
    }

    /** Real mismatch (items count ≠ succeeded count) → fail. */
    public function test_real_mismatch_fails(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'done', 'succeeded' => 5],
            'items' => [['x'], ['y']],
        ]);

        $this->assertSame('fail', $verdict['action']);
        $this->assertStringContainsString('items.length=2', $verdict['reason']);
        $this->assertStringContainsString('succeeded=5', $verdict['reason']);
    }

    /** Legacy sentinel succeeded=-1 → skip with reason, NOT fail. */
    public function test_legacy_sentinel_minus_one_is_skipped(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'done', 'succeeded' => -1],
            'items' => [['x']],
        ]);

        $this->assertSame('skip-legacy', $verdict['action'],
            'succeeded=-1 is a legacy sentinel from pre-refactor code paths — must skip, not fail');
        $this->assertStringContainsString('legacy', $verdict['reason']);
    }

    /**
     * Snapshot IDs older than the append-on-success migration cutoff are
     * skip-legacy even if their succeeded is a real integer — the items.length
     * mismatch is a pre-refactor data shape, not a current-code bug.
     */
    public function test_pre_cutoff_snapshot_id_is_skipped(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'id' => '20260511-205953-a3ef751c', // before cutoff 20260512
            'completion_stats' => ['phase' => 'done', 'succeeded' => 0],
            'items' => [['x']], // would be a fail today
        ]);

        $this->assertSame('skip-legacy', $verdict['action'],
            'snapshot IDs dated before the append-on-success migration cutoff must skip');
        $this->assertStringContainsString('20260511', $verdict['reason']);
    }

    /** Post-cutoff snapshot IDs are classified normally — real fails still fail. */
    public function test_post_cutoff_snapshot_id_fails_on_real_mismatch(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'id' => '20260513-100000-newhash00', // after cutoff
            'completion_stats' => ['phase' => 'done', 'succeeded' => 0],
            'items' => [['x']],
        ]);

        $this->assertSame('fail', $verdict['action'],
            'post-cutoff snapshots with real mismatches must still fail loudly');
    }

    /** Post-cutoff matching snapshot passes normally. */
    public function test_post_cutoff_matching_snapshot_passes(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'id' => '20260513-100000-newhash00',
            'completion_stats' => ['phase' => 'done', 'succeeded' => 2],
            'items' => [['x'], ['y']],
        ]);

        $this->assertSame('pass', $verdict['action']);
    }

    /** Snapshot still in-flight (phase != done) is not auditable yet — skip. */
    public function test_in_flight_snapshot_is_skipped(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'running', 'succeeded' => 3],
            'items' => [['x']],
        ]);

        $this->assertSame('skip-in-flight', $verdict['action']);
    }

    /** items_trimmed flag = items were capped by storage policy — skip. */
    public function test_trimmed_snapshot_is_skipped(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'done', 'succeeded' => 5000],
            'items' => array_fill(0, 1000, ['x']),
            'items_trimmed' => true,
        ]);

        $this->assertSame('skip-trimmed', $verdict['action']);
    }

    /** Cancelled snapshot (phase=cancelled) is not auditable for honesty. */
    public function test_cancelled_snapshot_is_skipped(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'cancelled', 'succeeded' => 2],
            'items' => [['x'], ['y']],
        ]);

        $this->assertSame('skip-in-flight', $verdict['action']);
    }

    /** items field missing entirely → treat as empty array (count=0). */
    public function test_missing_items_treated_as_empty(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'completion_stats' => ['phase' => 'done', 'succeeded' => 0],
        ]);

        $this->assertSame('pass', $verdict['action']);
    }

    /** Missing completion_stats entirely → cannot audit → skip. */
    public function test_missing_completion_stats_is_skipped(): void
    {
        $verdict = AuditCommand::classifyApplyCounterSnapshot([
            'items' => [['x']],
        ]);

        $this->assertSame('skip-in-flight', $verdict['action']);
    }
}
