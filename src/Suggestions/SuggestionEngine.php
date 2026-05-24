<?php

namespace Arturrossbach\Linkwise\Suggestions;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\NLP\LanguageRegistry;
use Arturrossbach\Linkwise\NLP\Stemmer;
use Arturrossbach\Linkwise\Support\TextNormalizer;
use Arturrossbach\Linkwise\NLP\Stopwords;
use Arturrossbach\Linkwise\Support\ContextExtractor;

class SuggestionEngine
{
    protected int $minPhraseWords;

    protected float $minScore;

    protected float $minKeywordScore;

    protected ?TargetKeywordManager $keywordManager;

    protected bool $enableKeywordMatches;

    public function __construct(
        ?int $minPhraseWords = null,
        ?float $minScore = null,
        ?float $minKeywordScore = null,
        ?TargetKeywordManager $keywordManager = null,
        ?bool $enableKeywordMatches = null,
    ) {
        $this->minPhraseWords = $minPhraseWords ?? $this->configOrDefault('linkwise.min_phrase_words', 2);
        $this->minScore = $minScore ?? $this->configOrDefault('linkwise.min_score', 0.4);
        $this->minKeywordScore = $minKeywordScore ?? $this->configOrDefault('linkwise.min_keyword_score', 0.15);
        $this->keywordManager = $keywordManager;
        $this->enableKeywordMatches = $enableKeywordMatches ?? $this->configOrDefault('linkwise.enable_keyword_matches', false);
    }

    protected function resolveKeywordManager(): TargetKeywordManager
    {
        if ($this->keywordManager === null) {
            $this->keywordManager = app(TargetKeywordManager::class);
        }

        return $this->keywordManager;
    }

    protected function configOrDefault(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Find link suggestions for the given text.
     *
     * @param  EntryRecord[]  $index
     * @param  string[]  $alreadyLinkedIds  Entry IDs already linked in the source content
     * @return Suggestion[]
     */
    public function suggest(string $text, array $index, ?string $excludeEntryId = null, array $alreadyLinkedIds = [], ?int $maxSuggestions = null, ?string $sourceLocaleOverride = null): array
    {
        $normalizedText = TextNormalizer::normalize($text);
        $suggestions = [];

        // Multisite locale-scoping (V1.x). Source-locale is the content language
        // of the entry we're generating suggestions FOR; it drives both the
        // same-locale target filter below and the per-source stemmer used to
        // tokenize $text. Null on single-site installs (the Indexer leaves
        // EntryRecord::$locale null when there's no language decision to
        // make) — in that case the same-locale filter short-circuits to
        // "pass" and tokenizeWithMappingFor delegates to the legacy global
        // stemmer/stopword path. Half-migrated indices (some records carry
        // locale, others don't) also pass: the filter only fires when BOTH
        // sides carry a locale, otherwise it would silently drop legacy
        // targets after a partial reindex.
        //
        // Inbound-flow exception (user-bug 2026-05-24): InboundEngine
        // builds a $singleIndex containing only the target record, then
        // iterates source records OUTSIDE the index. With that shape the
        // source-locale lookup against $index[$excludeEntryId] returns
        // null → filter silently passes cross-locale pairs. The caller
        // passes $sourceLocaleOverride explicitly to close that gap.
        $sourceLocale = $sourceLocaleOverride
            ?? (($excludeEntryId && isset($index[$excludeEntryId]))
                ? $index[$excludeEntryId]->locale
                : null);

        // If excludeEntryId is in the index, also exclude its outbound links
        if ($excludeEntryId && isset($index[$excludeEntryId])) {
            $alreadyLinkedIds = array_merge($alreadyLinkedIds, $index[$excludeEntryId]->outboundLinks);
        }

        // Prevent two-way linking: if target already links to source, don't suggest reverse
        $preventTwoWay = (bool) $this->configOrDefault('linkwise.prevent_two_way', false);
        if ($preventTwoWay && $excludeEntryId) {
            foreach ($index as $record) {
                if (in_array($excludeEntryId, $record->outboundLinks, true)) {
                    $alreadyLinkedIds[] = $record->id;
                }
            }
        }

        // Merge config-excluded entries
        $configExcluded = $this->getConfigArray('linkwise.excluded_entries');

        $excludeIds = array_unique(array_merge(
            $alreadyLinkedIds,
            $configExcluded,
            $excludeEntryId ? [$excludeEntryId] : [],
        ));

        // Load title blacklist and target collection filter
        $titleBlacklist = $this->getTitleBlacklist();
        $targetCollections = $this->getConfigArray('linkwise.target_collections');

        // Tokenize source text once for keyword matching (stemmed + original mapping).
        // Uses the SOURCE entry's locale so a DE source on an EN-default install
        // is stemmed with the German stemmer and filters against the German
        // stopword list — fixes the PR #100 root cause one level up (English
        // "and" isn't in the DE stopword list, so a global EN/DE-mismatch let
        // it through as a content word in the stem-cluster path).
        [$sourceTokens, $stemToOriginal] = TextNormalizer::tokenizeWithMappingFor($text, $sourceLocale);

        // Stage-1 pre-filter set (V1.2 perf gate): O(1) stem-lookup for the
        // intersection check below. Built once per suggest() call; reused
        // across all target records.
        $sourceTokenSet = array_flip($sourceTokens);

        // Per-call cache of stemmed title-tokens per target. Building the
        // stems is the only non-trivial work the gate does (~5 stem-calls
        // per target), and the gate runs for every target in the index —
        // amortise across the foreach below.
        $titleStemsCache = [];

        // Load all custom target keywords once (not per entry)
        $allCustomKeywords = [];
        try {
            $allCustomKeywords = $this->resolveKeywordManager()->loadAll();
        } catch (\Throwable) {
            // Not available (e.g. in unit tests)
        }

        foreach ($index as $record) {
            if (in_array($record->id, $excludeIds, true)) {
                continue;
            }

            // Multisite locale-scoping. Cross-locale links render incorrectly
            // (LinkMark::convertHref's `$item->in(Site::current())` falls back
            // to the original entry's URL when no localization exists in the
            // current site) and clutter the editor's review modal with
            // wrong-language anchors. Only enforce when BOTH sides carry a
            // locale so single-site installs and half-migrated indices keep
            // their existing behavior.
            if ($sourceLocale !== null && $record->locale !== null && $sourceLocale !== $record->locale) {
                continue;
            }

            // Skip blacklisted titles
            if (! empty($titleBlacklist) && in_array(mb_strtolower($record->title), $titleBlacklist, true)) {
                continue;
            }

            // Filter by target collections
            if (! empty($targetCollections) && ! in_array($record->collection, $targetCollections, true)) {
                continue;
            }

            // ───── Stage-1 pre-filter (V1.2 perf gate) ─────
            //
            // Cheap stemmed-token-set intersection. If the source carries
            // NO stem from either the target's title OR its TF-IDF keyword
            // map, no downstream path can fire. Skip the pair before the
            // expensive phrase regex + stem cluster + compound passes.
            //
            // Why a single-hit threshold (not "min 2 stems"): the compound
            // path (findTitleCompoundMatches) and the custom-keyword path
            // both legitimately produce matches from a SINGLE stem hit.
            // A 1-word hyphenated compound title like "CMS-Migration"
            // has only one stem; gating that at ≥2 would zero out the
            // entire path. Keyword-only suggestions (target has no
            // title-stem overlap but a strong TF-IDF keyword hit) need
            // their stems via the keyword map. Both are union'd into the
            // gate set.
            //
            // Cost: O(|gateSet|) per pair (typical 5–15 stems). Hot-path
            // lookups O(1) on $sourceTokenSet (built once outside loop).
            // Empty gate set ≡ no signal to filter on → pass through to
            // existing paths.
            if (! isset($titleStemsCache[$record->id])) {
                // Post-filter source and target share the same locale (or one
                // side is null = legacy/single-site). Tokenize the target
                // title with the source stemmer so prefilter stems match the
                // sourceTokenSet built above.
                [$titleStems] = TextNormalizer::tokenizeWithMappingFor($record->title, $sourceLocale);
                // Keyword stems are already stemmed (the keyword map is
                // built by KeywordExtractor::extract which stems before
                // storing). Use array_keys directly.
                $keywordStems = array_keys($record->keywords);
                $titleStemsCache[$record->id] = array_values(array_unique(array_merge($titleStems, $keywordStems)));
            }
            $gateStems = $titleStemsCache[$record->id];
            if (! empty($gateStems)) {
                $hit = false;
                // Stage A: exact stem match (O(1) hash lookup) — catches
                // the common case where both sides went through the same
                // stemmer.
                foreach ($gateStems as $stem) {
                    if (isset($sourceTokenSet[$stem])) {
                        $hit = true;
                        break;
                    }
                }
                // Stage B (only when A failed): prefix overlap. Required
                // because the active stemmer language may not strip every
                // inflection on every word — e.g. an English stemmer on a
                // German plural compound ("cms-migration" vs "cms-migrationen")
                // leaves divergent surface forms. The compound match path
                // downstream handles this via suffix-tolerant regex; the
                // pre-filter mirrors the tolerance with a >=4-char prefix
                // overlap so it doesn't block what the compound path
                // would have matched.
                if (! $hit) {
                    foreach ($gateStems as $stem) {
                        $len = mb_strlen($stem);
                        if ($len < 4) {
                            continue;
                        }
                        foreach ($sourceTokenSet as $sourceStem => $_) {
                            $sourceLen = mb_strlen($sourceStem);
                            if ($sourceLen < 4) {
                                continue;
                            }
                            if (str_starts_with($sourceStem, $stem) || str_starts_with($stem, $sourceStem)) {
                                $hit = true;
                                break 2;
                            }
                        }
                    }
                }
                if (! $hit) {
                    continue;
                }
            }

            // Tier 1: Title phrase matching (all positions)
            $matches = $this->findMatches($normalizedText, $text, $record);

            // Tier 1.2: Title-compound matching (Bug 4 — 2026-05-11).
            // Distinctive hyphenated compounds in the title (e.g. "CMS-Migration",
            // "Pre-Flight-Checklisten") that appear ALONE in source text were
            // missed by the regular paths: minPhraseWords=2 prevents 1-word
            // title-phrases, and findUnorderedStemMatch needs ≥2 stems found.
            // Compounds opt in: Title-Case per token, total len ≥6, suffix-
            // tolerant matching catches German plurals (CMS-Migrationen).
            $compoundMatches = $this->findTitleCompoundMatches($text, $record);
            foreach ($compoundMatches as $cm) {
                $dominated = false;
                foreach ($matches as $existing) {
                    if ($cm->position >= $existing->position &&
                        $cm->position < $existing->position + mb_strlen($existing->anchorText)) {
                        $dominated = true;
                        break;
                    }
                }
                if (! $dominated) {
                    $matches[] = $cm;
                }
            }

            // Tier 1.5: Custom keyword matching — always runs, adds to matches
            // Custom keywords are user-set and should always be checked regardless of title matches
            $customKw = $allCustomKeywords[$record->id] ?? [];
            if (! empty($customKw)) {
                $keywordDirectMatches = $this->findTargetKeywordDirectMatch($text, $record, $customKw);
                // Deduplicate: skip keyword matches at positions already covered by title matches
                $existingPositions = array_map(fn ($m) => $m->position, $matches);
                foreach ($keywordDirectMatches as $kwMatch) {
                    // Skip if a title match already covers this text range
                    $dominated = false;
                    foreach ($matches as $existing) {
                        if ($kwMatch->position >= $existing->position &&
                            $kwMatch->position < $existing->position + mb_strlen($existing->anchorText)) {
                            $dominated = true;
                            break;
                        }
                    }
                    if (! $dominated) {
                        $matches[] = $kwMatch;
                    }
                }
            }

            // Tier 2: TF-IDF keyword profile overlap (fallback if nothing else matched)
            if (empty($matches) && ! empty($record->keywords)) {
                $keywordMatch = $this->findKeywordMatch($text, $sourceTokens, $stemToOriginal, $record);
                if ($keywordMatch !== null) {
                    $matches = [$keywordMatch];
                }
            }

            foreach ($matches as $match) {
                // Boost score if entry has custom target keywords matching the source text
                $match = $this->applyTargetKeywordBoost($match, $normalizedText);
                $suggestions[] = $match;
            }
        }

        // Deduplicate: same target + same anchor text = keep highest score
        $deduped = [];
        foreach ($suggestions as $s) {
            $key = $s->targetEntryId.'||'.mb_strtolower($s->anchorText);
            if (! isset($deduped[$key]) || $s->score > $deduped[$key]->score) {
                $deduped[$key] = $s;
            }
        }
        $suggestions = array_values($deduped);

        // Sort by score descending, then by position ascending
        usort($suggestions, function (Suggestion $a, Suggestion $b) {
            $scoreCompare = $b->score <=> $a->score;

            return $scoreCompare !== 0 ? $scoreCompare : $a->position <=> $b->position;
        });

        // Apply max suggestions limit uniformly. List is already sorted
        // by score (and position as tiebreaker), so the top-N slice keeps
        // the best matches regardless of type. Title/stem/custom typically
        // score highest and dominate naturally without a special bypass.
        $maxSuggestions = $maxSuggestions ?? (int) $this->configOrDefault('linkwise.max_suggestions', 50);
        if ($maxSuggestions > 0 && count($suggestions) > $maxSuggestions) {
            $suggestions = array_slice($suggestions, 0, $maxSuggestions);
        }

        return $suggestions;
    }

    /**
     * Parse the title blacklist from config (newline-separated string).
     *
     * @return string[]  Lowercased titles
     */
    protected function getTitleBlacklist(): array
    {
        $raw = $this->configOrDefault('linkwise.title_blacklist', '');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_filter(
            array_map(fn ($line) => mb_strtolower(trim($line)), explode("\n", $raw)),
            fn ($line) => $line !== '',
        );
    }

    /**
     * Get an array config value safely (handles null/empty in unit tests).
     */
    protected function getConfigArray(string $key): array
    {
        $value = $this->configOrDefault($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Find phrase matches for a single entry record in the text.
     * Uses regex on original text to preserve correct anchor text and positions.
     *
     * @return Suggestion[]
     */
    protected function findMatches(string $normalizedText, string $originalText, EntryRecord $record): array
    {
        // Tier-1 title-phrase matching. PR #102 audit D1 + A1: pass the
        // title's locale (which may differ from the entry's locale when
        // blueprint declares `localizable: false` on title) so the phrase-
        // stripping and stopword-boundary logic uses the title's actual
        // language. Falls back to entry locale when titleLocale is null.
        $phrases = $this->generateMatchPhrases($record->title, $record->titleLocale ?? $record->locale);
        $titleWordCount = count(explode(' ', TextNormalizer::normalize($record->title)));
        $suggestions = [];

        foreach ($phrases as $phrase) {
            $phraseWordCount = count(explode(' ', $phrase));

            if ($phraseWordCount < $this->minPhraseWords) {
                continue;
            }

            // Score combines two signals:
            //   ratioScore — how much of the title this phrase covers (good
            //     for short titles: matching "Internal Linking" out of
            //     "Internal Linking Best Practices for SEO" rewards heavily)
            //   absoluteScore — confidence based on phrase length itself
            //     (4+ word literal match = saturated; 2-word match = 0.5).
            //
            // Taking the max of both prevents long descriptive titles (24+
            // words like a news headline) from making short literal matches
            // mathematically impossible — a 2-word phrase against a 24-word
            // title used to score 2/24 = 0.083, well below min_score=0.4.
            // It also keeps full-title matches at score=1.0 as before.
            $ratioScore = $titleWordCount > 0 ? $phraseWordCount / $titleWordCount : 0;
            $absoluteScore = min(1.0, $phraseWordCount / 4);
            $score = max($ratioScore, $absoluteScore);

            if ($score < $this->minScore) {
                continue;
            }

            // Build a regex that matches the phrase in original text
            // Case-insensitive, allows flexible whitespace/punctuation between words
            $pattern = $this->buildPhrasePattern($phrase);

            if (preg_match_all($pattern, $originalText, $allMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($allMatches[0] as $m) {
                    $anchorText = $m[0];

                    // Skip anchors spanning sentence boundaries or too long
                    if (preg_match('/[.!?]\s/', $anchorText) || mb_strlen($anchorText) > 80) {
                        continue;
                    }

                    // Trim leading/trailing stopwords from the matched span
                    // (e.g. "als gleichberechtigter Bestandteil" → "gleich-
                    // berechtigter Bestandteil"). Middle stopwords stay.
                    // PR #102 audit E3 + A1: title-locale-aware so a DE-
                    // title anchor in an EN-default install actually trims
                    // "die"/"als"/etc, and a non-localizable title in a
                    // DE-localization uses the title's true language.
                    [$trimmed, $shift] = TextNormalizer::trimBoundaryStopwords($anchorText, $record->titleLocale ?? $record->locale);
                    $anchorText = $trimmed;
                    $position = $this->byteToCharOffset($originalText, $m[1]) + $shift;
                    $context = $this->extractContext($originalText, $position, mb_strlen($anchorText));

                    $suggestions[] = new Suggestion(
                        targetEntryId: $record->id,
                        targetTitle: $record->title,
                        targetUrl: $record->url,
                        targetCollection: $record->collection,
                        anchorText: $anchorText,
                        position: $position,
                        score: round($score, 2),
                        sentenceContext: $context['text'],
                        contextTruncatedStart: $context['truncated_start'],
                        contextTruncatedEnd: $context['truncated_end'],
                        matchType: 'title',
                        matchReason: "The title phrase \"{$phrase}\" was found directly in the text.",
                    );
                }
                break; // Best phrase matched — don't try shorter phrases
            }
        }

        // Fallback: unordered stemmed matching
        // Finds title content words in the text regardless of order or inflection.
        // "strategies for internal Linking for Better SEO" matches "Internal Linking Strategy for Better SEO"
        if (empty($suggestions)) {
            $stemMatch = $this->findUnorderedStemMatch($originalText, $record, $titleWordCount);
            if ($stemMatch) {
                $suggestions[] = $stemMatch;
            }
        }

        return $suggestions;
    }

    /**
     * Tier 1.5: Find a match by checking if any of the target entry's
     * custom or content keywords appear literally in the source text.
     *
     * Unlike Tier 2 (TF-IDF profile overlap), this matches individual keywords
     * directly — e.g. if "coffee" is a custom keyword of the Coffee entry
     * and "coffee" appears in the text, suggest linking to Coffee.
     *
     * @param  string[]  $customKeywords  Custom keywords set by the user for this entry
     */
    /**
     * Bug 4 (2026-05-11): match a single hyphenated compound from the title.
     *
     * The default pipeline misses obvious anchors when the title's only
     * distinctive content word is a hyphenated compound:
     *   - generateMatchPhrases respects minPhraseWords (default 2), so a
     *     1-word phrase like "cms-migration" is never emitted.
     *   - findUnorderedStemMatch needs ≥2 distinct title-stems found in
     *     source text; if the only other content word ("Notizen") doesn't
     *     appear, the fallback fails too.
     *
     * Compound candidates are extracted from the title via extractTitleCompounds
     * (Title-Case per token, total length ≥6, each token ≥2 chars). Each is
     * matched with a suffix-tolerant pattern so German plurals/declensions
     * land too ("CMS-Migration" matches "CMS-Migrationen"). A negative
     * lookahead prevents the compound from over-matching inside a longer
     * hyphen-extended compound (so "On-Call" never wraps inside
     * "On-Call-Schedule" — that would mis-link to a different entry).
     *
     * Score = capped(tokens / 4); a 2-token compound = 0.5 (passes default
     * minScore=0.4). Treated equivalently to a 2-word title-phrase match
     * for ranking; deduplication downstream resolves overlaps.
     *
     * @return Suggestion[]
     */
    protected function findTitleCompoundMatches(string $originalText, EntryRecord $record): array
    {
        $compounds = $this->extractTitleCompounds($record->title);
        if (empty($compounds)) {
            return [];
        }

        // Per-title-locale stemmer (PR #102 audit A1). Falls back to the
        // entry locale when the title is localizable, so the standard path
        // is unchanged.
        $stemmer = new Stemmer($record->titleLocale ?? $record->locale);
        $suggestions = [];

        foreach ($compounds as $compound) {
            $tokens = explode('-', $compound);
            $tokenCount = count($tokens);

            // Score: cap at 1.0, treat hyphen-tokens as separate words for
            // the "how distinctive is this match" calc.
            $score = round(min(1.0, $tokenCount / 4), 2);
            if ($score < $this->minScore) {
                continue;
            }

            // Pattern: literal head tokens + suffix-tolerant tail.
            // Stem only the LAST token so "CMS-Migration" → "CMS-Migrationen"
            // works, while head tokens stay anchored ("CMS-" never drifts).
            $lastToken = $tokens[$tokenCount - 1];
            $stem = $stemmer->stem(mb_strtolower($lastToken));
            $maxSuffix = max(2, mb_strlen($lastToken) - mb_strlen($stem) + 2);

            $headTokens = array_slice($tokens, 0, $tokenCount - 1);
            $headEscaped = array_map(fn ($t) => preg_quote($t, '/'), $headTokens);
            $head = implode('-', $headEscaped);

            // Negative lookahead `(?!-\p{L})` blocks the compound from matching
            // inside a longer hyphen-extended one (On-Call inside On-Call-Schedule).
            $pattern = '/\b' . $head . '-' . preg_quote($stem, '/')
                . '\w{0,' . $maxSuffix . '}\b(?!-\p{L})/iu';

            if (! preg_match_all($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $m) {
                $anchorText = $m[0];
                $position = $this->byteToCharOffset($originalText, $m[1]);
                $context = $this->extractContext($originalText, $position, mb_strlen($anchorText));

                $suggestions[] = new Suggestion(
                    targetEntryId: $record->id,
                    targetTitle: $record->title,
                    targetUrl: $record->url,
                    targetCollection: $record->collection,
                    anchorText: $anchorText,
                    position: $position,
                    score: $score,
                    sentenceContext: $context['text'],
                    contextTruncatedStart: $context['truncated_start'],
                    contextTruncatedEnd: $context['truncated_end'],
                    matchType: 'title-compound',
                    matchReason: "The title compound \"{$compound}\" was found directly in the text.",
                );
            }
        }

        return $suggestions;
    }

    /**
     * Extract qualifying hyphenated compounds from a title (Bug 4).
     *
     * Filter "Mittel": each token must start with an uppercase letter
     * (incl. German umlauts), each token ≥2 chars, total compound length
     * ≥6 chars. Filters out generic glue words like "co-op", "ad-hoc",
     * "X-ray" while keeping real identifiers ("CMS-Migration",
     * "Pre-Flight-Check", "Trail-Rucksack", "Cache-Invalidierung").
     *
     * @return string[]  Unique compounds in their original casing
     */
    protected function extractTitleCompounds(string $title): array
    {
        $pattern = '/\b[A-ZÄÖÜ][\p{L}]+(?:-[A-ZÄÖÜ][\p{L}]+)+\b/u';
        if (! preg_match_all($pattern, $title, $matches)) {
            return [];
        }

        $compounds = [];
        foreach ($matches[0] as $c) {
            if (mb_strlen($c) < 6) {
                continue;
            }
            $compounds[$c] = true;
        }

        return array_keys($compounds);
    }

    /**
     * @return Suggestion[]
     */
    protected function findTargetKeywordDirectMatch(string $originalText, EntryRecord $record, array $customKeywords): array
    {
        // Custom keywords: user explicitly chose these → high confidence (0.7).
        $candidates = [];

        foreach ($customKeywords as $kw) {
            $kw = trim($kw);
            if ($kw !== '') {
                $candidates[] = ['keyword' => $kw, 'score' => 0.7];
            }
        }

        // Content keywords are NOT used as direct match source for single words.
        // They contribute via Tier 2 (TF-IDF profile overlap) and score boost.

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, function ($a, $b) {
            $scoreCompare = $b['score'] <=> $a['score'];
            return $scoreCompare !== 0 ? $scoreCompare : mb_strlen($b['keyword']) <=> mb_strlen($a['keyword']);
        });

        // Find ALL occurrences of each keyword — each position becomes its own suggestion
        $results = [];
        foreach ($candidates as $candidate) {
            $keyword = $candidate['keyword'];

            if (mb_strlen($keyword) < 2 || TextNormalizer::isStopword(mb_strtolower($keyword))) {
                continue;
            }

            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/iu';
            if (preg_match_all($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $m) {
                    $anchorText = $m[0];
                    $position = $this->byteToCharOffset($originalText, $m[1]);
                    $context = $this->extractContext($originalText, $position, mb_strlen($anchorText));

                    $results[] = new Suggestion(
                        targetEntryId: $record->id,
                        targetTitle: $record->title,
                        targetUrl: $record->url,
                        targetCollection: $record->collection,
                        anchorText: $anchorText,
                        position: $position,
                        score: $candidate['score'],
                        sentenceContext: $context['text'],
                        contextTruncatedStart: $context['truncated_start'],
                        contextTruncatedEnd: $context['truncated_end'],
                        matchType: 'custom',
                        matchReason: "Matches the custom target keyword \"{$keyword}\" that was set for this entry.",
                    );
                }
            }
        }

        return $results;
    }

    /**
     * Find a keyword-based match by checking how many of the target entry's
     * TF-IDF keywords appear in the source text.
     *
     * Uses the best matching keyword phrase as anchor text.
     */
    /**
     * @param  array<string, string>  $stemToOriginal  Mapping of stemmed token → original word
     */
    protected function findKeywordMatch(string $originalText, array $sourceTokens, array $stemToOriginal, EntryRecord $record): ?Suggestion
    {
        // Keyword matches are opt-in — disabled by default to prioritize signal over volume
        if (! $this->enableKeywordMatches) {
            return null;
        }

        if (empty($record->keywords) || empty($sourceTokens)) {
            return null;
        }

        $sourceTokenSet = array_flip($sourceTokens);

        // Find which target keywords appear in the source text (stemmed comparison)
        $matchingKeywords = [];
        $totalWeight = 0;
        $matchWeight = 0;

        foreach ($record->keywords as $keyword => $tfidfScore) {
            $totalWeight += $tfidfScore;
            if (isset($sourceTokenSet[$keyword])) {
                $matchingKeywords[$keyword] = $tfidfScore;
                $matchWeight += $tfidfScore;
            }
        }

        if (empty($matchingKeywords) || $totalWeight <= 0) {
            return null;
        }

        // Score = weighted overlap ratio (how much of target's keyword profile is covered)
        $overlapScore = $matchWeight / $totalWeight;

        // Require minimum overlap to avoid noise
        if ($overlapScore < $this->minKeywordScore) {
            return null;
        }

        // Map stemmed keywords back to their original forms for anchor text search
        $bestStemmedKeyword = array_key_first($matchingKeywords);
        $bestOriginalWord = $stemToOriginal[$bestStemmedKeyword] ?? $bestStemmedKeyword;

        $originalMatchingWords = array_map(
            fn ($stem) => $stemToOriginal[$stem] ?? $stem,
            array_keys($matchingKeywords),
        );

        // Find the best anchor using original (unstemmed) words in the original text.
        // Per-target-locale coordinator-set: same-locale filter in suggest() guarantees
        // source and target share this locale (or both null = legacy/single-site).
        $anchorText = $this->findBestAnchor($originalText, $bestOriginalWord, $originalMatchingWords, $record->locale);

        // Find position of anchor in original text
        $pattern = '/\b'.preg_quote($anchorText, '/').'\b/iu';
        if (! preg_match($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE)) {
            // Fallback: find just the single keyword
            $pattern = '/\b'.preg_quote($bestOriginalWord, '/').'\b/iu';
            if (! preg_match($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE)) {
                return null;
            }
        }

        $anchorText = $matches[0][0];

        // Reject single-word anchors for keyword matches — too generic to be useful
        if (str_word_count($anchorText) < 2) {
            return null;
        }

        // Reject anchors spanning line breaks (crossed paragraph boundaries)
        if (preg_match('/[\r\n]/', $anchorText)) {
            return null;
        }

        // Reject anchors that are purely generic/structural words
        if ($this->isGenericAnchor($anchorText)) {
            return null;
        }

        $position = $this->byteToCharOffset($originalText, $matches[0][1]);
        $context = $this->extractContext($originalText, $position, mb_strlen($anchorText));

        // Cap keyword-only scores below title-match scores to maintain ranking hierarchy
        $score = round(min($overlapScore, 0.89), 2);

        return new Suggestion(
            targetEntryId: $record->id,
            targetTitle: $record->title,
            targetUrl: $record->url,
            targetCollection: $record->collection,
            anchorText: $anchorText,
            position: $position,
            score: $score,
            sentenceContext: $context['text'],
            contextTruncatedStart: $context['truncated_start'],
            contextTruncatedEnd: $context['truncated_end'],
            matchType: 'keyword',
            matchReason: 'This entry and "'.mb_substr($record->title, 0, 40).'" share similar topics: '.implode(', ', array_slice($originalMatchingWords, 0, 5)).'.',
        );
    }

    /**
     * Find the best multi-word anchor text around a keyword.
     * Strategy: (1) adjacent keyword pair, (2) keyword + neighbor word from text.
     * Minimum 2 words to ensure meaningful anchor text.
     */
    protected function findBestAnchor(string $text, string $primaryKeyword, array $matchingKeywords, ?string $locale = null): string
    {
        // Coordinator stopwords that MUST NOT bridge two keywords —
        // produces user-visible Müll like "performance and tuning".
        // Bridge prepositions (von/of/for/in/…) are fine. See
        // SuggestionEngineStemClusterCoordTest for the rationale.
        //
        // Per-locale list since PR #102 audit (E2): FR/ES/IT/NL/PT/SV/DA/
        // NO/FI/RO/RU/CA also need coordinator protection. Null locale
        // falls back to the EN+DE union for legacy / single-site /
        // unknown-language sites — same protection as before the audit.
        $coordinatorStopwords = LanguageRegistry::coordinatorsFor($locale);

        // Strategy 1: Try to find two matching keywords near each other (0-1 word gap)
        foreach ($matchingKeywords as $otherKeyword) {
            if ($otherKeyword === $primaryKeyword) {
                continue;
            }

            $p = preg_quote($primaryKeyword, '/');
            $o = preg_quote($otherKeyword, '/');

            // Check "primary [gap?] other" order
            $pattern = '/\b('.$p.'\s+(?:([\p{L}\p{N}-]+)\s+)?'.$o.')\b/iu';
            if (preg_match($pattern, $text, $m)) {
                // Reject if the gap word is a coordinator — "performance
                // and tuning" is a user-bug Müll-anchor, "performance for
                // tuning" or "performance über tuning" is fine.
                $gap = isset($m[2]) ? mb_strtolower($m[2]) : '';
                if ($gap === '' || ! in_array($gap, $coordinatorStopwords, true)) {
                    return $m[1];
                }
            }

            // Check "other [gap?] primary" order
            $pattern = '/\b('.$o.'\s+(?:([\p{L}\p{N}-]+)\s+)?'.$p.')\b/iu';
            if (preg_match($pattern, $text, $m)) {
                $gap = isset($m[2]) ? mb_strtolower($m[2]) : '';
                if ($gap === '' || ! in_array($gap, $coordinatorStopwords, true)) {
                    return $m[1];
                }
            }
        }

        // Strategy 2: Grab the adjacent significant word from the original text
        // Look for "word keyword" or "keyword word" where word is not a stopword
        $escaped = preg_quote($primaryKeyword, '/');
        $stopwords = TextNormalizer::getStopwords();

        // Try "preceding_word keyword" first (more natural as anchor: "coding agents")
        $pattern = '/\b([\p{L}\p{N}-]+)\s+' . $escaped . '\b/iu';
        if (preg_match($pattern, $text, $m)) {
            $preceding = mb_strtolower($m[1]);
            if (! in_array($preceding, $stopwords, true) && mb_strlen($preceding) >= 2) {
                return $m[1] . ' ' . $primaryKeyword;
            }
        }

        // Try "keyword following_word"
        $pattern = '/\b' . $escaped . '\s+([\p{L}\p{N}-]+)\b/iu';
        if (preg_match($pattern, $text, $m)) {
            $following = mb_strtolower($m[1]);
            if (! in_array($following, $stopwords, true) && mb_strlen($following) >= 2) {
                return $primaryKeyword . ' ' . $m[1];
            }
        }

        // Last resort: single keyword (rare — most text has adjacent non-stopwords)
        return $primaryKeyword;
    }

    /**
     * Build a regex pattern from a normalized phrase.
     * Allows flexible whitespace/punctuation between words, case-insensitive.
     */
    /**
     * Unordered stemmed matching: find title content words in text regardless of order.
     *
     * Algorithm:
     * 1. Extract content words from title (skip stopwords), stem each
     * 2. For each stemmed title word, find all positions where it appears in the original text
     * 3. If enough title words are found (≥60% and ≥2), create a suggestion
     * 4. Score = matched_words / title_words * 0.9 (slight discount vs exact match)
     * 5. Anchor text = the text span from first matched word to last matched word
     */
    protected function findUnorderedStemMatch(string $originalText, EntryRecord $record, int $titleWordCount): ?Suggestion
    {
        // Per-target-title-locale stemmer. PR #102 audit A1: the title and
        // body can live in different languages when the blueprint declares
        // `localizable: false` on title — a DE-localization of an EN-origin
        // inherits the English title even though the body is German. Stem
        // the title with its actual language (titleLocale, set by Indexer);
        // body-side coordinator-list stays on $record->locale (the body's
        // language, after the same-locale filter equals source-locale).
        $titleLocale = $record->titleLocale ?? $record->locale;
        $stemmer = new Stemmer($titleLocale);
        $normalizedTitle = TextNormalizer::normalize($record->title);
        $titleWords = explode(' ', $normalizedTitle);

        // Per-target-locale coordinator-set. PR #100 introduced this as a
        // hardcoded EN+DE list (the language-agnostic guard against the
        // "performance and optimization" Müll shape from the Cloudways smoke
        // 2026-05-23). PR #102 audit E2 expanded the list to all 14
        // CONFIDENT-tier languages because FR/ES/IT/NL/PT/SV/DA/NO/FI/RO/RU/CA
        // sites can produce the same Müll with their own coordinators
        // (et/y/e/en/och/og/ja/și/и/i). Null locale falls back to EN+DE
        // union — same protection legacy/single-site users already had.
        $coordinatorStopwords = LanguageRegistry::coordinatorsFor($record->locale);
        // Get content words from title (skip stopwords + coordinators).
        $titleContentWords = array_filter($titleWords, fn ($w) => $w !== ''
            && ! TextNormalizer::isStopword($w)
            && ! in_array(mb_strtolower($w), $coordinatorStopwords, true));
        $titleContentWords = array_values($titleContentWords);

        if (count($titleContentWords) < 2) {
            return null;
        }

        // Stem each title content word
        $titleStems = [];
        foreach ($titleContentWords as $word) {
            $stem = $stemmer->stem($word);
            $maxSuffix = mb_strlen($word) - mb_strlen($stem) + 2;
            $titleStems[] = ['stem' => $stem, 'original' => $word, 'maxSuffix' => $maxSuffix];
        }

        // Find ALL positions of each title stem in the text
        $allPositions = []; // [{stem, word, position, length}]
        foreach ($titleStems as $ts) {
            $pattern = '/\b' . preg_quote($ts['stem'], '/') . '\w{0,' . $ts['maxSuffix'] . '}\b/iu';
            if (preg_match_all($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $m) {
                    $charPos = $this->byteToCharOffset($originalText, $m[1]);
                    $allPositions[] = [
                        'stem' => $ts['stem'],
                        'word' => $m[0],
                        'position' => $charPos,
                        'length' => mb_strlen($m[0]),
                    ];
                }
            }
        }

        // Count how many distinct stems were found
        $foundStems = array_unique(array_column($allPositions, 'stem'));
        $matchRatio = count($foundStems) / count($titleContentWords);

        // Long descriptive titles (think 24-word news headlines like "WAR
        // RAGES ON Houthis launch missile at Israel & join Iran's war ...")
        // are mathematically locked out by a flat 60% ratio: 2 specific
        // stems out of 13 content words is 0.15, well below 0.6. Yet a
        // source carrying two highly-specific stems like "houthi" + "israel"
        // together is genuine signal — those words don't co-occur by chance.
        //
        // Apply an absolute floor (foundStems / 3) ONLY for titles with 6+
        // content words. Below that, the ratio path is kept strict so a
        // short title like "Server Security for Web Applications" doesn't
        // false-positive on a sentence like "the spider built a web in the
        // application shed" (2 generic-word hits out of 4 = 50%, rejected).
        $titleContentWordCount = count($titleContentWords);
        $absoluteScore = $titleContentWordCount >= 6 ? min(1.0, count($foundStems) / 3) : 0.0;
        if (count($foundStems) < 2 || max($matchRatio, $absoluteScore) < 0.6) {
            return null;
        }

        // Find the tightest cluster: pick one position per stem that minimizes the total span.
        // Strategy: sort all positions, slide a window that covers all found stems.
        usort($allPositions, fn ($a, $b) => $a['position'] <=> $b['position']);

        $bestSpan = null;
        $bestFirst = 0;
        $bestLast = 0;
        $maxSpan = mb_strlen($record->title) * 3;

        for ($i = 0; $i < count($allPositions); $i++) {
            // Try starting from each position and collect one of each stem
            $covered = [];
            $lastEnd = 0;
            for ($j = $i; $j < count($allPositions); $j++) {
                $p = $allPositions[$j];
                if (! isset($covered[$p['stem']])) {
                    $covered[$p['stem']] = $p;
                    $lastEnd = $p['position'] + $p['length'];
                }
                if (count($covered) === count($foundStems)) {
                    break;
                }
            }

            if (count($covered) < count($foundStems)) {
                continue;
            }

            $spanLength = $lastEnd - $allPositions[$i]['position'];
            if ($spanLength <= $maxSpan && ($bestSpan === null || $spanLength < $bestSpan)) {
                $bestSpan = $spanLength;
                $bestFirst = $allPositions[$i]['position'];
                $bestLast = $lastEnd;
            }
        }

        if ($bestSpan === null) {
            return null; // No cluster within max span
        }

        $matchedCount = count($foundStems);
        $spanLength = $bestSpan;

        // Score: same conditional logic as the count gate above. Long
        // titles get the absolute floor (so the score doesn't drop below
        // min_score after the cluster has already proven itself); short
        // titles stay on pure ratio so weak generic-word matches don't
        // get artificially boosted into visibility.
        $ratioScore = $matchedCount / count($titleContentWords);
        $absoluteScore = count($titleContentWords) >= 6 ? min(1.0, $matchedCount / 3) : 0.0;
        $rawScore = max($ratioScore, $absoluteScore);
        $score = round(min($rawScore * 0.9, 0.95), 2);

        if ($score < $this->minScore) {
            return null;
        }

        $anchorText = mb_substr($originalText, $bestFirst, $spanLength);

        // Trim leading + trailing stopwords (mirror findMatches anchor trim).
        // Pre-fix: the stem-fallback path returned raw cluster spans like
        // "and performance" with the boundary stopword intact, even though
        // findMatches consistently trims them. User-bug 2026-05-23.
        [$trimmedAnchor, $anchorShift] = TextNormalizer::trimBoundaryStopwords($anchorText, $titleLocale);
        $anchorText = $trimmedAnchor;
        $bestFirst = $bestFirst + $anchorShift;
        $spanLength = mb_strlen($anchorText);

        // Per-target-locale boundary trim for coordinator conjunctions
        // ("and", "und", "et", "y", …) that are not in the active stopword
        // list. PR #102 audit E2: was hardcoded EN+DE only.
        $coordBoundaryWords = LanguageRegistry::coordinatorsFor($record->locale);
        $stripCoordPunct = fn (string $w): string => preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', mb_strtolower($w));
        // Loop to peel multiple stacked coordinators if the cluster ever
        // surfaces something like "and or X".
        while (true) {
            $words = preg_split('/\s+/', trim($anchorText));
            $words = array_values(array_filter($words, fn ($w) => $w !== ''));
            if (count($words) < 2) break;

            $first = $stripCoordPunct($words[0]);
            $last = $stripCoordPunct($words[count($words) - 1]);
            $hitFirst = in_array($first, $coordBoundaryWords, true);
            $hitLast = in_array($last, $coordBoundaryWords, true);
            if (! $hitFirst && ! $hitLast) break;

            if ($hitFirst) {
                // Drop leading word from anchor + shift bestFirst forward
                // by the consumed chars (word + separator).
                $oldLen = mb_strlen($anchorText);
                $rest = ltrim(mb_substr($anchorText, mb_strlen($words[0])));
                $consumed = $oldLen - mb_strlen($rest);
                $anchorText = $rest;
                $bestFirst += $consumed;
            }
            if ($hitLast && $anchorText !== '') {
                $words2 = preg_split('/\s+/', trim($anchorText));
                $words2 = array_values(array_filter($words2, fn ($w) => $w !== ''));
                if (count($words2) >= 2) {
                    $tail = $words2[count($words2) - 1];
                    $beforeTail = mb_substr($anchorText, 0, mb_strrpos($anchorText, $tail));
                    $anchorText = rtrim($beforeTail);
                }
            }
        }
        $spanLength = mb_strlen($anchorText);
        // Bail if the trim cascade ate everything substantive.
        if ($spanLength === 0 || count(preg_split('/\s+/', trim($anchorText))) < 1) {
            return null;
        }

        // Reject anchors that span sentence boundaries (. ! ?)
        if (preg_match('/[.!?]\s/', $anchorText)) {
            return null;
        }

        // Reject anchors longer than 80 characters
        if (mb_strlen($anchorText) > 80) {
            return null;
        }

        // Reject anchors with interior coordination conjunctions
        // (user-bug 2026-05-23): "optimization and performance" connects
        // two stems via a coordinator ("and") — structurally fragmented,
        // inverted order vs title, semantically two independent concepts
        // glued together. The user reads this as "and-Müll".
        //
        // Bridge prepositions ("von", "of", "in", "for", etc.) are NOT
        // rejected — they bind concepts into a coherent phrase. Editors
        // legitimately want "Wollsocken von Bircher" as an anchor where
        // "von" is the linking preposition between two title content
        // words. Coordinators do the opposite: they split.
        //
        // Per-target-locale list (PR #102 audit E2). POS-tagging would be
        // cleaner but adds a heavy dependency we deliberately avoid in V1.
        $coordinationStopwords = LanguageRegistry::coordinatorsFor($record->locale);
        $clusterWords = preg_split('/\s+/', trim($anchorText));
        $clusterWords = array_values(array_filter($clusterWords, fn ($w) => $w !== ''));
        $contentCount = 0;
        $hasInteriorCoord = false;
        foreach ($clusterWords as $idx => $cw) {
            $lower = mb_strtolower($cw);
            $isStop = TextNormalizer::isStopword($lower);
            if (! $isStop) {
                $contentCount++;
            }
            $isBoundary = $idx === 0 || $idx === count($clusterWords) - 1;
            if (! $isBoundary && in_array($lower, $coordinationStopwords, true)) {
                $hasInteriorCoord = true;
            }
        }
        // Reject only the canonical Müll-shape: exactly 2 content words
        // separated by a coordinator ("X and Y") — semantically gluing two
        // independent concepts. Larger anchors (3+ content words) with an
        // interior "and" usually form a legitimate phrase that overlaps
        // the title strongly enough to justify the link (e.g. "internal
        // linking and better SEO" against "Internal Linking Strategy for
        // Better SEO").
        if ($contentCount === 2 && $hasInteriorCoord) {
            return null;
        }

        // Reject anchors where most words are NOT title-related — span stretched across unrelated content.
        // A good title anchor references the target; a bad one is a sentence with two title words at the edges.
        // Use ALL title word stems (including ones filtered from content matching like "work") — any word
        // from the title is legitimately title-related when appearing in an anchor.
        $anchorWords = preg_split('/\s+/', trim($anchorText));
        $anchorWords = array_filter($anchorWords, fn ($w) => $w !== '');
        if (count($anchorWords) >= 4) {
            $allTitleStems = [];
            foreach (explode(' ', $normalizedTitle) as $tw) {
                if ($tw === '') {
                    continue;
                }
                $allTitleStems[$stemmer->stem($tw)] = true;
            }
            $titleRelatedCount = 0;
            $consecutiveNonTitle = 0;
            $maxConsecutiveNonTitle = 0;
            foreach ($anchorWords as $w) {
                // Split compound words (e.g., "PHP-based" → ["PHP", "based"]) — any part matching counts
                $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($w));
                $parts = array_filter($parts, fn ($p) => $p !== '');
                $isTitleWord = false;
                foreach ($parts as $part) {
                    if (isset($allTitleStems[$stemmer->stem($part)])) {
                        $isTitleWord = true;
                        break;
                    }
                }
                if ($isTitleWord) {
                    $titleRelatedCount++;
                    $consecutiveNonTitle = 0;
                } else {
                    $consecutiveNonTitle++;
                    $maxConsecutiveNonTitle = max($maxConsecutiveNonTitle, $consecutiveNonTitle);
                }
            }

            // Reject if 5+ consecutive non-title words — clear sentence padding, not a title reference.
            // "GraphQL provides a flexible alternative to REST APIs" has 5 in a row ("provides a flexible alternative to").
            // "building modern REST APIs using the popular Laravel" has max 3 in a row (acceptable).
            if ($maxConsecutiveNonTitle >= 5) {
                return null;
            }

            $titleRelatedRatio = $titleRelatedCount / count($anchorWords);
            // 35% threshold: anchors below this are stretched spans with mostly unrelated content.
            if ($titleRelatedRatio < 0.35) {
                return null;
            }
        }

        $context = $this->extractContext($originalText, $bestFirst, mb_strlen($anchorText));

        return new Suggestion(
            targetEntryId: $record->id,
            targetTitle: $record->title,
            targetUrl: $record->url,
            targetCollection: $record->collection,
            anchorText: $anchorText,
            position: $bestFirst,
            score: $score,
            sentenceContext: $context['text'],
            contextTruncatedStart: $context['truncated_start'],
            contextTruncatedEnd: $context['truncated_end'],
            matchType: 'stem',
            matchReason: 'Words from the title "'.mb_substr($record->title, 0, 50).'" were found in similar form: '.implode(', ', array_slice(array_map(function ($stem) use ($titleStems) {
                foreach ($titleStems as $ts) { if ($ts['stem'] === $stem) return $ts['original']; }
                return $stem;
            }, array_values($foundStems)), 0, 5)).'.',
        );
    }

    protected function buildPhrasePattern(string $normalizedPhrase): string
    {
        $words = explode(' ', $normalizedPhrase);
        $escaped = array_map(fn ($w) => preg_quote($w, '/'), $words);

        // Word boundary + words separated by whitespace/punctuation + word boundary
        return '/\b'.implode('[\s\p{P}]+', $escaped).'\b/iu';
    }

    /**
     * Build a regex pattern that matches stemmed word forms.
     * "migrating wordpress statamic" → matches "migrate from WordPress to Statamic"
     * Each non-stopword is reduced to its stem + \w* to match any inflection.
     * Stopwords between content words are matched with a flexible gap.
     */
    protected function buildStemmedPhrasePattern(string $normalizedPhrase): ?string
    {
        $words = explode(' ', $normalizedPhrase);
        $stemmer = new Stemmer;

        // Extract content words (non-stopwords) and their stems
        $contentParts = [];
        foreach ($words as $word) {
            if (TextNormalizer::isStopword($word)) {
                continue;
            }
            $stem = $stemmer->stem($word);
            // Full stem + limited suffix wildcard.
            // Max suffix length = difference between original word and its stem + 2 chars tolerance.
            // "migrat" from "migrating" (diff=3) → allows up to 5 extra chars → matches migrate, migrating, migration
            // "setup" from "setup" (diff=0) → allows up to 2 extra chars → matches setups, but NOT setupper (3 chars)
            $maxSuffix = mb_strlen($word) - mb_strlen($stem) + 2;
            $contentParts[] = preg_quote($stem, '/') . '\w{0,' . $maxSuffix . '}';
        }

        if (count($contentParts) < 2) {
            return null; // Single word — not useful as phrase match
        }

        // Content words separated by up to 3 intervening words (allows stopwords/prepositions)
        $gap = '(?:\s+\S+){0,3}\s+';
        return '/\b' . implode($gap, $contentParts) . '\b/iu';
    }

    /**
     * Generate match phrases from a title.
     * Returns phrases sorted by length descending (longest first for greedy matching).
     */
    public function generateMatchPhrases(string $title, ?string $locale = null): array
    {
        $normalized = TextNormalizer::normalize($title);
        $words = explode(' ', $normalized);
        $phrases = [];

        // Full title
        $phrases[] = $normalized;

        // Title with leading stopwords stripped
        $core = TextNormalizer::stripLeadingStopwords($words, $locale);

        if ($core !== $normalized && str_word_count($core) >= $this->minPhraseWords) {
            $phrases[] = $core;
        }

        // Title with trailing stopwords stripped
        $coreTail = TextNormalizer::stripTrailingStopwords($words, $locale);

        if ($coreTail !== $normalized && $coreTail !== $core && str_word_count($coreTail) >= $this->minPhraseWords) {
            $phrases[] = $coreTail;
        }

        // Both leading and trailing stripped
        $coreWords = explode(' ', $core);
        $coreBoth = TextNormalizer::stripTrailingStopwords($coreWords, $locale);

        if ($coreBoth !== $core && $coreBoth !== $coreTail && str_word_count($coreBoth) >= $this->minPhraseWords) {
            $phrases[] = $coreBoth;
        }

        // RAKE-style candidate generation (Rose et al. 2010, Step 1+2):
        // split the normalized token stream at stopwords; each surviving
        // content-word run is a candidate. Punctuation was already replaced
        // with spaces by TextNormalizer::normalize at the top of this method,
        // so stopwords are the only remaining phrase delimiter.
        //
        // Why this replaces the previous all-n-grams generator (user-bug
        // 2026-05-23): the old loop emitted every contiguous slice and then
        // filtered, which let boundary-stopword runs slip through ("performance
        // and", "and optimization"). RAKE only emits *between-stopword*
        // content-word runs by construction — boundary-stopword phrases can't
        // exist in the output.
        //
        // Adjoining-keywords heuristic (original RAKE rejoins pairs that
        // co-occur 2+ times in the document) is deliberately omitted —
        // Linkwise operates per title (typically 5–10 words, no repetition
        // signal). The Full-Title phrase + core/coreTail/coreBoth strips
        // above carry the rescue path for descriptive titles whose only
        // legitimate phrase form crosses an interior stopword (e.g.
        // "Tip of the Iceberg" survives via the Full-Title path).
        $currentRun = [];
        foreach ($words as $word) {
            if ($word === '' || TextNormalizer::isStopword($word)) {
                if (count($currentRun) >= $this->minPhraseWords) {
                    $phrases[] = implode(' ', $currentRun);
                }
                $currentRun = [];
                continue;
            }
            $currentRun[] = $word;
        }
        // Tail flush — last run if the title doesn't end on a stopword.
        if (count($currentRun) >= $this->minPhraseWords) {
            $phrases[] = implode(' ', $currentRun);
        }

        // Deduplicate, sort by length desc (longest first)
        $phrases = array_unique($phrases);
        usort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $phrases;
    }

    // REV-DR-02 Phase A (2026-05-13): 8 normalization/tokenization methods
    // moved to Arturrossbach\Linkwise\Support\TextNormalizer.
    // Removed here: normalize, tokenize, tokenizeWithMapping,
    // stripLeadingStopwords, trimBoundaryStopwords, stripTrailingStopwords,
    // isStopword, getStopwords. SuggestionEngine retained ~200 lines of
    // pure orchestration + match-finding; the stateless helpers live in
    // their own file with their own tests.

    /**
     * Extract surrounding context around a match position.
     */
    /**
     * @return array{text: string, truncated_start: bool, truncated_end: bool}
     */
    protected function extractContext(string $text, int $position, int $anchorLength, int $maxChars = 240): array
    {
        $textLength = mb_strlen($text);
        $minWindow = $anchorLength + 60;
        $effectiveMax = max($maxChars, $minWindow);
        $halfWindow = (int) max(30, floor(($effectiveMax - $anchorLength) / 2));

        $start = max(0, $position - $halfWindow);
        $end = min($textLength, $position + $anchorLength + $halfWindow);

        // Hard-stop at paragraph boundary ("\n"). TextExtractor::fromBard
        // joins paragraphs with "\n", so without this clamp the window can
        // bleed into the previous/next paragraph. The resulting context
        // string would contain a "\n" that BardLinkInserter cannot find
        // inside any single paragraph's text — every wrap silently fails
        // with "Anchor text not found". The context belongs to ONE
        // paragraph; never let it cross.
        $textBeforeAnchor = mb_substr($text, 0, $position);
        $lastNl = mb_strrpos($textBeforeAnchor, "\n");
        if ($lastNl !== false) {
            $start = max($start, $lastNl + 1);
        }
        $nextNl = mb_strpos($text, "\n", $position + $anchorLength);
        if ($nextNl !== false) {
            $end = min($end, $nextNl);
        }

        // Snap to word boundaries
        if ($start > 0 && $start < $textLength) {
            $spacePos = mb_strpos($text, ' ', $start);

            if ($spacePos !== false && $spacePos < $position) {
                $start = $spacePos + 1;
            }
        }

        if ($end < $textLength) {
            $spacePos = mb_strrpos(mb_substr($text, 0, $end), ' ');

            if ($spacePos !== false && $spacePos > $position + $anchorLength) {
                $end = $spacePos;
            }
        }

        return [
            'text' => trim(mb_substr($text, $start, $end - $start)),
            'truncated_start' => $start > 0,
            'truncated_end' => $end < $textLength,
        ];
    }

    /**
     * Boost suggestion score if the target entry has custom keywords matching the source text.
     */
    protected function applyTargetKeywordBoost(Suggestion $suggestion, string $normalizedText): Suggestion
    {
        try {
            $customKeywords = $this->resolveKeywordManager()->getKeywords($suggestion->targetEntryId);
        } catch (\Throwable) {
            return $suggestion;
        }

        if (empty($customKeywords)) {
            return $suggestion;
        }

        // Stem the source text words for comparison
        $stemmer = new Stemmer;
        $sourceWords = explode(' ', $normalizedText);
        $sourceStems = array_map(fn ($w) => $stemmer->stem($w), $sourceWords);
        $sourceStemSet = array_flip($sourceStems);

        // Check if any custom keywords (stemmed) appear in the source text (stemmed)
        $matchCount = 0;
        foreach ($customKeywords as $keyword) {
            $keywordStem = $stemmer->stem(mb_strtolower($keyword));
            if (isset($sourceStemSet[$keywordStem])) {
                $matchCount++;
            }
        }

        if ($matchCount === 0) {
            return $suggestion;
        }

        // Boost: up to +0.1 for keyword matches (capped at original tier ceiling)
        $boost = min($matchCount * 0.03, 0.1);
        $newScore = min($suggestion->score + $boost, 1.0);

        return new Suggestion(
            targetEntryId: $suggestion->targetEntryId,
            targetTitle: $suggestion->targetTitle,
            targetUrl: $suggestion->targetUrl,
            targetCollection: $suggestion->targetCollection,
            anchorText: $suggestion->anchorText,
            position: $suggestion->position,
            score: round($newScore, 2),
            sentenceContext: $suggestion->sentenceContext,
            contextTruncatedStart: $suggestion->contextTruncatedStart,
            contextTruncatedEnd: $suggestion->contextTruncatedEnd,
            matchType: $suggestion->matchType,
            matchReason: $suggestion->matchReason.($matchCount > 0 ? " Additionally, {$matchCount} custom target keyword(s) matched." : ''),
        );
    }

    /**
     * Convert a byte offset (from PREG_OFFSET_CAPTURE) to a character offset (for mb_substr).
     */
    protected function byteToCharOffset(string $text, int $byteOffset): int
    {
        return mb_strlen(substr($text, 0, $byteOffset));
    }

    protected function isStopword(string $word): bool
    {
        return in_array($word, TextNormalizer::getStopwords(), true);
    }

    protected function getStopwords(): array
    {
        return Stopwords::forConfig();
    }

    /**
     * Check if an anchor text consists entirely of generic/structural words
     * that would make poor link text regardless of context.
     *
     * Words like "site", "item", "text", "page" are valid as PART of a meaningful
     * phrase ("site migration", "text editor") but not as standalone anchors
     * or combined with other generic words ("bold text", "item containing").
     */
    protected function isGenericAnchor(string $anchor): bool
    {
        static $genericWords = [
            // Structural/layout words
            'item', 'items', 'element', 'elements', 'block', 'blocks', 'section', 'sections',
            'level', 'first', 'second', 'third', 'last', 'next', 'previous',
            'containing', 'including', 'using', 'based', 'related',
            // Overly generic web terms
            'site', 'sites', 'page', 'pages', 'link', 'links', 'text', 'content',
            'list', 'table', 'image', 'file', 'files', 'data', 'type', 'types',
            'set', 'sets', 'field', 'fields', 'value', 'values', 'entry', 'entries',
            // Generic tech terms
            'code', 'test', 'tests', 'app', 'apps', 'tool', 'tools',
            'feature', 'features', 'option', 'options', 'example', 'examples',
            'new', 'old', 'simple', 'basic', 'advanced', 'modern', 'best', 'good',
            'bold', 'italic', 'strong', 'small', 'large', 'main', 'key', 'top', 'full',
            'first-level', 'second-level', 'third-level', 'top-level', 'high-level', 'low-level',
            // German generic
            'seite', 'seiten', 'inhalt', 'inhalte', 'artikel', 'beitrag', 'beiträge',
            'punkt', 'punkte', 'teil', 'teile', 'erste', 'zweite', 'dritte', 'letzte',
            'alle', 'viele', 'weitere', 'andere', 'neue', 'wichtig', 'wichtige', 'wichtigen',
            'umfasst', 'bietet', 'ermöglicht', 'benötigt', 'verwendet', 'nutzt',
            'mehrwert', 'überblick', 'bereich', 'bereiche', 'thema', 'themen',
            'für', 'oder', 'sowie', 'dabei', 'also', 'bereits', 'einfach',
        ];

        $words = preg_split('/\s+/', mb_strtolower(trim($anchor)));
        $words = array_filter($words, fn ($w) => $w !== '');

        if (empty($words)) {
            return true;
        }

        // ALL words in the anchor must be non-generic for it to pass
        $meaningfulWords = array_filter($words, fn ($w) => ! in_array($w, $genericWords, true));

        return empty($meaningfulWords);
    }
}
