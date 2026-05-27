<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Architecture;

use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Structural pin for [[architectural_health]] CR-H-5 (V1.x-Polish-Audit):
 * JobLock::ACTIVE_PHASES + ::TERMINAL_PHASES must enumerate every literal
 * `'phase' => 'XXX'` value that any Cache::put('linkwise:*:status', ...)
 * actually writes.
 *
 * ## Why this test exists
 *
 * JobLock::ACTIVE_PHASES is a manually-maintained array. When a Command
 * introduces a new phase string (e.g. 'validating') and forgets to
 * register it here, three things break silently:
 *
 *   1. activeJob() returns null while the command is mid-flight, because
 *      the phase string isn't in_array($phase, ACTIVE_PHASES, true).
 *   2. snapshot() sees no active work → frontend global banner stays empty.
 *   3. Other endpoints can dispatch new bulks alongside it — index race.
 *
 * That was exactly the `'checking'`-not-listed bug (memory: JobLock comment
 * lines 64-67). Same class, structurally repeatable.
 *
 * ## What this test pins
 *
 * For every `Cache::put('linkwise:*:status', [...])` call in `src/`:
 *
 *   - Extract the literal `'phase' => 'XXX'` value (if present as a literal).
 *   - Assert XXX is in (ACTIVE_PHASES ∪ TERMINAL_PHASES) via Reflection.
 *
 * Cache::put writes are authoritative. Cache::get fallbacks like
 * `Cache::get(...) ?? ['phase' => 'idle']` are NOT pinned — 'idle' is a
 * read-side sentinel for "no status yet", it's never written.
 *
 * Dynamic phases like `'phase' => $status['phase'] ?? 'running'` are
 * exempted automatically (no literal to extract).
 *
 * ## Exempts
 *
 * Empty today. If a future Cache::put legitimately uses a phase that
 * shouldn't appear in active/terminal (e.g. an experimental dispatch-
 * gate), add it to EXEMPT_PHASES with justification.
 */
class PhaseRegistryParityTest extends TestCase
{
    /**
     * Phase literals that are exempt from the registry check.
     * Key = phase string, Value = justification.
     *
     * @var array<string, string>
     */
    private const EXEMPT_PHASES = [
        // 'foo' => 'Justification…',
    ];

    public function test_every_phase_written_to_cache_is_registered_in_joblock(): void
    {
        $registered = $this->registeredPhases();

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

            $src = file_get_contents($path);
            if (! str_contains($src, 'Cache::put(') || ! str_contains($src, 'linkwise:')) {
                continue;
            }

            foreach ($this->extractCachePutBlocks($src) as [$body, $lineNo]) {
                // Only interested in payloads addressing a linkwise:<kind>:status key.
                if (! preg_match('/[\'"]linkwise:[a-z]+:status[\'"]/', $body)) {
                    continue;
                }

                // Extract the literal `'phase' => 'XXX'` value (if present).
                // Dynamic phases (`'phase' => $variable`) yield no literal —
                // skipped automatically.
                if (! preg_match("/['\"]phase['\"]\\s*=>\\s*['\"]([a-z_]+)['\"]/", $body, $m)) {
                    continue;
                }
                $phase = $m[1];

                if (array_key_exists($phase, self::EXEMPT_PHASES)) {
                    continue;
                }

                if (! in_array($phase, $registered, true)) {
                    $gaps[] = "{$relative}:{$lineNo} writes phase '{$phase}' "
                        ."which is not in JobLock::ACTIVE_PHASES ∪ ::TERMINAL_PHASES";
                }
            }
        }

        $this->assertEmpty(
            $gaps,
            "Phase-registry drift detected. The following Cache::put writes\n"
            ."use phase strings that JobLock doesn't know about. activeJob()\n"
            ."will return null while the command runs, snapshot() returns no\n"
            ."active work, and the frontend banner stays empty AND concurrent\n"
            ."bulks can dispatch — exact bug class as the historical\n"
            ."'checking'-not-listed incident (JobLock.php:64-67 comment).\n\n"
            ."Sites:\n  - "
            .implode("\n  - ", $gaps)
            ."\n\nFix: add the missing phase to JobLock::ACTIVE_PHASES (mid-\n"
            ."flight, mutually exclusive) or ::TERMINAL_PHASES (done state).\n"
            ."If the phase is legitimately neither (rare), add it to\n"
            ."EXEMPT_PHASES here with justification.",
        );
    }

    /**
     * Sanity-pin: known-compliant Cache::put writes still extract phases.
     * Guards against the regex silently short-circuiting (whitespace
     * drift, quote-style change, refactor).
     */
    public function test_known_phase_writes_are_still_detectable(): void
    {
        $srcDir = realpath(__DIR__.'/../../../src');

        $expectedWrites = [
            'Commands/IndexCommand.php' => ['starting', 'indexing', 'saving', 'done', 'cancelled'],
            'Commands/CheckLinksCommand.php' => ['starting', 'checking', 'done', 'cancelled'],
            'Commands/BulkUnlinkCommand.php' => ['running', 'indexing', 'done', 'cancelled', 'error'],
            'Commands/ApplyRuleCommand.php' => ['running', 'indexing', 'done', 'cancelled', 'error'],
            'Commands/DetailUnlinkCommand.php' => ['running', 'indexing', 'done', 'cancelled', 'error'],
            'Commands/UrlChangerApplyCommand.php' => ['running', 'indexing', 'done', 'cancelled', 'error'],
        ];

        foreach ($expectedWrites as $rel => $expectedPhases) {
            $path = $srcDir.'/'.$rel;
            $this->assertFileExists($path);
            $src = file_get_contents($path);

            $foundPhases = [];
            foreach ($this->extractCachePutBlocks($src) as [$body, $_]) {
                if (! preg_match('/[\'"]linkwise:[a-z]+:status[\'"]/', $body)) {
                    continue;
                }
                if (preg_match("/['\"]phase['\"]\\s*=>\\s*['\"]([a-z_]+)['\"]/", $body, $m)) {
                    $foundPhases[$m[1]] = true;
                }
            }

            foreach ($expectedPhases as $expected) {
                $this->assertArrayHasKey(
                    $expected,
                    $foundPhases,
                    "Sanity-pin: expected to find a Cache::put write of phase\n"
                    ."'{$expected}' in {$rel}, but the regex didn't extract it.\n"
                    .'Either the file was refactored (update this list) or '
                    .'the regex drifted (fix extraction in this test).',
                );
            }
        }
    }

    /**
     * Sanity-pin: JobLock itself declares both phase-buckets via private/
     * protected constants. Surfaces them via Reflection so the drift-test
     * doesn't break if a refactor changes visibility.
     */
    public function test_joblock_exposes_both_phase_buckets(): void
    {
        $registered = $this->registeredPhases();

        $this->assertContains('running', $registered, 'ACTIVE_PHASES must contain "running"');
        $this->assertContains('done', $registered, 'TERMINAL_PHASES must contain "done"');
        $this->assertContains('error', $registered, 'TERMINAL_PHASES must contain "error"');
    }

    /**
     * Read JobLock::ACTIVE_PHASES ∪ ::TERMINAL_PHASES via Reflection so
     * the test stays valid if visibility changes (currently both are
     * protected — Reflection sidesteps that).
     *
     * @return list<string>
     */
    private function registeredPhases(): array
    {
        $ref = new \ReflectionClass(JobLock::class);
        $active = $ref->getConstant('ACTIVE_PHASES');
        $terminal = $ref->getConstant('TERMINAL_PHASES');

        $this->assertIsArray($active, 'JobLock::ACTIVE_PHASES must exist');
        $this->assertIsArray($terminal, 'JobLock::TERMINAL_PHASES must exist');

        return array_values(array_unique(array_merge($active, $terminal)));
    }

    /**
     * Extract `Cache::put(...)` call bodies via paren-balancing. Same
     * helper as TerminalStatusErrorsFieldParityTest — kept inline because
     * a shared trait would require restructuring tests/Unit/Architecture.
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
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
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
