<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source-pattern test: every bulk-write command MUST call
 * SafeEntrySaver::verifyHashes per-record before mutating the entry.
 *
 * Why a source-pattern test instead of an integration test: the bulk
 * commands are detached artisan commands consuming Statamic Entry facades.
 * A behavior test would need Statamic + Cache + Entry mocking — high cost
 * for an invariant check. The pattern test catches the actual failure mode
 * (Memory T1: BulkUnlinkCommand was missing the call entirely for weeks
 * before anyone noticed) by reading the source and asserting the call
 * exists in the right place.
 *
 * Locks down CLAUDE.md "Bulk-Write-Path Standard" Punkt 2:
 *   "SafeEntrySaver::verifyHashes per-record (in der loop) — NICHT fail-fast 409 im Controller"
 *
 * Plus feedback_no_silent_overwrite.md: Linkwise must skip-with-reason on
 * hash mismatch, never overwrite concurrent edits silently.
 *
 * REV-BJ-04 in docs/ARCHITECTURE_REVIEW.md.
 */
class BulkCommandHashGuardTest extends TestCase
{
    #[DataProvider('bulkCommandsWithHashGuard')]
    public function test_bulk_command_calls_verifyHashes(string $relativePath): void
    {
        $absolute = __DIR__.'/../../'.$relativePath;
        $this->assertFileExists($absolute, "Bulk command file missing: $relativePath");

        $source = file_get_contents($absolute);

        $this->assertStringContainsString(
            'SafeEntrySaver::verifyHashes',
            $source,
            "$relativePath must call SafeEntrySaver::verifyHashes to honor the ".
            "Bulk-Write-Path Standard (CLAUDE.md) — silent overwrite of concurrent ".
            "edits is the failure mode this prevents."
        );

        // Plus: the call must appear AFTER the `foreach` over bulk items (i.e.
        // per-record, not upfront fail-fast). ApplyRuleCommand has an upfront
        // call for the multi-rule pre-validation AND a per-record call — both
        // legal as long as at least one is per-record.
        $foreachPos = strpos($source, 'foreach');
        $verifyPos = strrpos($source, 'verifyHashes');
        $this->assertNotFalse($foreachPos, "$relativePath should have a foreach loop");
        $this->assertGreaterThan(
            $foreachPos,
            $verifyPos,
            "$relativePath must call verifyHashes AT LEAST ONCE inside/after a ".
            "foreach loop — fail-fast 409 in the controller is forbidden ".
            "(Bug 9 2026-05-11)."
        );

        // Conflict-handling must be visible in the source — either as a
        // per-item skip-with-reason 'modified' (the snapshot-drawer pattern
        // used by LinkInsert / DetailUnlink / UrlChangerApply / BulkUnlink)
        // OR as setExcludedEntries on the conflict set (the upfront-exclude
        // pattern used by ApplyRuleCommand). Both produce the same outcome
        // — conflicted entries are not mutated — and either is acceptable.
        $hasPerItemSkip = str_contains($source, "'modified'");
        $hasUpfrontExclude = str_contains($source, 'setExcludedEntries')
            && str_contains($source, 'conflictedEntries');
        $this->assertTrue(
            $hasPerItemSkip || $hasUpfrontExclude,
            "$relativePath must handle hash conflicts visibly — either via ".
            "per-item skip-reason 'modified' OR via setExcludedEntries on ".
            "the conflict set. Without either, hash mismatches would land ".
            "silently."
        );
    }

    /** @return iterable<string, array{string}> */
    public static function bulkCommandsWithHashGuard(): iterable
    {
        // All 5 commands that mutate entries via bulk operations.
        // BulkUnlinkCommand was missing the call until REV-BJ-04 (2026-05-13).
        yield 'LinkInsertCommand' => ['src/Commands/LinkInsertCommand.php'];
        yield 'DetailUnlinkCommand' => ['src/Commands/DetailUnlinkCommand.php'];
        yield 'UrlChangerApplyCommand' => ['src/Commands/UrlChangerApplyCommand.php'];
        yield 'ApplyRuleCommand' => ['src/Commands/ApplyRuleCommand.php'];
        yield 'BulkUnlinkCommand' => ['src/Commands/BulkUnlinkCommand.php'];
    }
}
