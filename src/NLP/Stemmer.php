<?php

namespace Arturrossbach\Linkwise\NLP;

use Wamania\Snowball\StemmerFactory;

/**
 * Stemmer wrapper around wamania/php-stemmer (Snowball). Resolves the
 * configured language via LanguageRegistry::resolve() if no explicit
 * language is passed, so all callers automatically get the right stemmer
 * for the site's content without each one needing to read config.
 *
 * Languages outside the CONFIDENT tier (no Snowball stemmer available)
 * get a no-op pass-through — the pipeline still works, but inflected
 * forms won't canonicalize. This matches the documented LIMITED-tier
 * behavior in LanguageRegistry.
 */
class Stemmer
{
    /** Cache of language-code → wamania stemmer instance. */
    protected array $cache = [];

    protected string $language;

    public function __construct(?string $language = null)
    {
        $this->language = $language ?? LanguageRegistry::resolve();
    }

    /**
     * Stem a single word. Returns the original on any failure (unknown
     * language, sub-3-char word, library error). Never throws.
     */
    public function stem(string $word): string
    {
        if (mb_strlen($word) < 3) {
            return $word;
        }
        if (! in_array($this->language, LanguageRegistry::stemmerSupportedCodes(), true)) {
            // LIMITED / BLOCKED tier — exact-match fallback.
            return $word;
        }
        try {
            return $this->stemmerFor($this->language)->stem($word);
        } catch (\Throwable) {
            return $word;
        }
    }

    /**
     * Stem an array of words. Same semantics as stem() per element.
     *
     * @param  string[]  $words
     * @return string[]
     */
    public function stemAll(array $words): array
    {
        return array_map(fn (string $w) => $this->stem($w), $words);
    }

    /**
     * Currently-effective language (resolved at construction time).
     */
    public function language(): string
    {
        return $this->language;
    }

    /**
     * Lazy-init the wamania Snowball stemmer for a language code. We
     * cache per-instance because StemmerFactory::create() reflects on
     * the language list every call — small overhead, but adds up across
     * thousands of stem() calls during indexing.
     */
    protected function stemmerFor(string $lang): object
    {
        if (! isset($this->cache[$lang])) {
            $this->cache[$lang] = StemmerFactory::create($lang);
        }
        return $this->cache[$lang];
    }
}
