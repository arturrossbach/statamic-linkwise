<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Acceptance pins for the stem-cluster anchor coordinator-reject
 * (user-bug 2026-05-23, post-launch Cloudways smoke):
 *
 * The `findUnorderedStemMatch` fallback path builds an anchor by
 * picking the tightest cluster of title-stem positions in the source
 * text. Pre-fix, when only two title-stems were present in the
 * source and a coordination conjunction sat between them ("and",
 * "or", "und", "oder", …), the cluster span included the coordinator
 * and surfaced as a Müll-anchor like "optimization and performance".
 *
 * Fix surface:
 *  1. Boundary stopwords are trimmed (mirror findMatches anchor trim).
 *  2. Anchors of exactly 2 content words separated by an interior
 *     coordinator are rejected — that exact shape carries the user-
 *     visible Müll. Larger anchors (3+ content words) with an
 *     interior coordinator usually form a legitimate phrase and are
 *     kept (e.g. "internal linking and better SEO" against title
 *     "Internal Linking Strategy for Better SEO").
 *
 * Bridge stopwords (von, of, in, for, …) are NEVER reject triggers —
 * they bind concepts into a coherent phrase. Editors legitimately
 * want anchors like "Wollsocken von Bircher" (title: "Birch
 * Wollsocken"). The distinction is coordinator vs preposition.
 *
 * POS-tagging would be cleaner; the explicit coordinator list is the
 * V1 trade-off (no NLP-library dependency).
 *
 * Linked memo: [[architectural_health]] — new bug-class entry
 * "Stem-Cluster Coordinator Anchor Leak".
 */
class SuggestionEngineStemClusterCoordTest extends TestCase
{
    protected SuggestionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SuggestionEngine;
    }

    protected function record(string $title): EntryRecord
    {
        return new EntryRecord(
            id: 'target',
            title: $title,
            url: '/x',
            collection: 'articles',
            text: '',
            outboundLinks: [],
        );
    }

    public function test_two_content_with_interior_and_is_rejected(): void
    {
        // User-bug canonical case: title "Performance Tuning Optimization",
        // source text "We need optimization and performance reviews quickly".
        // Pre-fix: stem cluster collapsed to "optimization and performance"
        // and was returned at score 0.6.
        $index = ['target' => $this->record('Performance Tuning Optimization')];
        $results = $this->engine->suggest(
            'We need optimization and performance reviews quickly.',
            $index,
            null,
        );

        $this->assertEmpty($results, '2-content + interior coordinator must be rejected.');
    }

    public function test_two_content_with_bridge_preposition_is_kept(): void
    {
        // User-validated keep case: title "Birch Wollsocken", source text
        // "Die Wollsocken von Bircher sind der Hammer". "von" is a bridge
        // preposition (binds), not a coordinator (splits). The reject
        // heuristic must NOT fire on this shape.
        $index = ['target' => $this->record('Birch Wollsocken')];
        $results = $this->engine->suggest(
            'Die Wollsocken von Bircher sind der Hammer',
            $index,
            null,
        );

        $this->assertNotEmpty($results, 'Bridge prepositions like "von" must not trigger reject.');
        $this->assertStringContainsString('Wollsocken', $results[0]->anchorText);
        $this->assertStringContainsString('Bircher', $results[0]->anchorText);
    }

    public function test_two_content_with_for_preposition_is_kept(): void
    {
        // Sister of the "von" case for English: "for" is a bridge
        // preposition, must not trigger the coordinator reject.
        $index = ['target' => $this->record('Best Practices Production')];
        $results = $this->engine->suggest(
            'Discussion: best practices for production deployments.',
            $index,
            null,
        );

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('practices', $results[0]->anchorText);
        $this->assertStringContainsString('production', $results[0]->anchorText);
    }

    public function test_three_plus_content_with_interior_and_is_kept(): void
    {
        // Larger anchors with interior "and" usually form a legitimate
        // phrase strongly overlapping the title. The reject heuristic
        // applies only to the canonical 2-content shape.
        $index = ['target' => $this->record('Internal Linking Strategy for Better SEO')];
        $results = $this->engine->suggest(
            'Our strategies for internal linking and better SEO drove organic traffic.',
            $index,
            null,
        );

        $this->assertNotEmpty($results,
            '4+ content-word cluster with interior "and" must NOT be rejected — strong title overlap.');
        $this->assertStringContainsString('internal linking', $results[0]->anchorText);
        $this->assertStringContainsString('better SEO', $results[0]->anchorText);
    }

    public function test_two_content_with_interior_or_is_also_rejected(): void
    {
        // "or" is the second canonical coordinator — same shape, same fix.
        $index = ['target' => $this->record('Performance Tuning Optimization')];
        $results = $this->engine->suggest(
            'Choose between optimization or performance during the review.',
            $index,
            null,
        );

        $this->assertEmpty($results, '"or" is a coordinator just like "and".');
    }

    public function test_two_content_with_interior_und_german_is_rejected(): void
    {
        // German "und" — coordination conjunction, same reject path.
        $index = ['target' => $this->record('Datenbank Migration Tuning')];
        $results = $this->engine->suggest(
            'Wir besprachen Tuning und Migration ausführlich.',
            $index,
            null,
        );

        $this->assertEmpty($results, 'German "und" must be on the coordinator list.');
    }

    public function test_boundary_and_is_trimmed_not_kept(): void
    {
        // Pre-fix, the stem-fallback path did NOT trim boundary stopwords
        // (unlike findMatches at Z. 382). An anchor span starting at "and"
        // would surface with "and" still attached. The trim mirror should
        // strip it; the remaining 2-content anchor is then evaluated by
        // the coord-reject heuristic. Either way, the user does not see
        // "and X" or "X and" boundaries.
        $index = ['target' => $this->record('Server Security Web')];
        $results = $this->engine->suggest(
            'Talk about server security and web hosting.',
            $index,
            null,
        );

        if (! empty($results)) {
            $anchor = $results[0]->anchorText;
            $this->assertFalse(str_starts_with(mb_strtolower($anchor), 'and '),
                "Anchor must not start with 'and ' — boundary trim missing.");
            $this->assertFalse(str_ends_with(mb_strtolower($anchor), ' and'),
                "Anchor must not end with ' and' — boundary trim missing.");
        }
    }
}

class SuggestionEngineKeywordAnchorCoordTest extends \Arturrossbach\Linkwise\Tests\TestCase
{
    public function test_findBestAnchor_rejects_coordinator_gap_falls_back_to_strategy2(): void
    {
        // Source-grep pin (mirrors the existing source-pattern test style
        // for guarantees the unit harness can't easily assert without
        // wiring TF-IDF state). Pins that findBestAnchor checks the
        // coordinator list when accepting a 1-word-gap match in Strategy 1.
        // Without the check, "performance and tuning" surfaces as a
        // user-visible Müll-anchor through the TF-IDF keyword-fallback
        // path even after the RAKE + stem-cluster guards.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Suggestions/SuggestionEngine.php');

        $this->assertMatchesRegularExpression(
            '/protected function findBestAnchor.*?\$coordinatorStopwords/s',
            $src,
            'findBestAnchor must declare a coordinatorStopwords list to reject "and"-bridged anchors.',
        );
        $this->assertStringContainsString("'and', 'or', 'but'", $src);
        $this->assertStringContainsString("'und', 'oder'", $src);

        // Pin that BOTH order-checks (primary-then-other AND other-then-
        // primary) run the gap-word through the coordinator filter.
        // Counted occurrences: the in_array($gap, $coordinatorStopwords...)
        // call must appear at least twice in findBestAnchor body.
        $bodyMatch = preg_match(
            '/protected function findBestAnchor.*?(?=\n    protected|\n    public|\n\})/s',
            $src,
            $bodyMatches,
        );
        $this->assertSame(1, $bodyMatch, 'Unable to isolate findBestAnchor body.');
        $count = substr_count($bodyMatches[0], 'in_array($gap, $coordinatorStopwords');
        $this->assertGreaterThanOrEqual(2, $count,
            'Both primary→other and other→primary order-checks must consult the coordinator list.');
    }
}
