<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\NLP\LanguageRegistry;
use Arturrossbach\Linkwise\NLP\Stemmer;
use Arturrossbach\Linkwise\NLP\Stopwords;
use PHPUnit\Framework\TestCase;

/**
 * Objective verification of the LanguageRegistry tier promises.
 *
 * For every CONFIDENT language we assert THREE things:
 *   1. Stemmer actually canonicalizes inflected forms (the marketing
 *      claim "inflection-aware matching" must be true per language)
 *   2. Stop-words list is non-empty (otherwise TF-IDF noise floods the
 *      keyword extractor)
 *   3. Stop-words list contains the language's most-common articles or
 *      pronouns (sanity check that we loaded the RIGHT language's data)
 *
 * For LIMITED languages we assert only (2) and (3) — stemming is
 * documented as exact-match-only for these.
 *
 * For BLOCKED languages we assert that the registry hard-blocks them
 * via tier() so the Settings UI never offers them.
 *
 * If a language fails its tier promise here, the README's compatibility
 * table is lying — fix the tier assignment in LanguageRegistry, don't
 * lower the test.
 */
class LanguageTierTest extends TestCase
{
    /**
     * Per-language inflection canonicalization fixtures. Each pair is
     * [inflected_form, base_form]. We assert stem(inflected) ===
     * stem(base) — Snowball maps both to the same root, which is what
     * makes the auto-link rule "Datenbank" match "Datenbanken" mentions.
     *
     * Pairs verified against the Snowball algorithm reference outputs
     * (snowballstem.org test suite).
     *
     * @return array<string, list<array{string, string}>>
     */
    private function inflectionFixtures(): array
    {
        return [
            'en' => [['running', 'run'], ['databases', 'database'], ['walked', 'walk']],
            'de' => [['Datenbanken', 'Datenbank'], ['Bibliotheken', 'Bibliothek'], ['gelaufen', 'laufen']],
            'fr' => [['bibliothèques', 'bibliothèque'], ['mangeons', 'manger']],
            'es' => [['bibliotecas', 'biblioteca'], ['corriendo', 'correr']],
            'it' => [['biblioteche', 'biblioteca'], ['mangiando', 'mangiare']],
            'nl' => [['boeken', 'boek'], ['lopen', 'loop']],
            'pt' => [['bibliotecas', 'biblioteca'], ['correndo', 'correr']],
            'sv' => [['böcker', 'bok'], ['springer', 'springa']],
            'da' => [['bøger', 'bog'], ['løber', 'løbe']],
            'no' => [['bøker', 'bok'], ['løper', 'løpe']],
            'fi' => [['kirjat', 'kirja'], ['juokseva', 'juosta']],
            'ro' => [['cărțile', 'carte'], ['alergând', 'alerga']],
            'ru' => [['книги', 'книга'], ['бегущий', 'бежать']],
            'ca' => [['biblioteques', 'biblioteca'], ['corrent', 'córrer']],
        ];
    }

    /**
     * Sanity-check tokens — the most common articles/pronouns/conjunctions
     * for each language. If the loaded stopwords list contains these, we
     * KNOW it's the right language's data (not English mislabeled, not
     * empty). Picked to be unambiguous (not loanwords across languages).
     *
     * @return array<string, list<string>>
     */
    private function sanityTokens(): array
    {
        return [
            // Confident
            'en' => ['the', 'and', 'is'],
            'de' => ['der', 'und', 'ist'],
            'fr' => ['le', 'la', 'est'],
            'es' => ['el', 'la', 'es'],
            'it' => ['il', 'la', 'è'],
            'nl' => ['de', 'het', 'een'],
            'pt' => ['o', 'a', 'é'],
            'sv' => ['och', 'är', 'jag'],
            'da' => ['og', 'er', 'jeg'],
            'no' => ['og', 'er', 'jeg'],
            'fi' => ['ja', 'on', 'ei'],
            'ro' => ['si', 'este', 'de'], // ISO list uses old-orthography 'şi'/'si', not new 'și'
            'ru' => ['и', 'в', 'не'],
            'ca' => ['el', 'la', 'i'],
            // Limited
            'hu' => ['és', 'a', 'az'],
            'pl' => ['i', 'jest', 'w'],
            'cs' => ['a', 'je', 'v'],
            'sk' => ['a', 'je', 'v'],
            'sl' => ['in', 'je', 'na'],
            'hr' => ['i', 'je', 'u'],
            'bg' => ['и', 'е', 'на'],
            'uk' => ['але', 'без', 'весь'], // ISO uk list is sparse; pick distinctive non-Russian words
            'lv' => ['un', 'ir', 'ar'],
            'lt' => ['ir', 'kad', 'ar'], // ISO lt list lacks 'yra'; ir+kad+ar are present
            'et' => ['ja', 'on', 'ei'],
            'ga' => ['agus', 'is', 'an'],
            'el' => ['ειναι', 'δεν', 'εκεινη'], // ISO el list uses unaccented forms
            'tr' => ['ve', 'bir', 'bu'],
        ];
    }

    /** Every CONFIDENT language must have a non-empty stopwords list. */
    public function test_confident_languages_have_stopwords(): void
    {
        foreach (array_keys(LanguageRegistry::byTier(LanguageRegistry::TIER_CONFIDENT)) as $code) {
            $words = Stopwords::forLanguage($code);
            $this->assertNotEmpty($words, "CONFIDENT '{$code}' should have stopwords from stopwords-iso");
            $this->assertGreaterThan(
                100,
                count($words),
                "CONFIDENT '{$code}' should have at least 100 stopwords (got " . count($words) . ')',
            );
        }
    }

    /** Each language's stopwords contain the language-specific sanity tokens. */
    public function test_languages_load_correct_stopwords_data(): void
    {
        $sanity = $this->sanityTokens();
        foreach ($sanity as $code => $tokens) {
            $tier = LanguageRegistry::tier($code);
            if ($tier === LanguageRegistry::TIER_BLOCKED) continue;
            $words = Stopwords::forLanguage($code);
            foreach ($tokens as $token) {
                $this->assertContains(
                    $token,
                    $words,
                    "Language '{$code}' (tier {$tier}) should have '{$token}' in its stopwords list — wrong language data loaded?",
                );
            }
        }
    }

    /** CONFIDENT languages canonicalize at least one inflected → base pair. */
    public function test_confident_stemming_canonicalizes_inflections(): void
    {
        foreach ($this->inflectionFixtures() as $code => $pairs) {
            $tier = LanguageRegistry::tier($code);
            $this->assertSame(
                LanguageRegistry::TIER_CONFIDENT,
                $tier,
                "Inflection fixture exists for '{$code}' but it's not in the CONFIDENT tier — registry mismatch",
            );
            $stemmer = new Stemmer($code);
            $matches = 0;
            foreach ($pairs as [$inflected, $base]) {
                if ($stemmer->stem($inflected) === $stemmer->stem($base)) {
                    $matches++;
                }
            }
            $this->assertGreaterThanOrEqual(
                1,
                $matches,
                "Stemmer for '{$code}' didn't canonicalize ANY of " . count($pairs) . ' inflection pairs',
            );
        }
    }

    /** LIMITED languages should NOT be in the Snowball-supported list. */
    public function test_limited_languages_have_no_snowball_stemmer(): void
    {
        $confidentCodes = LanguageRegistry::stemmerSupportedCodes();
        foreach (array_keys(LanguageRegistry::byTier(LanguageRegistry::TIER_LIMITED)) as $code) {
            // Greek and Turkish are exceptions — they HAVE Snowball stemmers
            // but are LIMITED for other reasons (sentence-boundary, lowercase
            // edge cases). Skip them in this assertion.
            if (in_array($code, ['el', 'tr'], true)) continue;
            $this->assertNotContains(
                $code,
                $confidentCodes,
                "LIMITED '{$code}' should not be in stemmerSupportedCodes — promote to CONFIDENT or fix the list",
            );
        }
    }

    /** BLOCKED languages must always report tier=BLOCKED. */
    public function test_blocked_languages_are_actually_blocked(): void
    {
        foreach (['ar', 'he', 'zh', 'ja', 'ko', 'th', 'vi'] as $code) {
            $this->assertSame(
                LanguageRegistry::TIER_BLOCKED,
                LanguageRegistry::tier($code),
                "'{$code}' must be tier BLOCKED — pipeline doesn't support its writing system",
            );
            $this->assertTrue(
                LanguageRegistry::isBlocked($code),
                "isBlocked('{$code}') must return true so the Settings UI hard-blocks it",
            );
        }
    }

    /** Unknown language code falls back to BLOCKED + a generic name. */
    public function test_unknown_language_treated_as_blocked(): void
    {
        $this->assertSame(LanguageRegistry::TIER_BLOCKED, LanguageRegistry::tier('xx'));
        $this->assertSame('xx', LanguageRegistry::name('xx'));
        $this->assertTrue(LanguageRegistry::isBlocked('xx'));
    }

    /** Stemmer respects the explicit language argument over global config. */
    public function test_stemmer_respects_explicit_language(): void
    {
        $en = new Stemmer('en');
        $de = new Stemmer('de');
        // English "databases" → "databas". German "Datenbanken" → "datenbank".
        // Different stemmers must produce different outputs for distinct inputs.
        $this->assertSame('en', $en->language());
        $this->assertSame('de', $de->language());
        $this->assertNotSame($en->stem('Datenbanken'), $de->stem('Datenbanken'));
    }

    /** LIMITED-tier stemmer falls through (returns input unchanged for ≥3-char). */
    public function test_limited_stemmer_is_pass_through(): void
    {
        $stemmer = new Stemmer('pl'); // No Snowball for Polish
        $this->assertSame('biblioteka', $stemmer->stem('biblioteka'));
        $this->assertSame('biblioteki', $stemmer->stem('biblioteki'));
    }
}
