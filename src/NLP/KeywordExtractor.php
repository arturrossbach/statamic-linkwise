<?php

namespace Arturrossbach\Linkwise\NLP;

class KeywordExtractor
{
    protected int $maxKeywords;

    /**
     * Pre-stemmed stopword set (hash for O(1) lookup). Built once at
     * construction time from `Stopwords::forConfig()` so the
     * tokenize-hot-path doesn't re-stem the ~620 ISO words per call.
     *
     * Stem-based match (vs. the previous surface-form match) closes
     * the inflection gap: `unseren` and `unsere` both stem to `uns`,
     * which is in the ISO list. Without this, `unseren` would slip
     * through the surface-only check and surface as an auto-detected
     * keyword (empirically observed 2026-05-22).
     *
     * @var array<string, bool>
     */
    protected array $stopwordStems;

    protected Stemmer $stemmer;

    protected FrequencyFilter $frequencyFilter;

    /**
     * Per-call title-stems context. Set by extractAllWithTitles() /
     * extractSingle($title=...) before tokenize() is invoked and reset
     * after the call. tokenize() reads this when deciding whether a
     * candidate stem is title-protected against the FrequencyFilter.
     *
     * Using instance state instead of threading a parameter through
     * tokenize() keeps the existing tokenize() signature stable —
     * the method is part of the public surface (called from
     * EntryIndexer + tests), changing its signature would force a
     * blast-radius edit everywhere.
     *
     * @var array<string, bool>
     */
    protected array $activeTitleStems = [];

    public function __construct(
        ?int $maxKeywords = null,
        ?array $stopwords = null,
        ?Stemmer $stemmer = null,
        ?FrequencyFilter $frequencyFilter = null,
    ) {
        $this->maxKeywords = $maxKeywords ?? config('linkwise.max_keywords_per_entry', 20);
        $this->stemmer = $stemmer ?? new Stemmer;
        $this->frequencyFilter = $frequencyFilter ?? new FrequencyFilter;

        // Pre-stem the ISO stopword list once. The list is small
        // (~620 entries for German) so the up-front cost is trivial;
        // every tokenize() call after this is an O(1) hash check
        // against $stopwordStems instead of a 620-element in_array
        // linear scan.
        $rawStopwords = $stopwords ?? Stopwords::forConfig();
        $this->stopwordStems = [];
        foreach ($rawStopwords as $word) {
            $stem = $this->stemmer->stem(mb_strtolower((string) $word));
            if ($stem !== '') {
                $this->stopwordStems[$stem] = true;
            }
        }
    }

    /**
     * Extract TF-IDF keywords for all documents in the corpus.
     *
     * Backwards-compatible signature — when called WITHOUT titles
     * (current EntryIndexer call site pre-2026-05-22), title-protect
     * silently degrades to "no protection" and tokens are filtered by
     * the FrequencyFilter purely on frequency. Behaviour is identical
     * for sites that have ISO-only stop-words in their indexer cache
     * (i.e. legacy state before Scan Content re-runs).
     *
     * For Title-Protect (mid-frequency domain words like `Rezept` that
     * appear in the entry's title should not be filtered), use
     * {@see extractAllWithTitles()}.
     *
     * @param  array<string, string>  $corpus  Map of document ID => text content
     * @return array<string, array<string, float>>  Map of document ID => [term => tfidf_score]
     */
    public function extractAll(array $corpus): array
    {
        return $this->extractAllWithTitles($corpus, []);
    }

    /**
     * Extract TF-IDF keywords with per-entry title-protection for the
     * frequency filter. Each title's stems shield matching body stems
     * from the FrequencyFilter — editor-marked relevance signal beats
     * generic corpus statistics.
     *
     * @param  array<string, string>  $corpus  Map of document ID => body text
     * @param  array<string, string>  $titles  Map of document ID => title string
     *   Entries missing from $titles get no protection (filter behaves
     *   identically to extractAll for them).
     * @return array<string, array<string, float>>  Map of doc ID => [term => tfidf_score]
     */
    public function extractAllWithTitles(array $corpus, array $titles): array
    {
        if (empty($corpus)) {
            return [];
        }

        // Tokenize all documents — each pass sets the active title-stems
        // context first so tokenize() applies the right title-protect
        // for that specific entry. Reset after the loop so any later
        // direct tokenize() call (outside an extract*-driven flow) gets
        // clean, no-protection behaviour.
        $tokenized = [];
        foreach ($corpus as $id => $text) {
            $this->setActiveTitleStems((string) ($titles[$id] ?? ''));
            $tokenized[$id] = $this->tokenize($text);
        }
        $this->clearActiveTitleStems();

        // Calculate IDF for all terms across the corpus
        $idf = $this->calculateIdf($tokenized);

        // Calculate TF-IDF per document and return top keywords
        $result = [];
        foreach ($tokenized as $id => $tokens) {
            $tf = $this->calculateTf($tokens);
            $tfidf = [];

            foreach ($tf as $term => $tfScore) {
                if (isset($idf[$term])) {
                    $tfidf[$term] = round($tfScore * $idf[$term], 4);
                }
            }

            // Sort by score descending, take top N
            arsort($tfidf);
            $result[$id] = array_slice($tfidf, 0, $this->maxKeywords, true);
        }

        return $result;
    }

    /**
     * Extract keywords for a single document against an existing corpus.
     * Used for incremental indexing on entry save.
     *
     * @param  string  $text  The document body text
     * @param  array<string, string[]>  $corpusTokens  Pre-tokenized corpus (id => tokens[])
     * @param  string  $title  Entry title (default empty = no title-protect).
     *   Pass the entry's title so mid-frequency domain words covered by
     *   the FrequencyFilter survive when they're explicitly named in
     *   the title — see extractAllWithTitles() for the same mechanism.
     * @return array<string, float>  term => tfidf_score
     */
    public function extractSingle(string $text, array $corpusTokens, string $title = ''): array
    {
        $this->setActiveTitleStems($title);
        $tokens = $this->tokenize($text);
        $this->clearActiveTitleStems();

        if (empty($tokens)) {
            return [];
        }

        $totalDocs = count($corpusTokens) + 1; // +1 for this document
        $tf = $this->calculateTf($tokens);
        $tfidf = [];

        foreach ($tf as $term => $tfScore) {
            // Count how many corpus documents contain this term
            $docsWithTerm = 0;
            foreach ($corpusTokens as $docTokens) {
                if (in_array($term, $docTokens, true)) {
                    $docsWithTerm++;
                }
            }
            // +1 because the current document also contains it
            $docsWithTerm++;

            $idfScore = log($totalDocs / $docsWithTerm);
            $tfidf[$term] = round($tfScore * $idfScore, 4);
        }

        arsort($tfidf);

        return array_slice($tfidf, 0, $this->maxKeywords, true);
    }

    /**
     * Calculate Term Frequency for a single document.
     *
     * @return array<string, float>  term => frequency (0-1)
     */
    protected function calculateTf(array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        $counts = array_count_values($tokens);
        $total = count($tokens);

        $tf = [];
        foreach ($counts as $term => $count) {
            $tf[$term] = $count / $total;
        }

        return $tf;
    }

    /**
     * Calculate Inverse Document Frequency across the corpus.
     *
     * @param  array<string, string[]>  $tokenized  Map of doc ID => tokens[]
     * @return array<string, float>  term => IDF score
     */
    protected function calculateIdf(array $tokenized): array
    {
        $totalDocs = count($tokenized);

        if ($totalDocs === 0) {
            return [];
        }

        // Count in how many documents each term appears
        $documentFrequency = [];
        foreach ($tokenized as $tokens) {
            $uniqueTerms = array_unique($tokens);
            foreach ($uniqueTerms as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        // IDF = log(totalDocs / docsContainingTerm)
        // Filter: terms appearing in >60% of documents have no discriminative value
        $maxDocFrequency = max(2, (int) ceil($totalDocs * 0.6));
        $idf = [];
        foreach ($documentFrequency as $term => $df) {
            if ($df > $maxDocFrequency) {
                continue; // Too common across corpus — no topical value
            }
            $idf[$term] = log($totalDocs / $df);
        }

        return $idf;
    }

    /**
     * Tokenize text into significant, stemmed terms.
     *
     * Pipeline (stem-first since 2026-05-22 — see KeywordExtractor
     * class docblock for the empirical bug that drove this):
     *   1. lowercase + punctuation strip (keep hyphens for compound words)
     *   2. drop length-<3 + numeric surface tokens (cheap pre-stem cut)
     *   3. STEM every surviving surface token
     *   4. ISO-stopword check on the STEM (catches `unser`, `unseren`,
     *      `unserer` etc. — all stem to `uns`, which is in ISO)
     *   5. Frequency-filter check on the STEM with title-protect
     *      (`vernachlässigten` stems to `vernachlassigt`, which is in
     *      the FrequencyWords 50k corpus; stays if and only if the same
     *      stem is in `$activeTitleStems` — author-intent override)
     *   6. final length-≥3 filter on the stem itself
     *
     * @return string[]
     */
    public function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        // Remove punctuation but keep hyphens between words
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text);
        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));

        if ($text === '') {
            return [];
        }

        $words = explode(' ', $text);

        // Step 2: cheap pre-stem cut — drop tokens that can never be
        // useful keywords regardless of language: too short or numeric.
        $surfaceCandidates = array_values(array_filter(
            $words,
            fn (string $word) => mb_strlen($word) >= 3 && ! is_numeric($word),
        ));

        if ($surfaceCandidates === []) {
            return [];
        }

        // Step 3: stem in bulk. wamania caches the stemmer instance
        // per language, so this is one library call per token.
        $stemmed = $this->stemmer->stemAll($surfaceCandidates);

        // Steps 4-6: stem-set filters + final length cut.
        $kept = [];
        foreach ($stemmed as $stem) {
            if (mb_strlen($stem) < 3) {
                continue;
            }
            if (isset($this->stopwordStems[$stem])) {
                // ISO stop-word match — kill regardless of title context.
                // Structural words (`unser`, `der`, `die`, `und`) must
                // never become keywords even if they appear in titles.
                continue;
            }
            if ($this->frequencyFilter->isCommonStopword($stem, $this->activeTitleStems)) {
                // Mid-frequency junk (`funktioniert`, `richtige`,
                // `vernachlässigten` → all stem to entries in the
                // 50k list). Title-protect already applied inside
                // isCommonStopword().
                continue;
            }
            $kept[] = $stem;
        }

        return $kept;
    }

    /**
     * Build the per-call title-stems set so tokenize() can check it
     * during the FrequencyFilter step. Public so the extract* methods
     * can wire it up; callers that go directly through tokenize()
     * implicitly get "no title protection" (empty set), which is the
     * safe default — matches the pre-feature behaviour exactly.
     */
    public function setActiveTitleStems(string $title): void
    {
        if ($title === '') {
            $this->activeTitleStems = [];

            return;
        }

        // Same lowercase + punctuation strip as the main tokenizer —
        // titles must hash to the same stems as body tokens or the
        // protect logic silently misfires (title says "Rezept", body
        // contains "Rezept" → both should stem to the same key; any
        // case-mismatch path here breaks that invariant).
        $lower = mb_strtolower($title);
        $stripped = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $lower);
        $collapsed = preg_replace('/\s+/', ' ', trim($stripped));
        $words = $collapsed === '' ? [] : explode(' ', $collapsed);
        $surface = array_values(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 3 && ! is_numeric($w),
        ));

        $stems = $this->stemmer->stemAll($surface);
        $this->activeTitleStems = $this->frequencyFilter->titleStemsSet($stems);
    }

    /**
     * Clear the title-stems context. Safe to call even when nothing
     * was set. Used by extract* methods to make sure tokenize() calls
     * outside the title-aware flow can't accidentally inherit
     * stale protection from a previous extraction.
     */
    public function clearActiveTitleStems(): void
    {
        $this->activeTitleStems = [];
    }

}
