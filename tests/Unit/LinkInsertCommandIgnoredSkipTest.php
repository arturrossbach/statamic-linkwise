<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-pattern pin: LinkInsertCommand MUST consult IgnoredSuggestionStore
 * per-item and skip with reason='ignored' before BardLinkInserter is called.
 *
 * Why a source-pattern test: same rationale as BulkCommandHashGuardTest —
 * the command is a detached artisan consumer of Statamic facades + Cache
 * payload, so a behavior test would need a full Statamic harness for what
 * is fundamentally an invariant ("the gate exists, in the right place,
 * builds a snapshot-skip-record"). Source-pin is cheap, robust, and pins
 * the actual failure mode (user-bug 2026-05-22: 3 ignored pairs were
 * inserted via the SuggestionModal Select-All flow because no layer of
 * the pipeline — modal, controller, command — gated against ignored).
 *
 * Three pins (matching the three contractual obligations of the gate):
 *
 *  1. The IgnoredSuggestionStore class is imported and resolved per-call
 *     inside the bulk loop (not constructor-injected — that would couple
 *     the command's signature to the store, and the command is wired by
 *     artisan, not the container's auto-wiring path).
 *
 *  2. The gate sits AFTER the missing-link-target / effectiveHref guard
 *     and BEFORE the SafeEntrySaver::verifyHashes call. Ordering matters
 *     because external href (no target_entry_id) has no entry-pair
 *     semantics and the ignored check must short-circuit those out.
 *
 *  3. The skip record uses reason='ignored' and carries anchor + target
 *     so the Activity-Log drawer renders an actionable row (matches the
 *     SkipRecordContextPinTest contract).
 *
 * Linked memos: [[session_2026_05_22_cloudways_smoke_handoff]],
 * [[bulk_write_path_standard]], [[activity_log_skip_context_gap]].
 */
class LinkInsertCommandIgnoredSkipTest extends TestCase
{
    protected static function source(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/src/Commands/LinkInsertCommand.php');
    }

    public function test_imports_ignored_suggestion_store(): void
    {
        $this->assertMatchesRegularExpression(
            '/use\s+Arturrossbach\\\\Linkwise\\\\Suggestions\\\\IgnoredSuggestionStore\s*;/',
            self::source(),
            'LinkInsertCommand must import IgnoredSuggestionStore so the per-item '
            .'ignored-pair gate can resolve it without a fully-qualified-name in the loop body.',
        );
    }

    public function test_ignored_set_loaded_once_before_foreach_loop(): void
    {
        // Perf invariant: the file-backed IgnoredSuggestionStore is loaded
        // exactly ONCE — into an in-memory $ignoredPairKeys lookup — BEFORE
        // the per-item foreach. Re-reading the JSON inside the loop turned
        // up to 200× into measurable disk I/O on slow hosts (Cloudways).
        // The bulk runs in a single artisan process, so the ignored-list
        // cannot mutate mid-loop in a way that would change correctness.
        $src = self::source();
        $foreachPos = strpos($src, 'foreach ($insertions');
        $loadAllPos = strpos($src, 'IgnoredSuggestionStore::class)->loadAll(');
        $this->assertNotFalse($foreachPos, 'LinkInsertCommand must iterate insertions via foreach.');
        $this->assertNotFalse(
            $loadAllPos,
            'LinkInsertCommand must load the ignored-list via loadAll() so the per-item gate '
            .'does O(1) set lookups instead of re-reading the JSON file per insertion.',
        );
        $this->assertLessThan(
            $foreachPos,
            $loadAllPos,
            'loadAll() must run BEFORE the foreach — per-iteration file reads were measurable '
            .'on slow I/O hosts. Bulks are single-process, so the snapshot at dispatch time is fine.',
        );
    }

    public function test_ignored_gate_uses_in_memory_set_and_short_circuits_before_write(): void
    {
        $src = self::source();
        $foreachPos = strpos($src, 'foreach ($insertions');
        $insertCallPos = strpos($src, '$success = BardLinkInserter::insertLinkIntoEntryWithHref');
        // Pin the *behavioural* shape (in-memory lookup), not the resolver
        // mechanic — keeps the test stable across DI / store-refactors.
        $setLookupPos = strpos($src, 'isset($ignoredPairKeys[', $foreachPos !== false ? $foreachPos : 0);
        $this->assertNotFalse($setLookupPos, 'The per-item gate must read from $ignoredPairKeys in-memory set.');
        $this->assertGreaterThan(
            $foreachPos,
            $setLookupPos,
            'The set lookup must live inside the foreach (per-item), not upfront.',
        );
        $this->assertLessThan(
            $insertCallPos,
            $setLookupPos,
            'The ignored-pair gate must execute BEFORE BardLinkInserter::insertLinkIntoEntryWithHref — '
            .'placing it after would only suppress the result, the entry would already be mutated.',
        );
    }

    public function test_ignored_gate_only_applies_to_internal_targets(): void
    {
        // External-href flow (Activity-Log Revert with https:// URLs) carries
        // no target_entry_id, so the undirected entry-pair concept doesn't
        // apply. The gate must guard on $targetEntryId truthiness — without
        // it, revert flows would normalise against a null second pair-element.
        $this->assertMatchesRegularExpression(
            '/\$pairKey\s*!==\s*null\s*&&\s*isset\(\$ignoredPairKeys\[\$pairKey\]\)/',
            self::source(),
            'The ignored gate must short-circuit when $pairKey is null (external href without '
            .'$targetEntryId) — undirected entry-pair semantics require both ids to compute the key.',
        );
    }

    public function test_ignored_skip_record_uses_correct_reason_and_carries_context(): void
    {
        // Skip-record contract matches SkipRecordContextPinTest — anchor +
        // target must be present so the Activity-Log drawer renders an
        // actionable "Pair X→Y was ignored" row, not a context-free
        // "something was skipped". Reason MUST be 'ignored' so the frontend
        // formatSkipReason() branch selects the un-ignore guidance copy
        // instead of falling through to the generic "modified" fallback.
        $this->assertMatchesRegularExpression(
            "/BulkSnapshotStore::buildSkipRecord\\(\\s*\\\$sourceEntryId\\s*,\\s*'ignored'\\s*,\\s*\\\$anchorText\\s*,\\s*\\\$targetEntryId\\s*,\\s*\\\$href\\s*\\)/",
            self::source(),
            "The ignored-skip path must build a skip record with reason='ignored' and pass "
            .'anchor + target + href — the Activity-Log drawer renders these and '
            ."ActivityPage.vue::formatSkipReason has a dedicated 'ignored' branch.",
        );
    }

    public function test_activity_page_renders_ignored_skip_reason(): void
    {
        // Frontend-pin: ActivityPage.vue::formatSkipReason must have a
        // dedicated branch for reason='ignored', otherwise the skip row
        // falls into the 'modified' fallback and confusingly says
        // "Modified by another editor" for a pair the user explicitly
        // marked as ignored.
        $vueSrc = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/pages/ActivityPage.vue',
        );
        $this->assertMatchesRegularExpression(
            "/row\\.reason\\s*===\\s*'ignored'/",
            $vueSrc,
            'ActivityPage.vue::formatSkipReason must include an explicit branch for '
            ."reason='ignored' — otherwise the Activity-Log drawer mislabels ignored skips.",
        );
    }
}
