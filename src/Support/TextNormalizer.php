<?php

namespace Arturrossbach\Linkwise\Support;

use Arturrossbach\Linkwise\NLP\Stemmer;
use Arturrossbach\Linkwise\NLP\Stopwords;

/**
 * Pure stateless text-normalization + tokenization helpers.
 *
 * REV-DR-02 Phase A (2026-05-13): extracted from SuggestionEngine, where
 * 8 instance methods covered the same stateless concern (lowercase /
 * punctuation-strip / whitespace-collapse / Snowball-stem / stopword-trim).
 * Same SuggestionEngine plus the InboundEngine custom-keyword path
 * eventually need these helpers without forcing the SuggestionEngine
 * service-binding through the dependency graph.
 *
 * All methods are `public static` — none reference `$this` in the original,
 * so the extraction is mechanical. Config-derived stopwords are still fetched
 * via NLP\Stopwords::forConfig(); the call is wrapped to keep the helper
 * usable from places that don't bootstrap Statamic config.
 */
class TextNormalizer
{
    /**
     * Normalize text for matching: lowercase, collapse whitespace, strip punctuation.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Replace punctuation with spaces (keep hyphens between words)
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text);
        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Tokenize text into significant, stemmed words (for keyword matching).
     *
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        [$stemmed] = static::tokenizeWithMapping($text);

        return $stemmed;
    }

    /**
     * Tokenize and stem text, returning both stemmed tokens and a stem→original mapping.
     *
     * @return array{0: string[], 1: array<string, string>}  [stemmedTokens, stemToOriginal]
     */
    public static function tokenizeWithMapping(string $text): array
    {
        return static::tokenizeWithMappingFor($text, null);
    }

    /**
     * Same as {@see tokenizeWithMapping()} but uses an explicit ISO-639-1
     * locale for both the stopword filter and the stemmer. Multisite
     * locale-scoping (V1.x): the SuggestionEngine reads the source entry's
     * locale from {@see \Arturrossbach\Linkwise\Indexer\EntryRecord::$locale}
     * and tokenizes the source text with the matching language, so a DE
     * source on an EN-default install isn't stemmed as English. Closes the
     * PR #100 root cause (language-mismatch in the stem fallback) one level
     * up, instead of patching individual coordinator-stopword leaks.
     *
     * Null locale ≡ legacy global behavior — falls back to
     * {@see resolveStemmer()} + {@see getStopwords()}.
     *
     * @return array{0: string[], 1: array<string, string>}  [stemmedTokens, stemToOriginal]
     */
    public static function tokenizeWithMappingFor(string $text, ?string $locale): array
    {
        $normalized = static::normalize($text);
        $words = explode(' ', $normalized);

        $stopwords = $locale !== null
            ? array_flip(Stopwords::forLanguage($locale))
            : null;
        $stemmer = $locale !== null
            ? new Stemmer($locale)
            : static::resolveStemmer();

        $stemmed = [];
        $stemToOriginal = [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $isStop = $stopwords !== null
                ? isset($stopwords[$word])
                : static::isStopword($word);
            if ($isStop) {
                continue;
            }
            $stem = $stemmer->stem($word);
            $stemmed[] = $stem;
            if (! isset($stemToOriginal[$stem])) {
                $stemToOriginal[$stem] = $word;
            }
        }

        return [$stemmed, $stemToOriginal];
    }

    /**
     * Drop leading stopwords from a word list and return the remainder as a
     * single space-joined string. Used by generateMatchPhrases.
     *
     * @param  string[]  $words
     */
    public static function stripLeadingStopwords(array $words): string
    {
        while (! empty($words) && static::isStopword($words[0])) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    /**
     * Trim leading + trailing stopwords from a matched anchor span. Middle
     * stopwords stay — "interne Verlinkung als Bestandteil" is a legitimate
     * highlight where "als" connects two content words. Only the dangling
     * boundary stopwords ("als gleichberechtigter Bestandteil" → "gleich-
     * berechtigter Bestandteil", "interne Verlinkung als" → "interne
     * Verlinkung") get cleaned up.
     *
     * Returns [trimmedAnchor, leadingOffset] — the offset is the byte/char
     * shift the caller must add to the original match position so the
     * trimmed anchor still points at the right span. Falls back to the
     * original if trimming would leave fewer than 2 words.
     *
     * @return array{0: string, 1: int}  [trimmedText, leadingShiftChars]
     */
    public static function trimBoundaryStopwords(string $anchor): array
    {
        // Preserve original whitespace/punctuation between words by working
        // on a regex split that captures separators.
        $parts = preg_split('/(\s+)/u', $anchor, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts) || empty($parts)) {
            return [$anchor, 0];
        }
        // $parts alternates: word, sep, word, sep, ...
        // Word indexes are even (0,2,4,...).
        $wordIndexes = [];
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0 && $p !== '') $wordIndexes[] = $i;
        }
        if (empty($wordIndexes)) {
            return [$anchor, 0];
        }
        // Stopword check ignores attached punctuation: "die," and "die." both
        // count as the German stopword "die". Otherwise "Dokumentation, die"
        // would never trim because the trailing word is "die" with no
        // attached punctuation but real-world matches like "Dokumentation, die"
        // (split: ["Dokumentation," " " "die"]) need both sides cleaned.
        $stripPunct = fn (string $w): string => preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', mb_strtolower($w));

        // Walk forward, drop while leading word is a stopword (with attached
        // punctuation stripped before the check).
        $first = 0;
        while ($first < count($wordIndexes)
            && static::isStopword($stripPunct($parts[$wordIndexes[$first]]))) {
            $first++;
        }
        // Walk backward, drop while trailing word is a stopword.
        $last = count($wordIndexes) - 1;
        while ($last >= $first
            && static::isStopword($stripPunct($parts[$wordIndexes[$last]]))) {
            $last--;
        }
        // Empty result (every word was a stopword) → keep original. A single
        // content word IS allowed to survive the trim — that's still a
        // legitimate anchor (e.g. "Dokumentation" for the title "Dokumentation,
        // die wirklich gelesen wird").
        if ($last < $first) {
            return [$anchor, 0];
        }
        $startIdx = $wordIndexes[$first];
        $endIdx = $wordIndexes[$last];
        // Strip leading and trailing non-letter characters from the final
        // boundary words too — "Dokumentation," → "Dokumentation". Trailing
        // commas/periods don't belong in a hyperlink anchor.
        $leadingPunctRe = '/^[^\p{L}\p{N}]+/u';
        $trailingPunctRe = '/[^\p{L}\p{N}]+$/u';
        $leadingPunctShift = 0;
        if (preg_match($leadingPunctRe, $parts[$startIdx], $m)) {
            $leadingPunctShift = mb_strlen($m[0]);
            $parts[$startIdx] = mb_substr($parts[$startIdx], $leadingPunctShift);
        }
        $parts[$endIdx] = preg_replace($trailingPunctRe, '', $parts[$endIdx]);

        $leadingShift = mb_strlen(implode('', array_slice($parts, 0, $startIdx))) + $leadingPunctShift;
        $trimmed = implode('', array_slice($parts, $startIdx, $endIdx - $startIdx + 1));

        return [$trimmed, $leadingShift];
    }

    /**
     * Drop trailing stopwords from a word list and return the remainder.
     *
     * @param  string[]  $words
     */
    public static function stripTrailingStopwords(array $words): string
    {
        while (! empty($words) && static::isStopword(end($words))) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    public static function isStopword(string $word): bool
    {
        return in_array($word, static::getStopwords(), true);
    }

    /** @return string[] */
    public static function getStopwords(): array
    {
        return Stopwords::forConfig();
    }

    /**
     * Resolve the project's Stemmer wrapper. Mirrors SuggestionEngine's
     * `new Stemmer` usage — language resolved via LanguageRegistry::resolve().
     */
    protected static function resolveStemmer(): Stemmer
    {
        return new Stemmer;
    }
}
