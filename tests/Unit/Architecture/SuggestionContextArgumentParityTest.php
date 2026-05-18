<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Architecture;

use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Structural pin for [[architectural_health]] Klasse 4.x
 * (filter-apply argument parity for `sentence_context`).
 *
 * ## Why this test exists
 *
 * `BardLinkInserter::insertLinkIntoEntryWithHref(string $sourceId,
 * string $anchor, string $href, bool $caseSensitive, bool $save,
 * ?string $expectedSentenceContext = null): bool` has 6 args. The
 * 6th, `expectedSentenceContext`, is what makes the Walker refuse
 * matches whose sentence context doesn't match what the Suggestion
 * was generated for.
 *
 * Drift class: a dry-run filter / audit-check / verify-loop calls
 * the inserter with only 5 args (no context), while the real-write
 * path calls with 6. User sees Suggestion in modal → Apply silent-
 * rejects with context_mismatch. OR: persisted counter over-counts
 * because verify-loop is more permissive than the user-facing
 * filter that produced it.
 *
 * Historical manifestations (all fixed):
 * - InboundEngine:158 — fixed by commit 4e6573d (2026-05-16)
 * - OutboundSuggestionGrouper:35 — fixed by PR #53 (B-1)
 * - EntryIndexer:207/231 — fixed by PR #52 (B-2)
 * - AuditCommand:2196 `checkSuggestionInsertable` — fixed by this PR
 *   (Welle 3, 2026-05-18) — caller had `$s->sentenceContext`
 *   available but the audit method ignored it. Audit could PASS
 *   while real apply FAILED — false negative in our own audit.
 *
 * ## What this test pins
 *
 * For every file in `src/` that calls
 * `BardLinkInserter::insertLinkIntoEntryWithHref(...)` AND lives in
 * a "Suggestion-context surface" (Suggestions/, Indexer/, Commands/
 * AuditCommand context-related methods): each call-site must pass
 * sentence_context as the 6th arg (named `expectedSentenceContext:`
 * or positional). 5-arg calls in these surfaces are a structural
 * smell that the test surfaces with a pointer to this comment.
 *
 * Exempt surfaces (in `EXEMPT_FILES` below) — each with written
 * justification:
 * - `AutoLink/AutoLinkApplier.php` — rule-based; AutoLinkApplier's
 *   own preview and apply BOTH call with 5 args (intentional, the
 *   rule defines "first valid match" not a specific context).
 * - `Subscribers/AutoLinkOnEntrySaveSubscriber.php` — fire-and-
 *   forget design, no preview to compare context against.
 * - `Commands/AuditCommand.php` `checkDryRunAgreesWithLinkStatus`
 *   (line 753) — symmetric to AutoLinkApplier's 5-arg flow.
 * - `Commands/LinkInsertCommand.php` — the REAL-WRITE path itself,
 *   uses 6 args (this is the baseline that 4.x asks others to
 *   match — not a sister-violation).
 *
 * ## Why source-grep not behaviour
 *
 * Same rationale as PR #59 / #60 structural pins. Behaviour-pinning
 * each Suggestion/audit/verify-loop path would need heavy Statamic
 * fixtures × N call-sites. Source-grep is O(1) maintenance and
 * catches the drift class directly: "new audit method / new verify
 * loop / new Suggestion-style filter, forgot the 6th arg".
 */
class SuggestionContextArgumentParityTest extends TestCase
{
    /**
     * Files whose `insertLinkIntoEntryWithHref` call-sites are
     * legitimately 5-arg (or fewer) and exempt from the contract.
     * Add new entries here ONLY with justification — the next
     * reviewer needs to understand why this file doesn't need the
     * sentence_context 6th arg.
     */
    private const EXEMPT_FILES = [
        'AutoLink/AutoLinkApplier.php' =>
            'Rule-based applier: preview + apply both use 5-arg (rule '
            .'defines "first valid match", not a specific sentence).',
        'Subscribers/AutoLinkOnEntrySaveSubscriber.php' =>
            'Fire-and-forget auto-apply-on-save: no preview, no '
            .'expected context to compare against.',
        'Commands/LinkInsertCommand.php' =>
            'The real-write path itself uses 6 args (baseline, '
            .'not a sister-violation).',
        'Support/BardLinkInserter.php' =>
            'The inserter calling itself (3-arg delegation helper '
            .'`insertLinkIntoEntry`) — uses default arg values.',
    ];

    /**
     * Files whose `insertLinkIntoEntryWithHref` calls are partially
     * exempt — only specific method bodies skip the 6-arg contract,
     * other methods in the same file follow it. The grep operates
     * file-by-file, so we list whole-files here with notes about
     * which methods are the exceptions.
     */
    private const PARTIAL_EXEMPT_FILES = [
        'Commands/AuditCommand.php' => [
            'exempt_methods' => ['checkDryRunAgreesWithLinkStatus'],
            'reason' => 'checkDryRunAgreesWithLinkStatus is rule-based '
                .'(symmetric to AutoLinkApplier). checkSuggestionInsertable '
                .'IS in scope and MUST pass 6 args (Welle-3-fix 2026-05-18).',
        ],
    ];

    public function test_every_suggestion_context_call_passes_sentence_context(): void
    {
        $srcDir = realpath(__DIR__.'/../../../src');
        $this->assertDirectoryExists($srcDir);

        $gaps = [];

        foreach ($this->collectPhpFiles($srcDir) as $path) {
            $relative = ltrim(str_replace($srcDir, '', $path), '/');
            if (array_key_exists($relative, self::EXEMPT_FILES)) {
                continue;
            }

            $src = file_get_contents($path);
            if (! str_contains($src, 'insertLinkIntoEntryWithHref')) {
                continue;
            }

            // Extract every `insertLinkIntoEntryWithHref(` call-block
            // through the next matching `)`. Naïve balancer that
            // handles our actual call shapes (single + multi-line,
            // positional and named args).
            foreach ($this->extractCallBlocks($src, 'insertLinkIntoEntryWithHref') as [$callBody, $lineNo]) {
                // The call-site MUST contain the sentence_context
                // 6th arg in one of these forms:
                //   - positional 6-arg (no easy way to count without
                //     a real parser — fall back to: any 6th argument
                //     marker)
                //   - named arg `expectedSentenceContext:`
                //   - explicit reference to `sentenceContext` (so
                //     callers using `$s->sentenceContext` or
                //     `$candidate['sentenceContext']` match)
                $hasContext =
                    str_contains($callBody, 'expectedSentenceContext:')
                    || str_contains($callBody, 'sentenceContext');

                if ($hasContext) {
                    continue;
                }

                // Partial-exempt files: skip the call if it's inside
                // an exempt method. Crude check by walking up to the
                // nearest `protected function <name>` / `function <name>`
                // before the call position.
                if (array_key_exists($relative, self::PARTIAL_EXEMPT_FILES)) {
                    $offset = strpos($src, $callBody);
                    $enclosingMethod = $this->enclosingMethodName($src, $offset);
                    if (in_array($enclosingMethod, self::PARTIAL_EXEMPT_FILES[$relative]['exempt_methods'], true)) {
                        continue;
                    }
                }

                $gaps[] = "{$relative}:{$lineNo} — insertLinkIntoEntryWithHref call "
                    .'without sentence_context arg';
            }
        }

        $this->assertEmpty(
            $gaps,
            "Klasse-4.x sister-gap: the following call-sites of\n"
            ."BardLinkInserter::insertLinkIntoEntryWithHref operate on Suggestion\n"
            ."or verify-loop surfaces but don't pass sentence_context as the\n"
            ."6th arg. The real-write path (LinkInsertCommand:201) DOES pass\n"
            ."it, so these filters/audits may PASS while real apply silently\n"
            ."rejects with context_mismatch (or vice-versa: persisted counters\n"
            ."over-count). Sites:\n  - "
            .implode("\n  - ", $gaps)
            ."\n\nFix: forward the Suggestion's sentence_context to the call (named\n"
            ."arg `expectedSentenceContext: \$s->sentenceContext` is the\n"
            ."canonical shape). Or add the file to EXEMPT_FILES or\n"
            ."PARTIAL_EXEMPT_FILES in this test with a justification why\n"
            .'sentence_context doesn\'t apply (e.g. rule-based path, fire-and-forget).',
        );
    }

    private function collectPhpFiles(string $dir): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /**
     * Return [callBody, lineNumber] for each invocation of `$method(`
     * in $src. callBody is the slice from the matched method name
     * through the closing `)` that balances the opening `(`.
     */
    private function extractCallBlocks(string $src, string $method): array
    {
        $out = [];
        $needle = $method.'(';
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

    /**
     * Walk backwards from $offset in $src to find the enclosing
     * `function name(` declaration. Returns the function name or null
     * if not found.
     */
    private function enclosingMethodName(string $src, int $offset): ?string
    {
        $before = substr($src, 0, $offset);
        if (preg_match_all('/function\s+([a-zA-Z_]\w*)\s*\(/', $before, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[1]);

            return is_array($last) ? $last[0] : null;
        }

        return null;
    }
}
