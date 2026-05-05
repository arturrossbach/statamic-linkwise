<?php

namespace Arturrossbach\Linkwise\NLP;

class KeywordExtractor
{
    protected int $maxKeywords;

    protected array $stopwords;

    protected Stemmer $stemmer;

    public function __construct(?int $maxKeywords = null, ?array $stopwords = null, ?Stemmer $stemmer = null)
    {
        $this->maxKeywords = $maxKeywords ?? config('linkwise.max_keywords_per_entry', 20);
        $this->stopwords = $stopwords ?? Stopwords::forConfig();
        $this->stemmer = $stemmer ?? new Stemmer;
    }

    /**
     * Extract TF-IDF keywords for all documents in the corpus.
     *
     * @param  array<string, string>  $corpus  Map of document ID => text content
     * @return array<string, array<string, float>>  Map of document ID => [term => tfidf_score]
     */
    public function extractAll(array $corpus): array
    {
        if (empty($corpus)) {
            return [];
        }

        // Tokenize all documents
        $tokenized = [];
        foreach ($corpus as $id => $text) {
            $tokenized[$id] = $this->tokenize($text);
        }

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
     * Useful for incremental indexing (entry saved).
     *
     * @param  string  $text  The document text
     * @param  array<string, string[]>  $corpusTokens  Pre-tokenized corpus (id => tokens[])
     * @return array<string, float>  term => tfidf_score
     */
    public function extractSingle(string $text, array $corpusTokens): array
    {
        $tokens = $this->tokenize($text);

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
     * Tokenize text into significant, stemmed terms (lowercase, no stopwords, min length 2).
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

        // Filter stopwords and short words, then stem
        $filtered = array_filter($words, function (string $word) {
            return mb_strlen($word) >= 3
                && ! is_numeric($word)
                && ! in_array($word, $this->stopwords, true);
        });

        // Stem each word to its root form (attacked→attack, trading→trade)
        $stemmed = $this->stemmer->stemAll(array_values($filtered));

        // Post-stem filter: remove stems shorter than 3 chars
        return array_values(array_filter($stemmed, fn ($s) => mb_strlen($s) >= 3));
    }

}
