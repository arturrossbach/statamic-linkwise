<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use PHPUnit\Framework\TestCase;

/**
 * trimBoundaryStopwords cleanup tests.
 *
 * Two pieces of logic interact:
 *  1. The stopword check at each boundary strips attached punctuation
 *     before lookup — "die," and "die." both count as the German
 *     stopword "die". Without this, "Dokumentation, die" never trims
 *     because the comma stays attached to "Dokumentation" and the
 *     trailing word "die" is still recognised as a stopword (no
 *     punctuation), but "die," at the leading position would slip
 *     through.
 *  2. After the trim, the kept boundary words have leading/trailing
 *     non-letter characters stripped — "Dokumentation," → "Dokumentation".
 *     A trailing comma never belongs in a hyperlink anchor.
 *
 * Plus the relaxed end-condition: a single surviving content word
 * (e.g. "Anleitung" trimmed from "die Anleitung") IS now a legit
 * anchor — the previous "must keep ≥2 words" rule discarded perfectly
 * good single-word matches.
 *
 * Default Stopwords::forConfig() is bilingual EN+DE here (no
 * linkwise.language set in the test container), so both "the" and
 * "die"/"der" reliably count as stopwords.
 */
class SuggestionEngineAnchorTrimTest extends TestCase
{
    private SuggestionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SuggestionEngine();
    }

    public function test_strips_leading_stopword(): void
    {
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('the documentation');

        $this->assertSame('documentation', $trimmed);
        $this->assertSame(4, $shift, '"the " consumed before "documentation"');
    }

    public function test_strips_trailing_stopword(): void
    {
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('documentation the');

        $this->assertSame('documentation', $trimmed);
        $this->assertSame(0, $shift, 'Trailing trim does not shift the start');
    }

    public function test_strips_trailing_stopword_with_attached_comma(): void
    {
        // The commit-1f3cf4d case: "Dokumentation, die" — the trailing
        // word is "die" (German stopword) BUT the leading word
        // "Dokumentation," has the comma attached. The walk-backward
        // recognises "die" as a stopword (no punctuation to strip on it
        // here) and the kept boundary word then has its trailing comma
        // stripped on cleanup. Real-world result: "Dokumentation".
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('Dokumentation, die');

        $this->assertSame('Dokumentation', $trimmed);
        $this->assertSame(0, $shift);
    }

    public function test_strips_leading_stopword_with_attached_comma(): void
    {
        // Mirror case: "die, Dokumentation". Without the stripPunct
        // helper, parts[0] = "die," would not match the stopword list
        // (which contains the bare "die") so the trim wouldn't happen
        // at all. With stripPunct, "die," → "die" → stopword → drop.
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('die, Dokumentation');

        $this->assertSame('Dokumentation', $trimmed);
        $this->assertSame(5, $shift, '"die, " (5 chars) consumed before "Dokumentation"');
    }

    public function test_strips_trailing_punctuation_from_kept_boundary_word(): void
    {
        // Single-word anchor with attached comma — no stopwords to drop,
        // but the boundary cleanup must still strip the trailing comma.
        // Trailing punctuation never belongs inside the <a> anchor.
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('documentation,');

        $this->assertSame('documentation', $trimmed);
        $this->assertSame(0, $shift);
    }

    public function test_strips_leading_punctuation_from_kept_boundary_word(): void
    {
        // Bracketed phrase: "(documentation)" — the parens belong outside
        // the anchor span. leadingShift must reflect the consumed "(".
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('(documentation)');

        $this->assertSame('documentation', $trimmed);
        $this->assertSame(1, $shift, 'Leading "(" consumed; offset advances by 1');
    }

    public function test_keeps_single_surviving_content_word(): void
    {
        // Pre-diff regression: the old "$last - $first < 1" check rejected
        // any trim that would leave only one word. "die Anleitung" trimmed
        // to "Anleitung" was discarded as too aggressive — but a single
        // content word IS a legit anchor (German title "Anleitung, die
        // wirklich hilft" should yield anchor "Anleitung", not the
        // untrimmed two-word "die Anleitung").
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('die Anleitung');

        $this->assertSame('Anleitung', $trimmed);
        $this->assertSame(4, $shift);
    }

    public function test_keeps_original_when_every_word_is_a_stopword(): void
    {
        // Walk-forward consumes everything → first > last → bail to
        // original. A trim that empties the anchor is meaningless.
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('die der das');

        $this->assertSame('die der das', $trimmed);
        $this->assertSame(0, $shift);
    }

    public function test_does_not_strip_middle_stopwords(): void
    {
        // Only boundary stopwords are dropped. "Anleitung der API" stays
        // intact — the "der" carries grammatical meaning between two
        // content words and removing it would break the phrase.
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('Anleitung der API');

        $this->assertSame('Anleitung der API', $trimmed);
        $this->assertSame(0, $shift);
    }

    public function test_returns_correct_offset_shift_for_punctuated_leading_stopword(): void
    {
        // Combined case: leading stopword "the," (with attached comma)
        // dropped, then "documentation" kept. leadingShift must include
        // both the dropped word AND the trailing space — i.e. mb_strlen
        // of everything before parts[startIdx]. Caller adds this to the
        // raw match offset so the trimmed anchor still points at the
        // right span.
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('the, documentation');

        $this->assertSame('documentation', $trimmed);
        $this->assertSame(5, $shift, '"the, " (4 + 1 space) consumed');
    }

    public function test_handles_empty_input(): void
    {
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('');

        $this->assertSame('', $trimmed);
        $this->assertSame(0, $shift);
    }

    public function test_strips_both_boundaries_in_one_pass(): void
    {
        // Sanity: leading + trailing stopwords AND attached punctuation
        // on both ends — exercises every branch in one shot.
        [$trimmed, $shift] = \Arturrossbach\Linkwise\Support\TextNormalizer::trimBoundaryStopwords('the, Dokumentation, die');

        $this->assertSame('Dokumentation', $trimmed);
        $this->assertSame(5, $shift, '"the, " consumed; trailing ", die" stripped + comma cleanup');
    }
}
