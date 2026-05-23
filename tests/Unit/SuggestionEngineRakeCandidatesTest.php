<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Acceptance pins for the RAKE-style anchor-candidate refactor
 * (user-bug 2026-05-23: trash anchors like "performance and" /
 * "and content" surfaced at 60% title-match score because the old
 * n-gram loop accepted boundary-stopword phrases).
 *
 * The refactor replaces the all-n-grams generator with RAKE Step 1+2:
 * split the normalized title at stopwords, emit content-word-runs as
 * candidates. Linkwise-specific tunings on top:
 *
 *  - The full normalized title stays as one candidate (rescue for
 *    "Tip of the Iceberg" — single-word runs would lose the phrase).
 *  - Leading/trailing stopword-stripped variants (`core` / `coreTail`
 *    / `coreBoth`) stay as rescue candidates.
 *  - `minPhraseWords` (default 2) filters out one-word candidates
 *    from the RAKE runs; the rescue phrases bypass this floor when
 *    the full title only carries content via stopword bridges.
 *
 * Original RAKE (Rose et al. 2010) describes an "adjoining keywords"
 * heuristic for interior stopwords (rejoin pairs that co-occur 2+
 * times in the document). Linkwise operates per title (5-10 words,
 * never repeated co-occurrence). The rescue phrases cover that case
 * instead, mirroring the JRC1995 / fast-rake convention of dropping
 * the adjoining heuristic for short texts.
 *
 * Linked memo: [[architectural_health]] — new bug class
 * "Anchor-Candidate Boundary-Stopword Leak".
 */
class SuggestionEngineRakeCandidatesTest extends TestCase
{
    protected SuggestionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SuggestionEngine;
    }

    public function test_user_bug_case_database_design_title(): void
    {
        // User-bug example 2026-05-23:
        // "Phrase: Good database design is fundamental to application
        //  performance and maintainability — aktuell wird 'performance
        //  and' als anchor vorgeschlagen. mit rake gebe es hier doch
        //  garkeine suggestion? oder wäre das 'application performance'?"
        //
        // RAKE-Split on stopwords [good, is, to, and] (yes, "good" is in
        // the English stopword list — it's a positive-sentiment filler
        // word treated as a delimiter, same as "best" / "great" / etc.):
        //   good [database design] is [fundamental] to [application performance] and [maintainability]
        //   → "database design" (2 content words) ✓
        //   → "fundamental" (1 word) → dropped by minPhraseWords=2
        //   → "application performance" (2 content words) ✓
        //   → "maintainability" (1 word) → dropped by minPhraseWords=2
        $phrases = $this->engine->generateMatchPhrases(
            'Good database design is fundamental to application performance and maintainability',
        );

        $this->assertContains('database design', $phrases,
            'Content-word run after leading "good" stopword must surface as a RAKE candidate.');
        $this->assertContains('application performance', $phrases,
            'Content-word run between stopwords must be a candidate.');

        // Hard rejections — the user-reported trash:
        $this->assertNotContains('performance and', $phrases,
            'Trailing-stopword phrase MUST NOT survive RAKE split (user-bug).');
        $this->assertNotContains('and maintainability', $phrases,
            'Leading-stopword phrase MUST NOT survive RAKE split (user-bug).');
        $this->assertNotContains('and content', $phrases,
            'Leading-stopword phrase MUST NOT survive RAKE split (user-bug).');
        $this->assertNotContains('fundamental to application', $phrases,
            'Mid-stopword bridging MUST NOT survive — adjoining heuristic is off.');
    }

    public function test_user_bug_case_performance_tuning_title(): void
    {
        // Sister case from user bug report.
        // Split on [and]: [performance tuning] and [optimization tactics]
        $phrases = $this->engine->generateMatchPhrases('Performance Tuning and Optimization Tactics');

        $this->assertContains('performance tuning', $phrases);
        $this->assertContains('optimization tactics', $phrases);

        $this->assertNotContains('performance tuning and', $phrases);
        $this->assertNotContains('and optimization', $phrases);
        $this->assertNotContains('and optimization tactics', $phrases);
    }

    public function test_full_title_phrase_survives_for_interior_stopwords(): void
    {
        // "Tip of the Iceberg" — every content-word run is 1 word ('tip',
        // 'iceberg'). minPhraseWords=2 would otherwise drop everything.
        // Linkwise's Full-Title rescue keeps the entire normalized title
        // as one candidate so the phrase still matches when an editor
        // writes "tip of the iceberg" in source content.
        $phrases = $this->engine->generateMatchPhrases('Tip of the Iceberg');

        $this->assertContains('tip of the iceberg', $phrases,
            'Full-title rescue must preserve the title even when no content-word run >= minPhraseWords exists.');
    }

    public function test_leading_stopword_strip_rescue(): void
    {
        // "How to Configure Redis" — RAKE-split on [how, to] yields
        // [configure redis] (2 content words) ✓. AND the existing
        // leading-stopword strip path produces "configure redis" too.
        // The rescue should still work — pin its result.
        $phrases = $this->engine->generateMatchPhrases('How to Configure Redis');

        $this->assertContains('configure redis', $phrases,
            'Leading-stopword strip + RAKE-split both produce "configure redis".');
        $this->assertNotContains('how to configure', $phrases,
            'Leading-stopword prefix MUST NOT survive.');
    }

    public function test_short_clean_title_passes_through(): void
    {
        // No stopwords inside — title IS the only content-word run.
        $phrases = $this->engine->generateMatchPhrases('Redis Setup Guide');

        $this->assertContains('redis setup guide', $phrases);
    }

    public function test_min_phrase_words_drops_single_word_runs(): void
    {
        // "Cats and Dogs" — split on [and] → [cats] (1) + [dogs] (1).
        // Both fall under min=2. Only the full-title rescue survives.
        $phrases = $this->engine->generateMatchPhrases('Cats and Dogs');

        $this->assertContains('cats and dogs', $phrases,
            'Full-title rescue covers the case where every RAKE run is too short.');
        $this->assertNotContains('cats', $phrases,
            'Single-word runs MUST be dropped by minPhraseWords (default 2).');
        $this->assertNotContains('dogs', $phrases);
    }
}
