<?php

namespace Arturrossbach\Linkwise\NLP;

/**
 * Stop-word lookup backed by stopwords-iso (MIT, github.com/stopwords-iso/
 * stopwords-iso). The JSON ships in resources/data/stopwords-iso.json and
 * covers 58 languages with curated lists (e.g. EN: 1298 words, DE: 620,
 * FR: 691) — a substantial improvement over the small hand-picked lists
 * we used pre-2026-05.
 *
 * The legacy English/German hand-picked methods are retained for back-
 * compat with anyone calling them directly, but the default path now
 * routes through the ISO data.
 */
class Stopwords
{
    /** @var array<string, list<string>>|null  Lazy-loaded JSON cache. */
    private static ?array $isoCache = null;

    /**
     * Stop-words for the configured language plus any custom stop-words
     * from settings. Falls back to default ('en') when config is empty
     * or points at an unknown language.
     *
     * @return string[]
     */
    public static function forConfig(): array
    {
        // When no language is explicitly configured, fall back to the
        // bilingual EN+DE list — preserves the long-standing behavior for
        // dual-language editorial sites that haven't picked a language
        // yet. resolve() returns DEFAULT_LANG ('en') when nothing's set,
        // but in that "implicit" case we merge German for back-compat.
        $configured = null;
        try {
            $configured = config('linkwise.language');
            $customRaw = (string) (config('linkwise.custom_stopwords', '') ?? '');
        } catch (\Throwable) {
            $customRaw = '';
        }
        $stopwords = (is_string($configured) && isset(LanguageRegistry::LANGUAGES[$configured]))
            ? static::forLanguage($configured)
            : static::default();

        if (trim($customRaw) !== '') {
            $custom = array_filter(
                array_map('trim', explode("\n", mb_strtolower($customRaw))),
                fn ($w) => $w !== '',
            );
            $stopwords = array_merge($stopwords, $custom);
        }

        return array_values(array_unique($stopwords));
    }

    /**
     * Stop-words for a specific language code. Returns the ISO list when
     * available; falls back to English when the code is unknown so the
     * pipeline never runs with zero filtering.
     *
     * @return list<string>
     */
    public static function forLanguage(string $code): array
    {
        $iso = static::iso();
        return $iso[$code] ?? $iso['en'] ?? [];
    }

    /**
     * Lazy-load the bundled stopwords-iso JSON. The file is ~200KB and
     * decoded once per process. Returns [] on missing/corrupt data so
     * the rest of the NLP pipeline degrades gracefully (matches still
     * work, they just don't filter common words).
     *
     * @return array<string, list<string>>
     */
    public static function iso(): array
    {
        if (self::$isoCache !== null) {
            return self::$isoCache;
        }
        $path = __DIR__.'/../../resources/data/stopwords-iso.json';
        if (! file_exists($path)) {
            return self::$isoCache = [];
        }
        try {
            $raw = file_get_contents($path);
            $data = $raw ? json_decode($raw, true) : null;
            self::$isoCache = is_array($data) ? $data : [];
        } catch (\Throwable) {
            self::$isoCache = [];
        }
        return self::$isoCache;
    }

    /**
     * Legacy: English + German merged. Kept for callers that explicitly
     * want this (e.g. multi-language test sites). Equivalent shape to
     * stopwords-iso's en+de combined.
     *
     * @return string[]
     */
    public static function default(): array
    {
        $iso = static::iso();
        return array_values(array_unique(array_merge($iso['en'] ?? [], $iso['de'] ?? [])));
    }

    /**
     * Legacy alias — returns stopwords-iso English. Keep until callers
     * migrate to forLanguage('en').
     *
     * @return list<string>
     */
    public static function english(): array
    {
        return static::iso()['en'] ?? [];
    }

    /**
     * Legacy alias — returns stopwords-iso German. Keep until callers
     * migrate to forLanguage('de').
     *
     * @return list<string>
     */
    public static function german(): array
    {
        return static::iso()['de'] ?? [];
    }
}
