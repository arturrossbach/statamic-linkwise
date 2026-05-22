<?php

namespace Arturrossbach\Linkwise\NLP;

/**
 * Second-stage stop-word filter backed by per-language stem sets derived
 * from `hermitdave/FrequencyWords` (MIT, Open-Subtitles 2018 corpus).
 *
 * ## Why a second stage
 *
 * The ISO list ({@see Stopwords}) carries ~620 hand-picked structural
 * words per language — articles, pronouns, auxiliaries, prepositions.
 * Critical, but tiny. Real content is full of mid-frequency words that
 * are not structurally meaningless but also not meaningful enough to
 * become a link anchor: `funktioniert`, `richtige`, `spricht`, `ruhig`,
 * `vernachlässigt`, `dinge`, `feiern`. None of these are in the ISO
 * list. All of them are in the frequency list.
 *
 * Surface match alone would still miss inflected forms (`vernachlässig-
 * ten` is not in the 50k surface list, but `vernachlässigt` is). The
 * built JSON contains pre-stemmed forms only, so a one-step stem-match
 * at runtime closes the inflection gap.
 *
 * ## Title-Protect
 *
 * Corpus statistics don't know author intent. The title does. If a
 * word's stem appears in the entry's title, it survives the filter
 * regardless of its frequency. This is standard fielded-retrieval
 * (BM25F) thinking: title-bearing terms get protected because they're
 * the editor's explicit relevance signal. A blog post about "Notebook
 * Reviews" will have `notebook` in its title — even though `notebook`
 * is rank 49.078 in the German list, it remains a keyword for that
 * specific post. A different post merely mentioning `notebook` in
 * passing doesn't get that protection.
 *
 * ## Falls back gracefully
 *
 * - Languages without a frequency-stems JSON (Limited tier, edge codes
 *   we never built) → `isCommonStopword()` returns `false` for every
 *   call. Pipeline degrades to "ISO list only" — same behaviour as
 *   before this filter existed.
 * - Missing/corrupt JSON → empty set, same fallback.
 *
 * @see BuildFrequencyStemsCommand for the build pipeline.
 */
class FrequencyFilter
{
    /** @var array<string, array<string, bool>>|null  lang code → stem→true map (hash for O(1) lookup). */
    private static ?array $cache = null;

    /**
     * True if `$stem` is a common-language word for the active language
     * AND not protected by appearing in `$titleStems`. The composition
     * is intentional: it's the only public contract the KeywordExtractor
     * needs to know. Two-set lookup, both O(1).
     *
     * @param  array<string, bool>  $titleStems  set of stems present in
     *   the entry's title (already stemmed via the same stemmer). Pass
     *   `[]` for "no title context" — every common word will be filtered.
     */
    public function isCommonStopword(string $stem, array $titleStems = []): bool
    {
        if ($stem === '') {
            return false;
        }
        if (isset($titleStems[$stem])) {
            // Title-Protect: editor's explicit relevance signal beats
            // corpus frequency. Even if the stem is in the frequency
            // list (e.g. `Rezept`, `Notebook`), it stays a keyword
            // because the post-author put it in the title.
            return false;
        }

        return isset($this->stemSetForActiveLanguage()[$stem]);
    }

    /**
     * Pre-compute a title-stems map from a list of raw title words.
     * The KeywordExtractor would normally do this itself, but the
     * helper makes the call sites readable and the cache cheap.
     *
     * @param  string[]  $stems  already-stemmed title tokens.
     * @return array<string, bool>
     */
    public function titleStemsSet(array $stems): array
    {
        $set = [];
        foreach ($stems as $stem) {
            if ($stem !== '') {
                $set[$stem] = true;
            }
        }

        return $set;
    }

    /**
     * Currently-active language code, resolved via the same code path
     * as {@see Stemmer} so the filter operates on stems compatible
     * with whatever the indexer is stemming with.
     */
    public function activeLanguage(): string
    {
        return LanguageRegistry::resolve();
    }

    /**
     * Lazy-load + cache the stem set for the active language. Returns
     * an empty array when no JSON ships for the language — pipeline
     * then degrades to "ISO list only" behaviour, which is the same
     * as before this filter existed.
     *
     * @return array<string, bool>
     */
    protected function stemSetForActiveLanguage(): array
    {
        $lang = $this->activeLanguage();
        if (self::$cache === null) {
            self::$cache = [];
        }
        if (isset(self::$cache[$lang])) {
            return self::$cache[$lang];
        }

        $path = __DIR__."/../../resources/data/frequency-stems-{$lang}.json";
        if (! file_exists($path)) {
            return self::$cache[$lang] = [];
        }

        try {
            $raw = file_get_contents($path);
            $data = $raw ? json_decode($raw, true) : null;
            if (! is_array($data)) {
                return self::$cache[$lang] = [];
            }
            // Convert list → set for O(1) lookup. The JSON is sorted
            // alphabetically (BuildFrequencyStemsCommand convention)
            // — irrelevant here, only membership matters.
            $set = [];
            foreach ($data as $stem) {
                if (is_string($stem) && $stem !== '') {
                    $set[$stem] = true;
                }
            }

            return self::$cache[$lang] = $set;
        } catch (\Throwable) {
            return self::$cache[$lang] = [];
        }
    }

    /**
     * Test-only — reset the static cache so unit tests can swap languages
     * cleanly between cases. Production callers don't need this.
     */
    public static function clearCacheForTesting(): void
    {
        self::$cache = null;
    }
}
