<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Architecture;

use Arturrossbach\Linkwise\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Structural pin for [[architectural_health]] new bug-class
 * "Excluded-Entries Suggestion-Scope Boundary".
 *
 * ## Why this test exists
 *
 * User-bug 2026-05-22 (Cloudways smoke): putting `Home` in the
 * `excluded_entries` setting silently hid it from the Domains panel,
 * Broken-Links Checker, and made the URL-Changer see "phantom" links
 * that nothing else could explain — because `EntryIndexer::buildIndex`
 * applied the filter at the index-write layer, so every `indexer->load()`
 * consumer inherited the filter "for free".
 *
 * The blueprint copy promised "neither suggested nor suggesting" — i.e.
 * Suggestion-scope only. Real-link reports had no business filtering.
 *
 * ## What this test pins
 *
 *  1. The Indexer DOES NOT filter excluded entries at index-write time —
 *     all entries reach the persisted index.
 *  2. Every Suggestion-generating path consults `ExcludedEntryFilter`
 *     explicitly — so a future change that adds a new engine without
 *     wiring the filter breaks this test instead of silently leaking
 *     excluded entries into suggestions.
 *  3. Non-Suggestion reports (Domains / BrokenLinks) DO NOT consult
 *     the filter — they should see the full universe of entries.
 *
 * Linked memo: [[session_2026_05_22_cloudways_smoke_handoff]].
 */
class ExcludedEntriesSuggestionScopeTest extends TestCase
{
    /**
     * Files that MUST consult ExcludedEntryFilter (Suggestion-scope).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function suggestionScopeFiles(): iterable
    {
        // path → human label
        yield 'EntryIndexer enrichment' => [
            'src/Indexer/EntryIndexer.php',
            'Indexer enrichment loops compute Suggestion counts — excluded entries must zero out, '
            .'else badge / modal counters lie. Without the filter, all 13 indexer->load() consumers '
            .'would surface stale per-record counts for excluded entries.',
        ];
        yield 'InboundEngine' => [
            'src/Suggestions/InboundEngine.php',
            'InboundEngine drives the inbound Suggestion modal. Filter must skip excluded source '
            .'AND target — blueprint copy: "neither suggested nor suggesting".',
        ];
        yield 'StatsApiController' => [
            'src/Http/Controllers/Dashboard/StatsApiController.php',
            'suggestionCounts() drives the Links Report badges. Excluded entries must read as '
            .'inbound=0/outbound=0 (defense-in-depth against stale persisted counts).',
        ];
    }

    /**
     * Files that MUST NOT consult ExcludedEntryFilter — they're real-link
     * reports, not suggestion paths.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function nonSuggestionScopeFiles(): iterable
    {
        yield 'DomainReport' => [
            'src/Reports/DomainReport.php',
            'Domains is a report of real external links — excluded entries that contain '
            .'github.com links MUST appear in the panel. Filter belongs to suggestions, not reports.',
        ];
        yield 'BrokenLinkChecker' => [
            'src/Links/BrokenLinkChecker.php',
            'Broken-Links is a real-link health report — excluded entries with broken URLs '
            .'must still be flagged. Filter would have hidden their broken links silently.',
        ];
    }

    #[DataProvider('suggestionScopeFiles')]
    public function test_suggestion_scope_files_consult_excluded_entry_filter(string $relativePath, string $rationale): void
    {
        $absolute = dirname(__DIR__, 3).'/'.$relativePath;
        $this->assertFileExists($absolute, "Suggestion-scope file missing: $relativePath");

        $source = file_get_contents($absolute);
        $this->assertStringContainsString(
            'ExcludedEntryFilter',
            $source,
            "$relativePath must reference ExcludedEntryFilter. Rationale: $rationale",
        );
    }

    #[DataProvider('nonSuggestionScopeFiles')]
    public function test_non_suggestion_scope_files_do_not_consult_excluded_entry_filter(string $relativePath, string $rationale): void
    {
        $absolute = dirname(__DIR__, 3).'/'.$relativePath;
        $this->assertFileExists($absolute, "Non-suggestion-scope file missing: $relativePath");

        $source = file_get_contents($absolute);
        $this->assertStringNotContainsString(
            'ExcludedEntryFilter',
            $source,
            "$relativePath must NOT reference ExcludedEntryFilter. Rationale: $rationale",
        );
        // Also pin that the older `config('linkwise.excluded_entries')` short-
        // circuit isn't smuggled back in as inline filtering at the same site.
        $this->assertStringNotContainsString(
            "config('linkwise.excluded_entries')",
            $source,
            "$relativePath must NOT read linkwise.excluded_entries directly — "
            .'the inline filter was the user-bug. Reports see the full universe.',
        );
    }

    public function test_indexer_buildIndex_does_not_filter_excluded_at_write_time(): void
    {
        // The crux of the fix: buildIndex() previously dropped excluded entries
        // from the persisted index. Pin its body NEVER reads excluded_entries
        // / excluded_collections — that's now strictly a Suggestion-engine
        // concern.
        $src = file_get_contents(dirname(__DIR__, 3).'/src/Indexer/EntryIndexer.php');

        // Extract buildIndex body to scope the assertion.
        $this->assertMatchesRegularExpression(
            '/public function buildIndex\(/',
            $src,
            'EntryIndexer must still expose buildIndex.',
        );

        if (! preg_match(
            '/public function buildIndex\(.*?(?=\n    \/\*\*|\n    public function )/s',
            $src,
            $body,
        )) {
            $this->fail('Could not isolate buildIndex body for pin assertion.');
        }
        $bodyText = $body[0];

        $this->assertStringNotContainsString(
            "config('linkwise.excluded_entries')",
            $bodyText,
            'EntryIndexer::buildIndex must NOT consult excluded_entries at index-write '
            .'time. Filter belongs in Suggestion-generating paths via ExcludedEntryFilter.',
        );
        $this->assertStringNotContainsString(
            "config('linkwise.excluded_collections')",
            $bodyText,
            'EntryIndexer::buildIndex must NOT consult excluded_collections at index-write '
            .'time. Same rationale as excluded_entries.',
        );
    }
}
