<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Architecture;

use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Structural pin for [[architectural_health]] Klasse 9a
 * (per-kind terminal-status shape parity — `errors` field sub-class).
 *
 * ## Why this test exists
 *
 * 5 bulk-Commands write a `Cache::put('linkwise:<kind>:status', ...)`
 * terminal payload when they reach phase=done. The frontend's
 * `bulkLabels.completionLabel(...)` consumer reads `extra.errors` for
 * 4 kinds (bulkunlink/urlchanger/detailunlink/inbound+outboundinsert)
 * to render the top error reason via `topErrorReason(extra.errors)`.
 *
 * Welle-4 audit (2026-05-18) found ApplyRuleCommand was the 5th
 * holdout — it wrote `succeeded` + `skipped` (PR #58, Klasse 9b
 * conflicts_skipped) but never `errors`, even though AutoLinkApplier
 * populates `$result['errors']` on Throwables (line 222-224). Result:
 * a future error-toast extension for applyrule would silently degrade
 * to "no reason available", and the terminal-status shape stayed
 * asymmetric across kinds.
 *
 * ## What this test pins
 *
 * For each `Cache::put('linkwise:<kind>:status', ...)` call in `src/`
 * that includes BOTH `succeeded` AND `skipped` fields (the canonical
 * markers of a "mutating bulk's terminal status" payload), the same
 * call MUST also include an `errors` field.
 *
 * Why succeeded+skipped as the trigger: any kind that reports per-
 * record success/skip counts is by definition iterating records
 * with possible Throwable-failures — the consumer side of the
 * contract (topErrorReason) is parametric over `extra.errors` so
 * producers MUST supply it for symmetry.
 *
 * Calls that only carry `succeeded`+`total` (no `skipped`) are
 * partial-progress writes (`running`, `indexing`) — they don't
 * need errors yet, hence the AND-gate.
 *
 * ## Exempts
 *
 * Empty today. All 5 commands honour the contract as of Welle 4.
 * If a future command legitimately doesn't have per-record error
 * tracking (e.g. an aggregator that only counts), add it to
 * EXEMPT_FILES with justification.
 */
class TerminalStatusErrorsFieldParityTest extends TestCase
{
    private const EXEMPT_FILES = [
        // 'src/Path/To/File.php' => 'Justification…',
    ];

    public function test_terminal_status_with_succeeded_skipped_also_carries_errors(): void
    {
        $srcDir = realpath(__DIR__.'/../../../src');
        $this->assertDirectoryExists($srcDir);

        $gaps = [];

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $path = $f->getPathname();
            $relative = ltrim(str_replace($srcDir, '', $path), '/');
            if (array_key_exists($relative, self::EXEMPT_FILES)) {
                continue;
            }

            $src = file_get_contents($path);
            if (! str_contains($src, 'Cache::put(') || ! str_contains($src, 'linkwise:')) {
                continue;
            }

            // Pull each Cache::put(... call-body via paren-balancing.
            foreach ($this->extractCachePutBlocks($src) as [$body, $lineNo]) {
                // Only interested in payloads addressing a linkwise:<kind>:status key.
                if (! preg_match('/[\'"]linkwise:[a-z]+:status[\'"]/', $body)) {
                    continue;
                }

                // Only check TERMINAL (phase=done) writes. `running` /
                // `indexing` writes also carry succeeded+skipped (for the
                // "5 done, 0 skipped — finalizing" banner) but errors are
                // a post-loop aggregation only meaningful at done.
                $isDoneTerminal = (bool) preg_match(
                    "/['\"]phase['\"]\\s*=>\\s*['\"]done['\"]/",
                    $body,
                );
                if (! $isDoneTerminal) {
                    continue;
                }

                // Require: BOTH succeeded AND skipped key in this payload.
                // Use word-boundary regex to avoid matching `entries_skipped`.
                $hasSucceeded = (bool) preg_match('/[\'"]succeeded[\'"]\s*=>/', $body);
                $hasSkipped = (bool) preg_match('/[\'"]skipped[\'"]\s*=>/', $body);
                if (! ($hasSucceeded && $hasSkipped)) {
                    continue;
                }

                $hasErrors = (bool) preg_match('/[\'"]errors[\'"]\s*=>/', $body);
                if (! $hasErrors) {
                    $gaps[] = "{$relative}:{$lineNo} — terminal Cache::put with "
                        .'succeeded+skipped is missing the `errors` field';
                }
            }
        }

        $this->assertEmpty(
            $gaps,
            "Klasse-9a sister-gap (errors-field parity): the following Cache::put\n"
            ."writes carry succeeded+skipped fields but no `errors` field. Frontend\n"
            ."`completionLabel(kind, ...)` reads `extra.errors` via\n"
            ."`topErrorReason()` for the other kinds — without symmetry, a future\n"
            ."error-toast extension for the affected kind silently degrades to\n"
            ."'no reason available'. Sites:\n  - "
            .implode("\n  - ", $gaps)
            ."\n\nFix: forward the per-bulk errors array to the terminal status\n"
            ."(see BulkStatusWriter::done in src/Support/BulkStatusWriter.php for\n"
            ."the canonical shape, or ApplyRuleCommand:262 for an inline example).\n"
            ."Shape is `[msg => count]`. If the kind legitimately doesn't track\n"
            ."per-record errors, add the file to EXEMPT_FILES with justification.",
        );
    }

    /**
     * Sanity-pin: pre-existing kinds that already satisfy the contract.
     * Guards against the grep silently short-circuiting (regex drift,
     * method rename).
     */
    public function test_known_compliant_commands_still_compliant(): void
    {
        $srcDir = realpath(__DIR__.'/../../../src');

        $compliantCalls = [
            'Commands/BulkUnlinkCommand.php',
            'Commands/DetailUnlinkCommand.php',
            'Commands/UrlChangerApplyCommand.php',
            'Commands/ApplyRuleCommand.php',  // post-Welle-4
        ];

        foreach ($compliantCalls as $rel) {
            $path = $srcDir.'/'.$rel;
            $this->assertFileExists($path);
            $src = file_get_contents($path);

            $foundAny = false;
            foreach ($this->extractCachePutBlocks($src) as [$body, $lineNo]) {
                if (! preg_match('/[\'"]linkwise:[a-z]+:status[\'"]/', $body)) {
                    continue;
                }
                $isDone = (bool) preg_match(
                    "/['\"]phase['\"]\\s*=>\\s*['\"]done['\"]/",
                    $body,
                );
                $hasSuccSkip = preg_match('/[\'"]succeeded[\'"]\s*=>/', $body)
                    && preg_match('/[\'"]skipped[\'"]\s*=>/', $body);
                if (! ($isDone && $hasSuccSkip)) {
                    continue;
                }
                $hasErrors = (bool) preg_match('/[\'"]errors[\'"]\s*=>/', $body);
                $this->assertTrue(
                    $hasErrors,
                    "{$rel}:{$lineNo} sanity-pin: should carry `errors` field "
                    .'but doesn\'t. If the contract is being intentionally '
                    .'broken, EXEMPT_FILES is the escape hatch.',
                );
                $foundAny = true;
            }

            $this->assertTrue(
                $foundAny,
                "{$rel} sanity-pin: expected at least one succeeded+skipped "
                .'terminal Cache::put — none found. Either the file was '
                .'refactored (update this list) or the regex drifted.',
            );
        }
    }

    /**
     * Extract `Cache::put(...)` call bodies via paren-balancing.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function extractCachePutBlocks(string $src): array
    {
        $out = [];
        $needle = 'Cache::put(';
        $offset = 0;
        while (($pos = strpos($src, $needle, $offset)) !== false) {
            $openParen = $pos + strlen($needle) - 1;
            $depth = 1;
            $i = $openParen + 1;
            while ($depth > 0 && $i < strlen($src)) {
                $ch = $src[$i];
                if ($ch === '(') $depth++;
                elseif ($ch === ')') $depth--;
                $i++;
            }
            $body = substr($src, $pos, $i - $pos);
            $lineNo = substr_count(substr($src, 0, $pos), "\n") + 1;
            $out[] = [$body, $lineNo];
            $offset = $i;
        }

        return $out;
    }
}
