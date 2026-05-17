<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Commands;

use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Structural pin for [[architectural_health]] Klasse 7
 * (async-bulk + Activity-Log skip-records parity).
 *
 * ## Why this test exists
 *
 * 4 of 5 bulk-Commands (BulkUnlink / DetailUnlink / LinkInsert /
 * UrlChangerApply) carry both
 *
 *   - `SafeEntrySaver::verifyHashes(...)` (or per-record hash check)
 *   - `BulkSnapshotStore::recordBulkSkipped(...)` (per-entry skip-log write)
 *
 * ApplyRuleCommand was the missing 5th — verifyHashes was called but no
 * recordBulkSkipped, so hash-conflict skips never appeared in the
 * Activity-Log drawer (User-Smoke 2026-05-17 Bug #2). PR-Welle-1 added
 * recordBulkSkipped to ApplyRule single + multi.
 *
 * This test pins the contract structurally: every Command source file
 * under `src/Commands/` that **mutates entries via verifyHashes** must
 * also call `recordBulkSkipped`. If a future Command is added that
 * mutates without writing skip-records, this test fails and the author
 * either (a) adds the skip-record write, or (b) adds the Command to
 * the EXEMPT_COMMANDS list with a written justification why no per-
 * record skip semantics apply.
 *
 * ## Why source-grep instead of behaviour test
 *
 * The 5 commands have very different signatures (Bard vs Markdown vs
 * Replicator inputs, sync vs async cache writes, different snapshot
 * shapes). A behaviour-pin would require either 5 separate fixture-heavy
 * tests OR a synthetic test that doesn't exercise the real Statamic
 * stack. A source-grep is the highest-leverage check: it catches the
 * Add-a-new-Command-without-skip-write drift class with O(1) maintenance.
 *
 * If a developer believes the grep is too strict (false positive on a
 * verifyHashes-using command that legitimately doesn't need skip-records),
 * EXEMPT_COMMANDS is the explicit escape hatch — but it requires a
 * comment justifying the exemption in this file.
 */
class BulkCommandSkipRecordParityTest extends TestCase
{
    /**
     * Commands explicitly exempted from the parity contract.
     *
     * Add an entry here ONLY with a justification — the next reviewer
     * needs to understand why this command doesn't need recordBulkSkipped
     * even though it uses verifyHashes.
     *
     * Today: empty. All 5 mutating commands (BulkUnlink, DetailUnlink,
     * LinkInsert, UrlChangerApply, ApplyRule) honour the contract as of
     * 2026-05-17.
     */
    private const EXEMPT_COMMANDS = [
        // 'SomeCommand' => 'Justification: ...',
    ];

    public function test_every_bulk_command_using_verify_hashes_also_writes_skip_records(): void
    {
        $commandsDir = realpath(__DIR__.'/../../../src/Commands');
        $this->assertDirectoryExists($commandsDir, 'Commands directory not found');

        $gaps = [];

        foreach (glob($commandsDir.'/*Command.php') as $path) {
            $name = basename($path, '.php');
            if (array_key_exists($name, self::EXEMPT_COMMANDS)) {
                continue;
            }

            $src = file_get_contents($path);
            $this->assertNotFalse($src, "Could not read {$path}");

            // Heuristic: a "mutating" command is one that calls verifyHashes
            // (the hash-conflict gate before each bulk-write). Index/Check/
            // Normalize/Seed commands don't qualify and don't need skip-records.
            $usesVerifyHashes = (bool) preg_match(
                '/SafeEntrySaver::verifyHashes\s*\(/',
                $src,
            );
            if (! $usesVerifyHashes) {
                continue;
            }

            // Must call recordBulkSkipped at least once. The 4-pre-existing
            // commands have it inside a `if (! empty($bulkSkippedRecords))`
            // guard; ApplyRuleCommand has it inside `if (! empty($conflictedEntries))`.
            // The grep below allows either guard style.
            $writesSkipRecords = (bool) preg_match(
                '/BulkSnapshotStore[^;]*recordBulkSkipped\s*\(/',
                $src,
            );
            if (! $writesSkipRecords) {
                $gaps[] = $name;
            }
        }

        $this->assertEmpty(
            $gaps,
            'Klasse-7-Sister-Audit gap: the following Commands call '
            ."SafeEntrySaver::verifyHashes\nbut never call "
            ."BulkSnapshotStore::recordBulkSkipped, leaving per-entry "
            ."skips invisible in the\nActivity-Log drawer:\n  - "
            .implode("\n  - ", $gaps)
            ."\n\nEither add recordBulkSkipped to the command (preferred — "
            ."mirror the\nshape used by BulkUnlinkCommand:215 / "
            ."LinkInsertCommand:302 / ApplyRuleCommand:262), or add the\n"
            ."command name to EXEMPT_COMMANDS in this test with a "
            ."justification why per-record skip semantics don't apply.",
        );
    }

    /**
     * Sanity: assert that the contract actually covers all 5 commands we
     * know to be in scope as of 2026-05-17. Guards against the grep
     * accidentally short-circuiting (e.g. a renamed method making the
     * regex miss all callers — would produce a false-green test).
     */
    public function test_known_mutating_commands_are_all_covered(): void
    {
        $commandsDir = realpath(__DIR__.'/../../../src/Commands');
        $expected = [
            'BulkUnlinkCommand',
            'DetailUnlinkCommand',
            'LinkInsertCommand',
            'UrlChangerApplyCommand',
            'ApplyRuleCommand',
        ];

        foreach ($expected as $name) {
            $path = $commandsDir.'/'.$name.'.php';
            $this->assertFileExists($path);
            $src = file_get_contents($path);
            $this->assertMatchesRegularExpression(
                '/SafeEntrySaver::verifyHashes\s*\(/',
                $src,
                "Sanity check: {$name} should call verifyHashes — if this "
                .'fails the parity-grep would also fail silently.',
            );
            $this->assertMatchesRegularExpression(
                '/BulkSnapshotStore[^;]*recordBulkSkipped\s*\(/',
                $src,
                "Klasse-7 gap: {$name} calls verifyHashes but not "
                .'recordBulkSkipped. Sister-pattern with the other 4 '
                .'commands is required for Activity-Log drawer parity.',
            );
        }
    }
}
