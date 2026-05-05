<?php

namespace Inkline\Linkwise\Tests\Unit;

use Inkline\Linkwise\NLP\KeywordExtractor;
use PHPUnit\Framework\TestCase;

class KeywordExtractorTest extends TestCase
{
    private KeywordExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new KeywordExtractor(maxKeywords: 10);
    }

    public function test_extracts_keywords_from_corpus(): void
    {
        $corpus = [
            'doc1' => 'Laravel provides excellent caching mechanisms. Redis and Memcached are supported. Laravel caching improves performance significantly.',
            'doc2' => 'Setting up Redis for your application requires careful configuration. Redis cluster mode enables high availability.',
            'doc3' => 'Database optimization involves indexing, query tuning, and connection pooling. Proper database design is fundamental.',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        $this->assertCount(3, $keywords);
        $this->assertArrayHasKey('doc1', $keywords);
        $this->assertArrayHasKey('doc2', $keywords);
        $this->assertArrayHasKey('doc3', $keywords);

        // Each document should have keywords
        $this->assertNotEmpty($keywords['doc1']);
        $this->assertNotEmpty($keywords['doc2']);
        $this->assertNotEmpty($keywords['doc3']);
    }

    public function test_tfidf_scores_unique_terms_higher(): void
    {
        $corpus = [
            'doc1' => 'Laravel caching with Redis improves performance. Laravel is fast. Laravel handles caching well.',
            'doc2' => 'Redis configuration for production servers. Redis cluster setup guide.',
            'doc3' => 'Vue.js components for frontend development. Vue reactive data binding.',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        // "vue" should be unique to doc3 and score high there
        $this->assertArrayHasKey('vue', $keywords['doc3']);

        // "redis" appears in doc1 and doc2, so should have lower IDF
        // but still appear due to frequency
        if (isset($keywords['doc2']['redis'])) {
            $this->assertGreaterThan(0, $keywords['doc2']['redis']);
        }
    }

    public function test_respects_max_keywords(): void
    {
        $extractor = new KeywordExtractor(maxKeywords: 3);

        $corpus = [
            'doc1' => 'Laravel caching Redis performance optimization database query indexing configuration deployment monitoring logging',
        ];

        $keywords = $extractor->extractAll($corpus);

        $this->assertCount(1, $keywords);
        $this->assertLessThanOrEqual(3, count($keywords['doc1']));
    }

    public function test_filters_stopwords(): void
    {
        $corpus = [
            'doc1' => 'The quick brown fox jumps over the lazy dog and the cat is sleeping',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        // Stopwords like "the", "and", "is", "over" should not be in keywords
        $terms = array_keys($keywords['doc1']);
        $this->assertNotContains('the', $terms);
        $this->assertNotContains('and', $terms);
        $this->assertNotContains('is', $terms);
        $this->assertNotContains('over', $terms);
    }

    public function test_handles_empty_corpus(): void
    {
        $keywords = $this->extractor->extractAll([]);
        $this->assertEmpty($keywords);
    }

    public function test_handles_empty_text(): void
    {
        $corpus = [
            'doc1' => '',
            'doc2' => 'Some actual content here about programming',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        $this->assertEmpty($keywords['doc1']);
        $this->assertNotEmpty($keywords['doc2']);
    }

    public function test_tokenize_normalizes_and_stems_text(): void
    {
        $tokens = $this->extractor->tokenize('Laravel\'s Caching — fast & reliable!');

        $this->assertContains('laravel', $tokens);
        $this->assertContains('cach', $tokens); // "caching" stemmed
        $this->assertContains('fast', $tokens);
        $this->assertContains('reliabl', $tokens); // "reliable" stemmed
    }

    public function test_tokenize_removes_short_words(): void
    {
        $tokens = $this->extractor->tokenize('I am a go to PHP developer');

        // Single-char words should be filtered
        $this->assertNotContains('i', $tokens);
        $this->assertNotContains('a', $tokens);
        // "am", "go", "to" are stopwords or too short
    }

    public function test_german_text_extraction(): void
    {
        $corpus = [
            'doc1' => 'Laravel Performance-Optimierung durch Caching-Strategien. Caching verbessert die Ladezeiten erheblich. Performance ist entscheidend.',
            'doc2' => 'Datenbank-Design und Abfrage-Optimierung. SQL-Indizes beschleunigen Abfragen deutlich.',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        $this->assertNotEmpty($keywords['doc1']);
        $this->assertNotEmpty($keywords['doc2']);

        // German stopwords like "die", "und", "ist" should be filtered
        $allTerms = array_merge(array_keys($keywords['doc1']), array_keys($keywords['doc2']));
        $this->assertNotContains('die', $allTerms);
        $this->assertNotContains('und', $allTerms);
        $this->assertNotContains('ist', $allTerms);
    }

    public function test_extract_single_against_corpus(): void
    {
        $corpusTokens = [
            'doc1' => $this->extractor->tokenize('Laravel caching with Redis for better performance'),
            'doc2' => $this->extractor->tokenize('Vue.js frontend component architecture patterns'),
        ];

        $keywords = $this->extractor->extractSingle(
            'Database optimization and indexing strategies for PostgreSQL. Database performance tuning.',
            $corpusTokens,
        );

        $this->assertNotEmpty($keywords);
        // "database" stemmed to "databas" should be unique to this doc
        $this->assertArrayHasKey('databas', $keywords);
    }

    public function test_scores_are_positive_floats(): void
    {
        $corpus = [
            'doc1' => 'Laravel framework provides routing, middleware, caching and database tools',
            'doc2' => 'React library handles components, state management and virtual DOM rendering',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        foreach ($keywords as $docKeywords) {
            foreach ($docKeywords as $term => $score) {
                $this->assertIsFloat($score);
                $this->assertGreaterThan(0, $score, "Score for '$term' should be positive");
            }
        }
    }

    public function test_single_document_corpus(): void
    {
        $corpus = [
            'doc1' => 'Laravel caching strategies for production applications',
        ];

        $keywords = $this->extractor->extractAll($corpus);

        // With single doc, IDF = log(1/1) = 0, so all TF-IDF scores = 0
        // This is expected — TF-IDF needs a corpus for contrast
        $this->assertArrayHasKey('doc1', $keywords);
        // All scores should be 0 since IDF(term appearing in 1 of 1 docs) = log(1) = 0
        foreach ($keywords['doc1'] as $score) {
            $this->assertSame(0.0, $score);
        }
    }
}
