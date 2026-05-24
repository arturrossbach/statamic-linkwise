<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Support\TextNormalizer;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Acceptance pins for the V1.x multilanguage track (2026-05-23).
 *
 * Two invariants under one roof:
 *
 *  1. Cross-locale targets MUST be filtered out. Statamic's
 *     `LinkMark::convertHref` auto-routes `statamic://entry::<uuid>`
 *     to the current site's localization via `$item->in(Site::current())`,
 *     falling back to the original entry when no localization exists.
 *     Suggesting a DE source link to an EN-only target therefore
 *     produces a wrong-language anchor that renders the EN URL into
 *     the DE page — editor-visible junk. Same-locale filter must
 *     prevent the suggestion from ever leaving the engine.
 *
 *  2. Single-site / legacy indices MUST behave exactly as before.
 *     `EntryRecord::$locale` is null when the Indexer has no
 *     locale to stamp (single-site Statamic) and on every record
 *     written by Linkwise <= v1.1.0. The same-locale filter
 *     short-circuits to "pass" whenever either side is null so the
 *     PR ships without forcing a reindex on existing installs.
 *
 *  3. Per-source-locale stemming closes the PR #100 root cause one
 *     level up: a DE source on an EN-default install used to be
 *     stemmed by the EN stemmer, letting "and" slip through as a
 *     content word. With the source's own locale driving the
 *     stemmer + stopword set, the cross-language mismatch can't
 *     occur in the first place.
 */
class SuggestionEngineLocaleScopingTest extends TestCase
{
    protected SuggestionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SuggestionEngine;
    }

    protected function record(string $id, string $title, ?string $locale, string $text = '', ?string $titleLocale = null): EntryRecord
    {
        return new EntryRecord(
            id: $id,
            title: $title,
            url: '/'.$id,
            collection: 'articles',
            text: $text,
            outboundLinks: [],
            locale: $locale,
            titleLocale: $titleLocale ?? $locale,
        );
    }

    public function test_cross_locale_target_is_filtered_out(): void
    {
        // DE source mentions "Datenbank" repeatedly. The EN target's title
        // would normally match through the stem-cluster path — except the
        // same-locale filter must reject it before any matching runs.
        $index = [
            'src' => $this->record('src', 'Source Article', 'de'),
            'tgt' => $this->record('tgt', 'Datenbank Optimierung', 'en'),
        ];
        $results = $this->engine->suggest(
            'Datenbank Optimierung ist wichtig für die Datenbank Performance.',
            $index,
            'src',
        );

        $this->assertEmpty($results, 'EN target must be filtered out for a DE source.');
    }

    public function test_same_locale_target_passes(): void
    {
        // Positive control: same setup as above but both sides DE. Without
        // a same-locale check the wrong-language case would also pass —
        // this test fails fast if the filter is over-eager.
        $index = [
            'src' => $this->record('src', 'Source Article', 'de'),
            'tgt' => $this->record('tgt', 'Datenbank Optimierung', 'de'),
        ];
        $results = $this->engine->suggest(
            'Datenbank Optimierung ist wichtig für die Datenbank Performance.',
            $index,
            'src',
        );

        $this->assertNotEmpty($results, 'Same-locale DE target must still produce a suggestion.');
    }

    public function test_null_source_locale_does_not_filter(): void
    {
        // Legacy / single-site: source record has no locale. Filter must
        // pass every target so existing installs see no behavior change
        // before they re-index.
        $index = [
            'src' => $this->record('src', 'Source Article', null),
            'tgt' => $this->record('tgt', 'Datenbank Optimierung', 'en'),
        ];
        $results = $this->engine->suggest(
            'Datenbank Optimierung ist wichtig für die Datenbank Performance.',
            $index,
            'src',
        );

        $this->assertNotEmpty($results, 'Null source locale must not filter targets.');
    }

    public function test_null_target_locale_does_not_filter(): void
    {
        // Half-migrated index: source carries locale, target doesn't (legacy
        // record not yet rebuilt). Must pass — a partial reindex shouldn't
        // silently make some targets invisible.
        $index = [
            'src' => $this->record('src', 'Source Article', 'de'),
            'tgt' => $this->record('tgt', 'Datenbank Optimierung', null),
        ];
        $results = $this->engine->suggest(
            'Datenbank Optimierung ist wichtig für die Datenbank Performance.',
            $index,
            'src',
        );

        $this->assertNotEmpty($results, 'Null target locale must not filter.');
    }

    public function test_source_outside_index_does_not_filter(): void
    {
        // suggest($text, $index, 'unsaved-draft') — the source isn't in the
        // index yet (preview path). Source locale resolves to null, filter
        // passes all targets. Sanity: no crash, no over-filter.
        $index = [
            'tgt' => $this->record('tgt', 'Datenbank Optimierung', 'en'),
        ];
        $results = $this->engine->suggest(
            'Datenbank Optimierung ist wichtig für die Datenbank Performance.',
            $index,
            'unsaved-draft',
        );

        $this->assertNotEmpty($results, 'Source outside index must not crash or over-filter.');
    }

    public function test_pr100_root_cause_no_coordinator_anchor_when_locale_matches(): void
    {
        // PR #100 reproduction shape but fixed at the root: source is EN,
        // target is EN. With per-source-locale stemming, "and" is filtered
        // as an EN stopword BEFORE it reaches the cluster builder. Even
        // without the coordinator-reject heuristic, no "and"-bridged Müll
        // anchor can form.
        $index = [
            'src' => $this->record('src', 'Source Article', 'en'),
            'tgt' => $this->record('tgt', 'Analytics and Measuring Content Performance', 'en'),
        ];
        $results = $this->engine->suggest(
            'We track analytics and performance closely.',
            $index,
            'src',
        );

        // Collect every anchor; assert none carry the coordinator. Done as
        // a single assertion (instead of foreach + assert) so the test isn't
        // flagged risky when $results comes back empty — empty is a valid
        // pass shape too (no suggestion at all > a Müll suggestion).
        $anchors = array_map(fn ($r) => $r->anchorText, $results);
        $bridged = array_filter($anchors, fn ($a) => str_contains($a, ' and '));
        $this->assertEmpty($bridged, 'No anchor may carry the bare "and" coordinator: '.implode(' | ', $anchors));
    }

    public function test_tokenize_with_mapping_for_uses_target_language_stopwords(): void
    {
        // Direct exercise of the new TextNormalizer entry point. When asked
        // to tokenize a German sentence with locale="de", "und" is filtered
        // (German stopword). With locale="en" on the same sentence, "und"
        // is NOT in the EN stopword list and survives — proves the locale
        // parameter routes to the right list.
        [$deTokens] = TextNormalizer::tokenizeWithMappingFor('Datenbank und Optimierung', 'de');
        [$enTokens] = TextNormalizer::tokenizeWithMappingFor('Datenbank und Optimierung', 'en');

        $this->assertNotContains('und', $deTokens, '"und" must be filtered by the German stopword list.');
        $this->assertContains('und', $enTokens, '"und" must survive the English stopword list.');
    }

    public function test_french_coordinator_does_not_bridge_anchor(): void
    {
        // PR #102 audit E2 — FR site, FR title with stem-cluster + "et" gap
        // in source content. Pre-E2 the coordinator list was EN+DE only, so
        // "et" passed as a content word and produced "performance et
        // optimisation" Müll. With per-locale coordinators, "et" is rejected.
        $index = [
            'src' => $this->record('src', 'Source Article', 'fr'),
            'tgt' => $this->record('tgt', 'Performance Réglage Optimisation', 'fr'),
        ];
        $results = $this->engine->suggest(
            'Nous avons besoin de optimisation et performance pour le site.',
            $index,
            'src',
        );

        $anchors = array_map(fn ($r) => $r->anchorText, $results);
        $bridged = array_filter($anchors, fn ($a) => str_contains(mb_strtolower($a), ' et '));
        $this->assertEmpty($bridged, 'No FR anchor may bridge via "et": '.implode(' | ', $anchors));
    }

    public function test_spanish_coordinator_does_not_bridge_anchor(): void
    {
        // PR #102 audit E2 — ES site, ES title with stem-cluster + "y" gap.
        $index = [
            'src' => $this->record('src', 'Source Article', 'es'),
            'tgt' => $this->record('tgt', 'Rendimiento Ajuste Optimización', 'es'),
        ];
        $results = $this->engine->suggest(
            'Necesitamos optimización y rendimiento para el sitio.',
            $index,
            'src',
        );

        $anchors = array_map(fn ($r) => $r->anchorText, $results);
        $bridged = array_filter($anchors, fn ($a) => str_contains(mb_strtolower($a), ' y '));
        $this->assertEmpty($bridged, 'No ES anchor may bridge via "y": '.implode(' | ', $anchors));
    }

    public function test_a1_title_localizable_false_uses_origin_locale_for_stemming(): void
    {
        // PR #102 audit A1 — DE-localization of an EN-origin with
        // `localizable: false` on title. The inherited English title is
        // stemmed with EN-stemmer (via titleLocale), not DE-stemmer.
        // Without A1, the DE-source's "und" coordinator would be filtered
        // by the GERMAN coordinator-list (correct) but the title stems
        // would be produced by the German stemmer applied to English text
        // (PR #100 root cause). With A1: title-paths use the title's
        // actual language, body-filter still passes (locale match).
        //
        // Smoke: same call signature, different titleLocale, different
        // engine behavior on title-stem generation. We assert the engine
        // doesn't crash and produces ZERO Müll anchors (the EN title
        // wouldn't produce real German stems even by accident).
        $index = [
            'src' => $this->record('src', 'Deutscher Quelltitel', 'de'),
            'tgt' => $this->record('tgt', 'Analytics and Measuring Content', 'de', titleLocale: 'en'),
        ];
        $results = $this->engine->suggest(
            'Wir messen Analytics und Performance über externe Tools.',
            $index,
            'src',
        );

        // The English title stemmed with the EN-stemmer produces stems like
        // "analyt"/"measur"/"content" which don't match the DE-stemmed
        // source ("messen"→"mess", "Performance"→"performanc", "Tools"→
        // "tool"). Either zero suggestions or — if a happy-accident match
        // surfaces — no anchor may carry an interior "und" coordinator
        // (since that would mean the title stems WERE German, i.e. the
        // bug we're guarding against).
        $anchors = array_map(fn ($r) => $r->anchorText, $results);
        $bridged = array_filter($anchors, fn ($a) => str_contains(mb_strtolower($a), ' und '));
        $this->assertEmpty($bridged, 'titleLocale=en must use EN-stemmer on the English title (no "und"-bridged anchor): '.implode(' | ', $anchors));
    }

    public function test_d1_generate_match_phrases_uses_target_locale_stopwords(): void
    {
        // PR #102 audit D1 — generateMatchPhrases routes through
        // stripLeadingStopwords + stripTrailingStopwords. With a DE title
        // and DE source, the leading "Die" must be strippable to also
        // surface "Optimierung der Datenbank" as a candidate phrase.
        // Without per-locale routing, the EN-default install would leave
        // the "Die" in and miss the phrase variant.
        $engine = $this->engine;
        $phrases = $engine->generateMatchPhrases('Die Optimierung der Datenbank', 'de');

        $this->assertContains('die optimierung der datenbank', array_map('mb_strtolower', $phrases));
        $stripped = array_filter($phrases, fn ($p) => str_starts_with(mb_strtolower($p), 'optimierung'));
        $this->assertNotEmpty($stripped, 'DE leading "Die" must be strippable: '.implode(' | ', $phrases));
    }

    public function test_e3_trim_boundary_stopwords_uses_target_locale(): void
    {
        // PR #102 audit E3 — trimBoundaryStopwords with DE locale trims a
        // DE-leading stopword that EN-stopwords would have left in.
        [$trimmed, $shift] = TextNormalizer::trimBoundaryStopwords('die Datenbank Optimierung', 'de');

        $this->assertSame('Datenbank Optimierung', $trimmed);
        $this->assertSame(4, $shift, '4-char shift = "die "');
    }

    public function test_e3_trim_boundary_stopwords_null_locale_falls_back_to_global(): void
    {
        // Single-site / legacy path: null locale → global stopword list.
        // Identical output to no-arg call.
        $a = TextNormalizer::trimBoundaryStopwords('the database optimization');
        $b = TextNormalizer::trimBoundaryStopwords('the database optimization', null);

        $this->assertSame($a, $b);
    }

    public function test_tokenize_with_mapping_for_null_locale_delegates_to_global(): void
    {
        // Backwards-compat path: null locale must produce the same output
        // as the unparameterized tokenizeWithMapping (which 15+ existing
        // callers still use). Hard contract — otherwise migrating one
        // caller would silently diverge from the rest.
        $text = 'Datenbank Optimierung Performance Tuning';
        $a = TextNormalizer::tokenizeWithMapping($text);
        $b = TextNormalizer::tokenizeWithMappingFor($text, null);

        $this->assertSame($a, $b);
    }
}
