<?php

namespace Arturrossbach\Linkwise\NLP;

/**
 * Single source of truth for which content languages Linkwise supports.
 *
 * Three tiers, derived from objective code-level capability:
 *
 *  - CONFIDENT: Snowball stemmer (via wamania/php-stemmer) + stopwords-iso
 *    list + space-tokenized writing system + Western sentence punctuation.
 *    Auto-link rules canonicalize inflected forms (Datenbanken → Datenbank,
 *    bibliothèques → bibliothèqu). Equivalent quality to English.
 *
 *  - LIMITED: stopwords-iso list available, but no Snowball stemmer or one
 *    of the language-specific edge cases (Greek `;` sentence boundary,
 *    Turkish dotted/dotless-i lowercase) that Linkwise doesn't handle yet.
 *    Auto-link is exact-match only — plurals, conjugations and other
 *    inflected forms won't match a base-form rule.
 *
 *  - BLOCKED: no space-based word boundaries (CJK + Thai + Vietnamese with
 *    syllable structure) or RTL (Arabic, Hebrew). The `\b`-style matching
 *    Linkwise uses doesn't apply; we hard-block these in the dropdown so
 *    users can't silently configure something that won't work.
 *
 * The README and the Settings UI BOTH read from this registry — keeping
 * the marketing claim and the runtime configuration in lock-step.
 */
class LanguageRegistry
{
    public const TIER_CONFIDENT = 'confident';
    public const TIER_LIMITED = 'limited';
    public const TIER_BLOCKED = 'blocked';

    /**
     * Each entry: ISO 639-1 code → metadata. The keys here map 1:1 to:
     *   - wamania/php-stemmer (for CONFIDENT)
     *   - stopwords-iso JSON (for CONFIDENT + LIMITED)
     *
     * The `notes` field is what the Settings dropdown shows as a tooltip
     * AND what the README's compatibility table renders verbatim — so it
     * needs to be honest, single-sentence, and stable across releases.
     */
    public const LANGUAGES = [
        // ─── CONFIDENT TIER (14) ────────────────────────────────────────
        'en' => ['name' => 'English',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 1298 stop-words.'],
        'de' => ['name' => 'German',     'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 620 stop-words. Umlaut-aware.'],
        'fr' => ['name' => 'French',     'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 691 stop-words.'],
        'es' => ['name' => 'Spanish',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 732 stop-words.'],
        'it' => ['name' => 'Italian',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 632 stop-words.'],
        'nl' => ['name' => 'Dutch',      'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 413 stop-words.'],
        'pt' => ['name' => 'Portuguese', 'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 560 stop-words.'],
        'sv' => ['name' => 'Swedish',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 418 stop-words.'],
        'da' => ['name' => 'Danish',     'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 170 stop-words.'],
        'no' => ['name' => 'Norwegian',  'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 221 stop-words.'],
        'fi' => ['name' => 'Finnish',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 847 stop-words.'],
        'ro' => ['name' => 'Romanian',   'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 434 stop-words.'],
        'ru' => ['name' => 'Russian',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + 559 stop-words. Cyrillic.'],
        'ca' => ['name' => 'Catalan',    'tier' => self::TIER_CONFIDENT, 'notes' => 'Full Snowball stemming + stop-words.'],

        // ─── LIMITED TIER (no Snowball stemmer in wamania, OR edge case) ─
        'hu' => ['name' => 'Hungarian', 'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only (no plural awareness). Stop-words available.'],
        'pl' => ['name' => 'Polish',    'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'cs' => ['name' => 'Czech',     'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'sk' => ['name' => 'Slovak',    'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'sl' => ['name' => 'Slovenian', 'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'hr' => ['name' => 'Croatian',  'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'bg' => ['name' => 'Bulgarian', 'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'uk' => ['name' => 'Ukrainian', 'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only. Cyrillic.'],
        'lv' => ['name' => 'Latvian',   'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'lt' => ['name' => 'Lithuanian','tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'et' => ['name' => 'Estonian',  'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'ga' => ['name' => 'Irish',     'tier' => self::TIER_LIMITED, 'notes' => 'No Snowball stemmer — exact-match only.'],
        'el' => ['name' => 'Greek',     'tier' => self::TIER_LIMITED, 'notes' => 'Greek question mark (`;`) is not yet recognized as a sentence boundary; sentence-context for content with questions may be off.'],
        'tr' => ['name' => 'Turkish',   'tier' => self::TIER_LIMITED, 'notes' => 'Turkish dotted/dotless-i lowercase rules differ from default Unicode — case-insensitive matching may miss some forms.'],

        // ─── BLOCKED TIER (hard-block in dropdown) ──────────────────────
        'ar' => ['name' => 'Arabic',     'tier' => self::TIER_BLOCKED, 'notes' => 'RTL + tokenization rules not implemented. Contact support if needed for V1.1.'],
        'he' => ['name' => 'Hebrew',     'tier' => self::TIER_BLOCKED, 'notes' => 'RTL + tokenization rules not implemented. Contact support if needed for V1.1.'],
        'zh' => ['name' => 'Chinese',    'tier' => self::TIER_BLOCKED, 'notes' => 'No space-based word boundaries — needs Chinese-specific tokenizer (jieba/ICU). Not supported in V1.'],
        'ja' => ['name' => 'Japanese',   'tier' => self::TIER_BLOCKED, 'notes' => 'No space-based word boundaries — needs Japanese-specific tokenizer (MeCab). Not supported in V1.'],
        'ko' => ['name' => 'Korean',     'tier' => self::TIER_BLOCKED, 'notes' => 'No space-based word boundaries. Not supported in V1.'],
        'th' => ['name' => 'Thai',       'tier' => self::TIER_BLOCKED, 'notes' => 'No space-based word boundaries. Not supported in V1.'],
        'vi' => ['name' => 'Vietnamese', 'tier' => self::TIER_BLOCKED, 'notes' => 'Space-separated but multi-syllable words use diacritics that complicate tokenization. Not supported in V1.'],
    ];

    /**
     * The legacy default — multi-language English+German setups (e.g. test
     * sites, dual-language editorial). Kept for backwards compatibility
     * with installs that haven't picked an explicit language yet.
     */
    public const DEFAULT_LANG = 'en';

    /** @return self::TIER_* */
    public static function tier(string $code): string
    {
        return self::LANGUAGES[$code]['tier'] ?? self::TIER_BLOCKED;
    }

    public static function name(string $code): string
    {
        return self::LANGUAGES[$code]['name'] ?? $code;
    }

    public static function notes(string $code): string
    {
        return self::LANGUAGES[$code]['notes'] ?? '';
    }

    public static function isConfident(string $code): bool
    {
        return self::tier($code) === self::TIER_CONFIDENT;
    }

    public static function isBlocked(string $code): bool
    {
        return self::tier($code) === self::TIER_BLOCKED;
    }

    /**
     * Languages that wamania/php-stemmer ships a Snowball stemmer for.
     * Linkwise's Stemmer class falls back to no-op for anything else.
     *
     * @return list<string>
     */
    public static function stemmerSupportedCodes(): array
    {
        return ['ca', 'da', 'nl', 'en', 'fi', 'fr', 'de', 'it', 'no', 'pt', 'ro', 'ru', 'es', 'sv'];
    }

    /**
     * @return array<string, array<string, mixed>>  All entries (for UI/README)
     */
    public static function all(): array
    {
        return self::LANGUAGES;
    }

    /**
     * @return array<string, array<string, mixed>>  Filtered by tier
     */
    public static function byTier(string $tier): array
    {
        return array_filter(self::LANGUAGES, fn ($l) => $l['tier'] === $tier);
    }

    /**
     * Map a raw locale string (Statamic `Site::shortLocale()` or `locale()`,
     * e.g. `de_DE`, `de`, `pt_BR`) to a supported 2-char ISO code, or null
     * when nothing in {@see LANGUAGES} matches.
     *
     * Pure function — no container, no Site lookup. Used by the Indexer to
     * stamp each {@see EntryRecord} with its content language at write time,
     * and by the SuggestionEngine to pick a per-entry stemmer in multisite
     * installs. The global {@see resolve()} chain (config → Site::current →
     * fallback) stays unchanged for callers that don't have an Entry in hand.
     */
    public static function resolveFor(?string $rawLocale): ?string
    {
        if (! is_string($rawLocale) || $rawLocale === '') {
            return null;
        }
        $short = mb_strtolower(mb_substr($rawLocale, 0, 2));
        return isset(self::LANGUAGES[$short]) ? $short : null;
    }

    /**
     * Resolve the configured language with sensible fallbacks:
     *  1. linkwise.language config explicitly set + valid → use it
     *  2. Statamic site locale (e.g. de_DE → de) maps to a known code → use it
     *  3. fallback to DEFAULT_LANG
     *
     * The fallback chain means a multi-site Statamic install with German
     * locale gets the right NLP pipeline without any Linkwise config —
     * we just look at what Statamic already knows.
     */
    public static function resolve(): string
    {
        return self::resolveWithSource()['code'];
    }

    /**
     * Same resolution chain as {@see resolve()} but returns both the
     * resolved code AND the resolution source so the Overview tab can
     * tell the user "auto-detected from Statamic site locale" instead
     * of silently picking a language.
     *
     * @return array{code: string, source: string, source_detail: ?string}
     */
    public static function resolveWithSource(): array
    {
        try {
            $configured = config('linkwise.language');
            if (is_string($configured) && isset(self::LANGUAGES[$configured])) {
                return [
                    'code' => $configured,
                    'source' => 'explicit',
                    'source_detail' => null,
                ];
            }
        } catch (\Throwable) {
            // No container — fall through.
        }
        try {
            $site = \Statamic\Sites\Site::current() ?? null;
            if ($site) {
                $rawLocale = (string) ($site->shortLocale() ?? $site->locale() ?? '');
                $shortLocale = mb_substr($rawLocale, 0, 2);
                if ($shortLocale && isset(self::LANGUAGES[$shortLocale])) {
                    return [
                        'code' => $shortLocale,
                        'source' => 'auto-detected',
                        'source_detail' => "Statamic site locale: {$rawLocale}",
                    ];
                }
            }
        } catch (\Throwable) {
            // Statamic not booted — fall through.
        }
        return [
            'code' => self::DEFAULT_LANG,
            'source' => 'fallback',
            'source_detail' => 'No Linkwise language configured and Statamic site locale not in the supported list',
        ];
    }
}
