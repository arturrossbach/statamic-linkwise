<?php

namespace Arturrossbach\Linkwise\Suggestions;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\NLP\Stemmer;
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
    public function suggest(string $text, array $index, ?string $excludeEntryId = null, array $alreadyLinkedIds = [], ?int $maxSuggestions = null): array
    {
        $normalizedText = $this->normalize($text);
        $suggestions = [];

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

        // Tokenize source text once for keyword matching (stemmed + original mapping)
        [$sourceTokens, $stemToOriginal] = $this->tokenizeWithMapping($text);

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

            // Skip blacklisted titles
            if (! empty($titleBlacklist) && in_array(mb_strtolower($record->title), $titleBlacklist, true)) {
                continue;
            }

            // Filter by target collections
            if (! empty($targetCollections) && ! in_array($record->collection, $targetCollections, true)) {
                continue;
            }

            // Tier 1: Title phrase matching (all positions)
            $matches = $this->findMatches($normalizedText, $text, $record);

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
        $phrases = $this->generateMatchPhrases($record->title);
        $titleWordCount = count(explode(' ', $this->normalize($record->title)));
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

                    $position = $this->byteToCharOffset($originalText, $m[1]);
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

            if (mb_strlen($keyword) < 2 || $this->isStopword(mb_strtolower($keyword))) {
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

        // Find the best anchor using original (unstemmed) words in the original text
        $anchorText = $this->findBestAnchor($originalText, $bestOriginalWord, $originalMatchingWords);

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
    protected function findBestAnchor(string $text, string $primaryKeyword, array $matchingKeywords): string
    {
        // Strategy 1: Try to find two matching keywords near each other (0-1 word gap)
        foreach ($matchingKeywords as $otherKeyword) {
            if ($otherKeyword === $primaryKeyword) {
                continue;
            }

            $p = preg_quote($primaryKeyword, '/');
            $o = preg_quote($otherKeyword, '/');

            // Check "primary [gap?] other" order
            $pattern = '/\b('.$p.'\s+(?:[\p{L}\p{N}-]+\s+)?'.$o.')\b/iu';
            if (preg_match($pattern, $text, $m)) {
                return $m[1];
            }

            // Check "other [gap?] primary" order
            $pattern = '/\b('.$o.'\s+(?:[\p{L}\p{N}-]+\s+)?'.$p.')\b/iu';
            if (preg_match($pattern, $text, $m)) {
                return $m[1];
            }
        }

        // Strategy 2: Grab the adjacent significant word from the original text
        // Look for "word keyword" or "keyword word" where word is not a stopword
        $escaped = preg_quote($primaryKeyword, '/');
        $stopwords = $this->getStopwords();

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
        $stemmer = new Stemmer;
        $normalizedTitle = $this->normalize($record->title);
        $titleWords = explode(' ', $normalizedTitle);

        // Get content words from title (skip stopwords)
        $titleContentWords = array_filter($titleWords, fn ($w) => $w !== '' && ! $this->isStopword($w));
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

        // Reject anchors that span sentence boundaries (. ! ?)
        if (preg_match('/[.!?]\s/', $anchorText)) {
            return null;
        }

        // Reject anchors longer than 80 characters
        if (mb_strlen($anchorText) > 80) {
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
            if ($this->isStopword($word)) {
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
    public function generateMatchPhrases(string $title): array
    {
        $normalized = $this->normalize($title);
        $words = explode(' ', $normalized);
        $phrases = [];

        // Full title
        $phrases[] = $normalized;

        // Title with leading stopwords stripped
        $core = $this->stripLeadingStopwords($words);

        if ($core !== $normalized && str_word_count($core) >= $this->minPhraseWords) {
            $phrases[] = $core;
        }

        // Title with trailing stopwords stripped
        $coreTail = $this->stripTrailingStopwords($words);

        if ($coreTail !== $normalized && $coreTail !== $core && str_word_count($coreTail) >= $this->minPhraseWords) {
            $phrases[] = $coreTail;
        }

        // Both leading and trailing stripped
        $coreWords = explode(' ', $core);
        $coreBoth = $this->stripTrailingStopwords($coreWords);

        if ($coreBoth !== $core && $coreBoth !== $coreTail && str_word_count($coreBoth) >= $this->minPhraseWords) {
            $phrases[] = $coreBoth;
        }

        // Generate contiguous n-grams down to minPhraseWords. The previous
        // hard floor of 3 words combined with the strict start/end stopword
        // filter caused long descriptive titles to produce no usable phrases
        // at all — e.g. "WAR RAGES ON Houthis launch missile..." generated
        // zero phrases starting with "war" because in en_de mixed mode "war"
        // was treated as a stopword (legitimate German past-tense of "sein"),
        // even though it's a content word in the actual English title.
        //
        // Two changes here:
        //   (1) walk down to minPhraseWords (default 2), not hard-3
        //   (2) reject only phrases where ALL tokens are stopwords; keep
        //       phrases where at least one word carries content. This still
        //       drops noise like "and the" without nuking "WAR RAGES" or
        //       similar where the multi-language stopword union false-flags
        //       a content word at a boundary.
        $minLen = max(2, $this->minPhraseWords);
        if (count($words) >= max(3, $minLen + 1)) {
            $significantWords = array_filter($words, fn ($w) => ! $this->isStopword($w));

            if (count($significantWords) >= 2) {
                for ($len = count($words) - 1; $len >= $minLen; $len--) {
                    for ($start = 0; $start <= count($words) - $len; $start++) {
                        $slice = array_slice($words, $start, $len);
                        $phrase = implode(' ', $slice);

                        // Reject only if EVERY word is a stopword — keeps
                        // boundary content words intact across language modes.
                        $allStopwords = true;
                        foreach ($slice as $w) {
                            if ($w !== '' && ! $this->isStopword($w)) {
                                $allStopwords = false;
                                break;
                            }
                        }
                        if (! $allStopwords) {
                            $phrases[] = $phrase;
                        }
                    }
                }
            }
        }

        // Deduplicate, sort by length desc (longest first)
        $phrases = array_unique($phrases);
        usort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $phrases;
    }

    /**
     * Normalize text for matching: lowercase, collapse whitespace, strip punctuation.
     */
    public function normalize(string $text): string
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
     */
    protected function tokenize(string $text): array
    {
        [$stemmed] = $this->tokenizeWithMapping($text);

        return $stemmed;
    }

    /**
     * Tokenize and stem text, returning both stemmed tokens and a stem→original mapping.
     *
     * @return array{0: string[], 1: array<string, string>}  [stemmedTokens, stemToOriginal]
     */
    protected function tokenizeWithMapping(string $text): array
    {
        $normalized = $this->normalize($text);
        $words = explode(' ', $normalized);

        $filtered = array_values(array_filter($words, fn ($w) => $w !== '' && ! $this->isStopword($w)));

        $stemmer = new Stemmer;
        $stemmed = [];
        $stemToOriginal = [];

        foreach ($filtered as $word) {
            $stem = $stemmer->stem($word);
            $stemmed[] = $stem;
            // Keep first occurrence's original form (most natural for anchor text)
            if (! isset($stemToOriginal[$stem])) {
                $stemToOriginal[$stem] = $word;
            }
        }

        return [$stemmed, $stemToOriginal];
    }

    protected function stripLeadingStopwords(array $words): string
    {
        while (! empty($words) && $this->isStopword($words[0])) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    protected function stripTrailingStopwords(array $words): string
    {
        while (! empty($words) && $this->isStopword(end($words))) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /**
     * Extract surrounding context around a match position.
     */
    /**
     * @return array{text: string, truncated_start: bool, truncated_end: bool}
     */
    protected function extractContext(string $text, int $position, int $anchorLength, int $maxChars = 160): array
    {
        $textLength = mb_strlen($text);
        $minWindow = $anchorLength + 60;
        $effectiveMax = max($maxChars, $minWindow);
        $halfWindow = (int) max(30, floor(($effectiveMax - $anchorLength) / 2));

        $start = max(0, $position - $halfWindow);
        $end = min($textLength, $position + $anchorLength + $halfWindow);

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
        return in_array($word, $this->getStopwords(), true);
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
