<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Pin-set for the actionable-skip-context contract.
 *
 * User-Smoke 2026-05-16: skipped bulk-items in the Activity-Log Drawer
 * showed only `{entry_id, entry_title, reason}` — the user saw "Anchor
 * text was not found" but had no clue WHICH anchor or which target.
 * Without anchor + target context the skip row is informationally
 * dead: a non-actionable error message
 * ([[feedback_actionable_error_toasts]] applied to snapshot data).
 *
 * Two contracts pinned:
 *
 *  1. `BulkSnapshotStore::buildSkipRecord` accepts anchor + target args
 *     and surfaces them in the return shape. Legacy null args still
 *     return the today-shape (additive, never destructive — old
 *     snapshots on disk keep loading).
 *
 *  2. All 4 bulk-write commands pass anchor + target when available.
 *     Source-level regex pin per command, matching the established
 *     `IndexerWriterSymmetryAndFilterParityTest` pattern (signature-
 *     drift detection without facade scaffolding).
 *
 * Linked memo: [[activity_log_skip_context_gap]].
 */
class SkipRecordContextPinTest extends TestCase
{
    // ── Contract 1: buildSkipRecord signature accepts new args ─────────

    public function test_buildSkipRecord_signature_accepts_anchor_and_target_args(): void
    {
        // Signature pin — the method must take `?string $anchorText`,
        // `?string $targetEntryId`, `?string $targetHref` as optional
        // positional arguments after the existing $entryId/$reason.
        // Optional defaults so the 'deleted' fallback at line 351 still
        // works without callers having to pass them.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Support/BulkSnapshotStore.php');
        $this->assertMatchesRegularExpression(
            '/public static function buildSkipRecord\(\s*string \$entryId\s*,\s*string \$reason\s*,\s*\?string \$anchorText\s*=\s*null\s*,\s*\?string \$targetEntryId\s*=\s*null\s*,\s*\?string \$targetHref\s*=\s*null\s*,?\s*\)/s',
            $src,
            'BulkSnapshotStore::buildSkipRecord must accept anchor + target + targetHref as '
            .'optional positional args (default null), preserving legacy 2-arg call compatibility.',
        );
    }

    public function test_buildSkipRecord_return_shape_includes_anchor_and_target_fields(): void
    {
        // Return-shape pin — the method must populate at minimum
        // `anchor_text`, `target_entry_id`, `target_entry_title`,
        // `target_href` keys in the returned array so the Activity-Log
        // renderer has the fields to display.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Support/BulkSnapshotStore.php');
        $this->assertMatchesRegularExpression(
            "/'anchor_text'\\s*=>\\s*\\\$anchorText/",
            $src,
            "buildSkipRecord must place \$anchorText under 'anchor_text' key in the return array.",
        );
        $this->assertMatchesRegularExpression(
            "/'target_entry_id'\\s*=>\\s*\\\$targetEntryId/",
            $src,
            "buildSkipRecord must place \$targetEntryId under 'target_entry_id' key.",
        );
        $this->assertMatchesRegularExpression(
            "/'target_entry_title'\\s*=>/",
            $src,
            "buildSkipRecord must emit 'target_entry_title' key (resolved from \$targetEntryId via Entry::find).",
        );
        $this->assertMatchesRegularExpression(
            "/'target_href'\\s*=>\\s*\\\$targetHref/",
            $src,
            "buildSkipRecord must place \$targetHref under 'target_href' key (carries the external URL for external targets).",
        );
    }

    public function test_buildSkipRecord_deleted_fallback_still_emits_new_keys_as_null(): void
    {
        // The deleted-entry fallback at line 351 returns early with the
        // entry_id-only shape. After the schema extension it must still
        // emit anchor_text / target_* keys (as null) so the renderer
        // doesn't crash on the missing-property path. Pin keeps the
        // fallback aligned to the main path.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Support/BulkSnapshotStore.php');
        // Pull out the `if (! $entry)` branch up to its closing `];`
        // and pin that all four new keys appear inside it.
        $this->assertMatchesRegularExpression(
            "/if \\(! \\\$entry\\) \\{.*?'anchor_text'.*?'target_entry_id'.*?'target_entry_title'.*?'target_href'.*?\\];/s",
            $src,
            'The (! $entry) deleted-fallback branch must still emit anchor_text + target_* keys '
            .'(all null) so renderers can safely read them on deleted-entry skip records.',
        );
    }

    // ── Contract 2: all 4 commands pass anchor + target to buildSkipRecord ─

    public function test_link_insert_command_passes_anchor_and_target_to_buildSkipRecord(): void
    {
        // LinkInsertCommand has 6 buildSkipRecord call-sites after the
        // 2026-05-22 ignored-pair gate (user-bug fix). All six must pass
        // $anchorText + $targetEntryId (where available — 'missing_link_target'
        // has no target by definition, hence the null-passthrough at that site).
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Commands/LinkInsertCommand.php');

        // Pin all 6 call-shapes. Each one carries $anchorText as the 3rd arg.
        // 5 of 6 also carry $targetEntryId as the 4th arg (the missing_link_target
        // site passes null because the skip reason is "no target was supplied").
        $callCount = preg_match_all(
            '/BulkSnapshotStore::buildSkipRecord\(\s*\$sourceEntryId\s*,\s*\'[a-z_]+\'\s*,\s*\$anchorText/s',
            $src,
        );
        $this->assertSame(6, $callCount,
            'LinkInsertCommand must have exactly 6 buildSkipRecord calls each passing $anchorText '
            .'as the 3rd argument (missing_link_target, ignored, modified [x2], anchor_not_found, error).',
        );

        // 5 of those 6 also pass $targetEntryId as the 4th arg.
        $withTargetCount = preg_match_all(
            '/BulkSnapshotStore::buildSkipRecord\(\s*\$sourceEntryId\s*,\s*\'[a-z_]+\'\s*,\s*\$anchorText\s*,\s*\$targetEntryId/s',
            $src,
        );
        $this->assertSame(5, $withTargetCount,
            'LinkInsertCommand must pass $targetEntryId as 4th arg in 5 of 6 call sites '
            .'(missing_link_target legitimately has null target — its whole point is missing target).',
        );
    }

    public function test_bulk_unlink_command_passes_anchor_and_target_to_buildSkipRecord(): void
    {
        // BulkUnlinkCommand has 4 buildSkipRecord call-sites (lines
        // 145, 160, 178, 183). All four must carry the replacement's
        // anchor_text + matched_url (the target href for an unlink op).
        // Since unlink targets can be either internal (statamic://entry::X)
        // or external URLs, we pass them via $targetHref — internal-vs-
        // external resolution is left to the renderer.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Commands/BulkUnlinkCommand.php');

        $callCount = preg_match_all(
            "/BulkSnapshotStore::buildSkipRecord\\(\\s*\\\$r\\['entry_id'\\][^,]*,\\s*'[a-z_]+'\\s*,\\s*\\\$r\\['anchor_text'\\]/s",
            $src,
        );
        $this->assertGreaterThanOrEqual(3, $callCount,
            'BulkUnlinkCommand must pass $r[anchor_text] as 3rd arg at the 3 sites that operate '
            ."on a per-replacement \$r record (lines 160, 178, 183 — the line-145 'modified' site "
            .'uses $entryId from earlier in the loop, anchor lookup may differ).',
        );

        $withTargetCount = preg_match_all(
            "/BulkSnapshotStore::buildSkipRecord\\([^)]*\\\$r\\['anchor_text'\\][^,]*,\\s*[^,)]*,\\s*\\\$r\\['matched_url'\\]/s",
            $src,
        );
        $this->assertGreaterThanOrEqual(3, $withTargetCount,
            'BulkUnlinkCommand must pass $r[matched_url] as $targetHref (5th arg) at the 3 '
            .'per-replacement sites — the target URL of the link being unlinked.',
        );
    }

    public function test_detail_unlink_command_passes_anchor_and_target_to_buildSkipRecord(): void
    {
        // DetailUnlinkCommand has 3 buildSkipRecord call-sites (lines
        // 171, 256, 263). All three operate on $entryReps (multiple
        // replacements for ONE entry). Skip-record is per-entry, not
        // per-replacement — we take the first replacement's anchor
        // + matched_url as representative (the renderer can show "and
        // N more" if it cares; today's drawer doesn't).
        //
        // Pin matches both naming conventions (`$anchorText` local OR
        // `$entryReps[0]['anchor_text']` inline) so future refactors
        // that extract a local don't break the test.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Commands/DetailUnlinkCommand.php');

        $callCount = preg_match_all(
            "/BulkSnapshotStore::buildSkipRecord\\([^)]*(?:\\\$anchorText|anchor_text|\\\$repAnchor)/s",
            $src,
        );
        $this->assertGreaterThanOrEqual(3, $callCount,
            'DetailUnlinkCommand must pass an anchor identifier (e.g. $anchorText, '
            ."\$entryReps[0]['anchor_text'], or anchor_text reference) to every "
            .'buildSkipRecord call — 3 sites total.',
        );
    }

    public function test_url_changer_apply_command_passes_anchor_and_target_to_buildSkipRecord(): void
    {
        // UrlChangerApplyCommand has 3 buildSkipRecord call-sites
        // (lines 172, 246, 253). Each operates on per-replacement data
        // with anchor_text + matched_url. Same shape-flexibility as the
        // DetailUnlinkCommand pin.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Commands/UrlChangerApplyCommand.php');

        $callCount = preg_match_all(
            "/BulkSnapshotStore::buildSkipRecord\\([^)]*(?:\\\$anchorText|anchor_text|\\\$repAnchor)/s",
            $src,
        );
        $this->assertGreaterThanOrEqual(3, $callCount,
            'UrlChangerApplyCommand must pass an anchor identifier to every '
            .'buildSkipRecord call — 3 sites total.',
        );
    }
}
